# Plano 118-01 — SUMMARY

**Fase:** 118 — NPS por empresa (v21.0) · **Wave 1 de 2**
**Requirements:** NPSE-01, NPSE-02, NPSE-03
**Concluído:** 2026-07-28
**Status:** ✅ completo — 3/3 tasks

## Commits

| Commit | Task | Conteúdo |
|---|---|---|
| `c7fdac01` | 1 | `NpsJanelaResolver` (régua de LEITURA da janela M+1) + chave `servico_id` em `NpsImputationService::notasDoUsuario()` + `NpsJanelaResolverTest` |
| `81c64d8d` | 2 | `NpsPorEmpresaService` — os 3 ramos agrupados por `company_id` + `NpsPorEmpresaContratoTest` |
| `71aad168` | 3 | `NpsPorEmpresaRamosTest` (reconciliação) + `NpsPorEmpresaJanelaTest` (3 casos da janela) |

## O que foi entregue

**`app/Services/Nps/NpsJanelaResolver.php`** — régua de LEITURA da janela M+1, com `gte`.

**`app/Services/Desempenho/NpsPorEmpresaService.php`** — `notasNpsPorEmpresa(User $user, Carbon $mes, bool $mesFechado, ?Collection $invalidadas = null): Collection`, chaveada por `company_id`, com shape auditável:

```
company_id, nota, origem, total_notas,
por_ramo{atribuicao, legado, imputada},
por_papel, papeis, servico_ids, consolidado,
notas_brutas, houve_survey
```

`notas_brutas` e `por_ramo` guardam o estado **pré-resolução da D-03**, que é o que permite ao plano 118-02 asserir o filtro multi-serviço. `houve_survey` é computado por query real (`whereIn`), distinguindo "nunca disparou" de "gap de atribuição" — não é chutado.

**`NpsImputationService::notasDoUsuario()`** — única edição em arquivo de produção existente: **5 linhas**, acrescentando a chave `servico_id` ao `->map()`. Verificado antes de aplicar que os 3 consumidores (`DesempenhoScoreService:1060` via `->pluck('nota')`, `PerformanceController:870`, `PortfolioController:2267`) leem por nome, nenhum posicional.

## Verificação

| Gate | Resultado |
|---|---|
| `--filter=Phase118` | **24/24 verdes** (98 asserções) |
| `--filter=Phase116` | **71/71 verdes** — a chave nova não regrediu nada |
| Aditividade: `sha256sum app/Services/DesempenhoScoreService.php` | `cfc16da2a8404fba…9edd` — **byte-a-byte intocado** |
| `--filter=Desempenho` | ⏳ execução em andamento no fechamento deste SUMMARY — baseline esperada de **14 falhas pré-existentes** (debug de margem já aberto, documentado no `deferred-items.md` da Fase 116 e reconfirmado na Fase 117). Qualquer número acima disso é regressão e deve ser investigado. |

## Decisões honradas

- **C-01 — a régua NÃO foi unificada.** `NpsJanelaResolver` nasce com `gte` (leitura); `NpsImputationService::materializar()` segue com `gt` (materialização). A divergência é deliberada e documentada em `NpsImputationService.php:121-127` — unificar congelaria a nota 1 com o cliente ainda dentro do prazo de 24h. `test_boundary_ultimo_dia_de_m_mais_1_as_14h_ja_conta_como_fechada` prova a régua `gte` explicitamente, e `test_resolver_concorda_com_computeNpsWindow_nos_tres_casos` compara a implementação nova com a original (via reflection) nos 3 boundaries.
- **C-04 — dedupe em PHP** no serviço novo; a dedupe SQL original de `notasPorAtribuicao()` ficou intocada.
- **D-01** — nota da dimensão do papel, nunca da dimensão `empresa`. Provado por `test_d01_estrategista_e_analista_da_mesma_empresa_recebem_notas_diferentes`.
- **D-02** — média dos papéis **depois** da dedupe. Provado por `test_d02_papeis_acumulados_na_mesma_empresa_viram_media_e_a_empresa_pesa_uma_vez`, que assere `count() === 1`.

## Incidente de execução (sem impacto no resultado)

O agente executor foi **interrompido pelo limite de sessão** durante a Task 3, enquanto rodava a suíte de regressão ampla. As Tasks 1 e 2 já estavam commitadas; os dois arquivos de teste da Task 3 estavam escritos **e passando**, mas sem commit.

Recuperação: o orquestrador auditou a árvore (gate de hash intacto, nada rastreado modificado, dois untracked identificados), rodou as suítes para confirmar o estado real, e commitou a Task 3. **Nenhum trabalho foi perdido e nada precisou ser refeito.**

## Débito registrado

A proteção contra divergência `gte`/`gt` é um **teste de equivalência**, não a extração completa. O plan-check confirmou que o teste não é tautológico — compara duas implementações fisicamente separadas. Follow-up para a Fase 119/120, quando o gate de aditividade sair: fazer `computeNpsWindow()` chamar `NpsJanelaResolver`. Registrado em `118-VALIDATION.md`.

## Próximo

Wave 2 — `118-02-PLAN.md`: D-03 (survey do serviço do vínculo + fallback consolidado), invalidação antes do piso da D-04, e o 8º método do teste de coerência. Requirements NPSE-04, NPSE-05, NPSE-06.
