---
tipo: quick
slug: uec-usar-estoque-calculado
data: 2026-10-09
origem: design_handoff_publicador/ETAPA-3-produto-fases.md §6
files_modified:
  - app/Services/Publicador/VinculoDeKitService.php
  - app/Http/Controllers/MlbPublicadorFaseController.php
  - routes/mlb_anuncios.php
  - resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx
  - tests/Feature/Publicador/VinculoDeKitTest.php
  - tests/js/publicador-produto-render.test.js
autonomous: true
---

<objective>
Fechar o **único item de código que ficou de fora da Etapa 3**: a ação explícita
**"Usar estoque calculado"** da §6 da especificação.

Hoje um combo que o usuário vinculou como Fase 2 **mantém o próprio estoque**
(`estoque_calculado = false`), e o cartão da fase já mostra
`"estoque próprio · calculado do base: N"` — mas **não existe a ação para adotar o
calculado**. Sem ela, quem vinculou um combo fica vendo os dois números e não tem como
escolher o de baixo.

Isto foi reportado ao usuário como não entregue quando a Etapa 3 subiu (09/10), e é o que
falta para a §6 estar cumprida.

**Nada de recalcular automaticamente:** a §6 é explícita que o combo vinculado **mantém** o
próprio estoque, e o `RecalculoEstoqueDoKitService` de propósito **pula** produto com
`estoque_calculado = false`. A ação é do usuário, um clique, por kit.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
<interfaces>
**O que a §6 pede, literalmente:**
> "**Estoque do combo vinculado:** mantém o próprio (`estoque_calculado = false`). Cartão da
> fase mostra 'estoque próprio' e o valor calculado ao lado; ação explícita **'Usar estoque
> calculado'**."

**O que JÁ existe — usar, não reimplementar:**

- `app/Services/Publicador/VinculoDeKitService.php` — `vincular()`, `desvincular()`,
  `recusar()`. ⚠️ **A trava `VINC-00` já permite gravar `estoque_calculado`**: a lista
  `$esperadas` (L105) é `['estoque_calculado', 'fase', 'produto_base_id', 'quantidade_kit']`.
  **Não alargar essa lista.**
- `app/Services/Publicador/RecalculoEstoqueDoKitService.php` — `propagar(PubRascunho $base)`
  devolve `['kits' => …, 'divergentes' => …]`. Reusa `PreviaDaFaseService::estoqueDoKit()`
  (divide **por depósito** e só depois soma — `floor` da soma dá número diferente). **Pula
  produto com `estoque_calculado = false`** — é por isso que a ação precisa gravar a coluna
  ANTES de chamar o recálculo.
- `app/Services/Publicador/FamiliaDeFasesService.php` — já manda
  `estoque_proprio` (L387) e `estoque_calculado_valor` (L388) para a tela.
- `resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx` L229/L254 — já desenha
  `"estoque próprio · calculado do base: N"`. **É aqui que o botão entra**, ao lado desse texto.
- As 3 rotas de vínculo em `routes/mlb_anuncios.php` (~L290-297), com
  `->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')`, dentro do grupo
  `role:admin`, throttle nomeado `publicador.vinculo`. **A rota nova segue esse molde.**
