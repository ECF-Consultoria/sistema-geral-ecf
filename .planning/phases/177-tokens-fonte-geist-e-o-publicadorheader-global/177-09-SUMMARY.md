---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 09
subsystem: ui
tags: [publicador, fechamento-de-fase, vite-build, manifest, baselines, gate-de-diff, fidelidade-de-pixel, checkpoint]

# Grafo de dependências
requires:
  - "177-01: a camada `pub-*` e as 4 baselines medidas antes de qualquer edição (a régua de GATE-04)"
  - "177-02: os 7 componentes de identidade + `identidade.js` e o gate GATE-03"
  - "177-03: a prop `cabecalho` nas 9 páginas e o endpoint do seletor de empresa"
  - "177-04: a casca do `PublicadorHeader` (sticky, marca, busca ⌘K, Configurações, CTA)"
  - "177-05: as 4 abas-pílula com contadores reais"
  - "177-06: o seletor de empresa, a pill/popover de conexão e o conserto dos 10 traços de ícone"
  - "177-07: o header nas 6 páginas de `Pages/Mlb/Publicador/` e os dois `sticky top-[108px]`"
  - "177-08: o header nas 3 páginas de `/mlb/anuncios` e as sub-abas em componente próprio"
provides:
  - "`npm run build` verde e as 11 entradas conferidas no `public/build/manifest.json` PELO JSON"
  - "As 4 baselines re-medidas no fechamento: JS 2789 (1 fail pré-existente), Publicador 1777/0, Phase134 37/0, Phase135 243/0"
  - "Os 8 gates de diff de `b36037f9..HEAD` com o resultado colado, mais a prova de que nenhum pacote entrou"
  - "A tabela FID-01 CONSOLIDADA das 20 dimensões, por área, com o valor implementado conferido no código"
  - "As 21 medidas em que o PROTÓTIPO venceu o plano, com `arquivo:linha` do protótipo"
  - "A lista fechada de divergências declaradas, separando decisão do usuário × falta de dado × restrição técnica"
  - "O inventário REG-01 das 9 páginas, conferido item a item na fonte (0 falhas em 77 conferências)"
  - "A cirurgia de gates consolidada: 13 asserções alteradas = 9 reapontadas + 4 endurecidas, ZERO removidas"
  - "O rastro dos 61 REQ-IDs da fase (entregues × parciais, com o motivo)"
affects: [178, 179, 180, 181, 182]

# Rastreio técnico
tech-stack:
  added: []   # T-177-SC: a fase 177 INTEIRA não instalou um único pacote — provado por diff vazio dos 4 arquivos de pacote
  patterns:
    - "Manifest do Vite conferido por `require()` do JSON, nunca por `grep`: o hash do arquivo sai com hífen e o grep passa pelo motivo errado"
    - "Token só existe para a tela quando GERA utilitário: sonda das 42 classes no CSS construído, com o escape do Tailwind (`[` → `\\[`)"
    - "Gate de diff SEMPRE com range `<commit-de-partida>..HEAD`: sem o range o diff sai vazio por construção e não prova nada"
    - "Fechamento de fase que mede, consolida e NÃO corrige: defeito achado aqui vira pergunta ao usuário, não commit"

key-files:
  created:
    - .planning/phases/177-tokens-fonte-geist-e-o-publicadorheader-global/177-09-SUMMARY.md
  modified: []   # `public/build/` é gitignored neste repo; nenhum arquivo de código foi tocado neste plano

key-decisions:
  - "Nenhuma linha de código de produção foi alterada: o papel deste plano é medir e consolidar. Os dois achados visuais viraram pergunta ao usuário, não conserto"
  - "`public/build/` é gitignored (`.gitignore:41` → `/public/build`), conferido por `git check-ignore -v`: os artefatos do build NÃO entram em commit"
  - "A sonda de CSS acusou 19 classes 'FALTANDO' na primeira rodada e eu NÃO registrei isso como defeito antes de conferir: o erro era do escape da minha sonda. Na segunda rodada, 42 de 42 presentes"
  - "O `h-[34px]` conta 6 na FONTE e 8 no HTML renderizado (as 4 pílulas saem de UMA ocorrência no `.map()`): o número do gate é o do render, e os dois estão certos"
  - "A divergência de CONTAGEM de páginas foi registrada em vez de silenciada: o REQUIREMENTS-v26 diz 11 páginas, a medição por `<BarraDaConta|<AbasDaConta` deu 9"

patterns-established:
  - "Inventário de regressão conferido por script sobre a FONTE das 9 páginas (77 conferências), não por leitura das SUMMARYs anteriores"
  - "Contagem de cirurgia de gates medida no diff (`git diff | grep '^-' | grep assert`), não declarada: 13 linhas de asserção saíram e todas as 13 têm substituta conferida"

requirements-completed: [FID-01, FID-02, FID-04, FID-05, GATE-01, GATE-02, GATE-03, GATE-04, GATE-05, GATE-06, REG-01, REG-02, REG-03, REG-04, REG-05, REG-06, REND-05, HEAD-11, HEAD-15]

# Métricas
duration: ~70min
completed: 2026-10-12
---

# Fase 177 Plano 09: fechamento da fase — Summary

**A fase fecha medida: `npm run build` verde com as 11 entradas conferidas no manifest pelo JSON (e as 42 classes do design novo provadas presentes no CSS construído), as 4 baselines sem uma única regressão, os 8 gates de diff vazios no range do commit de partida, nenhum pacote instalado em 8 planos, o inventário das 9 páginas conferido item a item na fonte com 0 falhas em 77 conferências, 13 asserções de gate alteradas das quais ZERO foram removidas — e a tabela FID-01 consolidada das 20 dimensões, com as 21 medidas em que o protótipo venceu o plano e as 12 divergências declaradas separadas por tipo de restrição.**

⚠️ **Eu NÃO vi tela renderizada.** Toda afirmação aqui é sobre **fonte, build e teste**. A conferência visual é do usuário, em produção, na conta 459, depois de um deploy que **só ele** autoriza. Este plano **não deployou, não deu push e não tocou no VPS**.

## Performance

- **Duração:** ~70 min
- **Concluído:** 2026-10-12
- **Tarefas:** 2 de 2 automáticas concluídas; a 3ª é o **checkpoint humano** (fim da fase)
- **Arquivos de código alterados:** **nenhum** — o papel deste plano é medir e consolidar

---

## 1. Build e manifest — conferidos PELO JSON

```
$ npm run build
✓ built in 37.74s
```

Conferência com `node --input-type=commonjs` (o `package.json` é `"type":"module"`), lendo o
`manifest.json` como objeto. ⚠️ **Nunca por `grep`**: o hash do arquivo sai com hífen
(`Produtos-Cp-R1IhK.js`) e um `grep` do nome passa pelo motivo errado. ⚠️ **Página React fora do
manifest SOME da aplicação** — tela branca em produção, sem erro no servidor.

| # | Entrada no manifest | Arquivo gerado |
|---|---|---|
| 1 | `Pages/Mlb/Publicador/VisaoGeral.jsx` | `assets/VisaoGeral-D89Yf5mR.js` |
| 2 | `Pages/Mlb/Publicador/Produtos.jsx` | `assets/Produtos-Cp-R1IhK.js` |
| 3 | `Pages/Mlb/Publicador/Produto.jsx` | `assets/Produto-D6P598Bh.js` |
| 4 | `Pages/Mlb/Publicador/Alavancas.jsx` | `assets/Alavancas-o1bV1Gho.js` |
| 5 | `Pages/Mlb/Publicador/Configuracoes.jsx` | `assets/Configuracoes-CwqGIUGm.js` |
| 6 | `Pages/Mlb/Publicador/PublicacaoEmLote.jsx` | `assets/PublicacaoEmLote-gdvIdLO0.js` |
| 7 | `Pages/Mlb/MeusAnuncios.jsx` | `assets/MeusAnuncios-BmDNVOHD.js` |
| 8 | `Pages/Mlb/AnunciosHistorico.jsx` | `assets/AnunciosHistorico-CiadSOju.js` |
| 9 | `Pages/Mlb/AnunciarMassa.jsx` | `assets/AnunciarMassa-R1lbb0lz.js` |
| 10 | `Pages/Mlb/AnunciosEmpresas.jsx` | `assets/AnunciosEmpresas-CyVpQz62.js` |
| 11 | `resources/js/app.jsx` | `assets/app-Bm6M2Nwa.js` |

**`manifest ok 11`** · 479 entradas no manifest inteiro · **0 fora**.

> As 9 primeiras são as páginas que trocaram o cabeçalho. A 10ª (`AnunciosEmpresas.jsx`) entrou na
> lista porque o plano 177-06 trocou 4 definições locais dela por um import do módulo único de
> contas recentes — ela **não** monta o header, mas foi tocada e tinha de ser conferida.

### Prova extra: os tokens geram CSS de verdade

Token que não gera classe é token que não existe para a tela. Sondei o CSS construído
(`assets/app-BGqs_iRL.css`, 211.205 bytes) com o escape que o Tailwind usa no seletor (`[` → `\[`):

**42 sondas · 42 presentes · 0 faltando.** Entre elas: `font-pub`, `font-pub-mono`,
`bg-pub-page/[0.86]`, `bg-pub-accent/[0.14]`, `shadow-pub-cta`, `shadow-pub-popover`,
`shadow-pub-aba-ativa`, `animate-pub-spin`, `rounded-pub-control/sub/panel/badge` — e as medidas
arbitrárias do protótipo, que são o coração de FID-03: `h-[60px]`, `h-[48px]`, `h-[34px]`,
`h-[38px]`, `h-[46px]`, `h-[28px]`, `top-[108px]`, `w-[250px]`, `w-[360px]`, `w-[320px]`,
`text-[13.5px]`, `text-[14.5px]`, `text-[10.5px]`, `text-[11.5px]`, `min-w-[1240px]`,
`max-w-[1600px]`, `px-[36px]`, `tracking-[-0.01em]`, `backdrop-blur-[14px]`, `bottom-[-7px]`,
`min-w-[22px]`. **Do outro lado da fronteira**, `bg-ecf-bg`, `bg-ecf-card` e `font-sans` seguem
presentes e inalterados — a camada `ecf-*` não perdeu nada.

⚠️ **Registro de um quase-erro meu, porque ele é instrutivo:** a primeira rodada da sonda acusou
**19 classes "FALTANDO"**, todas as de medida arbitrária. Eu **não** registrei isso como defeito:
fui conferir no CSS por `grep` direto e `.h-\[60px\]` **estava lá**. O erro era do escape da minha
própria sonda (barra invertida duplicada). Lição para quem repetir: ao sondar CSS do Tailwind, o
seletor tem **uma** barra invertida antes de `[`, `]`, `.` e `/` — e um "FALTA" em lote é quase
sempre defeito da sonda, não do build.

---

## 2. As 4 baselines — re-medidas por mim no fechamento

Comparadas com os números **MEDIDOS no 177-01** (não com os declarados no REQUIREMENTS).

| # | Suíte | Baseline medida (177-01, árvore em `490216f9`) | **Agora** | Leitura |
|---|---|---|---|---|
| 1 | JS inteiro (`npm run test:js`) | 2210 · 27 suites · 2209 pass · **1 fail** | **2789 · 27 suites · 2788 pass · 1 fail** | **+579 testes** nos 8 planos; o fail é o MESMO, sozinho |
| 2 | `tests/Unit/Publicador tests/Feature/Publicador` | 1761 · 0 fail · 10813 asserções | **1777 · 0 fail · 11023 asserções** | **+16 casos** (os do contrato do 177-03), +210 asserções, **0 falhas** |
| 3 | `tests/Feature/Phase134` | 37 · 0 fail · 152 asserções | **37 · 0 fail · 152 asserções** | idêntico |
| 4 | `tests/Feature/Phase135` | 243 · 0 fail · 1122 asserções | **243 · 0 fail · 1122 asserções** | idêntico |

**Nenhum fail novo em nenhuma das quatro. A fase não produziu uma regressão de teste.**

### O único fail, nomeado

```
✖ Características secundárias nasce recolhido (é o grupo que mais infla)
  tests/js/estrutura-grade-glide.test.js:123
  esperado: /RECOLHIDOS_INICIAIS = \[G_SECUND\]/   achado: RECOLHIDOS_INICIAIS = []
```

