---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 10
subsystem: portal-cliente / mapeamento-estrutural
tags: [produtos, rotas, allowlist, throttle, categorias, fretes, importacao]
requires: [167-08, 167-09]
provides:
  - PortalEstruturaProdutosController (index, modelo, gravarLinhas, excluirVariacao, previaImportacao, aplicarImportacao, CRUD de familias/ambientes, buscarCategorias, sugerirCategorias, cotarFretes)
  - 'produtos' como 1o submodulo do Mapeamento Estrutural (ModulosPortal)
  - 14 rotas portal.auth.estrutura.produtos* com throttle proprio e 13 linhas na allowlist do dominio do cliente
  - entrada() do Mapeamento seguindo D-21
affects: [167-11]
tech-stack:
  added: []
  patterns: [controller fino que so orquestra servicos, empresa e ator sempre do PortalContexto]
key-files:
  created:
    - app/Http/Controllers/PortalEstruturaProdutosController.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/GravarLinhasTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/CategoriaDoProdutoTest.php
  modified:
    - app/Support/Portal/ModulosPortal.php
    - routes/web.php
    - app/Http/Middleware/RestringeDominioDoPortal.php
    - app/Http/Controllers/PortalEstruturaController.php
    - tests/Feature/PortalCliente/PortalSemAnunciarTest.php
    - tests/Feature/PortalCliente/Estrutura/AcessoAoModuloEstruturaTest.php
key-decisions:
  - "Lista vazia na busca de categoria sai com indisponivel=true: o preditor do MlCatalogoMetaService degrada para [] quando o ML cai (e cacheia por 1h), entao 'sem resultado' e 'ML fora do ar' nao se distinguem la embaixo. A tela deve dizer 'nada encontrado ou Mercado Livre indisponivel'. Excecao propria tambem vira 200 indisponivel."
  - "sugerirCategorias nao grava nada; devolve a 1a candidata que e folha (D-06). Aceitar e pelo POST linhas."
  - "cotarFretes devolve exatamente o resultado do FreteMe2Service::cotar (fretes, pendentes, conectado, falhou); nada em estrutura_precificacoes."
requirements-completed: [PR167-01, PR167-04, PR167-07, PR167-08, PR167-10]
duration: ~60min
completed: 2026-10-05
---

# Phase 167 Plan 10: Submodulo Produtos no Portal (rotas, allowlist, entrada D-21) Summary

O que os planos 06 a 09 construiram agora responde por HTTP para o cliente e para a equipe: Produtos e o 1o submodulo do Mapeamento, com 14 rotas de throttle isolado, allowlist do dominio uma linha por rota, empresa sempre da sessao e origem cliente/interno no activity log.

## Commits

- `4aeafa6e` — fiacao: menu, 14 rotas, allowlist, entrada D-21, pagina `index` e testes de acesso
- `0feb0562` — escritas por JSON: linhas, exclusao de variacao, familias e ambientes
- `ec520400` — modelo .xlsx, importacao (previa e aplicacao), categorias e fretes

## Testes que mudaram DE PROPOSITO (contrato novo, nao e regressao)

- `PortalSemAnunciarTest::test_o_mapeamento_estrutural_tem_seis_submodulos` (era "cinco"; 'produtos' primeiro).
- `AcessoAoModuloEstruturaTest::test_a_entrada_abre_produtos_ou_a_lista_e_os_links_antigos_vao_para_o_mapeamento`: empresa vazia agora vai para Produtos (D-21); com ofertas e sem produto, Lista SKUs; menu com 6 submodulos.

## Verificacao

- `tests/Feature/PortalCliente` inteiro: 363 testes, 2618 assercoes, OK (baseline G4 antes da fase: 231/1872).
- `tests/Feature/PortalCliente/Estrutura`: 215 testes OK. `tests/Unit/PortalEstrutura`: 54 testes OK.
- `DominioLiberaTodoModuloTest` varre o router e passa com as rotas novas.
- Criterios: 13 linhas `portal/estrutura/produtos*` na allowlist e nenhum `portal/estrutura/produtos/*`; 14 rotas com `throttle:N,1,estrutura.produtos.*` (teste afirma, alem de estar no grupo `portal.auth`); `mimes:xlsx` 2x; `getRealPath()` presente; zero `input('company_id')`; `PortalEstruturaController` cresceu 8 linhas liquidas.
- `git diff routes/web.php` antes do commit: so linhas adicionadas (zero delecoes).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] Testes de sessao em duas empresas no mesmo teste**
- **Found during:** Task 1 e 2
- **Issue:** a segunda `entrarNoPortal` na mesma app de teste nao troca a empresa de forma confiavel (id de outra empresa respondeu 200).
- **Fix:** um metodo de teste por empresa; dado "alheio" gravado direto pelo servico, nao por HTTP.
- **Commits:** `4aeafa6e`, `0feb0562`

**2. [Rule 1 - Bug de teste] `Http::fake` acumulado**
- `Http::fake(['*' => 404])` no `setUp` vencia o stub especifico (1o que casa vence). Removido do `setUp` do `GravarLinhasTest`. **Commit:** `ec520400`

Fora isso, plano executado como escrito. Nenhum `state.*` do gsd-sdk foi usado.

## Known Stubs

Nenhum. A pagina `Portal/EstruturaProdutos` ainda nao existe (nasce no 167-11); os testes usam `->component('Portal/EstruturaProdutos', false)` como o plano previu. Ate la, abrir a rota no navegador falha por pagina ausente.

## Threat Flags

Nenhuma superficie fora do `<threat_model>` do plano. T-167-40 a 46 cobertas por teste (sem sessao, IDOR com duas empresas, allowlist, throttle, upload, app token, origem no log).

## Self-Check: PASSED

- Arquivos criados e commits `4aeafa6e`, `0feb0562`, `ec520400` conferidos no git.
