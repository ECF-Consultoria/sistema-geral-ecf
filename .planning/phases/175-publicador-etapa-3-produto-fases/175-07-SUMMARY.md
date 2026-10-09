---
phase: 175-publicador-etapa-3-produto-fases
plan: 07
subsystem: ui
tags: [publicador, kit, fases, react, inertia, ia, creative-engine, render-gate]

# Dependency graph
requires:
  - phase: 175-04
    provides: "PainelDoProduto.jsx com os 6 blocos da §3 e o ponto de montagem marcado `{/* 175-07: <PainelCriarFase /> entra aqui */}`"
  - phase: 175-05
    provides: "PreviaDaFaseService + endpoints publicador.fases.previa e publicador.fases.criar (prévia, avisos, erro_campo, recusas KIT-01..05)"
  - phase: 175-06
    provides: "SugestaoKitIaService + endpoints publicador.fases.ia e publicador.fases.ia.status; CapaDoKitService devolvendo ['ok','motivo','kit_id'] na resposta 201"
  - phase: 173-03
    provides: "textoSeguro() exportado de BarraDaConta.jsx"
provides:
  - "useSugestaoKitIa.js — acompanhamento do pedido de IA do kit, com o núcleo `umaVolta()` SEM React (testável em Node puro)"
  - "PainelCriarFase.jsx — o painel lateral de 620px da §4, em dois componentes: casca (estado/rede) e CorpoDoPainel (puro)"
  - "valoresAposPrevia(), erroLocalDaQuantidade(), erroDeRecusa(), motivoDaCapa(), tituloSugerido() — as regras da §4 que são cálculo puro, exportadas"
  - "O botão 'Criar Fase N' da tela do Produto abrindo o painel (deixa de ser 'Em breve nesta tela')"
affects: [175-08, 175-10, publicador]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Casca (estado + rede) separada do Corpo (puro) para que o gate de render exercite o payload do servidor sem depender de useEffect — não há DOM nos testes deste projeto"
    - "Núcleo de polling como função async pura (`umaVolta`) em vez de lógica dentro do useEffect: o comportamento ganha prova real sem jsdom"
    - "Mapa de campos TOCADOS à mão para que o recálculo do servidor nunca apague o que a pessoa digitou"
    - "Campo não tocado vai como `null` no POST, para o servidor manter a sugestão POR TIPO que o campo único não representa"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/useSugestaoKitIa.js
    - resources/js/Components/Mlb/Publicador/PainelCriarFase.jsx
    - tests/js/publicador-painel-criar-fase-render.test.js
  modified:
    - resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx
    - tests/js/publicador-produto-render.test.js

key-decisions:
  - "UM campo de título, não um por tipo: é o único formato que o contrato do servidor aceita (`titulo` string única em `fases.criar`), e campo não tocado vai como null para o servidor preservar a diferença por tipo da Fase 1"
  - "A chave interna da variante (`combinacao_chave`, ex. `COLOR=id:52049|SIZE=txt:m`) NUNCA vai para a tela: cada linha de estoque é rotulada pelo SELLER_SKU; `__single__` vira 'todas as unidades'"
  - "Mudar N LIMPA a marca 'sugerido pela IA' e recalcula o campo: uma sugestão feita para Kit 2 fala de 2 unidades e produziria um Kit 3 com título errado. Só edição À MÃO é protegida (é o que a §4 diz literalmente)"
  - "Arquivo de hook NOVO em vez de reaproveitar usePublicador.js: aquele acompanhamento é estado do EDITOR (rascunho em memória) e o painel aplica em campos locais; extrair mexeria no editor em produção sem ganho"
  - "Sem sessionStorage no hook (diferença deliberada do useIaDoPublicador): o painel é efêmero, um F5 o fecha e apaga os campos — guardar o pedido criaria acompanhamento órfão"
  - "Recusa da capa (201 com capa.ok=false) não fecha o painel nem é erro: mostra o motivo e oferece 'Abrir o editor do kit'"
  - "Botão travado também quando falta a conta na tela ou o id do produto — sem eles o painel não tem a quem pedir a prévia (D23: travado COM explicação)"

