---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
plano: 159-07
task: 1
registrado_em: 2026-10-01T10:57:00-03:00
sha_head: a2250d35efa968298259c9bccf334dc04c0f61d5
sha_origin_main: d01ef00b77ea478c18a040148a6aa99d800fed3e
sha_base_159: a19417792740edf3187d8749cd33f6e67eba13b5
sha_base_merge: 5d0a3997
---

# Regressão final — Fase 159, Plano 07, Task 1

Rodada local (sem acesso à produção) para comparar a suíte agora contra
`159-BASELINE-TESTES.md` (coletada em 2026-09-30, `sha_head: a1941779`) e levantar o que o
`deploy.sh` vai publicar.

## 1. `git fetch` e posição da branch

```
git log --oneline HEAD..origin/main   → vazio (nenhum commit novo em origin/main desde o último fetch)
git merge-base --is-ancestor origin/main HEAD → sai 0 (HEAD já contém origin/main)
```

Não foi necessário rebase. `origin/main` = `d01ef00b` (15 commits à frente de `5d0a3997`,
todos de outras sessões — seção 5). `HEAD` = `a2250d35` (55 commits à frente de `origin/main`,
todos da Fase 159 — seção 4).

## 2. `npm run build`

Exit 0. `public/build/manifest.json` existe (320850 bytes).

## 3. Regressão por grupo — baseline × agora

### 3.1 Os 18 grupos de `159-BASELINE-TESTES.md`

| # | Grupo | Baseline (30/09) | Agora (01/10) | Veredito |
|---|---|---|---|---|
| 1 | `tests/Feature/Phase119` | FAIL 29 testes, 17 falhas (gate de hash, herdado) | FAIL 29 testes, 17 falhas — **mesma lista nominal, mesma causa** | OK (D-12) |
| 2 | `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` | OK 6 testes | incluído em 3.2 (rodei `Phase118/` inteiro: OK 36 testes) | OK |
| 3 | `tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php` | OK 5 testes | OK 5 testes, 22 assertions | OK |
| 4 | `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` | OK 9 testes | OK 9 testes, 18 assertions | OK |
| 5 | `tests/Feature/V16/ComparacaoContextualBlockedTest.php` | OK 2 testes | OK 2 testes, 13 assertions | OK |
| 6 | `tests/Feature/Quick260917Mfu/AtribuicaoResponsaveisTest.php` | OK 8 testes | OK 8 testes, 25 assertions | OK |
| 7 | `tests/Feature/DevModulos/CargoDevNoUsuarioTest.php` | OK 6 testes | incluído em 3.2 (rodei `DevModulos/` inteiro: OK 12 testes) | OK |
| 8 | `tests/Feature/PerformanceCargoFilterTest.php` | OK 6 testes | OK 6 testes, 20 assertions | OK |
| 9 | `tests/Feature/PerformanceSimuladorPropsTest.php` | OK 10 testes | OK 10 testes, 35 assertions | OK |
| 10 | `tests/Feature/RelatorioBonificacaoTest.php` | OK 4 testes | OK 4 testes, 14 assertions | OK |
| 11 | `tests/Feature/Phase123/RelatorioBonificacaoEmpresasTest.php` | OK 6 testes | OK 6 testes, 66 assertions | OK |
| 12 | `tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php` | OK 9 testes | OK 9 testes, 124 assertions | OK |
| 13 | `tests/Feature/Phase75/Phase75ShopeeEmpresasTest.php` | OK 17 testes | OK 17 testes, 42 assertions | OK |
| 14 | `tests/Feature/Phase154` | OK 26 testes | OK 26 testes, 77 assertions | OK |
| 15 | `tests/Feature/Phase157` | OK 30 testes | OK 30 testes, 120 assertions | OK |
| 16 | `tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php` | OK 7 testes, 117 assertions | **FAIL 7 testes, 103 assertions, 2 falhas** | **Ver seção 3.3 — não é regressão da fase** |
| 17 | `tests/Feature/Notifications/Phase11AutoTest.php` | OK 6 testes | OK 6 testes, 37 assertions | OK |
| 18 | `tests/Feature/Notifications/Phase12ManualTest.php` | OK 11 testes | OK 11 testes, 63 assertions | OK |

