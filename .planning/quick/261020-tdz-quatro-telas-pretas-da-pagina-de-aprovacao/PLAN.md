---
tipo: quick
slug: 261020-tdz-quatro-telas-pretas-da-pagina-de-aprovacao
titulo: "TDZ na página de aprovação — as telas pretas por uso-antes-da-declaração"
autorizado_por: usuário, 20/10/2026
partida: bb11a70b4e756d408a387d3569cb875011b8eb90
deploy: NÃO (nem push, nem VPS, nem API do ML)
migration: nenhuma
---

# Quick 261020-tdz — as telas pretas por TDZ na página de aprovação

## O defeito (em produção desde `e250f9fb`, 20/10/2026)

`resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` lê `const` do próprio corpo do
componente **antes** de elas serem inicializadas. Em JavaScript isso é TDZ
(*temporal dead zone*): a leitura lança `ReferenceError: Cannot access 'X' before
initialization`. Como a leitura está numa posição **eager** (atributo de JSX
avaliado na hora, dentro de um ternário que é avaliado durante a execução do corpo
do componente), o resultado é **TELA PRETA no instante em que o painel de revisão
abre com conteúdo**.

Hoje é inalcançável só porque `pub_aprovacao_ofertas` está vazia. **A Fase 194 cria
a primeira oferta — e nesse instante o painel ficaria preto.**

### O mecanismo, medido

`Aprovacao.jsx:883`:

```
const fotosDoPainel = painelDeRevisao === null ? null : objetoSeguro(painelDeRevisao.fotos);
```

`Aprovacao.jsx:965` é um ternário avaliado NA HORA:

```
const colunaDeFotos = fotosDoPainel === null ? (…vazio…) : ( <ColunaDeFotos … onReprovar={abrirModalDeReprovar} … /> );
```

No ramo `null` a JSX **não é avaliada** e nada lança. No ramo com fotos a JSX **É
avaliada**, lê o binding em TDZ e **LANÇA**. O ramo perigoso é alcançado
exatamente quando `painelDeRevisao !== null`, isto é, **quando o painel abre com
payload**.

### POR QUE NENHUM GATE PEGOU

**Nenhuma sonda deste projeto nunca renderizou o `default export` da página.**
Todos os gates montam a página por `esbuild` e exercitam **só os named exports**;
o componente da página **nunca é instanciado**. O defeito morava no único lugar
que nenhuma sonda olhava. ➡️ É esse gate que a tarefa 1 escreve.

## O inventário MEDIDO (cascata por render, não por leitura)

| símbolo | DECLARADA em | 1ª leitura EAGER em | bloco que a lê | wave |
|---|---|---|---|---|
| `abrirModalDeReprovar` | 1165 | 977 | `colunaDeFotos` (:965), `onReprovar={…}` | 188-12 |
| `registrarConflito` | 1231 | 978 | `colunaDeFotos` (:965), `onConflito={…}` | 188-12 |
| `abrirModalDoPainel` | 1112 | 1014 | `colunaDePreco` (:1007), `onInformarFrete={… === null …}` | 188-11 |
| `precoComSimulacao` | 1373 | 1012 | `colunaDePreco` (:1007), `preco={…}` | 188-09 |
| **`podeEscreverNoPainel`** | **1107** | **1013** | `colunaDePreco` (:1007), `onConferirAlteracao={!… ? …}` | **188-09** |

⚠️ **São CINCO, não quatro.** O quinto (`podeEscreverNoPainel`) é invisível para a
sonda da 189-07 porque a forma da leitura é `!podeEscreverNoPainel ? …`, e aquela
sonda só casa `prop={nome}` e `{...nome}`. Ele foi achado por **render**, não por
leitura — e foi exatamente a leitura que falhou quatro vezes.

Os três leitores eager, medidos, são `colunaDeFotos` (:965), `colunaDePreco`
(:1007) e `noPendenciasDoPainel` (:1072), e eles são consumidos **só** em
`:1599`–`:1603`.

## Tarefas

### Tarefa 1 — o GATE QUE FALTA, visto FICAR VERMELHO antes de qualquer conserto

Arquivo novo `tests/js/publicador-pagina-aprovacao-tdz-261020.test.js`, que:

1. **Renderiza o `default export` da página** (`esbuild` + `react-dom/server`)
   com payloads que fazem `painelDeRevisao !== null` **e** `fotosDoPainel !== null`
   (e os equivalentes para preço e pendências), e exige que **não lance**.
2. **Cobra a ORDEM NA FONTE** dos CINCO símbolos nomeados, por lista CONSTANTE
   (nunca contagem): `linha da declaração < linha da primeira leitura eager`.
   Essa camada cobre os cinco de uma vez — o primeiro que lança esconde os outros.