# Metrics
duration: ~2h
completed: 2026-10-09
---

# Fase 175 Plano 07: Painel "Criar Fase N" Summary

O painel lateral de 620px da §4, consumindo os contratos fechados nos 175-05/06, com
hook próprio de acompanhamento da IA e o botão da tela do Produto finalmente ligado.

## O que foi feito

### 1. `useSugestaoKitIa.js` (novo, 242 linhas)

Acompanha o pedido de título/descrição do kit. Mesma disciplina dos hooks irmãos
(`useIaDoPublicador`, `useCriativosDoPublicador`, `useAcervoDaConta`): `INTERVALO = 2500`,
limite de tempo próprio, só aceita a resposta do **próprio** pedido, leitura que falha
não para o acompanhamento, `clearInterval` no unmount, pedidos num `useRef` para o
intervalo não fechar sobre valor velho.

Duas escolhas que fogem do molde, de propósito:

- **`LIMITE = 3 min`** (não os 15 min do `useIaDoPublicador`): a sugestão do kit é UMA
  chamada de texto, não a análise do produto inteiro. Passar disso é fila travada.
- **Sem `sessionStorage`**: o painel é efêmero — um F5 fecha o painel e apaga todos os
  campos locais, então não há o que retomar. Guardar o pedido só criaria um
  acompanhamento órfão de um painel que não existe mais.

O núcleo é `umaVolta({pendentes, ler, agora, limite})`, uma função **async sem React**
que decide o que fazer com cada leitura e devolve `{alvo, acao, valor?, erro?, encerra}`.
Isso existe por uma razão concreta: **não há DOM nos testes deste projeto** (sem jsdom,
sem react-test-renderer), então efeito de React nunca roda no gate. Com o núcleo fora do
`useEffect`, "pedido antigo é ignorado", "pronto encerra", "erro de rede não muda o
status" e "passado o limite vira erro sem gastar leitura" ganharam prova de verdade em
vez de regex sobre a fonte.

`interpretarLeitura()` recusa `valor` que não é string (objeto, número, vazio) e o
transforma em erro: esse campo cairia no `<textarea>` e, antes disso, num `{valor}` do
JSX — a lição de 07/10 aplicada à resposta da IA.

### 2. `PainelCriarFase.jsx` (novo, 795 linhas)

Painel lateral `w-[620px] max-w-full`, overlay, `role="dialog"` + `aria-modal`, Escape e
clique fora, foco inicial no campo Unidades (molde do `ModalNovoProduto.jsx`), tokens
`ecf-*`, `cn()`, botões de `Publicador/Mesa/botoes.jsx`.

**Dois componentes no mesmo arquivo:**

- `PainelCriarFase` (default) — a CASCA: estado, `axios`, debounce, hook da IA.
- `CorpoDoPainel` (named) — o CORPO, puro: recebe o payload do servidor e os valores dos
  campos, e só desenha.

A divisão não é estética. Sem DOM nos testes, um painel que só mostrasse a prévia
**depois** do `useEffect` jamais seria exercitado pelo gate de render — exatamente a
fenda pela qual o campo-objeto chegou a produção em 05-07/10. Com o corpo separado, o
gate renderiza o payload REAL de `PreviaDaFaseService` (e as versões adversas dele) sem
depender de efeito nenhum.

**Os campos, na ordem da §4:**

| # | Campo | Comportamento |
|---|-------|---------------|
| 1 | Unidades no kit | inteiro ≥ 2, sem teto de interface; debounce de 350 ms antes da prévia; erro local (vazio/0/1/não numérico) não chama o servidor |
| 2 | SKU do kit | máx 120, da prévia, marca "editado por você" quando tocado; repetido é aviso, não bloqueio |
| 3 | Estoque | `readOnly`, legenda "calculado do produto base", uma linha por variante e uma por depósito; desconhecido vira "—", nunca 0 |
| 4 | Título | um campo, contador contra `max_title_length`; com mais de um tipo, mostra o título de cada tipo e avisa que editar aplica o mesmo a todos |
| 5 | Descrição | textarea |
| 6 | Sugerir com IA | um botão que pede os DOIS alvos; "pedindo…" enquanto roda; marca "sugerido pela IA" + "Desfazer" que volta ao valor imediatamente anterior |
| 7 | Capa | checkbox **marcada por padrão**, renderizada só com `criativosIa`; desmarcada mostra "A capa ainda mostra 1 unidade" |
| 8 | Confirmar | POST com `{quantidade, sku, titulo, descricao, seller_skus, capa}`; desabilitado durante a prévia e durante o POST |

