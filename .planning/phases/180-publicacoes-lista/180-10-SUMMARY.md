---
phase: 180-publicacoes-lista
plan: 10
subsystem: Publicador — tela de Publicações
tags: [publicador, publicacoes, composicao-de-pagina, cirurgia-de-gate, list-25, criterio-13, render-adverso]
requires:
  - 180-04 (as props do servidor: `abas`, `contagens`, `grupos`, `rodape`, `sincronizacao`, `faixaSucesso`, os 7 params novos)
  - 180-05 (CabecalhoDePublicacoes, FaixaDeSucesso)
  - 180-06 (BuscaDePublicacoes, AbasDeStatus, LinhaDeControles)
  - 180-07 (MenuDeFiltro, ChipsDeFiltro, ChipsDeTriagem)
  - 180-08 (CabecalhoDaTabela, LinhaDePublicacao, CelulasDaLinha)
  - 180-09 (LinhaDeGrupoDeProduto, RodapeDaTabela, VaziosDaLista)
provides:
  - "a tela de Publicações COMPOSTA: os 14 componentes de `publicacoes/` montados na página, no container do protótipo"
  - "`grupoAberto()` — a regra de LIST-09 como função pura exportada e testada"
  - "os 3 gates antigos REAPONTADOS com veto ao valor antigo (vocabulário, mount do No ar|Histórico, Publicados|Rascunhos)"
  - "o bloco §6: o critério 13 item por item + render adverso da página COMPLETA (19 props × 9 formatos)"
affects:
  - 180-11 (atalhos de teclado e estado na URL: `onTecla`, `refDoInput`, `comFoco`, `sel`)
  - 181 (painel de inspeção de 460px: a Nota ECF, a saúde do ML, o buybox e a barra de ação em massa)
  - 183 (a fase do Histórico: a rota ficou ÓRFÃ de propósito)
tech-stack:
  added: []
  patterns:
    - "composição: a página orquestra, os componentes desenham — nenhuma regra de negócio duplicada"
    - "round-trip ao servidor em TODA navegação (D-05), com a busca por debounce de 400 ms"
    - "regra de UI com duas entradas vira FUNÇÃO PURA exportada quando o render estático não a observa"
key-files:
  created:
    - resources/js/Pages/Mlb/components/BotaoAtualizarAcervo.jsx
  modified:
    - resources/js/Pages/Mlb/MeusAnuncios.jsx
    - tests/js/estrutura-meus-anuncios.test.js
    - tests/js/publicador-paginas-anuncios-render.test.js
    - tests/js/publicador-vocabulario-novo.test.js
decisions:
  - "o segmentado No ar|Histórico SAIU da tela (LIST-25, decisão informada do usuário de 13/10/2026) e a rota ficou ÓRFÃ de propósito"
  - "CelulaSku e VazioDaListagem: decisão (a) — ficam exportados; o segundo virou ADAPTADOR de VaziosDaLista, o primeiro é ÓRFÃO DECLARADO"
  - "a precedência de `com_erro` × status NÃO foi decidida: é decisão de UI e a implementação exige editar `publicacoes/`, território do 180-08 — levada ao checkpoint"
  - "o BotaoAtualizar mudou de ARQUIVO sem mudar uma classe: movimento, não redesenho"
  - "a regra de LIST-09 virou `grupoAberto()` porque a mutação no JSX SOBREVIVEU — no render de servidor ela não era observável"
metrics:
  duration: ~4h30
  completed: 2026-10-14
---

# Fase 180 Plano 10: a composição da página de Publicações + a cirurgia nos gates — Summary

A tela de Publicações passou a ser o layout novo: os 14 componentes de `publicacoes/` montados em
`MeusAnuncios.jsx`, no container do protótipo, com as **oito** coisas do critério 13 preservadas uma
por uma e **uma** removida por decisão informada do usuário. Os três gates antigos foram
**reapontados com veto explícito ao valor antigo** — nenhuma asserção removida, `+assert > -assert`
nos três arquivos — e a página ganhou render adverso completo (19 props × 9 formatos) e 16 mutações.

**Commits:** `a68be042` (a página) · `22670c9f` (a cirurgia) · `0c68368e` (§6 + mutações) · este
(SUMMARY + STATE).

---

## (1) ⚠️⚠️ A CONFERÊNCIA DO LIST-25, DITA COM NÚMEROS

A guarda do LIST-25 é *"o atalho sai, o DADO não"*. A medição foi feita no planejamento e
**reconferida aqui por `grep -n`** — e as linhas do plano estavam deslocadas em ~36 (a armadilha §1
do learnings, de novo):

| | `meus()` (esta tela) | `historico()` |
|---|---|---|
| função | `MlbAnuncioController.php` | **`:323`-`:478`** (o plano dizia `:287`-`:441`) |
| tabela | `ml_acervo_itens` | `pub_publicacao_itens` ⨝ `pub_publicacoes` ⨝ `pub_rascunhos` ⨝ `pub_produtos` (**`:342`-`:344`**) |
| unidade | o anúncio | o **LOTE** (`categoria + dia de conclusão`), reconstruído em PHP em **`:408`-`:418`** — **não existe coluna de lote** |
| busca | `title` / `ml_item_id` / `skus` | `payload->family_name` / `pub_produtos.sku` (**`:359`-`:360`**) |
| exclusivos | — | o **payload ENVIADO** ao ML (`family_name` **`:429`**, `price` **`:430`**), a foto de `pub_imagens.ml_url` (**`:385`-`:404`**), `sku_origem` por `PubProduto::skuExibido()` (**`:432`**), `pode_duplicar` (**`:443`**), `total_lotes` (**`:474`**) |

