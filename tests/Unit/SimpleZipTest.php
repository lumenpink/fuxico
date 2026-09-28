<?php

declare(strict_types=1);

namespace Tests\Unit;

use Fuxico\SimpleZip;

test('creates a valid zip archive with multiple files', function () {
    $zip = new SimpleZip;
    $zip->addFile('hello.txt', 'Hello World!');
    $zip->addFile('src/main.py', "print('python')\n");
    $zip->addFile('README.md', "# Title\n\nDocs");

    $binary = $zip->build();
    expect($binary)->not->toBeEmpty();
    expect(str_starts_with($binary, "PK\x03\x04"))->toBeTrue();
    expect(str_contains($binary, 'hello.txt'))->toBeTrue();
    expect(str_contains($binary, 'src/main.py'))->toBeTrue();
    expect(str_contains($binary, 'README.md'))->toBeTrue();

    // Verify using system unzip if available
    $tmp = tempnam(sys_get_temp_dir(), 'test_zip_');
    file_put_contents($tmp, $binary);
    $out = shell_exec('unzip -t '.escapeshellarg($tmp).' 2>&1');
    @unlink($tmp);

    expect($out)->toContain('No errors detected');
});
