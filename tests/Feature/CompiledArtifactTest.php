<?php

declare(strict_types=1);

test('compile.php generates a valid standalone single-file distribution', function () {
    $rootDir = dirname(__DIR__, 2);
    $compileScript = $rootDir.'/compile.php';
    $tempDist = sys_get_temp_dir().'/fuxico_dist_test_'.uniqid().'.php';

    // Execute compile.php with custom output
    $output = [];
    $returnCode = 0;
    exec("php {$compileScript} --output={$tempDist} --quiet", $output, $returnCode);

    expect($returnCode)->toBe(0);
    expect(file_exists($tempDist))->toBeTrue();

    // Verify php -l syntax check
    $lintOutput = [];
    $lintCode = 0;
    exec("php -l {$tempDist} 2>&1", $lintOutput, $lintCode);
    expect($lintCode)->toBe(0);

    @unlink($tempDist);
});

test('compiled artifact defines constants and aliases in isolated execution', function () {
    $rootDir = dirname(__DIR__, 2);
    $distFile = $rootDir.'/dist/fuxico.php';

    $code = escapeshellarg(<<<PHP
define('FUXICO_NO_RUN', true);
require_once '{$distFile}';
echo json_encode([
    'compiled' => defined('FUXICO_COMPILED') && FUXICO_COMPILED === true,
    'has_version' => defined('FUXICO_VERSION'),
    'has_app' => class_exists('\\PicoShare\\App'),
    'has_fuxico_app' => class_exists('\\Fuxico\\App'),
    'has_fuxico_views' => class_exists('\\Fuxico\\Views'),
    'has_fuxico_config' => class_exists('\\Fuxico\\Config'),
]);
PHP
    );

    $output = shell_exec("php -r {$code}");
    $data = json_decode((string) $output, true);

    expect($data)->toBeArray();
    expect($data['compiled'])->toBeTrue();
    expect($data['has_version'])->toBeTrue();
    expect($data['has_app'])->toBeTrue();
    expect($data['has_fuxico_app'])->toBeTrue();
    expect($data['has_fuxico_views'])->toBeTrue();
    expect($data['has_fuxico_config'])->toBeTrue();
});