➡️ **São conjuntos DIFERENTES, não recortes um do outro.** O filtro de status desta tela não alcança
nada da coluna da direita.

### A metade SEGURA do LIST-25 (o atalho mudou de lugar, o dado ficou)

O segmentado separado **"Publicados | Rascunhos"** saiu, e "Rascunhos" é a 6ª opção do trilho de
status. **O dado não saiu, e isto é verificável no servidor:** `'rascunhos' => $totalDeRascunhos`
aparece **DUAS** vezes em `MlbAnuncioController.php` — uma em `subTotais` (a prop que a tela de hoje
lia) e uma em `contagens` (a que o trilho lê). ⚠️ **É literalmente a mesma variável**, e há asserção
cobrando que continue sendo (se uma das duas passar a contar por conta própria, os números divergem).
`sub` segue na URL, `trocarSubAba` segue indo ao servidor, a query do servidor não mudou nada.

### A metade AUTORIZADA (a exceção ao critério 13)

⚠️ **"No ar | Histórico" SAIU**, e a rota ficou **ÓRFÃ de propósito**. Decisão informada do usuário
em **13/10/2026**, entre **três opções**, com o aviso literal de que *"a rota fica órfã, sem nenhum
link vivo para ela"* — ele escolheu **"sai agora, Histórico vira fase própria"**. É a **segunda**
exceção explícita dele à regra-mestra "melhorar sem regredir" nesta milestone (a primeira foram os
dois blocos da Visão geral, 12/10/2026).

**O que NÃO saiu, provado por diff vazio no range da FASE (`f23f610d..HEAD`), com `test -e` ANTES:**

| alvo | `test -e` | arquivos no diff |
|---|---|---|
| `routes/mlb_anuncios.php` (a rota, `:430`) | existe | **0** |
| `resources/js/Pages/Mlb/AnunciosHistorico.jsx` | existe | **0** |
| `resources/js/Pages/Mlb/anuncioHistoricoUtils.js` | existe | **0** |
| `resources/js/Components/Mlb/Publicador/SubAbasDePublicacoes.jsx` | existe | **0** |
| `tests/Feature/Phase86/HistoricoAnunciosTest.php` | existe | **0** |
| `tests/js/estrutura-anuncios-historico.test.js` | existe | **0** |

E a prova **SEMÂNTICA** do controller (ele é editado pelo plano 180-04, então diff vazio ali seria
mentira): `public function historico(` ×1, os 4 joins presentes, `$chaveLote` ×4,
`'pode_duplicar' => false` ×1, `payload->family_name` ×1 — tudo com asserção no gate, não só no log.

As **três frases anti-reversão** estão no comentário de topo de `MeusAnuncios.jsx`, com a tabela de
`arquivo:linha` do que só o Histórico alcança.

---

## (2) ⚠️ A TABELA DA CIRURGIA — par `-`/`+` por par

```
tests/js/estrutura-meus-anuncios.test.js              -assert: 2   +assert: 46   (+411 / -12 linhas)
tests/js/publicador-paginas-anuncios-render.test.js   -assert: 7   +assert: 23   (+963 / -21 linhas)
tests/js/publicador-vocabulario-novo.test.js          -assert: 0   +assert: 3    (+40  / -0  linhas)
```
`test -e` nos três ANTES de acreditar em qualquer número; range **`f23f610d..HEAD`** (nunca `--stat`
pós-commit, que compara a árvore e sai vazio sempre). **`+assert > -assert` nos três.**

