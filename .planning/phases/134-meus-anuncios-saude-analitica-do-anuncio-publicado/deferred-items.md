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
