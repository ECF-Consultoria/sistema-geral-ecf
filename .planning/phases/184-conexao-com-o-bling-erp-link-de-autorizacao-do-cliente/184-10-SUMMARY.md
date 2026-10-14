---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 10
subsystem: portal-produtos
tags: [bling, erp, produtos, estoque, sku, portal-cliente, react]

requires:
  - phase: 184-01
    provides: "BlingVinculo e BlingConexao (situacao, status, estoque_ativo)"
  - phase: 184-09
    provides: "ProdutoCadastroService::gravarEstoqueDoBling() — caminho dedicado que não passa por gravarLinha, por isso imune a esta trava"
provides:
  - "ProdutoCadastroService::gravarLinha ignora 'estoque' e/ou 'codigo' (Ref) de $presentes quando a variação está vinculada ao Bling com a conexão ativa — cobre grade, ficha e importação de planilha de uma vez, com aviso em pt-BR no retorno"
  - "BlingVinculo::travas(?BlingConexao, ?BlingVinculo): array — fonte única das duas travas (sku_travado/estoque_travado), chamada pela escrita e pela leitura"
  - "ProdutoLinhas::paraProdutos traz o bloco 'bling' SEMPRE presente em cada item (vinculado, removido, estoque_travado, sku_travado, saldo_lido_em)"
  - "CartaoVariacao.jsx e EstoqueDoProduto.jsx mostram Ref/Estoque travados com explicação sem jargão, reusando o estilo de só-leitura existente"
affects:
  - "184-11 (tela de configurações): nenhum impacto direto — este plano não toca em 'erp' nem no Cockpit"
  - "184-12 (bloco do Bling nas Configurações): nenhum impacto direto"
  - "Qualquer plano futuro que grave produto via gravarLinhas precisa saber que 'codigo' agora passa por $presentes (antes era incondicional)"

tech-stack:
  added: []
  patterns:
    - "Regra de dono do dado aplicada via o mecanismo de $presentes ('chave ausente = não mexer') em vez de um if solto — cobre grade, ficha e planilha de uma vez porque as três passam pelo mesmo método"
    - "Decisão de trava extraída para um método estático único (BlingVinculo::travas) chamado pela escrita E pela leitura, para não repetir o erro já cometido no projeto com o ERP declarado (duas implementações divergindo)"
    - "Bloco de leitura opcional do servidor SEMPRE presente no item (nunca ausente), com leitura defensiva no front (?.) — precedente do cabecalho ?? null de Configuracoes.jsx"

key-files:
  created:
    - tests/Feature/Bling/TravaDaVariacaoVinculadaTest.php
    - tests/js/bling-variacao-travada.test.js
  modified:
    - app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php
    - app/Services/Portal/Estrutura/Produtos/ProdutoLinhas.php
    - app/Models/BlingVinculo.php
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/EstoqueDoProduto.jsx

key-decisions:
  - "'codigo' nunca esteve no vocabulário de $presentes do NormalizadorDeLinha (sempre obrigatório); gravarLinha agora o adiciona como 'sempre presente' na baseline, e a trava o remove só na variação vinculada — preserva o comportamento de antes (sempre grava) para quem não está travado"
  - "BlingVinculo::travas() definido em Task 1 (não em Task 2 como o plano sugeria) porque o arquivo já estava no <files> de ambas as tasks; Task 2 só consome, sem reimplementar"
  - "O Teste 9 da Task 1 (1 consulta a bling_vinculos por lote) virou 2 consultas depois da Task 2: gravarLinhas monta a própria resposta chamando ProdutoLinhas::paraProdutos no fim, que também consulta bling_vinculos — ainda constante por lote, não por linha; a asserção foi ajustada com comentário explicando a interação"

requirements-completed: [BLING-25]

metrics:
  duration: "~95min"
  completed: 2026-10-14
---

# Phase 184 Plan 10: Dono do dado na variação vinculada — estoque e Ref travados Summary

A regra "estoque e Ref vêm do Bling na variação vinculada" (CONTEXT §4.1) implementada **uma vez, no serviço**, pelo mecanismo de `$presentes` que `ProdutoCadastroService::gravarLinha` já usa — cobrindo grade, ficha e importação de planilha de uma só vez — mais o mesmo sinal replicado no modelo de leitura e na ficha do Portal, sem jargão e sem citar a plataforma de venda.

## Performance

- **Duration:** ~95 min
- **Completed:** 2026-10-14
- **Tasks:** 3/3
- **Files modified:** 7 (2 criados, 5 modificados)

