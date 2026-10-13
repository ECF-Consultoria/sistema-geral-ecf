---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 02
subsystem: Bling ERP — tradutor de produto (função pura)
tags: [bling, tradutor, fixtures, phpunit, produtos, variacoes]

requires:
  - phase: 184-01
    provides: "nenhuma dependência direta — este plano não toca em bling_conexoes/bling_vinculos, só monta o array `vinculos` que o plano 08 vai gravar lá"
provides:
  - "TradutorBlingParaPortal::paraLinhas() — função pura Bling → linhas do NormalizadorDeLinha (formato S e V)"
  - "8 fixtures em tests/fixtures-bling/ copiadas da documentação da API v3 do Bling"
  - "tests/Support/BlingFixtures — leitura das fixtures com falha alta (RuntimeException)"
affects:
  - "184-05 (eixo composto, unidade de medida, multivolume — os TODO(184-05) marcados no código)"
  - "184-08 (importador — consome `linhas`/`recusadas`/`vinculos` deste tradutor)"

tech-stack:
  added: []
  patterns:
    - "Tradutor como classe final, só métodos estáticos, zero construtor — testável sem container do Laravel"
    - "BlingFixtures::caminho() usa __DIR__ em vez de base_path(), porque o teste do tradutor roda sem bootstrap do Laravel"
    - "Chave ausente na linha = 'não mexer' (mesma regra do NormalizadorDeLinha), nunca '' ou 0"

key-files:
  created:
    - app/Services/Bling/TradutorBlingParaPortal.php
    - tests/Unit/Bling/TradutorBlingParaPortalTest.php
    - tests/Support/BlingFixtures.php
    - tests/fixtures-bling/produto-formato-s.json
    - tests/fixtures-bling/produto-formato-v.json
    - tests/fixtures-bling/dados-basicos.json
    - tests/fixtures-bling/estoques-saldos.json
    - tests/fixtures-bling/depositos.json
    - tests/fixtures-bling/oauth-token.json
    - tests/fixtures-bling/erro-429.json
    - tests/fixtures-bling/erro-401.json
    - tests/fixtures-bling/README.md
  modified: []

decisions:
  - "Fixtures de produto guardam o payload SEM o envelope {\"data\": ...} da API real — o tradutor consome o array do produto diretamente, e a resposta real do Bling para /produtos/{id} entrega exatamente esses campos sob 'data'; quem desembrulhar é o cliente HTTP do plano 06, não este tradutor"
  - "custo numérico comparado com 0 usa <= 0 como 'incompleto' (igual às medidas) — custo zero não apareceu em nenhum caso do plano, decisão por simetria com volumesDoProduto"
  - "eixoEValorDaVariacao trata 2+ pares de variacao.nome usando só o primeiro, sem aviso de 'dois atributos' — TODO(184-05) explícito; a fixture produto-formato-v.json já contém o caso 'Cor:Preto;Tamanho:M' para o plano 05 reaproveitar sem fixture nova"

requirements-completed: [BLING-05, BLING-06]

metrics:
  duration: "~25 min"
  completed: 2026-10-13
---

# Phase 184 Plan 02: Tradutor Bling → Portal (formatos S e V) Summary

Função pura `TradutorBlingParaPortal::paraLinhas()` que converte o produto do Bling (simples ou com
variações) no contrato de linha do `NormalizadorDeLinha`, provada por 16 testes sem rede e sem banco,
com as 8 fixtures que alimentam toda a Fase 184 copiadas da documentação da API v3.

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-10-13
- **Tasks:** 3/3
- **Files modified:** 12 (10 criados na Task 1, 2 criados + editados nas Tasks 2/3)

## Accomplishments

- 8 fixtures em `tests/fixtures-bling/` (grafia conferida no índice do git, sem homônima em outra
  caixa) cobrindo produto formato S, produto formato V (3 variações, uma com dois atributos),
  dados-básicos (CNPJ), saldos de estoque (um negativo), depósitos, token OAuth (1.831 caracteres) e
  os erros 429/401 — nenhuma veio de conta de cliente real.
- `TradutorBlingParaPortal::paraLinhas()` traduz `formato S` em 1 linha e `formato V` em N linhas (1
  por variação), com grupo compartilhado, custo/descrição/volumes herdados do pai, eixo/valor
  derivados de `variacao.nome`, e recusa — nunca descarte silencioso — para código vazio, código
  repetido, produto sem nenhum código e `formato E` (kit, fora de escopo da fase).
- Provado por teste, com asserção negativa dupla, que o `preco` e a `categoria.id` do Bling nunca
  saem do tradutor.
