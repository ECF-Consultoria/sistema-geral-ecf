# Fase 91 — Validation Architecture

> Extração standalone da seção `## Validation Architecture` do `91-RESEARCH.md`,
> para satisfazer o gate Dimension 8 do plan-checker (mesmo movimento da Fase 90).
> Fonte canônica: 91-RESEARCH.md (2026-07-16). Nota de atualização: o arquivo de
> testes novo foi travado pelos planos em `tests/Feature/V16/DesempenhoElegibilidadeTest.php`
> (constraint do orquestrador), não em `tests/Feature/Phase91/` como o research sugeria.

## Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config `phpunit.xml` |
| Config file | `C:\xampp\htdocs\ecf_admin\ecf_admin\phpunit.xml` (SQLite in-memory forçado via `<env>`) |
| Quick run command | `C:\xampp\php\php.exe vendor/bin/phpunit --filter=DesempenhoScoreServiceTest` |
| Full suite command | `C:\xampp\php\php.exe vendor/bin/phpunit --testsuite=Feature` (ou `php artisan test`) |

## Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DESEMP-01 | `computeUniverso` deriva de vínculos, não de `company_id` consolidado | unit/feature | `phpunit --filter=test_fixture_carlos_retorna_nota_4_08_basico` | ✅ (fixture ajustado no Plano 91-01 Task 1 — Pitfall 1) |
| DESEMP-02 | Score permanece único — sem score separado por marketplace | auditoria de ausência (grep) | Gate do Plano 91-02 Task 1 (grep `score_shopee\|score_ml\|ScoreShopee\|ScoreMl` = 0) | ✅ Plano 91-02 |
| DESEMP-03 | `computeNpsMedio` continua via `nps_score_assignments`, intocado | feature (regressão) | `phpunit --filter=BonusDualPathRegressaoTest` | ✅ (suite já existe, 5/5) |
| DESEMP-04 | `computeVarFaturamento`/`computeVarMargem` só usam vínculos elegíveis | feature | `test_misto_e_official_com_financeiro_so_do_vinculo_elegivel` + `test_so_shopee_recebe_blocked_com_nota_null_e_permanece_no_ranking` | ✅ Plano 91-01 Task 1 (V16/DesempenhoElegibilidadeTest) |
| DESEMP-05 | Retorno expõe os 6 metadados novos | feature | `test_compute_expoe_os_6_metadados_de_elegibilidade` | ✅ Plano 91-01 Task 1 |
| DESEMP-06 | Status `official`/`partial`/`blocked`; só-Shopee sem financeiro = blocked (nota null) | feature | `test_so_shopee_recebe_blocked_...` + `test_partial_quando_vinculo_elegivel_sem_dados_financeiros_no_periodo` + `test_misto_e_official_...` | ✅ Plano 91-01 Task 1 |
| DESEMP-07 | `sem_carteira` só quando ZERO vínculos (não zero financeiro) | feature | `test_sem_carteira_so_quando_zero_vinculos_ativos` + asserts de sem_carteira=false no cenário só-Shopee | ✅ Plano 91-01 Task 1 |
| Regressão cache | Bump v3→v4 | feature | `test_cache_bumpado_para_v4` (V16/DesempenhoElegibilidadeTest) + edição do teste 5 do BonusDualPathRegressaoTest | ✅ Plano 91-01 Tasks 1-2 |

## Sampling Rate

- **Per task commit:** `phpunit --filter=DesempenhoScoreServiceTest` (suite bloqueante, ~12 testes, roda em segundos por ser SQLite in-memory)
- **Per wave merge:** `phpunit --filter="DesempenhoScoreServiceTest|DesempenhoElegibilidadeTest|BonusDualPathRegressaoTest"` (Feature completo do domínio Desempenho + regressão NPS)
- **Phase gate:** Suite de domínio (`--filter="Desempenho|Bonus"`) verde antes de `/gsd:verify-work` — especialmente `BonusDualPathRegressaoTest` (5/5) e o fixture Carlos, as 2 âncoras nomeadas pelo usuário. Suite Feature completa roda como informativo (sessão paralela NPS pode ter WIP fora do domínio).

## Wave 0 Gaps (status pós-planejamento)

- [x] Atualizar `criarEmpresaNaCarteira()` em `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` para criar `contratos_servico` ativo (setor performance) + `servico_id` no pivot — Plano 91-01 Task 1 (Pitfall 1).
- [x] Novo arquivo de teste `tests/Feature/V16/DesempenhoElegibilidadeTest.php` cobrindo DESEMP-04/05/06/07 (cenários só-Performance, só-Shopee, misto, dual-vínculo na mesma empresa) reaproveitando a trait `tests/Feature/V16/CriaCenarioResponsaveis.php` — Plano 91-01 Task 1.
- [x] Teste de bump de cache `test_cache_bumpado_para_v4` (lixo sob v3 → nunca servido → v4 gravada) — Plano 91-01 Tasks 1-2.