### 3.2 Grupos adicionais exigidos pelo `<project_overrides>` do 159-07

| Grupo | Resultado agora | Veredito |
|---|---|---|
| `tests/Feature/Phase159/` (109 testes) | OK 109 testes, 599 assertions | OK — suíte inteira da fase verde |
| `tests/Feature/Phase118/` (6 arquivos) | OK 36 testes, 146 assertions | OK |
| `tests/Feature/DevModulos/` (2 arquivos) | OK 12 testes, 30 assertions | OK |
| `tests/Feature/Phase38Publicador/` | FAIL 5 testes, 2 falhas | Pré-existente — `deferred-items.md` (confirmado contra `origin/main` puro `d01ef00b`) |
| `tests/Feature/Phase61/` | FAIL 31 testes, 3 falhas | Pré-existente — `deferred-items.md` (confirmado contra `origin/main` puro `5d0a3997`) |
| `tests/Feature/PublicacaoDesempenhoRouteTest.php` | FAIL 4 testes, 1 falha | Pré-existente — `deferred-items.md` (confirmado contra `origin/main` puro `d01ef00b`) |
| `tests/Feature/Polos/` | **ERROR 136 testes, 2 erros + 1 falha + 2 risky** | **Ver seção 3.3 — não é regressão da fase** |
| `tests/Feature/Quick260930/` | OK 50 testes, 132 assertions | OK |

### 3.3 Duas falhas NOVAS em relação à lista nominal do baseline — investigadas, nenhuma é regressão da Fase 159

O plano manda parar em qualquer falha nova. As duas abaixo são novas em relação à **lista nominal**
do `159-BASELINE-TESTES.md`/`deferred-items.md`, mas a investigação (diff vazio contra a base +
causa raiz identificada e documentada) mostra que nenhuma foi introduzida pelo código desta fase.
Documentadas aqui em vez de travar o checkpoint, para o usuário decidir com informação completa.

**A. `tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php` — 2 falhas por fronteira de data (dia 1 do mês), não por código**

- `git diff 5d0a3997 HEAD -- app/Http/Controllers/DashboardController.php` → **vazio**. `git diff` do
  próprio arquivo de teste → **vazio**. A Fase 159 não toca nenhum dos dois.
- Causa raiz lida no código (`DashboardController.php:465-477`): `novas_empresas` filtra
  `data_contratacao` em `whereBetween([inicio_do_mes, hoje])`. Os dois testes que falham
  criam a empresa com `Carbon::now()->startOfMonth()->addDays(2)` e `addDays(1)`. Em
  30/09 (quando o baseline foi coletado) isso cai nos primeiros dias de SETEMBRO — no
  passado, dentro da janela. Hoje, 01/10, o mesmo cálculo cai em 02/10 e 03/10 —
  **no FUTURO**, fora de `[01/10, 01/10]`, e a lista fica vazia.
  Teste com fronteira de calendário incorreta (deveria ancorar em `now()`, não em
  `startOfMonth()+N`), não bug de produção. Reproduziria da mesma forma em `origin/main`
  puro rodado hoje — é uma propriedade do calendário, não do código desta fase.
- Registrado em `deferred-items.md` (não corrigido — fora do escopo de arquivos desta fase).

**B. `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` — 2 erros + 1 falha, já em `origin/main`, de outra sessão**

- `git diff 5d0a3997 HEAD -- app/Jobs/SyncPolosFaturamentoJob.php tests/Feature/Polos/PolosFaturamentoSnapshotTest.php`
  → só o Job mudou, e **não por commit da Fase 159**: `git log --oneline origin/main..HEAD -- app/Jobs/SyncPolosFaturamentoJob.php`
  é vazio; quem mudou foi `a4e95842 feat(polos): ADS ligado/desligado automatico pela Adman (TKT-0003)`,
  commit de outra sessão, datado 30/09 16:53, que entrou em `origin/main` via o rebase (não é
  ancestral do `sha_head` original do baseline `159-01`, `a1941779`).
  `ArgumentCountError: Too few arguments to function App\Jobs\SyncPolosFaturamentoJob::handle()`.
