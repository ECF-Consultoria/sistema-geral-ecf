---
phase: 159
plano: 159-01
registrado_em: 2026-09-30
sha_head: a19417792740edf3187d8749cd33f6e67eba13b5
---

# Baseline de testes — Fase 159 (antes de qualquer código novo)

Coletada **antes** da primeira linha de código desta fase, conforme exigido pela Task 1 do plano
`159-01-PLAN.md` (D-12). Nenhum arquivo de `app/`, `database/`, `resources/`, `routes/` ou `tests/`
foi tocado até este ponto — confirmado por `git status --porcelain app/ database/ resources/ routes/
tests/` vazio (ver seção final).

## Pré-condições verificadas

1. **Autoloader aponta para esta worktree** (`.planning/learnings/autoloader-compartilhado-entre-worktrees.md`):
   ```
   C:/xampp/php/php.exe -r "require 'vendor/autoload.php'; echo (new ReflectionClass('App\Http\Controllers\UserController'))->getFileName();"
   ```
   Resultado: `C:\tmp\ecf-cargo-duplo-260930\app\Http\Controllers\UserController.php` — dentro da
   worktree correta. `composer dump-autoload` NÃO foi executado (não era necessário).

2. **`npm run build`** rodou com `exit 0` e gerou `public/build/manifest.json` (existe e tem
   conteúdo — 316929 bytes). Sem isso, testes que renderizam resposta Inertia completa falhariam
   por motivo alheio ao código desta fase (159-RESEARCH.md §9.1).

## Comando executado por grupo

Cada grupo abaixo rodou **separadamente** (a suíte inteira estoura 512 MB de memória — learnings),
com a forma:

```bash
APP_BASE_PATH="$PWD" C:/xampp/php/php.exe vendor/bin/phpunit <alvo> --colors=never > "$ARQ" 2>&1
EXIT=$?   # capturado ANTES de qualquer pipe (learnings §4 — "| tail" engole o exit code)
```

**Armadilha encontrada e corrigida durante a coleta:** a primeira tentativa do script de loop usou
um array bash chamado `GROUPS`, que é uma variável ESPECIAL somente-leitura do bash (lista de
grupos Unix do usuário corrente) — a atribuição falhou silenciosamente e o loop rodou 1x com o
conteúdo antigo de `GROUPS` (o gid numérico do usuário, `197121`), produzindo
`Test file "197121" not found`. Renomear o array para `TEST_GROUPS` resolveu. Registrado aqui
porque não é dedutível do código e pode se repetir em qualquer script bash futuro deste projeto que
declare um array chamado `GROUPS`, `HOSTNAME`, `UID`, `PPID` ou outra variável especial do bash.

## Resultado por grupo (18 grupos PHP)

| # | Grupo | Exit | Resumo PHPUnit |
|---|---|---|---|
| 1 | `tests/Feature/Phase119` | **1** | `FAILURES! Tests: 29, Assertions: 169, Failures: 17.` |
| 2 | `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` | 0 | `OK (6 tests, 33 assertions)` |
| 3 | `tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php` | 0 | `OK (5 tests, 22 assertions)` |
| 4 | `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` | 0 | `OK (9 tests, 18 assertions)` |
| 5 | `tests/Feature/V16/ComparacaoContextualBlockedTest.php` | 0 | `OK (2 tests, 13 assertions)` |
| 6 | `tests/Feature/Quick260917Mfu/AtribuicaoResponsaveisTest.php` | 0 | `OK (8 tests, 25 assertions)` |
| 7 | `tests/Feature/DevModulos/CargoDevNoUsuarioTest.php` | 0 | `OK (6 tests, 19 assertions)` |
| 8 | `tests/Feature/PerformanceCargoFilterTest.php` | 0 | `OK (6 tests, 20 assertions)` |
| 9 | `tests/Feature/PerformanceSimuladorPropsTest.php` | 0 | `OK (10 tests, 35 assertions)` |
| 10 | `tests/Feature/RelatorioBonificacaoTest.php` | 0 | `OK (4 tests, 14 assertions)` |
| 11 | `tests/Feature/Phase123/RelatorioBonificacaoEmpresasTest.php` | 0 | `OK (6 tests, 66 assertions)` |
| 12 | `tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php` | 0 | `OK (9 tests, 124 assertions)` |
| 13 | `tests/Feature/Phase75/Phase75ShopeeEmpresasTest.php` | 0 | `OK (17 tests, 42 assertions)` |
| 14 | `tests/Feature/Phase154` | 0 | `OK (26 tests, 77 assertions)` |
| 15 | `tests/Feature/Phase157` | 0 | `OK (30 tests, 120 assertions)` |
| 16 | `tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php` | 0 | `OK (7 tests, 117 assertions)` |
| 17 | `tests/Feature/Notifications/Phase11AutoTest.php` | 0 | `OK (6 tests, 37 assertions)` |
| 18 | `tests/Feature/Notifications/Phase12ManualTest.php` | 0 | `OK (11 tests, 63 assertions)` |

