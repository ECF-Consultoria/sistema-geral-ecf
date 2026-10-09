---
phase: 175-publicador-etapa-3-produto-fases
plan: 04
subsystem: ui
tags: [publicador, fases, kits, inertia, react, esbuild, render-real, idor, phpunit]

# Dependency graph
requires:
  - phase: 175-01
    provides: "colunas `produto_base_id`, `quantidade_kit`, `fase`, `estoque_calculado` em `pub_produtos`"
  - phase: 175-02
    provides: "`PubProduto::base()/kits()/ehKit()/familia()/proximaFase()/proximaQuantidade()`"
  - phase: 164 (Publicador)
    provides: "`ProgramasPublicadorService::resolver()/produtosQuery()/empresaParaTela()` e `EditorRascunhoService::prontidao()`"
  - phase: 172-173 (Etapas 1 e 2)
    provides: "`BarraDaConta`, `AbasDaConta`, `AvisoContaTravada`, `SeloStatusProduto` e o harness de render real"
  - phase: 134 (Meus Anúncios)
    provides: "`ModalDetalheAnuncio` (90 dias) e `ml_acervo_itens` (preço/vendas/visitas)"
  - phase: 165 (Creative Engine no Publicador)
    provides: "`ml_anuncio_criativo_kits.pub_rascunho_id` e as rotas `publicador.criativos.*`"
provides:
  - "`App\\Services\\Publicador\\FamiliaDeFasesService::paraTela()` — o payload dos 6 blocos da §3"
  - "Rota nomeada `mlb.anuncios.publicador.produto` (`GET publicador/empresas/{conta}/produtos/{produto}`)"
  - "`App\\Http\\Controllers\\MlbPublicadorFaseController` — a casa dos endpoints das plans 175-05..10"
  - "`Pages/Mlb/Publicador/Produto.jsx` + `Components/Mlb/Publicador/PainelDoProduto.jsx`"
  - "Mapa de estado da fase derivado (`nao_iniciada|em_preparacao|publicada|com_problema`), sem coluna de status nova"
