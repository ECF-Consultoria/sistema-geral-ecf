# Itens fora de escopo — Fase 134

## `tests/js/estrutura-grade-glide.test.js` falhando (pré-existente, não é regressão do Plano 03)

**Encontrado durante:** 134-03, Task 3 (`npm run test:js`).

**Sintoma:** o teste que verifica `RECOLHIDOS_INICIAIS = [G_SECUND]` na fonte de
`resources/js/Pages/Mlb/GradeAnuncioGlide.jsx` falha — a fonte atual tem
`RECOLHIDOS_INICIAIS = []`.

**Confirmação de que não é regressão desta execução:** reproduzido com
`git stash push --include-untracked -- tests/js/notaEcfConcordancia.test.js`
(removendo temporariamente o único arquivo novo desta task) e rodando
`node --test tests/js/estrutura-grade-glide.test.js` isoladamente — falha
igual, sem o arquivo novo no working tree. `git stash pop` restaurou o
arquivo em seguida. Nenhum arquivo do módulo de grade em massa (Fase 87,
`GradeAnuncioGlide.jsx`, `gradeMassaUtils.js`) foi tocado por este plano.

**Não corrigido** — fora do escopo do Plano 03 (SCOPE BOUNDARY: só
auto-corrigir o que a task atual tocou). Fica para quem mexer no módulo de
grade em massa (Fase 87) da próxima vez.

## `MlAcervoService::gravarSerieDiaria()` (134-04) — `updateOrCreate` com igualdade direta em `data` nunca acha a linha do dia, mascarado pelo fail-open

**Encontrado durante:** 134-05, Task 3 (`tests/Unit/Phase134/RotacaoDetalheTest.php`),
ao implementar o mesmo padrão em `MlAcervoDetalheService::gravarVisitasSerieDiaria()`
e descobrir por que ele quebrava.

**Causa raiz:** `MlAcervoMetricaDiaria.data` tem cast `'date'`. O setter do
Eloquent (`fromDateTime()`) grava a coluna usando o formato COMPLETO do
grammar (`Y-m-d H:i:s`, ex.: `2026-08-10 00:00:00`) mesmo para cast `date` —
só o GETTER trunca para `Y-m-d` na leitura. `gravarSerieDiaria()` chama
`MlAcervoMetricaDiaria::updateOrCreate(['data' => $hoje, ...], [...])` com
`$hoje = now()->toDateString()` (string `Y-m-d`, sem hora). O `where($attributes)`
interno do `firstOrNew()` faz uma comparação SQL crua — `'2026-08-10' = '2026-08-10 00:00:00'`
nunca bate — então a linha de hoje NUNCA é encontrada, e toda reexecução no
mesmo dia tenta um INSERT novo, que colide com o `UNIQUE (company_id, ml_item_id, data)`
já existente.

**Por que passava despercebido:** `processarLote()` envolve cada item em
`try/catch (\Throwable $e)`, incrementando `$falhas` e logando
`Log::warning()` em vez de propagar. O teste `serie_diaria_so_grava_quando_algo_muda`
só verifica a CONTAGEM de linhas — e como o INSERT falha silenciosamente sem
alterar nada, a contagem permanece "correta" pelo motivo errado. Confirmado
empiricamente: 240 ocorrências do erro
`SQLSTATE[23000]: ... UNIQUE constraint failed: ml_acervo_metricas_diarias...`
acumuladas em `storage/logs/laravel.log` só de execuções passadas da suíte
de testes deste projeto.

**Impacto em produção:** a série diária da camada BARATA (`sold_quantity`,
`nota_ecf`, `health_ml`) nunca é atualizada numa reexecução no MESMO dia
(botão "Atualizar agora" rodado 2x no mesmo dia, por exemplo) — fica
silenciosamente presa no valor da primeira execução do dia, sem erro visível
para o usuário, só um `Log::warning` por item.

**Fix aplicado no 134-05 (só no arquivo novo desta fase):**
`MlAcervoDetalheService::gravarVisitasSerieDiaria()` resolve "existe linha
hoje?" reaproveitando o getter com cast (`$ultima->data->toDateString() === $hoje`,
comparação em memória de objetos `Carbon`) em vez de um `where`/`updateOrCreate`
com igualdade direta contra a coluna crua, e então faz `update()`/`create()`
explícito em vez de `updateOrCreate()`.

**Não corrigido em `MlAcervoService.php`** — fora do escopo do Plano 05
(SCOPE BOUNDARY: arquivo não está em `files_modified` deste plano, e o bug é
pré-existente do 134-04, não causado por esta execução). Quem mexer em
`MlAcervoService::gravarSerieDiaria()` de novo deve aplicar o mesmo fix.
