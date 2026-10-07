---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 17
subsystem: portal-estrutura-sugestoes
tags: [sugestoes, filtro-status, resumo, ambientes, gap-closure]
requires: ["168-15"]
provides:
  - "filtro Status (prontas / com_aviso) no servidor, com contagens por_status"
  - "resumo dos 4 cartões, familia_ambientes e gerado_em na prop sugestoes"
affects: ["168-18", "168-19", "168-21"]
key-files:
  modified:
    - app/Services/Portal/Estrutura/Geracao/ListaDeSugestoes.php
    - app/Services/Portal/Estrutura/Geracao/ConjuntoLogistico.php
    - app/Http/Controllers/PortalEstruturaSugestoesController.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/ListaDeSugestoesTest.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/AcessoAsSugestoesTest.php
    - tests/Unit/PortalEstrutura/Geracao/ConjuntoLogisticoTest.php
  created:
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/StatusEResumoTest.php
decisions:
  - "D-28, D-30 (ambientes do grupo) e D-31 (resumo e hora da carga) implementados; nenhuma regra de geração, aceite ou descarte mudou"
metrics:
  completed: 2026-10-07
---

# Phase 168 Plan 17: backend da referência visual Summary

Filtro Status por avisos, resumo dos cartões, ambientes de cada grupo de família e hora da carga, tudo calculado no servidor sobre o conjunto da aba Pendentes, sem migration, rota ou middleware novos.

## Commits

| Task | Commit | Descrição |
|------|--------|-----------|
| 1 | `65974fd1` | status por avisos, resumo, ambientes do grupo e hora da carga na lista |
| 2 | `e413d250` | filtro status normalizado no controller e contrato da página |

## RED e GREEN

- RED (Task 1): `StatusEResumoTest` rodou antes do código com exit 2 (13 testes; 7 erros `Undefined array key "por_status"` e falhas nos demais).
- GREEN (Task 1): StatusEResumoTest + ListaDeSugestoesTest + LogisticaDoConjuntoTest + ConjuntoLogisticoTest: OK, 45 testes, 313 asserções, exit 0.
- RED (Task 2): `AcessoAsSugestoesTest` atualizado contra o controller antigo saiu com exit 1; verde (exit 0) após a mudança.

## Grupos do baseline (contagem >= Depois 168-16)

| Grupo | Resultado | Referência 168-16 |
|-------|-----------|-------------------|
| G8 (`Sugestoes` + `Unit/PortalEstrutura/Geracao`) | OK, 256 testes / 1912 asserções, exit 0 | 241 / 1774 |
| G1 (`Feature/PortalCliente/Estrutura`) | OK, 376 testes / 2624 asserções, exit 0 | 362 / 2489 |

## Valores medidos no catálogo sintético

Total 29: **P (prontas) = 27, A (com_aviso) = 2**. Resumo: total 29, combo 15, kit 6, combit 8.

## Contrato novo do topo (para 168-18/19)

Item da página inalterado (168-10). O topo ganha:

- `por_status`: `{todas, prontas, com_aviso}`, calculado sobre o conjunto da aba Pendentes filtrado por família, fase, tipo e busca, SEM o status; `prontas + com_aviso = todas`. Fora da aba Pendentes é tudo 0.
- `resumo`: `{total, combo, kit, combit}` do conjunto inteiro de pendentes, sem filtro, igual em qualquer aba, página ou filtro; `total = contagens.sugestoes`.
- `familia_ambientes`: valor da família (`'sem'` para Sem família) para lista de ambientes, sem repetir, em ordem alfabética, sobre o conjunto filtrado (mesmas chaves de `familia_totais`).
- `gerado_em`: ISO 8601 com fuso (`2026-10-07T14:30:00-03:00`), hora em que a lista foi calculada.
- Filtro de entrada `status` (`prontas` | `com_aviso`): normalizado no controller e em `listar()` contra `ListaDeSugestoes::STATUS`; ecoado em `filtros.status` (null se desconhecido). Só vale na aba Pendentes. Conta como filtro ativo para `chaves_filtradas`. Facetas de fase e família respeitam o status.
- Veredito de aviso = `avisos` do gerador, SKU repetido ou `ConjuntoLogistico::semMedida` (fonte única, também usada por `avaliar()`).

## Deviations from Plan

**1. [Rule 2 - cobertura] Teste de unidade acrescentado a Task 1.** O plano previa o caso em `ConjuntoLogisticoTest` "se nenhum existente cobrir". Nenhum chamava `semMedida()` diretamente, então acrescentei `test_sem_medida_e_a_mesma_fonte_do_avaliar`; por isso o commit da Task 1 lista 5 caminhos, não 4. Os testes antigos de contrato do item não tiveram nenhuma linha removida (`grep -c '^-[^-]'` = 0).

**2. Medição de P e A** feita com um teste temporário descartável (não commitado) sobre o catálogo sintético.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum: query `status` fechada em lista (T-168-56) e resumo/ambientes calculados só sobre a empresa da sessão, com teste de isolamento (T-168-57).

## Self-Check: PASSED

- Arquivos citados existem; commits `65974fd1` e `e413d250` conferidos com `git show --stat`.
- `git diff --stat 65974fd1~1..HEAD -- routes app/Http/Middleware database` vazio.
