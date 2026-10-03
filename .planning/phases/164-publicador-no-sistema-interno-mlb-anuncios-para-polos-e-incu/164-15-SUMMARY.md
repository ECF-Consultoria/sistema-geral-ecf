---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 15
subsystem: portal-cliente / publicador
tags: [portal, anunciar, remocao, gate, learnings]
requires: [164-14]
provides:
  - Anunciar fora do Portal do Cliente para todos os clientes (D18)
  - gate final contra a baseline (D19)
  - learnings do Publicador interno (seção 9)
affects: [routes/web.php, RestringeDominioDoPortal, ModulosPortal, PortalEstruturaController]
key-files:
  created:
    - tests/Feature/PortalCliente/PortalSemAnunciarTest.php
  modified:
    - routes/web.php
    - app/Http/Middleware/RestringeDominioDoPortal.php
    - app/Support/Portal/ModulosPortal.php
    - app/Http/Controllers/PortalEstruturaController.php
    - app/Services/Publicador/EditorRascunhoService.php
    - resources/js/Components/Publicador/apoio.js
  deleted:
    - app/Http/Controllers/PortalPublicadorController.php
    - app/Services/Portal/Estrutura/EstruturaPublicacaoService.php
    - resources/js/Pages/Portal/EstruturaAnunciar.jsx
    - resources/js/Components/Portal/Estrutura/FormPublicacao.jsx
    - resources/js/Components/Publicador/EditorPublicador.jsx
    - tests/Feature/PortalCliente/Estrutura/AnunciarEstruturaTest.php
    - tests/Feature/Publicador/PortalPublicadorTest.php
decisions:
  - Anunciar do Portal removido; wizard antigo de /mlb/anuncios preservado (D22)
metrics:
  completed: 2026-10-02
---

# Phase 164 Plan 15: Anunciar sai do Portal e gate final Summary

O Anunciar deixou de existir no Portal do Cliente para todos (rotas, allowlist e menu), o código morto saiu em commit separado, e a suíte da fase fechou sem falha nova contra a baseline.

## Commits

| Tarefa | SHA | Mensagem |
|---|---|---|
| 1 | `eaa03321` | feat(160): Anunciar sai do Portal do Cliente para todos os clientes (D18) |
| 2 | `2cda5bc3` | refactor(160): remove o código do Anunciar do Portal que ficou sem uso |
| 3 | `74ded401` | docs(160): gate final contra a baseline e learnings do Publicador interno |

## Gate final (antes × depois)

| # | Grupo | Antes (testes/asserções/falhas/exit) | Depois |
|---|---|---|---|
| 1 | Publicador | 230 / 952 / 0 / 0 | 350 / 1637 / 0 / 0 |
| 2 | PortalCliente | 240 / 2123 / 0 / 0 | 231 / 1872 / 0 / 0 |
| 3 | Phase75 | 43 / 151 / 0 / 0 | 43 / 151 / 0 / 0 |
| 4 | Phase76 | 23 / 103 / 0 / 0 | 23 / 103 / 0 / 0 |
| 5 | Phase77 | 33 / 92 / 0 / 0 | 33 / 92 / 0 / 0 |
| 6 | Phase134 | 24 / 107 / 0 / 0 | 24 / 107 / 0 / 0 |
| 7 | Soltos | 52 / 189 / 0 / 0 | 52 / 190 / 0 / 0 |
| 8 | test:js | 476 / n/d / 2 / 1 | 624 / n/d / 2 / 1 (as mesmas 2 pré-existentes) |

A falha intermitente de Phase75 não reapareceu. Detalhe e lista nominal dos removidos em `164-BASELINE-TESTES.md` ("Depois da fase (164-15)").

## Testes removidos (nominal em 164-BASELINE-TESTES.md)

- `AnunciarEstruturaTest` (14 testes) e `PortalPublicadorTest` (10 testes): casos válidos já vivem em `CategoriaBuscaServiceTest`, `ConferenciaTest`, `PublicacaoTest`, `ImagensTest`, `EstadoDoProdutoTest`, `MlbPublicadorTest`, `ConferenciaContaNaoLiberadaTest`, `PublicaMlbEmpresaSemCompanyTest`.
- `CategoriaBuscaServiceTest::test_o_servico_do_portal_delega_e_devolve_exatamente_o_mesmo` (o service do Portal saiu).
- `tests/js/publicador-mesa.test.js`: teste que lia `EditorPublicador.jsx`.
- Novo: `PortalSemAnunciarTest` (5 testes: 404 nas 3 famílias logado e pelo domínio do portal, `Route::has`/allowlist, 5 submódulos, resto do Mapeamento abre).

