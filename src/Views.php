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
            top: -2px;
            left: 50%;
            transform: translateX(-50%) rotate(-2.5deg);
            background: rgba(226, 248, 53, 0.96);
            color: #000;
            font-family: var(--font-display);
            font-size: 0.75rem;
            letter-spacing: 2px;
            padding: 4px 34px;
            box-shadow: 3px 3px 0px rgba(0,0,0,0.6);
            z-index: 10;
            user-select: none;
            text-transform: uppercase;
            clip-path: polygon(
                0% 4px, 4px 0%, 8px 3px, 12px 0%, 100% 0%, 
                97% 6px, 100% 12px, 98% 18px, 100% 100%, 
                96% 96%, 92% 100%, 88% 97%, 0% 100%, 
                3% 82%, 0% 64%, 2% 48%, 0% 32%, 3% 16%
            );
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
        .top-bar {
            background: var(--tape-black);
            padding: 16px 24px 18px 24px;
            border-bottom: var(--border-width) solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .brand-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .brand-box {
            display: inline-flex;
            flex-direction: column;
            width: fit-content;
        }

        .brand {
            font-family: var(--font-display);
            font-size: 2.8rem;
            line-height: 0.95;
            letter-spacing: 1px;
            color: var(--acid-yellow);
            text-shadow: 3px 3px 0px var(--electric-pink);
            text-transform: uppercase;
        }

        .brand-desc {
            font-family: var(--font-heading);
            font-size: 0.81rem;
            font-weight: 700;
            color: var(--acid-yellow);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.15;
            margin-top: 5px;
            width: 100%;
        }

        .brand-desc-line {
            display: block;
            width: 100%;
            text-align: justify;
            text-align-last: justify;
        }

        /* Zine Collage Centerpiece */
        .zine-header-collage {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            flex: 1;
            margin: 0 10px;
            padding: 4px 6px;
            min-width: 250px;
            max-width: 460px;
            gap: 6px;
        }

        .zine-decor-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.85;
            z-index: 0;
        }

        .zine-halftone-svg {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0.65;
        }

        .zine-stamp-watermark {
            font-family: var(--font-display);
            font-size: 0.65rem;
            letter-spacing: 3px;
            color: rgba(255, 42, 133, 0.22);
            transform: rotate(-5deg);
            white-space: nowrap;
            user-select: none;
            border: 1px dashed rgba(255, 42, 133, 0.28);
            padding: 2px 8px;
        }

        .zine-scrap {
            position: relative;
            z-index: 1;
            display: inline-flex;
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .zine-scrap:hover {
            transform: scale(1.03) !important;
            z-index: 5;
        }

        .scrap-tagline {
            transform: rotate(-2.2deg);
            align-self: flex-start;
            margin-left: 6px;
        }

        .scrap-cultural {
            transform: rotate(1.8deg);
            align-self: flex-end;
            margin-top: -3px;
            margin-right: 6px;
        }

        /* Tape Strips */
        .tape-strip {
            position: absolute;
            width: 26px;
            height: 10px;
            background: rgba(226, 248, 53, 0.45);
            backdrop-filter: blur(1px);
            border-left: 1px dashed rgba(0,0,0,0.3);
            border-right: 1px dashed rgba(0,0,0,0.3);
            box-shadow: 1px 1px 2px rgba(0,0,0,0.4);
            z-index: 3;
            pointer-events: none;
        }

        .tape-left {
            top: -5px;
            left: -8px;
            transform: rotate(-16deg);
            background: rgba(255, 42, 133, 0.55);
        }

        .tape-right {
            bottom: -5px;
            right: -8px;
            transform: rotate(14deg);
            background: rgba(226, 248, 53, 0.5);
        }

        .badge-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 5px 12px;
            font-family: var(--font-mono);
            font-size: 0.73rem;
            font-weight: 700;
            text-align: center;
            box-sizing: border-box;
            user-select: none;
            white-space: nowrap;
        }

        .tagline-badge {
            background: var(--tape-black);
            color: var(--acid-yellow);
            border: 1.5px solid var(--border-color);
            box-shadow: 3px 3px 0px #000, 3px 3px 0px 1px var(--acid-yellow);
            clip-path: polygon(1% 0%, 100% 2%, 99% 100%, 0% 98%);
        }

        .cultural-badge {
            background: #140d1a;
            color: #5BCEFA;
            border: 1.5px solid #F5A9B8;
            box-shadow: 3px 3px 0px #000, 3px 3px 0px 1px var(--electric-pink);
            clip-path: polygon(0% 2%, 99% 0%, 100% 98%, 1% 100%);
        }

        .lang-switch {
            display: flex;
            flex-direction: column;
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
            padding: 6px 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            text-align: center;
        }

        .lang-btn:hover {
            color: var(--acid-yellow);
        }

        .lang-btn.active {
            background: var(--acid-yellow);
            color: #000;
            box-shadow: 2px 2px 0px #000;
        }

        @media (max-width: 680px) {
            .brand-row {
                flex-direction: column;
                align-items: center;
                gap: 12px;
            }
            .zine-header-collage {
                margin: 8px 0;
                width: 100%;
                max-width: 100%;
            }
            .scrap-tagline, .scrap-cultural {
                align-self: center;
                margin-left: 0;
                margin-right: 0;
            }
            .badge-box {
                white-space: normal;
            }
        }

        /* Token Section */
        .token-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 14px;
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
            padding: 44px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.4);
            overflow: hidden;
        }

        .dropzone-stamp {
            position: absolute;
            font-family: var(--font-display);
            font-size: 2.2rem;
            letter-spacing: 4px;
            color: rgba(255, 42, 133, 0.05);
            transform: translate(-50%, -50%) rotate(-10deg);
            top: 50%;
            left: 50%;
            pointer-events: none;
            white-space: nowrap;
            user-select: none;
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

        .dymo-tape {
            display: inline-block;
            background: #0a080d;
            color: #fff;
            font-family: var(--font-mono);
            font-weight: 700;
            font-size: 0.72rem;
            letter-spacing: 1.5px;
            padding: 2px 8px;
            border: 1px solid #383047;
            box-shadow: 2px 2px 0px #000;
            transform: rotate(-1deg);
            margin-left: 6px;
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
        .badge-doc { background: #c084fc; color: #000; }
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
            <div class="brand-row">
                <div class="brand-box">
                    <h1 class="brand">{$t('app_name')}</h1>
                    <p class="brand-desc">
                        <span class="brand-desc-line">{$t('brand_sub_line1')}</span>
                        <span class="brand-desc-line">{$t('brand_sub_line2')}</span>
                    </p>
                </div>

                <!-- Zine Style Collage Centerpiece -->
                <div class="zine-header-collage">
                    <!-- Halftone & Trash Polka SVG Accent Backdrop -->
                    <div class="zine-decor-layer" aria-hidden="true">
                        <svg class="zine-halftone-svg" viewBox="0 0 280 60" preserveAspectRatio="none">
                            <defs>
                                <pattern id="halftone-dots" x="0" y="0" width="8" height="8" patternUnits="userSpaceOnUse">
                                    <circle cx="2" cy="2" r="1.5" fill="rgba(255, 42, 133, 0.25)" />
                                    <circle cx="6" cy="6" r="1.2" fill="rgba(226, 248, 53, 0.2)" />
                                </pattern>
                            </defs>
                            <rect x="10" y="5" width="260" height="50" fill="url(#halftone-dots)" />
                            <!-- Trash Polka Slash Marks & Crosshair -->
                            <line x1="18" y1="8" x2="262" y2="52" stroke="rgba(255, 42, 133, 0.35)" stroke-width="1.5" stroke-dasharray="6,4" />
                            <circle cx="35" cy="22" r="8" stroke="rgba(226, 248, 53, 0.3)" stroke-width="1" fill="none" />
                            <line x1="35" y1="10" x2="35" y2="34" stroke="rgba(226, 248, 53, 0.3)" stroke-width="1" />
                            <line x1="23" y1="22" x2="47" y2="22" stroke="rgba(226, 248, 53, 0.3)" stroke-width="1" />
                        </svg>
                        <span class="zine-stamp-watermark">CONFIDENCIAL // BAFÃO // 100% EFÊMERO</span>
                    </div>

                    <!-- Cutout Scrap 1: Tagline / Malícia Popular -->
                    <div class="zine-scrap scrap-tagline">
                        <span class="tape-strip tape-left"></span>
                        <span class="badge-box tagline-badge">⚡ {$t('tagline')}</span>
                    </div>

                    <!-- Cutout Scrap 2: Cultural Manifesto / Tecnologia Travesti Latina -->
                    <div class="zine-scrap scrap-cultural">
                        <span class="tape-strip tape-right"></span>
                        <span class="badge-box cultural-badge">{$t('cultural_badge')}</span>
                    </div>
                </div>

                <div class="lang-switch">
                    <button class="lang-btn {$activePt}" onclick="setLanguage('pt')">PT</button>
                    <button class="lang-btn {$activeEn}" onclick="setLanguage('en')">EN</button>
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
        </nav>

        <!-- Content Area -->
        <main class="content-body">
            <!-- 1. File Upload Tab -->
            <section id="uploadTab" class="tab-pane active">
                <div id="dropzone" class="dropzone">
                    <span class="dropzone-stamp" aria-hidden="true">CONFIDENCIAL // 100% EFÊMERO</span>
                    <input type="file" id="fileInput" multiple style="display:none;">
                    <span class="drop-icon">📦</span>
                    <p class="drop-text" id="dropTitle">{$t('drop_idle')}</p>
                    <p class="drop-sub">{$t('drop_sub')} <span class="dymo-tape">MAX: {$maxSizeMb} MB</span></p>
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
                    <label class="option-item">
                        <input type="checkbox" id="optDoc">
                        <span class="option-label">📖 {$t('opt_doc')}{$tip('tip_doc')}</span>
                    </label>
                    <label class="option-item">
                        <input type="checkbox" id="optGist">
                        <span class="option-label">📦 {$t('opt_gist')}{$tip('tip_gist')}</span>
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

        </main>
        <div class="trans-stripe bottom" role="img" aria-label="Faixa do Orgulho Trans">
            <div class="trans-stripe-line blue"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line white"></div>
            <div class="trans-stripe-line pink"></div>
            <div class="trans-stripe-line blue"></div>
        </div>
    </div>

    <footer class="footer">
        <p style="margin-bottom: 8px;">{$t('footer_credit_prefix')} <a href="{$footerTravestiLink}" target="_blank" rel="noopener noreferrer" style="color:var(--electric-pink); font-weight:700;">{$t('footer_travesti_text')}</a> :)</p>
        <p><strong>FUXICO</strong> // <a href="https://codeberg.org/lumenpink/fuxico" target="_blank" rel="noopener noreferrer">Codeberg</a> // 100% 0x0.st &amp; rustypaste drop-in protocol</p>
    </footer>

    <script>
        const I18N = {$strings};
        const IS_PUBLIC_MODE = {$publicMode};

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

        tokenInput.addEventListener('input', () => {
            const val = tokenInput.value.trim();
            localStorage.setItem('fuxico_token', val);
        });

        // Tab Switching
        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            const target = document.getElementById(tabId);
            if (target) target.classList.add('active');
        }

        // Deep linking to tabs via query param or hash
        const urlTabParam = (new URLSearchParams(window.location.search)).get('tab') || window.location.hash.replace('#', '');
        if (urlTabParam === 'url' || urlTabParam === 'urlTab') {
            const urlBtn = document.querySelectorAll('.tab-btn')[1];
            if (urlBtn) switchTab('urlTab', urlBtn);
        }

        // File Drag & Drop
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const dropTitle = document.getElementById('dropTitle');
        let selectedFiles = [];

        dropzone.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                selectFiles(Array.from(e.target.files));
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
                selectFiles(Array.from(e.dataTransfer.files));
            }
        });

        function selectFiles(files) {
            selectedFiles = files;
            if (files.length === 1) {
                const file = files[0];
                document.getElementById('selectedFileName').innerText = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                if (file.name.endsWith('.md') || file.name.endsWith('.markdown')) {
                    const optDoc = document.getElementById('optDoc');
                    if (optDoc) optDoc.checked = true;
                }
            } else if (files.length > 1) {
                const totalBytes = files.reduce((acc, f) => acc + f.size, 0);
                document.getElementById('selectedFileName').innerText = '📦 ' + files.length + ' arquivos selecionados (' + (totalBytes / 1024).toFixed(1) + ' KB)';
                const optGist = document.getElementById('optGist');
                if (optGist) optGist.checked = true;
            }
        }

        // File Upload
        async function handleFileUpload() {
            if (selectedFiles.length === 0) {
                alert(t('drop_idle'));
                return;
            }

            const btn = document.getElementById('btnUpload');
            btn.disabled = true;
            btn.innerText = t('btn_uploading');

            const isGist = selectedFiles.length > 1 || (document.getElementById('optGist') && document.getElementById('optGist').checked);
            const fd = new FormData();

            if (isGist) {
                for (let i = 0; i < selectedFiles.length; i++) {
                    fd.append('files[]', selectedFiles[i]);
                }
                fd.append('type', 'gist');
            } else {
                fd.append('file', selectedFiles[0]);
            }

            const expires = document.getElementById('optExpires').value;
            if (expires) fd.append('expires', expires);

            if (document.getElementById('optOneshot').checked) fd.append('oneshot', 'true');
            if (document.getElementById('optSecret').checked) fd.append('secret', 'true');
            if (document.getElementById('optDoc').checked) fd.append('doc', 'true');

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

        function copyText(str) {
            navigator.clipboard.writeText(str).then(() => alert(t('copy_success')));
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

    public static function renderDashboardUI(Config $config, ?I18n $i18n = null): string
    {
        $i18n ??= new I18n;
        $publicMode = $config->publicMode ? 'true' : 'false';
        $lang = $i18n->getLang();
        $strings = json_encode($i18n->getAll(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $t = fn (string $key): string => htmlspecialchars($i18n->t($key), ENT_QUOTES, 'UTF-8');

        $activePt = $lang === 'pt' ? 'active' : '';
        $activeEn = $lang === 'en' ? 'active' : '';
        $footerTravestiLink = htmlspecialchars($i18n->t('footer_travesti_link'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$t('app_name')} - {$t('painel_title')}</title>
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

        .duct-tape {
            position: absolute;
            top: -2px;
            left: 50%;
            transform: translateX(-50%) rotate(-2.5deg);
            background: rgba(226, 248, 53, 0.96);
            color: #000;
            font-family: var(--font-display);
            font-size: 0.75rem;
            letter-spacing: 2px;
            padding: 4px 34px;
            box-shadow: 3px 3px 0px rgba(0,0,0,0.6);
            z-index: 10;
            user-select: none;
            text-transform: uppercase;
            clip-path: polygon(
                0% 4px, 4px 0%, 8px 3px, 12px 0%, 100% 0%, 
                97% 6px, 100% 12px, 98% 18px, 100% 100%, 
                96% 96%, 92% 100%, 88% 97%, 0% 100%, 
                3% 82%, 0% 64%, 2% 48%, 0% 32%, 3% 16%
            );
        }

        .container {
            position: relative;
            width: 100%;
            max-width: 960px;
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            box-shadow: 8px 12px 0px #0a080d, 8px 12px 0px 2px var(--electric-pink);
            padding: 34px 24px 28px 24px;
            margin-top: 14px;
            position: relative;
        }

        .trans-stripe {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 14px;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
        }

        .trans-stripe.bottom {
            margin-top: 24px;
            margin-bottom: 0;
            border-top: 2px solid #000;
            border-bottom: none;
        }

        .trans-stripe-line { flex: 1; width: 100%; }
        .trans-stripe-line.blue { background-color: #5BCEFA; }
        .trans-stripe-line.pink { background-color: #F5A9B8; }
        .trans-stripe-line.white { background-color: #FFFFFF; }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px dashed var(--border-color);
            padding-bottom: 18px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .brand-box {
            display: flex;
            flex-direction: column;
        }

        .brand {
            font-family: var(--font-display);
            font-size: 2.8rem;
            line-height: 0.9;
            color: var(--acid-yellow);
            text-shadow: 4px 4px 0px #000, 4px 4px 0px 1px var(--electric-pink);
            letter-spacing: 2px;
        }

        .tagline {
            font-size: 0.8rem;
            color: var(--electric-pink);
            font-weight: 700;
            letter-spacing: 1px;
            margin-top: 4px;
            text-transform: uppercase;
        }

        .lang-switch {
            display: flex;
            gap: 4px;
            background: #0a080d;
            padding: 2px;
            border: 1px solid var(--border-color);
        }

        .lang-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            cursor: pointer;
        }

        .lang-btn.active {
            background: var(--acid-yellow);
            color: #000;
            box-shadow: 1px 1px 0px #000;
        }

        .btn-action-icon {
            background: var(--card-surface);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.8rem;
            padding: 6px 12px;
            cursor: pointer;
            box-shadow: 2px 2px 0px #000;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            font-weight: 700;
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

        .btn-action {
            width: 100%;
            background: var(--acid-yellow);
            color: #000;
            border: 2px solid #000;
            box-shadow: 4px 4px 0px #000, 4px 4px 0px 2px var(--electric-pink);
            padding: 14px 20px;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1.05rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.1s ease;
            margin-top: 12px;
        }

        .btn-action:hover {
            background: var(--acid-yellow-hover);
            transform: translate(-1px, -1px);
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
            font-size: 2rem;
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
            gap: 12px;
            margin-bottom: 20px;
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
        .badge-doc { background: #c084fc; color: #000; }
        .badge-gist { background: #34d399; color: #000; }
        .badge-git { background: #f05032; color: #fff; }
        .badge-url { background: #38bdf8; color: #000; }
        .badge-oneshot { background: var(--electric-pink); color: #fff; }

        .dash-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 8px;
        }

        .dash-tab-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-family: var(--font-heading);
            font-size: 0.85rem;
            font-weight: 700;
            padding: 8px 16px;
            cursor: pointer;
            text-transform: uppercase;
            box-shadow: 2px 2px 0px #000;
        }

        .dash-tab-btn.active {
            background: var(--acid-yellow);
            color: #000;
            border-color: #000;
            box-shadow: 2px 2px 0px var(--electric-pink);
        }

        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--tape-black);
            border: 1px solid var(--acid-yellow);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 0.8rem;
            font-weight: 700;
            padding: 6px 14px;
            box-shadow: 2px 2px 0px #000;
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

        .modal.open { display: flex; }

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
        <div class="duct-tape">COFRE // PAINEL RESTRITO</div>

        <header class="top-bar">
            <div class="brand-box">
                <h1 class="brand">FUXICO</h1>
                <div class="tagline">{$t('painel_title')}</div>
            </div>
            <div class="lang-switch">
                <button class="lang-btn {$activePt}" onclick="setLanguage('pt')">PT</button>
                <button class="lang-btn {$activeEn}" onclick="setLanguage('en')">EN</button>
            </div>
        </header>

        <!-- 1. Login View -->
        <section id="loginView" style="display:none; max-width:480px; margin: 20px auto 40px auto; text-align:center;">
            <div style="background:var(--tape-black); border:2px solid var(--electric-pink); padding:28px 24px; box-shadow:6px 6px 0px #000;">
                <h2 style="font-family:var(--font-heading); font-size:1.4rem; color:var(--acid-yellow); margin-bottom:10px;">{$t('painel_login_title')}</h2>
                <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.5; margin-bottom:20px;">{$t('painel_login_desc')}</p>
                <div style="margin-bottom:14px;">
                    <input type="password" id="painelTokenInput" class="form-control" placeholder="{$t('token_placeholder')}" style="text-align:center;">
                </div>
                <button type="button" class="btn-action" onclick="unlockVault()">{$t('painel_login_btn')}</button>
                <div id="loginError" style="display:none; color:var(--electric-pink); margin-top:14px; font-size:0.85rem; font-weight:700;"></div>
            </div>
            <div style="margin-top:20px;">
                <a href="/" class="btn-action-icon">{$t('painel_back_home')}</a>
            </div>
        </section>

        <!-- 2. Dashboard View -->
        <section id="dashView" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <a href="/" class="btn-action-icon">{$t('painel_back_home')}</a>
                    <div id="userBadge" class="user-badge" style="display:none;"></div>
                </div>
                <button type="button" class="btn-action-icon btn-delete" onclick="logoutVault()">{$t('painel_logout_btn')}</button>
            </div>

            <!-- Dashboard Sub Tabs -->
            <div id="dashNavTabs" class="dash-tabs">
                <button id="tabBtnItems" class="dash-tab-btn active" onclick="switchDashTab('items')">{$t('tab_fofocas')}</button>
                <button id="tabBtnUsers" class="dash-tab-btn" style="display:none;" onclick="switchDashTab('users')">{$t('tab_fuxiqueiras')}</button>
            </div>

            <!-- Tab 1: Fofocas & Arquivos -->
            <div id="dashItemsSection">
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
                        <option value="doc">{$t('dash_filter_docs')}</option>
                        <option value="gist">{$t('dash_filter_gists')}</option>
                        <option value="git">{$t('dash_filter_gits')}</option>
                        <option value="url">{$t('dash_filter_urls')}</option>
                    </select>
                </div>

                <div style="overflow-x:auto;">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>{$t('col_slug')}</th>
                                <th id="thAuthor" style="display:none;">{$t('col_author')}</th>
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
            </div>

            <!-- Tab 2: Fuxiqueiras & Fichas (Admin Only) -->
            <div id="dashUsersSection" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                    <h3 style="font-family:var(--font-heading); font-size:1.1rem; color:var(--acid-yellow); text-transform:uppercase; letter-spacing:1px;">{$t('users_title')}</h3>
                    <button type="button" class="btn-action-icon" style="border-color:var(--acid-yellow); color:var(--acid-yellow);" onclick="openNewUserModal()">{$t('btn_new_user')}</button>
                </div>

                <div style="overflow-x:auto;">
                    <table class="dash-table">
                        <thead>
                            <tr>
                                <th>{$t('col_username')}</th>
                                <th>{$t('col_role')}</th>
                                <th>{$t('col_token')}</th>
                                <th>{$t('col_created')}</th>
                                <th>{$t('col_actions')}</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr>
                                <td colspan="5" style="text-align:center; color:var(--text-muted);">{$t('users_empty')}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

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

    <!-- New User Modal -->
    <div id="newUserModal" class="modal" onclick="closeNewUserModal()">
        <div class="modal-card" style="width:100%; max-width:440px;" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeNewUserModal()">×</button>
            <h3 style="font-family:var(--font-heading); font-size:1.2rem; color:var(--acid-yellow); margin-bottom:16px; text-transform:uppercase;">{$t('modal_new_user_title')}</h3>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('username_label')}</label>
                <input type="text" id="newUsernameInput" class="form-control" placeholder="{$t('username_placeholder')}">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('token_label')}</label>
                <input type="text" id="newUserTokenInput" class="form-control" placeholder="{$t('token_placeholder')}">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:6px; font-weight:700;">{$t('role_label')}</label>
                <select id="newUserRoleSelect" class="form-control">
                    <option value="member">{$t('role_member')}</option>
                    <option value="admin">{$t('role_admin')}</option>
                </select>
            </div>
            <button type="button" class="btn-action" style="margin-top:0;" onclick="submitNewUser()">{$t('btn_create_user')}</button>
            <div id="newUserError" style="display:none; color:var(--electric-pink); margin-top:12px; font-size:0.85rem; font-weight:700;"></div>
            <div id="newUserSuccess" style="display:none; margin-top:14px; background:var(--tape-black); border:1px solid var(--acid-yellow); padding:12px;">
                <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:6px;">{$t('user_created_success')}</p>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="createdTokenDisplay" class="form-control" readonly style="font-size:0.8rem;">
                    <button type="button" class="btn-action-icon" onclick="copyCreatedToken()">{$t('act_copy')}</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p style="margin-bottom: 8px;">{$t('footer_credit_prefix')} <a href="{$footerTravestiLink}" target="_blank" rel="noopener noreferrer" style="color:var(--electric-pink); font-weight:700;">{$t('footer_travesti_text')}</a> :)</p>
        <p><strong>FUXICO</strong> // <a href="https://codeberg.org/lumenpink/fuxico" target="_blank" rel="noopener noreferrer">Codeberg</a> // 100% 0x0.st &amp; rustypaste drop-in protocol</p>
    </footer>

    <script>
        const I18N = {$strings};
        const IS_PUBLIC_MODE = {$publicMode};
        const loginView = document.getElementById('loginView');
        const dashView = document.getElementById('dashView');
        const tokenInput = document.getElementById('painelTokenInput');
        const loginError = document.getElementById('loginError');

        let currentUser = null;

        function t(k) { return I18N[k] || k; }

        function setLanguage(lang) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        async function init() {
            const savedToken = localStorage.getItem('fuxico_token') || '';
            if (savedToken) {
                tokenInput.value = savedToken;
            }

            if (IS_PUBLIC_MODE && !savedToken) {
                currentUser = { authenticated: false, role: 'guest', username: 'Visitante' };
                showDashboard();
                return;
            }

            if (savedToken) {
                try {
                    const res = await fetch('/api/me', { headers: { 'Authorization': 'Bearer ' + savedToken } });
                    if (res.ok) {
                        currentUser = await res.json();
                        showDashboard();
                        return;
                    } else {
                        showLogin();
                        loginError.innerHTML = 'A ficha salva anteriormente foi rejeitada pelo servidor. <br><a href="#" onclick="logoutVault(); return false;" style="color:var(--accent-yellow); text-decoration:underline; font-size:0.85rem; margin-top:4px; display:inline-block;">Limpar ficha salva e digitar nova</a>';
                        loginError.style.display = 'block';
                        return;
                    }
                } catch (e) {}
            }

            showLogin();
        }

        function showLogin() {
            loginView.style.display = 'block';
            dashView.style.display = 'none';
            loginError.style.display = 'none';
        }

        function showDashboard() {
            loginView.style.display = 'none';
            dashView.style.display = 'block';
            updateUserUI();
            switchDashTab('items');
        }

        function updateUserUI() {
            const userBadge = document.getElementById('userBadge');
            const tabBtnUsers = document.getElementById('tabBtnUsers');
            const thAuthor = document.getElementById('thAuthor');

            if (currentUser && currentUser.authenticated) {
                userBadge.style.display = 'inline-flex';
                const roleName = currentUser.role === 'admin' ? t('role_admin') : t('role_member');
                userBadge.innerHTML = '👤 <strong style="color:var(--acid-yellow);">' + escapeHtml(currentUser.username) + '</strong> <span class="badge" style="margin-left:4px; background:' + (currentUser.role === 'admin' ? 'var(--electric-pink); color:#fff;' : 'var(--acid-yellow); color:#000;') + '">' + escapeHtml(roleName) + '</span>';

                if (currentUser.role === 'admin') {
                    tabBtnUsers.style.display = 'inline-block';
                    thAuthor.style.display = '';
                } else {
                    tabBtnUsers.style.display = 'none';
                    thAuthor.style.display = 'none';
                }
            } else {
                userBadge.style.display = 'none';
                tabBtnUsers.style.display = 'none';
                thAuthor.style.display = 'none';
            }
        }

        function switchDashTab(tab) {
            const tabBtnItems = document.getElementById('tabBtnItems');
            const tabBtnUsers = document.getElementById('tabBtnUsers');
            const dashItemsSection = document.getElementById('dashItemsSection');
            const dashUsersSection = document.getElementById('dashUsersSection');

            if (tab === 'items') {
                tabBtnItems.classList.add('active');
                tabBtnUsers.classList.remove('active');
                dashItemsSection.style.display = 'block';
                dashUsersSection.style.display = 'none';
                loadDashboard();
            } else if (tab === 'users') {
                tabBtnUsers.classList.add('active');
                tabBtnItems.classList.remove('active');
                dashUsersSection.style.display = 'block';
                dashItemsSection.style.display = 'none';
                loadUsers();
            }
        }

        async function unlockVault() {
            const token = tokenInput.value.trim();
            loginError.style.display = 'none';

            if (!IS_PUBLIC_MODE && !token) {
                loginError.innerText = 'Token mestre obrigatório.';
                loginError.style.display = 'block';
                return;
            }

            try {
                const headers = token ? { 'Authorization': 'Bearer ' + token } : {};
                const res = await fetch('/api/me', { headers });
                if (res.ok) {
                    currentUser = await res.json();
                    if (token) localStorage.setItem('fuxico_token', token);
                    showDashboard();
                } else {
                    let errMsg = 'Acesso negado: token incorreto ou cofre trancado.';
                    try {
                        const errJson = await res.json();
                        if (errJson.message) errMsg = 'Acesso negado: ' + errJson.message;
                    } catch(e) {}
                    loginError.innerHTML = errMsg + ' <br><a href="#" onclick="logoutVault(); return false;" style="color:var(--accent-yellow); text-decoration:underline; font-size:0.85rem; margin-top:4px; display:inline-block;">Limpar ficha salva e tentar novamente</a>';
                    loginError.style.display = 'block';
                }
            } catch (err) {
                loginError.innerText = 'Erro ao verificar token: ' + err.message;
                loginError.style.display = 'block';
            }
        }

        function logoutVault() {
            localStorage.removeItem('fuxico_token');
            tokenInput.value = '';
            currentUser = null;
            showLogin();
        }

        // Enter key in token input
        tokenInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') unlockVault();
        });

        async function loadDashboard() {
            const tbody = document.getElementById('dashTableBody');
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            const isAdmin = currentUser && currentUser.role === 'admin';
            const colCount = isAdmin ? 7 : 6;

            if (!IS_PUBLIC_MODE && !token) {
                tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center; color:var(--text-muted);">' + escapeHtml(t('dash_auth_required')) + '</td></tr>';
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
                    tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center; color:var(--electric-pink);">Acesso não autorizado ao cofre.</td></tr>';
                    return;
                }

                const data = await res.json();
                if (!data.items || data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center; color:var(--text-muted);">' + escapeHtml(t('dash_empty')) + '</td></tr>';
                    return;
                }

                let html = '';
                data.items.forEach(item => {
                    const sizeStr = item.type === 'url' ? '-' : (item.size / 1024).toFixed(1) + ' KB';
                    let typeBadge = '';
                    if (item.type === 'file') {
                        typeBadge = '<span class="badge badge-file">' + escapeHtml(t('tab_file')) + '</span>';
                    } else if (item.type === 'doc') {
                        typeBadge = '<span class="badge badge-doc">📖 ' + escapeHtml(t('tab_doc')) + '</span>';
                    } else if (item.type === 'gist') {
                        typeBadge = '<span class="badge badge-gist">📦 ' + escapeHtml(t('tab_gist')) + '</span>';
                    } else if (item.type === 'git') {
                        typeBadge = '<span class="badge badge-git">🌱 ' + escapeHtml(t('tab_git')) + '</span>';
                    } else {
                        typeBadge = '<span class="badge badge-url">URL</span>';
                    }
                    const oneshotBadge = item.is_oneshot ? '<span class="badge badge-oneshot">🔥 Oneshot</span>' : '';
                    const expStr = item.expires_at ? new Date(item.expires_at * 1000).toLocaleString() : escapeHtml(t('exp_none'));

                    const openBtn = (item.type === 'file')
                        ? '<button class="btn-action-icon" onclick="previewItem(\'' + escapeHtml(item.url) + '\', \'' + escapeHtml(item.mime_type) + '\')">' + escapeHtml(t('act_view')) + '</button> '
                        : '<a class="btn-action-icon" href="' + escapeHtml(item.url) + '" target="_blank">' + escapeHtml(t('act_open')) + '</a> ';

                    const authorCol = isAdmin
                        ? '<td><small style="color:var(--text-muted);">' + escapeHtml(item.author || t('author_anon')) + '</small></td>'
                        : '';

                    html += '<tr>' +
                        '<td><strong style="color:var(--acid-yellow); font-family:var(--font-mono);">' + escapeHtml(item.slug) + '</strong><br><small style="color:var(--text-muted);">' + escapeHtml(item.original_name || '') + '</small></td>' +
                        authorCol +
                        '<td>' + typeBadge + '</td>' +
                        '<td>' + sizeStr + '</td>' +
                        '<td>' + oneshotBadge + ' <small style="color:var(--text-muted);">' + expStr + '</small></td>' +
                        '<td>' + item.views + '</td>' +
                        '<td>' +
                            '<button class="btn-action-icon" onclick="copyText(\'' + escapeHtml(item.url) + '\')">' + escapeHtml(t('act_copy')) + '</button> ' +
                            openBtn +
                            '<button class="btn-action-icon btn-delete" onclick="deleteItem(\'' + escapeHtml(item.slug) + '\')">' + escapeHtml(t('act_delete')) + '</button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center; color:var(--electric-pink);">Erro ao carregar dados: ' + escapeHtml(err.message) + '</td></tr>';
            }
        }

        async function loadUsers() {
            const tbody = document.getElementById('usersTableBody');
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            if (!token) return;

            try {
                const res = await fetch('/api/users', { headers: { 'Authorization': 'Bearer ' + token } });
                if (!res.ok) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--electric-pink);">Erro ao carregar fuxiqueiras.</td></tr>';
                    return;
                }
                const data = await res.json();
                if (!data.users || data.users.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-muted);">' + escapeHtml(t('users_empty')) + '</td></tr>';
                    return;
                }
                let html = '';
                data.users.forEach(u => {
                    const roleLabel = u.role === 'admin' ? t('role_admin') : t('role_member');
                    const roleBadge = '<span class="badge" style="background:' + (u.role === 'admin' ? 'var(--electric-pink); color:#fff;' : 'var(--acid-yellow); color:#000;') + '">' + escapeHtml(roleLabel) + '</span>';
                    const createdStr = u.created_at ? new Date(u.created_at * 1000).toLocaleString() : '-';
                    const maskedToken = u.token ? (u.token.substring(0, 8) + '••••••••') : '-';

                    html += '<tr>' +
                        '<td><strong style="color:var(--acid-yellow); font-family:var(--font-mono);">' + escapeHtml(u.username) + '</strong></td>' +
                        '<td>' + roleBadge + '</td>' +
                        '<td><code>' + escapeHtml(maskedToken) + '</code> <button class="btn-action-icon" style="padding:2px 6px; font-size:0.7rem;" onclick="copyText(\'' + escapeHtml(u.token) + '\')">' + escapeHtml(t('act_copy')) + '</button></td>' +
                        '<td><small style="color:var(--text-muted);">' + escapeHtml(createdStr) + '</small></td>' +
                        '<td>' +
                            '<button class="btn-action-icon" onclick="regenUserToken(\'' + escapeHtml(u.id) + '\')">' + escapeHtml(t('act_regen_token')) + '</button> ' +
                            '<button class="btn-action-icon btn-delete" onclick="deleteUser(\'' + escapeHtml(u.id) + '\')">' + escapeHtml(t('act_delete')) + '</button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--electric-pink);">Erro: ' + escapeHtml(err.message) + '</td></tr>';
            }
        }

        async function regenUserToken(userId) {
            const promptMsg = t('regen_prompt') || 'Digite a nova ficha/senha (ou deixe em branco para sortear aleatória):';
            const input = prompt(promptMsg, '');
            if (input === null) return;
            const customToken = input.trim();

            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            try {
                const res = await fetch('/api/users/' + userId + '/token', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ token: customToken })
                });
                const data = await res.json();
                if (res.ok) {
                    alert(data.message + '\\n\\nFicha: ' + data.token);
                    loadUsers();
                } else {
                    alert('Erro: ' + (data.message || 'Falha ao atualizar ficha.'));
                }
            } catch (err) {
                alert('Erro: ' + err.message);
            }
        }

        async function deleteUser(userId) {
            if (!confirm(t('delete_user_confirm'))) return;
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            try {
                const res = await fetch('/api/users/' + userId, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (res.ok) {
                    loadUsers();
                } else {
                    alert('Erro: ' + (data.message || 'Falha ao remover fuxiqueira.'));
                }
            } catch (err) {
                alert('Erro: ' + err.message);
            }
        }

        function openNewUserModal() {
            document.getElementById('newUsernameInput').value = '';
            document.getElementById('newUserTokenInput').value = '';
            document.getElementById('newUserRoleSelect').value = 'member';
            document.getElementById('newUserError').style.display = 'none';
            document.getElementById('newUserSuccess').style.display = 'none';
            document.getElementById('createdTokenDisplay').value = '';
            document.getElementById('newUserModal').classList.add('open');
        }

        function closeNewUserModal() {
            document.getElementById('newUserModal').classList.remove('open');
            loadUsers();
        }

        async function submitNewUser() {
            const username = document.getElementById('newUsernameInput').value.trim();
            const role = document.getElementById('newUserRoleSelect').value;
            const customToken = document.getElementById('newUserTokenInput').value.trim();
            const errEl = document.getElementById('newUserError');
            const succEl = document.getElementById('newUserSuccess');
            const tokenDisplay = document.getElementById('createdTokenDisplay');

            errEl.style.display = 'none';
            succEl.style.display = 'none';

            if (!username) {
                errEl.innerText = 'Apelido da fuxiqueira é obrigatório.';
                errEl.style.display = 'block';
                return;
            }

            const payload = { username, role };
            if (customToken) {
                payload.token = customToken;
            }

            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
            try {
                const res = await fetch('/api/users', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok) {
                    tokenDisplay.value = data.user.token;
                    succEl.style.display = 'block';
                } else {
                    errEl.innerText = data.message || 'Falha ao cadastrar fuxiqueira.';
                    errEl.style.display = 'block';
                }
            } catch (err) {
                errEl.innerText = 'Erro: ' + err.message;
                errEl.style.display = 'block';
            }
        }

        function copyCreatedToken() {
            const token = document.getElementById('createdTokenDisplay').value;
            if (token) copyText(token);
        }

        async function deleteItem(slug) {
            if (!confirm(t('delete_confirm'))) return;
            const token = localStorage.getItem('fuxico_token') || tokenInput.value.trim();
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

        init();
    </script>
</body>
</html>
HTML;
    }
}
