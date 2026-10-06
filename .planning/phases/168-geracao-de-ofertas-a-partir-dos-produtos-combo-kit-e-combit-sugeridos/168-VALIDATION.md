---
phase: 168
slug: geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-10-06
---

# Phase 168 — Estratégia de validação

> Contrato de validação da fase: o que se testa a cada passo da execução. Fonte: `168-RESEARCH.md`, seção
> "Validation Architecture".

---

## Infraestrutura de teste

| Propriedade | Valor |
|----------|-------|
| **Framework** | PHPUnit 11.5 (SQLite `:memory:`, cache `array`, fila `sync`) + `node:test` (`npm run test:js`) |
| **Config** | `phpunit.xml`; gates JS com `tests/js/_fonte.js` (`lerSemComentarios`) |
| **Comando rápido** | `C:/xampp/php/php.exe -d memory_limit=1024M vendor/phpunit/phpunit/phpunit tests/Unit/PortalEstrutura/Geracao` |
| **Suíte da fase** | `tests/Unit/PortalEstrutura` + `tests/Feature/PortalCliente/Estrutura` (inclui `Sugestoes/`) + G2 + G3 + G5 + `npm run test:js` |
| **Tempo estimado** | ~30 s o rápido; ~3 min a suíte da fase, rodada POR GRUPO |

**Regras de execução:**
- A suíte inteira estoura 512 MB, então rode por grupo.
- A saída vai para arquivo, e o exit é conferido logo depois. Nunca use `| tail`, que engole o exit.
- Antes, confira o autoloader: `ReflectionClass(...)->getFileName()` aponta para o worktree.
- O `vendor/` foi instalado com `--ignore-platform-reqs`, porque o PHP local é 8.2 e o lock pede 8.4.

---

## Frequência de amostragem

- **Depois de cada commit de tarefa:** o arquivo de teste da tarefa, mais o `node --test` do arquivo JS tocado.
- **Depois de cada onda:** `tests/Unit/PortalEstrutura` + `tests/Feature/PortalCliente/Estrutura/Sugestoes` + G2 + G3.
- **Antes da verificação da fase:** G1..G8 sem falha nova (G6 com o piso das 2 antigas), mais a prova no MariaDB
  (PR168-13), o roteiro de gabarito local (PR168-15) e o `npm run build` com a página no manifest.
- **Latência máxima de retorno:** 60 s por tarefa.

### Baseline (`168-BASELINE-TESTES.md`, medida ANTES de mexer)

| # | Grupo | Referência (fim da 167, depois do merge `9d54db33`) |
|---|---|---|
| G1 | `tests/Feature/PortalCliente/Estrutura` | dentro do G4 |
| G2 | `DadosEfetivosTest` + `SincronizaPortalTest` + `MigracaoAnunciarAntigoTest` + `Alavancas/CustoDoAnuncioTest` | 22 / 118 |
| G3 | `PortalCliente/DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` | 7 / 180 |
| G4 | `tests/Feature/PortalCliente` | 394 / 2877 |
| G5 | `OfertaExcluidaNoPortalTest` + `ExclusaoDaEmpresaPreservaHistoricoTest` + `MigracoesDaFaseDetectamMariaDbTest` | 17 / 92 |
| G6 | `npm run test:js` | 1123 testes, 1121 passam. Piso: as 2 falhas antigas, "Características secundárias nasce recolhido" e "FASES_TERMINAIS" |
| G7 | `tests/Unit/PortalEstrutura` | 62 / 256 |
| G8 | `tests/Feature/PortalCliente/Estrutura/Sugestoes` + `tests/Unit/PortalEstrutura/Geracao` | novo |

---

## Mapa requisito → teste

O planejador detalha por tarefa (ID `168-NN-TT`).

