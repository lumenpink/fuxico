<?php

declare(strict_types=1);

/**
 * ┌─────────────────────────────────────────────────────────────┐
 * │  FUXICO — A malícia popular do boca a boca                  │
 * │  Single-file portable file sharing & URL shortener          │
 * │  100% compatible with 0x0.st and rustypaste protocols      │
 * └─────────────────────────────────────────────────────────────┘
 *
 * Version: 0.1.0-dev.20+20260920.bd346c4+508d238
 * Built:   2026-09-20 15:13:35 UTC
 * License: GPL-3.0-or-later
 * Source:  https://codeberg.org/lumenpink/fuxico
 *
 * Requirements:
 *   - PHP >= 8.2.0
 *   - Extensions: pdo_sqlite, fileinfo
 */

namespace {
    if (PHP_VERSION_ID < 80200) {
        $msg = "Fuxico requires PHP 8.2.0 or higher. Current version: " . PHP_VERSION;
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "[ERROR] " . $msg . "\n");
        } else {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $msg;
        }
        exit(1);
    }

    $requiredExtensions = ['pdo_sqlite', 'fileinfo'];
    $missingExtensions = [];
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $missingExtensions[] = $ext;
        }
    }
    if (!empty($missingExtensions)) {
        $msg = "Fuxico requires PHP extension(s): " . implode(', ', $missingExtensions);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "[ERROR] " . $msg . "\n");
        } else {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $msg;
        }
        exit(1);
    }

    if (!defined('FUXICO_COMPILED')) {
        define('FUXICO_COMPILED', true);
        define('FUXICO_VERSION', '0.1.0-dev.20+20260920.bd346c4+508d238');
        define('FUXICO_BUILD_DATE', '2026-09-20 15:13:35 UTC');
    }
}

/* --- Module: Config.php --- */
namespace PicoShare {
class Config
{
    public const DEFAULT_ALLOWED_CHARS = '256789bcdfghjklmnpqrstvwxyz';

    public const DEFAULT_FILENAME_LENGTH = 5;

    public const DEFAULT_SECRET_FILENAME_LENGTH = 16;

    public const DEFAULT_MAX_UPLOAD_SIZE = 524288000; // 500 MB

    public bool $publicMode;

    /** @var string[] */
    public array $authTokens;

    public int $maxUploadSize;

    public string $dataDir;

    public string $filesDir;

    public string $dbPath;

    public string $allowedChars;

    public int $filenameLength;

    public int $secretFilenameLength;

    public ?string $baseUrl;

    public function __construct(?string $baseDir = null)
    {
        $base = $baseDir ?? dirname(__DIR__);
        $this->dataDir = (string) (getenv('FUXICO_DATA_DIR') ?: getenv('PICOSHARE_DATA_DIR') ?: $base.'/data');
        $this->filesDir = $this->dataDir.'/files';
        $this->dbPath = $this->dataDir.'/fuxico.sqlite';

        // Public Mode: if true, anyone can upload. Dashboard & bulk list still require master token.
        $envPublic = getenv('FUXICO_PUBLIC_MODE') ?: getenv('PICOSHARE_PUBLIC_MODE');
        $this->publicMode = $envPublic !== false ? filter_var($envPublic, FILTER_VALIDATE_BOOLEAN) : false;

        // Auth Tokens
        $envTokens = getenv('FUXICO_AUTH_TOKENS') ?: getenv('PICOSHARE_AUTH_TOKENS');
        if ($envTokens !== false && trim((string) $envTokens) !== '') {
            $this->authTokens = array_map('trim', explode(',', (string) $envTokens));
        } else {
            $this->authTokens = ['tatanka'];
        }

        // Upload Limits & Slugs
        $this->maxUploadSize = (int) (getenv('FUXICO_MAX_UPLOAD_SIZE') ?: getenv('PICOSHARE_MAX_UPLOAD_SIZE') ?: self::DEFAULT_MAX_UPLOAD_SIZE);
        $this->allowedChars = self::DEFAULT_ALLOWED_CHARS;
        $this->filenameLength = (int) (getenv('FUXICO_SLUG_LENGTH') ?: getenv('PICOSHARE_SLUG_LENGTH') ?: self::DEFAULT_FILENAME_LENGTH);
        $this->secretFilenameLength = (int) (getenv('FUXICO_SECRET_SLUG_LENGTH') ?: getenv('PICOSHARE_SECRET_SLUG_LENGTH') ?: self::DEFAULT_SECRET_FILENAME_LENGTH);
        $this->baseUrl = getenv('FUXICO_BASE_URL') ?: getenv('PICOSHARE_BASE_URL') ?: null;
    }

    public function isAuthorized(?string $token): bool
    {
        if (empty($this->authTokens)) {
            return true;
        }

        if ($token === null || trim($token) === '') {
            return false;
        }

        // Bearer prefix strip
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        return in_array(trim($token), $this->authTokens, true);
    }
}
}

/* --- Module: Storage.php --- */
namespace PicoShare {
class Storage
{
    private string $dataDir;

    private string $filesDir;

    public function __construct(string $dataDir, string $filesDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
        $this->filesDir = rtrim($filesDir, '/');
        $this->ensureDirectories();
    }

