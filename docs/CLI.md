# Manual das Ferramentas de Linha de Comando (`fux`)

> [!NOTE]
> O Fuxico oferece duas implementações de cliente de terminal com paridade funcional total:
> 1. **Cliente Nativo em Rust (`fux-cli`):** Binário standalone pronto para uso e sem precisar de bibliotecas extras, disponível para x86_64, aarch64, armv7, armv6, macOS e Windows.
> 2. **Cliente Universal em Bash (`fux`):** Script portátil sem dependências externas além de `bash` e `curl`, pronto para rodar em qualquer sistema Unix (Linux, macOS, BSD, Termux no Android).

---

## 1. A Regra de Ouro da Ergonomia Unix

> [!IMPORTANT]
> **Deboche e telemetria vão para o `stderr`. A URL limpa vai para o `stdout`.**

Graças a essa garantia arquitetural, o comando `fux` pode ser encadeado em qualquer pipeline shell, script automatizado ou comando de clipboard sem risco de quebrar o fluxo:

```bash
# Copia diretamente a URL gerada para a área de transferência:
fux screenshot.png --copia

# Armazena a URL limpa diretamente em uma variável de ambiente:
URL=$(fux relatorio.pdf --caladinho)

# Envia a saída de um comando via pipe para uma queima-de-arquivo imediata:
journalctl -n 50 | fux -q | wl-copy
```

---

## 2. Tabela de Opções e Flags Bilíngues (EN / PT-BR)

O Fuxico disponibiliza opções totalmente bilíngues:

| Flag Curta | Flag Longa (EN) | Flag Longa (PT-BR) | Descrição |
| :--- | :--- | :--- | :--- |
| `-e <DUR>` | `--expire <DUR>` | `--caduca <DUR>` | Duração de retenção (ex: `10m`, `2h`, `7d`, ou inteiro em horas). |
| `-q`, `-o` | `--oneshot` | `--queima` | Queima-de-arquivo: apaga o arquivo imediatamente após o 1º download. |
| `-s` | `--secret` | `--segredo` | Gera um slug criptográfico de 16 caracteres com alta entropia. |
| `-n <NOME>`| `--name <NOME>` | `--nome <NOME>` | Define um apelido customizado para a rota (ex: `/<slug>/<nome>`). |
| `-u <URL>` | `--url <URL>` | `--encurta <URL>` | Encurta a URL de destino informada e retorna o link curto. |
| `-c` | `--clipboard` | `--copia` | Copia automaticamente a URL de saída para a área de transferência. |
| `-Q` | `--quiet` | `--calado`, `--caladinho` | Silencia toda a telemetria do `stderr`, emitindo apenas a URL pura. |
| `-l` | `--list` | `--lista` | Lista todas as fofocas e arquivos ativos na instância. |
| `-d <SLUG>`| `--delete <SLUG>`| `--apaga <SLUG>` | Apaga o item informado utilizando a ficha de gestão (`X-Token`). |
| `-t <TOK>` | `--token <TOK>` | `--ficha <TOK>` | Token de autorização mestre ou token de gestão (`X-Token`). |
| `-S <URL>` | `--server <URL>` | `--servidor <URL>` | Define o endereço do servidor (padrão: `https://fuxico.2lp.in`). |
| `-D <DIAL>`| `--dialect <DIAL>`| `--dialeto <DIAL>` | Dialeto do protocolo: `nullpointer` (0x0.st) ou `rustypaste`. |
| `-g` | `--gist` | `--gist` | Modo gist: agrupa múltiplos arquivos em uma publicação conjunta. |

---

## 3. Subcomandos Especiais

### 3.1 Publicação de Gists Multi-Arquivo
```bash
# Publica múltiplos arquivos em um único gist com abas de código:
fux gist script.py README.md style.css

# Com expiração e cópia automática:
fux gist main.rs config.toml --caduca 48h --copia
```

### 3.2 Criação Rápida de Repositórios Git Efêmeros
```bash
# Cria um repositório git temporário e já configura o remote local 'fuxico':
fux git init meu-projeto

# Envia seus commits imediatamente via Git HTTP Smart Protocol:
git push -u fuxico main
```

---

## 4. Arquivo de Configuração Unificado (`~/.config/fux/config.toml`)

Ambos os clientes (Rust e Bash) compartilham o mesmo arquivo de configuração local, permitindo definir servidores, fichas e dialetos padrão:

```toml
# Endereço do servidor Fuxico
server = "https://fuxico.2lp.in"

# Token mestre para instâncias privadas (opcional)
# token = "sua-ficha-aqui"

# Dialeto de comunicação padrão (nullpointer ou rustypaste)
dialect = "nullpointer"

# Tempo padrão de retenção
expires = "24h"

# Queima-de-arquivo desativada por padrão
oneshot = false

# Copiar automaticamente para o clipboard
clipboard = true
```