**Pré-existente**, de outro território (`GradeAnuncioGlide.jsx`) e de outra fase. Já falhava na
baseline do 177-01, em `490216f9`, antes da primeira edição desta fase. ⚠️ **Não corrigido, de
propósito** — consertá-lo aqui misturaria o resultado da fase com trabalho alheio.

### Deprecations do PHPUnit (não são falhas)

46 na suíte do Publicador, 40 na Phase134, 92 na Phase135 — todas **pré-existentes** e idênticas às
contadas no 177-01. Nenhuma foi introduzida por esta fase (a fase tocou 6 arquivos PHP, todos no
caminho de leitura de props).

---

## 3. Os 8 gates de diff — range `b36037f9..HEAD`

⚠️ **Com o range, sempre.** Sem ele o `git diff` olha a árvore de trabalho e sai **vazio por
construção** — passa sem provar nada. Esse erro aconteceu nesta sessão e foi pego por um executor.

| # | Gate | Comando | Resultado |
|---|---|---|---|
| 1 | D2/HEAD-14 — o `AppLayout` não muda | `git diff --stat b36037f9..HEAD -- resources/js/Layouts/AppLayout.jsx` | **VAZIO** ✔ |
| 2 | REG-03 — o editor não é tocado | `… -- resources/js/Pages/Mlb/Publicador/Editor.jsx` | **VAZIO** ✔ |
| 3 | REG-03 — a mesa do editor não é tocada | `… -- resources/js/Components/Publicador/Mesa` | **VAZIO** ✔ |
| 4 | REG-04 — `App\Support\Publicador` não muda | `… -- app/Support/Publicador` | **VAZIO** ✔ |
| 5 | REG-04 — `MlPublicacaoService` não muda | `… -- app/Services/Mlb/Publicacao/MlPublicacaoService.php` | **VAZIO** ✔ |
| 6 | Ordem do usuário — estado é do orquestrador | `… -- .planning/ROADMAP.md .planning/REQUIREMENTS-v26.md` | **VAZIO** ✔ |
| 7 | REG-05 — só a rota nova | `git diff b36037f9..HEAD -- routes/` | **9 adições, 0 remoções** — só `publicador.empresas-header` ✔ |
| 8 | Sem migration | `… -- database/migrations` **e** `… -- database` | **VAZIO** nos dois ✔ |

⚠️ **Os gates 4 e 5 só provam algo porque o alvo existe.** Conferido: `app/Support/Publicador` é um
diretório real e `app/Services/Mlb/Publicacao/MlPublicacaoService.php` é um arquivo real. Gate que
aponta para caminho inexistente passa por omissão — não é o caso de nenhum dos oito.

### T-177-SC — nenhum pacote entrou na fase

```
git diff b36037f9..HEAD -- package.json package-lock.json composer.json composer.lock
```
**VAZIO nos quatro.** A fase 177 inteira, em 8 planos, **não instalou um único pacote**. Tudo o que
ela usa já era dependência: `lucide-react`, `@radix-ui/react-popover`, `axios`. A Geist entra por
`<link>` no `app.blade.php`, no **mesmo host** (`fonts.googleapis.com`) que a página já usava para
Inter/Manrope — nenhuma origem de terceiro nova. **O gate de legitimidade de pacote não é devido.**

### O diff total do range

**73 arquivos · 14.581 adições · 213 remoções · 0 arquivos deletados.**

| Território | Arquivos | Nota |
|---|---|---|
| `.planning/phases/177-…/` | 18 | os 9 planos, as 8 SUMMARYs e o `deferred-items.md` |
| `app/Http/Controllers/` | 5 | a prop `cabecalho` nas 9 páginas (177-03) |
| `app/Services/Publicador/` | 2 | `CabecalhoPublicadorService` (novo) + `contasLeves()` (só adição) |
| `routes/` | 1 | só a rota nova |
| `tailwind.config.js` · `resources/views/app.blade.php` | 2 | a camada `pub-*` (107 linhas, só adição) e o `<link>` da Geist (9 linhas) |
| `resources/js/Components/Mlb/Publicador/` | 16 | 15 arquivos novos + o export `textoUtil` em `BarraDaConta.jsx` |
| `resources/js/Pages/Mlb/` | 10 | as 9 páginas + `AnunciosEmpresas.jsx` |
| `tests/` | 19 | 1 PHP novo + 8 gates novos + 10 gates reapontados |

⚠️ **Nenhum arquivo do outro dev aparece no diff do range.** `Editor.jsx`,
`Components/Publicador/Mesa/`, `AnunciarML.jsx` e `Tarefas.jsx` estão todos vazios no range —
conferidos um a um. **Não houve nada a reverter nem a avisar.**

⚠️ **`public/build/` é gitignored neste repo** (`git check-ignore -v` → `.gitignore:41:/public/build`).
Os artefatos do build **não entram em commit** e `git diff b36037f9..HEAD -- public/build` é vazio
por isso, não por omissão.

### Árvore limpa

- `git status --short -- tests/js` → **vazio**: nenhuma fixture órfã (`.*stub*.mjs`) sobrou das
  centenas de mutações das 8 waves.
- `git status --short | grep -v '^??'` → **vazio**: nenhum arquivo rastreado modificado. A árvore
  está limpa e pronta para o deploy que o usuário autorizar.

---

## 4. Tabela FID-01 CONSOLIDADA — as 20 dimensões, por área

Protótipo = `new_layout_publicador/PublicadorHeader.dc.html` (linha citada) + `Publicacoes v2.dc.html`
+ `Visao Geral.dc.html` + `Desempenho.dc.html` + `README.md`.

⚠️ **A coluna "Implementado" foi conferida no CÓDIGO, com script, não copiada dos planos.**
Script de conferência: **57 sondas positivas + 11 negativas = 68 conferências, 0 falhas reais**
(duas sondas minhas estavam erradas — `strokeWidth="1.4"` é `strokeWidth={1.4}` em JSX, e
`text-[14.5px]` aparece **2×** porque o nome da conta tem dois ramos, com conta e sem conta —
as duas confirmadas corretas no arquivo).

### A. Casca do header — `PublicadorHeader.jsx`

| # | Dimensão | Protótipo | Implementado (conferido) |
|---|---|---|---|
| 1 | largura | container `min-width:1240px` (:21) · conteúdo `max-width:1600px` (:22) · busca `250px` (:94) · Configurações `34px` (:99) · separador `1px` (:102) · tecla `min-width:20px` (:97) | `min-w-[1240px]` ×1 · `max-w-[1600px]` ×1 · `w-[250px]` ×1 · `w-[34px]` · `w-px` · `min-w-[20px]` |
| 2 | altura | linha 1 `60px` (:23) · nav `48px` (:109) · 4 controles `34px` (:25/94/99/103) · tecla e separador `20px` | `h-[60px]` ×1 · `h-[48px]` ×1 · `h-[34px]` (**8 no HTML renderizado**, contagem travada) · `h-[20px]` |
| 3 | grid | `flex; align-items:center; justify-content:space-between` (:23) | `flex items-center justify-between` |
| 4 | spacing | `margin:0 auto` (:22) · separador `margin:0 4px` (:102) | `mx-auto` · `mx-[4px]` |
| 5 | padding | container `0 36px` (:22) · marca `0 10px 0 4px` (:25) · busca `0 6px 0 12px` (:94) · CTA `0 14px 0 12px` (:103) · tecla N `1px 5px` (:105) | `px-[36px]` ×1 · `pl-[4px] pr-[10px]` · `pl-[12px] pr-[6px]` · `pl-[12px] pr-[14px]` · `px-[5px] py-px` |
| 6 | gap | linha 1 `20px` · esquerda `10px` · direita `8px` · marca `9px` · busca `10px` · teclas `3px` · CTA `7px` · nav `2px` | `gap-5` · `gap-[10px]` · `gap-2` · `gap-[9px]` · `gap-[10px]` · `gap-[3px]` · `gap-[7px]` · `gap-[2px]` |
| 7 | tipografia — família | UI Geist (:21) · teclas e badge N em Geist Mono (:97/105) | `font-pub` **1× no `<header>`** (cobre toda a subárvore) · `font-pub-mono` (**4 no HTML**: ⌘, K, badge N, código da conta) |
| 8 | tamanho | marca `14px` (:30) · busca `13px` · teclas `10.5px` · CTA `13px` · badge N `10.5px` | `text-[14px]` ×1 · `text-[13px]` · `text-[10.5px]` (**4 no HTML**) · `text-[13px]` |
| 9 | peso | marca `600` · CTA `600` · busca 400 (sem declaração) | `font-semibold` · `font-semibold` · sem classe de peso |
| 10 | tracking | marca `-0.01em` (:30) | `tracking-[-0.01em]` ×1 |
| 11 | raio | 4 controles `9px` · tecla `5px` · badge N `4px` | `rounded-pub-control` (**8 no HTML**) · `rounded-[5px]` · `rounded-pub-badge` |
| 12 | bordas | header `1px #1C1C1C` (:21) · busca `1px #222222` (:94) · tecla `1px #2E2E2E` · CTA sem borda | `border-b border-pub-line-subtle-2` ×1 (exato) · `border border-pub-line` ⚠️ **divergência T-1** · `border border-pub-line-strong` (exato) · sem borda |
| 13 | sombras | CTA `0 0 0 1px rgba(250,204,21,.25), 0 6px 20px rgba(250,204,21,.12)` (:103) · header sem sombra | `shadow-pub-cta` ×1 (o token é byte a byte esse valor) · nenhuma |
| 14 | cores | fundo `rgba(10,10,10,0.86)` + `saturate(140%) blur(14px)` (:21) · texto `#F4F4F5` · busca `#111111`/`#8A8A90` · CTA `#FACC15`/`#0A0A0A`, hover `#EAB308` · hover marca `#141414` · hover config `#161616` · separador `#333333` (:32) · badge N `rgba(10,10,10,0.14)` | `bg-pub-page/[0.86] backdrop-blur-[14px] backdrop-saturate-[1.4]` · `text-pub-txt` · `bg-pub-surface text-pub-txt-3` · `bg-pub-accent text-pub-page` + `hover:bg-pub-accent-hover` · `hover:bg-pub-hover` · `hover:bg-pub-hover-2` · `stroke-pub-seg-ring` · `bg-pub-page/[0.14]` — **todos por nome de token; `#FACC15` literal: 0 ocorrências no arquivo** |
| 15 | alinhamento | tudo centrado na vertical · busca à esquerda com `flex:1` (:96) | `items-center` · `flex-1 text-left` |
| 16 | ícones | busca 14px/1.6 (:95) · Configurações 17px/1.4 (:100) · "+" 14px/2 (:104) · separador "/" 16×22/1.4 `M11 3L5 19` (:32) | Lucide `Search 14 strokeWidth={2.4}` ×1 · `Settings 17 strokeWidth={2.1}` ×2 (ramo com conta e ramo desabilitado) · `Plus 14 strokeWidth={3}` ×1 · **SVG inline idêntico com `viewBox="0 0 16 22"` e `strokeWidth={1.4}`** — ver a conta do traço na área G |
| 17 | badges | tecla "N" mono 10.5px sobre `rgba(10,10,10,0.14)`, raio 4px, `margin-left:2px` (:105) | `ml-[2px] rounded-pub-badge bg-pub-page/[0.14] px-[5px] py-px font-pub-mono text-[10.5px]` |
| 18 | tabelas | — | n/a (a casca não desenha tabela) |
| 19 | estados | hover nos 4 controles · header fixo no scroll (README §0) | as classes `hover:` acima · `sticky top-0 z-50 -mx-6 -mt-6` ×1 |
| 20 | densidade / CTAs | CTA primário no canto direito da linha 1, depois do separador · Configurações FORA das abas (:99-106) | ordem travada por teste: busca → Configurações → separador → CTA |

### B. Marca e glyph de metodologia — `identidade/GlyphDaFase.jsx`, `RotuloDaFase.jsx`

