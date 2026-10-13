---
phase: 179-visao-geral-cockpit
plan: 10
subsystem: fechamento
tags: [publicador, fechamento-de-fase, vite-build, manifest, css-construido, baselines, gate-de-diff, fidelidade-de-pixel, fid-01, rastro-de-requisito, checkpoint]

requires:
  - phase: 179-visao-geral-cockpit (plano 01)
    provides: "JornadaDosProdutosService::jornada() — os 4 estágios do anel"
  - phase: 179-visao-geral-cockpit (plano 02)
    provides: "AcoesDaContaService + AdiamentoDeAcoes + a `chave` aditiva em oQueFazerAgora()"
  - phase: 179-visao-geral-cockpit (plano 02B)
    provides: "ProgramasPublicadorService::prontoParaFase2()/noAr() — a FONTE ÚNICA e os 4 consumidores"
  - phase: 179-visao-geral-cockpit (plano 03)
    provides: "CockpitDaContaService — banner, saude, resumoDePublicacoes, acervoDeImagens, desempenho, recentes, atividade"
  - phase: 179-visao-geral-cockpit (plano 04)
    provides: "os 3 tokens novos + a hachura em tailwind.config.js"
  - phase: 179-visao-geral-cockpit (plano 05)
    provides: "as 23 props da action visaoGeral() + a rota POST publicador.acoes.adiar"
  - phase: 179-visao-geral-cockpit (plano 06)
    provides: "jornada.js + JornadaDosProdutos.jsx"
  - phase: 179-visao-geral-cockpit (plano 07)
    provides: "acoes.js + OQueFazerAgora + SaudeDaConta + PublicacoesNoMercadoLivre"
  - phase: 179-visao-geral-cockpit (plano 08)
    provides: "recentes.js + BannerDeIntegracao + FaixaDeDesempenho + PublicacoesRecentes + AtividadeRecente"
  - phase: 179-visao-geral-cockpit (plano 09)
    provides: "CockpitDaConta.jsx + a página no container de 1600px + ARQUIVOS_NOVOS com os 12"
provides:
  - "O fechamento MEDIDO da Fase 179: build, manifest pelo JSON, 165 classes provadas no CSS construído, 78 VALORES de token conferidos contra o hex do protótipo, as 4 baselines re-medidas, os 16 gates de diff com range, a cirurgia de gate enumerada"
  - "A tabela FID-01 CONSOLIDADA — 85 medidas, com a coluna Implementado lida no CÓDIGO por script"
  - "O rastro dos 24 REQ-IDs (VG-01..20 + EXMP-01..04) e dos 15 critérios do ROADMAP"
  - "A CONFIRMAÇÃO POR EXECUÇÃO de que 'Prontos para a Fase 2' foi RESOLVIDO no 179-02B (os planos 179-05 e 179-09 herdaram uma pendência FANTASMA)"
  - "O roteiro de conferência visual de 11 passos, para o usuário executar em produção na conta 459"
affects: [180-publicacoes, 181-produtos, 182-desempenho]

tech-stack:
  added: []
  patterns:
    - "⚠️ 4ª normalização da sonda de CSS, descoberta aqui: `stroke`/`fill` SÓLIDOS saem em HEX LITERAL (`stroke:#facc15`), não em `rgb(R G B / var(...))` — não há variável de opacidade para eles no Tailwind. Esperar decimal deu 2 falsas faltas"
    - "⚠️ O zero suprimido pelo cssnano também cai DEPOIS do sinal de menos: `-0.03em` sai `-.03em`. Normalizar só `([ :(,])0\\.` deixa a medida negativa reprovando sozinha"
    - "⚠️ Na variante, o PREFIXO faz parte do nome da classe: o seletor é `.hover:bg-pub-hover-0:hover`, não `.bg-pub-hover-0:hover`. Procurar a classe base dá AUSENTE por construção"
    - "Extrator de classe que lê STRINGS do arquivo colhe COMENTÁRIO também: 3 das 168 'classes' eram texto de comentário (`hover:`, `pub-row-new`, `text-pub-page\"`). Classificar antes de acusar"
    - "Heredoc do shell COLAPSA a barra invertida mesmo com `<<'EOF'`: sonda com regex vai em arquivo escrito por ferramenta, nunca por heredoc nem por `node -e`"
    - "Gate de território com `test -e` em CADA caminho: pegou `identidade/dadosDeExemplo.js`, que NÃO existe nesse caminho — o gate 16 era vazio por omissão para ele"

key-files:
  created:
    - .planning/phases/179-visao-geral-cockpit/179-10-SUMMARY.md
  modified:
    - .planning/STATE.md

key-decisions:
  - "⚠️ CORREÇÃO CONFIRMADA POR EXECUÇÃO: a divergência de 'Prontos para a Fase 2' NÃO está aberta. O 179-02B a resolveu em 12/10/2026 por decisão do usuário, e os planos 179-05 e 179-09 escreveram 'continua pendente' herdando a frase do 179-02 sem contar o 02B. Medido: a fonte única existe, os 4 consumidores a chamam, e ProntoParaFase2Test passa com 6 testes / 29 asserções. NÃO registrada como pendência"
  - "Das 8 medidas que reprovaram na 1ª rodada das duas sondas, 8 eram erro do MEDIDOR, não do código: 3 artefatos do extrator (comentário), 2 pela 4ª normalização (stroke em hex), 1 pelo zero do cssnano no negativo, 1 pelo prefixo da variante e 1 alvo que eu inventei (`bg-pub-danger/[0.4]`, que não existe no código). ZERO medidas do código reprovaram"
  - "Duas linhas da MINHA tabela FID-01 apontavam o arquivo errado (o caractere ⟳ vive em `acoes.js:399`, não no `SaudeDaConta.jsx`; e o `hover` da legenda é aplicado só no ramo `<a>`, de propósito). Corrigidas: 85 de 85 conferidas no código"
  - "Nenhum arquivo de código foi tocado. Este plano MEDE: `files_modified` é a SUMMARY e o bloco aditivo do STATE.md"
  - "VG-15 e critério 10 ficam PARCIAIS na forma que o usuário aprovou em 12/10/2026 (3 KPIs reais, `<section>` em vez de link) — decisão registrada, não débito silencioso. O link e os KPIs de venda são a Fase 182"
  - "Critério 12 entrega 5 de 6 cenários: 'ERP desconectado' NÃO é estado alcançável — medido, `BANNERS` só tem a chave `ml`. É ausência de DADO, não omissão de implementação"

metrics:
  duration: ~3h
  completed: 2026-10-13
  tasks: 2 de 3 (a 3 é o checkpoint humano, entregue e NÃO resolvido)
  arquivos_criados: 1
  arquivos_de_codigo_modificados: 0
  gates_de_diff: 16
  classes_provadas_no_css: 165
  valores_de_token_medidos: 78
  medidas_fid01_consolidadas: 85
  mutacoes_da_fase: 217
  commits_da_fase: 61

requirements-completed: []
---

# Fase 179 Plano 10: o fechamento medido da Visão Geral Summary

**A fase fecha medida: `npm run build` verde com as 11 entradas conferidas no `manifest.json` PELO
JSON (e 0 entrada própria para os 12 Components novos), 165 classes provadas presentes no CSS
construído com as 5 iscas ausentes, 78 VALORES de token conferidos contra o hex do protótipo com 3
contra-provas reprovando, as 4 baselines re-medidas sem uma única regressão (PHP 2007/14.953/0 · JS
3720/3719/1 pré-existente · Phase134+135 280/1.274/0), os 16 gates de diff vazios no range
`898d68c2..HEAD` com o range provado vivo, 4 asserções removidas — todas REAPONTE, cada uma com a
substituta nomeada e conferida presente —, a tabela FID-01 consolidada das 85 medidas com a coluna
Implementado lida no CÓDIGO, e uma CORREÇÃO: a divergência de "Prontos para a Fase 2" que dois planos
reportaram como aberta está RESOLVIDA, e eu confirmei por execução.**

## Performance

- **Duração:** ~3 h
- **Concluído:** 2026-10-13
- **Tarefas:** 2 de 3 executadas; a Task 3 é o **checkpoint humano**, entregue e **não resolvido**
- **Arquivos:** 1 criado (esta SUMMARY) + o bloco aditivo do `STATE.md`. **0 de código modificados** —
  este plano **MEDE, não corrige**
- **Commit de partida de todos os gates:** `898d68c2` · **HEAD medido:** `f2820dbd` · **61 commits** na fase

---

## ⚠️ NENHUM EXECUTOR VIU TELA RENDERIZADA — EM NENHUM DOS 10 PLANOS

**Não há navegador neste ambiente e o banco local não tem empresa com `ml_token`.** Toda a prova de
fidelidade da Fase 179 é, sem exceção:

1. medida lida em `new_layout_publicador/Visao Geral.dc.html` (o protótipo), com a linha citada;
2. classe conferida no HTML que o `react-dom/server` produziu, dentro do harness de esbuild;
3. **valor** conferido no **CSS construído** pelo Vite;
4. contrato de servidor conferido por teste automatizado;
5. build e manifest conferidos por leitura estruturada do JSON;
6. prova por **mutação** (217 na fase), com restauração conferida por `sha256`.

⚠️ **A conferência visual é do usuário, em produção, na conta 459** — está no checkpoint, no fim deste
documento. **Nada foi deployado, nada foi empurrado, nenhum comando no VPS, em nenhum dos 10 planos.**
⚠️ **A leitura de produção segue bloqueada nesta sessão** (motivo "Production Reads"): é por isso que
o roteiro é para o usuário executar, e não para mim.

---

## 1. Build e manifest — conferidos PELO JSON, nunca por grep

`npm run build` → **verde, exit 0, 35,38 s**. `public/build` é ignorado pelo git (`.gitignore:41`),
então o build **não sujou a árvore**.

⚠️ **Nunca por `grep`:** o hash do arquivo sai com hífen (`VisaoGeral-BTucwMYP.js`) e um `grep` do
nome passaria pelo motivo errado. ⚠️ **Página React fora do manifest SOME da aplicação** — tela
branca em produção, sem erro no servidor. Lido com `node --input-type=commonjs` + `require()`, porque
o `package.json` é `"type":"module"`.

| # | Entrada no manifest | Arquivo gerado |
|---|---|---|
| 1 | `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` | `assets/VisaoGeral-BTucwMYP.js` |
| 2 | `resources/js/Pages/Mlb/Publicador/Produtos.jsx` | `assets/Produtos-Q-0VU2zJ.js` |
| 3 | `resources/js/Pages/Mlb/Publicador/Produto.jsx` | `assets/Produto-DiUlxo1E.js` |
| 4 | `resources/js/Pages/Mlb/Publicador/Alavancas.jsx` | `assets/Alavancas-COk8gWgd.js` |
| 5 | `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx` | `assets/Configuracoes-Bm-_-Vur.js` |
| 6 | `resources/js/Pages/Mlb/Publicador/PublicacaoEmLote.jsx` | `assets/PublicacaoEmLote-GNbn-zun.js` |
| 7 | `resources/js/Pages/Mlb/MeusAnuncios.jsx` | `assets/MeusAnuncios-DR1-tXjw.js` |
| 8 | `resources/js/Pages/Mlb/AnunciosHistorico.jsx` | `assets/AnunciosHistorico-ogEiFom-.js` |
| 9 | `resources/js/Pages/Mlb/AnunciarMassa.jsx` | `assets/AnunciarMassa-7m269fFU.js` |
| 10 | `resources/js/Pages/Mlb/AnunciosEmpresas.jsx` | `assets/AnunciosEmpresas-F5ryqNtJ.js` |
| 11 | `resources/js/app.jsx` | `assets/app-B7EaNMTX.js` |

**`manifest ok 11 de 11` · 477 entradas no manifest inteiro · 0 sem campo `file` · 0 fora.**
**ISCA:** uma página inexistente (`Publicador/NaoExiste.jsx`) sai **ausente** — a leitura não é
vazia por construção.

⚠️ **E os 12 arquivos novos de `visaogeral/` NÃO têm entrada própria: medido, 0 de 12.** São
Components, entram no grafo do `app.jsx`, e entrada própria para eles seria sintoma de import
dinâmico acidental. Correto.

**O CSS do app, lido pelo JSON** (`manifest['resources/js/app.jsx'].css[0]`):
`assets/app-D1n4ohkR.css`, **219.325 bytes, 5.272 barras invertidas**.

