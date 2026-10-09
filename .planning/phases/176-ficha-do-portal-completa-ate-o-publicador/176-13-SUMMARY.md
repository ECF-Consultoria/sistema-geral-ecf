---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 13
subsystem: portal + publicador
tags: [portao-final, conferencia-visual, learnings]
requires: [176-01, 176-02, 176-03, 176-04, 176-05, 176-06, 176-07, 176-08, 176-09, 176-10, 176-11, 176-12]
provides:
  - portão final verde (4 grupos PHP + test:js no piso + build)
  - conferência visual APROVADA pelo usuário (08/10/2026)
  - learnings publicador-ml §14/§15 e portal-do-cliente §37/§38
affects: []
requirements: [FP176-01, FP176-02, FP176-03, FP176-04, FP176-05, FP176-06, FP176-07, FP176-08, FP176-09]
completed: 2026-10-08
---

# Phase 176 Plan 13: Portão final e conferência visual

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 | 305253ef | docs(176): portão final, validação e learnings |
| 2 | 4095bd53 | docs(176-13): nota da conferência local da cópia da #459 |
| 3 | (este) | docs(176-13): resumo — conferência aprovada |

## Portão (HEAD 39c2ae2b)

| Grupo | Baseline | Final | Falhas |
|---|---|---|---|
| G1 `tests/Feature/Publicador` | 567 | 663 | 0 |
| G2 `tests/Unit/Publicador` | 256 | 293 | 0 |
| G3 `tests/Unit/PortalEstrutura` | 217 | 218 | 0 |
| G4 `tests/Feature/PortalCliente` | 592 (14 falhas de outra sessão) | 606 | 0 |
| G5 `npm run test:js` | 1303 (2) | 1332 (2) | as 2 antigas |
| Build | — | exit 0 | — |

Migrations re-provadas no MariaDB local com `--path` (Ran, lotes 136–138).

## Conferência visual (Task 3) — APROVADA

Cópia fictícia da #459 em SQLite no scratchpad (com guarda), servidor `php -S :8172`. O usuário conferiu a ficha
do portal e o rascunho no Publicador. Banco confirmado campo a campo: rascunho idêntico ao portal (27 campos +
pacote 60×55×30 cm / 12,5 kg), 3 cores com SKU/estoque (7, 0, vazio), fotos por cor em JPG (WebP convertida),
Combo/Kit com estoque derivado 1, multivalor com `revisar`.

### Ajustes pedidos durante a conferência (trabalho direto, mesmo branch)

| Commit | O quê |
|---|---|
| 4511b497 | Kit/Combit com componente descartado não calcula estoque (nulo + aviso) — evitava estoque maior que o real |
| fd5ce049, dabcb00e, 9e6e3453 | Explicação em TODO campo do editor do Publicador (glossário > guardado > ML > IA uma vez); tabela nova `atributo_explicacoes` (aditiva, provada no MariaDB) |
| e8c804bb | Ficha do portal usa o `ClassificadorAtributos`: 28 → 44 campos na MLB193945 (15 `hidden` editáveis + UPHOLSTERY_MATERIAL); "Não se aplica" (`valor_id = '-1'`) ponta a ponta |
| 57811746 | Explicação em todo campo da ficha do portal (filtro de sigilo); eixo de variação decidido POR PRODUTO |
| b0395298 | Grupo renomeado para "Mais detalhes" (pedido do usuário) |

Limpeza feita: servidor derrubado, SQLite e scripts do scratchpad apagados, fotos de `storage/app/private/estrutura/459`
e `publicador/1..4` apagadas (as `visual-*.jpg` de 02/10 e `creative-referencias` são de outras sessões e ficaram).

## Pendente (fora da fase)

- Texto "N anúncios" no cartão da variação da ficha do portal (da Fase 167, anterior à regra de sigilo) — aguardando o usuário.
- Deploy só com "pode subir": integrar `origin/main` (branch 64 à frente / 9 atrás em 08/10), regate, contagens de
  `estrutura_produtos`/`estrutura_produto_variacoes`/`pub_produtos` antes e depois, GD com WebP na VPS, `queue:restart`
  (jobs novos em `high` e `default`). Prova real na #459 depois do deploy, sem publicar.