    public function ensureDirectories(): void
    {
        if (! is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        if (! is_dir($this->filesDir)) {
            mkdir($this->filesDir, 0755, true);
        }

        $this->protectDirectory($this->dataDir);
        $this->protectDirectory($this->filesDir);
    }

    /**
     * Write access protection rules (.htaccess and index.html) to prevent direct web access.
     */
    private function protectDirectory(string $dir): void
    {
        $htaccessPath = $dir.'/.htaccess';
        if (! file_exists($htaccessPath)) {
            $htaccess = "# PicoShare Security Lockdown\n"
                      ."# Prevent direct web access to internal data and files\n"
                      ."<IfModule mod_authz_core.c>\n"
                      ."    Require all denied\n"
                      ."</IfModule>\n"
                      ."<IfModule !mod_authz_core.c>\n"
                      ."    Deny from all\n"
                      ."</IfModule>\n";
            file_put_contents($htaccessPath, $htaccess);
        }

        $indexPath = $dir.'/index.html';
        if (! file_exists($indexPath)) {
            file_put_contents($indexPath, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Direct access denied.</p></body></html>');
        }
    }

    public function getFilePath(string $filename): string
    {
        return $this->filesDir.'/'.basename($filename);
    }

    public function fileExists(string $filename): bool
    {
        return file_exists($this->getFilePath($filename));
    }

    public function storeUploadedFile(string $tmpPath, string $targetFilename): bool
    {
        $dest = $this->getFilePath($targetFilename);
        if (is_uploaded_file($tmpPath)) {
            return move_uploaded_file($tmpPath, $dest);
        }

        return copy($tmpPath, $dest);
    }

    /**
     * Store data from a stream resource (e.g. remote URL fetch).
     *
     * @param  resource  $sourceStream
     */
    public function storeStream($sourceStream, string $targetFilename, int $maxBytes = 0): bool
    {
        $dest = $this->getFilePath($targetFilename);
        $target = fopen($dest, 'wb');
        if (! $target) {
            return false;
        }

        $bytesWritten = 0;
        while (! feof($sourceStream)) {
            $chunk = fread($sourceStream, 65536);
            if ($chunk === false) {
                fclose($target);
                unlink($dest);

                return false;
            }
            $bytesWritten += strlen($chunk);
            if ($maxBytes > 0 && $bytesWritten > $maxBytes) {
                fclose($target);
                unlink($dest);

                return false;
            }
            fwrite($target, $chunk);
        }

        fclose($target);

        return true;
    }

    public function deleteFile(string $filename): bool
    {
        $path = $this->getFilePath($filename);
        if (file_exists($path)) {
            return unlink($path);
        }

        return true;
    }

    /**
     * Stream a file to HTTP client with Range request support and safe headers.
     */
    public function streamFile(string $filename, string $mimeType, string $displayName, bool $inline, bool $oneshot = false): void
    {
        $filePath = $this->getFilePath($filename);
        if (! file_exists($filePath)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "File Not Found\n";

            return;
        }

        if ($oneshot) {
            // Register shutdown function to guarantee deletion even on client disconnect
            register_shutdown_function(function () use ($filePath) {
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            });
        }

        $fileSize = (int) filesize($filePath);
        $fp = @fopen($filePath, 'rb');
        if (! $fp) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Failed to open file for streaming\n";

            return;
        }

        // Clean any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $start = 0;
        $end = $fileSize - 1;

        // Security & Cache Headers
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');
        header('Content-Type: '.$mimeType);

        $disposition = $inline ? 'inline' : 'attachment';
        $safeName = str_replace(['"', "\r", "\n"], '', $displayName);
        header("Content-Disposition: {$disposition}; filename=\"{$safeName}\"");

        // Handle HTTP Range header (crucial for video/audio seeking)
        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = $_SERVER['HTTP_RANGE'];
            if (preg_match('/bytes=\s*(\d*)-(\d*)/i', $range, $matches)) {
                if ($matches[1] !== '') {
                    $start = (int) $matches[1];
                }
                if ($matches[2] !== '') {
                    $end = (int) $matches[2];
                }

                if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
                    http_response_code(416); // Range Not Satisfiable
                    header("Content-Range: bytes */{$fileSize}");
                    fclose($fp);

                    return;
                }

                http_response_code(206); // Partial Content
                header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
            }
        } else {
            http_response_code(200);
        }

        $length = $end - $start + 1;
        header("Content-Length: {$length}");

        if ($start > 0) {
            fseek($fp, $start);
        }

        $bufferSize = 65536; // 64KB chunks
        $bytesRemaining = $length;

        while (! feof($fp) && $bytesRemaining > 0 && connection_status() === CONNECTION_NORMAL) {
            $readSize = min($bufferSize, $bytesRemaining);
            $buffer = fread($fp, $readSize);
            if ($buffer === false) {
                break;
            }
            echo $buffer;
            flush();
            $bytesRemaining -= strlen($buffer);
        }

        fclose($fp);

        if ($oneshot && file_exists($filePath)) {
            @unlink($filePath);
        }
    }
}
}

/* --- Module: MimeValidator.php --- */
namespace PicoShare {
class MimeValidator
{
    /** @var string[] Extensions strictly disallowed for security */
    public const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'inc',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd', 'com',
        'vbs', 'vbe', 'jsf', 'dll', 'so', 'bin', 'msi', 'jsp', 'asp', 'aspx', 'htaccess',
    ];

    /** @var array<string, string[]> Mapping of allowed extensions to valid MIME types */
    public const EXTENSION_MIME_MAP = [
        // Images
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'svg' => ['image/svg+xml', 'text/plain', 'text/xml'],
        'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'tiff' => ['image/tiff'],
        'avif' => ['image/avif'],

        // Videos
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'mkv' => ['video/x-matroska', 'video/webm'],
        'avi' => ['video/x-msvideo', 'video/avi'],
        'mov' => ['video/quicktime'],
        'wmv' => ['video/x-ms-wmv'],
        'flv' => ['video/x-flv'],

        // Audio
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'aac' => ['audio/aac', 'audio/x-aac'],
        'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'opus' => ['audio/opus', 'audio/ogg'],

        // Documents & Text
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain', 'application/json'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'log' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'rtf' => ['text/rtf', 'application/rtf'],
        'json' => ['application/json', 'text/plain'],
        'xml' => ['application/xml', 'text/xml', 'text/plain'],
        'yaml' => ['text/yaml', 'text/x-yaml', 'text/plain', 'application/x-yaml'],
        'yml' => ['text/yaml', 'text/x-yaml', 'text/plain', 'application/x-yaml'],
        'html' => ['text/html', 'text/plain'],
        'css' => ['text/css', 'text/plain'],
        'js' => ['application/javascript', 'text/javascript', 'text/plain'],

        // Archives
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'tar' => ['application/x-tar', 'application/tar'],
        'gz' => ['application/gzip', 'application/x-gzip'],
        'bz2' => ['application/x-bzip2', 'application/bzip2'],
        'xz' => ['application/x-xz', 'application/xz'],
        '7z' => ['application/x-7z-compressed'],
        'rar' => ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar'],
        'zst' => ['application/zstd'],

        // Office
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'odg' => ['application/vnd.oasis.opendocument.graphics', 'application/zip'],
        'odf' => ['application/vnd.oasis.opendocument.formula', 'application/zip'],

        // Cryptography & Encrypted files (GPG / ASC / PGP)
        // Handled specially: encrypted data is random gibberish or armored ASCII text
        'gpg' => ['application/octet-stream', 'application/pgp-encrypted', 'text/plain', 'application/x-gpg'],
        'asc' => ['text/plain', 'application/pgp-keys', 'application/pgp-signature'],
        'pgp' => ['application/octet-stream', 'application/pgp-encrypted', 'text/plain'],
    ];

    /**
     * Validate an uploaded file against extension and MIME type.
     *
     * @return array{valid: bool, mime: string, error: ?string}
     */
    public static function validate(string $filePath, string $originalName, int $maxSize): array
    {
        if (! file_exists($filePath)) {
            return ['valid' => false, 'mime' => '', 'error' => 'File does not exist'];
        }

        $size = filesize($filePath);
        if ($size === false || $size <= 0) {
            return ['valid' => false, 'mime' => '', 'error' => 'File is empty'];
        }

        if ($size > $maxSize) {
            return ['valid' => false, 'mime' => '', 'error' => 'File exceeds maximum allowed size'];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'txt'; // Default fallback extension if not provided
        }

        // Dangerous extension check
        if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            return ['valid' => false, 'mime' => '', 'error' => "Disallowed file extension: .{$ext}"];
        }

        // Detect real MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $filePath) : false;
        if ($finfo && PHP_VERSION_ID < 80500) {
            finfo_close($finfo);
        }

        if (! $detectedMime) {
            $detectedMime = mime_content_type($filePath) ?: 'application/octet-stream';
        }

        // Cryptographic exemption for .gpg, .asc, .pgp:
        // Encrypted binaries are inherently random bytes (detected as application/octet-stream)
        // or armored ASCII (detected as text/plain). They should always pass.
        if (in_array($ext, ['gpg', 'asc', 'pgp'], true)) {
            return ['valid' => true, 'mime' => $detectedMime, 'error' => null];
        }

