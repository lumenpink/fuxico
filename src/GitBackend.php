<?php

declare(strict_types=1);

namespace Fuxico;

class GitBackend
{
    /**
     * Check if git command-line tool is available on the system.
     */
    public static function isGitAvailable(): bool
    {
        $output = @shell_exec('git --version 2>/dev/null');

        return $output !== null && str_starts_with(trim($output), 'git version');
    }

    /**
     * Initialize an empty bare Git repository.
     */
    public static function initBareRepo(string $repoPath): bool
    {
        if (is_dir($repoPath)) {
            return true;
        }

        if (! mkdir($repoPath, 0755, true)) {
            return false;
        }

        $cmd = 'git init --bare '.escapeshellarg($repoPath).' 2>&1';
        $out = [];
        $ret = 0;
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            return false;
        }

        // Configure default branch to main and bare repository for HTTP protocol
        exec('git -C '.escapeshellarg($repoPath).' symbolic-ref HEAD refs/heads/main 2>/dev/null');
        exec('git -C '.escapeshellarg($repoPath).' config http.receivepack true');
        exec('git -C '.escapeshellarg($repoPath).' config uploadpack.allowAnySHA1InWant true');

        return true;
    }

    /**
     * Handle the smart HTTP ref discovery (GET /info/refs?service=git-upload-pack|git-receive-pack).
     */
    public static function handleInfoRefs(string $repoPath, string $service): void
    {
        if (! in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Invalid git service\n";

            return;
        }

        if (! is_dir($repoPath)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Repository not found\n";

            return;
        }

        $command = str_replace('git-', '', $service);

        header("Content-Type: application/x-{$service}-advertisement");
        header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: Fri, 01 Jan 1980 00:00:00 GMT');

        // Clean any output buffers
        if (php_sapi_name() !== 'cli') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        // Git Smart HTTP pkt-line advertisement format
        // Packet: # service=<service>\n
        $line = "# service={$service}\n";
        $len = strlen($line) + 4;
        echo sprintf('%04x%s0000', $len, $line);
        flush();

        // Run git advertise-refs
        $cmd = 'git '.escapeshellcmd($command).' --stateless-rpc --advertise-refs '.escapeshellarg($repoPath);
        $fp = popen($cmd, 'r');
        if ($fp) {
            while (! feof($fp)) {
                $buf = fread($fp, 8192);
                if ($buf !== false) {
                    echo $buf;
                    flush();
                }
            }
            pclose($fp);
        }
    }

    /**
     * Handle smart HTTP RPC (POST /git-upload-pack or /git-receive-pack).
     */
    public static function handleRpc(string $repoPath, string $service): int
    {
        if (! in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Invalid git service\n";

            return 1;
        }

        if (! is_dir($repoPath)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Repository not found\n";

            return 1;
        }

        $command = str_replace('git-', '', $service);

        header("Content-Type: application/x-{$service}-result");
        header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
        header('Pragma: no-cache');

        // Clean output buffers
        if (php_sapi_name() !== 'cli') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $cmd = 'git '.escapeshellcmd($command).' --stateless-rpc '.escapeshellarg($repoPath);
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($process)) {
            http_response_code(500);
            echo "Failed to execute git {$command}\n";

            return 1;
        }

        // Stream client body from php://input to process stdin
        $input = fopen('php://input', 'rb');
        $isGzip = strtolower($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip';
        if ($isGzip && function_exists('stream_filter_append') && $input) {
            stream_filter_append($input, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 15 + 32]);
        }

        if ($input) {
            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk !== false && strlen($chunk) > 0) {
                    fwrite($pipes[0], $chunk);
                }
            }
            fclose($input);
        }
        fclose($pipes[0]);

        // Stream process stdout directly to client output
        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && strlen($chunk) > 0) {
                echo $chunk;
                flush();
            }
        }
        fclose($pipes[1]);

        // Discard stderr
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return $exitCode;
    }

    /**
     * Retrieve repository metadata for static baking.
     *
     * @return array{
     *   has_commits: bool,
     *   default_branch: string,
     *   commit_count: int,
     *   commits: array<array{hash: string, author: string, date: string, message: string}>,
     *   files: array<array{name: string, size: int}>,
     *   readme_content: ?string,
     *   readme_filename: ?string,
     *   size: int
     * }
     */
    public static function getRepoMetadata(string $repoPath): array
    {
        $hasCommits = false;
        $defaultBranch = 'main';
        $commitCount = 0;
        $commits = [];
        $files = [];
        $readmeContent = null;
        $readmeFilename = null;

        // Check if HEAD has commits or points to an unborn branch
        $revCheck = @shell_exec('git -C '.escapeshellarg($repoPath).' rev-parse --verify HEAD 2>/dev/null');
        if ($revCheck === null || trim((string) $revCheck) === '') {
            $branchesOut = @shell_exec('git -C '.escapeshellarg($repoPath).' for-each-ref --format="%(refname:short)" refs/heads/ 2>/dev/null');
            if ($branchesOut) {
                $branches = array_values(array_filter(array_map('trim', explode("\n", (string) $branchesOut))));
                if (! empty($branches)) {
                    $chosen = in_array('main', $branches, true) ? 'main' : (in_array('master', $branches, true) ? 'master' : $branches[0]);
                    @shell_exec('git -C '.escapeshellarg($repoPath).' symbolic-ref HEAD refs/heads/'.escapeshellarg($chosen).' 2>/dev/null');
                    $revCheck = @shell_exec('git -C '.escapeshellarg($repoPath).' rev-parse --verify HEAD 2>/dev/null');
                }
            }
        }

        $headRef = trim((string) @shell_exec('git -C '.escapeshellarg($repoPath).' rev-parse --abbrev-ref HEAD 2>/dev/null'));
        if ($headRef !== '' && $headRef !== 'HEAD') {
            $defaultBranch = $headRef;
        }

        if ($revCheck !== null && trim((string) $revCheck) !== '') {
            $hasCommits = true;

            // Commit count
            $countOut = @shell_exec('git -C '.escapeshellarg($repoPath).' rev-list --count HEAD 2>/dev/null');
            if ($countOut !== null) {
                $commitCount = (int) trim($countOut);
            }

            // Commits log
            $logOut = @shell_exec('git -C '.escapeshellarg($repoPath).' log -n 15 --pretty=format:"%h|%an|%ad|%s" --date=short 2>/dev/null');
            if ($logOut) {
                $lines = explode("\n", trim($logOut));
                foreach ($lines as $line) {
                    $parts = explode('|', $line, 4);
                    if (count($parts) === 4) {
                        $commits[] = [
                            'hash' => $parts[0],
                            'author' => $parts[1],
                            'date' => $parts[2],
                            'message' => $parts[3],
                        ];
                    }
                }
            }

            // Files list with sizes
            $treeOut = @shell_exec('git -C '.escapeshellarg($repoPath).' ls-tree -r -l HEAD 2>/dev/null');
            if ($treeOut) {
                $lines = explode("\n", trim($treeOut));
                foreach ($lines as $line) {
                    if (preg_match('/^\d+\s+(\w+)\s+[a-f0-9]+\s+(\d+|-)\t(.+)$/', $line, $m)) {
                        $size = $m[2] === '-' ? 0 : (int) $m[2];
                        $path = $m[3];
                        $files[] = [
                            'name' => $path,
                            'size' => $size,
                        ];

                        // Detect README
                        if ($readmeContent === null && preg_match('/^readme(\.md|\.markdown|\.txt)?$/i', basename($path))) {
                            $readmeFilename = $path;
                        }
                    }
                }
            }

            if ($readmeFilename !== null) {
                $content = @shell_exec('git -C '.escapeshellarg($repoPath).' show HEAD:'.escapeshellarg($readmeFilename).' 2>/dev/null');
                if ($content !== null) {
                    $readmeContent = $content;
                }
            }
        }

        // Calculate repository directory size
        $totalSize = 0;
        if (is_dir($repoPath)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repoPath, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fi) {
                if ($fi->isFile()) {
                    $totalSize += $fi->getSize();
                }
            }
        }

        return [
            'has_commits' => $hasCommits,
            'default_branch' => $defaultBranch,
            'commit_count' => $commitCount,
            'commits' => $commits,
            'files' => $files,
            'readme_content' => $readmeContent,
            'readme_filename' => $readmeFilename,
            'size' => $totalSize,
        ];
    }

    /**
     * Retrieve content of a single file at HEAD.
     */
    public static function getFileContent(string $repoPath, string $filePath, string $ref = 'HEAD'): ?string
    {
        $cmd = 'git -C '.escapeshellarg($repoPath).' show '.escapeshellarg("{$ref}:{$filePath}").' 2>/dev/null';
        $content = @shell_exec($cmd);

        return $content !== null ? $content : null;
    }

    /**
     * Generate a ZIP archive binary of the repository at given ref.
     */
    public static function getArchiveZip(string $repoPath, string $ref = 'HEAD'): ?string
    {
        $cmd = 'git -C '.escapeshellarg($repoPath).' archive --format=zip '.escapeshellarg($ref).' 2>/dev/null';
        $zip = @shell_exec($cmd);

        return ($zip !== null && strlen($zip) > 0) ? $zip : null;
    }
}
