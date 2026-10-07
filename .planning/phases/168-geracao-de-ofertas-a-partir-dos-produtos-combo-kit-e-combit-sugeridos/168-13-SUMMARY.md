---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 13
subsystem: portal-estrutura
tags: [portal, rotas, throttle, allowlist, sugestoes]
requires: ["168-09", "168-10", "168-11"]
provides:
  - "PortalEstruturaSugestoesController (index, aceitar, descartar, restaurar, definirGeracao, cotarFrete)"
  - "6 rotas portal.auth.estrutura.sugestoes.* com throttle de prefixo próprio"
  - "allowlist do domínio do cliente sem curinga para as sugestões"
affects: ["168-14"]
key-files:
  created:
    - app/Http/Controllers/PortalEstruturaSugestoesController.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/AcessoAsSugestoesTest.php
  modified:
    - routes/web.php
    - app/Http/Middleware/RestringeDominioDoPortal.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest.php
decisions:
  - "D-02, D-18 e D-20 implementados: mesma porta de Produtos, cotação sob demanda por POST próprio, nenhum submódulo novo no menu"
metrics:
  tarefas: 2
  commit: 536e5c35
  completed: 2026-10-07
---

# Phase 168 Plan 13: porta HTTP das sugestões no Portal Summary

Controller fino das sugestões com 6 rotas no `portal.auth` (throttle de prefixo único por rota) e allowlist do domínio do cliente linha a linha, sem curinga.

## Rodada de entrada (antes de escrever qualquer coisa)

| Grupo | Resultado | Exit | Baseline |
|---|---|---|---|
| `tests/Feature/PortalCliente/Estrutura/Sugestoes` | 100 testes / 605 asserções | 0 | n/a |
| `tests/Unit/PortalEstrutura` | 187 / 1251 | 0 | n/a |
| G1 `tests/Feature/PortalCliente/Estrutura` | 346 / 2309 | 0 | 246 / 1704 (a diferença é o acréscimo das ondas 3 a 5) |

Nenhuma falha nova.

## Rodada de saída

- Sugestões (diretório): 116 / 779, exit 0.
- G4 `tests/Feature/PortalCliente`: 510 / 3668, exit 0 (baseline 394 / 2877).
- Os 4 arquivos do verify: 37 / 473, exit 0. `AcessoAsSugestoesTest` tem 16 testes (RED visto antes: 13 erros e 3 falhas).

## Commit

- `536e5c35` feat(168): rotas das sugestões no Portal com throttle próprio e allowlist sem curinga. As duas tarefas saíram juntas, como o plano manda. `git diff` de `routes/web.php` tem só linhas `+` (15 linhas).

## O que foi feito

- **Controller**: empresa e ator só de `PortalContexto`; chave ativa `estrutura.produtos`. Do navegador só chegam `chave`, `nome` e `sku` (qualquer `componentes` no corpo é descartado). Validação: lote ≤ `lote_aceite`, regex da chave de composição, nome e SKU ≤ 255 (o limite de 120 do SKU é por item, no serviço), frete ≤ `por_pagina` chaves. Filtros da URL normalizados contra listas fechadas.
- **Rotas**: GET sugestoes (60), POST aceitar (30), descartar (60), restaurar (60), PUT `produtos/{produto}/geracao` com `whereNumber` (60) e POST frete (10), cada uma com prefixo de throttle único na aplicação (o teste confere).
- **Allowlist**: 5 linhas em `PERMITIDO` e `portal/estrutura/sugestoes/produtos/{id}/geracao` em `PERMITIDO_COM_ID`. Nenhum `sugestoes/*`.
- **Menu**: nenhum submódulo novo; `DominioLiberaTodoModuloTest`, `PortalSemAnunciarTest` e a contagem de submódulos de Produtos seguem verdes.

## Deviations from Plan

**1. [Rule 3 - Bloqueio] `AcessoAosProdutosTest` afirmava `PERMITIDO_COM_ID` exatamente igual a `['portal/estrutura/produtos/{id}']`**
- Acrescentar a linha da geração (exigida pelo plano) quebraria essa asserção. Ela agora lista as duas linhas.
- Arquivo: `tests/Feature/PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest.php`. Commit 536e5c35.

**2. [Ajuste de teste] "nada enviado" virou "nada enviado ao Mercado Livre"**
- `Http::assertNothingSent()` falha em qualquer página do portal: o contexto compartilhado consulta o ECF Drive (`files.ecfconsultoria.com.br/api/v1/signals`) em toda renderização, o que também vale para a página de Produtos. O teste filtra por host `mercadolivre/mercadolibre`. A listagem não toca o ML.

## Known Stubs

Nenhum. A página `Portal/EstruturaSugestoes` ainda não existe (nasce no 168-14); os testes usam `->component('Portal/EstruturaSugestoes', false)`.

## Threat Flags

Nenhum. Os T-168-38 a T-168-43 estão cobertos pelos testes (sem sessão = redirect, 404 uniforme, chave/componentes alheios ignorados, validação, throttle único, allowlist sem curinga, ML sem requisição).

## Self-Check: PASSED

- Controller e teste existem; rotas listadas (6) por `route:list`; commit 536e5c35 confere com `git show --stat`.
- STATE.md e ROADMAP.md intocados.