| # | linha `-` removida | onde foi | teste em que o par vive |
|---|---|---|---|
| 1 | `assert.match(fonte, /de 86/)` | **reaponte de ARQUIVO**: `match(fonteCelulas, /de 86/)` + `match(/Nota ECF \${nota} de 86/)` + 4 negativas de endurecimento | `D-22: a nota aparece como "de 86"…` |
| 2 | `assert.match(fonte, /<SubAbasDePublicacoes atual="meus" …/)` | **negativa com veto** (`doesNotMatch(/<SubAbasDePublicacoes/)` + `doesNotMatch(/SubAbasDePublicacoes/)`) + **3 asserções NOVAS** (a rota, o controller, a página do Histórico) | `180: "No ar | Histórico" NÃO está mais em MeusAnuncios…` |
| 3 | `assert.equal((html.match(/<header/g)…).length, 1, 'esperado exatamente UM…')` | **valor POR PÁGINA** (`headers: 2` em MeusAnuncios, `1` nas outras duas) + asserção nova de que o PRIMEIRO header é o global | `${nome} — o header é o PRIMEIRO filho do AppLayout…` |
| 4 | `assert.match(html, /<\/header><div class="mx-auto max-w-\[1600px\] px-4 py-6 sm:px-6 lg:px-8/)` | **literal POR PÁGINA** (`container`), como o plano 179-09 fez na `VisaoGeral.jsx` | idem |
| 5 | `assert.equal(…, 1, 'o <header> sumiu com cabecalho ${rotulo}')` | **valor POR PÁGINA** | `${nome} — sobrevive a cabecalho completo, NULO, AUSENTE…` |
| 6 | `assert.match(corpo, /Publicados/)` | **negativa com veto** (`doesNotMatch(/>Publicados</)`): o rótulo deixou de ser controle; o DADO ficou e é provado na fonte | `180: "Rascunhos" continua no corpo COM contador…` |
| 7 | `assert.match(corpo, /Rascunhos/)` | **mantida e ENDURECIDA**: agora recortada à OPÇÃO do trilho (`role="tab"`), não ao HTML inteiro | idem |
| 8 | `assert.match(corpo, /\(3\)/)` | **VETADA** (`doesNotMatch(/\(3\)/)`): a forma com parênteses é a do controle que saiu | idem |
| 9 | `assert.match(corpo, /\(1\)/)` | **VETADA** + o contador reaparece na forma nova (`<span>1</span>` dentro da opção) | idem |

### ⚠️⚠️ E O FURO DO PRÓPRIO GATE DE REAPONTE, declarado

O gate `grep '^-.*assert'` **É CEGO para teste instalado por CHAMADA DE FÁBRICA.** A linha removida
`travaVocabularioVisual('resources/js/Pages/Mlb/MeusAnuncios.jsx')` **não contém a palavra
`assert`** — e ela instalava **SEIS testes** (18 asserções). O mesmo valeu para a saída de
`MeusAnuncios` do array `COM_SUBABAS`: **4 sub-testes** deixaram de rodar para aquela página e a
linha removida era só a entrada do array.

➡️ **Os dois pares estão enumerados aqui porque o `-assert` não os mostraria:**

| chamada/entrada removida | o que ela instalava | para onde foi |
|---|---|---|
| `travaVocabularioVisual('…/MeusAnuncios.jsx')` | 6 testes de aparência (18 asserções) | o teste nomeado `180: MeusAnuncios.jsx saiu do vocabulário ANTIGO`, com as **6** regras e o veto escrito em cada uma, **mais** os 6 sub-testes do gate NOVO (`ARQUIVOS_NOVOS`), que é mais duro |
| `{ nome: 'MeusAnuncios', … }` em `COM_SUBABAS` | 4 sub-testes de radiogroup | o teste nominal `180: MeusAnuncios NÃO tem mais o segmentado…` (3 sub-testes, 7 asserções), com as props **CHEIAS** |

⚠️ **Quem repetir este padrão no futuro: conte TESTES, não linhas de `assert`.** A contagem da suíte
é a prova real — e ela subiu (ver (8)).

### As 6 asserções de aparência, uma por uma

| # | asserção de hoje | diagnóstico | o que ficou |
|---|---|---|---|
| 1 | `doesNotMatch(/\btext-xs\b/)` | compatível | **mantida**, com a mensagem dizendo que segue proibida no novo |
| 2 | `doesNotMatch(/text-\[1[023]px\]/)` | **CONTRADIZ** (o protótipo usa 10/12/13px) | **VETO INVERTIDO: a MESMA regex, com `match`** — e ela passa (`text-[12px]` na paginação) |
| 3 | `match(/text-\[11px\]/)` + `match(/text-sm\b/)` + `match(/text-base\b/)` | **CONTRADIZ** (FID-03 proíbe a escala nomeada) | `text-sm`/`text-base` **VETADAS** + a escala nomeada INTEIRA fora; e o `text-[11px]` **continua cobrado** (segue no arquivo, em `CelulaSku`) |
| 4 | `doesNotMatch(/font-medium/)` | **CONTRADIZ** (o protótipo declara 500 em dezenas de lugares) | a proibição **MORREU**; a regra que a substitui é a dos 4 pesos (`doesNotMatch(/font-(thin|extralight|light|extrabold|black)/)`) |
| 5 | `doesNotMatch(/font-bold/)` | compatível | **mantida** |
| 6 | `doesNotMatch(/…-\d+\.5\b/)` (meio-passo) | compatível | **mantida** (`text-[13.5px]` não casa com ela, de propósito) |

⚠️ **DESVIO DECLARADO no item 4** (e no 3, em parte): a prescrição mandava tornar `font-medium`,
`text-[13.5px]` e `text-[15px]` **obrigatórios na página**. **Recusado, com medição:** a página
COMPÕE componentes e não escreve nenhuma dessas três em classe própria — a asserção reprovaria o
código CERTO. As três são cobradas onde moram (os 14 componentes, via `GATE-03`). O que entrou aqui
é a regra verificável que as substitui.

### E a migração não pode ser revogada em silêncio

