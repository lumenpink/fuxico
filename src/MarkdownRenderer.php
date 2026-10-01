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
        $baseTag = $slug !== '' ? "<base href=\"/{$slug}/\">\n    " : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR" data-theme="{$defaultTheme}">
<head>
    <meta charset="UTF-8">
    {$baseTag}<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Grotesk:wght@400;600;700&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Noto+Serif:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.21/dist/katex.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.21/dist/katex.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.21/dist/contrib/auto-render.min.js" onload="renderMathInElement(document.body, {delimiters: [{left: '$$', right: '$$', display: true}, {left: '$', right: '$', display: false}, {left: '\\\\(', right: '\\\\)', display: false}, {left: '\\\\[', right: '\\\\]', display: true}], throwOnError: false});"></script>
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
            background: color-mix(in srgb, var(--callout-color, #e2f835) 9%, var(--callout-bg));
            padding: 14px 18px;
            margin: 1.5em 0;
            border-radius: 4px;
        }

        .callout-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-family: var(--font-sub);
            color: var(--callout-color, var(--acid-yellow));
            margin-bottom: 8px;
            font-size: 0.95rem;
            letter-spacing: 0.2px;
        }

        .callout-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--callout-color, inherit);
        }

        .callout-icon svg {
            display: block;
            width: 18px;
            height: 18px;
            stroke-width: 2.2;
        }

        .callout-fold {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            color: var(--callout-color, inherit);
            opacity: 0.7;
            transition: transform 0.2s ease;
            transform: rotate(-90deg);
        }

        details.callout[open] .callout-fold {
            transform: rotate(0deg);
        }

        [data-theme="acad"] .callout {
            border: 1px solid #e4e4e7;
            border-left: 4px solid var(--callout-color, #18181b);
            background: color-mix(in srgb, var(--callout-color, #18181b) 7%, #ffffff);
            box-shadow: none;
        }

        [data-theme="acad"] .callout-title {
            color: var(--callout-color, #18181b);
            font-family: var(--font-heading);
            font-size: 0.92rem;
        }

        /* Callout Types & Colors */
        .callout-note { --callout-color: #0284c7; }
        .callout-abstract, .callout-summary, .callout-tldr { --callout-color: #0d9488; }
        .callout-info { --callout-color: #0284c7; }
        .callout-todo { --callout-color: #7c3aed; }
        .callout-tip, .callout-hint { --callout-color: #10b981; }
        .callout-success, .callout-check, .callout-done { --callout-color: #16a34a; }
        .callout-question, .callout-help, .callout-faq { --callout-color: #d97706; }
        .callout-warning, .callout-caution, .callout-attention { --callout-color: #ea580c; }
        .callout-failure, .callout-fail, .callout-missing { --callout-color: #dc2626; }
        .callout-danger, .callout-error { --callout-color: #e11d48; }
        .callout-bug { --callout-color: #f43f5e; }
        .callout-example { --callout-color: #9333ea; }
        .callout-quote, .callout-cite { --callout-color: #64748b; }
        .callout-important { --callout-color: #ec4899; }

        /* Dark mode palette adjustments */
        [data-theme="zine"] .callout-note { --callout-color: #38bdf8; }
        [data-theme="zine"] .callout-abstract, [data-theme="zine"] .callout-summary, [data-theme="zine"] .callout-tldr { --callout-color: #2dd4bf; }
        [data-theme="zine"] .callout-info { --callout-color: #38bdf8; }
        [data-theme="zine"] .callout-todo { --callout-color: #a855f7; }
        [data-theme="zine"] .callout-tip, [data-theme="zine"] .callout-hint { --callout-color: #34d399; }
        [data-theme="zine"] .callout-success, [data-theme="zine"] .callout-check, [data-theme="zine"] .callout-done { --callout-color: #22c55e; }
        [data-theme="zine"] .callout-question, [data-theme="zine"] .callout-help, [data-theme="zine"] .callout-faq { --callout-color: #fbbf24; }
        [data-theme="zine"] .callout-warning, [data-theme="zine"] .callout-caution, [data-theme="zine"] .callout-attention { --callout-color: #fb923c; }
        [data-theme="zine"] .callout-failure, [data-theme="zine"] .callout-fail, [data-theme="zine"] .callout-missing { --callout-color: #f87171; }
        [data-theme="zine"] .callout-danger, [data-theme="zine"] .callout-error { --callout-color: #fb7185; }
        [data-theme="zine"] .callout-bug { --callout-color: #f43f5e; }
        [data-theme="zine"] .callout-example { --callout-color: #c084fc; }
        [data-theme="zine"] .callout-quote, [data-theme="zine"] .callout-cite { --callout-color: #94a3b8; }
        [data-theme="zine"] .callout-important { --callout-color: #f472b6; }

        .callout-content {
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .callout-content p {
            margin: 0.6em 0;
        }
        .callout-content p:first-child {
            margin-top: 0;
        }
        .callout-content p:last-child {
            margin-bottom: 0;
        }
        .callout-content ul, .callout-content ol {
            margin: 0.5em 0 0.5em 1.5em;
        }

        details.callout summary.callout-title {
            cursor: pointer;
            user-select: none;
            margin-bottom: 0;
        }
        details.callout[open] summary.callout-title {
            margin-bottom: 8px;
        }
        details.callout summary.callout-title::-webkit-details-marker {
            display: none;
        }

        /* Footnotes */
        .footnotes {
            margin-top: 3em;
            padding-top: 1.5em;
            border-top: 1px solid var(--border-color);
            font-size: 0.88rem;
            color: var(--text-muted);
        }
        .footnotes ol {
            padding-left: 1.5em;
        }
        .footnotes li {
            margin-bottom: 0.6em;
        }
        .footnote-ref a, .footnote-backref {
            color: var(--electric-pink);
            text-decoration: none;
            font-weight: 600;
        }
        .footnote-ref a:hover, .footnote-backref:hover {
            text-decoration: underline;
        }

        /* LaTeX & Math Rendering */
        .math-block {
            margin: 1.5em 0;
            overflow-x: auto;
            text-align: center;
        }
        .katex-display {
            overflow-x: auto;
            overflow-y: hidden;
            padding: 8px 0;
        }

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

        .diagram-table .diagram-viewport {
            display: block;
            padding: 16px 20px;
        }

        .diagram-table .joaju-academic-table-wrapper {
            margin: 0 auto;
            width: 100%;
            overflow-x: auto;
        }

        .joaju-academic-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            line-height: 1.4;
            margin: 8px 0;
            border-top: 2px solid var(--text-main, #000) !important;
            border-bottom: 2px solid var(--text-main, #000) !important;
            border-left: none !important;
            border-right: none !important;
        }

        .fuxico-article .joaju-academic-table th,
        .fuxico-article .joaju-academic-table td,
        [data-theme="acad"] .fuxico-article .joaju-academic-table th,
        [data-theme="acad"] .fuxico-article .joaju-academic-table td {
            border-left: none !important;
            border-right: none !important;
            background: transparent !important;
            box-shadow: none !important;
        }

        .fuxico-article .joaju-academic-table thead th,
        [data-theme="acad"] .fuxico-article .joaju-academic-table thead th {
            border-top: none !important;
            border-bottom: 1px solid var(--text-main, #000) !important;
            padding: 6px 8px;
            font-weight: 700;
            vertical-align: bottom;
            white-space: normal;
        }

        .fuxico-article .joaju-academic-table tbody td,
        [data-theme="acad"] .fuxico-article .joaju-academic-table tbody td {
            border-top: none !important;
            border-bottom: none !important;
            padding: 5px 8px;
            vertical-align: middle;
            white-space: normal;
        }

        .joaju-academic-table tbody tr:hover {
            background: rgba(0, 0, 0, 0.03) !important;
        }

        .joaju-table-title {
            font-weight: 700;
            font-size: 0.92rem;
            margin-bottom: 6px;
            text-align: left;
            color: var(--text-main, #000);
        }

        .joaju-table-note {
            font-size: 0.78rem;
            font-style: italic;
            margin-top: 6px;
            text-align: left;
            color: var(--text-muted, #555);
            line-height: 1.35;
        }

        [data-theme="acad"] .joaju-academic-table {
            border-top: 2px solid #000 !important;
            border-bottom: 2px solid #000 !important;
            font-family: 'Times New Roman', Times, Georgia, serif;
        }

        [data-theme="acad"] .joaju-academic-table thead th {
            border-bottom: 1px solid #000 !important;
            font-family: 'Times New Roman', Times, Georgia, serif;
        }

        [data-theme="acad"] .joaju-academic-table tbody td {
            font-family: 'Times New Roman', Times, Georgia, serif;
        }

        [data-theme="acad"] .joaju-table-title {
            font-family: 'Times New Roman', Times, Georgia, serif;
            font-weight: bold;
        }

        [data-theme="acad"] .joaju-table-note {
            font-family: 'Times New Roman', Times, Georgia, serif;
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
            background: #fafafa;
        }

        [data-theme="acad"] .code-block-header {
            background: #f4f4f5;
            color: #71717a;
            border-bottom: 1px solid #e4e4e7;
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
            background: transparent;
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

        .fuxico-article pre code,
        [data-theme="acad"] .fuxico-article pre code,
        [data-theme="zine"] .fuxico-article pre code {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            color: inherit !important;
            box-shadow: none !important;
            display: block;
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

        @media print {
            @page {
                size: A4 portrait;
                margin: 20mm 15mm 20mm 15mm;
            }

            body {
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 11pt !important;
                line-height: 1.4 !important;
            }

            .doc-toolbar,
            .trans-stripe,
            .duct-tape,
            .doc-footer,
            .diagram-actions,
            .btn-diagram-action,
            .btn-copy-code,
            .diagram-source {
                display: none !important;
            }

            .fuxico-container {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
            }

            .fuxico-article {
                max-width: 100% !important;
                padding: 0 !important;
            }

            .diagram-figure {
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
                page-break-inside: avoid;
                break-inside: avoid;
                margin: 1.5em 0 !important;
            }

            .diagram-header {
                display: none !important;
            }

            .diagram-viewport {
                padding: 0 !important;
            }

            .joaju-academic-table {
                font-size: 8.5pt !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .joaju-academic-table thead {
                display: table-header-group;
            }

            .joaju-academic-table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .fuxico-article img {
                max-width: 100% !important;
                box-shadow: none !important;
                border: none !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            a {
                text-decoration: underline !important;
                color: #000000 !important;
            }
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
                <button type="button" class="btn-tool" onclick="window.print()" title="Imprimir ou salvar em PDF">
                    📄 PDF
                </button>
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

        // 0. Strip Obsidian comments (%% ... %%) while protecting fenced code blocks
        $codeBlockPlaceholders = [];
        $markdown = preg_replace_callback('/```[\s\S]*?```/', function ($m) use (&$codeBlockPlaceholders) {
            $key = '___FUXICO_CB_'.count($codeBlockPlaceholders).'___';
            $codeBlockPlaceholders[$key] = $m[0];

            return $key;
        }, $markdown) ?? $markdown;

        $markdown = preg_replace('/%%.*?%%/s', '', $markdown) ?? $markdown;

        if (! empty($codeBlockPlaceholders)) {
            $markdown = strtr($markdown, $codeBlockPlaceholders);
        }

        $footnotes = [];

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
                    'pedtools' => ['engine' => 'pedtools', 'label' => 'HEREDOGRAMA (PEDTOOLS)'],
                    'kinship2' => ['engine' => 'kinship2', 'label' => 'HEREDOGRAMA (KINSHIP2)'],
                    'openchemlib' => ['engine' => 'openchemlib', 'label' => 'QUÍMICA (OPENCHEMLIB)'],
                    'openbabel' => ['engine' => 'openbabel', 'label' => 'QUÍMICA (OPENBABEL)'],
                    'table' => ['engine' => 'table', 'label' => 'TABELA ACADÊMICA'],
                    'd2' => ['engine' => 'd2', 'label' => 'DIAGRAMA D2'],
                    'plantuml' => ['engine' => 'plantuml', 'label' => 'PLANTUML'],
                    'graphviz' => ['engine' => 'graphviz', 'label' => 'GRAPHVIZ'],
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

            // 2.1 Display Math ($$ ... $$)
            if (str_starts_with(trim($line), '$$')) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $trimmedLine = trim($line);
                if (preg_match('/^\$\$(.+)\$\$$/', $trimmedLine, $singleMath)) {
                    $mathContent = htmlspecialchars(trim($singleMath[1]), ENT_NOQUOTES, 'UTF-8');
                    $htmlOutput[] = "<div class=\"math-block\">\$\${$mathContent}\$\$</div>";
                    $i++;

                    continue;
                }

                $mathLines = [];
                $i++;
                while ($i < $count && ! str_starts_with(trim($lines[$i]), '$$')) {
                    $mathLines[] = $lines[$i];
                    $i++;
                }
                $mathContent = htmlspecialchars(implode("\n", $mathLines), ENT_NOQUOTES, 'UTF-8');
                $htmlOutput[] = "<div class=\"math-block\">\$\$\n{$mathContent}\n\$\$</div>";
                if ($i < $count) {
                    $i++;
                }

                continue;
            }

            // 3. Obsidian Callout (> [!TYPE][+-]? Title)
            if (preg_match('/^>\s*\[!(\w+)\]([+-]?)(?:[ \t]*(.*))?$/', $line, $matches)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $type = strtolower($matches[1]);
                $collapseSign = $matches[2] ?? '';
                $rawFirstLine = trim($matches[3] ?? '');
                $calloutLines = [];
                $i++;
                while ($i < $count && str_starts_with($lines[$i], '>')) {
                    $cLine = preg_replace('/^>\s?/', '', $lines[$i]);
                    $calloutLines[] = $cLine;
                    $i++;
                }

                $title = $rawFirstLine;
                if (! empty($rawFirstLine) && strlen($rawFirstLine) > 40) {
                    $splitBody = null;
                    if (preg_match('/^(.*?\?)\s+(.+)$/us', $rawFirstLine, $splitM)) {
                        $title = $splitM[1];
                        $splitBody = $splitM[2];
                    } elseif (preg_match('/^(.*?)\s+(\*\*.*)$/us', $rawFirstLine, $splitM)) {
                        $title = $splitM[1];
                        $splitBody = $splitM[2];
                    } elseif (preg_match('/^(.*?[:])\s+(.+)$/us', $rawFirstLine, $splitM)) {
                        $title = $splitM[1];
                        $splitBody = $splitM[2];
                    } elseif (preg_match('/^([A-ZÀ-Ú].{5,55}?)\s+((?:Em|No|Na|Nos|Nas|Restringiu-se|Adotou-se|Observou-se|Trata-se|Para|Com|Este|Esta|Esse|Essa|Conforme|Segundo)\s+.*)$/us', $rawFirstLine, $splitM)) {
                        $title = $splitM[1];
                        $splitBody = $splitM[2];
                    }
                    if ($splitBody !== null) {
                        array_unshift($calloutLines, $splitBody);
                    }
                }
                if ($title === '') {
                    $title = ucfirst($type);
                }

                $titleHtml = self::renderInline($title, $options);

                $bodyHtml = '';
                if (! empty($calloutLines)) {
                    $subParsed = self::parse(implode("\n", $calloutLines), $options);
                    $bodyHtml = $subParsed['html'];
                }

                $iconSvg = self::getCalloutIcon($type);
                $foldIcon = '<span class="callout-fold"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>';

                if ($collapseSign === '+') {
                    $htmlOutput[] = "<details open class=\"callout callout-{$type}\">"
                        ."<summary class=\"callout-title\"><span class=\"callout-icon\">{$iconSvg}</span><span class=\"callout-title-text\">{$titleHtml}</span>{$foldIcon}</summary>"
                        .($bodyHtml !== '' ? "<div class=\"callout-content\">{$bodyHtml}</div>" : '')
                        .'</details>';
                } elseif ($collapseSign === '-') {
                    $htmlOutput[] = "<details class=\"callout callout-{$type}\">"
                        ."<summary class=\"callout-title\"><span class=\"callout-icon\">{$iconSvg}</span><span class=\"callout-title-text\">{$titleHtml}</span>{$foldIcon}</summary>"
                        .($bodyHtml !== '' ? "<div class=\"callout-content\">{$bodyHtml}</div>" : '')
                        .'</details>';
                } else {
                    $htmlOutput[] = "<div class=\"callout callout-{$type}\">"
                        ."<div class=\"callout-title\"><span class=\"callout-icon\">{$iconSvg}</span><span class=\"callout-title-text\">{$titleHtml}</span></div>"
                        .($bodyHtml !== '' ? "<div class=\"callout-content\">{$bodyHtml}</div>" : '')
                        .'</div>';
                }

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
                $quoteContent = self::renderInline(implode('<br>', $quoteLines), $options);
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
                $headingHtml = self::renderInline($titleRaw, $options);
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

                $htmlOutput[] = self::renderTable($tableRows, $options);

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
                    $itemText = self::renderInline($taskMatch[2], $options);
                    $htmlOutput[] = "<li class=\"task-list-item\"><input type=\"checkbox\" {$checked} disabled> {$itemText}</li>";
                } else {
                    $itemText = self::renderInline($content, $options);
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

            // 8.5. Indented Code Block (4 spaces or 1 tab - CommonMark)
            if (preg_match('/^(?: {4}|\t)(.*)$/', $line, $indentMatch)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }

                $codeLines = [$indentMatch[1]];
                $i++;
                while ($i < $count) {
                    if (preg_match('/^(?: {4}|\t)(.*)$/', $lines[$i], $subMatch)) {
                        $codeLines[] = $subMatch[1];
                        $i++;
                    } elseif (trim($lines[$i]) === '') {
                        $k = $i + 1;
                        while ($k < $count && trim($lines[$k]) === '') {
                            $k++;
                        }
                        if ($k < $count && preg_match('/^(?: {4}|\t)/', $lines[$k])) {
                            while ($i < $k) {
                                $codeLines[] = '';
                                $i++;
                            }
                        } else {
                            break;
                        }
                    } elseif (trim($lines[$i]) !== '') {
                        // Check if an unindented line is part of a diagram or flanked by indented lines before an empty line
                        $hasIndentLater = false;
                        for ($k = $i + 1; $k < $count && trim($lines[$k]) !== ''; $k++) {
                            if (preg_match('/^(?: {4}|\t)/', $lines[$k])) {
                                $hasIndentLater = true;
                                break;
                            }
                        }
                        $isDiagramLine = (bool) preg_match('/(-->|->|--+|\x5c|\/|\||\[.*?\])/', $lines[$i]);
                        if ($hasIndentLater || $isDiagramLine) {
                            $codeLines[] = $lines[$i];
                            $i++;
                        } else {
                            break;
                        }
                    } else {
                        break;
                    }
                }

                $codeText = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8');
                $isAsciiDiagram = (bool) preg_match('/(-->|->|--+|\x5c|\/|\|)/', $codeText);
                $blockLabel = $isAsciiDiagram ? 'DIAGRAMA' : 'TEXT';

                $htmlOutput[] = '<div class="code-block-wrapper">'
                    .'<div class="code-block-header">'
                    .'<span>'.$blockLabel.'</span>'
                    .'<button type="button" class="btn-copy-code" onclick="copyCode(this)">Copiar</button>'
                    .'</div>'
                    ."<pre><code>{$codeText}</code></pre>"
                    .'</div>';

                continue;
            }

            // 8.8 Footnote Definition ([^id]: content)
            if (preg_match('/^\[\^([^\]]+)\]:\s*(.*)$/', $line, $fnMatch)) {
                if ($inList) {
                    $htmlOutput[] = "</{$listType}>";
                    $inList = false;
                }
                $fnKey = trim($fnMatch[1]);
                $fnBody = [$fnMatch[2]];
                $i++;
                while ($i < $count && (preg_match('/^(?: {4}|\t)(.*)$/', $lines[$i], $fnSub) || (trim($lines[$i]) !== '' && ! preg_match('/^(#{1,6}\s|>\s|```|\-{3,}|\*{3,}|\||[*+-]\s|\d+\.\s|\$\$|\[\^)/', $lines[$i])))) {
                    $fnBody[] = isset($fnSub[1]) ? $fnSub[1] : $lines[$i];
                    $i++;
                }
                $footnotes[$fnKey] = implode(' ', $fnBody);

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
                   ! preg_match('/^(#{1,6}\s|>\s|```|\-{3,}|\*{3,}|\||[*+-]\s|\d+\.\s|\$\$| {4}|\t|\[\^)/', $lines[$i])) {
                $paraLines[] = $lines[$i];
                $i++;
            }

            if (! empty($paraLines)) {
                $paraText = self::renderInline(implode(' ', $paraLines), $options);
                $htmlOutput[] = "<p>{$paraText}</p>";
            } else {
                $i++;
            }
        }

        if ($inList) {
            $htmlOutput[] = "</{$listType}>";
        }

        if (! empty($footnotes)) {
            $fnHtml = '<section class="footnotes"><hr><ol>';
            foreach ($footnotes as $fnId => $fnText) {
                $escapedId = htmlspecialchars($fnId, ENT_QUOTES, 'UTF-8');
                $fnContent = self::renderInline($fnText, $options);
                $fnHtml .= "<li id=\"fn-{$escapedId}\">{$fnContent} <a href=\"#fnref-{$escapedId}\" class=\"footnote-backref\">↩</a></li>";
            }
            $fnHtml .= '</ol></section>';
            $htmlOutput[] = $fnHtml;
        }

        return [
            'html' => implode("\n", $htmlOutput),
            'meta' => $meta,
        ];
    }

    /**
     * Renders inline markdown formatting.
     */
    public static function renderInline(string $text, array $options = []): string
    {
        $slug = trim((string) ($options['slug'] ?? ''));
        $assetPrefix = $slug !== '' ? "/{$slug}/assets/" : 'assets/';

        // 0. Protect Inline Code (`...`) from asterisks, underscores, and formatting
        $codePlaceholders = [];
        $text = preg_replace_callback('/`([^`]+)`/', function ($matches) use (&$codePlaceholders) {
            $key = "\x1BCODE".count($codePlaceholders)."X\x1B";
            $codePlaceholders[$key] = '<code>'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'</code>';

            return $key;
        }, $text) ?? $text;

        // 0.1 Protect Inline Math ($...$) from markdown asterisks/underscores
        $mathPlaceholders = [];
        $text = preg_replace_callback('/(?<!\\\\)\$(?!\$)(.*?)(?<!\\\\)\$/s', function ($matches) use (&$mathPlaceholders) {
            $key = "\x1BMATH".count($mathPlaceholders)."X\x1B";
            $mathPlaceholders[$key] = '$'.htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8').'$';

            return $key;
        }, $text) ?? $text;

        // 1. Obsidian Wikilink Images: ![[imagem.png|alt]] or ![[imagem.png]]
        $text = preg_replace_callback('/!\[\[(.*?)\]\]/', function ($matches) use ($assetPrefix) {
            $parts = explode('|', $matches[1]);
            $src = trim($parts[0]);
            $alt = isset($parts[1]) ? trim($parts[1]) : basename($src);
            $cleanSrc = $assetPrefix.basename($src);

            return '<img src="'.htmlspecialchars($cleanSrc, ENT_QUOTES, 'UTF-8').'" alt="'.htmlspecialchars($alt, ENT_QUOTES, 'UTF-8').'" loading="lazy">';
        }, $text) ?? $text;

        // 2. Standard Images: ![alt](url)
        $text = preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function ($matches) use ($assetPrefix) {
            $alt = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $rawSrc = trim($matches[2]);
            if (! preg_match('/^(https?:\/\/|data:|\/)/', $rawSrc)) {
                $src = $assetPrefix.basename($rawSrc);
            } else {
                $src = $rawSrc;
            }
            $cleanSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');

            return "<img src=\"{$cleanSrc}\" alt=\"{$alt}\" loading=\"lazy\">";
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

        // 5. Footnote references: [^id]
        $text = preg_replace_callback('/\[\^([^\]]+)\]/', function ($matches) {
            $id = htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');

            return '<sup class="footnote-ref"><a href="#fn-'.$id.'" id="fnref-'.$id.'">['.$id.']</a></sup>';
        }, $text) ?? $text;

        // 6. Obsidian Mark / Highlight ==text==
        $text = preg_replace_callback('/==(.*?)==/', function ($matches) {
            return '<mark>'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'</mark>';
        }, $text) ?? $text;

        // Protect generated HTML tags from markdown asterisks and underscores
        $htmlPlaceholders = [];
        $text = preg_replace_callback('/<[^>]+>/s', function ($matches) use (&$htmlPlaceholders) {
            $key = "\x1BHTML".count($htmlPlaceholders)."X\x1B";
            $htmlPlaceholders[$key] = $matches[0];

            return $key;
        }, $text) ?? $text;

        // 7. Bold **text** or __text__
        $text = preg_replace('/(\*\*|__)(.*?)\1/', '<strong>$2</strong>', $text) ?? $text;

        // 8. Italic *text* or _text_ (com proteção para underscores intra-palavra)
        $text = preg_replace('/(?<!\w)\*([^\*\s]+.*?)\*(?!\w)/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<!\w)_([^_\s]+.*?)_(?!\w)/', '<em>$1</em>', $text) ?? $text;

        // 9. Strikethrough ~~text~~
        $text = preg_replace('/~~(.*?)~~/', '<del>$1</del>', $text) ?? $text;

        // Restore protected HTML tags
        if (! empty($htmlPlaceholders)) {
            $text = strtr($text, $htmlPlaceholders);
        }

        // Restore protected Inline Code and Inline Math
        if (! empty($codePlaceholders)) {
            $text = strtr($text, $codePlaceholders);
        }
        if (! empty($mathPlaceholders)) {
            $text = strtr($text, $mathPlaceholders);
        }

        return $text;
    }

    /**
     * Renders Markdown table rows into responsive HTML table.
     *
     * @param  string[]  $rows
     */
    private static function renderTable(array $rows, array $options = []): string
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
            $html .= "<th{$alignStyle}>".self::renderInline($h, $options).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        for ($r = 2; $r < count($rows); $r++) {
            $cells = array_map('trim', explode('|', trim($rows[$r], '|')));
            $html .= '<tr>';
            foreach ($cells as $idx => $c) {
                $alignStyle = isset($alignments[$idx]) ? ' style="text-align:'.$alignments[$idx].';"' : '';
                $html .= "<td{$alignStyle}>".self::renderInline($c, $options).'</td>';
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
        if (! is_array($data)) {
            return null;
        }

        if (! empty($data['html']) && is_string($data['html'])) {
            return trim($data['html']);
        }

        if (empty($data['svg']) || ! is_string($data['svg'])) {
            return null;
        }

        $svg = $data['svg'];
        $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? $svg;

        return trim($svg);
    }

    /**
     * Returns an inline SVG icon for Obsidian callout types.
     */
    public static function getCalloutIcon(string $type): string
    {
        $icons = [
            'note' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>',
            'abstract' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
            'summary' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
            'tldr' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
            'info' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
            'todo' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="m9 12 2 2 4-4"/></svg>',
            'tip' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'hint' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'important' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            'success' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
            'check' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
            'done' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
            'question' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
            'help' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
            'faq' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
            'warning' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
            'caution' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
            'attention' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
            'failure' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'fail' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'missing' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'danger' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'error' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'bug' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>',
            'example' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg>',
            'quote' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg>',
            'cite' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg>',
        ];

        return $icons[$type] ?? $icons['note'];
    }
}
