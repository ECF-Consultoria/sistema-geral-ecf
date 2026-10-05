---
phase: 167
slug: cadastro-de-produto-no-mapeamento-estrutural
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-05
---

# Phase 167 — Validation Strategy

> Contrato de validação por fase: como cada requisito é amostrado durante a execução.
> Fonte: `167-RESEARCH.md` §Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.55 (SQLite `:memory:`, cache `array`, fila `sync`) + `node:test` (`npm run test:js`) |
| **Config file** | `phpunit.xml`; `tests/js/_fonte.js` |
| **Quick run command** | `C:/xampp/php/php.exe -d memory_limit=1024M vendor/phpunit/phpunit/phpunit tests/Feature/PortalCliente/Estrutura/Produtos` |
| **Full suite command** | `...phpunit tests/Feature/PortalCliente/Estrutura` + `tests/Unit/PortalEstrutura` + os 4 consumidores do Publicador + `DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` + `node --test tests/js/estrutura-produtos.test.js` |
| **Estimated runtime** | ~30 s (rápido) · alguns minutos (suíte da fase) |

Rodar sempre DENTRO do worktree (`.planning/learnings/autoloader-compartilhado-entre-worktrees.md`); a suíte inteira estoura 512 MB — rodar por diretório.

---

## Sampling Rate

- **After every task commit:** o arquivo de teste da tarefa + `node --test` do arquivo JS tocado
- **After every plan wave:** diretório `Produtos` + `tests/Feature/PortalCliente/Estrutura` + consumidores do Publicador + os 2 testes de domínio do portal
- **Before `/gsd:verify-work`:** tudo acima verde + verificação manual no MariaDB (PR167-14) + `npm run build` com a página no manifest
- **Max feedback latency:** 30 s no comando rápido

---

## Per-Task Verification Map

> Preenchido pelo planejador: cada tarefa dos `167-NN-PLAN.md` aponta o requisito e o comando.

| Requirement | Comportamento | Test Type | Arquivo / comando | File Exists | Status |
|-------------|---------------|-----------|-------------------|-------------|--------|
| PR167-01 | acesso cliente/equipe, 404 de outra empresa, `origem` no log, allowlist | feature | `Produtos/AcessoAosProdutosTest.php` + `DominioLiberaTodoModuloTest` | ❌ W0 / ✅ | ⬜ pending |
| PR167-02 | produto → variações → volumes; código único por empresa; peso derivado; cópia da 1ª | feature | `Produtos/CadastroDeProdutoTest.php` | ❌ W0 | ⬜ pending |
| PR167-03 | listas da empresa, ambiente múltiplo, normalização, "em uso" bloqueia | feature | `Produtos/ListasDaEmpresaTest.php` | ❌ W0 | ⬜ pending |
| PR167-04 | categoria real do ML, só folha, app token, ML fora não bloqueia | feature (`Http::fake`) | `Produtos/CategoriaDoProdutoTest.php` | ❌ W0 | ⬜ pending |
| PR167-05 | uma oferta simples por variação, `variacao_id` unique, ofertas antigas intactas, migration up/down | feature | `Produtos/OfertaLigadaAoProdutoTest.php` + `MigracoesDaFaseDetectamMariaDbTest` (ampliado) | ❌ W0 / ✅ | ⬜ pending |
| PR167-06 | custo da variação na Precificação; combo soma; Publicador herda | feature | `Produtos/CustoDoProdutoNaPrecificacaoTest.php` + consumidores existentes | ❌ W0 / ✅ | ⬜ pending |
| PR167-07 | tabela editável (`SpreadsheetGrid` estendido), gravação por linha | js gate + feature | `tests/js/estrutura-produtos.test.js`; `Produtos/GravarLinhasTest.php` | ❌ W0 | ⬜ pending |
| PR167-08 | modelo `.xlsx` + importação com prévia, acrescentar/atualizar | feature | `Produtos/ModeloEImportacaoTest.php` (fixture sintética) | ❌ W0 | ⬜ pending |
| PR167-09 | pacote empilhado, cubado, faturado, ME2/Full/ME1/pendente | unit | `tests/Unit/PortalEstrutura/LogisticaProdutoTest.php` | ❌ W0 | ⬜ pending |
| PR167-10 | frete ME2 por API (cache, lote), tabela ECF de reserva, sem literal de frete grátis | unit + feature | `TabelaFreteEcfTest.php`; `Produtos/FreteDoProdutoTest.php` | ❌ W0 | ⬜ pending |
| PR167-11 | pendências por linha | unit | `tests/Unit/PortalEstrutura/PendenciasDoProdutoTest.php` | ❌ W0 | ⬜ pending |
| PR167-12 | ciclo de vida (excluir/editar variação e oferta ligada) | feature | `Produtos/CicloDeVidaDoProdutoTest.php` + `OfertaExcluidaNoPortalTest` | ❌ W0 / ✅ | ⬜ pending |
| PR167-13 | combo/kit/combit sem logística/frete | feature | caso em `CadastroDeProdutoTest` | ❌ W0 | ⬜ pending |
| PR167-14 | baseline, migration no MariaDB real, rollback + re-up | manual | roteiro no `167-VERIFICATION.md` | manual | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `167-BASELINE-TESTES.md` com os números medidos na pesquisa (83/724 Estrutura · 22/118 consumidores do Publicador · 7/155 domínio), registrado ANTES de mexer (D-11)
- [ ] `tests/Feature/PortalCliente/Estrutura/Produtos/*` (10 arquivos) e `tests/Unit/PortalEstrutura/*` (4)
- [ ] `tests/js/estrutura-produtos.test.js`
- [ ] Atualizar `PortalSemAnunciarTest` e `AcessoAoModuloEstruturaTest` (6 submódulos, `produtos` primeiro)
- [ ] Ampliar `MigracoesDaFaseDetectamMariaDbTest::MIGRACOES`
- [ ] Fixture sintética do `.xlsx` gerada no próprio teste (NUNCA o arquivo real do cliente)

*Nenhum framework a instalar.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Migration em `estrutura_ofertas` no MariaDB 10.4 | PR167-14 | o SQLite dos testes não pega 1059/1830/1553 nem collation | `migrate --path` das migrations da fase; `SHOW CREATE TABLE` + `SHOW INDEX`; `rollback --path` e re-up; contagem de `estrutura_ofertas` igual antes e depois |
| Frete ME2 real por API | PR167-10 | precisa de conta do ML conectada | só leitura, com "pode" do usuário; comparar com a tabela ECF |
| Conferência visual da tabela de Produtos | PR167-07 | aprovação do usuário (UI-SPEC) | captura local (`.planning/learnings/verificacao-visual-local.md`) e checkpoint humano |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
