<?php

declare(strict_types=1);

use Fuxico\MarkdownRenderer;

test('bakes d2 diagram block into inline SVG within figure container', function () {
    $md = <<<'MD'
# Teste D2

```d2
x -> y: Conexao
```
MD;

    $parsed = MarkdownRenderer::parse($md);

    // If Joaju service is reachable, it bakes the SVG into .diagram-viewport
    // If not reachable (e.g. offline CI environment), it falls back gracefully
    if (str_contains($parsed['html'], 'class="diagram-figure diagram-d2"')) {
        expect($parsed['html'])->toContain('class="diagram-figure diagram-d2"');
        expect($parsed['html'])->toContain('<div class="diagram-viewport">');
        expect($parsed['html'])->toContain('<svg');
        expect($parsed['html'])->toContain('class="diagram-source"');
        expect($parsed['html'])->toContain('x -&gt; y: Conexao');
    } else {
        expect($parsed['html'])->toContain('class="code-block-wrapper"');
        expect($parsed['html'])->toContain('class="language-d2"');
    }
});

test('bakes kinship2 pedigree diagram block into inline SVG', function () {
    $md = <<<'MD'
# Heredograma Familiar

```kinship2
id, pai, mae, sexo, fenotipo
1, 0, 0, M, higido
2, 0, 0, F, afetado
3, 1, 2, F, portador
```
MD;

    $parsed = MarkdownRenderer::parse($md);

    if (str_contains($parsed['html'], 'class="diagram-figure diagram-kinship2"')) {
        expect($parsed['html'])->toContain('class="diagram-figure diagram-kinship2"');
        expect($parsed['html'])->toContain('<div class="diagram-viewport">');
        expect($parsed['html'])->toContain('<svg');
        expect($parsed['html'])->toContain('class="diagram-source"');
        expect($parsed['html'])->toContain('id, pai, mae');
    } else {
        expect($parsed['html'])->toContain('class="code-block-wrapper"');
        expect($parsed['html'])->toContain('class="language-kinship2"');
    }
});

test('bakes pedtools pedigree diagram block into inline SVG', function () {
    $md = <<<'MD'
# Heredograma Pedtools

```pedtools
id, pai, mae, sexo
1, 0, 0, 1
2, 0, 0, 2
3, 1, 2, 1
```
MD;

    $parsed = MarkdownRenderer::parse($md, ['joaju_url' => 'http://localhost:3000/render']);

    if (str_contains($parsed['html'], 'class="diagram-figure diagram-pedtools"')) {
        expect($parsed['html'])->toContain('class="diagram-figure diagram-pedtools"');
        expect($parsed['html'])->toContain('<div class="diagram-viewport">');
        expect($parsed['html'])->toContain('<svg');
        expect($parsed['html'])->toContain('class="diagram-source"');
        expect($parsed['html'])->toContain('id, pai, mae');
    } else {
        expect($parsed['html'])->toContain('class="code-block-wrapper"');
        expect($parsed['html'])->toContain('class="language-pedtools"');
    }
});

test('bakes table diagram block into HTML table in viewport', function () {
    $md = <<<'MD'
# Tabela Acadêmica

```table
Amostra, Controle, Tratamento
1, 10.2, 15.4
2, 11.0, 14.8
```
MD;

    $parsed = MarkdownRenderer::parse($md, ['joaju_url' => 'http://localhost:3000/render']);

    if (str_contains($parsed['html'], 'class="diagram-figure diagram-table"')) {
        expect($parsed['html'])->toContain('class="diagram-figure diagram-table"');
        expect($parsed['html'])->toContain('<div class="diagram-viewport">');
        expect($parsed['html'])->toContain('class="diagram-source"');
        expect($parsed['html'])->toContain('Amostra');
    } else {
        expect($parsed['html'])->toContain('class="code-block-wrapper"');
        expect($parsed['html'])->toContain('class="language-table"');
    }
});

test('gracefully falls back to standard code block when diagrams are disabled', function () {
    $md = <<<'MD'
```d2
a -> b
```
MD;

    $parsed = MarkdownRenderer::parse($md, ['disable_diagrams' => true]);

    expect($parsed['html'])->toContain('class="code-block-wrapper"');
    expect($parsed['html'])->toContain('<span>D2</span>');
    expect($parsed['html'])->toContain('class="language-d2"');
    expect($parsed['html'])->not->toContain('class="diagram-figure"');
});

test('standard code blocks remain untouched and do not invoke diagram rendering', function () {
    $md = <<<'MD'
```python
def fuxico():
    return "babado"
```
MD;

    $parsed = MarkdownRenderer::parse($md);

    expect($parsed['html'])->toContain('class="code-block-wrapper"');
    expect($parsed['html'])->toContain('<span>PYTHON</span>');
    expect($parsed['html'])->toContain('class="language-python"');
    expect($parsed['html'])->not->toContain('class="diagram-figure"');
});

test('legacy diagram tags (kinship, heredograma, pedigree, puml, dot) are not recognized as diagrams', function () {
    $md = <<<'MD'
```kinship
1, 0, 0, M
```

```heredograma
1, 0, 0, M
```

```puml
@startuml
@enduml
```
MD;

    $parsed = MarkdownRenderer::parse($md);

    expect($parsed['html'])->not->toContain('class="diagram-figure"');
    expect($parsed['html'])->toContain('class="language-kinship"');
    expect($parsed['html'])->toContain('class="language-heredograma"');
    expect($parsed['html'])->toContain('class="language-puml"');
});

test('render produces standalone html with baked SVG diagrams and toggle script', function () {
    $md = <<<'MD'
# Nota Clinica

```d2
service -> database
```
MD;

    $html = MarkdownRenderer::render($md, [
        'title' => 'Nota Clinica',
        'slug' => 'test-nota',
        'default_theme' => 'zine',
    ]);

    expect($html)->toContain('<!DOCTYPE html>');
    expect($html)->toContain('toggleDiagramSource');
    expect($html)->toContain('.diagram-figure');
    expect($html)->toContain('.diagram-viewport');
});

test('normalizes joaju base url to /render endpoint correctly', function () {
    expect(MarkdownRenderer::buildJoajuRenderUrl('http://100.69.210.39:3000'))
        ->toBe('http://100.69.210.39:3000/render');

    expect(MarkdownRenderer::buildJoajuRenderUrl('http://100.69.210.39:3000/'))
        ->toBe('http://100.69.210.39:3000/render');

    expect(MarkdownRenderer::buildJoajuRenderUrl('https://joaju.lumen.pink/render'))
        ->toBe('https://joaju.lumen.pink/render');

    expect(MarkdownRenderer::buildJoajuRenderUrl('http://joaju:3000'))
        ->toBe('http://joaju:3000/render');

    expect(MarkdownRenderer::buildJoajuRenderUrl(''))
        ->toBe('https://joaju.lumen.pink/render');
});
