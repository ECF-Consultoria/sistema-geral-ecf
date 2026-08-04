---
phase: 123-telas-e-relat-rios-v21-0
plan: 06
subsystem: desempenho
tags: [laravel, inertia, react, phpunit, checkpoint-humano, desempenho-por-empresa]

# Dependency graph
requires:
  - phase: 123-02
    provides: "PerformanceController::show() com empresas_score/tem_detalhe_empresas/empresas_score_resumo + dropdown corrigido (D-02)"
  - phase: 123-03
    provides: "BonusAuditoriaController::index() com nota_empresa por empresa (UIEM-04, metade Auditoria)"
  - phase: 123-04
    provides: "Card de margem sem jargão + EmpresasScoreTabela.jsx (UIEM-01/02/03)"
  - phase: 123-05
    provides: "RelatorioBonificacaoController com detalhe por empresa + linha expansível (fecha UIEM-04)"
provides:
  - "Suíte completa da fase classificada contra a baseline conhecida, zero regressão nova"
  - "Build de produção verde (rodado 2x nesta sessão, a última confirmando o que está servido)"
  - "Checkpoint visual com 20 dos 23 itens do roteiro confirmados objetivamente contra dado real de 2026-06 (script standalone que boota o app com .env/MySQL real e chama os controllers direto)"
  - "9 itens de julgamento visual genuíno aprovados pelo usuário em 2026-08-04"
  - "Auditoria de Bônus (verificação visual com dado real) formalmente adiada para pós-deploy, com causa isolada (company_users) e cobertura automatizada que sustenta a decisão"
  - "123-CHECKPOINT-VISUAL.md — registro completo do que foi conferido, por item, com resultado"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Verificação automatizada de tela Inertia com dado real, sem navegador: bootstrap completo do app (.env real, não o SQLite do phpunit.xml) + Auth::setUser()/setUserResolver() + chamada direta ao método do controller + header X-Inertia:true para obter o JSON de props — sem inventar rota nova"
    - "Autorização para trazer dado de compensação real para o BANCO LOCAL é distinta de autorização para VERSIONAR esse resultado — a segunda exige consentimento à parte mesmo com a primeira já dada (registrado em learnings §11 após uma correção de conduta nesta sessão)"
    - "Verificação em camadas quando falta dado real: separar 'confirmado por automação' (dado real + código) de 'pendente — olho humano' (julgamento visual) de 'adiado' (decisão explícita de não perseguir mais uma tabela de produção, sustentada por cobertura automatizada)"

key-files:
  created:
    - .planning/phases/123-telas-e-relat-rios-v21-0/123-CHECKPOINT-VISUAL.md
  modified:
    - .planning/learnings/desempenho-bonificacao.md
    - .planning/ROADMAP.md
    - .planning/REQUIREMENTS-v21.md (já estava correto — verificado, sem alteração necessária)

key-decisions:
  - "Suíte completa (`php artisan test` sem filtro) trava nesta máquina por um problema de infraestrutura pré-existente do Windows (set_time_limit(300) re-armado por comandos de Grants + usleep contando wall-clock), documentado desde a Fase 80 — não é regressão desta fase. O 'total' foi coberto pelos filtros específicos (Phase123/Phase122/Phase120/Phase110/Desempenho) que juntos cobrem a fase inteira e as duas baselines conhecidas."
  - "Autorizado pelo usuário: pull read-only de duas rodadas de tabelas da VPS para o banco local — (1) desempenho_score_snapshots/desempenho_company_score_snapshots (dado de score/bônus, 2026-06), (2) setores/cargos/user_setores (estrutura organizacional). Uma terceira rodada (company_users, vínculo profissional×empresa) foi solicitada e o usuário optou por NÃO autorizar."
  - "Decisão fechada do usuário: a verificação visual da Auditoria de Bônus com dado real de 2026-06 sai do escopo deste checkpoint e vai para conferência pós-deploy em produção, onde o dado é nativo — sustentada por 6 testes de feature (AuditoriaBonusNotaEmpresaTest) + 7 gates estruturais JS, e pela mesma fonte de leitura (CompanyScoreSnapshotReader) já confirmada com dado real no Relatório de Bonificação e na tela individual nesta mesma sessão."
  - "Correção de conduta registrada: um commit intermediário gravou, num arquivo versionado, tabela nominal pareando profissional com faixa de bônus e nota final — corrigido por amend antes de avançar no histórico. Regra aplicada no resto da sessão e documentada em learnings §11: contadores de carteira (entraram/total) por profissional podem ser versionados; nome pareado com faixa/nota/valor de bonificação, não."