## Accomplishments

- `ProdutoCadastroService::carregar()` ganha o mapa `bling` (variacao_id => travas), montado por **uma** consulta a `bling_conexoes` e, só quando ela existe, **uma** a `bling_vinculos` — para o lote inteiro, nunca por linha. `gravarLinha` remove `'estoque'` e/ou `'codigo'` de `$presentes` quando a variação alvo está no mapa, com aviso em pt-BR no retorno dizendo que os campos não mudaram porque vêm do Bling. Variação nova nunca está no mapa (correto: nunca foi vinculada). `gravarEstoqueDoBling` (plano 09) continua imune, confirmado por não passar por `gravarLinha`.
- `BlingVinculo::travas(?BlingConexao $conexao, ?BlingVinculo $vinculo): array` nasce como fonte única: `sku_travado` = vínculo `vinculado` + conexão `ativa`; `estoque_travado` = `sku_travado` + `estoque_ativo`. Chamado pela escrita (`ProdutoCadastroService`) e pela leitura (`ProdutoLinhas`) — a mesma regra nos dois lados, sem reimplementação.
- `ProdutoLinhas::paraProdutos` traz o bloco `bling` **sempre presente** em cada item (`vinculado`, `removido`, `estoque_travado`, `sku_travado`, `saldo_lido_em` em ISO 8601), mesmo para empresa sem nenhuma conexão com o Bling — uma consulta por tabela para o lote inteiro.
- `CartaoVariacao.jsx`: Ref e Estoque viram `<output>` só-leitura (reusando o estilo que `EstoqueDoProduto.jsx` já tinha para a soma) quando `variacao.bling?.sku_travado`/`estoque_travado`, com texto "este [campo] vem do Bling da empresa" — sem ERP, integração, sincronizado, API ou token, e sem citar a plataforma de venda. `removido_no_bling` destrava os dois com um aviso curto. `EstoqueDoProduto.jsx`: com UMA variação travada, o campo do produto reusa o mesmo ramo de só-leitura.

## Task Commits

1. **Task 1: A regra no serviço — estoque e Ref ignorados na variação vinculada** — `6966708c` (feat)
2. **Task 2: O sinal no modelo de leitura** — `c153ec59` (feat)
3. **Task 3: Ref e Estoque travados na ficha do Portal** — `13a584fc` (feat)

## Files Created/Modified

- `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php` — mapa `bling` em `carregar()`; trava em `gravarLinha` via `$presentes`; `'codigo'` passou a ser gated no bloco de atualização da variação existente (antes incondicional)
- `app/Services/Portal/Estrutura/Produtos/ProdutoLinhas.php` — `blingPorVariacao()` e a chave `bling` no item de cada variação
- `app/Models/BlingVinculo.php` — método estático `travas()`
- `resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx` — Ref/Estoque condicionais (`<output>` vs `<input>`), aviso de `removido`
- `resources/js/Components/Portal/Estrutura/Produtos/EstoqueDoProduto.jsx` — ramo de só-leitura reusado para a trava do Bling
- `tests/Feature/Bling/TravaDaVariacaoVinculadaTest.php` — 18 testes (10 Task 1 + 8 Task 2)
- `tests/js/bling-variacao-travada.test.js` — 7 testes (node --test + esbuild)

## Decisions Made

Ver frontmatter `key-decisions`. Destaque: `'codigo'` nunca esteve no vocabulário de `$presentes` do `NormalizadorDeLinha` — ele é sempre obrigatório na linha e, no bloco de atualização de variação existente, era sempre gravado incondicionalmente (`$novo = ['codigo' => $codigo]`). Para a trava poder agir pelo mesmo mecanismo de `$presentes` (como o plano pede, em vez de um `if` solto), `gravarLinha` agora adiciona `'codigo'` à baseline de `$presentes` sempre que ausente, e só a trava o remove. O comportamento de quem não está travado fica idêntico ao de antes (`codigo` sempre grava), provado pelo Teste 4 (par positivo) e pelo Teste 3 (outros campos continuam editáveis).

## Deviations from Plan

### Auto-fixed Issues

Nenhuma por bug, funcionalidade crítica faltante ou bloqueio (Regras 1-3) no código de produção.

### Ajuste de teste descoberto durante a Task 2 (não é desvio de produção)

