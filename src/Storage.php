<?php

declare(strict_types=1);

namespace Fuxico;

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