| # | Dimensão | Protótipo | Implementado (conferido) |
|---|---|---|---|
| 1-2 | largura / altura | glyph lista `18×18` (`Publicacoes v2`:225/228/231) · marca 24px · rodapé 16px · retângulos 10×13 / 15×18 / 9×12 | `w-[18px]` ⚠️ **P-3** · `w-[24px]` · `w-[16px]` · `h-[13px] w-[10px]` / `h-[18px] w-[15px]` / `h-[12px] w-[9px]` |
| 4 | spacing | offset do 2º retângulo 5px/3px (lista, rodapé) · 8px/6px (marca) | `left-[6px] top-[4px]` (a partir de `left-[1px] top-[1px]`, = 5/3) · `left-[5px] top-[3px]` · `left-[8px] top-[6px]` |
| 6 | gap | rótulo glyph→texto `10px` na lista (`Publicacoes v2`:223), `9px` na marca (:25) | `gap-[10px]` ⚠️ **P-8** · `gap-[9px]` |
| 8-9 | tamanho / peso | fase `13px`/500 · complemento `11.5px` | `text-[13px] font-medium` · `text-[11.5px]` |
| 11-12 | raio / bordas | glyph `2.5/4/2px` · bordas `1.5/1.6/1.4px` · Legado tracejada `#71717A` | `rounded-[2.5px]`/`[4px]`/`[2px]` · `border-[1.5px]`/`[1.6px]`/`[1.4px]` · `border-dashed border-pub-txt-4` |
| 14 | cores | borda do glyph `#D4D4D8` nas 7 instâncias · marca em `#FACC15` (README §0 + :27-28) · fundo de occlusão `#111111` (lista) / `#0D0D0D` (rodapé) | `border-pub-fase1` ×2 ⚠️ **P-1** · `border-pub-accent` ×1 · `bg-pub-surface` / `bg-pub-sunken` ⚠️ **P-2** |
| 19 | estados | 1 retângulo · 2 sobrepostos · 1 tracejado | 3 formas distintas, `aria-hidden` sempre (quem nomeia é o rótulo); **fase ausente escreve "—", nunca "Legado"** |
| 20 | densidade | literais `"Fase 1" · "Simples · 1 un."` / `"Fase 2" · "Combo · N un."` / `"Legado"` | byte a byte, com o ponto medial e o "un." com ponto; quantidade inválida → **"Combo"** sozinho |

### C. Seletor de empresa — `header/SeletorDeEmpresaDoHeader.jsx`

| # | Dimensão | Protótipo | Implementado (conferido) |
|---|---|---|---|
| 1 | largura | dropdown `360px` (:43) · tile do botão `28px` (:35) · tile do item `30px` (:54) · dot do item `6px` · bolinha de status `10px` (:36) | `w-[360px]` ×1 · `w-[28px]` · `w-[30px]` · `w-[6px]` · `w-[10px]` |
| 2 | altura | botão `38px` (:34) · input `38px` (:46) · item `46px` (:53) · lista `max-height:340px` (:48) | `h-[38px]` ×3 ⚠️ **P-R** · `h-[46px]` ×1 · `max-h-[340px]` ×1 |
| 3 | grid | item em `flex` com nome/meta em coluna (`gap:2px; min-width:0; flex:1`, :55) e check à direita (:59) · tiles `grid; place-items:center` | `flex` + `flex min-w-0 flex-1 flex-col gap-[2px]` + check no fim · `grid place-items-center` |
| 4 | spacing | bolinha `right:-3px; bottom:-3px` (:36) · dropdown `top:46px` = 38+8 (:43) | `right-[-3px] bottom-[-3px]` · `sideOffset={8}` |
| 5 | padding | botão `0 10px 0 5px` (:34) · busca `10px` (:44) · input `0 12px 0 36px` (:46) · lista `6px` · item `0 10px` · grupo `10px 10px 6px` · rodapé `10px 16px` | `pl-[5px] pr-[10px]` · `p-[10px]` · `pl-[36px] pr-[12px]` · `p-[6px]` · `px-[10px]` · `px-[10px] pb-[6px] pt-[10px]` · `px-[16px] py-[10px]` |
| 6 | gap | botão `10px` · item `10px` com meta `6px` · coluna nome/meta `2px` (:55) | `gap-[10px]` · `gap-[10px]` + `gap-[6px]` · `gap-[2px]` ⚠️ **P-5** |
| 7 | família | Geist; código da conta e do item em Geist Mono (:39/:57) | `font-pub` no `Popover.Content` (o Radix porta para o `document.body`, sai da subárvore do `<header>`) · `font-pub-mono` ×2 |
| 8 | tamanho | nome `14.5px` (:38) · código `11px` · tile do botão `10.5px` (:35) · input `13.5px` · grupo `11px` · nome do item `13.5px` · meta `11.5px` · tile do item `11px` (:54) | `text-[14.5px]` ×2 (ramo com conta e sem conta) · `text-[11px]` · `text-[10.5px]` · `text-[13.5px]` ×2 · `text-[11.5px]` · `text-[11px]` ⚠️ **P-3c** |
| 9 | peso | nome `600` · tile do botão `700` · grupo `600` · nome do item `500` · tile do item `600` (:54) | `font-semibold` · `font-bold` · `font-semibold` · `font-medium` · `font-semibold` |
| 10 | tracking | nome `-0.01em` (:38) · grupo `0.08em` (:51) | `tracking-[-0.01em]` · `tracking-[0.08em]` |
| 11 | raio | botão `10px` (:34) · dropdown `14px` · input `9px` · item `9px` · os 3 tiles `8px` (:35/54/80) | `rounded-pub-sub` (10px) ⚠️ **P-1c** · `rounded-[14px]` · `rounded-pub-control` ×2 · `rounded-[8px]` ×2 ⚠️ **P-2c** |
| 12 | bordas | dropdown `1px #2E2E2E` (:43) · input idem com foco `#FACC15` · tile `1px #2E2E2E` · bolinha `2px #0A0A0A` · divisórias `1px #1F1F1F` | `border-pub-line-strong` · + `focus:border-pub-accent` · `border-pub-line-strong` · `border-2 border-pub-page` · `border-pub-line-subtle` |
| 13 | sombras | dropdown `0 24px 60px rgba(0,0,0,0.7)` (:43) | `shadow-pub-popover` ×1 (o token é byte a byte) |
| 14 | cores | painel `#111111` · rodapé e input `#0D0D0D` · hover do item `#1A1A1A` · item atual `#1A1A1A` com tile `#FACC15`/`#0A0A0A` · check `#FACC15` · tile do botão `#1F1F1F`/`#FACC15` · código `#71717A` · grupo `#71717A` · meta `#8A8A90` · "·" `#3F3F46` | `bg-pub-surface` · `bg-pub-sunken` · `hover:bg-pub-hover-3` · `bg-pub-hover-3` + `bg-pub-accent text-pub-page` · `text-pub-accent` · `bg-pub-line-subtle text-pub-accent` · `text-pub-txt-4` ×2 · `text-pub-txt-3` · `text-pub-line-off` |
| 15 | alinhamento | rodapé `justify-between` · botão e item `text-align:left` · nome com `ellipsis` (:56) · `nowrap` no botão e no item | `justify-between` · `text-left` · `truncate` · `whitespace-nowrap` ⚠️ **P-5** |
| 16 | ícones | busca `15px`/1.6 (:45, `left:22px; top:21px`) · duplo-chevron `13px`/1.7 (:40) · check `14px`/2 (:59) | `Search 15 strokeWidth={2.4}` em `absolute left-[22px] top-[21px]` ⚠️ **P-4c** · `ChevronsUpDown 13 strokeWidth={2.55}` · `Check 14 strokeWidth={3}` |
| 19 | estados | botão fechado/aberto (:225) · hover do item · atual com check | todos acima, via `cn()` com a classe condicional conferida pelo **FIM** da string (`includes` casaria o `hover:` e seria asserção vazia) |
| 20 | densidade / CTA | "Ver todas →" no rodapé, à direita (:67) · "48 empresas sob gestão" (mock, :66) | idem · **`total` REAL do endpoint**; sem total, **nenhuma** contagem é escrita. `48 empresas` literal: **0 ocorrências** |

### D. Pill e popover de conexão — `header/ConexaoDoHeader.jsx`

| # | Dimensão | Protótipo | Implementado (conferido) |
|---|---|---|---|
| 1-2 | largura / altura | popover `320px` (:77) · pill `28px` (:73) · tile `30px` (:80) · dots `7px` (:74/:82) | `w-[320px]` ×1 · `h-[28px]` ×1 · `w-[30px]` · `w-[7px]` |
| 5-6 | padding / gap | pill `0 10px 0 8px` (:73) · linha `12px 14px` (:79) · rodapé `10px 14px` (:85) · pill `gap:8px` com dots `3px` · linha `gap:12px` com status `6px` | `pl-[8px] pr-[10px]` · `px-[14px] py-[12px]` · `px-[14px] py-[10px]` · `gap-2` + `gap-[3px]` · `gap-[12px]` + `gap-[6px]` |
| 7-9 | tipografia | pill `12px`/500 · nome `13px`/500 · meta `11.5px` · tile `10px`/700 · status `12px`/500 · rodapé `12px` | `text-[12px] font-medium` · `text-[13px] font-medium` · `text-[11.5px]` · `text-[10px] font-bold` · `text-[12px] font-medium` · `text-[12px]` |
| 11-13 | raio / bordas / sombras | popover `12px` · pill e dots `999px`/`50%` · borda `1px #2E2E2E` · divisórias `#1F1F1F` · sombra `0 24px 60px rgba(0,0,0,0.7)` · spinner `1.5px` | `rounded-pub-panel` · `rounded-full` · `border-pub-line-strong` · `border-pub-line-subtle` · `shadow-pub-popover` ×1 · `border-[1.5px]` |
| 14 | cores | painel `#111111` · rodapé `#0D0D0D` · tile ERP/PT `#1F1F1F`/`#D4D4D8` · dots ok/warn/erro `#22C55E`/`#F59E0B`/`#EF4444` · textos `#4ADE80`/`#FBBF24`/`#F87171` · hover da pill `#3F3F46` · pill por estado (:216) | `bg-pub-surface` · `bg-pub-sunken` · `bg-pub-line-subtle text-pub-fase1` · `bg-pub-ok`/`bg-pub-warn`/`bg-pub-danger` · `text-pub-ok-txt`/`text-pub-warn-txt`/`text-pub-danger-txt` · `hover:border-pub-line-off` · `border-pub-danger/[0.45]` \| `border-pub-warn/[0.45]` \| `border-pub-line` |
| 16 | ícones | — | `animate-pub-spin` no spinner de "Atualizar agora" |
| 19 | estados | 3 estados da pill (:216) · 2 linhas no popover (:217-220) | 3 estados + **3 linhas** (ML, ERP, Portal) ⚠️ **D-5** · **estado NÃO medido não desenha pill nem bolinha** |
| 20 | densidade / CTA | "Atualizar agora" no rodapé, à direita (:87) | idem — e **enfileira** (D-05), volta na hora com a mensagem |

### E. Abas-pílula — `header/AbasDoHeader.jsx`

