---
phase: 167
slug: cadastro-de-produto-no-mapeamento-estrutural
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-10-05
---

# Phase 167 — Validation Strategy

> Contrato de validação por fase: como cada requisito é amostrado durante a execução.
> Fonte: `167-RESEARCH.md` §Validation Architecture. Mapa por tarefa preenchido no planejamento (17 planos).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.55 (SQLite `:memory:`, cache `array`, fila `sync`) + `node:test` (`npm run test:js`) |
| **Config file** | `phpunit.xml`; `tests/js/_fonte.js` |
| **Quick run command** | `C:/xampp/php/php.exe -d memory_limit=1024M vendor/phpunit/phpunit/phpunit tests/Feature/PortalCliente/Estrutura/Produtos` |
| **Full suite command** | `...phpunit tests/Feature/PortalCliente/Estrutura` + `tests/Unit/PortalEstrutura` + os 4 consumidores do Publicador + `DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` + `node --test tests/js/estrutura-grid-produtos.test.js tests/js/estrutura-produtos*.test.js` |
| **Estimated runtime** | ~30 s (rápido) · alguns minutos (suíte da fase) |

Rodar sempre DENTRO do worktree (`.planning/learnings/autoloader-compartilhado-entre-worktrees.md`); a suíte inteira estoura 512 MB — rodar por diretório; nunca `| tail` (engole o exit code).

---

## Sampling Rate

- **After every task commit:** o arquivo de teste da tarefa + `node --test` do arquivo JS tocado
- **After every plan wave:** diretório `Produtos` + `tests/Feature/PortalCliente/Estrutura` + consumidores do Publicador + os 2 testes de domínio do portal
- **Before `/gsd:verify-work`:** tudo acima verde + verificação manual no MariaDB (PR167-14) + `npm run build` com a página no manifest
- **Max feedback latency:** 30 s no comando rápido

---

## Per-Task Verification Map

Os testes de cada tarefa são escritos pela PRÓPRIA tarefa (`tdd="true"`), no mesmo commit do código — não há Onda 0 separada.

