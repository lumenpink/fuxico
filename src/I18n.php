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
            'brand_sub' => 'Envios efêmeros e links curtos',
            'brand_sub_line1' => 'Envios efêmeros',
            'brand_sub_line2' => 'e links curtos',
            'cultural_badge' => '🏳️‍⚧️ Fervo & tecnologia travesti latina',
            'drop_idle' => 'Larga a pedrada aqui.',
            'drop_sub' => 'ou clique para catar o babado nos arquivos',
            'drop_drag' => 'Pode largar que a gente espalha!',
            'btn_upload' => 'Solta o verbo ⚡',
            'btn_uploading' => 'Espalhando o bafão...',
            'tab_file' => 'Arquivo',
            'tab_url' => 'Encurtar URL',
            'tab_dash' => 'Fofocas no Ar',
            'opt_burn' => 'Queima de arquivo / Desaquenda (some no 1º download)',
            'opt_secret' => 'Aquendar fofoca (slug cifrado de 16 caracteres)',
            'opt_doc' => 'Renderizar Markdown como Página (Fuxico Doc)',
            'opt_expires' => 'Validade da fofoca:',
            'exp_none' => 'Até alguém descobrir (sem expiração)',
            'exp_1h' => '1 hora de fofoca quente',
            'exp_24h' => '24 horas',
            'exp_7d' => '7 dias',
            'exp_30d' => '30 dias',
            'custom_name_placeholder' => 'Apelido do babado (opcional)',
            'url_placeholder' => 'https://exemplo.com/babado-forte',
            'btn_shorten' => 'Encurtar fofoca ⚡',
            'token_label' => 'Senha da fofoca (Token de Autorização):',
            'token_placeholder' => 'token ou chave mestra...',
            'token_public' => 'Modo público ativo: qualquer um pode fuxicar sem token!',
            'stat_files' => 'Arquivos no Ar',
            'stat_urls' => 'Links Espalhados',
            'stat_bytes' => 'Volume da Fofoca',
            'stat_views' => 'Bisbilhotadas',
            'dash_search' => 'Buscar fofoca por slug ou nome...',
            'dash_filter_all' => 'Tudo que tá rolando',
            'dash_filter_files' => 'Só arquivos',
            'dash_filter_urls' => 'Só links encurtados',
            'dash_filter_docs' => 'Só documentos Markdown',
            'dash_filter_gists' => 'Só Gists',
            'dash_filter_gits' => 'Só Repositórios Git',
            'opt_gist' => 'Empacotar como Gist (múltiplos arquivos)',
            'tip_gist' => 'Empacota múltiplos arquivos em um Gist com abas, preview de README e download em ZIP.',
            'tab_gist' => 'Gist',
            'opt_git' => 'Repositório Git efêmero',
            'tip_git' => 'Cria repositório Git com Git HTTP Smart Protocol, clone, push e baking estático.',
            'tab_git' => 'Git',
            'dash_empty' => 'Nenhum babado encontrado no cofre.',
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
            'painel_title' => 'PAINEL INTERNO // COFRE DE FOFOCAS',
            'painel_login_title' => 'Área Restrita // Identifique-se, comadre',
            'painel_login_desc' => 'Informe o token mestre de autorização para abrir o cofre de arquivos.',
            'painel_login_btn' => 'Destrancar Cofre ➔',
            'painel_logout_btn' => 'Sair do Cofre ✖',
            'painel_back_home' => '← Soltar Fofoca (Home)',
            'dl_title' => 'Fuxico | Baixar o babado',
            'dl_subtitle' => 'Alguém mandou esse babado pra você.',
            'dl_btn' => 'Baixar o babado 📦',
            'dl_warning_burn' => '⚠️ Aviso: assim que o download terminar, esse arquivo evapora do servidor.',
            'err_404_title' => 'Essa fofoca já prescreveu',
            'err_404_desc' => 'Essa fofoca já prescreveu (ou o link foi digitado errado).',
            'err_410_title' => 'Tarde demais. Esse segredo já foi recolhido.',
            'err_410_desc' => 'Esse pacote era queima-de-arquivo e já evaporou depois do primeiro acesso.',
            'back_home' => 'Voltar pro início e fuxicar outra coisa',
            'copy_success' => 'Babado copiado! Vai lá espalhar o bafão.',
            'delete_confirm' => 'Tem certeza que quer desaquendar e queimar esse babado pra sempre?',
            'footer_credit_prefix' => 'Orgulhosamente produzido por uma',
            'footer_travesti_text' => 'travesti latina',
            'footer_travesti_link' => 'https://pt.wikipedia.org/wiki/Travesti_(identidade_de_g%C3%AAnero)',
            'tip_burn' => '🏳️‍⚧️ No Pajubá: Desaquenda é soltar, revelar. ⚡ Técnico: O arquivo evapora permanentemente do disco imediatamente após o primeiro download.',
            'tip_secret' => '🏳️‍⚧️ No Pajubá: Aquendar é guardar, esconder e proteger a sete chaves. ⚡ Técnico: Gera uma URL com slug cifrado de 16 caracteres de alta entropia para impedir que curiosos adivinhem ou escaneiem o link.',
            'tip_doc' => '⚡ Técnico: Compila Markdown para HTML estático standalone com skins Trava Zine e Acadêmico Depressivo (?acad=true).',
            'tip_expires' => 'Tempo de retenção do babado antes de prescrever e ser purgado automaticamente do cofre.',
            'tip_custom_name' => 'Defina um apelido personalizado para a URL (ex: fuxico.cc/meu-babado) em vez de um código gerado aleatoriamente.',
            'tip_token' => 'Chave de autorização para fiscalizar o cofre no painel e subir arquivos em instâncias privadas.',
            'tip_shorten_url' => 'Cole qualquer link longo da web para encurtar e espalhar.',
            'tab_fuxiqueiras' => 'Fuxiqueiras & Fichas',
            'tab_fofocas' => 'Fofocas & Arquivos',
            'role_admin' => 'Fuxiqueira-mor (Admin)',
            'role_member' => 'Fuxiqueira (Membra)',
            'col_author' => 'Autora',
            'author_anon' => 'Anônima / Mestre',
            'btn_new_user' => '+ Nova Fuxiqueira',
            'users_title' => 'Gestão de Fuxiqueiras & Fichas de Acesso',
            'users_empty' => 'Nenhuma fuxiqueira cadastrada ainda.',
            'col_username' => 'Fuxiqueira',
            'col_token' => 'Ficha (Token)',
            'col_role' => 'Papel',
            'col_created' => 'Cadastrada em',
            'act_regen_token' => 'Regenerar Ficha',
            'regen_confirm' => 'Deseja regenerar a ficha de acesso desta fuxiqueira? O token anterior deixará de funcionar imediatamente.',
            'delete_user_confirm' => 'Tem certeza que deseja desaquendar e remover esta fuxiqueira do fuxico? As fofocas dela permanecerão no cofre como anônimas.',
            'modal_new_user_title' => 'Cadastrar Nova Fuxiqueira',
            'username_label' => 'Apelido da Fuxiqueira:',
            'username_placeholder' => 'ex: babado_forte',
            'token_label' => 'Ficha / Senha (opcional):',
            'token_placeholder' => 'Deixe em branco para gerar aleatória ou digite sua chave',
            'role_label' => 'Papel no Fuxico:',
            'btn_create_user' => 'Emitir Ficha ⚡',
            'user_created_success' => 'Fuxiqueira criada com sucesso! Copie a ficha de acesso:',
            'regen_prompt' => 'Digite a nova ficha/senha para a fuxiqueira (ou deixe em branco para sortear uma aleatória):',
        ],
        'en' => [
            'app_name' => 'FUXICO',
            'tagline' => 'Word-of-mouth street mischief',
            'brand_sub' => 'Ephemeral send & short links',
            'brand_sub_line1' => 'Ephemeral send',
            'brand_sub_line2' => '& short links',
            'cultural_badge' => '🏳️‍⚧️ Travesti latina technology & riot',
            'drop_idle' => 'Spill the beans here.',
            'drop_sub' => 'or click to browse your scoops',
            'drop_drag' => "Let it go — we'll spread the word!",
            'btn_upload' => 'Spill the beans ⚡',
            'btn_uploading' => 'Spreading the scoop...',
            'tab_file' => 'File',
            'tab_url' => 'Shorten URL',
            'tab_dash' => 'Active Scoops',
            'opt_burn' => 'Burn after reading / Desaquenda (destructs on 1st download)',
            'opt_secret' => 'Aquendar / Cipher (16-char secret slug)',
            'opt_doc' => 'Bake Markdown as Web Page (Fuxico Doc)',
            'opt_expires' => 'Gossip retention:',
            'exp_none' => 'Until discovered (permanent)',
            'exp_1h' => '1 hour of hot tea',
            'exp_24h' => '24 hours',
            'exp_7d' => '7 days',
            'exp_30d' => '30 days',
            'custom_name_placeholder' => 'Custom scoop alias (optional)',
            'url_placeholder' => 'https://example.com/spicy-leak',
            'btn_shorten' => 'Shorten scoop ⚡',
            'token_label' => 'Gossip Passphrase (Auth Token):',
            'token_placeholder' => 'token or master key...',
            'token_public' => 'Public mode active: anyone can spill the beans!',
            'stat_files' => 'Live Files',
            'stat_urls' => 'Spread Links',
            'stat_bytes' => 'Gossip Weight',
            'stat_views' => 'Total Snoops',
            'dash_search' => 'Search scoop by slug or original name...',
            'dash_filter_all' => 'Everything circulating',
            'dash_filter_files' => 'Files only',
            'dash_filter_urls' => 'URLs only',
            'dash_filter_docs' => 'Markdown docs only',
            'dash_filter_gists' => 'Gists only',
            'dash_filter_gits' => 'Git repos only',
            'opt_gist' => 'Package as Gist (multi-file)',
            'tip_gist' => 'Packages multiple files into a tabbed Gist with README preview and ZIP download.',
            'tab_gist' => 'Gist',
            'opt_git' => 'Ephemeral Git Repository',
            'tip_git' => 'Creates a Git repository with Git HTTP Smart Protocol, clone, push, and static baking.',
            'tab_git' => 'Git',
            'dash_empty' => 'No scoops found in the vault.',
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
            'painel_title' => 'INTERNAL VAULT // SNOOP DASHBOARD',
            'painel_login_title' => 'Restricted Area // Identify yourself, comrade',
            'painel_login_desc' => 'Provide the master authorization token to unlock the file vault.',
            'painel_login_btn' => 'Unlock Vault ➔',
            'painel_logout_btn' => 'Exit Vault ✖',
            'painel_back_home' => '← Spill Scoop (Home)',
            'dl_title' => 'Fuxico | Download the scoop',
            'dl_subtitle' => 'Someone passed this scoop along to you.',
            'dl_btn' => 'Download the scoop 📦',
            'dl_warning_burn' => '⚠️ Warning: once this download finishes, this file evaporates from the server.',
            'err_404_title' => 'This gossip already expired',
            'err_404_desc' => 'This gossip already expired (or the link was mistyped).',
            'err_410_title' => 'Too late. Somebody already snooped through this package.',
            'err_410_desc' => 'This was a burn-after-reading package and has already evaporated from the server.',
            'back_home' => 'Back to home to spill something else',
            'copy_success' => 'Link copied! Go out there and spread the scoop.',
            'delete_confirm' => 'Are you sure you want to burn this scoop forever?',
            'footer_credit_prefix' => 'Proudly produced by a',
            'footer_travesti_text' => 'travesti latina',
            'footer_travesti_link' => 'https://en.wikipedia.org/wiki/Travesti_(gender_identity)',
            'tip_burn' => '🏳️‍⚧️ In Pajubá: Desaquenda means to release or reveal. ⚡ Technical: The file evaporates permanently from the disk immediately after the first download.',
            'tip_secret' => '🏳️‍⚧️ In Pajubá: Aquendar means to hide, conceal, or closely guard. ⚡ Technical: Generates a 16-character high-entropy secret URL slug, preventing brute-force enumeration attacks.',
            'tip_doc' => '⚡ Technical: Bakes Markdown into static standalone HTML with Trava Zine and Depressive Academic skins (?acad=true).',
            'tip_expires' => 'Retention lifespan before the scoop expires and is automatically purged from the vault.',
            'tip_custom_name' => 'Set a custom URL alias (e.g. fuxico.cc/my-scoop) instead of an auto-generated random slug.',
            'tip_token' => 'Authorization token required to inspect the vault and upload items on private instances.',
            'tip_shorten_url' => 'Paste any long web address to shorten and spread.',
            'tab_fuxiqueiras' => 'Snoopers & Tokens',
            'tab_fofocas' => 'Scoops & Files',
            'role_admin' => 'Chief Snooper (Admin)',
            'role_member' => 'Snooper (Member)',
            'col_author' => 'Author',
            'author_anon' => 'Anonymous / Master',
            'btn_new_user' => '+ New Snooper',
            'users_title' => 'Manage Snoopers & Access Tokens',
            'users_empty' => 'No snoopers registered yet.',
            'col_username' => 'Snooper',
            'col_token' => 'Token',
            'col_role' => 'Role',
            'col_created' => 'Registered At',
            'act_regen_token' => 'Regen Token',
            'regen_confirm' => 'Regenerate this snooper token? The previous token will immediately stop working.',
            'delete_user_confirm' => 'Are you sure you want to remove this snooper? Their scoops will remain in the vault as anonymous.',
            'modal_new_user_title' => 'Register New Snooper',
            'username_label' => 'Snooper Nickname:',
            'username_placeholder' => 'e.g. spicy_snooper',
            'token_label' => 'Token / Password (optional):',
            'token_placeholder' => 'Leave blank to generate randomly or type custom key',
            'role_label' => 'Role in Fuxico:',
            'btn_create_user' => 'Issue Token ⚡',
            'user_created_success' => 'Snooper registered successfully! Copy access token:',
            'regen_prompt' => 'Enter new token/password for this snooper (or leave blank to generate randomly):',
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