| # | Dimensão | Protótipo | Implementado (conferido) |
|---|---|---|---|
| 1-2 | largura / altura | pílula por conteúdo `34px` (:111) · contador `min-width:22px`, `18px` (:120) · dot `6px` (:121) · barra `2px`, `left/right:12px` (:122) | `h-[34px]` · `min-w-[22px]` ×1 + `h-[18px]` ×1 · `h-[6px] w-[6px]` · `h-[2px] left-[12px] right-[12px]` |
| 3 | grid | pílula `flex; align-items:center` · ícone e contador `grid; place-items:center` (:112/:120) | `flex items-center` · `grid place-items-center` ×2 |
| 4-6 | spacing / padding / gap | barra `bottom:-7px` (:122) · pílula `0 12px` · contador `0 6px` · entre abas `2px` · dentro da aba `8px` | `bottom-[-7px]` ×1 · `px-[12px]` · `px-[6px]` · `gap-[2px]` · `gap-2` |
| 7 | família | Geist herdada (:21) · contador `font-variant-numeric:tabular-nums` (:120) | **nenhuma classe de família aqui** (vem do `font-pub` do `<header>`; há asserção de que as abas não a redeclaram) · `tabular-nums` ⚠️ **P-4b** |
| 8 | tamanho | label `13.5px` (:111) · contador `11px` (:120) | `text-[13.5px]` ⚠️ **nunca `text-sm`** (`text-sm` no arquivo: 0 ocorrências) · `text-[11px]` |
| 9 | peso | ativa 600, inativa 500 (:233) · contador 600 | `font-semibold` (só a ativa) · `font-medium` (as inativas) · `font-semibold` no contador |
| 11-13 | raio / bordas / sombras | pílula `9px` · contador `999px` · dot `50%` · barra `2px` · ativa com anel **INTERNO** `inset 0 0 0 1px #262626` (:233) | `rounded-pub-control` · `rounded-full` ×2 · `rounded-[2px]` · `shadow-pub-aba-ativa` ×1 e **gate que PROÍBE a classe `border` na pílula ativa** (borda empurraria o conteúdo 1px e a pílula mediria 36px) |
| 14 | cores | ativa: fundo `#161616`, texto `#F4F4F5`, ícone e contador `#FACC15` sobre `rgba(250,204,21,0.14)` · inativa: `#8A8A90`/`#71717A`/`#1A1A1A` · hover `#151515`/`#F4F4F5` · dot `#EF4444` · barra `#FACC15` | `bg-pub-hover-2 text-pub-txt text-pub-accent bg-pub-accent/[0.14]` · `text-pub-txt-3 text-pub-txt-4 bg-pub-hover-3` · `hover:bg-pub-raised hover:text-pub-txt` (**só nas inativas** ⚠️ **D-6**) · `bg-pub-danger` · `bg-pub-accent` |
| 16 | ícones | 15px, `stroke-width:1.6`, `round` (:113-117): 4 retângulos · caixa 3D · retângulo com linhas · raio | Lucide `LayoutDashboard`, `Package`, `LayoutList`, `Zap` — `size={15} strokeWidth={2.4}`, cada um conferido pela classe que o Lucide emite, 1 ocorrência cada |
| 17 | badges | contador em pílula (:120) · dot vermelho (:121) | linhas 1/2/8/9/11/14 acima |
| 19 | estados | ativa / inativa / hover | os 3 do protótipo **+ 2 que o protótipo não tem**: D23 (sem Company) e sem conta — ambos `aria-disabled="true"` com `title`, **nunca escondidos** |
| 20 | densidade / CTAs | **5 abas** (:215) | **4 abas**: `visao-geral` · `produtos` · `publicacoes` · `alavancas` ⚠️ **D-1, decisão do usuário**. Configurações saiu das abas e virou o ícone da linha 1 (HEAD-08). `Desempenho` literal no arquivo: **0 ocorrências** |

### F. Identidade de publicação e produto — `identidade/*`

| # | Dimensão | Protótipo | Implementado (conferido) |
|---|---|---|---|
| 1-2 | largura / altura | célula do mapa 18-20px (README) → **20×20 na única instância renderizada** (`Publicacoes v2`:185) · tile 28/30/34/38/40/84px | `style={{width,height}}` com `tamanho = 20` ⚠️ **P-6** · `style={{width,height}}`, default 34 |
| 3 | grid | mapa F1/F2 × C/P em 4 células lado a lado | `flex flex-none gap-[3px]` — **nenhum `grid-cols-*` novo** (FID-05) |
| 5 | padding | badge NOVA `2px 6px` (`Publicacoes v2`:218) · tag Catálogo `1px 6px` (:243) | `px-[6px] py-[2px]` ⚠️ **P-4** · `px-[6px] py-[1px]` ⚠️ **P-5a** |
| 6 | gap | status `7px` · tipo `5px` · nome/complemento `1px` · status/motivo `2px` · tipo/Catálogo `3px` | `gap-[7px]` · `gap-[5px]` · `gap-[1px]` · `gap-[2px]` · `gap-[3px]` |
| 8-10 | tipografia | tipo `13px` · Catálogo `10.5px` · NOVA `10px`/700/`0.06em` · letra C/P `10.5px`/600 · status `13px`/500 · motivo `11.5px` · iniciais 10.5/11.5/20px | `text-[13px]` · `text-[10.5px]` · `text-[10px] font-bold tracking-[0.06em]` · `text-[10.5px] font-semibold` · `text-[13px] font-medium` · `text-[11.5px]` · `text-[10.5px]`/`[11.5px]`/`[20px]` |
| 11 | raio | célula `5px` · badge `4px` · losango `1px` · barra de pausa `1px` · tile 8/9/12px | `rounded-[5px]` ×1 · `rounded-pub-badge` · `rounded-[1px]` ×2 · `style={{borderRadius}}` 8/9/12 ⚠️ **P-7** |
| 12 | bordas | célula vazia `1px dashed #3F3F46` · célula cheia `1px solid` da própria cor · anel de revisão `1.6px` | `border border-dashed border-pub-line-off` · `border border-pub-accent`/`border-pub-fase1-2` · `border-[1.6px] border-pub-neutral-dot border-r-transparent` |
| 13 | sombras | nenhuma | **nenhuma classe de sombra** nos 8 arquivos |
| 14 | cores | célula F1 `#E4E4E7`, F2 `#FACC15`, texto escuro `#0A0A0A`, letra vazia `#52525B` · status ok/warn/danger/neutral | `bg-pub-fase1-2` · `bg-pub-accent` · `text-pub-page` · `text-pub-txt-off` · `bg-pub-ok`+`text-pub-ok-txt`, `bg-pub-warn`+`text-pub-warn-txt`, `text-pub-danger`+`text-pub-danger-txt`, `border-pub-neutral-dot`+`text-pub-neutral-txt` |
| 15 | alinhamento | glyph e texto centrados · letra C/P centrada na célula | `inline-flex items-center` · `grid place-items-center` · `inline-grid place-items-center` |
| 16 | ícones | SVG do codebase, stroke 1.4-2px | `lucide-react` (`AlertTriangle`, `Package`), `strokeWidth={1.6}` ⚠️ **ver a nota do traço na área G** |
| 19 | estados | status nunca só por cor | **4 formas distintas** (círculo cheio · duas barras · triângulo · anel aberto), provadas distintas por teste; status desconhecido → anel aberto + o valor como chegou, **nunca "Ativa" inventada** |
| 20 | densidade | — | `title` + `aria-label` com o **mesmo** texto em cada célula ⚠️ **D-11 (IDENT-07)** |

### G. O traço dos ícones — a conversão de `viewBox`

Os SVG do protótipo usam `viewBox="0 0 16 16"`; o Lucide usa `0 0 24 24`. O `stroke-width` é medido
**no sistema de coordenadas do viewBox**, então o mesmo número **não** dá o mesmo traço:

| | Conta | Traço renderizado |
|---|---|---|
| protótipo (`viewBox` 16, ícone de 15px) | `1.6 × 15/16` | **1,5 px** |
| Lucide antes (`viewBox` 24, ícone de 15px) | `1.6 × 15/24` | **1,0 px** |

**Fator: `24/16 = 1,5`.** Os ícones saíam com **dois terços** da espessura desenhada. Medido agora
na árvore `header/`:

| Traço | Ocorrências | Quem |
|---|---|---|
| `strokeWidth={2.4}` | **4** | `Search` do header · `Search` do dropdown · os ícones das 4 abas (saem de 2 ocorrências no `.map()`) |
| `strokeWidth={2.1}` | **2** | `Settings` nos dois ramos (com conta e desabilitado) |
| `strokeWidth={3}` | **2** | `Plus` do CTA · `Check` da conta atual |
| `strokeWidth={2.55}` | **1** | `ChevronsUpDown` do botão da conta |
| `strokeWidth={1.4}` | **1** | ⚠️ o **separador "/"** — SVG inline com o `viewBox` 16×22 do próprio protótipo, **fora da conta de propósito** |
| `strokeWidth={1.6}` · `{1.7}` · `{2}` | **0 · 0 · 0** | **nenhum ícone ficou para trás** |

⚠️ **A conta é aritmética; "ficou bom" é olho.** Isto está na lista de conferência visual (item 2
do checkpoint).

---

## 5. As 21 medidas em que o PROTÓTIPO venceu o PLANO

⚠️ **Esta é a seção mais importante da entrega.** A instrução do usuário, de 11/10/2026, foi:
*"reproduza fielmente o layout, já tentei fazer um redesign e não saiu como eu esperava PORQUE VOCÊ
ADAPTAVA MUITO O NOVO LAYOUT AO SISTEMA ATUAL"*. A tabela FID-01 **do plano** é artefato derivado;
o protótipo é a fonte. Em 21 medidas o plano prescreveu um valor e o protótipo mede outro —
**seguiu-se o protótipo, com `arquivo:linha` como prova**. Nenhuma destas é "adaptei ao que já
existia"; cada uma é reversível em uma linha se o usuário preferir o valor do plano.

### Wave 2 (plano 177-02) — 8 medidas, nos componentes de identidade

| # | O plano prescrevia | O protótipo tem | Implementado | Prova |
|---|---|---|---|---|
| **P-1** | borda do glyph `pub-fase1-2` (`#E4E4E7`) | `#D4D4D8` nas **7** instâncias | `border-pub-fase1` na lista e no rodapé; `border-pub-accent` na marca | `Publicacoes v2.dc.html`:225/228/231 · `Desempenho.dc.html`:137/142 · `Visao Geral.dc.html`:324. `#E4E4E7` aparece **só** na célula "existe F1" (README:101, `Publicacoes v2`:569) — e lá é `pub-fase1-2`. **O plano conflatou a cor da célula com a cor da borda.** |
| **P-2** | fundo de occlusão `bg-pub-page` | rodapé do modal `#0D0D0D`; tabela `#111111`; marca **sem fundo** | `bg-pub-surface` (lista) · nenhum (marca) · `bg-pub-sunken` (rodapé) | `PublicadorHeader.dc.html`:159 · `Publicacoes v2`:228 · :27. O fundo existe para o traço de trás não vazar, logo tem de ser a cor da superfície em que o glyph se apoia. |
| **P-3** | caixa do glyph `lista` = `15×16px` | `18×18px` | `18×18`, 1º retângulo em `left-[1px] top-[1px]` (offset relativo de 5/3 preservado) | `Publicacoes v2`:225/228/231 |
| **P-4** | padding do badge NOVA `px-[5px] py-[1px]` | `padding:2px 6px` | `px-[6px] py-[2px]` | `Publicacoes v2`:218 e `Visao Geral`:322 (idênticos). O README não especifica padding. |
| **P-5a** | padding da tag Catálogo `px-[5px]` | `padding:1px 6px` | `px-[6px] py-[1px]` | `Publicacoes v2`:243 |
| **P-6** | célula do mapa `18px` fixa | `20×20`, raio 5px, com borda sólida no estado "existe" | `tamanho = 20` por prop + borda sólida | `Publicacoes v2`:185 (única instância renderizada) e `cell()`:569. A borda sólida é o que impede a célula cheia de ficar 2px menor que a tracejada. |
| **P-7** | raio do tile `rounded-pub-sub` (10px), um só | escada por lado: 28/30/32/34→8px · 38/40→9px · 84→12px | raio 8 (≤34), 9 (≤40), 12 (acima); letra 10.5/11.5/20px | `PublicadorHeader`:35/146 · `Desempenho`:148 · `Visao Geral`:242/320 · `Publicacoes v2`:175/326. A própria FID-01 do plano dizia "tile 8px" e mapeava para um token de **10px**. |
| **P-8** | gap glyph→texto `gap-2` na lista | `gap:10px` | `gap-[10px]` na lista, `gap-[9px]` na marca/rodapé | `Publicacoes v2`:223 · `PublicadorHeader`:25 |

### Wave 3 (plano 177-04) — 4 medidas, na casca do header

| # | O plano prescrevia | O protótipo tem | Implementado |
|---|---|---|---|
| **P-1b** | `title="Configurações da conta"` | `title="Configurações da empresa"` (**:99**) | `"Configurações da empresa"` em `title` **e** `aria-label` (texto idêntico, IDENT-07) |
| **P-2b** | `<Search size={14} />` sem `strokeWidth` (cairia no 2 default do Lucide) | `stroke-width:1.6` (**:95**) | `strokeWidth` explícito (hoje `2.4`, após a conversão de viewBox) |
| **P-3b** | nada sobre `white-space` | `white-space:nowrap` nos 4 controles (**:25, :94, :99, :103**) | `whitespace-nowrap` nos quatro |
| **P-4b'** | `<span className="flex gap-[3px]">` nas teclas, sem anti-encolhimento | grupo de teclas content-sized ao lado de um `flex:1` (**:96/97**) | **`flex-none`** acrescentado ao grupo de teclas, aos ícones e aos 4 controles — nenhuma diferença visual a 1440px, mas impede que o nome longo da empresa esprema o lado direito. **Adição defensiva, não adaptação de estilo.** |

### Wave 4 (plano 177-05) — 4 medidas, nas abas