## O que ficou do código antigo de propósito

`EstruturaPublicacao` (modelo/tabela) e `EstruturaOferta::publicacao()`, `MigracaoAnunciarAntigo` + `publicador:migrar-anunciar`, `CategoriaBuscaService` e a rota `mlb.anuncios.publicador.categorias`, `FotosDoPar.jsx` + `lib/fotosDoPar.js`, `PortalEstruturaController::oferta()`, `EditorDeEixos`/`GradeVariantes`/`CampoAtributo`/`FotosPorGrupo`/`Problemas`, o wizard antigo `mlb.anuncios.wizard` (D22) e o fallback `PUBLICADOR_EMPRESAS_PILOTO` em `config/publicador.php`. O rótulo "Anunciar" de `EstruturaPrecificacao.jsx` (preço de anunciar) não foi tocado.

## Deviations from Plan

1. **[Rule 3] `route:list` substituído/complementado.** `route:list` rodou com `DB_CONNECTION=sqlite DB_DATABASE=:memory:` e `timeout 120` (não travou): `--path=estrutura` sem nenhuma rota anunciar/publicacao/publicador; `--name=mlb.anuncios.wizard` lista a rota. Além disso, `PortalSemAnunciarTest` afirma `Route::has`.
2. **[Rule 1] Teste do service removido em `CategoriaBuscaServiceTest`** (dependia de `EstruturaPublicacaoService`, que o plano manda apagar).
3. **[Rule 1] Testes de `EstadoDoProdutoTest`/`OfertaExcluidaNoPortalTest` ajustados** porque asseriam a chave `oferta` do estado, removida conforme o plano (passo 4); `EstadoDoProdutoTest` agora afirma `assertArrayNotHasKey('oferta')`.
4. **`apoio.js`:** além de `rota`, saíram `ABAS`, `ABA_DA_ETAPA` e o campo `dica` de `SECOES` (sem consumidor); `NOME_ETAPA` ficou (usado por `Problemas.jsx`). Comentários de `usePublicador.js`, `fotosDoPar.js` e `EstruturaPublicacao.php` atualizados.
5. `PortalSemAnunciarTest` lê `SUBMODULOS` por reflexão (constante privada).
6. Um `sed -i` de remoção por intervalo foi negado pelo classificador do ambiente; as remoções em `routes/web.php` foram feitas com Edit (mesmo resultado, conferido por `git diff --cached` e `php -l`).
7. Nenhum desvio de comportamento: `git diff --cached routes/web.php` só tinha linhas removidas do Anunciar/Publicador do Portal.

## Pendências conhecidas (do 164-14, fora do escopo)

1. Conta não liberada: lateral diz "Tudo pronto..." acima de "A validação no Mercado Livre espera a liberação" (contraditório com D26).
2. Celular (390px) nas telas A e B: rolagem horizontal/colunas cortadas.
3. Editor a 1440px: ~190px vazios à direita.
4. Faixa de produtos não rola até o produto atual.
5. Painel "Como funciona" ao lado só a partir de 1600px.
6. Chip "Rascunho" da tela B conta produtos sem rascunho.

Passo de produção do D20 (`php artisan publicador:empresa-teste`, depois `--confirmar`) continua com o usuário. Nenhum deploy, push ou chamada ao ML foi feito.

## Known Stubs

Nenhum.

## Self-Check: PASSED

- Commits `eaa03321`, `2cda5bc3`, `74ded401` existem; arquivos apagados ausentes; `EstruturaPublicacao.php`, `MigracaoAnunciarAntigo.php`, `FotosDoPar.jsx`, `fotosDoPar.js` presentes.
- Build ok, manifest sem `EstruturaAnunciar.jsx` e com as 3 páginas do Publicador.
- STATE.md e ROADMAP.md não tocados.
