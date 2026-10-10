---
quick_id: 261010-ptg
slug: precificacao-lucro-alvo-zero-frete-fora
date: 2026-10-10
type: quick
requirements: [PTG-B, PTG-C, PTG-D, PTG-E]
status: concluido
commits:
  - 7a6f752c
  - ad86541b
key-files:
  created:
    - tests/Feature/Publicador/RecebimentoAbaixoDoCustoTest.php
    - tests/js/publicador-quanto-recebo.test.js
  modified:
    - app/Services/Publicador/DadosEfetivosService.php
    - app/Services/Publicador/EditorRascunhoService.php
    - resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx
    - tests/Feature/Publicador/Concerns/CenarioCadeira.php
metrics:
  php: "1713 / 0 -> 1728 / 0"
  js: "2125 pass / 1 fail -> 2140 pass / 1 fail (a mesma falha pre-existente)"
---

# Precificacao: o card passa a contar a verdade (o preco nao mudou)

O usuário testou a Poltrona Beny de ponta a ponta (custo R$ 280) e viu "Quanto você recebe" em
R$ 263,84 (Clássico) e R$ 281,75 (Premium) — prejuízo contra o custo. **A fórmula do preço está certa
e o preço NÃO mudou.** A causa do prejuízo é o **frete ausente** na Precificação do Portal (essa
oferta não tem linha de precificação, então o frete entra como zero), mais uma tela que mostrava um
número sem o contexto necessário para a pessoa perceber.

Esta tarefa consertou só a informação que faltava na tela.

## 1. A Poltrona Beny: antes x depois no card

O **preço não mudou** — segue 483,45 (Clássico) e 520,92 (Premium), com os divisores 0,695 e 0,645.
Mudou o que a tela conta sobre ele:

| | Clássico (483,45) | Premium (520,92) |
|---|---|---|
| Você recebe (hoje, **única** linha do card) | 263,84 | 281,75 |
| Custo | 280,00 | 280,00 |
| Lucro antes do imposto | **−16,16** | **+1,75** |
| Imposto reservado (19% do preço) | 91,86 | 98,97 |
| Lucro depois do imposto | **−108,02** | **−97,22** |
| Margem estimada | **−22,34%** | **−18,66%** |
| `abaixo_do_custo` | true | false |
| `prejuizo_com_imposto` | false (o aviso de cima já falou) | **true** |

**O Premium é a demonstração do conserto:** hoje a tela mostra algo que **parece sobrar R$ 1,75** e a
verdade é **R$ 97,22 no vermelho** depois do imposto que o próprio preço reserva — porque o Mercado
Livre **não desconta** imposto, e comparar "Quanto você recebe" direto com o custo **subestima** o
prejuízo. Esses dois números estão travados em teste.

Com o frete informado na Precificação do Portal, o preço sobe e todos esses números mudam. **Qualquer
preço novo só pode ser medido pelo próprio usuário clicando "Calcular"**: tarifa e frete vêm da API
real do Mercado Livre naquele instante. Não há navegador nesta sessão — **nenhuma tela renderizada foi
vista** e **produção não foi consultada**; a conferência visual é do usuário.

## 2. O que NÃO mudou, e a prova

O usuário desfez hoje a decisão de somar lucro-alvo ao preço. Nada disso foi tocado:

- **`EstruturaPrecificacaoParametros::PADROES`** — `margem_contribuicao` e `lucro_liquido` seguem em
  **`0.0`**. MC/LL zero é o padrão deliberado do sistema inteiro: o `minimo` é o ponto de equilíbrio e
  o `anunciado` é `minimo x 1,2` (sobram ~16,7% da receita no anunciado).
- **A fórmula do preço**, os divisores (0,695 / 0,645), o **`acrescimo`** (20%) e a semântica
  **`anunciado`/`minimo`** (ADR PORTAL-02).
- **O imposto no divisor** — está lá de propósito, como provisionamento. Não foi removido.
- **O V-SAL-08** ficou como está: a saída robusta é o aviso que não depende de procedência (conserto C).
- **Nenhuma migration**, nenhuma constante de modelo.
- **`App\Support\Publicador`** inteiro: `SimuladorVoceRecebe` segue intocado, com as 6 chaves na
  mesma ordem e os mesmos valores (travado em teste); `PrecoDaPromocao` foi apenas **lido/chamado**.