requirements-completed: [UIEM-01, UIEM-02, UIEM-03, UIEM-04]

# Metrics
duration: ~1h53min
completed: 2026-08-04
---

# Phase 123 Plan 06: Checkpoint visual humano (fecha a fase) Summary

**Suíte completa classificada contra a baseline conhecida (zero regressão nova), build de produção verde, e checkpoint visual com 20 dos 23 itens do roteiro confirmados objetivamente contra dado real de 2026-06 (Felipe 9/30, Matheus Estrela 100% Shopee 6/15, Ana Julia 23/24, Rubens 23/25, Renan Bassetto 34/34) — os 9 itens de julgamento visual genuíno aprovados pelo usuário; Auditoria de Bônus formalmente adiada para conferência pós-deploy por decisão explícita, sustentada por cobertura automatizada.**

## Performance

- **Duration:** ~1h 53min (12:10–14:03, incluindo as pausas do checkpoint humano entre rodadas)
- **Started:** 2026-08-04T12:10:14-03:00
- **Completed:** 2026-08-04T14:02:53-03:00 (checkpoint aprovado logo em seguida)
- **Tasks:** 2 (Task 1 auto + Task 2 checkpoint:human-verify, executado em múltiplas rodadas)
- **Files modified:** 3 (1 criado, 2 modificados) + o SUMMARY

## Accomplishments
- Task 1: os 8 comandos de verificação da suíte rodaram isolados (saída sempre redirecionada a arquivo, nunca por pipe) — `--filter=Phase123` 41/41, `--filter=Phase122` 49/49, `--filter=Phase120` 18/18, `npm run test:js` 124/125 (1 falha baseline não relacionada), `--filter=Phase110` 2F/3P (baseline), `--filter=Desempenho` 14F/101P (baseline), suíte completa classificada como travamento de infra pré-existente (Fase 80), `npm run build` verde. Os 4 invariantes de escopo (réguas, agregação, flag, PDF) provados por diff vazio.
- Task 2 rodou em múltiplas rodadas por causa de gaps de dado real descobertos durante o próprio checkpoint: (1) verificação automatizada inicial revelou banco local sem nenhuma linha de score de 2026-06; (2) após pull autorizado, 20 dos 23 itens confirmados objetivamente por um script que boota o app real e chama os controllers direto; (3) Relatório de Bonificação, inicialmente vazio por falta de `user_setores`/`cargos`, foi desbloqueado após pull adicional autorizado e confirmado com 5 contemplados reais + PDF real; (4) Auditoria de Bônus, bloqueada por uma terceira tabela (`company_users`) que o usuário optou por não trazer, foi formalmente adiada para pós-deploy.
- Corrigido, dentro da própria sessão, um erro de conduta: um commit intermediário havia gravado tabela nominal (profissional × faixa de bônus × nota) num arquivo versionado — identificado pelo coordenador, corrigido por amend, e a regra ("contadores sim, nome pareado com faixa/nota/valor não") foi seguida no resto da sessão e documentada em `learnings/desempenho-bonificacao.md` §11.
- Os 9 itens de julgamento visual genuíno (card de margem, aviso Shopee, ressalva de origem da nota, ausência de seção quando zero, expansão de linha no Relatório) foram aprovados pelo usuário — resposta literal "aprovado".

## Task Commits

Task 2 teve múltiplas rodadas de commit por causa da natureza iterativa do checkpoint humano (achados de dado real → correção de rota → nova decisão do usuário a cada rodada):

