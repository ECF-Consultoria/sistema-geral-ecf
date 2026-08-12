---
phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
plan: 06
subsystem: infra
tags: [clicksign, hubspot-webhook, sandbox, contrato-assinatura, mlb-empresa, gate-humano]

# Dependency graph
requires:
  - phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0 (planos 01-05)
    provides: coluna exige_contrato, GatilhoContratoAdministrativoService, os 2+1 pontos de entrada
      religados ao orquestrador, Observers de reavaliação automática
provides:
  - Os 3 gates da fase MEDIDOS contra o sandbox Clicksign real (não Http::fake()) — Success
    Criteria 0/1/4 do ROADMAP confirmados por medição
  - Fix de produção real em ContratoPdfService (data_vencimento nula quebrava TODO contrato de
    prazo indeterminado, não só este gate)
  - Achado documentado sobre a Clicksign recusar "name" de signatário com dígito/parênteses
  - Correção de fato da pesquisa D9 — o 9º serviço chama-se "Shopee", não "Gestão de ADS Shopee"
affects: [129-webhook-clicksign, 130-alerta-contrato-preso, 131-tela-administrativo, 132-cutover-producao]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Medição de gate via kernel HTTP real ($kernel->handle()) com HMAC calculado manualmente —
      mesma técnica que TestCase::call() usa por baixo, fora do contexto phpunit"
    - "Http::fake() escopado por domínio (só api.hubapi.com) para isolar o que está fora do escopo
      da fase, deixando a API real (Clicksign) sem fake nenhum"

key-files:
  created: []
  modified:
    - app/Services/ContratoPdfService.php
    - tests/Feature/Phase126/ContratoPdfDadosTest.php
    - .planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/128-GATE.md

key-decisions:
  - "data_vencimento nulo (prazo indeterminado) vira texto 'Indeterminado', nunca TypeError nem branco silencioso"
  - "Um serviço com vencimento indeterminado torna a vigência do CONJUNTO do contrato indeterminada"
  - "Gate 2 (MlbEmpresa/SC4) medido com Gestão+Assessoria no mesmo deal, não só Gestão — Gestão sozinho não cria MlbEmpresa (achado)"

requirements-completed: [REDE-06, FLUXO-08]

# Metrics
duration: 40min
completed: 2026-08-12
---

# Phase 128 Plan 06: Gate humano — medição real contra o sandbox Clicksign Summary

**Os 3 gates da fase medidos contra o sandbox Clicksign real (não `Http::fake()`): 3 envelopes reais
criados, um bug de produção achado e corrigido (contrato de prazo indeterminado quebrava sempre),
e a grafia do serviço "Shopee" corrigida em relação ao que a pesquisa presumia.**

## Performance

- **Duration:** ~40 min (Task 2, medição + fix + documentação)
- **Started:** 2026-08-12T19:15:00Z (aprox.)
- **Completed:** 2026-08-12T19:51:00Z
- **Tasks:** 2 (Task 1 já concluída em sessão anterior — `675dd856`)
- **Files modified:** 3

## Accomplishments

- **Gate 1 (SC1) medido: ✅** — envelope real criado no sandbox Clicksign pelo caminho do webhook
  HubSpot, em 3 execuções distintas (repetibilidade, não sorte de uma execução):
  `aea3c36b-6f4f-40a3-a1dc-5be2422cb93f`, `a8b25922-5a4b-4dde-a409-7082fb6dfb24`,
  `d0e46b0a-7ebb-463c-bc78-844c3e6dc737` — todos em `status: draft` (D-02, não ativados).
- **Bug de produção real achado e corrigido** — `ContratoPdfService::formatarData()` quebrava com
  `TypeError` para `data_vencimento` nulo (caso legítimo de "prazo indeterminado"). Não pegável por
  nenhum teste anterior porque nenhuma fixture usava esse valor. Afeta **todo** contrato de prazo
  indeterminado em qualquer ambiente, não só este gate.