`ARQUIVOS_NOVOS` é uma CONSTANTE e o `deepEqual` de `DIRETORIOS_FECHADOS` **não** cobre
`Pages/Mlb/`. Sem guarda, tirar a página da lista faria os **dois** gates passarem (o antigo porque a
chamada saiu, o novo porque a constante não cobra aquela pasta). ➡️ Por isso há, em
`estrutura-meus-anuncios.test.js`, a asserção de que
`tests/js/publicador-vocabulario-novo.test.js` **contém** o caminho da página.

---

## (3) ⚠️ OS NOMES PARA `ARQUIVOS_NOVOS` — e a contagem, elevada do jeito HONESTO

```
resources/js/Pages/Mlb/MeusAnuncios.jsx
```

⚠️ **A contagem de `publicacoes/` CONTINUA 14, e isso é correto:** este plano **não cria componente
nenhum** naquele diretório (gate de território: diff **vazio** em `publicacoes/` no range
`95d9b779..HEAD`). Elevar o 14 seria mentir sobre a pasta.

➡️ **O que cresceu é a lista da FASE**, e há sub-teste NOVO para isso:
`assert.equal(ARQUIVOS_NOVOS.filter(publicacoes/ || a página).length, 15)` — *"a Fase 180 contribui
**15** arquivos: os 14 componentes mais a página que os compõe"*. Sem ele, acrescentar (ou remover) o
caminho da página não mexeria em número nenhum.

O gate de vocabulário foi de **331 → 338** testes (os 6 sub-testes da página + este).

---

## (4) A DECISÃO SOBRE `CelulaSku` E `VazioDaListagem` — (a), com uma perda NOMEADA

**Decisão (a) do plano, executada:** os dois continuam **named exports** de `MeusAnuncios.jsx`, e
`publicador-meus-anuncios-sku-render.test.js` segue **verde sem nenhuma cirurgia** (12 casos, 0
reapontes — rodado e conferido).

| export | o que virou | consequência |
|---|---|---|
| `VazioDaListagem` | **ADAPTADOR** de `publicacoes/VaziosDaLista`: resolve `caso` a partir do termo (`'' → sem_anuncios`, senão `sem_resultado`) e repassa `busca`/`skuNaoColetado`/`acao` | os 7 casos daquele gate passam pelos textos que agora moram numa casa só (plano 180-09). **Zero duplicação de literal** |
| `CelulaSku` | **ÓRFÃO DECLARADO** (o padrão do `PainelVisaoGeral.jsx` na Fase 179): o export fica, o componente não é montado | ⚠️ **PERDA NOMEADA: o SKU por VARIAÇÃO saiu da linha.** A célula Publicação do layout novo mostra título + MLB + nome do produto, e acrescentar o SKU lá exige editar `publicacoes/CelulasDaLinha.jsx` (território do 180-08). Na visão "Por produto" o **SKU DO PRODUTO** continua na linha de grupo (`produto_sku`), e a **busca por SKU continua** (LIST-21). ➡️ **Pergunta levada ao checkpoint.** |

---

## (5) AS 10 PENDÊNCIAS NOMINAIS — uma por uma

| # | pendência | o que foi feito |
|---|---|---|
| 1 | elevar `ARQUIVOS_NOVOS` | feito do jeito honesto (ver (3)): `publicacoes/` segue 14, a FASE vai a 15, com sub-teste novo |
| 2 | `naoAvaliado` em camelCase | ligação **EXPLÍCITA** (`naoAvaliado={tri.nao_avaliado}`), com asserção de fonte **e** de render (o selo com o número 3 na tela). **Mutação x** prova que o gate pega o snake |
| 3 | `algumAtivo` derivado | derivado na página, com `!= null` (e **não** `!== null`: prop AUSENTE chega `undefined` e daria "filtro ligado" sem filtro). ⚠️ **Declarado como DÍVIDA:** a regra certa é o servidor mandar `filtros_ativos`, derivado do mesmo saneamento de `:596`-`:636` |
| 4 | `responsavel.opcoes` sem fonte | `opcoes: []` → o chip **não é desenhado**, e há asserção disso **mais** a asserção de que os outros 5 chips continuam (senão a negativa seria "nenhum chip existe"). Caminho nomeado: `PainelVisaoGeralService::resolverAtor()` |
| 5 | `rotulosDeMotivo`, `hoje`, `urlDoRascunho` | as três ligadas, com asserção de fonte **e** de render. **Mutações x e xi** provam que o gate pega. O `setTimeout(…, 1300)` é da PÁGINA. ⚠️ `nova` **não** é passada como prop — sai do item |
| 6 | `com_erro` × status | ⚠️ **NÃO DECIDIDO — levado ao checkpoint.** Ver (6) |
| 7 | "N produtos" | `resumo={null}` na busca (a fonte não existe), e `rodape.produtos` **existe** e é passado como veio. **Nada contado no cliente** (D-05) |
| 8 | a busca força os grupos abertos | regra na página — e ela virou **função pura exportada** porque a mutação no JSX SOBREVIVEU (ver (7)). `caso: 'nunca_coletado'` substitui a lista inteira; os outros dois vão DENTRO do card, antes do rodapé; `acao` nunca falta quando `skuNaoColetado` é verdadeiro |
| 9 | a barra flutuante de ação em massa | **fora do escopo** — é a Fase 181 (seleção em lote). Nenhum `pub-bar-float` foi criado. ⚠️ **Mas o CHECKBOX da linha foi ligado** (estado efêmero na página): ele marca, pinta a linha (LIST-15) e o cabeçalho tem "marcar todas". Sem barra, a marcação ainda não leva a ação nenhuma — **declarado, e é pergunta do checkpoint** |
| 10 | `preco` e `nova` | nada a fazer: saem do item e o componente já os lê. Conferido no render (`R$ 309,90` na tela, `—` na linha sem preço, nunca `R$ 0,00`) |

