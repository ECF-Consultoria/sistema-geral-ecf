---
phase: 160-fatia-fina-ponta-a-ponta-um-criativo-real-do-upload-aprova-o
plan: 01
subsystem: ml-publicador
tags: [laravel, inertia, react, creative-engine, upload, storage-privado]

requires: []
provides:
  - "Tabela única `ml_anuncio_criativos` (par referência→imagem do Creative Engine)"
  - "Model `MlAnuncioCriativo` com estados, trava anti-loop por tempo (LIMITE_MINUTOS) e relações"
  - "`CreativeEngineAtivo` — leitor da chave liga/desliga `creative_engine_ativo` em `configuracoes` (OPS-03)"
  - "`ReferenciaEfemeraService` — staging da foto de referência em disco privado (guardar/bytesDe/apagar)"
  - "Rotas `mlb.anuncios.criativo.referencia` (POST) e `mlb.anuncios.criativo.referencia.ver` (GET)"
  - "`PainelCriativosIa.jsx` montado na etapa 5 do wizard, atrás da prop `creativeAtivo`"
affects: ["160-02", "160-03", "160-04"]

tech-stack:
  added: []
  patterns:
    - "Leitor de feature flag em `configuracoes` memoizado por instância (molde FechamentoRegraTabela)"
    - "Diretório por registro em disco privado (`creative-referencias/{token}/`), nunca caminho compartilhado"

key-files:
  created:
    - database/migrations/2026_10_02_120000_create_ml_anuncio_criativos_table.php
    - app/Models/MlAnuncioCriativo.php
    - app/Services/Creative/CreativeEngineAtivo.php
    - app/Services/Creative/ReferenciaEfemeraService.php
    - resources/js/Pages/Mlb/components/PainelCriativosIa.jsx
    - tests/Feature/Phase160/CriativoReferenciaTest.php
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - routes/mlb_anuncios.php
    - resources/js/Pages/Mlb/AnunciarML.jsx

key-decisions:
  - "UMA tabela `ml_anuncio_criativos` (pt-BR, prefixo ml_), não um par de tabelas 'projeto'+'asset' em inglês — nesta fase existe exatamente uma imagem por pedido, e uma tabela 'projeto' teria sempre uma filha só com colunas de agregação fixas em 1/0/0 por construção. O kit de 7 (Fase 161) entra como tabela nova + FK anulável aqui, migration aditiva."
  - "Retenção da foto de referência em duas camadas (deleção pelo dono no estado terminal + varredura diária por idade do REGISTRO, nunca do arquivo) — desenhada aqui porque o schema depende dela, implementada no plano 160-04. Defesa direta contra o incidente do ECF Drive (2026-09-14): diretório por criativo (nunca compartilhado) e exclusão/consumo lendo a mesma fonte (a linha do banco)."
  - "A chave operacional de liga/desliga é o registro em `configuracoes`, não o `.env` — `CREATIVE_ENGINE_ENABLED` fica só como default de ambiente quando ainda não existe registro."

requirements-completed: [FOTO-01, FOTO-02, FOTO-04, FOTO-05, OPS-03, APROV-06]

duration: ~20min
completed: 2026-10-02
---

# Phase 160 Plan 01: Fatia fina — upload da foto de referência Summary

**Upload de foto de produto para disco privado atrás de uma chave liga/desliga sem deploy, atravessando UI → rota → controller → disco → tabela — primeira fatia vertical do Creative Engine.**

## Performance

- **Duration:** ~20 min
- **Completed:** 2026-10-02
- **Tasks:** 3/3 completas
- **Files modified:** 9 (6 criados, 3 modificados)

## Accomplishments

- Tabela única `ml_anuncio_criativos` criada sem `enum`, com todas as FKs anuláveis explícitas e índices nomeados à mão (evitando os dois erros de migration já vividos neste projeto no MariaDB: 1830 e 1059).
- `CreativeEngineAtivo` liga/desliga o módulo gravando em `configuracoes`, sem deploy e sem `config:cache` — confirmado pelo teste que a rota responde 404 puro com a chave ausente.
- `ReferenciaEfemeraService` grava cada foto em `creative-referencias/{token}/{indice}.{ext}` (disco `local`, privado), nunca bytes/base64 no banco.
- Controller faz o double-check de empresa (cópia literal do bloco já usado em `uploadImagem()`) e deriva `company_id`/`mlb_empresa_id`/`user_id` do rascunho e do usuário autenticado — nunca do corpo da requisição.
- `PainelCriativosIa.jsx` monta na etapa 5 do wizard atrás da prop `creativeAtivo` (vinda do servidor); com a chave desligada não renderiza nada.

## Task Commits

