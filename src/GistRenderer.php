<?php

declare(strict_types=1);

namespace Fuxico;

class GistRenderer
{
    /**
     * Renders a multi-file Gist into a standalone, dual-skin HTML page with interactive tabs.
     *
     * @param  array<string, string>  $files  Map of relative filename => file content
     * @param array{
     *   title?: string,
     *   slug?: string,
     *   default_theme?: string,
     *   created_at?: int,
     *   base_url?: string
     * } $options
     */
    public static function render(array $files, array $options = []): string
    {
        $slug = htmlspecialchars((string) ($options['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $baseUrl = rtrim((string) ($options['base_url'] ?? ''), '/');
        $defaultTheme = ($options['default_theme'] ?? 'zine') === 'acad' ? 'acad' : 'zine';
        $createdAt = $options['created_at'] ?? time();
        $dateFormatted = date('Y-m-d H:i:s', $createdAt);

        // Find primary title
        $firstFilename = array_key_first($files) ?: 'Gist';
        $titleRaw = (string) ($options['title'] ?? '');
        if ($titleRaw === '' || $titleRaw === $slug) {
            $titleRaw = $firstFilename;
        }
        $title = htmlspecialchars($titleRaw, ENT_QUOTES, 'UTF-8');

        $totalFiles = count($files);
        $totalBytes = 0;
        foreach ($files as $content) {
            $totalBytes += strlen($content);
        }
        $totalSizeKb = number_format($totalBytes / 1024, 1);

        // Order files: README files first, then alphabetical
        uksort($files, function ($a, $b) {
            $isReadmeA = str_starts_with(strtoupper($a), 'README');
            $isReadmeB = str_starts_with(strtoupper($b), 'README');
            if ($isReadmeA && ! $isReadmeB) {
                return -1;
            }
            if (! $isReadmeA && $isReadmeB) {
                return 1;
            }

            return strcasecmp($a, $b);
        });

        // Build file tabs and panes
        $tabsHtml = '';
        $panesHtml = '';
        $fileIndex = 0;

        $fileOptionsHtml = '';
        foreach (array_keys($files) as $fname) {
            $safeFname = htmlspecialchars($fname, ENT_QUOTES, 'UTF-8');
            $fileOptionsHtml .= "<option value=\"{$safeFname}\">{$safeFname}</option>";
        }

        // Variáveis JS para o modal de embed
        $baseUrlJs = json_encode($baseUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $slugJs = json_encode($slug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($files as $filename => $content) {
            $fileId = 'file-'.preg_replace('/[^a-zA-Z0-9_\-]+/', '-', $filename).'-'.$fileIndex;
            $safeName = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $isMd = in_array($ext, ['md', 'markdown'], true);
            $icon = self::getFileIcon($ext);
            $lineCount = substr_count($content, "\n") + (strlen($content) > 0 && ! str_ends_with($content, "\n") ? 1 : 0);
            $sizeKb = number_format(strlen($content) / 1024, 1);
            $isActive = $fileIndex === 0 ? ' active' : '';

            $rawUrl = ($baseUrl !== '' && $slug !== '')
                ? "{$baseUrl}/{$slug}/raw/".rawurlencode($filename)
                : '#';

            $tabsHtml .= <<<HTML
            <button class="gist-tab{$isActive}" data-target="{$fileId}" onclick="switchGistTab(this, '{$fileId}')">
                <span class="gist-tab-icon">{$icon}</span>
                <span class="gist-tab-name">{$safeName}</span>
                <span class="gist-tab-lines">{$lineCount}L</span>
            </button>
HTML;

            // Generate lines & code content
            $lines = explode("\n", $content);
            $lineNumbersHtml = '';
            $codeLinesHtml = '';
            for ($i = 1; $i <= count($lines); $i++) {
                $lineNumbersHtml .= "<span>{$i}</span>\n";
            }
            $escapedCode = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

            $previewToggleHtml = '';
            $previewBodyHtml = '';
            if ($isMd) {
                $parsedMd = MarkdownRenderer::parse($content);
                $renderedBody = $parsedMd['html'];
                $previewToggleHtml = <<<HTML
                <div class="gist-preview-toggle">
                    <button class="preview-btn active" onclick="toggleMdView(this, '{$fileId}', 'preview')">Visualização</button>
                    <button class="preview-btn" onclick="toggleMdView(this, '{$fileId}', 'source')">Código</button>
                </div>
HTML;
                $previewBodyHtml = <<<HTML
                <div id="{$fileId}-preview" class="gist-md-preview">
                    {$renderedBody}
                </div>
HTML;
            }

            $sourceStyle = $isMd ? 'display:none;' : '';
            $panesHtml .= <<<HTML
            <article id="{$fileId}" class="gist-pane{$isActive}" data-file="{$safeName}">
                <div class="gist-pane-header">
                    <div class="gist-pane-title">
                        <span class="gist-file-icon">{$icon}</span>
                        <strong>{$safeName}</strong>
                        <span class="gist-meta-badge">{$lineCount} linhas</span>
                        <span class="gist-meta-badge">{$sizeKb} KB</span>
                    </div>
                    <div class="gist-pane-actions">
                        {$previewToggleHtml}
                        <button class="btn-gist-action" onclick="openFileEmbedModal('{$safeName}')">🏷️ Embed</button>
                        <button class="btn-gist-action" onclick="copyFileContent('{$fileId}')">📋 Copiar</button>
                        <a class="btn-gist-action" href="{$rawUrl}" target="_blank" rel="noopener noreferrer">📄 Raw</a>
                    </div>
                </div>
                {$previewBodyHtml}
                <div id="{$fileId}-source" class="gist-code-box" style="{$sourceStyle}">
                    <div class="gist-line-numbers" aria-hidden="true">{$lineNumbersHtml}</div>
                    <pre class="gist-code-pre"><code id="{$fileId}-code" class="language-{$ext}">{$escapedCode}</code></pre>
                </div>
            </article>
HTML;
            $fileIndex++;
        }

        $zipUrl = ($baseUrl !== '' && $slug !== '') ? "{$baseUrl}/{$slug}/zip" : '#';
        $shortUrl = ($baseUrl !== '' && $slug !== '') ? "{$baseUrl}/{$slug}" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR" data-theme="{$defaultTheme}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — Fuxico Gist</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Grotesk:wght@400;600;700&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Noto+Serif:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <script>
        (function() {
            try {
                const params = new URLSearchParams(window.location.search);
                const saved = localStorage.getItem('fuxico_theme');
                let theme = '{$defaultTheme}';
                if (params.get('acad') === 'true' || params.get('theme') === 'acad' || params.has('acad')) {
                    theme = 'acad';
                } else if (params.get('theme') === 'zine') {
                    theme = 'zine';
                } else if (saved === 'acad' || saved === 'zine') {
                    theme = saved;
                }
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>
    <style>
        /* Base Resets & Transitions */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ─── DUAL SKIN: 1. TRAVA ZINE (FUXICO STANDARD) ─── */
        [data-theme="zine"] {
            --bg-body: #121016;
            --bg-card: #1a1622;
            --bg-surface: #221c2e;
            --text-main: #f5f4ef;
            --text-muted: #9f97ad;
            --text-link: #e2f835;
            --acid-yellow: #e2f835;
            --acid-hover: #ccee22;
            --electric-pink: #ff2a85;
            --tape-black: #0a080d;
            --border-color: #383047;
            --border-hard: 2px solid #000;
            --shadow-hard: 4px 4px 0px #0a080d;
            --shadow-accent: 5px 5px 0px var(--electric-pink);
            --font-heading: 'Anton', -apple-system, sans-serif;
            --font-sub: 'Space Grotesk', -apple-system, sans-serif;
            --font-body: 'Space Grotesk', -apple-system, sans-serif;
            --font-mono: 'Space Mono', monospace;
            --code-bg: #09080d;
            --callout-bg: #1c1726;
            --mark-bg: rgba(226, 248, 53, 0.3);
            --mark-color: #e2f835;
            --badge-bg: #272033;
            --line-num-color: #5d5272;
        }

        /* ─── DUAL SKIN: 2. ACADÊMICO DEPRESSIVO MONOCROMÁTICO (?acad=true) ─── */
        [data-theme="acad"] {
            --bg-body: #fcfcfc;
            --bg-card: #ffffff;
            --bg-surface: #f4f4f5;
            --text-main: #18181b;
            --text-muted: #52525b;
            --text-link: #09090b;
            --acid-yellow: #18181b;
            --acid-hover: #27272a;
            --electric-pink: #71717a;
            --tape-black: #f4f4f5;
            --border-color: #e4e4e7;
            --border-hard: 1px solid #d4d4d8;
            --shadow-hard: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-accent: 0 2px 4px rgba(0,0,0,0.05);
            --font-heading: 'Noto Serif', serif;
            --font-sub: 'Noto Serif', serif;
            --font-body: 'Noto Serif', Georgia, serif;
            --font-mono: 'Space Mono', monospace;
            --code-bg: #f8fafc;
            --callout-bg: #f4f4f5;
            --mark-bg: #e4e4e7;
            --mark-color: #09090b;
            --badge-bg: #e4e4e7;
            --line-num-color: #a1a1aa;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-body);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .trans-stripe {
            display: flex;
            width: 100%;
            height: 6px;
            flex-shrink: 0;
        }
        .trans-stripe-line { flex: 1; height: 100%; }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }

        .gist-container {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 20px 48px;
            flex: 1;
        }

        /* Header */
        .gist-header {
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-hard);
            padding: 24px;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .gist-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }

        .gist-title-wrap {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .gist-brand-badge {
            display: inline-block;
            align-self: flex-start;
            background: var(--tape-black);
            color: var(--acid-yellow);
            font-family: var(--font-mono);
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border: 1px solid var(--border-color);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .gist-title {
            font-family: var(--font-heading);
            font-size: 1.8rem;
            line-height: 1.2;
            color: var(--text-main);
            letter-spacing: 0.5px;
        }

        .gist-header-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.85rem;
            color: var(--text-muted);
            align-items: center;
        }

        .gist-header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn-header {
            background: var(--tape-black);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 8px 14px;
            font-family: var(--font-mono);
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 2px 2px 0px rgba(0,0,0,0.5);
            transition: all 0.15s ease;
        }

        .btn-header:hover {
            border-color: var(--acid-yellow);
            color: var(--acid-yellow);
            transform: translate(-1px, -1px);
        }

        .btn-zip {
            background: var(--electric-pink);
            color: #fff;
            border-color: #000;
        }
        .btn-zip:hover {
            background: #ff4797;
            color: #fff;
        }

        /* Tabs Nav */
        .gist-tabs-nav {
            display: flex;
            gap: 4px;
            overflow-x: auto;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 16px;
            padding-bottom: 0;
        }

        .gist-tab {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-bottom: none;
            color: var(--text-muted);
            padding: 10px 16px;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .gist-tab:hover {
            color: var(--text-main);
            background: var(--bg-surface);
        }

        .gist-tab.active {
            background: var(--bg-surface);
            color: var(--acid-yellow);
            border-top: 3px solid var(--acid-yellow);
            border-color: var(--border-color) var(--border-color) transparent;
        }

        [data-theme="acad"] .gist-tab.active {
            border-top: 3px solid #18181b;
            color: #18181b;
        }

        .gist-tab-lines {
            font-size: 0.72rem;
            opacity: 0.7;
            font-weight: 400;
            background: var(--badge-bg);
            padding: 2px 6px;
            border-radius: 2px;
        }

        /* File Panes */
        .gist-pane {
            display: none;
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-hard);
            margin-bottom: 24px;
        }

        .gist-pane.active {
            display: block;
        }

        .gist-pane-header {
            background: var(--tape-black);
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 10px;
        }

        .gist-pane-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: var(--font-mono);
            font-size: 0.9rem;
        }

        .gist-meta-badge {
            font-size: 0.75rem;
            color: var(--text-muted);
            background: var(--badge-bg);
            padding: 2px 8px;
            border: 1px solid var(--border-color);
        }

        .gist-pane-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .gist-preview-toggle {
            display: inline-flex;
            border: 1px solid var(--border-color);
            background: var(--bg-surface);
        }

        .preview-btn {
            background: transparent;
            border: none;
            padding: 4px 10px;
            font-family: var(--font-mono);
            font-size: 0.78rem;
            color: var(--text-muted);
            cursor: pointer;
        }

        .preview-btn.active {
            background: var(--acid-yellow);
            color: #000;
            font-weight: 700;
        }

        [data-theme="acad"] .preview-btn.active {
            background: #18181b;
            color: #fff;
        }

        .btn-gist-action {
            background: var(--bg-surface);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 5px 12px;
            font-family: var(--font-mono);
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-gist-action:hover {
            border-color: var(--acid-yellow);
            color: var(--acid-yellow);
        }

        /* Code View */
        .gist-code-box {
            display: flex;
            overflow-x: auto;
            background: var(--code-bg);
            font-family: var(--font-mono);
            font-size: 0.88rem;
            line-height: 1.5;
        }

        .gist-line-numbers {
            user-select: none;
            text-align: right;
            padding: 14px 12px 14px 16px;
            color: var(--line-num-color);
            border-right: 1px solid var(--border-color);
            background: rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
        }

        .gist-line-numbers span {
            display: block;
            height: 1.5em;
        }

        .gist-code-pre {
            flex: 1;
            padding: 14px 16px;
            overflow-x: auto;
            margin: 0;
            background: transparent;
        }

        .gist-code-pre code {
            font-family: inherit;
            color: var(--text-main);
            white-space: pre;
        }

        /* Markdown Preview Inside Gist */
        .gist-md-preview {
            padding: 24px;
            background: var(--bg-card);
            line-height: 1.7;
        }
        .gist-md-preview h1, .gist-md-preview h2, .gist-md-preview h3 {
            margin-top: 1.2em;
            margin-bottom: 0.6em;
            color: var(--text-main);
            font-family: var(--font-heading);
        }
        .gist-md-preview p { margin-bottom: 1em; }
        .gist-md-preview ul, .gist-md-preview ol { margin-left: 1.5em; margin-bottom: 1em; }
        .gist-md-preview code {
            background: var(--bg-surface);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: var(--font-mono);
            font-size: 0.88em;
        }
        .gist-md-preview pre {
            background: var(--code-bg);
            padding: 14px;
            border: 1px solid var(--border-color);
            overflow-x: auto;
            margin-bottom: 1em;
        }

        /* Embed Modal */
        .gist-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .gist-modal-overlay.active {
            display: flex;
        }
        .gist-modal-card {
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            width: 100%;
            max-width: 580px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            position: relative;
        }
        .gist-modal-close {
            position: absolute;
            top: 14px;
            right: 16px;
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: var(--text-muted);
            cursor: pointer;
        }
        .gist-modal-close:hover {
            color: var(--acid-yellow);
        }
        .gist-modal-title {
            font-family: var(--font-heading);
            font-size: 1.3rem;
            color: var(--acid-yellow);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .gist-modal-desc {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-bottom: 18px;
        }
        .gist-modal-field {
            margin-bottom: 14px;
        }
        .gist-modal-field label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .gist-modal-select, .gist-modal-input {
            width: 100%;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 8px 12px;
            font-family: inherit;
            font-size: 0.9rem;
            border-radius: 4px;
            outline: none;
        }
        .gist-modal-input {
            font-family: var(--font-mono);
            font-size: 0.85rem;
            background: var(--code-bg);
            border-color: var(--acid-yellow);
        }
        .gist-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        /* Footer */
        .gist-footer {
            margin-top: 32px;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
            padding-top: 16px;
        }
        .gist-footer a { color: var(--acid-yellow); text-decoration: none; }
        .gist-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
        <div class="trans-stripe-line blue"></div>
        <div class="trans-stripe-line pink"></div>
        <div class="trans-stripe-line white"></div>
        <div class="trans-stripe-line pink"></div>
        <div class="trans-stripe-line blue"></div>
    </div>

    <div class="gist-container">
        <header class="gist-header">
            <div class="gist-header-top">
                <div class="gist-title-wrap">
                    <span class="gist-brand-badge">⚡ FUXICO GIST // MULTI-ARQUIVO</span>
                    <h1 class="gist-title">{$title}</h1>
                </div>
                <div class="gist-header-actions">
                    <button class="btn-header" onclick="openEmbedModal()">🏷️ Embed</button>
                    <button class="btn-header" onclick="copyGistUrl()">🔗 Copiar Link</button>
                    <a class="btn-header btn-zip" href="{$zipUrl}">📦 Baixar ZIP</a>
                    <button class="btn-header" onclick="toggleTheme()" id="themeBtn">🎨 Alternar Tema</button>
                </div>
            </div>
            <div class="gist-header-meta">
                <span>📁 {$totalFiles} arquivos</span>
                <span>•</span>
                <span>💾 {$totalSizeKb} KB</span>
                <span>•</span>
                <span>📅 {$dateFormatted}</span>
                <span>•</span>
                <span style="font-family:var(--font-mono);">/{$slug}</span>
            </div>
        </header>

        <nav class="gist-tabs-nav" aria-label="Arquivos do Gist">
            {$tabsHtml}
        </nav>

        <main id="gistPanes">
            {$panesHtml}
        </main>

        <footer class="gist-footer">
            <p><strong>FUXICO</strong> // Ingestão efêmera &amp; gists multi-arquivo // Orgulhosamente travesti latina</p>
        </footer>
    </div>

    <div id="gistEmbedModal" class="gist-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="embedModalTitle" onclick="if(event.target===this)closeEmbedModal()">
        <div class="gist-modal-card">
            <button class="gist-modal-close" onclick="closeEmbedModal()" aria-label="Fechar modal">✕</button>
            <div class="gist-modal-title">🏷️ <span id="embedModalTitle">Embed do Gist</span></div>
            <p class="gist-modal-desc">Cole o snippet abaixo em qualquer página HTML para incorporar este gist.</p>

            <div class="gist-modal-field">
                <label for="embedFileSelect">Arquivo</label>
                <select id="embedFileSelect" class="gist-modal-select" onchange="updateEmbedSnippet()">
                    <option value="">Todos os arquivos</option>
                    {$fileOptionsHtml}
                </select>
            </div>

            <div class="gist-modal-field">
                <label for="embedThemeSelect">Tema</label>
                <select id="embedThemeSelect" class="gist-modal-select" onchange="updateEmbedSnippet()">
                    <option value="">Padrão</option>
                    <option value="zine">Zine (escuro)</option>
                    <option value="acad">Acad (claro)</option>
                </select>
            </div>

            <div class="gist-modal-field">
                <label for="embedSnippetInput">Snippet</label>
                <input id="embedSnippetInput" class="gist-modal-input" type="text" readonly onclick="this.select()" aria-label="Snippet de embed" />
            </div>

            <div class="gist-modal-actions">
                <button class="btn-header" onclick="closeEmbedModal()">Fechar</button>
                <button class="btn-header" onclick="copyEmbedSnippet()">📋 Copiar</button>
            </div>
        </div>
    </div>

    <script>
        function switchGistTab(btn, targetId) {
            document.querySelectorAll('.gist-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.gist-pane').forEach(p => p.classList.remove('active'));

            btn.classList.add('active');
            const targetPane = document.getElementById(targetId);
            if (targetPane) {
                targetPane.classList.add('active');
                if (history.replaceState) {
                    const cleanName = targetPane.getAttribute('data-file');
                    history.replaceState(null, '', '#' + encodeURIComponent(cleanName));
                }
            }
        }

        function toggleMdView(btn, fileId, mode) {
            const previewEl = document.getElementById(fileId + '-preview');
            const sourceEl = document.getElementById(fileId + '-source');
            const parent = btn.parentElement;
            parent.querySelectorAll('.preview-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            if (mode === 'preview') {
                if (previewEl) previewEl.style.display = 'block';
                if (sourceEl) sourceEl.style.display = 'none';
            } else {
                if (previewEl) previewEl.style.display = 'none';
                if (sourceEl) sourceEl.style.display = 'flex';
            }
        }

        function copyFileContent(fileId) {
            const codeEl = document.getElementById(fileId + '-code');
            if (!codeEl) return;
            navigator.clipboard.writeText(codeEl.innerText).then(() => {
                const btn = event.target;
                const oldText = btn.innerText;
                btn.innerText = '✔ Copiado!';
                setTimeout(() => { btn.innerText = oldText; }, 2000);
            }).catch(err => {
                alert('Erro ao copiar: ' + err);
            });
        }

        function copyGistUrl() {
            navigator.clipboard.writeText(window.location.href.split('#')[0]).then(() => {
                const btn = event.target;
                const oldText = btn.innerText;
                btn.innerText = '✔ Copiado!';
                setTimeout(() => { btn.innerText = oldText; }, 2000);
            });
        }

        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme') || 'zine';
            const next = current === 'zine' ? 'acad' : 'zine';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('fuxico_theme', next);
        }

        // Funções do modal de embed
        const _embedBaseUrl = {$baseUrlJs};
        const _embedSlug    = {$slugJs};

        function openEmbedModal() {
            document.getElementById('gistEmbedModal').classList.add('active');
            updateEmbedSnippet();
        }

        function openFileEmbedModal(filename) {
            const sel = document.getElementById('embedFileSelect');
            for (let i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === filename) { sel.selectedIndex = i; break; }
            }
            openEmbedModal();
        }

        function updateEmbedSnippet() {
            const file  = document.getElementById('embedFileSelect').value;
            const theme = document.getElementById('embedThemeSelect').value;
            let url = _embedBaseUrl + '/' + _embedSlug + '/embed.js';
            const params = [];
            if (file)  params.push('file='  + encodeURIComponent(file));
            if (theme) params.push('theme=' + encodeURIComponent(theme));
            if (params.length) url += '?' + params.join('&');
            document.getElementById('embedSnippetInput').value = '<script src="' + url + '"><\/script>';
        }

        function copyEmbedSnippet() {
            const input = document.getElementById('embedSnippetInput');
            navigator.clipboard.writeText(input.value).then(() => {
                const btn = event.target;
                const old = btn.innerText;
                btn.innerText = '✔ Copiado!';
                setTimeout(() => { btn.innerText = old; }, 2000);
            }).catch(err => alert('Erro ao copiar: ' + err));
        }

        function closeEmbedModal() {
            document.getElementById('gistEmbedModal').classList.remove('active');
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeEmbedModal();
        });

        window.addEventListener('DOMContentLoaded', () => {
            if (window.location.hash) {
                const targetName = decodeURIComponent(window.location.hash.substring(1));
                const targetPane = document.querySelector('.gist-pane[data-file="' + CSS.escape(targetName) + '"]');
                if (targetPane) {
                    const id = targetPane.id;
                    const tabBtn = document.querySelector('.gist-tab[data-target="' + CSS.escape(id) + '"]');
                    if (tabBtn) switchGistTab(tabBtn, id);
                }
            }
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * Gera o conteúdo JavaScript do widget de embed para ser servido em /{slug}/embed.js.
     *
     * O script usa document.currentScript para se auto-localizar no DOM e injeta
     * um container com os arquivos do gist, com estilos escopados em .fuxico-gist-embed.
     *
     * @param  array<string, string>  $files  Mapa filename => conteúdo
     * @param array{
     *   slug?: string,
     *   base_url?: string,
     *   theme?: string,
     *   file?: string,
     *   gist_url?: string
     * } $options
     */
    public static function renderEmbedJs(array $files, array $options = []): string
    {
        $slug = (string) ($options['slug'] ?? '');
        $baseUrl = rtrim((string) ($options['base_url'] ?? ''), '/');
        $theme = (($options['theme'] ?? '') === 'acad') ? 'acad' : 'zine';
        $gistUrl = $options['gist_url'] ?? ($baseUrl !== '' && $slug !== '' ? "{$baseUrl}/{$slug}" : '');

        // Filtragem de arquivo único
        $filterFile = (string) ($options['file'] ?? '');
        if ($filterFile !== '' && isset($files[$filterFile])) {
            $files = [$filterFile => $files[$filterFile]];
        }

        // Monta lista de arquivos como dados JSON
        $fileList = [];
        foreach ($files as $filename => $content) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $lines = substr_count($content, "\n") + (strlen($content) > 0 && ! str_ends_with($content, "\n") ? 1 : 0);
            $fileList[] = [
                'name' => $filename,
                'content' => $content,
                'ext' => $ext,
                'lines' => $lines,
                'size' => strlen($content),
            ];
        }

        $filesJson = json_encode($fileList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $gistUrlJson = json_encode($gistUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $slugJson = json_encode($slug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $themeJson = json_encode($theme, JSON_UNESCAPED_UNICODE);

        return <<<JS
(function() {
  'use strict';

  var THEME    = {$themeJson};
  var GIST_URL = {$gistUrlJson};
  var SLUG     = {$slugJson};
  var FILES    = {$filesJson};

  var COLORS = {
    zine: {
      bg:      '#121212',
      card:    '#1a1a1a',
      border:  '#2e2e2e',
      text:    '#e0e0e0',
      muted:   '#888',
      yellow:  '#f5e642',
      codeBg:  '#0d0d0d',
      tabBg:   '#1e1e1e',
      tabAct:  '#2a2a2a',
      fontMono: 'monospace',
    },
    acad: {
      bg:      '#f6f6f0',
      card:    '#ffffff',
      border:  '#d4d0c8',
      text:    '#222',
      muted:   '#666',
      yellow:  '#8b6914',
      codeBg:  '#f0f0ea',
      tabBg:   '#e8e8e0',
      tabAct:  '#ffffff',
      fontMono: 'monospace',
    }
  };

  var C = COLORS[THEME] || COLORS.zine;

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildStyles() {
    return [
      '.fuxico-gist-embed{font-family:system-ui,sans-serif;background:' + C.bg + ';color:' + C.text + ';border:1px solid ' + C.border + ';border-radius:8px;overflow:hidden;margin:1em 0;font-size:14px;}',
      '.fuxico-gist-embed .fg-header{background:' + C.card + ';border-bottom:1px solid ' + C.border + ';padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}',
      '.fuxico-gist-embed .fg-title{font-weight:700;color:' + C.yellow + ';}',
      '.fuxico-gist-embed .fg-link{font-size:0.8em;color:' + C.muted + ';text-decoration:none;}',
      '.fuxico-gist-embed .fg-link:hover{color:' + C.yellow + ';}',
      '.fuxico-gist-embed .fg-tabs{display:flex;overflow-x:auto;background:' + C.tabBg + ';border-bottom:1px solid ' + C.border + ';}',
      '.fuxico-gist-embed .fg-tab{padding:7px 14px;cursor:pointer;border:none;background:transparent;color:' + C.muted + ';font-size:0.85em;white-space:nowrap;border-bottom:2px solid transparent;}',
      '.fuxico-gist-embed .fg-tab.active{background:' + C.tabAct + ';color:' + C.yellow + ';border-bottom-color:' + C.yellow + ';}',
      '.fuxico-gist-embed .fg-pane{display:none;}',
      '.fuxico-gist-embed .fg-pane.active{display:block;}',
      '.fuxico-gist-embed .fg-meta{padding:6px 14px;font-size:0.78em;color:' + C.muted + ';background:' + C.card + ';border-bottom:1px solid ' + C.border + ';}',
      '.fuxico-gist-embed pre{margin:0;padding:14px;overflow-x:auto;background:' + C.codeBg + ';}',
      '.fuxico-gist-embed code{font-family:' + C.fontMono + ';font-size:0.85em;line-height:1.6;color:' + C.text + ';}',
      '.fuxico-gist-embed .fg-footer{padding:8px 14px;font-size:0.75em;color:' + C.muted + ';background:' + C.card + ';border-top:1px solid ' + C.border + ';text-align:center;}',
      '.fuxico-gist-embed .fg-footer a{color:' + C.yellow + ';text-decoration:none;}',
    ].join('');
  }

  function buildTabs() {
    if (FILES.length <= 1) return '';
    return FILES.map(function(f, i) {
      var active = i === 0 ? ' active' : '';
      return '<button class="fg-tab' + active + '" onclick="(function(btn,id){' +
        'var w=btn.closest(\'.fuxico-gist-embed\');' +
        'w.querySelectorAll(\'.fg-tab\').forEach(function(t){t.classList.remove(\'active\')});' +
        'w.querySelectorAll(\'.fg-pane\').forEach(function(p){p.classList.remove(\'active\')});' +
        'btn.classList.add(\'active\');var p=w.querySelector(\'#\'+id);if(p)p.classList.add(\'active\');' +
        '})(this,\'fg-pane-' + i + '\')">' + esc(f.name) + '</button>';
    }).join('');
  }

  function buildPanes() {
    return FILES.map(function(f, i) {
      var active = i === 0 ? ' active' : '';
      var meta   = f.lines + ' linhas · ' + (f.size / 1024).toFixed(1) + ' KB';
      return '<div class="fg-pane' + active + '" id="fg-pane-' + i + '">' +
        '<div class="fg-meta">' + esc(f.name) + ' — ' + meta + '</div>' +
        '<pre><code>' + esc(f.content) + '</code></pre>' +
        '</div>';
    }).join('');
  }

  function buildHTML(uid) {
    var title  = FILES.length === 1 ? FILES[0].name : SLUG;
    var tabsHtml = FILES.length > 1 ? '<div class="fg-tabs">' + buildTabs() + '</div>' : '';
    return '<style>' + buildStyles() + '</style>' +
      '<div class="fuxico-gist-embed" id="' + uid + '">' +
        '<div class="fg-header">' +
          '<span class="fg-title">⚡ ' + esc(title) + '</span>' +
          (GIST_URL ? '<a class="fg-link" href="' + esc(GIST_URL) + '" target="_blank" rel="noopener">Ver no Fuxico ↗</a>' : '') +
        '</div>' +
        tabsHtml +
        buildPanes() +
        '<div class="fg-footer">Embed via <a href="https://2lp.in" target="_blank" rel="noopener">Fuxico</a></div>' +
      '</div>';
  }

  function inject() {
    var script = document.currentScript;
    if (!script) return;
    var uid = 'fuxico-embed-' + Math.random().toString(36).slice(2, 9);
    var container = document.createElement('div');
    container.innerHTML = buildHTML(uid);
    script.parentNode.insertBefore(container, script.nextSibling);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }
})();
JS;
    }

    private static function getFileIcon(string $ext): string
    {
        return match ($ext) {
            'md', 'markdown' => '📝',
            'py' => '🐍',
            'rs' => '🦀',
            'js', 'ts', 'jsx', 'tsx', 'mjs' => '📜',
            'php' => '🐘',
            'html', 'htm' => '🌐',
            'css', 'scss', 'sass', 'less' => '🎨',
            'json', 'yaml', 'yml', 'toml', 'xml' => '⚙️',
            'sh', 'bash', 'zsh', 'fish' => '💻',
            'go' => '🐹',
            'c', 'cpp', 'cc', 'h', 'hpp' => '⚡',
            'sql' => '🗄️',
            'rb' => '💎',
            'java', 'kt' => '☕',
            'lua' => '🌙',
            default => '📄',
        };
    }
}