---

## (6) ⚠️⚠️ A DECISÃO QUE NÃO TOMEI: `com_erro` × precedência do status

`com_erro` **chega no item** (plano 180-04B), mas `STATUS_DA_PUBLICACAO`
(`publicacoes/CelulasDaLinha.jsx:388`) tem **quatro** entradas e não o conhece. Duas razões para
**parar e perguntar** em vez de decidir:

1. **É decisão de UI, não de código:** `com_erro` é **EIXO**, não status — um anúncio pode estar
   "Em revisão" **E** "Com erro" ao mesmo tempo (`AcervoTriagemService:275`-`:278`). Dizer que "Com
   erro" GANHA da precedência do status muda o que a linha afirma sobre o anúncio.
2. **A implementação não é minha:** o mapa vive em `publicacoes/CelulasDaLinha.jsx`, território do
   plano 180-08, e o gate de território deste plano prova diff **vazio** em `publicacoes/`.

➡️ **Está no checkpoint, com as duas opções e o custo de cada uma.** Nada foi derivado de
`severidade` (seria inferir: um anúncio moderado pode ter `severidade = 0`), e o recorte "Com erro"
continua **na aba** (o servidor filtra por `sub_status`) e **nos chips**.

---

## (7) ⚠️ A MUTAÇÃO QUE SOBREVIVEU — e virou lacuna REAL de cobertura

A mutação **vii** (`const aberto = buscaAtiva` → `!buscaAtiva`) **SOBREVIVEU**. Investigada em vez de
presumida equivalente, como o learnings §6.3 manda:

➡️ **No render de servidor `colapsados` é SEMPRE `[]`** (estado inicial), então
`!colapsados.includes(x)` é sempre verdadeiro e o `||` devolve `true` de qualquer jeito. **A regra de
LIST-09 não era observável em render nenhum** — nem com busca, nem sem.

**O conserto:** a regra virou a função pura **`export function grupoAberto(buscaAtiva, colapsados,
chave)`**, com 7 casos (as 4 combinações + `colapsados` nulo + grupo sem chave + `'sim'` em vez de
`true`). A mutação foi refeita **na regra** e agora reprova; a mutação **xvi** (sempre `true`)
também.

⚠️ **Isto é a terceira vez na milestone em que a sobrevivente era lacuna real.** O padrão vale como
receita: **regra de UI com duas entradas que o render estático não distingue → extraia para função
pura e teste as combinações.**

---

## (8) OS DESVIOS DECLARADOS (e por que cada um)

| # | prescrição | o que fiz | por quê |
|---|---|---|---|
| 1 | *"`:86` (D-22) fica sem um caractere de mudança"* | **reaponte de ARQUIVO** (a positiva passou a cobrar `CelulasDaLinha.jsx`), com as duas negativas da página **intactas** e 4 negativas novas | o literal "de 86" **mudou de arquivo** por desenho do próprio plano 180-08 (LIST-24 tirou a coluna do grid). Manter `match(fonte, /de 86/)` reprovaria o código certo; apagar perderia o critério 15. ⚠️ E a prova ficou **MAIS FORTE**: além da fonte, o render da página mostra `Nota ECF 71 de 86` |
| 2 | *"§1-3 do gate de render: não tocar"* | reapontei 3 asserções de §1/§2 (contagem de `<header>` e o literal do container) | **medido:** `CabecalhoDePublicacoes` monta um `<header>` semântico (é o protótipo, `:31`), então são 2; e o container do corpo mudou para o do protótipo. Sem o valor POR PÁGINA, o laço reprovava o código certo — e trocar o esperado para 2 nas três deixaria as outras duas perderem o header em silêncio |
| 3 | *"caso 1 do §6: 'No ar'/'Histórico' presentes"* | asserção de **AUSÊNCIA**, em teste próprio | a prescrição é anterior à decisão do usuário de 13/10 e é hoje o OPOSTO do exigido |
| 4 | *"item 4 da cirurgia: `font-medium` obrigatório"* | a proibição morreu, e a regra dos 4 pesos entrou no lugar | a página não escreve o peso 500 em classe própria (compõe componentes): a exigência reprovaria o código certo |
| 5 | *"passe `resumo={null}`" (faixa de sucesso)* | `resumo={faixaDispensada ? null : (faixaSucesso ?? null)}` | o valor REAL é `null` (medido pelo 180-04) e nada é fabricado — mas a LIGAÇÃO fica real, e há caso provando que com resumo do servidor a faixa aparece. ⚠️ `resumo={null}` hardcoded passaria o gate de ausência **sem o componente estar ligado** (mutação xiv) |
| 6 | *"`decodificarRotuloPaginacao()` fica sem mudança"* | endurecida: `String(label ?? '')` → guarda de tipo | `String({toString: null})` **LANÇA**, e é um dos casos do render adverso. O comportamento nos 3 rótulos reais é idêntico |
| 7 | *"não redesenhar o `BotaoAtualizar`"* | **obedecido** — mas ele mudou de ARQUIVO | a página entrou no gate de vocabulário novo, que proíbe `text-sm` e `ecf-*`; o botão é vocabulário antigo de propósito (slot do header, Fase 177). Há gate cobrando as **3 strings de `className` byte a byte** — movimento, não redesenho |
| 8 | o `<form>` da busca com Enter | **debounce de 400 ms** no round-trip | o novo `BuscaDePublicacoes` não tem form, e LIST-18 dá outro papel ao Enter (ir ao 1º resultado, plano 180-11). Sem o debounce a busca por SKU/nome/MLB **pararia de funcionar** — era o que o usuário acabou de ganhar. D-05 intacto: quem filtra é o servidor |