affects: [175-05, 175-06, 175-07, 175-08, 175-09, 175-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Estado da fase DERIVADO da mesma fonte de `produtosParaTela()` — nenhum status novo em `pub_produtos`"
    - "Página Inertia como casca fina + painel em `Components/` para render real sem `AppLayout` (herdado da 173-06)"
    - "Escopo por query (`produtosQuery()->whereKey()`) em vez de `abort(403)`: produto alheio é 404 por não existir no escopo"
    - "Tradução de listing_type reaproveitada do servidor (`EstruturaPublicacao::LISTING_TYPES` + `EstruturaAnuncio::TIPOS`), nunca um mapa novo"

key-files:
  created:
    - app/Services/Publicador/FamiliaDeFasesService.php
    - app/Http/Controllers/MlbPublicadorFaseController.php
    - resources/js/Pages/Mlb/Publicador/Produto.jsx
    - resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx
    - tests/Feature/Publicador/TelaDoProdutoTest.php
    - tests/js/publicador-produto-render.test.js
  modified:
    - routes/mlb_anuncios.php

key-decisions:
  - "A lateral Mapeamento NÃO sai de `EstruturaOferta` como a §3 diz: medidas/peso vêm de `estrutura_produto_volumes` (via `variacao_id → estrutura_produto_variacoes`) e material/EAN de `estrutura_produto_atributos`. Oferta antiga sem `variacao_id` degrada no bloco âmbar."
  - "Vários volumes: as medidas são as do PRIMEIRO volume e o peso é a SOMA — a leitura que o docblock de `EstruturaProdutoVolume` já descreve."
  - "`prontidao()` devolve a chave `rascunho` quando NÃO existe rascunho (é o 'Sem rascunho' do `SeloStatusProduto`), então `rascunho → nao_iniciada`, divergindo da letra da §3 que a lista sob 'Em preparação'. Quem está em preenchimento é a chave `conferir`."
  - "`estoque_total` soma só as variantes `ativa = true`: órfã/desativada não é estoque, e o `floor(base ÷ N)` do kit tem de bater com o mesmo número."
  - "Categoria lida DIRETO de `ml_categoria_schemas` (nunca `CategorySchemaRepository::obter()`, que busca no ML quando o cache venceu). Fora do cache, mostra o próprio id da categoria."
  - "Análises de IA no histórico: consulta escopada pela âncora da conta com `limit(100)` e casamento em PHP por `destinoPublicador()`, porque o vínculo vive em JSON e `where` em caminho JSON divide MariaDB e SQLite."
  - "'Fase criada' = `created_at` do kit; 'combo vinculado' = `updated_at` do kit quando `estoque_calculado` é false. Limitação aceita e registrada em comentário: é o único sinal que existe sem coluna nova."
  - "Botão 'Criar Fase N' fica SEMPRE desabilitado nesta plan (título 'Em breve nesta tela'), com o motivo do servidor visível quando `habilitado` é false — o painel chega no 175-07 e um botão que abre nada é pior que um botão que explica."
  - "`detalhe_disponivel` exige `company_id` (o modal de 90 dias é `mlb.anuncios.meus.detalhe`); sem Company o botão fica desabilitado COM o texto literal da D23, nunca escondido."
  - "O painel aceita `produto` E `base` como nome do cabeçalho: o serviço devolve `base`, o contrato da página chama de `produto`. Assim o payload cru do serviço também renderiza."

patterns-established:
  - "Gate de render real por tela nova: compila o componente com esbuild e renderiza em `react-dom/server` com dado adverso (objeto no lugar de string), porque regex sobre a fonte não pega 'Objects are not valid as a React child'"
  - "Flags booleanas calculadas DENTRO de cada callback de `.map()` (armadilha do Rollup deste projeto), nunca em variável de escopo do componente"
  - "Teste de 'zero chamada ao ML' em request de página filtra por `mercadolibre`, nunca `assertNothingSent()`: `HandleInertiaRequests` busca sinais do ECF Drive em toda página autenticada"

requirements-completed: [FASE-02, FASE-03]

# Metrics
duration: 25min
completed: 2026-10-08
---

# Fase 175 Plano 04: A tela do Produto Summary

**`GET publicador/empresas/{conta}/produtos/{produto}` entrega os 6 blocos da §3 — cabeçalho, cartões por fase, anúncios no ar com MLB/preço/vendas/visitas, histórico, criativos e mapeamento — com o estado da fase DERIVADO de `prontidao()` (nenhuma coluna de status nova), produto de outra conta em 404 por escopo de query, zero chamada ao Mercado Livre no request e um gate de render real que prova que objeto no lugar de texto não derruba mais a tela.**

## Performance

- **Duração:** ~25 min de execução (01:48 → 02:14 UTC de 2026-10-09)
- **Tasks:** 3 (todas TDD: RED → GREEN)
- **Arquivos criados:** 6 · **modificados:** 1
- **Testes PHP (`tests/{Feature,Unit}/Publicador`):** 934 → **965** (+31, todos verdes)
- **Testes JS (`npm run test:js`):** 1399 (1397 ✓ / 2 ✗ pré-existentes) → **1425** (1423 ✓ / **as mesmas 2** ✗)
- **`npm run build`:** verde, `Produto` no manifest (`assets/Produto-DXLnLfvH.js`)

## Accomplishments

- **`FamiliaDeFasesService::paraTela()`** monta `base`, `fase_destacada`, `fases`, `proxima_fase`, `ofertas`, `historico`, `criativos` e `mapeamento` em **carregamento por lote** (um SELECT de rascunhos, um de validações, um de itens de publicação, um de acervo, um de kits de criativo + slots) e **zero chamada HTTP ao Mercado Livre**.
- **Estado de cada fase derivado** da MESMA fonte da lista (`último PubRascunho` + `última PubValidacao` + `EditorRascunhoService::prontidao()`), com uma camada de rótulo por cima — `pub_produtos` continua sem coluna de status.
- **Rota + controller novos** com escopo pela própria `produtosQuery()`: produto de outra conta é **404, nunca 403**; `{conta}` sempre pelo resolver, com `->where('conta', '(empresa|company)-[0-9]+')` e `->whereNumber('produto')` na própria rota.
- **Abrir um kit leva à tela do BASE** com a fase do kit destacada; kit cujo base foi apagado (`produto_base_id` NULL depois do SET NULL) vira base solto com aviso âmbar.
- **Tela React em duas peças** (casca Inertia + painel testável), com `textoSeguro()`/`numeroSeguro()` em todo campo do servidor e **gate de render real** com 25 casos adversos.

## Task Commits

1. **Task 1 — `FamiliaDeFasesService`** (TDD)
   - RED: `f855147f` `test(175-04): prova dos 6 blocos da tela do Produto antes do servico`
   - GREEN: `1f8db947` `feat(175-04): FamiliaDeFasesService monta os 6 blocos da tela do Produto`
2. **Task 2 — rota `publicador.produto` + `MlbPublicadorFaseController::mostrar`** (TDD)
   - RED: `11467d82` `test(175-04): prova do escopo da rota do Produto antes do controller`
   - GREEN: `0aa2742d` `feat(175-04): rota publicador.produto e MlbPublicadorFaseController::mostrar`
3. **Task 3 — `Produto.jsx` + `PainelDoProduto.jsx` + render real** (TDD)
   - RED: `0f5e537c` `test(175-04): render real da tela do Produto antes do componente`
   - GREEN: `4bd53c39` `feat(175-04): Produto.jsx e PainelDoProduto.jsx com os 6 blocos da secao 3`

Nenhum commit de REFACTOR: os dois `feat` passaram no primeiro verde com Pint limpo.

## Files Created/Modified

- `app/Services/Publicador/FamiliaDeFasesService.php` (822 linhas) — o payload da tela; docblock registra as três disciplinas herdadas (estado derivado, zero ML, lote) e a colisão `pub_produtos.fase` × `estrutura_ofertas.fase`.
- `app/Http/Controllers/MlbPublicadorFaseController.php` — `mostrar()`; é a casa dos endpoints das plans 175-05..10.
- `routes/mlb_anuncios.php` — a rota nova dentro do grupo `['auth','verified','role:admin']` já existente, logo depois de `publicador.configuracoes`.
- `resources/js/Pages/Mlb/Publicador/Produto.jsx` — casca fina (AppLayout + BarraDaConta + AbasDaConta aba `produtos` + AvisoContaTravada).
- `resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx` — os 6 blocos, duas colunas `lg:grid-cols-[1fr_340px]`, tokens `ecf-*`.
- `tests/Feature/Publicador/TelaDoProdutoTest.php` — 31 testes (serviço + rota).
- `tests/js/publicador-produto-render.test.js` — 25 subtestes de render real.

## Como o 404 cross-conta foi provado

`test_produto_de_outra_conta_e_404_nunca_403` monta **duas contas de Polos completas** (cada uma com `MlbEmpresa` + `Company` + `MlToken`), cria um produto em cada e pede o produto de B pela URL de A:

- `assertNotFound()` e, explicitamente, `assertNotSame(403, $resposta->getStatusCode())` — a asserção negativa está lá para que trocar o escopo por `abort(403)` no futuro quebre o teste.
- Em seguida o MESMO produto é aberto pela conta dona e responde **200**, provando que o 404 vem do escopo e não de um produto inexistente.
- Complementos: `test_produto_inexistente_e_404`, `test_conta_inexistente_ou_sem_programa_e_404` (empresa arquivada incluída), `test_conta_fora_do_padrao_e_404_pela_propria_rota` (`foo-1`, `empresa-abc`, `{produto}` não numérico) e `test_nao_admin_e_bloqueado_pelo_middleware`.
- `test_admin_abre_a_tela_do_produto_com_todas_as_chaves_do_contrato` ainda roda `assertDontSee('fake-access-token')` / `assertDontSee('fake-refresh-token')`: nenhum token da conta chega ao navegador. E `test_criativos_...sem_token_no_navegador` prova que a URL da miniatura leva o id numérico do kit, não o token de 32 caracteres (D-13).

## Como o render real foi provado

`tests/js/publicador-produto-render.test.js` compila `PainelDoProduto.jsx` **de verdade** com esbuild (o motor do Vite) e renderiza com `renderToStaticMarkup` — o molde inteiro de `publicador-visao-geral-render.test.js`, com `recharts` e `@radix-ui/react-dialog` somados ao `external` porque `ModalDetalheAnuncio` os arrasta.

Os casos que fecham a fenda de 07/10:

- `produto.nome` = `{}`, `sku` = `{foo:'bar'}`, `categoria` = `{x:1}`, `estoque_total` = `'sete'` → **não lança**, e o HTML não contém `[object Object]` nem `foo`.
- Oferta com **todos** os 13 campos em formato inesperado (fase string, preço string, visitas objeto, situação array, `detalhe_disponivel: 'sim'`) → não lança.
- Fase com `estado` string em vez de objeto, `rotulo` objeto, `estado_fase` numérico → não lança.
- Histórico com item inteiro adverso; `criativos: 'nao-e-array'`; `mapeamento: 42`; `produto: null`; `fases: null`; **todas as props ausentes**.
- E os casos de regra: lista vazia nunca vira "0 anúncios", `detalhe_disponivel=false` renderiza o botão desabilitado **com** o texto da D23, `proxima_fase.habilitado=false` mostra "Publique a Fase 1 primeiro", `quem` nulo vira "origem antiga" e nunca "undefined".

A armadilha do Rollup foi honrada: toda flag usada dentro de `.map()` (fases, ofertas, histórico, criativos) é calculada **no próprio callback**, com comentário em cada bloco.

## Divergências entre plano/spec e o código medido

| Onde | O que a spec/plan dizia | O que o código exige | O que foi feito |
|---|---|---|---|
| §3, lateral Mapeamento | "Fonte: `EstruturaOferta`" | `estrutura_ofertas` só tem `company_id, variacao_id, sku, fase, nome, logistica, observacoes` | Medidas/peso de `estrutura_produto_volumes` via `variacao_id`; material/EAN de `estrutura_produto_atributos`. Documentado no docblock de `mapeamento()` |
| §3, estado da fase | "Em preparação (rascunho, conferir, pronto, publicando)" | a chave `rascunho` de `prontidao()` significa **não existe rascunho** (é o "Sem rascunho" do `SeloStatusProduto`) | `rascunho → nao_iniciada`; `conferir/pronto/publicando → em_preparacao`. Divergência registrada no docblock de `estadoDaFase()` e exigida pelo `<behavior>` da plan |
| Plan, Task 1 | `base` como nome do bloco de cabeçalho | o contrato de props da página (must_haves) chama de `produto` | O controller mapeia `base → produto`; o painel aceita os dois nomes |
| Plan, Task 3 `<behavior>` | "campo de texto chegando como objeto (ex.: `base.nome`)" | a prop da página é `produto.nome` (mesmo campo) | O teste cobre `produto.nome`; o painel também aceita `base` |
| Plan, `<verification>` | "`tests/Feature/Publicador` (baseline 871)" | o baseline MEDIDO antes desta plan era **934** em `tests/{Feature,Unit}/Publicador` | Baseline confirmado por execução antes de escrever código; fechou em 965 |
| Plan, Task 1 | "`detalhe_disponivel` false com `detalhe_motivo` preenchido" quando sem Company | ok, mas `MlAcervoItem` é por `company_id`: sem Company **não há acervo nenhum** | Sem Company, preço/vendas/visitas/situação saem **nulos** (nunca zero) além do `detalhe_disponivel` false |
| Plan, Task 2 `<behavior>` | "`Http::assertNothingSent()`" implícito no teste de página | `HandleInertiaRequests` chama `files.ecfconsultoria.com.br/api/v1/signals` em TODA página autenticada | O teste de página filtra por `mercadolibre`; o teste do SERVIÇO continua com `assertNothingSent()` estrito |

Nada de `App\Support\Publicador`, `MlPublicacaoService`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx` ou `usePublicador.js` foi tocado — `git diff --stat` desses caminhos sai vazio.

## Decisions Made

Ver `key-decisions` no frontmatter. Em uma linha cada:

1. Mapeamento pelo caminho real do Portal (volumes + atributos), não por `estrutura_ofertas`.
2. Vários volumes → medidas do primeiro, peso somado.
3. `rascunho → nao_iniciada` (a chave de `prontidao()` significa "sem rascunho").
4. `estoque_total` só de variantes ativas, para o `floor(base ÷ N)` bater.
5. Categoria direto de `ml_categoria_schemas`; fora do cache, o id.
6. IA no histórico por consulta escopada + casamento em PHP (o vínculo é JSON).
7. "Fase criada"/"combo vinculado" por `created_at`/`updated_at` — limitação aceita, registrada.
8. "Criar Fase N" sempre desabilitado nesta plan, com o motivo do servidor visível.
9. `detalhe_disponivel` exige `company_id` (D23).
10. O painel aceita `produto` e `base`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `Http::assertNothingSent()` reprovava o teste da página por uma chamada que não é da tela**
- **Found during:** Task 2 (rota + controller)
- **Issue:** `HandleInertiaRequests` busca os sinais críticos do ECF Drive (`files.ecfconsultoria.com.br/api/v1/signals`) em toda página autenticada. Com `Http::fake()` no `setUp`, essa chamada fica gravada e `assertNothingSent()` falha — dando a impressão falsa de que a tela do Produto fala com o Mercado Livre.
- **Fix:** o teste de PÁGINA passou a usar `Http::assertNotSent(fn ($q) => str_contains($q->url(), 'mercadolibre'))`, com comentário explicando por que `assertNothingSent()` não serve ali. Os testes do SERVIÇO (que não passam pelo middleware) seguem com `assertNothingSent()` estrito.
- **Files modified:** `tests/Feature/Publicador/TelaDoProdutoTest.php`
- **Verification:** `C:/xampp/php/php.exe artisan test tests/Feature/Publicador/TelaDoProdutoTest.php` — 31 verdes.
- **Committed in:** `0aa2742d`

**2. [Rule 2 - Correção] `Collection::offsetGet` sem `?? null` em chave ausente**
- **Found during:** Task 1 (serviço)
- **Issue:** `$rascunhos[$p->id]?->id` dispara aviso de chave indefinida em PHP 8 quando o produto da família não tem rascunho — `Collection::offsetGet()` acessa `$this->items[$key]` direto. O padrão do módulo (`produtosParaTela()`) sempre usa `?? null`.
- **Fix:** `($rascunhos[$p->id] ?? null)?->id` e `estoqueTotal()` reescrito com uma consulta só (`get(['estoque'])` + `isEmpty()`), em vez de duas (`sum()` + `exists()`).
- **Files modified:** `app/Services/Publicador/FamiliaDeFasesService.php`
- **Verification:** suíte verde sem avisos; `test_base_sem_kit_e_sem_rascunho_...` cobre exatamente o caminho sem rascunho.
- **Committed in:** `1f8db947`

**3. [Rule 1 - Bug] Expressão morta no rótulo de origem**
- **Found during:** Task 3 (componente)
- **Issue:** `ROTULO_ESTADO_FASE[''] ?? ROTULO_ORIGEM[...]` — sobra de edição; funcionava por acidente (`undefined ?? x`), mas lia o mapa errado.
- **Fix:** `ROTULO_ORIGEM[textoSeguro(p.origem, '')] ?? null`.
- **Files modified:** `resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx`
- **Verification:** render real verde; `npm run build` verde.
- **Committed in:** `4bd53c39`

---

**Total deviations:** 3 auto-corrigidas (2× Rule 1, 1× Rule 2). Nenhuma mudança arquitetural; nenhum escopo novo.
**Impact on plan:** nenhum. As três eram necessárias para o teste dizer a verdade (1), para não emitir aviso em produção (2) e para o rótulo ler o mapa certo (3).

## Issues Encountered

- **Baseline do plano estava desatualizado** (871 contra os 934 medidos). Resolvido rodando a suíte ANTES de escrever qualquer código e usando o número medido como referência — é o número que o prompt de execução já informava.
- **`ModalDetalheAnuncio` arrasta `recharts` e `@radix-ui/react-dialog`** para o bundle de teste. Resolvido somando os dois ao `external` do esbuild (o modal nunca é renderizado no teste: só é montado quando alguém clica em "Detalhe").
- **Nenhum problema de banco local:** `mysqld` estava rodando, mas a suíte usa SQLite em memória (`phpunit.xml`), então nada dependeu do MariaDB local.

## User Setup Required

Nenhuma configuração de serviço externo.

## Roteiro clicável de validação (PRODUÇÃO — conta #459 "Dev 02 Testes API")

⚠️ **O banco local não tem empresa com `ml_token`**, então a tela não pode ser conferida visualmente aqui. Depois de um deploy autorizado, o roteiro exato é:

1. Abrir `https://admin.ecfconsultoria.com.br/mlb/anuncios`.
2. Na barra de programas, escolher **Gestão**.
3. Na lista, clicar na empresa **"Dev 02 Testes API" (#459)**.
4. Abrir a aba **Produtos**.
5. Clicar num produto **já publicado** (selo verde "Publicado").

**O que deve aparecer:**

- Cabeçalho com a **foto 1**, o nome, o **SKU** em fonte mono, a pílula de origem ("Do Portal" ou "Cadastrado aqui"), a **categoria** (caminho completo, ex.: `Casa, Móveis e Decoração › Cadeiras de Escritório`) e o **estoque**; à direita, o botão **"Editar Fase 1"**, que abre o MESMO editor de hoje.
- Bloco **Fases** com **UM cartão** "Fase 1 · 1 unidade", o selo "Publicado" e "N anúncios no ar".
- Ao lado, o cartão tracejado **"Criar Fase 2"** com o botão **desabilitado** e o texto **"Em breve nesta tela"** embaixo (se a Fase 1 ainda não estiver publicada, o texto é "Publique a Fase 1 primeiro").
- Bloco **Anúncios no ar** com uma linha por anúncio: Fase, Tipo (**Clássico**/**Premium**), Título, o **MLB clicável** (abre o anúncio no Mercado Livre em nova aba), Preço, Vendas, Visitas, Situação e **"Detalhe"** — que abre o modal de 90 dias de Meus Anúncios.
- Bloco **Histórico** com a **publicação que o usuário fez** (quem publicou, há quanto tempo, "N anúncios criados", Fase 1).
- Lateral **Criativos**: as miniaturas do kit aprovado, se houver; senão "Nenhum criativo aprovado ainda."
- Lateral **Mapeamento**: medidas (`C × L × A cm`), peso em kg, material e EAN. Se a oferta do Portal for antiga (sem `variacao_id`), aparece o bloco **âmbar** "não informado" — isso é o comportamento correto, não um erro.
- **A barra da conta, as abas (Visão geral · Produtos · Publicações · Alavancas · Configurações) e tudo o que já existia continuam iguais.** A aba ativa é **Produtos**.

**Conferência de segurança (D-13), também clicável:**

6. Na barra de endereços, trocar o número do produto no fim da URL (`.../produtos/{id}`) por um id de **produto de outra empresa** → a página deve responder **404**, não 403 e não uma tela vazia.
7. Trocar `empresa-459` por `company-<id da Company>` na mesma URL → deve **redirecionar** para a forma canônica `empresa-...`, mantendo o mesmo produto.

## Next Phase Readiness

**Pronto para as plans seguintes:**

- **175-05/06/07 (painel "Criar Fase 2")**: `MlbPublicadorFaseController` já existe e é o lugar dos endpoints novos; `proxima_fase` já chega com `numero`, `quantidade_sugerida`, `habilitado` e `motivo`; o ponto de montagem está marcado no JSX com `{/* 175-07: <PainelCriarFase /> entra aqui */}` e o botão passa a ser ligado ali (basta trocar o `disabled` fixo pelo `proxima_fase.habilitado`).
- **175-08/09/10 (coluna Fases na lista, Visão geral por fase, vínculo de combos)**: `FamiliaDeFasesService` já expõe `fases` com `estado_fase` e `estoque_proprio`/`estoque_calculado_valor`, e o `SugestaoDeKitService` (175-03) continua sem consumidor — o vínculo desfazível que a §6 pede cabe na tela do Produto sem mudar este payload.

**Pontos de atenção registrados:**

- `historico` ainda não distingue com precisão "combo vinculado" de "fase criada" (usa `created_at`/`updated_at`). Se a Etapa 4 precisar de precisão, o caminho é uma coluna `vinculado_em`, não refinar a heurística — já anotado em comentário no serviço.
- A tela **não** foi validada visualmente: isso depende de deploy autorizado e da conta #459 em produção (roteiro acima).
- As **2 falhas JS pré-existentes** (`estrutura-grade-glide.test.js`, `polosEntrantes.test.js`) continuam exatamente 2 — não são regressão desta plan.

---
*Phase: 175-publicador-etapa-3-produto-fases*
*Plan: 04*
*Completed: 2026-10-08*

## Self-Check: PASSED

Conferido por execução, não por memória:

- Os 7 arquivos declarados existem em disco (`[ -f ]` em cada um).
- Os 6 commits existem no histórico (`git log --oneline --all | grep`).
- `app/Services/Publicador/FamiliaDeFasesService.php` tem **822 linhas** (o `min_lines` do plano era 200).
- `grep -n "publicador.produto'" routes/mlb_anuncios.php` → linha 247 (o `key_link` da rota).
- `grep -n prontidao app/Services/Publicador/FamiliaDeFasesService.php` → presente (o `key_link` do estado derivado).
- `C:/xampp/php/php.exe artisan test tests/Feature/Publicador tests/Unit/Publicador` → **965 passed**.
- `node --test tests/js/publicador-produto-render.test.js` → 25 subtestes verdes.
- `npm run test:js` → 1425 testes, 1423 ✓, 2 ✗ (as mesmas duas pré-existentes).
- `npm run build` → verde; `resources/js/Pages/Mlb/Publicador/Produto.jsx` presente no `public/build/manifest.json`.
- `git diff --stat` de `usePublicador.js`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `app/Support/Publicador` e `MlPublicacaoService.php` → **vazio**.
