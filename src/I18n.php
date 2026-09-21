<?php

declare(strict_types=1);

namespace Fuxico;

class I18n
{
    private string $lang;

    /**
     * @var array<string, array<string, string>>
     */
    private static array $dictionary = [
        'pt' => [
            'app_name' => 'FUXICO',
            'tagline' => 'A malícia popular do boca a boca',
            'brand_sub' => 'Compartilhamento efêmero de arquivos & encurtador de links',
            'drop_idle' => 'Larga a pedrada aqui.',
            'drop_sub' => 'ou clique para catar nos seus arquivos',
            'drop_drag' => 'Pode largar que a gente espalha!',
            'btn_upload' => 'Solta o verbo ⚡',
            'btn_uploading' => 'Espalhando o bafão...',
            'tab_file' => 'Arquivo',
            'tab_url' => 'Encurtar URL',
            'tab_dash' => 'Fofocas no Ar',
            'opt_burn' => 'Queima de arquivo (some no 1º download)',
            'opt_secret' => 'Fofoca cifrada (slug de 16 caracteres)',
            'opt_expires' => 'Validade da fofoca:',
            'exp_none' => 'Até alguém descobrir (sem expiração)',
            'exp_1h' => '1 hora de fofoca quente',
            'exp_24h' => '24 horas',
            'exp_7d' => '7 dias',
            'exp_30d' => '30 dias',
            'custom_name_placeholder' => 'Apelido customizado (opcional)',
            'url_placeholder' => 'https://exemplo.com/fofoca-bombastica',
            'btn_shorten' => 'Encurtar fofoca ⚡',
            'token_label' => 'Senha da fofoca (Token de Autorização):',
            'token_placeholder' => 'tatanka ou token mestre...',
            'token_public' => 'Modo público ativo: qualquer um pode fuxicar sem token!',
            'stat_files' => 'Arquivos no Ar',
            'stat_urls' => 'Links Espalhados',
            'stat_bytes' => 'Volume da Fofoca',
            'stat_views' => 'Bisbilhotadas',
            'dash_search' => 'Buscar fofoca por slug ou nome...',
            'dash_filter_all' => 'Tudo que tá rolando',
            'dash_filter_files' => 'Só arquivos',
            'dash_filter_urls' => 'Só links encurtados',
            'dash_empty' => 'Nenhum segredo encontrado.',
            'dash_auth_required' => 'Manda o token de autorização acima pra fiscalizar o cofre.',
            'col_slug' => 'Slug / Fofoca',
            'col_type' => 'Tipo',
            'col_size' => 'Tamanho',
            'col_exp' => 'Destino',
            'col_views' => 'Bisbilhotadas',
            'col_actions' => 'Ações',
            'act_copy' => 'Copiar',
            'act_view' => 'Espiar',
            'act_open' => 'Abrir',
            'act_delete' => 'Queimar',
            'dl_title' => 'Fuxico | Baixar o babado',
            'dl_subtitle' => 'Alguém mandou esse pacote pra você.',
            'dl_btn' => 'Baixar o babado 📦',
            'dl_warning_burn' => '⚠️ Aviso: assim que o download terminar, esse arquivo evapora do servidor.',
            'err_404_title' => 'Essa fofoca já prescreveu',
            'err_404_desc' => 'Essa fofoca já prescreveu (ou o link foi digitado errado).',
            'err_410_title' => 'Tarde demais. Esse segredo já foi recolhido.',
            'err_410_desc' => 'Esse pacote era queima-de-arquivo e já evaporou depois do primeiro acesso.',
            'back_home' => 'Voltar pro início e fuxicar outra coisa',
            'copy_success' => 'Link copiado! Vai lá espalhar.',
            'delete_confirm' => 'Tem certeza que quer queimar esse segredo pra sempre?',
        ],
        'en' => [
            'app_name' => 'FUXICO',
            'tagline' => 'Word-of-mouth street mischief',
            'brand_sub' => 'Ephemeral file sharing & URL shortener',
            'drop_idle' => 'Spill the beans here.',
            'drop_sub' => 'or click to browse your files',
            'drop_drag' => "Let it go — we'll spread the word!",
            'btn_upload' => 'Spill the beans ⚡',
            'btn_uploading' => 'Spreading the scoop...',
            'tab_file' => 'File',
            'tab_url' => 'Shorten URL',
            'tab_dash' => 'Active Scoops',
            'opt_burn' => 'Burn after reading (destructs on 1st download)',
            'opt_secret' => 'Ciphered scoop (16-char secret slug)',
            'opt_expires' => 'Gossip retention:',
            'exp_none' => 'Until discovered (permanent)',
            'exp_1h' => '1 hour of hot tea',
            'exp_24h' => '24 hours',
            'exp_7d' => '7 days',
            'exp_30d' => '30 days',
            'custom_name_placeholder' => 'Custom slug alias (optional)',
            'url_placeholder' => 'https://example.com/spicy-leak',
            'btn_shorten' => 'Shorten scoop ⚡',
            'token_label' => 'Gossip Passphrase (Auth Token):',
            'token_placeholder' => 'tatanka or master token...',
            'token_public' => 'Public mode active: anyone can spill the beans!',
            'stat_files' => 'Live Files',
            'stat_urls' => 'Spread Links',
            'stat_bytes' => 'Gossip Weight',
            'stat_views' => 'Total Snoops',
            'dash_search' => 'Search scoop by slug or original name...',
            'dash_filter_all' => 'Everything circulating',
            'dash_filter_files' => 'Files only',
            'dash_filter_urls' => 'URLs only',
            'dash_empty' => 'No records found in the vault.',
            'dash_auth_required' => 'Provide an authorization token above to inspect vault records.',
            'col_slug' => 'Slug / Gossip',
            'col_type' => 'Type',
            'col_size' => 'Size',
            'col_exp' => 'Fate',
            'col_views' => 'Snoops',
            'col_actions' => 'Actions',
            'act_copy' => 'Copy',
            'act_view' => 'Peek',
            'act_open' => 'Open',
            'act_delete' => 'Burn',
            'dl_title' => 'Fuxico | Download the scoop',
            'dl_subtitle' => 'Someone passed this package along to you.',
            'dl_btn' => 'Download the scoop 📦',
            'dl_warning_burn' => '⚠️ Warning: once this download finishes, this file evaporates from the server.',
            'err_404_title' => 'This gossip already expired',
            'err_404_desc' => 'This gossip already expired (or the link was mistyped).',
            'err_410_title' => 'Too late. Somebody already snooped through this package.',
            'err_410_desc' => 'This was a burn-after-reading package and has already evaporated from the server.',
            'back_home' => 'Back to home to spill something else',
            'copy_success' => 'Link copied! Go out there and spread the word.',
            'delete_confirm' => 'Are you sure you want to burn this secret forever?',
        ],
    ];