### O que NÃO mudou de propósito

- `AppLayout title="Meus Anúncios"` — nenhum requisito pede o título do documento, e mudá-lo exigia
  reapontar mais um valor do gate. O H1 da tela diz **"Publicações"** (LIST-01). Declarado.
- `rotacaoN` virou **prop ÓRFÃ DECLARADA** (rotulava o selo das colunas Visitas/Saúde do ML, que
  saíram do grid por LIST-24). Continua chegando e declarada, como `produtosPorFase` na Fase 179.
- `subTotais` também: o contador do trilho vem de `abas[].total`/`contagens.rascunhos`, que é a
  **mesma** variável do servidor.

---

## (9) O QUE OS GATES **NÃO** PROVAM — escrito em vez de maquiado

1. ⚠️ **O timer de 1,3 s do selo "copiado"** e o **`preserveState: true`** do round-trip são
   provados pelo **LITERAL na fonte**, não pelo runtime: `renderToStaticMarkup` não executa efeito
   nem timer. O comportamento (o check verde que volta sozinho; o scroll que não salta) é da
   **validação visual com o usuário**.
2. ⚠️ **O debounce de 400 ms da busca** — idem: o literal está gateado, o comportamento não.
3. ⚠️ **A eliminação de variável pelo Rollup** (`ReferenceError` só no bundle de produção) **não é
   reproduzível** em `renderToStaticMarkup`. A proteção é **estrutural** (toda flag nasce DENTRO do
   callback) e está declarada, não provada.
4. ⚠️ **A marcação de linha (checkbox) não leva a ação nenhuma** nesta fase: a barra flutuante é a
   181. O gate prova que marcar pinta a linha; não prova utilidade.
5. ⚠️ **Nenhum executor viu a tela renderizada em produção.** O banco local **não tem empresa com
   `ml_token`** (`meus()` faz `abort_unless($company->mlToken !== null, 404)`), `ml_acervo_itens`
   está **vazia** e a leitura de produção está **bloqueada** nesta sessão. Todo render deste plano é
   `esbuild` + `react-dom/server` com fixture sintético.
6. ⚠️ **O veto de FORMA do segmentado antigo** (`{tab.label}`, `({total})`) só pega a restauração
   verbatim. Quem prova a ausência é o **render** (§5: `>Publicados<` ausente do corpo).

---

## (10) "O QUE PRECISARIA ESTAR ERRADO NO CÓDIGO PARA ESTE GATE FALHAR?" — por teste novo