        // Check if extension is in the allowed map
        if (! array_key_exists($ext, self::EXTENSION_MIME_MAP)) {
            return ['valid' => false, 'mime' => $detectedMime, 'error' => "Extension .{$ext} is not supported"];
        }

        $allowedMimes = self::EXTENSION_MIME_MAP[$ext];

        // Validate detected MIME matches expected MIME(s)
        if (! in_array($detectedMime, $allowedMimes, true)) {
            // Check if generic text/plain for text types is acceptable
            $isTextFile = str_starts_with($detectedMime, 'text/');
            $allowsText = in_array('text/plain', $allowedMimes, true);

            if (! ($isTextFile && $allowsText)) {
                return [
                    'valid' => false,
                    'mime' => $detectedMime,
                    'error' => "MIME type mismatch for .{$ext}: detected '{$detectedMime}'",
                ];
            }
        }

        return ['valid' => true, 'mime' => $detectedMime, 'error' => null];
    }
}
}

/* --- Module: Database.php --- */
namespace PicoShare {
use PDO;

class Database
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:'.$dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // WAL mode for concurrency and performance
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA synchronous = NORMAL;');

        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS items (
                id TEXT PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                original_name TEXT,
                type TEXT NOT NULL,
                target_url TEXT,
                file_path TEXT,
                mime_type TEXT,
                size INTEGER DEFAULT 0,
                x_token TEXT NOT NULL,
                is_oneshot INTEGER DEFAULT 0,
                expires_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                views INTEGER DEFAULT 0
            );