**17 dos 18 grupos fecham 100% verdes.** Só `tests/Feature/Phase119` tem falha, e é herdada —
ver seção dedicada abaixo.

### `tests/Feature/Phase119` — 17 falhas, todas pela mesma causa (herdada, D-12)

Hash esperado nos 5 arquivos de teste de `Tests\Feature\Phase119`:
`a78b7d2823aa899ef30600d32b608b96c8ae84887588e17df8b058781a6b8794`

Hash atual de `app/Services/DesempenhoScoreService.php` nesta worktree (commit `5d0a3997` herdado de
`origin/main`, sem nenhuma edição desta fase):
`96e4ad1214a41b0918729619777441db05ff4b90d10709c3fa3aebe73190c062`

Mensagem que aparece nos 17 testes: `DesempenhoScoreService.php foi alterado — fase é ADITIVA.`
seguida de `Failed asserting that two strings are identical` comparando os dois hashes acima.

**Falhas herdadas; a Fase 159 não altera `DesempenhoScoreService.php` e não rotaciona a constante
(D-12).** Isto é o MESMO padrão já documentado em `.planning/learnings/desempenho-bonificacao.md`
§0.01 e confirmado em `159-RESEARCH.md` §9.1: algum commit legítimo, anterior a `5d0a3997`, alterou
`DesempenhoScoreService.php` sem rotacionar o hash esperado nos 5 arquivos de teste da Fase 119.
Não é esta fase quem vai consertar isso.

Lista nominal das 17 falhas (todas com a mesma mensagem de hash acima):

