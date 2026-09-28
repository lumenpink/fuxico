<?php

declare(strict_types=1);

/**
 * Fuxico Single-File Compiler / Bundler
 * Compiles all modular source files into a standalone, portable fuxico.php
 *
 * Inspired by indieinabox's compile.php architecture.
 */

// Parse CLI options
$options = getopt('o:chq', ['output:', 'check', 'help', 'quiet']);
$showHelp = isset($options['h']) || isset($options['help']);
$doCheck = isset($options['c']) || isset($options['check']);
$quiet = isset($options['q']) || isset($options['quiet']);
$customOutput = $options['o'] ?? $options['output'] ?? null;

if ($showHelp) {
    echo <<<'HELP'
Fuxico Single-File Compiler

Usage:
  php compile.php [options]

Options:
  -o, --output <path>   Destination path for compiled artifact (default: dist/fuxico.php)
  -c, --check           Run php -l lint check on compiled artifact
  -q, --quiet           Suppress non-error output
  -h, --help            Show this help message

HELP;
    exit(0);
}

$rootDir = __DIR__;
$srcDir = $rootDir.'/src';
$distDir = $rootDir.'/dist';

// 1. Read Version & Git info
$versionFile = $rootDir.'/VERSION';
$version = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.1.0-dev';

$gitHash = '';
if (is_dir($rootDir.'/.git')) {
    $headOutput = @shell_exec('git -C '.escapeshellarg($rootDir).' rev-parse --short HEAD 2>/dev/null');
    if ($headOutput) {
        $gitHash = trim($headOutput);
    }
}
$buildDate = gmdate('Y-m-d H:i:s \U\T\C');
$fullVersion = $gitHash !== '' ? "{$version}+{$gitHash}" : $version;

// 2. Define compilation order (topological dependency resolution)
$compileFiles = [
    'Config.php',
    'SimpleZip.php',
    'Storage.php',
    'MimeValidator.php',
    'Database.php',
    'I18n.php',
    'MarkdownRenderer.php',
    'GistRenderer.php',
    'Views.php',
    'App.php',
];

// Check that all files in src/ are accounted for
$existingFiles = glob($srcDir.'/*.php') ?: [];
$existingBasenames = array_map('basename', $existingFiles);
$missingFromCompile = array_diff($existingBasenames, $compileFiles);

if (! empty($missingFromCompile)) {
    fwrite(STDERR, '[ERROR] Found unlisted files in src/: '.implode(', ', $missingFromCompile)."\n");
    fwrite(STDERR, "Please update compile.php dependency order to include these files.\n");
    exit(1);
}

// 3. Assemble components
$compiled = [];

// Header comment and declare
$compiled[] = <<<'HEADER'
<?php

declare(strict_types=1);

/**
 * ┌─────────────────────────────────────────────────────────────┐
 * │  FUXICO — A malícia popular do boca a boca                  │
 * │  Single-file portable file sharing & URL shortener          │
 * │  100% compatible with 0x0.st and rustypaste protocols      │
 * └─────────────────────────────────────────────────────────────┘
 *
 * Version: %VERSION%
 * Built:   %BUILD_DATE%
 * License: GPL-3.0-or-later
 * Source:  https://codeberg.org/lumenpink/fuxico
 *
 * Requirements:
 *   - PHP >= 8.2.0
 *   - Extensions: pdo_sqlite, fileinfo
 */
HEADER;

$compiled[0] = str_replace(
    ['%VERSION%', '%BUILD_DATE%'],
    [$fullVersion, $buildDate],
    $compiled[0]
);

