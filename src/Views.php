<?php

declare(strict_types=1);

namespace Fuxico;

class Views
{
    public static function renderWebUI(Config $config, ?I18n $i18n = null): string
    {
        $i18n ??= new I18n;
        $publicMode = $config->publicMode ? 'true' : 'false';
        $maxSizeMb = (int) ($config->maxUploadSize / 1048576);
        $lang = $i18n->getLang();
        $strings = json_encode($i18n->getAll(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $t = fn (string $key): string => htmlspecialchars($i18n->t($key), ENT_QUOTES, 'UTF-8');

        $activePt = $lang === 'pt' ? 'active' : '';
        $activeEn = $lang === 'en' ? 'active' : '';
        $publicNotice = $config->publicMode ? $t('token_public') : '';
        $dashTabStyle = $config->publicMode ? '' : 'style="display:none;"';
        $footerTravestiLink = htmlspecialchars($i18n->t('footer_travesti_link'), ENT_QUOTES, 'UTF-8');
        $tip = fn (string $key): string => '<span class="tip-trigger" tabindex="0" role="tooltip" aria-label="'.$t($key).'">?<span class="tip-bubble">'.$t($key).'</span></span>';

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$t('app_name')} - {$t('tagline')}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #121016;
            --card-bg: #1a1622;
            --card-surface: #221c2e;
            --text-main: #f5f4ef;
            --text-muted: #9f97ad;
            --acid-yellow: #e2f835;
            --acid-yellow-hover: #ccee22;
            --electric-pink: #ff2a85;
            --tape-black: #0a080d;
            --border-width: 2px;
            --border-color: #383047;
            --border-acid: #e2f835;
            --font-display: 'Anton', -apple-system, sans-serif;
            --font-heading: 'Space Grotesk', -apple-system, sans-serif;
            --font-mono: 'Space Mono', monospace;
            --shadow-hard: 4px 4px 0px #0a080d;
            --shadow-acid: 4px 4px 0px #e2f835;
            --shadow-pink: 4px 4px 0px #ff2a85;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-mono);
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle, rgba(226, 248, 53, 0.05) 1.5px, transparent 1.5px),
                radial-gradient(circle at 10% 20%, rgba(255, 42, 133, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(226, 248, 53, 0.06) 0%, transparent 40%);
            background-size: 20px 20px, 100% 100%, 100% 100%;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px;
        }

        /* Tape effect decor */
        .duct-tape {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%) rotate(-3.5deg);
            background: rgba(226, 248, 53, 0.95);
            color: #000;
            font-family: var(--font-display);
            font-size: 0.75rem;
            letter-spacing: 2px;
            padding: 3px 32px;
            box-shadow: 2px 2px 0px rgba(0,0,0,0.5);
            z-index: 10;
            user-select: none;
            text-transform: uppercase;
        }

        .container {
            position: relative;
            width: 100%;
            max-width: 840px;
            background: var(--card-bg);
            border: var(--border-width) solid var(--acid-yellow);
            box-shadow: 8px 12px 0px #0a080d, 8px 12px 0px 2px var(--electric-pink);
            overflow: visible;
            display: flex;
            flex-direction: column;
            margin-top: 10px;
        }

