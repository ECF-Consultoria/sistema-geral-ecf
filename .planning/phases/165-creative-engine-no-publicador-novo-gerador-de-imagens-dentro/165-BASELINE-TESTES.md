# Fase 165 — Baseline de testes (antes de mexer)

- Data/hora: 2026-10-05 (medida antes de qualquer mudança de código da fase)
- `git rev-parse --short HEAD`: `0c623f2a`
- Saídas brutas: `scratchpad/base-creative.txt`, `base-pub.txt`, `base-js3.txt`, `base-js-full.txt` (sessão do executor)

| # | Grupo | Comando | Testes | Asserções | Falhas | Exit |
|---|---|---|---|---|---|---|
| 1 | Creative Engine (antigo) | `C:/xampp/php/php.exe -d memory_limit=1024M vendor/bin/phpunit tests/Unit/Phase160 tests/Unit/Phase161 tests/Unit/Quick261003L8o tests/Feature/Phase160 tests/Feature/Phase161 tests/Feature/Quick261003L8o` | 164 | 758 | 0 | 0 |
| 2 | Publicador | `C:/xampp/php/php.exe -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Publicador tests/Unit/Publicador` | 803 | 4039 | 0 | 0 |
| 3 | JS do editor e do assistente antigo | `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js tests/js/estrutura-anunciar-ml.test.js` | 155 | — | 0 | 0 |
| 4 | `npm run test:js` (suíte JS completa) | `npm run test:js` | 958 | — | 2 (pré-existentes) | 1 |

## Por que os números mudaram em relação à pesquisa (04/10/2026)

A pesquisa (`165-RESEARCH.md` §8) mediu, em 04/10, no worktree de pesquisa: 164/738 OK (grupo 1), 394/1995 OK
(grupo 2), 169/0 (grupo 3). Entre 04/10 e 05/10 entraram em produção, mergeados em `origin/main`:

- **Fase 162** (validador Gemini-como-juiz): acrescentou colunas (`validacao_status`, `validacao`, `validacoes`,
  `regeneracao_automatica`, `validacao_pedida_em`, `validacao_em` em `ml_anuncio_criativos`; `validacoes`,
  `regeneracoes_automaticas` no kit) e testes novos em `tests/Unit/Phase160`/`Phase161`/`Quick261003L8o` — por
  isso o grupo 1 manteve 164 testes mas subiu de 738 para 758 asserções (as suítes existentes ganharam
  asserções sobre as colunas novas, nenhum teste novo de arquivo).
- **Fase 164** (Publicador no sistema interno) e a **Fase 166** (Alavancas), ambas mergeadas depois da
  pesquisa, explicam o salto do grupo 2 de 394/1995 para 803/4039 — são centenas de testes novos do módulo
  do Publicador que não existiam em 04/10.
- O grupo 3 caiu de 169 para 155 testes nos MESMOS três arquivos — não é regressão desta execução (nenhum
  arquivo de teste foi tocado antes desta medição); a diferença é de commits anteriores a este plano, fora
  do nosso controle. Registrado aqui para o gate final comparar contra ESTE número (155), não contra o da
  pesquisa.

## Falhas PRÉ-EXISTENTES (não corrigidas aqui, grupo 4)

`npm run test:js`: 958 testes, 956 passam, 2 falham — as MESMAS duas falhas já documentadas em
`164-BASELINE-TESTES.md` (não têm relação com o Creative Engine nem com o Publicador; são da planilha de
Polos):

1. `Características secundárias nasce recolhido (é o grupo que mais infla)` — `AssertionError`: o fonte do
   componente de planilha não casa com `/RECOLHIDOS_INICIAIS = \[G_SECUND\]/`.
2. `FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha` — `deepStrictEqual`: o
   código tem `['Encerrado','Protocolo Churn','Desistência','Churn']` e o teste espera
   `['Encerrado','Protocolo Churn','Churn']`.

## Regra de comparação

Gate da fase = cada grupo com contagem de testes maior ou igual à desta tabela e nenhuma falha nova. No
grupo 4, as 2 falhas acima são o piso conhecido — qualquer falha ADICIONAL é regressão.

## Depois (165-08)

- Data/hora: 2026-10-05, depois do merge com `origin/main` (commit `bca07716`, que trouxe MCP do outro dev — `laravel/mcp`
  + `laravel/passport` — além das fases 164/166 já contabilizadas no "Antes").
- Mesmos comandos do baseline, rodados um a um, saída em arquivo fora do repositório
  (`scratchpad/165-08/depois-{creative,pub,phase165,js3,js-full}.txt`), `echo exit=$?` depois de cada um — nenhum `| tail`.
- Comando novo (Task 1): `C:/xampp/php/php.exe -d memory_limit=1024M vendor/bin/phpunit tests/Unit/Phase165 tests/Feature/Phase165`.

| # | Grupo | Antes (165-01) | Depois (165-08) | Diferença |
|---|---|---|---|---|
| 1 | Creative Engine (antigo) | 164 / 758 / 0 / 0 | 164 / 758 / 0 / exit 0 | Nenhuma — mesmo número de testes e asserções, 0 falhas. 1 deprecation do PHPUnit (pré-existente, não é falha) |
| 2 | Publicador | 803 / 4039 / 0 / 0 | 803 / 4039 / 0 / exit 0 | Nenhuma — idêntico ao baseline |
| 3 | JS (3 arquivos) | 155 / — / 0 / 0 | 164 / — / 0 / exit 0 | +9 testes nos MESMOS três arquivos (ondas da própria fase 165 acrescentaram `BlocoDeFotos`/`PainelCriativos` a `publicador-editor.test.js`), 0 falhas |
| 4 | `npm run test:js` (suíte JS completa) | 958 / — / 2 / 1 | 967 / — / 2 / exit 1 | +9 testes (mesmo delta do grupo 3); as MESMAS 2 falhas pré-existentes de Polos (`estrutura-grade-glide.test.js`/`polosEntrantes.test.js`), nenhuma falha nova |
| 5 | Phase165 (novo nesta linha) | não existe em 165-01 (tests/Unit/Phase165 e tests/Feature/Phase165 nasceram nesta fase) | 152 / 847 / 0 / exit 0, 1 **incomplete** | O incomplete é `RotasAntigasComKitDoPublicadorTest` (`tests/Feature/Phase165/RotasAntigasComKitDoPublicadorTest.php:149`, `markTestIncomplete`) — a guarda das rotas antigas `criativo.*` com `rascunho_id` NULL ainda não existe; risco aceito desde a onda 1 (T-165-20). Não é falha nova, é risco já registrado |

**Regra do gate (CLAUDE.md, fase por risco): PASSOU.**
- Creative Engine antigo: mesmo número de testes do baseline, 0 falhas. ✔
- Publicador: igual ao baseline (≥ baseline), 0 falhas. ✔
- JS: nenhuma falha nova (as 2 do grupo 4 são o piso conhecido, documentado desde `164-BASELINE-TESTES.md`). ✔
- Nenhuma falha NOVA em nenhum grupo.

**Fora da tabela do baseline, medido à parte pelo orquestrador (não é dos 4 grupos originais, referência apenas):**
`tests/Feature/Mcp` (módulo do outro dev, trazido pelo merge) — 36 testes, 332 asserções, 0 falhas. Não é escopo da fase 165 e
não entra na tabela de comparação; registrado aqui só para não parecer que foi esquecido.
