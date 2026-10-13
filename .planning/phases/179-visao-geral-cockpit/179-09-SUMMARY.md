---
phase: 179-visao-geral-cockpit
plan: 09
subsystem: ui
tags: [react, publicador, visao-geral, composicao, container, fidelidade, tailwind, mutacao, rend-01, rend-02, rend-03, reg-01, exmp-02, gate-01, gate-03]

requires:
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "O `PublicadorHeader` global como PRIMEIRO filho do AppLayout (HEAD-14), com o `-mx-6 -mt-6` que neutraliza o topo e as laterais do `p-6` do `<main>`"
  - phase: 178-modal-nova-publicacao
    provides: "O molde do gate por igualdade de CONJUNTO de classes, o harness esbuild + react-dom/server e a medida de que o bundle que alcança o header precisa de `@radix-ui/react-dialog` no `external`"
  - phase: 179-visao-geral-cockpit
    provides: "179-05: as 23 props na action · 179-06: `jornada.js` + `JornadaDosProdutos` · 179-07: `acoes.js` + `OQueFazerAgora` + `SaudeDaConta` + `PublicacoesNoMercadoLivre` · 179-08: `recentes.js` + `BannerDeIntegracao` + `FaixaDeDesempenho` + `PublicacoesRecentes` + `AtividadeRecente`"
provides:
  - "resources/js/Components/Mlb/Publicador/visaogeral/CockpitDaConta.jsx — a composição dos 5 blocos do protótipo, na ordem e nas grades medidas, com as duas URLs montadas no cliente"
  - "resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx — o container de 1600px com o `p-6` do AppLayout neutralizado NA PÁGINA"
  - "tests/js/publicador-visao-geral-cockpit.test.js — 135 testes, 122 asserções, 23 mutações reprovadas, render REAL da PÁGINA COMPLETA"
  - "`ARQUIVOS_NOVOS` com os 12 arquivos da fase + `8.5` em `TAMANHOS_PERMITIDOS` + o teste que reprova arquivo fora da lista"