        /* Trans Pride Stripe */
        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 25px;
            flex-shrink: 0;
            border-bottom: 2px solid #000;
        }

        .trans-stripe.bottom {
            border-top: 2px solid #000;
            border-bottom: none;
        }

        .trans-stripe-line {
            flex: 1;
            width: 100%;
        }

        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }

        /* Top Header */
        /* Top Header */
        .top-bar {
            background: var(--tape-black);
            padding: 14px 24px 18px 24px;
            border-bottom: var(--border-width) solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
        }

        .lang-switch-wrap {
            position: absolute;
            top: 14px;
            right: 18px;
            z-index: 5;
        }

        .header-center-row {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 10px;
            margin-top: 4px;
        }

        .brand-title-row {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .brand {
            font-family: var(--font-display);
            font-size: 2.6rem;
            line-height: 1;
            letter-spacing: 1px;
            color: var(--acid-yellow);
            text-shadow: 3px 3px 0px var(--electric-pink);
            text-transform: uppercase;
        }

        .brand-desc {
            font-family: var(--font-heading);
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--acid-yellow);
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .badges-row {
            display: flex;
            align-items: stretch;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            width: 100%;
        }

        .badge-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 6px 14px;
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 700;
            text-align: center;
            box-shadow: 2px 2px 0px #000;
            flex: 1 1 260px;
            max-width: 360px;
            min-height: 32px;
            box-sizing: border-box;
        }

        .tagline-badge {
            background: var(--electric-pink);
            color: #fff;
            border: 1px solid #000;
        }

        .cultural-badge {
            background: var(--tape-black);
            color: #5BCEFA;
            border: 1px solid #F5A9B8;
        }

        .lang-switch {
            display: flex;
            gap: 4px;
            background: var(--card-surface);
            padding: 4px;
            border: 1px solid var(--border-color);
        }

        .lang-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 8px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .lang-btn:hover {
            color: var(--acid-yellow);
        }

        .lang-btn.active {
            background: var(--acid-yellow);
            color: #000;
            box-shadow: 2px 2px 0px #000;
        }

        @media (max-width: 640px) {
            .brand-title-row {
                flex-direction: column;
                align-items: center;
                gap: 4px;
            }
            .lang-switch-wrap {
                position: static;
                align-self: flex-end;
            }
            .badge-box {
                max-width: 100%;
                flex: 1 1 100%;
            }
        }

        /* Token Section */
        .token-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .token-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
        }

        .token-input {
            width: 100%;
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            padding: 10px 14px;
            color: var(--acid-yellow);
            font-family: var(--font-mono);
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .token-input:focus {
            border-color: var(--acid-yellow);
            box-shadow: 2px 2px 0px var(--acid-yellow);
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: #14111a;
            border-bottom: var(--border-width) solid var(--border-color);
        }

        .tab-btn {
            flex: 1;
            padding: 14px 20px;
            background: none;
            border: none;
            border-right: 1px solid var(--border-color);
            color: var(--text-muted);
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .tab-btn:last-child {
            border-right: none;
        }

        .tab-btn:hover {
            color: var(--text-main);
            background: rgba(226, 248, 53, 0.05);
        }

        .tab-btn.active {
            color: #000;
            background: var(--acid-yellow);
            box-shadow: inset 0 -3px 0px var(--electric-pink);
        }

        /* Main Content */
        .content-body {
            padding: 28px 24px;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Zine Dropzone */
        .dropzone {
            border: 2px dashed var(--acid-yellow);
            background: #15111c;
            padding: 48px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.4);
        }

        .dropzone:hover, .dropzone.dragover {
            border-color: var(--electric-pink);
            background: #201729;
            transform: scale(0.995);
            box-shadow: 4px 4px 0px var(--electric-pink);
        }

        .drop-icon {
            font-size: 2.8rem;
            margin-bottom: 12px;
            display: block;
            filter: drop-shadow(2px 2px 0px var(--electric-pink));
        }

        .drop-text {
            font-family: var(--font-heading);
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        .drop-sub {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Options */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
            margin: 22px 0;
        }

        .option-item {
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s ease;
        }

        .option-item:hover {
            border-color: var(--acid-yellow);
            background: #272033;
        }

        .option-item input[type="checkbox"] {
            appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid var(--text-muted);
            background: var(--tape-black);
            cursor: pointer;
            position: relative;
        }

        .option-item input[type="checkbox"]:checked {
            border-color: var(--acid-yellow);
            background: var(--acid-yellow);
        }

        .option-item input[type="checkbox"]:checked::after {
            content: '✔';
            position: absolute;
            top: -2px;
            left: 2px;
            font-size: 13px;
            color: #000;
            font-weight: bold;
        }

        .option-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-main);
            display: inline-flex;
            align-items: center;
        }

        /* Zine Tooltips */
        .tip-trigger {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: #272033;
            border: 1px solid var(--acid-yellow);
            color: var(--acid-yellow);
            font-family: var(--font-mono);
            font-size: 0.65rem;
            font-weight: 700;
            cursor: help;
            margin-left: 8px;
            vertical-align: middle;
            user-select: none;
            line-height: 1;
            box-shadow: 1px 1px 0px #000;
        }

        .tip-trigger:hover, .tip-trigger:focus {
            background: var(--electric-pink);
            border-color: #fff;
            color: #fff;
            outline: none;
            box-shadow: 2px 2px 0px #000;
        }

        .tip-bubble {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            bottom: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%);
            background: #0a080d;
            border: 1px solid var(--electric-pink);
            box-shadow: 4px 4px 0px #000, 4px 4px 0px 1px var(--acid-yellow);
            color: #f5f4ef;
            font-family: var(--font-mono);
            font-size: 0.72rem;
            font-weight: 400;
            padding: 10px 14px;
            width: 270px;
            max-width: 82vw;
            line-height: 1.45;
            z-index: 1000;
            pointer-events: none;
            transition: opacity 0.15s ease, visibility 0.15s ease;
            text-align: left;
            white-space: normal;
        }

        .tip-bubble::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 6px;
            border-style: solid;
            border-color: var(--electric-pink) transparent transparent transparent;
        }

        .tip-trigger:hover .tip-bubble,
        .tip-trigger:focus .tip-bubble {
            visibility: visible;
            opacity: 1;
        }

        .form-row {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 18px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
        }

        .form-control {
            width: 100%;
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            padding: 12px 14px;
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.95rem;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--acid-yellow);
            box-shadow: 2px 2px 0px var(--acid-yellow);
        }

        /* Action Button */
        .btn-action {
            width: 100%;
            background: var(--acid-yellow);
            color: #000;
            border: 2px solid #000;
            box-shadow: 4px 4px 0px #000, 4px 4px 0px 2px var(--electric-pink);
            padding: 16px 24px;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1.15rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.1s ease;
            margin-top: 10px;
        }

        .btn-action:hover {
            background: var(--acid-yellow-hover);
            transform: translate(-1px, -1px);
            box-shadow: 5px 5px 0px #000, 5px 5px 0px 2px var(--electric-pink);
        }

        .btn-action:active {
            transform: translate(2px, 2px);
            box-shadow: 2px 2px 0px #000;
        }

        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Result Banner */
        .result-box {
            display: none;
            margin-top: 24px;
            padding: 18px;
            background: #16261c;
            border: 2px solid #38a169;
            box-shadow: 4px 4px 0px #0a080d;
        }

        .result-box.open {
            display: block;
        }

        .result-title {
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 700;
            color: #48bb78;
            margin-bottom: 8px;
        }

        .result-url-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .result-input {
            flex: 1;
            background: #0d1610;
            border: 1px solid #276749;
            padding: 10px 12px;
            color: #9ae6b4;
            font-family: var(--font-mono);
            font-size: 0.95rem;
            outline: none;
        }

        .btn-copy {
            background: #38a169;
            color: #fff;
            border: 1px solid #000;
            font-family: var(--font-heading);
            font-weight: 700;
            padding: 10px 16px;
            cursor: pointer;
            box-shadow: 2px 2px 0px #000;
        }

        .btn-copy:hover {
            background: #48bb78;
        }

        /* Dashboard & Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--tape-black);
            border: 1px solid var(--border-color);
            padding: 14px;
            text-align: center;
            box-shadow: 3px 3px 0px rgba(0,0,0,0.5);
        }

        .stat-val {
            font-family: var(--font-display);
            font-size: 1.9rem;
            color: var(--acid-yellow);
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        .filter-row {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .dash-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .dash-table th {
            background: var(--tape-black);
            color: var(--acid-yellow);
            font-family: var(--font-heading);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid var(--border-color);
        }

        .dash-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--card-bg);
        }

        .dash-table tr:hover td {
            background: var(--card-surface);
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid #000;
            box-shadow: 1px 1px 0px #000;
        }

        .badge-file { background: var(--acid-yellow); color: #000; }
        .badge-url { background: #38bdf8; color: #000; }
        .badge-oneshot { background: var(--electric-pink); color: #fff; }

        .btn-action-icon {
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            padding: 4px 8px;
            cursor: pointer;
            box-shadow: 2px 2px 0px #000;
            text-decoration: none;
            display: inline-block;
        }

        .btn-action-icon:hover {
            border-color: var(--acid-yellow);
            color: var(--acid-yellow);
        }

        .btn-delete {
            border-color: var(--electric-pink);
            color: var(--electric-pink);
        }

        .btn-delete:hover {
            background: var(--electric-pink);
            color: #fff;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(10, 8, 13, 0.85);
            backdrop-filter: blur(5px);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.open {
            display: flex;
        }

        .modal-card {
            background: var(--card-bg);
            border: 2px solid var(--acid-yellow);
            box-shadow: 8px 8px 0px var(--electric-pink);
            max-width: 90vw;
            max-height: 90vh;
            padding: 24px;
            position: relative;
            overflow: auto;
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 14px;
            background: var(--electric-pink);
            color: #fff;
            border: 1px solid #000;
            font-family: var(--font-mono);
            font-size: 1.2rem;
            padding: 2px 8px;
            cursor: pointer;
            box-shadow: 2px 2px 0px #000;
        }

        /* Footer */
        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }

        .footer a {
            color: var(--acid-yellow);
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
        <div class="duct-tape">MALÍCIA POPULAR // GOSSIP PROTOCOL</div>

        <!-- Header -->
        <header class="top-bar">
            <div class="lang-switch-wrap">
                <div class="lang-switch">
                    <button class="lang-btn {$activePt}" onclick="setLanguage('pt')">PT-BR</button>
                    <button class="lang-btn {$activeEn}" onclick="setLanguage('en')">EN</button>
                </div>
            </div>
            <div class="header-center-row">
                <div class="brand-title-row">
                    <h1 class="brand">{$t('app_name')}</h1>
                    <p class="brand-desc">{$t('brand_sub')}</p>
                </div>
                <div class="badges-row">
                    <span class="badge-box tagline-badge">⚡ {$t('tagline')}</span>
                    <span class="badge-box cultural-badge">{$t('cultural_badge')}</span>
                </div>
            </div>

            <!-- Token Section -->
            <div class="token-group">
                <label class="token-label" for="authToken">
                    <span style="display:inline-flex; align-items:center;">{$t('token_label')}{$tip('tip_token')}</span>
                    <span style="color:var(--acid-yellow);">{$publicNotice}</span>
                </label>
                <input type="password" id="authToken" class="token-input" placeholder="{$t('token_placeholder')}" spellcheck="false">
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="tabs">
            <button class="tab-btn active" onclick="switchTab('uploadTab', this)">📁 {$t('tab_file')}</button>
            <button class="tab-btn" onclick="switchTab('urlTab', this)">🔗 {$t('tab_url')}</button>
            <button class="tab-btn" id="dashTabBtn" onclick="switchTab('dashTab', this)" {$dashTabStyle}>👁️ {$t('tab_dash')}</button>
        </nav>

        <!-- Content Area -->
        <main class="content-body">
            <!-- 1. File Upload Tab -->
            <section id="uploadTab" class="tab-pane active">
                <div id="dropzone" class="dropzone">
                    <input type="file" id="fileInput" style="display:none;">
                    <span class="drop-icon">📦</span>
                    <p class="drop-text" id="dropTitle">{$t('drop_idle')}</p>
                    <p class="drop-sub">{$t('drop_sub')} (max: {$maxSizeMb} MB)</p>
                    <p id="selectedFileName" style="margin-top:14px; font-weight:700; color:var(--acid-yellow);"></p>
                </div>

                <div class="options-grid">
                    <label class="option-item">
                        <input type="checkbox" id="optOneshot">
                        <span class="option-label">🔥 {$t('opt_burn')}{$tip('tip_burn')}</span>
                    </label>
                    <label class="option-item">
                        <input type="checkbox" id="optSecret">
                        <span class="option-label">🕵️ {$t('opt_secret')}{$tip('tip_secret')}</span>
                    </label>
                </div>

                <div class="form-row">
                    <label class="form-label" for="optExpires" style="display:inline-flex; align-items:center;">⌛ {$t('opt_expires')}{$tip('tip_expires')}</label>
                    <select id="optExpires" class="form-control">
                        <option value="">{$t('exp_none')}</option>
                        <option value="1h">{$t('exp_1h')}</option>
                        <option value="24" selected>{$t('exp_24h')}</option>
                        <option value="7d">{$t('exp_7d')}</option>
                        <option value="30d">{$t('exp_30d')}</option>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label" for="optCustomName" style="display:inline-flex; align-items:center;">🏷️ {$t('custom_name_placeholder')}{$tip('tip_custom_name')}</label>
                    <input type="text" id="optCustomName" class="form-control" placeholder="fofoca-exclusiva">
                </div>

                <button id="btnUpload" class="btn-action" onclick="handleFileUpload()">{$t('btn_upload')}</button>

                <div id="uploadResult" class="result-box">
                    <p class="result-title">⚡ {$t('copy_success')}</p>
                    <div class="result-url-row">
                        <input type="text" id="resultUrl" class="result-input" readonly>
                        <button class="btn-copy" onclick="copyResultUrl()">{$t('act_copy')}</button>
                    </div>
                </div>
            </section>

            <!-- 2. URL Shortener Tab -->
            <section id="urlTab" class="tab-pane">
                <div class="form-row">
                    <label class="form-label" for="shortenUrl" style="display:inline-flex; align-items:center;">🌐 {$t('url_placeholder')}{$tip('tip_shorten_url')}</label>
                    <input type="url" id="shortenUrl" class="form-control" placeholder="https://...">
                </div>

                <div class="form-row">
                    <label class="form-label" for="urlCustomName" style="display:inline-flex; align-items:center;">🏷️ {$t('custom_name_placeholder')}{$tip('tip_custom_name')}</label>
                    <input type="text" id="urlCustomName" class="form-control" placeholder="link-quente">
                </div>

                <div class="options-grid">
                    <label class="option-item">
                        <input type="checkbox" id="urlOneshot">
                        <span class="option-label">🔥 {$t('opt_burn')}{$tip('tip_burn')}</span>
                    </label>
                    <label class="option-item">
                        <input type="checkbox" id="urlSecret">
                        <span class="option-label">🕵️ {$t('opt_secret')}{$tip('tip_secret')}</span>
                    </label>
                </div>

                <button id="btnShorten" class="btn-action" onclick="handleUrlShorten()">{$t('btn_shorten')}</button>

                <div id="shortenResult" class="result-box">
                    <p class="result-title">⚡ {$t('copy_success')}</p>
                    <div class="result-url-row">
                        <input type="text" id="shortenResultUrl" class="result-input" readonly>
                        <button class="btn-copy" onclick="copyShortenUrl()">{$t('act_copy')}</button>
                    </div>
                </div>
            </section>

            <!-- 3. Dashboard Tab -->
            <section id="dashTab" class="tab-pane">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div id="statFiles" class="stat-val">0</div>
                        <div class="stat-label">{$t('stat_files')}</div>
                    </div>
                    <div class="stat-card">
                        <div id="statUrls" class="stat-val">0</div>
                        <div class="stat-label">{$t('stat_urls')}</div>
                    </div>
                    <div class="stat-card">
                        <div id="statBytes" class="stat-val">0 MB</div>
                        <div class="stat-label">{$t('stat_bytes')}</div>
                    </div>
                    <div class="stat-card">
                        <div id="statViews" class="stat-val">0</div>
                        <div class="stat-label">{$t('stat_views')}</div>
                    </div>
                </div>

                <div class="filter-row">
                    <input type="text" id="dashSearch" class="form-control" style="flex:2;" placeholder="{$t('dash_search')}" oninput="debounce(loadDashboard, 300)()">
                    <select id="dashType" class="form-control" style="flex:1;" onchange="loadDashboard()">
                        <option value="">{$t('dash_filter_all')}</option>
                        <option value="file">{$t('dash_filter_files')}</option>
                        <option value="url">{$t('dash_filter_urls')}</option>
                    </select>
                </div>

                <div style="overflow-x:auto;">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>{$t('col_slug')}</th>
                                <th>{$t('col_type')}</th>
                                <th>{$t('col_size')}</th>
                                <th>{$t('col_exp')}</th>
                                <th>{$t('col_views')}</th>
                                <th>{$t('col_actions')}</th>
                            </tr>
                        </thead>
                        <tbody id="dashTableBody">
                            <tr>
                                <td colspan="6" style="text-align:center; color:var(--text-muted);">{$t('dash_auth_required')}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
        <div class="trans-stripe bottom" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="modal" onclick="closeModal()">
        <div class="modal-card" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeModal()">×</button>
            <div id="modalBody"></div>
        </div>
    </div>

    <footer class="footer">
        <p style="margin-bottom: 8px;">{$t('footer_credit_prefix')} <a href="{$footerTravestiLink}" target="_blank" rel="noopener noreferrer" style="color:var(--electric-pink); font-weight:700;">travesti</a> :)</p>
        <p><strong>FUXICO</strong> // <a href="https://codeberg.org/lumenpink/fuxico" target="_blank" rel="noopener noreferrer">Codeberg</a> // 100% 0x0.st &amp; rustypaste drop-in protocol</p>
    </footer>

    <script>
        const I18N = {$strings};
        const IS_PUBLIC_MODE = {$publicMode};
        const dashTabBtn = document.getElementById('dashTabBtn');

        function t(k) { return I18N[k] || k; }

        function setLanguage(lang) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        // Token management via localStorage
        const tokenInput = document.getElementById('authToken');
        const savedToken = localStorage.getItem('fuxico_token') || localStorage.getItem('pico_auth_token');
        if (savedToken) {
            tokenInput.value = savedToken;
        }

        async function updateAuthVisibility() {
            if (IS_PUBLIC_MODE) {
                if (dashTabBtn) dashTabBtn.style.display = '';
                return;
            }

            const token = tokenInput.value.trim();
            if (!token) {
                if (dashTabBtn) dashTabBtn.style.display = 'none';
                if (document.getElementById('dashTab').classList.contains('active')) {
                    switchTab('uploadTab', document.querySelector('.tab-btn'));
                }
                return;
            }

            try {
                const res = await fetch('/api/stats', { headers: { 'Authorization': 'Bearer ' + token } });
                if (res.ok) {
                    if (dashTabBtn) dashTabBtn.style.display = '';
                } else {
                    if (dashTabBtn) dashTabBtn.style.display = 'none';
                    if (document.getElementById('dashTab').classList.contains('active')) {
                        switchTab('uploadTab', document.querySelector('.tab-btn'));
                    }
                }
            } catch (e) {
                if (dashTabBtn) dashTabBtn.style.display = 'none';
            }
        }

        updateAuthVisibility();

        tokenInput.addEventListener('input', () => {
            const val = tokenInput.value.trim();
            localStorage.setItem('fuxico_token', val);
            updateAuthVisibility();
            if (document.getElementById('dashTab').classList.contains('active')) {
                loadDashboard();
            }
        });

        // Tab Switching
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(tabId).classList.add('active');

            if (tabId === 'dashTab') {
                loadDashboard();
            }
        }

        // File Drag & Drop
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const dropTitle = document.getElementById('dropTitle');
        let selectedFile = null;

        dropzone.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                selectFile(e.target.files[0]);
            }
        });

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
            dropTitle.innerText = t('drop_drag');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
            dropTitle.innerText = t('drop_idle');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            dropTitle.innerText = t('drop_idle');
            if (e.dataTransfer.files.length > 0) {
                selectFile(e.dataTransfer.files[0]);
            }
        });

        function selectFile(file) {
            selectedFile = file;
            document.getElementById('selectedFileName').innerText = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        }

        // File Upload
        async function handleFileUpload() {
            if (!selectedFile) {
                alert(t('drop_idle'));
                return;
            }

            const btn = document.getElementById('btnUpload');
            btn.disabled = true;
            btn.innerText = t('btn_uploading');

            const fd = new FormData();
            fd.append('file', selectedFile);

            const expires = document.getElementById('optExpires').value;
            if (expires) fd.append('expires', expires);

            if (document.getElementById('optOneshot').checked) fd.append('oneshot', 'true');
            if (document.getElementById('optSecret').checked) fd.append('secret', 'true');

            const customName = document.getElementById('optCustomName').value.trim();
            if (customName) fd.append('customName', customName);

            const headers = {};
            const token = tokenInput.value.trim();
            if (token) headers['Authorization'] = 'Bearer ' + token;

            try {
                const res = await fetch('/', { method: 'POST', body: fd, headers });
                const text = await res.text();
                if (res.ok) {
                    const url = text.trim();
                    document.getElementById('resultUrl').value = url;
                    document.getElementById('uploadResult').classList.add('open');
                } else {
                    alert('Erro no envio: ' + text);
                }
            } catch (err) {
                alert('Falha na rede: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = t('btn_upload');
            }
        }

        // URL Shortening
        async function handleUrlShorten() {
            const url = document.getElementById('shortenUrl').value.trim();
            if (!url) {
                alert('Informe uma URL válida.');
                return;
            }

            const btn = document.getElementById('btnShorten');
            btn.disabled = true;

            const fd = new FormData();
            fd.append('shorten', url);

            if (document.getElementById('urlOneshot').checked) fd.append('oneshot', 'true');
            if (document.getElementById('urlSecret').checked) fd.append('secret', 'true');

            const customName = document.getElementById('urlCustomName').value.trim();
            if (customName) fd.append('customName', customName);

            const headers = {};
            const token = tokenInput.value.trim();
            if (token) headers['Authorization'] = 'Bearer ' + token;

            try {
                const res = await fetch('/', { method: 'POST', body: fd, headers });
                const text = await res.text();
                if (res.ok) {
                    const shortUrl = text.trim();
                    document.getElementById('shortenResultUrl').value = shortUrl;
                    document.getElementById('shortenResult').classList.add('open');
                } else {
                    alert('Erro ao encurtar: ' + text);
                }
            } catch (err) {
                alert('Falha na rede: ' + err.message);
            } finally {
                btn.disabled = false;
            }
        }

        function copyResultUrl() {
            const val = document.getElementById('resultUrl').value;
            navigator.clipboard.writeText(val).then(() => alert(t('copy_success')));
        }

        function copyShortenUrl() {
            const val = document.getElementById('shortenResultUrl').value;
            navigator.clipboard.writeText(val).then(() => alert(t('copy_success')));
        }

        // Dashboard
        async function loadDashboard() {
            const tbody = document.getElementById('dashTableBody');
            const token = tokenInput.value.trim();

            if (!IS_PUBLIC_MODE && !token) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--text-muted);">' + t('dash_auth_required') + '</td></tr>';
                return;
            }

            const search = encodeURIComponent(document.getElementById('dashSearch').value.trim());
            const type = encodeURIComponent(document.getElementById('dashType').value);
            const headers = token ? { 'Authorization': 'Bearer ' + token } : {};

            try {
                // Stats
                const statsRes = await fetch('/api/stats', { headers });
                if (statsRes.ok) {
                    const stats = await statsRes.json();
                    document.getElementById('statFiles').innerText = stats.total_files;
                    document.getElementById('statUrls').innerText = stats.total_urls;
                    document.getElementById('statBytes').innerText = (stats.total_bytes / (1024*1024)).toFixed(1) + ' MB';
                    document.getElementById('statViews').innerText = stats.total_views;
                }

                // Items
                const res = await fetch('/api/items?search=' + search + '&type=' + type, { headers });

                if (!res.ok) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--electric-pink);">Acesso não autorizado ao cofre.</td></tr>';
                    return;
                }

                const data = await res.json();
                if (!data.items || data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--text-muted);">' + t('dash_empty') + '</td></tr>';
                    return;
                }

                let html = '';
                data.items.forEach(item => {
                    const sizeStr = item.type === 'file' ? (item.size / 1024).toFixed(1) + ' KB' : '-';
                    const typeBadge = item.type === 'file' ? '<span class="badge badge-file">' + t('tab_file') + '</span>' : '<span class="badge badge-url">URL</span>';
                    const oneshotBadge = item.is_oneshot ? '<span class="badge badge-oneshot">🔥 Oneshot</span>' : '';
                    const expStr = item.expires_at ? new Date(item.expires_at * 1000).toLocaleString() : t('exp_none');

                    html += '<tr>' +
                        '<td><strong style="color:var(--acid-yellow); font-family:var(--font-mono);">' + escapeHtml(item.slug) + '</strong><br><small style="color:var(--text-muted);">' + escapeHtml(item.original_name || '') + '</small></td>' +
                        '<td>' + typeBadge + '</td>' +
                        '<td>' + sizeStr + '</td>' +
                        '<td>' + oneshotBadge + ' <small style="color:var(--text-muted);">' + expStr + '</small></td>' +
                        '<td>' + item.views + '</td>' +
                        '<td>' +
                            '<button class="btn-action-icon" onclick="copyText(\'' + escapeHtml(item.url) + '\')">' + t('act_copy') + '</button> ' +
                            (item.type === 'file' ? '<button class="btn-action-icon" onclick="previewItem(\'' + escapeHtml(item.url) + '\', \'' + escapeHtml(item.mime_type) + '\')">' + t('act_view') + '</button> ' : '<a class="btn-action-icon" href="' + escapeHtml(item.url) + '" target="_blank">' + t('act_open') + '</a> ') +
                            '<button class="btn-action-icon btn-delete" onclick="deleteItem(\'' + escapeHtml(item.slug) + '\')">' + t('act_delete') + '</button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--electric-pink);">Erro ao carregar dados: ' + escapeHtml(err.message) + '</td></tr>';
            }
        }

        async function deleteItem(slug) {
            if (!confirm(t('delete_confirm'))) return;
            const token = tokenInput.value.trim();
            try {
                const res = await fetch('/api/items/' + slug, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                if (res.ok) {
                    loadDashboard();
                } else {
                    alert('Falha ao apagar item.');
                }
            } catch (err) {
                alert('Erro: ' + err.message);
            }
        }

        function previewItem(url, mime) {
            const modal = document.getElementById('previewModal');
            const body = document.getElementById('modalBody');
            body.innerHTML = '';

            if (mime.startsWith('image/')) {
                body.innerHTML = '<img src="' + url + '" style="max-width:100%; max-height:75vh; border:2px solid var(--acid-yellow);">';
            } else if (mime.startsWith('video/')) {
                body.innerHTML = '<video src="' + url + '" controls autoplay style="max-width:100%; max-height:75vh; border:2px solid var(--acid-yellow);"></video>';
            } else if (mime.startsWith('audio/')) {
                body.innerHTML = '<audio src="' + url + '" controls autoplay style="width:100%; margin-top:20px;"></audio>';
            } else {
                window.open(url, '_blank');
                return;
            }
            modal.classList.add('open');
        }

        function closeModal() {
            document.getElementById('previewModal').classList.remove('open');
            document.getElementById('modalBody').innerHTML = '';
        }

        function copyText(str) {
            navigator.clipboard.writeText(str).then(() => alert(t('copy_success')));
        }

        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>'"]/g, tag => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[tag] || tag));
        }
    </script>