| # | O plano prescrevia | O protótipo tem | Implementado |
|---|---|---|---|
| **P-1c'** | nada sobre `white-space` na pílula | `white-space:nowrap` (**:111**) | `whitespace-nowrap` nas 4 pílulas |
| **P-2c'** | `relative inline-flex h-[34px] …` | `display:flex` (**:111**) | `flex` — como item de contêiner flex os dois computam igual, mas o valor do protótipo é `flex` e é o que está escrito |
| **P-3c'** | "ícone `text-pub-accent` na ativa" sem dizer **onde** a cor mora | o ícone vive dentro de `<span style="display:grid;place-items:center;color:…">` (**:112**) | a cor está na **caixa** `<span className="grid flex-none place-items-center …">`, como no protótipo — não jogada no `<svg>` |
| **P-4b** | "contador … `tabular-nums`" | `font-variant-numeric:tabular-nums` declarado **no próprio contador** (**:120**) | `tabular-nums` no contador, contagem exata de 3 no HTML (TOK-02) |

### Wave 5 (plano 177-06) — 5 medidas, no seletor e na pill

| # | O plano prescrevia | O protótipo tem | Implementado |
|---|---|---|---|
| **P-1c** | botão da conta com `rounded-pub-panel` (**12px**) | `border-radius:10px` (**:34**) | `rounded-pub-sub` (10px) |
| **P-2c** | tiles com `rounded-pub-sub` (**10px**) | `border-radius:8px` nos três (**:35, :54, :80**) | `rounded-[8px]` — ⚠️ **não há token de 8px na camada**; valor arbitrário de propósito (TOK-15 proíbe hex, não medida) |
| **P-3c** | nada sobre as iniciais do tile do item | `font-size:11px; font-weight:600` (**:54**) | `text-[11px] font-semibold` |
| **P-4c** | "ícone `Search` absoluto", sem coordenadas | `left:22px; top:21px` (**:45**) | `absolute left-[22px] top-[21px]` |
| **P-5** | nada sobre `white-space` nem sobre o `gap` da coluna nome/meta | `white-space:nowrap` no botão, na pill e no item (**:34, :73, :53**); `gap:2px` na coluna (**:55, :81**) | `whitespace-nowrap` nos três; `gap-[2px]` nas duas colunas |

### ⚠️ A medida que fez as contagens do gate CONTINUAREM em 8

A wave 4 deixou o recado *"o seletor é outro controle de 34px: atualize as contagens de 8 para 9"*.
**Medido no protótipo: o botão da conta tem `height:38px` e `border-radius:10px`** (`:34`) — o
"header 34px" do README vale para os controles da **direita** da linha 1, não para ele
(**P-R**, nesta tabela como medida nº 22 se contada à parte).

Resultado: as contagens `h-[34px]` e `rounded-pub-control` **continuam em 8**, e o gate ganhou
**asserção NEGATIVA** (`proibirClasses(tag, ['h-[34px]', 'rounded-pub-control', 'rounded-pub-panel'])`)
para o número certo **não ser "corrigido" de volta** por quem ler o recado antigo. As contagens que
**de fato** subiram foram outras duas: `font-pub-mono` 3→**4** e `text-[10.5px]` 3→**4**.

---

## 6. Divergências DECLARADAS — a lista fechada, por tipo de restrição

⚠️ **Nenhuma justificativa da lista proibida de FID-02 aparece aqui.** Não há uma única ocorrência
de "ficou mais parecido com o resto do sistema", "mantive o token `ecf-*` por consistência", "o
componente que já existe faz parecido" nem "o Tailwind não tem esse passo".

### 6.1 Por DECISÃO DO USUÁRIO — não é desvio acidental

| # | Protótipo / README | Implementado | A decisão |
|---|---|---|---|
| **D-1** | **5 abas**, com "Desempenho" (`:215` e README §0) | **4 abas** | **Decisão do usuário em 11/10/2026.** Palavras dele: *"não mostre o link da tela desempenho por enquanto"*. A régua é a dele: **nada nasce decorativo** — a tela não existe (Fase 182 registrada e não planejada, porque o dado de vendas por dia não existe) e aba que leva a lugar nenhum é link morto (EXMP-04). ⚠️ **NÃO "corrigir" para cinco.** O registro está em **três** lugares: o bloco no topo de `AbasDoHeader.jsx` com a frase dele, o teste *"a aba Desempenho NÃO existe (desvio deliberado)"* — que reprova no HTML **e na FONTE** — e esta SUMMARY. **Gatilho de volta:** a aba entra junto com a Fase 182. |
| **D-2** | a camada de tokens do `CLAUDE.md` manda "manter os tokens `ecf-*`" | camada `pub-*` **aditiva**, ao lado da `ecf-*` intacta | **Decisão do usuário em 11/10/2026** (D3 do handoff). O gate importa o `tailwind.config.js` de verdade e prova as duas metades: os 35 hex novos existem **e** `colors.ecf`, `fontFamily.sans` e `fontFamily.display` ficaram idênticos, com o **conjunto exato de chaves** (a camada antiga não pode ganhar nem perder token). |

### 6.2 Por FALTA DE DADO — o sistema não tem o que o protótipo mostra

| # | Protótipo | Implementado | A restrição concreta |
|---|---|---|---|
| **D-3** | `title="Publicações com erro"` (**:121**) | **"N publicações pedem ação"** | `MlAcervoItem.status` só assume `active\|paused\|under_review\|closed` (o `match` de `AcervoTriagemService::escopo()`). **Não existe status de erro no acervo — a coluna não existe.** O número real é "publicação com ≥1 motivo de triagem" (`severidade > 0` no escopo `acionaveis`), que é **exatamente** o número que o topo da tela de Publicações já mostra. Chave do contrato: `publicacoes_pedem_acao`. O gate reprova a volta de "com erro" (0 ocorrências no arquivo). |
| **D-4** | linha do ERP: *"Conectado · 32 SKUs · estoque atualizado há 25 min"* (**:219**) | **"Declarado: {nome}"** / **"Não informado"**, dot cinza | **Não existe integração com ERP neste sistema.** O ERP é apenas **declarado** na ficha de onboarding (ou vem da coluna `erp` do sync da planilha de Polos). Decisão 8 do handoff, literal: *"Exibir 'Bling · declarado no onboarding', nunca 'conectado', até existir integração real"*. `integracoes.erp` tem **uma única chave**: `declarado`. A prova é **negativa de propósito** — `assertArrayNotHasKey('conectado')`, `('sincronizado_em')`, `('atualizado_em')`: asserção positiva deixaria a porta aberta para uma fase futura ACRESCENTAR `conectado` com o teste continuando verde. |
| **D-5** | o 2º dot da pill é o **ERP** (**:74, :226**) e o popover tem **2 linhas** | o 2º dot é o **Portal do Cliente**, e o popover tem **3 linhas** (ML, ERP, Portal) | Mesmo motivo de D-4: não há estado de conexão do ERP para medir. O **Portal** tem estado real (`situacao`/`novas`) e é a capacidade que o `SeloPortal` entrega hoje — HEAD-11 exige que ela não se perca. É o **único** segundo sinal de conexão REAL que existe. |
| **D-6** | meta do ML: *"Conta ECFDEV02 · token renova em **4 h**"* (**:218**) | *"Conta {nome} · {ml_user_id}"* | `empresaParaTela()` expõe `ativo\|expirado\|sem_token` — o MESMO que o `SeloConta` mostra hoje. **Não há fonte para o prazo de renovação.** Gate: `doesNotMatch(/renova/i)` no HTML **e** na fonte; o teste de contrato trava a lista exata de chaves e nega `renova_em`/`expires_at`. (`renova` no arquivo: 0 ocorrências.) |
| **D-7** | rodapé *"48 empresas sob gestão"* (**:66**) | o `total` **REAL** do endpoint | Número fixo no JSX é proibido (HEAD-10). Sem `total`, **nenhuma** contagem é escrita. Gate: `doesNotMatch(FONTE, /48 empresas/)`. |
| **D-8** | o mapa de cobertura deriva de uma lista de publicações | 4 booleanos nomeados (`f1_classico`, `f1_premium`, `f2_classico`, `f2_premium`) | O shape da lista de publicações **só se decide na Fase 180**. Nenhuma medida visual muda. `1` também conta como `true` (um `tinyint` cru chegaria 1). |
| **D-9** | `sem_token` = "Sem conexão" (prescrição do plano) | **"Falta reconectar"** | `SeloConta.jsx` documenta que `sem_token` significa *"autorizou antes de 21/09/2026, quando o callback descartava a credencial: **autorizou de verdade**, falta reconectar"*. "Sem conexão" mandaria o consultor pedir autorização do zero. É o literal **que já está no ar** (REG-01). |
| **D-10** | `"Reconectar →"` com o `link_reconexao` (prescrição do plano) | `<Link>` para a **lista de empresas**, com `title`/`aria-label` avisando | O link é o link **público** do Onboarding: **quem clica tem de ser o CLIENTE, no navegador dele**. Um clique interno da ECF autoriza a conta errada — é o **caso Masitto, 27/08**, escrito no `title` de `LinkReconexao.jsx`. Na lista o link aparece num botão que **copia**, que é a forma segura. Gate: `doesNotMatch(html, /exemplo\/onboarding/)` e `doesNotMatch(FONTE, /href=\{[^}]*link_reconexao/)`. |

### 6.3 Por RESTRIÇÃO TÉCNICA concreta

| # | Protótipo | Implementado | A restrição concreta |
|---|---|---|---|
| **D-11** | IDENT-07: "tooltips pelo componente de tooltip do codebase, não por `title` nativo" | `title` nativo **mais** `aria-label` com o **mesmo** texto | **Não existe componente de tooltip em `Components/ui/`** e esta fase **não instala pacote** (T-177-SC). A guarda real é **não deixar informação EXCLUSIVA no `title`**: a célula do mapa já diz por letra (C/P), posição (F1 à esquerda), borda (sólida × tracejada) e cor; o `aria-label` entrega o texto completo a quem usa leitor de tela. Mutação M10 do gate reprova se o `aria-label` sair. Troca por tooltip rico é item da Fase 179. |
| **D-12** | borda da busca e do separador vertical `#222222` (**:94, :102**) | `border-pub-line` (`#262626`) | `#222222` **não está na tabela de tokens do README**, e TOK-15 proíbe hex literal fora da camada. Diferença de 4 unidades de luminância numa borda de 1px. **Para o valor exato, o caminho é acrescentar o token à camada — não escrever o hex na tela.** |
| **D-13** | separador "/" em SVG inline (**:32**) | SVG inline, **idêntico** | TOK-14 manda preferir o Lucide "quando existe equivalente"; **não existe barra inclinada fina de separação no Lucide 1.11**. Exceção nominal, com o `viewBox` 16×22 do próprio protótipo — e por isso **fora** da conversão de traço, com asserção nova travando isso para ninguém "uniformizar" depois. |
| **D-14** | dropdown/popover por `position:absolute` + backdrop `fixed inset-0` (**:129**) | `@radix-ui/react-popover` com `Portal`, Esc e clique-fora nativos | O header vive **dentro do `<main>` com `overflow-y-auto`** e **D2 proíbe alterar o `AppLayout`**: conteúdo absoluto ali é recortado pelo scrollport e rola com a página. O Radix já era dependência e é o que `BarraDaConta` usa hoje. HEAD-13 preservado — `onEscapeKeyDown`/`onPointerDownOutside` **não** foram desabilitados, com gate negativo provando. |
| **D-15** | não existe slot de ações nem `AvisoContaTravada` no protótipo | slot `acoes` no fim da `<nav>` (linha 2) · `AvisoContaTravada` como selo na linha 1 | **HEAD-11 exige que a capacidade de hoje sobreviva.** Na linha 1 o slot mudaria a geometria que o protótipo fixa; a linha 2 tem espaço livre e a ação é de página, como as abas. **Sem `acoes`, nenhum `<div>` é renderizado** (teste conta os `<div>`). O `AvisoContaTravada` não tem contraparte no protótipo e o redesenho dele não está em nenhuma fase desta milestone — reusar é preservar, redesenhar seria escopo inventado. |
| **D-16** | modal "Nova publicação" de 620px (**:132-164**) | navega para a seleção de produtos da conta | **Fase 178**, por sequência do roadmap. O CTA **não fica morto** (EXMP-03) e a assinatura de `abrirNovaPublicacao` já é a que a 178 vai reaproveitar. |
| **D-17** | não existe header global + lista sticky juntos | `sticky top-[108px]` nos filtros de `Produtos` e do lote | O header ocupa 108px do topo do scrollport; `top-0` esconderia os filtros e o cabeçalho de colunas atrás dele — **regressão funcional, não estética**. ⚠️ Ver o ponto 1 da conferência visual. |
| **D-18** | não existem sub-abas **No ar \| Histórico** | movidas para o corpo, **com a aparência de hoje** | A remoção é autorizada só na **Fase 180** (LIST-25), com a guarda *o atalho sai, o dado não*. Redesenhá-las agora seria trabalho para algo que vai ser apagado; harmonizá-las seria FID-02 sem restrição. As 4 strings de `className` são iguais às de `AbasDaConta` menos o `mt-2` (que era espaçamento EXTERNO — quem posiciona agora é a página). |
| **D-19** | o protótipo redesenha as telas | **corpo antigo intacto** nas 9 páginas, com `ecf-*` | **HEAD-15**: estado intermediário **aceito e declarado**. As Fases 179/180/181 redesenham corpos. ⚠️ **O corpo de `Produtos` não está em nenhuma fase desta milestone.** |
| **D-20** | — | `font-pub` **não aplicado** ao corpo de nenhuma das 9 páginas | A família é declarada **uma vez**, na tag do `<header>` (cobre toda a subárvore, inclusive os 7 componentes de identidade) e nos 2 `Popover.Content` (o Radix porta para o `document.body`, única saída da subárvore). TOK-01 diz "ao lado de Inter/Manrope, **sem trocar o `sans` global**", e `<body class="font-sans antialiased">` está travado por gate. ⚠️ **Dois executores recusaram instrução contrária do orquestrador** (planos 177-07 e 177-08) — e estavam certos: a premissa repassada estava desatualizada. **Medido agora: `font-pub` em 0 das 9 páginas e em 0 do `SubAbasDePublicacoes`.** |
| **D-21** | props `active`, `mlConnected`, `erpConnected` (**:168**) | `aba` + `cabecalho` + `acoes` + `liberada` | Os três booleanos são **mock**. O estado real das integrações chega do servidor num objeto só (177-03); `aba` continua prop própria porque é a página que sabe em que aba está. **Informação idêntica, forma diferente.** |
| **D-22** | `style-hover` vale para TODAS as abas, inclusive a ativa (**:111**) | hover **só nas inativas** | O `style-hover` do formato DC é uma regra única, sem ramo por estado. Aplicá-lo à ativa a **ESCURECERIA** no hover (`#161616` → `#151515`), que é o inverso do feedback esperado. A ativa já tem fundo e anel próprios. |

