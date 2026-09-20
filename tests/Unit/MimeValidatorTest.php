<?php

declare(strict_types=1);

use Fuxico\MimeValidator;

$testEnv = new class
{
    public string $tempDir = '';
};

beforeEach(function () use ($testEnv) {
    $testEnv->tempDir = sys_get_temp_dir().'/fuxico_test_'.uniqid();
    mkdir($testEnv->tempDir, 0777, true);
});

afterEach(function () use ($testEnv) {
    $files = glob($testEnv->tempDir.'/*');
    if ($files !== false) {
        foreach ($files as $file) {
            unlink($file);
        }
    }
    if (is_dir($testEnv->tempDir)) {
        rmdir($testEnv->tempDir);
    }
});

test('validates clean text file correctly', function () use ($testEnv) {
    $filePath = $testEnv->tempDir.'/test.txt';
    file_put_contents($filePath, 'Hello, Fuxico!');

    $result = MimeValidator::validate($filePath, 'test.txt', 1048576);

    expect($result['valid'])->toBeTrue();
    expect($result['mime'])->toBe('text/plain');
    expect($result['error'])->toBeNull();
});

test('rejects dangerous extensions even if content is plain text', function () use ($testEnv) {
    $filePath = $testEnv->tempDir.'/script.php';
    file_put_contents($filePath, '<?php echo "evil"; ?>');

    $result = MimeValidator::validate($filePath, 'script.php', 1048576);

    expect($result['valid'])->toBeFalse();
    expect($result['error'])->toContain('Disallowed file extension: .php');
});

test('rejects fake extension spoofing (plain text disguised as image)', function () use ($testEnv) {
    $filePath = $testEnv->tempDir.'/fake.png';
    file_put_contents($filePath, 'This is definitely not a PNG file header');

    $result = MimeValidator::validate($filePath, 'fake.png', 1048576);

    expect($result['valid'])->toBeFalse();
    expect($result['error'])->toContain('MIME type mismatch for .png');
});

test('accepts valid PNG image with PNG magic bytes', function () use ($testEnv) {
    $filePath = $testEnv->tempDir.'/valid.png';
    // 1x1 transparent PNG bytes
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    file_put_contents($filePath, $pngBytes);

    $result = MimeValidator::validate($filePath, 'valid.png', 1048576);

    expect($result['valid'])->toBeTrue();
    expect($result['mime'])->toBe('image/png');
});

test('exempts .gpg encrypted binary data as legitimate crypto gibberish', function () use ($testEnv) {
    $filePath = $testEnv->tempDir.'/backup.gpg';
    // Arbitrary binary encrypted payload
    $randomCryptoData = random_bytes(256);
    file_put_contents($filePath, $randomCryptoData);

    $result = MimeValidator::validate($filePath, 'backup.gpg', 1048576);

    expect($result['valid'])->toBeTrue();
    expect($result['error'])->toBeNull();
});

test('exempts .asc armored PGP data', function () use ($testEnv) {
    $filePath = $testEnv->tempDir.'/key.asc';
    $armoredData = "-----BEGIN PGP PUBLIC KEY BLOCK-----\nVersion: Test\n\nmQENBF...\n-----END PGP PUBLIC KEY BLOCK-----";
    file_put_contents($filePath, $armoredData);

    $result = MimeValidator::validate($filePath, 'key.asc', 1048576);

    expect($result['valid'])->toBeTrue();
    expect($result['error'])->toBeNull();
});
