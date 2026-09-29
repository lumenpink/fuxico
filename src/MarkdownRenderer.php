<?php

declare(strict_types=1);

namespace Fuxico;

class MarkdownRenderer
{
    /**
     * Renders a Markdown document into a standalone, dual-skin HTML page.
     *
     * @param array{
     *   title?: string,
     *   slug?: string,
     *   original_name?: string,
     *   default_theme?: string,
     *   created_at?: int,
     *   base_url?: string
     * } $options
     */
    public static function render(string $markdown, array $options = []): string
    {
        $parsed = self::parse($markdown, $options);
        $bodyHtml = $parsed['html'];
        $meta = $parsed['meta'];

        $title = htmlspecialchars(
            (string) ($meta['title'] ?? $options['title'] ?? $options['original_name'] ?? 'Fuxico Doc'),
            ENT_QUOTES,
            'UTF-8'
        );
        $slug = htmlspecialchars((string) ($options['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rawFilename = htmlspecialchars((string) ($options['original_name'] ?? ($slug !== '' ? "{$slug}.md" : 'document.md')), ENT_QUOTES, 'UTF-8');
        $defaultTheme = ($options['default_theme'] ?? ($meta['theme'] ?? 'zine')) === 'acad' ? 'acad' : 'zine';
        $dateFormatted = date('Y-m-d H:i:s', $options['created_at'] ?? time());

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR" data-theme="{$defaultTheme}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
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
        }

        /* ─── DUAL SKIN: 2. ACADÊMICO DEPRESSIVO MONOCROMÁTICO (?acad=true) ─── */
        [data-theme="acad"] {
            --bg-body: #fcfcfc;
            --bg-card: #ffffff;
            --bg-surface: #f4f4f5;
            --text-main: #18181b;
            --text-muted: #52525b;
            --text-link: #09090b;
            --acid-yellow: #09090b;
            --acid-hover: #27272a;
            --electric-pink: #27272a;
            --tape-black: #e4e4e7;
            --border-color: #d4d4d8;
            --border-hard: 1px solid #18181b;
            --shadow-hard: 2px 2px 0px #d4d4d8;
            --shadow-accent: none;
            --font-heading: 'Noto Serif', Georgia, serif;
            --font-sub: 'Noto Serif', Georgia, serif;
            --font-body: 'Noto Serif', Georgia, serif;
            --font-mono: 'Space Mono', Courier, monospace;
            --code-bg: #f4f4f5;
            --callout-bg: #fafafa;
            --mark-bg: #e4e4e7;
            --mark-color: #09090b;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-body);
            line-height: 1.7;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 16px 40px 16px;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        /* Zine background pattern */
        [data-theme="zine"] body {
            background-image: 
                radial-gradient(circle, rgba(226, 248, 53, 0.04) 1.5px, transparent 1.5px),
                radial-gradient(circle at 10% 20%, rgba(255, 42, 133, 0.06) 0%, transparent 40%);
            background-size: 20px 20px, 100% 100%;
        }

        /* Academic background pattern */
        [data-theme="acad"] body {
            background-image: linear-gradient(to right, #f4f4f5 1px, transparent 1px);
            background-size: 80px 100%;
        }

        /* Layout Container */
        .fuxico-container {
            width: 100%;
            max-width: 860px;
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-hard);
            padding: 36px 32px;
            position: relative;
        }

        [data-theme="zine"] .fuxico-container {
            box-shadow: 8px 12px 0px #0a080d, 8px 12px 0px 2px var(--electric-pink);
            border: 2px solid var(--border-color);
        }

        [data-theme="acad"] .fuxico-container {
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            max-width: 820px;
            padding: 56px 48px;
        }

        /* Trans Pride Stripes */
        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 12px;
            margin-bottom: 24px;
            border-bottom: 2px solid #000;
        }
        .trans-stripe.bottom {
            margin-top: 36px;
            margin-bottom: 0;
            border-top: 2px solid #000;
            border-bottom: none;
        }
        .trans-stripe-line { flex: 1; width: 100%; }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }

        /* In Academic mode, hide decorative stripes and tape */
        [data-theme="acad"] .trans-stripe,
        [data-theme="acad"] .duct-tape {
            display: none !important;
        }

        /* Duct tape badge */
        .duct-tape {
            position: absolute;
            top: -3px;
            left: 50%;
            transform: translateX(-50%) rotate(-1.5deg);
            background: rgba(226, 248, 53, 0.96);
            color: #000;
            font-family: var(--font-heading);
            font-size: 0.72rem;
            letter-spacing: 2px;
            padding: 4px 28px;
            box-shadow: 3px 3px 0px rgba(0,0,0,0.6);
            z-index: 10;
            user-select: none;
            text-transform: uppercase;
        }

        /* Document Top Toolbar */
        .doc-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 16px;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 12px;
        }

        [data-theme="acad"] .doc-toolbar {
            border-bottom: 1px solid #18181b;
            font-size: 0.85rem;
            padding-bottom: 12px;
            margin-bottom: 36px;
        }

        .doc-breadcrumbs {
            font-family: var(--font-mono);
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .doc-breadcrumbs a {
            color: var(--text-link);
            text-decoration: none;
            font-weight: 700;
        }

        .doc-breadcrumbs a:hover { text-decoration: underline; }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-tool {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 5px 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 2px 2px 0px rgba(0,0,0,0.4);
            user-select: none;
        }

        [data-theme="acad"] .btn-tool {
            background: #ffffff;
            border: 1px solid #71717a;
            color: #18181b;
            box-shadow: none;
            border-radius: 2px;
        }

        .btn-tool:hover {
            border-color: var(--acid-yellow);
            color: var(--acid-yellow);
        }

        [data-theme="acad"] .btn-tool:hover {
            background: #f4f4f5;
            color: #000;
            border-color: #000;
        }

        /* ─── TYPOGRAPHY & ARTICLE CONTENT ─── */
        .fuxico-article {
            font-size: 1.05rem;
        }

        [data-theme="acad"] .fuxico-article {
            font-size: 1.08rem;
            text-align: justify;
        }

        /* Headings */
        .fuxico-article h1,
        .fuxico-article h2,
        .fuxico-article h3,
        .fuxico-article h4,
        .fuxico-article h5,
        .fuxico-article h6 {
            color: var(--text-main);
            margin-top: 1.6em;
            margin-bottom: 0.6em;
            line-height: 1.25;
            scroll-margin-top: 20px;
        }

        [data-theme="zine"] .fuxico-article h1 {
            font-family: var(--font-heading);
            font-size: 2.4rem;
            color: var(--acid-yellow);
            letter-spacing: 1px;
            text-shadow: 2px 2px 0px #000;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 8px;
        }
        [data-theme="zine"] .fuxico-article h2 {
            font-family: var(--font-sub);
            font-size: 1.6rem;
            color: var(--electric-pink);
            letter-spacing: 0.5px;
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 6px;
        }
        [data-theme="zine"] .fuxico-article h3 {
            font-family: var(--font-sub);
            font-size: 1.3rem;
            color: var(--text-main);
        }

        [data-theme="acad"] .fuxico-article h1 {
            font-size: 1.9rem;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
            margin-bottom: 1.2em;
            border-bottom: 1px solid #18181b;
            padding-bottom: 12px;
        }
        [data-theme="acad"] .fuxico-article h2 {
            font-size: 1.4rem;
            font-weight: 700;
            margin-top: 1.8em;
            border-bottom: 1px solid #e4e4e7;
            padding-bottom: 4px;
        }
        [data-theme="acad"] .fuxico-article h3 {
            font-size: 1.18rem;
            font-style: italic;
            font-weight: 600;
        }

        /* Paragraphs & Text */
        .fuxico-article p {
            margin-bottom: 1.2em;
        }

        .fuxico-article a {
            color: var(--text-link);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        [data-theme="zine"] .fuxico-article a:hover {
            background: var(--acid-yellow);
            color: #000;
            text-decoration: none;
        }

        .fuxico-article strong { font-weight: 700; }
        .fuxico-article em { font-style: italic; }
        .fuxico-article s, .fuxico-article del { text-decoration: line-through; opacity: 0.75; }

        /* Highlights */
        .fuxico-article mark {
            background-color: var(--mark-bg);
            color: var(--mark-color);
            padding: 2px 6px;
            border-radius: 2px;
            font-weight: 600;
        }

        /* Blockquotes */
        .fuxico-article blockquote {
            border-left: 4px solid var(--electric-pink);
            background: var(--callout-bg);
            padding: 12px 18px;
            margin: 1.4em 0;
            font-style: italic;
            color: var(--text-muted);
        }

        [data-theme="acad"] .fuxico-article blockquote {
            border-left: 3px solid #71717a;
            background: #fafafa;
            color: #3f3f46;
            margin-left: 20px;
            margin-right: 20px;
        }

        /* Obsidian Callouts */
        .callout {
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--callout-color, var(--acid-yellow));
            background: var(--callout-bg);
            padding: 14px 18px;
            margin: 1.5em 0;
            border-radius: 2px;
        }

        .callout-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-family: var(--font-sub);
            color: var(--callout-color, var(--acid-yellow));
            margin-bottom: 8px;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        [data-theme="acad"] .callout {
            border: 1px solid #d4d4d8;
            border-left: 4px solid #18181b;
            background: #fbfbfb;
            box-shadow: none;
        }
        [data-theme="acad"] .callout-title {
            color: #18181b;
            font-family: var(--font-heading);
            font-size: 0.9rem;
        }

        .callout-note { --callout-color: #38bdf8; }
        .callout-tip { --callout-color: #4ade80; }
        .callout-warning { --callout-color: #facc15; }
        .callout-danger { --callout-color: #f87171; }
        .callout-info { --callout-color: #a78bfa; }
        .callout-quote { --callout-color: #9f97ad; }

        /* Diagram Figures (Joaju Native Baking) */
        .diagram-figure {
            margin: 1.8em 0;
            border: 2px solid var(--border-color);
            background: var(--code-bg);
            box-shadow: var(--shadow-hard);
            overflow: hidden;
            display: block;
        }

        [data-theme="acad"] .diagram-figure {
            border: 1px solid #d4d4d8;
            box-shadow: none;
            background: #ffffff;
        }

        .diagram-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--tape-black);
            padding: 8px 14px;
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color);
        }

        [data-theme="acad"] .diagram-header {
            background: #f4f4f5;
            border-bottom: 1px solid #d4d4d8;
            color: #52525b;
        }

        .diagram-badge {
            font-weight: 700;
            color: var(--acid-yellow);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        [data-theme="acad"] .diagram-badge {
            color: #18181b;
        }

        .diagram-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-diagram-action {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 0.7rem;
            padding: 2px 8px;
            cursor: pointer;
            text-transform: uppercase;
        }

        .btn-diagram-action:hover {
            color: var(--text-main);
            border-color: var(--acid-yellow);
        }

        [data-theme="acad"] .btn-diagram-action:hover {
            color: #000;
            border-color: #000;
        }

        .diagram-viewport {
            width: 100%;
            overflow-x: auto;
            padding: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #ffffff;
        }

        [data-theme="acad"] .diagram-viewport {
            background: #ffffff;
            padding: 24px;
        }

        .diagram-viewport svg {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        .diagram-source {
            border-top: 1px dashed var(--border-color);
            background: var(--code-bg);
        }

        /* Code Blocks */
        .code-block-wrapper {
            margin: 1.5em 0;
            border: 1px solid var(--border-color);
            background: var(--code-bg);
            box-shadow: var(--shadow-hard);
            overflow: hidden;
        }

        [data-theme="acad"] .code-block-wrapper {
            border: 1px solid #d4d4d8;
            box-shadow: none;
        }

        .code-block-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--tape-black);
            padding: 6px 14px;
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }

        .btn-copy-code {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 0.7rem;
            padding: 2px 8px;
            cursor: pointer;
        }

        .btn-copy-code:hover {
            color: var(--text-main);
            border-color: var(--acid-yellow);
        }

        .fuxico-article pre {
            padding: 14px 16px;
            overflow-x: auto;
            font-family: var(--font-mono);
            font-size: 0.9rem;
            line-height: 1.5;
            color: var(--text-main);
        }

        .fuxico-article code {
            font-family: var(--font-mono);
            font-size: 0.9em;
            background: var(--code-bg);
            padding: 2px 6px;
            border: 1px solid var(--border-color);
            color: var(--acid-yellow);
        }

        [data-theme="acad"] .fuxico-article code {
            color: #18181b;
            background: #f4f4f5;
            border: 1px solid #e4e4e7;
        }

        .fuxico-article pre code {
            background: transparent;
            border: none;
            padding: 0;
            color: inherit;
        }

        /* Lists */
        .fuxico-article ul, .fuxico-article ol {
            margin: 1.2em 0 1.2em 2em;
        }

        .fuxico-article li {
            margin-bottom: 0.4em;
        }

        .task-list-item {
            list-style-type: none;
            margin-left: -1.2em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .task-list-item input[type="checkbox"] {
            appearance: none;
            width: 16px;
            height: 16px;
            border: 1px solid var(--border-color);
            background: var(--bg-surface);
            cursor: default;
            position: relative;
        }

        .task-list-item input[type="checkbox"]:checked {
            background: var(--acid-yellow);
            border-color: var(--acid-yellow);
        }

        .task-list-item input[type="checkbox"]:checked::after {
            content: '✔';
            position: absolute;
            top: -3px;
            left: 2px;
            font-size: 11px;
            color: #000;
            font-weight: bold;
        }

        /* Tables */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            margin: 1.6em 0;
        }

        .fuxico-article table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
            margin: 0 auto;
        }

        .fuxico-article th {
            background: var(--tape-black);
            color: var(--text-main);
            font-family: var(--font-sub);
            font-weight: 700;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            text-align: left;
        }

        [data-theme="zine"] .fuxico-article th {
            color: var(--acid-yellow);
        }

        [data-theme="acad"] .fuxico-article th {
            background: #f4f4f5;
            color: #18181b;
            border: 1px solid #d4d4d8;
            font-family: var(--font-heading);
            font-size: 0.88rem;
        }

        .fuxico-article td {
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
        }

        [data-theme="acad"] .fuxico-article td {
            border: 1px solid #e4e4e7;
        }

        /* Images & Figures */
        .fuxico-article img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 1.8em auto;
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-hard);
        }

        [data-theme="acad"] .fuxico-article img {
            border: 1px solid #d4d4d8;
            box-shadow: none;
        }

        /* Horizontal Rule */
        .fuxico-article hr {
            border: none;
            border-top: 2px dashed var(--border-color);
            margin: 2.2em 0;
        }

        [data-theme="acad"] .fuxico-article hr {
            border-top: 1px solid #d4d4d8;
        }

        /* Footer */
        .doc-footer {
            margin-top: 36px;
            text-align: center;
            font-family: var(--font-mono);
            font-size: 0.78rem;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        .doc-footer a {
            color: var(--acid-yellow);
            text-decoration: none;
        }

        [data-theme="acad"] .doc-footer a {
            color: #18181b;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="fuxico-container">
        <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
        <div class="duct-tape">FUXICO // DOCUMENTO</div>

        <nav class="doc-toolbar">
            <div class="doc-breadcrumbs">
                <a href="/">FUXICO</a>
                <span>/</span>
                <span>{$rawFilename}</span>
            </div>
            <div class="toolbar-actions">
                <button type="button" class="btn-tool" id="btnThemeToggle" onclick="toggleTheme()" title="Alternar entre visual Zine e Acadêmico">
                    <span id="themeLabel">🏛️ Acadêmico</span>
                </button>
                <a href="?raw=1" class="btn-tool" title="Ver ou baixar markdown puro">
                    ⬇ Raw
                </a>
                <button type="button" class="btn-tool" onclick="copyDocUrl()" title="Copiar link do documento">
                    🔗 Copiar
                </button>
            </div>
        </nav>

        <article class="fuxico-article">
            {$bodyHtml}
        </article>

        <div class="trans-stripe bottom" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
    </div>

    <footer class="doc-footer">
        <p>Postado em {$dateFormatted} // <strong>FUXICO DOCS</strong> // <a href="/">Soltar Fofoca</a></p>
    </footer>

    <script>
        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme') || 'zine';
            const next = current === 'zine' ? 'acad' : 'zine';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('fuxico_theme', next);
            updateThemeLabel(next);
        }

        function updateThemeLabel(theme) {
            const label = document.getElementById('themeLabel');
            if (label) {
                label.innerText = theme === 'zine' ? '🏛️ Acadêmico' : '⚡ Zine';
            }
        }

        function copyDocUrl() {
            navigator.clipboard.writeText(window.location.href.split('?')[0])
                .then(() => alert('Link copiado para a área de transferência!'));
        }

        function toggleDiagramSource(btn) {
            const fig = btn.closest('.diagram-figure');
            if (!fig) return;
            const src = fig.querySelector('.diagram-source');
            const view = fig.querySelector('.diagram-viewport');
            if (src && view) {
                const isHidden = src.style.display === 'none' || getComputedStyle(src).display === 'none';
                src.style.display = isHidden ? 'block' : 'none';
                view.style.display = isHidden ? 'none' : 'flex';
                btn.innerText = isHidden ? 'Visual' : 'Código';
            }
        }

        function copyCode(btn) {
            const container = btn.closest('.code-block-wrapper') || btn.closest('.diagram-figure');
            const pre = container ? container.querySelector('pre') : null;
            if (pre) {
                navigator.clipboard.writeText(pre.innerText).then(() => {
                    const original = btn.innerText;
                    btn.innerText = 'Copiado!';
                    setTimeout(() => { btn.innerText = original; }, 2000);
                });
            }
        }

        // Initialize button label on load
        document.addEventListener('DOMContentLoaded', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'zine';
            updateThemeLabel(current);
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * Parses raw Markdown text into HTML and extracts YAML frontmatter.
     *
     * @return array{html: string, meta: array<string, mixed>}
     */
    public static function parse(string $markdown, array $options = []): array
    {
        $meta = [];
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // 1. Extract YAML Frontmatter
        if (str_starts_with($markdown, "---\n")) {
            $endPos = strpos($markdown, "\n---\n", 4);
            if ($endPos !== false) {
                $yamlContent = substr($markdown, 4, $endPos - 4);
                $markdown = substr($markdown, $endPos + 5);
                $meta = self::parseSimpleYaml($yamlContent);
            }
        }

        $lines = explode("\n", $markdown);
        $htmlOutput = [];
        $i = 0;
        $count = count($lines);

        $inList = false;
        $listType = 'ul';

        while ($i < $count) {
            $line = $lines[$i];

            // 2. Fenced Code Block & Joaju Diagram Rendering
            if (preg_match('/^```(\w*)/', $line, $matches)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $rawLang = strtolower(trim($matches[1] ?: 'text'));
                $lang = htmlspecialchars($rawLang, ENT_QUOTES, 'UTF-8');
                $rawCodeLines = [];
                $i++;
                while ($i < $count && ! str_starts_with($lines[$i], '```')) {
                    $rawCodeLines[] = $lines[$i];
                    $i++;
                }
                $rawCode = implode("\n", $rawCodeLines);
                $escapedCode = htmlspecialchars($rawCode, ENT_QUOTES, 'UTF-8');

                $diagramEngines = [
                    'kinship' => ['engine' => 'kinship', 'label' => 'HEREDOGRAMA (KINSHIP)'],
                    'kinship2' => ['engine' => 'kinship', 'label' => 'HEREDOGRAMA (KINSHIP)'],
                    'heredograma' => ['engine' => 'kinship', 'label' => 'HEREDOGRAMA (KINSHIP)'],
                    'pedigree' => ['engine' => 'kinship', 'label' => 'HEREDOGRAMA (KINSHIP)'],
                    'd2' => ['engine' => 'd2', 'label' => 'DIAGRAMA D2'],
                    'plantuml' => ['engine' => 'plantuml', 'label' => 'PLANTUML'],
                    'puml' => ['engine' => 'plantuml', 'label' => 'PLANTUML'],
                    'graphviz' => ['engine' => 'graphviz', 'label' => 'GRAPHVIZ'],
                    'dot' => ['engine' => 'graphviz', 'label' => 'GRAPHVIZ (DOT)'],
                    'mermaid' => ['engine' => 'mermaid', 'label' => 'MERMAID'],
                ];

                $renderedSvg = null;
                if (isset($diagramEngines[$rawLang])) {
                    $engineInfo = $diagramEngines[$rawLang];
                    $renderedSvg = self::renderJoajuDiagram($rawCode, $engineInfo['engine'], $options);
                }

                if ($renderedSvg !== null) {
                    $engineInfo = $diagramEngines[$rawLang];
                    $label = htmlspecialchars($engineInfo['label'], ENT_QUOTES, 'UTF-8');
                    $htmlOutput[] = '<figure class="diagram-figure diagram-'.$engineInfo['engine'].'" data-engine="'.$engineInfo['engine'].'">'
                        .'<div class="diagram-header">'
                        .'<span class="diagram-badge">⚡ '.$label.'</span>'
                        .'<div class="diagram-actions">'
                        .'<button type="button" class="btn-diagram-action" onclick="toggleDiagramSource(this)">Código</button>'
                        .'<button type="button" class="btn-copy-code" onclick="copyCode(this)">Copiar</button>'
                        .'</div>'
                        .'</div>'
                        .'<div class="diagram-viewport">'
                        .$renderedSvg
                        .'</div>'
                        .'<div class="diagram-source" style="display:none;">'
                        ."<pre><code class=\"language-{$lang}\">{$escapedCode}</code></pre>"
                        .'</div>'
                        .'</figure>';
                } else {
                    $htmlOutput[] = '<div class="code-block-wrapper">'
                        .'<div class="code-block-header">'
                        .'<span>'.strtoupper($lang).'</span>'
                        .'<button type="button" class="btn-copy-code" onclick="copyCode(this)">Copiar</button>'
                        .'</div>'
                        ."<pre><code class=\"language-{$lang}\">{$escapedCode}</code></pre>"
                        .'</div>';
                }
                $i++;

                continue;
            }

            // 3. Obsidian Callout (> [!TYPE] Title)
            if (preg_match('/^>\s*\[!(\w+)\]\s*(.*)$/', $line, $matches)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $type = strtolower($matches[1]);
                $calloutTitle = trim($matches[2]) ?: ucfirst($type);
                $calloutLines = [];
                $i++;
                while ($i < $count && str_starts_with($lines[$i], '>')) {
                    $cLine = preg_replace('/^>\s?/', '', $lines[$i]);
                    $calloutLines[] = $cLine;
                    $i++;
                }

                $cContent = self::renderInline(implode("\n", $calloutLines));
                $htmlOutput[] = "<div class=\"callout callout-{$type}\">"
                    .'<div class="callout-title">'.htmlspecialchars($calloutTitle, ENT_QUOTES, 'UTF-8').'</div>'
                    ."<div class=\"callout-content\">{$cContent}</div>"
                    .'</div>';

                continue;
            }

            // 4. Standard Blockquote
            if (str_starts_with($line, '>')) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $quoteLines = [];
                while ($i < $count && str_starts_with($lines[$i], '>')) {
                    $quoteLines[] = preg_replace('/^>\s?/', '', $lines[$i]);
                    $i++;
                }
                $quoteContent = self::renderInline(implode('<br>', $quoteLines));
                $htmlOutput[] = "<blockquote>{$quoteContent}</blockquote>";

                continue;
            }

            // 5. Headings
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $matches)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $level = strlen($matches[1]);
                $titleRaw = trim($matches[2]);
                $headingId = self::slugify($titleRaw);
                $headingHtml = self::renderInline($titleRaw);
                $htmlOutput[] = "<h{$level} id=\"{$headingId}\">{$headingHtml}</h{$level}>";
                $i++;

                continue;
            }

            // 6. Horizontal Rule
            if (preg_match('/^(\-{3,}|\*{3,}|_{3,})$/', trim($line))) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $htmlOutput[] = '<hr>';
                $i++;

                continue;
            }

            // 7. Markdown Pipe Tables
            if (str_starts_with(trim($line), '|') && str_ends_with(trim($line), '|')) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $tableRows = [];
                while ($i < $count && str_starts_with(trim($lines[$i]), '|') && str_ends_with(trim($lines[$i]), '|')) {
                    $tableRows[] = trim($lines[$i]);
                    $i++;
                }

                $htmlOutput[] = self::renderTable($tableRows);

                continue;
            }

            // 8. Lists (Unordered, Ordered, Task Lists)
            $isUnordered = preg_match('/^(\s*)[*+-]\s+(.*)$/', $line, $matchesU);
            $isOrdered = preg_match('/^(\s*)\d+\.\s+(.*)$/', $line, $matchesO);

            if ($isUnordered || $isOrdered) {
                $currentType = $isOrdered ? 'ol' : 'ul';
                $content = $isOrdered ? $matchesO[2] : $matchesU[2];

                if (! $inList) {
                    $htmlOutput[] = "<{$currentType}>";
                    $inList = true;
                    $listType = $currentType;
                } elseif ($listType !== $currentType) {
                    $htmlOutput[] = "</{$listType}><{$currentType}>";
                    $listType = $currentType;
                }

                // Check Task list item (- [ ] or - [x])
                if (preg_match('/^\[([ xX])\]\s+(.*)$/', $content, $taskMatch)) {
                    $checked = strtolower($taskMatch[1]) === 'x' ? 'checked' : '';
                    $itemText = self::renderInline($taskMatch[2]);
                    $htmlOutput[] = "<li class=\"task-list-item\"><input type=\"checkbox\" {$checked} disabled> {$itemText}</li>";
                } else {
                    $itemText = self::renderInline($content);
                    $htmlOutput[] = "<li>{$itemText}</li>";
                }
                $i++;

                continue;
            }

            // End list if non-list line
            if ($inList && trim($line) === '') {
                $htmlOutput[] = "</{$listType}>";
                $inList = false;
                $i++;

                continue;
            }

            // 9. Standard Paragraphs
            $trimmed = trim($line);
            if ($trimmed === '') {
                $i++;

                continue;
            }

            $paraLines = [];
            while ($i < $count && trim($lines[$i]) !== '' &&
                   ! preg_match('/^(#{1,6}\s|>\s|```|\-{3,}|\*{3,}|\||[*+-]\s|\d+\.\s)/', $lines[$i])) {
                $paraLines[] = $lines[$i];
                $i++;
            }

            if (! empty($paraLines)) {
                $paraText = self::renderInline(implode(' ', $paraLines));
                $htmlOutput[] = "<p>{$paraText}</p>";
            }
        }