### 6.4 A ÚNICA remoção de elemento da fase

| Item | Hoje / Protótipo | Implementado | A restrição concreta |
|---|---|---|---|
| Trilha `Publicador › {Programa} › {Nome}` | existe em `BarraDaConta`; **não existe no protótipo** | **removida** | A marca "Publicador" (que é um link para a entrada) + o seletor de empresa cumprem a função de "onde estou / como volto", e o programa aparece no dropdown do seletor. **Recriar a trilha dentro do header seria adaptar o layout novo ao sistema atual** — exatamente o que FID-02 proíbe e o que o usuário nomeou como a causa do redesign anterior ter falhado. |

**Nada mais foi removido da tela.** Nenhum arquivo foi deletado em 8 planos
(`git diff --diff-filter=D b36037f9..HEAD` → vazio).

---

## 7. Inventário REG-01 CONSOLIDADO — as 9 páginas, conferidas item a item

⚠️ **Conferido por script sobre a FONTE das 9 páginas, agora, não lido das SUMMARYs anteriores.**
**77 conferências · 0 falhas.**

| Página | Rota | `aba` | `acoes` | import ×1 | monta ×1 | `cabecalho ?? null` | sem `BarraDaConta`/`AbasDaConta` | header ANTES do corpo | corpo sem `font-pub` |
|---|---|---|---|---|---|---|---|---|---|
| `VisaoGeral.jsx` | `…/visao-geral` | `visao-geral` | — | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `Produtos.jsx` | `publicador/empresas/{conta}` | `produtos` | Sincronizar + "+ Produto" | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `Produto.jsx` | `…/produtos/{produto}` | `produtos` | — | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `Alavancas.jsx` | `…/alavancas` | `alavancas` | — | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `Configuracoes.jsx` | `…/configuracoes` | `configuracoes` | — | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `PublicacaoEmLote.jsx` | `…/lote` | `produtos` | Link "‹ Produtos" | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `MeusAnuncios.jsx` | `/mlb/anuncios/meus/{company}` | `publicacoes` | `BotaoAtualizar` | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `AnunciosHistorico.jsx` | `/mlb/anuncios/historico/{company}` | `publicacoes` | — (de propósito) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `AnunciarMassa.jsx` | `/mlb/anuncios/massa/{company}` | `produtos` | Link "‹ Voltar aos produtos" | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |

> `Configuracoes.jsx` usa `aba="configuracoes"` **de propósito**: a aba de Configurações saiu da
> linha 2 e virou o ícone de engrenagem da linha 1 (HEAD-08), então esse valor serve para
> **NENHUMA** das quatro pílulas ficar ativa. `AnunciosHistorico.jsx` **não** passa `acoes=`:
> um slot vazio deixaria uma caixa órfã na linha 2.

### A lista do que não podia se perder — conferida

| Capacidade (HEAD-11 / REG-01) | Sobreviveu? | Onde está agora / como foi conferido |
|---|---|---|
| Nome da conta | ✔ | `SeletorDeEmpresaDoHeader` (botão de 38px). Render real nas 9 páginas |
| Chave da conta | ✔ | mesmo botão, em `font-pub-mono`; passa por `textoSeguro(…, null)` — chave objeto **não** monta destino inventado em `route()` |
| `SeloConta` (3 estados) | ✔ | vocabulário reusado pela pill de conexão; em `Configuracoes` o `SeloConta` original **continua montado no corpo**, intocado |
| `SeloPortal` | ✔ | 2º dot da pill + linha do Portal no popover de 320px; em `Configuracoes` segue no corpo |
| `AvisoContaTravada` | ✔ **nos dois lugares** | selo na linha 1 (`liberada={false}`) **e** faixa no corpo — as duas aparições já conviviam antes da troca |
| "Trocar empresa" preservando a aba | ✔ | dropdown de 360px, com busca que ignora acento e caixa; usa `destinoParaItem()` da Fase 173, **importado e não reimplementado** |
| Slot de ações por página | ✔ | `acoes` do header, na linha 2 à direita; conteúdo **byte a byte** o de antes, conferido **dentro** do `<header>` |
| Contador de produtos | ✔ | `cabecalho.contadores.produtos`, do servidor (reusa `produtosQuery()`) |
| Badge de alavancas pendentes | ✔ | `cabecalho.contadores.alavancas` — **a MESMA chamada de antes** (`PubTarefa::abertasDaConta`), comparada linha a linha com `MlbAlavancasController::index()` |
| **D23 com o literal exato** | ✔ | `'Disponível só para empresas cadastradas no sistema'`, aba **nunca escondida**, contador visível; travado por 4 asserções + gate de fonte |
| As 4 rotas das abas | ✔ | inalteradas desde `AbasDaConta.jsx`; render real confere as 4 presentes |
| `aria-current` na ativa | ✔ | **contagem exata**: 1 nas páginas de aba, 0 em `Configuracoes` |
| Sub-abas **No ar \| Histórico** | ✔ | `SubAbasDePublicacoes.jsx`, com `role="radiogroup"`, os 2 `role="radio"`, as 2 rotas, os 2 rótulos e `aria-checked` no certo — **depois do `</header>`**, nunca dentro dele |
| "Atualizar agora" **enfileirando** (D-05) | ✔ | no slot de ações, no rodapé do popover e em 3 pontos do corpo; **todos** chamam a MESMA rota, que enfileira |
| **D-11** (a tela nunca escreve no ML) | ✔ **intacta** | `doesNotMatch(/router\.put\(/)`, `/delete\(/`, `/patch\(/` + `strictEqual(chamadasPost.length, 1)` — não toquei |
| **D-22** (nota "de 86", nunca `/100`) | ✔ **intacta** | `match(/de 86/)` + `doesNotMatch(/\/ 100\b/)` + `doesNotMatch(/\* 100 \/ 86/)` — não toquei |
| **D-14** (sub-abas Publicados \| Rascunhos) | ✔ **intacta** | `SUBABAS`, as 2 chaves e `trocarSubAba` |
| **REG-02** (o que o usuário acabou de ganhar) | ✔ | "Em revisão" em `MeusAnuncios.jsx` · "Quanto você recebe" em `Mesa/EtapaCondicoes.jsx` (território do outro dev, intocado) · "de 86" em `MeusAnuncios.jsx` |
| **REND-05** (âncoras de DOM são contrato) | ✔ | `Components/Publicador/destaque.js` **intocado** no range |
| Trilha `Publicador › Programa › Nome` | ❌ **removida** | a única remoção — ver 6.4 |

### A colisão de sticky — e o que sustenta o 108

| Arquivo | Antes | Depois | Gate |
|---|---|---|---|
| `Produtos.jsx` (~L888) | `sticky top-0 z-10 rounded-t-xl bg-ecf-card` | `sticky top-[108px] z-10 …` | 2 asserções + `doesNotMatch(/class="sticky top-0 z-10/)` |
| `PublicacaoEmLote.jsx` (~L247) | idem | idem | 2 asserções |

A conta é `h-[60px]` (linha 1) + `h-[48px]` (nav) = **108px de conteúdo**, e as **duas medidas
estão asseridas nos gates de `Produtos` e do lote** justamente para o conserto não ser esquecido se
alguma mudar (mutação `h-[60px]`→`h-[64px]` reprova em 7 testes; `h-[48px]`→`h-[44px]` em 5).
Quem rola é o `<main>` (`overflow-y-auto`), não a janela — o header do `AppLayout` (`h-[60px]
shrink-0`) fica **fora** do scrollport e por isso **não** entra na conta.

⚠️ **O hairline de 1px está no ponto 1 da conferência visual.**

### As 2 páginas que NÃO entraram, e por quê (re-medido)

| Página | Monta `BarraDaConta`/`AbasDaConta`? | Diff no range |
|---|---|---|
| `Pages/Mlb/AnunciarML.jsx` (assistente antigo) | **Não** — só o comentário dizendo que não usa (`:2015`) e a trilha própria dele | **VAZIO** ✔ |
| `Pages/Mlb/Publicador/Tarefas.jsx` | **Não** — tela GLOBAL, de todas as contas; não há conta para o header exibir | **VAZIO** ✔ |

⚠️ **Divergência de CONTAGEM, registrada em vez de silenciada:** `REQUIREMENTS-v26.md` diz
**"11 páginas"** em HEAD-11, HEAD-15 e REG-01. A medição por quem **monta**
(`grep -rn '<BarraDaConta\|<AbasDaConta' resources/js/`) deu **9**. As 9 são as desta tabela; as
duas candidatas óbvias (`AnunciarML`, `Tarefas`) **não montavam** nenhum dos dois. O número 11 do
REQUIREMENTS parece ser estimativa do planejamento; **o número medido é 9**. Como
`REQUIREMENTS-v26.md` **não** é editado por ordem do usuário, o registro mora aqui.

---

## 8. Cirurgia de gates CONSOLIDADA — medida no diff

⚠️ **Contagem medida, não declarada:** `git diff b36037f9..HEAD -- tests/ | grep '^-' | grep assert`.

### O resultado de uma frase: **13 asserções alteradas · 9 reapontadas (a) · 4 endurecidas (c) · ZERO removidas (b).**

