<?php

declare(strict_types=1);

namespace Fuxico;

class App
{
    private Config $config;

    private Database $db;

    private Storage $storage;

    public function __construct(?Config $config = null, ?Database $db = null, ?Storage $storage = null)
    {
        $this->config = $config ?? new Config;
        $this->db = $db ?? new Database($this->config->dbPath);
        $this->storage = $storage ?? new Storage($this->config->dataDir, $this->config->filesDir);
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getDatabase(): Database
    {
        return $this->db;
    }

    public function getStorage(): Storage
    {
        return $this->storage;
    }

    public function getBaseUrl(): string
    {
        if ($this->config->baseUrl !== null && $this->config->baseUrl !== '') {
            return rtrim($this->config->baseUrl, '/');
        }

        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                  (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                  ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return "{$scheme}://{$host}";
    }

    /**
     * Generate a unique slug.
     */
    public function generateSlug(bool $secret = false): string
    {
        $length = $secret ? $this->config->secretFilenameLength : $this->config->filenameLength;
        $chars = $this->config->allowedChars;
        $charsLength = strlen($chars);

        do {
            $slug = '';
            for ($i = 0; $i < $length; $i++) {
                $slug .= $chars[random_int(0, $charsLength - 1)];
            }
        } while ($this->db->isSlugTaken($slug));

        return $slug;
    }

    /**
     * Parse expiration parameter into a Unix timestamp.
     * Supports:
     * - Hours as int or float (0x0.st style, e.g. "24")
     * - Milliseconds since epoch (0x0.st style, e.g. "1681996320000")
     * - Seconds since epoch
     * - Relative strings (rustypaste style, e.g. "10m", "2h", "7d", "1w")
     */
    public function parseExpiration(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $val = trim($value);

        // Relative duration strings (e.g. 10m, 2h, 7d, 30d, 1y)
        if (preg_match('/^(\d+)\s*(s|sec|m|min|h|hr|d|day|w|week|y|year)s?$/i', $val, $m)) {
            $num = (int) $m[1];
            $unit = strtolower($m[2]);
            $seconds = match ($unit) {
                's', 'sec' => $num,
                'm', 'min' => $num * 60,
                'h', 'hr' => $num * 3600,
                'd', 'day' => $num * 86400,
                'w', 'week' => $num * 86400 * 7,
                'y', 'year' => $num * 86400 * 365,
                default => 0,
            };

            return time() + $seconds;
        }

        if (is_numeric($val)) {
            $num = (float) $val;
            // Milliseconds since epoch
            if ($num > 1000000000000) {
                return (int) round($num / 1000.0);
            }
            // Seconds since epoch
            if ($num > 1000000000) {
                return (int) $num;
            }
            // Hours format (0x0.st standard format)
            if ($num > 0) {
                return time() + (int) round($num * 3600.0);
            }
        }

        return null;
    }

    /**
     * Purge any expired records and their corresponding files from disk.
     */
    public function purgeExpired(): int
    {
        $expired = $this->db->getExpiredItems(time());
        $purgedCount = 0;

        foreach ($expired as $item) {
            $this->storage->deleteItemFiles($item['slug'], $item['file_path'] ?? null);
            $this->db->deleteBySlug($item['slug']);
            $purgedCount++;
        }

        return $purgedCount;
    }

    /**
     * Main HTTP request router.
     */
    public function handleRequest(): void
    {
        $this->purgeExpired();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (($uri === '/' || $uri === '/index.php') && ! empty($_GET['q'])) {
            $uri = '/'.ltrim((string) $_GET['q'], '/');
        }
        $path = trim($uri, '/');

        // Extract Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (! $authHeader && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }

        // 1. Rustypaste list endpoint
        if ($method === 'GET' && $path === 'list') {
            $this->handleList($authHeader);

            return;
        }

        // 2. API endpoints for Web Dashboard
        if (str_starts_with($path, 'api/')) {
            $this->handleApi(substr($path, 4), $method, $authHeader);

            return;
        }

        // 2.5 Dedicated Admin Panel endpoint
        if ($path === 'painel') {
            $this->handlePainel($method);

            return;
        }

        // 3. Root Endpoint
        if ($path === '') {
            if ($method === 'POST') {
                $this->handleUpload($authHeader);
            } elseif ($method === 'GET') {
                $this->handleRootGet();
            } else {
                http_response_code(405);
                header('Content-Type: text/plain');
                echo "Method Not Allowed\n";
            }

            return;
        }

        // 3.5 Git HTTP Smart Protocol endpoints (/<slug>.git/info/refs, /<slug>.git/git-upload-pack, etc.)
        if (preg_match('#^([a-zA-Z0-9_\-\.]+?)(?:\.git)?/(info/refs|git-upload-pack|git-receive-pack)$#', $path, $m)) {
            $this->handleGitRoute($m[1], $m[2], $method, $authHeader);

            return;
        }

        // 4. File / URL lookup or 0x0.st Management
        $this->handleItemRoute($path, $method, $authHeader);
    }

    private function handleUpload(?string $authHeader): void
    {
        if (! $this->config->publicMode && ! $this->config->isAuthorized($authHeader)) {
            http_response_code(401);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "401 Unauthorized: Ficha ou token de acesso inválido ou ausente. Esta instância é privada. Envie o token via 'Authorization: Bearer <token>' ou use 'fux -t <ficha>'.\n";

            return;
        }

        $isSecret = isset($_POST['secret']) || isset($_GET['secret']);
        $oneshot = isset($_POST['oneshot']) ||
                   isset($_POST['oneshot_url']) ||
                   (isset($_FILES['oneshot']) && is_array($_FILES['oneshot']) && ! empty($_FILES['oneshot']['tmp_name'])) ||
                   (isset($_SERVER['HTTP_ONESHOT']) && filter_var($_SERVER['HTTP_ONESHOT'], FILTER_VALIDATE_BOOLEAN));

        $expireParam = $_POST['expires'] ?? $_POST['expire'] ?? $_SERVER['HTTP_EXPIRE'] ?? null;
        $expiresAt = $this->parseExpiration($expireParam ? (string) $expireParam : null);

        $customName = trim((string) ($_POST['customName'] ?? $_POST['custom_name'] ?? $_POST['name'] ?? $_SERVER['HTTP_FILENAME'] ?? ''));

        // Determine slug & optional custom extension
        $customExt = '';
        if ($customName !== '') {
            $nameExt = pathinfo($customName, PATHINFO_EXTENSION);
            $baseName = pathinfo($customName, PATHINFO_FILENAME);
            $cleanBase = preg_replace('/[^a-zA-Z0-9_\-]+/', '-', strtolower($baseName));
            $cleanBase = trim((string) $cleanBase, '-');
            $slug = $cleanBase !== '' ? $cleanBase : $this->generateSlug($isSecret);
            if ($nameExt !== '') {
                $customExt = strtolower($nameExt);
            }
            if ($this->db->isSlugTaken($slug)) {
                http_response_code(409);
                header('Content-Type: text/plain');
                echo "Error: Name '{$customName}' is already taken or invalid.\n";

                return;
            }
        } else {
            $slug = $this->generateSlug($isSecret);
        }

        $xToken = bin2hex(random_bytes(16));

        // 0. Multi-file Gist Upload (via files[] or type=gist)
        $isGistUpload = (isset($_FILES['files']) && is_array($_FILES['files']['name'])) ||
                        (isset($_POST['type']) && $_POST['type'] === 'gist');
        if ($isGistUpload) {
            $this->handleGistUpload($slug, $xToken, $oneshot, $expiresAt, $customName);

            return;
        }

        // 1. File Upload (supports 'file' and rustypaste 'oneshot' multipart field)
        $uploadFile = null;
        if (isset($_FILES['file']) && is_array($_FILES['file']) && ! empty($_FILES['file']['tmp_name'])) {
            $uploadFile = $_FILES['file'];
        } elseif (isset($_FILES['oneshot']) && is_array($_FILES['oneshot']) && ! empty($_FILES['oneshot']['tmp_name'])) {
            $uploadFile = $_FILES['oneshot'];
        }

        if ($uploadFile !== null) {
            $file = $uploadFile;
            if ($file['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                header('Content-Type: text/plain');
                echo "Upload error code: {$file['error']}\n";

                return;
            }

            $effectiveName = $customExt !== '' ? "{$slug}.{$customExt}" : $file['name'];
            $validation = MimeValidator::validate($file['tmp_name'], $effectiveName, $this->config->maxUploadSize);
            if (! $validation['valid']) {
                http_response_code(400);
                header('Content-Type: text/plain');
                echo "Error: {$validation['error']}\n";

                return;
            }

            $ext = $customExt !== '' ? $customExt : strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $targetFilename = $slug.($ext !== '' ? ".{$ext}" : '');

            if (! $this->storage->storeUploadedFile($file['tmp_name'], $targetFilename)) {
                http_response_code(500);
                header('Content-Type: text/plain');
                echo "Error storing uploaded file.\n";

                return;
            }

            $isRenderDoc = isset($_POST['doc']) ||
                           isset($_GET['doc']) ||
                           (isset($_POST['render']) && in_array(strtolower((string) $_POST['render']), ['true', '1', 'md', 'markdown', 'doc'], true)) ||
                           (isset($_POST['type']) && $_POST['type'] === 'doc') ||
                           (isset($_SERVER['HTTP_FUXICO_DOC']) && filter_var($_SERVER['HTTP_FUXICO_DOC'], FILTER_VALIDATE_BOOLEAN));

            $isMarkdown = $ext === 'md' || in_array($validation['mime'], ['text/markdown', 'text/x-markdown', 'text/plain'], true);
            $itemType = ($isRenderDoc && $isMarkdown) ? 'doc' : 'file';

            if ($itemType === 'doc') {
                $rawMd = file_get_contents($this->storage->getFilePath($targetFilename));
                if ($rawMd !== false) {
                    $defaultTheme = (isset($_POST['theme']) && strtolower((string) $_POST['theme']) === 'acad') ? 'acad' : 'zine';
                    $bakedHtml = MarkdownRenderer::render($rawMd, [
                        'title' => pathinfo($file['name'], PATHINFO_FILENAME),
                        'slug' => $slug,
                        'original_name' => $file['name'],
                        'default_theme' => $defaultTheme,
                        'created_at' => time(),
                        'base_url' => $this->getBaseUrl(),
                    ]);
                    file_put_contents($this->storage->getFilePath("{$slug}.html"), $bakedHtml);
                }

                // Save attached assets if provided (e.g. assets[] multipart upload)
                if (isset($_FILES['assets']) && is_array($_FILES['assets']['name'])) {
                    $assetsDir = $this->storage->getFilesDir()."/{$slug}_assets";
                    if (! is_dir($assetsDir)) {
                        mkdir($assetsDir, 0755, true);
                    }
                    $countAssets = count($_FILES['assets']['name']);
                    for ($ai = 0; $ai < $countAssets; $ai++) {
                        $tmpAsset = $_FILES['assets']['tmp_name'][$ai] ?? null;
                        $assetName = $_FILES['assets']['name'][$ai] ?? null;
                        if ($tmpAsset && $assetName && is_uploaded_file($tmpAsset)) {
                            $cleanName = basename($assetName);
                            move_uploaded_file($tmpAsset, "{$assetsDir}/{$cleanName}");
                        }
                    }
                }
            }

            $this->db->createItem([
                'id' => bin2hex(random_bytes(8)),
                'slug' => $slug,
                'original_name' => $file['name'],
                'type' => $itemType,
                'file_path' => $targetFilename,
                'mime_type' => $itemType === 'doc' ? 'text/markdown' : $validation['mime'],
                'size' => (int) filesize($this->storage->getFilePath($targetFilename)),
                'x_token' => $xToken,
                'is_oneshot' => $oneshot ? 1 : 0,
                'expires_at' => $expiresAt,
                'created_at' => time(),
                'views' => 0,
            ]);

            header("x-token: {$xToken}");
            header('Content-Type: text/plain');
            $urlExt = ($itemType !== 'doc' && $ext !== '') ? ".{$ext}" : '';
            echo "{$this->getBaseUrl()}/{$slug}{$urlExt}\n";

            return;
        }

        // 2. URL Shorten (or remote fetch if requested)
        $urlCandidate = (isset($_POST['shorten']) && is_string($_POST['shorten']))
            ? $_POST['shorten']
            : ((isset($_POST['url']) && is_string($_POST['url'])) ? $_POST['url'] : null);
        $isRemoteFetch = isset($_POST['remote']) || (isset($_POST['url']) && ! isset($_POST['shorten']) && isset($_POST['fetch']));

        if ($isRemoteFetch && $urlCandidate) {
            $this->handleRemoteFetch($urlCandidate, $slug, $xToken, $oneshot, $expiresAt);

            return;
        }

        if ($urlCandidate) {
            if (! filter_var($urlCandidate, FILTER_VALIDATE_URL)) {
                http_response_code(400);
                header('Content-Type: text/plain');
                echo "Error: Invalid URL format.\n";

                return;
            }

            $this->db->createItem([
                'id' => bin2hex(random_bytes(8)),
                'slug' => $slug,
                'original_name' => $urlCandidate,
                'type' => 'url',
                'target_url' => $urlCandidate,
                'mime_type' => 'text/plain',
                'size' => strlen($urlCandidate),
                'x_token' => $xToken,
                'is_oneshot' => $oneshot ? 1 : 0,
                'expires_at' => $expiresAt,
                'created_at' => time(),
                'views' => 0,
            ]);

            header("x-token: {$xToken}");
            header('Content-Type: text/plain');
            echo "{$this->getBaseUrl()}/{$slug}\n";

            return;
        }

        http_response_code(400);
        header('Content-Type: text/plain');
        echo "Bad Request: No file or URL provided.\n";
    }

    private function handleRemoteFetch(string $remoteUrl, string $slug, string $xToken, bool $oneshot, ?int $expiresAt): void
    {
        if (! filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Error: Invalid remote URL.\n";

            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'pico_fetch_');
        $fp = fopen($tmpPath, 'w+b');
        if (! $fp) {
            http_response_code(500);
            echo "Error: Failed creating temporary file.\n";

            return;
        }

        $ch = curl_init($remoteUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Fuxico/1.0');
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (! $success || $httpCode >= 400) {
            @unlink($tmpPath);
            http_response_code(502);
            header('Content-Type: text/plain');
            echo "Error: Failed downloading remote URL (HTTP {$httpCode}).\n";

            return;
        }

        $parsedPath = parse_url($remoteUrl, PHP_URL_PATH) ?: 'download';
        $originalName = basename($parsedPath);
        if ($originalName === '' || $originalName === '/') {
            $originalName = 'download.bin';
        }

        $validation = MimeValidator::validate($tmpPath, $originalName, $this->config->maxUploadSize);
        if (! $validation['valid']) {
            @unlink($tmpPath);
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Error: Remote content invalid ({$validation['error']}).\n";

            return;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $targetFilename = $slug.($ext !== '' ? ".{$ext}" : '');
        $this->storage->storeUploadedFile($tmpPath, $targetFilename);
        @unlink($tmpPath);

        $this->db->createItem([
            'id' => bin2hex(random_bytes(8)),
            'slug' => $slug,
            'original_name' => $originalName,
            'type' => 'file',
            'file_path' => $targetFilename,
            'mime_type' => $validation['mime'],
            'size' => (int) filesize($this->storage->getFilePath($targetFilename)),
            'x_token' => $xToken,
            'is_oneshot' => $oneshot ? 1 : 0,
            'expires_at' => $expiresAt,
            'created_at' => time(),
            'views' => 0,
        ]);

        header("x-token: {$xToken}");
        header('Content-Type: text/plain');
        $urlExt = $ext !== '' ? ".{$ext}" : '';
        echo "{$this->getBaseUrl()}/{$slug}{$urlExt}\n";
    }

    private function handleGistUpload(string $slug, string $xToken, bool $oneshot, ?int $expiresAt, string $customName): void
    {
        $files = [];
        if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < $count; $i++) {
                $tmp = $_FILES['files']['tmp_name'][$i];
                $name = $_FILES['files']['name'][$i];
                if ($tmp && is_uploaded_file($tmp) && $_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                    $content = file_get_contents($tmp);
                    if ($content !== false) {
                        $files[basename($name)] = $content;
                    }
                }
            }
        } elseif (isset($_FILES['file']) && is_array($_FILES['file']) && ! empty($_FILES['file']['tmp_name'])) {
            $content = file_get_contents($_FILES['file']['tmp_name']);
            if ($content !== false) {
                $files[$_FILES['file']['name']] = $content;
            }
        }

        if (empty($files)) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Error: No files provided for Gist.\n";

            return;
        }

        $totalBytes = 0;
        foreach ($files as $c) {
            $totalBytes += strlen($c);
        }
        if ($totalBytes > $this->config->maxUploadSize) {
            http_response_code(413);
            header('Content-Type: text/plain');
            echo "Error: Gist payload exceeds maximum allowed size.\n";

            return;
        }

        $this->storage->storeGistFiles($slug, $files);

        $defaultTheme = (isset($_POST['theme']) && strtolower((string) $_POST['theme']) === 'acad') ? 'acad' : 'zine';
        $title = $customName !== '' ? $customName : (array_key_first($files) ?: 'Gist');
        $bakedHtml = GistRenderer::render($files, [
            'title' => $title,
            'slug' => $slug,
            'default_theme' => $defaultTheme,
            'created_at' => time(),
            'base_url' => $this->getBaseUrl(),
        ]);
        file_put_contents($this->storage->getFilePath("{$slug}.html"), $bakedHtml);

        $this->db->createItem([
            'id' => bin2hex(random_bytes(8)),
            'slug' => $slug,
            'original_name' => $title,
            'type' => 'gist',
            'file_path' => "{$slug}.zip",
            'mime_type' => 'application/zip',
            'size' => $totalBytes,
            'x_token' => $xToken,
            'is_oneshot' => $oneshot ? 1 : 0,
            'expires_at' => $expiresAt,
            'created_at' => time(),
            'views' => 0,
        ]);

        header("x-token: {$xToken}");
        header('Content-Type: text/plain');
        echo "{$this->getBaseUrl()}/{$slug}\n";
    }

    private function handleItemRoute(string $path, string $method, ?string $authHeader): void
    {
        // Support subpaths like /<slug>.<ext>/<friendly_name>
        $parts = explode('/', $path);
        $firstPart = $parts[0];
        $slug = explode('.', $firstPart)[0];

        $item = $this->db->findBySlug($slug);
        if (! $item) {
            http_response_code(404);
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            if (str_contains($accept, 'text/html')) {
                header('Content-Type: text/html; charset=UTF-8');
                echo Views::renderErrorPage(404, 'err_404_title', 'err_404_desc', new I18n);
            } else {
                header('Content-Type: text/plain');
                echo "404 Not Found: Essa fofoca já prescreveu (ou você errou o link).\n";
            }

            return;
        }

        // Subpath handling (e.g. /<slug>/raw/<filename>, /<slug>/zip, /<slug>/assets/<filename>)
        if (count($parts) > 1) {
            $subAction = $parts[1];

            // 1. Raw file inside Gist or Git Repo: /<slug>/raw or /<slug>/raw/<filename>
            if ($subAction === 'raw') {
                $requestedFile = implode('/', array_slice($parts, 2));
                if ($item['type'] === 'git') {
                    $repoPath = $this->storage->getRepoPath($slug);
                    $content = GitBackend::getFileContent($repoPath, $requestedFile !== '' ? $requestedFile : 'README.md');
                } else {
                    if ($requestedFile === '') {
                        $files = $this->storage->getGistFiles($slug);
                        $first = array_key_first($files);
                        $requestedFile = $first ?: '';
                    }
                    $content = $this->storage->getGistFile($slug, $requestedFile);
                }
                if ($content !== null) {
                    $ext = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'md', 'markdown' => 'text/markdown; charset=UTF-8',
                        'json' => 'application/json; charset=UTF-8',
                        'html' => 'text/html; charset=UTF-8',
                        'css' => 'text/css; charset=UTF-8',
                        default => 'text/plain; charset=UTF-8',
                    };
                    header('Content-Type: '.$mime);
                    echo $content;

                    return;
                }
            }

            // 2. Download ZIP: /<slug>/zip
            if ($subAction === 'zip') {
                $zipData = $item['type'] === 'git'
                    ? GitBackend::getArchiveZip($this->storage->getRepoPath($slug))
                    : $this->storage->getGistZip($slug);
                if ($zipData !== null) {
                    $safeZipName = ($item['original_name'] ? preg_replace('/[^\w\-]/', '_', $item['original_name']) : $slug).'.zip';
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="'.$safeZipName.'"');
                    header('Content-Length: '.(string) strlen($zipData));
                    echo $zipData;

                    return;
                }
            }

            // 3. Asset inside doc directory (e.g. /<slug>/assets/<filename>)
            $assetName = basename(end($parts));
            $assetPath = $this->storage->getFilesDir()."/{$slug}_assets/{$assetName}";
            if (file_exists($assetPath)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $assetMime = $finfo ? finfo_file($finfo, $assetPath) : false;
                if ($finfo && PHP_VERSION_ID < 80500) {
                    finfo_close($finfo);
                }
                if (! $assetMime) {
                    $assetMime = mime_content_type($assetPath) ?: 'application/octet-stream';
                }
                header('Content-Type: '.$assetMime);
                header('Content-Length: '.(string) filesize($assetPath));
                header('Cache-Control: public, max-age=86400');
                readfile($assetPath);

                return;
            }
        }

        // 1. Rustypaste DELETE /<slug>
        if ($method === 'DELETE') {
            $token = $authHeader;
            if (str_starts_with((string) $token, 'Bearer ')) {
                $token = substr((string) $token, 7);
            }
            $isMaster = $this->config->isAuthorized($authHeader);
            $isOwner = $token === $item['x_token'];

            if (! $isMaster && ! $isOwner) {
                http_response_code(401);
                header('Content-Type: text/plain');
                echo "Unauthorized\n";

                return;
            }

            $this->storage->deleteItemFiles($slug, $item['file_path'] ?? null);
            $this->db->deleteBySlug($slug);
            http_response_code(200);
            header('Content-Type: text/plain');
            echo "File deleted.\n";

            return;
        }

        // 2. 0x0.st Management Endpoint (POST /<slug> with token)
        if ($method === 'POST') {
            $token = $_POST['token'] ?? null;
            if (! $token || $token !== $item['x_token']) {
                http_response_code(401);
                header('Content-Type: text/plain');
                echo "Invalid management token.\n";

                return;
            }

            if (isset($_POST['delete'])) {
                $this->storage->deleteItemFiles($slug, $item['file_path'] ?? null);
                $this->db->deleteBySlug($slug);
                header('Content-Type: text/plain');
                echo "File deleted.\n";

                return;
            }

            if (isset($_POST['expires'])) {
                $newExpires = $this->parseExpiration((string) $_POST['expires']);
                $this->db->updateExpires($slug, $newExpires);
                header('Content-Type: text/plain');
                echo "Expiration updated.\n";

                return;
            }

            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Bad Request: specify 'delete' or 'expires'.\n";

            return;
        }

        // 3. GET /<slug> (Serving File, Doc or Redirecting URL)
        if ($method === 'GET') {
            // Check expiration
            if ($item['expires_at'] !== null && $item['expires_at'] <= time()) {
                $this->storage->deleteItemFiles($slug, $item['file_path'] ?? null);
                $this->db->deleteBySlug($slug);
                http_response_code(404);
                header('Content-Type: text/plain');
                echo "File Expired\n";

                return;
            }

            // Redirect URL
            if ($item['type'] === 'url') {
                $this->db->incrementViews($slug);
                if ($item['is_oneshot']) {
                    $this->db->deleteBySlug($slug);
                }
                header('Location: '.$item['target_url'], true, 302);

                return;
            }

            // Rendered Markdown Document
            if ($item['type'] === 'doc') {
                $this->db->incrementViews($slug);
                $isOneshot = (bool) $item['is_oneshot'];
                if ($isOneshot) {
                    $this->db->deleteBySlug($slug);
                }

                $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
                $isRaw = isset($_GET['raw']) || ! str_contains($accept, 'text/html');

                if ($isRaw) {
                    $this->storage->streamFile($item['file_path'], 'text/plain; charset=UTF-8', $item['original_name'] ?: "{$slug}.md", true, $isOneshot);

                    return;
                }

                $htmlFilename = "{$slug}.html";
                if (! $this->storage->fileExists($htmlFilename)) {
                    $rawMd = file_get_contents($this->storage->getFilePath($item['file_path']));
                    if ($rawMd !== false) {
                        $baked = MarkdownRenderer::render($rawMd, [
                            'title' => pathinfo($item['original_name'] ?: $slug, PATHINFO_FILENAME),
                            'slug' => $slug,
                            'original_name' => $item['original_name'] ?: "{$slug}.md",
                            'created_at' => (int) $item['created_at'],
                            'base_url' => $this->getBaseUrl(),
                        ]);
                        file_put_contents($this->storage->getFilePath($htmlFilename), $baked);
                    }
                }

                $htmlDisplayName = $item['original_name'] ? pathinfo($item['original_name'], PATHINFO_FILENAME).'.html' : "{$slug}.html";
                $this->storage->streamFile($htmlFilename, 'text/html; charset=UTF-8', $htmlDisplayName, true, $isOneshot);

                return;
            }

            // Rendered Gist Package
            if ($item['type'] === 'gist') {
                $this->db->incrementViews($slug);
                $isOneshot = (bool) $item['is_oneshot'];
                if ($isOneshot) {
                    $this->db->deleteBySlug($slug);
                }

                $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
                $isRaw = isset($_GET['raw']) || ! str_contains($accept, 'text/html');

                if ($isRaw) {
                    $files = $this->storage->getGistFiles($slug);
                    header('Content-Type: text/plain; charset=UTF-8');
                    if (count($files) === 1) {
                        echo reset($files);
                    } else {
                        foreach ($files as $name => $content) {
                            echo "=== File: {$name} ===\n";
                            echo $content."\n\n";
                        }
                    }

                    return;
                }

                $htmlFilename = "{$slug}.html";
                if (! $this->storage->fileExists($htmlFilename)) {
                    $files = $this->storage->getGistFiles($slug);
                    $baked = GistRenderer::render($files, [
                        'title' => $item['original_name'] ?: $slug,
                        'slug' => $slug,
                        'created_at' => (int) $item['created_at'],
                        'base_url' => $this->getBaseUrl(),
                    ]);
                    file_put_contents($this->storage->getFilePath($htmlFilename), $baked);
                }

                $this->storage->streamFile($htmlFilename, 'text/html; charset=UTF-8', "{$slug}.html", true, $isOneshot);

                return;
            }

            // Rendered Git Repository
            if ($item['type'] === 'git') {
                $this->db->incrementViews($slug);
                $isOneshot = (bool) $item['is_oneshot'];
                if ($isOneshot) {
                    $this->db->deleteBySlug($slug);
                }

                $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
                $isRaw = isset($_GET['raw']) || ! str_contains($accept, 'text/html');

                if ($isRaw) {
                    $meta = GitBackend::getRepoMetadata($this->storage->getRepoPath($slug));
                    header('Content-Type: text/plain; charset=UTF-8');
                    echo "Fuxico Git Repository: {$slug}\n";
                    echo "Clone URL: {$this->getBaseUrl()}/{$slug}.git\n";
                    echo "Branch: {$meta['default_branch']} ({$meta['commit_count']} commits)\n";
                    if (! empty($meta['files'])) {
                        echo 'Files ('.count($meta['files'])."):\n";
                        foreach ($meta['files'] as $f) {
                            echo "  - {$f['name']} ({$f['size']} bytes)\n";
                        }
                    }

                    return;
                }

                $htmlFilename = "{$slug}.html";
                if (! $this->storage->fileExists($htmlFilename)) {
                    $metadata = GitBackend::getRepoMetadata($this->storage->getRepoPath($slug));
                    $baked = RepoRenderer::render($metadata, [
                        'title' => $item['original_name'] ?: "{$slug}.git",
                        'slug' => $slug,
                        'created_at' => (int) $item['created_at'],
                        'base_url' => $this->getBaseUrl(),
                    ]);
                    file_put_contents($this->storage->getFilePath($htmlFilename), $baked);
                }

                $this->storage->streamFile($htmlFilename, 'text/html; charset=UTF-8', "{$slug}.html", true, $isOneshot);

                return;
            }

            // Stream File
            $this->db->incrementViews($slug);
            $mime = $item['mime_type'] ?: 'application/octet-stream';
            $inline = str_starts_with($mime, 'image/') ||
                      str_starts_with($mime, 'video/') ||
                      str_starts_with($mime, 'audio/') ||
                      str_starts_with($mime, 'text/') ||
                      $mime === 'application/pdf';

            $displayName = count($parts) > 1 ? end($parts) : ($item['original_name'] ?: $item['file_path']);
            $isOneshot = (bool) $item['is_oneshot'];

            if ($isOneshot) {
                $this->db->deleteBySlug($slug);
            }

            $this->storage->streamFile($item['file_path'], $mime, $displayName, $inline, $isOneshot);

            return;
        }

        http_response_code(405);
        header('Content-Type: text/plain');
        echo "Method Not Allowed\n";
    }

    private function handleList(?string $authHeader): void
    {
        if (! $this->config->isAuthorized($authHeader)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Ficha ou token de acesso inválido ou ausente. Forneça o token via Authorization: Bearer <token> ou use a flag -t no fux.',
            ]);

            return;
        }

