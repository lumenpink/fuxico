<?php

declare(strict_types=1);

namespace PicoShare;

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
        $this->dataDir = (string) (getenv('PICOSHARE_DATA_DIR') ?: $base.'/data');
        $this->filesDir = $this->dataDir.'/files';
        $this->dbPath = $this->dataDir.'/picoshare.sqlite';

        // Public Mode: if true, anyone can upload. Dashboard & bulk list still require master token.
        $envPublic = getenv('PICOSHARE_PUBLIC_MODE');
        $this->publicMode = $envPublic !== false ? filter_var($envPublic, FILTER_VALIDATE_BOOLEAN) : false;

        // Auth Tokens
        $envTokens = getenv('PICOSHARE_AUTH_TOKENS');
        if ($envTokens !== false && trim($envTokens) !== '') {
            $this->authTokens = array_map('trim', explode(',', $envTokens));
        } else {
            $this->authTokens = ['tatanka'];
        }

        // Upload Limits & Slugs
        $this->maxUploadSize = (int) (getenv('PICOSHARE_MAX_UPLOAD_SIZE') ?: self::DEFAULT_MAX_UPLOAD_SIZE);
        $this->allowedChars = self::DEFAULT_ALLOWED_CHARS;
        $this->filenameLength = (int) (getenv('PICOSHARE_SLUG_LENGTH') ?: self::DEFAULT_FILENAME_LENGTH);
        $this->secretFilenameLength = (int) (getenv('PICOSHARE_SECRET_SLUG_LENGTH') ?: self::DEFAULT_SECRET_FILENAME_LENGTH);
        $this->baseUrl = getenv('PICOSHARE_BASE_URL') ?: null;
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