1. `CompanyScoreServiceDispatcherTest::test_dispatcher_e_chamado_exatamente_1x_por_empresa_com_fonte_e_0x_sem_fonte`
2. `CompanyScoreServiceDispatcherTest::test_empresa_sem_fonte_nao_lanca_excecao_e_segue_listada_como_sem_fonte`
3. `CompanyScoreServiceFonteTest::test_empresa_com_dois_vinculos_performance_e_shopee_resolve_fonte_adman_e_produz_uma_linha`
4. `CompanyScoreServiceFonteTest::test_empresa_so_shopee_entra_complete_com_margem_placeholder_e_caso_ancora_3_07`
5. `CompanyScoreServiceFonteTest::test_regua_de_margem_nunca_e_aplicada_para_shopee_mesmo_com_diff_pp_fabricado`
6. `CompanyScoreServiceMargemTest::test_fixture_mpp06_pontua_margem_sobre_diff_pp_4_pontos_e_nao_5`
7. `CompanyScoreServiceMargemTest::test_sem_prev_margem_var_pp_e_pontos_ficam_null_com_motivo`
8. `CompanyScoreServiceMargemTest::test_mes_em_curso_margem_var_pp_vem_da_janela_baseline`
9. `CompanyScoreServiceMargemTest::test_regua_de_margem_nunca_recebe_diff_pct`
10. `CompanyScoreServiceReconciliacaoTest::test_universo_elegivel_e_mapa_de_fontes_batem_entre_os_dois_caminhos`
11. `CompanyScoreServiceReconciliacaoTest::test_empresa_invalidada_na_competencia_esta_ausente_nos_dois_caminhos`
12. `CompanyScoreServiceReconciliacaoTest::test_divergencia_de_granularidade_e_esperada_regua_por_empresa_diverge_da_regua_da_media`
13. `CompanyScoreServiceStatusTest::test_status_complete_com_nps_faturamento_e_margem_presentes`
14. `CompanyScoreServiceStatusTest::test_status_partial_margem_ausente_devolve_apenas_parcial_e_motivo_unico`
15. `CompanyScoreServiceStatusTest::test_status_sem_fonte_empresa_polos_com_nps_isolado`
16. `CompanyScoreServiceStatusTest::test_status_sem_dados_todos_os_componentes_ausentes_motivos_na_ordem_deterministica`
17. `CompanyScoreServiceStatusTest::test_os_quatro_status_sao_mutuamente_exclusivos_e_nenhum_status_legado_vaza`

Os 12 testes restantes de `tests/Feature/Phase119` (29 − 17) passam normalmente — não é o
diretório inteiro que está vermelho, é especificamente o gate de hash.

## `npm run test:js`

```bash
npm run test:js > out.txt 2>&1
echo $?   # capturado ANTES de qualquer pipe
```

**Exit code: 1.** Resumo do node `--test`:

```
ℹ tests 455
ℹ suites 24
ℹ pass 453
ℹ fail 2
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
```

Duas falhas, **ambas herdadas e fora do escopo desta fase** (nenhuma toca `user_setores`,
`UserController` ou `Users/Index.jsx`):

1. `tests/js/estrutura-grade-glide.test.js:122` — `Características secundárias nasce recolhido (é
   o grupo que mais infla)`. Regex `/RECOLHIDOS_INICIAIS = \[G_SECUND\]/` não casa porque o código
   fonte atual tem `RECOLHIDOS_INICIAIS = []` — divergência do módulo de grade de anúncios (Mlb),
   não relacionada a cargos/setores.
2. `tests/js/polosEntrantes.test.js:187` — `FASES_TERMINAIS cobre as três fases de saída, com as
   strings exatas da planilha`. Esperado `['Encerrado', 'Protocolo Churn', 'Churn']`, atual inclui
   também `'Desistência'` — divergência do módulo Polos (ver
   `.planning/learnings/painel-polos-status-e-meta.md`), não relacionada a esta fase.

## Regra para o verifier

Qualquer falha nova fora desta lista é regressão da Fase 159. Especificamente:

- `tests/Feature/Phase119` tem de terminar a fase com **EXATAMENTE as mesmas 17 falhas** (mesmos
  nomes de teste, mesma causa de hash) — nem mais, nem menos, nem falha em teste diferente.
- Os outros 17 grupos PHP listados na tabela têm de continuar **100% verdes**.
- `npm run test:js` tem de terminar com as **mesmas 2 falhas** listadas acima (`estrutura-grade-glide`
  e `polosEntrantes`) — qualquer falha nova em outro arquivo `.test.js` é regressão.
- Se `DesempenhoScoreService.php` aparecer em `git diff origin/main -- app/Services/DesempenhoScoreService.php`
  ao fim da fase, isso é uma violação da restrição do plano (arquivo não deve ser modificado nesta
  fase) — não confundir com o gate de hash acima, que é pré-existente e intocado.

## Confirmação final

```bash
git status --porcelain app/ database/ resources/ routes/ tests/
```

Saída: vazia. Nenhum arquivo de código foi alterado durante a coleta desta baseline.

Este documento é o "antes" — não editar depois de criado.
