---
phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
plan: 01
subsystem: auth
tags: [laravel, permissions, migration, clicksign, contratos, tailwind, react]

# Dependency graph
requires:
  - phase: 130-gerador-de-contratos-e-rede-de-seguranca-v22-0
    provides: "ContratoAssinatura::STATUS_* (7 estados), ContratosPresosService, ContratoLiberacaoManualController (backend definitivo absorvido pela D-10 em plano futuro)"
provides:
  - "Permission admin.contratos no catalogo (Permissions::isValid), concedida a todo role:admin via short-circuit de User::hasPermission() sem migration/seeder"
  - "config('services.clicksign.painel_url') derivada de CLICKSIGN_ENV, destino do CTA 'Registrar e ir para a Clicksign' do plano 131-05"
  - "3 colunas em contrato_assinaturas para o cancelamento solicitado (D-13): cancelamento_motivo, cancelamento_solicitado_por_user_id, cancelamento_solicitado_em"
  - "resources/js/lib/contratoStatus.js — modulo unico dos 7 rotulos/cores/formatacao 'ha N dias', consumido pelos planos 131-02 a 131-05"
affects: [131-02, 131-03, 131-04, 131-05, 131-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Permission key nova concedida a role:admin sem migration/seeder via short-circuit de hasPermission() (D-09)"
    - "Config derivada de env com fallback por ambiente (CLICKSIGN_ENV) em vez de valor hardcoded em JSX"
    - "Estado derivado de timestamp (cancelamento_solicitado_em) em vez de 8o valor de enum de status"
    - "Modulo JS compartilhado de mapa estado->rotulo/cor, espelho manual de constantes PHP (sem enum compartilhado PHP<->JS)"

key-files:
  created:
    - database/migrations/2026_08_16_100000_add_cancelamento_solicitado_to_contrato_assinaturas_table.php
    - tests/Feature/Phase131/MigrationFase131ConvencoesTest.php
    - resources/js/lib/contratoStatus.js
  modified:
    - app/Support/Permissions.php
    - config/services.php
    - .env.example
    - app/Models/ContratoAssinatura.php

key-decisions:
  - "D-09 aplicada literalmente: nenhuma migration/seeder para a permission — o short-circuit de User::hasPermission() ja concede admin.contratos a todo role:admin"
  - "D-13 aplicada literalmente: cancelamento solicitado e DERIVADO da presenca de cancelamento_solicitado_em, nunca um 8o valor de status (preserva as 7 contagens do resumo da D-04 e o indice unico de 'em andamento')"
  - "FK cancelamento_solicitado_por_user_id nomeada explicitamente ca_cancel_solic_user_fk (24 chars) — o nome autogerado pelo Laravel teria 65 caracteres e estouraria o limite de 64 do MariaDB (erro 1059)"
  - "ADM-03 registrada como ja cumprida nesta fase (D-12) — nao ha remocao de campo nesta task nem em nenhuma outra do plano 131-01"

patterns-established:
  - "Modulo de biblioteca JS sem import de lucide-react nem de Pages/ — cada tela importa seus proprios icones, o modulo so exporta dados/funcoes puras"

requirements-completed: [UI-05, CLICK-10, ADM-03]

# Metrics
duration: 40min
completed: 2026-08-14
---

# Phase 131 Plan 01: Fundacao — permission, migration do cancelamento e modulo dos 7 estados Summary

**Permission `admin.contratos` sem migration (short-circuit de `hasPermission()`), `config('services.clicksign.painel_url')` derivada de `CLICKSIGN_ENV`, migration aditiva com FK nomeada a mao para o cancelamento solicitado, e `resources/js/lib/contratoStatus.js` como fonte unica dos 7 rotulos/cores da D-04.**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-08-14 (sessao unica)
- **Completed:** 2026-08-14T16:38:15Z
- **Tasks:** 3/3
- **Files modified:** 8 (5 modificados, 3 criados)

## Accomplishments
- `Permissions::ADMIN_CONTRATOS` (`admin.contratos`) existe no catalogo, valida por `Permissions::isValid()`, e concedida a todo `role:admin` no dia do deploy sem gravar nada em `setor_permissoes` (D-09)
- `config('services.clicksign.painel_url')` aponta para o **painel** da Clicksign (nao a API), derivada do mesmo `CLICKSIGN_ENV` que decide `base_url`, com `CLICKSIGN_PAINEL_URL` documentada e sem valor no `.env.example`
- Migration aditiva `2026_08_16_100000_add_cancelamento_solicitado_to_contrato_assinaturas_table` cria `cancelamento_motivo` (text livre, sem `enum`), `cancelamento_solicitado_por_user_id` (FK `ca_cancel_solic_user_fk`, `nullable()` + `nullOnDelete()`) e `cancelamento_solicitado_em` (timestamp) — testada com `up()`/`rollback()`/`up()` real contra o MariaDB local, com a FK confirmada por reconsulta a `information_schema.KEY_COLUMN_USAGE`
- `ContratoAssinatura::STATUS_TODOS` permanece com exatamente 7 elementos — "cancelamento solicitado" e derivado de `cancelamento_solicitado_em`, nunca um 8o status
- `resources/js/lib/contratoStatus.js` exporta os 7 rotulos/cores da D-04 (redacao final do UI-SPEC), o estado so-de-exibicao `SEM_CONTRATO`/`SEM_CONTRATO_LABEL` fora do mapa dos 7, e `formatarHaDias()` com a pluralizacao pt-BR travada pelo UI-SPEC

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Declarar a permission `admin.contratos` e a URL do painel da Clicksign** - `4f32500a` (feat)
2. **Task 2: Migration aditiva do cancelamento solicitado (D-13) + fillable/casts** - `d9363f73` (feat)
3. **Task 3: Modulo unico dos 7 estados — rotulos, cores e "ha N dias"** - `f764c7d5` (feat)

**Plan metadata:** commit deste SUMMARY + STATE.md (a seguir)

## Files Created/Modified
- `app/Support/Permissions.php` - constante `ADMIN_CONTRATOS` + entrada no catalogo do grupo Administrativo
- `config/services.php` - chave `clicksign.painel_url` derivada de `CLICKSIGN_ENV`
- `.env.example` - `CLICKSIGN_PAINEL_URL` documentada, comentada, sem valor
- `database/migrations/2026_08_16_100000_add_cancelamento_solicitado_to_contrato_assinaturas_table.php` - 3 colunas aditivas do cancelamento solicitado (D-13)
- `app/Models/ContratoAssinatura.php` - `$fillable` + `$casts` das 3 colunas novas
- `tests/Feature/Phase131/MigrationFase131ConvencoesTest.php` - 10 testes de convencao de migration (enum, FK nullable+nullOnDelete, nome de FK <=64 chars, guardas `Schema::hasColumn`, `$fillable`, `STATUS_TODOS`)
- `resources/js/lib/contratoStatus.js` - `CONTRATO_STATUS_LABELS`, `CONTRATO_STATUS_CLS`, `SEM_CONTRATO`/`SEM_CONTRATO_LABEL`, `rotuloContrato()`, `classeContrato()`, `formatarHaDias()`

## Decisions Made
- Nenhuma decisao nova alem das ja travadas pelo CONTEXT/UI-SPEC — este plano e fundacao pura, seguiu D-09/D-13/D-04 literalmente
- FK nomeada `ca_cancel_solic_user_fk` (curta, 24 caracteres) em vez do nome autogerado do Laravel (65 caracteres, estouraria o limite de 64 do MariaDB)

## Deviations from Plan

None - plano executado exatamente como escrito. As tres tasks seguiram os analogos apontados pelo `131-PATTERNS.md` sem necessidade de ajuste estrutural.

## Issues Encountered

Nenhum problema tecnico. O primeiro `grep` de verificacao da Task 3 (`aguardando_administrativo` dentro da janela de 12 linhas apos `CONTRATO_STATUS_LABELS`) acusou falso positivo porque um comentario proximo repetia literalmente o identificador `CONTRATO_STATUS_LABELS`, criando uma segunda ocorrencia que estendia a janela do `grep -A 12` ate a linha de `SEM_CONTRATO`. Corrigido reescrevendo o comentario para nao repetir o identificador ("no mapa de rotulos acima" em vez do nome literal) — mesmo criterio de aceitacao, sem mudanca de comportamento do modulo. Mesma rodada, tambem removida a mencao a `lucide-react` do comentario de topo (o criterio exige zero ocorrencias da string no arquivo, incluindo comentarios).

## ADM-03 — Status desta fase

**ADM-03 ja esta cumprida — nenhum trabalho de remocao foi feito neste plano nem esta previsto em nenhuma task do 131-01.** Conforme a D-12 do `131-CONTEXT.md`: o campo correto (`companies.email_colaborador`) ja saiu do formulario do Comercial na quick task `260805-eqk` e segue editavel em `/companies` desde entao — a janela de risco que a ADM-03 existia para evitar ("ninguem consegue cadastrar o dado") nunca se abriu. O `mlb_implementacoes.gmail_colaborador` do Polos **nao foi tocado** — e outro campo, de outro fluxo, para um servico isento de contrato (D9 da milestone). Nenhum arquivo `NovaEmpresa.jsx` foi modificado por este plano.

## User Setup Required

None - nao ha configuracao de servico externo necessaria. `CLICKSIGN_PAINEL_URL` e opcional (tem default seguro por ambiente); se algum dia precisar de valor customizado, e so preencher no `.env` local ou nas variaveis do servidor — nunca no `.env.example`.

## Next Phase Readiness

Fundacao completa para os 5 planos seguintes da Fase 131:
- `admin.contratos` pronta para ser usada como middleware `permission:` nas rotas novas (plano 131-03) — **nunca** `role:admin`
- `config('services.clicksign.painel_url')` pronta para ser passada como prop ao `Admin/ContratoDetalhe.jsx` (plano 131-05), nunca hardcoded no JSX
- As 3 colunas do cancelamento solicitado existem em producao-equivalente (testado local) para a action de "Registrar cancelamento" (plano 131-05)
- `resources/js/lib/contratoStatus.js` pronto para import em `Admin/Contratos.jsx`, `Admin/ContratoDetalhe.jsx` e o badge de `Comercial/EmpresasListagem.jsx` (planos 131-02 a 131-05) — nenhum dos tres deve redeclarar o mapa de estados

Nenhum bloqueio identificado.

---
*Phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer*
*Completed: 2026-08-14*

## Self-Check: PASSED

Todos os arquivos criados confirmados em disco e todos os 3 commits de task confirmados em `git log --oneline --all`.