- **Achado novo sobre a API Clicksign** (não documentado em nenhuma pesquisa anterior): o campo
  `name` do signatário é validado pela Clicksign e recusa dígitos/parênteses (`400`, pointer
  `/data/attributes/name`).
- **Gate 2 (SC4) medido: ✅** — `MlbEmpresa` criada 1 segundo após a `Company`, na mesma rodada de
  um contrato real (empresa com Gestão + Assessoria). Achado: "Gestão" sozinho NÃO cria
  `MlbEmpresa` (só `polos`/`assessoria`/`incubadora` acionam ficha operacional) — não é falha de
  roteamento, é que `MlbEmpresa` é a ficha de implementação, e Gestão não usa essa mecânica.
- **Gate 3 (SC0) medido: ✅** — catálogo real confrontado (`SELECT ... FROM servicos`): só Polos é
  isento de contrato; os outros 8 exigem, incluindo o "Shopee" (nome real, corrigindo a confiança
  MÉDIA da pesquisa D9 que citava "Gestão de ADS Shopee"). Empresa fictícia só-Polos cadastrada
  pelo caminho do Comercial: 0 `ContratoAssinatura`, `MlbEmpresa` presente (tipo POLO).
- **Flag `administrativo_bloqueio_ativo` confirmada desligada** ao final — `Configuracao::get()`
  sem argumento devolve `NULL` (chave nasce só na Fase 133), mas o `EmpresaOperacionalRouter` sempre
  chama com default `'0'`, então `null` é tratado como desligado em todo caminho de código real.
- **Ambiente limpo** — todas as 5 empresas fictícias, seus contratos/fichas/eventos, os mappings de
  teste e o usuário admin de teste foram apagados ao final. Local voltou a 0 companies / 1 user
  (o pré-existente da máquina).

## Task Commits

1. **Task 1: Preparar o roteiro de medição e a suíte completa** - `675dd856` (docs, sessão anterior)
2. **Task 2 (fix): trata data_vencimento nula** - `8ce6a4e3` (fix)
3. **Task 2 (docs): preenche as 3 medições reais** - `193a4b64` (docs)

**Plan metadata:** (este commit)

## Files Created/Modified

- `app/Services/ContratoPdfService.php` - `formatarData()` aceita `?string`, devolve "Indeterminado" para nulo; `montarVigencia()` propaga indeterminação do conjunto
- `tests/Feature/Phase126/ContratoPdfDadosTest.php` - 2 testes de regressão (data_vencimento nula isolada e combinada com outro serviço)
- `.planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/128-GATE.md` - as 3 medições reais, achados, limpeza documentada

## Decisions Made

- **`data_vencimento` nulo vira "Indeterminado", não entra em `campos_pendentes`** — é caso
  legítimo (`ContratoDadosMinimosService::faltantes()` já não reprova isso), diferente dos 3 campos
  genuinamente ausentes no banco que usam o placeholder "A DEFINIR".
- **Vigência do CONJUNTO fica indeterminada se QUALQUER serviço do snapshot tiver vencimento em
  aberto** — não dá para apurar "a maior data" quando uma delas não existe; mais correto que ignorar
  o nulo na ordenação.
- **Gate 2 medido com Gestão+Assessoria no mesmo deal, não com a empresa isolada do Gate 1** — o
  roteiro original presumia que a MESMA empresa do Gate 1 geraria `MlbEmpresa`, o que não acontece
  para "Gestão" sozinho. Ajuste registrado como achado no GATE.md, não escondido.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `ContratoPdfService::formatarData()` quebrava com `data_vencimento` nulo**
- **Found during:** Task 2, 1ª tentativa de medição do Gate 1 (empresa fictícia real contra o sandbox)
- **Issue:** `TypeError` ao montar as variáveis do modelo — `data_vencimento` nulo é caso legítimo
  ("prazo indeterminado"), mas `formatarData(string $data)` exigia string não-nula