3. **Espiona os slots**: `PainelDeRevisao` devolve `createPortal`
   (`PainelDeRevisao.jsx:1335`), e `renderToStaticMarkup` **não desenha portal** —
   então asserção de conteúdo sobre o HTML da página seria **gate vazio por
   construção**. ➡️ O gate aliasa `PainelDeRevisao` para um espião que recebe os
   nós dos slots e os desenha INLINE, e assere sobre esse HTML.

⚠️ Contra as fontes de HOJE ele TEM de falhar com a mensagem real de
`ReferenceError`. **Se passar de primeira: PARAR** — ou o payload não alcança o
ramo (gate vazio por construção) ou o diagnóstico está errado.

**Método para provar um por vez** (declarado): *isolamento por payload* +
*cascata*. Payload com só `fotos` isola `abrirModalDeReprovar`; só `preco` isola
`precoComSimulacao`; só `pendencias` isola `abrirModalDoPainel`. `registrarConflito`
e `podeEscreverNoPainel` **nunca são os primeiros** em nenhum payload, então são
provados por cascata: variante com um bloco já movido, re-render, e o próximo
lança.

### Tarefa 2 — reordenar o MÍNIMO e provar que não criou TDZ nova

**QUATRO blocos contíguos** movidos para imediatamente antes de
`const colunaDeFotos` (:965), nesta ordem:

| bloco | linhas (pristino) | símbolos |
|---|---|---|
| M1 | 1104–1143 | `podeEscreverNoPainel`, `abrirModalDoPainel` |
| M2 | 1146–1206 | `abrirModalDeReprovar` |
| M3 | 1231–1238 | `registrarConflito` |
| M4 | 1360–1376 | `simulacaoDoFlash`, `previaDoFrete`, `precoComSimulacao` |

**A escolha, com a razão:** mover as DECLARAÇÕES para cima, não os leitores para
baixo. Mover os leitores também funcionaria (medido: são consumidos só em
:1599–:1603) e tocaria menos linhas, mas deixaria a **ordem de declaração
quebrada** — e aí a próxima wave que acrescentasse um atributo eager perto dos
slots reintroduziria o defeito. Mover as declarações conserta a raiz: handlers e
derivações **antes** dos construtores de slot que os consomem.

`simulacaoDoFlash` e `previaDoFrete` viajam em M4 porque `precoComSimulacao`
**depende** de `simulacaoDoFlash`, e o bloco de comentário de 1360–1369 é
contíguo (dividi-lo seria pior). `previaDoFrete` não precisava mover; vai junto
para manter o bloco contíguo, e só lê a prop `flash`.

**A prova de que não há quinta TDZ** (por RENDER, nunca por leitura): para CADA
bloco movido, todos os identificadores que ele lê **eagerly** são declarados em
linha ≤ 886 (`empresaId`:344, `ofertaDoPainel`:853, `versaoDoPainel`:854,
`precoDoPainel`:884, prop `flash`), exceto `podeEscreverNoPainel` (lido por M2),
que M1 declara **antes** de M2.

⚠️ **NÃO** trocar a JSX eager por função/callback. ⚠️ **NÃO** mexer em mais nada
no arquivo: nenhuma renomeação, nenhuma melhoria de passagem. O diff tem de ser
legível como *"quatro blocos mudaram de lugar"*, e é provado por **permutação
pura** (o multiset de linhas tem o MESMO `sha256` antes e depois).

### Tarefa 3 — render adverso e fechamento

Com a página finalmente renderizável, exercer o payload adverso: cada campo do
`painel` como **objeto, nulo, ausente, NaN e array**, e o `painel` inteiro nulo.
`doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)` sobre o HTML **do
espião** (o da página é vazio por portal). ⚠️ Nunca `String()`/`JSON.stringify()`
na mensagem de asserção — e há gate da 187-04 que PROÍBE `String(` na página, e
ele está CERTO.

## Gates e manifesto

**A convenção, MEDIDA e não inventada.** `tests/gates/verificar-testes.sh:122`
resolve o 1º argumento por `sed -nE 's/^([0-9]+)-([0-9]+)$/\1-\2/p'` — **dois
grupos de DÍGITOS**. Medido empiricamente:

```
arg=261020-tdz → ABORTOU: manifesto nao existe: …/contagens-186-261020-tdz.txt
arg=261020-01  → ABORTOU: manifesto nao existe: …/contagens-261020-01.txt
```