| teste | o que precisaria estar errado |
|---|---|
| `180: MeusAnuncios.jsx saiu do vocabulário ANTIGO` | a página voltar a usar `text-sm`/`text-base`/`text-xs`, perder a escala em px, ganhar peso fora dos 4 ou meio-passo de utilitário |
| `180: a migração para o gate NOVO é REAL` | alguém tirar a página de `ARQUIVOS_NOVOS` (ela ficaria sem conferência nos dois gates) |
| `180: o "Atualizar agora" mudou de ARQUIVO sem mudar uma classe` | qualquer utilitário acrescentado/removido/alterado nas 3 strings; o rótulo mudar (a frase do aviso de SKU o cita); ou a página recriar o botão em vez de importá-lo |
| `180: D-11 endurecido` | a tela ganhar `useForm`, `axios`, `fetch`, `<form>` ou `router.visit` — portas de escrita que `router\.put\(` não pega |
| `D-22` (reapontado) | o literal "de 86" sair do dono novo, a frase mudar de forma, aparecer "de 100", ou a página voltar a desenhar a nota por conta própria |
| `180: "No ar \| Histórico" NÃO está mais em MeusAnuncios` | o mount (ou o import) voltar; **ou** a rota/o controller/a página do Histórico serem apagados |
| `180: o que SÓ o Histórico alcança continua no servidor` | `historico()` perder a assinatura, um dos 4 joins, o `$chaveLote`, o `payload->family_name` ou o `pode_duplicar` |
| `180: Publicados \| Rascunhos` | `SUBABAS`/`trocarSubAba` saírem; a troca parar de ir ao servidor; a whitelist abrir; o trilho não marcar "Rascunhos"; ou o servidor passar a contar rascunho em dois lugares |
| `180: critério 13` (8 casos) | cada item: o banner sumir/ficar vermelho/esconder a lista; os vazios sumirem; o aviso de SKU perder a frase ou o botão; Rascunhos não trocar a lista; a paginação sumir ou vazar entidade HTML; a tela dizer "Mais recentes primeiro"; a nota virar "de 100"; um verbo de escrita entrar |
| `180: decisões de honestidade (EXMP-01)` | a faixa aparecer com dado inventado **ou** deixar de estar ligada; o chip de Responsável aparecer com `opcoes` vazias (ou os outros 5 sumirem); Preço virar `R$ 0,00`; a visão Lista desenhar grupo; a tela esconder linha que o rodapé conta |
| `⚠️ a regra de LIST-09 nas QUATRO combinações` | a busca deixar de forçar aberto; o recolhido deixar de fechar; `colapsados` nulo fechar tudo; truthiness no lugar de `=== true` |
| `180: o vocabulário do corpo e as travas` | o `Select` do shadcn voltar; `dangerouslySetInnerHTML` entrar; a tabela antiga voltar; um dos 12 imports virar cópia; o container perder uma das 9 medidas; uma das 3 ligações silenciosas quebrar |
| `180: RENDER ADVERSO` (19 props × 9 formatos + 30 internos) | qualquer `String(valor)`, `obj[chaveDeFora]`, `Object.fromEntries` com chave de fora, `new Date()` ou leitura de campo sem guarda em QUALQUER ponto da composição |
| `180: as medidas GERAM CSS com o valor certo` | classe presente com valor errado; o alfa do âmbar mudar; a página sair do manifest (ela SOME da aplicação) |

---

## (11) AS 16 MUTAÇÕES — 16/16 PEGAS, 1 sobrevivente investigada, 1 recusada e refeita

Contagem travada antes de aplicar (recusa se ≠ 1), **linha conferida**, ANSI removido antes de
filtrar, **contagem de testes comparada** (109 no gate de render, 29 no de fonte), restauração por
cópia pristina com **sha256**.

| # | mutação | reprovou em |
|---|---|---|
| i | **restaura** o mount do `<SubAbasDePublicacoes>` | `180: "No ar \| Histórico" NÃO está mais…` |
| ii | remove o banner de defasagem | caso 1 do critério 13 |
| iii | banner com `pub-danger` em vez de `pub-warn` | caso 1 (tom âmbar) |
| iv | para de passar `skuNaoColetado` ao vazio da lista | caso 3 (aviso de SKU) |
| v | `de 86` → `de 100` em `CelulasDaLinha` | caso 7 (D-22) |
| vi | acrescenta um `router.patch(` | `D-11: nenhum router.put/delete/patch` |
| vii | inverte a regra da busca em `grupoAberto()` | `a regra de LIST-09 nas QUATRO combinações` |
| viii | timer 1300 → 800 | o teste do literal (não do runtime — declarado) |
| ix | `preserveState: true` → `false` | idem |
| x | `naoAvaliado` → `nao_avaliado` | `as três ligações que quebrariam em silêncio` |
| xi | `urlDoRascunho` nunca montada | idem |
| xii | o trilho volta a marcar o status com a fonte Rascunhos | `com sub=rascunhos é "Rascunhos" que fica marcada` |
| xiii | remove o caminho plano de reserva | `a tela nunca esconde linha que o rodapé conta` |
| xiv | `resumo={null}` hardcoded | `a LIGAÇÃO é real: com resumo do servidor a faixa aparece` |
| xv | remove o `min-w-[1240px]` | `o container do corpo tem as medidas do protótipo` |
| xvi | `grupoAberto` sempre `true` | `a regra de LIST-09 nas QUATRO combinações` |

### Notas honestas da campanha

- ⚠️ **A mutação iv foi RECUSADA na 1ª rodada** (2 ocorrências de `skuNaoColetado={skuNaoColetado}`:
  a do card e a do adaptador `VazioDaListagem`) e refeita com a **indentação** que a torna única.
  Exatamente o que o learnings §6.1 manda: travar a contagem e conferir a linha.
- ⚠️⚠️ **O GUARD DA CONTAGEM DE TESTES ESTAVA CEGO na 1ª rodada**: o reporter prefixa com `ℹ`
  (UTF-8 de 3 bytes) e meu `grep -E '^. tests [0-9]+$'` **não casava** — as 15 mutações saíram com
  `TESTS=0` e a campanha "parecia" ter medido a contagem. É o item 11 do learnings §4 com outra
  roupa. Corrigido (âncora na palavra, não na coluna) e a campanha **inteira refeita**: 109/29 em
  todas.
- ⚠️ **As mutações v e iv tocaram arquivo de OUTRO plano** (`CelulasDaLinha.jsx`) de forma
  TEMPORÁRIA: sem isso não há como provar que o gate do "de 86" tem dentes. Restaurada por cópia
  pristina, `sha256` idêntico (`510a2c5e…`) e `git diff --numstat` **vazio** no arquivo.

