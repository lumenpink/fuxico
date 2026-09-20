<?php

declare(strict_types=1);

$testEnv = new class
{
    public int $port = 0;

    public string $host = '';

    public int $pid = 0;

    public string $tempDir = '';

    public string $bashBin = '';

    public string $rustBin = '';

    /**
     * Helper to run CLI commands capturing stdout, stderr and exit code.
     *
     * @return array{stdout: string, stderr: string, code: int}
     */
    public function runCmd(string $cmd, ?string $stdin = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException("Failed to execute process: {$cmd}");
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'code' => $code,
        ];
    }

    public function fetch(string $url): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $content = @file_get_contents($url, false, $ctx);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (! empty($headers[0]) && preg_match('/HTTP\/\S+\s+(\d+)/', $headers[0], $m)) {
            $status = (int) $m[1];
        }

        return [
            'status' => $status,
            'body' => $content !== false ? $content : '',
            'headers' => $headers,
        ];
    }
};

beforeAll(function () use ($testEnv) {
    $rootDir = dirname(__DIR__, 2);
    $distFile = $rootDir.'/dist/fuxico.php';
    if (! file_exists($distFile)) {
        exec("php {$rootDir}/compile.php --quiet");
    }

    $testEnv->bashBin = $rootDir.'/bin/fux';
    $testEnv->rustBin = $rootDir.'/cli/target/release/picoshare-cli';

    // Ensure rust binary exists if cargo is present in environment
    if (! file_exists($testEnv->rustBin)) {
        exec('which cargo 2>&1', $cargoCheck, $cargoExit);
        if ($cargoExit === 0) {
            exec("cargo build --release --manifest-path {$rootDir}/cli/Cargo.toml > /dev/null 2>&1");
        }
    }

    $testEnv->tempDir = sys_get_temp_dir().'/fuxico_cli_suite_'.uniqid();
    mkdir($testEnv->tempDir.'/data', 0755, true);

    $testEnv->port = random_int(19000, 19999);
    $testEnv->host = "http://127.0.0.1:{$testEnv->port}";

    $cmd = sprintf(
        'FUXICO_PUBLIC_MODE=true FUXICO_AUTH_TOKENS=tatanka PICOSHARE_DATA_DIR=%s php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($testEnv->tempDir.'/data'),
        $testEnv->port,
        escapeshellarg($distFile)
    );

    $testEnv->pid = (int) trim((string) shell_exec($cmd));

    // Wait for server readiness
    $ready = false;
    for ($i = 0; $i < 25; $i++) {
        usleep(100000);
        $fp = @fsockopen('127.0.0.1', $testEnv->port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            $ready = true;
            break;
        }
    }

    if (! $ready) {
        throw new RuntimeException("Test server failed to start on port {$testEnv->port}");
    }
});

afterAll(function () use ($testEnv) {
    if ($testEnv->pid > 0) {
        exec("kill -9 {$testEnv->pid} 2>/dev/null");
    }

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
    $cleanDir($testEnv->tempDir);
});

// ============================================================================
// Bash CLI (bin/fux) Test Suite
// ============================================================================

test('bash cli uploads file: deboche on stderr, clean URL on stdout, and valid download', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/sample.txt';
    $content = 'Fuxico bash CLI test content '.uniqid();
    file_put_contents($sample, $content);

    $res = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} ".escapeshellarg($sample));

    expect($res['code'])->toBe(0);
    expect($res['stderr'])->toContain('[fux]');
    expect($res['stderr'])->toContain('espalhando o bafão');
    expect($res['stderr'])->toContain('tá na rua');

    $url = trim($res['stdout']);
    expect($url)->toStartWith("{$testEnv->host}/");

    $resp = $testEnv->fetch($url);
    expect($resp['status'])->toBe(200);
    expect($resp['body'])->toBe($content);
});

test('bash cli --quiet mode produces 100% silent stderr for script pipelines', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/sample_quiet.txt';
    file_put_contents($sample, 'quiet content');

    $res = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} --quiet ".escapeshellarg($sample));

    expect($res['code'])->toBe(0);
    expect($res['stderr'])->toBe('');
    expect(trim($res['stdout']))->toStartWith("{$testEnv->host}/");
});

