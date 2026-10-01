<?php

declare(strict_types=1);

namespace Fuxico;

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
