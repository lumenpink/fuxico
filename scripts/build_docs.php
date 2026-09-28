<?php

declare(strict_types=1);

/**
 * Fuxico — Static Documentation Generator
 * Compiles docs/*.md into dual-skin Zine/Acad standalone HTML pages under site/docs/.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Fuxico\MarkdownRenderer;

$rootDir = dirname(__DIR__);
$docsDir = $rootDir.'/docs';
$outDir = $rootDir.'/site/docs';

if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

echo "✨ [build_docs] Compilando documentação Zine do Fuxico...\n";

$docFiles = glob($docsDir.'/*.md');
if (empty($docFiles)) {
    echo "❌ [build_docs] Nenhum arquivo markdown encontrado em {$docsDir}\n";
    exit(1);
}

$manifest = [];

foreach ($docFiles as $file) {
    $basename = basename($file, '.md');
    $content = (string) file_get_contents($file);

    // Extract first # heading as title
    $title = $basename;
    if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
        $title = trim($m[1]);
    }

    // Extract summary/subtitle from blockquote or first paragraph
    $summary = '';
    if (preg_match('/^>\s*\[!NOTE\]\s*\n>\s*(.+)$/m', $content, $m)) {
        $summary = trim($m[1], "* \t\n\r\0\x0B");
    } elseif (preg_match('/^>\s*(.+)$/m', $content, $m)) {
        $summary = trim($m[1], "* \t\n\r\0\x0B");
    }

    $options = [
        'title' => "Fuxico — {$title}",
        'slug' => strtolower($basename),
        'original_name' => "{$basename}.md",
        'default_theme' => 'zine',
        'created_at' => filemtime($file) ?: time(),
    ];

    $html = MarkdownRenderer::render($content, $options);
    $targetFile = "{$outDir}/".strtolower($basename).'.html';
    file_put_contents($targetFile, $html);

    $manifest[] = [
        'slug' => strtolower($basename),
        'title' => $title,
        'summary' => $summary,
        'file' => strtolower($basename).'.html',
    ];

    echo "   📄 {$basename}.md → site/docs/".strtolower($basename).".html\n";
}

// Generate Index Page in site/docs/index.html
$indexMarkdown = <<<'MARKDOWN'
# 📚 Central de Documentação do Fuxico

> [!NOTE]
> Bem-vinda à documentação técnica e cultural do **Fuxico** — tecnologia travesti latino-americana de compartilhamento efêmero, visualização de markdown, gists e git http smart protocol.

---

## Guias e Manuais Disponíveis

MARKDOWN;

foreach ($manifest as $doc) {
    $indexMarkdown .= "### [{$doc['title']}]({$doc['file']})\n";
    if ($doc['summary'] !== '') {
        $indexMarkdown .= "{$doc['summary']}\n\n";
    } else {
        $indexMarkdown .= "Consulte a especificação completa em [{$doc['title']}]({$doc['file']}).\n\n";
    }
}

$indexMarkdown .= <<<'MARKDOWN'
---

## Links Rápidos
- 🏠 [Voltar para a Página Principal do Fuxico](../index.html)
- 💻 [Manual de Comandos da CLI](../commands.html)
- 💾 [Seção de Downloads & Binários](../downloads.html)
- 🐱 [Repositório Oficial no Codeberg](https://codeberg.org/lumenpink/fuxico)
MARKDOWN;

$indexHtml = MarkdownRenderer::render($indexMarkdown, [
    'title' => 'Fuxico — Central de Documentação Zine',
    'slug' => 'index',
    'original_name' => 'docs-index.md',
    'default_theme' => 'zine',
    'created_at' => time(),
]);

file_put_contents("{$outDir}/index.html", $indexHtml);
echo "   📑 Índice gerado: site/docs/index.html\n";

echo "🎉 [build_docs] Toda a documentação foi compilada com sucesso!\n";