</body>
</html>
HTML;
    }

    public static function renderErrorPage(int $statusCode, string $titleKey, string $descKey, ?I18n $i18n = null): string
    {
        $i18n ??= new I18n;
        $title = htmlspecialchars($i18n->t($titleKey), ENT_QUOTES, 'UTF-8');
        $desc = htmlspecialchars($i18n->t($descKey), ENT_QUOTES, 'UTF-8');
        $back = htmlspecialchars($i18n->t('back_home'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$i18n->getLang()}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} - {$title}</title>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #121016;
            background-image: radial-gradient(circle, rgba(226, 248, 53, 0.05) 1.5px, transparent 1.5px);
            background-size: 20px 20px;
            color: #f5f4ef;
            font-family: 'Space Mono', monospace;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
            margin: 0;
        }
        .error-card {
            background: #1a1622;
            border: 2px solid #ff2a85;
            box-shadow: 6px 6px 0px #e2f835;
            padding: 40px 30px;
            max-width: 540px;
            text-align: center;
        }
        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 18px;
            margin-bottom: 24px;
            border-bottom: 2px solid #000;
        }
        .trans-stripe-line {
            flex: 1;
            width: 100%;
        }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }
        .error-code {
            font-family: 'Anton', sans-serif;
            font-size: 5rem;
            color: #ff2a85;
            line-height: 1;
            margin-bottom: 12px;
            text-shadow: 3px 3px 0px #000;
        }
        .error-title {
            font-family: 'Anton', sans-serif;
            font-size: 1.8rem;
            color: #e2f835;
            margin-bottom: 16px;
            text-transform: uppercase;
        }
        .error-desc {
            font-size: 0.95rem;
            color: #9f97ad;
            margin-bottom: 28px;
            line-height: 1.5;
        }
        .btn-home {
            display: inline-block;
            background: #e2f835;
            color: #000;
            border: 2px solid #000;
            box-shadow: 3px 3px 0px #000;
            padding: 12px 24px;
            font-weight: 700;
            text-decoration: none;
            text-transform: uppercase;
        }
        .btn-home:hover {
            background: #ccee22;
            transform: translate(-1px, -1px);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="trans-stripe" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
        <div class="error-code">{$statusCode}</div>
        <h1 class="error-title">{$title}</h1>
        <p class="error-desc">{$desc}</p>
        <a href="/" class="btn-home">{$back}</a>
    </div>
</body>
</html>
HTML;
    }
}