affects: [179-10-fechamento, 180-publicacoes, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "A composição devolve FRAGMENTO e o `gap` mora SÓ no container da página: no protótipo os blocos são filhos DIRETOS do `<main>`, e wrapper no componente criaria um 2º nível de gap"
    - "Neutralizar padding de layout exige DOIS elementos: `-mx-6` e `mx-auto` disputam a mesma margem lateral"
    - "⚠️ `new RegExp(`\\b${x}\\b`)` com TEMPLATE LITERAL é asserção VAZIA — em template literal `\\b` é BACKSPACE (U+0008). Usar `String.raw`"
    - "⚠️ Asserção negativa sobre LITERAL DE TELA exige medir antes se o literal é EXCLUSIVO do bloco removido — dois dos sete colidiam com texto legítimo da tela nova"
    - "Asserção negativa de literal recortada ao CORPO da página pelos índices absolutos do container, nunca ao HTML inteiro (o header renderiza substrings colidentes)"
    - "Guarda de PARÂMETRO DE URL = a MESMA forma fechada que a rota aceita (`^(empresa|company)-[0-9]+$`), não `textoUtil`"
    - "Slot localizado por IDENTIDADE (o que o bloco É) e não por índice: um teste cobra a POSIÇÃO, os outros cobram o CONTEÚDO"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/visaogeral/CockpitDaConta.jsx
    - tests/js/publicador-visao-geral-cockpit.test.js
  modified:
    - resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx
    - tests/js/publicador-vocabulario-novo.test.js
    - .planning/STATE.md

key-decisions:
  - "⚠️ 'Produtos por fase' e 'Quem publicou' NÃO nascem na tela nova — DECISÃO INFORMADA DO USUÁRIO EM 12/10/2026, entre três opções, com o aviso de que eram o ÚNICO caminho desses dados. Exceção explícita a 'melhorar sem regredir'; LIST-25 foi CUMPRIDO"
  - "As props `produtosPorFase` e `quemPublicou` continuam chegando: a decisão é de TELA, payload não é tela — e manter custou ZERO cirurgia de gate"
  - "`CockpitDaConta` devolve FRAGMENTO e o `gap-[20px]` mora só no container da página — escolhido entre as duas opções que o plano deixou abertas"
  - "A legenda do recorte é IRMÃ DIRETA da grade 3 (não wrapper em volta dela): mantém a grade como filho direto do container e o ritmo vertical de 20px"
  - "`whitespace-nowrap` na coluna lateral: o protótipo o declara (`:234`) e os dois cards de dentro foram desenhados já herdando o nowrap — divergência da tabela do plano, a favor do protótipo"
  - "A prova de 'Fase 1 publicada' virou POSICIONAL e a de 'Origem antiga' virou de FONTE ÚNICA no fixture, porque os dois literais colidem com texto legítimo da tela nova (medido)"
  - "`chaveDeContaSegura()` (Rule 2): a chave de conta tem de casar a MESMA forma fechada da rota, senão `textoUtil(7)` inventava `conta: '7'`"
  - "O gate HEAD-14 do vocabulário ganhou tabela `CONTAINER_POR_PAGINA`: REAPONTE de valor, com o predicado `header < corpo` intacto e UMA asserção a mais"

metrics:
  duration: "~4h"
  completed: 2026-10-13
  tasks: 3
  tests_added: 212
  mutations: 23
  mutations_caught: 23
  mutations_survived: 0
  mutations_abortadas_na_1a_rodada: 3
  migrations: 0
  pacotes: 0
---

# Fase 179 Plano 09: a composição da Visão geral Summary

**A tela que o usuário queria ver trocou.** O corpo de `/mlb/anuncios/publicador/empresas/{conta}/visao-geral`
deixou de ser `PainelVisaoGeral` e passou a ser o **cockpit**: os cinco blocos do protótipo, na ordem
e nas grades medidas, no container de 1600px com o `p-6` do `AppLayout` neutralizado **na página**.
**23 mutações reprovadas, 135 testes no gate novo, nenhum arquivo apagado, nenhum pacote.**

⚠️ **Nenhuma tela foi vista renderizada** — não há navegador neste ambiente e o banco local não tem
empresa com `ml_token`. Toda a conferência é de teste automatizado, de sonda sobre o CSS construído e
de mutação. **A validação visual é do usuário, em produção, na conta 459**, e é a Task 3 do plano
179-10.

---

## ⚠️ A DECISÃO DO USUÁRIO — registrada com a data, a pergunta e a escolha

> **"Produtos por fase" e "Quem publicou" saíram da tela por DECISÃO INFORMADA DO USUÁRIO EM
> 12/10/2026**, escolhida entre três opções (**preservar no fim da página** · **mover para
> Configurações da conta** · **tirar os dois**) e com o **aviso explícito de que os dois são o ÚNICO
> caminho desses dados no sistema**. É **exceção explícita à regra-mestra "melhorar sem regredir"**,
> que valeu na milestone v26.0 inteira — até aqui a única remoção autorizada era o seletor de período
> (critério 1 do ROADMAP). E **LIST-25 foi CUMPRIDO**: a guarda é *"se for o ÚNICO caminho para algum
> dado, a fase PARA e PERGUNTA"*, e foi exatamente o que aconteceu. A decisão de perder o dado é do
> dono do produto, não do planejador.
>
> ⚠️ **Nenhuma sessão futura deve restaurá-los sem uma decisão NOVA do usuário.**

O registro está em **três lugares**, para não depender de ninguém lembrar: no comentário de topo do
`CockpitDaConta.jsx`, na **mensagem de asserção** que falha se o bloco voltar, e aqui.

### E as props continuam chegando — os 4 motivos, para a próxima pessoa não "limpar"

A decisão do usuário é de **TELA** (*"não mostre esses dois blocos"*), e **payload não é tela**. O que
saiu é o **render**. `produtosPorFase` e `quemPublicou` seguem nas 23 props do `Inertia::render`:

1. ⚠️ **Parar de enviar exigiria cirurgia em 14 asserções** de `tests/Feature/Publicador/ListaPorFaseTest.php`
   (10) e `VisaoGeralTest.php` (4) — incluindo `test_visao_geral_entrega_produtos_por_fase_sem_perder_nenhuma_prop`,
   **um teste cujo propósito inteiro é provar que o contrato não perde prop**. Reapontá-lo seria
   reescrever a intenção dele.
2. **`produtosPorFase` custa 0** (passthrough sobre o `contagemProdutos` já calculado).
3. **`PainelVisaoGeral.jsx` fica no disco** e é renderizado por 2 gates (33 + 56 casos): se uma sessão
   futura reapontar a página para ele (rollback, depuração), as props precisam estar lá.
4. **Área de diff:** a fase tem 10 planos e a árvore é compartilhada com outro dev ativo.

⚠️ **A cirurgia de gate foi ZERO, provada por diff:**

```
git diff --stat 402810c2..HEAD -- tests/Feature/Publicador/ListaPorFaseTest.php \
                                  tests/Feature/Publicador/VisaoGeralTest.php   →  VAZIO
```

⚠️ **Mas o 179-05 mediu que `quemPublicou` NÃO é de graça:** ela custa 1 consulta + 1 `User::find()`
**por publicação da conta** (`publicadosRecentes()` → `resolverAtor()`, dívida 1 do
`deferred-items.md`). Agora que o render saiu, **tirar a prop passou a ter benefício medido** — mas
isso é decisão informada de outro plano, com os 14 testes na mesa. **Este plano não a tirou, e está
certo em não ter tirado.**

⚠️ **Prop órfã no `CockpitDaConta` é deliberada:** o componente nem as desestrutura, e um teste cobra
que elas **não** apareçam no arquivo (`assert.doesNotMatch(fonte, /produtosPorFase/)`), para ninguém
"ligar" o render por engano.

---

## ⚠️ O INVENTÁRIO REG-01 COMPLETO — travado em constante no gate, uma asserção por linha

| Bloco de hoje | Onde ele está amanhã | Tipo |
|---|---|---|
| os 6 KPIs do topo (`no_ar`, `com_venda`, `sem_oferta`, `publicados_30d`, `tracao_pct`, `criativos_packs`) | `publicacoesMl` (no ar) · `desempenho` (criadas, com venda, criativos) · estágio `none` da `jornada` (sem oferta) · `acervoImagens` (packs) | **home nova** |
| "O que fazer agora" (todas as linhas) | `acoes` — a MESMA linha, com severidade, sub-itens e adiamento, **com contagem exata** (provada no 179-02) | **home nova** |
| "Últimas publicações" | `recentes` (mais rico: capa, fase, status, quem/quando) | **home nova** |
| "Atividade da equipe" | `atividade` (as 3 fontes reais + a 4ª), agrupada por dia | **home nova** |
| "Situação dos produtos" (4 buckets) | **outro caminho:** a tela Produtos recebe `contagens` e tem os mesmos filtros com os mesmos números | **outro caminho** |
| "Alertas Meli & ERP" (motivos da triagem) | **outro caminho:** `MeusAnuncios.jsx` tem os chips da triagem com total e filtro — é a casa canônica | **outro caminho** |
| "Integrações" (`publicacao_liberada`, `alavancas_liberada`, `portal`, `erp`) | `saude` (ML · ERP · coleta) **+ outro caminho:** "Configurações da conta" recebe `conexoes` = o MESMO `integracoes()`; o header já mostra ML/ERP/Portal | **outro caminho** |
| "Identidade visual" (resumo) | **outro caminho:** "Configurações da conta" recebe o texto **completo**, não o resumo | **outro caminho** |
| **"Produtos por fase"** (5 buckets) | ⚠️ **REMOVIDO da tela — decisão informada do usuário, 12/10/2026** | **removido** |
| **"Quem publicou"** (agregado 30 dias) | ⚠️ **REMOVIDO da tela — decisão informada do usuário, 12/10/2026** | **removido** |
| seletor de período | **removido** pelo critério 1 — e a UI passou a **dizer** o recorte | **removido** |

⚠️ **Para as 4 linhas de "outro caminho", o gate cobra que o literal do H2 antigo NÃO está no HTML
novo** — é a prova de que a informação não sumiu do sistema **e** de que a tela não ficou com dois
blocos dizendo a mesma coisa. Com **isca**: os 3 literais conferidos como presentes em
`PainelVisaoGeral.jsx`, senão a ausência seria porque o literal nunca existiu.

---

## A ordem, as grades e o container — tudo medido no protótipo

Fonte: `new_layout_publicador/Visao Geral.dc.html`. ⚠️ **As linhas abaixo foram MEDIDAS com `grep -n`
e `awk`**; as do `<action>` do plano estão deslocadas.

### Os CINCO filhos diretos do `<main>` (medidos por varredura de indentação)

| # | Bloco | Protótipo (linha medida) | Plano dizia |
|---|---|---|---|
| 1 | banner de integração (`sc-if hasBanner`) | `:27` | — |
| 2 | Jornada dos produtos (`<section>`) | `:40` | — |
| 3 | grade `minmax(0,1fr) 392px`, `gap:20px`, `align-items:start` | **`:164`** | `:161` |
| 4 | faixa de Desempenho | `:286` | — |
| 5 | grade `minmax(0,1fr) minmax(0,1fr)`, `gap:20px`, `align-items:start` | **`:312`** | `:304` |

⚠️ **A grade 5 fecha em `:383` e o `</main>` é `:384` — nada depois dela.** O gate cobra que ela é o
**último filho** do container, e a mutação M11 (um `<div>` qualquer depois) reprova.

### O container

| Elemento | Protótipo | Implementado |
|---|---|---|
| raiz da página | `min-height:100vh;min-width:1240px` (**`:22`**, o plano diz `:23`) | `min-w-[1240px]` no container interno |
| `<main>` | `max-width:1600px;margin:0 auto;padding:24px 36px 80px;display:flex;flex-direction:column;gap:20px` (**`:25`-`:26`**, o plano diz `:26`) | o container interno, byte a byte |
| coluna lateral | `white-space:nowrap;display:flex;flex-direction:column;gap:20px` (**`:234`**) | `flex flex-col gap-[20px] whitespace-nowrap` |

**As duas classes finais, travadas no gate por igualdade de CONJUNTO:**

```
externo: -mx-6 -mb-6
interno: mx-auto flex max-w-[1600px] min-w-[1240px] flex-col gap-[20px] px-[36px] pt-[24px] pb-[80px]
```

### ⚠️ A aritmética do `p-6`, e por que são DOIS elementos

O `<main>` do `AppLayout` tem `p-6` (24px) e **`AppLayout.jsx` é território proibido** (D2/HEAD-14),
então a neutralização é **na página**. Sem ela o lateral efetivo seria 24 + 36 = **60px** e o inferior
80 + 24 = **104px**.

- o **externo** cancela: `-mx-6` (o `px-6`) e `-mb-6` (o `pb-6`);
- o **interno** aplica o padding do protótipo e centra os 1600px.

⚠️ **Não dá para fundir:** `-mx-6` e `mx-auto` disputam a **mesma** margem lateral, e `mx-auto`
precisa da margem automática para centrar. O `pt-6` do `<main>` **continua cancelado pelo `-mt-6` do
HEADER** — o gate cobra que o header tem o `-mt-6` **e** que a página **não** o tem, para o
`pt-[24px]` continuar medido da borda inferior do header.

⚠️ **`AppLayout.jsx` não foi tocado:** `git diff --stat 898d68c2..HEAD -- resources/js/Layouts/AppLayout.jsx` → **vazio**.

---

## O seletor de período saiu, e a UI DIZ o recorte (VG-03 / critério 1)

**A legenda, literal, travada por igualdade de string inteira:**

> `Jornada, Saúde e Ações mostram o estado atual. Recentes e Atividade mostram os últimos 30 dias.`

Em `text-[12px] text-pub-txt-4`, como **irmã direta** no topo da grade 3.

⚠️ **Divergência DECLARADA do protótipo:** ele **não tem** esta linha. Ela entra por exigência do
critério 1 (*o seletor sai **e** a UI tem de **dizer** o recorte*), não por adaptação de layout.

⚠️ **Decisão de posição:** irmã direta da grade (e não wrapper em volta dela). Wrapper tiraria a
grade de "filho direto do container" e criaria um segundo nível de gap — o mesmo motivo pelo qual o
cockpit devolve fragmento.

**Os outros dois recortes já vinham dos blocos** e o gate cobra os dois: a Jornada diz
`Estado atual · independe do período` (179-06) e "Publicações recentes" diz
`O que acabou de entrar no ar nos últimos 30 dias` (179-08). A legenda cobre **o que faltava**
(Saúde, Ações, Atividade), não o que já estava dito.

⚠️ **O protótipo CALCULA `periods` (`:601`) e NUNCA o renderiza** — não há seletor de período lá nem
aqui. O gate cobra a ausência de `Hoje`, `Últimos 7 dias`, `Este mês` e `7 dias` no corpo, **com
ISCA**: a tela **SIM** escreve rótulos de data (`HOJE` em caixa alta, `recentes.js:424`) e
`Últimos 30 dias`. Sem a isca, "não contém Hoje" passaria também numa tela que não renderiza data
nenhuma — e as asserções são **sensíveis a caixa de propósito**.

---

## ⚠️ As DUAS colisões de literal que mudaram o desenho da asserção negativa

O plano mandava cobrar que o HTML **não** contém `Produtos por fase`, `Quem publicou`, os 5 rótulos
de bucket e `Origem antiga`, com as props preenchidas. **Medi antes, e dois dos sete literais colidem
com texto LEGÍTIMO da tela nova** — negativa cega neles reprovaria a tela **certa**:

| Literal | Colisão medida | Como a prova ficou |
|---|---|---|
| `Fase 1 publicada` | é o **rótulo do estágio 03 da Jornada** (`jornada.js:73`) e também o rótulo do bucket `fase1_publicada` (`PainelVisaoGeralService.php:704`) | **POSICIONAL**: toda ocorrência tem de cair **dentro do nó da Jornada** (índices absolutos), e a contagem > 0 impede a prova de virar vazia |
| `Origem antiga` | é valor **legítimo** de `atividade` e `recentes` (`CockpitDaContaService.php:576`, `PainelVisaoGeralService.php:988`) | **FONTE ÚNICA no fixture**: os blocos novos recebem `Ana Ribeiro`/`Carla Mendes`, e o gate **prova** que o payload novo não traz o literal antes de cobrar a ausência |

⚠️ **E uma terceira armadilha de substring:** `Sem oferta` é **substring de `Sem ofertas no Portal`**
(`header/ConexaoDoHeader.jsx:195`), que o **header renderiza na MESMA página**. Por isso **toda**
asserção negativa de literal é recortada ao **CORPO** da página (pelos índices absolutos do nó do
container), nunca ao HTML inteiro — senão o estado do header decidiria se o gate passa.

Os **6 literais** que sobraram com negativa direta: `Produtos por fase`, `Quem publicou`,
`Sem oferta`, `Fase 2 em preparação`, `Fase 2 publicada`, `Fase 3+`. E o gate **prova primeiro** que
o fixture carrega cada um deles dentro de `produtosPorFase`/`quemPublicou` — **com elas vazias a
ausência seria vazia por construção**, a mesma classe de furo do `git diff` sem range.

**As mutações M10 e M10b** renderizam os literais **sem mexer na estrutura** (um `<span>` dentro do
`<p>` da legenda, que não altera `textoDireto` nem a contagem de filhos) — **as duas reprovam**, e
reprovam **pela asserção negativa**, que é o que se queria provar.

---

## Os 12 arquivos em `ARQUIVOS_NOVOS` e o `8.5` — com a medida

**A lista REAL, conferida por `git`, não pelo número do plano:**

```
git diff --name-only --diff-filter=A 898d68c2..HEAD -- resources/js   →  11 arquivos
+ CockpitDaConta.jsx (deste plano)                                    →  12
```

⚠️ **O plano falava em 13.** O 13º (`BlocosPreservados.jsx`) **não nasceu**, por causa da decisão do
usuário de 12/10.

| Plano | Arquivos |
|---|---|
| 179-06 | `jornada.js` · `JornadaDosProdutos.jsx` |
| 179-07 | `acoes.js` · `OQueFazerAgora.jsx` · `SaudeDaConta.jsx` · `PublicacoesNoMercadoLivre.jsx` |
| 179-08 | `recentes.js` · `BannerDeIntegracao.jsx` · `FaixaDeDesempenho.jsx` · `PublicacoesRecentes.jsx` · `AtividadeRecente.jsx` |
| 179-09 | `CockpitDaConta.jsx` |

### A justificativa MEDIDA do `8.5` (é ampliação da lista, não endurecimento)

| O que | Medido |
|---|---|
| `font-size:8.5px` no protótipo | **3 vezes**: iniciais do avatar de 24px (**`:104`**), do thumb de 26px (**`:197`**), ícone de 22px do sub-item (**`:210`**) |
| `text-[8.5px]` nos 12 arquivos | **3 vezes**: `JornadaDosProdutos.jsx` (1) e `OQueFazerAgora.jsx` (2) |
| nos 5 arquivos do 179-08 | **zero** — como o 179-08 avisou, medido com `grep` |

⚠️ **As linhas do plano (`:97`/`:192`/`:204`) e as do 179-07 (`:191`/`:202`) estão deslocadas.** As
acima saíram de `grep -n`. **Nenhum valor foi removido de `TAMANHOS_PERMITIDOS`.**

E o **teste novo que fecha a omissão**: compara `ARQUIVOS_NOVOS` com a **listagem real** de
`identidade/` e `visaogeral/` e **reprova arquivo no disco fora da lista** (com `noDisco.length > 0`
para o diretório vazio não passar calado). ⚠️ `header/` fica **fora** dessa checagem de propósito —
tem arquivos que não são desta milestone.

---

## As divergências DECLARADAS, cada uma com a medição

| # | O plano diz | O que foi entregue | Medição |
|---|---|---|---|
| D-1 | "os 8 blocos do protótipo" / "a grade 5" | **5 filhos diretos** do `<main>` (o 6º é a legenda, que é adição do critério 1) | Varredura de indentação de `:25`-`:384`: `:27`, `:40`, `:164`, `:286`, `:312`. Os "8 blocos" contam os cards DENTRO das grades |
| D-2 | o `gap-[20px]` "aqui **ou** no `CockpitDaConta`" | **no container da página**, e o cockpit devolve **fragmento** | É o que o protótipo faz (`:26`): os blocos são filhos diretos do `<main>`. Wrapper no componente criaria 2º nível de gap. **M19** (embrulhar) reprova em 29 testes |
| D-3 | referências `:23`/`:26`/`:161`/`:304` | **`:22`/`:25`-`:26`/`:164`/`:312`** | `grep -n` + `awk`. O placar da fase segue: **toda** divergência a favor do protótipo |
| D-4 | a tabela do container não cita `white-space` na coluna lateral | **`whitespace-nowrap` acrescentado** ao `<aside>` | `:234` o declara, e os dois cards de dentro (179-07) foram desenhados lendo essas mesmas linhas, já herdando o nowrap. **M18** (tirar) reprova |
| D-5 | "asserção negativa" nos 7 literais dos blocos removidos | **6 negativas diretas + 1 posicional + 1 de fonte única** | `Fase 1 publicada` e `Origem antiga` colidem com texto legítimo da tela nova (ver a seção acima) |
| D-6 | "a legenda no topo da grade 3" | **irmã direta** da grade, imediatamente antes | Wrapper tiraria a grade de filho direto do container e criaria 2º nível de gap. A legenda fica no mesmo ritmo de 20px |
| D-7 | "≥ 12 mutações" | **23** (3 abortaram na 1ª rodada por alvo impuro e foram refeitas) | Ver o placar abaixo |
| D-8 | "`npm run test:js` em ≥ 3019 testes" | **3720** | A baseline medida ANTES de editar já era **3508 / 3507 pass / 1 fail** |
| D-9 | — | **`chaveDeContaSegura()`** (Rule 2) | `textoUtil(7)` devolve `'7'` e montava `conta: '7'`, destino que a rota recusa com 404. Ver a seção de Deviations |

---

## A prova por mutação — 23 nominais, 23 reprovações, 0 sobreviventes

Processo: contagem de ocorrências **travada antes** (recusa se ≠ 1), **recusa de alvo que só casa em
comentário**, aplicação, **impressão da linha alterada (antes/depois)**, execução dos gates,
restauração de cópia pristina e **conferência por `sha256`**, com o harness abortando se divergisse:

```
CockpitDaConta.jsx  ac25640792e5badf…0065c0aa   OK no fim
VisaoGeral.jsx      967fda08dcbe792d…fa5b03d20   OK no fim
vocabulario.test.js a7dab864b0713f2a…6708f536   OK no fim
```

| # | Mutação | Resultado / reprovou em |
|---|---|---|
| M01 | trocar a ordem de dois blocos (a faixa **depois** da grade 5) | **REPROVOU** (4) — teste A, slots 5 e 6 |
| M02 | `gap-[20px]` do container → `gap-5` | **REPROVOU** (3) — teste C, "o gap mora em UM lugar" |
| M03 | `max-w-[1600px]` → `max-w-[1536px]` | **REPROVOU** (4) — teste C **+ HEAD-14** do vocabulário |
| M04 | tirar o `-mx-6` da neutralização | **REPROVOU** (2) — teste C, wrapper externo |
| M05 | `px-[36px]` → `px-9` | **REPROVOU** (2) — teste C, container interno |
| M06 | `pb-[80px]` → `pb-20` | **REPROVOU** (2) — teste C |
| M07 | `392px` → `380px` na grade 3 | **REPROVOU** (13) — testes A, B, D, H, J |
| M08 | tirar `items-start` da grade 3 | **REPROVOU** (2) — teste B |
| M09 | tirar a legenda do recorte | **REPROVOU** (15) — testes A, D, H |
| **M10** | ⚠️ **RENDERIZAR "Quem publicou"/"Origem antiga"**, sem mexer na estrutura | **REPROVOU** (3) — **teste H**, as duas negativas |
| **M10b** | ⚠️ **RENDERIZAR "Produtos por fase"/"Fase 2 publicada"** | **REPROVOU** (3) — **teste H** |
| M11 | acrescentar um bloco depois da grade 5 (apêndice) | **REPROVOU** (9) — teste A ("último bloco"), H, J |
| M12 | tirar `CockpitDaConta.jsx` de `ARQUIVOS_NOVOS` | **REPROVOU** (3) — **o teste novo da listagem do diretório** |
| M12b | tirar `SaudeDaConta.jsx` de `ARQUIVOS_NOVOS` | **REPROVOU** (3) — idem |
| M13 | tirar `8.5` de `TAMANHOS_PERMITIDOS` | **REPROVOU** (3) — os arquivos que o usam |
| M14 | pôr `z-40` num bloco | **REPROVOU** (5) — testes B **e K** |
| M15 | importar `dadosDeExemplo` num arquivo novo | **REPROVOU** (4) — testes E e F (EXMP-02) |
| M16 | trocar o nome da rota de Produtos | **REPROVOU** (2) — teste M |
| M17 | deixar de descer `urlPublicacoes` para "Publicações recentes" | **REPROVOU** (2) — teste M |
| M18 | tirar o `whitespace-nowrap` da coluna lateral | **REPROVOU** (2) — teste B |
| M19 | embrulhar os blocos num wrapper próprio (2º nível de gap) | **REPROVOU** (29) — testes A, B, C, D, H, J |
| M20 | passar `expandidasIniciais` (prop que é **só do gate**) | ⚠️ 1ª rodada **SOBREVIVEU** (gate furado) · 2ª **REPROVOU** (3) — teste N |
| M21 | afrouxar a guarda canônica da chave (voltar ao `textoUtil` cru) | **REPROVOU** (2) — teste M |

⚠️ **Três mutações ABORTARAM na 1ª rodada pela trava de segurança do próprio harness** (M01, M03,
M19): o alvo abrangia **comentário JSX + código**, e a checagem "alvo que só casa em comentário" deu
**falso positivo**. Alvo de mutação tem de ser trecho **puro de código**, com a reinserção numa etapa
separada. Refeitas e reprovando.

---

## Deviations from Plan

### [Rule 2] Chave de conta não-canônica inventava destino — e foi o GATE deste plano que pegou

- **Encontrado durante:** o teste M (as duas URLs), na asserção "sem destino ÚTIL a prop desce `null`".
- **Causa:** `textoUtil(7)` devolve a **string** `'7'` (o `textoSeguro` aceita número), e o cockpit
  montava `route('mlb.anuncios.publicador.produtos', { conta: '7' })` — **um destino que não pode
  existir**, porque a rota declara `->where('conta', '(empresa|company)-[0-9]+')` e responderia 404.
  É literalmente a arapuca que o 179-07 registrou: *"guarda de DESTINO e de PARÂMETRO mais estrita que
  guarda de TEXTO"*. **Terceira reincidência do `textoUtil(7)` nesta fase** (179-06, 179-07, 179-09).
- **Correção:** `chaveDeContaSegura()` aceita a **MESMA** forma que a rota aceita, nem mais larga nem
  mais estreita (`^(empresa|company)-[0-9]+$`), e devolve `null` em qualquer outra — aí o CTA
  simplesmente não nasce (EXMP-01). Vale também para `contaChave`, que vira parâmetro do POST de
  "Adiar até amanhã" (`publicador.acoes.adiar` tem o **mesmo** `where`).
- **Gate:** 7 formas não-canônicas (`'company'`, `'company-'`, `'459'`, `'empresa-abc'`, `'loja-7'`,
  `'company-459x'`, `7`) + as duas canônicas + a borda aparada. **M21 reprova quem reverter.**
- **Commit:** `a346c1f6`

### [Rule 1 — gate furado] A mutação M20 SOBREVIVEU: as 4 props de estado inicial passavam calado

- **Encontrado durante:** a 1ª rodada de mutação.
- **Causa:** passar `expandidasIniciais={{com_problema: true}}` ao `OQueFazerAgora` não reprovava
  nada. As quatro props de **estado inicial** (`expandidasIniciais`, `atualizandoInicialmente`,
  `filtroInicial`, `chipInicial`) e a `agora` existem para que os gates dos planos 179-07/179-08 não
  fiquem vazios por construção; passá-las em produção mudaria o estado inicial da tela **em
  silêncio** — gaveta aberta, spinner ligado, chip trocado, data congelada.
- **Correção:** teste **N**, com as 5 props cobradas na fonte sem comentário, **isca** de que elas
  **existem** nos componentes donos, e a **lista FECHADA** das 12 props que a composição de fato
  passa. `agora` é cobrada como **prop** (`/\bagora=/`): `/agora/` solto casaria com o literal
  "O que fazer agora" e seria asserção vazia ao contrário.
- **Commit:** `cdecd846`

### ⚠️ [Rule 1 — armadilha de medidor NOVA] `new RegExp(` + template literal + `\b)` é asserção VAZIA

- **Encontrado durante:** a escrita do próprio teste N — e **quem denunciou foi a ISCA**.
- **Causa:** em **template literal**, `\b` é o caractere **BACKSPACE (U+0008)**, não a borda de
  palavra do regex. Nenhuma fonte deste projeto contém backspace, então as **4 asserções negativas
  passavam POR CONSTRUÇÃO**. Elas são `doesNotMatch`, logo não tinham como acusar; a isca usa a
  **mesma expressão no sentido POSITIVO** e reprovou na hora.
- **Correção:** `String.raw`, com a armadilha escrita no arquivo.
- **Família:** é irmã do `/href="…"/` sem o `\s` do 179-08 — **medidor que nunca falha**.
- **Commit:** `cdecd846`

### [Desvio de processo] As Tasks 1 e 2 foram commitadas ANTES do gate que as verifica

O `<verify>` das duas é `tests/js/publicador-visao-geral-cockpit.test.js`, que a Task **3** cria. Segui
a ordem do plano (1 → 2 → 3) em vez de inverter para RED-primeiro, como as waves 1 e 2 fizeram. As
duas estão verificadas no fim: o gate ficou verde e as 23 mutações reprovaram. Registrado para não
parecer que o gate foi escrito depois **para encaixar** no código: 3 mutações (M02, M05, M06) alteram
só a página e reprovam no teste C, e M01/M07/M08/M18 alteram só o cockpit.

### [Fora de escopo, registrado] A dívida que este plano NÃO consertou

`quemPublicou` custa **1 consulta + 1 `User::find()` por publicação da conta** (medido pelo 179-05:
65 consultas com 2 publicações, 91 com 12) e **o bloco dela saiu da tela**. Tirar a prop junto com o
render passou a ter benefício medido — mas custa cirurgia em 14 asserções, incluindo um teste cujo
propósito é provar que o contrato não perde prop. **Decisão informada para outro plano**, com o
número na mesa. Segue registrado em `.planning/phases/179-visao-geral-cockpit/deferred-items.md`.

---

## Verification Results

| # | Gate | Resultado |
|---|---|---|
| 1 | `npm run test:js` | **3720 testes / 3719 pass / 1 fail** — o pré-existente `estrutura-grade-glide.test.js:122`. ⚠️ Baseline medida **ANTES** de editar: **3508 / 3507 / 1**. Delta: **+212 testes, mesma única falha** |
| 2 | `publicador-visao-geral-cockpit` · `publicador-vocabulario-novo` · `publicador-header-nas-paginas` · `publicador-tarefas` · `publicador-header-zindex-ordem` | ✅ verdes (**135** + **245** + os três) |
| 3 | ⚠️ `publicador-visao-geral-render` (33) · `publicador-dashboard-v2` (56) | ✅ **verdes sem NENHUMA mudança** — `PainelVisaoGeral.jsx` não foi tocado |
| 4 | `git diff 402810c2..HEAD -- tests/ \| grep '^-' \| grep assert` | **2 linhas**, as duas do **REAPONTE** do HEAD-14, cada uma com o `+` do **mesmo teste** (enumeradas abaixo). Isca: **130** asserções acrescentadas no mesmo range |
| 5 | diff vazio em `AppLayout.jsx` · `PainelVisaoGeral.jsx` · `header/` · `identidade/` | ✅ (o range de `header/`/`identidade/` é `402810c2..HEAD`, porque o 179-08 os tocou com props aditivas) |
| 6 | diff vazio em `Editor.jsx` · `Components/Publicador/Mesa` (`898d68c2..HEAD`) | ✅ território do outro dev intacto |
| 7 | diff vazio em `app` · `routes` · `database` · `tailwind.config.js` (`402810c2..HEAD`) | ✅ este plano é só tela e gate |
| 7b | diff vazio nos **11 componentes** das waves 1-2 (`402810c2..HEAD`) | ✅ nenhum arquivo de outro plano tocado |
| 7c | diff vazio em `app/Support/Publicador` · `MlPublicacaoService.php` (`898d68c2..HEAD`) | ✅ |
| 8 | `git diff --diff-filter=D --name-only 898d68c2..HEAD` | ✅ **vazio** — nada apagado na fase, inclusive o painel antigo |
| 9 | `git status --short \| grep tests/js` | ✅ **vazio** (nenhum stub do esbuild sobrou) |
| 10 | `npm run build` | ✅ verde em 28,9s, e **`resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` presente no `manifest.json`** lido **pelo JSON** (`node --input-type=commonjs` + `require`) → `assets/VisaoGeral-BTucwMYP.js` |
| 10b | **sonda do CSS construído** | ✅ as 13 classes novas compilam, inclusive as duas grades (`.grid-cols-\[minmax\(0\,1fr\)_392px\]`). ⚠️ Iscas: `max-w-[1601px]`, `px-[37px]` e `grid-cols-[minmax(0,1fr)_393px]` **ausentes** — a sonda não é vazia |
| 11 | os **18 caminhos** dos gates conferidos por `test -e` **antes** de acreditar no vazio | ✅ nenhum ausente |
| 11b | **ISCA do range** (`402810c2..HEAD -- resources/js tests/js`) | ✅ 4 arquivos / **1789 inserções** — o vazio dos outros **não é por construção** |
| 12 | diff vazio nos 4 manifests (`composer.*`, `package*.json`) | ✅ **nenhum pacote** (T-179-SC) |
| 13 | diff vazio em `.planning/ROADMAP.md` e `.planning/REQUIREMENTS-v26.md` | ✅ **não tocados** |
| 14 | `STATE.md` aditivo | ✅ `git diff --numstat` = **11 / 0** |
| 15 | PHP | ⚠️ **NÃO rodado, e de propósito:** `git diff --stat 402810c2..HEAD -- app routes database` é **vazio**, logo nada de PHP pode ter mudado. Prova por diff, não por afirmação |

### ⚠️ O gate 4 é o que o briefing avisou que é VAZIO AO CONTRÁRIO — as 2 remoções, cada uma com o seu `+`

| removida (`tests/js/publicador-vocabulario-novo.test.js`) | acrescentada, no MESMO teste |
|---|---|
| `assert.ok(corpo !== -1, 'container do corpo (max-w-[1240px]) não encontrado');` | `assert.ok(corpo !== -1, \`container do corpo ("${marca}") não encontrado em ${nome}\`);` |
| `assert.ok(header < corpo, \`…com o px-8 dele o -mx-6 não encosta nas bordas\`);` | `assert.ok(header < corpo, \`…com o padding dele o -mx-6 não encosta nas bordas\`);` |

**O predicado é o MESMO nas duas** (`corpo !== -1` e `header < corpo`); o que mudou é o **literal com
que cada página é localizada**, que virou a tabela `CONTAINER_POR_PAGINA`. E o teste ficou **mais
forte**, não mais fraco: ganhou `assert.ok(marca, …)` por página **e** uma asserção nova provando que
**toda** página com header tem container declarado — nenhuma passa a ser medida por omissão.
**M03 reprova** quem mexer na medida da Visão geral.

⚠️ As outras 2 remoções de `assert` no range da **fase** (`898d68c2..HEAD`) são **PHP, de planos
anteriores** (`ListaPorFaseTest`), não deste plano.

---

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-44 Tampering (tela preta de 07/10) | **mitigado** | `objeto()` em **toda** prop; render REAL da **PÁGINA COMPLETA** com cada uma das 23 props **objeto · nula · ausente** mais **5 formas de lixo** (`{a:1}`, `[1,2]`, `7`, `'x'`, `true`), com `cabecalho: null`, com `{}` e com o mínimo de `publicador-header-nas-paginas`; `doesNotMatch(/\[object Object\]\|NaN\|undefined\|Invalid Date/)` em todos |
| T-179-45 Repudiation (informação que sumiu sem decisão) | **mitigado** | o inventário REG-01 como **constante no teste**, uma asserção por linha, com isca de que os 3 literais de "outro caminho" existem no painel antigo; e as duas remoções **registradas com data e literalidade** |
| T-179-58 Repudiation (bloco restaurado por engano) | **mitigado** | negativa com as props **preenchidas** (provado que o fixture as carrega), com a **data da decisão na mensagem de asserção**; **M10 e M10b** reprovam; as duas colisões de literal medidas e tratadas |
| T-179-46 Tampering (ordem de empilhamento) | **mitigado** | varredura por `z-` com **lookahead** de `]`/espaço/aspa/fim nas **13** fontes, cobrando ≤ 30, **mais** a varredura do HTML renderizado; **isca** provando que o extrator lê 30 em `z-30` e não 3; **M14** reprova |
| T-179-47 Repudiation (gate que passa por omissão) | **mitigado** | os 12 na lista **mais** o teste que compara com a listagem **real** de `identidade/` e `visaogeral/`; **M12 e M12b** reprovam |
| T-179-48 Tampering (layout do sistema) | **mitigado** | neutralização **na página**, com a aritmética escrita; gate de diff vazio em `AppLayout.jsx`; e o gate que cobra que o `-mt-6` fica no **header** e **não** na página |
| T-179-49 Information Disclosure (`quemPublicou` no payload) | **accept** | a prop continua chegando e **nada a renderiza** (asserção negativa de que o cockpit nem a desestrutura); ela já não expunha nome nem id de cliente, só o rótulo agregado (T-173-09) |
| T-179-50 Repudiation (dado real marcado como exemplo) | **mitigado** | asserção negativa de `SeloExemplo`/`dadosDeExemplo` nos 12 (lida **sem comentário**, porque `FaixaDeDesempenho.jsx:35` **menciona** `SeloExemplo` justamente para explicar por que não leva pilha), com **isca** de que os dois módulos existem e ainda têm consumidor; a mensagem manda **REAPONTAR**, nunca apagar; **M15** reprova |
| T-179-SC Tampering (pacote) | **mitigado** | **nenhum pacote**; diff vazio nos 4 manifests |

---

## ⚠️ Para o plano 179-10 — o que você precisa saber

**1. Os conjuntos de classe que este gate TRAVOU** (o 179-10 vai medir os valores no CSS construído):

```
wrapper externo : -mx-6 -mb-6
container       : mx-auto flex max-w-[1600px] min-w-[1240px] flex-col gap-[20px] px-[36px] pt-[24px] pb-[80px]
grade 3         : grid grid-cols-[minmax(0,1fr)_392px] items-start gap-[20px]
coluna lateral  : flex flex-col gap-[20px] whitespace-nowrap
grade 5         : grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-start gap-[20px]
legenda         : m-0 text-[12px] text-pub-txt-4
```

⚠️ **Todas as 13 já foram conferidas no CSS construído** (com o escape de barra invertida do seletor
e com 3 iscas ausentes). O seletor da grade sai como
`.grid-cols-\[minmax\(0\,1fr\)_392px\]` — ⚠️ o `_` **não** é escapado (é caractere de palavra), e
escapá-lo dá **falsa falta**.

**2. `PainelVisaoGeral.jsx` ficou ÓRFÃO DECLARADO.** Saiu do import da página; o arquivo **não** foi
tocado. Os dois gates que o renderizam direto (`publicador-visao-geral-render`, 33 casos, e
`publicador-dashboard-v2`, 56 casos) seguem verdes **sem uma linha de cirurgia** — é o mesmo desenho
de `BarraDaConta`/`AbasDaConta` na Fase 177. **Não apague.**

**3. O `8.5` já está em `TAMANHOS_PERMITIDOS`** e os **12** arquivos já estão em `ARQUIVOS_NOVOS`,
com o teste de listagem do diretório. Arquivo novo em `visaogeral/` **reprova** até entrar na lista
à mão.

**4. A legenda do recorte é uma ADIÇÃO ao protótipo**, exigida pelo critério 1. Se o fechamento medido
comparar a tela com o protótipo elemento a elemento, **ela vai sobrar** — e é intencional.

---

## Known Stubs

**Nenhum.** Nada na composição é constante, placeholder ou valor de exemplo: os 11 blocos recebem o
retorno dos serviços da wave 1 sem transformação, e as duas URLs são montadas por `route()` do Ziggy.
As ausências que aparecem na tela são **decisões declaradas** das waves anteriores: `banner` nulo com
token ativo, `desempenho.url` nulo (a tela Desempenho é a Fase 182) e os CTA que não nascem sem
destino (EXMP-01).

---

## Perguntas em aberto para o usuário

1. ⚠️ **A conferência visual é sua, em produção, na conta 459** (Task 3 do 179-10). **Nenhuma tela foi
   vista renderizada** — não há navegador neste ambiente e o banco local não tem empresa com
   `ml_token`.
2. ⚠️ **"Produtos por fase" e "Quem publicou" NÃO estão mais na tela**, conforme sua decisão de
   **12/10/2026**. Se ao ver a tela você sentir falta de algum dos dois, **diga** — restaurar exige uma
   decisão nova sua, e está tudo registrado para isso ser barato.
3. **A decisão de "Prontos para a Fase 2" continua pendente** (aberta pelo 179-02, repetida pelo
   179-05): o anel da Jornada e a ação mostram números **defensáveis e diferentes** para o mesmo
   conceito, e a sub-lista de uma ação mostra 2 itens sob um cabeçalho que diz 1. Esta composição **põe
   as duas fontes na mesma tela**, o que torna a divergência **visível**.
4. **`quemPublicou` custa 1 consulta + 1 `User::find()` por publicação** e não tem mais render. Vale
   tirar a prop (cirurgia em 14 asserções) ou fica?
5. **O N+1 por publicação** e o **flake de horário do `CockpitDaContaTest`** (as duas dívidas do
   `deferred-items.md`) continuam abertos.
6. **Nada deployado, nada empurrado, nada no VPS.** A leitura de produção segue bloqueada nesta sessão.

---

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/visaogeral/CockpitDaConta.jsx` — FOUND (220 linhas)
- `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` — FOUND (82 linhas, modificado)
- `tests/js/publicador-visao-geral-cockpit.test.js` — FOUND (1.384 linhas, 122 asserções, 135 testes)
- `tests/js/publicador-vocabulario-novo.test.js` — FOUND (modificado, 245 testes)
- `.planning/STATE.md` — FOUND (bloco aditivo, 11 / 0)
- commits `9238452a`, `c0b76700`, `100f9cc4`, `a346c1f6`, `cdecd846` — FOUND em `git log`
- `resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx` — FOUND (intacto, órfão declarado)
- `manifest.json` → `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` — FOUND (lido pelo JSON)
- os três arquivos de código **idênticos ao commit** depois das 23 mutações (`sha256` conferido em
  cada rodada e no fim, com o harness abortando se divergisse)