| Req | Comportamento seguro | Tipo | Arquivo | Existe? |
|-----|----------------------|------|---------|---------|
| PR168-01 | gerador sem banco, estável e ordenado; só usa variação com oferta ligada | unit | `tests/Unit/PortalEstrutura/Geracao/GeradorDeSugestoesTest.php` | ❌ W0 |
| PR168-02 | família igual e não nula; ambiente em comum; sem tipo fora de Kit/Combit; só 2 itens | unit | `.../GeradorRegrasDurasTest.php` | ❌ W0 |
| PR168-03 | variações em paralelo, nunca cartesiano | unit | `.../VariacoesEmParaleloTest.php` | ❌ W0 |
| PR168-04 | semente idempotente; par único; direção do Combit | feature | `Sugestoes/TiposEParesTest.php` | ❌ W0 |
| PR168-05 | tipo efetivo (override > categoria > nome); ambíguo = sem tipo; isolamento por empresa | unit + feature | `.../TipoDoProdutoTest.php`, `Sugestoes/GeracaoDoProdutoTest.php` | ❌ W0 |
| PR168-06 | chave canônica; composição existente (até a feita à mão) não volta; aceite duplo cria 1 | unit + feature | `.../ChaveDeComposicaoTest.php`, `Sugestoes/NadaDuplicadoTest.php` | ❌ W0 |
| PR168-07 | descartar persiste e não volta; restaurar; não vaza entre empresas | feature | `Sugestoes/DescarteDeSugestaoTest.php` | ❌ W0 |
| PR168-08 | aceitar pelo `criar()`; lote ≤ 100; erro isolado; espera varrida 1×; aparece na Lista SKUs e na Precificação | feature | `Sugestoes/AceitarSugestaoTest.php` | ❌ W0 |
| PR168-09 | nome e SKU por fase; SKU > 120 bloqueia; título > 60 avisa | unit | `.../NomesSugeridosTest.php` | ❌ W0 |
| PR168-10 | volumes × quantidade; nenhuma chamada HTTP na lista; nada gravado como preço | unit + feature | `.../ConjuntoLogisticoTest.php`, `Sugestoes/LogisticaDoConjuntoTest.php` | ❌ W0 |
| PR168-11 | painel sobre o conjunto, página no servidor, cartões (sem `SpreadsheetGrid`), regra não duplicada no JS | feature + JS | `Sugestoes/ListaDeSugestoesTest.php`, `tests/js/estrutura-sugestoes.test.js` | ❌ W0 |
| PR168-12 | `portal.auth`, 404 de outra empresa, throttle próprio, allowlist sem curinga | feature | `Sugestoes/AcessoAsSugestoesTest.php` + `DominioLiberaTodoModuloTest` | ❌ W0 / ✅ |
| PR168-13 | migrations idempotentes; semente 2× | feature + manual | `MigracoesDaFaseDetectamMariaDbTest` (ampliado) + roteiro MariaDB | ❌ W0 / manual |
| PR168-14 | admin cria e edita tipo e par; não-admin recebe 403 | feature | `Sugestoes/AdminTiposEParesTest.php` | ❌ W0 |
| PR168-15 | gabarito sintético com a forma medida; roteiro local que imprime só contagens | unit + manual | `.../GabaritoDaGeracaoTest.php` + roteiro fora do repo | ❌ W0 / manual |

*Status: ⬜ pendente · ✅ verde · ❌ vermelho · ⚠️ instável*

---

## Requisitos da Onda 0

- [ ] `168-BASELINE-TESTES.md` com os números de hoje (G1..G8).
- [ ] Fixture SINTÉTICA do catálogo, gerada no teste e nunca tirada do `.xlsx` real, com a forma medida: uma família grande, famílias de 1 produto, grupos de 2 variações, par sem ambiente comum e produto sem tipo.
- [ ] Os arquivos de `tests/Unit/PortalEstrutura/Geracao/*` e `tests/Feature/PortalCliente/Estrutura/Sugestoes/*`, e `tests/js/estrutura-sugestoes.test.js`.
- [ ] Ampliar `MigracoesDaFaseDetectamMariaDbTest::MIGRACOES` com as migrations novas.
- [ ] Nenhum framework a instalar.

---

## Verificações só manuais

| Comportamento | Req | Por que manual | Como |
|---------------|-----|----------------|------|
| Migrations e semente no MariaDB 10.4 | PR168-13 | O SQLite não pega os erros 1059/1830/1553 | Só `migrate`/`rollback` com `--path` no `ecf_admin` local; contar `estrutura_ofertas` antes e depois |
| Gabarito na planilha real | PR168-15 | A planilha é de cliente e não entra no repo | Roteiro no scratchpad que imprime só contagens: meta ≥ 106 acertos de 129, ~171 geradas; o script é apagado no fim |
| Tela de revisão | PR168-11 | Aprovação visual do usuário | Checkpoint humano com dados fictícios em SQLite isolado |

---

## Aprovação da validação

- [ ] Toda tarefa tem verificação `<automated>` ou depende da Onda 0.
- [ ] Nenhuma sequência de 3 tarefas sem verificação automática.
- [ ] A Onda 0 cobre todas as referências que faltam.
- [ ] Nenhum modo watch.
- [ ] Retorno em menos de 60 s.
- [ ] `nyquist_compliant: true` no frontmatter.

**Aprovação:** pendente

---

## Mapa por tarefa (planejamento, 06/10)

Cada tarefa de código escreve o teste ANTES do código, no próprio plano (tarefa `tdd="true"`); por isso a "Onda 0" de
arquivos de teste não é um plano separado: o 168-01 só mede a baseline, e cada arquivo da tabela da seção "Mapa requisito →
teste" nasce na tarefa indicada abaixo. Toda tarefa tem `<automated>`; os checkpoints (168-02 T3, 168-16 T3) são as únicas
sem comando, e nenhuma sequência de 3 tarefas fica sem verificação automática.