- `MlbPublicadorFaseController` — `vincular()`, `desvincular()`, `recusarSugestao()` e os
  helpers `produtoDaConta()` (o produto da URL) e `recusa()`. ⚠️ **Usar `produtoDaConta()`,
  NUNCA `baseDaConta()`** (que devolve `$p->base ?? $p`): num kit, `baseDaConta()` resolveria
  no base e a ação gravaria no produto errado — foi exatamente o bug que o 175-08 corrigiu.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: `usarEstoqueCalculado()` no serviço, com teste antes</name>
  <files>app/Services/Publicador/VinculoDeKitService.php, tests/Feature/Publicador/VinculoDeKitTest.php</files>
  <action>
  Acrescentar `usarEstoqueCalculado(PubProduto $kit): void` ao `VinculoDeKitService`, no molde
  de `desvincular()`: em `DB::transaction`, grava `estoque_calculado = true` e **só isso** (a
  trava `VINC-00` continua valendo e já aceita a coluna), depois dispara o recálculo do
  rascunho do **base** por `app(RecalculoEstoqueDoKitService::class)->propagar($baseRascunho)`.
  ⚠️ Resolver o serviço por `app()` sob demanda, **não** por injeção no construtor: há
  dependência circular conhecida nesse módulo (`EditorRascunhoService` ↔ recálculo) e injetar
  fecha ciclo no container.

  Recusas novas, no padrão dos `VINC-*` existentes (ids livres, conferir antes):
  - produto que **não é kit** (`produto_base_id` nulo ou `quantidade_kit < 2`) ⇒ recusa com
    mensagem em pt-BR sem jargão;
  - kit cujo **base foi apagado** (`produto_base_id` nulo por `SET NULL`) ⇒ recusa dizendo que
    não há base de onde calcular;
  - kit **sem rascunho no base** ⇒ recusa (não há de onde ler estoque);
  - já está em `true` ⇒ **idempotente**, não é erro: não grava de novo e não recalcula.

  Testes (RED antes): (1) o caminho feliz grava `estoque_calculado = true` **e** o estoque das
  variantes do kit passa a ser `floor(base ÷ N)` **por depósito** (fixture com depósitos A=5,
  B=5 e N=3 ⇒ `{A:1,B:1}` total **2**, nunca 3); (2) idempotência; (3) as três recusas; (4)
  ⚠️ **o que NÃO muda:** SKU, nome, preços, publicações e `ml_item_id` do kit ficam intactos —
  comparar `RascunhoRepository::snapshot()` do kit antes/depois nos campos que não são estoque,
  e as linhas de `pub_publicacao_itens` byte a byte.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && C:/xampp/php/php.exe artisan test tests/Feature/Publicador/VinculoDeKitTest.php</automated>
  </verify>
  <done>Testes novos verdes; `VinculoDeKitTest` inteiro continua verde.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: O endpoint</name>
  <files>app/Http/Controllers/MlbPublicadorFaseController.php, routes/mlb_anuncios.php, tests/Feature/Publicador/VinculoDeKitTest.php</files>
  <action>
  `POST publicador/empresas/{conta}/produtos/{produto}/estoque-calculado`, name
  `publicador.vinculo.estoque-calculado`, no grupo `role:admin`, com
  `->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')` e o throttle nomeado
  `publicador.vinculo` (o mesmo das outras três — é a mesma família de ação).

  No controller, método novo que usa **`produtoDaConta()`** (o produto da URL), chama o
  serviço e responde **`{produto: {...}}`** — o mesmo contrato das outras três rotas de
  vínculo, para a tela atualizar o cartão sem recarregar. `RegraViolada` vira **422** com
  `{message, regra}` pelo helper `recusa()` que já existe.

  Testes: 200 no caminho feliz com o `produto` de volta no corpo; **404** (nunca 403) para
  produto de outra conta; 422 com a regra certa em cada recusa; 403 para não-admin.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && C:/xampp/php/php.exe artisan test tests/Feature/Publicador/VinculoDeKitTest.php && C:/xampp/php/php.exe artisan test --filter="Phase132" 2>&1 | tail -3</automated>
  </verify>
  <done>Endpoint testado; `php artisan route:list | grep estoque-calculado` mostra a rota; a sanidade de rotas (Phase132) continua verde.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: O botão no cartão da fase</name>
  <files>resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx, tests/js/publicador-produto-render.test.js</files>
  <action>
  No cartão da fase, **ao lado** do texto que já existe (`estoque próprio · calculado do base:
  N`, L254), um botão **"Usar estoque calculado"** que chama o endpoint e atualiza o cartão
  com o `produto` devolvido.

  Regras:
  - Aparece **só** quando `linha.estoque_proprio === true` **e**
    `linha.estoque_calculado_valor` não é nulo. Sem valor calculado não há o que adotar.
  - Em voo: desabilitado, com texto de progresso. Erro: mensagem do servidor em pt-BR.
  - ⚠️ **Desabilitado com explicação, nunca escondido** quando houver motivo (regra D23).
  - ⚠️ **Armadilha do Rollup:** flag booleana usada dentro de `.map()` é eliminada no bundle de
    produção — computar **dentro** do callback. Este cartão é renderizado num `.map()` de fases.
  - ⚠️ **Asserção de desabilitado se prova com `/disabled=/`**, nunca `/disabled/` (as classes
    contêm `disabled:opacity-40` e casam sempre — asserção vazia).

  Testes de **render real** no arquivo que já existe: botão presente com
  `estoque_proprio: true` + valor; **ausente** com `estoque_proprio: false`; ausente com valor
  nulo; e os casos adversos (`estoque_calculado_valor` como objeto, string, negativo) sem
  estourar e sem `[object Object]`.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-produto-render.test.js && npm run build 2>&1 | tail -3</automated>
  </verify>
  <done>Render real verde; `npm run build` verde e `Produto.jsx` no manifest (resolver pelo JSON, o hash pode ter hífen ou sublinhado).</done>
</task>

</tasks>

<verification>
1. `php artisan route:list | grep estoque-calculado` → a rota existe, no grupo admin.
2. `git diff --stat -- database` → **vazio** (nenhuma migration; a coluna já existe desde a 175-01).
3. Baseline PHP `tests/{Feature,Unit}/Publicador`: **1364** verdes antes; sem falha nova depois.
4. Baseline JS: **1770 testes / 1768 pass / 2 fail**; as 2 falhas pré-existentes continuam 2.
5. `npm run build` verde.
6. ⚠️ O recálculo divide **por depósito antes de somar** — provado por fixture em que a ordem
   inversa daria outro número.
</verification>

<success_criteria>
- Um combo vinculado como Fase 2 mostra "estoque próprio · calculado do base: N" **e** o botão.
- Um clique adota o calculado: `estoque_calculado = true` e o estoque das variantes do kit
  passa a `floor(base ÷ N)` por depósito.
- SKU, nome, preços, publicações e MLBs do kit **não mudam**.
- O recálculo automático continua **pulando** quem tem `estoque_calculado = false`.
- A §6 da Etapa 3 passa a estar cumprida.
</success_criteria>

<output>
Create `.planning/quick/261009-uec-usar-estoque-calculado/SUMMARY.md` when done
</output>