O Teste 9 da Task 1 (`mapa de vinculos e uma consulta para o lote inteiro`) contava **1** consulta a `bling_vinculos` para um lote de 50 linhas. Depois de implementar a Task 2, o mesmo teste passou a contar **2**: `gravarLinhas` monta a própria resposta chamando `ProdutoLinhas::paraProdutos` no fim (linha já existente, não tocada por este plano), e `paraProdutos` também consulta `bling_vinculos` para montar o bloco `bling` da leitura (Task 2). As duas consultas são **constantes por lote** (não por linha — 50 linhas continuam dando 2, não 50+), então a asserção foi ajustada de `1` para `2` com um comentário explicando a interação entre as duas tasks. Nenhuma linha de produção foi alterada por causa disto; o gate real que o teste prova (consulta constante, não dentro de `foreach`) continua válido e provado.

---

**Total deviations:** 0 auto-fixed de produção; 1 ajuste de asserção de teste (documentado acima).
**Impact on plan:** Nenhum impacto de escopo — o comportamento de produção é exatamente o que o plano descreveu.

## Known Stubs

Nenhum. O bloco `bling` de leitura é real (consulta ao banco), não um mock; o front só esconde/mostra campos com base nele.

## Threat Flags

Nenhum novo. As superfícies deste plano (trava de escrita, bloco de leitura, UI condicional) já estavam cobertas pelo `<threat_model>` do próprio `184-10-PLAN.md` (T-184-66 a T-184-73, T-184-SC) — nenhum arquivo tocado introduz superfície fora dele.

## Issues Encountered

Nenhum bloqueio no código de produção. A árvore é compartilhada com a Fase 180 (outra sessão): o commit `0c68368e feat(180-10)` tocou `resources/js/Pages/Mlb/MeusAnuncios.jsx` entre os commits das Tasks 1/2 e a Task 3 deste plano — confirmado inofensivo por `git log --oneline <sha-inicial>..HEAD -- <arquivo>` e por `git status --short` mostrando só os arquivos deste plano em cada commit meu. Commits feitos sempre por `git commit -- <paths>`, nunca `git add -A`.

## Gate dos acceptance_criteria

- `artisan test --filter=TravaDaVariacaoVinculada`: **18 passed** (10 Task 1 + 8 Task 2), exit 0.
- `artisan test --filter=Bling` (suíte inteira da fase): **188 passed** (170 anteriores + 18 novos), exit 0, zero regressão.
- `tests/Feature/PortalCliente` (757 testes): **757 passed**, mesmo baseline conferido pelo orquestrador.
- `tests/Feature/PortalCliente/Estrutura/Produtos` (diretório focado, por causa do `memory_limit` conhecido): **312 passed**, exit 0, medido antes E depois da Task 2 (sem mudança).
- `npm run test:js`: **4398/4399 passed** — a única falha é a pré-existente conhecida `tests/js/estrutura-grade-glide.test.js` (linha ~123, `RECOLHIDOS_INICIAIS`), confirmada por texto do próprio erro batendo com o aviso crítico do plano; **não é regressão desta fase**.
- `npm run build`: código 0; `public/build/manifest.json` com data de modificação da execução.
- `grep -c "bling?\." CartaoVariacao.jsx` → **5** (≥ 3 exigido); `grep -cE "variacao\.bling\.[a-z]"` → **0** (nenhuma leitura sem `?.`).
- `grep -ciE "\bERP\b|integra(ç|c)[ãa]o|sincroniza|\bAPI\b|token"` nos dois componentes → **0**.
- `grep -ciE "mercado ?livre|mercadolivre|shopee|magalu|amazon"` nos dois componentes → **0**.
- `git diff --stat <sha-inicial>..HEAD -- app/Services/Portal resources/js`: os 4 arquivos previstos (`ProdutoCadastroService.php`, `ProdutoLinhas.php`, `CartaoVariacao.jsx`, `EstoqueDoProduto.jsx`) **mais** `resources/js/Pages/Mlb/MeusAnuncios.jsx`, que é commit da Fase 180 (outra sessão, `0c68368e`), não deste plano.
- `grep -c "BlingVinculo::travas" ProdutoLinhas.php` → ≥ 1; mesmo grep em `ProdutoCadastroService.php` → ≥ 1 — a regra é uma só, chamada dos dois lados.

## User Setup Required

None — nenhuma configuração externa. As credenciais reais do Bling continuam para o checkpoint humano do plano 13.

## Next Phase Readiness

- A trava e o bloco `bling` estão prontos; o plano 11 (tela de configurações) e o 12 (bloco do Bling nas Configurações) não dependem de nada deste plano além do que já existia (`BlingConexao`/`BlingVinculo`).
- Nenhum bloqueio.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-14*

## Self-Check: PASSED