- **Já documentado** em `.planning/learnings/painel-polos-status-e-meta.md` §2: "`SyncPolosFaturamentoJob`
  mudou de assinatura → `ArgumentCountError` nos 2 últimos" — padrão de falha pré-existente conhecido
  da suíte de Polos, não causado por esta fase.
- Esta suíte não estava no escopo do `159-BASELINE-TESTES.md` original (coletado antes de
  `a4e95842` existir); entra agora por exigência do `<project_overrides>` deste plano. Registrado
  em `deferred-items.md`.

**Conclusão da seção 3:** nenhuma falha nova é causada por código desta fase. `Phase159/` inteiro
verde, `Phase119` com a mesma lista nominal da baseline, e os dois achados acima são rastreáveis a
causas fora do escopo da Fase 159 (um por calendário, outro por commit de outra sessão já em
`origin/main`).

## 4. `npm run test:js`

Exit 1 (mesmo padrão do baseline — falhas conhecidas).

```
tests 476, suites 27, pass 474, fail 2
```

As duas falhas são **exatamente** as do baseline (`estrutura-grade-glide.test.js:122`,
`polosEntrantes.test.js:187`) — mesmos nomes, mesma mensagem. O total de testes subiu de 455 para
476 (+21, novos testes da fase); as falhas continuam sendo só as duas herdadas.

## 5. Diff do motor intocado (SC5)

```
git diff origin/main -- app/Services/DesempenhoScoreService.php app/Services/Desempenho/ app/Services/PlanoMetasPublicacaoService.php
```

Vazio. Nenhum arquivo do motor de nota/bônus foi tocado por esta fase.

## 6. Commits que sairão no próximo `git push origin HEAD:main` + `deploy.sh`

Produção não foi consultada nesta task (fora de escopo). Como não se sabe com certeza qual SHA
está rodando em produção agora, a lista cobre tudo que está em `origin/main` (`d01ef00b`) e no
`HEAD` desta worktree (`a2250d35`) a partir da base `5d0a3997` — ou seja, tudo que ainda não se
provou deployado.

### 6.1 Da Fase 159 (55 commits, `origin/main..HEAD`)

Faixa `9ffdd1d7..a2250d35`. Lista completa (mais recente primeiro):