1. **Task 1: Teste de aceitação do upload da foto de referência (RED)** - `e92b03f4` (test)
2. **Task 2: Tabela, model, chave liga/desliga, serviço de referência e rotas (GREEN)** - `6da117b0` (feat)
3. **Task 3: Painel de criativos na etapa 5 do wizard (componente separado) + build** - `c6a03de1` (feat)

_TDD: Task 1 (RED) → Task 2 (GREEN). Task 3 não é TDD (frontend puro)._

## Files Created/Modified

- `database/migrations/2026_10_02_120000_create_ml_anuncio_criativos_table.php` - cria `ml_anuncio_criativos` (guardada por `Schema::hasTable`)
- `app/Models/MlAnuncioCriativo.php` - estados, `LIMITE_MINUTOS=12`, `travada()`/`encerrarSeTravada()`, `referenciasVivas()`
- `app/Services/Creative/CreativeEngineAtivo.php` - leitor memoizado da chave `creative_engine_ativo`
- `app/Services/Creative/ReferenciaEfemeraService.php` - `guardar()`/`bytesDe()`/`apagar()` em disco privado
- `app/Http/Controllers/MlbAnuncioController.php` - `criativoReferenciaStore()`, `criativoReferenciaVer()`, injeção dos 2 serviços novos, prop `creativeAtivo` no `wizard()`
- `routes/mlb_anuncios.php` - rotas `mlb.anuncios.criativo.referencia` (POST, throttle:20,1) e `.ver` (GET)
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` - componente de upload com miniaturas servidas pela rota privada
- `resources/js/Pages/Mlb/AnunciarML.jsx` - import + montagem na etapa 4 (5ª do wizard), prop `creativeAtivo` no sinal da função
- `tests/Feature/Phase160/CriativoReferenciaTest.php` - 9 testes cobrindo OPS-03, FOTO-01/02/04/05, autorização e leitura por token

## Decisions Made

- **Nome e cardinalidade da tabela** — ver `key-decisions` acima e a seção "Decisão 1" do `160-01-PLAN.md`.
- **Desenho da retenção da foto (FOTO-03)** — desenhado neste plano porque o schema (`referencias_apagadas_em`) depende dele; implementação (comando agendado + chamada na aprovação) fica para o plano 160-04.
- **Chave operacional em `configuracoes`, não `.env`** — mesmo padrão de `FechamentoRegraTabela` (Fase 141): o `.env` só serve de default antes do primeiro `Configuracao::set`.

## Deviations from Plan

None - plan executado exatamente como escrito. Um ajuste de redação (não de comportamento) foi necessário: o docblock da migration citava literalmente os nomes descartados `creative_projects`/`creative_assets` para explicar a decisão — isso fazia o grep de verificação do próprio plano (`grep -rn "creative_projects\|creative_assets" app/ database/`) encontrar 2 ocorrências em comentário. Reescrito para descrever a alternativa descartada sem repetir os literais, mantendo o mesmo sentido; grep final = 0.

## Known Stubs

Nenhum. O espaço reservado para o resultado da geração em `PainelCriativosIa.jsx` (`{/* resultado da geração entra aqui (160-02) */}`) é intencional e documentado no próprio plano — a geração por IA é objetivo do plano 160-02, não desta fatia.

## Threat Flags

Nenhum além do que já está registrado no `<threat_model>` do `160-01-PLAN.md` (T-160-01 a T-160-07, T-160-SC) — a implementação seguiu as mitigações descritas ali (double-check de empresa antes da chave, token de 32 chars, disco privado, `Cache-Control: private, no-store`, throttle, nenhum pacote novo).

## Verification Results

1. `CriativoReferenciaTest` — **9/9 verde** (37 assertions).
2. `GeminiImageProviderTest` (spike V0.1) — **11/11 verde**, intocado.
3. `php artisan migrate --pretend | grep ml_anuncio_criativos` — tabela aparece, nenhum `enum` emitido, todas as FKs com `on delete cascade`/`on delete set null` explícitos.
4. `grep -rn "creative_projects\|creative_assets" app/ database/` — **0** ocorrências.
5. `npm run build` — concluído sem erro; `PainelCriativosIa` aparece exatamente 2 vezes em `AnunciarML.jsx` (import + montagem).

## Self-Check: PASSED

- `database/migrations/2026_10_02_120000_create_ml_anuncio_criativos_table.php` — FOUND
- `app/Models/MlAnuncioCriativo.php` — FOUND
- `app/Services/Creative/CreativeEngineAtivo.php` — FOUND
- `app/Services/Creative/ReferenciaEfemeraService.php` — FOUND
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` — FOUND
- `tests/Feature/Phase160/CriativoReferenciaTest.php` — FOUND
- Commit `e92b03f4` — FOUND (git log)
- Commit `6da117b0` — FOUND (git log)
- Commit `c6a03de1` — FOUND (git log)