➡️ A saída (i) do briefing, **literalmente `261020-tdz`, NÃO funciona**: o harness
cai no ramo `else` e procura `contagens-186-261020-tdz.txt`, um caminho com
prefixo de fase FALSO. ➡️ Escolha declarada: **`tests/gates/contagens-261020-01.txt`,
chamado com `261020-01`** — par puramente numérico, que casa a forma documentada
`<fase>-<plano>`, e cujo caminho o harness monta EXATAMENTE (provado acima pela
mensagem de abort, que imprime o caminho). Nenhuma forma que o harness ignore em
silêncio.

**Não há manifesto de quick neste projeto** — todos os `contagens-*.txt` são de
fase. Este é o primeiro, e a razão fica escrita no próprio arquivo.

Ids:

| id | tipo | arquivo |
|---|---|---|
| `pagina-aprovacao-tdz` | js | `tests/js/publicador-pagina-aprovacao-tdz-261020.test.js` |
| `acoes-do-lightbox` | js | `tests/js/publicador-lightbox-acoes-189.test.js` (o veto da 189-07, **não editado**) |

### Escriturações conferidas e NÃO tocadas

- `ARQUIVOS_NOVOS` / `JSX_DEPOIS_DA_187` — **medido** que as duas listas são de
  arquivos sob `resources/js` (o GATE-03 compara disco × lista nos diretórios
  `identidade/`, `visaogeral/`, `publicacoes/`, `aprovacao/`). Esta quick **não
  cria `.jsx` nenhum**, só um `.test.js` em `tests/js/`. ➡️ Nada a acrescentar, e
  o invariante *"nenhuma PEÇA nova sob `resources/` é `.js`"* fica intacto.
- `vocabulario-tamanhos` — nenhum tamanho novo, nenhum `.jsx` novo. Re-medir e
  declarar.
- `tests/js/publicador-lightbox-acoes-189.test.js:1097` (o veto da 189-07) —
  **NÃO EDITAR**. Medir o que ele faz depois do conserto e declarar.

### Sufixo

Todo método/caso novo termina em `_261020_tdz` (o `--filter` do PHPUnit é regex
NÃO ANCORADO; no JS o arquivo roda inteiro, mas nome genérico colide na bijeção).

## Campanha de mutação

`mutar-261020-tdz.sh` (nome próprio — o scratchpad é COMPARTILHADO e na Fase 187
um script foi SOBRESCRITO e a campanha foi REFEITA inteira). Mutação principal:
**devolver UMA das declarações à posição antiga** — o gate novo TEM de matá-la,
**nomeando o símbolo**. Regras: commit ANTES; `cp -p` para fora do repo;
ocorrência travada em 1 ANTES por string exata; linha conferida depois; aplicação
por divergência de `sha256`; restauração DA CÓPIA em `trap EXIT`; `sha256` contra
`git show HEAD:` com `tr -d '\r'`; **`git checkout` NUNCA**. Confirmar que o
`# tests` do MUTADO é IGUAL ao do pristino — se cair, o arquivo não carregou e a
morte não é da asserção.

## Território

✅ **Autorizado:** editar `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx`
(reordenação) e criar arquivo de teste novo em `tests/js/` (+ o manifesto).

⛔ **Fora:** qualquer `.jsx` de `resources/js/Components/Mlb/Publicador/aprovacao/`,
qualquer serviço de `app/`, `AppLayout.jsx`, `PublicadorHeader.jsx`,
`tailwind.config.js`, `app/Services/Creative/`, endpoints `criativo*`,
`AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx`, o editor,
`useCriativosDoPublicador.js`, `useAcervoDaConta.js`, `App\Support\Publicador`,
`MlPublicacaoService`, `PublicacaoService`, `Bling*`, `Services/Portal/`,
`app/Mcp/`, `Acervo*`/`ImagemAssetService`, `.planning/ROADMAP.md`,
`.planning/STATE.md`, `REQUIREMENTS-v27.md`, os `189-*.md`, o `189-FID01.md`.
**Não rodar `state.advance-plan`** (o orquestrador atualiza o `STATE.md`).

⛔ **Git:** nunca `git add -A`, `git stash` (em forma NENHUMA — a árvore tem 27
stashes e o stack é COMPARTILHADO entre worktrees), `git reset`, `git checkout`,
`git commit --amend`, `git clean`. Commit por pathspec, `-m` ANTES do `--`,
`-F <arquivo>` sempre.

## Condições de PARADA

1. O gate novo **passar de primeira** (RED que não aconteceu).
2. O conserto exigir mexer em `.jsx` de `aprovacao/` ou em serviço de `app/`.
3. O veto da 189-07 **não ficar verde**.
4. A reordenação criar TDZ nova que não se resolva movendo só esses blocos.
5. A saída certa ser trocar a JSX eager por função.
