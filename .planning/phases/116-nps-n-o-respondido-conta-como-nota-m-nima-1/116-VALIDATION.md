---
phase: 116
slug: nps-n-o-respondido-conta-como-nota-m-nima-1
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-27
approved: 2026-07-27
---

# Phase 116 — Estratégia de Validação

> Contrato de validação por fase para amostragem de feedback durante a execução.

---

## Infraestrutura de Teste

| Propriedade | Valor |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) + Laravel Feature Tests (`RefreshDatabase`) |
| **Arquivo de config** | `phpunit.xml` |
| **Comando rápido** | `php artisan test --filter=Phase116` |
| **Comando full** | `php artisan test` |
| **Runtime estimado** | ~15s (filtro Phase116) · ~4-6 min (suite completa) |

---

## Taxa de Amostragem

- **Após cada commit de task:** `php artisan test --filter=Phase116`
- **Após cada wave:** `php artisan test --filter=Nps` + `php artisan test --filter=Desempenho`
- **Antes de `/gsd:verify-work`:** suite completa verde, incluindo os 4 arquivos de cacheKey atualizados
- **Latência máxima de feedback:** ~15s

---

## Mapa de Verificação por Requisito

| Req ID | Comportamento | Tipo | Comando automatizado | Arquivo existe |
|--------|---------------|------|----------------------|----------------|
| NPSFLOOR-01 | Survey não respondido conta nota 1 nos cards da área NPS | Feature | `php artisan test --filter=NpsFloorAreaNpsTest` | ❌ Wave 0 |
| NPSFLOOR-02 | Survey não respondido conta nota 1 no `DesempenhoScoreService::compute()` | Feature | `php artisan test --filter=NpsFloorDesempenhoTest` | ❌ Wave 0 |
| NPSFLOOR-03 | Empresa **sem nenhum survey** no mês nunca vira 1 (invariante D3) | Feature | `php artisan test --filter=NpsFloorAreaNpsTest` (caso dedicado) | ❌ Wave 0 |
| NPSFLOOR-04 | Empresa invalidada na competência não puxa 1 **nem no Desempenho nem na área NPS** (D5) | Feature | `php artisan test --filter=BonusInvalidacaoEmpresaTest` + caso novo na área NPS | ⚠️ existe p/ Desempenho; área NPS é capacidade **nova** |
| NPSFLOOR-05 | Responsável consolidado (gap conhecido) não vira 1 indevido | Feature | `php artisan test --filter=AtribuicaoConsolidadoNpsTest` | ✅ existe, precisa de cenário novo |
| NPSFLOOR-06 | Não respondido parcial (1 modelo respondido, outro não) conta 1 só no modelo faltante | Feature | `php artisan test --filter=NpsFloorMultiModeloTest` | ❌ Wave 0 |
| NPSFLOOR-07 | Resposta tardia (após fechamento) não reescreve a nota definitiva (D2) | Feature | `php artisan test --filter=NpsFloorDesempenhoTest` (caso dedicado) | ❌ Wave 0 |
| NPSFLOOR-08 | Comando de backfill idempotente + `--dry-run` com relatório antes/depois | Feature (Console) | `php artisan test --filter=NpsMaterializarNaoRespondidosCommandTest` | ❌ Wave 0 |
| NPSFLOOR-08b | Backfill de competência fechada **reconsolida** o `DesempenhoScoreSnapshot` mensal (o retroativo chega ao ranking / Relatório de Bonificação / auditoria, não só ao `compute()`); `--desfazer` reconsolida de volta | Feature (Console) | `php artisan test --filter=NpsMaterializarNaoRespondidosCommandTest` (casos com asserção sobre `DesempenhoScoreSnapshot`) | ❌ Wave 0 |
| NPSFLOOR-09 | UI da área NPS explicita a regra em linguagem simples, sem jargão | Manual + payload | asserção de chave no payload Inertia + checkpoint visual | ⚠️ parcial (ver manuais) |
| NPSFLOOR-10 | Suite existente verde após bump da cacheKey | Regressão | `php artisan test` | ⚠️ requer atualizar os 4 arquivos de Q8 |
| NPSFLOOR-11 | Disparo **manual** sem resposta também vira 1, inclusive sem `month_reference` (D6) | Feature | `php artisan test --filter=NpsFloorAreaNpsTest` (caso dedicado) | ❌ Wave 0 |
| NPSFLOOR-12 | Dimensão **empresa** também recebe o 1 nos cards da área NPS (D7) | Feature | `php artisan test --filter=NpsFloorAreaNpsTest` (caso dedicado) | ❌ Wave 0 |