---

## 2. ⚠️ O CSS construído: presença com ISCA, e depois VALOR

**Classe que não gera utilitário não existe para a tela.** É o gate mais importante desta wave: tudo
pode estar certo no JSX e a tela sair sem estilo se o Tailwind não varreu o arquivo.

⚠️ **A sonda foi escrita em ARQUIVO e rodada com `node <arquivo>`** — nunca `node -e`, nunca
heredoc. **E a primeira tentativa por heredoc FALHOU exatamente como o plano avisou:** mesmo com
`<<'EOF'`, a barra invertida de `/\\/g` chegou ao arquivo como `/\/g` e o Node estourou
`SyntaxError: Invalid regular expression`. A sonda final não contém nenhuma barra invertida literal:
ela usa `String.fromCharCode(92)`.

### 2a. Presença — 165 classes reais, 0 faltando

As classes foram **extraídas do CÓDIGO** (as strings dos 12 arquivos de `visaogeral/` + a página),
filtradas pelos grupos que o plano pede (`pub-*`, `text-[…]`, `h-[…]`, `w-[…]`, `grid-cols-[…]`,
`rounded-[…]`, `tracking-[…]`, `-mx-6`, `-mb-6`, `hover:*`), e procuradas no CSS construído.

⚠️ **A sonda NÃO tenta reproduzir a regra de escape do Tailwind.** Ela **desescapa o CSS inteiro**
(tira as 5.272 barras invertidas) e depois casa `.classe` exigindo que o caractere seguinte seja
**fim de seletor** (`{ , : > + ~` espaço `)`). Os dois motivos: (a) a sonda do 177-09 acusou 19
classes "FALTANDO" tentando reproduzir o escape, e o erro era dela; (b) o fim de seletor impede que
`.font-pub` case **dentro** de `.font-pub-mono`.

| grupo | quantas | resultado |
|---|---|---|
| extraídas do código | **168** | — |
| **artefatos do extrator** (texto de comentário, não classe) | **3** | classificados abaixo |
| **classes reais** | **165** | **FALTANDO: NENHUMA** |
| **iscas** (`w-[1601px]`, `text-[8.6px]`, `bg-pub-inexistente`, `grid-cols-[393px]`, `h-[177px]`) | 5 | **as 5 AUSENTES** ✔ |

#### ⚠️ Os 3 "faltando" eram ARTEFATO DO MEU EXTRATOR — e a classe real de cada um está PRESENTE

O extrator lê **toda string** do arquivo, e comentário com *backtick* é string para ele:

| o que a sonda acusou | de onde veio | a classe REAL |
|---|---|---|
| `hover:` (prefixo solto) | **comentário** em `JornadaDosProdutos.jsx:275` — *"o `hover:` vem por ÚLTIMO na lista do `cn()`"* | `hover:bg-pub-hover-0` → **presente** (`.hover:bg-pub-hover-0:hover{…rgb(18 18 18…)}`) |
| `pub-row-new` (nome do token, sem utilitário) | **comentário** em `PublicacoesRecentes.jsx:108` — *"`#14120A` já tem nome na camada: `pub-row-new`"* | `bg-pub-row-new` → **presente** |
| `text-pub-page"` (com a aspa colada) | **comentário** em `OQueFazerAgora.jsx:51` — `` `<AlertTriangle className="fill-pub-danger text-pub-page" />` `` | `text-pub-page` → **presente** |

