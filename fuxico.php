<?php

declare(strict_types=1);

/**
 * ┌─────────────────────────────────────────────────────────────┐
 * │  FUXICO — A malícia popular do boca a boca                  │
 * │  Single-file portable file sharing & URL shortener          │
 * │  100% compatible with 0x0.st and rustypaste protocols      │
 * └─────────────────────────────────────────────────────────────┘
 *
 * Version: 0.3.0-dev.125+20261023.9ed568e+5c576de
 * Built:   2026-10-23 02:49:10 UTC
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
        define('FUXICO_VERSION', '0.3.0-dev.125+20261023.9ed568e+5c576de');
        define('FUXICO_BUILD_DATE', '2026-10-23 02:49:10 UTC');
    }
}

/* --- Module: Config.php --- */
namespace Fuxico {
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

    public string $joajuUrl;

    public string $resenhaUrl;

    public int $resenhaTimeout;

    public function __construct(?string $baseDir = null)
    {
        $base = $baseDir ?? dirname(__DIR__);
        $this->dataDir = (string) (getenv('FUXICO_DATA_DIR') ?: $base.'/data');
        $this->filesDir = $this->dataDir.'/files';
        $this->dbPath = $this->dataDir.'/fuxico.sqlite';

        // Public Mode: if true, anyone can upload. Dashboard & bulk list still require master token.
        $envPublic = getenv('FUXICO_PUBLIC_MODE');
        $this->publicMode = $envPublic !== false ? filter_var($envPublic, FILTER_VALIDATE_BOOLEAN) : false;

        // Auth Tokens
        $envTokens = getenv('FUXICO_AUTH_TOKENS');
        if ($envTokens !== false && trim((string) $envTokens) !== '') {
            $this->authTokens = array_map('trim', explode(',', (string) $envTokens));
        } else {
            $this->authTokens = [];
        }

        // Upload Limits & Slugs
        $this->maxUploadSize = (int) (getenv('FUXICO_MAX_UPLOAD_SIZE') ?: self::DEFAULT_MAX_UPLOAD_SIZE);
        $this->allowedChars = self::DEFAULT_ALLOWED_CHARS;
        $this->filenameLength = (int) (getenv('FUXICO_SLUG_LENGTH') ?: self::DEFAULT_FILENAME_LENGTH);
        $this->secretFilenameLength = (int) (getenv('FUXICO_SECRET_SLUG_LENGTH') ?: self::DEFAULT_SECRET_FILENAME_LENGTH);
        $this->baseUrl = getenv('FUXICO_BASE_URL') ?: null;
        $this->joajuUrl = (string) (getenv('FUXICO_JOAJU_URL') ?: 'http://joaju:3000');
        $this->resenhaUrl = (string) (getenv('RESENHA_API_URL') ?: '');
        $this->resenhaTimeout = (int) (getenv('RESENHA_TIMEOUT') ?: 15);
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

/* --- Module: SimpleZip.php --- */
namespace Fuxico {
/**
 * Pure PHP lightweight ZIP archive builder.
 *
 * Implements standard PKZIP 2.0 format with DEFLATE (gzdeflate) compression.
 * Does not require ext-zip or external binaries.
 */
class SimpleZip
{
    /** @var array<string, string> */
    private array $files = [];

    public function addFile(string $name, string $data): void
    {
        $cleanName = str_replace('\\', '/', ltrim($name, '/'));
        $this->files[$cleanName] = $data;
    }

    public function build(): string
    {
        $ctrlDir = '';
        $dataBlocks = '';
        $offset = 0;
        $time = time();

        $year = (int) date('Y', $time);
        $dosYear = max(0, $year - 1980);
        $dtime = ($dosYear << 25) | ((int) date('m', $time) << 21) | ((int) date('d', $time) << 16) |
                 ((int) date('H', $time) << 11) | ((int) date('i', $time) << 5) | ((int) ((int) date('s', $time) / 2));

        foreach ($this->files as $name => $data) {
            $uncLen = strlen($data);
            $crc = crc32($data);

            $deflated = function_exists('gzdeflate') ? @gzdeflate($data) : false;
            if ($deflated !== false && strlen($deflated) < $uncLen) {
                $compData = $deflated;
                $compMethod = 8; // DEFLATE
            } else {
                $compData = $data;
                $compMethod = 0; // STORE
            }
            $cLen = strlen($compData);

            // Local File Header
            $localHeader = "\x50\x4b\x03\x04".
                           pack('v', 20).         // Version needed to extract (2.0)
                           pack('v', 0).          // General purpose bit flag
                           pack('v', $compMethod). // Compression method
                           pack('V', $dtime).      // Mod time & date
                           pack('V', $crc).        // CRC32
                           pack('V', $cLen).       // Compressed size
                           pack('V', $uncLen).     // Uncompressed size
                           pack('v', strlen($name)). // File name length
                           pack('v', 0);           // Extra field length

            $dataBlocks .= $localHeader.$name.$compData;

            // Central Directory Record
            $ctrlRecord = "\x50\x4b\x01\x02".
                          pack('v', 20).          // Version made by (2.0)
                          pack('v', 20).          // Version needed (2.0)
                          pack('v', 0).           // Bit flag
                          pack('v', $compMethod).  // Compression method
                          pack('V', $dtime).       // Mod time & date
                          pack('V', $crc).         // CRC32
                          pack('V', $cLen).        // Compressed size
                          pack('V', $uncLen).      // Uncompressed size
                          pack('v', strlen($name)). // File name length
                          pack('v', 0).            // Extra field length
                          pack('v', 0).            // File comment length
                          pack('v', 0).            // Disk number start
                          pack('v', 0).            // Internal file attributes
                          pack('V', 32).           // External file attributes (regular archive file)
                          pack('V', $offset);      // Relative offset of local header

            $ctrlDir .= $ctrlRecord.$name;

            $offset += strlen($localHeader) + strlen($name) + $cLen;
        }

        $totalFiles = count($this->files);

        // End of Central Directory Record (EOCD)
        $eocd = "\x50\x4b\x05\x06".
                pack('v', 0).              // Number of this disk
                pack('v', 0).              // Disk with central directory
                pack('v', $totalFiles).     // Entries on this disk
                pack('v', $totalFiles).     // Total entries
                pack('V', strlen($ctrlDir)).// Size of central directory
                pack('V', $offset).         // Offset of central directory
                pack('v', 0);               // Comment length

        return $dataBlocks.$ctrlDir.$eocd;
    }
}
}

/* --- Module: Storage.php --- */
namespace Fuxico {
class Storage
{
    private string $dataDir;

    private string $filesDir;

    private string $reposDir;

    public function __construct(string $dataDir, string $filesDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
        $this->filesDir = rtrim($filesDir, '/');
        $this->reposDir = $this->dataDir.'/repos';
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
        if (! is_dir($this->reposDir)) {
            mkdir($this->reposDir, 0755, true);
        }

        $this->protectDirectory($this->dataDir);
        $this->protectDirectory($this->filesDir);
        $this->protectDirectory($this->reposDir);
    }

    /**
     * Write access protection rules (.htaccess and index.html) to prevent direct web access.
     */
    private function protectDirectory(string $dir): void
    {
        $htaccessPath = $dir.'/.htaccess';
        if (! file_exists($htaccessPath)) {
            $htaccess = "# Fuxico Security Lockdown\n"
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

    public function getRepoPath(string $slug): string
    {
        $cleanSlug = basename($slug);
        if (! str_ends_with($cleanSlug, '.git')) {
            $cleanSlug .= '.git';
        }

        return $this->reposDir.'/'.$cleanSlug;
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

    public function getFilesDir(): string
    {
        return $this->filesDir;
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
     * Completely removes all physical files, baked HTML, and asset directory for an item.
     */
    public function deleteItemFiles(string $slug, ?string $filePath = null): void
    {
        if ($filePath !== null && $filePath !== '') {
            $this->deleteFile($filePath);
        }

        $htmlFile = $this->getFilePath("{$slug}.html");
        if (file_exists($htmlFile)) {
            @unlink($htmlFile);
        }

        $pdfFile = $this->getFilePath("{$slug}.pdf");
        if (file_exists($pdfFile)) {
            @unlink($pdfFile);
        }

        $assetsDir = $this->filesDir."/{$slug}_assets";
        if (is_dir($assetsDir)) {
            $this->deleteDirectory($assetsDir);
        }

        $gistDir = $this->filesDir."/{$slug}_gist";
        if (is_dir($gistDir)) {
            $this->deleteDirectory($gistDir);
        }

        $zipFile = $this->getFilePath("{$slug}.zip");
        if (file_exists($zipFile)) {
            @unlink($zipFile);
        }

        $repoDir = $this->getRepoPath($slug);
        if (is_dir($repoDir)) {
            $this->deleteDirectory($repoDir);
        }
    }

    public function deleteDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $target = $dir.'/'.$file;
            is_dir($target) ? $this->deleteDirectory($target) : @unlink($target);
        }

        return @rmdir($dir);
    }

    /**
     * Store files for a Gist in data/files/<slug>_gist/
     *
     * @param  array<string, string>  $files
     */
    public function storeGistFiles(string $slug, array $files): bool
    {
        $gistDir = $this->filesDir."/{$slug}_gist";
        if (! is_dir($gistDir)) {
            mkdir($gistDir, 0755, true);
        }

        foreach ($files as $name => $content) {
            $cleanName = str_replace(['../', '..\\'], '', ltrim($name, '/\\'));
            $cleanName = preg_replace('/[^\w\-.\/]/', '_', $cleanName) ?: 'file';
            $filePath = $gistDir.'/'.$cleanName;
            $fileSubdir = dirname($filePath);
            if (! is_dir($fileSubdir)) {
                mkdir($fileSubdir, 0755, true);
            }
            if (file_put_contents($filePath, $content) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieve all files for a Gist.
     *
     * @return array<string, string> Map of relative filename => content
     */
    public function getGistFiles(string $slug): array
    {
        $gistDir = $this->filesDir."/{$slug}_gist";
        if (! is_dir($gistDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($gistDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $relative = substr($fileInfo->getPathname(), strlen($gistDir) + 1);
                $relative = str_replace('\\', '/', $relative);
                $content = file_get_contents($fileInfo->getPathname());
                if ($content !== false) {
                    $files[$relative] = $content;
                }
            }
        }

        return $files;
    }

    /**
     * Retrieve a specific file from a Gist.
     */
    public function getGistFile(string $slug, string $filename): ?string
    {
        $cleanName = str_replace(['../', '..\\'], '', ltrim($filename, '/\\'));
        $filePath = $this->filesDir."/{$slug}_gist/".$cleanName;
        if (file_exists($filePath) && is_file($filePath)) {
            $content = file_get_contents($filePath);

            return $content !== false ? $content : null;
        }

        return null;
    }

    /**
     * Returns the ZIP archive binary for a Gist (generating and caching it if needed).
     */
    public function getGistZip(string $slug): ?string
    {
        $zipPath = $this->getFilePath("{$slug}.zip");
        if (file_exists($zipPath)) {
            $data = file_get_contents($zipPath);

            return $data !== false ? $data : null;
        }

        $files = $this->getGistFiles($slug);
        if (empty($files)) {
            return null;
        }

        $zip = new SimpleZip;
        foreach ($files as $name => $content) {
            $zip->addFile($name, $content);
        }

        $zipBinary = $zip->build();
        file_put_contents($zipPath, $zipBinary);

        return $zipBinary;
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

        // Clean any output buffers (preserve test buffers in CLI)
        if (php_sapi_name() !== 'cli') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
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
namespace Fuxico {
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
        'r' => ['text/plain', 'text/x-r', 'text/x-rsrc', 'application/octet-stream'],
        'rmd' => ['text/plain', 'text/markdown', 'text/x-markdown'],

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
namespace Fuxico {
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
                max_downloads INTEGER NULL,
                hash TEXT NULL,
                expires_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                views INTEGER DEFAULT 0,
                user_id TEXT NULL,
                source TEXT NOT NULL DEFAULT "native",
                mocambo_meta TEXT NULL,
                data TEXT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_items_slug ON items(slug);
            CREATE INDEX IF NOT EXISTS idx_items_expires ON items(expires_at);
            CREATE INDEX IF NOT EXISTS idx_items_type ON items(type);
            CREATE INDEX IF NOT EXISTS idx_items_created ON items(created_at);

            CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                username TEXT NOT NULL UNIQUE,
                token TEXT NOT NULL UNIQUE,
                role TEXT NOT NULL DEFAULT "member",
                created_at INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_users_token ON users(token);
            CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
        ');

        // Check if user_id, max_downloads, hash, source, mocambo_meta, data columns exist on pre-existing items table
        $columns = $this->pdo->query('PRAGMA table_info(items)')->fetchAll();
        $hasUserId = false;
        $hasMaxDownloads = false;
        $hasHash = false;
        $hasSource = false;
        $hasMocamboMeta = false;
        $hasData = false;
        foreach ($columns as $col) {
            $colName = $col['name'] ?? '';
            if ($colName === 'user_id') {
                $hasUserId = true;
            } elseif ($colName === 'max_downloads') {
                $hasMaxDownloads = true;
            } elseif ($colName === 'hash') {
                $hasHash = true;
            } elseif ($colName === 'source') {
                $hasSource = true;
            } elseif ($colName === 'mocambo_meta') {
                $hasMocamboMeta = true;
            } elseif ($colName === 'data') {
                $hasData = true;
            }
        }
        if (! $hasUserId) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN user_id TEXT NULL;');
        }
        if (! $hasMaxDownloads) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN max_downloads INTEGER NULL DEFAULT NULL;');
        }
        if (! $hasHash) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN hash TEXT NULL DEFAULT NULL;');
        }
        if (! $hasSource) {
            $this->pdo->exec("ALTER TABLE items ADD COLUMN source TEXT NOT NULL DEFAULT 'native';");
        }
        if (! $hasMocamboMeta) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN mocambo_meta TEXT NULL;');
        }
        if (! $hasData) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN data TEXT NULL;');
        }
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_user_id ON items(user_id);');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_source ON items(source);');
    }

    public function isSlugTaken(string $slug): bool
    {
        $reserved = ['api', 'list', 'painel', 'index.php', 'favicon.ico', 'robots.txt'];
        if (in_array(strtolower($slug), $reserved, true)) {
            return true;
        }

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
     *   size_bytes?: int,
     *   x_token: string,
     *   is_oneshot?: int,
     *   max_downloads?: ?int,
     *   hash?: ?string,
     *   expires_at?: ?int,
     *   created_at?: int,
     *   views?: int,
     *   user_id?: ?string,
     *   source?: string,
     *   mocambo_meta?: ?string,
     *   data?: ?string
     * } $data
     */
    public function createItem(array $data): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO items (
                id, slug, original_name, type, target_url, file_path,
                mime_type, size, x_token, is_oneshot, max_downloads, hash, expires_at, created_at, views, user_id,
                source, mocambo_meta, data
            ) VALUES (
                :id, :slug, :original_name, :type, :target_url, :file_path,
                :mime_type, :size, :x_token, :is_oneshot, :max_downloads, :hash, :expires_at, :created_at, :views, :user_id,
                :source, :mocambo_meta, :data
            )
        ');

        return $stmt->execute([
            ':id' => $data['id'] ?? bin2hex(random_bytes(8)),
            ':slug' => $data['slug'],
            ':original_name' => $data['original_name'] ?? null,
            ':type' => $data['type'],
            ':target_url' => $data['target_url'] ?? null,
            ':file_path' => $data['file_path'] ?? null,
            ':mime_type' => $data['mime_type'] ?? 'application/octet-stream',
            ':size' => $data['size_bytes'] ?? $data['size'] ?? 0,
            ':x_token' => $data['x_token'],
            ':is_oneshot' => $data['is_oneshot'] ?? 0,
            ':max_downloads' => $data['max_downloads'] ?? null,
            ':hash' => $data['hash'] ?? null,
            ':expires_at' => $data['expires_at'] ?? null,
            ':created_at' => $data['created_at'] ?? time(),
            ':views' => $data['views'] ?? 0,
            ':user_id' => $data['user_id'] ?? null,
            ':source' => $data['source'] ?? 'native',
            ':mocambo_meta' => $data['mocambo_meta'] ?? null,
            ':data' => $data['data'] ?? null,
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
    public function listItems(
        int $limit = 100,
        int $offset = 0,
        ?string $search = null,
        ?string $type = null,
        ?string $userId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $sql = '
            SELECT items.id, items.slug, items.original_name, items.type, items.target_url, items.file_path,
                   items.mime_type, items.size, items.is_oneshot, items.expires_at, items.created_at, items.views,
                   items.user_id, users.username as author
            FROM items
            LEFT JOIN users ON items.user_id = users.id
            WHERE 1=1
        ';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (items.slug LIKE :search OR items.original_name LIKE :search OR items.target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url', 'doc', 'gist', 'git'], true)) {
            $sql .= ' AND items.type = :type';
            $params[':type'] = $type;
        }

        if ($userId === 'none' || $userId === 'anonymous') {
            $sql .= ' AND items.user_id IS NULL';
        } elseif ($userId !== null && trim($userId) !== '') {
            $sql .= ' AND items.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $tsFrom = is_numeric($dateFrom) ? (int) $dateFrom : strtotime(trim($dateFrom).' 00:00:00');
            if ($tsFrom !== false) {
                $sql .= ' AND items.created_at >= :date_from';
                $params[':date_from'] = $tsFrom;
            }
        }

        if ($dateTo !== null && trim($dateTo) !== '') {
            $tsTo = is_numeric($dateTo) ? (int) $dateTo : strtotime(trim($dateTo).' 23:59:59');
            if ($tsTo !== false) {
                $sql .= ' AND items.created_at <= :date_to';
                $params[':date_to'] = $tsTo;
            }
        }

        $sql .= ' ORDER BY items.created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countItems(
        ?string $search = null,
        ?string $type = null,
        ?string $userId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        $sql = 'SELECT COUNT(*) FROM items WHERE 1=1';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (slug LIKE :search OR original_name LIKE :search OR target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url', 'doc', 'gist', 'git'], true)) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        if ($userId === 'none' || $userId === 'anonymous') {
            $sql .= ' AND user_id IS NULL';
        } elseif ($userId !== null && trim($userId) !== '') {
            $sql .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $tsFrom = is_numeric($dateFrom) ? (int) $dateFrom : strtotime(trim($dateFrom).' 00:00:00');
            if ($tsFrom !== false) {
                $sql .= ' AND created_at >= :date_from';
                $params[':date_from'] = $tsFrom;
            }
        }

        if ($dateTo !== null && trim($dateTo) !== '') {
            $tsTo = is_numeric($dateTo) ? (int) $dateTo : strtotime(trim($dateTo).' 23:59:59');
            if ($tsTo !== false) {
                $sql .= ' AND created_at <= :date_to';
                $params[':date_to'] = $tsTo;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<array{id: int|string, slug: string, file_path: ?string, type: string}>
     */
    public function getItemsForBulkDelete(
        ?string $search = null,
        ?string $type = null,
        ?string $userId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $sql = 'SELECT id, slug, file_path, type FROM items WHERE 1=1';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (slug LIKE :search OR original_name LIKE :search OR target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url', 'doc', 'gist', 'git'], true)) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        if ($userId === 'none' || $userId === 'anonymous') {
            $sql .= ' AND user_id IS NULL';
        } elseif ($userId !== null && trim($userId) !== '') {
            $sql .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $tsFrom = is_numeric($dateFrom) ? (int) $dateFrom : strtotime(trim($dateFrom).' 00:00:00');
            if ($tsFrom !== false) {
                $sql .= ' AND created_at >= :date_from';
                $params[':date_from'] = $tsFrom;
            }
        }

        if ($dateTo !== null && trim($dateTo) !== '') {
            $tsTo = is_numeric($dateTo) ? (int) $dateTo : strtotime(trim($dateTo).' 23:59:59');
            if ($tsTo !== false) {
                $sql .= ' AND created_at <= :date_to';
                $params[':date_to'] = $tsTo;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param  array<int|string>  $ids
     */
    public function deleteItemsByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM items WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($ids));

        return $stmt->rowCount();
    }

    public function updateType(string $slug, string $type): bool
    {
        $stmt = $this->pdo->prepare('UPDATE items SET type = :type WHERE slug = :slug');

        return $stmt->execute([':type' => $type, ':slug' => $slug]);
    }

    public function updateContent(string $slug, int $newSize): bool
    {
        $stmt = $this->pdo->prepare('UPDATE items SET size = :size WHERE slug = :slug');

        return $stmt->execute([':size' => $newSize, ':slug' => $slug]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function updateItemParameters(string $slug, array $params): bool
    {
        $allowed = ['expires_at', 'max_downloads', 'is_oneshot', 'original_name', 'target_url', 'hash', 'mime_type', 'data'];
        $sets = [];
        $binds = [':slug' => $slug];
        foreach ($params as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[] = "{$k} = :{$k}";
                $binds[":{$k}"] = $v;
            }
        }
        if (empty($sets)) {
            return false;
        }
        $sql = 'UPDATE items SET '.implode(', ', $sets).' WHERE slug = :slug';
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($binds);
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
     * @return array{total_items: int, total_files: int, total_urls: int, total_docs: int, total_gists: int, total_gits: int, total_bytes: int, total_views: int}
     */
    public function getStats(?string $userId = null): array
    {
        $sql = '
            SELECT
                COUNT(*) as total_items,
                SUM(CASE WHEN type = "file" THEN 1 ELSE 0 END) as total_files,
                SUM(CASE WHEN type = "url" THEN 1 ELSE 0 END) as total_urls,
                SUM(CASE WHEN type = "doc" THEN 1 ELSE 0 END) as total_docs,
                SUM(CASE WHEN type = "gist" THEN 1 ELSE 0 END) as total_gists,
                SUM(CASE WHEN type = "git" THEN 1 ELSE 0 END) as total_gits,
                SUM(size) as total_bytes,
                SUM(views) as total_views
            FROM items
        ';
        $params = [];
        if ($userId !== null && trim($userId) !== '') {
            $sql .= ' WHERE user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch();

        return [
            'total_items' => (int) ($res['total_items'] ?? 0),
            'total_files' => (int) ($res['total_files'] ?? 0),
            'total_urls' => (int) ($res['total_urls'] ?? 0),
            'total_docs' => (int) ($res['total_docs'] ?? 0),
            'total_gists' => (int) ($res['total_gists'] ?? 0),
            'total_gits' => (int) ($res['total_gits'] ?? 0),
            'total_bytes' => (int) ($res['total_bytes'] ?? 0),
            'total_views' => (int) ($res['total_views'] ?? 0),
        ];
    }

    /**
     * @param  array{id: string, username: string, token: string, role?: string, created_at?: int}  $data
     */
    public function createUser(array $data): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO users (id, username, token, role, created_at)
            VALUES (:id, :username, :token, :role, :created_at)
        ');

        return $stmt->execute([
            ':id' => $data['id'],
            ':username' => trim($data['username']),
            ':token' => trim($data['token']),
            ':role' => $data['role'] ?? 'member',
            ':created_at' => $data['created_at'] ?? time(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => trim($token)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username COLLATE NOCASE LIMIT 1');
        $stmt->execute([':username' => trim($username)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listUsers(): array
    {
        $stmt = $this->pdo->query('
            SELECT users.id, users.username, users.token, users.role, users.created_at,
                   COUNT(items.id) as items_count
            FROM users
            LEFT JOIN items ON users.id = items.user_id
            GROUP BY users.id
            ORDER BY users.created_at ASC
        ');

        return $stmt->fetchAll();
    }

    public function deleteUser(string $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE items SET user_id = NULL WHERE user_id = :id');
        $stmt->execute([':id' => $id]);

        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');

        return $stmt->execute([':id' => $id]);
    }

    public function updateUserToken(string $id, string $newToken): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET token = :token WHERE id = :id');

        return $stmt->execute([
            ':token' => trim($newToken),
            ':id' => $id,
        ]);
    }
}
}

/* --- Module: I18n.php --- */
namespace Fuxico {
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
            'brand_sub' => 'Envios efêmeros e links curtos',
            'brand_sub_line1' => 'Envios efêmeros',
            'brand_sub_line2' => 'e links curtos',
            'cultural_badge' => '🏳️‍⚧️ Fervo & tecnologia travesti latina',
            'drop_idle' => 'Larga a pedrada aqui.',
            'drop_sub' => 'ou clique para catar o babado nos arquivos',
            'drop_drag' => 'Pode largar que a gente espalha!',
            'btn_upload' => 'Solta o verbo ⚡',
            'btn_uploading' => 'Espalhando o bafão...',
            'tab_file' => 'Arquivo',
            'tab_url' => 'Encurtar URL',
            'tab_dash' => 'Fofocas no Ar',
            'opt_burn' => 'Queima de arquivo / Desaquenda (some no 1º download)',
            'opt_secret' => 'Aquendar fofoca (slug cifrado de 16 caracteres)',
            'opt_resenha' => '✂ Destilar artigo na borda (Resenha)',
            'opt_doc' => 'Renderizar Markdown como Página (Fuxico Doc)',
            'opt_expires' => 'Validade da fofoca:',
            'exp_none' => 'Até alguém descobrir (sem expiração)',
            'exp_1h' => '1 hora de fofoca quente',
            'exp_24h' => '24 horas',
            'exp_7d' => '7 dias',
            'exp_30d' => '30 dias',
            'custom_name_placeholder' => 'Apelido do babado (opcional)',
            'url_placeholder' => 'https://exemplo.com/babado-forte',
            'btn_shorten' => 'Encurtar fofoca ⚡',
            'token_label' => 'Senha da fofoca (Token de Autorização):',
            'token_placeholder' => 'token ou chave mestra...',
            'token_public' => 'Modo público ativo: qualquer um pode fuxicar sem token!',
            'stat_files' => 'Arquivos no Ar',
            'stat_urls' => 'Links Espalhados',
            'stat_bytes' => 'Volume da Fofoca',
            'stat_views' => 'Bisbilhotadas',
            'dash_search' => 'Buscar fofoca por slug ou nome...',
            'dash_filter_all' => 'Tudo que tá rolando',
            'dash_filter_files' => 'Só arquivos',
            'dash_filter_urls' => 'Só links encurtados',
            'dash_filter_docs' => 'Só documentos Markdown',
            'dash_filter_gists' => 'Só Gists',
            'dash_filter_gits' => 'Só Repositórios Git',
            'dash_filter_all_users' => 'Todas as fuxiqueiras',
            'dash_filter_anon_user' => 'Sem dona / Anônimo',
            'dash_date_from' => 'A partir de',
            'dash_date_to' => 'Até a data',
            'dash_clear_filters' => 'Limpar filtros',
            'dash_btn_bulk_delete' => 'Excluir Filtrados',
            'dash_bulk_confirm' => 'Tem certeza que deseja apagar definitivamente as {count} fofocas que atendem ao filtro aplicado? Esta ação é irreversível.',
            'dash_bulk_success' => '{count} fofocas excluídas com sucesso!',
            'opt_gist' => 'Empacotar como Gist (múltiplos arquivos)',
            'tip_gist' => 'Empacota múltiplos arquivos em um Gist com abas, preview de README e download em ZIP.',
            'tab_gist' => 'Gist',
            'opt_git' => 'Repositório Git efêmero',
            'tip_git' => 'Cria repositório Git com Git HTTP Smart Protocol, clone, push e baking estático.',
            'tab_git' => 'Git',
            'dash_empty' => 'Nenhum babado encontrado no cofre.',
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
            'painel_title' => 'PAINEL INTERNO // COFRE DE FOFOCAS',
            'painel_login_title' => 'Área Restrita // Identifique-se, comadre',
            'painel_login_desc' => 'Informe o token mestre de autorização para abrir o cofre de arquivos.',
            'painel_login_btn' => 'Destrancar Cofre ➔',
            'painel_logout_btn' => 'Sair do Cofre ✖',
            'painel_back_home' => '← Soltar Fofoca (Home)',
            'dl_title' => 'Fuxico | Baixar o babado',
            'dl_subtitle' => 'Alguém mandou esse babado pra você.',
            'dl_btn' => 'Baixar o babado 📦',
            'dl_warning_burn' => '⚠️ Aviso: assim que o download terminar, esse arquivo evapora do servidor.',
            'err_404_title' => 'Essa fofoca já prescreveu',
            'err_404_desc' => 'Essa fofoca já prescreveu (ou o link foi digitado errado).',
            'err_410_title' => 'Tarde demais. Esse segredo já foi recolhido.',
            'err_410_desc' => 'Esse pacote era queima-de-arquivo e já evaporou depois do primeiro acesso.',
            'back_home' => 'Voltar pro início e fuxicar outra coisa',
            'copy_success' => 'Babado copiado! Vai lá espalhar o bafão.',
            'delete_confirm' => 'Tem certeza que quer desaquendar e queimar esse babado pra sempre?',
            'footer_credit_prefix' => 'Orgulhosamente produzido por uma',
            'footer_travesti_text' => 'travesti latina',
            'footer_travesti_link' => 'https://pt.wikipedia.org/wiki/Travesti_(identidade_de_g%C3%AAnero)',
            'tip_burn' => '🏳️‍⚧️ No Pajubá: Desaquenda é soltar, revelar. ⚡ Técnico: O arquivo evapora permanentemente do disco imediatamente após o primeiro download.',
            'tip_secret' => '🏳️‍⚧️ No Pajubá: Aquendar é guardar, esconder e proteger a sete chaves. ⚡ Técnico: Gera uma URL com slug cifrado de 16 caracteres de alta entropia para impedir que curiosos adivinhem ou escaneiem o link.',
            'tip_resenha' => 'Destila o conteúdo editorial do link via motor Resenha, removendo ruídos e anúncios, empacotando imagens e gerando um Fuxico Doc limpo e completo.',
            'tip_doc' => '⚡ Técnico: Compila Markdown para HTML estático standalone com skins Trava Zine e Acadêmico Depressivo (?acad=true).',
            'tip_expires' => 'Tempo de retenção do babado antes de prescrever e ser purgado automaticamente do cofre.',
            'tip_custom_name' => 'Defina um apelido personalizado para a URL (ex: fuxico.cc/meu-babado) em vez de um código gerado aleatoriamente.',
            'tip_token' => 'Chave de autorização para fiscalizar o cofre no painel e subir arquivos em instâncias privadas.',
            'tip_shorten_url' => 'Cole qualquer link longo da web para encurtar e espalhar.',
            'tab_fuxiqueiras' => 'Fuxiqueiras & Fichas',
            'tab_fofocas' => 'Fofocas & Arquivos',
            'role_admin' => 'Fuxiqueira-mor (Admin)',
            'role_member' => 'Fuxiqueira (Membra)',
            'col_author' => 'Autora',
            'author_anon' => 'Anônima / Mestre',
            'btn_new_user' => '+ Nova Fuxiqueira',
            'users_title' => 'Gestão de Fuxiqueiras & Fichas de Acesso',
            'users_empty' => 'Nenhuma fuxiqueira cadastrada ainda.',
            'col_username' => 'Fuxiqueira',
            'col_token' => 'Ficha (Token)',
            'col_role' => 'Papel',
            'col_created' => 'Cadastrada em',
            'act_regen_token' => 'Regenerar Ficha',
            'regen_confirm' => 'Deseja regenerar a ficha de acesso desta fuxiqueira? O token anterior deixará de funcionar imediatamente.',
            'delete_user_confirm' => 'Tem certeza que deseja desaquendar e remover esta fuxiqueira do fuxico? As fofocas dela permanecerão no cofre como anônimas.',
            'modal_new_user_title' => 'Cadastrar Nova Fuxiqueira',
            'username_label' => 'Apelido da Fuxiqueira:',
            'username_placeholder' => 'ex: babado_forte',
            'token_label' => 'Ficha / Senha (opcional):',
            'token_placeholder' => 'Deixe em branco para gerar aleatória ou digite sua chave',
            'role_label' => 'Papel no Fuxico:',
            'btn_create_user' => 'Emitir Ficha ⚡',
            'user_created_success' => 'Fuxiqueira criada com sucesso! Copie a ficha de acesso:',
            'regen_prompt' => 'Digite a nova ficha/senha para a fuxiqueira (ou deixe em branco para sortear uma aleatória):',
            'act_edit_params' => 'Ajustar',
            'modal_edit_params_title' => 'Ajustar Parâmetros do Fuxico',
            'label_orig_name' => 'Nome do Arquivo / Título:',
            'label_target_url' => 'URL de Destino:',
            'label_expires' => 'Prazo de Expiração:',
            'opt_exp_keep' => 'Manter atual',
            'opt_exp_never' => 'Sem expiração (Perpétuo)',
            'opt_exp_1h' => '+1 Hora',
            'opt_exp_24h' => '+24 Horas',
            'opt_exp_7d' => '+7 Dias',
            'opt_exp_30d' => '+30 Dias',
            'label_max_downloads' => 'Limite de Downloads (vazio para ilimitado):',
            'label_is_oneshot' => 'Modo Queima (apagar logo após o primeiro acesso)',
            'label_hash' => 'Checksum SHA-256:',
            'btn_recalc_hash' => 'Recalcular Hash',
            'btn_save_params' => 'Salvar Parâmetros ⚡',
            'params_saved_success' => 'Parâmetros atualizados com sucesso!',
            'label_item_type' => 'Tipo de Exibição:',
            'opt_type_keep' => 'Manter atual',
            'opt_type_file' => 'Arquivo normal (Download direto)',
            'opt_type_doc' => 'Documento renderizado (Markdown com Joaju)',
            'label_rebake_diagrams' => 'Recompilar HTML e diagramas Joaju',
            'advanced_options_title' => 'Opções Avançadas',
            'label_default_theme' => 'Tema Padrão:',
            'opt_theme_keep' => 'Manter atual',
        ],
        'en' => [
            'app_name' => 'FUXICO',
            'tagline' => 'Word-of-mouth street mischief',
            'brand_sub' => 'Ephemeral send & short links',
            'brand_sub_line1' => 'Ephemeral send',
            'brand_sub_line2' => '& short links',
            'cultural_badge' => '🏳️‍⚧️ Travesti latina technology & riot',
            'drop_idle' => 'Spill the beans here.',
            'drop_sub' => 'or click to browse your scoops',
            'drop_drag' => "Let it go — we'll spread the word!",
            'btn_upload' => 'Spill the beans ⚡',
            'btn_uploading' => 'Spreading the scoop...',
            'tab_file' => 'File',
            'tab_url' => 'Shorten URL',
            'tab_dash' => 'Active Scoops',
            'opt_burn' => 'Burn after reading / Desaquenda (destructs on 1st download)',
            'opt_secret' => 'Aquendar / Cipher (16-char secret slug)',
            'opt_resenha' => '✂ Distill article at the edge (Resenha)',
            'opt_doc' => 'Bake Markdown as Web Page (Fuxico Doc)',
            'opt_expires' => 'Gossip retention:',
            'exp_none' => 'Until discovered (permanent)',
            'exp_1h' => '1 hour of hot tea',
            'exp_24h' => '24 hours',
            'exp_7d' => '7 days',
            'exp_30d' => '30 days',
            'custom_name_placeholder' => 'Custom scoop alias (optional)',
            'url_placeholder' => 'https://example.com/spicy-leak',
            'btn_shorten' => 'Shorten scoop ⚡',
            'token_label' => 'Gossip Passphrase (Auth Token):',
            'token_placeholder' => 'token or master key...',
            'token_public' => 'Public mode active: anyone can spill the beans!',
            'stat_files' => 'Live Files',
            'stat_urls' => 'Spread Links',
            'stat_bytes' => 'Gossip Weight',
            'stat_views' => 'Total Snoops',
            'dash_search' => 'Search scoop by slug or original name...',
            'dash_filter_all' => 'Everything circulating',
            'dash_filter_files' => 'Files only',
            'dash_filter_urls' => 'URLs only',
            'dash_filter_docs' => 'Markdown docs only',
            'dash_filter_gists' => 'Gists only',
            'dash_filter_gits' => 'Git repos only',
            'dash_filter_all_users' => 'All fuxiqueiras',
            'dash_filter_anon_user' => 'No owner / Anonymous',
            'dash_date_from' => 'From date',
            'dash_date_to' => 'To date',
            'dash_clear_filters' => 'Clear filters',
            'dash_btn_bulk_delete' => 'Delete Filtered',
            'dash_bulk_confirm' => 'Are you sure you want to permanently delete all {count} items matching current filters? This action cannot be undone.',
            'dash_bulk_success' => '{count} items deleted successfully!',
            'opt_gist' => 'Package as Gist (multi-file)',
            'tip_gist' => 'Packages multiple files into a tabbed Gist with README preview and ZIP download.',
            'tab_gist' => 'Gist',
            'opt_git' => 'Ephemeral Git Repository',
            'tip_git' => 'Creates a Git repository with Git HTTP Smart Protocol, clone, push, and static baking.',
            'tab_git' => 'Git',
            'dash_empty' => 'No scoops found in the vault.',
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
            'painel_title' => 'INTERNAL VAULT // SNOOP DASHBOARD',
            'painel_login_title' => 'Restricted Area // Identify yourself, comrade',
            'painel_login_desc' => 'Provide the master authorization token to unlock the file vault.',
            'painel_login_btn' => 'Unlock Vault ➔',
            'painel_logout_btn' => 'Exit Vault ✖',
            'painel_back_home' => '← Spill Scoop (Home)',
            'dl_title' => 'Fuxico | Download the scoop',
            'dl_subtitle' => 'Someone passed this scoop along to you.',
            'dl_btn' => 'Download the scoop 📦',
            'dl_warning_burn' => '⚠️ Warning: once this download finishes, this file evaporates from the server.',
            'err_404_title' => 'This gossip already expired',
            'err_404_desc' => 'This gossip already expired (or the link was mistyped).',
            'err_410_title' => 'Too late. Somebody already snooped through this package.',
            'err_410_desc' => 'This was a burn-after-reading package and has already evaporated from the server.',
            'back_home' => 'Back to home to spill something else',
            'copy_success' => 'Link copied! Go out there and spread the scoop.',
            'delete_confirm' => 'Are you sure you want to burn this scoop forever?',
            'footer_credit_prefix' => 'Proudly produced by a',
            'footer_travesti_text' => 'travesti latina',
            'footer_travesti_link' => 'https://en.wikipedia.org/wiki/Travesti_(gender_identity)',
            'tip_burn' => '🏳️‍⚧️ In Pajubá: Desaquenda means to release or reveal. ⚡ Technical: The file evaporates permanently from the disk immediately after the first download.',
            'tip_secret' => '🏳️‍⚧️ In Pajubá: Aquendar means to hide, conceal, or closely guard. ⚡ Technical: Generates a 16-character high-entropy secret URL slug, preventing brute-force enumeration attacks.',
            'tip_resenha' => 'Distills the editorial content of the link via the Resenha engine, stripping noise and ads, bundling images, and publishing as a sovereign Fuxico Doc.',
            'tip_doc' => '⚡ Technical: Bakes Markdown into static standalone HTML with Trava Zine and Depressive Academic skins (?acad=true).',
            'tip_expires' => 'Retention lifespan before the scoop expires and is automatically purged from the vault.',
            'tip_custom_name' => 'Set a custom URL alias (e.g. fuxico.cc/my-scoop) instead of an auto-generated random slug.',
            'tip_token' => 'Authorization token required to inspect the vault and upload items on private instances.',
            'tip_shorten_url' => 'Paste any long web address to shorten and spread.',
            'tab_fuxiqueiras' => 'Snoopers & Tokens',
            'tab_fofocas' => 'Scoops & Files',
            'role_admin' => 'Chief Snooper (Admin)',
            'role_member' => 'Snooper (Member)',
            'col_author' => 'Author',
            'author_anon' => 'Anonymous / Master',
            'btn_new_user' => '+ New Snooper',
            'users_title' => 'Manage Snoopers & Access Tokens',
            'users_empty' => 'No snoopers registered yet.',
            'col_username' => 'Snooper',
            'col_token' => 'Token',
            'col_role' => 'Role',
            'col_created' => 'Registered At',
            'act_regen_token' => 'Regen Token',
            'regen_confirm' => 'Regenerate this snooper token? The previous token will immediately stop working.',
            'delete_user_confirm' => 'Are you sure you want to remove this snooper? Their scoops will remain in the vault as anonymous.',
            'modal_new_user_title' => 'Register New Snooper',
            'username_label' => 'Snooper Nickname:',
            'username_placeholder' => 'e.g. spicy_snooper',
            'token_label' => 'Token / Password (optional):',
            'token_placeholder' => 'Leave blank to generate randomly or type custom key',
            'role_label' => 'Role in Fuxico:',
            'btn_create_user' => 'Issue Token ⚡',
            'user_created_success' => 'Snooper registered successfully! Copy access token:',
            'regen_prompt' => 'Enter new token/password for this snooper (or leave blank to generate randomly):',
            'act_edit_params' => 'Edit',
            'modal_edit_params_title' => 'Edit Fuxico Parameters',
            'label_orig_name' => 'File Name / Title:',
            'label_target_url' => 'Target URL:',
            'label_expires' => 'Expiration Deadline:',
            'opt_exp_keep' => 'Keep current',
            'opt_exp_never' => 'No expiration (Permanent)',
            'opt_exp_1h' => '+1 Hour',
            'opt_exp_24h' => '+24 Hours',
            'opt_exp_7d' => '+7 Days',
            'opt_exp_30d' => '+30 Days',
            'label_max_downloads' => 'Max Downloads (empty for unlimited):',
            'label_is_oneshot' => 'Burn on read (delete immediately after 1st access)',
            'label_hash' => 'SHA-256 Checksum:',
            'btn_recalc_hash' => 'Recalculate Hash',
            'btn_save_params' => 'Save Parameters ⚡',
            'params_saved_success' => 'Parameters updated successfully!',
            'label_item_type' => 'Display Format:',
            'opt_type_keep' => 'Keep current',
            'opt_type_file' => 'Raw file (Direct download)',
            'opt_type_doc' => 'Rendered document (Markdown with Joaju)',
            'label_rebake_diagrams' => 'Re-bake HTML & Joaju diagrams',
            'advanced_options_title' => 'Advanced Options',
            'label_default_theme' => 'Default Theme:',
            'opt_theme_keep' => 'Keep current',
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

        $envLang = getenv('FUXICO_LANG');
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

/* --- Module: MarkdownRenderer.php --- */
namespace Fuxico {
class MarkdownRenderer
{
    /**
     * Renders a Markdown document into a standalone, dual-skin HTML page.
     *
     * @param array{
     *   title?: string,
     *   slug?: string,
     *   original_name?: string,
     *   default_theme?: string,
     *   created_at?: int,
     *   base_url?: string
     * } $options
     */
    public static function render(string $markdown, array $options = []): string
    {
        $parsed = self::parse($markdown, $options);
        $bodyHtml = $parsed['html'];
        $meta = $parsed['meta'];

        $title = htmlspecialchars(
            (string) ($meta['title'] ?? $options['title'] ?? $options['original_name'] ?? 'Fuxico Doc'),
            ENT_QUOTES,
            'UTF-8'
        );
        $slug = htmlspecialchars((string) ($options['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rawFilename = htmlspecialchars((string) ($options['original_name'] ?? ($slug !== '' ? "{$slug}.md" : 'document.md')), ENT_QUOTES, 'UTF-8');
        $defaultTheme = ($options['default_theme'] ?? ($meta['theme'] ?? 'zine')) === 'acad' ? 'acad' : 'zine';
        $dateFormatted = date('Y-m-d H:i:s', $options['created_at'] ?? time());
        $baseTag = $slug !== '' ? "<base href=\"/{$slug}/\">\n    " : '';
        $pdfButton = $slug !== ''
            ? "<a href=\"/{$slug}/pdf\" class=\"btn-tool\" target=\"_blank\" title=\"Baixar PDF compilado com Joaju e Typst\">📄 PDF</a>"
            : '<button type="button" class="btn-tool" onclick="window.print()" title="Imprimir ou salvar em PDF">📄 PDF</button>';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR" data-theme="{$defaultTheme}">
<head>
    <meta charset="UTF-8">
    {$baseTag}<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Grotesk:wght@400;600;700&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Noto+Serif:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.21/dist/katex.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.21/dist/katex.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.21/dist/contrib/auto-render.min.js" onload="renderMathInElement(document.body, {delimiters: [{left: '$$', right: '$$', display: true}, {left: '$', right: '$', display: false}, {left: '\\\\(', right: '\\\\)', display: false}, {left: '\\\\[', right: '\\\\]', display: true}], throwOnError: false});"></script>
    <script>
        (function() {
            try {
                const params = new URLSearchParams(window.location.search);
                const saved = localStorage.getItem('fuxico_theme');
                let theme = '{$defaultTheme}';
                if (params.get('acad') === 'true' || params.get('theme') === 'acad' || params.has('acad')) {
                    theme = 'acad';
                } else if (params.get('theme') === 'zine') {
                    theme = 'zine';
                } else if (saved === 'acad' || saved === 'zine') {
                    theme = saved;
                }
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>
    <style>
        /* Base Resets & Transitions */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ─── DUAL SKIN: 1. TRAVA ZINE (FUXICO STANDARD) ─── */
        [data-theme="zine"] {
            --bg-body: #121016;
            --bg-card: #1a1622;
            --bg-surface: #221c2e;
            --text-main: #f5f4ef;
            --text-muted: #9f97ad;
            --text-link: #e2f835;
            --acid-yellow: #e2f835;
            --acid-hover: #ccee22;
            --electric-pink: #ff2a85;
            --tape-black: #0a080d;
            --border-color: #383047;
            --border-hard: 2px solid #000;
            --shadow-hard: 4px 4px 0px #0a080d;
            --shadow-accent: 5px 5px 0px var(--electric-pink);
            --font-heading: 'Anton', -apple-system, sans-serif;
            --font-sub: 'Space Grotesk', -apple-system, sans-serif;
            --font-body: 'Space Grotesk', -apple-system, sans-serif;
            --font-mono: 'Space Mono', monospace;
            --code-bg: #09080d;
            --callout-bg: #1c1726;
            --mark-bg: rgba(226, 248, 53, 0.3);
            --mark-color: #e2f835;
        }

        /* ─── DUAL SKIN: 2. ACADÊMICO DEPRESSIVO MONOCROMÁTICO (?acad=true) ─── */
        [data-theme="acad"] {
            --bg-body: #fcfcfc;
            --bg-card: #ffffff;
            --bg-surface: #f4f4f5;
            --text-main: #18181b;
            --text-muted: #52525b;
            --text-link: #09090b;
            --acid-yellow: #09090b;
            --acid-hover: #27272a;
            --electric-pink: #27272a;
            --tape-black: #e4e4e7;
            --border-color: #d4d4d8;
            --border-hard: 1px solid #18181b;
            --shadow-hard: 2px 2px 0px #d4d4d8;
            --shadow-accent: none;
            --font-heading: 'Noto Serif', Georgia, serif;
            --font-sub: 'Noto Serif', Georgia, serif;
            --font-body: 'Noto Serif', Georgia, serif;
            --font-mono: 'Space Mono', Courier, monospace;
            --code-bg: #f4f4f5;
            --callout-bg: #fafafa;
            --mark-bg: #e4e4e7;
            --mark-color: #09090b;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-body);
            line-height: 1.7;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 16px 40px 16px;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        /* Zine background pattern */
        [data-theme="zine"] body {
            background-image: 
                radial-gradient(circle, rgba(226, 248, 53, 0.04) 1.5px, transparent 1.5px),
                radial-gradient(circle at 10% 20%, rgba(255, 42, 133, 0.06) 0%, transparent 40%);
            background-size: 20px 20px, 100% 100%;
        }

        /* Academic background pattern */
        [data-theme="acad"] body {
            background-image: linear-gradient(to right, #f4f4f5 1px, transparent 1px);
            background-size: 80px 100%;
        }

        /* Layout Container */
        .fuxico-container {
            width: 100%;
            max-width: 860px;
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-hard);
            padding: 36px 32px;
            position: relative;
        }

        [data-theme="zine"] .fuxico-container {
            box-shadow: 8px 12px 0px #0a080d, 8px 12px 0px 2px var(--electric-pink);
            border: 2px solid var(--border-color);
        }

        [data-theme="acad"] .fuxico-container {
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            max-width: 820px;
            padding: 56px 48px;
        }

        /* Trans Pride Stripes */
        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 12px;
            margin-bottom: 24px;
            border-bottom: 2px solid #000;
        }
        .trans-stripe.bottom {
            margin-top: 36px;
            margin-bottom: 0;
            border-top: 2px solid #000;
            border-bottom: none;
        }
        .trans-stripe-line { flex: 1; width: 100%; }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }

        /* In Academic mode, hide decorative stripes and tape */
        [data-theme="acad"] .trans-stripe,
        [data-theme="acad"] .duct-tape {
            display: none !important;
        }

        /* Duct tape badge */
        .duct-tape {
            position: absolute;
            top: -3px;
            left: 50%;
            transform: translateX(-50%) rotate(-1.5deg);
            background: rgba(226, 248, 53, 0.96);
            color: #000;
            font-family: var(--font-heading);
            font-size: 0.72rem;
            letter-spacing: 2px;
            padding: 4px 28px;
            box-shadow: 3px 3px 0px rgba(0,0,0,0.6);
            z-index: 10;
            user-select: none;
            text-transform: uppercase;
        }

        /* Document Top Toolbar */
        .doc-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 16px;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 12px;
        }

        [data-theme="acad"] .doc-toolbar {
            border-bottom: 1px solid #18181b;
            font-size: 0.85rem;
            padding-bottom: 12px;
            margin-bottom: 36px;
        }

        .doc-breadcrumbs {
            font-family: var(--font-mono);
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .doc-breadcrumbs a {
            color: var(--text-link);
            text-decoration: none;
            font-weight: 700;
        }

        .doc-breadcrumbs a:hover { text-decoration: underline; }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-tool {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 5px 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 2px 2px 0px rgba(0,0,0,0.4);
            user-select: none;
        }

        [data-theme="acad"] .btn-tool {
            background: #ffffff;
            border: 1px solid #71717a;
            color: #18181b;
            box-shadow: none;
            border-radius: 2px;
        }

        .btn-tool:hover {
            border-color: var(--acid-yellow);
            color: var(--acid-yellow);
        }

        [data-theme="acad"] .btn-tool:hover {
            background: #f4f4f5;
            color: #000;
            border-color: #000;
        }

        /* ─── TYPOGRAPHY & ARTICLE CONTENT ─── */
        .fuxico-article {
            font-size: 1.05rem;
        }

        [data-theme="acad"] .fuxico-article {
            font-size: 1.08rem;
            text-align: justify;
        }

        /* Headings */
        .fuxico-article h1,
        .fuxico-article h2,
        .fuxico-article h3,
        .fuxico-article h4,
        .fuxico-article h5,
        .fuxico-article h6 {
            color: var(--text-main);
            margin-top: 1.6em;
            margin-bottom: 0.6em;
            line-height: 1.25;
            scroll-margin-top: 20px;
        }

        [data-theme="zine"] .fuxico-article h1 {
            font-family: var(--font-heading);
            font-size: 2.4rem;
            color: var(--acid-yellow);
            letter-spacing: 1px;
            text-shadow: 2px 2px 0px #000;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 8px;
        }
        [data-theme="zine"] .fuxico-article h2 {
            font-family: var(--font-sub);
            font-size: 1.6rem;
            color: var(--electric-pink);
            letter-spacing: 0.5px;
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 6px;
        }
        [data-theme="zine"] .fuxico-article h3 {
            font-family: var(--font-sub);
            font-size: 1.3rem;
            color: var(--text-main);
        }

        [data-theme="acad"] .fuxico-article h1 {
            font-size: 1.9rem;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
            margin-bottom: 1.2em;
            border-bottom: 1px solid #18181b;
            padding-bottom: 12px;
        }
        [data-theme="acad"] .fuxico-article h2 {
            font-size: 1.4rem;
            font-weight: 700;
            margin-top: 1.8em;
            border-bottom: 1px solid #e4e4e7;
            padding-bottom: 4px;
        }
        [data-theme="acad"] .fuxico-article h3 {
            font-size: 1.18rem;
            font-style: italic;
            font-weight: 600;
        }

        /* Paragraphs & Text */
        .fuxico-article p {
            margin-bottom: 1.2em;
        }

        .fuxico-article a {
            color: var(--text-link);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        [data-theme="zine"] .fuxico-article a:hover {
            background: var(--acid-yellow);
            color: #000;
            text-decoration: none;
        }

        .fuxico-article strong { font-weight: 700; }
        .fuxico-article em { font-style: italic; }
        .fuxico-article s, .fuxico-article del { text-decoration: line-through; opacity: 0.75; }

        /* Highlights */
        .fuxico-article mark {
            background-color: var(--mark-bg);
            color: var(--mark-color);
            padding: 2px 6px;
            border-radius: 2px;
            font-weight: 600;
        }

        /* Blockquotes */
        .fuxico-article blockquote {
            border-left: 4px solid var(--electric-pink);
            background: var(--callout-bg);
            padding: 12px 18px;
            margin: 1.4em 0;
            font-style: italic;
            color: var(--text-muted);
        }

        [data-theme="acad"] .fuxico-article blockquote {
            border-left: 3px solid #71717a;
            background: #fafafa;
            color: #3f3f46;
            margin-left: 20px;
            margin-right: 20px;
        }

        /* Obsidian Callouts */
        .callout {
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--callout-color, var(--acid-yellow));
            background: color-mix(in srgb, var(--callout-color, #e2f835) 9%, var(--callout-bg));
            padding: 14px 18px;
            margin: 1.5em 0;
            border-radius: 4px;
        }

        .callout-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-family: var(--font-sub);
            color: var(--callout-color, var(--acid-yellow));
            margin-bottom: 8px;
            font-size: 0.95rem;
            letter-spacing: 0.2px;
        }

        .callout-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--callout-color, inherit);
        }

        .callout-icon svg {
            display: block;
            width: 18px;
            height: 18px;
            stroke-width: 2.2;
        }

        .callout-fold {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            color: var(--callout-color, inherit);
            opacity: 0.7;
            transition: transform 0.2s ease;
            transform: rotate(-90deg);
        }

        details.callout[open] .callout-fold {
            transform: rotate(0deg);
        }

        [data-theme="acad"] .callout {
            border: 1px solid #e4e4e7;
            border-left: 4px solid var(--callout-color, #18181b);
            background: color-mix(in srgb, var(--callout-color, #18181b) 7%, #ffffff);
            box-shadow: none;
        }

        [data-theme="acad"] .callout-title {
            color: var(--callout-color, #18181b);
            font-family: var(--font-heading);
            font-size: 0.92rem;
        }

        /* Callout Types & Colors */
        .callout-note { --callout-color: #0284c7; }
        .callout-abstract, .callout-summary, .callout-tldr { --callout-color: #0d9488; }
        .callout-info { --callout-color: #0284c7; }
        .callout-todo { --callout-color: #7c3aed; }
        .callout-tip, .callout-hint { --callout-color: #10b981; }
        .callout-success, .callout-check, .callout-done { --callout-color: #16a34a; }
        .callout-question, .callout-help, .callout-faq { --callout-color: #d97706; }
        .callout-warning, .callout-caution, .callout-attention { --callout-color: #ea580c; }
        .callout-failure, .callout-fail, .callout-missing { --callout-color: #dc2626; }
        .callout-danger, .callout-error { --callout-color: #e11d48; }
        .callout-bug { --callout-color: #f43f5e; }
        .callout-example { --callout-color: #9333ea; }
        .callout-quote, .callout-cite { --callout-color: #64748b; }
        .callout-important { --callout-color: #ec4899; }

        /* Dark mode palette adjustments */
        [data-theme="zine"] .callout-note { --callout-color: #38bdf8; }
        [data-theme="zine"] .callout-abstract, [data-theme="zine"] .callout-summary, [data-theme="zine"] .callout-tldr { --callout-color: #2dd4bf; }
        [data-theme="zine"] .callout-info { --callout-color: #38bdf8; }
        [data-theme="zine"] .callout-todo { --callout-color: #a855f7; }
        [data-theme="zine"] .callout-tip, [data-theme="zine"] .callout-hint { --callout-color: #34d399; }
        [data-theme="zine"] .callout-success, [data-theme="zine"] .callout-check, [data-theme="zine"] .callout-done { --callout-color: #22c55e; }
        [data-theme="zine"] .callout-question, [data-theme="zine"] .callout-help, [data-theme="zine"] .callout-faq { --callout-color: #fbbf24; }
        [data-theme="zine"] .callout-warning, [data-theme="zine"] .callout-caution, [data-theme="zine"] .callout-attention { --callout-color: #fb923c; }
        [data-theme="zine"] .callout-failure, [data-theme="zine"] .callout-fail, [data-theme="zine"] .callout-missing { --callout-color: #f87171; }
        [data-theme="zine"] .callout-danger, [data-theme="zine"] .callout-error { --callout-color: #fb7185; }
        [data-theme="zine"] .callout-bug { --callout-color: #f43f5e; }
        [data-theme="zine"] .callout-example { --callout-color: #c084fc; }
        [data-theme="zine"] .callout-quote, [data-theme="zine"] .callout-cite { --callout-color: #94a3b8; }
        [data-theme="zine"] .callout-important { --callout-color: #f472b6; }

        .callout-content {
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .callout-content p {
            margin: 0.6em 0;
        }
        .callout-content p:first-child {
            margin-top: 0;
        }
        .callout-content p:last-child {
            margin-bottom: 0;
        }
        .callout-content ul, .callout-content ol {
            margin: 0.5em 0 0.5em 1.5em;
        }

        details.callout summary.callout-title {
            cursor: pointer;
            user-select: none;
            margin-bottom: 0;
        }
        details.callout[open] summary.callout-title {
            margin-bottom: 8px;
        }
        details.callout summary.callout-title::-webkit-details-marker {
            display: none;
        }

        /* Footnotes */
        .footnotes {
            margin-top: 3em;
            padding-top: 1.5em;
            border-top: 1px solid var(--border-color);
            font-size: 0.88rem;
            color: var(--text-muted);
        }
        .footnotes ol {
            padding-left: 1.5em;
        }
        .footnotes li {
            margin-bottom: 0.6em;
        }
        .footnote-ref a, .footnote-backref {
            color: var(--electric-pink);
            text-decoration: none;
            font-weight: 600;
        }
        .footnote-ref a:hover, .footnote-backref:hover {
            text-decoration: underline;
        }

        /* LaTeX & Math Rendering */
        .math-block {
            margin: 1.5em 0;
            overflow-x: auto;
            text-align: center;
        }
        .katex-display {
            overflow-x: auto;
            overflow-y: hidden;
            padding: 8px 0;
        }

        /* Diagram Figures (Joaju Native Baking) */
        .diagram-figure {
            margin: 1.8em 0;
            border: 2px solid var(--border-color);
            background: var(--code-bg);
            box-shadow: var(--shadow-hard);
            overflow: hidden;
            display: block;
        }

        [data-theme="acad"] .diagram-figure {
            border: 1px solid #d4d4d8;
            box-shadow: none;
            background: #ffffff;
        }

        .diagram-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--tape-black);
            padding: 8px 14px;
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color);
        }

        [data-theme="acad"] .diagram-header {
            background: #f4f4f5;
            border-bottom: 1px solid #d4d4d8;
            color: #52525b;
        }

        .diagram-badge {
            font-weight: 700;
            color: var(--acid-yellow);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        [data-theme="acad"] .diagram-badge {
            color: #18181b;
        }

        .diagram-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-diagram-action {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 0.7rem;
            padding: 2px 8px;
            cursor: pointer;
            text-transform: uppercase;
        }

        .btn-diagram-action:hover {
            color: var(--text-main);
            border-color: var(--acid-yellow);
        }

        [data-theme="acad"] .btn-diagram-action:hover {
            color: #000;
            border-color: #000;
        }

        .diagram-viewport {
            width: 100%;
            overflow-x: auto;
            padding: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #ffffff;
        }

        [data-theme="acad"] .diagram-viewport {
            background: #ffffff;
            padding: 24px;
        }

        .diagram-viewport svg {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        .diagram-table .diagram-viewport {
            display: block;
            padding: 16px 20px;
        }

        .diagram-table .joaju-academic-table-wrapper {
            margin: 0 auto;
            width: 100%;
            overflow-x: auto;
        }

        .joaju-academic-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            line-height: 1.4;
            margin: 8px 0;
            border-top: 2px solid var(--text-main, #000) !important;
            border-bottom: 2px solid var(--text-main, #000) !important;
            border-left: none !important;
            border-right: none !important;
        }

        .fuxico-article .joaju-academic-table th,
        .fuxico-article .joaju-academic-table td,
        [data-theme="acad"] .fuxico-article .joaju-academic-table th,
        [data-theme="acad"] .fuxico-article .joaju-academic-table td {
            border-left: none !important;
            border-right: none !important;
            background: transparent !important;
            box-shadow: none !important;
        }

        .fuxico-article .joaju-academic-table thead th,
        [data-theme="acad"] .fuxico-article .joaju-academic-table thead th {
            border-top: none !important;
            border-bottom: 1px solid var(--text-main, #000) !important;
            padding: 6px 8px;
            font-weight: 700;
            vertical-align: bottom;
            white-space: normal;
        }

        .fuxico-article .joaju-academic-table tbody td,
        [data-theme="acad"] .fuxico-article .joaju-academic-table tbody td {
            border-top: none !important;
            border-bottom: none !important;
            padding: 5px 8px;
            vertical-align: middle;
            white-space: normal;
        }

        .joaju-academic-table tbody tr:hover {
            background: rgba(0, 0, 0, 0.03) !important;
        }

        .joaju-table-title {
            font-weight: 700;
            font-size: 0.92rem;
            margin-bottom: 6px;
            text-align: left;
            color: var(--text-main, #000);
        }

        .joaju-table-note {
            font-size: 0.78rem;
            font-style: italic;
            margin-top: 6px;
            text-align: left;
            color: var(--text-muted, #555);
            line-height: 1.35;
        }

        [data-theme="acad"] .joaju-academic-table {
            border-top: 2px solid #000 !important;
            border-bottom: 2px solid #000 !important;
            font-family: 'Times New Roman', Times, Georgia, serif;
        }

        [data-theme="acad"] .joaju-academic-table thead th {
            border-bottom: 1px solid #000 !important;
            font-family: 'Times New Roman', Times, Georgia, serif;
        }

        [data-theme="acad"] .joaju-academic-table tbody td {
            font-family: 'Times New Roman', Times, Georgia, serif;
        }

        [data-theme="acad"] .joaju-table-title {
            font-family: 'Times New Roman', Times, Georgia, serif;
            font-weight: bold;
        }

        [data-theme="acad"] .joaju-table-note {
            font-family: 'Times New Roman', Times, Georgia, serif;
        }

        .diagram-source {
            border-top: 1px dashed var(--border-color);
            background: var(--code-bg);
        }

        /* Code Blocks */
        .code-block-wrapper {
            margin: 1.5em 0;
            border: 1px solid var(--border-color);
            background: var(--code-bg);
            box-shadow: var(--shadow-hard);
            overflow: hidden;
        }

        [data-theme="acad"] .code-block-wrapper {
            border: 1px solid #d4d4d8;
            box-shadow: none;
            background: #fafafa;
        }

        [data-theme="acad"] .code-block-header {
            background: #f4f4f5;
            color: #71717a;
            border-bottom: 1px solid #e4e4e7;
        }

        .code-block-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--tape-black);
            padding: 6px 14px;
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }

        .btn-copy-code {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 0.7rem;
            padding: 2px 8px;
            cursor: pointer;
        }

        .btn-copy-code:hover {
            color: var(--text-main);
            border-color: var(--acid-yellow);
        }

        .fuxico-article pre {
            padding: 14px 16px;
            overflow-x: auto;
            font-family: var(--font-mono);
            font-size: 0.9rem;
            line-height: 1.5;
            color: var(--text-main);
            background: transparent;
        }

        .fuxico-article code {
            font-family: var(--font-mono);
            font-size: 0.9em;
            background: var(--code-bg);
            padding: 2px 6px;
            border: 1px solid var(--border-color);
            color: var(--acid-yellow);
        }

        [data-theme="acad"] .fuxico-article code {
            color: #18181b;
            background: #f4f4f5;
            border: 1px solid #e4e4e7;
        }

        .fuxico-article pre code,
        [data-theme="acad"] .fuxico-article pre code,
        [data-theme="zine"] .fuxico-article pre code {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            color: inherit !important;
            box-shadow: none !important;
            display: block;
        }

        /* Lists */
        .fuxico-article ul, .fuxico-article ol {
            margin: 1.2em 0 1.2em 2em;
        }

        .fuxico-article li {
            margin-bottom: 0.4em;
        }

        .task-list-item {
            list-style-type: none;
            margin-left: -1.2em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .task-list-item input[type="checkbox"] {
            appearance: none;
            width: 16px;
            height: 16px;
            border: 1px solid var(--border-color);
            background: var(--bg-surface);
            cursor: default;
            position: relative;
        }

        .task-list-item input[type="checkbox"]:checked {
            background: var(--acid-yellow);
            border-color: var(--acid-yellow);
        }

        .task-list-item input[type="checkbox"]:checked::after {
            content: '✔';
            position: absolute;
            top: -3px;
            left: 2px;
            font-size: 11px;
            color: #000;
            font-weight: bold;
        }

        /* Tables */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            margin: 1.6em 0;
        }

        .fuxico-article table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
            margin: 0 auto;
        }

        .fuxico-article th {
            background: var(--tape-black);
            color: var(--text-main);
            font-family: var(--font-sub);
            font-weight: 700;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            text-align: left;
        }

        [data-theme="zine"] .fuxico-article th {
            color: var(--acid-yellow);
        }

        [data-theme="acad"] .fuxico-article th {
            background: #f4f4f5;
            color: #18181b;
            border: 1px solid #d4d4d8;
            font-family: var(--font-heading);
            font-size: 0.88rem;
        }

        .fuxico-article td {
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
        }

        [data-theme="acad"] .fuxico-article td {
            border: 1px solid #e4e4e7;
        }

        /* Images & Figures */
        .fuxico-article img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 1.8em auto;
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-hard);
        }

        [data-theme="acad"] .fuxico-article img {
            border: 1px solid #d4d4d8;
            box-shadow: none;
        }

        /* Horizontal Rule */
        .fuxico-article hr {
            border: none;
            border-top: 2px dashed var(--border-color);
            margin: 2.2em 0;
        }

        [data-theme="acad"] .fuxico-article hr {
            border-top: 1px solid #d4d4d8;
        }

        /* Footer */
        .doc-footer {
            margin-top: 36px;
            text-align: center;
            font-family: var(--font-mono);
            font-size: 0.78rem;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        .doc-footer a {
            color: var(--acid-yellow);
            text-decoration: none;
        }

        [data-theme="acad"] .doc-footer a {
            color: #18181b;
            text-decoration: underline;
        }

        /* ─── RESPONSIVIDADE E DISPOSITIVOS MÓVEIS (TICKET #46) ─── */
        @media (max-width: 768px) {
            body {
                padding: 12px 8px 90px 8px;
            }

            .fuxico-container {
                padding: 20px 14px 24px 14px;
                border-width: 1px;
            }

            [data-theme="zine"] .fuxico-container {
                box-shadow: 4px 6px 0px #0a080d, 4px 6px 0px 1px var(--electric-pink);
            }

            [data-theme="acad"] .fuxico-container {
                padding: 24px 16px 28px 16px;
                border-width: 1px;
                box-shadow: none;
            }

            /* Toolbar fixa na parte inferior da tela (Mobile App Bar) */
            .doc-toolbar {
                margin-bottom: 20px;
                padding-bottom: 10px;
            }

            .toolbar-actions {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 1000;
                background: rgba(26, 22, 34, 0.94);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                border-top: 1px solid var(--border-color);
                padding: 10px 14px max(10px, env(safe-area-inset-bottom));
                display: flex;
                justify-content: space-around;
                align-items: center;
                gap: 8px;
                box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.4);
            }

            [data-theme="acad"] .toolbar-actions {
                background: rgba(255, 255, 255, 0.95);
                border-top: 1px solid #d4d4d8;
                box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.08);
            }

            .toolbar-actions .btn-tool {
                flex: 1;
                min-height: 44px;
                padding: 8px 10px;
                font-size: 0.8rem;
                justify-content: center;
                text-align: center;
            }

            /* Tipografia fluida */
            .fuxico-article h1,
            [data-theme="zine"] .fuxico-article h1,
            [data-theme="acad"] .fuxico-article h1 {
                font-size: 1.85rem;
                line-height: 1.15;
                word-break: break-word;
            }

            .fuxico-article h2,
            [data-theme="zine"] .fuxico-article h2,
            [data-theme="acad"] .fuxico-article h2 {
                font-size: 1.45rem;
                word-break: break-word;
            }

            .fuxico-article h3,
            [data-theme="zine"] .fuxico-article h3,
            [data-theme="acad"] .fuxico-article h3 {
                font-size: 1.2rem;
                word-break: break-word;
            }

            /* Tabelas e Wrappers com Rolagem Segura */
            .table-wrapper,
            .diagram-table .joaju-academic-table-wrapper,
            .joaju-academic-table-wrapper {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 1.2em 0;
            }

            .fuxico-article .joaju-academic-table th,
            .fuxico-article .joaju-academic-table td,
            [data-theme="acad"] .fuxico-article .joaju-academic-table th,
            [data-theme="acad"] .fuxico-article .joaju-academic-table td {
                padding: 6px 8px;
                font-size: 0.8rem;
            }

            .joaju-table-title {
                font-size: 0.88rem;
            }

            .joaju-table-note {
                font-size: 0.74rem;
            }

            /* Fórmulas matemáticas e código */
            .katex-display {
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                max-width: 100%;
                padding: 6px 0;
            }

            .code-block-wrapper pre,
            .diagram-source pre,
            .fuxico-article pre {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                max-width: 100%;
                font-size: 0.8rem;
            }

            /* Diagramas interativos em telas móveis */
            .diagram-viewport {
                padding: 12px;
                overflow: hidden;
                touch-action: pan-y pinch-zoom;
            }

            .diagram-header {
                flex-wrap: wrap;
                gap: 8px;
                padding: 8px 12px;
            }

            .btn-diagram-action,
            .btn-copy-code {
                min-height: 36px;
                padding: 6px 12px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 8px 4px 90px 4px;
            }

            .fuxico-container {
                padding: 16px 10px 24px 10px;
            }

            .toolbar-actions .btn-tool {
                font-size: 0.75rem;
                padding: 8px 6px;
            }

            .fuxico-article h1,
            [data-theme="zine"] .fuxico-article h1,
            [data-theme="acad"] .fuxico-article h1 {
                font-size: 1.6rem;
            }
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 20mm 15mm 20mm 15mm;
            }

            body {
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 11pt !important;
                line-height: 1.4 !important;
            }

            .doc-toolbar,
            .trans-stripe,
            .duct-tape,
            .doc-footer,
            .diagram-actions,
            .btn-diagram-action,
            .btn-copy-code,
            .diagram-source {
                display: none !important;
            }

            .fuxico-container {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
            }

            .fuxico-article {
                max-width: 100% !important;
                padding: 0 !important;
            }

            .diagram-figure {
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
                page-break-inside: avoid;
                break-inside: avoid;
                margin: 1.5em 0 !important;
            }

            .diagram-header {
                display: none !important;
            }

            .diagram-viewport {
                padding: 0 !important;
            }

            .joaju-academic-table {
                font-size: 8.5pt !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .joaju-academic-table thead {
                display: table-header-group;
            }

            .joaju-academic-table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .fuxico-article img {
                max-width: 100% !important;
                box-shadow: none !important;
                border: none !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            a {
                text-decoration: underline !important;
                color: #000000 !important;
            }
        }
    </style>
</head>
<body>
    <div class="fuxico-container">
        <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
        <div class="duct-tape">FUXICO // DOCUMENTO</div>

        <nav class="doc-toolbar">
            <div class="doc-breadcrumbs">
                <a href="/">FUXICO</a>
                <span>/</span>
                <span>{$rawFilename}</span>
            </div>
            <div class="toolbar-actions">
                <button type="button" class="btn-tool" id="btnThemeToggle" onclick="toggleTheme()" title="Alternar entre visual Zine e Acadêmico">
                    <span id="themeLabel">🏛️ Acadêmico</span>
                </button>
                <a href="?raw=1" class="btn-tool" title="Ver ou baixar markdown puro">
                    ⬇ Raw
                </a>
                {$pdfButton}
                <button type="button" class="btn-tool" onclick="copyDocUrl()" title="Copiar link do documento">
                    🔗 Copiar
                </button>
            </div>
        </nav>

        <article class="fuxico-article">
            {$bodyHtml}
        </article>

        <div class="trans-stripe bottom" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
    </div>

    <footer class="doc-footer">
        <p>Postado em {$dateFormatted} // <strong>FUXICO DOCS</strong> // <a href="/">Soltar Fofoca</a></p>
    </footer>

    <script>
        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme') || 'zine';
            const next = current === 'zine' ? 'acad' : 'zine';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('fuxico_theme', next);
            updateThemeLabel(next);
        }

        function updateThemeLabel(theme) {
            const label = document.getElementById('themeLabel');
            if (label) {
                label.innerText = theme === 'zine' ? '🏛️ Acadêmico' : '⚡ Zine';
            }
        }

        function copyDocUrl() {
            navigator.clipboard.writeText(window.location.href.split('?')[0])
                .then(() => alert('Link copiado para a área de transferência!'));
        }

        function toggleDiagramSource(btn) {
            const fig = btn.closest('.diagram-figure');
            if (!fig) return;
            const src = fig.querySelector('.diagram-source');
            const view = fig.querySelector('.diagram-viewport');
            if (src && view) {
                const isHidden = src.style.display === 'none' || getComputedStyle(src).display === 'none';
                src.style.display = isHidden ? 'block' : 'none';
                view.style.display = isHidden ? 'none' : 'flex';
                btn.innerText = isHidden ? 'Visual' : 'Código';
            }
        }

        function copyCode(btn) {
            const container = btn.closest('.code-block-wrapper') || btn.closest('.diagram-figure');
            const pre = container ? container.querySelector('pre') : null;
            if (pre) {
                navigator.clipboard.writeText(pre.innerText).then(() => {
                    const original = btn.innerText;
                    btn.innerText = 'Copiado!';
                    setTimeout(() => { btn.innerText = original; }, 2000);
                });
            }
        }

        // Gestos táteis nos diagramas (Pinch-to-zoom & Pan para mobile)
        function initDiagramTouchInteractions() {
            document.querySelectorAll('.diagram-viewport').forEach(viewport => {
                const svg = viewport.querySelector('svg');
                if (!svg) return;

                let scale = 1;
                let pointX = 0;
                let pointY = 0;
                let startX = 0;
                let startY = 0;
                let initialDistance = 0;
                let isDragging = false;
                let lastTap = 0;

                function setTransform() {
                    svg.style.transform = 'translate(' + pointX + 'px, ' + pointY + 'px) scale(' + scale + ')';
                }

                viewport.addEventListener('touchstart', e => {
                    if (e.touches.length === 2) {
                        e.preventDefault();
                        initialDistance = Math.hypot(
                            e.touches[0].clientX - e.touches[1].clientX,
                            e.touches[0].clientY - e.touches[1].clientY
                        );
                    } else if (e.touches.length === 1) {
                        const now = Date.now();
                        if (now - lastTap < 300) {
                            e.preventDefault();
                            if (scale > 1) {
                                scale = 1;
                                pointX = 0;
                                pointY = 0;
                            } else {
                                scale = 2;
                            }
                            setTransform();
                            lastTap = 0;
                            return;
                        }
                        lastTap = now;

                        if (scale > 1) {
                            isDragging = true;
                            startX = e.touches[0].clientX - pointX;
                            startY = e.touches[0].clientY - pointY;
                        }
                    }
                }, { passive: false });

                viewport.addEventListener('touchmove', e => {
                    if (e.touches.length === 2 && initialDistance > 0) {
                        e.preventDefault();
                        const currentDistance = Math.hypot(
                            e.touches[0].clientX - e.touches[1].clientX,
                            e.touches[0].clientY - e.touches[1].clientY
                        );
                        const diff = currentDistance / initialDistance;
                        scale = Math.min(Math.max(0.8, scale * (1 + (diff - 1) * 0.5)), 4);
                        initialDistance = currentDistance;
                        setTransform();
                    } else if (e.touches.length === 1 && isDragging) {
                        e.preventDefault();
                        pointX = e.touches[0].clientX - startX;
                        pointY = e.touches[0].clientY - startY;
                        setTransform();
                    }
                }, { passive: false });

                viewport.addEventListener('touchend', e => {
                    if (e.touches.length < 2) {
                        initialDistance = 0;
                    }
                    if (e.touches.length === 0) {
                        isDragging = false;
                        if (scale <= 1) {
                            pointX = 0;
                            pointY = 0;
                            setTransform();
                        }
                    }
                });
            });
        }

        // Initialize button label and touch gestures on load
        document.addEventListener('DOMContentLoaded', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'zine';
            updateThemeLabel(current);
            initDiagramTouchInteractions();
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * Parses raw Markdown text into HTML and extracts YAML frontmatter.
     *
     * @return array{html: string, meta: array<string, mixed>}
     */
    public static function parse(string $markdown, array $options = []): array
    {
        $meta = [];
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // 0. Strip Obsidian comments (%% ... %%) while protecting fenced code blocks
        $codeBlockPlaceholders = [];
        $markdown = preg_replace_callback('/```[\s\S]*?```/', function ($m) use (&$codeBlockPlaceholders) {
            $key = '___FUXICO_CB_'.count($codeBlockPlaceholders).'___';
            $codeBlockPlaceholders[$key] = $m[0];

            return $key;
        }, $markdown) ?? $markdown;

        $markdown = preg_replace('/%%.*?%%/s', '', $markdown) ?? $markdown;

        if (! empty($codeBlockPlaceholders)) {
            $markdown = strtr($markdown, $codeBlockPlaceholders);
        }

        $footnotes = [];

        // 1. Extract YAML Frontmatter
        if (str_starts_with($markdown, "---\n")) {
            $endPos = strpos($markdown, "\n---\n", 4);
            if ($endPos !== false) {
                $yamlContent = substr($markdown, 4, $endPos - 4);
                $markdown = substr($markdown, $endPos + 5);
                $meta = self::parseSimpleYaml($yamlContent);
            }
        }

        $lines = explode("\n", $markdown);
        $htmlOutput = [];
        $i = 0;
        $count = count($lines);

        $inList = false;
        $listType = 'ul';

        while ($i < $count) {
            $line = $lines[$i];

            // 2. Fenced Code Block & Joaju Diagram Rendering
            if (preg_match('/^```(\w*)/', $line, $matches)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $rawLang = strtolower(trim($matches[1] ?: 'text'));
                $lang = htmlspecialchars($rawLang, ENT_QUOTES, 'UTF-8');
                $rawCodeLines = [];
                $i++;
                while ($i < $count && ! str_starts_with($lines[$i], '```')) {
                    $rawCodeLines[] = $lines[$i];
                    $i++;
                }
                $rawCode = implode("\n", $rawCodeLines);
                $escapedCode = htmlspecialchars($rawCode, ENT_QUOTES, 'UTF-8');

                $diagramEngines = [
                    'pedtools' => ['engine' => 'pedtools', 'label' => 'HEREDOGRAMA (PEDTOOLS)'],
                    'kinship2' => ['engine' => 'kinship2', 'label' => 'HEREDOGRAMA (KINSHIP2)'],
                    'openchemlib' => ['engine' => 'openchemlib', 'label' => 'QUÍMICA (OPENCHEMLIB)'],
                    'openbabel' => ['engine' => 'openbabel', 'label' => 'QUÍMICA (OPENBABEL)'],
                    'table' => ['engine' => 'table', 'label' => 'TABELA ACADÊMICA'],
                    'd2' => ['engine' => 'd2', 'label' => 'DIAGRAMA D2'],
                    'plantuml' => ['engine' => 'plantuml', 'label' => 'PLANTUML'],
                    'graphviz' => ['engine' => 'graphviz', 'label' => 'GRAPHVIZ'],
                    'mermaid' => ['engine' => 'mermaid', 'label' => 'MERMAID'],
                ];

                $renderedSvg = null;
                if (isset($diagramEngines[$rawLang])) {
                    $engineInfo = $diagramEngines[$rawLang];
                    $renderedSvg = self::renderJoajuDiagram($rawCode, $engineInfo['engine'], $options);
                }

                if ($renderedSvg !== null) {
                    $engineInfo = $diagramEngines[$rawLang];
                    $label = htmlspecialchars($engineInfo['label'], ENT_QUOTES, 'UTF-8');
                    $htmlOutput[] = '<figure class="diagram-figure diagram-'.$engineInfo['engine'].'" data-engine="'.$engineInfo['engine'].'">'
                        .'<div class="diagram-header">'
                        .'<span class="diagram-badge">⚡ '.$label.'</span>'
                        .'<div class="diagram-actions">'
                        .'<button type="button" class="btn-diagram-action" onclick="toggleDiagramSource(this)">Código</button>'
                        .'<button type="button" class="btn-copy-code" onclick="copyCode(this)">Copiar</button>'
                        .'</div>'
                        .'</div>'
                        .'<div class="diagram-viewport">'
                        .$renderedSvg
                        .'</div>'
                        .'<div class="diagram-source" style="display:none;">'
                        ."<pre><code class=\"language-{$lang}\">{$escapedCode}</code></pre>"
                        .'</div>'
                        .'</figure>';
                } else {
                    $htmlOutput[] = '<div class="code-block-wrapper">'
                        .'<div class="code-block-header">'
                        .'<span>'.strtoupper($lang).'</span>'
                        .'<button type="button" class="btn-copy-code" onclick="copyCode(this)">Copiar</button>'
                        .'</div>'
                        ."<pre><code class=\"language-{$lang}\">{$escapedCode}</code></pre>"
                        .'</div>';
                }
                $i++;

                continue;
            }

            // 2.1 Display Math ($$ ... $$)
            if (str_starts_with(trim($line), '$$')) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $trimmedLine = trim($line);
                if (preg_match('/^\$\$(.+)\$\$$/', $trimmedLine, $singleMath)) {
                    $mathContent = htmlspecialchars(trim($singleMath[1]), ENT_NOQUOTES, 'UTF-8');
                    $htmlOutput[] = "<div class=\"math-block\">\$\${$mathContent}\$\$</div>";
                    $i++;

                    continue;
                }

                $mathLines = [];
                $i++;
                while ($i < $count && ! str_starts_with(trim($lines[$i]), '$$')) {
                    $mathLines[] = $lines[$i];
                    $i++;
                }
                $mathContent = htmlspecialchars(implode("\n", $mathLines), ENT_NOQUOTES, 'UTF-8');
                $htmlOutput[] = "<div class=\"math-block\">\$\$\n{$mathContent}\n\$\$</div>";
                if ($i < $count) {
                    $i++;
                }

                continue;
            }

            // 3. Obsidian Callout (> [!TYPE][+-]? Title)
            if (preg_match('/^>\s*\[!(\w+)\]([+-]?)(?:[ \t]*(.*))?$/', $line, $matches)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $type = strtolower($matches[1]);
                $collapseSign = $matches[2] ?? '';
                $rawFirstLine = trim($matches[3] ?? '');
                $calloutLines = [];
                $i++;
                while ($i < $count && str_starts_with($lines[$i], '>')) {
                    $cLine = preg_replace('/^>\s?/', '', $lines[$i]);
                    $calloutLines[] = $cLine;
                    $i++;
                }

                $title = $rawFirstLine;
                if (! empty($rawFirstLine) && strlen($rawFirstLine) > 40) {
                    $splitBody = null;
                    if (preg_match('/^(.*?\?)\s+(.+)$/us', $rawFirstLine, $splitM)) {
                        $title = $splitM[1];
                        $splitBody = $splitM[2];
                    } elseif (preg_match('/^(.*?)\s+(\*\*.*)$/us', $rawFirstLine, $splitM)) {
                        $title = $splitM[1];
                        $splitBody = $splitM[2];
                    } elseif (preg_match('/^(.*?[:])\s+(.+)$/us', $rawFirstLine, $splitM)) {
                        $title = $splitM[1];
                        $splitBody = $splitM[2];
                    } elseif (preg_match('/^([A-ZÀ-Ú].{5,55}?)\s+((?:Em|No|Na|Nos|Nas|Restringiu-se|Adotou-se|Observou-se|Trata-se|Para|Com|Este|Esta|Esse|Essa|Conforme|Segundo)\s+.*)$/us', $rawFirstLine, $splitM)) {
                        $title = $splitM[1];
                        $splitBody = $splitM[2];
                    }
                    if ($splitBody !== null) {
                        array_unshift($calloutLines, $splitBody);
                    }
                }
                if ($title === '') {
                    $title = ucfirst($type);
                }

                $titleHtml = self::renderInline($title, $options);

                $bodyHtml = '';
                if (! empty($calloutLines)) {
                    $subParsed = self::parse(implode("\n", $calloutLines), $options);
                    $bodyHtml = $subParsed['html'];
                }

                $iconSvg = self::getCalloutIcon($type);
                $foldIcon = '<span class="callout-fold"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>';

                if ($collapseSign === '+') {
                    $htmlOutput[] = "<details open class=\"callout callout-{$type}\">"
                        ."<summary class=\"callout-title\"><span class=\"callout-icon\">{$iconSvg}</span><span class=\"callout-title-text\">{$titleHtml}</span>{$foldIcon}</summary>"
                        .($bodyHtml !== '' ? "<div class=\"callout-content\">{$bodyHtml}</div>" : '')
                        .'</details>';
                } elseif ($collapseSign === '-') {
                    $htmlOutput[] = "<details class=\"callout callout-{$type}\">"
                        ."<summary class=\"callout-title\"><span class=\"callout-icon\">{$iconSvg}</span><span class=\"callout-title-text\">{$titleHtml}</span>{$foldIcon}</summary>"
                        .($bodyHtml !== '' ? "<div class=\"callout-content\">{$bodyHtml}</div>" : '')
                        .'</details>';
                } else {
                    $htmlOutput[] = "<div class=\"callout callout-{$type}\">"
                        ."<div class=\"callout-title\"><span class=\"callout-icon\">{$iconSvg}</span><span class=\"callout-title-text\">{$titleHtml}</span></div>"
                        .($bodyHtml !== '' ? "<div class=\"callout-content\">{$bodyHtml}</div>" : '')
                        .'</div>';
                }

                continue;
            }

            // 4. Standard Blockquote
            if (str_starts_with($line, '>')) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $quoteLines = [];
                while ($i < $count && str_starts_with($lines[$i], '>')) {
                    $quoteLines[] = preg_replace('/^>\s?/', '', $lines[$i]);
                    $i++;
                }
                $quoteContent = self::renderInline(implode('<br>', $quoteLines), $options);
                $htmlOutput[] = "<blockquote>{$quoteContent}</blockquote>";

                continue;
            }

            // 5. Headings
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $matches)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $level = strlen($matches[1]);
                $titleRaw = trim($matches[2]);
                $headingId = self::slugify($titleRaw);
                $headingHtml = self::renderInline($titleRaw, $options);
                $htmlOutput[] = "<h{$level} id=\"{$headingId}\">{$headingHtml}</h{$level}>";
                $i++;

                continue;
            }

            // 6. Horizontal Rule
            if (preg_match('/^(\-{3,}|\*{3,}|_{3,})$/', trim($line))) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $htmlOutput[] = '<hr>';
                $i++;

                continue;
            }

            // 7. Markdown Pipe Tables
            if (str_starts_with(trim($line), '|') && str_ends_with(trim($line), '|')) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $tableRows = [];
                while ($i < $count && str_starts_with(trim($lines[$i]), '|') && str_ends_with(trim($lines[$i]), '|')) {
                    $tableRows[] = trim($lines[$i]);
                    $i++;
                }

                $htmlOutput[] = self::renderTable($tableRows, $options);

                continue;
            }

            // 8. Lists (Unordered, Ordered, Task Lists)
            $isUnordered = preg_match('/^(\s*)[*+-]\s+(.*)$/', $line, $matchesU);
            $isOrdered = preg_match('/^(\s*)\d+\.\s+(.*)$/', $line, $matchesO);

            if ($isUnordered || $isOrdered) {
                $currentType = $isOrdered ? 'ol' : 'ul';
                $content = $isOrdered ? $matchesO[2] : $matchesU[2];

                if (! $inList) {
                    $htmlOutput[] = "<{$currentType}>";
                    $inList = true;
                    $listType = $currentType;
                } elseif ($listType !== $currentType) {
                    $htmlOutput[] = "</{$listType}><{$currentType}>";
                    $listType = $currentType;
                }

                // Check Task list item (- [ ] or - [x])
                if (preg_match('/^\[([ xX])\]\s+(.*)$/', $content, $taskMatch)) {
                    $checked = strtolower($taskMatch[1]) === 'x' ? 'checked' : '';
                    $itemText = self::renderInline($taskMatch[2], $options);
                    $htmlOutput[] = "<li class=\"task-list-item\"><input type=\"checkbox\" {$checked} disabled> {$itemText}</li>";
                } else {
                    $itemText = self::renderInline($content, $options);
                    $htmlOutput[] = "<li>{$itemText}</li>";
                }
                $i++;

                continue;
            }

            // End list if non-list line
            if ($inList && trim($line) === '') {
                $htmlOutput[] = "</{$listType}>";
                $inList = false;
                $i++;

                continue;
            }

            // 8.5. Indented Code Block (4 spaces or 1 tab - CommonMark)
            if (preg_match('/^(?: {4}|\t)(.*)$/', $line, $indentMatch)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $codeLines = [$indentMatch[1]];
                $i++;
                while ($i < $count) {
                    if (preg_match('/^(?: {4}|\t)(.*)$/', $lines[$i], $subMatch)) {
                        $codeLines[] = $subMatch[1];
                        $i++;
                    } elseif (trim($lines[$i]) === '') {
                        $k = $i + 1;
                        while ($k < $count && trim($lines[$k]) === '') {
                            $k++;
                        }
                        if ($k < $count && preg_match('/^(?: {4}|\t)/', $lines[$k])) {
                            while ($i < $k) {
                                $codeLines[] = '';
                                $i++;
                            }
                        } else {
                            break;
                        }
                    } elseif (trim($lines[$i]) !== '') {
                        // Check if an unindented line is part of a diagram or flanked by indented lines before an empty line
                        $hasIndentLater = false;
                        for ($k = $i + 1; $k < $count && trim($lines[$k]) !== ''; $k++) {
                            if (preg_match('/^(?: {4}|\t)/', $lines[$k])) {
                                $hasIndentLater = true;
                                break;
                            }
                        }
                        $isDiagramLine = (bool) preg_match('/(-->|->|--+|\x5c|\/|\||\[.*?\])/', $lines[$i]);
                        if ($hasIndentLater || $isDiagramLine) {
                            $codeLines[] = $lines[$i];
                            $i++;
                        } else {
                            break;
                        }
                    } else {
                        break;
                    }
                }

                $codeText = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8');
                $isAsciiDiagram = (bool) preg_match('/(-->|->|--+|\x5c|\/|\|)/', $codeText);
                $blockLabel = $isAsciiDiagram ? 'DIAGRAMA' : 'TEXT';

                $htmlOutput[] = '<div class="code-block-wrapper">'
                    .'<div class="code-block-header">'
                    .'<span>'.$blockLabel.'</span>'
                    .'<button type="button" class="btn-copy-code" onclick="copyCode(this)">Copiar</button>'
                    .'</div>'
                    ."<pre><code>{$codeText}</code></pre>"
                    .'</div>';

                continue;
            }

            // 8.8 Footnote Definition ([^id]: content)
            if (preg_match('/^\[\^([^\]]+)\]:\s*(.*)$/', $line, $fnMatch)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }
                $fnKey = trim($fnMatch[1]);
                $fnBody = [$fnMatch[2]];
                $i++;
                while ($i < $count && (preg_match('/^(?: {4}|\t)(.*)$/', $lines[$i], $fnSub) || (trim($lines[$i]) !== '' && ! preg_match('/^(#{1,6}\s|>\s|```|\-{3,}|\*{3,}|\||[*+-]\s|\d+\.\s|\$\$|\[\^)/', $lines[$i])))) {
                    $fnBody[] = isset($fnSub[1]) ? $fnSub[1] : $lines[$i];
                    $i++;
                }
                $footnotes[$fnKey] = implode(' ', $fnBody);

                continue;
            }

            // 9. Standard Paragraphs
            $trimmed = trim($line);
            if ($trimmed === '') {
                $i++;

                continue;
            }

            $paraLines = [];
            while ($i < $count && trim($lines[$i]) !== '' &&
                   ! preg_match('/^(#{1,6}\s|>\s|```|\-{3,}|\*{3,}|\||[*+-]\s|\d+\.\s|\$\$| {4}|\t|\[\^)/', $lines[$i])) {
                $paraLines[] = $lines[$i];
                $i++;
            }

            if (! empty($paraLines)) {
                $paraText = self::renderInline(implode(' ', $paraLines), $options);
                $htmlOutput[] = "<p>{$paraText}</p>";
            } else {
                $i++;
            }
        }

        if ($inList) {
            $htmlOutput[] = "</{$listType}>";
        }

        if (! empty($footnotes)) {
            $fnHtml = '<section class="footnotes"><hr><ol>';
            foreach ($footnotes as $fnId => $fnText) {
                $escapedId = htmlspecialchars($fnId, ENT_QUOTES, 'UTF-8');
                $fnContent = self::renderInline($fnText, $options);
                $fnHtml .= "<li id=\"fn-{$escapedId}\">{$fnContent} <a href=\"#fnref-{$escapedId}\" class=\"footnote-backref\">↩</a></li>";
            }
            $fnHtml .= '</ol></section>';
            $htmlOutput[] = $fnHtml;
        }

        return [
            'html' => implode("\n", $htmlOutput),
            'meta' => $meta,
        ];
    }

    /**
     * Renders inline markdown formatting.
     */
    public static function renderInline(string $text, array $options = []): string
    {
        $slug = trim((string) ($options['slug'] ?? ''));
        $assetPrefix = $slug !== '' ? "/{$slug}/assets/" : 'assets/';

        // 0. Protect Inline Code (`...`) from asterisks, underscores, and formatting
        $codePlaceholders = [];
        $text = preg_replace_callback('/`([^`]+)`/', function ($matches) use (&$codePlaceholders) {
            $key = "\x1BCODE".count($codePlaceholders)."X\x1B";
            $codePlaceholders[$key] = '<code>'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'</code>';

            return $key;
        }, $text) ?? $text;

        // 0.1 Protect Inline Math ($...$) from markdown asterisks/underscores
        $mathPlaceholders = [];
        $text = preg_replace_callback('/(?<!\\\\)\$(?!\$)(.*?)(?<!\\\\)\$/s', function ($matches) use (&$mathPlaceholders) {
            $key = "\x1BMATH".count($mathPlaceholders)."X\x1B";
            $mathPlaceholders[$key] = '$'.htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8').'$';

            return $key;
        }, $text) ?? $text;

        // 1. Obsidian Wikilink Images: ![[imagem.png|alt]] or ![[imagem.png]]
        $text = preg_replace_callback('/!\[\[(.*?)\]\]/', function ($matches) use ($assetPrefix) {
            $parts = explode('|', $matches[1]);
            $src = trim($parts[0]);
            $alt = isset($parts[1]) ? trim($parts[1]) : basename($src);
            $cleanSrc = $assetPrefix.basename($src);

            return '<img src="'.htmlspecialchars($cleanSrc, ENT_QUOTES, 'UTF-8').'" alt="'.htmlspecialchars($alt, ENT_QUOTES, 'UTF-8').'" loading="lazy">';
        }, $text) ?? $text;

        // 2. Standard Images: ![alt](url)
        $text = preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function ($matches) use ($assetPrefix) {
            $alt = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $rawSrc = trim($matches[2]);
            if (! preg_match('/^(https?:\/\/|data:|\/)/', $rawSrc)) {
                $src = $assetPrefix.basename($rawSrc);
            } else {
                $src = $rawSrc;
            }
            $cleanSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');

            return "<img src=\"{$cleanSrc}\" alt=\"{$alt}\" loading=\"lazy\">";
        }, $text) ?? $text;

        // 3. Obsidian Wikilink internal: [[link|label]] or [[link]]
        $text = preg_replace_callback('/\[\[(.*?)\]\]/', function ($matches) {
            $parts = explode('|', $matches[1]);
            $target = trim($parts[0]);
            $label = isset($parts[1]) ? trim($parts[1]) : $target;

            return '<a href="'.htmlspecialchars($target, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a>';
        }, $text) ?? $text;

        // 4. Standard Links: [text](url)
        $text = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function ($matches) {
            $label = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars(trim($matches[2]), ENT_QUOTES, 'UTF-8');

            return "<a href=\"{$href}\" target=\"_blank\" rel=\"noopener noreferrer\">{$label}</a>";
        }, $text) ?? $text;

        // 5. Footnote references: [^id]
        $text = preg_replace_callback('/\[\^([^\]]+)\]/', function ($matches) {
            $id = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');

            return '<sup class="footnote-ref"><a href="#fn-'.$id.'" id="fnref-'.$id.'">['.$id.']</a></sup>';
        }, $text) ?? $text;

        // 6. Obsidian Mark / Highlight ==text==
        $text = preg_replace_callback('/==(.*?)==/', function ($matches) {
            return '<mark>'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'</mark>';
        }, $text) ?? $text;

        // Protect generated HTML tags from markdown asterisks and underscores
        $htmlPlaceholders = [];
        $text = preg_replace_callback('/<[^>]+>/s', function ($matches) use (&$htmlPlaceholders) {
            $key = "\x1BHTML".count($htmlPlaceholders)."X\x1B";
            $htmlPlaceholders[$key] = $matches[0];

            return $key;
        }, $text) ?? $text;

        // 7. Bold **text** or __text__
        $text = preg_replace('/(\*\*|__)(.*?)\1/', '<strong>$2</strong>', $text) ?? $text;

        // 8. Italic *text* or _text_ (com proteção para underscores intra-palavra)
        $text = preg_replace('/(?<!\w)\*([^\*\s]+.*?)\*(?!\w)/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<!\w)_([^_\s]+.*?)_(?!\w)/', '<em>$1</em>', $text) ?? $text;

        // 9. Strikethrough ~~text~~
        $text = preg_replace('/~~(.*?)~~/', '<del>$1</del>', $text) ?? $text;

        // Restore protected HTML tags
        if (! empty($htmlPlaceholders)) {
            $text = strtr($text, $htmlPlaceholders);
        }

        // Restore protected Inline Code and Inline Math
        if (! empty($codePlaceholders)) {
            $text = strtr($text, $codePlaceholders);
        }
        if (! empty($mathPlaceholders)) {
            $text = strtr($text, $mathPlaceholders);
        }

        return $text;
    }

    /**
     * Renders Markdown table rows into responsive HTML table.
     *
     * @param  string[]  $rows
     */
    private static function renderTable(array $rows, array $options = []): string
    {
        if (count($rows) < 2) {
            return '';
        }

        $headers = array_map('trim', explode('|', trim($rows[0], '|')));
        $alignments = [];

        // Check alignment row (second row)
        $alignRow = array_map('trim', explode('|', trim($rows[1], '|')));
        foreach ($alignRow as $a) {
            if (str_starts_with($a, ':') && str_ends_with($a, ':')) {
                $alignments[] = 'center';
            } elseif (str_ends_with($a, ':')) {
                $alignments[] = 'right';
            } else {
                $alignments[] = 'left';
            }
        }

        $html = '<div class="table-wrapper"><table><thead><tr>';
        foreach ($headers as $idx => $h) {
            $alignStyle = isset($alignments[$idx]) ? ' style="text-align:'.$alignments[$idx].';"' : '';
            $html .= "<th{$alignStyle}>".self::renderInline($h, $options).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        for ($r = 2; $r < count($rows); $r++) {
            $cells = array_map('trim', explode('|', trim($rows[$r], '|')));
            $html .= '<tr>';
            foreach ($cells as $idx => $c) {
                $alignStyle = isset($alignments[$idx]) ? ' style="text-align:'.$alignments[$idx].';"' : '';
                $html .= "<td{$alignStyle}>".self::renderInline($c, $options).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * Parse minimal key-value frontmatter.
     *
     * @return array<string, string>
     */
    private static function parseSimpleYaml(string $yaml): array
    {
        $meta = [];
        $lines = explode("\n", $yaml);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $key = strtolower(trim(substr($line, 0, $colonPos)));
                $val = trim(substr($line, $colonPos + 1));
                $val = trim($val, "\"'");
                $meta[$key] = $val;
            }
        }

        return $meta;
    }

    private static function slugify(string $text): string
    {
        $text = strip_tags($text);

        // Normalize accented characters
        $accentMap = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'a', 'À' => 'a', 'Ã' => 'a', 'Â' => 'a', 'Ä' => 'a',
            'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'Ó' => 'o', 'Ò' => 'o', 'Õ' => 'o', 'Ô' => 'o', 'Ö' => 'o',
            'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
            'Ç' => 'c', 'Ñ' => 'n',
        ];
        $text = strtr($text, $accentMap);

        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($trans !== false) {
                $text = $trans;
            }
        }

        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\-]+/i', '-', $text);

        return trim((string) $text, '-');
    }

    /**
     * Builds and normalizes the Joaju diagram rendering endpoint URL.
     */
    public static function buildJoajuRenderUrl(string $rawUrl = ''): string
    {
        $url = trim($rawUrl);
        if ($url === '') {
            $envUrl = getenv('FUXICO_JOAJU_URL');
            $url = is_string($envUrl) && trim($envUrl) !== '' ? trim($envUrl) : 'https://joaju.lumen.pink';
        }

        $url = rtrim($url, '/');
        if (str_ends_with($url, '/render')) {
            return $url;
        }

        return "{$url}/render";
    }

    /**
     * Renders a diagram code block via Joaju API (/render) into clean inline SVG.
     *
     * @param  array<string, mixed>  $options
     */
    public static function renderJoajuDiagram(string $rawCode, string $engine, array $options = []): ?string
    {
        if (getenv('FUXICO_DISABLE_DIAGRAMS') === '1' || ($options['disable_diagrams'] ?? false)) {
            return null;
        }

        $rawJoajuUrl = (string) ($options['joaju_url'] ?? getenv('FUXICO_JOAJU_URL') ?: '');
        $joajuUrl = self::buildJoajuRenderUrl($rawJoajuUrl);

        $trimmed = trim($rawCode);
        if ($trimmed === '') {
            return null;
        }

        $wrappedCode = str_starts_with($trimmed, '```') ? $trimmed : "```{$engine}\n{$trimmed}\n```";
        $payload = json_encode([
            'code' => $wrappedCode,
            'engine' => $engine,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return null;
        }

        $ch = curl_init($joajuUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($options['joaju_timeout'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($httpCode !== 200 || ! is_string($response)) {
            return null;
        }

        $data = json_decode($response, true);
        if (! is_array($data)) {
            return null;
        }

        if (! empty($data['html']) && is_string($data['html'])) {
            return trim($data['html']);
        }

        if (empty($data['svg']) || ! is_string($data['svg'])) {
            return null;
        }

        $svg = $data['svg'];
        $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? $svg;

        return trim($svg);
    }

    /**
     * Returns an inline SVG icon for Obsidian callout types.
     */
    public static function getCalloutIcon(string $type): string
    {
        $icons = [
            'note' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>',
            'abstract' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
            'summary' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
            'tldr' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
            'info' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
            'todo' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="m9 12 2 2 4-4"/></svg>',
            'tip' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'hint' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'important' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'success' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
            'check' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
            'done' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
            'question' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
            'help' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
            'faq' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
            'warning' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
            'caution' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
            'attention' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
            'failure' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'fail' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'missing' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'danger' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'error' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'bug' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>',
            'example' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg>',
            'quote' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg>',
            'cite' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg>',
        ];

        return $icons[$type] ?? $icons['note'];
    }
}
}

/* --- Module: GistRenderer.php --- */
namespace Fuxico {
class GistRenderer
{
    /**
     * Renders a multi-file Gist into a standalone, dual-skin HTML page with interactive tabs.
     *
     * @param  array<string, string>  $files  Map of relative filename => file content
     * @param array{
     *   title?: string,
     *   slug?: string,
     *   default_theme?: string,
     *   created_at?: int,
     *   base_url?: string
     * } $options
     */
    public static function render(array $files, array $options = []): string
    {
        $slug = htmlspecialchars((string) ($options['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $baseUrl = rtrim((string) ($options['base_url'] ?? ''), '/');
        $defaultTheme = ($options['default_theme'] ?? 'zine') === 'acad' ? 'acad' : 'zine';
        $createdAt = $options['created_at'] ?? time();
        $dateFormatted = date('Y-m-d H:i:s', $createdAt);

        // Find primary title
        $firstFilename = array_key_first($files) ?: 'Gist';
        $titleRaw = (string) ($options['title'] ?? '');
        if ($titleRaw === '' || $titleRaw === $slug) {
            $titleRaw = $firstFilename;
        }
        $title = htmlspecialchars($titleRaw, ENT_QUOTES, 'UTF-8');

        $totalFiles = count($files);
        $totalBytes = 0;
        foreach ($files as $content) {
            $totalBytes += strlen($content);
        }
        $totalSizeKb = number_format($totalBytes / 1024, 1);

        // Order files: README files first, then alphabetical
        uksort($files, function ($a, $b) {
            $isReadmeA = str_starts_with(strtoupper($a), 'README');
            $isReadmeB = str_starts_with(strtoupper($b), 'README');
            if ($isReadmeA && ! $isReadmeB) {
                return -1;
            }
            if (! $isReadmeA && $isReadmeB) {
                return 1;
            }

            return strcasecmp($a, $b);
        });

        // Build file tabs and panes
        $tabsHtml = '';
        $panesHtml = '';
        $fileIndex = 0;

        $fileOptionsHtml = '';
        foreach (array_keys($files) as $fname) {
            $safeFname = htmlspecialchars($fname, ENT_QUOTES, 'UTF-8');
            $fileOptionsHtml .= "<option value=\"{$safeFname}\">{$safeFname}</option>";
        }

        // Variáveis JS para o modal de embed
        $baseUrlJs = json_encode($baseUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $slugJs = json_encode($slug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($files as $filename => $content) {
            $fileId = 'file-'.preg_replace('/[^a-zA-Z0-9_\-]+/', '-', $filename).'-'.$fileIndex;
            $safeName = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $isMd = in_array($ext, ['md', 'markdown'], true);
            $icon = self::getFileIcon($ext);
            $lineCount = substr_count($content, "\n") + (strlen($content) > 0 && ! str_ends_with($content, "\n") ? 1 : 0);
            $sizeKb = number_format(strlen($content) / 1024, 1);
            $isActive = $fileIndex === 0 ? ' active' : '';

            $rawUrl = ($baseUrl !== '' && $slug !== '')
                ? "{$baseUrl}/{$slug}/raw/".rawurlencode($filename)
                : '#';

            $tabsHtml .= <<<HTML
            <button class="gist-tab{$isActive}" data-target="{$fileId}" onclick="switchGistTab(this, '{$fileId}')">
                <span class="gist-tab-icon">{$icon}</span>
                <span class="gist-tab-name">{$safeName}</span>
                <span class="gist-tab-lines">{$lineCount}L</span>
            </button>
HTML;

            // Generate lines & code content
            $lines = explode("\n", $content);
            $lineNumbersHtml = '';
            $codeLinesHtml = '';
            for ($i = 1; $i <= count($lines); $i++) {
                $lineNumbersHtml .= "<span>{$i}</span>\n";
            }
            $escapedCode = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

            $previewToggleHtml = '';
            $previewBodyHtml = '';
            if ($isMd) {
                $parsedMd = MarkdownRenderer::parse($content);
                $renderedBody = $parsedMd['html'];
                $previewToggleHtml = <<<HTML
                <div class="gist-preview-toggle">
                    <button class="preview-btn active" onclick="toggleMdView(this, '{$fileId}', 'preview')">Visualização</button>
                    <button class="preview-btn" onclick="toggleMdView(this, '{$fileId}', 'source')">Código</button>
                </div>
HTML;
                $previewBodyHtml = <<<HTML
                <div id="{$fileId}-preview" class="gist-md-preview">
                    {$renderedBody}
                </div>
HTML;
            }

            $sourceStyle = $isMd ? 'display:none;' : '';
            $panesHtml .= <<<HTML
            <article id="{$fileId}" class="gist-pane{$isActive}" data-file="{$safeName}">
                <div class="gist-pane-header">
                    <div class="gist-pane-title">
                        <span class="gist-file-icon">{$icon}</span>
                        <strong>{$safeName}</strong>
                        <span class="gist-meta-badge">{$lineCount} linhas</span>
                        <span class="gist-meta-badge">{$sizeKb} KB</span>
                    </div>
                    <div class="gist-pane-actions">
                        {$previewToggleHtml}
                        <button class="btn-gist-action" onclick="openFileEmbedModal('{$safeName}')">🏷️ Embed</button>
                        <button class="btn-gist-action" onclick="copyFileContent('{$fileId}')">📋 Copiar</button>
                        <a class="btn-gist-action" href="{$rawUrl}" target="_blank" rel="noopener noreferrer">📄 Raw</a>
                    </div>
                </div>
                {$previewBodyHtml}
                <div id="{$fileId}-source" class="gist-code-box" style="{$sourceStyle}">
                    <div class="gist-line-numbers" aria-hidden="true">{$lineNumbersHtml}</div>
                    <pre class="gist-code-pre"><code id="{$fileId}-code" class="language-{$ext}">{$escapedCode}</code></pre>
                </div>
            </article>
HTML;
            $fileIndex++;
        }

        $zipUrl = ($baseUrl !== '' && $slug !== '') ? "{$baseUrl}/{$slug}/zip" : '#';
        $shortUrl = ($baseUrl !== '' && $slug !== '') ? "{$baseUrl}/{$slug}" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR" data-theme="{$defaultTheme}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — Fuxico Gist</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Grotesk:wght@400;600;700&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Noto+Serif:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <script>
        (function() {
            try {
                const params = new URLSearchParams(window.location.search);
                const saved = localStorage.getItem('fuxico_theme');
                let theme = '{$defaultTheme}';
                if (params.get('acad') === 'true' || params.get('theme') === 'acad' || params.has('acad')) {
                    theme = 'acad';
                } else if (params.get('theme') === 'zine') {
                    theme = 'zine';
                } else if (saved === 'acad' || saved === 'zine') {
                    theme = saved;
                }
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>
    <style>
        /* Base Resets & Transitions */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ─── DUAL SKIN: 1. TRAVA ZINE (FUXICO STANDARD) ─── */
        [data-theme="zine"] {
            --bg-body: #121016;
            --bg-card: #1a1622;
            --bg-surface: #221c2e;
            --text-main: #f5f4ef;
            --text-muted: #9f97ad;
            --text-link: #e2f835;
            --acid-yellow: #e2f835;
            --acid-hover: #ccee22;
            --electric-pink: #ff2a85;
            --tape-black: #0a080d;
            --border-color: #383047;
            --border-hard: 2px solid #000;
            --shadow-hard: 4px 4px 0px #0a080d;
            --shadow-accent: 5px 5px 0px var(--electric-pink);
            --font-heading: 'Anton', -apple-system, sans-serif;
            --font-sub: 'Space Grotesk', -apple-system, sans-serif;
            --font-body: 'Space Grotesk', -apple-system, sans-serif;
            --font-mono: 'Space Mono', monospace;
            --code-bg: #09080d;
            --callout-bg: #1c1726;
            --mark-bg: rgba(226, 248, 53, 0.3);
            --mark-color: #e2f835;
            --badge-bg: #272033;
            --line-num-color: #5d5272;
        }

        /* ─── DUAL SKIN: 2. ACADÊMICO DEPRESSIVO MONOCROMÁTICO (?acad=true) ─── */
        [data-theme="acad"] {
            --bg-body: #fcfcfc;
            --bg-card: #ffffff;
            --bg-surface: #f4f4f5;
            --text-main: #18181b;
            --text-muted: #52525b;
            --text-link: #09090b;
            --acid-yellow: #18181b;
            --acid-hover: #27272a;
            --electric-pink: #71717a;
            --tape-black: #f4f4f5;
            --border-color: #e4e4e7;
            --border-hard: 1px solid #d4d4d8;
            --shadow-hard: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-accent: 0 2px 4px rgba(0,0,0,0.05);
            --font-heading: 'Noto Serif', serif;
            --font-sub: 'Noto Serif', serif;
            --font-body: 'Noto Serif', Georgia, serif;
            --font-mono: 'Space Mono', monospace;
            --code-bg: #f8fafc;
            --callout-bg: #f4f4f5;
            --mark-bg: #e4e4e7;
            --mark-color: #09090b;
            --badge-bg: #e4e4e7;
            --line-num-color: #a1a1aa;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-body);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .trans-stripe {
            display: flex;
            width: 100%;
            height: 6px;
            flex-shrink: 0;
        }
        .trans-stripe-line { flex: 1; height: 100%; }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }

        .gist-container {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 20px 48px;
            flex: 1;
        }

        /* Header */
        .gist-header {
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-hard);
            padding: 24px;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .gist-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }

        .gist-title-wrap {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .gist-brand-badge {
            display: inline-block;
            align-self: flex-start;
            background: var(--tape-black);
            color: var(--acid-yellow);
            font-family: var(--font-mono);
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border: 1px solid var(--border-color);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .gist-title {
            font-family: var(--font-heading);
            font-size: 1.8rem;
            line-height: 1.2;
            color: var(--text-main);
            letter-spacing: 0.5px;
        }

        .gist-header-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.85rem;
            color: var(--text-muted);
            align-items: center;
        }

        .gist-header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn-header {
            background: var(--tape-black);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 8px 14px;
            font-family: var(--font-mono);
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 2px 2px 0px rgba(0,0,0,0.5);
            transition: all 0.15s ease;
        }

        .btn-header:hover {
            border-color: var(--acid-yellow);
            color: var(--acid-yellow);
            transform: translate(-1px, -1px);
        }

        .btn-zip {
            background: var(--electric-pink);
            color: #fff;
            border-color: #000;
        }
        .btn-zip:hover {
            background: #ff4797;
            color: #fff;
        }

        /* Tabs Nav */
        .gist-tabs-nav {
            display: flex;
            gap: 4px;
            overflow-x: auto;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 16px;
            padding-bottom: 0;
        }

        .gist-tab {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-bottom: none;
            color: var(--text-muted);
            padding: 10px 16px;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .gist-tab:hover {
            color: var(--text-main);
            background: var(--bg-surface);
        }

        .gist-tab.active {
            background: var(--bg-surface);
            color: var(--acid-yellow);
            border-top: 3px solid var(--acid-yellow);
            border-color: var(--border-color) var(--border-color) transparent;
        }

        [data-theme="acad"] .gist-tab.active {
            border-top: 3px solid #18181b;
            color: #18181b;
        }

        .gist-tab-lines {
            font-size: 0.72rem;
            opacity: 0.7;
            font-weight: 400;
            background: var(--badge-bg);
            padding: 2px 6px;
            border-radius: 2px;
        }

        /* File Panes */
        .gist-pane {
            display: none;
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-hard);
            margin-bottom: 24px;
        }

        .gist-pane.active {
            display: block;
        }

        .gist-pane-header {
            background: var(--tape-black);
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 10px;
        }

        .gist-pane-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: var(--font-mono);
            font-size: 0.9rem;
        }

        .gist-meta-badge {
            font-size: 0.75rem;
            color: var(--text-muted);
            background: var(--badge-bg);
            padding: 2px 8px;
            border: 1px solid var(--border-color);
        }

        .gist-pane-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .gist-preview-toggle {
            display: inline-flex;
            border: 1px solid var(--border-color);
            background: var(--bg-surface);
        }

        .preview-btn {
            background: transparent;
            border: none;
            padding: 4px 10px;
            font-family: var(--font-mono);
            font-size: 0.78rem;
            color: var(--text-muted);
            cursor: pointer;
        }

        .preview-btn.active {
            background: var(--acid-yellow);
            color: #000;
            font-weight: 700;
        }

        [data-theme="acad"] .preview-btn.active {
            background: #18181b;
            color: #fff;
        }

        .btn-gist-action {
            background: var(--bg-surface);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 5px 12px;
            font-family: var(--font-mono);
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-gist-action:hover {
            border-color: var(--acid-yellow);
            color: var(--acid-yellow);
        }

        /* Code View */
        .gist-code-box {
            display: flex;
            overflow-x: auto;
            background: var(--code-bg);
            font-family: var(--font-mono);
            font-size: 0.88rem;
            line-height: 1.5;
        }

        .gist-line-numbers {
            user-select: none;
            text-align: right;
            padding: 14px 12px 14px 16px;
            color: var(--line-num-color);
            border-right: 1px solid var(--border-color);
            background: rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
        }

        .gist-line-numbers span {
            display: block;
            height: 1.5em;
        }

        .gist-code-pre {
            flex: 1;
            padding: 14px 16px;
            overflow-x: auto;
            margin: 0;
            background: transparent;
        }

        .gist-code-pre code {
            font-family: inherit;
            color: var(--text-main);
            white-space: pre;
        }

        /* Markdown Preview Inside Gist */
        .gist-md-preview {
            padding: 24px;
            background: var(--bg-card);
            line-height: 1.7;
        }
        .gist-md-preview h1, .gist-md-preview h2, .gist-md-preview h3 {
            margin-top: 1.2em;
            margin-bottom: 0.6em;
            color: var(--text-main);
            font-family: var(--font-heading);
        }
        .gist-md-preview p { margin-bottom: 1em; }
        .gist-md-preview ul, .gist-md-preview ol { margin-left: 1.5em; margin-bottom: 1em; }
        .gist-md-preview code {
            background: var(--bg-surface);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: var(--font-mono);
            font-size: 0.88em;
        }
        .gist-md-preview pre {
            background: var(--code-bg);
            padding: 14px;
            border: 1px solid var(--border-color);
            overflow-x: auto;
            margin-bottom: 1em;
        }

        /* Embed Modal */
        .gist-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .gist-modal-overlay.active {
            display: flex;
        }
        .gist-modal-card {
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            width: 100%;
            max-width: 580px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            position: relative;
        }
        .gist-modal-close {
            position: absolute;
            top: 14px;
            right: 16px;
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: var(--text-muted);
            cursor: pointer;
        }
        .gist-modal-close:hover {
            color: var(--acid-yellow);
        }
        .gist-modal-title {
            font-family: var(--font-heading);
            font-size: 1.3rem;
            color: var(--acid-yellow);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .gist-modal-desc {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-bottom: 18px;
        }
        .gist-modal-field {
            margin-bottom: 14px;
        }
        .gist-modal-field label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .gist-modal-select, .gist-modal-input {
            width: 100%;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 8px 12px;
            font-family: inherit;
            font-size: 0.9rem;
            border-radius: 4px;
            outline: none;
        }
        .gist-modal-input {
            font-family: var(--font-mono);
            font-size: 0.85rem;
            background: var(--code-bg);
            border-color: var(--acid-yellow);
        }
        .gist-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        /* Footer */
        .gist-footer {
            margin-top: 32px;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
            padding-top: 16px;
        }
        .gist-footer a { color: var(--acid-yellow); text-decoration: none; }
        .gist-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
        <div class="trans-stripe-line blue"></div>
        <div class="trans-stripe-line pink"></div>
        <div class="trans-stripe-line white"></div>
        <div class="trans-stripe-line pink"></div>
        <div class="trans-stripe-line blue"></div>
    </div>

    <div class="gist-container">
        <header class="gist-header">
            <div class="gist-header-top">
                <div class="gist-title-wrap">
                    <span class="gist-brand-badge">⚡ FUXICO GIST // MULTI-ARQUIVO</span>
                    <h1 class="gist-title">{$title}</h1>
                </div>
                <div class="gist-header-actions">
                    <button class="btn-header" onclick="openEmbedModal()">🏷️ Embed</button>
                    <button class="btn-header" onclick="copyGistUrl()">🔗 Copiar Link</button>
                    <a class="btn-header btn-zip" href="{$zipUrl}">📦 Baixar ZIP</a>
                    <button class="btn-header" onclick="toggleTheme()" id="themeBtn">🎨 Alternar Tema</button>
                </div>
            </div>
            <div class="gist-header-meta">
                <span>📁 {$totalFiles} arquivos</span>
                <span>•</span>
                <span>💾 {$totalSizeKb} KB</span>
                <span>•</span>
                <span>📅 {$dateFormatted}</span>
                <span>•</span>
                <span style="font-family:var(--font-mono);">/{$slug}</span>
            </div>
        </header>

        <nav class="gist-tabs-nav" aria-label="Arquivos do Gist">
            {$tabsHtml}
        </nav>

        <main id="gistPanes">
            {$panesHtml}
        </main>

        <footer class="gist-footer">
            <p><strong>FUXICO</strong> // Ingestão efêmera &amp; gists multi-arquivo // Orgulhosamente travesti latina</p>
        </footer>
    </div>

    <div id="gistEmbedModal" class="gist-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="embedModalTitle" onclick="if(event.target===this)closeEmbedModal()">
        <div class="gist-modal-card">
            <button class="gist-modal-close" onclick="closeEmbedModal()" aria-label="Fechar modal">✕</button>
            <div class="gist-modal-title">🏷️ <span id="embedModalTitle">Embed do Gist</span></div>
            <p class="gist-modal-desc">Cole o snippet abaixo em qualquer página HTML para incorporar este gist.</p>

            <div class="gist-modal-field">
                <label for="embedFileSelect">Arquivo</label>
                <select id="embedFileSelect" class="gist-modal-select" onchange="updateEmbedSnippet()">
                    <option value="">Todos os arquivos</option>
                    {$fileOptionsHtml}
                </select>
            </div>

            <div class="gist-modal-field">
                <label for="embedThemeSelect">Tema</label>
                <select id="embedThemeSelect" class="gist-modal-select" onchange="updateEmbedSnippet()">
                    <option value="">Padrão</option>
                    <option value="zine">Zine (escuro)</option>
                    <option value="acad">Acad (claro)</option>
                </select>
            </div>

            <div class="gist-modal-field">
                <label for="embedSnippetInput">Snippet</label>
                <input id="embedSnippetInput" class="gist-modal-input" type="text" readonly onclick="this.select()" aria-label="Snippet de embed" />
            </div>

            <div class="gist-modal-actions">
                <button class="btn-header" onclick="closeEmbedModal()">Fechar</button>
                <button class="btn-header" onclick="copyEmbedSnippet()">📋 Copiar</button>
            </div>
        </div>
    </div>

    <script>
        function switchGistTab(btn, targetId) {
            document.querySelectorAll('.gist-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.gist-pane').forEach(p => p.classList.remove('active'));

            btn.classList.add('active');
            const targetPane = document.getElementById(targetId);
            if (targetPane) {
                targetPane.classList.add('active');
                if (history.replaceState) {
                    const cleanName = targetPane.getAttribute('data-file');
                    history.replaceState(null, '', '#' + encodeURIComponent(cleanName));
                }
            }
        }

        function toggleMdView(btn, fileId, mode) {
            const previewEl = document.getElementById(fileId + '-preview');
            const sourceEl = document.getElementById(fileId + '-source');
            const parent = btn.parentElement;
            parent.querySelectorAll('.preview-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            if (mode === 'preview') {
                if (previewEl) previewEl.style.display = 'block';
                if (sourceEl) sourceEl.style.display = 'none';
            } else {
                if (previewEl) previewEl.style.display = 'none';
                if (sourceEl) sourceEl.style.display = 'flex';
            }
        }

        function copyFileContent(fileId) {
            const codeEl = document.getElementById(fileId + '-code');
            if (!codeEl) return;
            navigator.clipboard.writeText(codeEl.innerText).then(() => {
                const btn = event.target;
                const oldText = btn.innerText;
                btn.innerText = '✔ Copiado!';
                setTimeout(() => { btn.innerText = oldText; }, 2000);
            }).catch(err => {
                alert('Erro ao copiar: ' + err);
            });
        }

        function copyGistUrl() {
            navigator.clipboard.writeText(window.location.href.split('#')[0]).then(() => {
                const btn = event.target;
                const oldText = btn.innerText;
                btn.innerText = '✔ Copiado!';
                setTimeout(() => { btn.innerText = oldText; }, 2000);
            });
        }

        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme') || 'zine';
            const next = current === 'zine' ? 'acad' : 'zine';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('fuxico_theme', next);
        }

        // Funções do modal de embed
        const _embedBaseUrl = {$baseUrlJs};
        const _embedSlug    = {$slugJs};

        function openEmbedModal() {
            document.getElementById('gistEmbedModal').classList.add('active');
            updateEmbedSnippet();
        }

        function openFileEmbedModal(filename) {
            const sel = document.getElementById('embedFileSelect');
            for (let i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === filename) { sel.selectedIndex = i; break; }
            }
            openEmbedModal();
        }

        function updateEmbedSnippet() {
            const file  = document.getElementById('embedFileSelect').value;
            const theme = document.getElementById('embedThemeSelect').value;
            let url = _embedBaseUrl + '/' + _embedSlug + '/embed.js';
            const params = [];
            if (file)  params.push('file='  + encodeURIComponent(file));
            if (theme) params.push('theme=' + encodeURIComponent(theme));
            if (params.length) url += '?' + params.join('&');
            document.getElementById('embedSnippetInput').value = '<script src="' + url + '"><\/script>';
        }

        function copyEmbedSnippet() {
            const input = document.getElementById('embedSnippetInput');
            navigator.clipboard.writeText(input.value).then(() => {
                const btn = event.target;
                const old = btn.innerText;
                btn.innerText = '✔ Copiado!';
                setTimeout(() => { btn.innerText = old; }, 2000);
            }).catch(err => alert('Erro ao copiar: ' + err));
        }

        function closeEmbedModal() {
            document.getElementById('gistEmbedModal').classList.remove('active');
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeEmbedModal();
        });

        window.addEventListener('DOMContentLoaded', () => {
            if (window.location.hash) {
                const targetName = decodeURIComponent(window.location.hash.substring(1));
                const targetPane = document.querySelector('.gist-pane[data-file="' + CSS.escape(targetName) + '"]');
                if (targetPane) {
                    const id = targetPane.id;
                    const tabBtn = document.querySelector('.gist-tab[data-target="' + CSS.escape(id) + '"]');
                    if (tabBtn) switchGistTab(tabBtn, id);
                }
            }
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * Gera o conteúdo JavaScript do widget de embed para ser servido em /{slug}/embed.js.
     *
     * O script usa document.currentScript para se auto-localizar no DOM e injeta
     * um container com os arquivos do gist, com estilos escopados em .fuxico-gist-embed.
     *
     * @param  array<string, string>  $files  Mapa filename => conteúdo
     * @param array{
     *   slug?: string,
     *   base_url?: string,
     *   theme?: string,
     *   file?: string,
     *   gist_url?: string
     * } $options
     */
    public static function renderEmbedJs(array $files, array $options = []): string
    {
        $slug = (string) ($options['slug'] ?? '');
        $baseUrl = rtrim((string) ($options['base_url'] ?? ''), '/');
        $theme = (($options['theme'] ?? '') === 'acad') ? 'acad' : 'zine';
        $gistUrl = $options['gist_url'] ?? ($baseUrl !== '' && $slug !== '' ? "{$baseUrl}/{$slug}" : '');

        // Filtragem de arquivo único
        $filterFile = (string) ($options['file'] ?? '');
        if ($filterFile !== '' && isset($files[$filterFile])) {
            $files = [$filterFile => $files[$filterFile]];
        }

        // Monta lista de arquivos como dados JSON
        $fileList = [];
        foreach ($files as $filename => $content) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $lines = substr_count($content, "\n") + (strlen($content) > 0 && ! str_ends_with($content, "\n") ? 1 : 0);
            $fileList[] = [
                'name' => $filename,
                'content' => $content,
                'ext' => $ext,
                'lines' => $lines,
                'size' => strlen($content),
            ];
        }

        $filesJson = json_encode($fileList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $gistUrlJson = json_encode($gistUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $slugJson = json_encode($slug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $themeJson = json_encode($theme, JSON_UNESCAPED_UNICODE);

        return <<<JS
(function() {
  'use strict';

  var THEME    = {$themeJson};
  var GIST_URL = {$gistUrlJson};
  var SLUG     = {$slugJson};
  var FILES    = {$filesJson};

  var COLORS = {
    zine: {
      bg:      '#121212',
      card:    '#1a1a1a',
      border:  '#2e2e2e',
      text:    '#e0e0e0',
      muted:   '#888',
      yellow:  '#f5e642',
      codeBg:  '#0d0d0d',
      tabBg:   '#1e1e1e',
      tabAct:  '#2a2a2a',
      fontMono: 'monospace',
    },
    acad: {
      bg:      '#f6f6f0',
      card:    '#ffffff',
      border:  '#d4d0c8',
      text:    '#222',
      muted:   '#666',
      yellow:  '#8b6914',
      codeBg:  '#f0f0ea',
      tabBg:   '#e8e8e0',
      tabAct:  '#ffffff',
      fontMono: 'monospace',
    }
  };

  var C = COLORS[THEME] || COLORS.zine;

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildStyles() {
    return [
      '.fuxico-gist-embed{font-family:system-ui,sans-serif;background:' + C.bg + ';color:' + C.text + ';border:1px solid ' + C.border + ';border-radius:8px;overflow:hidden;margin:1em 0;font-size:14px;}',
      '.fuxico-gist-embed .fg-header{background:' + C.card + ';border-bottom:1px solid ' + C.border + ';padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}',
      '.fuxico-gist-embed .fg-title{font-weight:700;color:' + C.yellow + ';}',
      '.fuxico-gist-embed .fg-link{font-size:0.8em;color:' + C.muted + ';text-decoration:none;}',
      '.fuxico-gist-embed .fg-link:hover{color:' + C.yellow + ';}',
      '.fuxico-gist-embed .fg-tabs{display:flex;overflow-x:auto;background:' + C.tabBg + ';border-bottom:1px solid ' + C.border + ';}',
      '.fuxico-gist-embed .fg-tab{padding:7px 14px;cursor:pointer;border:none;background:transparent;color:' + C.muted + ';font-size:0.85em;white-space:nowrap;border-bottom:2px solid transparent;}',
      '.fuxico-gist-embed .fg-tab.active{background:' + C.tabAct + ';color:' + C.yellow + ';border-bottom-color:' + C.yellow + ';}',
      '.fuxico-gist-embed .fg-pane{display:none;}',
      '.fuxico-gist-embed .fg-pane.active{display:block;}',
      '.fuxico-gist-embed .fg-meta{padding:6px 14px;font-size:0.78em;color:' + C.muted + ';background:' + C.card + ';border-bottom:1px solid ' + C.border + ';}',
      '.fuxico-gist-embed pre{margin:0;padding:14px;overflow-x:auto;background:' + C.codeBg + ';}',
      '.fuxico-gist-embed code{font-family:' + C.fontMono + ';font-size:0.85em;line-height:1.6;color:' + C.text + ';}',
      '.fuxico-gist-embed .fg-footer{padding:8px 14px;font-size:0.75em;color:' + C.muted + ';background:' + C.card + ';border-top:1px solid ' + C.border + ';text-align:center;}',
      '.fuxico-gist-embed .fg-footer a{color:' + C.yellow + ';text-decoration:none;}',
    ].join('');
  }

  function buildTabs() {
    if (FILES.length <= 1) return '';
    return FILES.map(function(f, i) {
      var active = i === 0 ? ' active' : '';
      return '<button class="fg-tab' + active + '" onclick="(function(btn,id){' +
        'var w=btn.closest(\'.fuxico-gist-embed\');' +
        'w.querySelectorAll(\'.fg-tab\').forEach(function(t){t.classList.remove(\'active\')});' +
        'w.querySelectorAll(\'.fg-pane\').forEach(function(p){p.classList.remove(\'active\')});' +
        'btn.classList.add(\'active\');var p=w.querySelector(\'#\'+id);if(p)p.classList.add(\'active\');' +
        '})(this,\'fg-pane-' + i + '\')">' + esc(f.name) + '</button>';
    }).join('');
  }

  function buildPanes() {
    return FILES.map(function(f, i) {
      var active = i === 0 ? ' active' : '';
      var meta   = f.lines + ' linhas · ' + (f.size / 1024).toFixed(1) + ' KB';
      return '<div class="fg-pane' + active + '" id="fg-pane-' + i + '">' +
        '<div class="fg-meta">' + esc(f.name) + ' — ' + meta + '</div>' +
        '<pre><code>' + esc(f.content) + '</code></pre>' +
        '</div>';
    }).join('');
  }

  function buildHTML(uid) {
    var title  = FILES.length === 1 ? FILES[0].name : SLUG;
    var tabsHtml = FILES.length > 1 ? '<div class="fg-tabs">' + buildTabs() + '</div>' : '';
    return '<style>' + buildStyles() + '</style>' +
      '<div class="fuxico-gist-embed" id="' + uid + '">' +
        '<div class="fg-header">' +
          '<span class="fg-title">⚡ ' + esc(title) + '</span>' +
          (GIST_URL ? '<a class="fg-link" href="' + esc(GIST_URL) + '" target="_blank" rel="noopener">Ver no Fuxico ↗</a>' : '') +
        '</div>' +
        tabsHtml +
        buildPanes() +
        '<div class="fg-footer">Embed via <a href="https://2lp.in" target="_blank" rel="noopener">Fuxico</a></div>' +
      '</div>';
  }

  function inject() {
    var script = document.currentScript;
    if (!script) return;
    var uid = 'fuxico-embed-' + Math.random().toString(36).slice(2, 9);
    var container = document.createElement('div');
    container.innerHTML = buildHTML(uid);
    script.parentNode.insertBefore(container, script.nextSibling);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }
})();
JS;
    }

    private static function getFileIcon(string $ext): string
    {
        return match ($ext) {
            'md', 'markdown' => '📝',
            'py' => '🐍',
            'rs' => '🦀',
            'js', 'ts', 'jsx', 'tsx', 'mjs' => '📜',
            'php' => '🐘',
            'html', 'htm' => '🌐',
            'css', 'scss', 'sass', 'less' => '🎨',
            'json', 'yaml', 'yml', 'toml', 'xml' => '⚙️',
            'sh', 'bash', 'zsh', 'fish' => '💻',
            'go' => '🐹',
            'c', 'cpp', 'cc', 'h', 'hpp' => '⚡',
            'sql' => '🗄️',
            'rb' => '💎',
            'java', 'kt' => '☕',
            'lua' => '🌙',
            default => '📄',
        };
    }
}
}

/* --- Module: GitBackend.php --- */
namespace Fuxico {
class GitBackend
{
    /**
     * Check if git command-line tool is available on the system.
     */
    public static function isGitAvailable(): bool
    {
        $output = @shell_exec('git --version 2>/dev/null');

        return $output !== null && str_starts_with(trim($output), 'git version');
    }

    /**
     * Initialize an empty bare Git repository.
     */
    public static function initBareRepo(string $repoPath): bool
    {
        if (is_dir($repoPath)) {
            return true;
        }

        if (! mkdir($repoPath, 0755, true)) {
            return false;
        }

        $cmd = 'git init --bare '.escapeshellarg($repoPath).' 2>&1';
        $out = [];
        $ret = 0;
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            return false;
        }

        // Configure default branch to main and bare repository for HTTP protocol
        exec('git -C '.escapeshellarg($repoPath).' symbolic-ref HEAD refs/heads/main 2>/dev/null');
        exec('git -C '.escapeshellarg($repoPath).' config http.receivepack true');
        exec('git -C '.escapeshellarg($repoPath).' config uploadpack.allowAnySHA1InWant true');

        return true;
    }

    /**
     * Handle the smart HTTP ref discovery (GET /info/refs?service=git-upload-pack|git-receive-pack).
     */
    public static function handleInfoRefs(string $repoPath, string $service): void
    {
        if (! in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Invalid git service\n";

            return;
        }

        if (! is_dir($repoPath)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Repository not found\n";

            return;
        }

        $command = str_replace('git-', '', $service);

        header("Content-Type: application/x-{$service}-advertisement");
        header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: Fri, 01 Jan 1980 00:00:00 GMT');

        // Clean any output buffers
        if (php_sapi_name() !== 'cli') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        // Git Smart HTTP pkt-line advertisement format
        // Packet: # service=<service>\n
        $line = "# service={$service}\n";
        $len = strlen($line) + 4;
        echo sprintf('%04x%s0000', $len, $line);
        flush();

        // Run git advertise-refs
        $cmd = 'git '.escapeshellcmd($command).' --stateless-rpc --advertise-refs '.escapeshellarg($repoPath);
        $fp = popen($cmd, 'r');
        if ($fp) {
            while (! feof($fp)) {
                $buf = fread($fp, 8192);
                if ($buf !== false) {
                    echo $buf;
                    flush();
                }
            }
            pclose($fp);
        }
    }

    /**
     * Handle smart HTTP RPC (POST /git-upload-pack or /git-receive-pack).
     */
    public static function handleRpc(string $repoPath, string $service): int
    {
        if (! in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Invalid git service\n";

            return 1;
        }

        if (! is_dir($repoPath)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Repository not found\n";

            return 1;
        }

        $command = str_replace('git-', '', $service);

        header("Content-Type: application/x-{$service}-result");
        header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
        header('Pragma: no-cache');

        // Clean output buffers
        if (php_sapi_name() !== 'cli') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $cmd = 'git '.escapeshellcmd($command).' --stateless-rpc '.escapeshellarg($repoPath);
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($process)) {
            http_response_code(500);
            echo "Failed to execute git {$command}\n";

            return 1;
        }

        // Stream client body from php://input to process stdin
        $input = fopen('php://input', 'rb');
        $isGzip = strtolower($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip';
        if ($isGzip && function_exists('stream_filter_append') && $input) {
            stream_filter_append($input, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 15 + 32]);
        }

        if ($input) {
            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk !== false && strlen($chunk) > 0) {
                    fwrite($pipes[0], $chunk);
                }
            }
            fclose($input);
        }
        fclose($pipes[0]);

        // Stream process stdout directly to client output
        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && strlen($chunk) > 0) {
                echo $chunk;
                flush();
            }
        }
        fclose($pipes[1]);

        // Discard stderr
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return $exitCode;
    }

    /**
     * Retrieve repository metadata for static baking.
     *
     * @return array{
     *   has_commits: bool,
     *   default_branch: string,
     *   commit_count: int,
     *   commits: array<array{hash: string, author: string, date: string, message: string}>,
     *   files: array<array{name: string, size: int}>,
     *   readme_content: ?string,
     *   readme_filename: ?string,
     *   size: int
     * }
     */
    public static function getRepoMetadata(string $repoPath): array
    {
        $hasCommits = false;
        $defaultBranch = 'main';
        $commitCount = 0;
        $commits = [];
        $files = [];
        $readmeContent = null;
        $readmeFilename = null;

        // Check if HEAD has commits or points to an unborn branch
        $revCheck = @shell_exec('git -C '.escapeshellarg($repoPath).' rev-parse --verify HEAD 2>/dev/null');
        if ($revCheck === null || trim((string) $revCheck) === '') {
            $branchesOut = @shell_exec('git -C '.escapeshellarg($repoPath).' for-each-ref --format="%(refname:short)" refs/heads/ 2>/dev/null');
            if ($branchesOut) {
                $branches = array_values(array_filter(array_map('trim', explode("\n", (string) $branchesOut))));
                if (! empty($branches)) {
                    $chosen = in_array('main', $branches, true) ? 'main' : (in_array('master', $branches, true) ? 'master' : $branches[0]);
                    @shell_exec('git -C '.escapeshellarg($repoPath).' symbolic-ref HEAD refs/heads/'.escapeshellarg($chosen).' 2>/dev/null');
                    $revCheck = @shell_exec('git -C '.escapeshellarg($repoPath).' rev-parse --verify HEAD 2>/dev/null');
                }
            }
        }

        $headRef = trim((string) @shell_exec('git -C '.escapeshellarg($repoPath).' rev-parse --abbrev-ref HEAD 2>/dev/null'));
        if ($headRef !== '' && $headRef !== 'HEAD') {
            $defaultBranch = $headRef;
        }

        if ($revCheck !== null && trim((string) $revCheck) !== '') {
            $hasCommits = true;

            // Commit count
            $countOut = @shell_exec('git -C '.escapeshellarg($repoPath).' rev-list --count HEAD 2>/dev/null');
            if ($countOut !== null) {
                $commitCount = (int) trim($countOut);
            }

            // Commits log
            $logOut = @shell_exec('git -C '.escapeshellarg($repoPath).' log -n 15 --pretty=format:"%h|%an|%ad|%s" --date=short 2>/dev/null');
            if ($logOut) {
                $lines = explode("\n", trim($logOut));
                foreach ($lines as $line) {
                    $parts = explode('|', $line, 4);
                    if (count($parts) === 4) {
                        $commits[] = [
                            'hash' => $parts[0],
                            'author' => $parts[1],
                            'date' => $parts[2],
                            'message' => $parts[3],
                        ];
                    }
                }
            }

            // Files list with sizes
            $treeOut = @shell_exec('git -C '.escapeshellarg($repoPath).' ls-tree -r -l HEAD 2>/dev/null');
            if ($treeOut) {
                $lines = explode("\n", trim($treeOut));
                foreach ($lines as $line) {
                    if (preg_match('/^\d+\s+(\w+)\s+[a-f0-9]+\s+(\d+|-)\t(.+)$/', $line, $m)) {
                        $size = $m[2] === '-' ? 0 : (int) $m[2];
                        $path = $m[3];
                        $files[] = [
                            'name' => $path,
                            'size' => $size,
                        ];

                        // Detect README
                        if ($readmeContent === null && preg_match('/^readme(\.md|\.markdown|\.txt)?$/i', basename($path))) {
                            $readmeFilename = $path;
                        }
                    }
                }
            }

            if ($readmeFilename !== null) {
                $content = @shell_exec('git -C '.escapeshellarg($repoPath).' show HEAD:'.escapeshellarg($readmeFilename).' 2>/dev/null');
                if ($content !== null) {
                    $readmeContent = $content;
                }
            }
        }

        // Calculate repository directory size
        $totalSize = 0;
        if (is_dir($repoPath)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repoPath, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fi) {
                if ($fi->isFile()) {
                    $totalSize += $fi->getSize();
                }
            }
        }

        return [
            'has_commits' => $hasCommits,
            'default_branch' => $defaultBranch,
            'commit_count' => $commitCount,
            'commits' => $commits,
            'files' => $files,
            'readme_content' => $readmeContent,
            'readme_filename' => $readmeFilename,
            'size' => $totalSize,
        ];
    }

    /**
     * Retrieve content of a single file at HEAD.
     */
    public static function getFileContent(string $repoPath, string $filePath, string $ref = 'HEAD'): ?string
    {
        $cmd = 'git -C '.escapeshellarg($repoPath).' show '.escapeshellarg("{$ref}:{$filePath}").' 2>/dev/null';
        $content = @shell_exec($cmd);

        return $content !== null ? $content : null;
    }

    /**
     * Generate a ZIP archive binary of the repository at given ref.
     */
    public static function getArchiveZip(string $repoPath, string $ref = 'HEAD'): ?string
    {
        $cmd = 'git -C '.escapeshellarg($repoPath).' archive --format=zip '.escapeshellarg($ref).' 2>/dev/null';
        $zip = @shell_exec($cmd);

        return ($zip !== null && strlen($zip) > 0) ? $zip : null;
    }
}
}

/* --- Module: RepoRenderer.php --- */
namespace Fuxico {
class RepoRenderer
{
    /**
     * Renders a Git repository into a standalone, dual-skin HTML page.
     *
     * @param array{
     *   has_commits: bool,
     *   default_branch: string,
     *   commit_count: int,
     *   commits: array<array{hash: string, author: string, date: string, message: string}>,
     *   files: array<array{name: string, size: int}>,
     *   readme_content: ?string,
     *   readme_filename: ?string,
     *   size: int
     * } $metadata
     * @param array{
     *   title?: string,
     *   slug?: string,
     *   default_theme?: string,
     *   created_at?: int,
     *   base_url?: string
     * } $options
     */
    public static function render(array $metadata, array $options = []): string
    {
        $slug = htmlspecialchars((string) ($options['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $baseUrl = rtrim((string) ($options['base_url'] ?? ''), '/');
        $defaultTheme = ($options['default_theme'] ?? 'zine') === 'acad' ? 'acad' : 'zine';
        $createdAt = $options['created_at'] ?? time();
        $dateFormatted = date('Y-m-d H:i:s', $createdAt);

        $repoNameRaw = (string) ($options['title'] ?? '');
        if ($repoNameRaw === '' || $repoNameRaw === $slug) {
            $repoNameRaw = $slug;
        }
        $repoName = htmlspecialchars($repoNameRaw, ENT_QUOTES, 'UTF-8');

        $cloneUrl = ($baseUrl !== '' && $slug !== '') ? "{$baseUrl}/{$slug}.git" : "{$slug}.git";
        $safeCloneUrl = htmlspecialchars($cloneUrl, ENT_QUOTES, 'UTF-8');

        $zipUrl = ($baseUrl !== '' && $slug !== '') ? "{$baseUrl}/{$slug}/zip" : '#';
        $safeZipUrl = htmlspecialchars($zipUrl, ENT_QUOTES, 'UTF-8');

        $hasCommits = $metadata['has_commits'] ?? false;
        $defaultBranch = htmlspecialchars($metadata['default_branch'] ?? 'main', ENT_QUOTES, 'UTF-8');
        $commitCount = (int) ($metadata['commit_count'] ?? 0);
        $totalBytes = (int) ($metadata['size'] ?? 0);
        $sizeKb = number_format($totalBytes / 1024, 1);

        // Render Readme if available
        $readmeHtml = '';
        if ($hasCommits && ! empty($metadata['readme_content'])) {
            $readmeName = htmlspecialchars($metadata['readme_filename'] ?? 'README.md', ENT_QUOTES, 'UTF-8');
            $parsed = MarkdownRenderer::parse($metadata['readme_content']);
            $renderedBody = $parsed['html'];
            $readmeHtml = <<<HTML
            <div class="repo-card repo-readme-card">
                <div class="repo-card-header">
                    <span class="repo-card-title">📖 {$readmeName}</span>
                </div>
                <div class="repo-card-body markdown-content">
                    {$renderedBody}
                </div>
            </div>
HTML;
        }

        // Render Files Table
        $filesHtml = '';
        if ($hasCommits && ! empty($metadata['files'])) {
            $filesListHtml = '';
            foreach ($metadata['files'] as $f) {
                $filePath = $f['name'];
                $safePath = htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8');
                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                $icon = self::getFileIcon($ext);
                $fileSizeKb = number_format(($f['size'] ?? 0) / 1024, 1);
                $rawFileUrl = ($baseUrl !== '' && $slug !== '')
                    ? "{$baseUrl}/{$slug}/raw/".rawurlencode($filePath)
                    : '#';
                $safeRawFileUrl = htmlspecialchars($rawFileUrl, ENT_QUOTES, 'UTF-8');

                $filesListHtml .= <<<HTML
                <tr class="repo-file-row">
                    <td class="repo-file-name">
                        <span class="repo-file-icon">{$icon}</span>
                        <a href="{$safeRawFileUrl}" class="repo-file-link" title="Ver {$safePath}">{$safePath}</a>
                    </td>
                    <td class="repo-file-size">{$fileSizeKb} KB</td>
                    <td class="repo-file-action">
                        <a href="{$safeRawFileUrl}" class="repo-raw-btn" target="_blank" title="Arquivo raw">raw</a>
                    </td>
                </tr>
HTML;
            }

            $filesCount = count($metadata['files']);
            $filesHtml = <<<HTML
            <div class="repo-card repo-files-card">
                <div class="repo-card-header">
                    <span class="repo-card-title">📁 Arquivos ({$filesCount})</span>
                    <a href="{$safeZipUrl}" class="repo-btn repo-btn-download" download="{$slug}.zip">
                        📦 Baixar ZIP
                    </a>
                </div>
                <div class="repo-table-wrapper">
                    <table class="repo-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Tamanho</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$filesListHtml}
                        </tbody>
                    </table>
                </div>
            </div>
HTML;
        }

        // Render Commits Timeline
        $commitsHtml = '';
        if ($hasCommits && ! empty($metadata['commits'])) {
            $commitsListHtml = '';
            foreach ($metadata['commits'] as $c) {
                $safeHash = htmlspecialchars($c['hash'], ENT_QUOTES, 'UTF-8');
                $safeAuthor = htmlspecialchars($c['author'], ENT_QUOTES, 'UTF-8');
                $safeDate = htmlspecialchars($c['date'], ENT_QUOTES, 'UTF-8');
                $safeMsg = htmlspecialchars($c['message'], ENT_QUOTES, 'UTF-8');

                $commitsListHtml .= <<<HTML
                <div class="repo-commit-item">
                    <div class="repo-commit-left">
                        <span class="repo-commit-hash">{$safeHash}</span>
                        <span class="repo-commit-msg">{$safeMsg}</span>
                    </div>
                    <div class="repo-commit-right">
                        <span class="repo-commit-author">{$safeAuthor}</span>
                        <span class="repo-commit-date">{$safeDate}</span>
                    </div>
                </div>
HTML;
            }

            $commitsCount = count($metadata['commits']);
            $commitsHtml = <<<HTML
            <div class="repo-card repo-commits-card">
                <div class="repo-card-header">
                    <span class="repo-card-title">🌱 Histórico de Commits ({$commitsCount})</span>
                </div>
                <div class="repo-commits-list">
                    {$commitsListHtml}
                </div>
            </div>
HTML;
        }

        // Empty Repo State
        $emptyHtml = '';
        if (! $hasCommits) {
            $emptyHtml = <<<HTML
            <div class="repo-card repo-empty-card">
                <div class="repo-card-header">
                    <span class="repo-card-title">🚀 Repositório Vazio / Quick Setup</span>
                </div>
                <div class="repo-card-body">
                    <p class="repo-empty-desc">Este repositório Git foi criado e aguarda seu primeiro push.</p>
                    
                    <div class="repo-instructions-block">
                        <div class="repo-block-label">Criar novo repositório local e empurrar:</div>
                        <pre class="repo-code-box"><code>mkdir {$slug}
cd {$slug}
git init
echo "# {$slug}" > README.md
git add README.md
git commit -m "primeiro fuxico"
git branch -M main
git remote add origin {$cloneUrl}
git push -u origin main</code></pre>
                    </div>

                    <div class="repo-instructions-block">
                        <div class="repo-block-label">Ou empurrar repositório Git local já existente:</div>
                        <pre class="repo-code-box"><code>git remote add origin {$cloneUrl}
git branch -M main
git push -u origin main</code></pre>
                    </div>
                </div>
            </div>
HTML;
        }

        $sourceStyle = self::getStyles();

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR" data-theme="{$defaultTheme}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$repoName} · Repositório Git · Fuxico</title>
    <style>
        {$sourceStyle}
    </style>
</head>
<body class="theme-{$defaultTheme}">
    <header class="repo-header">
        <div class="repo-header-top">
            <div class="repo-identity">
                <a href="{$baseUrl}/" class="repo-back-link" title="Voltar ao início">← Fuxico</a>
                <span class="repo-badge repo-badge-git">GIT</span>
                <h1 class="repo-name">{$repoName}</h1>
                <span class="repo-branch-badge">branch: {$defaultBranch}</span>
            </div>
            <div class="repo-actions">
                <button class="repo-btn repo-theme-toggle" id="themeToggleBtn" onclick="toggleRepoTheme()">
                    <span id="themeToggleIcon">🎭</span>
                    <span id="themeToggleText">Modo Acadêmico</span>
                </button>
            </div>
        </div>

        <div class="repo-meta-bar">
            <div class="repo-meta-item">🌱 <strong>{$commitCount}</strong> commits</div>
            <div class="repo-meta-item">💾 <strong>{$sizeKb}</strong> KB</div>
            <div class="repo-meta-item">🕒 {$dateFormatted}</div>
        </div>

        <div class="repo-clone-container">
            <div class="repo-clone-wrapper">
                <span class="repo-clone-label">git clone</span>
                <input type="text" readonly value="{$safeCloneUrl}" id="cloneInput" class="repo-clone-input" onclick="this.select()">
                <button class="repo-btn repo-copy-clone-btn" onclick="copyCloneUrl()">Copiar</button>
            </div>
            <div class="repo-remote-hint">
                Dica: <code>git remote add fuxico {$safeCloneUrl}</code>
            </div>
        </div>
    </header>

    <main class="repo-main">
        {$emptyHtml}
        {$readmeHtml}
        {$filesHtml}
        {$commitsHtml}
    </main>

    <footer class="repo-footer">
        <div class="repo-footer-content">
            <p>Hospedado no <strong>Fuxico</strong> — forja efêmera & compartilhamento de arquivos sem caô.</p>
        </div>
    </footer>

    <script>
        function toggleRepoTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme') || 'zine';
            const nextTheme = currentTheme === 'zine' ? 'acad' : 'zine';
            
            html.setAttribute('data-theme', nextTheme);
            document.body.className = 'theme-' + nextTheme;
            
            const btnText = document.getElementById('themeToggleText');
            const btnIcon = document.getElementById('themeToggleIcon');
            if (btnText && btnIcon) {
                if (nextTheme === 'acad') {
                    btnText.textContent = 'Modo Zine Trava';
                    btnIcon.textContent = '✨';
                } else {
                    btnText.textContent = 'Modo Acadêmico';
                    btnIcon.textContent = '🎭';
                }
            }
            
            try {
                const url = new URL(window.location);
                if (nextTheme === 'acad') {
                    url.searchParams.set('acad', 'true');
                } else {
                    url.searchParams.delete('acad');
                }
                window.history.replaceState({}, '', url);
            } catch (e) {}
        }

        function copyCloneUrl() {
            const input = document.getElementById('cloneInput');
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(input.value).then(() => {
                const btn = document.querySelector('.repo-copy-clone-btn');
                if (btn) {
                    const original = btn.textContent;
                    btn.textContent = 'Copiado!';
                    setTimeout(() => { btn.textContent = original; }, 2000);
                }
            }).catch(() => {
                document.execCommand('copy');
            });
        }

        // Initialize URL ?acad=true override
        (function() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('acad') === 'true' || params.get('acad') === '1') {
                document.documentElement.setAttribute('data-theme', 'acad');
                document.body.className = 'theme-acad';
                const btnText = document.getElementById('themeToggleText');
                const btnIcon = document.getElementById('themeToggleIcon');
                if (btnText && btnIcon) {
                    btnText.textContent = 'Modo Zine Trava';
                    btnIcon.textContent = '✨';
                }
            }
        })();
    </script>
</body>
</html>
HTML;
    }

    public static function getFileIcon(string $ext): string
    {
        return match ($ext) {
            'php' => '🐘',
            'rs' => '🦀',
            'go' => '🐹',
            'py' => '🐍',
            'js', 'mjs', 'cjs' => '🟨',
            'ts', 'mts', 'cts' => '🔷',
            'html', 'htm' => '🌐',
            'css', 'scss', 'sass' => '🎨',
            'json' => '📋',
            'md', 'markdown' => '📝',
            'sh', 'bash', 'zsh' => '💻',
            'c', 'h', 'cpp', 'hpp' => '⚙️',
            'sql' => '🗄️',
            'yml', 'yaml', 'toml' => '⚙️',
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp' => '🖼️',
            'zip', 'tar', 'gz' => '📦',
            default => '📄',
        };
    }

    public static function getStyles(): string
    {
        return <<<'CSS'
/* Dual-Skin Styles for Fuxico Git Repository: Zine Trava (default) vs Acadêmico Depressivo */

:root {
    --zine-bg: #121113;
    --zine-surface: #1e1c22;
    --zine-surface-2: #28252e;
    --zine-border: #443e52;
    --zine-text: #f3f0f7;
    --zine-text-muted: #a39cb2;
    --zine-pink: #ff2a85;
    --zine-purple: #9d4edd;
    --zine-cyan: #05d9e8;
    --zine-emerald: #00f0b5;
    --zine-mono: "Space Mono", "JetBrains Mono", Consolas, monospace;
    --zine-sans: "Work Sans", system-ui, sans-serif;

    --acad-bg: #f7f7f7;
    --acad-surface: #ffffff;
    --acad-surface-2: #eeeeee;
    --acad-border: #cccccc;
    --acad-text: #222222;
    --acad-text-muted: #666666;
    --acad-serif: "Times New Roman", Times, "Computer Modern", Georgia, serif;
    --acad-mono: "Courier New", Courier, monospace;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: var(--zine-sans);
    background-color: var(--zine-bg);
    color: var(--zine-text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    line-height: 1.5;
}

/* =========================================
   ZINE THEME (Default)
   ========================================= */
body.theme-zine {
    background-color: var(--zine-bg);
    color: var(--zine-text);
}

.theme-zine .repo-header {
    background: linear-gradient(180deg, #18161b 0%, #121113 100%);
    border-bottom: 2px solid var(--zine-border);
    padding: 1.5rem 2rem;
}

.theme-zine .repo-badge-git {
    background: #00f0b5;
    color: #0b291e;
    font-weight: 800;
    font-family: var(--zine-mono);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    letter-spacing: 1px;
}

.theme-zine .repo-branch-badge {
    background: var(--zine-surface-2);
    color: var(--zine-cyan);
    font-family: var(--zine-mono);
    padding: 0.2rem 0.6rem;
    border-radius: 4px;
    font-size: 0.8rem;
    border: 1px solid var(--zine-border);
}

.theme-zine .repo-name {
    font-family: var(--zine-mono);
    color: #fff;
    font-size: 1.6rem;
    font-weight: 800;
    text-shadow: 0 0 10px rgba(255, 42, 133, 0.3);
}

.theme-zine .repo-back-link {
    color: var(--zine-pink);
    text-decoration: none;
    font-family: var(--zine-mono);
    font-size: 0.9rem;
    font-weight: 700;
    margin-right: 0.5rem;
}

.theme-zine .repo-back-link:hover {
    text-decoration: underline;
}

.theme-zine .repo-card {
    background: var(--zine-surface);
    border: 1px solid var(--zine-border);
    border-radius: 8px;
    margin-bottom: 1.5rem;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
}

.theme-zine .repo-card-header {
    background: var(--zine-surface-2);
    border-bottom: 1px solid var(--zine-border);
    padding: 0.8rem 1.2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.theme-zine .repo-card-title {
    font-family: var(--zine-mono);
    font-weight: 700;
    color: var(--zine-cyan);
    font-size: 0.95rem;
}

.theme-zine .repo-clone-wrapper {
    background: #0d0c0e;
    border: 1px solid var(--zine-border);
    border-radius: 6px;
    display: flex;
    align-items: center;
    overflow: hidden;
    max-width: 650px;
    margin-top: 0.8rem;
}

.theme-zine .repo-clone-label {
    background: var(--zine-surface-2);
    color: var(--zine-text-muted);
    font-family: var(--zine-mono);
    font-size: 0.8rem;
    padding: 0.5rem 0.8rem;
    border-right: 1px solid var(--zine-border);
    user-select: none;
}

.theme-zine .repo-clone-input {
    background: transparent;
    border: none;
    color: #fff;
    font-family: var(--zine-mono);
    font-size: 0.85rem;
    padding: 0.5rem 0.8rem;
    flex: 1;
    outline: none;
}

.theme-zine .repo-remote-hint {
    color: var(--zine-text-muted);
    font-size: 0.8rem;
    margin-top: 0.4rem;
}

.theme-zine .repo-remote-hint code {
    color: var(--zine-emerald);
    background: rgba(0, 240, 181, 0.1);
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-family: var(--zine-mono);
}

.theme-zine .repo-btn {
    background: var(--zine-surface-2);
    color: var(--zine-text);
    border: 1px solid var(--zine-border);
    border-radius: 6px;
    padding: 0.45rem 0.9rem;
    font-family: var(--zine-mono);
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.theme-zine .repo-btn:hover {
    background: var(--zine-pink);
    color: #fff;
    border-color: var(--zine-pink);
}

.theme-zine .repo-btn-download {
    background: rgba(0, 240, 181, 0.15);
    color: var(--zine-emerald);
    border-color: var(--zine-emerald);
}

.theme-zine .repo-btn-download:hover {
    background: var(--zine-emerald);
    color: #000;
    border-color: var(--zine-emerald);
}

.theme-zine .repo-table {
    width: 100%;
    border-collapse: collapse;
    font-family: var(--zine-mono);
    font-size: 0.85rem;
}

.theme-zine .repo-table th {
    text-align: left;
    padding: 0.6rem 1.2rem;
    color: var(--zine-text-muted);
    border-bottom: 1px solid var(--zine-border);
    font-size: 0.75rem;
    text-transform: uppercase;
}

.theme-zine .repo-table td {
    padding: 0.6rem 1.2rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.theme-zine .repo-file-row:hover td {
    background: rgba(255, 42, 133, 0.05);
}

.theme-zine .repo-file-link {
    color: #fff;
    text-decoration: none;
}

.theme-zine .repo-file-link:hover {
    color: var(--zine-cyan);
    text-decoration: underline;
}

.theme-zine .repo-raw-btn {
    color: var(--zine-text-muted);
    font-size: 0.75rem;
    text-decoration: none;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    border: 1px solid var(--zine-border);
}

.theme-zine .repo-raw-btn:hover {
    color: #fff;
    background: var(--zine-surface-2);
}

.theme-zine .repo-commit-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.7rem 1.2rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 0.85rem;
}

.theme-zine .repo-commit-item:hover {
    background: rgba(157, 78, 221, 0.08);
}

.theme-zine .repo-commit-hash {
    font-family: var(--zine-mono);
    color: var(--zine-cyan);
    background: rgba(5, 217, 232, 0.1);
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
    margin-right: 0.6rem;
    font-size: 0.8rem;
}

.theme-zine .repo-commit-msg {
    color: #fff;
    font-weight: 500;
}

.theme-zine .repo-commit-author {
    color: var(--zine-text-muted);
    margin-right: 0.8rem;
    font-size: 0.8rem;
}

.theme-zine .repo-commit-date {
    color: var(--zine-text-muted);
    font-family: var(--zine-mono);
    font-size: 0.75rem;
}

.theme-zine .repo-code-box {
    background: #0d0c0e;
    border: 1px solid var(--zine-border);
    border-radius: 6px;
    padding: 1rem;
    font-family: var(--zine-mono);
    font-size: 0.85rem;
    color: #00f0b5;
    overflow-x: auto;
    line-height: 1.6;
}

/* =========================================
   ACADEMIC DEPRESSIVE THEME (?acad=true)
   ========================================= */
body.theme-acad {
    background-color: var(--acad-bg);
    color: var(--acad-text);
    font-family: var(--acad-serif);
}

.theme-acad .repo-header {
    background: #ffffff;
    border-bottom: 2px solid #000;
    padding: 1.5rem 2rem;
}

.theme-acad .repo-badge-git {
    border: 1px solid #000;
    color: #000;
    background: transparent;
    font-family: var(--acad-mono);
    padding: 0.15rem 0.4rem;
    font-size: 0.75rem;
    border-radius: 0;
}

.theme-acad .repo-branch-badge {
    border: 1px solid #666;
    color: #333;
    background: #f0f0f0;
    font-family: var(--acad-mono);
    padding: 0.15rem 0.4rem;
    font-size: 0.75rem;
    border-radius: 0;
}

.theme-acad .repo-name {
    font-family: var(--acad-serif);
    color: #000;
    font-size: 1.8rem;
    font-weight: 700;
}

.theme-acad .repo-back-link {
    color: #000;
    text-decoration: underline;
    font-family: var(--acad-mono);
    font-size: 0.85rem;
    margin-right: 0.5rem;
}

.theme-acad .repo-card {
    background: #ffffff;
    border: 1px solid #bbb;
    border-radius: 0;
    margin-bottom: 1.5rem;
    box-shadow: none;
}

.theme-acad .repo-card-header {
    background: #e8e8e8;
    border-bottom: 1px solid #bbb;
    padding: 0.6rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.theme-acad .repo-card-title {
    font-family: var(--acad-serif);
    font-weight: 700;
    color: #000;
    font-size: 1rem;
}

.theme-acad .repo-clone-wrapper {
    background: #f9f9f9;
    border: 1px solid #999;
    border-radius: 0;
    display: flex;
    align-items: center;
    max-width: 650px;
    margin-top: 0.8rem;
}

.theme-acad .repo-clone-label {
    background: #e0e0e0;
    color: #000;
    font-family: var(--acad-mono);
    font-size: 0.8rem;
    padding: 0.4rem 0.6rem;
    border-right: 1px solid #999;
}

.theme-acad .repo-clone-input {
    background: transparent;
    border: none;
    color: #000;
    font-family: var(--acad-mono);
    font-size: 0.85rem;
    padding: 0.4rem 0.6rem;
    flex: 1;
    outline: none;
}

.theme-acad .repo-remote-hint {
    color: #555;
    font-size: 0.85rem;
    margin-top: 0.4rem;
}

.theme-acad .repo-remote-hint code {
    color: #000;
    background: #e4e4e4;
    padding: 0.15rem 0.4rem;
    border: 1px solid #ccc;
    font-family: var(--acad-mono);
}

.theme-acad .repo-btn {
    background: #f0f0f0;
    color: #000;
    border: 1px solid #888;
    border-radius: 0;
    padding: 0.35rem 0.7rem;
    font-family: var(--acad-serif);
    font-size: 0.85rem;
    cursor: pointer;
    text-decoration: none;
}

.theme-acad .repo-btn:hover {
    background: #000;
    color: #fff;
}

.theme-acad .repo-table {
    width: 100%;
    border-collapse: collapse;
    font-family: var(--acad-serif);
    font-size: 0.95rem;
}

.theme-acad .repo-table th {
    text-align: left;
    padding: 0.5rem 1rem;
    color: #000;
    border-bottom: 2px solid #000;
}

.theme-acad .repo-table td {
    padding: 0.5rem 1rem;
    border-bottom: 1px solid #e0e0e0;
}

.theme-acad .repo-file-row:hover td {
    background: #f2f2f2;
}

.theme-acad .repo-file-link {
    color: #000;
    text-decoration: underline;
}

.theme-acad .repo-raw-btn {
    color: #444;
    border: 1px solid #999;
    padding: 0.1rem 0.3rem;
    font-family: var(--acad-mono);
    font-size: 0.75rem;
    text-decoration: none;
}

.theme-acad .repo-commit-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.6rem 1rem;
    border-bottom: 1px solid #e0e0e0;
}

.theme-acad .repo-commit-hash {
    font-family: var(--acad-mono);
    border: 1px solid #999;
    padding: 0.1rem 0.3rem;
    margin-right: 0.5rem;
    font-size: 0.8rem;
}

.theme-acad .repo-commit-msg {
    color: #000;
}

.theme-acad .repo-commit-author {
    color: #666;
    margin-right: 0.5rem;
    font-size: 0.85rem;
}

.theme-acad .repo-commit-date {
    color: #666;
    font-family: var(--acad-mono);
    font-size: 0.8rem;
}

.theme-acad .repo-code-box {
    background: #ffffff;
    border: 1px solid #999;
    padding: 0.8rem;
    font-family: var(--acad-mono);
    font-size: 0.85rem;
    color: #000;
    line-height: 1.5;
}

/* =========================================
   COMMON LAYOUT
   ========================================= */
.repo-header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.repo-identity {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}

.repo-meta-bar {
    display: flex;
    gap: 1.5rem;
    margin-top: 0.8rem;
    font-size: 0.85rem;
    opacity: 0.85;
}

.repo-main {
    max-width: 1200px;
    width: 100%;
    margin: 1.5rem auto;
    padding: 0 1.5rem;
    flex: 1;
}

.repo-card-body {
    padding: 1.2rem;
}

.repo-instructions-block {
    margin-top: 1rem;
}

.repo-block-label {
    font-weight: 700;
    font-size: 0.85rem;
    margin-bottom: 0.4rem;
    opacity: 0.8;
}

.repo-footer {
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    padding: 1.5rem;
    text-align: center;
    font-size: 0.8rem;
    opacity: 0.7;
    margin-top: auto;
}

.theme-acad .repo-footer {
    border-top: 1px solid #ccc;
    background: #fafafa;
}

.markdown-content {
    line-height: 1.6;
}

.markdown-content h1, .markdown-content h2, .markdown-content h3 {
    margin-top: 1rem;
    margin-bottom: 0.5rem;
}

.markdown-content pre {
    background: rgba(0, 0, 0, 0.3);
    padding: 0.8rem;
    border-radius: 4px;
    overflow-x: auto;
    margin: 0.8rem 0;
}

.theme-acad .markdown-content pre {
    background: #f4f4f4;
    border: 1px solid #ddd;
}
CSS;
    }
}
}

/* --- Module: Views.php --- */
namespace Fuxico {
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
        $footerTravestiLink = htmlspecialchars($i18n->t('footer_travesti_link'), ENT_QUOTES, 'UTF-8');
        $tip = fn (string $key): string => '<span class="tip-trigger" tabindex="0" role="tooltip" aria-label="'.$t($key).'">?<span class="tip-bubble">'.$t($key).'</span></span>';

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
            top: -2px;
            left: 50%;
            transform: translateX(-50%) rotate(-2.5deg);
            background: rgba(226, 248, 53, 0.96);
            color: #000;
            font-family: var(--font-display);
            font-size: 0.75rem;
            letter-spacing: 2px;
            padding: 4px 34px;
            box-shadow: 3px 3px 0px rgba(0,0,0,0.6);
            z-index: 10;
            user-select: none;
            text-transform: uppercase;
            clip-path: polygon(
                0% 4px, 4px 0%, 8px 3px, 12px 0%, 100% 0%, 
                97% 6px, 100% 12px, 98% 18px, 100% 100%, 
                96% 96%, 92% 100%, 88% 97%, 0% 100%, 
                3% 82%, 0% 64%, 2% 48%, 0% 32%, 3% 16%
            );
        }

        .container {
            position: relative;
            width: 100%;
            max-width: 840px;
            background: var(--card-bg);
            border: var(--border-width) solid var(--acid-yellow);
            box-shadow: 8px 12px 0px #0a080d, 8px 12px 0px 2px var(--electric-pink);
            overflow: visible;
            display: flex;
            flex-direction: column;
            margin-top: 10px;
        }

        /* Trans Pride Stripe */
        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 25px;
            flex-shrink: 0;
            border-bottom: 2px solid #000;
        }

        .trans-stripe.bottom {
            border-top: 2px solid #000;
            border-bottom: none;
        }

        .trans-stripe-line {
            flex: 1;
            width: 100%;
        }

        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }

        /* Top Header */
        .top-bar {
            background: var(--tape-black);
            padding: 16px 24px 18px 24px;
            border-bottom: var(--border-width) solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .brand-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .brand-box {
            display: inline-flex;
            flex-direction: column;
            width: fit-content;
        }

        .brand {
            font-family: var(--font-display);
            font-size: 2.8rem;
            line-height: 0.95;
            letter-spacing: 1px;
            color: var(--acid-yellow);
            text-shadow: 3px 3px 0px var(--electric-pink);
            text-transform: uppercase;
        }

        .brand-desc {
            font-family: var(--font-heading);
            font-size: 0.81rem;
            font-weight: 700;
            color: var(--acid-yellow);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.15;
            margin-top: 5px;
            width: 100%;
        }

        .brand-desc-line {
            display: block;
            width: 100%;
            text-align: justify;
            text-align-last: justify;
        }

        /* Zine Collage Centerpiece */
        .zine-header-collage {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            flex: 1;
            margin: 0 10px;
            padding: 4px 6px;
            min-width: 250px;
            max-width: 460px;
            gap: 6px;
        }

        .zine-decor-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.85;
            z-index: 0;
        }

        .zine-halftone-svg {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0.65;
        }

        .zine-stamp-watermark {
            font-family: var(--font-display);
            font-size: 0.65rem;
            letter-spacing: 3px;
            color: rgba(255, 42, 133, 0.22);
            transform: rotate(-5deg);
            white-space: nowrap;
            user-select: none;
            border: 1px dashed rgba(255, 42, 133, 0.28);
            padding: 2px 8px;
        }

        .zine-scrap {
            position: relative;
            z-index: 1;
            display: inline-flex;
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .zine-scrap:hover {
            transform: scale(1.03) !important;
            z-index: 5;
        }

        .scrap-tagline {
            transform: rotate(-2.2deg);
            align-self: flex-start;
            margin-left: 6px;
        }

        .scrap-cultural {
            transform: rotate(1.8deg);
            align-self: flex-end;
            margin-top: -3px;
            margin-right: 6px;
        }

        /* Tape Strips */
        .tape-strip {
            position: absolute;
            width: 26px;
            height: 10px;
            background: rgba(226, 248, 53, 0.45);
            backdrop-filter: blur(1px);
            border-left: 1px dashed rgba(0,0,0,0.3);
            border-right: 1px dashed rgba(0,0,0,0.3);
            box-shadow: 1px 1px 2px rgba(0,0,0,0.4);
            z-index: 3;
            pointer-events: none;
        }

        .tape-left {
            top: -5px;
            left: -8px;
            transform: rotate(-16deg);
            background: rgba(255, 42, 133, 0.55);
        }

        .tape-right {
            bottom: -5px;
            right: -8px;
            transform: rotate(14deg);
            background: rgba(226, 248, 53, 0.5);
        }

        .badge-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 5px 12px;
            font-family: var(--font-mono);
            font-size: 0.73rem;
            font-weight: 700;
            text-align: center;
            box-sizing: border-box;
            user-select: none;
            white-space: nowrap;
        }

        .tagline-badge {
            background: var(--tape-black);
            color: var(--acid-yellow);
            border: 1.5px solid var(--border-color);
            box-shadow: 3px 3px 0px #000, 3px 3px 0px 1px var(--acid-yellow);
            clip-path: polygon(1% 0%, 100% 2%, 99% 100%, 0% 98%);
        }

        .cultural-badge {
            background: #140d1a;
            color: #5BCEFA;
            border: 1.5px solid #F5A9B8;
            box-shadow: 3px 3px 0px #000, 3px 3px 0px 1px var(--electric-pink);
            clip-path: polygon(0% 2%, 99% 0%, 100% 98%, 1% 100%);
        }

        .lang-switch {
            display: flex;
            flex-direction: column;
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
            padding: 6px 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-align: center;
        }

        .lang-btn:hover {
            color: var(--acid-yellow);
        }

        .lang-btn.active {
            background: var(--acid-yellow);
            color: #000;
            box-shadow: 2px 2px 0px #000;
        }

        @media (max-width: 680px) {
            .brand-row {
                flex-direction: column;
                align-items: center;
                gap: 12px;
            }
            .zine-header-collage {
                margin: 8px 0;
                width: 100%;
                max-width: 100%;
            }
            .scrap-tagline, .scrap-cultural {
                align-self: center;
                margin-left: 0;
                margin-right: 0;
            }
            .badge-box {
                white-space: normal;
            }
        }

        /* Token Section */
        .token-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 14px;
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
            padding: 44px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.4);
            overflow: hidden;
        }

        .dropzone-stamp {
            position: absolute;
            font-family: var(--font-display);
            font-size: 2.2rem;
            letter-spacing: 4px;
            color: rgba(255, 42, 133, 0.05);
            transform: translate(-50%, -50%) rotate(-10deg);
            top: 50%;
            left: 50%;
            pointer-events: none;
            white-space: nowrap;
            user-select: none;
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

        .dymo-tape {
            display: inline-block;
            background: #0a080d;
            color: #fff;
            font-family: var(--font-mono);
            font-weight: 700;
            font-size: 0.72rem;
            letter-spacing: 1.5px;
            padding: 2px 8px;
            border: 1px solid #383047;
            box-shadow: 2px 2px 0px #000;
            transform: rotate(-1deg);
            margin-left: 6px;
        }

        /* Options */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 16px 0;
        }

        @media (max-width: 520px) {
            .options-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Advanced Options Drawer */
        .advanced-drawer {
            margin: 18px 0;
            border: 1px dashed var(--border-color);
            background: rgba(0, 0, 0, 0.25);
            padding: 10px 14px;
            transition: all 0.2s ease;
        }

        .advanced-drawer[open] {
            border-style: solid;
            border-color: var(--acid-yellow);
            background: var(--card-surface);
            padding: 14px 16px;
        }

        .advanced-summary {
            cursor: pointer;
            font-family: var(--font-heading);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--acid-yellow);
            user-select: none;
            display: flex;
            align-items: center;
            gap: 8px;
            outline: none;
        }

        .advanced-summary:hover {
            color: var(--electric-pink);
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
            display: inline-flex;
            align-items: center;
        }

        /* Zine Tooltips */
        .tip-trigger {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: #272033;
            border: 1px solid var(--acid-yellow);
            color: var(--acid-yellow);
            font-family: var(--font-mono);
            font-size: 0.65rem;
            font-weight: 700;
            cursor: help;
            margin-left: 8px;
            vertical-align: middle;
            user-select: none;
            line-height: 1;
            box-shadow: 1px 1px 0px #000;
        }

        .tip-trigger:hover, .tip-trigger:focus {
            background: var(--electric-pink);
            border-color: #fff;
            color: #fff;
            outline: none;
            box-shadow: 2px 2px 0px #000;
        }

        .tip-bubble {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            bottom: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%);
            background: #0a080d;
            border: 1px solid var(--electric-pink);
            box-shadow: 4px 4px 0px #000, 4px 4px 0px 1px var(--acid-yellow);
            color: #f5f4ef;
            font-family: var(--font-mono);
            font-size: 0.72rem;
            font-weight: 400;
            padding: 10px 14px;
            width: 270px;
            max-width: 82vw;
            line-height: 1.45;
            z-index: 1000;
            pointer-events: none;
            transition: opacity 0.15s ease, visibility 0.15s ease;
            text-align: left;
            white-space: normal;
        }

        .tip-bubble::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 6px;
            border-style: solid;
            border-color: var(--electric-pink) transparent transparent transparent;
        }

        .tip-trigger:hover .tip-bubble,
        .tip-trigger:focus .tip-bubble {
            visibility: visible;
            opacity: 1;
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
        .badge-doc { background: #c084fc; color: #000; }
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
        <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
        <div class="duct-tape">MALÍCIA POPULAR // GOSSIP PROTOCOL</div>

        <!-- Header -->
        <header class="top-bar">
            <div class="brand-row">
                <div class="brand-box">
                    <h1 class="brand">{$t('app_name')}</h1>
                    <p class="brand-desc">
                        <span class="brand-desc-line">{$t('brand_sub_line1')}</span>
                        <span class="brand-desc-line">{$t('brand_sub_line2')}</span>
                    </p>
                </div>

                <!-- Zine Style Collage Centerpiece -->
                <div class="zine-header-collage">
                    <!-- Halftone & Trash Polka SVG Accent Backdrop -->
                    <div class="zine-decor-layer" aria-hidden="true">
                        <svg class="zine-halftone-svg" viewBox="0 0 280 60" preserveAspectRatio="none">
                            <defs>
                                <pattern id="halftone-dots" x="0" y="0" width="8" height="8" patternUnits="userSpaceOnUse">
                                    <circle cx="2" cy="2" r="1.5" fill="rgba(255, 42, 133, 0.25)" />
                                    <circle cx="6" cy="6" r="1.2" fill="rgba(226, 248, 53, 0.2)" />
                                </pattern>
                            </defs>
                            <rect x="10" y="5" width="260" height="50" fill="url(#halftone-dots)" />
                            <!-- Trash Polka Slash Marks & Crosshair -->
                            <line x1="18" y1="8" x2="262" y2="52" stroke="rgba(255, 42, 133, 0.35)" stroke-width="1.5" stroke-dasharray="6,4" />
                            <circle cx="35" cy="22" r="8" stroke="rgba(226, 248, 53, 0.3)" stroke-width="1" fill="none" />
                            <line x1="35" y1="10" x2="35" y2="34" stroke="rgba(226, 248, 53, 0.3)" stroke-width="1" />
                            <line x1="23" y1="22" x2="47" y2="22" stroke="rgba(226, 248, 53, 0.3)" stroke-width="1" />
                        </svg>
                        <span class="zine-stamp-watermark">CONFIDENCIAL // BAFÃO // 100% EFÊMERO</span>
                    </div>

                    <!-- Cutout Scrap 1: Tagline / Malícia Popular -->
                    <div class="zine-scrap scrap-tagline">
                        <span class="tape-strip tape-left"></span>
                        <span class="badge-box tagline-badge">⚡ {$t('tagline')}</span>
                    </div>

                    <!-- Cutout Scrap 2: Cultural Manifesto / Tecnologia Travesti Latina -->
                    <div class="zine-scrap scrap-cultural">
                        <span class="tape-strip tape-right"></span>
                        <span class="badge-box cultural-badge">{$t('cultural_badge')}</span>
                    </div>
                </div>

                <div class="lang-switch">
                    <button class="lang-btn {$activePt}" onclick="setLanguage('pt')">PT</button>
                    <button class="lang-btn {$activeEn}" onclick="setLanguage('en')">EN</button>
                </div>
            </div>

            <!-- Token Section -->
            <div class="token-group">
                <label class="token-label" for="authToken">
                    <span style="display:inline-flex; align-items:center;">{$t('token_label')}{$tip('tip_token')}</span>
                    <span style="color:var(--acid-yellow);">{$publicNotice}</span>
                </label>
                <input type="password" id="authToken" class="token-input" placeholder="{$t('token_placeholder')}" spellcheck="false">
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="tabs">
            <button class="tab-btn active" onclick="switchTab('uploadTab', this)">📁 {$t('tab_file')}</button>
            <button class="tab-btn" onclick="switchTab('urlTab', this)">🔗 {$t('tab_url')}</button>
        </nav>

        <!-- Content Area -->
        <main class="content-body">
            <!-- 1. File Upload Tab -->
            <section id="uploadTab" class="tab-pane active">
                <div id="dropzone" class="dropzone">
                    <span class="dropzone-stamp" aria-hidden="true">CONFIDENCIAL // 100% EFÊMERO</span>
                    <input type="file" id="fileInput" multiple style="display:none;">
                    <span class="drop-icon">📦</span>
                    <p class="drop-text" id="dropTitle">{$t('drop_idle')}</p>
                    <p class="drop-sub">{$t('drop_sub')} <span class="dymo-tape">MAX: {$maxSizeMb} MB</span></p>
                    <p id="selectedFileName" style="margin-top:14px; font-weight:700; color:var(--acid-yellow);"></p>
                </div>

                <details class="advanced-drawer" id="uploadAdvancedDrawer">
                    <summary class="advanced-summary">⚙️ {$t('advanced_options_title')}</summary>
                    <div style="margin-top:14px;">
                        <div class="options-grid">
                            <label class="option-item">
                                <input type="checkbox" id="optOneshot">
                                <span class="option-label">🔥 {$t('opt_burn')}{$tip('tip_burn')}</span>
                            </label>
                            <label class="option-item">
                                <input type="checkbox" id="optSecret">
                                <span class="option-label">🕵️ {$t('opt_secret')}{$tip('tip_secret')}</span>
                            </label>
                            <label class="option-item">
                                <input type="checkbox" id="optDoc" onchange="toggleThemeSelector()">
                                <span class="option-label">📖 {$t('opt_doc')}{$tip('tip_doc')}</span>
                            </label>
                            <label class="option-item">
                                <input type="checkbox" id="optGist">
                                <span class="option-label">📦 {$t('opt_gist')}{$tip('tip_gist')}</span>
                            </label>
                        </div>

                        <div id="uploadThemeRow" class="form-row" style="display:none;">
                            <label class="form-label" for="optTheme" style="display:inline-flex; align-items:center;">🎨 {$t('label_default_theme')}{$tip('tip_doc')}</label>
                            <select id="optTheme" class="form-control">
                                <option value="zine" selected>Zine (Punk / Trans / DIY)</option>
                                <option value="acad">Acad (Minimalista / Acadêmico)</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="optExpires" style="display:inline-flex; align-items:center;">⌛ {$t('opt_expires')}{$tip('tip_expires')}</label>
                            <select id="optExpires" class="form-control">
                                <option value="" selected>{$t('exp_none')}</option>
                                <option value="1h">{$t('exp_1h')}</option>
                                <option value="24">{$t('exp_24h')}</option>
                                <option value="7d">{$t('exp_7d')}</option>
                                <option value="30d">{$t('exp_30d')}</option>
                            </select>
                        </div>

                        <div class="form-row" style="margin-bottom:0;">
                            <label class="form-label" for="optCustomName" style="display:inline-flex; align-items:center;">🏷️ {$t('custom_name_placeholder')}{$tip('tip_custom_name')}</label>
                            <input type="text" id="optCustomName" class="form-control" placeholder="fofoca-exclusiva">
                        </div>
                    </div>
                </details>

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
                    <label class="form-label" for="shortenUrl" style="display:inline-flex; align-items:center;">🌐 {$t('url_placeholder')}{$tip('tip_shorten_url')}</label>
                    <input type="url" id="shortenUrl" class="form-control" placeholder="https://...">
                </div>

                <details class="advanced-drawer" id="urlAdvancedDrawer">
                    <summary class="advanced-summary">⚙️ {$t('advanced_options_title')}</summary>
                    <div style="margin-top:14px;">
                        <div class="form-row">
                            <label class="form-label" for="urlCustomName" style="display:inline-flex; align-items:center;">🏷️ {$t('custom_name_placeholder')}{$tip('tip_custom_name')}</label>
                            <input type="text" id="urlCustomName" class="form-control" placeholder="link-quente">
                        </div>

                        <div class="options-grid" style="margin-bottom:0;">
                            <label class="option-item">
                                <input type="checkbox" id="urlOneshot">
                                <span class="option-label">🔥 {$t('opt_burn')}{$tip('tip_burn')}</span>
                            </label>
                            <label class="option-item">
                                <input type="checkbox" id="urlSecret">
                                <span class="option-label">🕵️ {$t('opt_secret')}{$tip('tip_secret')}</span>
                            </label>
                            <label class="option-item">
                                <input type="checkbox" id="urlResenha">
                                <span class="option-label">{$t('opt_resenha')}{$tip('tip_resenha')}</span>
                            </label>
                        </div>
                    </div>
                </details>

                <button id="btnShorten" class="btn-action" onclick="handleUrlShorten()">{$t('btn_shorten')}</button>

                <div id="shortenResult" class="result-box">
                    <p class="result-title">⚡ {$t('copy_success')}</p>
                    <div class="result-url-row">
                        <input type="text" id="shortenResultUrl" class="result-input" readonly>
                        <button class="btn-copy" onclick="copyShortenUrl()">{$t('act_copy')}</button>
                    </div>
                </div>
            </section>

        </main>
        <div class="trans-stripe bottom" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
    </div>

    <footer class="footer">
        <p style="margin-bottom: 8px;">{$t('footer_credit_prefix')} <a href="{$footerTravestiLink}" target="_blank" rel="noopener noreferrer" style="color:var(--electric-pink); font-weight:700;">{$t('footer_travesti_text')}</a> :)</p>
        <p><strong>FUXICO</strong> // <a href="https://codeberg.org/lumenpink/fuxico" target="_blank" rel="noopener noreferrer">Codeberg</a> // 100% 0x0.st &amp; rustypaste drop-in protocol</p>
    </footer>

    <script>
        const I18N = {$strings};
        const IS_PUBLIC_MODE = {$publicMode};

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
        });

        // Tab Switching
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            const target = document.getElementById(tabId);
            if (target) target.classList.add('active');
        }

        // Deep linking to tabs via query param or hash
        const urlTabParam = (new URLSearchParams(window.location.search)).get('tab') || window.location.hash.replace('#', '');
        if (urlTabParam === 'url' || urlTabParam === 'urlTab') {
            const urlBtn = document.querySelectorAll('.tab-btn')[1];
            if (urlBtn) switchTab('urlTab', urlBtn);
        }

        // File Drag & Drop
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const dropTitle = document.getElementById('dropTitle');
        let selectedFiles = [];

        dropzone.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                selectFiles(Array.from(e.target.files));
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
                selectFiles(Array.from(e.dataTransfer.files));
            }
        });

        function toggleThemeSelector() {
            const optDoc = document.getElementById('optDoc');
            const themeRow = document.getElementById('uploadThemeRow');
            if (themeRow && optDoc) {
                themeRow.style.display = optDoc.checked ? 'block' : 'none';
            }
        }

        function selectFiles(files) {
            selectedFiles = files;
            if (files.length === 1) {
                const file = files[0];
                document.getElementById('selectedFileName').innerText = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                if (file.name.endsWith('.md') || file.name.endsWith('.markdown')) {
                    const optDoc = document.getElementById('optDoc');
                    if (optDoc) {
                        optDoc.checked = true;
                        toggleThemeSelector();
                    }
                }
            } else if (files.length > 1) {
                const totalBytes = files.reduce((acc, f) => acc + f.size, 0);
                document.getElementById('selectedFileName').innerText = '📦 ' + files.length + ' arquivos selecionados (' + (totalBytes / 1024).toFixed(1) + ' KB)';
                const optGist = document.getElementById('optGist');
                if (optGist) optGist.checked = true;
            }
        }

        // File Upload
        async function handleFileUpload() {
            if (selectedFiles.length === 0) {
                alert(t('drop_idle'));
                return;
            }

            const btn = document.getElementById('btnUpload');
            btn.disabled = true;
            btn.innerText = t('btn_uploading');

            const isGist = selectedFiles.length > 1 || (document.getElementById('optGist') && document.getElementById('optGist').checked);
            const fd = new FormData();

            if (isGist) {
                for (let i = 0; i < selectedFiles.length; i++) {
                    fd.append('files[]', selectedFiles[i]);
                }
                fd.append('type', 'gist');
            } else {
                fd.append('file', selectedFiles[0]);
            }

            const expires = document.getElementById('optExpires').value;
            if (expires) fd.append('expires', expires);

            if (document.getElementById('optOneshot').checked) fd.append('oneshot', 'true');
            if (document.getElementById('optSecret').checked) fd.append('secret', 'true');
            if (document.getElementById('optDoc').checked) {
                fd.append('doc', 'true');
                const optTheme = document.getElementById('optTheme');
                if (optTheme && optTheme.value) fd.append('theme', optTheme.value);
            }

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
            if (document.getElementById('urlResenha')?.checked) fd.append('resenha', 'true');

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

        function copyText(str) {
            navigator.clipboard.writeText(str).then(() => alert(t('copy_success')));
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
        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 18px;
            margin-bottom: 24px;
            border-bottom: 2px solid #000;
        }
        .trans-stripe-line {
            flex: 1;
            width: 100%;
        }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }
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
        <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
        <div class="error-code">{$statusCode}</div>
        <h1 class="error-title">{$title}</h1>
        <p class="error-desc">{$desc}</p>
        <a href="/" class="btn-home">{$back}</a>
    </div>
</body>
</html>
HTML;
    }

    public static function renderDashboardUI(Config $config, ?I18n $i18n = null): string
    {
        $i18n ??= new I18n;
        $publicMode = $config->publicMode ? 'true' : 'false';
        $lang = $i18n->getLang();
        $strings = json_encode($i18n->getAll(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $t = fn (string $key): string => htmlspecialchars($i18n->t($key), ENT_QUOTES, 'UTF-8');

        $activePt = $lang === 'pt' ? 'active' : '';
        $activeEn = $lang === 'en' ? 'active' : '';
        $footerTravestiLink = htmlspecialchars($i18n->t('footer_travesti_link'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$t('app_name')} - {$t('painel_title')}</title>
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

        .duct-tape {
            position: absolute;
            top: -2px;
            left: 50%;
            transform: translateX(-50%) rotate(-2.5deg);
            background: rgba(226, 248, 53, 0.96);
            color: #000;
            font-family: var(--font-display);
            font-size: 0.75rem;
            letter-spacing: 2px;
            padding: 4px 34px;
            box-shadow: 3px 3px 0px rgba(0,0,0,0.6);
            z-index: 10;
            user-select: none;
            text-transform: uppercase;
            clip-path: polygon(
                0% 4px, 4px 0%, 8px 3px, 12px 0%, 100% 0%, 
                97% 6px, 100% 12px, 98% 18px, 100% 100%, 
                96% 96%, 92% 100%, 88% 97%, 0% 100%, 
                3% 82%, 0% 64%, 2% 48%, 0% 32%, 3% 16%
            );
        }

        .container {
            position: relative;
            width: 100%;
            max-width: 960px;
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            box-shadow: 8px 12px 0px #0a080d, 8px 12px 0px 2px var(--electric-pink);
            padding: 34px 24px 28px 24px;
            margin-top: 14px;
            position: relative;
        }

        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 14px;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
        }

        .trans-stripe.bottom {
            margin-top: 24px;
            margin-bottom: 0;
            border-top: 2px solid #000;
            border-bottom: none;
        }

        .trans-stripe-line { flex: 1; width: 100%; }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px dashed var(--border-color);
            padding-bottom: 18px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .brand-box {
            display: flex;
            flex-direction: column;
        }

        .brand {
            font-family: var(--font-display);
            font-size: 2.8rem;
            line-height: 0.9;
            color: var(--acid-yellow);
            text-shadow: 4px 4px 0px #000, 4px 4px 0px 1px var(--electric-pink);
            letter-spacing: 2px;
        }

        .tagline {
            font-size: 0.8rem;
            color: var(--electric-pink);
            font-weight: 700;
            letter-spacing: 1px;
            margin-top: 4px;
            text-transform: uppercase;
        }

        .lang-switch {
            display: flex;
            gap: 4px;
            background: #0a080d;
            padding: 2px;
            border: 1px solid var(--border-color);
        }

        .lang-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            cursor: pointer;
        }

        .lang-btn.active {
            background: var(--acid-yellow);
            color: #000;
            box-shadow: 1px 1px 0px #000;
        }

        .btn-action-icon {
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.8rem;
            padding: 6px 12px;
            cursor: pointer;
            box-shadow: 2px 2px 0px #000;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            font-weight: 700;
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

        .btn-action {
            width: 100%;
            background: var(--acid-yellow);
            color: #000;
            border: 2px solid #000;
            box-shadow: 4px 4px 0px #000, 4px 4px 0px 2px var(--electric-pink);
            padding: 14px 20px;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1.05rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.1s ease;
            margin-top: 12px;
        }

        .btn-action:hover {
            background: var(--acid-yellow-hover);
            transform: translate(-1px, -1px);
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
            font-size: 2rem;
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
            gap: 12px;
            margin-bottom: 20px;
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
        .badge-doc { background: #c084fc; color: #000; }
        .badge-gist { background: #34d399; color: #000; }
        .badge-git { background: #f05032; color: #fff; }
        .badge-url { background: #38bdf8; color: #000; }
        .badge-oneshot { background: var(--electric-pink); color: #fff; }

        .dash-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 8px;
        }

        .dash-tab-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-family: var(--font-heading);
            font-size: 0.85rem;
            font-weight: 700;
            padding: 8px 16px;
            cursor: pointer;
            text-transform: uppercase;
            box-shadow: 2px 2px 0px #000;
        }

        .dash-tab-btn.active {
            background: var(--acid-yellow);
            color: #000;
            border-color: #000;
            box-shadow: 2px 2px 0px var(--electric-pink);
        }

        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--tape-black);
            border: 1px solid var(--acid-yellow);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.8rem;
            font-weight: 700;
            padding: 6px 14px;
            box-shadow: 2px 2px 0px #000;
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

        .modal.open { display: flex; }

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
        <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
        <div class="duct-tape">COFRE // PAINEL RESTRITO</div>

        <header class="top-bar">
            <div class="brand-box">
                <h1 class="brand">FUXICO</h1>
                <div class="tagline">{$t('painel_title')}</div>
            </div>
            <div class="lang-switch">
                <button class="lang-btn {$activePt}" onclick="setLanguage('pt')">PT</button>
                <button class="lang-btn {$activeEn}" onclick="setLanguage('en')">EN</button>
            </div>
        </header>

        <!-- 1. Login View -->
        <section id="loginView" style="display:none; max-width:480px; margin: 20px auto 40px auto; text-align:center;">
            <div style="background:var(--tape-black); border:2px solid var(--electric-pink); padding:28px 24px; box-shadow:6px 6px 0px #000;">
                <h2 style="font-family:var(--font-heading); font-size:1.4rem; color:var(--acid-yellow); margin-bottom:10px;">{$t('painel_login_title')}</h2>
                <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.5; margin-bottom:20px;">{$t('painel_login_desc')}</p>
                <div style="margin-bottom:14px;">
                    <input type="password" id="painelTokenInput" class="form-control" placeholder="{$t('token_placeholder')}" style="text-align:center;">
                </div>
                <button type="button" class="btn-action" onclick="unlockVault()">{$t('painel_login_btn')}</button>
                <div id="loginError" style="display:none; color:var(--electric-pink); margin-top:14px; font-size:0.85rem; font-weight:700;"></div>
            </div>
            <div style="margin-top:20px;">
                <a href="/" class="btn-action-icon">{$t('painel_back_home')}</a>
            </div>
        </section>

        <!-- 2. Dashboard View -->
        <section id="dashView" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <a href="/" class="btn-action-icon">{$t('painel_back_home')}</a>
                    <div id="userBadge" class="user-badge" style="display:none;"></div>
                </div>
                <button type="button" class="btn-action-icon btn-delete" onclick="logoutVault()">{$t('painel_logout_btn')}</button>
            </div>

            <!-- Dashboard Sub Tabs -->
            <div id="dashNavTabs" class="dash-tabs">
                <button id="tabBtnItems" class="dash-tab-btn active" onclick="switchDashTab('items')">{$t('tab_fofocas')}</button>
                <button id="tabBtnUsers" class="dash-tab-btn" style="display:none;" onclick="switchDashTab('users')">{$t('tab_fuxiqueiras')}</button>
            </div>

            <!-- Tab 1: Fofocas & Arquivos -->
            <div id="dashItemsSection">
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
                    <input type="text" id="dashSearch" class="form-control" style="flex:2; min-width:180px;" placeholder="{$t('dash_search')}" oninput="debounce(loadDashboard, 300)()">
                    <select id="dashType" class="form-control" style="flex:1; min-width:130px;" onchange="loadDashboard()">
                        <option value="">{$t('dash_filter_all')}</option>
                        <option value="file">{$t('dash_filter_files')}</option>
                        <option value="doc">{$t('dash_filter_docs')}</option>
                        <option value="gist">{$t('dash_filter_gists')}</option>
                        <option value="git">{$t('dash_filter_gits')}</option>
                        <option value="url">{$t('dash_filter_urls')}</option>
                    </select>
                    <select id="dashUser" class="form-control" style="flex:1; min-width:140px; display:none;" onchange="loadDashboard()">
                        <option value="">{$t('dash_filter_all_users')}</option>
                        <option value="none">{$t('dash_filter_anon_user')}</option>
                    </select>
                    <div style="display:flex; gap:6px; align-items:center; flex:1; min-width:220px;">
                        <input type="date" id="dashDateFrom" class="form-control" style="flex:1;" title="{$t('dash_date_from')}" onchange="loadDashboard()">
                        <span style="color:var(--text-muted); font-size:0.85rem;">→</span>
                        <input type="date" id="dashDateTo" class="form-control" style="flex:1;" title="{$t('dash_date_to')}" onchange="loadDashboard()">
                    </div>
                    <button type="button" class="btn-action-icon" style="padding:6px 12px; font-size:0.8rem;" title="{$t('dash_clear_filters')}" onclick="clearDashboardFilters()">✕ {$t('dash_clear_filters')}</button>
                    <button type="button" id="btnBulkDelete" class="btn-action-icon btn-delete" style="display:none; padding:6px 12px; font-size:0.8rem; border-color:var(--electric-pink); color:var(--electric-pink);" title="{$t('dash_btn_bulk_delete')}" onclick="confirmBulkDelete()">🗑️ {$t('dash_btn_bulk_delete')} (<span id="bulkDeleteCount">0</span>)</button>
                </div>

                <div style="overflow-x:auto;">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>{$t('col_slug')}</th>
                                <th id="thAuthor" style="display:none;">{$t('col_author')}</th>
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
            </div>

            <!-- Tab 2: Fuxiqueiras & Fichas (Admin Only) -->
            <div id="dashUsersSection" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                    <h3 style="font-family:var(--font-heading); font-size:1.1rem; color:var(--acid-yellow); text-transform:uppercase; letter-spacing:1px;">{$t('users_title')}</h3>
                    <button type="button" class="btn-action-icon" style="border-color:var(--acid-yellow); color:var(--acid-yellow);" onclick="openNewUserModal()">{$t('btn_new_user')}</button>
                </div>

                <div style="overflow-x:auto;">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>{$t('col_username')}</th>
                                <th>{$t('col_role')}</th>
                                <th>{$t('col_token')}</th>
                                <th>{$t('col_created')}</th>
                                <th>{$t('col_actions')}</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr>
                                <td colspan="5" style="text-align:center; color:var(--text-muted);">{$t('users_empty')}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <div class="trans-stripe bottom" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="modal" onclick="closeModal()">
        <div class="modal-card" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeModal()">×</button>
            <div id="modalBody"></div>
        </div>
    </div>

    <!-- New User Modal -->
    <div id="newUserModal" class="modal" onclick="closeNewUserModal()">
        <div class="modal-card" style="width:100%; max-width:440px;" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeNewUserModal()">×</button>
            <h3 style="font-family:var(--font-heading); font-size:1.2rem; color:var(--acid-yellow); margin-bottom:16px; text-transform:uppercase;">{$t('modal_new_user_title')}</h3>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('username_label')}</label>
                <input type="text" id="newUsernameInput" class="form-control" placeholder="{$t('username_placeholder')}">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('token_label')}</label>
                <input type="text" id="newUserTokenInput" class="form-control" placeholder="{$t('token_placeholder')}">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('role_label')}</label>
                <select id="newUserRoleSelect" class="form-control">
                    <option value="member">{$t('role_member')}</option>
                    <option value="admin">{$t('role_admin')}</option>
                </select>
            </div>
            <button type="button" class="btn-action" style="margin-top:0;" onclick="submitNewUser()">{$t('btn_create_user')}</button>
            <div id="newUserError" style="display:none; color:var(--electric-pink); margin-top:12px; font-size:0.85rem; font-weight:700;"></div>
            <div id="newUserSuccess" style="display:none; margin-top:14px; background:var(--tape-black); border:1px solid var(--acid-yellow); padding:12px;">
                <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:6px;">{$t('user_created_success')}</p>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="createdTokenDisplay" class="form-control" readonly style="font-size:0.8rem;">
                    <button type="button" class="btn-action-icon" onclick="copyCreatedToken()">{$t('act_copy')}</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Ajustar Parâmetros do Fuxico -->
    <div id="editItemModal" class="modal" onclick="closeEditItemModal()">
        <div class="modal-card" style="width:100%; max-width:520px;" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeEditItemModal()">×</button>
            <h3 style="font-family:var(--font-heading); font-size:1.2rem; color:var(--acid-yellow); margin-bottom:16px; text-transform:uppercase;">{$t('modal_edit_params_title')}</h3>
            <div id="editItemError" style="display:none; color:var(--electric-pink); margin-bottom:12px; font-size:0.85rem; font-weight:700;"></div>
            <div id="editItemSuccess" style="display:none; color:var(--acid-green); margin-bottom:12px; font-size:0.85rem; font-weight:700;"></div>

            <input type="hidden" id="editItemSlug">

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('label_orig_name')}</label>
                <input type="text" id="editItemNameInput" class="form-control">
            </div>

            <div id="editItemTargetUrlGroup" style="margin-bottom:12px; display:none;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('label_target_url')}</label>
                <input type="text" id="editItemTargetUrlInput" class="form-control">
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('label_expires')}</label>
                <select id="editItemExpiresSelect" class="form-control">
                    <option value="keep">{$t('opt_exp_keep')}</option>
                    <option value="never">{$t('opt_exp_never')}</option>
                    <option value="1h">{$t('opt_exp_1h')}</option>
                    <option value="24h">{$t('opt_exp_24h')}</option>
                    <option value="7d">{$t('opt_exp_7d')}</option>
                    <option value="30d">{$t('opt_exp_30d')}</option>
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('label_max_downloads')}</label>
                <input type="number" id="editItemMaxDownloadsInput" class="form-control" min="1" placeholder="ex: 5">
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; color:var(--text-main); cursor:pointer;">
                    <input type="checkbox" id="editItemOneshotCheckbox">
                    {$t('label_is_oneshot')}
                </label>
            </div>

            <div id="editItemTypeGroup" style="margin-bottom:12px; display:none;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('label_item_type')}</label>
                <select id="editItemTypeSelect" class="form-control" onchange="toggleModalThemeGroup()">
                    <option value="keep">{$t('opt_type_keep')}</option>
                    <option value="file">{$t('opt_type_file')}</option>
                    <option value="doc">{$t('opt_type_doc')}</option>
                </select>
            </div>

            <div id="editItemThemeGroup" style="margin-bottom:12px; display:none;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('label_default_theme')}</label>
                <select id="editItemThemeSelect" class="form-control">
                    <option value="keep">{$t('opt_theme_keep')}</option>
                    <option value="zine">Zine (Punk / Trans / DIY)</option>
                    <option value="acad">Acad (Minimalista / Acadêmico)</option>
                </select>
            </div>

            <div id="editItemRebakeGroup" style="margin-bottom:12px; display:none;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; color:var(--text-main); cursor:pointer;">
                    <input type="checkbox" id="editItemRebakeCheckbox">
                    {$t('label_rebake_diagrams')}
                </label>
            </div>

            <div id="editItemHashGroup" style="margin-bottom:16px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:4px; font-weight:700;">{$t('label_hash')}</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" id="editItemHashDisplay" class="form-control" readonly style="font-family:var(--font-mono); font-size:0.75rem;">
                    <button class="btn-action-icon" type="button" onclick="recalcItemHash()" style="white-space:nowrap;">{$t('btn_recalc_hash')}</button>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn-action-icon" onclick="closeEditItemModal()">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="submitEditItem()" style="padding:8px 16px;">{$t('btn_save_params')}</button>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p style="margin-bottom: 8px;">{$t('footer_credit_prefix')} <a href="{$footerTravestiLink}" target="_blank" rel="noopener noreferrer" style="color:var(--electric-pink); font-weight:700;">{$t('footer_travesti_text')}</a> :)</p>
        <p><strong>FUXICO</strong> // <a href="https://codeberg.org/lumenpink/fuxico" target="_blank" rel="noopener noreferrer">Codeberg</a> // 100% 0x0.st &amp; rustypaste drop-in protocol</p>
    </footer>

    <script>
        const I18N = {$strings};
        const IS_PUBLIC_MODE = {$publicMode};
        const loginView = document.getElementById('loginView');
        const dashView = document.getElementById('dashView');
        const tokenInput = document.getElementById('painelTokenInput');
        const loginError = document.getElementById('loginError');

        let currentUser = null;

        function t(k) { return I18N[k] || k; }

        function setLanguage(lang) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        async function init() {
            const savedToken = localStorage.getItem('fuxico_token') || '';
            if (savedToken) {
                tokenInput.value = savedToken;
            }

            if (IS_PUBLIC_MODE && !savedToken) {
                currentUser = { authenticated: false, role: 'guest', username: 'Visitante' };
                showDashboard();
                return;
            }

            if (savedToken) {
                try {
                    const res = await fetch('/api/me', { headers: { 'Authorization': 'Bearer ' + savedToken } });
                    if (res.ok) {
                        currentUser = await res.json();
                        showDashboard();
                        return;
                    } else {
                        showLogin();
                        loginError.innerHTML = 'A ficha salva anteriormente foi rejeitada pelo servidor. <br><a href="#" onclick="logoutVault(); return false;" style="color:var(--accent-yellow); text-decoration:underline; font-size:0.85rem; margin-top:4px; display:inline-block;">Limpar ficha salva e digitar nova</a>';
                        loginError.style.display = 'block';
                        return;
                    }
                } catch (e) {}
            }

            showLogin();
        }

        function showLogin() {
            loginView.style.display = 'block';
            dashView.style.display = 'none';
            loginError.style.display = 'none';
        }

        function showDashboard() {
            loginView.style.display = 'none';
            dashView.style.display = 'block';
            updateUserUI();
            switchDashTab('items');
        }

        function updateUserUI() {
            const userBadge = document.getElementById('userBadge');
            const tabBtnUsers = document.getElementById('tabBtnUsers');
            const thAuthor = document.getElementById('thAuthor');
            const dashUser = document.getElementById('dashUser');

            if (currentUser && currentUser.authenticated) {
                userBadge.style.display = 'inline-flex';
                const roleName = currentUser.role === 'admin' ? t('role_admin') : t('role_member');
                userBadge.innerHTML = '👤 <strong style="color:var(--acid-yellow);">' + escapeHtml(currentUser.username) + '</strong> <span class="badge" style="margin-left:4px; background:' + (currentUser.role === 'admin' ? 'var(--electric-pink); color:#fff;' : 'var(--acid-yellow); color:#000;') + '">' + escapeHtml(roleName) + '</span>';

                if (currentUser.role === 'admin') {
                    tabBtnUsers.style.display = 'inline-block';
                    thAuthor.style.display = '';
                    if (dashUser) {
                        dashUser.style.display = 'inline-block';
                        populateUserFilter();
                    }
                } else {
                    tabBtnUsers.style.display = 'none';
                    thAuthor.style.display = 'none';
                    if (dashUser) dashUser.style.display = 'none';
                }
            } else {
                userBadge.style.display = 'none';
                tabBtnUsers.style.display = 'none';
                thAuthor.style.display = 'none';
                if (dashUser) dashUser.style.display = 'none';
            }
        }

        async function populateUserFilter() {
            const dashUser = document.getElementById('dashUser');
            if (!dashUser || dashUser.dataset.loaded === 'true') return;
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            if (!token) return;

            try {
                const res = await fetch('/api/users', { headers: { 'Authorization': 'Bearer ' + token } });
                if (res.ok) {
                    const data = await res.json();
                    if (data.users && data.users.length > 0) {
                        const currentVal = dashUser.value;
                        let opts = '<option value="">' + escapeHtml(t('dash_filter_all_users')) + '</option>';
                        opts += '<option value="none">' + escapeHtml(t('dash_filter_anon_user')) + '</option>';
                        data.users.forEach(u => {
                            opts += '<option value="' + escapeHtml(u.id) + '">' + escapeHtml(u.username) + '</option>';
                        });
                        dashUser.innerHTML = opts;
                        dashUser.value = currentVal;
                        dashUser.dataset.loaded = 'true';
                    }
                }
            } catch(e) {}
        }

        function clearDashboardFilters() {
            document.getElementById('dashSearch').value = '';
            document.getElementById('dashType').value = '';
            const dashUser = document.getElementById('dashUser');
            if (dashUser) dashUser.value = '';
            const dashDateFrom = document.getElementById('dashDateFrom');
            if (dashDateFrom) dashDateFrom.value = '';
            const dashDateTo = document.getElementById('dashDateTo');
            if (dashDateTo) dashDateTo.value = '';
            loadDashboard();
        }

        function switchDashTab(tab) {
            const tabBtnItems = document.getElementById('tabBtnItems');
            const tabBtnUsers = document.getElementById('tabBtnUsers');
            const dashItemsSection = document.getElementById('dashItemsSection');
            const dashUsersSection = document.getElementById('dashUsersSection');

            if (tab === 'items') {
                tabBtnItems.classList.add('active');
                tabBtnUsers.classList.remove('active');
                dashItemsSection.style.display = 'block';
                dashUsersSection.style.display = 'none';
                loadDashboard();
            } else if (tab === 'users') {
                tabBtnUsers.classList.add('active');
                tabBtnItems.classList.remove('active');
                dashUsersSection.style.display = 'block';
                dashItemsSection.style.display = 'none';
                loadUsers();
            }
        }

        async function unlockVault() {
            const token = tokenInput.value.trim();
            loginError.style.display = 'none';

            if (!IS_PUBLIC_MODE && !token) {
                loginError.innerText = 'Token mestre obrigatório.';
                loginError.style.display = 'block';
                return;
            }

            try {
                const headers = token ? { 'Authorization': 'Bearer ' + token } : {};
                const res = await fetch('/api/me', { headers });
                if (res.ok) {
                    currentUser = await res.json();
                    if (token) localStorage.setItem('fuxico_token', token);
                    showDashboard();
                } else {
                    let errMsg = 'Acesso negado: token incorreto ou cofre trancado.';
                    try {
                        const errJson = await res.json();
                        if (errJson.message) errMsg = 'Acesso negado: ' + errJson.message;
                    } catch(e) {}
                    loginError.innerHTML = errMsg + ' <br><a href="#" onclick="logoutVault(); return false;" style="color:var(--accent-yellow); text-decoration:underline; font-size:0.85rem; margin-top:4px; display:inline-block;">Limpar ficha salva e tentar novamente</a>';
                    loginError.style.display = 'block';
                }
            } catch (err) {
                loginError.innerText = 'Erro ao verificar token: ' + err.message;
                loginError.style.display = 'block';
            }
        }

        function logoutVault() {
            localStorage.removeItem('fuxico_token');
            tokenInput.value = '';
            currentUser = null;
            showLogin();
        }

        // Enter key in token input
        tokenInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') unlockVault();
        });

        async function loadDashboard() {
            const tbody = document.getElementById('dashTableBody');
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            const isAdmin = currentUser && currentUser.role === 'admin';
            const colCount = isAdmin ? 7 : 6;

            if (!IS_PUBLIC_MODE && !token) {
                tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center; color:var(--text-muted);">' + escapeHtml(t('dash_auth_required')) + '</td></tr>';
                return;
            }

            const search = encodeURIComponent(document.getElementById('dashSearch').value.trim());
            const type = encodeURIComponent(document.getElementById('dashType').value);
            const userEl = document.getElementById('dashUser');
            const userVal = userEl && userEl.value ? encodeURIComponent(userEl.value) : '';
            const dateFromEl = document.getElementById('dashDateFrom');
            const dateFrom = dateFromEl && dateFromEl.value ? encodeURIComponent(dateFromEl.value) : '';
            const dateToEl = document.getElementById('dashDateTo');
            const dateTo = dateToEl && dateToEl.value ? encodeURIComponent(dateToEl.value) : '';
            const headers = token ? { 'Authorization': 'Bearer ' + token } : {};

            try {
                // Stats
                const statsRes = await fetch('/api/stats', { headers });
                if (statsRes.ok) {
                    const stats = await statsRes.json();
                    document.getElementById('statFiles').innerText = stats.total_files;
                    document.getElementById('statUrls').innerText = stats.total_urls;
                    document.getElementById('statBytes').innerText = (stats.total_bytes / (1024*1024)).toFixed(1) + ' MB';
                    document.getElementById('statViews').innerText = stats.total_views;
                }

                // Items
                let itemsUrl = '/api/items?search=' + search + '&type=' + type;
                if (userVal) itemsUrl += '&user_id=' + userVal;
                if (dateFrom) itemsUrl += '&date_from=' + dateFrom;
                if (dateTo) itemsUrl += '&date_to=' + dateTo;

                const res = await fetch(itemsUrl, { headers });

                const btnBulkDelete = document.getElementById('btnBulkDelete');
                const bulkDeleteCount = document.getElementById('bulkDeleteCount');

                if (!res.ok) {
                    if (btnBulkDelete) btnBulkDelete.style.display = 'none';
                    tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center; color:var(--electric-pink);">Acesso não autorizado ao cofre.</td></tr>';
                    return;
                }

                const data = await res.json();
                if (btnBulkDelete && bulkDeleteCount) {
                    if (isAdmin && data.total > 0) {
                        btnBulkDelete.style.display = 'inline-block';
                        bulkDeleteCount.innerText = data.total;
                    } else {
                        btnBulkDelete.style.display = 'none';
                    }
                }

                if (!data.items || data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center; color:var(--text-muted);">' + escapeHtml(t('dash_empty')) + '</td></tr>';
                    return;
                }

                let html = '';
                window.dashItemsCache = {};
                data.items.forEach(item => {
                    window.dashItemsCache[item.slug] = item;
                    const sizeStr = item.type === 'url' ? '-' : (item.size / 1024).toFixed(1) + ' KB';
                    let typeBadge = '';
                    if (item.type === 'file') {
                        typeBadge = '<span class="badge badge-file">' + escapeHtml(t('tab_file')) + '</span>';
                    } else if (item.type === 'doc') {
                        typeBadge = '<span class="badge badge-doc">📖 ' + escapeHtml(t('tab_doc')) + '</span>';
                    } else if (item.type === 'gist') {
                        typeBadge = '<span class="badge badge-gist">📦 ' + escapeHtml(t('tab_gist')) + '</span>';
                    } else if (item.type === 'git') {
                        typeBadge = '<span class="badge badge-git">🌱 ' + escapeHtml(t('tab_git')) + '</span>';
                    } else {
                        typeBadge = '<span class="badge badge-url">URL</span>';
                    }
                    const oneshotBadge = item.is_oneshot ? '<span class="badge badge-oneshot">🔥 Oneshot</span> ' : '';
                    const maxDlBadge = item.max_downloads ? '<span class="badge" style="background:var(--tape-black); border:1px solid var(--acid-yellow); color:var(--acid-yellow); margin-right:4px;">📥 ' + item.views + '/' + item.max_downloads + '</span> ' : '';
                    const expStr = item.expires_at ? new Date(item.expires_at * 1000).toLocaleString() : escapeHtml(t('exp_none'));

                    const openBtn = (item.type === 'file')
                        ? '<button class="btn-action-icon" onclick="previewItem(\'' + escapeHtml(item.url) + '\', \'' + escapeHtml(item.mime_type) + '\')">' + escapeHtml(t('act_view')) + '</button> '
                        : '<a class="btn-action-icon" href="' + escapeHtml(item.url) + '" target="_blank">' + escapeHtml(t('act_open')) + '</a> ';

                    const authorCol = isAdmin
                        ? '<td><small style="color:var(--text-muted);">' + escapeHtml(item.author || t('author_anon')) + '</small></td>'
                        : '';

                    html += '<tr>' +
                        '<td><strong style="color:var(--acid-yellow); font-family:var(--font-mono);">' + escapeHtml(item.slug) + '</strong><br><small style="color:var(--text-muted);">' + escapeHtml(item.original_name || '') + '</small></td>' +
                        authorCol +
                        '<td>' + typeBadge + '</td>' +
                        '<td>' + sizeStr + '</td>' +
                        '<td>' + oneshotBadge + maxDlBadge + '<small style="color:var(--text-muted);">' + expStr + '</small></td>' +
                        '<td>' + item.views + '</td>' +
                        '<td>' +
                            '<button class="btn-action-icon" onclick="copyText(\'' + escapeHtml(item.url) + '\')">' + escapeHtml(t('act_copy')) + '</button> ' +
                            openBtn +
                            '<button class="btn-action-icon" onclick="openEditItemModal(\'' + escapeHtml(item.slug) + '\')">⚙️ ' + escapeHtml(t('act_edit_params')) + '</button> ' +
                            '<button class="btn-action-icon btn-delete" onclick="deleteItem(\'' + escapeHtml(item.slug) + '\')">' + escapeHtml(t('act_delete')) + '</button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center; color:var(--electric-pink);">Erro ao carregar dados: ' + escapeHtml(err.message) + '</td></tr>';
            }
        }

        async function loadUsers() {
            const tbody = document.getElementById('usersTableBody');
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            if (!token) return;

            try {
                const res = await fetch('/api/users', { headers: { 'Authorization': 'Bearer ' + token } });
                if (!res.ok) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--electric-pink);">Erro ao carregar fuxiqueiras.</td></tr>';
                    return;
                }
                const data = await res.json();
                if (!data.users || data.users.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-muted);">' + escapeHtml(t('users_empty')) + '</td></tr>';
                    return;
                }
                let html = '';
                data.users.forEach(u => {
                    const roleLabel = u.role === 'admin' ? t('role_admin') : t('role_member');
                    const roleBadge = '<span class="badge" style="background:' + (u.role === 'admin' ? 'var(--electric-pink); color:#fff;' : 'var(--acid-yellow); color:#000;') + '">' + escapeHtml(roleLabel) + '</span>';
                    const createdStr = u.created_at ? new Date(u.created_at * 1000).toLocaleString() : '-';
                    const maskedToken = u.token ? (u.token.substring(0, 8) + '••••••••') : '-';

                    html += '<tr>' +
                        '<td><strong style="color:var(--acid-yellow); font-family:var(--font-mono);">' + escapeHtml(u.username) + '</strong></td>' +
                        '<td>' + roleBadge + '</td>' +
                        '<td><code>' + escapeHtml(maskedToken) + '</code> <button class="btn-action-icon" style="padding:2px 6px; font-size:0.7rem;" onclick="copyText(\'' + escapeHtml(u.token) + '\')">' + escapeHtml(t('act_copy')) + '</button></td>' +
                        '<td><small style="color:var(--text-muted);">' + escapeHtml(createdStr) + '</small></td>' +
                        '<td>' +
                            '<button class="btn-action-icon" onclick="regenUserToken(\'' + escapeHtml(u.id) + '\')">' + escapeHtml(t('act_regen_token')) + '</button> ' +
                            '<button class="btn-action-icon btn-delete" onclick="deleteUser(\'' + escapeHtml(u.id) + '\')">' + escapeHtml(t('act_delete')) + '</button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--electric-pink);">Erro: ' + escapeHtml(err.message) + '</td></tr>';
            }
        }

        async function regenUserToken(userId) {
            const promptMsg = t('regen_prompt') || 'Digite a nova ficha/senha (ou deixe em branco para sortear aleatória):';
            const input = prompt(promptMsg, '');
            if (input === null) return;
            const customToken = input.trim();

            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            try {
                const res = await fetch('/api/users/' + userId + '/token', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ token: customToken })
                });
                const data = await res.json();
                if (res.ok) {
                    alert(data.message + '\\n\\nFicha: ' + data.token);
                    loadUsers();
                } else {
                    alert('Erro: ' + (data.message || 'Falha ao atualizar ficha.'));
                }
            } catch (err) {
                alert('Erro: ' + err.message);
            }
        }

        async function deleteUser(userId) {
            if (!confirm(t('delete_user_confirm'))) return;
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            try {
                const res = await fetch('/api/users/' + userId, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (res.ok) {
                    loadUsers();
                } else {
                    alert('Erro: ' + (data.message || 'Falha ao remover fuxiqueira.'));
                }
            } catch (err) {
                alert('Erro: ' + err.message);
            }
        }

        function openNewUserModal() {
            document.getElementById('newUsernameInput').value = '';
            document.getElementById('newUserTokenInput').value = '';
            document.getElementById('newUserRoleSelect').value = 'member';
            document.getElementById('newUserError').style.display = 'none';
            document.getElementById('newUserSuccess').style.display = 'none';
            document.getElementById('createdTokenDisplay').value = '';
            document.getElementById('newUserModal').classList.add('open');
        }

        function closeNewUserModal() {
            document.getElementById('newUserModal').classList.remove('open');
            loadUsers();
        }

        async function submitNewUser() {
            const username = document.getElementById('newUsernameInput').value.trim();
            const role = document.getElementById('newUserRoleSelect').value;
            const customToken = document.getElementById('newUserTokenInput').value.trim();
            const errEl = document.getElementById('newUserError');
            const succEl = document.getElementById('newUserSuccess');
            const tokenDisplay = document.getElementById('createdTokenDisplay');

            errEl.style.display = 'none';
            succEl.style.display = 'none';

            if (!username) {
                errEl.innerText = 'Apelido da fuxiqueira é obrigatório.';
                errEl.style.display = 'block';
                return;
            }

            const payload = { username, role };
            if (customToken) {
                payload.token = customToken;
            }

            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            try {
                const res = await fetch('/api/users', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok) {
                    tokenDisplay.value = data.user.token;
                    succEl.style.display = 'block';
                } else {
                    errEl.innerText = data.message || 'Falha ao cadastrar fuxiqueira.';
                    errEl.style.display = 'block';
                }
            } catch (err) {
                errEl.innerText = 'Erro: ' + err.message;
                errEl.style.display = 'block';
            }
        }

        function copyCreatedToken() {
            const token = document.getElementById('createdTokenDisplay').value;
            if (token) copyText(token);
        }

        async function deleteItem(slug) {
            if (!confirm(t('delete_confirm'))) return;
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
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

        async function confirmBulkDelete() {
            if (!currentUser || currentUser.role !== 'admin') return;

            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            if (!token) return;

            const bulkDeleteCount = document.getElementById('bulkDeleteCount');
            const count = bulkDeleteCount ? bulkDeleteCount.innerText : '0';

            const confirmMsg = t('dash_bulk_confirm').replace('{count}', count);
            if (!confirm(confirmMsg)) return;

            const search = document.getElementById('dashSearch').value.trim();
            const type = document.getElementById('dashType').value;
            const userEl = document.getElementById('dashUser');
            const userId = userEl ? userEl.value : '';
            const dateFromEl = document.getElementById('dashDateFrom');
            const dateFrom = dateFromEl ? dateFromEl.value : '';
            const dateToEl = document.getElementById('dashDateTo');
            const dateTo = dateToEl ? dateToEl.value : '';

            try {
                const res = await fetch('/api/items/bulk-delete', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        search: search || null,
                        type: type || null,
                        user_id: userId || null,
                        date_from: dateFrom || null,
                        date_to: dateTo || null
                    })
                });

                const data = await res.json();
                if (res.ok && data.success) {
                    alert(t('dash_bulk_success').replace('{count}', data.count));
                    loadDashboard();
                } else {
                    alert(data.message || data.error || 'Falha ao realizar exclusão em massa.');
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

        function openEditItemModal(slug) {
            const item = (window.dashItemsCache && window.dashItemsCache[slug]) || null;
            if (!item) return;

            document.getElementById('editItemSlug').value = item.slug;
            document.getElementById('editItemNameInput').value = item.original_name || '';
            document.getElementById('editItemExpiresSelect').value = 'keep';
            document.getElementById('editItemMaxDownloadsInput').value = item.max_downloads || '';
            document.getElementById('editItemOneshotCheckbox').checked = !!item.is_oneshot;
            document.getElementById('editItemHashDisplay').value = item.hash || '';

            const targetUrlGroup = document.getElementById('editItemTargetUrlGroup');
            const targetUrlInput = document.getElementById('editItemTargetUrlInput');
            if (item.type === 'url') {
                targetUrlGroup.style.display = 'block';
                targetUrlInput.value = item.target_url || '';
            } else {
                targetUrlGroup.style.display = 'none';
                targetUrlInput.value = '';
            }

            const hashGroup = document.getElementById('editItemHashGroup');
            if (item.type === 'file' || item.type === 'doc') {
                hashGroup.style.display = 'block';
            } else {
                hashGroup.style.display = 'none';
            }

            const typeGroup = document.getElementById('editItemTypeGroup');
            const typeSelect = document.getElementById('editItemTypeSelect');
            const themeGroup = document.getElementById('editItemThemeGroup');
            const themeSelect = document.getElementById('editItemThemeSelect');
            const rebakeGroup = document.getElementById('editItemRebakeGroup');
            const rebakeCheck = document.getElementById('editItemRebakeCheckbox');
            rebakeCheck.checked = false;

            let itemTheme = 'keep';
            if (item.data) {
                try {
                    const parsed = typeof item.data === 'string' ? JSON.parse(item.data) : item.data;
                    if (parsed && parsed.theme) itemTheme = parsed.theme;
                } catch(e) {}
            }
            themeSelect.value = itemTheme;

            if (item.type === 'file' || item.type === 'doc') {
                typeGroup.style.display = 'block';
                typeSelect.value = item.type;
                themeGroup.style.display = (item.type === 'doc' || (item.original_name && item.original_name.endsWith('.md'))) ? 'block' : 'none';
                rebakeGroup.style.display = (item.type === 'doc' || (item.original_name && item.original_name.endsWith('.md'))) ? 'block' : 'none';
            } else {
                typeGroup.style.display = 'none';
                typeSelect.value = 'keep';
                themeGroup.style.display = 'none';
                rebakeGroup.style.display = 'none';
            }

            document.getElementById('editItemError').style.display = 'none';
            document.getElementById('editItemSuccess').style.display = 'none';
            document.getElementById('editItemModal').classList.add('open');
        }

        function toggleModalThemeGroup() {
            const type = document.getElementById('editItemTypeSelect').value;
            const themeGroup = document.getElementById('editItemThemeGroup');
            const rebakeGroup = document.getElementById('editItemRebakeGroup');
            if (themeGroup && rebakeGroup) {
                const isDoc = type === 'doc';
                themeGroup.style.display = isDoc ? 'block' : 'none';
                rebakeGroup.style.display = isDoc ? 'block' : 'none';
            }
        }

        function closeEditItemModal() {
            document.getElementById('editItemModal').classList.remove('open');
        }

        async function recalcItemHash() {
            const slug = document.getElementById('editItemSlug').value;
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            const errEl = document.getElementById('editItemError');
            const succEl = document.getElementById('editItemSuccess');
            errEl.style.display = 'none';
            succEl.style.display = 'none';

            try {
                const res = await fetch('/api/items/' + encodeURIComponent(slug), {
                    method: 'PATCH',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ recalculate_hash: true })
                });
                const data = await res.json();
                if (res.ok && data.item) {
                    document.getElementById('editItemHashDisplay').value = data.item.hash || '';
                    if (window.dashItemsCache) window.dashItemsCache[slug] = data.item;
                    succEl.innerText = 'Hash recalculado com sucesso!';
                    succEl.style.display = 'block';
                } else {
                    errEl.innerText = data.message || 'Erro ao recalcular hash.';
                    errEl.style.display = 'block';
                }
            } catch (err) {
                errEl.innerText = 'Erro: ' + err.message;
                errEl.style.display = 'block';
            }
        }

        async function submitEditItem() {
            const slug = document.getElementById('editItemSlug').value;
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            const errEl = document.getElementById('editItemError');
            const succEl = document.getElementById('editItemSuccess');
            errEl.style.display = 'none';
            succEl.style.display = 'none';

            const payload = {
                original_name: document.getElementById('editItemNameInput').value.trim(),
                is_oneshot: document.getElementById('editItemOneshotCheckbox').checked
            };

            const expChoice = document.getElementById('editItemExpiresSelect').value;
            if (expChoice !== 'keep') {
                payload.expires = expChoice;
            }

            const maxDl = document.getElementById('editItemMaxDownloadsInput').value.trim();
            payload.max_downloads = maxDl ? parseInt(maxDl, 10) : null;

            const typeChoice = document.getElementById('editItemTypeSelect').value;
            if (typeChoice !== 'keep') {
                payload.type = typeChoice;
            }

            const themeChoice = document.getElementById('editItemThemeSelect').value;
            if (themeChoice && themeChoice !== 'keep') {
                payload.theme = themeChoice;
            }

            if (document.getElementById('editItemRebakeCheckbox').checked) {
                payload.rebake = true;
            }

            const targetUrlGroup = document.getElementById('editItemTargetUrlGroup');
            if (targetUrlGroup.style.display !== 'none') {
                payload.target_url = document.getElementById('editItemTargetUrlInput').value.trim();
            }

            try {
                const res = await fetch('/api/items/' + encodeURIComponent(slug), {
                    method: 'PATCH',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok) {
                    succEl.innerText = t('params_saved_success') || 'Parâmetros atualizados com sucesso!';
                    succEl.style.display = 'block';
                    loadDashboard();
                    setTimeout(() => { closeEditItemModal(); }, 700);
                } else {
                    errEl.innerText = data.message || 'Erro ao atualizar parâmetros.';
                    errEl.style.display = 'block';
                }
            } catch (err) {
                errEl.innerText = 'Erro: ' + err.message;
                errEl.style.display = 'block';
            }
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

        init();
    </script>
</body>
</html>
HTML;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>|I18n  $i18n
     */
    public static function renderPreDownload(array $item, array|I18n $i18n = []): string
    {
        $slug = htmlspecialchars((string) ($item['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $filename = htmlspecialchars((string) ($item['original_name'] ?: $item['slug']), ENT_QUOTES, 'UTF-8');
        $mime = (string) ($item['mime_type'] ?: 'application/octet-stream');
        $safeMime = htmlspecialchars($mime, ENT_QUOTES, 'UTF-8');
        $source = (string) ($item['source'] ?? 'native');
        $size = (int) ($item['size_bytes'] ?? $item['size'] ?? 0);

        $formattedSize = match (true) {
            $size >= 1073741824 => number_format($size / 1073741824, 2).' GB',
            $size >= 1048576 => number_format($size / 1048576, 2).' MB',
            $size >= 1024 => number_format($size / 1024, 2).' KB',
            default => $size.' B',
        };

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isMarkdown = in_array($ext, ['md', 'markdown'], true) || $mime === 'text/markdown';
        $isImage = str_starts_with($mime, 'image/');
        $isAudio = str_starts_with($mime, 'audio/');
        $isVideo = str_starts_with($mime, 'video/');
        $isPdf = $mime === 'application/pdf' || $ext === 'pdf';

        $sourceBadge = $source === 'mocambo'
            ? '<span class="badge badge-mocambo">🏰 Mocambo Storage (Stream Seguro)</span>'
            : '<span class="badge badge-native">✨ Fuxico Nativo</span>';

        $previewHtml = '';
        if ($isImage) {
            $previewHtml = <<<HTML
            <div class="preview-box">
                <img src="/{$slug}?raw=1" alt="{$filename}" class="preview-img">
            </div>
HTML;
        } elseif ($isAudio) {
            $previewHtml = <<<HTML
            <div class="preview-box">
                <audio controls src="/{$slug}?raw=1" style="width: 100%; margin-top: 10px;"></audio>
            </div>
HTML;
        } elseif ($isVideo) {
            $previewHtml = <<<HTML
            <div class="preview-box">
                <video controls src="/{$slug}?raw=1" style="max-width: 100%; max-height: 400px; border-radius: 4px;"></video>
            </div>
HTML;
        } elseif ($isPdf) {
            $previewHtml = <<<'HTML'
            <div class="preview-box pdf-box">
                <div class="pdf-icon">📄</div>
                <div class="pdf-hint">Documento PDF pronto para download ou visualização direta.</div>
            </div>
HTML;
        }

        $zineBtnHtml = '';
        if ($isMarkdown) {
            $zineBtnHtml = <<<HTML
            <a href="/{$slug}?raw=1" class="btn btn-zine">📖 Ler como Zine / Doc</a>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$filename} - Fuxico Pré-Download</title>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Mono:wght@400;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #121016;
            --card-bg: #1a1622;
            --text-main: #f5f4ef;
            --text-muted: #9f97ad;
            --acid-yellow: #e2f835;
            --acid-yellow-hover: #ccee22;
            --electric-pink: #ff2a85;
            --shadow-hard: 6px 6px 0px #0a080d;
            --font-display: 'Anton', sans-serif;
            --font-mono: 'Space Mono', monospace;
            --font-heading: 'Space Grotesk', sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg-color);
            background-image: radial-gradient(circle, rgba(226, 248, 53, 0.05) 1.5px, transparent 1.5px);
            background-size: 20px 20px;
            color: var(--text-main);
            font-family: var(--font-mono);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .container.pre-download {
            background: var(--card-bg);
            border: 2px solid var(--electric-pink);
            box-shadow: var(--shadow-hard);
            max-width: 640px;
            width: 100%;
            padding: 32px 24px;
            text-align: center;
            position: relative;
        }
        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 12px;
            margin-bottom: 24px;
            border-bottom: 2px solid #000;
        }
        .trans-stripe-line { flex: 1; width: 100%; }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }
        .title {
            font-family: var(--font-display);
            font-size: 1.8rem;
            color: var(--acid-yellow);
            word-break: break-all;
            margin-bottom: 12px;
            text-shadow: 2px 2px 0px #000;
        }
        .meta-list {
            margin: 20px 0;
            padding: 16px;
            background: #121016;
            border: 1px dashed #383047;
            text-align: left;
            font-size: 0.9rem;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .meta-row { display: flex; justify-content: space-between; word-break: break-all; }
        .meta-label { color: var(--text-muted); }
        .meta-value { color: var(--text-main); font-weight: bold; }
        .badge {
            display: inline-block;
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 2px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-mocambo {
            background: #2a1538;
            color: #d175ff;
            border: 1px solid #ff2a85;
        }
        .badge-native {
            background: #1c2e1f;
            color: #55f385;
            border: 1px solid #55f385;
        }
        .preview-box {
            margin: 20px 0;
            padding: 12px;
            background: #0e0c12;
            border: 1px solid #2a2436;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .preview-img {
            max-width: 100%;
            max-height: 380px;
            object-fit: contain;
            border: 1px solid #383047;
        }
        .pdf-box { padding: 30px 20px; }
        .pdf-icon { font-size: 3rem; margin-bottom: 8px; }
        .pdf-hint { color: var(--text-muted); font-size: 0.85rem; }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 24px;
        }
        .btn {
            display: inline-block;
            text-decoration: none;
            font-family: var(--font-heading);
            font-size: 1.05rem;
            font-weight: bold;
            padding: 14px 24px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-transform: uppercase;
            box-shadow: 3px 3px 0px #0a080d;
        }
        .btn-download {
            background: var(--acid-yellow);
            color: #000;
            border: 2px solid #000;
        }
        .btn-download:hover {
            background: var(--acid-yellow-hover);
            transform: translate(-1px, -1px);
            box-shadow: 4px 4px 0px #0a080d;
        }
        .btn-zine {
            background: var(--electric-pink);
            color: #fff;
            border: 2px solid #000;
        }
        .btn-zine:hover {
            opacity: 0.95;
            transform: translate(-1px, -1px);
        }
        .footer-brand {
            margin-top: 28px;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .footer-brand a { color: var(--electric-pink); text-decoration: none; }
    </style>
</head>
<body class="pre-download">
    <div class="pre-download container">
        <div class="trans-stripe">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>

        <h1 class="title">{$filename}</h1>
        <div>{$sourceBadge}</div>

        {$previewHtml}

        <div class="meta-list">
            <div class="meta-row">
                <span class="meta-label">Tamanho:</span>
                <span class="meta-value">{$formattedSize}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Formato:</span>
                <span class="meta-value">{$safeMime}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Link Direto / Raw:</span>
                <span class="meta-value">/{$slug}/r</span>
            </div>
        </div>

        <div class="actions">
            {$zineBtnHtml}
            <a href="/{$slug}/r" class="btn btn-download">⚡ Baixar Arquivo Cru ({$formattedSize})</a>
        </div>

        <div class="footer-brand">
            Distribuído com amor e subversão via <a href="/">Fuxico</a>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<string, string>|I18n  $i18n
     */
    public static function renderFolderView(array $item, array $files, array|I18n $i18n = []): string
    {
        $slug = htmlspecialchars((string) ($item['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $folderName = htmlspecialchars((string) ($item['original_name'] ?: $item['slug']), ENT_QUOTES, 'UTF-8');
        $source = (string) ($item['source'] ?? 'native');
        $fileCount = count($files);

        $totalSize = 0;
        foreach ($files as $f) {
            $totalSize += (int) ($f['size'] ?? 0);
        }
        $formattedTotal = match (true) {
            $totalSize >= 1073741824 => number_format($totalSize / 1073741824, 2).' GB',
            $totalSize >= 1048576 => number_format($totalSize / 1048576, 2).' MB',
            $totalSize >= 1024 => number_format($totalSize / 1024, 2).' KB',
            default => $totalSize.' B',
        };

        $rowsHtml = '';
        foreach ($files as $f) {
            $path = htmlspecialchars((string) ($f['path'] ?? 'arquivo'), ENT_QUOTES, 'UTF-8');
            $fSize = (int) ($f['size'] ?? 0);
            $fSizeStr = match (true) {
                $fSize >= 1048576 => number_format($fSize / 1048576, 2).' MB',
                $fSize >= 1024 => number_format($fSize / 1024, 2).' KB',
                default => $fSize.' B',
            };
            $rowsHtml .= <<<HTML
            <tr>
                <td class="file-icon">📄</td>
                <td class="file-name">{$path}</td>
                <td class="file-size">{$fSizeStr}</td>
            </tr>
HTML;
        }

        $sourceBadge = $source === 'mocambo'
            ? '<span class="badge badge-mocambo">🏰 Pasta do Mocambo</span>'
            : '<span class="badge badge-native">📁 Pasta Compartilhada</span>';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$folderName} - Fuxico Pasta</title>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Mono:wght@400;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #121016;
            --card-bg: #1a1622;
            --text-main: #f5f4ef;
            --text-muted: #9f97ad;
            --acid-yellow: #e2f835;
            --acid-yellow-hover: #ccee22;
            --electric-pink: #ff2a85;
            --shadow-hard: 6px 6px 0px #0a080d;
            --font-display: 'Anton', sans-serif;
            --font-mono: 'Space Mono', monospace;
            --font-heading: 'Space Grotesk', sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg-color);
            background-image: radial-gradient(circle, rgba(226, 248, 53, 0.05) 1.5px, transparent 1.5px);
            background-size: 20px 20px;
            color: var(--text-main);
            font-family: var(--font-mono);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .container.folder-view {
            background: var(--card-bg);
            border: 2px solid var(--acid-yellow);
            box-shadow: var(--shadow-hard);
            max-width: 720px;
            width: 100%;
            padding: 32px 24px;
            position: relative;
        }
        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 12px;
            margin-bottom: 24px;
            border-bottom: 2px solid #000;
        }
        .trans-stripe-line { flex: 1; width: 100%; }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .title {
            font-family: var(--font-display);
            font-size: 2rem;
            color: var(--acid-yellow);
            text-shadow: 2px 2px 0px #000;
        }
        .badge {
            display: inline-block;
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 2px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-mocambo {
            background: #2a1538;
            color: #d175ff;
            border: 1px solid #ff2a85;
        }
        .badge-native {
            background: #1c2e1f;
            color: #55f385;
            border: 1px solid #55f385;
        }
        .folder-meta {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-bottom: 20px;
        }
        .table-wrap {
            background: #121016;
            border: 1px solid #383047;
            max-height: 420px;
            overflow-y: auto;
            margin-bottom: 24px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        th, td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid #221c2e;
        }
        th {
            background: #191422;
            color: var(--acid-yellow);
            font-family: var(--font-heading);
            text-transform: uppercase;
            font-size: 0.75rem;
        }
        .file-icon { width: 24px; text-align: center; }
        .file-name { word-break: break-all; color: var(--text-main); }
        .file-size { text-align: right; color: var(--text-muted); white-space: nowrap; }
        .actions {
            display: flex;
            justify-content: flex-end;
        }
        .btn {
            display: inline-block;
            text-decoration: none;
            font-family: var(--font-heading);
            font-size: 1.05rem;
            font-weight: bold;
            padding: 14px 24px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-transform: uppercase;
            box-shadow: 3px 3px 0px #0a080d;
        }
        .btn-download-zip {
            background: var(--electric-pink);
            color: #fff;
            border: 2px solid #000;
        }
        .btn-download-zip:hover {
            opacity: 0.92;
            transform: translate(-1px, -1px);
            box-shadow: 4px 4px 0px #0a080d;
        }
    </style>
</head>
<body>
    <div class="container folder-view">
        <div class="trans-stripe">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>

        <div class="header">
            <h1 class="title">📁 {$folderName}</h1>
            <div>{$sourceBadge}</div>
        </div>

        <div class="folder-meta">
            Contém <strong>{$fileCount}</strong> arquivo(s) &bull; Tamanho total estimado: <strong>{$formattedTotal}</strong>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th colspan="2">Nome / Caminho do Arquivo</th>
                        <th style="text-align: right;">Tamanho</th>
                    </tr>
                </thead>
                <tbody>
                    {$rowsHtml}
                </tbody>
            </table>
        </div>

        <div class="actions">
            <a href="/{$slug}/zip" class="btn btn-download-zip">📦 Baixar Pasta Completa (.zip)</a>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
}

/* --- Module: App.php --- */
namespace Fuxico {
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
                $docTheme = (isset($_POST['theme']) && strtolower((string) $_POST['theme']) === 'acad') ? 'acad' : 'zine';
                if ($rawMd !== false) {
                    $renderOptions = [
                        'title' => pathinfo($file['name'], PATHINFO_FILENAME),
                        'slug' => $slug,
                        'original_name' => $file['name'],
                        'created_at' => time(),
                        'base_url' => $this->getBaseUrl(),
                        'default_theme' => $docTheme,
                    ];
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

            $itemData = [];
            if ($itemType === 'doc' && isset($docTheme)) {
                $itemData['theme'] = $docTheme;
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
                'data' => ! empty($itemData) ? json_encode($itemData) : null,
            ]);

            http_response_code(200);
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
                        $itemData = json_decode($item['data'] ?? '{}', true) ?: [];
                        $docTheme = ($itemData['theme'] ?? 'zine') === 'acad' ? 'acad' : 'zine';
                        $baked = MarkdownRenderer::render($rawMd, [
                            'title' => pathinfo($item['original_name'] ?: $slug, PATHINFO_FILENAME),
                            'slug' => $slug,
                            'original_name' => $item['original_name'] ?: "{$slug}.md",
                            'created_at' => (int) $item['created_at'],
                            'base_url' => $this->getBaseUrl(),
                            'default_theme' => $docTheme,
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

            // 7. Markdown theme (acad | zine)
            $themeParam = null;
            if (isset($body['theme']) && in_array(strtolower((string) $body['theme']), ['acad', 'zine'], true)) {
                $themeParam = strtolower((string) $body['theme']);
                $itemData = json_decode($item['data'] ?? '{}', true) ?: [];
                $itemData['theme'] = $themeParam;
                $params['data'] = json_encode($itemData);
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
                        $docTheme = $themeParam ?? (json_decode($params['data'] ?? ($item['data'] ?? '{}'), true)['theme'] ?? 'zine');
                        $baked = MarkdownRenderer::render($rawMd, [
                            'title' => pathinfo($item['original_name'] ?: $slug, PATHINFO_FILENAME),
                            'slug' => $slug,
                            'original_name' => $item['original_name'] ?: "{$slug}.md",
                            'created_at' => (int) $item['created_at'],
                            'base_url' => $this->getBaseUrl(),
                            'default_theme' => $docTheme,
                        ]);
                        file_put_contents($this->storage->getFilePath("{$slug}.html"), $baked);
                    }
                } elseif ($newType === 'file') {
                    $htmlFile = $this->storage->getFilePath("{$slug}.html");
                    if (file_exists($htmlFile)) {
                        @unlink($htmlFile);
                    }
                }
            } elseif ((! empty($body['rebake']) || $themeParam !== null) && $item['type'] === 'doc' && ! empty($item['file_path'])) {
                $rawMd = file_get_contents($this->storage->getFilePath($item['file_path']));
                if ($rawMd !== false) {
                    $docTheme = $themeParam ?? (json_decode($params['data'] ?? ($item['data'] ?? '{}'), true)['theme'] ?? 'zine');
                    $baked = MarkdownRenderer::render($rawMd, [
                        'title' => pathinfo($item['original_name'] ?: $slug, PATHINFO_FILENAME),
                        'slug' => $slug,
                        'original_name' => $item['original_name'] ?: "{$slug}.md",
                        'created_at' => (int) $item['created_at'],
                        'base_url' => $this->getBaseUrl(),
                        'default_theme' => $docTheme,
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
                    $docTheme = $themeParam ?? (json_decode($params['data'] ?? ($item['data'] ?? '{}'), true)['theme'] ?? 'zine');
                    $baked = MarkdownRenderer::render($body['content'], [
                        'title' => pathinfo($item['original_name'] ?: $slug, PATHINFO_FILENAME),
                        'slug' => $slug,
                        'original_name' => $item['original_name'] ?: "{$slug}.md",
                        'created_at' => (int) $item['created_at'],
                        'base_url' => $this->getBaseUrl(),
                        'default_theme' => $docTheme,
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
}

/* --- Runner --- */
namespace {
    // Auto-dispatch when executed directly via web server or CLI
    if (!defined('FUXICO_NO_RUN')) {
        $isCli = (PHP_SAPI === 'cli');
        $isDirectCli = $isCli && (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));
        $isWebServer = !$isCli;

        if ($isWebServer || $isDirectCli) {
            $app = new \Fuxico\App();
            $app->handleRequest();
        }
    }
}
