#!/usr/bin/env python3
"""
Gera todos os prints reais e mockups de terminal da documentação em site/assets/prints/
localizados para os idiomas Português (PT-BR) e Inglês (EN):
- ui-dropzone.png (PT) e ui-dropzone-en.png (EN)
- ui-shorten.png (PT) e ui-shorten-en.png (EN)
- ui-dashboard.png (PT) e ui-dashboard-en.png (EN)
- ui-terminal.png (PT) e ui-terminal-en.png (EN)
"""

import os
import subprocess
import tempfile

PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
PRINTS_DIR = os.path.join(PROJECT_ROOT, "site", "assets", "prints")

os.makedirs(PRINTS_DIR, exist_ok=True)


def run_chrome(html_path: str, output_png: str, width: int = 1200, height: int = 900):
    cmd = [
        "google-chrome",
        "--headless=new",
        "--ozone-platform=headless",
        "--disable-gpu",
        f"--screenshot={output_png}",
        f"--window-size={width},{height}",
        "--virtual-time-budget=2000",
        f"file://{html_path}",
    ]
    subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    print(f"✅ Gerado: {os.path.basename(output_png)} ({width}x{height})")


def generate_terminals():
    terminal_template = """<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<style>
  * {{ box-sizing: border-box; margin: 0; padding: 0; }}
  body {{
    width: 960px;
    height: 560px;
    background: #0d0b11;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Space Mono', monospace;
    overflow: hidden;
  }}
  .terminal-box {{
    width: 920px;
    height: 520px;
    background: #14111a;
    border: 2px solid #e2f835;
    box-shadow: 6px 6px 0px #ff2a85;
    border-radius: 4px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }}
  .terminal-header {{
    background: #1f1b29;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #383047;
  }}
  .dots {{ display: flex; gap: 7px; }}
  .dot {{ width: 12px; height: 12px; border-radius: 50%; }}
  .dot-red {{ background: #ff5f56; }}
  .dot-yellow {{ background: #ffbd2e; }}
  .dot-green {{ background: #27c93f; }}
  .title {{ color: #9f97ad; font-size: 0.82rem; font-weight: 700; letter-spacing: 0.5px; }}
  .terminal-content {{
    padding: 22px 24px;
    color: #f5f4ef;
    font-size: 0.88rem;
    line-height: 1.6;
    flex: 1;
  }}
  .prompt {{ color: #5bcefa; font-weight: 700; }}
  .cmd {{ color: #ffffff; }}
  .tag-fux {{ color: #ff2a85; font-weight: 700; }}
  .meta-label {{ color: #e2f835; }}
  .out-url {{ color: #e2f835; font-weight: 700; }}
  .muted {{ color: #9f97ad; }}
  .line-space {{ margin-bottom: 18px; }}
</style>
</head>
<body>
  <div class="terminal-box">
    <div class="terminal-header">
      <div class="dots">
        <div class="dot dot-red"></div>
        <div class="dot dot-yellow"></div>
        <div class="dot dot-green"></div>
      </div>
      <div class="title">{title}</div>
    </div>
    <div class="terminal-content">
      {body}
    </div>
  </div>
</body>
</html>"""

    # 1. Terminal PT-BR
    body_pt = """
      <div><span class="prompt">lumen@indiebox:~$</span> <span class="cmd">fux bafao.png --caduca 24h --copia</span></div>
      <div><span class="tag-fux">[fux]</span> + espalhando o bafão: bafao.png (2.4 MB)</div>
      <div><span class="tag-fux">[fux]</span> @ dialeto: nullpointer | servidor: https://fuxico.2lp.in</div>
      <div><span class="tag-fux">[fux]</span> &amp; validade: 24 horas (expira em 2026-09-23 11:08 UTC)</div>
      <div><span class="tag-fux">[fux]</span> @ ficha de gestão: 9f72b81ec0a4b3d858349281a (X-Token)</div>
      <div><span class="tag-fux">[fux]</span> 📋 copiado para a área de transferência via xclip! ++</div>
      <div class="line-space"><span class="muted">&gt;</span> <span class="out-url">https://fuxico.2lp.in/8x9yt.png</span></div>

      <div><span class="prompt">lumen@indiebox:~$</span> <span class="cmd">cat segredo.log | fux -q --caladinho</span></div>
      <div class="line-space"><span class="muted">&gt;</span> <span class="out-url">https://fuxico.2lp.in/z7k1q</span></div>

      <div><span class="prompt">lumen@indiebox:~$</span> <span class="cmd">fux --encurta https://codeberg.org/lumenpink/fuxico --nome fuxico</span></div>
      <div><span class="tag-fux">[fux]</span> @ encurtando link com apelido customizado 'fuxico'...</div>
      <div><span class="muted">&gt;</span> <span class="out-url">https://fuxico.2lp.in/fuxico</span></div>
    """
    with tempfile.NamedTemporaryFile("w", suffix=".html", delete=False) as f:
        f.write(terminal_template.format(title="fux — A malícia popular do boca a boca no terminal", body=body_pt))
        tmp_pt = f.name
    run_chrome(tmp_pt, os.path.join(PRINTS_DIR, "ui-terminal.png"), 960, 560)
    os.unlink(tmp_pt)

    # 2. Terminal EN
    body_en = """
      <div><span class="prompt">lumen@indiebox:~$</span> <span class="cmd">fux scoop.png --expires 24h --copy</span></div>
      <div><span class="tag-fux">[fux]</span> + spilling the beans: scoop.png (2.4 MB)</div>
      <div><span class="tag-fux">[fux]</span> @ dialect: nullpointer | server: https://fuxico.2lp.in</div>
      <div><span class="tag-fux">[fux]</span> &amp; expires: 24 hours (expires at 2026-09-23 11:08 UTC)</div>
      <div><span class="tag-fux">[fux]</span> @ management token: 9f72b81ec0a4b3d858349281a (X-Token)</div>
      <div><span class="tag-fux">[fux]</span> 📋 copied to clipboard via xclip! ++</div>
      <div class="line-space"><span class="muted">&gt;</span> <span class="out-url">https://fuxico.2lp.in/8x9yt.png</span></div>

      <div><span class="prompt">lumen@indiebox:~$</span> <span class="cmd">cat secret.log | fux -o --silent</span></div>
      <div class="line-space"><span class="muted">&gt;</span> <span class="out-url">https://fuxico.2lp.in/z7k1q</span></div>

      <div><span class="prompt">lumen@indiebox:~$</span> <span class="cmd">fux --shorten https://codeberg.org/lumenpink/fuxico --name fuxico</span></div>
      <div><span class="tag-fux">[fux]</span> @ shortening link with custom alias 'fuxico'...</div>
      <div><span class="muted">&gt;</span> <span class="out-url">https://fuxico.2lp.in/fuxico</span></div>
    """
    with tempfile.NamedTemporaryFile("w", suffix=".html", delete=False) as f:
        f.write(terminal_template.format(title="fux — Word-of-mouth street mischief in your terminal", body=body_en))
        tmp_en = f.name
    run_chrome(tmp_en, os.path.join(PRINTS_DIR, "ui-terminal-en.png"), 960, 560)
    os.unlink(tmp_en)