⚠️ **Registrado como achado da SONDA, não do código** — é a lição que a 178-04 escreveu ("6 de 6
eram falso positivo da sonda") repetida por outro caminho.

**Prova de prefixo — seletores DISTINTOS no CSS:**

| par | medido |
|---|---|
| `bg-pub-accent` × `bg-pub-accent-sunken` | os dois **presentes**, separados |
| `font-pub` × `font-pub-mono` | os dois **presentes**, separados |
| `text-[11px]` × `text-[11.5px]` | os dois **presentes**, separados |
| `bg-pub-hover` × `bg-pub-hover-0` | `bg-pub-hover` presente; **`bg-pub-hover-0` ausente — e está CERTO** |
| `bg-pub-raised` × `bg-pub-raised-0` | **`bg-pub-raised` ausente — e está CERTO**; `bg-pub-raised-0` presente |

⚠️ **Os dois "ausentes" acima são o JIT funcionando:** `hover-0` só é usado na variante
(`hover:bg-pub-hover-0`) e ninguém usa a classe base; `raised` nunca é usado (o estágio selecionado
usa `raised-2`). **Classe que nenhum `.jsx` escreve não entra no CSS** — e um "presente" aqui seria o
sintoma de que a sonda casa com qualquer coisa.

### 2b. ⚠️ VALOR — 78 medidas contra o hex do protótipo, 0 divergências

Presença não é fidelidade. **Classe presente com valor errado é a falha silenciosa que a FID-01
existe para pegar.** Li o **corpo da regra** no CSS construído e comparei com o valor declarado.

**Os 4 tokens NOVOS do 179-04 — e as linhas do protótipo RE-CONFERIDAS por `grep -n`:**

| classe | valor no CSS construído | protótipo | linha (re-medida) |
|---|---|---|---|
| `hover:bg-pub-hover-0` | `rgb(18 18 18 / var(--tw-bg-opacity, 1))` = **#121212** | `style-hover="background:#121212"` | **`:137`** ✔ |
| `bg-pub-raised-0` | `rgb(19 19 19 / …)` = **#131313** | `bg: top ? '#131313' : '#111111'` | **`:516`** ✔ |
| `bg-pub-accent-sunken` | `rgb(31 27 8 / …)` = **#1F1B08** | `IC = { pub: ['#1F1B08', …] }` | **`:588`** ✔ |
| `bg-pub-hachura` | `repeating-linear-gradient(135deg,#3f3f46 0 3px,#262626 3px 6px)` | `barBg: k === 'none' ? 'repeating-linear-gradient(135deg,#3F3F46 0 3px,#262626 3px 6px)'` | **`:479`** ✔ |

**As 4 linhas que o 179-04 declarou estão EXATAS.** (Aquele plano já havia corrigido as 5 referências
erradas do `179-04-PLAN.md`; re-medi e as corrigidas conferem.)

**Os outros 74, por grupo — todos OK:**

| grupo | quantos | exemplo medido |
|---|---|---|
| **variantes `hover:`** | 5 | `.hover:border-pub-line-off:hover{…rgb(63 63 70…)}` = `#3F3F46` |
| **cores sólidas** (`bg-`/`text-`/`border-`/`border-t-`) | 30 | `bg-pub-surface` → `rgb(17 17 17)` = `#111111` |
| **`stroke`/`fill` sólidos** | 7 | `stroke-pub-accent` → `stroke:#facc15` = `#FACC15` |
| **raios `rounded-pub-*`** | 6 | `rounded-pub-card` → `16px` · `rounded-pub-control-sm` → `7px` |
| **animação e sombra** | 2 | `animate-pub-spin` → `spin .7s linear infinite` · `shadow-pub-seg-ativo` → `inset 0 0 0 1px #333333` |
| **tintas com ALFA (hex de 8 dígitos)** | 11 | `bg-pub-danger/[0.13]` → **`#ef444421`** (`0x21` = 33 = round(0,13 × 255)) |
| **geometria do 179-09** | 14 | `max-w-[1600px]` → `1600px` · `grid-cols-[minmax(0,1fr)_392px]` → `minmax(0,1fr) 392px` · `tracking-[-0.03em]` → `-.03em` |

**CONTRA-PROVAS — a sonda distingue valor errado (as 3 REPROVARAM):**

| contra-prova | resultado |
|---|---|
| `bg-pub-surface` contra `#112233` | **REPROVOU** ✔ |
| `bg-pub-danger/[0.13]` contra o byte de `0.12` (`#ef44441f`) | **REPROVOU** ✔ |
| `rounded-pub-card` contra `17px` | **REPROVOU** ✔ |

### ⚠️ ACHADO: a MINHA sonda de valor reprovou 5 na 1ª rodada — e os 5 eram erro DELA

É a lição do 177-09, do 178-04 e das quatro armadilhas do 179-06/07/08 repetida. **Nenhuma linha de
código foi tocada** — o que estava errado era a medida, e o plano manda registrar o que mediu nos
dois casos. **Duas destas são NOVAS e não estavam na lista de armadilhas da fase:**

| # | o que eu esperava | o que o CSS emite | classe do erro |
|---|---|---|---|
| 1 | `.bg-pub-hover-0:hover{` | **`.hover:bg-pub-hover-0:hover{`** — na variante, o **prefixo faz parte do nome da classe**; procurar a classe base dá AUSENTE por construção | refinamento da 3ª normalização da 178-04 |
| 2 | `stroke-pub-accent` → `rgb(250 204 21 / var(…))` | **`stroke:#facc15`** — ⚠️ **`stroke`/`fill` SÓLIDOS saem em HEX LITERAL**: não há variável de opacidade para eles no Tailwind. **4ª normalização, NOVA** | 2 falsas faltas |
| 3 | `tracking-[-0.03em]` → `-0.03em` | **`-.03em`** — o cssnano tira o zero **também depois do sinal de menos**; normalizar só `([ :(,])0\.` deixa a medida negativa reprovando sozinha | 1 falsa falta |
| 4 | `bg-pub-danger/[0.4]` presente | **alvo que EU inventei** — não existe no código (conferido na lista extraída); a tinta real de 0.4 é `border-pub-danger/[0.4]`, e ela **bate** (`#ef444466`) | 1 falsa falta |

➡️ **Depois de corrigir a sonda: 78 medidos, 0 falhas.** As três armadilhas que a 178-04 documentou
(decimal para sólido, hex de 8 dígitos para alfa, pseudo antes da chave) **já estavam tratadas** e
não produziram uma única falsa falta.

---

## 3. As quatro baselines — re-medidas por mim, não copiadas

| Suíte | Baseline de partida | **Medido agora (13/10/2026)** | Veredito |
|---|---|---|---|
| `tests/Unit/Publicador` + `tests/Feature/Publicador` | 1848 · 11.421 · 0 fail (quick `261012-mbw`) | **2007 testes · 14.953 asserções · 0 falhas** (7 min 34 s, 338 MB) | **+159 casos / +3.532 asserções**. Nenhuma regressão |
| JS inteiro (`npm run test:js`) | 3019 · 3018 pass · 1 fail | **3720 testes · 27 suítes · 3719 pass · 1 fail** (18,58 s) | **+701 casos**, **a MESMA única falha** |
| `tests/Feature/Phase134` + `Phase135` | re-medir | **280 testes · 1.274 asserções · 0 falhas** (1 min 58 s) | sem fail |

⚠️ **Os números batem com o que o plano previa** (PHP ≥ 2007 / 14.953 / 0; JS ≥ 3720 com exatamente
1 fail) — medidos, não copiados.

### O único fail aceito, nomeado e NÃO corrigido

```
tests/js/estrutura-grade-glide.test.js:122
✖ Características secundárias nasce recolhido (é o grupo que mais infla)
  AssertionError: The input did not match /RECOLHIDOS_INICIAIS = \[G_SECUND\]/
  actual: const RECOLHIDOS_INICIAIS = [];
```

É **pré-existente**, de **outro território** (`GradeAnuncioGlide`, o editor em massa) e de **outra
fase**. ⚠️ **Não corrigido de propósito.** ⚠️ **Nota de precisão:** o plano e o briefing citam
`:123`; o arquivo reporta **`:122`** — o 179-07 e o 179-08 já mediram `:122`. É o mesmo teste.

**Deprecations: 54 na suíte do Publicador** (47 pré-existentes + 5 das classes novas da fase com
`@group phase179` + 2 dos `/** @test */` que seguem a convenção do próprio
`PainelVisaoGeralServiceTest`), e **132** em Phase134+135. **Deprecations do PHPUnit não são
falhas** — são a convenção de `@group` que **75 arquivos** deste projeto usam. **Fora de escopo.**

---

## 4. Os 16 gates de diff — SEMPRE com o range `898d68c2..HEAD`

⚠️ **Com o range, sempre.** `git diff --stat -- <dir>` **depois de commitar** compara a **árvore de
trabalho** e sai **vazio POR CONSTRUÇÃO**. ⚠️ **E gate só prova algo se o alvo existir:** gate
apontando para caminho inexistente passa **por omissão**. Cada caminho foi conferido com `test -e`
**antes** de eu acreditar no vazio dele.

| # | Gate | Alvo | `test -e` | Resultado |
|---|---|---|---|---|
| 1 | o `AppLayout` não muda | `resources/js/Layouts/AppLayout.jsx` | ok | **VAZIO** |
| 2 | o editor do outro dev não é tocado | `resources/js/Pages/Mlb/Publicador/Editor.jsx` | ok | **VAZIO** |
| 3 | a mesa do editor não é tocada | `resources/js/Components/Publicador/Mesa` | ok | **VAZIO** |
| 4 | `App\Support\Publicador` não muda | `app/Support/Publicador` | ok | **VAZIO** |
| 5 | `MlPublicacaoService` não muda | `app/Services/Mlb/Publicacao/MlPublicacaoService.php` | ok | **VAZIO** |
| 6a | nenhuma migration | `database` | ok | **VAZIO** |
| 6b | nenhuma migration | `database/migrations` | ok | **VAZIO** |
| 7 | `header/` e `identidade/` — vazio OU só acréscimo aditivo | os 2 diretórios | ok | `header/` **VAZIO**; `identidade/` **+24/−1** e **+17/−2** (as 2 props aditivas do 179-08) |
| 8 | `atalhosDoHeader.js` não muda | `.../header/atalhosDoHeader.js` | ok | **VAZIO** |
| 9 | `PainelVisaoGeral.jsx` **intacto** (o órfão declarado) | `resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx` | ok | **VAZIO** |
| 10 | estado é do orquestrador | `.planning/ROADMAP.md` `.planning/REQUIREMENTS-v26.md` | ok | **VAZIO nos 2** |
| 11 | **0 linhas removidas** em `routes/` | `routes/` → `grep '^-'` | ok | **+19 / −0** · `grep '^-'` **vazio** |
| 12 | nenhum pacote | `package.json` `package-lock.json` `composer.json` `composer.lock` | ok | **VAZIO nos 4** |
| 13 | nenhum arquivo deletado | `--diff-filter=D --name-only` | — | **VAZIO** |
| 14 | Creative Engine intocado (aviso do `CLAUDE.md`) | `AnunciarML.jsx` `Publicador/Tarefas.jsx` `app/Services/Creative` | ok | **VAZIO** |
| 15 | em `resources/js/Pages`, **só** `VisaoGeral.jsx` | `resources/js/Pages` | ok | **1 arquivo: `Mlb/Publicador/VisaoGeral.jsx`** |
| 16 | `tempo.js` e `dadosDeExemplo.js` não mudam | ⚠️ **ver o achado abaixo** | ⚠️ | **VAZIO no caminho REAL** |

### ⚠️ ACHADO: o gate 16 era VAZIO POR OMISSÃO — e foi o `test -e` que pegou

O plano manda medir `.../tempo.js` e `.../dadosDeExemplo.js`. **Nenhum dos dois existe em
`identidade/`:**

| caminho medido primeiro | `test -e` | caminho REAL |
|---|---|---|
| `…/Publicador/identidade/tempo.js` | **NÃO EXISTE** | `resources/js/Components/Mlb/Publicador/tempo.js` |
| `…/Publicador/identidade/dadosDeExemplo.js` | **NÃO EXISTE** | `resources/js/Components/Mlb/Publicador/dadosDeExemplo.js` |

Os dois (mais `SeloExemplo.jsx`) vivem **um nível acima**, em `Components/Mlb/Publicador/`. Sem o
`test -e` eu teria reportado "intocados" sem ter medido nada. **Refeito nos 3 caminhos reais: diff
VAZIO nos três.** ⚠️ É a mesma classe do `MlPublicacaoService` que o 179-02B pegou — **e é o segundo
caminho de gate errado desta milestone.** Os planos 180/181 devem usar os caminhos reais.

### ⚠️ A prova de que os 16 vazios NÃO são vazios por construção

O **mesmo range**, aplicado aos caminhos que eu **espero não-vazios**, devolve os **38 arquivos da
fase**:

```
git diff --stat 898d68c2..HEAD -- app routes tests resources/js tailwind.config.js
→ 38 files changed, 19.346 insertions(+), 92 deletions(-)
```

| grupo | arquivos | ± |
|---|---|---|
| `app/` (os 5 serviços novos + os 3 tocados + o controller) | 8 | **+2.357 / −52** |
| `resources/js/Components/.../visaogeral/` (os 12 novos) | 12 | **+3.952** |
| `resources/js/Components/.../identidade/` (as 2 props aditivas) | 2 | **+41 / −3** |
| `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` | 1 | **+76 / −6** |
| `routes/mlb_anuncios.php` | 1 | **+19 / −0** |
| `tailwind.config.js` | 1 | **+21 / −0** |
| `tests/` (PHP e JS) | 13 | **+12.880 / −31** |

**Logo o range está vivo.** E `.planning/STATE.md` tem **+570 / −0** no range — os blocos aditivos
escritos à mão pelas waves, **0 linhas removidas**, conferido por `git diff --numstat`.

---

## 5. Cirurgia de gate — as 4 asserções removidas, ENUMERADAS

```
git diff 898d68c2..HEAD -- tests/ | grep '^-' | grep -v '^---' | grep -i assert
→ 4 linhas       (isca: 1.991 linhas com `assert` ACRESCENTADAS no mesmo range)
```

⚠️ **O gate cru é VAZIO AO CONTRÁRIO para reaponte de valor** — mudar `assertSame(2, …)` para
`assertSame(3, …)` remove e acrescenta a linha **por construção**. Como o 179-02B recomendou, cada
`-assert` foi casado com o `+assert` do **mesmo teste**, e a substituta foi **conferida presente no
arquivo agora**:

| # | Linha REMOVIDA | Classificação | Substituta, CONFERIDA presente agora |
|---|---|---|---|
| 1 | `assertSame(2, $daVisaoGeral, 'a regra original mudou — reler PainelVisaoGeralService:592')` | **(a) REAPONTADA**, com VETO explícito ao valor antigo | `NovaPublicacaoDoHeaderTest.php:381` → `assertSame(3, $daVisaoGeral, 'ERA 2 antes do alinhamento de 12/10/2026 … se voltou a 2, alguém desfez a decisão do usuário')` |
| 2 | `assertEqualsCanonicalizing(['PUB','PARC'], $this->skus($saida,'prontos_fase2'))` | **(c) ENDURECIDA** (o conjunto cresceu) | `NovaPublicacaoDoHeaderTest.php:387` → `assertEqualsCanonicalizing(['PUB','PARC','ERRO-COM-ANUNCIO'], …)` |
| 3 | `assert.ok(corpo !== -1, 'container do corpo (max-w-[1240px]) não encontrado')` | **(a) REAPONTADA** — o **predicado é o MESMO**; mudou o literal que localiza cada página | `publicador-vocabulario-novo.test.js:487` → `assert.ok(corpo !== -1, \`container do corpo ("${marca}") não encontrado em ${nome}\`)` |
| 4 | `assert.ok(header < corpo, '…com o px-8 dele o -mx-6 não encosta nas bordas')` | **(a) REAPONTADA** — predicado `header < corpo` **idêntico** | `publicador-vocabulario-novo.test.js:488` → a mesma asserção, com "padrão" em vez de "px-8" |

### ⚠️ **(b) REMOVIDAS = ZERO.** ✔

⚠️ **As 2 do `179-09` são reaponte legítimo, como o briefing dizia — conferi em vez de acusar cego.**
O predicado `header < corpo` e `corpo !== -1` é **byte a byte o mesmo**; o que virou tabela
(`CONTAINER_POR_PAGINA`, 6 páginas) é o **literal com que cada página é localizada**. E o teste ficou
**mais forte**: ganhou `assert.ok(marca, …)` por página mais uma asserção nova provando que **toda**
página com header tem container declarado. **`+assert (1.991) > -assert (4)` por três ordens de
grandeza.**

### As 13 linhas removidas de `tests/` que NÃO são asserções — uma por uma

| quantas | o que é |
|---|---|
| **7** | o docblock do GATE DE CONSISTÊNCIA de `NovaPublicacaoDoHeaderTest` — *"a regra … é copiada de `prontosParaFase2()` … porque lá ela é `private`"*. **Reescrito** pelo 179-02B: a regra deixou de ser cópia e virou fonte única. Comentário, não asserção |
| **1** | a linha de `TAMANHOS_PERMITIDOS`. **ADITIVA:** saiu `10, 10.5, …, 34` e entrou `8.5, 10, 10.5, …, 34` — conferido no arquivo, **nenhum valor removido**, só o `8.5` acrescentado |
| **4** | um bloco de comentário sobre o `mx-auto max-w-[1240px]` do corpo — substituído pelo comentário da tabela nova |
| **1** | `const corpo = fonte.indexOf('mx-auto max-w-[1240px]')` — o literal cravado, substituído pela leitura da tabela `CONTAINER_POR_PAGINA` |

---

## 6. As três auditorias — GATE-05, prefixo e FID-02

### GATE-05 — asserção de desabilitado SEM o `=`: **0 achados**

Varri os **6 arquivos de teste** da fase por `assert…/disabled/` sem o sinal de igual.
`assert.match(tag, /disabled/)` é **asserção vazia neste projeto**, porque as classes têm
`disabled:opacity-40`.

**Resultado: 0.** As **7** asserções de desabilitado que existem usam `/disabled=/` ou
`/aria-disabled="true"/`, **com o `=`** (6 em `publicador-visao-geral-acoes.test.js`, 1 em
`…-recentes.test.js`).
**CONTRA-PROVA:** injetei `assert.match(tag, /disabled/);` num arquivo de teste da sonda e ela
**PEGOU**. A auditoria não é decorativa.

### Auditoria de prefixo — `includes('text-[` e `includes('font-pub`: **0 achados**

**Resultado: 0 em 6 arquivos.** ⚠️ **ISCA:** apontada contra um arquivo com as duas formas, a sonda
**acha as 2**. Diferente da 178-04 (6 acusações, 6 falso positivo), aqui não houve nem acusação —
as waves 2/3 desta fase trocaram `includes` por regex com negative-lookahead de propósito.

### Auditoria FID-02 — as quatro justificativas VETADAS: **0 usos**

Varri os **12 arquivos novos** + a página + **as 10 SUMMARYs da fase** por *"ficou mais parecido com
o resto do sistema"*, *"mantive o token `ecf-*` por consistência"*, *"o componente que já existe faz
parecido"* e *"o Tailwind não tem esse passo"* — e também pelas formas parciais (*"faz parecido"*,
*"por consistência"*, *"parecido com o resto"*).

**Resultado: 0 casamentos, em nenhuma das formas.** ⚠️ Mais: o 179-04 encontrou o caso em que a
justificativa vetada *seria* a tentadora (reusar `bg-pub-hover`/`bg-pub-raised` no lugar dos `-0`) e
**recusou explicitamente**, escrevendo *"'usar o token parecido que já existe' é justificativa
vetada (FID-02)"* no próprio `tailwind.config.js`.

### Soma das mutações da fase: **217 aplicadas** (o plano pedia ≥ 128)

| plano | nominais | reprovaram | observação |
|---|---|---|---|
| 179-01 | 12 | 12 | — |
| 179-02 | 20 | 19 | **1 EQUIVALENTE por construção** (`tomorrow()` → `now()->addDay()`; o `setTime(8,0)` normaliza). Declarada, não contada — substituída por M09b/M09c, que reprovam |
| 179-02B | 13 | 12 | **1 SOBREVIVEU e NÃO era equivalente: era LACUNA DE COBERTURA real** (M12, `bucketDaFase()`). Fechada e reaplicada como M12b, que reprova |
| 179-03 | 15 | 15 | — |
| 179-04 | 9 | 9 | a M09 quebra o **medidor** e a isca a pega |
| 179-05 | 11 | 10 | **1 EQUIVALENTE** (mover o `abort_unless` para depois do `adiar()`: o serviço também recusa em silêncio) |
| 179-06 | 32 | 32 | 2 sobreviveram na 1ª rodada e **eram as 2 lacunas reais** (estado que só nasce de clique) |
| 179-07 | 42 | 42 | **1 RECUSADA antes de aplicar** (alvo com `// hue 30` dentro) e reescrita |
| 179-08 | 40 | 40 | 2 sobreviveram na 1ª rodada: 1 lacuna real (`/href="…"/` sem o `\s`) e 1 erro do aplicador (trocou comentário) |
| 179-09 | 23 | 23 | **3 ABORTARAM antes de contar** pela trava do harness (alvo abrangia comentário JSX + código) |
| **total** | **217** | **214** | **2 declaradas equivalentes · 1 lacuna de cobertura encontrada e fechada · 5 recusadas/abortadas antes de contar** |