- **Fix:** `formatarData()` passou a aceitar `?string`, devolvendo "Indeterminado" para nulo/vazio;
  `montarVigencia()` propaga indeterminação do conjunto quando qualquer serviço está em aberto
- **Files modified:** `app/Services/ContratoPdfService.php`, `tests/Feature/Phase126/ContratoPdfDadosTest.php`
- **Verification:** 2 testes de regressão adicionados; suíte completa (Phase124/125/126/127/128) reexecutada, 100% verde; 3ª medição real subsequente confirmou o fix contra a API real (envelope criado com sucesso)
- **Committed in:** `8ce6a4e3`

---

**Total deviations:** 1 auto-fixado (Rule 1 — bug de produção real, não coberto por teste anterior)
**Impact on plan:** Correção essencial — sem ela, Success Criteria 1 nunca seria atendido para
nenhum contrato de prazo indeterminado (o caso mais comum de assinatura recorrente), em qualquer
ambiente, não só neste gate. Zero scope creep — o fix ficou restrito ao método exato que quebrou.

## Issues Encountered

- **Clicksign recusa `name` de signatário com dígito/parênteses** — não é bug de código, é
  validação real da API não documentada antes. Corrigido ajustando os dados de teste (nomes só com
  letras/espaços) e registrado no GATE.md como achado para a próxima fase que montar nome de
  signatário dinamicamente.
- **Sob `QUEUE_CONNECTION=sync` (ambiente local, sem worker), o 2º job de uma empresa com 2 serviços
  que exigem contrato pode ficar "engolido" silenciosamente** (`WithoutOverlapping` + `SyncJob::release()`
  não reagenda). Confirmado que é artefato exclusivo de rodar sem worker (processando o job
  manualmente funcionou de primeira) — não afeta produção, que usa `queue:work` real. Documentado no
  GATE.md como achado de ambiente, não como bug.
- **MariaDB local corrompido no início da sessão** — já reparado pelo orquestrador antes de este
  agente ser despachado (ver `<environment_already_prepared_by_orchestrator>` no prompt); não foi
  necessário nenhum trabalho adicional de recuperação nesta plano.

## User Setup Required

None - a medição usou credenciais de sandbox já configuradas em `.env` (mesmas da Fase 127). Nenhum
token/secret novo foi adicionado permanentemente.

## Next Phase Readiness

- **Fase 129 (webhook Clicksign)** pode prosseguir — os 3 envelopes reais deste gate (`draft`) ficam
  disponíveis no sandbox para exercitar o webhook de assinatura quando a Fase 129 chegar, se for
  útil (não foram cancelados).
- **Fase 130 (alerta de contrato preso)** ganha um dado a mais: sob fila `sync`/sem worker, um 2º
  contrato da mesma empresa pode ficar "rascunho" órfão sem erro — vale considerar esse caso na
  reconciliação, mesmo sendo artefato de ambiente (produção usa worker real, mas a reconciliação
  deveria ser robusta a qualquer causa de "rascunho parado").
- **Fase 131 (tela do Administrativo)** — `servicos.clicksign_template_id` do serviço "Gestão" já
  está configurado no ambiente local (`9e5d4517-…`, sandbox) como resultado colateral desta medição;
  útil para quem for testar a tela sem precisar recadastrar o modelo.
- Sem bloqueios. Fase 128 encerrada — todos os 3 Success Criteria (0, 1, 4) do ROADMAP confirmados
  por medição real, não por teste com fake.

---
*Phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: app/Services/ContratoPdfService.php
- FOUND: tests/Feature/Phase126/ContratoPdfDadosTest.php
- FOUND: .planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/128-GATE.md
- FOUND: .planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/128-06-SUMMARY.md
- FOUND commit: 8ce6a4e3
- FOUND commit: 193a4b64
- FOUND commit: 675dd856