1. **Task 1: Suíte completa contra baseline + build de produção** - `42e5a047` (docs)
2. **Task 2: Preparação do ambiente + roteiro do checkpoint** - `1f9b97da` (docs)
3. **Task 2: Verificação automatizada dos 23 itens com dado real (rodada 1)** - `6f9f6c06` (docs — amendado pelo coordenador para remover tabela nominal indevida)
4. **Task 2: Reverificação do Relatório de Bonificação pós-pull de `user_setores`/`cargos`** - `3dca237e` (docs)
5. **Task 2: Fechamento — Auditoria de Bônus adiada para pós-deploy + build final** - `e2879f2e` (docs)
6. **Task 2: Checkpoint visual aprovado pelo usuário** - `8890d882` (docs)

**Plan metadata:** commit deste SUMMARY (a seguir)

## Files Created/Modified
- `.planning/phases/123-telas-e-relat-rios-v21-0/123-CHECKPOINT-VISUAL.md` - Documento completo do checkpoint: tabela dos 8 comandos da suíte com exit code e veredito, invariantes de escopo por diff, verificação automatizada dos 23 itens do roteiro visual (com URLs, resultado por item, e classificação confirmado-por-automação/pendente-olho-humano/adiado-pós-deploy), aprovação final registrada
- `.planning/learnings/desempenho-bonificacao.md` - Nova seção §11: autorização para trazer dado de compensação real para o banco local não é autorização para versionar o resultado individual
- `.planning/ROADMAP.md` - Fase 123 marcada 6/6 planos completos, `123-06-PLAN.md` com checkbox marcado
- `.planning/REQUIREMENTS-v21.md` - Conferido: UIEM-01..04 já estavam corretamente marcados `[x]`/`Complete (2026-08-04)` desde o commit do Plano 05 (que fechou UIEM-04) — nenhuma alteração necessária, estado já refletia a entrega real

## Decisions Made
Ver `key-decisions` no frontmatter — resumo: (1) suíte completa trava por infra pré-existente do Windows, não regressão; (2) duas rodadas de pull read-only de dado de produção autorizadas (score/bônus de 2026-06, depois estrutura organizacional), uma terceira (carteira `company_users`) recusada pelo usuário; (3) Auditoria de Bônus adiada para pós-deploy por decisão explícita, sustentada por cobertura automatizada; (4) correção de conduta sobre versionamento de dado nominal de bônus, documentada para não se repetir.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking, corrigido durante a Task 2] Banco local sem dado real de 2026-06 bloqueava a verificação do checkpoint**
- **Found during:** Task 2, ao preparar o ambiente de verificação
- **Issue:** `desempenho_company_score_snapshots`/`desempenho_score_snapshots` tinham 0 linhas localmente — sem isso, os três casos-limite reais da fase (Felipe, Matheus Estrela, Débora Lima) não eram verificáveis
- **Fix:** Sinalizado ao coordenador em vez de decidir sozinho (dado sensível de compensação); usuário autorizou pull read-only da VPS pelo método já estabelecido nesta casa (`plink`/`pscp`, `--insert-ignore`)
- **Verification:** Reconsulta ao banco confirmou 862 linhas em `desempenho_company_score_snapshots`, 390 em `desempenho_score_snapshots`, zero órfãos de `company_id`/`user_id`
- **Committed in:** `1f9b97da` (sinalização), pull feito pelo coordenador fora do meu controle de versão direto

**2. [Rule 4 - Arquitetural/dado sensível, escalado ao usuário] Relatório de Bonificação e Auditoria de Bônus vazios por falta de `user_setores`/`cargos`**
- **Found during:** Task 2, verificação automatizada rodada 1
- **Issue:** Os dois controllers resolvem "quem é profissional" via join em `user_setores`/`cargos` antes de tocar no dado de score — tabela de estrutura organizacional, não fazia parte do primeiro pull
- **Fix:** Sinalizado ao coordenador (não presumido); usuário autorizou pull read-only de `setores`/`cargos`/`user_setores`
- **Verification:** `user_setores` 9→39, `cargos` 4→17, `setores` 5→6, join analista/estrategista 0→14; Relatório de Bonificação passou a devolver 5 linhas reais
- **Committed in:** `3dca237e`

