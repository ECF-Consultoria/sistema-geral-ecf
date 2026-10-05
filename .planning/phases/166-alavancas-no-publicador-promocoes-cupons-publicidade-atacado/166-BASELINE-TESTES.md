# Fase 166 — Baseline de testes (antes de mexer)

- Data/hora: 2026-10-04 (medida antes de qualquer código da fase)
- `git rev-parse HEAD`: `2a27906819857d7f86be1f5a5ea3bab9af702e6a`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\PubRascunho.php` (carrega ESTE worktree)
- `node_modules` presente no worktree; `public/build/manifest.json` com data de modificação `2026-10-04 20:22` (o gate final compara para provar que o build rodou de verdade)
- Rodado um grupo por vez, saída redirecionada para arquivo (sem pipe), `echo exit=$?` logo depois.
- `tests/Feature/Publicador` e `tests/Unit/Publicador` rodados em separado (nesta fase são dois comandos).

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo | Depois (166-16) |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `tests/Feature/Publicador` | 238 | 1473 | 0 | 0 | 0 | 0 | 1 min 38 s | |
| 2 | `tests/Unit/Publicador` | 156 | 522 | 0 | 0 | 0 | 0 | 3 s | |
| 3 | `npm run test:js` (node --test) | 710 | n/d | 2 | 0 | 0 | 1 | 2 s | |

## Falhas PRÉ-EXISTENTES (não corrigidas aqui)

Grupo 3 (`test:js`), 708 passam e 2 falham — as mesmas duas já deixadas pela Fase 164, sem relação com o Publicador (planilha / fases de Polos):

1. `Características secundárias nasce recolhido (é o grupo que mais infla)` — o fonte do componente de planilha não casa com `/RECOLHIDOS_INICIAIS = \[G_SECUND\]/`.
2. `FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha` — `deepStrictEqual`: o código tem `['Encerrado','Protocolo Churn','Desistência','Churn']` e o teste espera `['Encerrado','Protocolo Churn','Churn']` (tests/js/polosEntrantes.test.js:188).

## Regra de comparação

Gate da fase = cada grupo com contagem de testes maior ou igual à desta tabela e nenhuma falha nova. No grupo 3 as 2 falhas acima são o piso conhecido. Teste que falha só com rede (sem `Http::fake`) roda isolado antes de virar regressão (ex.: `Phase75/PublicarEmpresaNaoAtribuidaTest::test_admin_nao_recebe_403_no_update`, intermitente conhecido da 164).