**Prova — os quatro `git diff --stat` saem VAZIOS** (medido após cada tarefa e no fim):

```
$ git diff --stat -- database app/Models app/Services/Portal/Estrutura app/Support/Publicador
(vazio — 0 linhas)
```

**Nada foi tocado em `app/Services/Portal/Estrutura/`**: não há nada a levar para a coordenação com o
outro dev nesta tarefa.

## 3. O que foi feito

### Servidor (`simular()`), commit `7a6f752c`

- **`DadosEfetivosService::custosDoProduto()`** (novo, público): custo, origem e imposto da
  Precificação do Portal. A exceção de imposto da oferta vence o parâmetro da empresa — a **mesma
  precedência** que a visão rápida do lote usa.
- **`EditorRascunhoService::simular()`** passou a usar **`comEfetivosDe($e)`** (o mesmo caminho do
  `estado()`): é o único jeito de ele conhecer `preco_do_portal` e o `portal` por tipo. Acréscimo
  puro — antes ele só lia os preços da variante.
- A saída por tipo é o **spread das 6 chaves do `SimuladorVoceRecebe`** + `custo`, `custo_origem`,
  `imposto_pct`, `imposto_reservado`, `lucro`, `lucro_depois_do_imposto`, `margem_pct`,
  `abaixo_do_custo`, `prejuizo_com_imposto`, `preco_do_portal`, `portal_sem_frete`, `promocao`.
  As contas moram num privado `recebimentoVsCusto()` do próprio serviço.
- O custo é o da **variante que está sendo simulada** (pelo SKU dela, com fallback para o do produto):
  mostrar o custo da âncora ao lado do preço de outra cor seria número errado.

### Tela (o card), commit `ad86541b`

- Linhas novas no `<dl>` de cada tipo: Custo, Lucro antes do imposto, Imposto reservado (N%), Lucro
  depois do imposto e Margem estimada (com o `title` explicando a conta, sem jargão).
- Cinco avisos, cada um com `data-aviso-recebimento="<tipo>|<chave>"`: `portal_sem_frete`,
  `frete_conhecido`, `abaixo_do_custo`, `prejuizo_com_imposto` e `sem_custo`.
- **A nota fixa do conserto D**: "O Mercado Livre não desconta imposto: o que você recebe ainda tem
  imposto a pagar. O preço do Portal já reserva uma parte para isso — é a linha Imposto reservado."
- **Todo** número do servidor passa por `paraNumero`. Nenhuma conta de preço, margem ou promoção foi
  reimplementada em JS — a tela só formata.

### Acréscimo pedido pelo usuário em 10/10: o desconto automático de 14 dias

Pedido **durante a execução**, depois do plano. Todo anúncio publicado recebe sozinho um
PRICE_DISCOUNT de 14 dias que desce até o `minimo` do Portal, e o usuário não sabia disso — descobrir
depois de publicar é surpresa ruim.

O card passa a mostrar, numa linha (`data-promocao-do-card="<tipo>"`):

- **com desconto calculável:** "Desconto automático nos primeiros 14 dias: R$ 402,88 (−16,67%)." —
  no Clássico da Poltrona; R$ 434,10 (−16,67%) no Premium;
- **sem desconto:** o motivo em português. No caso **real** da Poltrona (oferta sem frete):
  "Sem desconto automático nos primeiros 14 dias: o preço do Portal foi calculado sem frete." Isso é
  informação valiosa, não ausência — explica o comportamento do anúncio.

**De onde vem o número e o que não foi alterado:** a conta é
`App\Support\Publicador\PrecoDaPromocao::calcular()`, a **mesma e única** função pura que o gatilho
pós-publicação e a renovação usam; os motivos em texto vêm do mapa `MOTIVOS` dela. **A lógica da
promoção NÃO foi alterada**: nem `PrecoDaPromocao.php` nem o espelho `promocaoAutomatica.js` foram
editados (só lidos/chamados — por isso o gate de `app/Support/Publicador/` segue vazio). Do JS, o
card reutiliza apenas os formatadores já exportados (`pct` e `DIAS_DA_PROMOCAO`). **Nenhuma terceira
cópia da conta foi escrita.**