⚠️ **O achado metodológico da fase está nas recusas:** 5 mutações foram barradas **antes** de
produzir número, porque o alvo casava em comentário. Com `sed`/`String.replace` cego as cinco
teriam caído no comentário, o teste passaria e o relatório registraria **cinco furos que não
existem** — é literalmente a origem de 3 dos "furos" da Fase 177.

---

## 7. ⚠️ A CORREÇÃO: "Prontos para a Fase 2" NÃO está pendente — confirmado POR EXECUÇÃO

**Os planos `179-05` (pergunta 1) e `179-09` (pergunta 3) escrevem que *"a decisão de 'Prontos para a
Fase 2' continua pendente"*. ESTÁ ERRADO.** Os dois herdaram a frase da SUMMARY do `179-02` sem
contar o **`179-02B`**, que foi criado exatamente para resolvê-la — e resolveu.

**Medido por mim, nesta sessão, nos três eixos:**

| # | O que medi | Resultado |
|---|---|---|
| 1 | a **fonte única** existe e é **uma só** em `app/` | `ProgramasPublicadorService.php:541` → `public static function prontoParaFase2(array $p): bool`; e `:489` → `public static function noAr(array $p): bool`. **Nenhuma segunda definição** |
| 2 | os **quatro consumidores** a CHAMAM | `JornadaDosProdutosService.php:232` · `NovaPublicacaoService.php:147` · `PainelVisaoGeralService.php:661` · `ProgramasPublicadorService.php:449` (`bucketDaFase()` via `self::noAr()`), mais `PainelVisaoGeralService.php:349` (`noArPorFase()`) |
| 3 | o **gate** passa | `artisan test --filter=ProntoParaFase2Test` → **6 passed, 29 assertions**, 8,29 s |

➡️ **A decisão foi do usuário em 12/10/2026** (*"alinhar os três lugares"*), apresentada com três
opções e com o aviso de que mudava o modal já aprovado em produção. Ela **superou a "Exclusão 2"**
declarada no `178-01`, que sobrevive só para `erro` **sem** anúncio vivo.

➡️ ⚠️ **NÃO registrada como pendência.** O que fica registrado é a **pendência fantasma**: nenhuma
sessão futura deve "reabrir" essa decisão lendo a pergunta 1 do 179-05 ou a pergunta 3 do 179-09.
⚠️ **O que SEGUE em aberto é outra coisa, e é menor:** o número de produção da classe de fronteira
(base `FAILED` + anúncio `CREATED` vivo + sem kit + não composta) **nunca foi medido**, porque a
leitura de produção está bloqueada. A decisão foi tomada sem esse número, e o usuário foi informado.

---

## 8. Árvore e órfãos

- `git status --short -- tests/js` → **VAZIO**. **Nenhuma fixture órfã** (`ls tests/js/.*.mjs` →
  nenhum). Os stubs do harness de esbuild são apagados pelo `after()` de cada gate.
- `git status --short | grep -v '^??'` → **VAZIO**. Nenhum arquivo rastreado modificado, nem meu nem
  de outra sessão, no momento da medição.
- **Não meus, intocados:** `?? tests/Feature/CompanyPortfolioAccessTest.php` e `public/images/*`
  (3 arquivos). Já estavam não rastreados antes da fase. **Não tocados, não commitados, não
  apagados** — a árvore é compartilhada com outro dev ATIVO. `public/build` é gitignored
  (`.gitignore:41`), então o build não sujou nada.
- **Conjunto CONFERIDO:** os arquivos **novos** de `resources/js` no range são **exatamente 12**
  (`git diff --name-only --diff-filter=A`), e os **12 estão** em `ARQUIVOS_NOVOS` do gate de
  vocabulário — conferidos nome a nome. A lista é constante de propósito: **esquecer um faria o gate
  passar por OMISSÃO**. E o `GATE-03` do próprio gate compara a lista com a **listagem real** de
  `identidade/` e `visaogeral/`, fechando a omissão estruturalmente.
- `artisan route:list --name=publicador` → **107 linhas**; `--name=acoes.adiar` → **1 rota**
  (`POST mlb/anuncios/publicador/empresas/{conta}/acoes/adiar`). **0 linhas removidas de `routes/`.**
- **Nenhuma migration na fase inteira** — nada a validar no VPS: nenhuma coluna nova, nenhum `enum`,
  nenhum índice, nenhuma FK. As armadilhas de MariaDB/MySQL que o SQLite dos testes não pega **não se
  aplicam**.

---

## A. FID-01 CONSOLIDADA — 85 medidas, protótipo × implementado

⚠️ **A coluna "Implementado" foi conferida no CÓDIGO, por script**, lendo a fonte **sem linhas de
comentário** (comentário não é implementação) — não copiada dos planos nem das SUMMARYs das waves.
Protótipo = `new_layout_publicador/Visao Geral.dc.html` (**620 linhas, 66.341 bytes**), com a linha
**re-conferida por `grep -n`/`sed -n`** em cada item estrutural.

**ISCAS da tabela** (`grid-cols-[185px_…]`, `max-w-[1601px]`, `bg-pub-hachura-xyz`): **as 3 saíram
AUSENTES** — a tabela não é cega.

### A.1 — Jornada dos produtos (21 medidas) · `JornadaDosProdutos.jsx` + `jornada.js`

| Elemento | Protótipo | Implementado (medido no código) |
|---|---|---|
| card: raio/borda/fundo + padding 22/24 | `:40` | `rounded-pub-card border border-pub-line bg-pub-surface px-[24px] pb-[24px] pt-[22px]` |
| grid anel + estágios, 184px | `:77` | `grid-cols-[184px_minmax(0,1fr)]` |
| caixa do anel 176×176 | `:78` | `relative h-[176px] w-[176px]` |
| trilho `r=74` | `:80` | `r="74"` |
| trilho: cor + espessura | `:80` | `stroke-pub-line-subtle-2 stroke-[14]` |
| percentual 34px / −0.03em / tabular | `:86` | `text-[34px] font-semibold tracking-[-0.03em] tabular-nums` |
| grid dos 4 estágios, gap 12 | `:92` | `grid grid-cols-4 gap-[12px]` |
| badge PRÓXIMO PASSO | `:97` | `rounded-pub-badge bg-pub-accent px-[6px] py-[2px] text-[10.5px] font-bold tracking-[0.06em] text-pub-page` |
| número do estágio 32px | `:99` | `text-[32px] font-semibold leading-none tracking-[-0.03em] tabular-nums` |
| letra do avatar da pilha | `:104` | `text-[8.5px]` |
| seta entre estágios, `right:-13px`, `z:2` | `:112` | `right-[-13px] top-1/2 z-[2]` |
| barra 8px, gap 3px | `:119` | `flex h-[8px] gap-[3px]` |
| hachura no segmento `none` (VG-07) | `:479` | `bg-pub-hachura` |
| painel: raio 12, fundo sunken | `:128` | `rounded-pub-panel border border-pub-line-subtle bg-pub-sunken` |
| linha do painel: grid de 5 colunas | `:137` | `grid-cols-[minmax(0,1.6fr)_130px_150px_minmax(0,1.2fr)_150px]` |
| linha do painel: hover `#121212` | `:137` | `hover:bg-pub-hover-0` |
| divisória da linha `#1A1A1A` (**V-1**) | `:489` | `border-t border-pub-hover-3` |
| empty state: `max-w:560px` na **COLUNA** (**V-2**) | `:65` | `flex max-w-[560px] flex-col gap-[8px]` |
| a ordem do ANEL é OUTRA que a dos cartões | `:483` | `ORDEM_ANEL` (constante própria) |
| o `+N` sai do **total** do estágio, não da amostra | `:478` | `restanteDaPilha` |
| `grow` mínimo `0.0001` na barra | `:479` | `0.0001` |

### A.2 — "O que fazer agora" (13 medidas) · `OQueFazerAgora.jsx` + `acoes.js`

| Elemento | Protótipo | Implementado (medido no código) |
|---|---|---|
| trilho do segmented: bg `#161616`, raio 10 | `:170` | `rounded-pub-sub border border-pub-line-subtle bg-pub-hover-2 p-[3px]` |
| opção: h 28, raio 7, nowrap, pointer | `:172` | `flex h-[28px] cursor-pointer items-center gap-[6px] whitespace-nowrap rounded-pub-control-sm` |
| opção ativa: `seg` + anel inset | `:172` | `bg-pub-seg text-pub-txt shadow-pub-seg-ativo` |
| dot do segmented usa a cor do **TEXTO** (**V-1**) | `:520` | `bg-pub-danger-txt` |
| linha recomendada: fundo `#131313` | `:516` | `bg-pub-raised-0` |
| grid da ação `38px … 28px` | `:177` | `grid-cols-[38px_minmax(0,1fr)_auto_auto_28px]` |
| ícone crítico: `path` à mão com `fill` | `:179` | `M8 1.5L15 14H1z` |
| badge RECOMENDADO AGORA | `:186` | `rounded-pub-badge border border-pub-line-off` |
| sub-lista: 4 lados explícitos, `pl:72px` (**D-7**) | `:198` | `pb-[14px] pl-[72px] pr-[20px] pt-0` |
| sub-item: h 40, grid de 3 colunas | `:200` | `h-[40px] grid-cols-[minmax(0,1fr)_auto_auto]` |
| "Adiar": h 26, raio 6, hover `#1A1A1A` | `:210` | `rounded-pub-chip border-none bg-transparent px-[10px] text-[12px] text-pub-txt-4 hover:bg-pub-hover-3` |
| vazio: tile verde `0.12` | `:217` | `bg-pub-ok/[0.12]` |
| o rodapé do vazio **LÊ o ISO** (VG-11), não crava "8h" | `:229` | `volta_em_iso` |
| o `SUBTITULO` fica no **plural** do protótipo | `:603` | `SUBTITULO` (sem "conserto" de concordância) |

### A.3 — Saúde da conta (8 medidas) · `SaudeDaConta.jsx` + `acoes.js`

| Elemento | Protótipo | Implementado (medido no código) |
|---|---|---|
| dot do geral 7×7 com a cor **CLARA** (**V-2**) | `:238` | `h-[7px] w-[7px] rounded-full` + `text/bg-pub-ok-txt` |
| linha: `border-top #1C1C1C`, pad 11/18 | `:240` | `border-t border-pub-line-subtle-2 px-[18px] py-[11px]` |
| tile 32×32, raio 8, letra 10.5 | `:241` | `h-[32px] w-[32px] flex-none place-items-center rounded-[8px] text-[10.5px]` |
| tile do ML: amarelo com texto `page` | `:525` | `bg-pub-accent text-pub-page` |
| o **caractere** `⟳` no tile de Sincronização | `:531` | `acoes.js:399` → `sincronizacao: { nome: 'Sincronização', tile: '⟳' }` |
| botão "Atualizar": h 28, raio 7 | `:246` | `h-[28px] cursor-pointer items-center gap-[6px] whitespace-nowrap rounded-pub-control-sm` |
| spinner 11px, borda 1.5, topo amarelo | `:247` | `h-[11px] w-[11px] animate-pub-spin rounded-full border-[1.5px] border-pub-line-off border-t-pub-accent` |
| o ERP **nunca diz "Conectado"** (**D-2**) | `:527` | `Declarado:` / `Não informado` — e 4 asserções negativas |

### A.4 — Publicações no Mercado Livre (7 medidas) · `PublicacoesNoMercadoLivre.jsx` + `acoes.js`