def generate_web_ui():
    php_script = f"""<?php
require_once '{PROJECT_ROOT}/vendor/autoload.php';

use Fuxico\\Config;
use Fuxico\\I18n;
use Fuxico\\Views;

$config = new Config();
$config->publicMode = true;
$config->maxUploadSize = 680 * 1024 * 1024;

// 1. Dashboard EN
$i18nEn = new I18n('en');
$htmlDashEn = Views::renderDashboardUI($config, $i18nEn);
$dashInjectEn = <<<JS
<script>
window.addEventListener('DOMContentLoaded', () => {{
    document.getElementById('loginView').style.display = 'none';
    document.getElementById('dashView').style.display = 'block';
    document.getElementById('statFiles').innerText = '4';
    document.getElementById('statUrls').innerText = '2';
    document.getElementById('statBytes').innerText = '2.4 MB';
    document.getElementById('statViews').innerText = '42';

    const tbody = document.getElementById('dashTableBody');
    tbody.innerHTML = `
        <tr>
            <td><strong style="color:var(--acid-yellow); font-family:var(--font-mono);">fuxrepo</strong><br><small style="color:var(--text-muted);">https://codeberg.org/lumenpink/fuxico</small></td>
            <td><span class="badge badge-url">URL</span></td>
            <td>-</td>
            <td><small style="color:var(--text-muted);">Until discovered (permanent)</small></td>
            <td>14</td>
            <td>
                <button class="btn-action-icon">Copy</button>
                <a class="btn-action-icon" href="#">Open</a>
                <button class="btn-action-icon btn-delete">Burn</button>
            </td>
        </tr>
        <tr>
            <td><strong style="color:var(--acid-yellow); font-family:var(--font-mono);">2hthl</strong><br><small style="color:var(--text-muted);">README.md</small></td>
            <td><span class="badge badge-file">File</span></td>
            <td>1.2 MB</td>
            <td><small style="color:var(--text-muted);">1 hour of hot tea</small></td>
            <td>8</td>
            <td>
                <button class="btn-action-icon">Copy</button>
                <button class="btn-action-icon">Peek</button>
                <button class="btn-action-icon btn-delete">Burn</button>
            </td>
        </tr>
    `;
}});
</script>
JS;
file_put_contents('{PROJECT_ROOT}/site/assets/prints/tmp_dash_en.html', str_replace('</body>', $dashInjectEn . '</body>', $htmlDashEn));

// 2. Shorten URL EN
$htmlShortenEn = Views::renderWebUI($config, $i18nEn);
$shortenInjectEn = <<<JS
<script>
window.addEventListener('DOMContentLoaded', () => {{
    const urlBtn = document.querySelectorAll('.tab-btn')[1];
    if (urlBtn) switchTab('urlTab', urlBtn);
    const input = document.getElementById('urlInput');
    if (input) input.value = 'https://example.com/spicy-leak';
}});
</script>
JS;
file_put_contents('{PROJECT_ROOT}/site/assets/prints/tmp_shorten_en.html', str_replace('</body>', $shortenInjectEn . '</body>', $htmlShortenEn));
"""
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as f:
        f.write(php_script)
        tmp_php = f.name
    subprocess.run(["php", tmp_php], check=True)
    os.unlink(tmp_php)

    tmp_dash_en = os.path.join(PRINTS_DIR, "tmp_dash_en.html")
    tmp_shorten_en = os.path.join(PRINTS_DIR, "tmp_shorten_en.html")

    run_chrome(tmp_dash_en, os.path.join(PRINTS_DIR, "ui-dashboard-en.png"), 1200, 900)
    run_chrome(tmp_shorten_en, os.path.join(PRINTS_DIR, "ui-shorten-en.png"), 1200, 900)

    if os.path.exists(tmp_dash_en):
        os.unlink(tmp_dash_en)
    if os.path.exists(tmp_shorten_en):
        os.unlink(tmp_shorten_en)


if __name__ == "__main__":
    print("🎨 Gerando prints e mockups de terminal em EN e PT-BR...")
    generate_terminals()
    generate_web_ui()
    print("✨ Todos os prints foram gerados com sucesso em site/assets/prints/!")
