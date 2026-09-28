<?php

declare(strict_types=1);

$gitEnv = new class
{
    public int $port = 0;

    public string $host = '';

    public int $pid = 0;

    public string $tempDir = '';

    public string $bashBin = '';

    public string $rustBin = '';

    public function runCmd(string $cmd): array
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

    public function fetch(string $url, array $options = []): array
    {
        $headers = [];
        if (! empty($options['headers'])) {
            $headers = $options['headers'];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $options['method'] ?? 'GET',
                'header' => implode("\r\n", $headers),
                'content' => $options['body'] ?? null,
                'timeout' => 5,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $content = @file_get_contents($url, false, $ctx);
        $respHeaders = $http_response_header ?? [];
        $status = 0;
        if (! empty($respHeaders[0]) && preg_match('/HTTP\/\S+\s+(\d+)/', $respHeaders[0], $m)) {
            $status = (int) $m[1];
        }

        return [
            'status' => $status,
            'body' => $content !== false ? $content : '',
            'headers' => $respHeaders,
        ];
    }
};

beforeAll(function () use ($gitEnv) {
    $rootDir = dirname(__DIR__, 2);
    exec("php {$rootDir}/compile.php --quiet");
    $distFile = $rootDir.'/dist/fuxico.php';

    $gitEnv->bashBin = $rootDir.'/bin/fux';
    $gitEnv->rustBin = $rootDir.'/dist/fux-rs';

    $gitEnv->tempDir = sys_get_temp_dir().'/fuxico_git_feature_'.uniqid();
    mkdir($gitEnv->tempDir.'/data', 0755, true);

    $gitEnv->port = random_int(21000, 21999);
    $gitEnv->host = "http://127.0.0.1:{$gitEnv->port}";

    $cmd = sprintf(
        'FUXICO_PUBLIC_MODE=true FUXICO_AUTH_TOKENS=tatanka FUXICO_DATA_DIR=%s php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($gitEnv->tempDir.'/data'),
        $gitEnv->port,
        escapeshellarg($distFile)
    );

    $gitEnv->pid = (int) trim((string) shell_exec($cmd));

    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        $fp = @fsockopen('127.0.0.1', $gitEnv->port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            break;
        }
    }
});

afterAll(function () use ($gitEnv) {
    if ($gitEnv->pid > 0) {
        posix_kill($gitEnv->pid, SIGKILL);
    }
    if (is_dir($gitEnv->tempDir)) {
        exec('rm -rf '.escapeshellarg($gitEnv->tempDir));
    }
});

test('api creates git bare repository and returns clone url and token', function () use ($gitEnv) {
    $resp = $gitEnv->fetch("{$gitEnv->host}/api/git", [
        'method' => 'POST',
        'headers' => ['Content-Type: application/json'],
        'body' => json_encode(['name' => 'meu-projeto', 'token' => 'tok123']),
    ]);

    expect($resp['status'])->toBe(200);
    $data = json_decode($resp['body'], true);
    expect($data['success'])->toBeTrue();
    expect($data['slug'])->toBe('meu-projeto');
    expect($data['clone_url'])->toBe("{$gitEnv->host}/meu-projeto.git");
    expect($data['x_token'])->toBe('tok123');

    // Verify empty repo HTML was baked
    $htmlResp = $gitEnv->fetch("{$gitEnv->host}/meu-projeto", [
        'headers' => ['Accept: text/html'],
    ]);
    expect($htmlResp['status'])->toBe(200);
    expect($htmlResp['body'])->toContain('meu-projeto');
    expect($htmlResp['body'])->toContain('Repositório Vazio / Quick Setup');
});