| Elemento | Protótipo | Implementado (medido no código) |
|---|---|---|
| card: pad 16/18/18, gap 14 | `:256` | `rounded-pub-card border border-pub-line bg-pub-surface px-[18px] pb-[18px] pt-[16px]` |
| total 30px, −0.03em, tabular | `:260` | `text-[30px] font-semibold leading-none tracking-[-0.03em] tabular-nums` |
| barra: h 8, gap 2, raio 4, trilho `#1C1C1C` | `:261` | `flex h-[8px] gap-[2px] overflow-hidden rounded-[4px] bg-pub-line-subtle-2` |
| legenda: h 30, `-mx-8`, raio 7 | `:265` | `flex h-[30px] items-center gap-[10px] -mx-[8px] whitespace-nowrap rounded-pub-control-sm px-[8px] text-[13px] text-pub-fase1` |
| hover `#1A1A1A` **só no ramo `<a>`** | `:265` | `PublicacoesNoMercadoLivre.jsx:199` → `cn(classes, 'hover:bg-pub-hover-3')` |
| acervo: raio 10, fundo sunken, borda `#1C1C1C` | `:268` | `rounded-pub-sub border border-pub-line-subtle-2 bg-pub-sunken p-[12px]` |
| mosaico 3 × 14px, gap 3 | `:269` | `grid-cols-[repeat(3,14px)] gap-[3px]` |
| as **4 faixas**, sem "Com erro" (**D-3**) | `:536` | `ativas / pausadas / em_revisao / encerradas` + `PEDEM_ACAO = 'Pedem ação'` |

### A.5 — Banner, Desempenho, Recentes e Atividade (29 medidas)

| Bloco | Elemento | Protótipo | Implementado (medido no código) |
|---|---|---|---|
| Banner | wrapper: borda 0.4, fundo 0.07, raio 12 | `:28` | `rounded-pub-panel border border-pub-danger/[0.4] bg-pub-danger/[0.07] px-[16px] py-[14px]` |
| Banner | tile 34×34, raio 9, tinta 0.14 | `:29` | `h-[34px] w-[34px] flex-none place-items-center rounded-[9px] bg-pub-danger/[0.14]` |
| Banner | CTA h 34, raio 8, sobre `danger-txt` | `:36` | `h-[34px] items-center whitespace-nowrap rounded-[8px] bg-pub-danger-txt px-[14px] text-[13px] font-semibold text-pub-page` |
| Desempenho | faixa: nowrap + card + **3** KPIs (**D-3/D-4**) | `:286` | `grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]` |
| Desempenho | é `<section>`, nunca `<a>` (**D-3**) | `:286` | `<section` · **0** `href=` · "Ver desempenho" ausente |
| Desempenho | tile 36×36, raio 10, tinta 0.12 | `:288` | `h-[36px] w-[36px] place-items-center rounded-pub-sub bg-pub-accent/[0.12]` |
| Desempenho | célula: `border-left`, pad 12/20 | `:294` | `border-l border-pub-line-subtle px-[20px] py-[12px]` |
| Desempenho | linha do valor: **baseline**, gap 6 (**O-5**) | `:297` | `items-baseline gap-[6px]` |
| Desempenho | valor 20px, −0.02em, tabular | `:297` | `text-[20px] font-semibold leading-none tracking-[-0.02em] tabular-nums` |
| Desempenho | sparkline 64×26, `viewBox "0 0 100 34"` | `:303` | `viewBox="0 0 100 34"` |
| Desempenho | área 0.10 + linha stroke 2 | `:304` | `fill-pub-accent/[0.10]` |
| Recentes | linha: grid 40/1fr/112/104 + nowrap | `:319` | `grid-cols-[40px_minmax(0,1fr)_112px_104px]` |
| Recentes | linha NOVA: fundo `#14120A` | `:319` | `bg-pub-row-new` |
| Recentes | hover da linha `#161616` | `:319` | `hover:bg-pub-hover-2` |
| Recentes | tile 40px (a escada devolve raio 9 / 11.5px) | `:320` | `tamanho={40}`, **nenhuma prop extra** |
| Recentes | glyph de fase: tamanho `recentes` (**D-6**) | `:324` | `tamanho="recentes"` |
| Recentes | MLB em Geist Mono 11px | `:326` | `font-pub-mono text-[11px]` |
| Recentes | status com corpo 12.5px (prop aditiva) | `:328` | `corpo="text-[12.5px]"` |
| Recentes | coluna do título gap **3px** (**V-2**) | `:315` | `flex flex-col gap-[3px]` |
| Atividade | chip: h 26, raio 999, nowrap, pointer | `:346` | `h-[26px] cursor-pointer whitespace-nowrap rounded-full border px-[9px] text-[12px]` |
| Atividade | rótulo do grupo `pt:12 pb:8` (**V-1**) | `:353` | `pb-[8px] pt-[12px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4` |
| Atividade | evento: grid 30px/1fr/auto, `pb:16` | `:355` | `grid-cols-[30px_minmax(0,1fr)_auto] gap-[12px] pb-[16px]` |
| Atividade | fio: `left 14.5 / top 32 / bottom 2 / w px` | `:356` | `bottom-[2px] left-[14.5px] top-[32px] w-px bg-pub-line` |
| Atividade | ícone 30×30 redondo com borda | `:357` | `h-[30px] w-[30px] place-items-center rounded-full border` |
| Atividade | tinta do ícone de publicação (`#1F1B08`) | `:588` | `bg-pub-accent-sunken` |
| Atividade | frase 13px, `leading 1.4` | `:366` | `text-[13px] leading-[1.4] text-pub-txt-2` |
| Atividade | hora 12px, `pt:5`, tabular | `:375` | `pt-[5px] text-[12px] tabular-nums text-pub-txt-4` |
| Atividade | vazio `py:30`, centro, 13px | `:380` | `py-[30px] text-center text-[13px] text-pub-txt-4` |
| Atividade | `HOJE` / `ONTEM` em **caixa alta** | `:589` | `HOJE` |

### A.6 — Composição e container (6 medidas) · `CockpitDaConta.jsx` + `VisaoGeral.jsx`

| Elemento | Protótipo (re-medido) | Implementado (medido no código) |
|---|---|---|
| neutralização do `p-6` do `<main>` | `:25` | `-mx-6 -mb-6` (wrapper externo) |
| container: 1600 / 1240 / px36 / pt24 / pb80 / gap20 | **`:22`** (`min-width:1240px`) + **`:25`** (`max-width:1600px;padding:24px 36px 80px;gap:20px`) | `mx-auto flex max-w-[1600px] min-w-[1240px] flex-col gap-[20px] px-[36px] pt-[24px] pb-[80px]` |
| grade 3: `1fr` + `392px`, `items-start` | **`:164`** | `grid grid-cols-[minmax(0,1fr)_392px] items-start gap-[20px]` |
| coluna lateral com `nowrap` (**D-4**) | **`:234`** | `flex flex-col gap-[20px] whitespace-nowrap` |
| grade 5: `1fr` + `1fr`, `items-start` | **`:312`** (fecha em `:383`, `</main>` em `:384` — **nada depois**) | `grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-start gap-[20px]` |
| legenda do recorte | **— (NÃO existe no protótipo)** | `m-0 text-[12px] text-pub-txt-4` — **ADIÇÃO exigida pelo critério 1** |

**TOTAL: 85 medidas, 85 conferidas no código.**

### ⚠️ As 2 medidas em que a MINHA tabela apontava errado (e não o código)

| # | o que a minha tabela dizia | o que medi | veredito |
|---|---|---|---|
| 1 | o caractere `⟳` está em `SaudeDaConta.jsx` | está em **`acoes.js:399`** (`sincronizacao: { nome: 'Sincronização', tile: '⟳' }`), e o componente o consome | **presente e correto** — eu apontei o arquivo errado |
| 2 | a linha da legenda carrega `hover:bg-pub-hover-3` na mesma string de classes | o `hover` é aplicado **só no ramo `<a>`** (`:199`, via `cn(classes, 'hover:bg-pub-hover-3')`) — **de propósito**: hover numa `<span>` que não navega promete clique que não existe | **presente e correto**, e a decisão é melhor que a minha expectativa |

---

## B. ⚠️ As medidas em que o PROTÓTIPO venceu a tabela do PLANO

> *"Quero que reproduza fielmente o layout, já tentei fazer um redesign e não saiu como eu esperava
> **porque você adaptava muito o novo layout ao sistema atual**"* — usuário, 2026-10-11

**A tabela FID-01 dos planos é artefato derivado; o protótipo é a fonte.**

| fase / plano | divergências medidas | em quantas a tabela do plano venceu |
|---|---|---|
| Fase 177 | 21 | **0** |
| Fase 178 | 3 | **0** |
| 179-04 | 5 (4 referências de linha + a contagem "33 × 35 tokens") | **0** |
| 179-06 | 31 (29 referências + 2 de valor + 2 omitidas) | **0** |
| 179-07 | 16 (10 referências + 2 de valor + 7 omitidas, com sobreposição) | **0** |
| 179-08 | 23 (11 referências + 2 de valor + 10 omitidas) | **0** |
| 179-09 | 9 (`D-1`..`D-9`, inclusive as 4 referências de `:23/:26/:161/:304`) | **0** |
| 179-01 / 02 / 02B / 03 / 05 (servidor) | ~14 contradições do próprio plano (ex.: o literal prescrito que reprovaria o gate 8 do plano; "13 props" listando 14; `pedem_acao` prescrito de duas formas opostas) | **0** |
| **TOTAL DA FASE 179** | **~102** | ⚠️ **ZERO** |

⚠️ **O placar que o briefing pedia está CONFIRMADO: em nenhuma das ~102 medidas a tabela do plano
acertou contra o protótipo.** E as que eu **re-medi nesta sessão** (as 4 do 179-04 e as 6
estruturais do 179-09) **conferem com o que as SUMMARYs corrigiram**, não com o que os PLANs diziam.

⚠️ **O que eu re-medi × o que copiei, declarado:** re-medi os hex dos 4 tokens novos (`:137`, `:516`,
`:588`, `:479`), as 6 referências estruturais do container e das grades (`:22`, `:25`, `:164`,
`:234`, `:312`, `:383-384`) e as 85 linhas da coluna "Implementado". As **contagens por plano** da
tabela acima são **copiadas das 10 SUMMARYs** — eu as somei e reconciliei, não as re-derivei do
protótipo uma a uma.

---

## C. Divergências DECLARADAS, separadas por TIPO DE RESTRIÇÃO

### C.1 — Falta de DADO (o sistema não tem a informação nessa forma)

| # | O protótipo tem | Entregue | A restrição concreta |
|---|---|---|---|
| 1 | KPIs **Vendas**, **Visitas**, **Conversão** na faixa (`:554`) | **não nascem** (3 KPIs, não 4) | `sold_quantity` é o **acumulado da vida inteira** do anúncio; `visitas` é **NULL em 1.374.527 linhas**; **não existe tabela de pedidos** (DESEMP-05) |
| 2 | nota *"Rascunho parado em Imagens"* (`:397`) | `Rascunho em preenchimento · faltam {n}` | **não existe coluna de etapa parada** — existe `faltam`, a contagem de bloqueios |
| 3 | **6 miniaturas de 34px** no evento de criativos (`:369`-`:371`) | só o `detalhe` escrito ("6 imagens geradas") | `atividadeDaEquipe()` não entrega **nenhuma URL de imagem**; o binário é endereçado dentro do editor (território de outro dev) |
| 4 | **link de ação por evento** (`:373`, `href="#"`) | **não nasce** | o shape do evento é `{tipo, quem, quando_iso, titulo, detalhe}` — **não tem destino**. `href="#"` é clique mudo (EXMP-03) |
| 5 | chip **"Sistema"** (5º chip, `:592`) | **4 chips** | não existe fonte de **evento** de sincronização: o que existe é um **carimbo** (`coletado_em`), que já é a linha de Sincronização da Saúde. **Carimbo não é linha do tempo** |
| 6 | evento de **erro** e de **sincronização** na linha do tempo (`:588`) | **não nascem** | idem: as fontes reais da atividade são 4 (`publicou`, `criativos`, `conferiu`, `produto_recebido`) |

### C.2 — Restrição TÉCNICA / de MEDIÇÃO

