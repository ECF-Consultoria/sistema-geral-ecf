---
phase: 171-acervo-navegavel-e-reuso-das-imagens-ja-geradas
plan: 01
subsystem: api
tags: [publicador, creative-engine, laravel, eloquent, sqlite-tests]

# Dependency graph
requires:
  - phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro
    provides: "PublicadorCriativoAprovacaoService::aprovarSlot() (molde de reaproveitar()), ImagemAssetService::receber(), disciplina D-13 (id numérico, 404 nunca 403)"
  - phase: 160-162
    provides: "ml_anuncio_criativos com company_id/mlb_empresa_id (cascadeOnDelete) e pub_rascunho_id (nullOnDelete) — a âncora de conta que o acervo usa"
provides:
  - "PublicadorAcervoService::listar()/reaproveitar() — a query escopada por conta e a cópia que revalida"
  - "MlbPublicadorAcervoController — 3 rotas (listar/imagem/usar) em mlb.anuncios.publicador.acervo.*"
  - "Prova por teste de que creative:limpar-referencias nunca apaga a imagem GERADA"
affects: [171-02-frontend-do-acervo]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Escopo de acervo por company_id/mlb_empresa_id do produto AUTORIZADO, nunca por pub_rascunho_id — permite cruzar rascunhos (inclusive órfãos) da mesma conta sem vazar entre contas"
    - "Reaproveitamento = CÓPIA via ImagemAssetService::receber() (molde literal de aprovarSlot()), nunca referência compartilhada — a cópia revalida ValidadorImagem::problemas() de novo"

key-files:
  created:
    - app/Services/Publicador/Criativos/PublicadorAcervoService.php
    - app/Http/Controllers/MlbPublicadorAcervoController.php
    - tests/Feature/Phase171/MlbPublicadorAcervoControllerTest.php
    - tests/Feature/Phase171/AcervoNuncaApagaPelaLimpezaDeReferenciasTest.php
  modified:
    - routes/mlb_anuncios.php

key-decisions:
  - "Zero migration: a âncora de conta já existe em ml_anuncio_criativos desde a Fase 160"
  - "Nenhum MlToken criado nos testes — ImagemAssetService::enviarAoMl() devolve antes de qualquer HTTP quando contaOuNula() é null, então Http::preventStrayRequests() garante por teste que nenhuma chamada real ao ML acontece"
  - "Teste do kit legado de 7 slots usa um MlAnuncioCriativoKit real (FK de kit_id exige linha existente) em vez de um id solto"

patterns-established:
  - "criativoDaConta() — escopo de item do acervo por conta do produto autorizado, nunca por pub_rascunho_id, com 404 sempre (nunca 403) quando o id existe mas é de outra conta"

requirements-completed: [ACERVO-01, ACERVO-02, ACERVO-03, ACERVO-04, ACERVO-05]

# Metrics
duration: ~55min
completed: 2026-10-08
---

# Fase 171 Plano 01: O acervo no servidor — Summary

**API de acervo (listar/imagem/usar) que lista as imagens já geradas por conta (inclusive de rascunhos apagados), serve o binário escopado por conta e copia uma imagem para o rascunho atual via o mesmo caminho de aprovação já existente, sem gastar nenhuma chamada nova ao provedor de IA.**

## Performance

- **Duration:** ~55 min
- **Tasks:** 2/2 completos
- **Files modified:** 5 (3 novos, 1 editado, 1 editado em rotas)

## Accomplishments

- `PublicadorAcervoService::listar()` — query escopada por `company_id`/`mlb_empresa_id` do `PubProduto` autorizado, com/sem `toda_conta`, incluindo criativos órfãos (`pub_rascunho_id` nulo) quando `toda_conta=1`.
- `PublicadorAcervoService::reaproveitar()` — copia os bytes da imagem gerada para uma `PubImagem` NOVA do rascunho atual via `ImagemAssetService::receber()` (revalida `ValidadorImagem::problemas()`, inclusive V-IMG-03), sem nunca tocar o criativo de origem.
- `MlbPublicadorAcervoController` com 3 rotas novas (`mlb.anuncios.publicador.acervo.listar/imagem/usar`), mesma disciplina D-13 (`{criativo}` numérico, 404 nunca 403) do `MlbPublicadorCriativoController`, mas escopada pela CONTA em vez do rascunho.
- 14 testes novos provando por execução (não por leitura de código): escopo com/sem `toda_conta`, isolamento entre contas (mesmo por enumeração de id), criativo órfão sem nome de produto, cópia que nunca altera a origem, revalidação de tamanho mínimo na cópia, grupo forjado, rascunho intocável, chave desligada, idempotência ao reaproveitar duas vezes e kit legado de 7 slots.
- Confirmado por teste que `creative:limpar-referencias` (dry-run e execução real) nunca toca `imagem_path`/`imagem_mime`/`imagem_bytes` — só as referências.

## Task Commits

1. **Task 1: Serviço, controller e rotas do acervo** - `41fd4f7d` (feat)
2. **Task 2: Testes de escopo, cópia, revalidação e regressão de retenção** - `fe729f88` (test)

**Plan metadata:** (este commit, docs — ver STATE.md/ROADMAP.md)

## Files Created/Modified

