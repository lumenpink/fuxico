<?php

declare(strict_types=1);

use Fuxico\MarkdownRenderer;

test('parses frontmatter and renders title and headings with anchor ids', function () {
    $md = <<<'MD'
---
title: Fofoca Acadêmica
theme: zine
---

# Título Principal

Texto introdutório com **negrito** e *itálico*.

## Subtítulo do Bafão
MD;

    $parsed = MarkdownRenderer::parse($md);
    expect($parsed['meta'])->toHaveKey('title');
    expect($parsed['meta']['title'])->toBe('Fofoca Acadêmica');
    expect($parsed['meta']['theme'])->toBe('zine');

    expect($parsed['html'])->toContain('<h1 id="titulo-principal">Título Principal</h1>');
    expect($parsed['html'])->toContain('<h2 id="subtitulo-do-bafao">Subtítulo do Bafão</h2>');
    expect($parsed['html'])->toContain('<strong>negrito</strong>');
    expect($parsed['html'])->toContain('<em>itálico</em>');
});

test('parses obsidian callouts and wikilinks', function () {
    $md = <<<'MD'
> [!NOTE] Aviso Importante
> Essa nota foi compilada com Joaju.

> [!WARNING] Cuidado
> Não revele as fontes.

Veja essa imagem: ![[grafico1.png|Gráfico de Parentesco]]
MD;

    $parsed = MarkdownRenderer::parse($md);
    expect($parsed['html'])->toContain('class="callout callout-note"');
    expect($parsed['html'])->toContain('Aviso Importante');
    expect($parsed['html'])->toContain('Essa nota foi compilada com Joaju.');
    expect($parsed['html'])->toContain('class="callout callout-warning"');
    expect($parsed['html'])->toContain('<img src="assets/grafico1.png" alt="Gráfico de Parentesco" loading="lazy">');
});

test('preserves images and links with multiple underscores without corrupting src to italic', function () {
    $md = <<<'MD'
![[forest_plot_iniquidades_sc_2024.png]]
![[grafico_com_multiplos_underscores.png|Legenda do Gráfico]]
![Imagem Padrão](subpasta/minha_foto_com_underscore.png)
[meu_link_com_underscore](https://example.com/api_v1_path)
MD;

    $parsed = MarkdownRenderer::parse($md);
    expect($parsed['html'])->toContain('<img src="assets/forest_plot_iniquidades_sc_2024.png" alt="forest_plot_iniquidades_sc_2024.png" loading="lazy">');
    expect($parsed['html'])->toContain('<img src="assets/grafico_com_multiplos_underscores.png" alt="Legenda do Gráfico" loading="lazy">');
    expect($parsed['html'])->toContain('<img src="assets/minha_foto_com_underscore.png" alt="Imagem Padrão" loading="lazy">');
    expect($parsed['html'])->toContain('<a href="https://example.com/api_v1_path" target="_blank" rel="noopener noreferrer">meu_link_com_underscore</a>');
    expect($parsed['html'])->not->toContain('<em>plot</em>');
    expect($parsed['html'])->not->toContain('<em>sc</em>');
});

test('renders absolute asset paths and base tag when slug option is passed', function () {
    $md = <<<'MD'
![[forest_plot_iniquidades_sc_2024.png]]
![Foto](minha_foto.png)
MD;

    $rendered = MarkdownRenderer::render($md, ['slug' => 'fvwls']);
    expect($rendered)->toContain('<base href="/fvwls/">');
    expect($rendered)->toContain('<img src="/fvwls/assets/forest_plot_iniquidades_sc_2024.png" alt="forest_plot_iniquidades_sc_2024.png" loading="lazy">');
    expect($rendered)->toContain('<img src="/fvwls/assets/minha_foto.png" alt="Foto" loading="lazy">');
});

test('parses obsidian highlights, task lists and code blocks', function () {
    $md = <<<'MD'
Aqui está um texto com ==marcação amarela== e código inline `echo 'fuxico';`.

- [ ] Tarefa não feita
- [x] Tarefa concluída com sucesso

```python
def deboche():
    return "babado"
```
MD;

    $parsed = MarkdownRenderer::parse($md);
    expect($parsed['html'])->toContain('<mark>marcação amarela</mark>');
    expect($parsed['html'])->toContain('<code>echo &#039;fuxico&#039;;</code>');
    expect($parsed['html'])->toContain('<li class="task-list-item"><input type="checkbox"  disabled> Tarefa não feita</li>');
    expect($parsed['html'])->toContain('<li class="task-list-item"><input type="checkbox" checked disabled> Tarefa concluída com sucesso</li>');
    expect($parsed['html'])->toContain('class="code-block-wrapper"');
    expect($parsed['html'])->toContain('<span>PYTHON</span>');
    expect($parsed['html'])->toContain('class="language-python"');
});

test('parses markdown pipe tables', function () {
    $md = <<<'MD'
| Nome | Papel | Situação |
| :--- | :---: | ---: |
| Brenda | Trava Hacker | Ativa |
| Safira | Produtora | Aquendada |
MD;

    $parsed = MarkdownRenderer::parse($md);
    expect($parsed['html'])->toContain('<div class="table-wrapper"><table><thead><tr>');
    expect($parsed['html'])->toContain('<th style="text-align:left;">Nome</th>');
    expect($parsed['html'])->toContain('<th style="text-align:center;">Papel</th>');
    expect($parsed['html'])->toContain('<th style="text-align:right;">Situação</th>');
    expect($parsed['html'])->toContain('<td style="text-align:left;">Brenda</td>');
    expect($parsed['html'])->toContain('<td style="text-align:center;">Trava Hacker</td>');
    expect($parsed['html'])->toContain('<td style="text-align:right;">Ativa</td>');
});

test('render produces standalone html with dual-skin zine and acad css and inline switcher', function () {
    $md = <<<'MD'
# Manifesto Travesti

Compartilhando a fofoca com classe.
MD;

    $html = MarkdownRenderer::render($md, [
        'title' => 'Manifesto',
        'slug' => 'manifesto',
        'original_name' => 'manifesto.md',
    ]);

    expect($html)->toContain('<!DOCTYPE html>');
    expect($html)->toContain('<title>Manifesto</title>');
    expect($html)->toContain('[data-theme="zine"]');
    expect($html)->toContain('[data-theme="acad"]');
    expect($html)->toContain('data-theme="zine"');
    expect($html)->toContain('class="trans-stripe"');
    expect($html)->toContain('class="duct-tape"');
    expect($html)->toContain('id="btnThemeToggle"');
    expect($html)->toContain('params.get(\'acad\') === \'true\'');
    expect($html)->toContain('<h1 id="manifesto-travesti">Manifesto Travesti</h1>');
    expect($html)->toContain('href="?raw=1"');
});

test('render includes mobile responsive stylesheet and touch interactions for diagrams (#46)', function () {
    $md = <<<'MD'
# Teste Responsivo

Texto com diagrama e tabela.

```d2
direction: down
a -> b
```
MD;

    $html = MarkdownRenderer::render($md, [
        'title' => 'Teste Mobile',
        'slug' => 'test-mobile',
    ]);

    expect($html)->toContain('@media (max-width: 768px)');
    expect($html)->toContain('@media (max-width: 480px)');
    expect($html)->toContain('position: fixed');
    expect($html)->toContain('initDiagramTouchInteractions()');
    expect($html)->toContain('touch-action: pan-y pinch-zoom');
});