// Environment verification and constants in global namespace
$compiled[] = <<<ENV_CHECK
namespace {
    if (PHP_VERSION_ID < 80200) {
        \$msg = "Fuxico requires PHP 8.2.0 or higher. Current version: " . PHP_VERSION;
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "[ERROR] " . \$msg . "\\n");
        } else {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo \$msg;
        }
        exit(1);
    }

    \$requiredExtensions = ['pdo_sqlite', 'fileinfo'];
    \$missingExtensions = [];
    foreach (\$requiredExtensions as \$ext) {
        if (!extension_loaded(\$ext)) {
            \$missingExtensions[] = \$ext;
        }
    }
    if (!empty(\$missingExtensions)) {
        \$msg = "Fuxico requires PHP extension(s): " . implode(', ', \$missingExtensions);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "[ERROR] " . \$msg . "\\n");
        } else {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo \$msg;
        }
        exit(1);
    }

    if (!defined('FUXICO_COMPILED')) {
        define('FUXICO_COMPILED', true);
        define('FUXICO_VERSION', '{$fullVersion}');
        define('FUXICO_BUILD_DATE', '{$buildDate}');
    }
}
ENV_CHECK;

// 4. Process each source file
foreach ($compileFiles as $filename) {
    $filePath = $srcDir.'/'.$filename;
    if (! file_exists($filePath)) {
        fwrite(STDERR, "[ERROR] Source file not found: {$filePath}\n");
        exit(1);
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        fwrite(STDERR, "[ERROR] Could not read file: {$filePath}\n");
        exit(1);
    }

    // Strip <?php and declare
    $content = preg_replace('/^<\?php\s*/', '', $content) ?? '';
    $content = preg_replace('/declare\s*\([^)]*\);\s*/', '', $content) ?? '';

    // Strip namespace Fuxico; statement
    $content = preg_replace('/namespace\s+Fuxico\s*;\s*/', '', $content) ?? '';

    $content = trim($content);

    $compiled[] = <<<MODULE
/* --- Module: {$filename} --- */
namespace Fuxico {
{$content}
}
MODULE;
}

// 5. Add Global Runner
$compiled[] = <<<'RUNNER'
/* --- Runner --- */
namespace {
    // Auto-dispatch when executed directly via web server or CLI
    if (!defined('FUXICO_NO_RUN')) {
        $isCli = (PHP_SAPI === 'cli');
        $isDirectCli = $isCli && (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));
        $isWebServer = !$isCli;

        if ($isWebServer || $isDirectCli) {
            $app = new \Fuxico\App();
            $app->handleRequest();
        }
    }
}
RUNNER;

$finalCode = implode("\n\n", $compiled)."\n";

// 6. Write output
if (! is_dir($distDir)) {
    mkdir($distDir, 0755, true);
}

$outputFile = $customOutput ? (str_starts_with($customOutput, '/') ? $customOutput : $rootDir.'/'.$customOutput) : $distDir.'/fuxico.php';
$outputDir = dirname($outputFile);
if (! is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

if (file_put_contents($outputFile, $finalCode) === false) {
    fwrite(STDERR, "[ERROR] Failed to write compiled artifact to: {$outputFile}\n");
    exit(1);
}

// Also update root fuxico.php if standard compilation
if (! $customOutput) {
    file_put_contents($rootDir.'/fuxico.php', $finalCode);
}

// 7. Verification & Stats
$sizeBytes = strlen($finalCode);
$sizeKb = round($sizeBytes / 1024, 2);
$lineCount = substr_count($finalCode, "\n") + 1;
$sha256 = hash('sha256', $finalCode);

if (! $quiet) {
    echo "✨ Fuxico compilation successful!\n";
    echo "   Artifact:  {$outputFile}\n";
    if (! $customOutput) {
        echo "   Root copy: {$rootDir}/fuxico.php\n";
    }
    echo "   Version:   {$fullVersion}\n";
    echo "   Size:      {$sizeKb} KB ({$sizeBytes} bytes)\n";
    echo "   Lines:     {$lineCount}\n";
    echo "   SHA-256:   {$sha256}\n";
}

// 8. Syntax lint check
$lintOutput = [];
$lintStatus = 0;
exec('php -l '.escapeshellarg($outputFile).' 2>&1', $lintOutput, $lintStatus);

if ($lintStatus !== 0) {
    fwrite(STDERR, "[ERROR] Syntax check failed on {$outputFile}:\n".implode("\n", $lintOutput)."\n");
    exit(1);
}

if (! $quiet) {
    echo "   Syntax:    No syntax errors detected in compiled artifact.\n";
}