        if ($inList) {
            $htmlOutput[] = "</{$listType}>";
        }

        return [
            'html' => implode("\n", $htmlOutput),
            'meta' => $meta,
        ];
    }

    /**
     * Renders inline markdown formatting.
     */
    public static function renderInline(string $text): string
    {
        // 1. Obsidian Wikilink Images: ![[imagem.png|alt]] or ![[imagem.png]]
        $text = preg_replace_callback('/!\[\[(.*?)\]\]/', function ($matches) {
            $parts = explode('|', $matches[1]);
            $src = trim($parts[0]);
            $alt = isset($parts[1]) ? trim($parts[1]) : basename($src);
            $cleanSrc = 'assets/'.ltrim($src, '/');

            return '<img src="'.htmlspecialchars($cleanSrc, ENT_QUOTES, 'UTF-8').'" alt="'.htmlspecialchars($alt, ENT_QUOTES, 'UTF-8').'" loading="lazy">';
        }, $text) ?? $text;

        // 2. Standard Images: ![alt](url)
        $text = preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function ($matches) {
            $alt = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $src = htmlspecialchars(trim($matches[2]), ENT_QUOTES, 'UTF-8');

            return "<img src=\"{$src}\" alt=\"{$alt}\" loading=\"lazy\">";
        }, $text) ?? $text;

        // 3. Obsidian Wikilink internal: [[link|label]] or [[link]]
        $text = preg_replace_callback('/\[\[(.*?)\]\]/', function ($matches) {
            $parts = explode('|', $matches[1]);
            $target = trim($parts[0]);
            $label = isset($parts[1]) ? trim($parts[1]) : $target;

            return '<a href="'.htmlspecialchars($target, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a>';
        }, $text) ?? $text;

        // 4. Standard Links: [text](url)
        $text = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function ($matches) {
            $label = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars(trim($matches[2]), ENT_QUOTES, 'UTF-8');

            return "<a href=\"{$href}\" target=\"_blank\" rel=\"noopener noreferrer\">{$label}</a>";
        }, $text) ?? $text;

        // 5. Inline Code `code`
        $text = preg_replace_callback('/`([^`]+)`/', function ($matches) {
            return '<code>'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'</code>';
        }, $text) ?? $text;

        // 6. Obsidian Mark / Highlight ==text==
        $text = preg_replace_callback('/==(.*?)==/', function ($matches) {
            return '<mark>'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'</mark>';
        }, $text) ?? $text;

        // 7. Bold **text** or __text__
        $text = preg_replace('/(\*\*|__)(.*?)\1/', '<strong>$2</strong>', $text) ?? $text;

        // 8. Italic *text* or _text_
        $text = preg_replace('/(\*|_)(.*?)\1/', '<em>$2</em>', $text) ?? $text;

        // 9. Strikethrough ~~text~~
        $text = preg_replace('/~~(.*?)~~/', '<del>$1</del>', $text) ?? $text;

        return $text;
    }

    /**
     * Renders Markdown table rows into responsive HTML table.
     *
     * @param  string[]  $rows
     */
    private static function renderTable(array $rows): string
    {
        if (count($rows) < 2) {
            return '';
        }

        $headers = array_map('trim', explode('|', trim($rows[0], '|')));
        $alignments = [];

        // Check alignment row (second row)
        $alignRow = array_map('trim', explode('|', trim($rows[1], '|')));
        foreach ($alignRow as $a) {
            if (str_starts_with($a, ':') && str_ends_with($a, ':')) {
                $alignments[] = 'center';
            } elseif (str_ends_with($a, ':')) {
                $alignments[] = 'right';
            } else {
                $alignments[] = 'left';
            }
        }

        $html = '<div class="table-wrapper"><table><thead><tr>';
        foreach ($headers as $idx => $h) {
            $alignStyle = isset($alignments[$idx]) ? ' style="text-align:'.$alignments[$idx].';"' : '';
            $html .= "<th{$alignStyle}>".self::renderInline($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        for ($r = 2; $r < count($rows); $r++) {
            $cells = array_map('trim', explode('|', trim($rows[$r], '|')));
            $html .= '<tr>';
            foreach ($cells as $idx => $c) {
                $alignStyle = isset($alignments[$idx]) ? ' style="text-align:'.$alignments[$idx].';"' : '';
                $html .= "<td{$alignStyle}>".self::renderInline($c).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * Parse minimal key-value frontmatter.
     *
     * @return array<string, string>
     */
    private static function parseSimpleYaml(string $yaml): array
    {
        $meta = [];
        $lines = explode("\n", $yaml);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $key = strtolower(trim(substr($line, 0, $colonPos)));
                $val = trim(substr($line, $colonPos + 1));
                $val = trim($val, "\"'");
                $meta[$key] = $val;
            }
        }

        return $meta;
    }

    private static function slugify(string $text): string
    {
        $text = strip_tags($text);

        // Normalize accented characters
        $accentMap = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'a', 'À' => 'a', 'Ã' => 'a', 'Â' => 'a', 'Ä' => 'a',
            'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'Ó' => 'o', 'Ò' => 'o', 'Õ' => 'o', 'Ô' => 'o', 'Ö' => 'o',
            'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
            'Ç' => 'c', 'Ñ' => 'n',
        ];
        $text = strtr($text, $accentMap);

        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($trans !== false) {
                $text = $trans;
            }
        }

        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\-]+/i', '-', $text);

        return trim((string) $text, '-');
    }

    /**
     * Renders a diagram code block via Joaju API (/render) into clean inline SVG.
     *
     * @param  array<string, mixed>  $options
     */
    public static function renderJoajuDiagram(string $rawCode, string $engine, array $options = []): ?string
    {
        if (getenv('FUXICO_DISABLE_DIAGRAMS') === '1' || ($options['disable_diagrams'] ?? false)) {
            return null;
        }

        $joajuUrl = (string) ($options['joaju_url'] ?? getenv('FUXICO_JOAJU_URL') ?: 'https://joaju.lumen.pink/render');

        $trimmed = trim($rawCode);
        if ($trimmed === '') {
            return null;
        }

        $wrappedCode = str_starts_with($trimmed, '```') ? $trimmed : "```{$engine}\n{$trimmed}\n```";
        $payload = json_encode([
            'code' => $wrappedCode,
            'engine' => $engine,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return null;
        }

        $ch = curl_init($joajuUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($options['joaju_timeout'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($httpCode !== 200 || ! is_string($response)) {
            return null;
        }

        $data = json_decode($response, true);
        if (! is_array($data) || empty($data['svg']) || ! is_string($data['svg'])) {
            return null;
        }

        $svg = $data['svg'];
        $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? $svg;

        return trim($svg);
    }
}
