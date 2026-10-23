<?php

namespace Fuxico;

class OgCard
{
    /**
     * Gera os bytes de uma imagem PNG de 1200x630 pixels para Open Graph.
     */
    public static function generatePng(array $item, Config $config): string
    {
        $width = 1200;
        $height = 630;

        $im = imagecreatetruecolor($width, $height);
        if ($im === false) {
            return '';
        }

        // Ativar antialiasing
        if (function_exists('imageantialias')) {
            imageantialias($im, true);
        }

        // 1. Paleta de Cores
        $bg = imagecolorallocate($im, 18, 16, 22); // #121016
        $cardBg = imagecolorallocate($im, 26, 22, 34); // #1a1622
        $pink = imagecolorallocate($im, 255, 42, 133); // #ff2a85
        $yellow = imagecolorallocate($im, 226, 248, 53); // #e2f835
        $white = imagecolorallocate($im, 245, 244, 239); // #f5f4ef
        $muted = imagecolorallocate($im, 159, 151, 173); // #9f97ad
        $borderDark = imagecolorallocate($im, 10, 8, 13); // #0a080d

        // Cores da Listra Trans
        $transBlue = imagecolorallocate($im, 91, 206, 250); // #5BCEFA
        $transPink = imagecolorallocate($im, 245, 169, 184); // #F5A9B8
        $transWhite = imagecolorallocate($im, 255, 255, 255); // #FFFFFF

        // Fundo principal
        imagefilledrectangle($im, 0, 0, $width, $height, $bg);

        // Retícula pontilhada de fundo (textura de fanzine / xerox)
        $dotColor = imagecolorallocatealpha($im, 226, 248, 53, 120);
        for ($x = 20; $x < $width; $x += 40) {
            for ($y = 20; $y < $height; $y += 40) {
                imagefilledellipse($im, $x, $y, 3, 3, $dotColor);
            }
        }

        // Sombra dura da moldura central
        $mx1 = 50;
        $my1 = 45;
        $mx2 = $width - 50;
        $my2 = $height - 45;
        imagefilledrectangle($im, $mx1 + 12, $my1 + 12, $mx2 + 12, $my2 + 12, $borderDark);

        // Moldura do card central
        imagefilledrectangle($im, $mx1, $my1, $mx2, $my2, $cardBg);
        imagesetthickness($im, 4);
        imagerectangle($im, $mx1, $my1, $mx2, $my2, $pink);

        // Listra Trans no topo do card
        $stripeY = $my1 + 16;
        $stripeH = 6;
        $stripes = [$transBlue, $transPink, $transWhite, $transPink, $transBlue];
        foreach ($stripes as $idx => $sCol) {
            $sy = $stripeY + ($idx * $stripeH);
            imagefilledrectangle($im, $mx1 + 4, $sy, $mx2 - 4, $sy + $stripeH - 1, $sCol);
        }

        // Localizar fontes TrueType no sistema
        $fontMono = self::findFont([
            '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf',
        ]);
        $fontSans = self::findFont([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ]);

        // Cabeçalho: Logo FUXICO // VANGUARDA TRAVESTI
        $headerText = '⚡ FUXICO // ARQUIVO EFÊMERO & ZINE DE CÓDIGO';
        if ($fontMono) {
            imagettftext($im, 16, 0, $mx1 + 36, $my1 + 80, $yellow, $fontMono, $headerText);
        } else {
            imagestring($im, 5, $mx1 + 36, $my1 + 65, $headerText, $yellow);
        }

        // Linha divisória
        imagesetthickness($im, 2);
        imageline($im, $mx1 + 36, $my1 + 96, $mx2 - 36, $my1 + 96, $pink);

        // Extração de Título do Item
        $title = (string) ($item['original_name'] ?: $item['slug'] ?: 'Publicação Fuxico');
        if (! empty($item['type']) && $item['type'] === 'doc') {
            $itemData = json_decode((string) ($item['data'] ?? '{}'), true) ?: [];
            if (! empty($itemData['title'])) {
                $title = (string) $itemData['title'];
            }
        }

        // Sanitização e formatação do título
        $title = trim($title);
        $displayTitle = self::wrapText($title, 40);

        $titleY = $my1 + 180;
        if ($fontSans) {
            imagettftext($im, 36, 0, $mx1 + 36, $titleY, $white, $fontSans, $displayTitle);
        } else {
            imagestring($im, 5, $mx1 + 36, $titleY - 40, substr($displayTitle, 0, 50), $white);
        }

        // Metadados do Item: Tipo, Tamanho, Data
        $type = strtoupper((string) ($item['type'] ?? 'FILE'));
        $size = (int) ($item['size_bytes'] ?? $item['size'] ?? 0);
        $formattedSize = match (true) {
            $size >= 1073741824 => number_format($size / 1073741824, 2).' GB',
            $size >= 1048576 => number_format($size / 1048576, 2).' MB',
            $size >= 1024 => number_format($size / 1024, 2).' KB',
            $size > 0 => $size.' B',
            default => 'N/A',
        };

        $badgeText = "[ TIPO: {$type} ]   [ TAMANHO: {$formattedSize} ]";
        if (! empty($item['is_oneshot'])) {
            $badgeText .= '   [ 🔥 QUEIMA APÓS LEITURA ]';
        }

        $badgeY = $my1 + 360;
        // Fundo do badge
        imagefilledrectangle($im, $mx1 + 36, $badgeY - 26, $mx1 + 720, $badgeY + 12, $borderDark);
        imagesetthickness($im, 2);
        imagerectangle($im, $mx1 + 36, $badgeY - 26, $mx1 + 720, $badgeY + 12, $yellow);

        if ($fontMono) {
            imagettftext($im, 15, 0, $mx1 + 48, $badgeY, $yellow, $fontMono, $badgeText);
        } else {
            imagestring($im, 4, $mx1 + 48, $badgeY - 18, $badgeText, $yellow);
        }

        // Rodapé do Card
        $footerY = $my2 - 40;
        $urlDisplay = rtrim((string) $config->baseUrl, '/').'/'.($item['slug'] ?? '');
        $securityNotice = '🔒 Fuxico Soberano // Sem Rastreamento // Criptografia na Borda';

        if ($fontMono) {
            imagettftext($im, 16, 0, $mx1 + 36, $footerY, $pink, $fontMono, '↗ '.$urlDisplay);
            imagettftext($im, 12, 0, $mx1 + 36, $footerY + 24, $muted, $fontMono, $securityNotice);
        } else {
            imagestring($im, 4, $mx1 + 36, $footerY - 20, '↗ '.$urlDisplay, $pink);
            imagestring($im, 2, $mx1 + 36, $footerY + 5, $securityNotice, $muted);
        }

        ob_start();
        imagepng($im);
        $output = (string) ob_get_clean();

        return $output;
    }

    /**
     * Localiza a primeira fonte TrueType existente na lista de caminhos candidatos.
     */
    private static function findFont(array $candidates): ?string
    {
        foreach ($candidates as $font) {
            if (file_exists($font) && is_readable($font)) {
                return $font;
            }
        }

        return null;
    }

    /**
     * Quebra texto em linhas com limite máximo de caracteres.
     */
    private static function wrapText(string $text, int $maxChars): string
    {
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if (mb_strlen($current.' '.$word, 'UTF-8') <= $maxChars) {
                $current = $current === '' ? $word : $current.' '.$word;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
                if (count($lines) >= 2) {
                    break;
                }
            }
        }
        if ($current !== '' && count($lines) < 2) {
            $lines[] = $current;
        }

        $result = implode("\n", $lines);
        if (mb_strlen($text, 'UTF-8') > mb_strlen($result, 'UTF-8')) {
            $result .= '...';
        }

        return $result;
    }
}