| # | Arquivo · asserção que saiu | Classif. | O que entrou no lugar, e por quê |
|---|---|---|---|
| 1 | `publicador-alavancas` → `/import AbasDaConta /` | **(a)** | `/import PublicadorHeader /` + `doesNotMatch` dos dois imports antigos (o nome do teste mudou, então o veto tem de ficar explícito) |
| 2 | `publicador-alavancas` → `/<AbasDaConta aba="produtos"/` | **(a)** | `/<PublicadorHeader\s+aba="produtos"/` — prova a MESMA coisa (a lista marca "produtos"), no alvo novo |
| 3 | `publicador-entrada` → `/companyId={abas\??\.company_id( \?\? null)?}/` | **(a)** | `/cabecalho={cabecalho \?\? null}/`. ⚠️ **A lição que a asserção guardava continua escrita no comentário acima dela**: o default de parâmetro só cobre `undefined`, e prop NULA numa recarga parcial do Inertia **já derrubou tela neste projeto** |
| 4 | `publicador-entrada` → `/conta={empresa\.chave}/` | **(a)** | ⚠️ **Esta NÃO falhou — e foi por isso que precisou sair.** A string continua em `Produtos.jsx` porque o `BotaoSincronizarPortal` a usa; a asserção **passaria sem provar nada sobre as abas**. **Asserção vazia é pior que asserção ausente.** → `/<PublicadorHeader\s+aba="produtos"/` |
| 5-6 | `publicador-produtos-fases` → `/sticky top-0 z-10/` ×2 | **(a)** | `/sticky top-\[108px\] z-10/` + `doesNotMatch` do `top-0` |
| 7 | `publicador-produtos-layout` → `/sticky top-0 z-10 rounded-t-xl bg-ecf-card/` | **(a)** | idem + **contagem exata (1)** + `doesNotMatch(/class="sticky top-0 z-10/)` + as **duas medidas do header que sustentam o 108** |
| 8 | `publicador-tarefas` → `/contagemAlavancas=\{alavancas\.tarefas_abertas \?\? null\}/` | **(a)** | `/<PublicadorHeader aba="alavancas" cabecalho=\{cabecalho \?\? null\}/`. O contador **existe igual**, só muda de rota: mesma chamada no servidor |
| 9 | `publicador-tarefas` → `/contagemAlavancas=\{abas\?\.alavancas_pendentes \?\? null\}/` | **(a)** | idem, no laço de `VisaoGeral` e `Produtos` |
| 10-12 | `publicador-lote` → as 3 de `disabled=""` com `.*?` e flag `/s` | **(c) endurecida** | **Furo REAL, não previsto em plano nenhum.** O `.*?` com `/s` casa **através de elementos**: com o header no topo, a busca de ⌘K nasce `disabled` e tem um `<svg>` dentro, então o casamento saía de um botão do HEADER e varria dezenas de elementos até o texto do botão de agendar. Era **falso negativo** numa e **asserção vazia** nas duas irmãs (passavam pelo motivo errado). → `exigirBotao()`, que extrai o `<button>` e confere `/disabled=/` **naquele** elemento |
| 13 | `estrutura-anuncios-historico` → `/grupos\.links/` | **(c) endurecida** | Furo **PRÉ-EXISTENTE de 173-05**, achado na prova por mutação do 177-08: `grupos.linksQualquerCoisa` passava. → `/grupos\.links\b/`. **Não é regressão da fase** — registrado em `deferred-items.md` por isso |

**Mais uma endurecida que não aparece como linha removida** (a asserção era nova no mesmo plano):
em `estrutura-meus-anuncios`, a aparência do componente movido deixou de ser **checklist de classes**
e passou a ser **igualdade da string INTEIRA** dos 4 `className`, depois que a checklist deixou
passar `gap-1` → `gap-2` no trilho do segmentado. **Nasceu fraca e foi endurecida antes do commit.**

### ⚠️ O resultado que muda a leitura de GATE-01 e GATE-02 nesta fase

**ZERO asserções de aparência antiga precisaram sair — e isso foi MEDIDO por execução, não suposto.**

GATE-01/GATE-02 mandam remover a asserção de aparência antiga **só dos arquivos redesenhados**. Os
arquivos redesenhados desta fase são os de `header/` e `identidade/` — e **eles não estão nas
listas desses gates** (quem os cobre é o gate novo, `publicador-vocabulario-novo.test.js`). As
páginas **não** ganharam uma única classe nova: as classes do design novo moram todas nos
componentes do header, e as páginas só passam props. Por isso:

- em `publicador-entrada.test.js`, `Produtos.jsx` e `Alavancas.jsx` **continuam gateados** contra
  tipografia só 24/15/13/11px, pesos só 400/700, `bg-ecf-yellow` sólido, `@/Components/ui/select`,
  `dangerouslySetInnerHTML` e `uppercase` — **e passam**;
- em `estrutura-meus-anuncios.test.js`, o helper `travaVocabularioVisual()` aplica as **6**
  asserções a **três** arquivos (`MeusAnuncios`, `RascunhosPainel`, `ModalDetalheAnuncio`) = **18
  testes**, todos verdes antes e depois. `RascunhosPainel` e `ModalDetalheAnuncio` **não foram
  tocados** e nem precisaram ser.

**GATE-02 fica devido para as Fases 179/180/181**, quando os corpos forem redesenhados — aí sim a
asserção sai, por asserção, citando a medida do README nominalmente.

### Asserções que ENTRARAM

| Onde | Testes novos |
|---|---|
| `publicador-tokens-do-publicador.test.js` (177-01, novo) | 6 |
| `publicador-vocabulario-novo.test.js` (177-02, novo; crescido em 04/05/06/07) | 61 + 12 + 6 + 12 + 44 asserções |
| `publicador-identidade-nova-render.test.js` (177-02, novo) | 63 |
| `CabecalhoDoPublicadorTest.php` (177-03, novo) | **16 casos · 210 asserções** |
| `publicador-header-render.test.js` (177-04, novo) | 72 |
| `publicador-header-abas-render.test.js` (177-05, novo) | 72 |
| `publicador-header-conta-render.test.js` (177-06, novo) | 109 |
| `publicador-header-nas-paginas.test.js` (177-07, novo) | 37 |
| `publicador-paginas-anuncios-render.test.js` (177-08, novo) | 53 |
| **Total** | **+579 testes JS · +16 casos PHP (+210 asserções)** · **1.039 linhas de asserção acrescentadas contra 23 linhas removidas em `tests/`** |

### A rede que NÃO existia e passou a existir

Isto não é zelo — é resultado de medir por mutação **antes** de escrever gate:

| Lacuna medida | Consequência | O que fechou |
|---|---|---|
| `Produto.jsx` não tinha **nenhum** teste (nem de fonte, nem de render) desde a Fase 175 | apagar o header dessa página **não reprovava nada** na suíte inteira | `publicador-header-nas-paginas.test.js` + HEAD-14 no gate de vocabulário |
| `VisaoGeral.jsx` e `Alavancas.jsx` só tinham gate de **fonte** | `cabecalho` adverso nunca era exercitado | idem |
| `Configuracoes.jsx` e `PublicacaoEmLote.jsx` eram renderizados **sem** `cabecalho` | só o caminho do default de parâmetro era coberto — justamente o que **não** cobre `null` | idem |
| O bloco sticky de `PublicacaoEmLote.jsx` não era olhado por teste nenhum | voltar para `top-0` passava calado | asserção nova em `publicador-lote.test.js` |
| Os slots de `acoes` de `Produtos` e do lote | apagar o slot inteiro na troca não reprovava nada | 2 asserções novas, com os botões conferidos **dentro** do `<header>` |
| Nenhum gate dizia que o header **existe** nas páginas | só havia gate contra o header ANTIGO voltar, que não é a mesma coisa | HEAD-14: 6 páginas × 3 asserções, com a tabela `ABA_POR_PAGINA` lendo a prop **da tag** |
| Só `MeusAnuncios` tinha render real, e só de 2 sub-componentes | as 3 páginas de `/mlb/anuncios` inteiras nunca eram montadas | `publicador-paginas-anuncios-render.test.js` (53 testes) |

### Prova por mutação — o total da fase

| Plano | Mutações | Furos achados e fechados |
|---|---|---|
| 177-01 | 8 | 0 |
| 177-02 | 16 | 0 |
| 177-04 | 39 (22 + 17) | **4** — `html.includes()` global não prova nada quando 4 controles compartilham a classe; o gate foi **reescrito** para extrair a tag por elemento + contagens exatas |
| 177-05 | 47 | 0 |
| 177-06 | 125 | **7** |
| 177-07 | 52 | **1** — o gate provava que o header **existe**, não **qual aba** marca |
| 177-08 | 50 (+2 controles) | **1** — checklist de classes deixou passar `gap-1`→`gap-2` |
| **Total** | **337** | **13 furos achados e fechados antes do commit** |

Em todos, restauração do arquivo conferida por **sha256** e `git diff` vazio depois.

### GATE-05 auditado na fase inteira

⚠️ `assert.match(tag, /disabled/)` é **asserção vazia** neste projeto (as classes têm
`disabled:opacity-40`). Auditoria de **todos** os gates tocados no range: **toda** asserção real usa
`/disabled=/` ou `/aria-disabled="true"/` — **com o `=`**. As 8 ocorrências de `/disabled/` sem o
`=` estão **todas dentro de comentários** que avisam sobre a armadilha. **0 asserções vazias.**

---

## 9. Rastro dos 61 REQ-IDs da fase

| Família | IDs | Entregues | Parciais | Nota |
|---|---|---|---|---|
| **FID** (6) | 01-06 | 01, 02, 03, 04, 05 | **06** | FID-06 ("o protótipo é autoridade de comportamento: ordenação, cálculo de margem…") só se exerce quando os **corpos** forem redesenhados (Fases 179-181). No header não há ordenação nem cálculo |
| **TOK** (15) | 01-15 | 01, 02, 04, 05, 06, 07, 08, 09, 13, 14, 15 | **03, 10, 11, 12** | **TOK-03**: só o tracking do header (`-0.01em`) existe; H1 28px/`-0.025em` e H2 17px são do corpo. **TOK-10/TOK-11**: espaçamento e alturas **deliberadamente NÃO tokenizados** — FID-03 manda escrever a medida exata (`h-[54px]`, `gap-[18px]`), e tokenizar é **decisão do usuário**, não do executor. **TOK-12**: `animate-pub-spin` e `ease-pub-painel` declarados; "chevrons rotacionam" não se aplica ao `ChevronsUpDown` do protótipo |
| **IDENT** (8) | 01-08 | 01, 02, 03, 04, 05, 06, 08 | **07** | **IDENT-07**: `title` + `aria-label` em vez de componente de tooltip — divergência **D-11**, sem componente no codebase e a fase não instala pacote |
| **HEAD** (15) | 01-15 | 01, 03, 04, 05, 06, 07, 08, 10, 11, 12, 13, 14, 15 | **02, 09** | **HEAD-02**: props em forma diferente (**D-21**). **HEAD-09**: **4 abas em vez de 5** — decisão do usuário (**D-1**); todo o resto do HEAD-09 (geometria da pílula, contador, anel interno, barra amarela) está entregue |
| **GATE** (6) | 01-06 | 03, 04, 05, 06 | **01, 02** | **GATE-01/02**: devidos para as Fases 179/180/181 — **medido** que nenhum arquivo redesenhado desta fase está nas listas desses gates, logo nada tinha a sair |
| **REND** (5) | 01-05 | 01, 02, 03, 04, 05 | — | todos entregues; REND-05 conferido (`destaque.js` intocado) |
| **REG** (6) | 01-06 | 01, 02, 03, 04, 05, 06 | — | ⚠️ REG-01 entregue para **9** páginas; o REQUIREMENTS diz 11 (ver a divergência de contagem em §7) |

**Entregues: 48 · Parciais com motivo escrito: 13 · Sem tratamento: 0.**

⚠️ **`REQUIREMENTS-v26.md` NÃO foi editado** (ordem do usuário) — o rastro mora aqui.
⚠️ Vale o aviso da memória do projeto: `requirements.mark-complete` dá `not_found` para IDs que só
existem em `REQUIREMENTS-vNN.md`, e `phase.complete` devolve `requirements_updated:false` **em
silêncio**. Marcar os checkboxes à mão é do orquestrador/usuário, ao fechar a fase.

---

## 10. Varredura de órfãos — declarada, com a medida

| Arquivo | Estado medido | Decisão |
|---|---|---|
| `BarraDaConta.jsx` | **`<BarraDaConta>` montado em 0 lugares.** O `default` ficou sem consumidor. Mas os exports **nomeados** `textoSeguro` e `textoUtil` são importados por **16 arquivos** (12 componentes — inclusive os 4 novos do `header/` e 2 da `identidade/` — e 4 páginas) | **NÃO APAGAR O ARQUIVO.** Apagá-lo quebraria 16 imports. Só o `default` é dívida |
| `AbasDaConta.jsx` | **Importado por 0 arquivos** (só citado em comentários). Órfão de verdade | **Não apagar nesta fase.** `publicador-barra-abas-render.test.js` segue **37/37 verde** sobre os dois arquivos; apagar aqui derrubaria um teste verde por nada |
| `SubAbasDePublicacoes.jsx` | Vivo, montado nas 2 páginas de Publicações. **Único arquivo novo FORA do `ARQUIVOS_NOVOS`** do gate de vocabulário — de propósito: é **corpo antigo**, e o gate novo (que proíbe `ecf-*`, `text-sm`) reprovaria justamente o que aqui é obrigatório | **DELETAR na Fase 180** (LIST-25). O docblock dele diz isso, com data e referência |