test('bash cli uploads from stdin pipe when no file argument is given', function () use ($testEnv) {
    $input = 'Direct pipe fofoca from stdin '.uniqid();
    $res = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} --quiet", $input);

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);
    expect($url)->toStartWith("{$testEnv->host}/");

    $resp = $testEnv->fetch($url);
    expect($resp['status'])->toBe(200);
    expect($resp['body'])->toBe($input);
});

test('bash cli shortens URLs via -u flag', function () use ($testEnv) {
    $targetUrl = 'https://codeberg.org/lumenpink/fuxico';
    $res = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} --quiet -u ".escapeshellarg($targetUrl));

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);
    expect($url)->toStartWith("{$testEnv->host}/");

    $resp = $testEnv->fetch($url);
    expect($resp['status'])->toBeIn([301, 302]);
});

test('bash cli generates 16-character secret slug via -s flag', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/secret.txt';
    file_put_contents($sample, 'top secret intel');

    $res = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} --quiet -s ".escapeshellarg($sample));

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);
    $slug = pathinfo(basename(parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_FILENAME);
    expect(strlen($slug))->toBe(16);
});

test('bash cli supports queima-de-arquivo oneshot (-q): evaporates after 1st download', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/burn.txt';
    $content = 'burn after reading '.uniqid();
    file_put_contents($sample, $content);

    $res = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} --quiet -q ".escapeshellarg($sample));

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);

    // 1st download must succeed
    $first = $testEnv->fetch($url);
    expect($first['status'])->toBe(200);
    expect($first['body'])->toBe($content);

    // 2nd download must return 404 or 410
    $second = $testEnv->fetch($url);
    expect($second['status'])->toBeIn([404, 410]);
});

test('bash cli supports custom alias name via -n flag', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/named.txt';
    file_put_contents($sample, 'custom named content');
    $alias = 'meu_bafao_'.uniqid();

    $res = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} --quiet -n {$alias} ".escapeshellarg($sample));

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);
    expect($url)->toContain("/{$alias}");

    $resp = $testEnv->fetch($url);
    expect($resp['status'])->toBe(200);
});

test('bash cli lists active files via -l flag', function () use ($testEnv) {
    $res = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} -t tatanka -l");

    expect($res['code'])->toBe(0);
    expect($res['stdout'])->toContain('[');
    $json = json_decode($res['stdout'], true);
    expect($json)->toBeArray();
});

test('bash cli deletes fofoca via -d flag', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/to_delete.txt';
    file_put_contents($sample, 'delete me');

    $resUpload = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} --quiet ".escapeshellarg($sample));
    $url = trim($resUpload['stdout']);
    $slug = basename(parse_url($url, PHP_URL_PATH) ?? '');

    // Confirm file exists
    expect($testEnv->fetch($url)['status'])->toBe(200);

    // Delete with bash CLI
    $resDel = $testEnv->runCmd("{$testEnv->bashBin} -S {$testEnv->host} -t tatanka -d {$slug}");
    expect($resDel['code'])->toBe(0);

    // Confirm file is gone
    expect($testEnv->fetch($url)['status'])->toBe(404);
});

// ============================================================================
// Native Rust CLI (cli/target/release/picoshare-cli) Test Suite
// ============================================================================

test('rust cli uploads file: management token on stderr, clean URL on stdout, and valid download', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/rust_sample.txt';
    $content = 'Rust CLI test payload '.uniqid();
    file_put_contents($sample, $content);

    $res = $testEnv->runCmd("{$testEnv->rustBin} -S {$testEnv->host} ".escapeshellarg($sample));

    expect($res['code'])->toBe(0);
    expect($res['stderr'])->toContain('Management Token (X-Token):');

    $url = trim($res['stdout']);
    expect($url)->toStartWith("{$testEnv->host}/");

    $resp = $testEnv->fetch($url);
    expect($resp['status'])->toBe(200);
    expect($resp['body'])->toBe($content);
})->skip(fn () => ! file_exists($testEnv->rustBin), 'Rust binary not available in this environment');

