<?php

declare(strict_types=1);

namespace Fuxico;

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