**Dívida declarada, a varrer depois**, com a prova de que nenhuma página monta os dois. A varredura
é separada, e ao fazê-la: `textoSeguro`/`textoUtil` precisam de outra casa **antes**.

### O gate novo cobre TODOS os arquivos novos de código

Conferido por conjunto: **15 arquivos novos** em `resources/js` no range · **14 na lista
`ARQUIVOS_NOVOS`** · **0 na lista que não existem em disco** · **1 fora da lista**:
`SubAbasDePublicacoes.jsx`, deliberado.

A lista é **constante, não glob** — de propósito: glob pegaria arquivo que ainda não nasceu e o gate
passaria **por omissão**. Já a lista de **páginas** de HEAD-14 é **glob**, também de propósito: lá o
risco é página nova **escapar** do gate. As duas listas são conferidas uma contra a outra por
`deepEqual` com o `readdirSync`.

---

## 11. Inventário do que ficou DEFERRED

`.planning/phases/177-…/deferred-items.md` (2 itens, nenhum causado pela fase):

| # | Item | Estado | Quando resolver |
|---|---|---|---|
| 1 | `iniciais()` **morto** no escopo do módulo em `AnunciarMassa.jsx` (~:39) | **já era morto antes** da troca (conferido em `git show HEAD:…`); as ocorrências nas linhas 88-110 são uma variável LOCAL homônima | na varredura que apagar `BarraDaConta`/`AbasDaConta` |
| 2 | Furo pré-existente de `grupos.links` sem `\b` (gate de 173-05) | **já CORRIGIDO** no 177-08 (um caractere, arquivo já aberto). Registrado para **não** ser lido como regressão desta fase | feito |

### Itens que esta fase deixa anotados para quem vier (não são deferred, são avisos)

1. **`#18160C`** (fundo da linha **marcada**, checkbox) e **`#5F5F68`** (anel de "Em revisão") são
   hex que aparecem nas telas do protótipo e **não constam da tabela de cores do README** — logo não
   têm token. TOK-15 proíbe hex literal nas telas, então **quem redesenhar a tabela de Publicações
   (Fase 180) precisa acrescentar os tokens à camada** (adição; o gate atual não impede) ou aceitar
   o valor vizinho que já tem token. **Não inventei token** — a instrução é seguir a tabela
   prescritiva.
2. **O `tailwind.config.js` só é importável por Node com um hook de resolve.** Ele faz
   `import defaultTheme from 'tailwindcss/defaultTheme'` e o `tailwindcss` 3.4.19 não tem mapa
   `exports`; o ESM do Node exige o `.js`. **É pré-existente.** Qualquer gate futuro sobre o config
   precisa do mesmo preâmbulo (`module.registerHooks()`, Node ≥ 22.15 — a máquina está em v24.14.0
   e o projeto não tem `.nvmrc`). Acrescentar `.js` ao import seria **uma linha**, mas é mudança
   **não aditiva** num arquivo que alimenta o build de todo o sistema.
3. **A conta do 108 é frágil por natureza**: depende de `h-[60px]` e `h-[48px]`, que moram em
   `PublicadorHeader.jsx`. Se alguma mudar, **os dois `sticky top-[108px]` mudam junto** — há
   asserção das duas medidas nos gates de `Produtos` e do lote para isso.
4. **Toda página nova em `Pages/Mlb/Publicador/` vai reprovar dois gates** até alguém decidir se ela
   tem header de conta (o `deepEqual` de HEAD-14 e o de `publicador-header-nas-paginas`). É de
   propósito — foi `Produto.jsx` ficar sem nenhum teste desde a Fase 175 que motivou a trava.
5. **As fixtures de mutação** (`tests/js/.*-stub-*.mjs`) são apagadas pelo `after()` do
   `node --test`, mas **ficam para trás quando o módulo falha antes de carregar**. Não há regra no
   `.gitignore`. Conferir `git status --short | grep tests/js` antes de commitar. **Agora está
   limpo.**

---

## 12. ⚠️ Os 2 pontos que as waves deixaram para a CONFERÊNCIA VISUAL

**Os dois são para o usuário olhar. Nenhum foi "consertado" por mim — este plano mede, não corrige.**
O conserto de cada um está escrito, pronto.

### Ponto 1 — o hairline de 1px no `sticky`

O header mede **108px de conteúdo** (`h-[60px]` + `h-[48px]`) **mais 1px de `border-b`** = caixa
externa de **109px**. Os filtros de `Produtos.jsx` e `PublicacaoEmLote.jsx` usam `top-[108px]`,
então encostam **1px sob** a borda do header.

A wave 6 analisou e concluiu que **nada legível se esconde**: o `z-50` do header pinta sobre o
`z-10` do bloco, e esse 1º pixel do bloco é só `bg-ecf-card` com o `rounded-t-xl` (o `p-4` do
conteúdo vem depois). **Manteve 108**, que é o valor prescrito em três lugares do plano e travado em
**4 gates**.

⚠️ **Se a conferência visual mostrar a borda do header cortando o bloco de filtros, o valor certo é
`top-[109px]`.** O conserto é: o literal em **4 gates** (`publicador-produtos-layout`,
`publicador-produtos-fases` ×2, `publicador-lote`) e **2 comentários** nas duas páginas.

### Ponto 2 — o traço dos ícones

A wave 5 multiplicou os `strokeWidth` por **1,5** (`viewBox` 16 do protótipo × 24 do Lucide):
`1.6`→`2.4` · `1.4`→`2.1` · `2`→`3` · `1.7`→`2.55`. Antes, os ícones saíam com **dois terços** da
espessura desenhada (1,0 px em vez de 1,5 px).

⚠️ **A conta é aritmética; "ficou bom" é olho.** Se ficaram grossos demais, o ajuste é **uma linha
em cada traço** mais as asserções de gate — e vale fazer **de uma vez**, não por wave.

⚠️ **O separador "/" ficou FORA da conta de propósito** (SVG inline com o `viewBox` 16×22 do próprio
protótipo, logo o `1.4` dele já é o traço certo). Há asserção **nova** travando isso, para ninguém
"uniformizar" os traços depois e estragar o separador — e mutação provando que a uniformização
reprova.

---

## 13. O que NÃO foi feito neste plano, e por quê

- **Nenhuma linha de código de produção alterada.** O papel deste plano é **medir e consolidar**.
  Os dois achados visuais viraram **pergunta ao usuário**, não commit.
- **Não "harmonizei" o header com o corpo antigo** (FID-02/HEAD-15) — é o deslize que o usuário
  nomeou como a causa do redesign anterior ter falhado.
- **Não apliquei `font-pub` ao corpo de nenhuma página** — ver **D-20**.
- **Não toquei no `AppLayout.jsx`** (D2) · **nem no `Editor.jsx`/`Components/Publicador/Mesa/`**
  (REG-03, outro dev **ativo**) · **nem em `AnunciarML.jsx`/`Tarefas.jsx`**. Diffs vazios, conferidos.
- **Não toquei em `ROADMAP.md`, `REQUIREMENTS-v26.md` nem `STATE.md`**, e **não rodei
  `state.advance-plan`** — o avanço de estado é do orquestrador.
- **Não corrigi o fail pré-existente** de `estrutura-grade-glide.test.js`.
- **Não apaguei `BarraDaConta.jsx` nem `AbasDaConta.jsx`** — ver §10.
- **Não construí o modal "Nova publicação"** (Fase 178). O CTA continua levando ao destino de hoje.
- **Não commitei `public/build/`** — é gitignored neste repo.
- ⚠️ **Não deployei, não dei push, não toquei no VPS, não consultei produção.** O deploy é decisão
  do usuário, em outra conversa.

---

## Threat Flags

Nenhuma superfície de segurança nova fora do `<threat_model>` dos 9 planos.

| Threat | Disposição | Estado no fechamento |
|---|---|---|
| **T-177-36** (página fora do manifest) | mitigate | **Coberto:** as 11 entradas conferidas **pelo JSON**, nunca por grep |
| **T-177-37** (commit na árvore compartilhada) | mitigate | **Coberto:** nenhum arquivo do outro dev no diff do range; nada a reverter. Este plano não usou `git add -A`, `stash`, `reset`, `checkout` nem `--amend` |
| **T-177-38** (afirmação visual sem prova) | mitigate | **Coberto:** declarado 3× nesta SUMMARY e no checkpoint que o executor **não viu tela renderizada** |
| **T-177-39** (deploy não autorizado) | mitigate | **Coberto:** nenhum comando de deploy, push ou VPS foi executado |
| **T-177-40** (nada novo exposto) | accept | A única superfície nova é `GET publicador/empresas-header`: **sem parâmetro de entrada** (IDOR estrutural, não prometido em comentário), `role:admin`, `throttle:120,1`, e o teste varre o corpo da resposta por `APP_USR` e `TG-` — **nunca** devolve `access_token`/`refresh_token` |
| **T-177-SC** (npm/composer install) | mitigate | **Coberto e VAZIO por construção:** diff dos 4 arquivos de pacote vazio no range. **A fase não instalou um único pacote. O gate de legitimidade de pacote não é devido** |

---

## Known Stubs

**Nenhum stub de dado.** Varredura dos arquivos criados/modificados na fase: nenhum
`=[]`/`={}`/`=null`/`=""` hardcoded fluindo para render, nenhum "não disponível"/"coming
soon"/placeholder.

Os **pontos de montagem** declarados nas waves 3-5 foram **todos ligados** até a wave 6: as abas
(177-05), o seletor de empresa e a pill de conexão (177-06), as 9 páginas (177-07/08). O único que
**continua apontando para o destino de hoje** é o CTA "Nova publicação" (e o ⌘K), que navega para a
seleção de produtos da conta em vez de abrir o modal de 620px — **Fase 178, por sequência do
roadmap, com o botão funcionando** (EXMP-03: nada nasce decorativo, nada nasce morto).

E o contrário de stub, que vale registrar: **contador não medido não desenha pílula nenhuma**.
`publicacoes = null` (acervo nunca coletado, ou conta sem Company) **não escreve `0`** — porque
`0` afirmaria "não há" quando a verdade é "não sabemos". Leitura por `Number.isFinite`, nunca por
`typeof === 'number'` (`NaN` é `number` e escreveria "NaN" na tela). Mesma disciplina na pill de
conexão: **estado não medido não desenha dot nem bolinha** — verde sem dado seria mentira e cinza
seria enfeite.

---

## Self-Check: PASSED

- `.planning/phases/177-…/177-09-SUMMARY.md` — FOUND (este arquivo)
- `public/build/manifest.json` — FOUND, 479 entradas, as **11** conferidas pelo JSON
- `public/build/assets/app-BGqs_iRL.css` — FOUND, 42 de 42 sondas de classe presentes
- `npm run build` — **verde** (37,74s)
- `npm run test:js` — **2789 · 2788 pass · 1 fail** (o pré-existente, nomeado)
- `phpunit tests/Unit/Publicador tests/Feature/Publicador` — **1777 · 0 fail · 11023 asserções**
- `phpunit tests/Feature/Phase134` — **37 · 0 fail** · `Phase135` — **243 · 0 fail**
- Os **8 gates de diff** de `b36037f9..HEAD` — resultado colado em §3
- `package.json` / `package-lock.json` / `composer.json` / `composer.lock` — **intactos** no range
- `git diff --diff-filter=D b36037f9..HEAD` — **vazio**: nenhum arquivo deletado em 8 planos
- `git status --short` sem untracked — **vazio**: nenhum arquivo rastreado modificado, nenhuma
  fixture órfã em `tests/js`
- Inventário REG-01 das 9 páginas — **77 conferências, 0 falhas**
- Conferência FID-01 contra o código — **68 conferências, 0 falhas reais**
- `ROADMAP.md`, `REQUIREMENTS-v26.md`, `STATE.md` — **não tocados**; `state.advance-plan` **não
  executado**
