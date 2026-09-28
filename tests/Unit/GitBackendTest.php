<?php

declare(strict_types=1);

use Fuxico\GitBackend;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/fuxico_test_git_backend_'.bin2hex(random_bytes(6));
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->tmpDir)) {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
    }
});

test('git is available on system', function () {
    expect(GitBackend::isGitAvailable())->toBeTrue();
});

test('initializes empty bare repo and retrieves metadata', function () {
    $barePath = $this->tmpDir.'/test-repo.git';
    $ok = GitBackend::initBareRepo($barePath);
    expect($ok)->toBeTrue();
    expect(is_dir($barePath))->toBeTrue();
    expect(file_exists($barePath.'/HEAD'))->toBeTrue();

    $meta = GitBackend::getRepoMetadata($barePath);
    expect($meta['has_commits'])->toBeFalse();
    expect($meta['commit_count'])->toBe(0);
    expect($meta['files'])->toBeEmpty();
    expect($meta['readme_content'])->toBeNull();
});

test('retrieves commits, files, readme, and archive from bare repo', function () {
    $workDir = $this->tmpDir.'/work';
    $barePath = $this->tmpDir.'/target.git';
    mkdir($workDir, 0755, true);

    // Initialize local repo and push to bare
    exec("cd {$workDir} && git init && git config user.name 'Test Travesti' && git config user.email 'test@fuxico.cc'");
    file_put_contents($workDir.'/README.md', "# Fuxico Repo\n\nDocumentação viva.");
    file_put_contents($workDir.'/index.php', "<?php echo 'hello';");
    exec("cd {$workDir} && git add . && git commit -m 'Initial commit'");
    exec("cd {$workDir} && echo 'update' >> index.php && git commit -am 'Second commit'");

    exec('git clone --bare '.escapeshellarg($workDir).' '.escapeshellarg($barePath));

    $meta = GitBackend::getRepoMetadata($barePath);
    expect($meta['has_commits'])->toBeTrue();
    expect($meta['commit_count'])->toBe(2);
    expect($meta['commits'])->toHaveCount(2);
    expect($meta['commits'][0]['message'])->toBe('Second commit');
    expect($meta['commits'][1]['message'])->toBe('Initial commit');
    expect($meta['readme_content'])->toContain('# Fuxico Repo');
    expect($meta['files'])->toHaveCount(2);

    $readmeContent = GitBackend::getFileContent($barePath, 'README.md');
    expect($readmeContent)->toContain('Documentação viva.');

    $phpContent = GitBackend::getFileContent($barePath, 'index.php');
    expect($phpContent)->toContain("echo 'hello';");

    $zip = GitBackend::getArchiveZip($barePath);
    expect($zip)->not()->toBeNull();
    expect(strlen($zip))->toBeGreaterThan(50);
});
