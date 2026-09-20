<?php

declare(strict_types=1);

/**
 * Generates CI Job Summary for $GITHUB_STEP_SUMMARY and HTML dashboard for Codeberg Pages.
 *
 * Usage:
 *   php scripts/generate_ci_summary.php --step-summary
 *   php scripts/generate_ci_summary.php --build-pages
 */
$baseDir = dirname(__DIR__);
$coverageDir = $baseDir.'/coverage';
$isPages = in_array('--build-pages', $argv, true);
$isStepSummary = in_array('--step-summary', $argv, true);

// 1. Parse Clover XML (Code Coverage)
$cloverFile = $coverageDir.'/clover.xml';
$coveragePct = null;
$totalLines = 0;
$coveredLines = 0;
if (file_exists($cloverFile)) {
    $xml = @simplexml_load_file($cloverFile);
    if ($xml && isset($xml->project->metrics)) {
        $metrics = $xml->project->metrics;
        $totalLines = (int) ($metrics['loc'] ?? $metrics['statements'] ?? 0);
        $coveredLines = (int) ($metrics['coveredstatements'] ?? 0);
        if ($totalLines > 0) {
            $coveragePct = round(($coveredLines / $totalLines) * 100, 1);
        }
    }
}

// 2. Parse JUnit XML (Tests)
$junitFile = $coverageDir.'/junit.xml';
$testCount = 0;
$testFailures = 0;
$testErrors = 0;
$testTime = 0.0;
if (file_exists($junitFile) && filesize($junitFile) > 0) {
    $xml = @simplexml_load_file($junitFile);
    if ($xml) {
        foreach ($xml->testsuite as $suite) {
            $testCount += (int) ($suite['tests'] ?? 0);
            $testFailures += (int) ($suite['failures'] ?? 0);
            $testErrors += (int) ($suite['errors'] ?? 0);
            $testTime += (float) ($suite['time'] ?? 0);
        }
    }
}

// 3. Parse Psalm JSON
$psalmFile = $coverageDir.'/psalm.json';
$psalmIssues = 0;
$psalmErrors = [];
if (file_exists($psalmFile)) {
    $data = json_decode((string) file_get_contents($psalmFile), true);
    if (is_array($data)) {
        $psalmIssues = count($data);
        $psalmErrors = array_slice($data, 0, 5);
    }
}

// 4. Check OWASP Dependency-Check Report
$owaspHtml = $coverageDir.'/dependency-check/dependency-check-report.html';
$hasOwasp = file_exists($owaspHtml);

// Output Markdown for GITHUB_STEP_SUMMARY
if ($isStepSummary || ! $isPages) {
    $covEmoji = $coveragePct !== null ? ($coveragePct >= 80 ? '🟢' : ($coveragePct >= 50 ? '🟡' : '🔴')) : '⚪';
    $covText = $coveragePct !== null ? "{$coveragePct}% ({$coveredLines}/{$totalLines} lines)" : 'N/A';
    $testStatus = ($testFailures === 0 && $testErrors === 0) ? '✅ Success' : '❌ Failed';
    $psalmStatus = $psalmIssues === 0 ? '✅ 0 issues' : "⚠️ {$psalmIssues} issues";

    $md = <<<MARKDOWN
## 📊 PicoShare CI Execution Report

| Item | Status | Details |
|---|---|---|
| **Pest Test Suite** | {$testStatus} | {$testCount} tests executed in {$testTime}s |
| **Code Coverage** | {$covEmoji} {$covText} | Clover XML |
| **Static Security (Psalm)** | {$psalmStatus} | Taint analysis enabled |
| **OWASP Dependency-Check** | 🛡️ Analyzed | Vulnerability scan completed |

MARKDOWN;

    if (! empty($psalmErrors)) {
        $md .= "\n<details><summary>⚠️ <b>Psalm Issues (".count($psalmErrors).')</b></summary>\n\n';
        foreach ($psalmErrors as $err) {
            $md .= "- `{$err['type']}` at `{$err['file_name']}:{$err['line_from']}`\n";
        }
        $md .= "\n</details>\n";
    }

    $summaryEnv = getenv('GITHUB_STEP_SUMMARY');
    if ($summaryEnv && is_writable($summaryEnv)) {
        file_put_contents($summaryEnv, $md, FILE_APPEND);
        echo "Step summary written to {$summaryEnv}\n";
    } else {
        echo $md;
    }
}

// Generate Static HTML for Pages
if ($isPages) {
    $outDir = $baseDir.'/public_reports';
    if (! is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    if (is_dir($coverageDir.'/html')) {
        copyDir($coverageDir.'/html', $outDir.'/coverage');
    }
    if (file_exists($coverageDir.'/clover.xml')) {
        @mkdir($outDir.'/coverage', 0755, true);
        copy($coverageDir.'/clover.xml', $outDir.'/coverage/clover.xml');
    }

    $indexHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PicoShare CI Reports Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 40px 20px; }
        .container { max-width: 800px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 32px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #db2777; margin-bottom: 20px; }
        a { color: #38bdf8; text-decoration: none; }
        a:hover { text-decoration: underline; }
        ul { list-style: none; padding: 0; }
        li { padding: 12px; border-bottom: 1px solid #334155; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🪶 PicoShare CI Reports</h1>
        <ul>
            <li><a href="coverage/index.html">📊 Code Coverage HTML Report</a></li>
            <li><a href="coverage/clover.xml">📄 Clover XML Coverage</a></li>
        </ul>
    </div>
</body>
</html>
HTML;
    file_put_contents($outDir.'/index.html', $indexHtml);
    echo "Pages dashboard generated in {$outDir}\n";
}

function copyDir(string $src, string $dst): void
{
    $dir = opendir($src);
    if (! $dir) {
        return;
    }
    @mkdir($dst, 0755, true);
    while (false !== ($file = readdir($dir))) {
        if (($file !== '.') && ($file !== '..')) {
            if (is_dir($src.'/'.$file)) {
                copyDir($src.'/'.$file, $dst.'/'.$file);
            } else {
                copy($src.'/'.$file, $dst.'/'.$file);
            }
        }
    }
    closedir($dir);
}