---

## Requisitos de Wave 0

- [ ] `tests/Feature/Phase116/NpsFloorAreaNpsTest.php` — cobre NPSFLOOR-01/03/11/12 + o ramo novo de invalidação na área NPS (NPSFLOOR-04)
- [ ] `tests/Feature/Phase116/NpsFloorDesempenhoTest.php` — cobre NPSFLOOR-02/05/07
- [ ] `tests/Feature/Phase116/NpsFloorMultiModeloTest.php` — cobre NPSFLOOR-06
- [ ] `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` — cobre NPSFLOOR-08 **e NPSFLOOR-08b** (fixture no molde de `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php`, única do repo que faz `desempenho:consolidar-mes` gravar snapshot em SQLite)
- [ ] Atualizar (não criar) os arquivos que hardcodam `desempenho.compute.v11`: `tests/Feature/DesempenhoShopeeScoreTest.php:363`, `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php:246,347`, `tests/Feature/V18/DesempenhoMetadadosCacheTest.php:232,258,260,277`
- [ ] **Auditoria de fixtures (não é gap de framework, é risco real):** varrer os 7 arquivos listados em Q8 do RESEARCH atrás de surveys `pending`/`expired` criados "de passagem" — hoje são ignorados na média; com a imputação ligada passam a puxar 1 e quebram asserções que nada têm a ver com a fase

---

## Verificações Só-Manuais

| Comportamento | Requisito | Por que manual | Instruções |
|---------------|-----------|----------------|------------|
| Texto explicativo da regra na tela de NPS é compreensível sem jargão | NPSFLOOR-09 | Legibilidade é julgamento humano; teste automatizado só garante que a chave existe no payload | Abrir `/nps`, conferir que a distinção "respondidos × não respondidos contados como 1" está visível e que nenhum termo técnico (assignment, imputação, penalização) aparece na tela |
| Relatório `--dry-run` do backfill bate com a realidade antes de aplicar | NPSFLOOR-08 / D1 | O usuário precisa **conferir e aprovar** o impacto no bônus antes da escrita — é um gate de negócio, não de código | Rodar `php artisan nps:materializar-nao-respondidos --dry-run` no VPS, revisar a tabela antes/depois por pessoa e competência **e o plano de reconsolidação** (quais competências terão o snapshot congelado reescrito), só então rodar sem `--dry-run` |
| Impacto aprovado aparece de fato nas telas de mês fechado | NPSFLOOR-08b / D1 | Só verificável contra dados reais depois de aplicar | Depois do backfill: conferir ranking de `/performance`, Relatório de Bonificação e auditoria de bônus da competência aplicada; se ainda mostrarem o número velho, a reconsolidação não pegou (checar `Degradados` do gate de margem e re-rodar `desempenho:consolidar-mes --mes=YYYY-MM`) |
| Volume real do backfill retroativo | D1 | MariaDB local indisponível na pesquisa (Q10) | Contar `nps_surveys` com `status != 'completed'` por `month_reference` no ambiente com banco de pé, antes de dimensionar o backfill |

---

## Sign-Off de Validação

- [x] Todas as tasks têm verificação automatizada ou dependência de Wave 0
- [x] Continuidade de amostragem: sem 3 tasks consecutivas sem verify automatizado
- [x] Wave 0 cobre todas as referências MISSING
- [x] Sem flags de watch-mode
- [x] Latência de feedback < 20s
- [x] `nyquist_compliant: true` no frontmatter

**Aprovação:** aprovado em 2026-07-27 pelo plan-checker — RED-antes-de-GREEN confirmado nos 8 planos,
`<verify><automated>` presente em toda task `auto`, nenhuma flag de watch-mode. `wave_0_complete`
permanece `false`: a execução da fase ainda não rodou.