**Preço: nenhum campo, nenhuma sugestão, nenhuma frase escrita no front.** A decisão de
2026-10-08 (`175-DECISOES.md` item 2) é cumprida pelo aviso `preco_vazio` que vem do
servidor em `avisos[]` — o painel renderiza `aviso.mensagem` e pronto. Há gate de fonte
provando que o painel **não escreve** essa frase, e gate de render provando que uma
mensagem arbitrária do servidor aparece.

**Os avisos:** TODOS os do servidor, por `aviso.mensagem`, num bloco âmbar "Antes de
confirmar" imediatamente acima do Confirmar — e **nenhum deles desabilita o botão**.
Chave desconhecida também aparece (nada é engolido). Perto dos campos ficam marcadores
curtos da própria UI (o contador do título com "cortado na última palavra inteira", "este
código já existe nesta empresa"), nunca uma reescrita do texto do servidor.

### 3. Montagem na tela do Produto

O comentário `{/* 175-07: <PainelCriarFase /> entra aqui */}` saiu e deu lugar à montagem
real. O botão "Criar Fase N" deixou de ser "Em breve nesta tela":

- `proxima_fase.habilitado === true` + conta + id do produto → botão clicável que abre o painel;
- qualquer um dos três faltando → travado **com o motivo visível** (D23). Quando o motivo
  é a falta da conta na tela, o texto é próprio ("Esta tela não recebeu a conta do
  produto. Recarregue a página.") em vez de reaproveitar o motivo do servidor, que fala
  de outra coisa;
- `onCriado(url)` → `router.get(url)`. A URL vem do servidor **já com `?etapa=condicoes`**;
  o painel não a monta.

Nada mais no arquivo mudou — o gate confere que os 6 blocos da §3 continuam de pé.

## Verificação

| Gate | Resultado |
|------|-----------|
| `node --test tests/js/publicador-painel-criar-fase-render.test.js` | 74 testes, 74 passando |
| `node --test ...criar-fase... ...produto-render...` | 101 testes, 101 passando |
| `npm run test:js` (suíte inteira) | **pass 1517 / fail 2** (baseline medido: **1442 / 2**) — as 2 falhas são as mesmas pré-existentes: `estrutura-grade-glide.test.js:123` e `polosEntrantes.test.js:188` |
| `npm run build` | verde em 40,24 s; `resources/js/Pages/Mlb/Publicador/Produto.jsx` segue no manifest (`assets/Produto-CVS9QkmR.js`), e o bundle contém "Unidades no kit" |
| `git diff --stat resources/js/Components/Publicador/usePublicador.js` | **vazio** |
| arquivos de servidor (`app/`, `routes/`) | **nenhum tocado** por esta plan |

Contagens medidas pelos contadores do `node --test` (`ℹ pass` / `ℹ fail`), não pelas
marcas `✔` — estas contam suítes também e foi daí que vieram os números divergentes
entre sessões.

### O gate de render contra a tela preta de 07/10

O teste compila os módulos de verdade com esbuild (o mesmo motor do Vite) e renderiza com
`react-dom/server`. Cobre:

- **cada campo da prévia chegando como objeto** (`sku`, `titulo_por_tipo`, `descricao`,
  `variantes.*`, `avisos[].mensagem`, `erro_campo`, `max_title_length`, `tipos`);
- `avisos` não-array, `variantes` nula, `tipos` não-array, `titulo_por_tipo` nulo;
- `resultado`, `iaEstados`, `errosCampo`, `tocados`, `sugeridos`, `anteriores` adversos;
- **todas as props ausentes**, na casca e no corpo;
- `empresa` e `criativos_ia` adversos na tela do Produto.

Em todos: não lança e **nenhum `[object Object]`** (nem `undefined`, nem a chave `foo` dos
objetos-sonda) no HTML.

**Armadilha do Rollup:** todo `.map()` deste painel calcula suas flags **dentro** do
callback, e quando precisa do tamanho da lista usa o **terceiro argumento** do `map`
(passado pelo runtime) em vez de uma variável capturada. São quatro `.map()`: avisos,
variantes, depósitos e títulos por tipo.

### Como provei que campo editado à mão não é sobrescrito ao mudar N

A regra virou função pura exportada, `valoresAposPrevia({dados, tocados, valores})`, e tem
quatro provas diretas:

1. nada tocado → a prévia de N=3 atualiza SKU, título e descrição;
2. `tocados: {sku: true}` → o SKU digitado (`CAD-DUPLA-ESPECIAL`) sobrevive e os outros dois
   são atualizados;
3. os três tocados → a prévia nova não apaga nenhum;
4. prévia adversa → string vazia, nunca objeto.

Na casca, o mapa de tocados vive num `useRef` (`tocadosRef`): a resposta da prévia chega
**depois** e não pode fechar sobre o estado da renderização em que a chamada saiu. No
render, o campo tocado ganha a marca "editado por você", também com gate.

## Desvios do plano

### 1. [Rule 3 - Bloqueio] O teste do 175-04 que exigia "Em breve nesta tela" foi atualizado

**Encontrado em:** Task 3.
**O quê:** `publicador-produto-render.test.js` tinha o caso
`'proxima_fase.habilitado=true ainda fica desabilitado nesta plan, com "Em breve nesta
tela"'` — a trava provisória que **esta plan existe para remover**. Mantê-lo faria o gate
falhar; o arquivo não está em `files_modified` do plano, mas está no `<verification>`.
**Como ficou:** o caso virou `'habilitado=true deixa o botão CLICÁVEL, sem "Em breve
nesta tela"'`, e entrou um caso novo para o botão travado por falta de conta.
**Commit:** `64017899`.

### 2. [Rule 1 - Bug no teste] `assert.match(..., /disabled/)` era asserção vazia

**Encontrado em:** Task 2, ao rodar o gate pela primeira vez.
**O quê:** `BASE_BOTAO` contém as classes `disabled:pointer-events-none disabled:opacity-40`.
Qualquer regex `/disabled/` sobre a tag do botão casa **sempre**, inclusive quando o botão
está habilitado. Os `assert.match(..., /disabled/)` passavam sem provar nada e os
`doesNotMatch` falhavam sem motivo.
**Como ficou:** todas as asserções passaram a usar `/disabled=/` (o atributo que o React
renderiza como `disabled=""`), nos dois arquivos de teste. Sem isso, "Confirmar fica
desabilitado durante o POST" seria um gate de fachada.
**Commit:** `c1068931` / `64017899`.

### 3. [Decisão de contrato] UM campo de título, não um por tipo

**O quê:** o `<action>` da Task 2 pede "um campo por tipo de `tipos`". O contrato do
servidor aceita **um** `titulo` (string) — e o próprio docblock de
`MlbPublicadorFaseController::titulosParaGravar()` diz "O painel tem UM campo de título
(§4)". Vários campos não teriam onde ser gravados.
**Como ficou:** um campo editável. Quando `tipos` tem mais de um item, o painel lista o
título sugerido de cada tipo (somente leitura) e avisa que editar aplica o mesmo a todos.
E — o detalhe que importa — **campo não tocado vai como `null` no POST**, porque é assim
que o servidor mantém a sugestão por tipo: mandar o texto do campo único apagaria a
diferença entre os títulos da Fase 1.

### 4. [Decisão de UI] A chave interna da variante não vai para a tela

**O quê:** a prévia devolve `variantes` indexado por `combinacao_chave`
(`COLOR=id:52049|SIZE=txt:m`, ou `__single__`). O plano não diz como rotular as linhas de
estoque, e a prévia não devolve rótulo humano.
**Como ficou:** cada linha é rotulada pelo **SELLER_SKU** (que é significativo para o
operador); `__single__` com um item só vira "todas as unidades"; sem SELLER_SKU, "variação
N". A chave interna nunca aparece — é identidade interna e jargão puro (regra sistêmica de
evitar jargão sem explicação). Há gate provando que `COLOR=id` e `__single__` não chegam ao
HTML.

### 5. [Decisão] Mudar N limpa a marca "sugerido pela IA"

**O quê:** o plano protege do recálculo os campos "editados à mão". Uma sugestão da IA não
é edição à mão — mas também não é a sugestão do servidor.
**Como ficou:** mudar N recalcula o campo e **apaga** a marca da IA. A alternativa
(proteger a sugestão) criaria um Kit 3 com o título "Kit 2 …" que a IA escreveu para o N
anterior — número errado no título é pior que sugestão perdida, e a chave de cache do
servidor inclui o N justamente por isso. Edição à mão continua protegida.

### 6. [Registrado, não corrigido] `criativos_ia` não chega à tela do Produto

**O quê:** o painel só renderiza a caixa da capa com `criativos_ia === true`, mas
`MlbPublicadorFaseController::mostrar()` **não envia essa prop**. Em produção a caixa não
aparece e o Confirmar manda `capa: false` sempre — a capa do kit entregue pelo 175-06 não
é acionável pela tela.
**Por que não corrigi:** `MlbPublicadorFaseController.php` é do `175-08`, que rodou em
paralelo e é dono do arquivo. A instrução era explícita: parar e registrar.
**Onde ficou:** `deferred-items.md`, item 3, com a linha exata a acrescentar e a referência
literal (`MlbPublicadorEntradaController.php:260`) de como as duas condições são
combinadas neste módulo.

### 7. [Processo] O commit do RED da Task 1 saiu sem a linha de atribuição

`1a5b1114` (`test(175-07): prova do nucleo do acompanhamento de IA do kit`) ficou sem o
`Co-Authored-By`; percebi depois e `--amend` é proibido na árvore compartilhada. Os quatro
commits seguintes têm a linha.

## Observações sobre TDD

- **Task 1:** RED observado de verdade (`ℹ pass 1 / fail 2`, build do esbuild falhando por
  módulo inexistente) → commit `test` → GREEN → commit `feat`.
- **Task 2:** o arquivo de teste foi escrito **antes** do componente, mas eu rodei a suíte
  só depois de criar o componente. Para não deixar o gate sem prova, renomeei
  temporariamente `PainelCriarFase.jsx` para fora da árvore e rodei: `ℹ pass 18 / fail 3`
  (os três testes do painel falhando), restaurei e rodei de novo. Sem `git stash`, sem
  `git reset`.
- Sequência de gates no `git log`: `test(175-07)` → `feat(175-07)` → `test(175-07)` →
  `feat(175-07)` → `feat(175-07)`.

## Como o usuário confere (produção, conta #459 "Dev 02 Testes API")

O banco local não tem empresa com `ml_token`, então o roteiro é clicável e em produção,
**depois de um deploy autorizado**.

1. Abrir `/mlb/anuncios` → aba **Gestão** → **"Dev 02 Testes API"** → **Produtos**.
2. Clicar num produto **já publicado** (Fase 1 publicada, inclui "Parte publicada").
3. Na seção **Fases**, clicar **"Criar Fase 2"** — o botão agora é clicável e o painel
   abre pela direita, com 620 px.
4. Conferir o estado inicial:
   - **Unidades no kit = 2**;
   - **SKU** terminando em `-KIT2`;
   - **Estoque** em cinza/somente leitura, com "calculado do produto base" e um número
     igual à metade do estoque do base, arredondado para baixo;
   - **Título** começando com "Kit 2 ", com o contador contra o limite da categoria;
   - **Descrição** começando com "Este kit contém 2 unidades de …";
   - **nenhum campo de preço** em lugar nenhum;
   - o bloco âmbar **"Antes de confirmar"** com o aviso de que o kit nasce sem o valor e
     só publica depois que ele for informado no editor — e o **Confirmar habilitado**.
5. **Trocar 2 por 3**: SKU, título, descrição e estoque têm de mudar na hora (há ~350 ms
   de debounce).
6. **A prova que importa:** digitar um SKU à mão (ex.: `CAD-DUPLA-ESPECIAL`), ver a marca
   "editado por você" aparecer, e **depois** trocar a quantidade. O SKU digitado **não**
   pode ser apagado; título, descrição e estoque devem atualizar.
7. Digitar `1` nas unidades: tem de aparecer "Um kit tem 2 unidades ou mais." no campo e o
   Confirmar tem de travar — sem ida ao servidor.
8. Se a família já tiver um Kit 2, digitar `2` mostra **"Já existe Kit 2 deste produto."**
   no campo das unidades e trava o Confirmar.
9. Clicar **"Sugerir com IA"**: o botão vira "pedindo…" e, em até ~1 minuto, título e
   descrição mudam com a marca **"sugerido pela IA"** e um **"Desfazer"** que volta ao
   valor anterior.
   ⚠️ A IA roda na fila `high`. Se nada voltar, conferir `supervisorctl status` e rodar
   `sudo -u www-data php artisan queue:restart` depois do deploy.
10. **A caixa "Gerar a capa do kit" NÃO vai aparecer ainda** — ver o desvio 6 e o item 3
    do `deferred-items.md`. Isso é esperado nesta plan, não um defeito do painel.
11. **Confirmar** leva ao editor do kit já na etapa **Condições de venda**. A barra do
    editor deve mostrar "Fase 2 · Kit 2" com link "Produto base".

## Known Stubs

Nenhum stub no painel. O único ponto em que a tela está pronta e o servidor ainda não
alimenta é a prop `criativos_ia` (desvio 6 / `deferred-items.md` item 3): o default é
`false` e o efeito é a ausência da caixa da capa, não um placeholder na tela.

## Threat Flags

Nenhuma superfície nova. O painel só consome os dois endpoints já registrados no
`<threat_model>` do plano e as mitigações estão no lugar:

| Ameaça | Como ficou |
|--------|------------|
| T-175-28 (campo em forma inesperada derruba a tela) | `textoSeguro()`/`numeroSeguro()`/`objetoSeguro()`/`listaSegura()` em todo campo do servidor; gate de render com objeto, nulo, lista não-array e props ausentes |
| T-175-29 (corpo mandando estoque/âncora/base) | o corpo tem só `quantidade`, `sku`, `titulo`, `descricao`, `seller_skus` e `capa`; gate de fonte proíbe `estoque:`, `mlb_empresa_id`, `company_id` e `produto_base_id` no arquivo |
| T-175-30 (duplo clique criando dois kits) | Confirmar desabilitado enquanto `enviando`, com gate de render |
| T-175-31 (token de criativo no navegador) | o painel manda só `capa: true/false`; gate de fonte proíbe a palavra `token` no arquivo |

## Commits

| Hash | Mensagem |
|------|----------|
| `1a5b1114` | `test(175-07): prova do nucleo do acompanhamento de IA do kit` |
| `b5178a20` | `feat(175-07): useSugestaoKitIa, o acompanhamento da IA do kit` |
| `c1068931` | `test(175-07): prova dos 8 campos do painel Criar Fase N` |
| `70982cbb` | `feat(175-07): PainelCriarFase, os 8 campos da secao 4` |
| `64017899` | `feat(175-07): o botao Criar Fase N abre o painel na tela do Produto` |

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/useSugestaoKitIa.js` — existe (242 linhas)
- `resources/js/Components/Mlb/Publicador/PainelCriarFase.jsx` — existe (795 linhas, mínimo pedido: 250)
- `tests/js/publicador-painel-criar-fase-render.test.js` — existe (74 testes verdes)
- `resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx` — modificado, marcador substituído
- os 5 commits acima existem no `git log`
- `usePublicador.js` sem diferença; nenhum arquivo de `app/` ou `routes/` tocado