| Plano · Tarefa | Requirement | Comportamento | Tipo | Arquivo / comando | Status |
|----------------|-------------|---------------|------|-------------------|--------|
| 167-01 T1 | PR167-14 | baseline G1–G6 commitado sozinho | doc | `git show --stat --format=%s HEAD` | ⬜ pending |
| 167-01 T2 | PR167-02 | 6 tabelas, unique por empresa, cascatas | feature | `Produtos/SchemaDosProdutosTest.php` | ⬜ pending |
| 167-01 T3 | PR167-05, PR167-14 | `variacao_id` unique + FK SET NULL, sem backfill, up/down idempotente; driver mariadb; prova no MariaDB local | feature + manual | `SchemaDosProdutosTest` + `Publicador/MigracoesDaFaseDetectamMariaDbTest.php` + `tests/Feature/PortalCliente/Estrutura` | ⬜ pending |
| 167-02 T1 | PR167-10 | NumeroBr e VolumesTexto | unit | `tests/Unit/PortalEstrutura` | ⬜ pending |
| 167-02 T2 | PR167-09, PR167-10, PR167-11 | gabarito ME2/Full/ME1/pendente, tabela ECF (−0,0001), pendências, sem literal 79/6000 | unit | `tests/Unit/PortalEstrutura` | ⬜ pending |
| 167-03 T1 | PR167-03 | listas por empresa, normalização, "em uso", 404 | feature | `Produtos/ListasDaEmpresaTest.php` | ⬜ pending |
| 167-03 T2 | PR167-12 | oferta ligada protegida, sincronizar, variacao_id na visão | feature | `Produtos/OfertaLigadaNaListaSkusTest.php` + `tests/Feature/PortalCliente/Estrutura` + `Publicador/OfertaExcluidaNoPortalTest.php` | ⬜ pending |
| 167-04 T1 | PR167-07 | colar crescendo, makeRow, onRowsCommit, tabWrap; Onboarding intacto | js gate | `node --test tests/js/estrutura-grid-produtos.test.js tests/js/estrutura-grid-textarea.test.js` | ⬜ pending |
| 167-04 T2 | PR167-07 | picker, variant portal, rowActions/rowNote, selecionar | js gate + build | idem + `npm run build` | ⬜ pending |
| 167-05 T1 | PR167-10 | estimativa pela tabela, preço de cotação, instável | feature | `Produtos/FreteDoProdutoTest.php` | ⬜ pending |
| 167-05 T2 | PR167-10 | API em lote ≤ 12, cache, sem token, só GET, D-19 | feature (`Http::fake`) | `Produtos/FreteDoProdutoTest.php` | ⬜ pending |
| 167-06 T1 | PR167-02, PR167-04 | leitura da linha (ordinal, eixo, ambientes, categoria, números) | unit | `tests/Unit/PortalEstrutura/NormalizadorDeLinhaTest.php` | ⬜ pending |
| 167-06 T2 | PR167-11 | linha com logística, frete, pendências, paginação | feature | `Produtos/ProdutoLinhasTest.php` | ⬜ pending |
| 167-06 T3 | PR167-02, PR167-03, PR167-04 | gravarLinhas (código único, cópia da 1ª, listas, categoria, origem) | feature | `Produtos/CadastroDeProdutoTest.php` | ⬜ pending |
| 167-07 T1 | PR167-05, PR167-13 | uma oferta por variação, antigas intactas, SKU repetido, espera, D-18 | feature | `Produtos/OfertaLigadaAoProdutoTest.php` | ⬜ pending |
| 167-07 T2 | PR167-12 | excluir variação (componente bloqueia, espera, D27) | feature | `Produtos/CicloDeVidaDoProdutoTest.php` + `OfertaExcluidaNoPortalTest` | ⬜ pending |
| 167-08 T1 | PR167-06 | custo da variação na Precificação, combos, salvarOferta recusa | feature | `Produtos/CustoDoProdutoNaPrecificacaoTest.php` + `PrecificacaoEstruturaTest` | ⬜ pending |
| 167-08 T2 | PR167-06 | Publicador herda (DadosEfetivos) + regressão G2 | feature | idem + 4 consumidores | ⬜ pending |
| 167-09 T1 | PR167-08 | modelo 11 colunas, leitor seguro (zip, limites, sem fórmula) | feature | `Produtos/ModeloEImportacaoTest.php` (fixture sintética) | ⬜ pending |
| 167-09 T2 | PR167-08 | prévia/aplicar, refaz plano, nada apagado | feature | `Produtos/ModeloEImportacaoTest.php` | ⬜ pending |
| 167-10 T1 | PR167-01 | menu, rotas, allowlist, entrada D-21, página para cliente e equipe | feature | `Produtos/AcessoAosProdutosTest.php` + `DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` + `AcessoAoModuloEstruturaTest` | ⬜ pending |
| 167-10 T2 | PR167-01, PR167-07 | POST linhas, exclusão e listas por JSON; 404 de outra empresa; origem cliente/interno | feature | `Produtos/GravarLinhasTest.php` + `Produtos/AcessoAosProdutosTest.php` | ⬜ pending |
| 167-10 T3 | PR167-04, PR167-08, PR167-10 | modelo, importação, categorias (app token), fretes por HTTP | feature | `Produtos/CategoriaDoProdutoTest.php` + `GravarLinhasTest` | ⬜ pending |
| 167-11 T1 | PR167-07 | página com a grade e gravação por linha | build + grep | `npm run build` (manifest) | ⬜ pending |
| 167-11 T2 | PR167-07, PR167-12 | exclusão D-22 na tela; página real nos testes | js gate + feature | `tests/js/estrutura-produtos.test.js` + `AcessoAosProdutosTest` | ⬜ pending |
| 167-12 T1/T2 | PR167-06, PR167-12 | selo "do Produtos", campos protegidos, custo somente leitura | js gate + build | `tests/js/estrutura-produtos-ligadas.test.js` | ⬜ pending |
| 167-13 T1/T2 | PR167-03, PR167-07 | pickers de família/ambiente e editor de volumes | js gate + build | `tests/js/estrutura-produtos-pickers.test.js` | ⬜ pending |
| 167-14 T1/T2 | PR167-04, PR167-10 | categoria na célula, sugestões desmarcadas, estados do frete | js gate + build | `tests/js/estrutura-produtos-ml.test.js` | ⬜ pending |
| 167-15 T1/T2 | PR167-08, PR167-03 | janelas de importação e de listas | js gate + build | `tests/js/estrutura-produtos-janelas.test.js` | ⬜ pending |
| 167-16 T1/T2 | PR167-07 | celular: cartões e Sheet | js gate + build | `tests/js/estrutura-produtos-mobile.test.js` | ⬜ pending |
| 167-17 T1 | PR167-14, PR167-09 | gate contra o baseline, MariaDB final, gabarito real (contagens) | feature + manual | `tests/Feature/PortalCliente/Estrutura` + `tests/Unit/PortalEstrutura` | ⬜ pending |
| 167-17 T3 | PR167-07, PR167-10, PR167-14 | conferência visual e do banco pelo usuário | manual (checkpoint) | — | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Migration em `estrutura_ofertas` no MariaDB 10.4 | PR167-14 | o SQLite dos testes não pega 1059/1830/1553 nem collation | 167-01 T3 e 167-17 T1: `migrate --path` das migrations da fase; `SHOW CREATE TABLE` + `SHOW INDEX`; `rollback --path` e re-up; contagem de `estrutura_ofertas` igual antes e depois |
| Gabarito da planilha real | PR167-09 | o arquivo do cliente não entra no repositório nem em teste | 167-17 T1: script no scratchpad, só contagens (esperado 55 ME1 / 8 ME2 / 6 Full / 1 pendente) |
| Frete ME2 real por API | PR167-10 | precisa de conta do ML conectada | só leitura, com "pode" do usuário; senão pendente para depois do deploy |
| Conferência visual da tela de Produtos | PR167-07 | aprovação do usuário (UI-SPEC) | 167-17 T2/T3: SQLite isolado, dados sintéticos, capturas e checkpoint humano |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify (o checkpoint 167-17 T3 é humano por definição)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Testes de cada tarefa criados pela própria tarefa (tdd), no mesmo commit
- [x] No watch-mode flags
- [x] Feedback latency < 30s no comando rápido
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** planejado em 2026-10-05; execução pendente