test('rust cli uploads from stdin stream pipe', function () use ($testEnv) {
    $input = 'Rust stdin pipe content '.uniqid();
    $res = $testEnv->runCmd("{$testEnv->rustBin} -S {$testEnv->host}", $input);

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);
    expect($url)->toStartWith("{$testEnv->host}/");

    $resp = $testEnv->fetch($url);
    expect($resp['status'])->toBe(200);
    expect($resp['body'])->toBe($input);
})->skip(fn () => ! file_exists($testEnv->rustBin), 'Rust binary not available in this environment');

test('rust cli shortens URLs via -u flag', function () use ($testEnv) {
    $targetUrl = 'https://codeberg.org/lumenpink/fuxico';
    $res = $testEnv->runCmd("{$testEnv->rustBin} -S {$testEnv->host} -u ".escapeshellarg($targetUrl));

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);
    expect($url)->toStartWith("{$testEnv->host}/");

    $resp = $testEnv->fetch($url);
    expect($resp['status'])->toBeIn([301, 302]);
})->skip(fn () => ! file_exists($testEnv->rustBin), 'Rust binary not available in this environment');

test('rust cli generates 16-character secret slug via -s flag', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/rust_secret.txt';
    file_put_contents($sample, 'rust secret data');

    $res = $testEnv->runCmd("{$testEnv->rustBin} -S {$testEnv->host} -s ".escapeshellarg($sample));

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);
    $slug = pathinfo(basename(parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_FILENAME);
    expect(strlen($slug))->toBe(16);
})->skip(fn () => ! file_exists($testEnv->rustBin), 'Rust binary not available in this environment');

test('rust cli supports oneshot (-o): evaporates after 1st download', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/rust_oneshot.txt';
    $content = 'rust oneshot burn '.uniqid();
    file_put_contents($sample, $content);

    $res = $testEnv->runCmd("{$testEnv->rustBin} -S {$testEnv->host} -o ".escapeshellarg($sample));

    expect($res['code'])->toBe(0);
    $url = trim($res['stdout']);

    // 1st download succeeds
    $first = $testEnv->fetch($url);
    expect($first['status'])->toBe(200);
    expect($first['body'])->toBe($content);

    // 2nd download evaporates
    $second = $testEnv->fetch($url);
    expect($second['status'])->toBeIn([404, 410]);
})->skip(fn () => ! file_exists($testEnv->rustBin), 'Rust binary not available in this environment');

test('rust cli lists active items as structured JSON via -l flag', function () use ($testEnv) {
    $res = $testEnv->runCmd("{$testEnv->rustBin} -S {$testEnv->host} -t tatanka -l");

    expect($res['code'])->toBe(0);
    $data = json_decode($res['stdout'], true);
    expect($data)->toBeArray();
    if (! empty($data)) {
        expect($data[0])->toHaveKey('file_name');
        expect($data[0])->toHaveKey('url');
    }
})->skip(fn () => ! file_exists($testEnv->rustBin), 'Rust binary not available in this environment');

test('rust cli deletes item via -d flag and management token', function () use ($testEnv) {
    $sample = $testEnv->tempDir.'/rust_delete.txt';
    file_put_contents($sample, 'rust delete test');

    $uploadRes = $testEnv->runCmd("{$testEnv->rustBin} -S {$testEnv->host} ".escapeshellarg($sample));
    expect($uploadRes['code'])->toBe(0);

    // Extract x-token from stderr
    preg_match('/Management Token \(X-Token\):\s*([a-f0-9]+)/', $uploadRes['stderr'], $matches);
    expect($matches)->toHaveCount(2);
    $xToken = $matches[1];

    $url = trim($uploadRes['stdout']);
    $slug = basename(parse_url($url, PHP_URL_PATH) ?? '');

    // Confirm exists
    expect($testEnv->fetch($url)['status'])->toBe(200);

    // Delete with rust cli
    $delRes = $testEnv->runCmd("{$testEnv->rustBin} -S {$testEnv->host} -d {$slug} -t {$xToken}");
    expect($delRes['code'])->toBe(0);
    expect($delRes['stdout'])->toContain("Successfully deleted: {$slug}");

    // Confirm deleted
    expect($testEnv->fetch($url)['status'])->toBe(404);
})->skip(fn () => ! file_exists($testEnv->rustBin), 'Rust binary not available in this environment');