| # | O protótipo tem | Entregue | A restrição concreta |
|---|---|---|---|
| 7 | linha âmbar **"ERP Bling desconectado"** + banner + CTA "Reconectar ERP" (`:368`, `:519`, `:527`) | o ERP só com `declarado`, `problema` **sempre false**, **nenhum banner**, **nenhuma pílula**, e **nunca a palavra "Conectado"** | ⚠️ **O ERP NÃO É INTEGRAÇÃO neste sistema** — é **declarado** na ficha de onboarding (ou pelo sync da planilha de Polos). Travado na Fase 177-03 com `assertArrayNotHasKey('conectado', $erp)`. **Medido agora: `BANNERS` só tem a chave `ml`** |
| 8 | barra `Ativas / Pausadas / **Com erro**` (`:536`) | 4 faixas que **somam o total** + a legenda **"Pedem ação"** | `MlAcervoItem.status` só assume `active\|paused\|under_review\|closed` — **não existe status de erro**. O 3º sinal real é `severidade > 0`, o MESMO número do dot da aba Publicações |
| 9 | *"O token da conta expirou **há 2 h**"* (`:594`) | a descrição **sem o prazo** | `banner()` devolve `{chave, link_reconexao}` e a 177-03 travou a **ausência** de `renova_em`/`expires_at`. "há 2 h" seria número inventado (VG-18) |
| 10 | a hachura como `repeating-linear-gradient` **inline** (`:479`) | o token `bg-pub-hachura` | TOK-15 proíbe hex literal no JSX; a forma inline levaria **dois** hex para dentro da tela e o gate de vocabulário reprova. **Valor idêntico, medido no CSS** |
| 11 | `F1 [C][P]` e `F2 [C][P]` com rótulo próprio e 6px de respiro (`:145`-`:148`) | um `MapaDeCobertura` com as **4 células seguidas** | o componente tem `CELULAS` como **constante de 4 itens** e `identidade/` estava **vedado** àquele plano. A fase não se perde (cada célula emite `title`/`aria-label`), mas **os rótulos F1/F2 não aparecem, o raio é 5 em vez de 4 e a letra 10,5px em vez de 10** |
| 12 | o tempo absoluto *"há 12 min"*, *"Ontem, 17:48"* (`:572`) | sempre relativo (`haQuanto`) | `haQuanto` **já existe** em `tempo.js` e não se reescreve (IDENT-08); o formato absoluto exigiria uma segunda régua de data no cliente |

### C.3 — DECISÃO DO USUÁRIO (registrada, não débito silencioso)

| # | Decisão | Data | Consequência |
|---|---|---|---|
| **D-3** | **a faixa de Desempenho fica com 3 KPIs reais e é `<section>`, não `<a>`** — sem link e sem a coluna "Ver desempenho →" | **12/10/2026** (a aba Desempenho ausente é de **11/10**) | ⚠️ **Decisão REGISTRADA.** O link e os KPIs de venda são a **Fase 182**. `desempenho.url` vem `null` do servidor e a mutação M11 do 179-03 reprova quem o preencher. **Não é débito silencioso** |
| **D-4** | "publicação com prejuízo" fica **fora** até a rentabilidade existir | 11/10/2026 | a dependência **179 → 181** está marcada no ROADMAP; a ação **não aparece** em vez de virar enfeite (VG-12, EXMP-01) |
| **D-8** | ⚠️ **"Produtos por fase" e "Quem publicou" SAEM DA TELA e não voltam** | **12/10/2026** | ver o bloco próprio abaixo |
| **02B** | **"Prontos para a Fase 2": alinhar os três lugares** | **12/10/2026** | a fonte única nasceu; **a divergência está RESOLVIDA** (§7). Superou a "Exclusão 2" do 178-01 |

### ⚠️ C.4 — A exceção EXPLÍCITA do usuário à regra "melhorar sem regredir"

> **"Produtos por fase" e "Quem publicou" saíram da tela por DECISÃO INFORMADA DO USUÁRIO EM
> 12/10/2026.** A fase **PAROU e PERGUNTOU** (precedente **LIST-25 CUMPRIDO**, não violado),
> apresentou **TRÊS** opções — *preservar no fim da página* · *mover para Configurações da conta* ·
> *tirar os dois* — **com o aviso explícito de que os dois são o ÚNICO caminho desses dados no
> sistema**, e ele **escolheu tirar**.
>
> É a **segunda** remoção autorizada da milestone v26.0 (a primeira foi o seletor de período,
> critério 1 do ROADMAP). ⚠️ **As props `produtosPorFase` e `quemPublicou` SEGUEM chegando** nas 23
> props: a decisão é de **TELA**, e payload não é tela. Nenhum método de serviço foi apagado, e
> `CockpitDaConta.jsx` **nem as desestrutura** (prop órfã deliberada, com asserção negativa para
> ninguém "ligar" o render por engano).
>
> ⚠️ **NENHUMA SESSÃO FUTURA DEVE RESTAURÁ-LOS SEM UMA DECISÃO NOVA DO USUÁRIO.** O registro está em
> **quatro** lugares: no comentário de topo do `CockpitDaConta.jsx`, na **mensagem de asserção** que
> falha se o bloco voltar (com a data), no `179-09-SUMMARY.md` e aqui.

### C.5 — Regra de MODELAGEM e ausência de TELA DE DESTINO

| # | O protótipo tem | Entregue | Por quê |
|---|---|---|---|
| 13 | 4 estágios e **nenhum caso de erro** | `erro` cai dentro de `draft` | **totalidade do anel**: um quinto caso faria o "N de M produtos" mentir. O erro segue na ação crítica "Produtos com problema na publicação" |
| 14 | "Acervo de imagens … **Abrir →**" (`:273`) | os dois números, **sem link** | não existe tela de acervo **por conta**: ele é endereçado por `publicador/produtos/{produto}/acervo` e vive **dentro do editor** (território de outro dev). `Abrir →` sem destino é botão mudo (EXMP-03) |
| 15 | a ação de sincronizar teria CTA "Sincronizar" | **nenhum CTA** — a ação aparece com título, número, descrição e sub-lista | o botão REAL (`BotaoSincronizarPortal`) **já existe**, mas carrega `ecf-yellow`, `border-white/[0.10]` e `h-10`, **vetados** por FID-03/FID-04 no layout novo — e serve **3 outras telas** |

---

## D. O rastro dos 24 REQ-IDs