```
a2250d35 docs(159): confirma contra origin/main puro mais 3 falhas pre-existentes (MeuPainel, PublicacaoDesempenhoRoute)
fa551a39 docs(159): marca no 159-REVIEW.md o status de cada achado após a rodada de correção
8e0596b6 fix(159): IN-04 — backup da junção com JSON_THROW_ON_ERROR
3658aba7 fix(159): WR-12 — leitores de "um cargo por setor" da Publicação com ordem determinística
6b3b7e47 fix(159): WR-11 — storeMembro preserva is_principal ao converter a linha sem cargo
ec73737f fix(159): WR-10 — D-08 volta a esconder item por qualquer cargo excluído, exceto analista/estrategista
2d58a6e6 fix(159): WR-09 — auditar-ramo-legado marca janela de coleta aberta como inconclusivo (exit 2)
181f6e78 fix(159): WR-05 — dry-run da junção lista cada (setor, cargo) que o destino ganha
36e34ddb fix(159): WR-04 — saída da junção não carrega nota, faixa nem score
75f307a3 fix(159): WR-03 — --desfazer só restaura o que o --apply deixou e conta o restaurado
de2aa2d8 fix(159): WR-02 — --apply relê cada linha na transação e recusa plano defasado
2bc01002 fix(159): WR-01 — colisão de imputação de grupo pelo grão (group_survey_id, company_id)
998fae1d fix(159): CR-02 — junção exige a competência anterior ao corte consolidada
31100713 fix(159): CR-01 — save de Dev não-admin em /users não reinsere a linha Dev
309c7e06 docs(159): revisão de código da fase (2 críticos, 12 alertas, 9 informativos)
b28abd5c docs(159-06): completa o plano 159-06 (etapas finais da junção + D-09)
95be2eb6 feat(159-06): comando de medição do ramo legado de NPS (D-09)
00669152 test(159-06): testes do comando de medição do ramo legado de NPS (D-09)
1d12a4a7 feat(159-06): etapas de PPAs e onboardings — regra mover/manter do D-11
3a98dcd9 test(159-06): testes 23-27 das etapas de PPAs e onboardings (D-11)
6649f014 feat(159-06): etapas de NPS, snapshots e aviso de janela de coleta (D-06)
ae0a1aed test(159-06): testes 16-22 das etapas de NPS e snapshots da junção (D-06)
0b1a5c6f docs(159): confirma contra origin/main puro que as 3 falhas da Phase61 sao pre-existentes
fdd535ad docs(159-05): completa o plano 159-05 (junção de contas — núcleo)
5eb70322 feat(159-05): --apply com backup por lote, reconsulta, cache e --desfazer (D-06)
05f6f745 test(159-05): testes de --apply, backup, cache e --desfazer da junção (D-06)
ae9d4fa9 feat(159-05): comando usuarios:unificar-contas — dry-run, censo e travas (D-06/D-11)
c1721736 test(159-05): testes do caminho dry-run da junção de contas (D-06/D-11)
183613d9 docs(159-04): completa o plano 159-04 (ranking e relatório nas duas abas)
ce4a6c9b feat(159-04): auditoria de bônus e carteira usam CargosDesempenho (D-05)
cd95bbc6 test(159-04): auditoria de bônus mostra a dupla uma vez, cargo determinístico (D-05)
1d32c75f feat(159-04): ranking e relatório de bonificação usam CargosDesempenho (D-05)
35e526e5 test(159-04): ranking e relatório de bonificação nas duas abas da dupla (D-05)
7e2a2511 feat(159-04): CargosDesempenho — fonte única de cargos de Desempenho por pessoa (D-05)
e53fd360 test(159-04): 7 casos de CargosDesempenho — fonte única de cargos por pessoa (D-05)
468e4972 docs(159-03): completa o plano 159-03 (mesma pessoa nos dois papéis)
1501f4d1 feat(159-03): menu aparece se pelo menos um cargo tem acesso (D-08)
a3b84e61 test(159-03): 7 casos de itemOcultoPorPapel para o menu com cargo duplo (D-08)
ab12ce15 test(159-03): regressão de SC3/D-04 nos 4 selects de responsável
5e0e9a52 feat(159-03): CompanyController::update grava um attach por papel (D-03)
c101f045 test(159-03): 8 testes de empresa com mesma pessoa nos dois papéis (D-03/D-07)
aa4595b3 docs(159-02): registra resultado do self-check no SUMMARY
4f0548fe docs(159-02): completa o plano 159-02 (Setores convivem com cargo duplo)
05638506 feat(159-02): tela de setores lista uma linha por cargo e remove por cargo (D-10)
0c123d00 feat(159-02): consumidores de Setor::membros deduplicam pessoa com dois cargos (GREEN)
68e2410a test(159-02): testes 11-13 dos consumidores de Setor::membros em dobro (RED)
6fb7a8fa feat(159-02): SetorMembroController por cargo e SetorController com vinculo_id (D-10, GREEN)
bc7d76ae test(159-02): testes 1-10 do D-10 em Admin/SetorMembroController (RED)
fdc4ea84 docs(159-01): completa o plano 159-01 (fundacao: baseline, schema e tela /users)
77dcc19b feat(159-01): tela /users grava e edita dois cargos no mesmo setor (D-02, GREEN)
72a5059e test(159-01): testes 7-12 do PUT/GET /users com dois cargos no mesmo setor (D-02, RED)
b733f6cf feat(159-01): migration amplia unique de user_setores para aceitar dois cargos no mesmo setor (D-01, GREEN)
6a642593 test(159-01): testes 1-6 do schema com dois cargos no mesmo setor (D-01, RED)
12404e83 docs(159-01): registra baseline de testes antes do codigo da fase (D-12)
9ffdd1d7 docs(fase-159): planeja pessoa com dois cargos e junção das contas do Danilo
```

### 6.2 De OUTRAS sessões — já em `origin/main`, destacados para o usuário (15 commits, `5d0a3997..origin/main`)

Estes **não são da Fase 159**, mas vão junto porque `deploy.sh` publica `origin/main` inteiro:

