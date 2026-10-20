<?php

declare(strict_types=1);

namespace Fuxico;

class App
{
    private Config $config;

    private Database $db;

    private Storage $storage;

    private ?string $mockRawInput = null;

    public function __construct(?Config $config = null, ?Database $db = null, ?Storage $storage = null)
    {
        $this->config = $config ?? new Config;
        $this->db = $db ?? new Database($this->config->dbPath);
        $this->storage = $storage ?? new Storage($this->config->dataDir, $this->config->filesDir);
    }

    public function setMockRawInput(?string $input): void
    {
        $this->mockRawInput = $input;
    }

    public function getRawInput(): string
    {
        if ($this->mockRawInput !== null) {
            return $this->mockRawInput;
        }

        return (string) file_get_contents('php://input');
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
        if ($method === 'HEAD') {
            $method = 'GET';
        }
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

    /**
     * Resolve caller authentication into user context.
     *
     * @return array{authenticated: bool, role: string, user_id: ?string, username: string, is_master: bool}|null
     */
    public function resolveAuth(?string $authHeader): ?array
    {
        if ($authHeader === null || trim($authHeader) === '') {
            $tokenParam = $_POST['token'] ?? $_GET['token'] ?? null;
            if ($tokenParam !== null && is_string($tokenParam) && trim($tokenParam) !== '') {
                $authHeader = trim($tokenParam);
            } else {
                return null;
            }
        }

        $token = trim($authHeader);
        if (str_starts_with($token, 'Bearer ')) {
            $token = trim(substr($token, 7));
        }

        if ($token === '') {
            return null;
        }

        // 1. Check Master Tokens in Config (FUXICO_AUTH_TOKENS)
        if ($this->config->isAuthorized($token)) {
            return [
                'authenticated' => true,
                'role' => 'admin',
                'user_id' => null,
                'username' => 'mestre',
                'is_master' => true,
            ];
        }

        // 2. Check Database users table
        $user = $this->db->findUserByToken($token);
        if ($user) {
            return [
                'authenticated' => true,
                'role' => (string) ($user['role'] ?? 'member'),
                'user_id' => (string) $user['id'],
                'username' => (string) $user['username'],
                'is_master' => false,
            ];
        }

        return null;
    }

    private function handleUpload(?string $authHeader): void
    {
        $auth = $this->resolveAuth($authHeader);
        if (! $this->config->publicMode && $auth === null) {
            http_response_code(401);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "401 Unauthorized: Ficha ou token de acesso inválido ou ausente. Esta instância é privada. Envie o token via 'Authorization: Bearer <token>' ou use 'fux -t <ficha>'.\n";

            return;
        }

        $userId = $auth['user_id'] ?? null;

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
                $existing = $this->db->findBySlug($slug);
                $isOwner = $existing && $auth && (
                    $auth['is_master'] ||
                    (! empty($existing['user_id']) && $auth['user_id'] === $existing['user_id'])
                );

                if ($isOwner) {
                    $this->storage->deleteItemFiles($slug, $existing['file_path'] ?? null);
                    $this->db->deleteBySlug($slug);
                } else {
                    http_response_code(409);
                    header('Content-Type: text/plain');
                    echo "Error: Name '{$customName}' is already taken or invalid.\n";

                    return;
                }
            }
        } else {
            $slug = $this->generateSlug($isSecret);
        }

        $xToken = bin2hex(random_bytes(16));

        // 0. Multi-file Gist Upload (via files[] or type=gist)
        $isGistUpload = (isset($_FILES['files']) && is_array($_FILES['files']['name'])) ||
                        (isset($_POST['type']) && $_POST['type'] === 'gist');
        if ($isGistUpload) {
            $this->handleGistUpload($slug, $xToken, $oneshot, $expiresAt, $customName, $userId);

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
                    $renderOptions = [
                        'title' => pathinfo($file['name'], PATHINFO_FILENAME),
                        'slug' => $slug,
                        'original_name' => $file['name'],
                        'created_at' => time(),
                        'base_url' => $this->getBaseUrl(),
                    ];
                    if (isset($_POST['theme'])) {
                        $renderOptions['default_theme'] = strtolower((string) $_POST['theme']) === 'acad' ? 'acad' : 'zine';
                    }
                    $bakedHtml = MarkdownRenderer::render($rawMd, $renderOptions);
                    file_put_contents($this->storage->getFilePath("{$slug}.html"), $bakedHtml);

                    $cachedPdf = $this->storage->getFilesDir()."/{$slug}.pdf";
                    if (file_exists($cachedPdf)) {
                        @unlink($cachedPdf);
                    }
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
                'user_id' => $userId,
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
            $this->handleRemoteFetch($urlCandidate, $slug, $xToken, $oneshot, $expiresAt, $userId);

            return;
        }