        $items = $this->db->listItems(500, 0, null, 'file');
        $list = [];
        foreach ($items as $item) {
            if ($item['is_oneshot']) {
                continue;
            }
            $list[] = [
                'file_name' => $item['file_path'] ?: $item['slug'],
                'file_size' => (int) $item['size'],
                'expires_at' => $item['expires_at'],
                'url' => "{$this->getBaseUrl()}/{$item['file_path']}",
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function handleApi(string $endpoint, string $method, ?string $authHeader): void
    {
        $isAuthorized = $this->config->isAuthorized($authHeader);

        // In private mode, all API endpoints require authorization
        // In public mode, GET stats and items are publicly readable
        if (! $this->config->publicMode && ! $isAuthorized) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Ficha ou token de acesso inválido ou ausente. Esta rota requer autenticação.',
            ]);

            return;
        }

        if ($endpoint === 'gist' && $method === 'POST') {
            $this->handleGistApi($authHeader);

            return;
        }

        if ($endpoint === 'git' && $method === 'POST') {
            $this->handleGitApi($authHeader);

            return;
        }

        if ($endpoint === 'stats' && $method === 'GET') {
            header('Content-Type: application/json');
            echo json_encode($this->db->getStats());

            return;
        }

        if ($endpoint === 'items' && $method === 'GET') {
            $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $search = isset($_GET['search']) ? (string) $_GET['search'] : null;
            $type = isset($_GET['type']) ? (string) $_GET['type'] : null;

            $items = $this->db->listItems($limit, $offset, $search, $type);
            $total = $this->db->countItems($search, $type);

            header('Content-Type: application/json');
            echo json_encode([
                'items' => array_map(function ($i) {
                    $i['url'] = "{$this->getBaseUrl()}/".($i['type'] === 'file' ? ($i['file_path'] ?: $i['slug']) : $i['slug']);

                    return $i;
                }, $items),
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);

            return;
        }

        if (str_starts_with($endpoint, 'items/') && $method === 'DELETE') {
            if (! $isAuthorized) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Ficha ou token de acesso inválido ou ausente para exclusão.',
                ]);