- `tests/Support/BlingFixtures` falha alto quando uma fixture não existe ou o JSON é inválido.

## Task Commits

1. **Task 1: Fixtures dos payloads do Bling** — `fd96476c` (test)
2. **Task 2: Tradutor — formato S (produto simples)** — `498525e7` (feat)
3. **Task 3: Tradutor — formato V (produto com variações)** — `30fb586d` (feat)

**Plan metadata:** (este commit — docs)

_Nota: Task 2 e Task 3 têm `tdd="true"`, mas seguiram a convenção já registrada em `184-VALIDATION.md`
("não há onda de andaimes vermelhos" — testes e código nascem no mesmo commit), não o ciclo
RED→GREEN→REFACTOR em commits separados._

## Files Created/Modified

- `app/Services/Bling/TradutorBlingParaPortal.php` (425 linhas) — a função pura
- `tests/Unit/Bling/TradutorBlingParaPortalTest.php` (206 linhas, 16 testes) — a prova
- `tests/Support/BlingFixtures.php` — leitura das fixtures com falha alta
- `tests/fixtures-bling/*.json` (8 arquivos) + `README.md` — as fixtures e sua procedência

## Decisions Made

- **Fixtures de produto sem o envelope `{"data": ...}`** da API real: decisão de design não
  especificada no plano. O tradutor consome o array do produto diretamente; o desembrulho da
  resposta HTTP real é responsabilidade do cliente HTTP (plano 06), não deste tradutor puro.
- **`eixoEValorDaVariacao` com 2+ atributos usa só o primeiro par**, sem aviso específico de "dois
  atributos" — `TODO(184-05)` marcado explicitamente no código, conforme a instrução do plano de não
  afirmar esse caso aqui. A fixture `produto-formato-v.json` já contém o caso `"Cor:Preto;Tamanho:M"`
  para o plano 05 reaproveitar sem precisar de fixture nova.
- **Conversão de unidade de medida**: só `unidadeMedida === 1` (cm) está provado. `0` (metro) e `2`
  (milímetro) ficam com `TODO(184-05)` no código, sem conversão — nenhum teste afirma esse caminho.

## Deviations from Plan

None - plano executado como escrito. Os únicos ajustes foram de implementação (não de escopo):

- Dois comentários do código/teste citavam literalmente os padrões que os próprios
  `acceptance_criteria` do plano proíbem por `grep` (`$produto['preco']` no docblock da classe, e
  `RefreshDatabase` no docblock do teste). Reescritos para preservar o mesmo sentido sem a string
  literal — comportamento idêntico, só o texto do comentário mudou. Não é um desvio de Rule 1-4: é
  apenas evitar que a documentação em prosa colidisse com o próprio gate que ela descreve.

## Issues Encountered

`BlingFixtures::caminho()` usava `base_path()`, que depende do container do Laravel — mas
`TradutorBlingParaPortalTest` estende `PHPUnit\Framework\TestCase` puro (exigência do plano, para que
um `use RefreshDatabase` acidental apareça como erro), e o container não está inicializado nesse
contexto. Troquei para `dirname(__DIR__)."/fixtures-bling/{$nome}.json"` (baseado em `__DIR__` do
próprio `BlingFixtures.php`), que funciona com ou sem o Laravel inicializado. Corrigido antes do
primeiro commit; não é um desvio de escopo, é a implementação correta do helper que a Task 1 já
havia anunciado.

## Next Phase Readiness

- O tradutor está pronto para o plano 05 (eixo composto, unidade de medida 0/2, multivolume,
  `SaldoDoBling`) estender os pontos já marcados com `TODO(184-05)`.
- O array `vinculos` que `paraLinhas()` devolve está no formato que o plano 08 (importador) vai
  gravar em `bling_vinculos` — nenhum ajuste de contrato esperado.
- Nenhum bloqueio. `artisan test --filter=Bling`: 21 passed (16 novos + 5 do plano 01), exit 0, sem
  regressão.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-13*

## Self-Check: PASSED

Arquivos (12/12 encontrados): `app/Services/Bling/TradutorBlingParaPortal.php`,
`tests/Unit/Bling/TradutorBlingParaPortalTest.php`, `tests/Support/BlingFixtures.php`, as 8 fixtures
em `tests/fixtures-bling/` + `README.md`.

Commits (3/3 encontrados): `fd96476c` (Task 1), `498525e7` (Task 2), `30fb586d` (Task 3).

`artisan test --filter=Bling`: 21 passed (64 assertions), exit 0 — sem regressão nos 5 testes do
plano 01.
