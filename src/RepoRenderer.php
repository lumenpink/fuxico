<?php

declare(strict_types=1);

namespace Fuxico;

class RepoRenderer
{
    /**
     * Renders a Git repository into a standalone, dual-skin HTML page.
     *
     * @param array{
     *   has_commits: bool,
     *   default_branch: string,
     *   commit_count: int,
     *   commits: array<array{hash: string, author: string, date: string, message: string}>,
     *   files: array<array{name: string, size: int}>,
     *   readme_content: ?string,
     *   readme_filename: ?string,
     *   size: int
     * } $metadata
     * @param array{
     *   title?: string,
     *   slug?: string,
     *   default_theme?: string,
     *   created_at?: int,
     *   base_url?: string
     * } $options
     */
    public static function render(array $metadata, array $options = []): string
    {
        $slug = htmlspecialchars((string) ($options['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $baseUrl = rtrim((string) ($options['base_url'] ?? ''), '/');
        $defaultTheme = ($options['default_theme'] ?? 'zine') === 'acad' ? 'acad' : 'zine';
        $createdAt = $options['created_at'] ?? time();
        $dateFormatted = date('Y-m-d H:i:s', $createdAt);

        $repoNameRaw = (string) ($options['title'] ?? '');
        if ($repoNameRaw === '' || $repoNameRaw === $slug) {
            $repoNameRaw = $slug;
        }
        $repoName = htmlspecialchars($repoNameRaw, ENT_QUOTES, 'UTF-8');

        $cloneUrl = ($baseUrl !== '' && $slug !== '') ? "{$baseUrl}/{$slug}.git" : "{$slug}.git";
        $safeCloneUrl = htmlspecialchars($cloneUrl, ENT_QUOTES, 'UTF-8');

        $zipUrl = ($baseUrl !== '' && $slug !== '') ? "{$baseUrl}/{$slug}/zip" : '#';
        $safeZipUrl = htmlspecialchars($zipUrl, ENT_QUOTES, 'UTF-8');

        $hasCommits = $metadata['has_commits'] ?? false;
        $defaultBranch = htmlspecialchars($metadata['default_branch'] ?? 'main', ENT_QUOTES, 'UTF-8');
        $commitCount = (int) ($metadata['commit_count'] ?? 0);
        $totalBytes = (int) ($metadata['size'] ?? 0);
        $sizeKb = number_format($totalBytes / 1024, 1);

        // Render Readme if available
        $readmeHtml = '';
        if ($hasCommits && ! empty($metadata['readme_content'])) {
            $readmeName = htmlspecialchars($metadata['readme_filename'] ?? 'README.md', ENT_QUOTES, 'UTF-8');
            $parsed = MarkdownRenderer::parse($metadata['readme_content']);
            $renderedBody = $parsed['html'];
            $readmeHtml = <<<HTML
            <div class="repo-card repo-readme-card">
                <div class="repo-card-header">
                    <span class="repo-card-title">📖 {$readmeName}</span>
                </div>
                <div class="repo-card-body markdown-content">
                    {$renderedBody}
                </div>
            </div>
HTML;
        }

        // Render Files Table
        $filesHtml = '';
        if ($hasCommits && ! empty($metadata['files'])) {
            $filesListHtml = '';
            foreach ($metadata['files'] as $f) {
                $filePath = $f['name'];
                $safePath = htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8');
                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                $icon = self::getFileIcon($ext);
                $fileSizeKb = number_format(($f['size'] ?? 0) / 1024, 1);
                $rawFileUrl = ($baseUrl !== '' && $slug !== '')
                    ? "{$baseUrl}/{$slug}/raw/".rawurlencode($filePath)
                    : '#';
                $safeRawFileUrl = htmlspecialchars($rawFileUrl, ENT_QUOTES, 'UTF-8');

                $filesListHtml .= <<<HTML
                <tr class="repo-file-row">
                    <td class="repo-file-name">
                        <span class="repo-file-icon">{$icon}</span>
                        <a href="{$safeRawFileUrl}" class="repo-file-link" title="Ver {$safePath}">{$safePath}</a>
                    </td>
                    <td class="repo-file-size">{$fileSizeKb} KB</td>
                    <td class="repo-file-action">
                        <a href="{$safeRawFileUrl}" class="repo-raw-btn" target="_blank" title="Arquivo raw">raw</a>
                    </td>
                </tr>
HTML;
            }

            $filesCount = count($metadata['files']);
            $filesHtml = <<<HTML
            <div class="repo-card repo-files-card">
                <div class="repo-card-header">
                    <span class="repo-card-title">📁 Arquivos ({$filesCount})</span>
                    <a href="{$safeZipUrl}" class="repo-btn repo-btn-download" download="{$slug}.zip">
                        📦 Baixar ZIP
                    </a>
                </div>
                <div class="repo-table-wrapper">
                    <table class="repo-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Tamanho</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$filesListHtml}
                        </tbody>
                    </table>
                </div>
            </div>
HTML;
        }

        // Render Commits Timeline
        $commitsHtml = '';
        if ($hasCommits && ! empty($metadata['commits'])) {
            $commitsListHtml = '';
            foreach ($metadata['commits'] as $c) {
                $safeHash = htmlspecialchars($c['hash'], ENT_QUOTES, 'UTF-8');
                $safeAuthor = htmlspecialchars($c['author'], ENT_QUOTES, 'UTF-8');
                $safeDate = htmlspecialchars($c['date'], ENT_QUOTES, 'UTF-8');
                $safeMsg = htmlspecialchars($c['message'], ENT_QUOTES, 'UTF-8');

                $commitsListHtml .= <<<HTML
                <div class="repo-commit-item">
                    <div class="repo-commit-left">
                        <span class="repo-commit-hash">{$safeHash}</span>
                        <span class="repo-commit-msg">{$safeMsg}</span>
                    </div>
                    <div class="repo-commit-right">
                        <span class="repo-commit-author">{$safeAuthor}</span>
                        <span class="repo-commit-date">{$safeDate}</span>
                    </div>
                </div>
HTML;
            }

            $commitsCount = count($metadata['commits']);
            $commitsHtml = <<<HTML
            <div class="repo-card repo-commits-card">
                <div class="repo-card-header">
                    <span class="repo-card-title">🌱 Histórico de Commits ({$commitsCount})</span>
                </div>
                <div class="repo-commits-list">
                    {$commitsListHtml}
                </div>
            </div>
HTML;
        }

        // Empty Repo State
        $emptyHtml = '';
        if (! $hasCommits) {
            $emptyHtml = <<<HTML
            <div class="repo-card repo-empty-card">
                <div class="repo-card-header">
                    <span class="repo-card-title">🚀 Repositório Vazio / Quick Setup</span>
                </div>
                <div class="repo-card-body">
                    <p class="repo-empty-desc">Este repositório Git foi criado e aguarda seu primeiro push.</p>
                    
                    <div class="repo-instructions-block">
                        <div class="repo-block-label">Criar novo repositório local e empurrar:</div>
                        <pre class="repo-code-box"><code>mkdir {$slug}
cd {$slug}
git init
echo "# {$slug}" > README.md
git add README.md
git commit -m "primeiro fuxico"
git branch -M main
git remote add origin {$cloneUrl}
git push -u origin main</code></pre>
                    </div>

                    <div class="repo-instructions-block">
                        <div class="repo-block-label">Ou empurrar repositório Git local já existente:</div>
                        <pre class="repo-code-box"><code>git remote add origin {$cloneUrl}
git branch -M main
git push -u origin main</code></pre>
                    </div>
                </div>
            </div>
HTML;
        }

        $sourceStyle = self::getStyles();

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR" data-theme="{$defaultTheme}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$repoName} · Repositório Git · Fuxico</title>
    <meta property="og:type" content="website">
    <meta property="og:title" content="{$repoName} — Repositório Git Fuxico">
    <meta property="og:description" content="Repositório git soberano hospedado via Fuxico">
    <meta property="og:image" content="/{$slug}/og.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$repoName} — Repositório Git Fuxico">
    <meta name="twitter:description" content="Repositório git soberano hospedado via Fuxico">
    <meta name="twitter:image" content="/{$slug}/og.png">
    <style>
        {$sourceStyle}
    </style>
</head>
<body class="theme-{$defaultTheme}">
    <header class="repo-header">
        <div class="repo-header-top">
            <div class="repo-identity">
                <a href="{$baseUrl}/" class="repo-back-link" title="Voltar ao início">← Fuxico</a>
                <span class="repo-badge repo-badge-git">GIT</span>
                <h1 class="repo-name">{$repoName}</h1>
                <span class="repo-branch-badge">branch: {$defaultBranch}</span>
            </div>
            <div class="repo-actions">
                <button class="repo-btn repo-theme-toggle" id="themeToggleBtn" onclick="toggleRepoTheme()">
                    <span id="themeToggleIcon">🎭</span>
                    <span id="themeToggleText">Modo Acadêmico</span>
                </button>
            </div>
        </div>

        <div class="repo-meta-bar">
            <div class="repo-meta-item">🌱 <strong>{$commitCount}</strong> commits</div>
            <div class="repo-meta-item">💾 <strong>{$sizeKb}</strong> KB</div>
            <div class="repo-meta-item">🕒 {$dateFormatted}</div>
        </div>

        <div class="repo-clone-container">
            <div class="repo-clone-wrapper">
                <span class="repo-clone-label">git clone</span>
                <input type="text" readonly value="{$safeCloneUrl}" id="cloneInput" class="repo-clone-input" onclick="this.select()">
                <button class="repo-btn repo-copy-clone-btn" onclick="copyCloneUrl()">Copiar</button>
            </div>
            <div class="repo-remote-hint">
                Dica: <code>git remote add fuxico {$safeCloneUrl}</code>
            </div>
        </div>
    </header>

    <main class="repo-main">
        {$emptyHtml}
        {$readmeHtml}
        {$filesHtml}
        {$commitsHtml}
    </main>

    <footer class="repo-footer">
        <div class="repo-footer-content">
            <p>Hospedado no <strong>Fuxico</strong> — forja efêmera & compartilhamento de arquivos sem caô.</p>
        </div>
    </footer>

    <script>
        function toggleRepoTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme') || 'zine';
            const nextTheme = currentTheme === 'zine' ? 'acad' : 'zine';
            
            html.setAttribute('data-theme', nextTheme);
            document.body.className = 'theme-' + nextTheme;
            
            const btnText = document.getElementById('themeToggleText');
            const btnIcon = document.getElementById('themeToggleIcon');
            if (btnText && btnIcon) {
                if (nextTheme === 'acad') {
                    btnText.textContent = 'Modo Zine Trava';
                    btnIcon.textContent = '✨';
                } else {
                    btnText.textContent = 'Modo Acadêmico';
                    btnIcon.textContent = '🎭';
                }
            }
            
            try {
                const url = new URL(window.location);
                if (nextTheme === 'acad') {
                    url.searchParams.set('acad', 'true');
                } else {
                    url.searchParams.delete('acad');
                }
                window.history.replaceState({}, '', url);
            } catch (e) {}
        }

        function copyCloneUrl() {
            const input = document.getElementById('cloneInput');
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(input.value).then(() => {
                const btn = document.querySelector('.repo-copy-clone-btn');
                if (btn) {
                    const original = btn.textContent;
                    btn.textContent = 'Copiado!';
                    setTimeout(() => { btn.textContent = original; }, 2000);
                }
            }).catch(() => {
                document.execCommand('copy');
            });
        }

        // Initialize URL ?acad=true override
        (function() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('acad') === 'true' || params.get('acad') === '1') {
                document.documentElement.setAttribute('data-theme', 'acad');
                document.body.className = 'theme-acad';
                const btnText = document.getElementById('themeToggleText');
                const btnIcon = document.getElementById('themeToggleIcon');
                if (btnText && btnIcon) {
                    btnText.textContent = 'Modo Zine Trava';
                    btnIcon.textContent = '✨';
                }
            }
        })();
    </script>
</body>
</html>
HTML;
    }

    public static function getFileIcon(string $ext): string
    {
        return match ($ext) {
            'php' => '🐘',
            'rs' => '🦀',
            'go' => '🐹',
            'py' => '🐍',
            'js', 'mjs', 'cjs' => '🟨',
            'ts', 'mts', 'cts' => '🔷',
            'html', 'htm' => '🌐',
            'css', 'scss', 'sass' => '🎨',
            'json' => '📋',
            'md', 'markdown' => '📝',
            'sh', 'bash', 'zsh' => '💻',
            'c', 'h', 'cpp', 'hpp' => '⚙️',
            'sql' => '🗄️',
            'yml', 'yaml', 'toml' => '⚙️',
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp' => '🖼️',
            'zip', 'tar', 'gz' => '📦',
            default => '📄',
        };
    }

    public static function getStyles(): string
    {
        return <<<'CSS'
/* Dual-Skin Styles for Fuxico Git Repository: Zine Trava (default) vs Acadêmico Depressivo */

:root {
    --zine-bg: #121113;
    --zine-surface: #1e1c22;
    --zine-surface-2: #28252e;
    --zine-border: #443e52;
    --zine-text: #f3f0f7;
    --zine-text-muted: #a39cb2;
    --zine-pink: #ff2a85;
    --zine-purple: #9d4edd;
    --zine-cyan: #05d9e8;
    --zine-emerald: #00f0b5;
    --zine-mono: "Space Mono", "JetBrains Mono", Consolas, monospace;
    --zine-sans: "Work Sans", system-ui, sans-serif;

    --acad-bg: #f7f7f7;
    --acad-surface: #ffffff;
    --acad-surface-2: #eeeeee;
    --acad-border: #cccccc;
    --acad-text: #222222;
    --acad-text-muted: #666666;
    --acad-serif: "Times New Roman", Times, "Computer Modern", Georgia, serif;
    --acad-mono: "Courier New", Courier, monospace;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: var(--zine-sans);
    background-color: var(--zine-bg);
    color: var(--zine-text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    line-height: 1.5;
}

/* =========================================
   ZINE THEME (Default)
   ========================================= */
body.theme-zine {
    background-color: var(--zine-bg);
    color: var(--zine-text);
}

.theme-zine .repo-header {
    background: linear-gradient(180deg, #18161b 0%, #121113 100%);
    border-bottom: 2px solid var(--zine-border);
    padding: 1.5rem 2rem;
}

.theme-zine .repo-badge-git {
    background: #00f0b5;
    color: #0b291e;
    font-weight: 800;
    font-family: var(--zine-mono);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    letter-spacing: 1px;
}

.theme-zine .repo-branch-badge {
    background: var(--zine-surface-2);
    color: var(--zine-cyan);
    font-family: var(--zine-mono);
    padding: 0.2rem 0.6rem;
    border-radius: 4px;
    font-size: 0.8rem;
    border: 1px solid var(--zine-border);
}

.theme-zine .repo-name {
    font-family: var(--zine-mono);
    color: #fff;
    font-size: 1.6rem;
    font-weight: 800;
    text-shadow: 0 0 10px rgba(255, 42, 133, 0.3);
}

.theme-zine .repo-back-link {
    color: var(--zine-pink);
    text-decoration: none;
    font-family: var(--zine-mono);
    font-size: 0.9rem;
    font-weight: 700;
    margin-right: 0.5rem;
}

.theme-zine .repo-back-link:hover {
    text-decoration: underline;
}

.theme-zine .repo-card {
    background: var(--zine-surface);
    border: 1px solid var(--zine-border);
    border-radius: 8px;
    margin-bottom: 1.5rem;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
}

.theme-zine .repo-card-header {
    background: var(--zine-surface-2);
    border-bottom: 1px solid var(--zine-border);
    padding: 0.8rem 1.2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.theme-zine .repo-card-title {
    font-family: var(--zine-mono);
    font-weight: 700;
    color: var(--zine-cyan);
    font-size: 0.95rem;
}

.theme-zine .repo-clone-wrapper {
    background: #0d0c0e;
    border: 1px solid var(--zine-border);
    border-radius: 6px;
    display: flex;
    align-items: center;
    overflow: hidden;
    max-width: 650px;
    margin-top: 0.8rem;
}

.theme-zine .repo-clone-label {
    background: var(--zine-surface-2);
    color: var(--zine-text-muted);
    font-family: var(--zine-mono);
    font-size: 0.8rem;
    padding: 0.5rem 0.8rem;
    border-right: 1px solid var(--zine-border);
    user-select: none;
}

.theme-zine .repo-clone-input {
    background: transparent;
    border: none;
    color: #fff;
    font-family: var(--zine-mono);
    font-size: 0.85rem;
    padding: 0.5rem 0.8rem;
    flex: 1;
    outline: none;
}

.theme-zine .repo-remote-hint {
    color: var(--zine-text-muted);
    font-size: 0.8rem;
    margin-top: 0.4rem;
}

.theme-zine .repo-remote-hint code {
    color: var(--zine-emerald);
    background: rgba(0, 240, 181, 0.1);
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-family: var(--zine-mono);
}

.theme-zine .repo-btn {
    background: var(--zine-surface-2);
    color: var(--zine-text);
    border: 1px solid var(--zine-border);
    border-radius: 6px;
    padding: 0.45rem 0.9rem;
    font-family: var(--zine-mono);
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.theme-zine .repo-btn:hover {
    background: var(--zine-pink);
    color: #fff;
    border-color: var(--zine-pink);
}

.theme-zine .repo-btn-download {
    background: rgba(0, 240, 181, 0.15);
    color: var(--zine-emerald);
    border-color: var(--zine-emerald);
}

.theme-zine .repo-btn-download:hover {
    background: var(--zine-emerald);
    color: #000;
    border-color: var(--zine-emerald);
}

.theme-zine .repo-table {
    width: 100%;
    border-collapse: collapse;
    font-family: var(--zine-mono);
    font-size: 0.85rem;
}

.theme-zine .repo-table th {
    text-align: left;
    padding: 0.6rem 1.2rem;
    color: var(--zine-text-muted);
    border-bottom: 1px solid var(--zine-border);
    font-size: 0.75rem;
    text-transform: uppercase;
}

.theme-zine .repo-table td {
    padding: 0.6rem 1.2rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.theme-zine .repo-file-row:hover td {
    background: rgba(255, 42, 133, 0.05);
}

.theme-zine .repo-file-link {
    color: #fff;
    text-decoration: none;
}

.theme-zine .repo-file-link:hover {
    color: var(--zine-cyan);
    text-decoration: underline;
}

.theme-zine .repo-raw-btn {
    color: var(--zine-text-muted);
    font-size: 0.75rem;
    text-decoration: none;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    border: 1px solid var(--zine-border);
}

.theme-zine .repo-raw-btn:hover {
    color: #fff;
    background: var(--zine-surface-2);
}

.theme-zine .repo-commit-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.7rem 1.2rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 0.85rem;
}

.theme-zine .repo-commit-item:hover {
    background: rgba(157, 78, 221, 0.08);
}

.theme-zine .repo-commit-hash {
    font-family: var(--zine-mono);
    color: var(--zine-cyan);
    background: rgba(5, 217, 232, 0.1);
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
    margin-right: 0.6rem;
    font-size: 0.8rem;
}

.theme-zine .repo-commit-msg {
    color: #fff;
    font-weight: 500;
}

.theme-zine .repo-commit-author {
    color: var(--zine-text-muted);
    margin-right: 0.8rem;
    font-size: 0.8rem;
}

.theme-zine .repo-commit-date {
    color: var(--zine-text-muted);
    font-family: var(--zine-mono);
    font-size: 0.75rem;
}

.theme-zine .repo-code-box {
    background: #0d0c0e;
    border: 1px solid var(--zine-border);
    border-radius: 6px;
    padding: 1rem;
    font-family: var(--zine-mono);
    font-size: 0.85rem;
    color: #00f0b5;
    overflow-x: auto;
    line-height: 1.6;
}

/* =========================================
   ACADEMIC DEPRESSIVE THEME (?acad=true)
   ========================================= */
body.theme-acad {
    background-color: var(--acad-bg);
    color: var(--acad-text);
    font-family: var(--acad-serif);
}

.theme-acad .repo-header {
    background: #ffffff;
    border-bottom: 2px solid #000;
    padding: 1.5rem 2rem;
}

.theme-acad .repo-badge-git {
    border: 1px solid #000;
    color: #000;
    background: transparent;
    font-family: var(--acad-mono);
    padding: 0.15rem 0.4rem;
    font-size: 0.75rem;
    border-radius: 0;
}

.theme-acad .repo-branch-badge {
    border: 1px solid #666;
    color: #333;
    background: #f0f0f0;
    font-family: var(--acad-mono);
    padding: 0.15rem 0.4rem;
    font-size: 0.75rem;
    border-radius: 0;
}

.theme-acad .repo-name {
    font-family: var(--acad-serif);
    color: #000;
    font-size: 1.8rem;
    font-weight: 700;
}

.theme-acad .repo-back-link {
    color: #000;
    text-decoration: underline;
    font-family: var(--acad-mono);
    font-size: 0.85rem;
    margin-right: 0.5rem;
}

.theme-acad .repo-card {
    background: #ffffff;
    border: 1px solid #bbb;
    border-radius: 0;
    margin-bottom: 1.5rem;
    box-shadow: none;
}

.theme-acad .repo-card-header {
    background: #e8e8e8;
    border-bottom: 1px solid #bbb;
    padding: 0.6rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.theme-acad .repo-card-title {
    font-family: var(--acad-serif);
    font-weight: 700;
    color: #000;
    font-size: 1rem;
}

.theme-acad .repo-clone-wrapper {
    background: #f9f9f9;
    border: 1px solid #999;
    border-radius: 0;
    display: flex;
    align-items: center;
    max-width: 650px;
    margin-top: 0.8rem;
}

.theme-acad .repo-clone-label {
    background: #e0e0e0;
    color: #000;
    font-family: var(--acad-mono);
    font-size: 0.8rem;
    padding: 0.4rem 0.6rem;
    border-right: 1px solid #999;
}

.theme-acad .repo-clone-input {
    background: transparent;
    border: none;
    color: #000;
    font-family: var(--acad-mono);
    font-size: 0.85rem;
    padding: 0.4rem 0.6rem;
    flex: 1;
    outline: none;
}

.theme-acad .repo-remote-hint {
    color: #555;
    font-size: 0.85rem;
    margin-top: 0.4rem;
}

.theme-acad .repo-remote-hint code {
    color: #000;
    background: #e4e4e4;
    padding: 0.15rem 0.4rem;
    border: 1px solid #ccc;
    font-family: var(--acad-mono);
}

.theme-acad .repo-btn {
    background: #f0f0f0;
    color: #000;
    border: 1px solid #888;
    border-radius: 0;
    padding: 0.35rem 0.7rem;
    font-family: var(--acad-serif);
    font-size: 0.85rem;
    cursor: pointer;
    text-decoration: none;
}

.theme-acad .repo-btn:hover {
    background: #000;
    color: #fff;
}

.theme-acad .repo-table {
    width: 100%;
    border-collapse: collapse;
    font-family: var(--acad-serif);
    font-size: 0.95rem;
}

.theme-acad .repo-table th {
    text-align: left;
    padding: 0.5rem 1rem;
    color: #000;
    border-bottom: 2px solid #000;
}

.theme-acad .repo-table td {
    padding: 0.5rem 1rem;
    border-bottom: 1px solid #e0e0e0;
}

.theme-acad .repo-file-row:hover td {
    background: #f2f2f2;
}

.theme-acad .repo-file-link {
    color: #000;
    text-decoration: underline;
}

.theme-acad .repo-raw-btn {
    color: #444;
    border: 1px solid #999;
    padding: 0.1rem 0.3rem;
    font-family: var(--acad-mono);
    font-size: 0.75rem;
    text-decoration: none;
}

.theme-acad .repo-commit-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.6rem 1rem;
    border-bottom: 1px solid #e0e0e0;
}

.theme-acad .repo-commit-hash {
    font-family: var(--acad-mono);
    border: 1px solid #999;
    padding: 0.1rem 0.3rem;
    margin-right: 0.5rem;
    font-size: 0.8rem;
}

.theme-acad .repo-commit-msg {
    color: #000;
}

.theme-acad .repo-commit-author {
    color: #666;
    margin-right: 0.5rem;
    font-size: 0.85rem;
}

.theme-acad .repo-commit-date {
    color: #666;
    font-family: var(--acad-mono);
    font-size: 0.8rem;
}

.theme-acad .repo-code-box {
    background: #ffffff;
    border: 1px solid #999;
    padding: 0.8rem;
    font-family: var(--acad-mono);
    font-size: 0.85rem;
    color: #000;
    line-height: 1.5;
}

/* =========================================
   COMMON LAYOUT
   ========================================= */
.repo-header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.repo-identity {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}

.repo-meta-bar {
    display: flex;
    gap: 1.5rem;
    margin-top: 0.8rem;
    font-size: 0.85rem;
    opacity: 0.85;
}

.repo-main {
    max-width: 1200px;
    width: 100%;
    margin: 1.5rem auto;
    padding: 0 1.5rem;
    flex: 1;
}

.repo-card-body {
    padding: 1.2rem;
}

.repo-instructions-block {
    margin-top: 1rem;
}

.repo-block-label {
    font-weight: 700;
    font-size: 0.85rem;
    margin-bottom: 0.4rem;
    opacity: 0.8;
}

.repo-footer {
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    padding: 1.5rem;
    text-align: center;
    font-size: 0.8rem;
    opacity: 0.7;
    margin-top: auto;
}

.theme-acad .repo-footer {
    border-top: 1px solid #ccc;
    background: #fafafa;
}

.markdown-content {
    line-height: 1.6;
}

.markdown-content h1, .markdown-content h2, .markdown-content h3 {
    margin-top: 1rem;
    margin-bottom: 0.5rem;
}

.markdown-content pre {
    background: rgba(0, 0, 0, 0.3);
    padding: 0.8rem;
    border-radius: 4px;
    overflow-x: auto;
    margin: 0.8rem 0;
}

.theme-acad .markdown-content pre {
    background: #f4f4f4;
    border: 1px solid #ddd;
}
CSS;
    }
}
