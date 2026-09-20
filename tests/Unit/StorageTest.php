<?php

declare(strict_types=1);

use Fuxico\Storage;

$testEnv = new class
{
    public string $baseDir = '';

    public string $dataDir = '';

    public string $filesDir = '';

    public ?Storage $storage = null;

    public function storage(): Storage
    {
        assert($this->storage !== null);

        return $this->storage;
    }
};

beforeEach(function () use ($testEnv) {
    $testEnv->baseDir = sys_get_temp_dir().'/fuxico_storage_'.uniqid();
    $testEnv->dataDir = $testEnv->baseDir.'/data';
    $testEnv->filesDir = $testEnv->dataDir.'/files';
    $testEnv->storage = new Storage($testEnv->dataDir, $testEnv->filesDir);
});

afterEach(function () use ($testEnv) {
    $deleteDir = function (string $dir) use (&$deleteDir) {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    };

    $deleteDir($testEnv->baseDir);
});

test('ensures directories and creates security protection files', function () use ($testEnv) {
    expect(is_dir($testEnv->dataDir))->toBeTrue();
    expect(is_dir($testEnv->filesDir))->toBeTrue();

    // Verify .htaccess with lockdown
    $htaccess = file_get_contents($testEnv->filesDir.'/.htaccess');
    expect($htaccess)->toContain('Require all denied');

    // Verify index.html exists
    expect(file_exists($testEnv->filesDir.'/index.html'))->toBeTrue();
});

test('stores and deletes files safely', function () use ($testEnv) {
    $tmpFile = sys_get_temp_dir().'/upload_'.uniqid().'.txt';
    file_put_contents($tmpFile, 'Sample content');

    $stored = $testEnv->storage()->storeUploadedFile($tmpFile, 'sample.txt');
    expect($stored)->toBeTrue();
    expect($testEnv->storage()->fileExists('sample.txt'))->toBeTrue();

    $content = file_get_contents($testEnv->storage()->getFilePath('sample.txt'));
    expect($content)->toBe('Sample content');

    $testEnv->storage()->deleteFile('sample.txt');
    expect($testEnv->storage()->fileExists('sample.txt'))->toBeFalse();
});