            CREATE INDEX IF NOT EXISTS idx_items_slug ON items(slug);
            CREATE INDEX IF NOT EXISTS idx_items_expires ON items(expires_at);
            CREATE INDEX IF NOT EXISTS idx_items_type ON items(type);
            CREATE INDEX IF NOT EXISTS idx_items_created ON items(created_at);
        ');
    }

    public function isSlugTaken(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM items WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{
     *   id: string,
     *   slug: string,
     *   original_name?: ?string,
     *   type: string,
     *   target_url?: ?string,
     *   file_path?: ?string,
     *   mime_type?: ?string,
     *   size?: int,
     *   x_token: string,
     *   is_oneshot?: int,
     *   expires_at?: ?int,
     *   created_at?: int,
     *   views?: int
     * } $data
     */
    public function createItem(array $data): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO items (
                id, slug, original_name, type, target_url, file_path,
                mime_type, size, x_token, is_oneshot, expires_at, created_at, views
            ) VALUES (
                :id, :slug, :original_name, :type, :target_url, :file_path,
                :mime_type, :size, :x_token, :is_oneshot, :expires_at, :created_at, :views
            )
        ');

        return $stmt->execute([
            ':id' => $data['id'],
            ':slug' => $data['slug'],
            ':original_name' => $data['original_name'] ?? null,
            ':type' => $data['type'],
            ':target_url' => $data['target_url'] ?? null,
            ':file_path' => $data['file_path'] ?? null,
            ':mime_type' => $data['mime_type'] ?? 'application/octet-stream',
            ':size' => $data['size'] ?? 0,
            ':x_token' => $data['x_token'],
            ':is_oneshot' => $data['is_oneshot'] ?? 0,
            ':expires_at' => $data['expires_at'] ?? null,
            ':created_at' => $data['created_at'] ?? time(),
            ':views' => $data['views'] ?? 0,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $slug, string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE slug = :slug AND x_token = :token LIMIT 1');
        $stmt->execute([':slug' => $slug, ':token' => $token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deleteBySlug(string $slug): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM items WHERE slug = :slug');

        return $stmt->execute([':slug' => $slug]);
    }

    public function updateExpires(string $slug, ?int $expiresAt): bool
    {
        $stmt = $this->pdo->prepare('UPDATE items SET expires_at = :expires_at WHERE slug = :slug');

        return $stmt->execute([
            ':slug' => $slug,
            ':expires_at' => $expiresAt,
        ]);
    }

    public function incrementViews(string $slug): void
    {
        $stmt = $this->pdo->prepare('UPDATE items SET views = views + 1 WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listItems(int $limit = 100, int $offset = 0, ?string $search = null, ?string $type = null): array
    {
        $sql = 'SELECT id, slug, original_name, type, target_url, file_path, mime_type, size, is_oneshot, expires_at, created_at, views FROM items WHERE 1=1';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (slug LIKE :search OR original_name LIKE :search OR target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url'], true)) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countItems(?string $search = null, ?string $type = null): int
    {
        $sql = 'SELECT COUNT(*) FROM items WHERE 1=1';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (slug LIKE :search OR original_name LIKE :search OR target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url'], true)) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getExpiredItems(int $now): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE expires_at IS NOT NULL AND expires_at <= :now');
        $stmt->execute([':now' => $now]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{total_items: int, total_files: int, total_urls: int, total_bytes: int, total_views: int}
     */
    public function getStats(): array
    {
        $stmt = $this->pdo->query('
            SELECT
                COUNT(*) as total_items,
                SUM(CASE WHEN type = "file" THEN 1 ELSE 0 END) as total_files,
                SUM(CASE WHEN type = "url" THEN 1 ELSE 0 END) as total_urls,
                SUM(size) as total_bytes,
                SUM(views) as total_views
            FROM items
        ');
        $res = $stmt->fetch();

        return [
            'total_items' => (int) ($res['total_items'] ?? 0),
            'total_files' => (int) ($res['total_files'] ?? 0),
            'total_urls' => (int) ($res['total_urls'] ?? 0),
            'total_bytes' => (int) ($res['total_bytes'] ?? 0),
            'total_views' => (int) ($res['total_views'] ?? 0),
        ];
    }
}
}

/* --- Module: I18n.php --- */
namespace PicoShare {
class I18n
{
    private string $lang;

    /**
     * @var array<string, array<string, string>>
     */
    private static array $dictionary = [
        'pt' => [
            'app_name' => 'FUXICO',
            'tagline' => 'A malícia popular do boca a boca',
            'brand_sub' => 'Fuxico na borda',
            'drop_idle' => 'Despeja o podre aqui.',
            'drop_sub' => 'ou clique para catar nos seus arquivos',
            'drop_drag' => 'Pode largar que a gente espalha!',
            'btn_upload' => 'Solta o verbo ⚡',
            'btn_uploading' => 'Espalhando o bafão...',
            'tab_file' => 'Arquivo',
            'tab_url' => 'Encurtar URL',
            'tab_dash' => 'Fofocas no Ar',
            'opt_burn' => 'Queima de arquivo (some no 1º download)',
            'opt_secret' => 'Fofoca cifrada (slug de 16 caracteres)',
            'opt_expires' => 'Validade da fofoca:',
            'exp_none' => 'Até alguém descobrir (sem expiração)',
            'exp_1h' => '1 hora de fofoca quente',
            'exp_24h' => '24 horas',
            'exp_7d' => '7 dias',
            'exp_30d' => '30 dias',
            'custom_name_placeholder' => 'Apelido customizado (opcional)',
            'url_placeholder' => 'https://exemplo.com/fofoca-bombastica',
            'btn_shorten' => 'Encurtar fofoca ⚡',
            'token_label' => 'Senha da fofoca (Token de Autorização):',
            'token_placeholder' => 'tatanka ou token mestre...',
            'token_public' => 'Modo público ativo: qualquer um pode fuxicar sem token!',
            'stat_files' => 'Arquivos no Ar',
            'stat_urls' => 'Links Espalhados',
            'stat_bytes' => 'Volume da Fofoca',
            'stat_views' => 'Bisbilhotadas',
            'dash_search' => 'Buscar fofoca por slug ou nome...',
            'dash_filter_all' => 'Tudo que tá rolando',
            'dash_filter_files' => 'Só arquivos',
            'dash_filter_urls' => 'Só links encurtados',
            'dash_empty' => 'Nenhum podre encontrado.',
            'dash_auth_required' => 'Manda o token de autorização acima pra fiscalizar o cofre.',
            'col_slug' => 'Slug / Fofoca',
            'col_type' => 'Tipo',
            'col_size' => 'Tamanho',
            'col_exp' => 'Destino',
            'col_views' => 'Bisbilhotadas',
            'col_actions' => 'Ações',
            'act_copy' => 'Copiar',
            'act_view' => 'Espiar',
            'act_open' => 'Abrir',
            'act_delete' => 'Queimar',
            'dl_title' => 'Fuxico | Baixar o babado',
            'dl_subtitle' => 'Alguém mandou esse pacote pra você.',
            'dl_btn' => 'Baixar o babado 📦',
            'dl_warning_burn' => '⚠️ Aviso: assim que o download terminar, esse arquivo evapora do servidor.',
            'err_404_title' => 'Essa fofoca já prescreveu',
            'err_404_desc' => 'Essa fofoca já prescreveu (ou o link foi digitado errado).',
            'err_410_title' => 'Tarde demais. Esse segredo já foi recolhido.',
            'err_410_desc' => 'Esse pacote era queima-de-arquivo e já evaporou depois do primeiro acesso.',
            'back_home' => 'Voltar pro início e fuxicar outra coisa',
            'copy_success' => 'Link copiado! Vai lá espalhar.',
            'delete_confirm' => 'Tem certeza que quer queimar esse segredo pra sempre?',
        ],
        'en' => [
            'app_name' => 'FUXICO',
            'tagline' => 'Word-of-mouth mischief at the edge',
            'brand_sub' => 'Edge file vault',
            'drop_idle' => 'Spill the beans here.',
            'drop_sub' => 'or click to browse your files',
            'drop_drag' => "Let it go — we'll spread the word!",
            'btn_upload' => 'Spill the beans ⚡',
            'btn_uploading' => 'Spreading the scoop...',
            'tab_file' => 'File',
            'tab_url' => 'Shorten URL',
            'tab_dash' => 'Active Scoops',
            'opt_burn' => 'Burn after reading (destructs on 1st download)',
            'opt_secret' => 'Ciphered scoop (16-char secret slug)',
            'opt_expires' => 'Gossip retention:',
            'exp_none' => 'Until discovered (permanent)',
            'exp_1h' => '1 hour of hot tea',
            'exp_24h' => '24 hours',
            'exp_7d' => '7 days',
            'exp_30d' => '30 days',
            'custom_name_placeholder' => 'Custom slug alias (optional)',
            'url_placeholder' => 'https://example.com/spicy-leak',
            'btn_shorten' => 'Shorten scoop ⚡',
            'token_label' => 'Gossip Passphrase (Auth Token):',
            'token_placeholder' => 'tatanka or master token...',
            'token_public' => 'Public mode active: anyone can spill the beans!',
            'stat_files' => 'Live Files',
            'stat_urls' => 'Spread Links',
            'stat_bytes' => 'Gossip Weight',
            'stat_views' => 'Total Snoops',
            'dash_search' => 'Search scoop by slug or original name...',
            'dash_filter_all' => 'Everything circulating',
            'dash_filter_files' => 'Files only',
            'dash_filter_urls' => 'URLs only',
            'dash_empty' => 'No records found in the vault.',
            'dash_auth_required' => 'Provide an authorization token above to inspect vault records.',
            'col_slug' => 'Slug / Gossip',
            'col_type' => 'Type',
            'col_size' => 'Size',
            'col_exp' => 'Fate',
            'col_views' => 'Snoops',
            'col_actions' => 'Actions',
            'act_copy' => 'Copy',
            'act_view' => 'Peek',
            'act_open' => 'Open',
            'act_delete' => 'Burn',
            'dl_title' => 'Fuxico | Download the scoop',
            'dl_subtitle' => 'Someone passed this package along to you.',
            'dl_btn' => 'Download the scoop 📦',
            'dl_warning_burn' => '⚠️ Warning: once this download finishes, this file evaporates from the server.',
            'err_404_title' => 'This gossip already expired',
            'err_404_desc' => 'This gossip already expired (or the link was mistyped).',
            'err_410_title' => 'Too late. Somebody already snooped through this package.',
            'err_410_desc' => 'This was a burn-after-reading package and has already evaporated from the server.',
            'back_home' => 'Back to home to spill something else',
            'copy_success' => 'Link copied! Go out there and spread the word.',
            'delete_confirm' => 'Are you sure you want to burn this secret forever?',
        ],
    ];

    public function __construct(?string $explicitLang = null)
    {
        $this->lang = $this->detectLanguage($explicitLang);
    }

    /**
     * Detect language:
     * 1. Explicit parameter / query string (?lang=pt|en)
     * 2. Environment variable FUXICO_LANG
     * 3. HTTP Accept-Language header
     * 4. Default: 'en'
     */
    public function detectLanguage(?string $explicit = null): string
    {
        if ($explicit !== null && in_array(strtolower($explicit), ['pt', 'en'], true)) {
            return strtolower($explicit);
        }

        if (isset($_GET['lang']) && in_array(strtolower((string) $_GET['lang']), ['pt', 'en'], true)) {
            return strtolower((string) $_GET['lang']);
        }

        $envLang = getenv('FUXICO_LANG') ?: getenv('PICOSHARE_LANG');
        if ($envLang && in_array(strtolower((string) $envLang), ['pt', 'en'], true)) {
            return strtolower((string) $envLang);
        }

        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($header !== '') {
            $langs = explode(',', $header);
            foreach ($langs as $langPart) {
                $code = strtolower(trim(explode(';', $langPart)[0]));
                if (str_starts_with($code, 'pt')) {
                    return 'pt';
                }
            }
        }

        return 'en';
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function isPortuguese(): bool
    {
        return $this->lang === 'pt';
    }

    /**
     * Translate a key, falling back to English or key name.
     */
    public function t(string $key): string
    {
        if (isset(self::$dictionary[$this->lang][$key])) {
            return self::$dictionary[$this->lang][$key];
        }

        if (isset(self::$dictionary['en'][$key])) {
            return self::$dictionary['en'][$key];
        }

        return $key;
    }

    /**
     * Return all translations for client-side JS.
     *
     * @return array<string, string>
     */
    public function getAll(): array
    {
        return self::$dictionary[$this->lang] ?? self::$dictionary['en'];
    }
}
}

/* --- Module: Views.php --- */
namespace PicoShare {
class Views
{
    public static function renderWebUI(Config $config, ?I18n $i18n = null): string
    {
        $i18n ??= new I18n;
        $publicMode = $config->publicMode ? 'true' : 'false';
        $maxSizeMb = (int) ($config->maxUploadSize / 1048576);
        $lang = $i18n->getLang();
        $strings = json_encode($i18n->getAll(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $t = fn (string $key): string => htmlspecialchars($i18n->t($key), ENT_QUOTES, 'UTF-8');

        $activePt = $lang === 'pt' ? 'active' : '';
        $activeEn = $lang === 'en' ? 'active' : '';
        $publicNotice = $config->publicMode ? $t('token_public') : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$t('app_name')} - {$t('tagline')}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #121016;
            --card-bg: #1a1622;
            --card-surface: #221c2e;
            --text-main: #f5f4ef;
            --text-muted: #9f97ad;
            --acid-yellow: #e2f835;
            --acid-yellow-hover: #ccee22;
            --electric-pink: #ff2a85;
            --tape-black: #0a080d;
            --border-width: 2px;
            --border-color: #383047;
            --border-acid: #e2f835;
            --font-display: 'Anton', -apple-system, sans-serif;
            --font-heading: 'Space Grotesk', -apple-system, sans-serif;
            --font-mono: 'Space Mono', monospace;
            --shadow-hard: 4px 4px 0px #0a080d;
            --shadow-acid: 4px 4px 0px #e2f835;
            --shadow-pink: 4px 4px 0px #ff2a85;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-mono);
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle, rgba(226, 248, 53, 0.05) 1.5px, transparent 1.5px),
                radial-gradient(circle at 10% 20%, rgba(255, 42, 133, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(226, 248, 53, 0.06) 0%, transparent 40%);
            background-size: 20px 20px, 100% 100%, 100% 100%;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px;
        }

        /* Tape effect decor */
        .duct-tape {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%) rotate(-1deg);
            background: rgba(226, 248, 53, 0.9);
            color: #000;
            font-family: var(--font-display);
            font-size: 0.75rem;
            letter-spacing: 2px;
            padding: 2px 28px;
            box-shadow: 2px 2px 0px rgba(0,0,0,0.5);
            z-index: 10;
            user-select: none;
            text-transform: uppercase;
        }

        .container {
            position: relative;
            width: 100%;
            max-width: 840px;
            background: var(--card-bg);
            border: var(--border-width) solid var(--acid-yellow);
            box-shadow: 8px 8px 0px #0a080d, 8px 8px 0px 2px var(--electric-pink);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            margin-top: 10px;
        }

        /* Top Header */
        .top-bar {
            background: var(--tape-black);
            padding: 20px 24px;
            border-bottom: var(--border-width) solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .brand-box {
            display: flex;
            align-items: baseline;
            gap: 12px;
        }

        .brand {
            font-family: var(--font-display);
            font-size: 2.8rem;
            line-height: 1;
            letter-spacing: 1px;
            color: var(--acid-yellow);
            text-shadow: 3px 3px 0px var(--electric-pink);
            text-transform: uppercase;
        }

        .brand-sub {
            font-family: var(--font-heading);
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .lang-switch {
            display: flex;
            gap: 4px;
            background: var(--card-surface);
            padding: 4px;
            border: 1px solid var(--border-color);
        }

        .lang-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 8px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .lang-btn:hover {
            color: var(--acid-yellow);
        }

        .lang-btn.active {
            background: var(--acid-yellow);
            color: #000;
            box-shadow: 2px 2px 0px #000;
        }

        .tagline-badge {
            display: inline-block;
            background: var(--electric-pink);
            color: #fff;
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border: 1px solid #000;
            box-shadow: 2px 2px 0px #000;
            margin-top: -6px;
        }

        /* Token Section */
        .token-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .token-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
        }

        .token-input {
            width: 100%;
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            padding: 10px 14px;
            color: var(--acid-yellow);
            font-family: var(--font-mono);
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .token-input:focus {
            border-color: var(--acid-yellow);
            box-shadow: 2px 2px 0px var(--acid-yellow);
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: #14111a;
            border-bottom: var(--border-width) solid var(--border-color);
        }

        .tab-btn {
            flex: 1;
            padding: 14px 20px;
            background: none;
            border: none;
            border-right: 1px solid var(--border-color);
            color: var(--text-muted);
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .tab-btn:last-child {
            border-right: none;
        }

        .tab-btn:hover {
            color: var(--text-main);
            background: rgba(226, 248, 53, 0.05);
        }

        .tab-btn.active {
            color: #000;
            background: var(--acid-yellow);
            box-shadow: inset 0 -3px 0px var(--electric-pink);
        }

        /* Main Content */
        .content-body {
            padding: 28px 24px;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Zine Dropzone */
        .dropzone {
            border: 2px dashed var(--acid-yellow);
            background: #15111c;
            padding: 48px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.4);
        }

        .dropzone:hover, .dropzone.dragover {
            border-color: var(--electric-pink);
            background: #201729;
            transform: scale(0.995);
            box-shadow: 4px 4px 0px var(--electric-pink);
        }

        .drop-icon {
            font-size: 2.8rem;
            margin-bottom: 12px;
            display: block;
            filter: drop-shadow(2px 2px 0px var(--electric-pink));
        }

        .drop-text {
            font-family: var(--font-heading);
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        .drop-sub {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Options */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
            margin: 22px 0;
        }

        .option-item {
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s ease;
        }

        .option-item:hover {
            border-color: var(--acid-yellow);
            background: #272033;
        }

        .option-item input[type="checkbox"] {
            appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid var(--text-muted);
            background: var(--tape-black);
            cursor: pointer;
            position: relative;
        }

        .option-item input[type="checkbox"]:checked {
            border-color: var(--acid-yellow);
            background: var(--acid-yellow);
        }

        .option-item input[type="checkbox"]:checked::after {
            content: '✔';
            position: absolute;
            top: -2px;
            left: 2px;
            font-size: 13px;
            color: #000;
            font-weight: bold;
        }

        .option-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .form-row {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 18px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
        }

        .form-control {
            width: 100%;
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            padding: 12px 14px;
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.95rem;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--acid-yellow);
            box-shadow: 2px 2px 0px var(--acid-yellow);
        }

        /* Action Button */
        .btn-action {
            width: 100%;
            background: var(--acid-yellow);
            color: #000;
            border: 2px solid #000;
            box-shadow: 4px 4px 0px #000, 4px 4px 0px 2px var(--electric-pink);
            padding: 16px 24px;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1.15rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.1s ease;
            margin-top: 10px;
        }

        .btn-action:hover {
            background: var(--acid-yellow-hover);
            transform: translate(-1px, -1px);
            box-shadow: 5px 5px 0px #000, 5px 5px 0px 2px var(--electric-pink);
        }

        .btn-action:active {
            transform: translate(2px, 2px);
            box-shadow: 2px 2px 0px #000;
        }

        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Result Banner */
        .result-box {
            display: none;
            margin-top: 24px;
            padding: 18px;
            background: #16261c;
            border: 2px solid #38a169;
            box-shadow: 4px 4px 0px #0a080d;
        }

        .result-box.open {
            display: block;
        }

        .result-title {
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 700;
            color: #48bb78;
            margin-bottom: 8px;
        }

        .result-url-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .result-input {
            flex: 1;
            background: #0d1610;
            border: 1px solid #276749;
            padding: 10px 12px;
            color: #9ae6b4;
            font-family: var(--font-mono);
            font-size: 0.95rem;
            outline: none;
        }

        .btn-copy {
            background: #38a169;
            color: #fff;
            border: 1px solid #000;
            font-family: var(--font-heading);
            font-weight: 700;
            padding: 10px 16px;
            cursor: pointer;
            box-shadow: 2px 2px 0px #000;
        }

        .btn-copy:hover {
            background: #48bb78;
        }

        /* Dashboard & Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--tape-black);
            border: 1px solid var(--border-color);
            padding: 14px;
            text-align: center;
            box-shadow: 3px 3px 0px rgba(0,0,0,0.5);
        }

        .stat-val {
            font-family: var(--font-display);
            font-size: 1.9rem;
            color: var(--acid-yellow);
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        .filter-row {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .dash-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .dash-table th {
            background: var(--tape-black);
            color: var(--acid-yellow);
            font-family: var(--font-heading);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid var(--border-color);
        }

        .dash-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--card-bg);
        }

        .dash-table tr:hover td {
            background: var(--card-surface);
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid #000;
            box-shadow: 1px 1px 0px #000;
        }

        .badge-file { background: var(--acid-yellow); color: #000; }
        .badge-url { background: #38bdf8; color: #000; }
        .badge-oneshot { background: var(--electric-pink); color: #fff; }

        .btn-action-icon {
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            padding: 4px 8px;
            cursor: pointer;
            box-shadow: 2px 2px 0px #000;
            text-decoration: none;
            display: inline-block;
        }

        .btn-action-icon:hover {
            border-color: var(--acid-yellow);
            color: var(--acid-yellow);
        }

        .btn-delete {
            border-color: var(--electric-pink);
            color: var(--electric-pink);
        }

        .btn-delete:hover {
            background: var(--electric-pink);
            color: #fff;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(10, 8, 13, 0.85);
            backdrop-filter: blur(5px);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.open {
            display: flex;
        }

        .modal-card {
            background: var(--card-bg);
            border: 2px solid var(--acid-yellow);
            box-shadow: 8px 8px 0px var(--electric-pink);
            max-width: 90vw;
            max-height: 90vh;
            padding: 24px;
            position: relative;
            overflow: auto;
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 14px;
            background: var(--electric-pink);
            color: #fff;
            border: 1px solid #000;
            font-family: var(--font-mono);
            font-size: 1.2rem;
            padding: 2px 8px;
            cursor: pointer;
            box-shadow: 2px 2px 0px #000;
        }

        /* Footer */
        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        .footer a {
            color: var(--acid-yellow);
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="duct-tape">MALÍCIA NA BORDA // GOSSIP AT THE EDGE</div>

        <!-- Header -->
        <header class="top-bar">
            <div class="brand-row">
                <div class="brand-box">
                    <h1 class="brand">{$t('app_name')}</h1>
                    <span class="brand-sub">{$t('brand_sub')}</span>
                </div>
                <div class="lang-switch">
                    <button class="lang-btn {$activePt}" onclick="setLanguage('pt')">PT-BR</button>
                    <button class="lang-btn {$activeEn}" onclick="setLanguage('en')">EN</button>
                </div>
            </div>
            <div>
                <span class="tagline-badge">⚡ {$t('tagline')}</span>
            </div>

            <!-- Token Section -->
            <div class="token-group">
                <label class="token-label" for="authToken">
                    <span>{$t('token_label')}</span>
                    <span style="color:var(--acid-yellow);">{$publicNotice}</span>
                </label>
                <input type="password" id="authToken" class="token-input" placeholder="{$t('token_placeholder')}" spellcheck="false">
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="tabs">
            <button class="tab-btn active" onclick="switchTab('uploadTab', this)">📁 {$t('tab_file')}</button>
            <button class="tab-btn" onclick="switchTab('urlTab', this)">🔗 {$t('tab_url')}</button>
            <button class="tab-btn" onclick="switchTab('dashTab', this)">👁️ {$t('tab_dash')}</button>
        </nav>

        <!-- Content Area -->
        <main class="content-body">
            <!-- 1. File Upload Tab -->
            <section id="uploadTab" class="tab-pane active">
                <div id="dropzone" class="dropzone">
                    <input type="file" id="fileInput" style="display:none;">
                    <span class="drop-icon">📦</span>
                    <p class="drop-text" id="dropTitle">{$t('drop_idle')}</p>
                    <p class="drop-sub">{$t('drop_sub')} (max: {$maxSizeMb} MB)</p>
                    <p id="selectedFileName" style="margin-top:14px; font-weight:700; color:var(--acid-yellow);"></p>
                </div>

                <div class="options-grid">
                    <label class="option-item">
                        <input type="checkbox" id="optOneshot">
                        <span class="option-label">🔥 {$t('opt_burn')}</span>
                    </label>
                    <label class="option-item">
                        <input type="checkbox" id="optSecret">
                        <span class="option-label">🕵️ {$t('opt_secret')}</span>
                    </label>
                </div>

                <div class="form-row">
                    <label class="form-label" for="optExpires">⌛ {$t('opt_expires')}</label>
                    <select id="optExpires" class="form-control">
                        <option value="">{$t('exp_none')}</option>
                        <option value="1h">{$t('exp_1h')}</option>
                        <option value="24" selected>{$t('exp_24h')}</option>
                        <option value="7d">{$t('exp_7d')}</option>
                        <option value="30d">{$t('exp_30d')}</option>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label" for="optCustomName">🏷️ {$t('custom_name_placeholder')}</label>
                    <input type="text" id="optCustomName" class="form-control" placeholder="fofoca-exclusiva">
                </div>

                <button id="btnUpload" class="btn-action" onclick="handleFileUpload()">{$t('btn_upload')}</button>

                <div id="uploadResult" class="result-box">
                    <p class="result-title">⚡ {$t('copy_success')}</p>
                    <div class="result-url-row">
                        <input type="text" id="resultUrl" class="result-input" readonly>
                        <button class="btn-copy" onclick="copyResultUrl()">{$t('act_copy')}</button>
                    </div>
                </div>
            </section>

            <!-- 2. URL Shortener Tab -->
            <section id="urlTab" class="tab-pane">
                <div class="form-row">
                    <label class="form-label" for="shortenUrl">🌐 {$t('url_placeholder')}</label>
                    <input type="url" id="shortenUrl" class="form-control" placeholder="https://...">
                </div>

                <div class="form-row">
                    <label class="form-label" for="urlCustomName">🏷️ {$t('custom_name_placeholder')}</label>
                    <input type="text" id="urlCustomName" class="form-control" placeholder="link-quente">
                </div>

                <div class="options-grid">
                    <label class="option-item">
                        <input type="checkbox" id="urlOneshot">
                        <span class="option-label">🔥 {$t('opt_burn')}</span>
                    </label>
                    <label class="option-item">
                        <input type="checkbox" id="urlSecret">
                        <span class="option-label">🕵️ {$t('opt_secret')}</span>
                    </label>
                </div>

                <button id="btnShorten" class="btn-action" onclick="handleUrlShorten()">{$t('btn_shorten')}</button>

                <div id="shortenResult" class="result-box">
                    <p class="result-title">⚡ {$t('copy_success')}</p>
                    <div class="result-url-row">
                        <input type="text" id="shortenResultUrl" class="result-input" readonly>
                        <button class="btn-copy" onclick="copyShortenUrl()">{$t('act_copy')}</button>
                    </div>
                </div>
            </section>

            <!-- 3. Dashboard Tab -->
            <section id="dashTab" class="tab-pane">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div id="statFiles" class="stat-val">0</div>
                        <div class="stat-label">{$t('stat_files')}</div>
                    </div>
                    <div class="stat-card">
                        <div id="statUrls" class="stat-val">0</div>
                        <div class="stat-label">{$t('stat_urls')}</div>
                    </div>
                    <div class="stat-card">
                        <div id="statBytes" class="stat-val">0 MB</div>
                        <div class="stat-label">{$t('stat_bytes')}</div>
                    </div>
                    <div class="stat-card">
                        <div id="statViews" class="stat-val">0</div>
                        <div class="stat-label">{$t('stat_views')}</div>
                    </div>
                </div>

                <div class="filter-row">
                    <input type="text" id="dashSearch" class="form-control" style="flex:2;" placeholder="{$t('dash_search')}" oninput="debounce(loadDashboard, 300)()">
                    <select id="dashType" class="form-control" style="flex:1;" onchange="loadDashboard()">
                        <option value="">{$t('dash_filter_all')}</option>
                        <option value="file">{$t('dash_filter_files')}</option>
                        <option value="url">{$t('dash_filter_urls')}</option>
                    </select>
                </div>

                <div style="overflow-x:auto;">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>{$t('col_slug')}</th>
                                <th>{$t('col_type')}</th>
                                <th>{$t('col_size')}</th>
                                <th>{$t('col_exp')}</th>
                                <th>{$t('col_views')}</th>
                                <th>{$t('col_actions')}</th>
                            </tr>
                        </thead>
                        <tbody id="dashTableBody">
                            <tr>
                                <td colspan="6" style="text-align:center; color:var(--text-muted);">{$t('dash_auth_required')}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="modal" onclick="closeModal()">
        <div class="modal-card" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeModal()">×</button>
            <div id="modalBody"></div>
        </div>
    </div>

    <footer class="footer">
        <p><strong>FUXICO</strong> // <a href="https://codeberg.org/lumenpink/picoshare" target="_blank">Codeberg</a> // 100% 0x0.st & rustypaste drop-in protocol</p>
    </footer>

    <script>
        const I18N = {$strings};

        function t(k) { return I18N[k] || k; }

        function setLanguage(lang) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        // Token management via localStorage
        const tokenInput = document.getElementById('authToken');
        const savedToken = localStorage.getItem('fuxico_token') || localStorage.getItem('pico_auth_token');
        if (savedToken) {
            tokenInput.value = savedToken;
        }

        tokenInput.addEventListener('input', () => {
            const val = tokenInput.value.trim();
            localStorage.setItem('fuxico_token', val);
            if (document.getElementById('dashTab').classList.contains('active')) {
                loadDashboard();
            }
        });

        // Tab Switching
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(tabId).classList.add('active');

            if (tabId === 'dashTab') {
                loadDashboard();
            }
        }

        // File Drag & Drop
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const dropTitle = document.getElementById('dropTitle');
        let selectedFile = null;

        dropzone.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                selectFile(e.target.files[0]);
            }
        });

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
            dropTitle.innerText = t('drop_drag');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
            dropTitle.innerText = t('drop_idle');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            dropTitle.innerText = t('drop_idle');
            if (e.dataTransfer.files.length > 0) {
                selectFile(e.dataTransfer.files[0]);
            }
        });

        function selectFile(file) {
            selectedFile = file;
            document.getElementById('selectedFileName').innerText = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        }

        // File Upload
        async function handleFileUpload() {
            if (!selectedFile) {
                alert(t('drop_idle'));
                return;
            }

            const btn = document.getElementById('btnUpload');
            btn.disabled = true;
            btn.innerText = t('btn_uploading');

            const fd = new FormData();
            fd.append('file', selectedFile);

            const expires = document.getElementById('optExpires').value;
            if (expires) fd.append('expires', expires);

            if (document.getElementById('optOneshot').checked) fd.append('oneshot', 'true');
            if (document.getElementById('optSecret').checked) fd.append('secret', 'true');

            const customName = document.getElementById('optCustomName').value.trim();
            if (customName) fd.append('customName', customName);

            const headers = {};
            const token = tokenInput.value.trim();
            if (token) headers['Authorization'] = 'Bearer ' + token;

            try {
                const res = await fetch('/', { method: 'POST', body: fd, headers });
                const text = await res.text();
                if (res.ok) {
                    const url = text.trim();
                    document.getElementById('resultUrl').value = url;
                    document.getElementById('uploadResult').classList.add('open');
                } else {
                    alert('Erro no envio: ' + text);
                }
            } catch (err) {
                alert('Falha na rede: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = t('btn_upload');
            }
        }

        // URL Shortening
        async function handleUrlShorten() {
            const url = document.getElementById('shortenUrl').value.trim();
            if (!url) {
                alert('Informe uma URL válida.');
                return;
            }

            const btn = document.getElementById('btnShorten');
            btn.disabled = true;

            const fd = new FormData();
            fd.append('shorten', url);

            if (document.getElementById('urlOneshot').checked) fd.append('oneshot', 'true');
            if (document.getElementById('urlSecret').checked) fd.append('secret', 'true');

            const customName = document.getElementById('urlCustomName').value.trim();
            if (customName) fd.append('customName', customName);

            const headers = {};
            const token = tokenInput.value.trim();
            if (token) headers['Authorization'] = 'Bearer ' + token;

            try {
                const res = await fetch('/', { method: 'POST', body: fd, headers });
                const text = await res.text();
                if (res.ok) {
                    const shortUrl = text.trim();
                    document.getElementById('shortenResultUrl').value = shortUrl;
                    document.getElementById('shortenResult').classList.add('open');
                } else {
                    alert('Erro ao encurtar: ' + text);
                }
            } catch (err) {
                alert('Falha na rede: ' + err.message);
            } finally {
                btn.disabled = false;
            }
        }

        function copyResultUrl() {
            const val = document.getElementById('resultUrl').value;
            navigator.clipboard.writeText(val).then(() => alert(t('copy_success')));
        }

        function copyShortenUrl() {
            const val = document.getElementById('shortenResultUrl').value;
            navigator.clipboard.writeText(val).then(() => alert(t('copy_success')));
        }

        // Dashboard
        async function loadDashboard() {
            const tbody = document.getElementById('dashTableBody');
            const token = tokenInput.value.trim();

            if (!token) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--text-muted);">' + t('dash_auth_required') + '</td></tr>';
                return;
            }

            const search = encodeURIComponent(document.getElementById('dashSearch').value.trim());
            const type = encodeURIComponent(document.getElementById('dashType').value);

            try {
                // Stats
                const statsRes = await fetch('/api/stats', { headers: { 'Authorization': 'Bearer ' + token } });
                if (statsRes.ok) {
                    const stats = await statsRes.json();
                    document.getElementById('statFiles').innerText = stats.total_files;
                    document.getElementById('statUrls').innerText = stats.total_urls;
                    document.getElementById('statBytes').innerText = (stats.total_bytes / (1024*1024)).toFixed(1) + ' MB';
                    document.getElementById('statViews').innerText = stats.total_views;
                }

                // Items
                const res = await fetch('/api/items?search=' + search + '&type=' + type, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });

                if (!res.ok) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--electric-pink);">Acesso não autorizado ao cofre.</td></tr>';
                    return;
                }

                const data = await res.json();
                if (!data.items || data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--text-muted);">' + t('dash_empty') + '</td></tr>';
                    return;
                }

                let html = '';
                data.items.forEach(item => {
                    const sizeStr = item.type === 'file' ? (item.size / 1024).toFixed(1) + ' KB' : '-';
                    const typeBadge = item.type === 'file' ? '<span class="badge badge-file">' + t('tab_file') + '</span>' : '<span class="badge badge-url">URL</span>';
                    const oneshotBadge = item.is_oneshot ? '<span class="badge badge-oneshot">🔥 Oneshot</span>' : '';
                    const expStr = item.expires_at ? new Date(item.expires_at * 1000).toLocaleString() : t('exp_none');

                    html += '<tr>' +
                        '<td><strong style="color:var(--acid-yellow); font-family:var(--font-mono);">' + escapeHtml(item.slug) + '</strong><br><small style="color:var(--text-muted);">' + escapeHtml(item.original_name || '') + '</small></td>' +
                        '<td>' + typeBadge + '</td>' +
                        '<td>' + sizeStr + '</td>' +
                        '<td>' + oneshotBadge + ' <small style="color:var(--text-muted);">' + expStr + '</small></td>' +
                        '<td>' + item.views + '</td>' +
                        '<td>' +
                            '<button class="btn-action-icon" onclick="copyText(\'' + escapeHtml(item.url) + '\')">' + t('act_copy') + '</button> ' +
                            (item.type === 'file' ? '<button class="btn-action-icon" onclick="previewItem(\'' + escapeHtml(item.url) + '\', \'' + escapeHtml(item.mime_type) + '\')">' + t('act_view') + '</button> ' : '<a class="btn-action-icon" href="' + escapeHtml(item.url) + '" target="_blank">' + t('act_open') + '</a> ') +
                            '<button class="btn-action-icon btn-delete" onclick="deleteItem(\'' + escapeHtml(item.slug) + '\')">' + t('act_delete') + '</button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--electric-pink);">Erro ao carregar dados: ' + escapeHtml(err.message) + '</td></tr>';
            }
        }

        async function deleteItem(slug) {
            if (!confirm(t('delete_confirm'))) return;
            const token = tokenInput.value.trim();
            try {
                const res = await fetch('/api/items/' + slug, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                if (res.ok) {
                    loadDashboard();
                } else {
                    alert('Falha ao apagar item.');
                }
            } catch (err) {
                alert('Erro: ' + err.message);
            }
        }

        function previewItem(url, mime) {
            const modal = document.getElementById('previewModal');
            const body = document.getElementById('modalBody');
            body.innerHTML = '';

            if (mime.startsWith('image/')) {
                body.innerHTML = '<img src="' + url + '" style="max-width:100%; max-height:75vh; border:2px solid var(--acid-yellow);">';
            } else if (mime.startsWith('video/')) {
                body.innerHTML = '<video src="' + url + '" controls autoplay style="max-width:100%; max-height:75vh; border:2px solid var(--acid-yellow);"></video>';
            } else if (mime.startsWith('audio/')) {
                body.innerHTML = '<audio src="' + url + '" controls autoplay style="width:100%; margin-top:20px;"></audio>';
            } else {
                window.open(url, '_blank');
                return;
            }
            modal.classList.add('open');
        }

        function closeModal() {
            document.getElementById('previewModal').classList.remove('open');
            document.getElementById('modalBody').innerHTML = '';
        }

        function copyText(str) {
            navigator.clipboard.writeText(str).then(() => alert(t('copy_success')));
        }

        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>'"]/g, tag => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[tag] || tag));
        }
    </script>
</body>
</html>
HTML;
    }

    public static function renderErrorPage(int $statusCode, string $titleKey, string $descKey, ?I18n $i18n = null): string
    {
        $i18n ??= new I18n;
        $title = htmlspecialchars($i18n->t($titleKey), ENT_QUOTES, 'UTF-8');
        $desc = htmlspecialchars($i18n->t($descKey), ENT_QUOTES, 'UTF-8');
        $back = htmlspecialchars($i18n->t('back_home'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$i18n->getLang()}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} - {$title}</title>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #121016;
            background-image: radial-gradient(circle, rgba(226, 248, 53, 0.05) 1.5px, transparent 1.5px);
            background-size: 20px 20px;
            color: #f5f4ef;
            font-family: 'Space Mono', monospace;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
            margin: 0;
        }
        .error-card {
            background: #1a1622;
            border: 2px solid #ff2a85;
            box-shadow: 6px 6px 0px #e2f835;
            padding: 40px 30px;
            max-width: 540px;
            text-align: center;
        }
        .error-code {
            font-family: 'Anton', sans-serif;
            font-size: 5rem;
            color: #ff2a85;
            line-height: 1;
            margin-bottom: 12px;
            text-shadow: 3px 3px 0px #000;
        }
        .error-title {
            font-family: 'Anton', sans-serif;
            font-size: 1.8rem;
            color: #e2f835;
            margin-bottom: 16px;
            text-transform: uppercase;
        }
        .error-desc {
            font-size: 0.95rem;
            color: #9f97ad;
            margin-bottom: 28px;
            line-height: 1.5;
        }
        .btn-home {
            display: inline-block;
            background: #e2f835;
            color: #000;
            border: 2px solid #000;
            box-shadow: 3px 3px 0px #000;
            padding: 12px 24px;
            font-weight: 700;
            text-decoration: none;
            text-transform: uppercase;
        }
        .btn-home:hover {
            background: #ccee22;
            transform: translate(-1px, -1px);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-code">{$statusCode}</div>
        <h1 class="error-title">{$title}</h1>
        <p class="error-desc">{$desc}</p>
        <a href="/" class="btn-home">{$back}</a>
    </div>
</body>
</html>
HTML;
    }
}
}

/* --- Module: App.php --- */
namespace PicoShare {
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
}

/* --- Runner & Namespace Compatibility --- */
namespace {
    // Class aliases for Fuxico namespace
    if (!class_exists(\Fuxico\App::class, false)) {
        class_alias(\PicoShare\App::class, \Fuxico\App::class);
        class_alias(\PicoShare\Config::class, \Fuxico\Config::class);
        class_alias(\PicoShare\Database::class, \Fuxico\Database::class);
        class_alias(\PicoShare\Storage::class, \Fuxico\Storage::class);
        class_alias(\PicoShare\Views::class, \Fuxico\Views::class);
        class_alias(\PicoShare\MimeValidator::class, \Fuxico\MimeValidator::class);
        class_alias(\PicoShare\I18n::class, \Fuxico\I18n::class);
    }

    // Auto-dispatch when executed directly via web server or CLI
    if (!defined('FUXICO_NO_RUN')) {
        $isCli = (PHP_SAPI === 'cli');
        $isDirectCli = $isCli && (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));
        $isWebServer = !$isCli;

        if ($isWebServer || $isDirectCli) {
            $app = new \PicoShare\App();
            $app->handleRequest();
        }
    }
}