**3. [Rule 4 - Arquitetural/dado sensível, escalado ao usuário] Auditoria de Bônus continuava vazia por falta de `company_users`**
- **Found during:** Task 2, reverificação pós-pull de `user_setores`/`cargos`
- **Issue:** `BonusAuditoriaController::index()` também monta a lista de empresas via `CarteiraContextService::forUser()`, que depende da pivot `company_users` — uma terceira tabela, distinta das duas já trazidas
- **Fix:** Sinalizado ao coordenador; usuário optou por **não** autorizar esse terceiro pull. Decisão aceita e formalizada: Auditoria de Bônus sai do escopo do checkpoint local, vai para conferência pós-deploy, sustentada pela cobertura automatizada já existente (`AuditoriaBonusNotaEmpresaTest`, 6 testes verdes)
- **Verification:** N/A — decisão de escopo, não bug
- **Committed in:** `e2879f2e`

**4. [Rule 1 - Bug de conduta, corrigido por amend] Tabela nominal de bônus versionada indevidamente**
- **Found during:** entre as rodadas de Task 2, identificado pelo coordenador
- **Issue:** O commit `6f9f6c06` (antes do amend) gravou no `123-CHECKPOINT-VISUAL.md` uma tabela pareando cada um dos 11 profissionais com `faixa_bonus`/`nota_final` — autorização para trazer o dado para o banco local não cobre versionar o resultado individual
- **Fix:** Coordenador substituiu a tabela nominal por um agregado por faixa (contagem, sem nomes) e removeu as demais menções que pareavam nome com faixa; commit amendado para `6f9f6c06`. Regra seguida no resto da sessão (contadores de carteira sim, nome+faixa/nota/valor não) e documentada em `learnings/desempenho-bonificacao.md` §11
- **Verification:** `grep` final no arquivo confirma zero ocorrência de nome pareado com faixa/nota/valor
- **Committed in:** amend de `6f9f6c06`; regra aplicada nos commits seguintes (`3dca237e`, `e2879f2e`, `8890d882`)

---

**Total deviations:** 4 (1 blocking de dado resolvido por escalonamento, 2 decisões arquiteturais/de escopo escaladas ao usuário — uma aprovada, uma recusada —, 1 correção de conduta sobre versionamento de dado sensível)
**Impact on plan:** Nenhum scope creep de código — toda esta fase é documentação de verificação (`.planning/`), nenhum arquivo de `app/`/`resources/js/` tocado. O impacto real foi de **processo**: duas escaladas corretas ao usuário em vez de decisões presumidas, e uma correção de conduta que virou lição documentada para a próxima sessão que mexer em dado de bônus.

## Issues Encountered
Nenhum além das deviations acima, todas resolvidas dentro da própria Task 2, com decisão do usuário quando a decisão não era minha para tomar.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. O pull de dado da VPS para o banco local foi feito pelo coordenador/usuário fora do escopo de comandos deste executor.

## Next Phase Readiness

- **Fase 123 (v21.0) FECHADA — 6/6 planos.** UIEM-01, UIEM-02, UIEM-03, UIEM-04 completos e verificados (código + testes automatizados + checkpoint visual aprovado com dado real, exceto a fatia Auditoria de Bônus, formalmente adiada).
- **Dívida de verificação conhecida, registrada, não perdida:** a conferência visual da Auditoria de Bônus com dado real de 2026-06 fica para pós-deploy em produção. Não é item silenciosamente fechado — está explícito em `123-CHECKPOINT-VISUAL.md` e neste SUMMARY.
- **Nenhum deploy executado nesta fase** — a autorização de deploy é decisão separada do usuário, conforme `CLAUDE.md` e o `<threat_model>` do plano.
- Milestone v21.0 (Desempenho por nota individual de empresa) fica com as 7 fases (117-123) todas fechadas, restando a decisão de negócio, fora desta fase, de quando/se ligar `metrics.performance_company_first_score` (gates MPP-04 e ROLL, ver `learnings/desempenho-bonificacao.md` §9).

---
*Phase: 123-telas-e-relat-rios-v21-0*
*Completed: 2026-08-04*