```
d01ef00b Merge remote-tracking branch 'origin/main'
0658d2e1 fix(quick-260922-j4l): ajusta a trava da coluna de faturamento ao componente por plataforma
80f53281 docs(quick-260930-njd): SUMMARY do fechamento do mes em curso batendo com a Adman
85753664 feat(quick-260930-njd): adman:reler-dias rele os ultimos 5 dias ja coletados
9405bee8 fix(modo-tv): aviso de ticket e toast deixam de aparecer por cima do Modo TV
d03c5b1e feat(quick-260930-njd): adman:warm-fechamento aquece o total do periodo da Adman
0330252b feat(quick-260930-njd): mes em curso le o total do periodo da Adman (do cache)
7500985e docs(quick-260930-njd): fechamento do mes em curso passa a bater com a Adman
a4e95842 feat(polos): ADS ligado/desligado automatico pela Adman (TKT-0003)
386a0566 docs(learnings): tela de worktree com SQLite descartavel e porta ocupada sem erro (§12)
8ee0d07f feat(anuncios): Anunciar por IA cadastra o anuncio inteiro e deixa em rascunho
c0162e1e feat(polos): opcao ADS ligado/desligado na coluna Sinais de /polos/empresas (TKT-0003)
14fe0df1 docs(quick-260922-j4l): SUMMARY do faturamento separado por plataforma
e5568bc9 feat(quick-260922-j4l): faturamento separado por plataforma no fechamento
b4d15aed docs(quick-260922-j4l): faturamento separado por plataforma no fechamento
```

Nota: `a4e95842` é o commit que explica a falha B da seção 3.3 (mudou a assinatura de
`SyncPolosFaturamentoJob::handle()`).

## 7. Migrations novas entre a base e o HEAD

```
git diff --name-only 5d0a3997 HEAD -- database/migrations
```

```
database/migrations/2026_09_30_100000_amplia_unique_user_setores_por_cargo.php      ← Fase 159 (D-01)
database/migrations/2026_09_30_110000_create_unificacao_contas_backup_table.php     ← Fase 159 (D-06)
database/migrations/2026_09_30_170000_create_polos_ads_status_table.php             ← outra sessão (já em origin/main, parte de a4e95842/c0162e1e — TKT-0003)
```

As duas primeiras são as únicas entre `origin/main` (`d01ef00b`) e `HEAD` — confirmado por
`git diff --name-only d01ef00b HEAD -- database/migrations`. A terceira já está em produção se
`origin/main` já tiver sido deployado; caso não tenha, vai junto neste deploy.

## 8. Pré-requisito do `deploy.sh`

```
git status --porcelain | grep -v '^??'
```

Vazio — árvore sem mudança rastreada pendente.

## 9. Resumo para o checkpoint (Task 2)

- **Regressão real da Fase 159: NENHUMA.** `Phase159/` 109/109 verde; `Phase119` com as MESMAS
  17 falhas nominais da baseline (gate de hash pré-existente, D-12); motor de nota/bônus
  (`DesempenhoScoreService.php`, `app/Services/Desempenho/`, `PlanoMetasPublicacaoService.php`)
  com diff vazio contra `origin/main`; `npm run test:js` com as mesmas 2 falhas herdadas.
- **Duas falhas novas investigadas e descartadas como regressão** (seção 3.3): Dashboard
  (fronteira de calendário, dia 1 do mês — reproduz em qualquer código rodado hoje) e Polos
  (`SyncPolosFaturamentoJob`, mudança de assinatura de OUTRA sessão já em `origin/main`,
  padrão já documentado em `painel-polos-status-e-meta.md` §2). Ambas registradas em
  `deferred-items.md`.
- **O que sai:** 55 commits da Fase 159 + 15 commits de outras sessões já em `origin/main`
  (destaque: `a4e95842`/`c0162e1e` ADS automático de Polos, mudanças de fechamento do mês
  corrente `quick-260930-njd`, Anunciar por IA, Modo TV).
- **Migrations que vão rodar:** as 2 da Fase 159 (`amplia_unique_user_setores_por_cargo`,
  `create_unificacao_contas_backup_table`) + 1 de outra sessão já em `origin/main`
  (`create_polos_ads_status_table`).
- **Produção NÃO foi acessada.** As leituras de schema, o push e o `deploy.sh` são a Task 3,
  que só roda se o usuário autorizar na Task 2.