| Tarefa | Requisitos | Arquivo de teste | Comando |
|---|---|---|---|
| 168-01-T1 | PR168-12, PR168-13 | baseline G1..G7 | grupos do baseline |
| 168-02-T1 | PR168-04, PR168-05 | `Geracao/TipoDoProdutoTest`, `Geracao/CatalogoDaEcfTest` | `phpunit tests/Unit/PortalEstrutura/Geracao` |
| 168-02-T2 | PR168-15 | roteiro local (só contagens) | falha se houver `.xlsx` ou saída do roteiro versionados ou não rastreados (`git ls-files` + `git status --porcelain`) |
| 168-03-T1 | PR168-06, PR168-04 | `Geracao/ChaveDeComposicaoTest`, `Geracao/QuantidadesTest` | phpunit dos 2 arquivos |
| 168-03-T2 | PR168-03 | `Geracao/VariacoesEmParaleloTest` | phpunit do arquivo |
| 168-04-T1 | PR168-09 | `Geracao/NomesSugeridosTest` | phpunit do arquivo |
| 168-04-T2 | PR168-10 | `Geracao/ConjuntoLogisticoTest` | phpunit do arquivo |
| 168-05-T1/T2 | PR168-11, PR168-08 | `tests/js/estrutura-sugestoes-selecao.test.js` (comportamental) | `node --test` do arquivo |
| 168-06-T1/T2 | PR168-04, PR168-13 | `Sugestoes/TiposEParesTest`, `MigracoesDaFaseDetectamMariaDbTest` + G5 | phpunit dos arquivos |
| 168-06-T3 | PR168-13 | prova no MariaDB 10.4 (`--path`) | `migrate:status --path=` |
| 168-07-T1 | PR168-01, PR168-02, PR168-03 | `Geracao/GeradorDeSugestoesTest`, `Geracao/GeradorRegrasDurasTest` | phpunit dos 2 arquivos |
| 168-07-T2 | PR168-15 | `Geracao/GabaritoDaGeracaoTest` (15/5/8) | `phpunit tests/Unit/PortalEstrutura/Geracao` |
| 168-08-T1 | PR168-05 | `Sugestoes/CatalogoSinteticoTest` (forma do catálogo de teste) | phpunit do arquivo |
| 168-08-T2 | PR168-01, PR168-05, PR168-06 | `Sugestoes/RetratoDoCatalogoTest` | phpunit do arquivo |
| 168-09-T1/T2 | PR168-14 | `Sugestoes/AdminTiposEParesTest` + `DevControllerTest` | phpunit dos arquivos |
| 168-10-T1 | PR168-11 | `Sugestoes/ListaDeSugestoesTest` | phpunit do arquivo |
| 168-10-T2 | PR168-10 | `Sugestoes/LogisticaDoConjuntoTest` | `phpunit tests/Feature/PortalCliente/Estrutura/Sugestoes` |
| 168-11-T1 | PR168-08, PR168-06 | `Sugestoes/AceitarSugestaoTest`, `Sugestoes/NadaDuplicadoTest` | phpunit dos 2 arquivos |
| 168-11-T2 | PR168-07, PR168-05 | `Sugestoes/DescarteDeSugestaoTest`, `Sugestoes/GeracaoDoProdutoTest` | `phpunit tests/Feature/PortalCliente/Estrutura/Sugestoes` |
| 168-12-T1/T2 | PR168-14 | `tests/js/estrutura-geracao-admin.test.js` + manifest | `node --test` do arquivo |
| 168-13-T1/T2 | PR168-12, PR168-11, PR168-10 | `Sugestoes/AcessoAsSugestoesTest` + G3 | phpunit dos arquivos |
| 168-14-T1 | PR168-11 | `tests/js/estrutura-sugestoes-componentes.test.js` (gate dos 6 componentes) | `node --test` do arquivo |
| 168-14-T2 | PR168-11, PR168-08 | `tests/js/estrutura-sugestoes.test.js` (página, aceite/descarte) | `node --test` dos arquivos |
| 168-14-T3 | PR168-11, PR168-09 | `tests/js/estrutura-sugestoes.test.js` (guarda, frete, estados vazios) + manifest | `node --test` dos arquivos |
| 168-15-T1 | PR168-05, PR168-11 | `tests/js/estrutura-sugestoes-abas.test.js` (Sem tipo, JanelaTipo) | `node --test` dos arquivos |
| 168-15-T2 | PR168-07 | `tests/js/estrutura-sugestoes-abas.test.js` (Descartadas) | `node --test` dos arquivos |
| 168-15-T3 | PR168-11 | `estrutura-produtos-topo.test.js` + manifest | `node --test` dos arquivos |
| 168-16-T1 | PR168-13, PR168-15 | G1..G8, MariaDB final, gabarito real | grupos do baseline |
| 168-16-T2 | PR168-11 | guarda do SQLite (recusa do MariaDB testada) + roteiro puppeteer T1..T14 | `curl` do servidor de conferência |
