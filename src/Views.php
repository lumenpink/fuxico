<?php

declare(strict_types=1);

namespace PicoShare;

class Views
{
    public static function renderWebUI(Config $config): string
    {
        $publicMode = $config->publicMode ? 'true' : 'false';
        $maxSizeMb = (int) ($config->maxUploadSize / 1048576);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>picoshare - Minimalist File Vault & Shortener</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0d0e12;
            --card-bg: #16181f;
            --card-hover: #1e212b;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --accent: #db2777; /* Lumen Pink 600 */
            --accent-hover: #be185d;
            --accent-glow: rgba(219, 39, 119, 0.25);
            --border-color: #272a37;
            --input-bg: #212430;
            --success-bg: #064e3b;
            --success-border: #059669;
            --danger: #ef4444;
            --badge-bg: #2d1927;
            --badge-border: #831843;
            --font-main: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-main);
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle at 15% 20%, rgba(219, 39, 119, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(139, 92, 246, 0.05) 0%, transparent 45%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px;
        }

        .container {
            width: 100%;
            max-width: 820px;
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.6), 0 0 30px var(--accent-glow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        /* Top Header */
        .top-bar {
            background: rgba(18, 20, 27, 0.95);
            backdrop-filter: blur(10px);
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-main);
        }

        .brand span.accent {
            color: var(--accent);
            text-shadow: 0 0 12px var(--accent-glow);
        }

        .brand-tag {
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--badge-bg);
            color: var(--accent);
            border: 1px solid var(--badge-border);
            padding: 2px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .controls-row {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .auth-wrapper {
            flex: 1;
            transition: all 0.3s ease;
        }

        .auth-input {
            width: 100%;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: white;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            height: 42px;
            transition: all 0.2s;
        }

        .auth-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px var(--accent-glow);
        }

