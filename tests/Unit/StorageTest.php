<?php

declare(strict_types=1);

use Fuxico\Storage;

beforeEach(function () {
    $this->baseDir = sys_get_temp_dir().'/fuxico_storage_'.uniqid();
    $this->dataDir = $this->baseDir.'/data';
    $this->filesDir = $this->dataDir.'/files';
    $this->storage = new Storage($this->dataDir, $this->filesDir);
});

afterEach(function () {
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

    $deleteDir($this->baseDir);
});

test('ensures directories and creates security protection files', function () {
    expect(is_dir($this->dataDir))->toBeTrue();
    expect(is_dir($this->filesDir))->toBeTrue();

    // Verify .htaccess with lockdown
    $htaccess = file_get_contents($this->filesDir.'/.htaccess');
    expect($htaccess)->toContain('Require all denied');

    // Verify index.html exists
    expect(file_exists($this->filesDir.'/index.html'))->toBeTrue();
});

test('stores and deletes files safely', function () {
    $tmpFile = sys_get_temp_dir().'/upload_'.uniqid().'.txt';
    file_put_contents($tmpFile, 'Sample content');

    $stored = $this->storage->storeUploadedFile($tmpFile, 'sample.txt');
    expect($stored)->toBeTrue();
    expect($this->storage->fileExists('sample.txt'))->toBeTrue();

    $content = file_get_contents($this->storage->getFilePath('sample.txt'));
    expect($content)->toBe('Sample content');

    $this->storage->deleteFile('sample.txt');
    expect($this->storage->fileExists('sample.txt'))->toBeFalse();
});