---

## (12) VERIFICAÇÃO

| gate | resultado |
|---|---|
| `node --test` nos 4 gates js tocados | **verdes** (`estrutura-meus-anuncios` 29 · `publicador-paginas-anuncios-render` 114 · `publicador-vocabulario-novo` 338 · `publicador-meus-anuncios-sku-render` 12, **sem nenhum reaponte**) |
| `npm run test:js` ANTES | **4325 testes / 4324 pass / 1 fail** |
| `npm run test:js` DEPOIS | **4392 / 4391 / 1 fail** — a falha é a **pré-existente** `tests/js/estrutura-grade-glide.test.js:122` ("Características secundárias nasce recolhido"), **não corrigida** de propósito |
| a aritmética do delta | **+67** = +61 no gate de render (53→114) −1 no de fonte (30→29) +7 no de vocabulário (331→338) |
| `npm run build` | **verde em 52,7 s** (a 1ª tentativa morreu com `The service was stopped: write UNKNOWN` do esbuild — transitório no Windows; a 2ª passou) |
| manifest **pelo JSON** | **479 entradas / 196 páginas** (referência 478/195: +1 por `components/BotaoAtualizarAcervo.jsx`, que é chunk sob `Pages/`). ⚠️ `resources/js/Pages/Mlb/MeusAnuncios.jsx` → `assets/MeusAnuncios-Cr316Y4z.js` **presente** |
| sonda de CSS construído | **rodou** (não pulou): as 6 medidas do container e os 3 tons do âmbar com o valor certo, 4 iscas ausentes |
| território proibido (range da FASE) | **0** em `header/`, `SubAbasDePublicacoes.jsx`, `identidade/`, `AppLayout.jsx`, `Editor.jsx`, `Publicador/Mesa`, `app/Support/Publicador`, `MlPublicacaoService.php`. `tailwind.config.js` tem 1 — é do **plano 180-01** (`5972353b`), conferido |
| `publicacoes/` (range do 180-09) | **0 arquivos no diff** — este plano não cria nem edita componente |
| a rota órfã | os **6** caminhos com `test -e` e **0** no diff |
| PHP | **zero**, provado **commit por commit**: os 3 commits `180-10` têm `php=0`. Os commits de PHP/migration/ROADMAP/STATE no range são da **sessão paralela da Fase 184** (`184-08`/`184-09`), intercalados com os meus |
| suíte PHP | ⚠️ **NÃO RODADA, de propósito** (instrução do briefing: a outra sessão commita PHP e medir a área dela mediria trabalho alheio). Nenhuma linha de PHP foi tocada |

---

## Known Stubs

Nenhum stub novo. As ausências desta tela são **medidas e declaradas**, não placeholders:

| o que não aparece | por quê | onde está registrado |
|---|---|---|
| a faixa de sucesso | `faixaSucesso` é `null` SEMPRE (3 caminhos medidos no 180-04) | (8) desvio 5 + gate com os dois sentidos |
| o chip "Responsável" | a lista de responsáveis não existe como prop (medido no 180-07) | (5) pendência 4 |
| a margem na célula Preço | o servidor não manda `margem` (tarifa do ML fora do banco) | 180-08 §6a |
| a badge NOVA e o fundo `row-new` | o servidor não manda `nova` (sem chave de flash de publicação) | 180-04B §2 |
| "Com erro" na linha | `com_erro` chega, mas o mapa é território do 180-08 **e a precedência é decisão de UI** | (6) + checkpoint |
| o SKU por variação na linha | a célula nova não o desenha; o export `CelulaSku` ficou órfão declarado | (4) + checkpoint |
| a barra flutuante de ação em massa | é a Fase 181 | (5) pendência 9 + checkpoint |

## Threat Flags

Nenhuma superfície nova. `T-180-10-E` (D-11) e `T-180-10-D` (a tela preta) estão mitigados com gate
próprio; `T-180-10-T` (`dangerouslySetInnerHTML` ausente, `decodificarRotuloPaginacao` preservada e
endurecida) idem; `T-180-10-T2` (`navegar()` só com valores do estado controlado, validação por
lista fechada no servidor) idem. **Nenhum pacote novo. Nenhuma migration.**

## Self-Check: PASSED

| o que | resultado |
|---|---|
| `resources/js/Pages/Mlb/MeusAnuncios.jsx` | **902 linhas** (mínimo do plano: 300) |
| `resources/js/Pages/Mlb/components/BotaoAtualizarAcervo.jsx` | 52 linhas, criado |
| os 3 commits `180-10` | `a68be042`, `22670c9f`, `0c68368e` — todos em `git log` |
| `+assert > -assert` nos 3 gates tocados | 46>2 · 23>7 · 3>0 |
| as 3 asserções nominais (D-11 ×2, D-22) | intactas (as duas de D-11 byte a byte; a de D-22 reapontada de ARQUIVO, com as negativas da página intactas) |
| 16 mutações | 16 pegas · 1 sobrevivente investigada e convertida em cobertura · 1 recusada e refeita |
| JS antes/depois | 4325/4324/1 → 4392/4391/1 (mesma falha pré-existente) |
