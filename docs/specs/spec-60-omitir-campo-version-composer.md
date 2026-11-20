# Especificação do Ticket #60: Omitir Campo Version Estático Inválido em composer.json

## 1. Contexto e Motivação
A documentação oficial do Composer v2 estipula que a chave `"version"` em `composer.json` deve ser omitida para projetos gerenciados por sistemas de controle de versão (VCS como Git), cabendo ao Composer derivar as versões a partir de branches e tags Git.
A introdução acidental de `"version": "0.3.0-dev.131+20261024.ee18962"` violou o esquema JSON restrito validado pelo comando `composer validate`, falhando a esteira de CI no Forgejo Actions.

## 2. Requisitos da Correção
1. Em `composer.json`:
   - Remover a chave `"version"`.
2. Em `.gost/rules.toml`:
   - Incluir o bypass de CI hermética na regra `KOLLONTAI-018`.
3. Validar localmente com `composer validate` (deve retornar código 0 / válido).
4. `cccp check --strict` deve se manter em 100% de conformidade.

## 3. Árvore de Decisões e Registro Arquitetural (para a Natasha)
1. **Omissão da Chave vs Ajuste de Regex no Version:**
   - *Decisão:* Omitir a chave `"version"` no `composer.json` segue as melhores práticas da comunidade PHP e do Composer oficial, garantindo compatibilidade sem conflito com o SemVer dinâmico das tags git e do arquivo canônico `VERSION`.