                return;
            }

            $slug = substr($endpoint, 6);
            $item = $this->db->findBySlug($slug);
            if (! $item) {
                http_response_code(404);
                echo json_encode(['error' => 'Item not found']);

                return;
            }
            $this->storage->deleteItemFiles($slug, $item['file_path'] ?? null);
            $this->db->deleteBySlug($slug);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);

            return;
        }

        if (str_starts_with($endpoint, 'items/') && ($method === 'PATCH' || $method === 'PUT')) {
            if (! $isAuthorized) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Ficha ou token de acesso inválido ou ausente para edição.',
                ]);

                return;
            }

            $slug = substr($endpoint, 6);
            $item = $this->db->findBySlug($slug);
            if (! $item) {
                http_response_code(404);
                echo json_encode(['error' => 'Item not found']);

                return;
            }

            $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

            // Change Type (file <-> doc)
            if (isset($body['type']) && in_array($body['type'], ['file', 'doc'], true)) {
                $newType = (string) $body['type'];
                $this->db->updateType($slug, $newType);

                if ($newType === 'doc' && ! empty($item['file_path'])) {
                    $rawMd = file_get_contents($this->storage->getFilePath($item['file_path']));
                    if ($rawMd !== false) {
                        $baked = MarkdownRenderer::render($rawMd, [
                            'title' => pathinfo($item['original_name'] ?: $slug, PATHINFO_FILENAME),
                            'slug' => $slug,
                            'original_name' => $item['original_name'] ?: "{$slug}.md",
                            'created_at' => (int) $item['created_at'],
                            'base_url' => $this->getBaseUrl(),
                        ]);
                        file_put_contents($this->storage->getFilePath("{$slug}.html"), $baked);
                    }
                } elseif ($newType === 'file') {
                    $htmlFile = $this->storage->getFilePath("{$slug}.html");
                    if (file_exists($htmlFile)) {
                        @unlink($htmlFile);
                    }
                }
            }

            // Edit Content
            if (isset($body['content']) && is_string($body['content']) && ! empty($item['file_path'])) {
                file_put_contents($this->storage->getFilePath($item['file_path']), $body['content']);
                $newSize = strlen($body['content']);
                $this->db->updateContent($slug, $newSize);

                if ($item['type'] === 'doc' || ($body['type'] ?? '') === 'doc') {
                    $baked = MarkdownRenderer::render($body['content'], [
                        'title' => pathinfo($item['original_name'] ?: $slug, PATHINFO_FILENAME),
                        'slug' => $slug,
                        'original_name' => $item['original_name'] ?: "{$slug}.md",
                        'created_at' => (int) $item['created_at'],
                        'base_url' => $this->getBaseUrl(),
                    ]);
                    file_put_contents($this->storage->getFilePath("{$slug}.html"), $baked);
                }
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);

            return;
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }

    private function handleGistApi(?string $authHeader): void
    {
        if (! $this->config->publicMode && ! $this->config->isAuthorized($authHeader)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Ficha ou token de acesso inválido ou ausente para publicar gists.',
            ]);

            return;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = str_contains($contentType, 'application/json');

        $files = [];
        $title = '';
        $expires = null;
        $oneshot = false;
        $secret = false;
        $defaultTheme = 'zine';

        if ($isJson) {
            $body = file_get_contents('php://input');
            if (! $body && isset($_POST['json'])) {
                $body = (string) $_POST['json'];
            }
            $data = json_decode($body ?: '', true);
            if (! is_array($data) || empty($data['files'])) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Missing or invalid "files" object.']);

                return;
            }

            $title = (string) ($data['title'] ?? $data['description'] ?? '');
            $expires = $data['expires'] ?? $data['expiration'] ?? null;
            $oneshot = ! empty($data['oneshot']);
            $secret = ! empty($data['secret']);
            if (isset($data['theme']) && strtolower((string) $data['theme']) === 'acad') {
                $defaultTheme = 'acad';
            }

            foreach ($data['files'] as $name => $fileData) {
                $content = is_array($fileData) ? ($fileData['content'] ?? '') : (string) $fileData;
                $files[(string) $name] = $content;
            }
        } else {
            // Multipart
            if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
                $count = count($_FILES['files']['name']);
                for ($i = 0; $i < $count; $i++) {
                    $tmp = $_FILES['files']['tmp_name'][$i];
                    $name = $_FILES['files']['name'][$i];
                    if ($tmp && is_uploaded_file($tmp) && $_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                        $content = file_get_contents($tmp);
                        if ($content !== false) {
                            $files[basename($name)] = $content;
                        }
                    }
                }
            } elseif (isset($_FILES['file']) && is_array($_FILES['file']) && ! empty($_FILES['file']['tmp_name'])) {
                $content = file_get_contents($_FILES['file']['tmp_name']);
                if ($content !== false) {
                    $files[$_FILES['file']['name']] = $content;
                }
            }

            $title = (string) ($_POST['title'] ?? $_POST['description'] ?? $_POST['customName'] ?? '');
            $expires = $_POST['expires'] ?? null;
            $oneshot = isset($_POST['oneshot']) && filter_var($_POST['oneshot'], FILTER_VALIDATE_BOOLEAN);
            $secret = isset($_POST['secret']) && filter_var($_POST['secret'], FILTER_VALIDATE_BOOLEAN);
            if (isset($_POST['theme']) && strtolower((string) $_POST['theme']) === 'acad') {
                $defaultTheme = 'acad';
            }
        }

        if (empty($files)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No files provided for Gist.']);

            return;
        }

        $totalBytes = 0;
        foreach ($files as $c) {
            $totalBytes += strlen($c);
        }
        if ($totalBytes > $this->config->maxUploadSize) {
            http_response_code(413);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Gist payload exceeds maximum allowed size.']);

            return;
        }

        $expiresAt = $this->parseExpiration($expires ? (string) $expires : null);
        $slug = $this->generateSlug($secret);
        $xToken = bin2hex(random_bytes(16));

        $this->storage->storeGistFiles($slug, $files);

        $firstFilename = array_key_first($files) ?: 'Gist';
        $finalTitle = $title !== '' ? $title : $firstFilename;

        $bakedHtml = GistRenderer::render($files, [
            'title' => $finalTitle,
            'slug' => $slug,
            'default_theme' => $defaultTheme,
            'created_at' => time(),
            'base_url' => $this->getBaseUrl(),
        ]);
        file_put_contents($this->storage->getFilePath("{$slug}.html"), $bakedHtml);

        $this->db->createItem([
            'id' => bin2hex(random_bytes(8)),
            'slug' => $slug,
            'original_name' => $finalTitle,
            'type' => 'gist',
            'file_path' => "{$slug}.zip",
            'mime_type' => 'application/zip',
            'size' => $totalBytes,
            'x_token' => $xToken,
            'is_oneshot' => $oneshot ? 1 : 0,
            'expires_at' => $expiresAt,
            'created_at' => time(),
            'views' => 0,
        ]);

        header("x-token: {$xToken}");
        $url = "{$this->getBaseUrl()}/{$slug}";

        if ($isJson || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode([
                'slug' => $slug,
                'url' => $url,
                'raw_url' => "{$url}/raw",
                'zip_url' => "{$url}/zip",
                'x_token' => $xToken,
                'files' => array_keys($files),
                'total_bytes' => $totalBytes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            header('Content-Type: text/plain');
            echo "{$url}\n";
        }
    }

    private function handleGitRoute(string $slug, string $gitAction, string $method, ?string $authHeader): void
    {
        $repoPath = $this->storage->getRepoPath($slug);
        [$user, $pass] = $this->getGitAuthCredentials($authHeader);
        $token = ($pass !== null && $pass !== '') ? $pass : ($user ?? '');

        if ($gitAction === 'info/refs') {
            $service = $_GET['service'] ?? '';
            if ($service === 'git-upload-pack') {
                if (! is_dir($repoPath)) {
                    http_response_code(404);
                    header('Content-Type: text/plain');
                    echo "Repository not found\n";

                    return;
                }
                GitBackend::handleInfoRefs($repoPath, $service);

                return;
            }

            if ($service === 'git-receive-pack') {
                $item = $this->db->findBySlug($slug);

                if ($item) {
                    $isMaster = $this->config->isAuthorized($authHeader) || in_array($token, $this->config->authTokens, true);
                    $isOwner = $token !== '' && $token === $item['x_token'];
                    if (! $isMaster && ! $isOwner) {
                        header('WWW-Authenticate: Basic realm="Fuxico Git"');
                        http_response_code(401);
                        echo "Authentication required\n";

                        return;
                    }
                } else {
                    // Push-to-create: repository doesn't exist yet
                    if ($token === '') {
                        header('WWW-Authenticate: Basic realm="Fuxico Git"');
                        http_response_code(401);
                        echo "Authentication required to create repository\n";

                        return;
                    }

                    $isMaster = $this->config->isAuthorized($authHeader) || in_array($token, $this->config->authTokens, true);
                    if (! $this->config->publicMode && ! $isMaster) {
                        http_response_code(401);
                        echo "Unauthorized\n";

                        return;
                    }

                    GitBackend::initBareRepo($repoPath);
                    $this->db->createItem([
                        'id' => bin2hex(random_bytes(8)),
                        'slug' => $slug,
                        'original_name' => "{$slug}.git",
                        'type' => 'git',
                        'file_path' => "{$slug}.git",
                        'mime_type' => 'application/x-git',
                        'size' => 0,
                        'x_token' => $token,
                        'is_oneshot' => 0,
                        'expires_at' => null,
                        'created_at' => time(),
                        'views' => 0,
                    ]);

                    $html = RepoRenderer::render(['has_commits' => false], [
                        'slug' => $slug,
                        'base_url' => $this->getBaseUrl(),
                        'title' => "{$slug}.git",
                    ]);
                    file_put_contents($this->storage->getFilePath("{$slug}.html"), $html);
                }

                GitBackend::handleInfoRefs($repoPath, $service);

                return;
            }

            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Invalid git service\n";

            return;
        }

        if ($gitAction === 'git-upload-pack' && $method === 'POST') {
            if (! is_dir($repoPath)) {
                http_response_code(404);
                header('Content-Type: text/plain');
                echo "Repository not found\n";

                return;
            }
            GitBackend::handleRpc($repoPath, 'git-upload-pack');

            return;
        }

        if ($gitAction === 'git-receive-pack' && $method === 'POST') {
            $item = $this->db->findBySlug($slug);
            $isMaster = $this->config->isAuthorized($authHeader) || in_array($token, $this->config->authTokens, true);
            $isOwner = $item && $token !== '' && $token === $item['x_token'];

            if (! $isMaster && ! $isOwner) {
                http_response_code(401);
                header('Content-Type: text/plain');
                echo "Unauthorized\n";

                return;
            }

            $exitCode = GitBackend::handleRpc($repoPath, 'git-receive-pack');
            if ($exitCode === 0 && is_dir($repoPath)) {
                $metadata = GitBackend::getRepoMetadata($repoPath);
                $html = RepoRenderer::render($metadata, [
                    'slug' => $slug,
                    'base_url' => $this->getBaseUrl(),
                    'title' => $item['original_name'] ?? "{$slug}.git",
                    'created_at' => (int) ($item['created_at'] ?? time()),
                ]);
                file_put_contents($this->storage->getFilePath("{$slug}.html"), $html);
                $this->db->updateContent($slug, $metadata['size'] ?? 0);
            }

            return;
        }

        http_response_code(405);
        header('Content-Type: text/plain');
        echo "Method Not Allowed\n";
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function getGitAuthCredentials(?string $authHeader): array
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $pass = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ($user === null && $pass === null && $authHeader !== null) {
            if (str_starts_with($authHeader, 'Basic ')) {
                $decoded = base64_decode(substr($authHeader, 6), true);
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$user, $pass] = explode(':', $decoded, 2);
                }
            } elseif (str_starts_with($authHeader, 'Bearer ')) {
                $pass = substr($authHeader, 7);
            }
        }

        return [$user, $pass];
    }

    private function handleGitApi(?string $authHeader): void
    {
        $isAuthorized = $this->config->isAuthorized($authHeader);
        if (! $this->config->publicMode && ! $isAuthorized) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Ficha ou token de acesso inválido ou ausente para criar repositórios git.',
            ]);

            return;
        }

        $rawBody = (string) file_get_contents('php://input');
        $jsonData = json_decode($rawBody, true);
        $data = is_array($jsonData) ? $jsonData : $_POST;

        $name = trim((string) ($data['name'] ?? $data['slug'] ?? ''));
        $slug = $name !== '' ? preg_replace('/[^\w\-]/', '-', $name) : $this->generateSlug();

        if ($slug === '' || $this->db->isSlugTaken($slug)) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Slug already exists or is invalid']);

            return;
        }

        $xToken = trim((string) ($data['token'] ?? ''));
        if ($xToken === '') {
            $xToken = bin2hex(random_bytes(16));
        }

        $expireParam = $data['expires'] ?? $data['expire'] ?? null;
        $expiresAt = $this->parseExpiration($expireParam ? (string) $expireParam : null);

        $repoPath = $this->storage->getRepoPath($slug);
        if (! GitBackend::initBareRepo($repoPath)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to initialize git repository']);

            return;
        }

        $this->db->createItem([
            'id' => bin2hex(random_bytes(8)),
            'slug' => $slug,
            'original_name' => "{$slug}.git",
            'type' => 'git',
            'file_path' => "{$slug}.git",
            'mime_type' => 'application/x-git',
            'size' => 0,
            'x_token' => $xToken,
            'is_oneshot' => 0,
            'expires_at' => $expiresAt,
            'created_at' => time(),
            'views' => 0,
        ]);

        $html = RepoRenderer::render(['has_commits' => false], [
            'slug' => $slug,
            'base_url' => $this->getBaseUrl(),
            'title' => "{$slug}.git",
        ]);
        file_put_contents($this->storage->getFilePath("{$slug}.html"), $html);

        $cloneUrl = "{$this->getBaseUrl()}/{$slug}.git";
        $webUrl = "{$this->getBaseUrl()}/{$slug}";

        header("x-token: {$xToken}");
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'slug' => $slug,
            'clone_url' => $cloneUrl,
            'web_url' => $webUrl,
            'x_token' => $xToken,
            'expires_at' => $expiresAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function handlePainel(string $method): void
    {
        if ($method !== 'GET') {
            http_response_code(405);
            header('Content-Type: text/plain');
            echo "Method Not Allowed\n";

            return;
        }

        $i18n = new I18n;
        echo Views::renderDashboardUI($this->config, $i18n);
    }

    private function handleRootGet(): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $i18n = new I18n;
        if (str_contains($accept, 'text/html')) {
            echo Views::renderWebUI($this->config, $i18n);

            return;
        }

        // Plain text banner for CLI curl
        header('Content-Type: text/plain');
        $tagline = $i18n->t('tagline');
        echo <<<TEXT
  ___ _   ___  _____ ___  ___ 
 | __| | | \ \/ /_ _/ __|/ _ \
 | _|| |_| |>  < | | (__| (_) |
 |_|  \___//_/\_\___\___|\___/ 
 {$tagline}

Upload a file / Solta o verbo:
    curl -F 'file=@yourfile.png' {$this->getBaseUrl()}/

Burn after reading / Queima de arquivo:
    curl -F 'file=@yourfile.png' -F 'oneshot=true' {$this->getBaseUrl()}/

Upload with expiration (e.g. 2h / 24 hours):
    curl -F 'file=@yourfile.png' -F 'expires=2h' {$this->getBaseUrl()}/

Shorten a URL / Encurtar fofoca:
    curl -F 'shorten=https://example.com' {$this->getBaseUrl()}/

Delete file with token / Queimar segredo:
    curl -F 'token=<X-Token>' -F 'delete=' {$this->getBaseUrl()}/<slug>

List files / Listar bafões (rustypaste API):
    curl -H 'Authorization: <token>' {$this->getBaseUrl()}/list

CLI tool available:
    ./bin/fux <file>
    cat dump.sql | ./bin/fux -q

TEXT;
    }
}