## 4. Sigilo do custo

Custo é dado do cliente. Ele sai pela rota `simular` **já existente** (nenhuma rota nova), atrás da
mesma permissão de publicação que **já** mostra custo e margem na **visão rápida do lote**
(`LinhaDoLote.jsx`, "Custo" + `ROTULO_ORIGEM_CUSTO`) — mesma audiência (equipe), mesma permissão.
Nada vai para o portal do cliente nem para resposta pública. **A regra de sigilo da Fase 176 não foi
tocada**: ela é sobre não entregar ao CLIENTE o destino do cadastro, assunto diferente.

## 5. Decisões de desenho registradas no código

- **O custo saiu por método separado** (`custosDoProduto`), fora de `daProduto()`/`daOferta()`: aquele
  array é comparado **INTEIRO** (chaves, valores e ordem) contra o `Fila/EfetivosEmLote` pelo
  `VisaoRapidaDoLoteTest`. Chave nova ali obrigaria a espelhar no lote e quebraria a paridade até lá.
  Há teste provando que `daProduto()` continua com exatamente as 5 chaves de antes.
- **Existem duas contas de margem, de propósito.** O lote (`Fila/ResumoRapidoService::dinheiro`) usa
  **percentuais planejados**; o card do editor usa a **simulação REAL** (tarifa e frete que a API
  respondeu naquele clique). São perguntas diferentes — não foram unificadas, e a diferença está
  escrita no `title` da linha de margem e no docblock de `simular()`.

## 6. Desvios do plano

**1. [Regra 1 — expectativa de teste errada] `portal_sem_frete` com preço digitado**

Havia uma asserção extra, além do que o plano pedia, exigindo que `portal_sem_frete` ficasse `false`
quando a pessoa digita o preço. O teste falhou e a asserção estava errada, não o código:
`portal_sem_frete` é fato **da oferta** (é o mesmo fato que o `comEfetivos`, o `PrecoDaPromocao` e o
V-SAL-08 já leem), não do preço exibido. Se ele sumisse com o preço digitado, o card ficaria
incoerente — diria "sem desconto automático porque o preço do Portal foi calculado sem frete" **sem**
mostrar o aviso correspondente. A expectativa foi corrigida e o porquê ficou escrito no teste. Nenhum
valor de "actual" foi copiado para fazer o teste passar.

**2. [Regra 3 — desbloqueio] `CenarioCadeira` ganhou a expectativa de `custosDoProduto`**

O cenário compartilhado mocka o `DadosEfetivosService` no container. Como `simular()` passou a chamar
um método novo dele, o mock precisava conhecê-lo, senão qualquer teste futuro que chegasse ao
`simular` estouraria. Acréscimo puro (propriedade de custos + `shouldReceive`), sem alterar nenhuma
expectativa existente — os 1713 testes de antes seguem passando.

**3. [Acréscimo de escopo] A linha do desconto automático de 14 dias** — pedida pelo usuário durante a
execução (seção 3). Entra como requisito novo PTG-E.

## 7. Gates (antes x depois)

| Gate | Baseline | Depois |
|---|---|---|
| `phpunit tests/Unit/Publicador tests/Feature/Publicador` | 1713 / 0 falhando | **1728 / 0 falhando** (15 novos) |
| `npm run test:js` | 2125 pass / 1 fail | **2140 pass / 1 fail** (15 novos) |
| Única falha JS | `estrutura-grade-glide` — "Características secundárias nasce recolhido" | **a mesma, não corrigida** |
| `npm run build` | — | feito; manifest conferido **pelo JSON**: Publicador/Editor -> `assets/Editor-B_xwnfn1.js` |
| `git diff --stat -- database` | — | **VAZIO** |
| `git diff --stat -- app/Models` | — | **VAZIO** |
| `git diff --stat -- app/Services/Portal/Estrutura` | — | **VAZIO** |
| `git diff --stat -- app/Support/Publicador` | — | **VAZIO** |
| API real (ML / Gemini) nos testes | — | nenhuma: `Http::fake` em tudo |