| REQ | Estado | Onde está (`arquivo:linha`) | O gate que prova |
|---|---|---|---|
| **VG-01** | ✅ entregue | `VisaoGeral.jsx` (container) + `CockpitDaConta.jsx` (os 5 blocos) | `publicador-visao-geral-cockpit` testes A/C; `max-w-[1600px]`/`px-[36px]`/`pb-[80px]`/`gap-[20px]` medidos no CSS; M01/M02/M03/M05/M06/M11/M19 |
| **VG-02** | ✅ entregue | `BannerDeIntegracao.jsx:57,75,77`; `recentes.js:150` (`BANNERS`) | `…-recentes.test.js`; M02 (banner nulo renderiza) · M03 (CTA sem link) · M04 (`href` → `data-href`) |
| **VG-03** | ✅ entregue | `JornadaDosProdutos.jsx` (card 22/24, grid 184px) + a **legenda** em `CockpitDaConta.jsx` + `jornada.js` (`NOTA_PERIODO`) | `…-jornada` 10/11; `…-cockpit` teste D (a legenda, e a ausência de `Hoje`/`7 dias`/`Este mês` **com isca**); M09 |
| **VG-04** | ✅ entregue | `JornadaDosProdutos.jsx:219,225,233,241,248`; `jornada.js` (`ORDEM_ANEL`, `segmentosDoAnel`) | `…-jornada` 13/14/15/16; M01/M02/M03/M04/M05/M06/M10/M14 |
| **VG-05** | ✅ entregue | `JornadaDosProdutos.jsx:260,271,302,323-329`; `jornada.js` (`ESTAGIOS`, `restanteDaPilha`) | `…-jornada` 20/21/22/26/29/30/31; M07/M08/M09/M16/M17/M20/M21/M26/M30 |
| **VG-06** | ✅ entregue | `JornadaDosProdutos.jsx:295`; `jornada.js:401` (`ehProximoPasso` com `&& cnt > 0`) | M28 (badge em estágio vazio) — **era lacuna de cobertura real, fechada** |
| **VG-07** | ✅ entregue | `JornadaDosProdutos.jsx:91,371`; `tailwind.config.js` (`backgroundImage['pub-hachura']`) | `publicador-tokens-do-publicador` (valor do gradiente pelo motor) + a sonda de CSS desta SUMMARY; M04/M05/M06 do 179-04 e M11/M12/M13 do 179-06 |
| **VG-08** | ✅ entregue | `JornadaDosProdutos.jsx:133` (o guard do derivado), `:137`-`:157` (o painel) | `…-jornada` (painel do `ready` nasce aberto, link `Ver todos os N →`); M18/M22/M23/M24/M25/M27 |
| **VG-09** | ✅ entregue | `OQueFazerAgora.jsx:176,183,185`; `acoes.js` (`abasComContagem`, `SEVERIDADES.ordem`) | `…-acoes`; M01/M02/M03/M31/M32 · e no servidor `AcoesDaContaTest` M01/M15 |
| **VG-10** | ✅ entregue | `OQueFazerAgora.jsx:69,104,208,216,220,239,246,267,278` | `…-acoes`; M04/M05/M07/M08/M09/M10/M12/M13/M14/M28/M33 |
| **VG-11** | ✅ entregue | `OQueFazerAgora.jsx:92,293,308,391`; `AdiamentoDeAcoes.php`; `MlbPublicadorEntradaController::adiarAcao()`; rota `publicador.acoes.adiar` | **persistência por usuário e até amanhã às 8h** provada em `AcoesDaContaTest` (M10/M11/M12/M13) e `VisaoGeralCockpitTest` (M04/M05/M06/M07b/M08/M10); no cliente M16/M17/M18/M30/M34 |
| **VG-12** | ✅ entregue | `AcoesDaContaService::SEVERIDADE` (as 12 chaves reais) | `AcoesDaContaTest` *"toda linha de hoje virou ação com contagem EXATA"* (`count($linhas)+1`); "prejuízo" **não aparece** (decisão 4, dependência 179→181) |
| **VG-13** | ✅ entregue | `SaudeDaConta.jsx:49,71,94`; `acoes.js:331,348,364,393-399` | `…-acoes`; M19/M20/M21/M27/M29/M38 · servidor `CockpitDaContaTest` M04/M05 |
| **VG-14** | ⚠️ **parcial — medido** | `PublicacoesNoMercadoLivre.jsx:52,81,91,120,137,139,192,199`; `acoes.js:531-537,384 ` | **4 faixas que SOMAM o total + legenda "Pedem ação"** em vez de "Com erro" (**§C.2 #8**: não existe status de erro) e o **acervo sem `Abrir →`** (**§C.5 #14**: não existe tela de acervo por conta). M22/M23/M24/M25/M26/M35/M36/M37/M40/M41/M42 |
| **VG-15** | ⚠️ **parcial — NA FORMA QUE O USUÁRIO APROVOU EM 12/10/2026** | `FaixaDeDesempenho.jsx:98` (`<section>`), `:73,141,142,149,165,167,172,177`; `recentes.js:207` (`KPIS`, **3 chaves**) | **3 KPIs reais, `<section>` em vez de link.** A parte *"o bloco inteiro é link"* e os KPIs de venda são a **Fase 182** — ⚠️ **decisão registrada, não débito silencioso**. `desempenho.url` é `null` no servidor (M11 do 179-03 reprova quem preencher) e no cliente M05/M06/M07/M08/M09/M10/M11/M12/M13/M26/M29/M30/M32/M35 |
| **VG-16** | ✅ entregue | `PublicacoesRecentes.jsx:107,109,115,122,133,140,145,169` | `…-recentes`; M14/M15/M16/M17/M18/M27/M31/M33/M34/M36 |
| **VG-17** | ⚠️ **parcial — medido** | `AtividadeRecente.jsx:174,192,212,219,241`; `recentes.js:401-406` (**4 chips**), `:424,445,549` | **4 chips, não 5** (**§C.1 #5**: "Sistema" não tem fonte de evento) e **sem as miniaturas de 34px** (**§C.1 #3**: não há URL de imagem). O resto — timeline HOJE/ONTEM/data, ícone circular de 30px, ator+verbo+alvo — entregue. M19/M20/M21/M22/M23/M24/M28/M37/M38/M40 |
| **VG-18** | ✅ entregue | `CockpitDaContaService.php` (o mapa `null × 0` dos 8 indicadores); `acoes.js:403,451`; `recentes.js:70,272,276` | `CockpitDaContaTest` M01/M02/M06/M09/M12 + a varredura do JSON por qualquer `0` no ramo não medido; cliente M12/M26/M39/M40/M41 + a guarda `finito()` (⚠️ `textoSeguro(NaN)` devolvia a string `'NaN'`) |
| **VG-19** | ⚠️ **5 de 6 cenários — MEDIÇÃO, não falha** | os 5 alcançáveis estão nos testes adversos dos 4 gates de JS | ⚠️ **"ERP desconectado" NÃO É ESTADO ALCANÇÁVEL:** medi `recentes.js:150` e `BANNERS` tem **só a chave `ml`**, porque o ERP **não é integração** (`§C.2 #7`, 177-03 com asserção negativa). **É ausência de DADO, não omissão de implementação.** Os outros 5 (com pendências · conta saudável · ML desconectado · produtos sem publicação com anel 0% · sem produtos com o empty state ilustrado) estão cobertos |
| **VG-20** | ✅ entregue | os 12 arquivos novos; `PainelVisaoGeral.jsx` **intacto** | **0 imports** de `dadosDeExemplo`/`SeloExemplo` nos 12 (medido), **com ISCA** de que os dois módulos existem e ainda têm consumidor (`PainelVisaoGeral.jsx`); a Atividade segue com as **3 fontes reais + a 4ª**; M15 do 179-09 reprova quem importar exemplo |
| **EXMP-01** | ✅ entregue | os 12 arquivos | **nenhum CTA sem destino nasce**: `urlSegura()`/`chaveDeContaSegura()` recusam objeto, número e vazio, e sem destino o elemento é `<span>` ou **não é renderizado**. A única peça ornamental é o **mosaico de 6 quadrados** do acervo, **declarada** (`aria-hidden`, nenhum número, nenhum clique) |
| **EXMP-02** | ✅ entregue | — | **0 imports** de `dadosDeExemplo.js`/`SeloExemplo.jsx` nos 12 + na página (medido por grep, com isca). Nenhum bloco mistura real e exemplo no mesmo número porque **nenhum bloco de exemplo nasceu** |
| **EXMP-03** | ✅ entregue | `FaixaDeDesempenho.jsx` (0 `href=`), `AtividadeRecente.jsx` (0 `<a>`), o acervo sem `Abrir →`, a ação de sincronizar sem CTA | asserções **negativas** de `href="#"`, `Ver desempenho`, `Abrir →` e `>Sincronizar<`; M03/M04/M13/M35 |
| **EXMP-04** | ✅ entregue | — | **os 3 blocos que só poderiam nascer decorativos NÃO nasceram**: as miniaturas de criativo, o link por evento e o chip "Sistema". M23 (5 chips) e os gates de `w-[34px]`/`<img>` reprovam quem os criar |

⚠️ **Medido: NENHUM dos 24 REQ-IDs fica descoberto pela remoção de "Produtos por fase" e "Quem
publicou".** Os dois blocos não eram alvo de REQ nenhum desta fase — VG-01..20 e EXMP-01..04 falam
dos **blocos do protótipo**, e o protótipo não tem nenhum dos dois. **REG-01 pede o INVENTÁRIO**, e
ele está feito (§E), com as duas linhas marcadas REMOVIDO com data e literalidade.

---

## E. Os 15 critérios de sucesso do ROADMAP (linha 3664)

| # | Critério | Veredito medido |
|---|---|---|
| 1 | ordem vertical com gap 20, 1600px, padding 24/36/80; o seletor de período **saiu** e a UI **diz** o recorte | ✅ **VERDADE.** As 6 classes conferidas no CSS; a legenda literal travada por string inteira; ausência de `Hoje`/`Últimos 7 dias`/`Este mês`/`7 dias` no **corpo** (recortado pelos índices do container), **com isca** |
| 2 | anel de 176px (r=74, stroke 14, gaps de 3px), 4 segmentos na ordem certa, percentual 34px, 4 estágios com pilha + "+N", barra de 8px com a hachura | ✅ **VERDADE.** Geometria comparada **em números** com `segmentosDoAnel` **e** contra os literais medidos (`156.83 464.96`, `-159.83`); `ORDEM_ANEL` travada; 32 mutações |
| 3 | o "próximo passo" leva o badge amarelo e o CTA amarelo; "Prontos para Fase 2" **já aberto** | ✅ **VERDADE.** Nasce aberto por `contagens.ready > 0` (como o protótipo em `:390`/`:472`), não pelo `proximo_passo` que o plano prescrevia; M28 fecha o badge em estágio vazio |
| 4 | clicar seleciona (`#181818`/`#3F3F46`), derruba os outros para 0.3–0.35, abre painel de até 6 produtos com "Ver todos os N →"; clicar de novo fecha | ✅ **VERDADE.** `opacity:0.3(?!5)` 3× e `opacity:0.35` 3× lidos **no mesmo HTML**; o guard do derivado travado por leitura de fonte (M22 era lacuna real, fechada). ⚠️ O `MapaDeCobertura` entra com as **4 células seguidas**, sem os rótulos `F1`/`F2` (**§C.2 #11**) |
| 5 | ordena Crítico → Atenção → Oportunidade, filtra com contagens, badge RECOMENDADO AGORA com CTA amarelo e fundo `#131313`, sub-lista com motivo, "Abrir →" e "Adiar até amanhã" | ✅ **VERDADE.** 42 mutações; a `recomendada` é decidida no **servidor** sobre a lista completa (filtrar não promove ninguém) |
| 6 | o adiamento **persiste por usuário até o dia seguinte** — F5 não traz a ação de volta | ✅ **VERDADE** no contrato e na rota. `AdiamentoDeAcoes` grava no cache com `$userId` e `$contaChave` **na CHAVE**, expirando `tomorrow()->setTime(8,0)`; `back()` mantém o servidor como fonte. ⚠️ **A prova de F5 em navegador é do checkpoint** |
| 7 | as ações são reais e "publicação com prejuízo" **não aparece** | ✅ **VERDADE.** As 12 chaves de `SEVERIDADE` saem de medição; o gate cobra `count($linhas) + 1` (contagem **exata**, não `assertContains`) |
| 8 | Saúde com status geral + 3 linhas, "Atualizar" com spinner, tint na linha com problema | ✅ **VERDADE.** 3 linhas travadas (nunca 4); `animate-pub-spin` → `spin .7s linear infinite` medido; tints `0.07` conferidas pelo **byte de alfa** |
| 9 | total em 30px, "% ativas", barra empilhada e legenda **clicável**, mais o acervo | ⚠️ **PARCIAL, medido.** Total/pct/barra/legenda clicável ✅ (cada faixa traz a **sua** `url`). ⚠️ A barra tem **"Pedem ação"** em vez de "Com erro" (**§C.2 #8**) e o acervo **sem `Abrir →`** (**§C.5 #14**) |
| 10 | a faixa é um **link inteiro** com **4** mini-KPIs e "Ver desempenho →" | ⚠️ **PARCIAL — NA FORMA QUE O USUÁRIO APROVOU EM 12/10/2026.** É `<section>` com **3** KPIs reais, sem link e sem a coluna final. ⚠️ **Decisão registrada**, levada com alternativas: a tela Desempenho é a **Fase 182**; destino inventado é pior que destino nenhum (EXMP-03). "os KPIs sem dado não aparecem como número inventado" — **essa metade está ✅** |
| 11 | 5 linhas recentes com tile 40, NOVA, glyph, tipo, MLB, status, quem/quando; Atividade por HOJE/ONTEM/data com ícone de 30px, ator+verbo+alvo e **miniaturas de 34px** | ⚠️ **PARCIAL, medido.** Tudo ✅ **menos as miniaturas** (**§C.1 #3**: `atividadeDaEquipe()` não entrega URL de imagem; o número *"6 imagens geradas"* **aparece**) |
| 12 | os **6 cenários** funcionam | ⚠️ **5 de 6 — MEDIÇÃO, não omissão.** "ERP desconectado" **não é estado alcançável**: o ERP não é integração (`BANNERS` só tem `ml`, medido). **Não é falha de implementação: é ausência de dado** |
| 13 | "nunca medimos" ≠ "medimos e é zero" em todo indicador, e **nenhum bloco que hoje é real regrediu a exemplo** | ✅ **VERDADE nas duas metades.** O mapa `null × 0` cobre os 8 indicadores com os dois casos opostos testados juntos; **0 imports de exemplo** nos 12 arquivos, com isca; a Atividade segue com as 3 fontes reais + a 4ª. ⚠️ **"Sair da tela por decisão do usuário" é coisa DIFERENTE de "regredir a exemplo"** — os dois blocos removidos não viraram exemplo, e o cálculo deles **não foi apagado** |
| 14 | todo bloco **sem dado real** carrega **uma** pilha `SeloExemplo`, consome só `dadosDeExemplo.js` e nunca mistura real e exemplo no mesmo número | ✅ **VERDADE, por EXMP-04.** ⚠️ **Nenhum bloco sem dado real nasceu** — então nenhuma pilha foi necessária. A regra é cumprida pelo outro lado: *"bloco inteiro que só poderia nascer decorativo NÃO nasce"*, e os 3 que cairiam nisso (miniaturas, link por evento, chip Sistema) **não nasceram**. Medido: **0 imports** de `SeloExemplo`/`dadosDeExemplo` nos 12 |
| 15 | o plano traz a tabela de conferência item a item contra `Visao Geral.dc.html` (FID-01) | ✅ **VERDADE.** As tabelas parciais estão nos planos 06/07/08/09 e a **consolidada de 85 medidas** está na §A desta SUMMARY, com a coluna Implementado **lida no código** e 3 iscas |

**Placar: 10 VERDADE · 5 PARCIAIS**, e **as 5 parciais têm causa declarada**: 2 por falta de dado
(9 e 11), 1 por decisão registrada do usuário (10), 1 por estado não alcançável (12) e 1 por
restrição de componente vedado (4, o `MapaDeCobertura`). ⚠️ **Nenhuma é omissão de implementação.**

---

## F. ⚠️ As DUAS pendências de fechamento que falham EM SILÊNCIO

Medidas na Fase 178 e reconfirmadas aqui pela leitura do `REQUIREMENTS.md` da raiz:

| ferramenta | o que faz | ação necessária |
|---|---|---|
| `gsd-sdk query roadmap.update-plan-progress 179` | ⚠️ **reporta sucesso e NÃO escreve nada** nesta milestone | a linha de progresso da Fase 179 no `ROADMAP.md` precisa ser atualizada **À MÃO** |
| `gsd-sdk query requirements.mark-complete VG-01 …` | ⚠️ devolve **`not_found` em silêncio** para IDs de `REQUIREMENTS-vNN.md` (o `REQUIREMENTS.md` da raiz parou na **v17.0**) | os **24 checkboxes** (VG-01..20 + EXMP-01..04) em `.planning/REQUIREMENTS-v26.md` precisam ser marcados **À MÃO** |

➡️ ⚠️ **NÃO as marquei.** `ROADMAP.md` e `REQUIREMENTS-v26.md` estão com **diff VAZIO** no range
(gate 10), e `gsd-sdk query state.advance-plan` **não foi executado** (ele já corrompeu o `STATE.md`
seis vezes nesta milestone). **Quem fechar a fase tem de marcar os 24 checkboxes e o progresso à
mão**, e as linhas do ROADMAP a atualizar são **3892** (`VG-01..VG-20 (20) | Phase 179 | Pending`) e
**3893** (`EXMP-01..EXMP-04 (4) | Phase 179 | Pending`).

---

## G. Known Stubs

**Nenhum.** Varri os 12 arquivos novos e a página por valor vazio cravado que flui para a tela
(`=[]`, `={}`, `=null`, `=""`), por texto de placeholder (*"não disponível"*, *"em breve"*,
`TODO`, `FIXME`) e por componente sem fonte de dado: **nada**. Os `null` que existem no contrato
(`desempenho.url`, `banner` com token ativo, `serie` do KPI de acervo) são **decisões declaradas**
das waves anteriores, cada uma com a restrição escrita no docblock e travada por mutação.

As **3 peças do protótipo que não nascem** (miniaturas de criativo, link por evento, chip "Sistema")
são **ausências deliberadas** com a restrição concreta em §C.1, **não** pendências de implementação —
e há asserção **negativa** contra cada uma, para uma sessão futura não as "consertar" com enfeite.

## H. Threat Flags

**Nenhuma.** Nenhuma superfície nova de segurança nasce nesta fase além do que as waves já
declararam e mitigaram: **nenhuma migration**, **nenhum pacote**, **nenhum endpoint novo** além do
`POST publicador.acoes.adiar` — que nasceu dentro do grupo `auth/verified/role:admin`, com
`->where('conta','(empresa|company)-[0-9]+')` na **rota**, `resolver()`+404, `throttle:60,1` nomeado
e a **lista FECHADA de 12 chaves validada na borda com 422 antes de qualquer escrita** (T-179-16/17,
mutações M06/M07b/M08/M10 do 179-05).

---

# ⚠️ CHECKPOINT — a conferência visual do usuário, em produção, na conta 459

**O que foi construído:** a Visão geral passou a renderizar o **cockpit** novo: banner de integração,
Jornada dos produtos com o anel de 176px e os quatro estágios clicáveis, "O que fazer agora" com
severidade / expandir / adiar, Saúde da conta, Publicações no Mercado Livre, a faixa de Desempenho,
Publicações recentes e Atividade recente — no container de 1600px.
⚠️ **"Produtos por fase" e "Quem publicou" saíram da tela**, por decisão sua de 12/10/2026.

⚠️ **Nenhum executor viu tela renderizada, em nenhum dos 10 planos.** Não há navegador neste
ambiente e o banco local **não tem empresa com `ml_token`** — toda a prova é de contrato, de classe
no HTML que o `react-dom/server` produziu e de **valor** no CSS construído. **A conferência visual é
sua.**

⚠️ **Nada foi deployado e nada foi empurrado.** Nenhum comando no VPS, em nenhum plano. O deploy é
decisão sua, e esta milestone tem **outro dev ativo na mesma árvore**.

## O roteiro — 11 passos

Quando você autorizar o deploy e ele acontecer, abra
`https://admin.ecfconsultoria.com.br/mlb/anuncios/publicador/empresas/company-459/visao-geral`:

1. **O layout mudou?** Na Fase 177 você viu *"só o header nav"*. Agora a **página inteira** tem de
   estar no visual novo — fundo quase preto, cards cinza-escuro, Geist, nenhum card amarelo antigo.
   A ordem de cima para baixo é: **banner** (só se houver problema) → **Jornada dos produtos** →
   **a legenda do recorte** → **"O que fazer agora" (esquerda) + Saúde da conta e Publicações no ML
   (coluna direita de 392px)** → **faixa de Desempenho** → **Publicações recentes + Atividade
   recente** (metade a metade). ⚠️ **O seletor de período SAIU** — no lugar dele a UI **diz** o
   recorte: *"Jornada, Saúde e Ações mostram o estado atual. Recentes e Atividade mostram os últimos
   30 dias."* Confirme que essa frase está lá e que não sobrou nenhum filtro global.
2. **A Jornada:** o anel grande aparece com o percentual no centro, a palavra "publicados" e
   "N de M produtos"? Os quatro estágios estão numerados **01 a 04** com setas entre eles (três
   setas, não quatro)? **"Fase 1 publicada"** tem a tarja amarela **PRÓXIMO PASSO** e já está
   **aberto**, com a lista de produtos embaixo? E na pilha de tiles de um estágio cheio aparece o
   **"+N"** com o número que sobra?
3. Clique em **outro estágio**: ele fica selecionado, o anel e a barra escurecem os outros pedaços e
   a lista troca? **Clique de novo: fecha?**
4. **"O que fazer agora":** a ordem é **Crítico → Atenção → Oportunidade**? Os filtros
   (Todas / Crítico / Atenção / Oportunidade) mostram contagens que fazem sentido? A primeira ação
   tem o badge **RECOMENDADO AGORA**, o CTA amarelo cheio e o fundo um pouco mais claro? Abra o
   chevron de uma ação (a sub-lista dos produtos afetados aparece) e clique em **"Adiar até
   amanhã"**: a ação desaparece. ⚠️ **Recarregue a página (F5)** — ela **não** pode voltar. (Ela
   volta sozinha às **8h de amanhã**.)
5. **Saúde da conta:** o status geral aparece no topo. Na linha do ERP, ela diz **"Declarado: X"** ou
   **"Não informado"**. ⚠️ **Se disser "Conectado", é defeito — me avise.** Clique em **"Atualizar"**
   na linha de Sincronização: spinner, e depois a mensagem de que a coleta foi **enfileirada** (ela
   não roda na hora, por desenho).
6. **Publicações no Mercado Livre:** o total bate com o número da aba "Publicações" do header? As 4
   faixas da barra **somam** o total? Clique em **"Ativas"** na legenda — vai para a lista já
   filtrada? ⚠️ A legenda tem **"Pedem ação"** em vez de **"Com erro"**, porque **não existe status
   de erro** no acervo — está certo assim?
7. **A faixa de Desempenho:** ⚠️ ela **não é clicável** e **não tem** "Ver desempenho →", porque a
   tela Desempenho ainda não existe (sua decisão de 11/10). Ela tem **3** KPIs, não 4: "Vendas"
   ficou fora porque não há fonte. **Está certo assim, ou você prefere a faixa inteira fora?**
8. **Publicações recentes e Atividade:** as 5 linhas aparecem com tile, badge NOVA na mais nova,
   glyph de fase, tipo e MLB em fonte mono? A Atividade agrupa em **HOJE / ONTEM / data**? ⚠️ Os
   chips são **4** (Tudo / Publicações / Criativos / Produtos) — **"Sistema" não nasceu** porque
   nasceria sempre vazio.
9. ⚠️ **Conta sem produtos:** se você tiver uma à mão, a Jornada tem de mostrar o anel em **0%** e o
   **empty state ilustrado** com os dois botões ("Cadastrar produto" e "Enviar link do Portal ao
   cliente").
10. ⚠️ **"Produtos por fase" e "Quem publicou" NÃO estão mais na página** — é o que você decidiu em
    12/10, sabendo que os dois eram o único caminho desses dados. **Confirme que não sente falta de
    nenhum dos dois.** Se sentir, me diga qual: o cálculo **não foi apagado** do servidor e as props
    continuam chegando, então o retorno de um deles é **trabalho de tela, não de dado**.
11. **Fontes, tamanhos e espaçamentos:** é a parte que só o seu olho julga. Abra
    `new_layout_publicador/Visao Geral.dc.html` no navegador **lado a lado** com a produção.
    ⚠️ Qualquer diferença que te incomodar, me diga **qual elemento** — as 85 medidas estão na tabela
    FID-01 desta SUMMARY e a correção é pontual. ⚠️ **A espessura dos traços dos ícones** foi
    multiplicada por **1,5** na conversão de `viewBox` (16 → 24 do Lucide): geometricamente é
    idêntico, mas só o olho confirma.

## ⚠️ Perguntas em aberto — TODAS para o usuário, NENHUMA decidida aqui

1. **A ação "Ofertas novas no Portal" fica sem botão.** O botão real existe
   (`BotaoSincronizarPortal`), mas carrega `ecf-yellow`, `border-white/[0.10]` e `h-10` — todos
   **vetados** no layout novo — e serve **3 outras telas**. Hoje a ação aparece com número, título e
   descrição, e o botão está na tela de **Produtos**. Levá-lo para o layout novo é trabalho de um
   plano que possa tocar aquele arquivo.
2. **A linha do ERP repete o nome:** ela mostra o nome declarado no título *e* `Declarado: {nome}` na
   meta (as duas prescrições do plano). Se preferir sem repetição, o título volta a ser fixo (`ERP`)
   e a meta carrega o nome — **uma linha**.
3. **O `MapaDeCobertura` entra com as 4 células seguidas**, sem os rótulos `F1`/`F2` que o protótipo
   separa (e com raio 5 em vez de 4, letra 10,5px em vez de 10). Consertar exige uma prop opcional
   no componente — **fase que possa tocar `identidade/`**, não esta.
4. **A unidade do KPI ficou só no `title`.** O protótipo **calcula** `"de 150"` e `"packs"` mas
   **não os desenha** em lugar nenhum — não há tamanho, cor nem posição medidos para eles. Hoje
   passar o mouse mostra `12 de 150` / `3 packs`. Unidade **visível** é decisão de desenho nova.
5. **O delta negativo sai `↓ -2`**, com o sinal repetido, porque é o que o protótipo renderiza
   (`delta: (d>0?'+':'')+d`, `:555`, com a flecha separada em `:299`). Se preferir `↓ 2`, é uma linha.
6. **O status da linha de recente mantém a FORMA** (círculo / barras / triângulo / anel) em vez do
   dot de 7px do protótipo, porque IDENT-04 (*"nunca depender só de cor"*) é requisito aprovado na
   Fase 177 e o componente serve 4 telas. O **texto** é idêntico. Se quiser o dot aqui, dá por mais
   uma prop aditiva.
7. **O acervo de imagens não tem `Abrir →`** e a faixa de Desempenho não tem destino: nos dois casos
   a tela de chegada não existe. Confirmar que é melhor assim do que um link que erra.
8. **O chip "Sistema" não nasceu** e a Atividade não tem evento de erro nem de sincronização: não
   existe fonte de **evento** desses — só o carimbo `coletado_em`, que já é a linha de Sincronização.
9. **Herdada da Fase 178-04, ainda não consertada:** falta `cursor-pointer` no item do dropdown de
   empresa (`SeletorDeEmpresaDoHeader.jsx:171`), e o protótipo o declara (`PublicadorHeader.dc.html:53`).
10. **Herdadas da Fase 177, para o seu olho:** o `top-[108px]` × `109px` do header, e a espessura dos
    traços dos ícones (`strokeWidth × 1,5` na conversão de `viewBox` 16 → 24).
11. ⚠️ **`quemPublicou` custa 1 consulta + 1 `User::find()` POR PUBLICAÇÃO da conta** (medido: 65
    consultas com 2 publicações, 91 com 12) **e não tem mais render.** Três caminhos:
    **(a)** tirar a prop — cirurgia em 14 asserções, uma delas um teste cujo propósito inteiro é
    provar que o contrato não perde prop; **(b)** deixar como está; ou
    ⚠️ **(c) — a que eu considero a melhor:** **consertar o N+1 do `resolverAtor()`** com o `whereIn`
    que o **próprio arquivo já usa** em `PainelVisaoGeralService.php:976` (`atividadeDaEquipe()`
    resolve os nomes em **uma** consulta, e o docblock de `:860` até declara *"Nunca um `User::find()`
    por linha"*). **Sem tocar prop nem asserção, sem mudança de contrato**, e o ganho vale para
    `publicadosRecentes()` **e** `ultimasPublicacoes()`. Quem fizer deve baixar o teto do gate
    `test_a_pagina_ainda_cresce_com_as_publicacoes_e_isso_e_divida_pre_existente` no mesmo commit.
12. **O flake de horário do `CockpitDaContaTest`** (dívida 2 do `deferred-items.md`): entre 00:00 e
    09:00 locais o cenário reporta 1 falha que **não é regressão**. Correção é **uma linha** de
    `Carbon::setTestNow()`.
13. ⚠️ **O número de produção da classe de fronteira de "Prontos para a Fase 2"** (base `FAILED` +
    anúncio `CREATED` vivo + sem kit + não composta) **segue não medido** — a leitura de produção
    está bloqueada nesta sessão. A decisão de 12/10 foi tomada sem ele, e você foi informado. Se
    quiser o número antes de deployar, é uma consulta que **você** roda (ou me libera).

---

## Self-Check: PASSED

**Arquivos conferidos no disco:**
- `.planning/phases/179-visao-geral-cockpit/179-10-SUMMARY.md` — FOUND
- `.planning/STATE.md` — FOUND (bloco aditivo)
- os **12** arquivos de `resources/js/Components/Mlb/Publicador/visaogeral/` — FOUND
- `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` — FOUND
- `resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx` — FOUND (intacto, órfão declarado)
- `public/build/manifest.json` e `public/build/assets/app-D1n4ohkR.css` — FOUND
- `app/Services/Publicador/{JornadaDosProdutos,AcoesDaConta,AdiamentoDeAcoes,CockpitDaConta}.php` — FOUND
- os **3** caminhos reais de `tempo.js` / `dadosDeExemplo.js` / `SeloExemplo.jsx` — FOUND

**Números reafirmados por reconsulta, não por memória:**
- `git diff --numstat 898d68c2..HEAD -- .planning/STATE.md` → **0 linhas removidas** antes do meu bloco
- `git diff --name-only --diff-filter=A 898d68c2..HEAD -- resources/js` → **12** arquivos, os 12 em `ARQUIVOS_NOVOS`
- `artisan test --filter=ProntoParaFase2Test` → **6 passed, 29 assertions**
- `git status --short | grep -v '^??'` → **vazio**