    public function __construct(?string $explicitLang = null)
    {
        $this->lang = $this->detectLanguage($explicitLang);
    }

    /**
     * Detect language:
     * 1. Explicit parameter / query string (?lang=pt|en)
     * 2. Environment variable FUXICO_LANG
     * 3. HTTP Accept-Language header
     * 4. Default: 'en'
     */
    public function detectLanguage(?string $explicit = null): string
    {
        if ($explicit !== null && in_array(strtolower($explicit), ['pt', 'en'], true)) {
            return strtolower($explicit);
        }

        if (isset($_GET['lang']) && in_array(strtolower((string) $_GET['lang']), ['pt', 'en'], true)) {
            return strtolower((string) $_GET['lang']);
        }

        $envLang = getenv('FUXICO_LANG');
        if ($envLang && in_array(strtolower((string) $envLang), ['pt', 'en'], true)) {
            return strtolower((string) $envLang);
        }

        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($header !== '') {
            $langs = explode(',', $header);
            foreach ($langs as $langPart) {
                $code = strtolower(trim(explode(';', $langPart)[0]));
                if (str_starts_with($code, 'pt')) {
                    return 'pt';
                }
            }
        }

        return 'en';
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function isPortuguese(): bool
    {
        return $this->lang === 'pt';
    }

    /**
     * Translate a key, falling back to English or key name.
     */
    public function t(string $key): string
    {
        if (isset(self::$dictionary[$this->lang][$key])) {
            return self::$dictionary[$this->lang][$key];
        }

        if (isset(self::$dictionary['en'][$key])) {
            return self::$dictionary['en'][$key];
        }

        return $key;
    }

    /**
     * Return all translations for client-side JS.
     *
     * @return array<string, string>
     */
    public function getAll(): array
    {
        return self::$dictionary[$this->lang] ?? self::$dictionary['en'];
    }
}
