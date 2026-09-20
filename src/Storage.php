<?php

declare(strict_types=1);

namespace Fuxico;

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
