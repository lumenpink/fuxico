<?php

declare(strict_types=1);

$thirdPartyEnv = new class
{
    public int $port = 0;

    public string $host = '';

    public int $pid = 0;

    public string $tempDir = '';

    public string $clientsDir = '';

    public string $rpasteBin = '';

    public string $andyBin = '';

    public string $olavBin = '';

    /**
     * Helper to run CLI commands capturing stdout, stderr and exit code.
     *
     * @param  array<string, string>  $env
     * @return array{stdout: string, stderr: string, code: int}
     */
    public function runCmd(string $cmd, ?string $stdin = null, array $env = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $processEnv = array_merge($_ENV, getenv(), $env);

        $process = proc_open($cmd, $descriptors, $pipes, null, $processEnv);
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

    /**
     * Helper to perform HTTP GET requests to the test server.
     *
     * @return array{status: int, body: string, headers: list<string>}
     */
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

beforeAll(function () use ($thirdPartyEnv) {
    $rootDir = dirname(__DIR__, 2);
    $distFile = $rootDir.'/dist/fuxico.php';

    // Compile distribution artifact if missing
    if (! file_exists($distFile)) {
        exec("php {$rootDir}/compile.php --quiet");
    }

    $thirdPartyEnv->tempDir = sys_get_temp_dir().'/fuxico_thirdparty_suite_'.uniqid();
    mkdir($thirdPartyEnv->tempDir.'/data', 0755, true);
    mkdir($thirdPartyEnv->tempDir.'/config', 0755, true);
    mkdir($thirdPartyEnv->tempDir.'/data_home', 0755, true);

    $thirdPartyEnv->clientsDir = $rootDir.'/bin/.clients';

    // Always fetch latest external clients as requested; must fail if download fails
    $fetchScript = $rootDir.'/scripts/fetch_external_clients.sh';
    exec("bash {$fetchScript} {$thirdPartyEnv->clientsDir} 2>&1", $fetchOutput, $fetchCode);
    if ($fetchCode !== 0) {
        throw new RuntimeException("Failed to fetch external clients (exit code {$fetchCode}):\n".implode("\n", $fetchOutput));
    }

    $thirdPartyEnv->rpasteBin = $thirdPartyEnv->clientsDir.'/rpaste';
    $thirdPartyEnv->andyBin = $thirdPartyEnv->clientsDir.'/0x0-andy';
    $thirdPartyEnv->olavBin = $thirdPartyEnv->clientsDir.'/0x0-olav';

    foreach (['rpaste' => $thirdPartyEnv->rpasteBin, '0x0-andy' => $thirdPartyEnv->andyBin, '0x0-olav' => $thirdPartyEnv->olavBin] as $name => $bin) {
        if (! file_exists($bin) || ! is_executable($bin)) {
            throw new RuntimeException("Required external client {$name} is missing or not executable at {$bin}");
        }
    }

    // Start local server in public mode with auth tokens
    $thirdPartyEnv->port = random_int(20000, 29999);
    $thirdPartyEnv->host = "http://127.0.0.1:{$thirdPartyEnv->port}";

    $cmd = sprintf(
        'FUXICO_PUBLIC_MODE=true FUXICO_AUTH_TOKENS=test-token FUXICO_DATA_DIR=%s php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($thirdPartyEnv->tempDir.'/data'),
        $thirdPartyEnv->port,
        escapeshellarg($distFile)
    );

    $thirdPartyEnv->pid = (int) trim((string) shell_exec($cmd));

    // Wait for server socket readiness
    $ready = false;
    for ($i = 0; $i < 25; $i++) {
        usleep(100000);
        $fp = @fsockopen('127.0.0.1', $thirdPartyEnv->port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            $ready = true;
            break;
        }
    }

    if (! $ready) {
        throw new RuntimeException("Test server failed to start on port {$thirdPartyEnv->port}");
    }
});

afterAll(function () use ($thirdPartyEnv) {
    if ($thirdPartyEnv->pid > 0) {
        exec("kill -9 {$thirdPartyEnv->pid} 2>/dev/null");
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

    $cleanDir($thirdPartyEnv->tempDir);
});

// =============================================================================
// RUSTYPASTE OFFICIAL CLIENT (rpaste)
// =============================================================================

describe('rustypaste-cli (rpaste)', function () use ($thirdPartyEnv) {
    it('uploads text and binary files to fuxico', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->rpasteBin))->toBeTrue();

        $testFile = $thirdPartyEnv->tempDir.'/rpaste_sample.txt';
        $content = "Travesti Latina Fuxico Test — rpaste payload\n";
        file_put_contents($testFile, $content);

        $cmd = sprintf(
            '%s -s %s %s',
            escapeshellarg($thirdPartyEnv->rpasteBin),
            escapeshellarg($thirdPartyEnv->host),
            escapeshellarg($testFile)
        );

        $res = $thirdPartyEnv->runCmd($cmd);
        expect($res['code'])->toBe(0);

        $url = trim($res['stdout']);
        expect($url)->toStartWith($thirdPartyEnv->host.'/');

        $fetchRes = $thirdPartyEnv->fetch($url);
        expect($fetchRes['status'])->toBe(200);
        expect($fetchRes['body'])->toBe($content);
    });

    it('uploads streaming payload from stdin pipe', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->rpasteBin))->toBeTrue();

        $stdinData = 'Fuxico via rpaste STDIN stream: '.uniqid();
        $cmd = sprintf(
            '%s -s %s -',
            escapeshellarg($thirdPartyEnv->rpasteBin),
            escapeshellarg($thirdPartyEnv->host)
        );

        $res = $thirdPartyEnv->runCmd($cmd, $stdinData);
        expect($res['code'])->toBe(0);

        $url = trim($res['stdout']);
        expect($url)->toStartWith($thirdPartyEnv->host.'/');

        $fetchRes = $thirdPartyEnv->fetch($url);
        expect($fetchRes['status'])->toBe(200);
        expect($fetchRes['body'])->toBe($stdinData);
    });

    it('shortens URLs using -u flag', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->rpasteBin))->toBeTrue();

        $targetUrl = 'https://codeberg.org/lumenpink/fuxico';
        $cmd = sprintf(
            '%s -s %s -u %s',
            escapeshellarg($thirdPartyEnv->rpasteBin),
            escapeshellarg($thirdPartyEnv->host),
            escapeshellarg($targetUrl)
        );

        $res = $thirdPartyEnv->runCmd($cmd);
        expect($res['code'])->toBe(0);

        $shortUrl = trim($res['stdout']);
        expect($shortUrl)->toStartWith($thirdPartyEnv->host.'/');

        $fetchRes = $thirdPartyEnv->fetch($shortUrl);
        expect($fetchRes['status'])->toBe(302);

        $locations = array_filter($fetchRes['headers'], fn ($h) => stripos($h, 'Location:') === 0);
        expect(implode(' ', $locations))->toContain($targetUrl);
    });

    it('deletes files using -d flag with auth token', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->rpasteBin))->toBeTrue();

        $testFile = $thirdPartyEnv->tempDir.'/rpaste_delete_target.txt';
        file_put_contents($testFile, 'Short lived secret for rpaste deletion');

        // Upload file
        $uploadCmd = sprintf(
            '%s -s %s %s',
            escapeshellarg($thirdPartyEnv->rpasteBin),
            escapeshellarg($thirdPartyEnv->host),
            escapeshellarg($testFile)
        );
        $uploadRes = $thirdPartyEnv->runCmd($uploadCmd);
        expect($uploadRes['code'])->toBe(0);

        $fileUrl = trim($uploadRes['stdout']);

        // Verify it exists
        $fetchBefore = $thirdPartyEnv->fetch($fileUrl);
        expect($fetchBefore['status'])->toBe(200);

        // Delete with auth token
        $deleteCmd = sprintf(
            '%s -s %s -a test-token -d %s',
            escapeshellarg($thirdPartyEnv->rpasteBin),
            escapeshellarg($thirdPartyEnv->host),
            escapeshellarg($fileUrl)
        );
        $deleteRes = $thirdPartyEnv->runCmd($deleteCmd);
        expect($deleteRes['code'])->toBe(0);
        expect($deleteRes['stdout'])->toContain('File deleted');

        // Verify file is gone
        $fetchAfter = $thirdPartyEnv->fetch($fileUrl);
        expect($fetchAfter['status'])->toBe(404);
    });

    it('supports custom filename via -n flag (named share)', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->rpasteBin))->toBeTrue();

        $customName = 'rpaste_bafao_'.uniqid().'.txt';
        $cmd = sprintf(
            '%s -s %s -n %s -',
            escapeshellarg($thirdPartyEnv->rpasteBin),
            escapeshellarg($thirdPartyEnv->host),
            escapeshellarg($customName)
        );

        $res = $thirdPartyEnv->runCmd($cmd, "Named rpaste payload\n");
        expect($res['code'])->toBe(0);

        $url = trim($res['stdout']);
        expect($url)->toContain("/{$customName}");

        $fetchRes = $thirdPartyEnv->fetch($url);
        expect($fetchRes['status'])->toBe(200);
        expect($fetchRes['body'])->toBe("Named rpaste payload\n");
    });

    it('generates oneshot links via -o flag (burn after reading)', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->rpasteBin))->toBeTrue();

        $burnContent = 'Burn after reading via rpaste: '.uniqid()."\n";
        $cmd = sprintf(
            '%s -s %s -o -',
            escapeshellarg($thirdPartyEnv->rpasteBin),
            escapeshellarg($thirdPartyEnv->host)
        );

        $res = $thirdPartyEnv->runCmd($cmd, $burnContent);
        expect($res['code'])->toBe(0);

        $url = trim($res['stdout']);
        expect($url)->toStartWith($thirdPartyEnv->host.'/');

        // 1st fetch succeeds
        $fetch1 = $thirdPartyEnv->fetch($url);
        expect($fetch1['status'])->toBe(200);
        expect($fetch1['body'])->toBe($burnContent);

        // 2nd fetch evaporates
        $fetch2 = $thirdPartyEnv->fetch($url);
        expect($fetch2['status'])->toBeIn([404, 410]);
    });

    it('sets defined expiration via -e flag', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->rpasteBin))->toBeTrue();

        $cmd = sprintf(
            '%s -s %s -e 2h -',
            escapeshellarg($thirdPartyEnv->rpasteBin),
            escapeshellarg($thirdPartyEnv->host)
        );

        $res = $thirdPartyEnv->runCmd($cmd, "Expiring rpaste payload\n");
        expect($res['code'])->toBe(0);

        $url = trim($res['stdout']);
        expect($url)->toStartWith($thirdPartyEnv->host.'/');

        $fetchRes = $thirdPartyEnv->fetch($url);
        expect($fetchRes['status'])->toBe(200);
    });
});