test('git http smart protocol discovery info/refs for clone and push', function () use ($gitEnv) {
    // 1. Upload pack advertisement (clone/fetch) - public
    $respUpload = $gitEnv->fetch("{$gitEnv->host}/meu-projeto.git/info/refs?service=git-upload-pack");
    expect($respUpload['status'])->toBe(200);
    expect($respUpload['body'])->toContain('# service=git-upload-pack');

    // 2. Receive pack advertisement without auth returns 401
    $respNoAuth = $gitEnv->fetch("{$gitEnv->host}/meu-projeto.git/info/refs?service=git-receive-pack");
    expect($respNoAuth['status'])->toBe(401);

    // 3. Receive pack with valid token returns 200
    $authBasic = base64_encode('anonymous:tok123');
    $respWithAuth = $gitEnv->fetch("{$gitEnv->host}/meu-projeto.git/info/refs?service=git-receive-pack", [
        'headers' => ["Authorization: Basic {$authBasic}"],
    ]);
    expect($respWithAuth['status'])->toBe(200);
    expect($respWithAuth['body'])->toContain('# service=git-receive-pack');
});

test('performs real git push over HTTP, static baking, raw file access and zip download', function () use ($gitEnv) {
    $localGitDir = $gitEnv->tempDir.'/local_repo';
    mkdir($localGitDir, 0755, true);

    $gitCmd = "cd {$localGitDir} && git init && git config user.name 'Baquara' && git config user.email 'baquara@fuxico.cc'";
    $gitEnv->runCmd($gitCmd);

    file_put_contents($localGitDir.'/README.md', "# Meu Super Projeto\n\nFeito com amor e deboche.");
    file_put_contents($localGitDir.'/app.py', "print('fuxico rules')\n");

    $gitEnv->runCmd("cd {$localGitDir} && git add . && git commit -m 'Initial commit'");

    // Push using token in URL
    $pushUrl = "http://tok123@127.0.0.1:{$gitEnv->port}/meu-projeto.git";
    $pushRes = $gitEnv->runCmd("cd {$localGitDir} && git push --set-upstream ".escapeshellarg($pushUrl).' HEAD:main 2>&1');
    expect($pushRes['code'])->toBe(0);

    // Verify static baking generated rich HTML with README and files
    $htmlResp = $gitEnv->fetch("{$gitEnv->host}/meu-projeto", [
        'headers' => ['Accept: text/html'],
    ]);
    expect($htmlResp['status'])->toBe(200);
    expect($htmlResp['body'])->toContain('Meu Super Projeto');
    expect($htmlResp['body'])->toContain('Feito com amor e deboche.');
    expect($htmlResp['body'])->toContain('app.py');
    expect($htmlResp['body'])->toContain('Initial commit');

    // Verify raw file serving
    $rawResp = $gitEnv->fetch("{$gitEnv->host}/meu-projeto/raw/app.py");
    expect($rawResp['status'])->toBe(200);
    expect($rawResp['body'])->toContain("print('fuxico rules')");

    // Verify ZIP archive download
    $zipResp = $gitEnv->fetch("{$gitEnv->host}/meu-projeto/zip");
    expect($zipResp['status'])->toBe(200);
    expect(strlen($zipResp['body']))->toBeGreaterThan(100);
    expect(str_starts_with($zipResp['body'], "PK\x03\x04"))->toBeTrue();
});

test('cli tools fux and fux-rs support git init and chuchuzim phrase in help', function () use ($gitEnv) {
    // 1. Bash CLI help contains chuchuzim
    $bashHelp = $gitEnv->runCmd("{$gitEnv->bashBin} --help");
    expect($bashHelp['stdout'])->toContain('fux é um chuchuzim fuxiquento.');

    // 2. Bash CLI git init
    $bashGit = $gitEnv->runCmd("{$gitEnv->bashBin} -S {$gitEnv->host} git init fux-bash-repo");
    expect($bashGit['code'])->toBe(0);
    expect(trim($bashGit['stdout']))->toBe("{$gitEnv->host}/fux-bash-repo.git");

    // 3. Rust CLI help contains chuchuzim
    $rustHelp = $gitEnv->runCmd("{$gitEnv->rustBin} --help");
    expect($rustHelp['stdout'])->toContain('fux é um chuchuzim fuxiquento.');

    // 4. Rust CLI git init
    $rustGit = $gitEnv->runCmd("{$gitEnv->rustBin} -S {$gitEnv->host} --git-init fux-rust-repo");
    expect($rustGit['code'])->toBe(0);
    expect(trim($rustGit['stdout']))->toBe("{$gitEnv->host}/fux-rust-repo.git");
});