        if ($urlCandidate) {
            if (! filter_var($urlCandidate, FILTER_VALIDATE_URL)) {
                http_response_code(400);
                header('Content-Type: text/plain');
                echo "Error: Invalid URL format.\n";

                return;
            }

            if (isset($_POST['resenha']) || isset($_POST['distill'])) {
                $this->handleResenhaDistill($urlCandidate, $slug, $xToken, $oneshot, $expiresAt, $userId);

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
                'user_id' => $userId,
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

    private function handleResenhaDistill(string $targetUrl, string $slug, string $xToken, bool $oneshot, ?int $expiresAt, ?string $userId = null): void
    {
        $resenhaBase = rtrim(! empty($this->config->resenhaUrl) ? $this->config->resenhaUrl : ((string) getenv('RESENHA_API_URL')), '/');
        if ($resenhaBase === '') {
            http_response_code(503);
            header('Content-Type: text/plain');
            echo "Error: RESENHA_API_URL não configurada no servidor.\n";

            return;
        }

        $endpoint = "{$resenhaBase}/?url=".urlencode($targetUrl).'&format=json';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config->resenhaTimeout ?? 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($httpCode !== 200 || ! $response) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo "Error: Falha ao destilar artigo via Resenha (HTTP {$httpCode}).\n";

            return;
        }

        $data = json_decode((string) $response, true);
        if (! is_array($data) || empty($data['markdown'])) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo "Error: Resposta inválida do motor Resenha.\n";

            return;
        }

        $rawMd = (string) $data['markdown'];
        $targetFilename = "{$slug}.md";
        file_put_contents($this->storage->getFilePath($targetFilename), $rawMd);

        $renderOptions = [
            'title' => (string) ($data['title'] ?? 'Resenha'),
            'slug' => $slug,
            'original_name' => $targetFilename,
            'created_at' => time(),
            'base_url' => $this->getBaseUrl(),
        ];
        $bakedHtml = MarkdownRenderer::render($rawMd, $renderOptions);
        file_put_contents($this->storage->getFilePath("{$slug}.html"), $bakedHtml);

        if (! empty($data['assets']) && is_array($data['assets'])) {
            $assetsDir = $this->storage->getFilesDir()."/{$slug}_assets";
            if (! is_dir($assetsDir)) {
                mkdir($assetsDir, 0755, true);
            }
            foreach ($data['assets'] as $asset) {
                if (! empty($asset['filename']) && ! empty($asset['data_base64'])) {
                    $cleanName = basename((string) $asset['filename']);
                    $bytes = base64_decode((string) $asset['data_base64']);
                    if ($bytes !== false) {
                        file_put_contents("{$assetsDir}/{$cleanName}", $bytes);
                    }
                }
            }
        }

        $this->db->createItem([
            'id' => bin2hex(random_bytes(8)),
            'slug' => $slug,
            'original_name' => (string) ($data['title'] ?? $targetUrl),
            'type' => 'doc',
            'file_path' => $targetFilename,
            'mime_type' => 'text/markdown',
            'size' => strlen($rawMd),
            'x_token' => $xToken,
            'is_oneshot' => $oneshot ? 1 : 0,
            'expires_at' => $expiresAt,
            'created_at' => time(),
            'views' => 0,
            'user_id' => $userId,
        ]);

        http_response_code(200);
        header("x-token: {$xToken}");
        header('Content-Type: text/plain');
        echo "{$this->getBaseUrl()}/{$slug}\n";
    }