// =============================================================================
// NULLPOINTER / 0X0.ST BASH CLIENT (AndyRusso/0x0)
// =============================================================================

describe('nullpointer Bash client (AndyRusso/0x0)', function () use ($thirdPartyEnv) {
    it('uploads file, parses x-token and deletes file via management token', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->andyBin))->toBeTrue();

        $configPath = $thirdPartyEnv->tempDir.'/config/0x0.sh';
        file_put_contents($configPath, "instance=\"{$thirdPartyEnv->host}/\"\nembed=false\nsecret=false\nexpiry=0\n");

        $env = [
            'XDG_CONFIG_HOME' => $thirdPartyEnv->tempDir.'/config',
            'XDG_DATA_HOME' => $thirdPartyEnv->tempDir.'/data_home',
        ];

        $uniqueContent = 'Unique AndyRusso payload: '.uniqid().' - '.hrtime(true);
        $testFile = $thirdPartyEnv->tempDir.'/andy_sample.txt';
        file_put_contents($testFile, $uniqueContent);

        // Upload
        $uploadCmd = sprintf('%s upload %s', escapeshellarg($thirdPartyEnv->andyBin), escapeshellarg($testFile));
        $uploadRes = $thirdPartyEnv->runCmd($uploadCmd, null, $env);
        expect($uploadRes['code'])->toBe(0);
        expect($uploadRes['stdout'])->toContain('Upload successful!');

        $lines = array_filter(array_map('trim', explode("\n", $uploadRes['stdout'])));
        $uploadedUrl = end($lines);
        expect($uploadedUrl)->toStartWith($thirdPartyEnv->host.'/');

        // Check file is readable
        $fetchRes = $thirdPartyEnv->fetch($uploadedUrl);
        expect($fetchRes['status'])->toBe(200);
        expect($fetchRes['body'])->toBe($uniqueContent);

        // Verify token file was written
        $tokensFile = $thirdPartyEnv->tempDir.'/data_home/0x0_tokens';
        expect(file_exists($tokensFile))->toBeTrue();
        $tokensContent = (string) file_get_contents($tokensFile);
        expect($tokensContent)->toContain($uploadedUrl);

        // Delete using AndyRusso delete command
        $deleteCmd = sprintf('%s delete %s', escapeshellarg($thirdPartyEnv->andyBin), escapeshellarg($uploadedUrl));
        $deleteRes = $thirdPartyEnv->runCmd($deleteCmd, null, $env);
        expect($deleteRes['code'])->toBe(0);
        expect($deleteRes['stdout'])->toContain('File deleted successfully!');

        // Check file is now 404
        $fetchAfter = $thirdPartyEnv->fetch($uploadedUrl);
        expect($fetchAfter['status'])->toBe(404);
    });

    it('uploads with secret 16-character filename via -s flag', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->andyBin))->toBeTrue();

        $env = [
            'XDG_CONFIG_HOME' => $thirdPartyEnv->tempDir.'/config',
            'XDG_DATA_HOME' => $thirdPartyEnv->tempDir.'/data_home',
        ];

        $testFile = $thirdPartyEnv->tempDir.'/andy_secret.txt';
        file_put_contents($testFile, 'Andy secret payload');

        $cmd = sprintf('%s -s %s', escapeshellarg($thirdPartyEnv->andyBin), escapeshellarg($testFile));
        $res = $thirdPartyEnv->runCmd($cmd, null, $env);
        expect($res['code'])->toBe(0);

        $lines = array_filter(array_map('trim', explode("\n", $res['stdout'])));
        $uploadedUrl = end($lines);
        $slug = pathinfo(basename(parse_url($uploadedUrl, PHP_URL_PATH) ?? ''), PATHINFO_FILENAME);
        expect(strlen($slug))->toBe(16);
    });

    it('uploads with defined expiration and allows changing expiry date via expire command', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->andyBin))->toBeTrue();

        $env = [
            'XDG_CONFIG_HOME' => $thirdPartyEnv->tempDir.'/config',
            'XDG_DATA_HOME' => $thirdPartyEnv->tempDir.'/data_home',
        ];

        $testFile = $thirdPartyEnv->tempDir.'/andy_exp.txt';
        file_put_contents($testFile, 'Andy expiration payload');

        // Upload with -ex 2
        $uploadCmd = sprintf('%s -ex 2 %s', escapeshellarg($thirdPartyEnv->andyBin), escapeshellarg($testFile));
        $uploadRes = $thirdPartyEnv->runCmd($uploadCmd, null, $env);
        expect($uploadRes['code'])->toBe(0);

        $lines = array_filter(array_map('trim', explode("\n", $uploadRes['stdout'])));
        $uploadedUrl = end($lines);

        // Change expiry date via expire subcommand
        $expireCmd = sprintf('%s expire %s 10', escapeshellarg($thirdPartyEnv->andyBin), escapeshellarg($uploadedUrl));
        $expireRes = $thirdPartyEnv->runCmd($expireCmd, null, $env);
        expect($expireRes['code'])->toBe(0);
        expect($expireRes['stdout'])->toContain('File expiry changed successfully!');
    });
});

