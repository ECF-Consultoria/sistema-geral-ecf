---
phase: 129-webhook-clicksign-v22-0
plan: 06
subsystem: api
tags: [laravel, clicksign, pdf, storage, download, streaming, autenticacao]

# Dependency graph
requires:
  - phase: 129-05
    provides: "ProcessarEventoClicksignJob::handle() fechando o circuito de liberação por webhook"
provides:
  - "ClicksignClient::consultarDocumento() — GET /envelopes/{id}/documents/{id}"
  - "BaixarPdfContratoAssinadoJob — download por streaming, disco privado, falha não bloqueante"
  - "ContratoPdfAssinadoController + rota contratos.pdf-assinado — única porta de saída do PDF assinado"
  - "Coluna pdf_assinado_erro — sinal legível de 'assinado, PDF pendente' para o alerta da Fase 130"
affects: ["130", "131"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "D-12 aplicada na prática: todo download reconsulta o documento (ClicksignClient::consultarDocumento()) IMEDIATAMENTE antes de baixar — nunca reusa link do payload do evento nem de tentativa anterior, porque o link S3 vale 5 minutos (X-Amz-Expires=300, MEDIDO)"
    - "Job de efeito colateral SEPARADO do job de decisão de negócio: BaixarPdfContratoAssinadoJob nunca altera status/liberação — só grava pdf_assinado_path (sucesso) ou pdf_assinado_erro (falha permanente), nunca os dois em conflito"
    - "dispatch() dentro de try/catch alheio ao job disparado: ProcessarEventoClicksignJob engole QUALQUER exceção do dispatch (inclusive as que a fila 'sync' relança de dentro do job), provando que a liberação já gravada nunca é desfeita por download"
    - "Streaming com sink + checagem de assinatura de arquivo (%PDF nos 4 primeiros bytes) para distinguir um PDF real de um XML de erro do S3 salvo com nome de PDF"
    - "Rota de arquivo a partir de valor de banco se defende SOZINHA contra path traversal (recusa '..' e exige prefixo 'contratos/'), mesmo o valor sendo hoje 100% gerado pelo próprio sistema"

key-files:
  created:
    - database/migrations/2026_08_14_100002_add_pdf_assinado_erro_to_contrato_assinaturas_table.php
    - app/Jobs/BaixarPdfContratoAssinadoJob.php
    - app/Http/Controllers/ContratoPdfAssinadoController.php
    - tests/Feature/Phase129/DownloadPdfAssinadoTest.php
    - tests/Feature/Phase129/DownloadPdfFalhaNaoBloqueiaTest.php
    - tests/Feature/Phase129/RotaPdfAssinadoAutenticadaTest.php
  modified:
    - app/Models/ContratoAssinatura.php
    - app/Services/Clicksign/ClicksignClient.php
    - app/Jobs/ProcessarEventoClicksignJob.php
    - routes/web.php

key-decisions:
  - "'Estado próprio' de PDF pendente NÃO é um 8º valor de STATUS_* (colidiria com a D-06 da Fase 125 e com a própria D-14, que exige status continuar 'assinado') — é a COMBINAÇÃO status=assinado + pdf_assinado_path IS NULL + pdf_assinado_erro preenchido/vazio, documentada no topo da migration"
  - "Ordem de resolução do link seguiu literalmente o texto do plano (links.files.signed → attributes.files.signed → links.files.original → attributes.files.original) — o 129-GATE.md não registrou a forma exata do bloco de arquivo do endpoint de documento (só do envelope), então não havia override a aplicar"
  - "Rota /admin/contratos/{contratoAssinatura}/pdf-assinado ficou FORA do grupo role:admin existente (prefix administrativo/, name admin.*) — plano pediu path e name exatos (contratos.pdf-assinado) que não combinam com esse prefixo; middleware(['auth','role:admin']) replicado standalone, sem 'verified' (não pedido pelo plano)"
  - "Segundo caso de teste do D-14 (dispatch() lançando exceção) simulado com clicksign_document_id nulo — faz o guard do próprio BaixarPdfContratoAssinadoJob lançar RuntimeException imediatamente (fila sync executa e relança), provando que o try/catch do ProcessarEventoClicksignJob protege a liberação independente da ORIGEM da exceção, não só de falha HTTP"

requirements-completed: [CLICK-11]

# Metrics
duration: ~1h20min
completed: 2026-08-13
---

# Phase 129 Plan 06: PDF assinado dentro do sistema (D6/CLICK-11) Summary

**`BaixarPdfContratoAssinadoJob` traz o PDF assinado da Clicksign para `storage/app` por streaming, sempre reconsultando o documento para um link fresco (o S3 pré-assinado vale 5 minutos), servido só por rota autenticada de admin — e uma falha permanente de download nunca desfaz a liberação da empresa, que já aconteceu.**

## Performance

- **Duration:** ~1h20min
- **Started:** 2026-08-13
- **Completed:** 2026-08-13
- **Tasks:** 2/2
- **Files modified:** 6 criados, 4 modificados

## Accomplishments

- **`ClicksignClient::consultarDocumento()`** — `GET /envelopes/{id}/documents/{id}`, molde idêntico a `consultarEnvelope()`. Docblock cita §10.4 (documento em `draft` não tem bloco de arquivo — não é bug, é a API) e §7 (link de 5 minutos, MEDIDO) do empírico.
- **`BaixarPdfContratoAssinadoJob`** (D-12/D-13/D-14) — guard de reentrega (arquivo já presente = zero chamada HTTP), reconsulta SEMPRE antes de baixar (nunca reusa link de payload), streaming direto pro disco `local` via `Http::withOptions(['sink' => ...])` (nunca `->body()`), checagem dos 4 primeiros bytes (`%PDF`) pra recusar um XML de erro do S3 salvo como se fosse PDF, e `failed()` que só marca `pdf_assinado_erro` — nunca toca `status` nem `ContratoLiberacao`.
- **Coluna `pdf_assinado_erro`** — migration com comentário de topo explicando por que o "estado próprio" da D-14 é a combinação `status=assinado` + `pdf_assinado_path IS NULL`, não um 8º valor de `STATUS_*`. Somada a `$fillable` (T-127-03: sem isso o mass assignment falha em silêncio) e provada por teste de mass assignment dedicado, não por leitura do array.
- **Disparo pós-liberação** (`ProcessarEventoClicksignJob`) — `BaixarPdfContratoAssinadoJob::dispatch($contrato)` roda DEPOIS de `liberarEmpresa()`, dentro de `try/catch` que só loga `warning`. Provado por dois cenários distintos: download HTTP falhando (403 do S3) e o próprio `dispatch()` relançando uma exceção de guard (`clicksign_document_id` nulo) — nos dois, a liberação já gravada sobrevive intacta.
- **`ContratoPdfAssinadoController` + rota `contratos.pdf-assinado`** — única porta de saída do arquivo, atrás de `middleware(['auth', 'role:admin'])`. Recusa caminho absoluto e qualquer `..` mesmo o valor vindo do próprio banco (defesa própria da rota, T-129-38). 5 testes cobrindo anônimo (redirect), não-admin (403), admin com arquivo (200 + `Content-Disposition: attachment`), `pdf_assinado_path` nulo (404) e path traversal (404).

## Task Commits

1. **Task 1: `consultarDocumento()`, sinal de PDF pendente e `BaixarPdfContratoAssinadoJob`** — `07051e61` (feat) — 6 testes, 9 assertions
2. **Task 2: disparo pós-liberação e rota autenticada de download** — `d6953c60` (feat) — 7 testes, 16 assertions

_Nenhuma task teve TDD — plano `type="execute"` padrão._

## Files Created/Modified

- `database/migrations/2026_08_14_100002_add_pdf_assinado_erro_to_contrato_assinaturas_table.php` (novo) — coluna `text` nullable, comentário de topo com a divergência deliberada da D-14
- `app/Models/ContratoAssinatura.php` (modificado) — `pdf_assinado_erro` em `$fillable`
- `app/Services/Clicksign/ClicksignClient.php` (modificado) — `consultarDocumento()`
- `app/Jobs/BaixarPdfContratoAssinadoJob.php` (novo) — job de download por streaming, disco privado, falha não bloqueante
- `app/Jobs/ProcessarEventoClicksignJob.php` (modificado) — dispatch protegido por `try/catch` depois de `liberarEmpresa()`
- `app/Http/Controllers/ContratoPdfAssinadoController.php` (novo) — rota autenticada de download com defesa contra path traversal
- `routes/web.php` (modificado) — `GET /admin/contratos/{contratoAssinatura}/pdf-assinado` (`contratos.pdf-assinado`)
- `tests/Feature/Phase129/DownloadPdfAssinadoTest.php` (novo) — 6 testes: download com sucesso, prova da D-13 (disco `public` vazio), prova da D-12 (`consultarDocumento()` sempre chamado), rejeição de resposta que não é PDF, reentrega sem chamada HTTP, mass assignment de `pdf_assinado_erro`
- `tests/Feature/Phase129/DownloadPdfFalhaNaoBloqueiaTest.php` (novo) — 2 testes: falha HTTP não impede liberação/ficha e não vaza e-mail no erro; exceção de guard do job (não HTTP) também não impede liberação já gravada
- `tests/Feature/Phase129/RotaPdfAssinadoAutenticadaTest.php` (novo) — 5 testes: anônimo, não-admin, admin com arquivo, `pdf_assinado_path` nulo, path traversal

## Decisions Made

Ver `key-decisions` no frontmatter para o registro completo. Destaque: a ordem de resolução do link do documento (`links.files.signed` → `attributes.files.signed` → `links.files.original` → `attributes.files.original`) seguiu o texto literal do plano porque o `129-GATE.md` mediu a forma do `consultarEnvelope()` (achado 4: recurso desembrulhado) mas **não** exercitou o endpoint de documento (`consultarDocumento()`) contra o sandbox real nesta rodada — não havia override a aplicar. A confirmação real desta ordem é tarefa do gate humano 129-07.

## Deviations from Plan

Nenhuma — o plano foi executado como escrito, com uma extensão pontual: o critério de aceite "assertar por teste de mass assignment, não por leitura" foi atendido com um teste dedicado (`pdf_assinado_erro_e_mass_assignable`) usando `ContratoAssinatura::create([...])`, em vez de inferir isso de outro teste que só faz atribuição direta de propriedade (`$contrato->pdf_assinado_erro = ...`, que funciona independente de `$fillable`).

## Issues Encountered

- **Guzzle `MockHandler` respeita a opção `sink`** (confirmado lendo `vendor/guzzlehttp/guzzle/src/Handler/MockHandler.php`) — `Http::fake()` + `Http::withOptions(['sink' => ...])` funciona em teste exatamente como em produção, escrevendo o corpo da resposta fake no arquivo. Sem essa confirmação, o desenho do teste teria assumido (erradamente) que precisaria de um HTTP real para provar o streaming.
- **Fila `sync` relança exceções do job via `SyncQueue::handleException()`, mas ANTES chama `$job->fail($e)`** — ou seja, mesmo sem um worker de fila de verdade, `failed()` roda de verdade nos testes (marcando `pdf_assinado_erro`) e a exceção original ainda sobe para quem chamou `dispatch()`. Isso permitiu testar o D-14 fim a fim sem precisar de `Queue::fake()` nem mock do `Bus`.

## User Setup Required

None — nenhuma configuração nova de ambiente.

⚠️ Lembrete herdado do ambiente (não desta task): o túnel cloudflared e o `php artisan serve` seguem rodando desta sessão — **não foram tocados** por esta execução.

## Next Phase Readiness

- `pdf_assinado_path` deixa de ser uma coluna sem escritor — a Fase 129 está completa nesse ponto (D6 da milestone).
- A Fase 130 (REDE-03) pode varrer `status = 'assinado' AND pdf_assinado_path IS NULL` (com `pdf_assinado_erro` preenchido = tentou e desistiu, vazio = ainda não tentou) para o alerta de "PDF pendente" — o par de sinais já existe, só falta o canal de alerta.
- A Fase 131 (tela do Administrativo) pode linkar direto para `route('contratos.pdf-assinado', $contrato)` — a rota já existe e já está protegida; quando a permissão dedicada `admin.contratos` (UI-05) nascer, trocar só o middleware, sem mexer no controller.
- O gate humano do plano 129-07 é quem prova, contra o sandbox real, que a ordem de resolução do link (`links.files.signed`/`attributes.files.signed`/...) bate com a forma real da resposta — esta suíte só prova a fiação.
- Nenhum bloqueio para o plano 129-07 iniciar.

## Self-Check: PASSED

- `database/migrations/2026_08_14_100002_add_pdf_assinado_erro_to_contrato_assinaturas_table.php` → FOUND
- `app/Jobs/BaixarPdfContratoAssinadoJob.php` (contém `'sink' =>`, `consultarDocumento(`, `%PDF`) → FOUND
- `app/Http/Controllers/ContratoPdfAssinadoController.php` (contém `Storage::disk('local')`) → FOUND
- `tests/Feature/Phase129/DownloadPdfAssinadoTest.php` → FOUND
- `tests/Feature/Phase129/DownloadPdfFalhaNaoBloqueiaTest.php` → FOUND
- `tests/Feature/Phase129/RotaPdfAssinadoAutenticadaTest.php` → FOUND
- Commit `07051e61` → FOUND em `git log`
- Commit `d6953c60` → FOUND em `git log`
- `php artisan test --filter=DownloadPdfAssinadoTest` → 6 passed / 9 assertions, exit 0
- `php artisan test --filter="DownloadPdfFalhaNaoBloqueiaTest|RotaPdfAssinadoAutenticadaTest"` → 7 passed / 16 assertions, exit 0
- `php artisan route:list --path=pdf-assinado` → confirma middlewares `auth` + `role:admin`
- `php artisan migrate:status | grep pdf_assinado_erro` → `Ran`
- Suíte cumulativa `Phase124|Phase125|Phase126|Phase127|Phase128|Phase129` → 346 passed / 1128 assertions, exit 0 (baseline 333/1103 + 13 testes novos — sem regressão)

---
*Phase: 129-webhook-clicksign-v22-0*
*Completed: 2026-08-13*