`public/build/` é ignorado pelo git (`.gitignore:41`), então o build não entra em commit. Commits por
**pathspec** (`git add --` e `git commit -F ... --`), sem `git add -A`, sem `stash`, sem `reset`, sem
`amend`, sem `pint`. **Nenhum push, nenhum deploy, nenhum comando no VPS.** `ROADMAP.md` e
`state.advance-plan` não foram tocados.

## 8. O que ficou FORA

1. **PERGUNTA EM ABERTO para o usuário — o desconto automático de 14 dias desce até o ponto de
   equilíbrio.** Todo anúncio novo recebe a promoção automática, e ela baixa o preço até o `minimo`,
   que é **exatamente o break-even**. São **14 dias vendendo com lucro zero** — e com **prejuízo** se
   o frete estiver errado ou ausente. **A pergunta é se esse desconto deveria parar ACIMA do
   equilíbrio.** É regra da promoção automática (`PrecoDaPromocao` + `promocaoAutomatica.js`), não
   desta tarefa: mexer ali obriga a mexer nos dois lados juntos, com os mesmos números nos dois
   testes. Esta tarefa apenas passou a **mostrar** o número; não mudou a regra.
2. `PADROES`, fórmula, `acrescimo`, semântica `anunciado`/`minimo`, V-SAL-08, qualquer migration.
3. Unificar a margem do lote com a do card (são perguntas diferentes de propósito).
4. O onboarding antigo (`ImplementacaoPublica*`, `resources/js/lib/precificacaoProdutos.js`,
   `tests/Feature/Phase76`): tem a própria configuração e não lê `PADROES`.
5. Acervo, tela de Publicações e filtro de status (tarefa já commitada, `f2a6cf42`);
   `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx` (D-08).

## 9. O que o usuário precisa conferir no navegador

Nada aqui foi visto renderizado — a conferência é sua:

1. Abrir o editor do Publicador na **Poltrona Beny**, etapa Condições de venda, e clicar **Calcular**.
2. Conferir se o card mostra, por tipo: **Preço de venda, Tarifa, Frete, Você recebe, Custo, Lucro
   antes do imposto, Imposto reservado (19%), Lucro depois do imposto e Margem estimada**.
3. Conferir se o **Clássico** avisa recebimento abaixo do custo e se o **Premium** avisa prejuízo
   **depois** do imposto (os R$ 1,75 que pareciam sobrar).
4. Conferir se aparece a linha **"Sem desconto automático nos primeiros 14 dias: o preço do Portal foi
   calculado sem frete."** (é o que se espera dessa oferta hoje).
5. Conferir se aparece a **nota do imposto** no pé do card.
6. **Cadastrar o frete** dessa oferta na Precificação do Portal, voltar e clicar Calcular de novo: o
   preço sobe, os avisos devem mudar e a linha do desconto automático deve passar a mostrar o preço e
   o percentual.
7. Decidir a pergunta em aberto do item 8.1.

## 10. Como os gates foram medidos (armadilha)

Com tudo commitado, `git diff --stat -- <dir>` sai vazio **por construção** — ele compara a árvore de
trabalho, não o que mudou. O gate honesto é contra o commit de partida (`f2a6cf42`, o último antes
desta tarefa):

```
$ git diff --stat f2a6cf42..HEAD -- database app/Models app/Services/Portal/Estrutura app/Support/Publicador
(vazio — 0 linhas)

$ git diff --name-only f2a6cf42..HEAD
.planning/quick/261010-ptg-.../PLAN.md
.planning/quick/261010-ptg-.../SUMMARY.md
app/Services/Publicador/DadosEfetivosService.php
app/Services/Publicador/EditorRascunhoService.php
resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx
tests/Feature/Publicador/Concerns/CenarioCadeira.php
tests/Feature/Publicador/RecebimentoAbaixoDoCustoTest.php
tests/js/publicador-quanto-recebo.test.js
```

Oito arquivos, e nenhum deles em `database/`, `app/Models/`, `app/Services/Portal/Estrutura/` ou
`app/Support/Publicador/`. É esta a prova de que o preço, os `PADROES` e a fórmula não se moveram.