    private function handleRemoteFetch(string $remoteUrl, string $slug, string $xToken, bool $oneshot, ?int $expiresAt, ?string $userId = null): void
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
            'user_id' => $userId,
        ]);

        header("x-token: {$xToken}");
        header('Content-Type: text/plain');
        $urlExt = $ext !== '' ? ".{$ext}" : '';
        echo "{$this->getBaseUrl()}/{$slug}{$urlExt}\n";
    }

    private function handleGistUpload(string $slug, string $xToken, bool $oneshot, ?int $expiresAt, string $customName, ?string $userId = null): void
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
            'user_id' => $userId,
        ]);

        http_response_code(200);
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
            // Fallback para assets de documentos requisitados como /assets/<filename> (resolução relativa em navegadores)
            if ($firstPart === 'assets' && count($parts) > 1) {
                $assetFilename = basename(end($parts));
                $targetAssetPath = null;
                $referer = $_SERVER['HTTP_REFERER'] ?? '';
                if ($referer !== '') {
                    $refPath = trim((string) parse_url($referer, PHP_URL_PATH), '/');
                    $refSlug = explode('/', $refPath)[0];
                    $candidate = $this->storage->getFilesDir()."/{$refSlug}_assets/{$assetFilename}";
                    if (file_exists($candidate)) {
                        $targetAssetPath = $candidate;
                    }
                }
                if ($targetAssetPath === null) {
                    $matches = glob($this->storage->getFilesDir()."/*_assets/{$assetFilename}");
                    if (! empty($matches)) {
                        $targetAssetPath = $matches[0];
                    }
                }
                if ($targetAssetPath && file_exists($targetAssetPath)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $assetMime = $finfo ? finfo_file($finfo, $targetAssetPath) : false;
                    if ($finfo && PHP_VERSION_ID < 80500) {
                        finfo_close($finfo);
                    }
                    if (! $assetMime) {
                        $assetMime = mime_content_type($targetAssetPath) ?: 'application/octet-stream';
                    }
                    header('Content-Type: '.$assetMime);
                    header('Content-Length: '.(string) filesize($targetAssetPath));
                    header('Cache-Control: public, max-age=86400');
                    readfile($targetAssetPath);

                    return;
                }
            }

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

            // 0. Widget de embed JavaScript: /<slug>/embed.js
            if ($subAction === 'embed.js') {
                if (! in_array($item['type'], ['gist', 'file'], true)) {
                    http_response_code(404);
                    header('Content-Type: text/plain');
                    echo "404 Not Found: embed.js só disponível para gists e arquivos.\n";

                    return;
                }

                $embedFile = trim((string) ($_GET['file'] ?? ''));
                $embedTheme = trim((string) ($_GET['theme'] ?? ''));

                if ($item['type'] === 'gist') {
                    $files = $this->storage->getGistFiles($slug);
                } else {
                    $singleContent = $this->storage->getFileContent($slug);
                    $originalName = $item['original_name'] ?: $slug;
                    $files = $singleContent !== null ? [$originalName => $singleContent] : [];
                }

                if (empty($files)) {
                    http_response_code(404);
                    header('Content-Type: text/plain');
                    echo "404 Not Found: Nenhum arquivo encontrado.\n";

                    return;
                }

                $baseUrl = rtrim((string) ($_SERVER['REQUEST_SCHEME'] ?? 'https').'://'.$_SERVER['HTTP_HOST'], '/');
                $js = GistRenderer::renderEmbedJs($files, [
                    'slug' => $slug,
                    'base_url' => $baseUrl,
                    'theme' => $embedTheme,
                    'file' => $embedFile,
                    'gist_url' => "{$baseUrl}/{$slug}",
                ]);

                header('Content-Type: application/javascript; charset=UTF-8');
                header('Cache-Control: public, max-age=300');
                echo $js;

                return;
            }

            // 1. Raw file inside Gist, Git Repo, or Single File: /<slug>/raw, /<slug>/r
            if ($subAction === 'raw' || $subAction === 'r') {
                if ($item['type'] === 'file') {
                    $this->streamRawFileItem($item, $slug, $parts);

                    return;
                }

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
                if ($item['type'] === 'folder') {
                    $this->handleFolderZipDownload($item);

                    return;
                }
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

            // 4. Download PDF compiled via Joaju: /<slug>/pdf
            if ($subAction === 'pdf') {
                $pdfPath = $this->compileDocToPdf($slug);
                if ($pdfPath !== null && file_exists($pdfPath)) {
                    $downloadFilename = ($item['original_name'] ? pathinfo($item['original_name'], PATHINFO_FILENAME) : $slug).'.pdf';
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="'.$downloadFilename.'"');
                    header('Content-Length: '.(string) filesize($pdfPath));
                    header('Cache-Control: public, max-age=86400');
                    readfile($pdfPath);

                    return;
                }

                http_response_code(502);
                header('Content-Type: text/plain; charset=UTF-8');
                echo "Erro ao compilar PDF via Joaju.\n";

                return;
            }
        }

        // 1. Rustypaste DELETE /<slug>
        if ($method === 'DELETE') {
            $token = $authHeader;
            if (str_starts_with((string) $token, 'Bearer ')) {
                $token = substr((string) $token, 7);
            }
            $auth = $this->resolveAuth($authHeader);
            $isMaster = $auth !== null && $auth['role'] === 'admin';
            $isUserOwner = $auth !== null && ! empty($item['user_id']) && $item['user_id'] === $auth['user_id'];
            $isOwner = ($token !== null && $token !== '' && $token === $item['x_token']) || $isUserOwner;

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
                $maxDownloads = isset($item['max_downloads']) && $item['max_downloads'] !== null ? (int) $item['max_downloads'] : null;
                $viewsAfter = (int) $item['views'] + 1;
                $shouldBurn = (bool) $item['is_oneshot'] || ($maxDownloads !== null && $viewsAfter >= $maxDownloads);
                if ($shouldBurn) {
                    $this->db->deleteBySlug($slug);
                }
                header('Location: '.$item['target_url'], true, 302);

                return;
            }

            // Pasta Compartilhada (Nativa ou Mocambo)
            if ($item['type'] === 'folder') {
                $this->db->incrementViews($slug);
                $filesData = ! empty($item['data']) ? (json_decode((string) $item['data'], true)['files'] ?? []) : [];
                $i18n = (new I18n)->getAll();
                header('Content-Type: text/html; charset=UTF-8');
                echo Views::renderFolderView($item, $filesData, $i18n);

                return;
            }

            // Rendered Markdown Document
            if ($item['type'] === 'doc') {
                // Suporte a requisição direta de PDF: /<slug>.pdf
                if (str_ends_with($firstPart, '.pdf')) {
                    $pdfPath = $this->compileDocToPdf($slug);
                    if ($pdfPath !== null && file_exists($pdfPath)) {
                        $downloadFilename = ($item['original_name'] ? pathinfo($item['original_name'], PATHINFO_FILENAME) : $slug).'.pdf';
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: inline; filename="'.$downloadFilename.'"');
                        header('Content-Length: '.(string) filesize($pdfPath));
                        header('Cache-Control: public, max-age=86400');
                        readfile($pdfPath);

                        return;
                    }
                }

                $this->db->incrementViews($slug);
                $maxDownloads = isset($item['max_downloads']) && $item['max_downloads'] !== null ? (int) $item['max_downloads'] : null;
                $viewsAfter = (int) $item['views'] + 1;
                $shouldBurn = (bool) $item['is_oneshot'] || ($maxDownloads !== null && $viewsAfter >= $maxDownloads);
                if ($shouldBurn) {
                    $this->storage->deleteItemFiles($slug, $item['file_path'] ?? null);
                    $this->db->deleteBySlug($slug);
                }

                $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
                $isRaw = isset($_GET['raw']) || ! str_contains($accept, 'text/html');

                if ($isRaw) {
                    $this->storage->streamFile($item['file_path'], 'text/plain; charset=UTF-8', $item['original_name'] ?: "{$slug}.md", true, $shouldBurn);

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
                $this->storage->streamFile($htmlFilename, 'text/html; charset=UTF-8', $htmlDisplayName, true, $shouldBurn);

                return;
            }

            // Rendered Gist Package
            if ($item['type'] === 'gist') {
                $this->db->incrementViews($slug);
                $maxDownloads = isset($item['max_downloads']) && $item['max_downloads'] !== null ? (int) $item['max_downloads'] : null;
                $viewsAfter = (int) $item['views'] + 1;
                $shouldBurn = (bool) $item['is_oneshot'] || ($maxDownloads !== null && $viewsAfter >= $maxDownloads);
                if ($shouldBurn) {
                    $this->storage->deleteItemFiles($slug, $item['file_path'] ?? null);
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

                $this->storage->streamFile($htmlFilename, 'text/html; charset=UTF-8', "{$slug}.html", true, $shouldBurn);

                return;
            }

            // Rendered Git Repository
            if ($item['type'] === 'git') {
                $this->db->incrementViews($slug);
                $maxDownloads = isset($item['max_downloads']) && $item['max_downloads'] !== null ? (int) $item['max_downloads'] : null;
                $viewsAfter = (int) $item['views'] + 1;
                $shouldBurn = (bool) $item['is_oneshot'] || ($maxDownloads !== null && $viewsAfter >= $maxDownloads);
                if ($shouldBurn) {
                    $this->storage->deleteItemFiles($slug, $item['file_path'] ?? null);
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

                $this->storage->streamFile($htmlFilename, 'text/html; charset=UTF-8', "{$slug}.html", true, $shouldBurn);

                return;
            }

            // Stream File
            // Página de Pré-Download para navegadores web (estilo Google Drive/Dropbox)
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $isRaw = isset($_GET['raw']) || isset($_GET['r']) || isset($_GET['download']);
            $isTerminal = (bool) preg_match('/^(curl|wget|httpie|aria2|fetch)/i', $userAgent);
            $isBrowserHtml = str_contains($accept, 'text/html') && ! $isTerminal;

            if ($isBrowserHtml && ! $isRaw) {
                $i18n = (new I18n)->getAll();
                header('Content-Type: text/html; charset=UTF-8');
                echo Views::renderPreDownload($item, $i18n);

                return;
            }

            $this->streamRawFileItem($item, $slug, $parts);

            return;
        }

        http_response_code(405);
        header('Content-Type: text/plain');
        echo "Method Not Allowed\n";
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $parts
     */
    private function streamRawFileItem(array $item, string $slug, array $parts = []): void
    {
        $this->db->incrementViews($slug);
        $mime = $item['mime_type'] ?: 'application/octet-stream';
        $inline = str_starts_with($mime, 'image/') ||
                  str_starts_with($mime, 'video/') ||
                  str_starts_with($mime, 'audio/') ||
                  str_starts_with($mime, 'text/') ||
                  $mime === 'application/pdf';

        $displayName = count($parts) > 2 ? end($parts) : ($item['original_name'] ?: ($item['file_path'] ?? $slug));
        $maxDownloads = isset($item['max_downloads']) && $item['max_downloads'] !== null ? (int) $item['max_downloads'] : null;
        $viewsAfter = (int) $item['views'] + 1;
        $shouldBurn = (bool) $item['is_oneshot'] || ($maxDownloads !== null && $viewsAfter >= $maxDownloads);

        if ($shouldBurn) {
            $this->db->deleteBySlug($slug);
        }

        // Stream reverso do Mocambo se source === 'mocambo'
        if (($item['source'] ?? 'native') === 'mocambo') {
            $meta = ! empty($item['mocambo_meta']) ? json_decode((string) $item['mocambo_meta'], true) : [];
            $streamUrl = $meta['stream_url'] ?? null;
            if ($streamUrl) {
                header('Content-Type: '.$mime);
                if (! empty($item['size'])) {
                    header('Content-Length: '.(string) $item['size']);
                }
                header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$displayName.'"');
                $fp = @fopen($streamUrl, 'rb');
                if ($fp) {
                    fpassthru($fp);
                    fclose($fp);
                } else {
                    $ch = curl_init($streamUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_exec($ch);
                    curl_close($ch);
                }

                return;
            }
        }

        $storageFile = ! empty($item['file_path']) ? (string) $item['file_path'] : $slug;
        $this->storage->streamFile($storageFile, $mime, $displayName, $inline, $shouldBurn);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function handleFolderZipDownload(array $item): void
    {
        $filesData = ! empty($item['data']) ? (json_decode((string) $item['data'], true)['files'] ?? []) : [];
        $zip = new SimpleZip;
        foreach ($filesData as $f) {
            $path = (string) ($f['path'] ?? 'file');
            $content = (string) ($f['content'] ?? '');
            $zip->addFile($path, $content);
        }
        $zipData = $zip->build();
        $safeZipName = ($item['original_name'] ? preg_replace('/[^\w\-]/', '_', (string) $item['original_name']) : (string) $item['slug']).'.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$safeZipName.'"');
        header('Content-Length: '.(string) strlen($zipData));
        echo $zipData;
    }

    private function handleList(?string $authHeader): void
    {
        $auth = $this->resolveAuth($authHeader);
        if ($auth === null) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Ficha ou token de acesso inválido ou ausente. Forneça o token via Authorization: Bearer <token> ou use a flag -t no fux.',
            ]);

            return;
        }

        $userIdFilter = $auth['role'] === 'member' ? $auth['user_id'] : null;
        $items = $this->db->listItems(500, 0, null, 'file', $userIdFilter);
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

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function handleApi(string $endpoint, string $method, ?string $authHeader): void
    {
        $auth = $this->resolveAuth($authHeader);
        $isAuthorized = $auth !== null;

        // Endpoints de Integração com Mocambo: /api/mocambo/share e /api/mocambo/share/{slug}
        if (str_starts_with($endpoint, 'mocambo/')) {
            if (! $isAuthorized) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Ficha ou token de acesso inválido ou ausente. Esta rota requer autenticação.',
                ]);

                return;
            }

            if ($endpoint === 'mocambo/share' && $method === 'POST') {
                $rawInput = $this->getRawInput();
                $body = json_decode($rawInput, true) ?? [];

                $slug = ! empty($body['slug']) ? (string) $body['slug'] : $this->generateSlug();
                $expiresAt = $this->parseExpiration(isset($body['ttl']) ? (string) $body['ttl'] : null);
                $xToken = bin2hex(random_bytes(16));

                $isFolder = ($body['type'] ?? '') === 'folder';

                $this->db->createItem([
                    'slug' => $slug,
                    'original_name' => (string) ($body['file_name'] ?? $body['name'] ?? ($isFolder ? 'Pasta Mocambo' : 'arquivo_mocambo')),
                    'mime_type' => (string) ($body['mime_type'] ?? ($isFolder ? 'application/x-directory' : 'application/octet-stream')),
                    'size_bytes' => (int) ($body['file_size'] ?? $body['size'] ?? 0),
                    'x_token' => $xToken,
                    'user_id' => $auth['user_id'] ?? $auth['username'] ?? 'admin',
                    'created_at' => time(),
                    'expires_at' => $expiresAt,
                    'type' => $isFolder ? 'folder' : 'file',
                    'source' => 'mocambo',
                    'mocambo_meta' => json_encode([
                        'mocambo_token' => $body['mocambo_token'] ?? null,
                        'stream_url' => $body['mocambo_stream_url'] ?? null,
                    ]),
                    'data' => ! empty($body['files']) ? json_encode(['files' => $body['files']]) : null,
                ]);

                http_response_code(201);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'slug' => $slug,
                    'url' => $this->getBaseUrl().'/'.$slug,
                    'x_token' => $xToken,
                ]);

                return;
            }

            if (preg_match('#^mocambo/share/([^/]+)$#', $endpoint, $m) && $method === 'DELETE') {
                $targetSlug = $m[1];
                $deleted = $this->db->deleteBySlug($targetSlug);

                http_response_code(200);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => $deleted,
                    'slug' => $targetSlug,
                ]);

                return;
            }

            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Not Found']);

            return;
        }

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

        if ($endpoint === 'me' && $method === 'GET') {
            header('Content-Type: application/json; charset=UTF-8');
            if ($auth) {
                http_response_code(200);
                echo json_encode($auth);
            } else {
                http_response_code(401);
                echo json_encode([
                    'authenticated' => false,
                    'role' => null,
                    'error' => 'Unauthorized',
                ]);
            }

            return;
        }

        if ($endpoint === 'users' && $method === 'GET') {
            if (! $auth || $auth['role'] !== 'admin') {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Forbidden',
                    'message' => '403 Proibido: Apenas a fuxiqueira-mor (administradora) pode gerenciar usuárias.',
                ]);

                return;
            }

            $users = $this->db->listUsers();
            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'users' => $users,
                'total' => count($users),
            ]);

            return;
        }

        if ($endpoint === 'users' && $method === 'POST') {
            if (! $auth || $auth['role'] !== 'admin') {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Forbidden',
                    'message' => '403 Proibido: Apenas a fuxiqueira-mor (administradora) pode cadastrar novas fuxiqueiras.',
                ]);

                return;
            }

            $body = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
            $username = trim((string) ($body['username'] ?? ''));
            $role = in_array($body['role'] ?? '', ['admin', 'member'], true) ? $body['role'] : 'member';

            if ($username === '' || ! preg_match('/^[a-zA-Z0-9_\-\.]{2,32}$/', $username)) {
                http_response_code(400);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Bad Request',
                    'message' => 'Nome de fuxiqueira inválido. Deve ter entre 2 e 32 caracteres (letras, números, hífen, underline ou ponto).',
                ]);

                return;
            }

            if ($this->db->findUserByUsername($username)) {
                http_response_code(409);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Conflict',
                    'message' => "Já existe uma fuxiqueira cadastrada com o apelido '{$username}'.",
                ]);

                return;
            }

            $id = bin2hex(random_bytes(8));
            $rawToken = trim((string) ($body['token'] ?? ''));
            if ($rawToken !== '') {
                if (strlen($rawToken) < 4 || strlen($rawToken) > 128) {
                    http_response_code(400);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'error' => 'Bad Request',
                        'message' => 'Ficha/senha inválida. Deve ter entre 4 e 128 caracteres.',
                    ]);

                    return;
                }

                if ($this->config->isAuthorized($rawToken) || $this->db->findUserByToken($rawToken)) {
                    http_response_code(409);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'error' => 'Conflict',
                        'message' => 'Esta ficha/senha já está em uso.',
                    ]);

                    return;
                }

                $token = $rawToken;
            } else {
                $token = 'fux_'.bin2hex(random_bytes(16));
            }

            $this->db->createUser([
                'id' => $id,
                'username' => $username,
                'token' => $token,
                'role' => $role,
                'created_at' => time(),
            ]);

            http_response_code(201);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => $id,
                    'username' => $username,
                    'token' => $token,
                    'role' => $role,
                    'created_at' => time(),
                ],
                'message' => "Fuxiqueira '{$username}' cadastrada com sucesso!",
            ]);

            return;
        }

        if (preg_match('#^users/([a-zA-Z0-9_\-]+)/token$#', $endpoint, $m) && $method === 'POST') {
            if (! $auth || $auth['role'] !== 'admin') {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Forbidden',
                    'message' => '403 Proibido: Apenas a fuxiqueira-mor pode regenerar fichas.',
                ]);

                return;
            }

            $userId = $m[1];
            $user = $this->db->findUserById($userId);
            if (! $user) {
                http_response_code(404);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'User not found']);

                return;
            }

            $body = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
            $rawToken = trim((string) ($body['token'] ?? ''));
            if ($rawToken !== '') {
                if (strlen($rawToken) < 4 || strlen($rawToken) > 128) {
                    http_response_code(400);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'error' => 'Bad Request',
                        'message' => 'Ficha/senha inválida. Deve ter entre 4 e 128 caracteres.',
                    ]);

                    return;
                }

                $existingUser = $this->db->findUserByToken($rawToken);
                if ($this->config->isAuthorized($rawToken) || ($existingUser && $existingUser['id'] !== $userId)) {
                    http_response_code(409);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'error' => 'Conflict',
                        'message' => 'Esta ficha/senha já está em uso.',
                    ]);

                    return;
                }

                $newToken = $rawToken;
            } else {
                $newToken = 'fux_'.bin2hex(random_bytes(16));
            }

            $this->db->updateUserToken($userId, $newToken);

            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'id' => $userId,
                'username' => $user['username'],
                'token' => $newToken,
                'message' => "Ficha da fuxiqueira '{$user['username']}' atualizada com sucesso!",
            ]);

            return;
        }

        if (preg_match('#^users/([a-zA-Z0-9_\-]+)$#', $endpoint, $m) && $method === 'DELETE') {
            if (! $auth || $auth['role'] !== 'admin') {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Forbidden',
                    'message' => '403 Proibido: Apenas a fuxiqueira-mor pode remover usuárias.',
                ]);

                return;
            }

            $userId = $m[1];
            $user = $this->db->findUserById($userId);
            if (! $user) {
                http_response_code(404);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'User not found']);

                return;
            }

            $this->db->deleteUser($userId);
            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'message' => "Fuxiqueira '{$user['username']}' removida.",
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
            $userIdFilter = ($auth && $auth['role'] === 'member') ? $auth['user_id'] : null;
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($this->db->getStats($userIdFilter));

            return;
        }

        if ($endpoint === 'items' && $method === 'GET') {
            $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $search = isset($_GET['search']) ? (string) $_GET['search'] : null;
            $type = isset($_GET['type']) ? (string) $_GET['type'] : null;
            $dateFrom = isset($_GET['date_from']) ? (string) $_GET['date_from'] : (isset($_GET['from']) ? (string) $_GET['from'] : null);
            $dateTo = isset($_GET['date_to']) ? (string) $_GET['date_to'] : (isset($_GET['to']) ? (string) $_GET['to'] : null);

            $userIdFilter = null;
            if ($auth && $auth['role'] === 'member') {
                $userIdFilter = $auth['user_id'];
            } elseif ($auth && $auth['role'] === 'admin' && isset($_GET['user_id']) && trim((string) $_GET['user_id']) !== '') {
                $userIdFilter = trim((string) $_GET['user_id']);
            }

            $items = $this->db->listItems($limit, $offset, $search, $type, $userIdFilter, $dateFrom, $dateTo);
            $total = $this->db->countItems($search, $type, $userIdFilter, $dateFrom, $dateTo);

            http_response_code(200);
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

        if ($endpoint === 'items/bulk-delete' && $method === 'POST') {
            if (! $isAuthorized) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Ficha ou token de acesso inválido ou ausente para exclusão.',
                ]);

                return;
            }

            if (! $auth || $auth['role'] !== 'admin') {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Forbidden',
                    'message' => '403 Proibido: Apenas a fuxiqueira-mor (administradora) pode realizar exclusão em massa.',
                ]);

                return;
            }

            $body = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
            $search = isset($body['search']) && trim((string) $body['search']) !== '' ? (string) $body['search'] : null;
            $type = isset($body['type']) && trim((string) $body['type']) !== '' ? (string) $body['type'] : null;
            $userId = isset($body['user_id']) && trim((string) $body['user_id']) !== '' ? (string) $body['user_id'] : null;
            $dateFrom = isset($body['date_from']) && trim((string) $body['date_from']) !== '' ? (string) $body['date_from'] : (isset($body['from']) ? (string) $body['from'] : null);
            $dateTo = isset($body['date_to']) && trim((string) $body['date_to']) !== '' ? (string) $body['date_to'] : (isset($body['to']) ? (string) $body['to'] : null);

            $itemsToDelete = $this->db->getItemsForBulkDelete($search, $type, $userId, $dateFrom, $dateTo);
            $ids = [];
            foreach ($itemsToDelete as $it) {
                $ids[] = $it['id'];
                $this->storage->deleteItemFiles($it['slug'], $it['file_path'] ?? null);
            }

            $count = $this->db->deleteItemsByIds($ids);

            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "{$count} fofocas excluídas com sucesso.",
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

            if ($auth && $auth['role'] === 'member' && ($item['user_id'] ?? null) !== $auth['user_id']) {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Forbidden',
                    'message' => '403 Proibido: Você só tem permissão para excluir as suas próprias fofocas.',
                ]);

                return;
            }

            $this->storage->deleteItemFiles($slug, $item['file_path'] ?? null);
            $this->db->deleteBySlug($slug);
            http_response_code(200);
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
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'Item not found']);

                return;
            }

            if ($auth && $auth['role'] === 'member' && ($item['user_id'] ?? null) !== $auth['user_id']) {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'error' => 'Forbidden',
                    'message' => '403 Proibido: Você só tem permissão para editar as suas próprias fofocas.',
                ]);

                return;
            }

            $rawBody = (string) file_get_contents('php://input');
            $jsonData = json_decode($rawBody, true);
            $body = is_array($jsonData) ? $jsonData : $_POST;

            // Update item lifecycle and metadata parameters
            $params = [];

            // 1. Expiration
            if (array_key_exists('expires', $body) || array_key_exists('expires_at', $body)) {
                $expVal = $body['expires'] ?? $body['expires_at'];
                if ($expVal === null || $expVal === '' || $expVal === 'never' || $expVal === 0 || $expVal === '0') {
                    $params['expires_at'] = null;
                } else {
                    $params['expires_at'] = $this->parseExpiration((string) $expVal);
                }
            }

            // 2. Max downloads quota
            if (array_key_exists('max_downloads', $body)) {
                $mdVal = $body['max_downloads'];
                if ($mdVal === null || $mdVal === '' || (int) $mdVal <= 0) {
                    $params['max_downloads'] = null;
                } else {
                    $params['max_downloads'] = (int) $mdVal;
                }
            }

            // 3. Oneshot toggle
            if (array_key_exists('is_oneshot', $body)) {
                $params['is_oneshot'] = filter_var($body['is_oneshot'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }

            // 4. Original name
            if (array_key_exists('original_name', $body)) {
                $nameVal = trim((string) $body['original_name']);
                $params['original_name'] = $nameVal !== '' ? $nameVal : null;
            }

            // 5. Target URL (for shortened links)
            if (array_key_exists('target_url', $body)) {
                $urlVal = trim((string) $body['target_url']);
                $params['target_url'] = $urlVal !== '' ? $urlVal : null;
            }

            // 6. Checksum / Hash
            if (! empty($body['recalculate_hash']) && ! empty($item['file_path'])) {
                $realFilePath = $this->storage->getFilePath($item['file_path']);
                if (file_exists($realFilePath)) {
                    $params['hash'] = hash_file('sha256', $realFilePath);
                }
            } elseif (array_key_exists('hash', $body)) {
                $params['hash'] = trim((string) $body['hash']) ?: null;
            }

            if (! empty($params)) {
                $this->db->updateItemParameters($slug, $params);
            }

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
            } elseif (! empty($body['rebake']) && $item['type'] === 'doc' && ! empty($item['file_path'])) {
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

                    $cachedPdf = $this->storage->getFilesDir()."/{$slug}.pdf";
                    if (file_exists($cachedPdf)) {
                        @unlink($cachedPdf);
                    }
                }
            }

            // Edit Content
            if (isset($body['content']) && is_string($body['content']) && ! empty($item['file_path'])) {
                file_put_contents($this->storage->getFilePath($item['file_path']), $body['content']);
                $newSize = strlen($body['content']);
                $this->db->updateContent($slug, $newSize);
                $this->db->updateItemParameters($slug, ['hash' => hash('sha256', $body['content'])]);

                if ($item['type'] === 'doc' || ($body['type'] ?? '') === 'doc') {
                    $baked = MarkdownRenderer::render($body['content'], [
                        'title' => pathinfo($item['original_name'] ?: $slug, PATHINFO_FILENAME),
                        'slug' => $slug,
                        'original_name' => $item['original_name'] ?: "{$slug}.md",
                        'created_at' => (int) $item['created_at'],
                        'base_url' => $this->getBaseUrl(),
                    ]);
                    file_put_contents($this->storage->getFilePath("{$slug}.html"), $baked);

                    $cachedPdf = $this->storage->getFilesDir()."/{$slug}.pdf";
                    if (file_exists($cachedPdf)) {
                        @unlink($cachedPdf);
                    }
                }
            }

            $updatedItem = $this->db->findBySlug($slug);

            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'item' => $updatedItem,
                'message' => 'Fuxico atualizado com sucesso!',
            ]);

            return;
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }

    private function handleGistApi(?string $authHeader): void
    {
        $auth = $this->resolveAuth($authHeader);
        if (! $this->config->publicMode && $auth === null) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Ficha ou token de acesso inválido ou ausente para publicar gists.',
            ]);

            return;
        }

        $userId = $auth['user_id'] ?? null;

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
            'user_id' => $userId,
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
        $auth = $this->resolveAuth($token) ?? $this->resolveAuth($authHeader);
        $isAdmin = $auth && $auth['role'] === 'admin';

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
                    $isOwner = ($auth && ! empty($item['user_id']) && $auth['user_id'] === $item['user_id'])
                        || ($token !== '' && $token === $item['x_token']);
                    if (! $isAdmin && ! $isOwner) {
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

                    if (! $this->config->publicMode && $auth === null) {
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
                        'user_id' => $auth['user_id'] ?? null,
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
            $isOwner = ($auth && ! empty($item['user_id']) && $auth['user_id'] === $item['user_id'])
                || ($item && $token !== '' && $token === $item['x_token']);

            if (! $isAdmin && ! $isOwner) {
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
        $auth = $this->resolveAuth($authHeader);
        if (! $this->config->publicMode && $auth === null) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Ficha ou token de acesso inválido ou ausente para criar repositórios git.',
            ]);

            return;
        }

        $userId = $auth['user_id'] ?? null;

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
            'user_id' => $userId,
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

    public function getJoajuUrl(): string
    {
        return rtrim($this->config->joajuUrl ?? (getenv('FUXICO_JOAJU_URL') ?: 'http://joaju:3000'), '/');
    }

    public function compileDocToPdf(string $slug): ?string
    {
        $item = $this->db->findBySlug($slug);
        if (! $item || empty($item['file_path'])) {
            return null;
        }

        $mdPath = $this->storage->getFilePath($item['file_path']);
        if (! file_exists($mdPath)) {
            return null;
        }

        $pdfPath = $this->storage->getFilesDir()."/{$slug}.pdf";
        $mdMtime = filemtime($mdPath);

        // 1. Validar integridade e atualidade do cache em disco
        $isCacheValid = file_exists($pdfPath) && filesize($pdfPath) > 0 && filemtime($pdfPath) >= $mdMtime;
        $assetsDir = $this->storage->getFilesDir()."/{$slug}_assets";
        if ($isCacheValid && is_dir($assetsDir)) {
            $assetFiles = glob("{$assetsDir}/*");
            if ($assetFiles) {
                foreach ($assetFiles as $af) {
                    if (is_file($af) && filemtime($af) > filemtime($pdfPath)) {
                        $isCacheValid = false;
                        break;
                    }
                }
            }
        }

        if ($isCacheValid) {
            return $pdfPath;
        }

        // 2. Compilar via Joaju (/compile)
        $rawMd = file_get_contents($mdPath);
        if ($rawMd === false) {
            return null;
        }

        $joajuUrl = $this->getJoajuUrl();
        $hasAssets = is_dir($assetsDir) && ! empty(glob("{$assetsDir}/*"));

        $ch = curl_init("{$joajuUrl}/compile");
        if (! $ch) {
            return null;
        }

        if ($hasAssets) {
            $zip = new SimpleZip;
            $zip->addFile('main.md', $rawMd);
            foreach (glob("{$assetsDir}/*") as $assetFile) {
                if (is_file($assetFile)) {
                    $baseName = basename($assetFile);
                    $assetContent = (string) file_get_contents($assetFile);
                    $zip->addFile("attachments/{$baseName}", $assetContent);
                    $zip->addFile($baseName, $assetContent);
                }
            }
            $payload = $zip->build();
            $contentType = 'application/zip';
        } else {
            $payload = json_encode(['markdown' => $rawMd]);
            $contentType = 'application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Content-Type: {$contentType}",
                'Accept: application/pdf',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $result = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode === 200 && is_string($result) && strlen($result) > 100 && str_starts_with($result, '%PDF-')) {
            file_put_contents($pdfPath, $result);

            return $pdfPath;
        }

        return null;
    }
}
