<?php

declare(strict_types=1);

namespace PicoShare;

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
            if ($item['type'] === 'file' && ! empty($item['file_path'])) {
                $this->storage->deleteFile($item['file_path']);
            }
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

        // 4. File / URL lookup or 0x0.st Management
        $this->handleItemRoute($path, $method, $authHeader);
    }

    private function handleUpload(?string $authHeader): void
    {
        // Public vs Private authentication
        if (! $this->config->publicMode && ! $this->config->isAuthorized($authHeader)) {
            http_response_code(401);
            header('Content-Type: text/plain');
            echo "401 Unauthorized: Valid Authorization header required.\n";

            return;
        }

        $isSecret = isset($_POST['secret']) || isset($_GET['secret']);
        $oneshot = isset($_POST['oneshot']) ||
                   isset($_POST['oneshot_url']) ||
                   (isset($_SERVER['HTTP_ONESHOT']) && filter_var($_SERVER['HTTP_ONESHOT'], FILTER_VALIDATE_BOOLEAN));

        $expireParam = $_POST['expires'] ?? $_POST['expire'] ?? $_SERVER['HTTP_EXPIRE'] ?? null;
        $expiresAt = $this->parseExpiration($expireParam ? (string) $expireParam : null);

        $customName = trim((string) ($_POST['customName'] ?? $_POST['custom_name'] ?? $_POST['name'] ?? ''));

        // Determine slug
        if ($customName !== '') {
            $slug = preg_replace('/[^a-zA-Z0-9_\-]+/', '-', strtolower($customName));
            $slug = trim($slug, '-');
            if ($slug === '' || $this->db->isSlugTaken($slug)) {
                http_response_code(409);
                header('Content-Type: text/plain');
                echo "Error: Name '{$customName}' is already taken or invalid.\n";

                return;
            }
        } else {
            $slug = $this->generateSlug($isSecret);
        }

        $xToken = bin2hex(random_bytes(16));

        // 1. File Upload
        if (isset($_FILES['file']) && is_array($_FILES['file']) && ! empty($_FILES['file']['tmp_name'])) {
            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                header('Content-Type: text/plain');
                echo "Upload error code: {$file['error']}\n";

                return;
            }

            $validation = MimeValidator::validate($file['tmp_name'], $file['name'], $this->config->maxUploadSize);
            if (! $validation['valid']) {
                http_response_code(400);
                header('Content-Type: text/plain');
                echo "Error: {$validation['error']}\n";

                return;
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $targetFilename = $slug.($ext !== '' ? ".{$ext}" : '');

            if (! $this->storage->storeUploadedFile($file['tmp_name'], $targetFilename)) {
                http_response_code(500);
                header('Content-Type: text/plain');
                echo "Error storing uploaded file.\n";

                return;
            }

            $this->db->createItem([
                'id' => bin2hex(random_bytes(8)),
                'slug' => $slug,
                'original_name' => $file['name'],
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

            header("X-Token: {$xToken}");
            header('Content-Type: text/plain');
            $urlExt = $ext !== '' ? ".{$ext}" : '';
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

            header("X-Token: {$xToken}");
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'PicoShare/1.0');
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

        header("X-Token: {$xToken}");
        header('Content-Type: text/plain');
        $urlExt = $ext !== '' ? ".{$ext}" : '';
        echo "{$this->getBaseUrl()}/{$slug}{$urlExt}\n";
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

            if ($item['type'] === 'file' && ! empty($item['file_path'])) {
                $this->storage->deleteFile($item['file_path']);
            }
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
                if ($item['type'] === 'file' && ! empty($item['file_path'])) {
                    $this->storage->deleteFile($item['file_path']);
                }
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

        // 3. GET /<slug> (Serving File or Redirecting URL)
        if ($method === 'GET') {
            // Check expiration
            if ($item['expires_at'] !== null && $item['expires_at'] <= time()) {
                if ($item['type'] === 'file' && ! empty($item['file_path'])) {
                    $this->storage->deleteFile($item['file_path']);
                }
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
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);

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
        if (! $this->config->isAuthorized($authHeader)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);

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
            $slug = substr($endpoint, 6);
            $item = $this->db->findBySlug($slug);
            if (! $item) {
                http_response_code(404);
                echo json_encode(['error' => 'Item not found']);

                return;
            }
            if ($item['type'] === 'file' && ! empty($item['file_path'])) {
                $this->storage->deleteFile($item['file_path']);
            }
            $this->db->deleteBySlug($slug);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);

            return;
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
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