// =============================================================================
// NULLPOINTER / 0X0.ST POSIX SHELL CLIENT (olavfosse/0x0)
// =============================================================================

describe('nullpointer POSIX sh client (olavfosse/0x0)', function () use ($thirdPartyEnv) {
    it('uploads file to fuxico via NULLPOINTERINSTANCE', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->olavBin))->toBeTrue();

        $env = [
            'NULLPOINTERINSTANCE' => $thirdPartyEnv->host,
        ];

        $content = "Olav Fosse 0x0 upload test\n";
        $testFile = $thirdPartyEnv->tempDir.'/olav_sample.txt';
        file_put_contents($testFile, $content);

        $cmd = sprintf('%s file %s', escapeshellarg($thirdPartyEnv->olavBin), escapeshellarg($testFile));
        $res = $thirdPartyEnv->runCmd($cmd, null, $env);
        expect($res['code'])->toBe(0);

        $url = trim($res['stdout']);
        expect($url)->toStartWith($thirdPartyEnv->host.'/');

        $fetchRes = $thirdPartyEnv->fetch($url);
        expect($fetchRes['status'])->toBe(200);
        expect($fetchRes['body'])->toBe($content);
    });

    it('uploads from stdin stream pipe', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->olavBin))->toBeTrue();

        $env = [
            'NULLPOINTERINSTANCE' => $thirdPartyEnv->host,
        ];

        $stdinData = 'Piping into 0x0 olav: '.uniqid();
        $cmd = sprintf('%s file -', escapeshellarg($thirdPartyEnv->olavBin));

        $res = $thirdPartyEnv->runCmd($cmd, $stdinData, $env);
        expect($res['code'])->toBe(0);

        $url = trim($res['stdout']);
        expect($url)->toStartWith($thirdPartyEnv->host.'/');

        $fetchRes = $thirdPartyEnv->fetch($url);
        expect($fetchRes['status'])->toBe(200);
        expect($fetchRes['body'])->toBe($stdinData);
    });

    it('shortens URLs via shorten subcommand', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->olavBin))->toBeTrue();

        $env = [
            'NULLPOINTERINSTANCE' => $thirdPartyEnv->host,
        ];

        $targetUrl = 'https://codeberg.org/lumenpink';
        $cmd = sprintf('%s shorten %s', escapeshellarg($thirdPartyEnv->olavBin), escapeshellarg($targetUrl));

        $res = $thirdPartyEnv->runCmd($cmd, null, $env);
        expect($res['code'])->toBe(0);

        $shortUrl = trim($res['stdout']);
        expect($shortUrl)->toStartWith($thirdPartyEnv->host.'/');

        $fetchRes = $thirdPartyEnv->fetch($shortUrl);
        expect($fetchRes['status'])->toBe(302);

        $locations = array_filter($fetchRes['headers'], fn ($h) => stripos($h, 'Location:') === 0);
        expect(implode(' ', $locations))->toContain($targetUrl);
    });

    it('uploads via remote URL via url subcommand', function () use ($thirdPartyEnv) {
        expect(file_exists($thirdPartyEnv->olavBin))->toBeTrue();

        $env = [
            'NULLPOINTERINSTANCE' => $thirdPartyEnv->host,
        ];

        $targetUrl = 'https://codeberg.org/lumenpink/fuxico';
        $cmd = sprintf('%s url %s', escapeshellarg($thirdPartyEnv->olavBin), escapeshellarg($targetUrl));

        $res = $thirdPartyEnv->runCmd($cmd, null, $env);
        expect($res['code'])->toBe(0);

        $shortUrl = trim($res['stdout']);
        expect($shortUrl)->toStartWith($thirdPartyEnv->host.'/');

        $fetchRes = $thirdPartyEnv->fetch($shortUrl);
        expect($fetchRes['status'])->toBe(302);
    });
});
