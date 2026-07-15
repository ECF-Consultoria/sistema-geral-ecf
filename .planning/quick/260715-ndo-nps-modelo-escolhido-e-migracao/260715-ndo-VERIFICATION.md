---
quick_id: 260715-ndo
verified: 2026-07-15T20:32:39Z
status: passed
score: 11/11 must-haves verified
overrides_applied: 0
---

# Quick Task 260715-ndo: Verification Report

**Task Goal:** (1) `NpsController::generate` deve honrar o modelo NPS escolhido por qualquer usuário autorizado — não só admin. (2) Comando `nps:remigrar-modelo-resposta` que migra as 2 respostas restantes (surveys #102, #106) reatribuindo as notas ao responsável de Performance.

**Verified:** 2026-07-15T20:32:39Z
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Não-admin que escolhe modelo no modal recebe link COM aquele modelo (nunca resolvido por priority) | ✓ VERIFIED | `NpsController.php:404` — gate `isAdmin() &&` removido do bloco de override; teste `test_nao_admin_escolhendo_template_perf_recebe_survey_com_perf_nao_shopee` passa de fato (rodado nesta verificação, não apenas alegado) |
| 2 | Modelo que não cobre serviço ATIVO da empresa é rejeitado com toast, sem criar survey | ✓ VERIFIED | `NpsController.php:421-430` — `$company->contratosServico()->active()->whereIn('servico_id', $servicoIds)->exists()`; teste `test_escopo_rejeita_template_cujo_servico_nao_tem_contrato_ativo` passa, `assertSessionHas('error')` + 0 surveys |
| 3 | Modelo SEM serviços cobertos (pivot vazio, ex. NPS Padrão) continua aceito para qualquer empresa | ✓ VERIFIED | `NpsController.php:422` — guarda só entra `if ($servicoIds->isNotEmpty())`; teste `test_escopo_aceita_template_sem_nenhum_scope_pivot_vazio` passa |
| 4 | Modelo inativo (`active=false`) é rejeitado com toast, sem criar survey | ✓ VERIFIED | `NpsController.php:411-413`; teste `test_escopo_rejeita_template_inativo` passa |
| 5 | Admin continua escolhendo o modelo igual a hoje (sem regressão) | ✓ VERIFIED | Ramo `!empty($data['template_id'])` não distingue mais admin/não-admin — comportamento estendido, não removido; teste `test_admin_escolhendo_template_continua_funcionando` passa |
| 6 | Requisição sem `template_id` continua caindo no `resolveForCompany` (fallback preservado) | ✓ VERIFIED | `NpsController.php:432-436` — ramo `else` intacto; teste `test_sem_template_id_continua_caindo_no_resolve_for_company` passa (survey nasce com o template de maior priority) |
| 7 | Checagem de autorização por empresa (403 fora da carteira) permanece intacta | ✓ VERIFIED | `NpsController.php:370-375` inalterado no diff (`git diff e00b9da^..HEAD`); teste `test_nao_admin_com_empresa_fora_da_carteira_recebe_403` passa |
| 8 | Comando `nps:remigrar-modelo-resposta` reatribui a resposta migrada para os responsáveis do modelo NOVO, não do antigo | ✓ VERIFIED | `NpsRemigrarModeloResposta.php:218-239` — troca `template_id`, apaga snapshot antigo, recongela via `NpsSnapshotService::registrar()`; teste `test_remigra_survey_reatribuindo_para_responsaveis_do_template_novo` passa (prova positiva E negativa: responsável Shopee sai, Performance entra) |
| 9 | Troca de modelo entre clones idênticos NÃO altera o valor da nota | ✓ VERIFIED | Teste `test_valor_da_nota_preservado_apos_remigracao` passa — médias antes/depois idênticas (comparação por `round(2)`) |
| 10 | Rodar o comando 2× não duplica nem altera linhas de snapshot (idempotente) | ✓ VERIFIED | `NpsRemigrarModeloResposta.php:203-208` — idempotência por comparação de `template_id`, sem tolerância decimal (DEC-NDO-7 respeitada, evita o bug de `NpsBackfillDivisorTextoLivre:212`); teste `test_idempotente_segunda_execucao_nao_duplica_nem_altera` passa, reporta `no-op` |
| 11 | `--dry-run` não grava nada e mostra o diff de quem-para-quem | ✓ VERIFIED | `NpsRemigrarModeloResposta.php:128-150` — tudo dentro de `DB::beginTransaction()`, `--dry-run` sempre faz `rollBack()`; teste `test_dry_run_nao_grava_nada` passa |

**Score:** 11/11 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Http/Controllers/NpsController.php` | `generate()` honrando `template_id` de qualquer usuário + validação de escopo | ✓ VERIFIED | Contém `servicos()->pluck` (linha 421); diff restrito ao bloco de resolução de template (linhas ~377-436), nenhum outro método tocado |
| `app/Console/Commands/NpsRemigrarModeloResposta.php` | Comando `nps:remigrar-modelo-resposta` | ✓ VERIFIED | Signature contém `nps:remigrar-modelo-resposta`; registrado via auto-discovery (`Artisan::all()` contém a chave, testado) |
| `tests/Feature/V16/NpsModeloEscolhidoTest.php` | Cobertura RED→GREEN Bug A + escopo + não-regressão | ✓ VERIFIED | 7 testes, todos passam nesta verificação |
| `tests/Feature/V16/NpsRemigrarModeloRespostaTest.php` | Cobertura da remigração | ✓ VERIFIED | 8 testes, todos passam nesta verificação |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `NpsController.php::generate` | `NpsTemplate::servicos() ∩ Company::contratosServico()->active()` | validação de escopo espelhando `empresasElegiveis` | ✓ WIRED | Padrão idêntico aos 2 ramos de `NpsTemplateController::empresasElegiveis:462-487` (mesma condição `isNotEmpty()` + `whereHas`/`whereIn`) |
| `NpsRemigrarModeloResposta.php` | `NpsSnapshotService::registrar` | recongelamento do snapshot | ✓ WIRED | `processarSurvey()` linha 239, chamado dentro da transação aberta em `handle()` |
| `NpsRemigrarModeloResposta.php` | `nps_score_assignments`/`nps_response_covered_services`/`nps_response_scores` | delete do snapshot antigo ANTES do `registrar()` | ✓ WIRED | `processarSurvey()` linhas 224-226, ordem correta (assignments → covered_services → scores, por causa de FK) |

### Behavioral Spot-Checks (executados nesta verificação, não herdados da SUMMARY)

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Suites da task (15 testes) | `php artisan test tests/Feature/V16/NpsModeloEscolhidoTest.php tests/Feature/V16/NpsRemigrarModeloRespostaTest.php` | 15 passed (50 assertions) | ✓ PASS |
| Regressão NPS completa | `php artisan test --filter=Nps` | 204 passed (1194 assertions) | ✓ PASS |
| `git diff --stat` escopo real | `git diff --stat e00b9da^..HEAD` | 4 arquivos exatos (1 controller, 1 comando, 2 suites) | ✓ PASS |
| Falha pré-existente não relacionada | `php artisan test --filter=PublicacaoDesempenhoRouteTest` | 1 falha (403≠200), confirmada via `git log` como do commit `8748d47` (Phase 49-02, 30/06), não tocado por esta task | ✓ PASS (confirma que NÃO é regressão desta task) |

### Pontos de Atenção Específicos (do prompt de verificação)

1. **Gate sumiu de verdade + 403 intacto:** confirmado por leitura direta do código (linhas 370-375 inalteradas no diff) e teste `test_nao_admin_com_empresa_fora_da_carteira_recebe_403` passando.
2. **Validação de escopo com os DOIS ramos (com/sem serviços cobertos):** confirmado — código espelha exatamente `empresasElegiveis` (`isNotEmpty()` → exige contrato ativo; vazio → aceita todas). Não é mais restritivo nem mais permissivo que o modal.
3. **Flash, nunca `abort(422)`:** confirmado — ambas as guardas usam `return back()->with('error', ...)` (linhas 412, 429).
4. **Comando cirúrgico, sem seletor genérico:** confirmado — assinatura só tem `--survey=* --para=`, nenhum seletor por `template_id` existente.
5. **Não repetiu o bug de tolerância 0.0001:** confirmado — `snapshotAtribuicoes()` usa `round((float) $a->average_score, 2)`, comparação exata pós-round.
6. **Empresa com AMBOS os serviços pode gerar DOIS NPS separados:** confirmado indiretamente — nenhuma constraint de unicidade bloqueia (dedup_key é coluna virtual só não-nula para `status='completed' AND month_reference IS NOT NULL`; links manuais têm `month_reference=null`); código não tem lógica de bloqueio por serviço já coberto. Não há um teste único que gera os dois links em sequência na mesma execução, mas a ausência de qualquer guarda de unicidade + os testes que geram cada template independentemente para a mesma empresa-cenário (`cenarioBase`) tornam a alegação verificável no código.
7. **Testes no diretório certo:** confirmado — ambas as suites em `tests/Feature/V16/`; `git diff --stat` não toca nenhum arquivo em `tests/Feature/Phase77..82/`.

### Anti-Patterns Found

Nenhum. Busca por `TBD|FIXME|XXX|TODO|HACK|PLACEHOLDER` nos 4 arquivos retornou apenas um falso-positivo (substring "TODO" dentro da palavra "TODOS" em um comentário de docblock).

### Requirements Coverage

Não aplicável — quick task sem `requirements:` declarados no frontmatter do PLAN (`requirements-completed: []` na SUMMARY). Nenhum REQ-ID mapeado.

### Human Verification Required

Nenhum item requer verificação humana. O comando `nps:remigrar-modelo-resposta` está pronto para uso em produção mas propositalmente NÃO foi executado contra produção nesta task (conforme escopo) — a execução real (`--survey=102 --survey=106 --para=2`) fica para quando o operador decidir rodar, com `--dry-run` primeiro (fora do escopo de verificação de código).

### Gaps Summary

Nenhum gap encontrado. Todos os 11 must-haves (truths) verificados com evidência direta de código + execução real dos testes (não confiança na SUMMARY). Diff restrito exatamente aos 4 arquivos declarados. Regressão completa da suite NPS (204/204) e da suite V16 (15/15 desta task) passou nesta verificação. A única falha observada em `--filter=Desempenho` é pré-existente, confirmada por `git log` como não relacionada a este trabalho.

---
*Verified: 2026-07-15T20:32:39Z*
*Verifier: Claude (gsd-verifier)*