test('compiled standalone fuxico.php handles HTTP requests via php built-in server', function () {
    $rootDir = dirname(__DIR__, 2);
    $distFile = $rootDir.'/dist/fuxico.php';

    $tempDir = sys_get_temp_dir().'/fuxico_compiled_test_'.uniqid();
    mkdir($tempDir, 0755, true);

    // Pick a random available port
    $port = random_int(18000, 19999);
    $host = "127.0.0.1:{$port}";

    // Start background PHP built-in server with env pointing to tempDir and public mode enabled
    $serverCmd = sprintf(
        'FUXICO_PUBLIC_MODE=true PICOSHARE_DATA_DIR=%s php -S %s %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($tempDir.'/data'),
        $host,
        escapeshellarg($distFile)
    );

    $pid = (int) trim((string) shell_exec($serverCmd));

    // Wait for server to be responsive
    $started = false;
    for ($i = 0; $i < 20; $i++) {
        usleep(100000); // 100ms
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            $started = true;
            break;
        }
    }

    try {
        expect($started)->toBeTrue();

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        // 1. GET / with Accept: text/html should return 200 with HTML title
        $htmlCtx = stream_context_create([
            'http' => [
                'header' => "Accept: text/html\r\n",
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents("http://{$host}/", false, $htmlCtx);
        expect($response)->toBeString();
        expect($response)->toContain('FUXICO');

        // 1b. GET / without Accept: text/html should return CLI banner with tagline
        $cliResponse = file_get_contents("http://{$host}/", false, $ctx);
        expect($cliResponse)->toBeString();
        expect($cliResponse)->toContain('Solta o verbo');

        // 2. POST upload via 0x0.st protocol
        $boundary = '--------------------------'.microtime(true);
        $fileContent = "Single-file standalone test upload!\n";
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"file\"; filename=\"test.txt\"\r\n"
            ."Content-Type: text/plain\r\n\r\n"
            .$fileContent."\r\n"
            ."--{$boundary}--\r\n";

        $postCtx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                    .'Content-Length: '.strlen($body)."\r\n",
                'content' => $body,
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $uploadResp = file_get_contents("http://{$host}/", false, $postCtx);
        expect($uploadResp)->toBeString();
        expect(trim($uploadResp))->toStartWith("http://{$host}/");

        // 3. Retrieve uploaded file
        $uploadedUrl = trim($uploadResp);
        $fetched = file_get_contents($uploadedUrl, false, $ctx);
        expect($fetched)->toBe($fileContent);

    } finally {
        // Kill background php server
        if ($pid > 0) {
            exec("kill -9 {$pid} 2>/dev/null");
        }

        // Clean up temp directory
        $cleanDir = function (string $dir) use (&$cleanDir): void {
            if (! is_dir($dir)) {
                return;
            }
            $items = scandir($dir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $p = $dir.'/'.$item;
                if (is_dir($p)) {
                    $cleanDir($p);
                } else {
                    @unlink($p);
                }
            }
            @rmdir($dir);
        };
        $cleanDir($tempDir);
    }
});

test('compile.php emits synchronized picoshare.php compatibility artifact', function () {
    $rootDir = dirname(__DIR__, 2);
    $picoshareDist = $rootDir.'/dist/picoshare.php';
    $picoshareRoot = $rootDir.'/picoshare.php';

    expect(file_exists($picoshareDist))->toBeTrue();
    expect(file_exists($picoshareRoot))->toBeTrue();

    // Verify syntax of picoshare.php
    $lintOutput = [];
    $lintCode = 0;
    exec("php -l {$picoshareDist} 2>&1", $lintOutput, $lintCode);
    expect($lintCode)->toBe(0);

    // Verify execution defines both PicoShare and Fuxico namespaces
    $code = escapeshellarg(<<<PHP
define('FUXICO_NO_RUN', true);
require_once '{$picoshareDist}';
echo json_encode([
    'has_app' => class_exists('\\PicoShare\\App'),
    'has_fuxico' => class_exists('\\Fuxico\\App'),
]);
PHP
    );

    $output = shell_exec("php -r {$code}");
    $data = json_decode((string) $output, true);

    expect($data)->toBeArray();
    expect($data['has_app'])->toBeTrue();
    expect($data['has_fuxico'])->toBeTrue();
});

test('scripts/build_bundles.sh produces full distribution suite with valid sha256 checksums', function () {
    $rootDir = dirname(__DIR__, 2);
    $script = $rootDir.'/scripts/build_bundles.sh';

    $output = [];
    $code = 0;
    exec("bash {$script} 2>&1", $output, $code);

    expect($code)->toBe(0);

    $distDir = $rootDir.'/dist';
    expect(file_exists($distDir.'/fuxico.php'))->toBeTrue();
    expect(file_exists($distDir.'/picoshare.php'))->toBeTrue();
    expect(file_exists($distDir.'/fux'))->toBeTrue();
    expect(file_exists($distDir.'/picoshare'))->toBeTrue();
    expect(file_exists($distDir.'/checksums.sha256'))->toBeTrue();

    // Checksums file must contain entries for all key artifacts
    $checksums = (string) file_get_contents($distDir.'/checksums.sha256');
    expect($checksums)->toContain('fuxico.php');
    expect($checksums)->toContain('picoshare.php');
    expect($checksums)->toContain('fux');
    expect($checksums)->toContain('picoshare');

    // Tarball must exist and be non-empty
    $tarballs = glob($distDir.'/fuxico-*.tar.gz');
    expect($tarballs)->not->toBeEmpty();
    expect(filesize($tarballs[0]))->toBeGreaterThan(1000);
});