- `app/Services/Publicador/Criativos/PublicadorAcervoService.php` - `listar()` (query por conta) e `reaproveitar()` (cópia via `ImagemAssetService::receber()`)
- `app/Http/Controllers/MlbPublicadorAcervoController.php` - `listar()`/`imagem()`/`usar()`, escopo por `criativoDaConta()` (nunca por `pub_rascunho_id`)
- `routes/mlb_anuncios.php` - grupo `acervo` irmão de `criativos`/`identidade`, dentro de `publicador/produtos/{produto}` (já `role:admin`)
- `tests/Feature/Phase171/MlbPublicadorAcervoControllerTest.php` - 12 casos (escopo, órfão, 404 nunca 403, cópia, revalidação, grupo forjado, intocável, chave desligada, idempotência, kit legado)
- `tests/Feature/Phase171/AcervoNuncaApagaPelaLimpezaDeReferenciasTest.php` - dry-run e execução real de `creative:limpar-referencias` nunca tocam a imagem gerada

## Decisions Made

- Nenhuma migration: a âncora de conta (`company_id`/`mlb_empresa_id`) já existe em `ml_anuncio_criativos` desde a Fase 160; "produto de origem" é resolvido ao vivo por `pub_rascunho_id → pub_rascunhos.produto_id`.
- Testes sem `MlToken`: como nenhuma conta de teste tem token ativo, `PubProduto::contaOuNula()` devolve `null` e `ImagemAssetService::enviarAoMl()` retorna ANTES de qualquer chamada HTTP — `Http::preventStrayRequests()` prova isso por teste, sem precisar de `Http::fake()` com respostas simuladas.
- O teste de "kit legado de 7 slots" cria um `MlAnuncioCriativoKit` de verdade (a FK de `kit_id` em `ml_anuncio_criativos` exige uma linha existente) em vez de um id solto — mais fiel ao dado real de produção também.

## Deviations from Plan

None - plano executado exatamente como escrito. O único ajuste foi de implementação de teste (ver "Issues Encountered" abaixo), não uma mudança de comportamento do código de produção.

## Issues Encountered

- Primeira tentativa do teste "kit legado de 7 slots" usou `kit_id = 999` sem criar a linha correspondente em `ml_anuncio_criativo_kits`, violando a FK no SQLite (`FOREIGN KEY constraint failed`). Corrigido criando um `MlAnuncioCriativoKit` real antes dos 7 slots — sem impacto no código de produção, só no setup do teste.

## Verificação por teste (conforme pedido no prompt)

- **(a) Nenhum token em JSON:** todo payload de `listar()`/`usar()` só expõe `id` (int), `imagem_url` (rota por id), `rotulo`, `criado_em`, `aprovada`, `do_produto_atual`, `produto_nome`, `imagem_id` (string numérica) e `repetida` — nenhuma chave devolve `token`. O endereçamento de `{criativo}` na rota é sempre numérico (`whereNumber('criativo')`); nenhum teste gera nem asserta um token de 32 caracteres nesta fase (D-13 já provado pela suíte da Fase 165 para o resto do Creative Engine; aqui a prova é por construção do contrato — ver `PublicadorAcervoService::paraItem()`).
- **(b) Conta A nunca vê conta B:** `test_listar_com_toda_conta_mostra_outro_produto_da_mesma_conta_mas_nunca_de_outra_conta` e `test_imagem_de_criativo_de_outra_conta_devolve_404` — o item da conta B nunca aparece na listagem (mesmo com `toda_conta=1`) e o binário de um criativo da conta B pedido a partir de um produto da conta A devolve 404 (nunca 403).
- **(c) A cópia revalida dimensão:** `test_usar_com_imagem_pequena_demais_recusa_e_nao_cria_pub_imagem` — uma imagem gerada de 10×10px é recusada com a mensagem de mínimo 500px, e nenhuma `PubImagem` nova é criada.
- **(d) Kit legado de 7 continua legível:** `test_kit_legado_de_7_slots_aparece_na_listagem_sem_erro` — 7 slots do mesmo kit aparecem todos na listagem, sem erro.
- **(e) Órfão aparece sem nome de produto e sem lançar:** `test_criativo_orfao_so_aparece_com_toda_conta_e_sem_nome_de_produto` — o criativo com `pub_rascunho_id = null` não aparece sem `toda_conta`, aparece com `toda_conta=1` e seu `produto_nome` é `null`.

## Resultado das suítes (saída real)

- `tests/Feature/Phase171/` — **14 testes, 61 assertions, OK** (14/14 passam).
- `tests/Feature/Publicador` + `tests/Unit/Publicador` (baseline) — **819 testes, 4106 assertions, OK** — sem regressão.
- `tests/Feature/Phase160,161,162,165,169` + `tests/Unit/Phase160,161,162,165,168,169` (Creative Engine) — **377 testes, 1725 assertions, 1 incomplete, OK** — sem regressão (o incomplete é pré-existente, não introduzido por este plano).

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Nenhuma migration, nenhuma dependência nova.

## Next Phase Readiness

- A API do acervo está pronta para o `171-02` (frontend) consumir: `GET .../acervo` (listar, com `toda_conta`), `GET .../acervo/{criativo}/imagem` (binário) e `POST .../acervo/{criativo}/usar` (reaproveitar, com `grupo` no corpo).
- Nenhum bloqueio conhecido. `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx`, `Editor.jsx`, `EtapaImagens.jsx` e `apoio.js` não foram tocados (D-08), como exigido — ficam para o `171-02`.

---
*Phase: 171-acervo-navegavel-e-reuso-das-imagens-ja-geradas*
*Completed: 2026-10-08*

## Self-Check: PASSED

Todos os 5 arquivos listados em `key-files` confirmados em disco; os 2 commits (`41fd4f7d`, `fe729f88`) confirmados em `git log`.