        /* Result banner */
        .result-wrapper {
            flex: 2;
            display: none;
            background: var(--success-bg);
            border-radius: 8px;
            align-items: center;
            padding: 0 8px 0 12px;
            border: 1px solid var(--success-border);
            height: 42px;
            animation: slideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .result-wrapper.visible { display: flex; }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .result-link {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #fff;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            margin-right: 8px;
        }

        .btn-copy {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: white;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            white-space: nowrap;
        }

        .btn-copy:hover { background: rgba(0, 0, 0, 0.6); }

        /* Tabs */
        .tabs {
            display: flex;
            background: rgba(18, 20, 27, 0.6);
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            flex: 1;
            padding: 14px;
            text-align: center;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 2px solid transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .tab:hover { color: var(--text-main); background: rgba(255, 255, 255, 0.02); }
        .tab.active {
            background: var(--card-bg);
            border-bottom: 2px solid var(--accent);
            color: var(--accent);
            font-weight: 600;
        }

        /* Content Area */
        .content-area {
            padding: 24px;
            display: none;
            flex-direction: column;
            gap: 20px;
        }

        .content-area.active { display: flex; }

        /* Drop Zone */
        .drop-zone {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 36px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            background: rgba(33, 36, 48, 0.4);
        }

        .drop-zone:hover, .drop-zone.dragover {
            border-color: var(--accent);
            background: rgba(219, 39, 119, 0.05);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .drop-zone svg {
            width: 44px;
            height: 44px;
            stroke: var(--accent);
            margin-bottom: 12px;
        }

        .drop-zone p.primary {
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-main);
            margin-bottom: 4px;
        }

        .drop-zone p.secondary {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .file-selected {
            margin-top: 12px;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            color: var(--accent);
            font-weight: 600;
        }

        /* Form Controls */
        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .input-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: white;
            font-family: var(--font-main);
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px var(--accent-glow);
        }

        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .toggle-group {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: var(--text-main);
            cursor: pointer;
            user-select: none;
        }

        .checkbox-wrapper {
            position: relative;
            width: 38px;
            height: 20px;
        }

        .checkbox-wrapper input { opacity: 0; width: 0; height: 0; }

        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #333948; border-radius: 20px; transition: .3s;
        }

        .slider:before {
            position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px;
            background-color: white; border-radius: 50%; transition: .3s;
        }

        input:checked + .slider { background-color: var(--accent); }
        input:checked + .slider:before { transform: translateX(18px); }

        .btn-submit {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-hover) 100%);
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 15px var(--accent-glow);
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px var(--accent-glow);
        }

        .btn-submit:disabled {
            background: #2b2e3c;
            color: #64748b;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Dashboard Styles */
        .dash-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 8px;
        }

        .stat-card {
            background: rgba(33, 36, 48, 0.5);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 16px;
        }

        .stat-val { font-size: 1.25rem; font-weight: 700; color: var(--accent); }
        .stat-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; }

        .dash-toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .dash-table-wrap {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }

        .dash-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            text-align: left;
        }

        .dash-table th {
            background: #191c25;
            padding: 12px 14px;
            color: var(--text-muted);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
        }

        .dash-table td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
        }

        .dash-table tr:hover td { background: var(--card-hover); }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-file { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
        .badge-url { background: rgba(168, 85, 247, 0.2); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.4); }
        .badge-oneshot { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }

        .btn-action-icon {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            border-radius: 6px;
            padding: 6px 10px;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.75rem;
        }

        .btn-action-icon:hover {
            color: white;
            border-color: var(--accent);
            background: var(--card-hover);
        }

        .btn-delete:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        /* Modal Preview */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.open { display: flex; }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            max-width: 90%;
            max-height: 90%;
            overflow: auto;
            position: relative;
            padding: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.7);
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 14px;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
        }
        .modal-close:hover { color: white; }

        /* Responsive */
        @media (max-width: 600px) {
            .controls-row { flex-direction: column; }
            .auth-wrapper, .result-wrapper { width: 100%; }
            .dash-toolbar { flex-direction: column; }
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="brand-row">
                <div class="brand">
                    <span>pico<span class="accent">share</span></span>
                    <span class="brand-tag">vault</span>
                </div>
            </div>

            <div class="controls-row">
                <div class="auth-wrapper" id="authWrapper">
                    <input type="password" id="authToken" class="auth-input" placeholder="Authorization Token">
                </div>

                <div class="result-wrapper" id="resultWrapper">
                    <div id="resultLink" class="result-link"></div>
                    <button class="btn-copy" onclick="copyResult()" id="copyBtn">Copy</button>
                    <button class="btn-copy" onclick="resetResult()" style="margin-left:4px;">×</button>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="switchTab('file')" id="tabBtn-file">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                Upload File
            </div>
            <div class="tab" onclick="switchTab('url')" id="tabBtn-url">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                Shorten URL
            </div>
            <div class="tab" onclick="switchTab('dashboard')" id="tabBtn-dashboard">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                Dashboard
            </div>
        </div>

        <!-- Tab 1: File Upload -->
        <div id="tab-file" class="content-area active">
            <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                <p class="primary">Drag and drop file here or click to browse</p>
                <p class="secondary">Max size: {$maxSizeMb} MB. Direct access protected.</p>
                <input type="file" id="fileInput" style="display:none;" onchange="onFileSelected(this)">
                <div id="selectedFileInfo" class="file-selected"></div>
            </div>

            <div class="options-grid">
                <div class="input-group">
                    <label class="input-label" for="customNameFile">Custom Slug / Name (Optional)</label>
                    <input type="text" id="customNameFile" class="form-input" placeholder="e.g. quarterly-report">
                </div>
                <div class="input-group">
                    <label class="input-label" for="expiresFile">Retention / Expiration</label>
                    <select id="expiresFile" class="form-select">
                        <option value="">Never expire (permanent)</option>
                        <option value="1h">1 Hour</option>
                        <option value="24h">24 Hours (1 Day)</option>
                        <option value="7d">7 Days</option>
                        <option value="30d">30 Days</option>
                        <option value="365d">1 Year</option>
                    </select>
                </div>
            </div>

            <div class="options-grid">
                <label class="toggle-group">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" id="oneshotFile">
                        <span class="slider"></span>
                    </div>
                    <span>Oneshot (Self-destruct after first download)</span>
                </label>
                <label class="toggle-group">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" id="secretFile">
                        <span class="slider"></span>
                    </div>
                    <span>Secret URL (16-char unguessable slug)</span>
                </label>
            </div>

            <button class="btn-submit" id="btnUploadFile" onclick="submitUpload('file')">Upload to Vault</button>
        </div>

        <!-- Tab 2: URL Shortener -->
        <div id="tab-url" class="content-area">
            <div class="input-group">
                <label class="input-label" for="urlInput">Target Destination URL</label>
                <input type="url" id="urlInput" class="form-input" placeholder="https://example.com/very/long/path/to/resource">
            </div>

            <div class="options-grid">
                <div class="input-group">
                    <label class="input-label" for="customNameUrl">Custom Slug (Optional)</label>
                    <input type="text" id="customNameUrl" class="form-input" placeholder="e.g. my-project">
                </div>
                <div class="input-group">
                    <label class="input-label" for="expiresUrl">Expiration</label>
                    <select id="expiresUrl" class="form-select">
                        <option value="">Never expire</option>
                        <option value="1h">1 Hour</option>
                        <option value="24h">24 Hours</option>
                        <option value="7d">7 Days</option>
                        <option value="30d">30 Days</option>
                    </select>
                </div>
            </div>

            <label class="toggle-group">
                <div class="checkbox-wrapper">
                    <input type="checkbox" id="oneshotUrl">
                    <span class="slider"></span>
                </div>
                <span>Oneshot (Delete after first redirect)</span>
            </label>

            <button class="btn-submit" id="btnShortenUrl" onclick="submitUpload('url')">Create Short Link</button>
        </div>

        <!-- Tab 3: File Manager & Dashboard -->
        <div id="tab-dashboard" class="content-area">
            <div class="dash-stats">
                <div class="stat-card">
                    <div class="stat-val" id="statFiles">0</div>
                    <div class="stat-label">Files</div>
                </div>
                <div class="stat-card">
                    <div class="stat-val" id="statUrls">0</div>
                    <div class="stat-label">URLs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-val" id="statBytes">0 MB</div>
                    <div class="stat-label">Storage Used</div>
                </div>
                <div class="stat-card">
                    <div class="stat-val" id="statViews">0</div>
                    <div class="stat-label">Total Views</div>
                </div>
            </div>

            <div class="dash-toolbar">
                <input type="text" id="dashSearch" class="form-input" placeholder="Search by name, slug, or target..." oninput="debounceLoadDashboard()">
                <select id="dashType" class="form-select" style="max-width: 140px;" onchange="loadDashboard()">
                    <option value="">All Types</option>
                    <option value="file">Files Only</option>
                    <option value="url">URLs Only</option>
                </select>
                <button class="btn-action-icon" onclick="loadDashboard()">↻ Refresh</button>
            </div>

            <div class="dash-table-wrap">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Item / Slug</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th>Views</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="dashTableBody">
                        <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">Enter Authorization Token to view vault items.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Preview -->
    <div id="previewModal" class="modal" onclick="closeModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <div id="modalBody" style="text-align:center;"></div>
        </div>
    </div>

    <script>
        const PUBLIC_MODE = {$publicMode};
        const tokenInput = document.getElementById('authToken');

        // Token persistence in localStorage
        const savedToken = localStorage.getItem('picoshare_token');
        if (savedToken) {
            tokenInput.value = savedToken;
        }
        tokenInput.addEventListener('input', (e) => {
            localStorage.setItem('picoshare_token', e.target.value.trim());
        });

        // Tab Switching
        function switchTab(tab) {
            document.querySelectorAll('.content-area').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tab).classList.add('active');
            document.getElementById('tabBtn-' + tab).classList.add('active');
            if (tab === 'dashboard') {
                loadDashboard();
            }
        }

        // Drag & Drop
        const dropZone = document.getElementById('dropZone');
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => {
            dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); });
        });
        ['dragenter', 'dragover'].forEach(ev => dropZone.classList.add('dragover'));
        ['dragleave', 'drop'].forEach(ev => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', e => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                document.getElementById('fileInput').files = files;
                onFileSelected(document.getElementById('fileInput'));
            }
        });

        function onFileSelected(input) {
            const display = document.getElementById('selectedFileInfo');
            if (input.files.length > 0) {
                const f = input.files[0];
                const sizeKb = (f.size / 1024).toFixed(1);
                display.innerText = '✓ ' + f.name + ' (' + sizeKb + ' KB)';
            } else {
                display.innerText = '';
            }
        }

        // Submissions
        async function submitUpload(type) {
            const token = tokenInput.value.trim();
            if (!PUBLIC_MODE && !token) {
                alert('Please enter your Authorization Token at the top.');
                tokenInput.focus();
                return;
            }

            const formData = new FormData();
            const btn = type === 'file' ? document.getElementById('btnUploadFile') : document.getElementById('btnShortenUrl');
            const originalText = btn.innerText;

            if (type === 'file') {
                const fileInput = document.getElementById('fileInput');
                if (!fileInput.files || fileInput.files.length === 0) {
                    alert('Please select a file first.');
                    return;
                }
                formData.append('file', fileInput.files[0]);
                const custom = document.getElementById('customNameFile').value.trim();
                if (custom) formData.append('customName', custom);
                const expires = document.getElementById('expiresFile').value;
                if (expires) formData.append('expires', expires);
                if (document.getElementById('oneshotFile').checked) formData.append('oneshot', 'true');
                if (document.getElementById('secretFile').checked) formData.append('secret', 'true');
            } else {
                const urlVal = document.getElementById('urlInput').value.trim();
                if (!urlVal) {
                    alert('Please enter a target URL.');
                    return;
                }
                formData.append('shorten', urlVal);
                const custom = document.getElementById('customNameUrl').value.trim();
                if (custom) formData.append('customName', custom);
                const expires = document.getElementById('expiresUrl').value;
                if (expires) formData.append('expires', expires);
                if (document.getElementById('oneshotUrl').checked) formData.append('oneshot', 'true');
            }

            btn.disabled = true;
            btn.innerText = 'Processing...';

            try {
                const headers = {};
                if (token) headers['Authorization'] = 'Bearer ' + token;

                const res = await fetch('/', {
                    method: 'POST',
                    headers: headers,
                    body: formData
                });

                const text = await res.text();
                if (res.ok) {
                    showResult(text.trim());
                    if (type === 'file') {
                        document.getElementById('fileInput').value = '';
                        document.getElementById('selectedFileInfo').innerText = '';
                    } else {
                        document.getElementById('urlInput').value = '';
                    }
                } else {
                    alert('Error: ' + text);
                }
            } catch (err) {
                alert('Connection error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = originalText;
            }
        }

        function showResult(link) {
            document.getElementById('resultLink').innerText = link;
            document.getElementById('resultWrapper').classList.add('visible');
            navigator.clipboard.writeText(link).catch(() => {});
            const copyBtn = document.getElementById('copyBtn');
            copyBtn.innerText = 'Copied!';
            setTimeout(() => { copyBtn.innerText = 'Copy'; }, 2500);
        }

        function copyResult() {
            const text = document.getElementById('resultLink').innerText;
            navigator.clipboard.writeText(text).then(() => {
                const copyBtn = document.getElementById('copyBtn');
                copyBtn.innerText = 'Copied!';
                setTimeout(() => { copyBtn.innerText = 'Copy'; }, 2000);
            });
        }

        function resetResult() {
            document.getElementById('resultWrapper').classList.remove('visible');
        }

        // Dashboard
        let debounceTimer;
        function debounceLoadDashboard() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(loadDashboard, 300);
        }

        async function loadDashboard() {
            const token = tokenInput.value.trim();
            const tbody = document.getElementById('dashTableBody');
            if (!token) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--text-muted);">Please provide an Authorization Token above to inspect vault records.</td></tr>';
                return;
            }

            const search = encodeURIComponent(document.getElementById('dashSearch').value.trim());
            const type = encodeURIComponent(document.getElementById('dashType').value);

            try {
                // Fetch stats
                const statsRes = await fetch('/api/stats', { headers: { 'Authorization': 'Bearer ' + token } });
                if (statsRes.ok) {
                    const stats = await statsRes.json();
                    document.getElementById('statFiles').innerText = stats.total_files;
                    document.getElementById('statUrls').innerText = stats.total_urls;
                    document.getElementById('statBytes').innerText = (stats.total_bytes / (1024*1024)).toFixed(1) + ' MB';
                    document.getElementById('statViews').innerText = stats.total_views;
                }

                // Fetch items
                const res = await fetch('/api/items?search=' + search + '&type=' + type, {
                    headers: { 'Authorization': 'Bearer ' + token }
                });

                if (!res.ok) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--danger);">Unauthorized or failed loading items.</td></tr>';
                    return;
                }

                const data = await res.json();
                if (!data.items || data.items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--text-muted);">No records found in vault.</td></tr>';
                    return;
                }

                let html = '';
                data.items.forEach(item => {
                    const sizeStr = item.type === 'file' ? (item.size / 1024).toFixed(1) + ' KB' : '-';
                    const typeBadge = item.type === 'file' ? '<span class="badge badge-file">File</span>' : '<span class="badge badge-url">URL</span>';
                    const oneshotBadge = item.is_oneshot ? '<span class="badge badge-oneshot">Oneshot</span>' : '';
                    const expStr = item.expires_at ? new Date(item.expires_at * 1000).toLocaleString() : 'Permanent';

                    html += '<tr>' +
                        '<td><strong style="color:var(--text-main); font-family:var(--font-mono);">' + escapeHtml(item.slug) + '</strong><br><small style="color:var(--text-muted);">' + escapeHtml(item.original_name || '') + '</small></td>' +
                        '<td>' + typeBadge + '</td>' +
                        '<td>' + sizeStr + '</td>' +
                        '<td>' + oneshotBadge + ' <small style="color:var(--text-muted);">' + expStr + '</small></td>' +
                        '<td>' + item.views + '</td>' +
                        '<td>' +
                            '<button class="btn-action-icon" onclick="copyText(\'' + escapeHtml(item.url) + '\')">Copy</button> ' +
                            (item.type === 'file' ? '<button class="btn-action-icon" onclick="previewItem(\'' + escapeHtml(item.url) + '\', \'' + escapeHtml(item.mime_type) + '\')">View</button> ' : '<a class="btn-action-icon" href="' + escapeHtml(item.url) + '" target="_blank">Open</a> ') +
                            '<button class="btn-action-icon btn-delete" onclick="deleteItem(\'' + escapeHtml(item.slug) + '\')">Delete</button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--danger);">Error loading data: ' + escapeHtml(err.message) + '</td></tr>';
            }
        }

        async function deleteItem(slug) {
            if (!confirm('Are you sure you want to permanently delete "' + slug + '"?')) return;
            const token = tokenInput.value.trim();
            try {
                const res = await fetch('/api/items/' + slug, {
                    method: 'DELETE',
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                if (res.ok) {
                    loadDashboard();
                } else {
                    alert('Failed to delete item.');
                }
            } catch (err) {
                alert('Error: ' + err.message);
            }
        }

        function previewItem(url, mime) {
            const modal = document.getElementById('previewModal');
            const body = document.getElementById('modalBody');
            body.innerHTML = '';

            if (mime.startsWith('image/')) {
                body.innerHTML = '<img src="' + url + '" style="max-width:100%; max-height:75vh; border-radius:8px;">';
            } else if (mime.startsWith('video/')) {
                body.innerHTML = '<video src="' + url + '" controls autoplay style="max-width:100%; max-height:75vh; border-radius:8px;"></video>';
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
            navigator.clipboard.writeText(str).then(() => {
                alert('Copied to clipboard: ' + str);
            });
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
}
