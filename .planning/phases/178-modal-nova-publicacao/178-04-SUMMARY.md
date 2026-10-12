---
phase: 178-modal-nova-publicacao
plan: 04
subsystem: fechamento
tags: [publicador, fechamento-de-fase, vite-build, manifest, css-construido, baselines, gate-de-diff, fidelidade-de-pixel, checkpoint]

requires:
  - phase: 178-modal-nova-publicacao (plano 01)
    provides: "NovaPublicacaoService + o endpoint lazy mlb.anuncios.publicador.nova-publicacao"
  - phase: 178-modal-nova-publicacao (plano 02)
    provides: "novaPublicacao.js (literais + busca local) e PainelNovaPublicacao.jsx (o corpo)"
  - phase: 178-modal-nova-publicacao (plano 03)
    provides: "ModalNovaPublicacao.jsx (a casca portada) e o header ligado"
provides:
  - "O fechamento MEDIDO da Fase 178: build, manifest pelo JSON, as classes provadas no CSS construído, as 4 baselines re-medidas, os 14 gates de diff com range, a cirurgia de gate medida no diff"
  - "A tabela FID-01 CONSOLIDADA das 32 medidas do modal (12 da casca + 20 do corpo), com a coluna Implementado conferida no CÓDIGO por script"
  - "A prova de VALOR (não só de presença): os 16 tokens pub-* do modal medidos no CSS construído contra o hex do protótipo"
  - "O rastro dos 10 REQ-IDs NOVA-01..10"
  - "O roteiro de conferência visual de 10 passos, para o usuário executar em produção na conta 459"
affects: [179-visao-geral, 180-busca-global, 181]

tech-stack:
  added: []
  patterns:
    - "Sonda de CSS que DESESCAPA o arquivo inteiro antes de comparar, em vez de tentar reproduzir a regra de escape do Tailwind (a sonda do 177-09 errou 19 vezes tentando reproduzir)"
    - "Prova de VALOR e não só de presença: a classe existir no CSS não diz que o valor bate com o protótipo — comparar o corpo da regra"
    - "Toda sonda carrega ISCA (valor que precisa estar AUSENTE) e CONTRA-PROVA (a sonda reprova o errado?) — gate sem isca não se distingue de gate decorativo"
    - "Gate de diff SEMPRE com range `<inicial>..HEAD`, e a prova de que o vazio não é por construção: o mesmo range nos caminhos esperados NÃO-vazios + os caminhos proibidos conferidos como existentes no disco"

key-files:
  created:
    - .planning/phases/178-modal-nova-publicacao/178-04-SUMMARY.md
  modified: []

key-decisions:
  - "A sonda de CSS reprovou 14 de 30 na primeira rodada e O ERRO ERA DELA, não do código — três causas medidas: o Tailwind emite cor como rgb(R G B / var(--tw-*-opacity)) em DECIMAL, cor com alfa sai como hex de 8 dígitos (#000000a6), e variante sai com o pseudo ANTES da chave (.hover:bg-x:hover{). Exatamente a lição do 177-09, repetida."
  - "Medi VALOR, não só presença: as 16 medidas de token (14 cores + raios + sombra) foram comparadas contra o hex do protótipo no CSS construído. Classe presente com valor errado é a falha silenciosa que a FID-01 existe para pegar — e as 16 bateram."
  - "A divergência de CONTAGEM DE PÁGINAS do manifest é de vocabulário, não de fato: '11 entradas' (plano/177-09) = as 9 páginas que montam o header + AnunciosEmpresas + app.jsx; '8 páginas' (waves 2/3) = os 8 arquivos .jsx no diretório Pages/Mlb/Publicador/. Conjuntos DIFERENTES. Medi os dois: 11/11 e 8/8."
  - "ACHADO FECHADO: o protótipo declara `cursor:pointer` TAMBÉM no :53 (item do dropdown de empresa), e o SeletorDeEmpresaDoHeader.jsx:171 implementado NÃO tem `cursor-pointer`. A pergunta aberta da wave 2 está respondida: a 177-06 ficou com uma divergência real. FORA DE ESCOPO — não consertada aqui."
  - "A auditoria de prefixo acusou 6 linhas e as 6 são FALSO POSITIVO da minha sonda (4 são comentários que ALERTAM contra o padrão, 2 são Array.prototype.includes sobre lista de tokens partida por espaço — igualdade exata, não substring). Registrado como achado da sonda, não do código."
  - "Nenhum arquivo de código foi tocado. As medidas que reprovaram foram da SONDA, e corrigir a sonda não é corrigir o código. Nenhuma medida do código reprovou."

metrics:
  duration: ~70min
  completed: 2026-10-12
  tasks: 2 de 3 (a 3 é o checkpoint humano, entregue e NÃO resolvido)
  arquivos_criados: 1
  arquivos_modificados: 0
  gates_de_diff: 14
  classes_provadas_no_css: 70
  valores_de_token_medidos: 16
  literais_medidos: 12
  mutacoes_da_fase: 69

requirements-completed: []
---

# Fase 178 Plano 04: o fechamento medido do modal "Nova publicação" Summary

**A fase fecha medida: `npm run build` verde com as 11 entradas conferidas no `manifest.json` PELO
JSON, as 70 classes do modal provadas presentes no CSS construído e — o que nenhuma wave havia feito
— os 16 valores de token medidos contra o hex do protótipo dentro do CSS, com 0 divergências; as 4
baselines re-medidas sem uma única regressão (PHP 1848/11.421/0 · JS 3012/3011/1 pré-existente ·
Phase134 37/0 · Phase135 243/0); os 14 gates de diff vazios no range `da54a9ee..HEAD` com todos os
alvos conferidos como existentes; ZERO asserções removidas em 69 mutações de três waves; a tabela
FID-01 consolidada das 32 medidas do modal com a coluna Implementado lida no CÓDIGO — e um achado
novo que a wave 2 deixou em aberto e aqui ficou PROVADO: o protótipo declara `cursor:pointer` também
no `:53`, e a Fase 177-06 ficou sem ele.**

## Performance

- **Duração:** ~70 min
- **Concluído:** 2026-10-12
- **Tarefas:** 2 de 3 executadas; a Task 3 é o **checkpoint humano**, entregue e **não resolvido**
- **Arquivos:** 1 criado (esta SUMMARY), **0 de código modificados** — este plano MEDE, não corrige
- **Commit de partida de todos os gates:** `da54a9ee`

---

## ⚠️ NENHUM EXECUTOR VIU TELA RENDERIZADA — EM NENHUMA DAS 4 WAVES

**Não há navegador neste ambiente.** Toda a prova de fidelidade da Fase 178 é, sem exceção:

1. medida lida em `new_layout_publicador/PublicadorHeader.dc.html` (o protótipo), com a linha citada;
2. classe conferida no HTML que o `react-dom/server` produziu, ou na string exportada quando o
   conteúdo é **portado** (o `Dialog.Portal` do Radix devolve `null` no servidor de propósito);
3. valor conferido no **CSS construído** pelo Vite;
4. contrato de servidor conferido por teste automatizado;
5. build e manifest conferidos por leitura estruturada do JSON.

**O banco local não tem empresa com `ml_token`**, então a validação visual **só** existe em produção.
⚠️ **A conferência visual é do usuário, na conta 459** — está no checkpoint, no fim deste documento.

---

## 1. Build e manifest — conferidos PELO JSON, nunca por grep

`npm run build` → **verde, exit 0, 31,91s**. `public/build` é ignorado pelo git (`.gitignore:41`),
então o build **não sujou a árvore**.

⚠️ **Nunca por `grep`:** o hash do arquivo sai com hífen (`Produtos-OyS65O-r.js`) e um `grep` do nome
passaria pelo motivo errado. ⚠️ **Página React fora do manifest SOME da aplicação** — tela branca em
produção, sem erro no servidor. Lido com `node --input-type=commonjs` + `require()`, porque o
`package.json` é `"type":"module"`.

| # | Entrada no manifest | Arquivo gerado |
|---|---|---|
| 1 | `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` | `assets/VisaoGeral-Ce-6-qlf.js` |
| 2 | `resources/js/Pages/Mlb/Publicador/Produtos.jsx` | `assets/Produtos-OyS65O-r.js` |
| 3 | `resources/js/Pages/Mlb/Publicador/Produto.jsx` | `assets/Produto-KyAVvGNL.js` |
| 4 | `resources/js/Pages/Mlb/Publicador/Alavancas.jsx` | `assets/Alavancas-iCDwjUnP.js` |
| 5 | `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx` | `assets/Configuracoes-B1riVDvJ.js` |
| 6 | `resources/js/Pages/Mlb/Publicador/PublicacaoEmLote.jsx` | `assets/PublicacaoEmLote-BcUjDRmM.js` |
| 7 | `resources/js/Pages/Mlb/MeusAnuncios.jsx` | `assets/MeusAnuncios-CRFMffij.js` |
| 8 | `resources/js/Pages/Mlb/AnunciosHistorico.jsx` | `assets/AnunciosHistorico-B8Zpe3vG.js` |
| 9 | `resources/js/Pages/Mlb/AnunciarMassa.jsx` | `assets/AnunciarMassa-42ZHQOYA.js` |
| 10 | `resources/js/Pages/Mlb/AnunciosEmpresas.jsx` | `assets/AnunciosEmpresas-DR9kcTxr.js` |
| 11 | `resources/js/app.jsx` | `assets/app-DZqD6M2K.js` |

**`manifest ok 11 de 11` · 480 entradas no manifest inteiro · 0 fora.**

Esta fase **não cria página nova** — e isso também foi medido: **entradas próprias para
`ModalNovaPublicacao` / `PainelNovaPublicacao` / `novaPublicacao` = 0.** Os três são **Components**,
entram no grafo do `app.jsx` e **não devem** ter entrada própria. Correto.

### ⚠️ A divergência de contagem que eu encontrei — e é de VOCABULÁRIO, não de fato

O plano manda conferir **11** entradas. As SUMMARYs das waves 2 e 3 dizem *"as **8** páginas do
Publicador seguem no manifest"*. **Não é contradição: são conjuntos diferentes, e medi os dois.**

| "páginas" de quem | o que é | medido agora |
|---|---|---|
| **11 entradas** (plano e 177-09) | as **9 páginas que montam o header** (6 em `Pages/Mlb/Publicador/` + `MeusAnuncios` + `AnunciosHistorico` + `AnunciarMassa`) + `AnunciosEmpresas.jsx` + `app.jsx` | **11/11 no manifest** |
| **8 páginas** (waves 2 e 3) | os **8 arquivos `.jsx` no diretório** `resources/js/Pages/Mlb/Publicador/` — inclui `Editor.jsx` e `Tarefas.jsx`, que **não** montam o header; exclui `MeusAnuncios`/`AnunciosHistorico`/`AnunciarMassa`, que montam | **8/8 no manifest** |

Nenhuma página do Publicador está fora do manifest em nenhuma das duas contagens. **Registrada a
divergência em vez de ajustar o número**, como o plano manda. Recomendação para as Fases 179/180/181:
usar sempre *"as 9 páginas que montam o header"*, que é a definição de REG-01.

---

## 2. ⚠️ A prova de que as classes novas geram CSS — e, além dela, a prova de VALOR

**Classe que não gera utilitário não existe para a tela.** É o gate mais importante desta wave:
tudo pode estar certo no JSX e a tela sair sem estilo se o Tailwind não varreu o arquivo.

CSS construído: **`public/build/assets/app-Cg8qkwwN.css`, 212.455 bytes, 4.987 barras invertidas.**

### 2a. Presença — 70 classes, 0 faltando

| grupo | quantas | resultado |
|---|---|---|
| **obrigatórias** (as 17 da lista do plano) | 17 | **FALTANDO: NENHUMA** |
| **extras** (as demais que a fase introduziu) | 51 | **FALTANDO: NENHUMA** |
| **variantes** (`hover:bg-pub-hover-3`, `placeholder:text-pub-txt-4`) | 2 | **FALTANDO: NENHUMA** |
| **iscas** (`w-[621px]`, `top-[111px]`, `font-pub-inexistente`, `bg-pub-accent/[0.15]`, `max-h-[421px]`) | 5 | **as 5 AUSENTES** ✔ |
| **prova de prefixo** | — | `font-pub` e `font-pub-mono` são seletores **distintos** no CSS |

⚠️ **A isca existe porque sem ela a sonda não se distingue de uma sonda decorativa.** Se
`w-[621px]` "existisse", a sonda casaria com qualquer coisa e as 70 presenças não provariam nada.

⚠️ **A sonda NÃO tenta reproduzir a regra de escape do Tailwind.** Ela **desescapa o CSS inteiro**
(tira todas as barras invertidas) e depois casa `.classe` exigindo que o caractere seguinte seja fim
de seletor (`{ , : > + ~` espaço `)`). Os dois motivos: (a) a sonda do 177-09 acusou **19 classes
"FALTANDO"** na primeira rodada **tentando reproduzir o escape**, e o erro era dela; (b) exigir o fim
de seletor impede que `.font-pub` case **dentro** de `.font-pub-mono` — a armadilha de substring que
já mordeu este projeto quatro vezes.

### 2b. ⚠️ VALOR — 16 tokens medidos contra o hex do protótipo (nenhuma wave havia feito isto)

Presença não é fidelidade. **Classe presente com valor errado é a falha silenciosa que a tabela
FID-01 existe para pegar.** Li o corpo de cada regra no CSS construído e comparei com o valor que o
protótipo declara.

| classe | valor no CSS construído | protótipo | linha do protótipo |
|---|---|---|---|
| `bg-pub-surface` | `#111111` | `#111111` | `:134` background da caixa |
| `bg-pub-sunken` | `#0d0d0d` | `#0D0D0D` | `:158` background do rodapé |
| `text-pub-accent` | `#facc15` | `#FACC15` | `:151` ctaFg do tom ready |
| `border-pub-line-strong` | `#2e2e2e` | `#2E2E2E` | `:134` borda da caixa / `:138` borda do Esc |
| `border-pub-line-subtle` | `#1f1f1f` | `#1F1F1F` | `:135` border-bottom / `:158` border-top |
| `bg-pub-line-subtle` | `#1f1f1f` | `#1F1F1F` | `:151` ctaBg dos tons `none` e `draft` |
| `text-pub-txt` | `#f4f4f5` | `#F4F4F5` | `:134` / `:145` color |
| `text-pub-txt-2` | `#a1a1aa` | `#A1A1AA` | `:136` stroke / `:138` color / ctaFg draft |
| `text-pub-txt-3` | `#8a8a90` | `#8A8A90` | `:149` meta / `:156` vazio / `:158` rodapé |
| `text-pub-txt-4` | `#71717a` | `#71717A` | `:143` título do grupo |
| `text-pub-txt-off` | `#52525b` | `#52525B` | `:143` hint do grupo |
| `hover:bg-pub-hover-3` | `#1a1a1a` | `#1A1A1A` | `:145` `style-hover` |
| `border-pub-fase1` | `#d4d4d8` | `#D4D4D8` | `:159` borda dos retângulos do glyph |
| **`bg-black/[0.65]`** | **`#000000a6`** | `rgba(0,0,0,0.65)` → `#000000a6` | `:133` background do fundo |
| **`bg-pub-accent/[0.14]`** | **`#facc1524`** | `rgba(250,204,21,0.14)` → `#facc1524` | `:151` ctaBg do tom ready |
| `shadow-pub-modal` | `0 30px 80px rgba(0,0,0,.8)` | `0 30px 80px rgba(0,0,0,0.8)` | `:134` box-shadow |

**FALHAS DE VALOR: 0 de 16.** Mais os geométricos, lidos no mesmo CSS: `rounded-pub-card` = `16px`
(`:134`), `rounded-pub-sub` = `10px` (`:145`), `rounded-pub-control-sm` = `7px` (`:151`),
`w-[620px]` = `620px`, `top-[110px]` = `110px`, `max-h-[420px]` = `420px`, `h-[60px]` = `60px`,
`h-[50px]` = `50px`, `p-[36px]` = `36px`, `z-[80]` = `80`, `text-[13.5px]` = `13.5px`,
`text-[11.5px]` = `11.5px`, `text-[16px]` = `16px`, `tracking-[0.08em]` = `.08em`,
`rounded-[5px]` = `5px`.

**Contra-prova:** a sonda, apontada contra `#112233`, **reprova** `bg-pub-surface`. Ela distingue
valor errado.

### ⚠️ ACHADO: a MINHA sonda reprovou 14 de 30 na primeira rodada — e o erro era DELA

É a lição do 177-09 repetida, e vale escrever para a próxima pessoa não registrar defeito inexistente:

| causa | o que eu esperava | o que o CSS emite |
|---|---|---|
| 1 | `#111111` | `--tw-bg-opacity: 1; background-color: rgb(17 17 17 / var(--tw-bg-opacity, 1))` — **decimal, não hex** |
| 2 | `rgba(0,0,0,0.65)` | `#000000a6` — **hex de 8 dígitos** (`a6` = 166 = round(0,65 × 255)) |
| 3 | `.hover:bg-pub-hover-3{` | `.hover:bg-pub-hover-3**:hover**{` — o pseudo vem **antes** da chave |

Corrigida a sonda (normalizando os três casos), **as 16 bateram**. ⚠️ **Nenhuma linha de código foi
tocada** — o que estava errado era a medida, e o plano manda registrar o que mediu nos dois casos.

Uma quarta "falha" foi `bg-pub-fase2` — **token que não existe**: o retângulo da frente do glyph usa
`bg-pub-accent` (`GlyphDaFase.jsx:101`), que já está na tabela acima. A sonda inventou o nome.

---

## 3. As baselines — re-medidas por mim, não copiadas

| Suíte | Baseline de partida (plano) | **Medido agora (12/10/2026)** | Veredito |
|---|---|---|---|
| `tests/Unit/Publicador` + `tests/Feature/Publicador` | 1816 · 0 fail (11.243 asserções) | **1848 testes · 11.421 asserções · 0 falhas** (6min 19s, 318 MB) | **+32 casos / +178 asserções** sobre a baseline do plano — exatamente o que a wave 1 somou. Nenhuma regressão |
| JS inteiro (`npm run test:js`) | 2859 · 2858 pass · 1 fail | **3012 testes · 27 suítes · 3011 pass · 1 fail** (17,25s) | **+153 casos** sobre a baseline do plano (+107 wave 2, +46 wave 3). Nenhuma regressão |
| `tests/Feature/Phase134` | re-medir | **37 testes · 152 asserções · 0 falhas** · 40 deprecations | sem fail |
| `tests/Feature/Phase135` | re-medir | **243 testes · 1.122 asserções · 0 falhas** · 92 deprecations | sem fail |

### O único fail aceito, nomeado e NÃO corrigido

```
tests/js/estrutura-grade-glide.test.js:123
✖ Características secundárias nasce recolhido (é o grupo que mais infla)
  expected: /RECOLHIDOS_INICIAIS = \[G_SECUND\]/
  actual:   const RECOLHIDOS_INICIAIS = [];
```

É **pré-existente**, de **outro território** (`GradeAnuncioGlide`, o editor em massa) e de **outra
fase**. ⚠️ **Não corrigido de propósito** — consertá-lo aqui misturaria o resultado desta fase com
trabalho alheio, e o arquivo nem está no range desta fase.

**Deprecations do PHPUnit não são falhas:** **47** na suíte do Publicador, **40** na Phase134, **92**
na Phase135. **Pré-existentes** — a wave 1 mediu que `CabecalhoDoPublicadorTest` sozinho, sem
nenhuma mudança, já reporta 1. **Fora de escopo.**

---

## 4. Os 14 gates de diff — SEMPRE com o range `da54a9ee..HEAD`

⚠️ **Com o range, sempre.** `git diff --stat -- <dir>` **depois de commitar** compara a **árvore de
trabalho** e sai **vazio POR CONSTRUÇÃO** — passa sem provar nada. Esse erro já aconteceu na Fase 177
e foi pego por um executor.

⚠️ **E gate só prova algo se o alvo existir:** gate apontando para caminho inexistente passa **por
omissão**. Os 13 caminhos abaixo foram conferidos como **existentes no disco** (`[ -e ]`) **antes** de
eu acreditar no vazio deles. Coluna `alvo` = `ok` significa "existe".

| # | Gate | Caminho | alvo | Resultado |
|---|---|---|---|---|
| 1 | o `AppLayout` não muda (D2/HEAD-14) | `resources/js/Layouts/AppLayout.jsx` | ok | **VAZIO** |
| 2 | o editor do outro dev não é tocado | `resources/js/Pages/Mlb/Publicador/Editor.jsx` | ok | **VAZIO** |
| 3 | a mesa do editor não é tocada | `resources/js/Components/Publicador/Mesa` | ok | **VAZIO** |
| 4 | `App\Support\Publicador` não muda | `app/Support/Publicador` | ok | **VAZIO** |
| 5 | `MlPublicacaoService` não muda | `app/Services/Mlb/Publicacao/MlPublicacaoService.php` | ok | **VAZIO** |
| 6a | nenhuma migration | `database` | ok | **VAZIO** |
| 6b | nenhuma migration | `database/migrations` | ok | **VAZIO** |
| 7 | as 9 páginas não mudam | `resources/js/Pages` | ok | **VAZIO** |
| 8 | `atalhosDoHeader.js` não muda | `.../header/atalhosDoHeader.js` | ok | **VAZIO** |
| 9 | `PainelVisaoGeralService` não muda | `app/Services/Publicador/PainelVisaoGeralService.php` | ok | **VAZIO** |
| 10 | estado é do orquestrador | `.planning/ROADMAP.md` `.planning/REQUIREMENTS-v26.md` | ok | **VAZIO** |
| 11 | só a rota nova | `routes/` → linhas removidas | ok | **0 linhas removidas** (`grep '^-'` vazio) |
| 12 | nenhum pacote | `package.json` `package-lock.json` `composer.json` `composer.lock` | ok | **VAZIO nos 4** |
| 13 | nenhum arquivo deletado | `--diff-filter=D --name-only` | — | **VAZIO** |
| 14 | Creative Engine intocado (aviso do `CLAUDE.md`) | `AnunciarML.jsx` `Publicador/Tarefas.jsx` `app/Services/Creative` | ok | **VAZIO** |

### ⚠️ A prova de que os 14 vazios NÃO são vazios por construção

O **mesmo range**, aplicado aos caminhos que eu **espero não-vazios**, devolve exatamente os
**14 arquivos das três waves**:

```
git diff --stat da54a9ee..HEAD -- app routes tests resources/js
→ 14 files changed, 3382 insertions(+), 22 deletions(-)
```

| arquivo | ± |
|---|---|
| `app/Http/Controllers/MlbPublicadorEntradaController.php` | +33 |
| `app/Services/Publicador/NovaPublicacaoService.php` | +257 |
| `.../header/ModalNovaPublicacao.jsx` | +293 |
| `.../header/PainelNovaPublicacao.jsx` | +245 |
| `.../header/PublicadorHeader.jsx` | +47 / −15 |
| `.../header/novaPublicacao.js` | +174 |
| `.../identidade/TileDoProduto.jsx` | +19 / −2 |
| `routes/mlb_anuncios.php` | +11 |
| `tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php` | +834 |
| `tests/js/publicador-configuracoes-render.test.js` | +8 / −1 |
| `tests/js/publicador-header-render.test.js` | +114 / −4 |
| `tests/js/publicador-identidade-nova-render.test.js` | +37 |
| `tests/js/publicador-modal-nova-publicacao.test.js` | +1303 |
| `tests/js/publicador-vocabulario-novo.test.js` | +7 |

**Logo o range está vivo.** `.planning/STATE.md` tem **+207 / −0** no range — os três blocos aditivos
escritos à mão pelas waves, **0 linhas removidas**, conferido por `git diff --numstat`.

## 5. Árvore e órfãos

- `git status --short -- tests/js` → **vazio**. **Nenhuma fixture órfã** (`ls tests/js/.*.mjs` →
  nenhum). Os 4 stubs que a campanha de mutação da wave 3 deixou foram apagados **um por um com
  `rm -f`**, nunca com `git clean`.
- `git status --short | grep -v '^??'` → **vazio**. Nenhum arquivo rastreado modificado, nem meu nem
  de outra sessão.
- **Não meus, intocados:** `?? tests/Feature/CompanyPortfolioAccessTest.php` e `public/images/*`. Já
  estavam não rastreados antes da fase. **Não tocados, não commitados, não apagados** — a árvore é
  compartilhada com outro dev ATIVO. `public/build` é gitignored (`.gitignore:41`), então o build não
  sujou nada.
- **Conjunto conferido:** os arquivos **novos** de `resources/js` no range são exatamente 3
  (`ModalNovaPublicacao.jsx`, `PainelNovaPublicacao.jsx`, `novaPublicacao.js`) e **os 3 estão em
  `ARQUIVOS_NOVOS`** do gate de vocabulário (`tests/js/publicador-vocabulario-novo.test.js`). A lista
  é constante de propósito — esquecer faria o gate passar **por omissão**. Nenhuma exclusão a declarar.

---

## A. FID-01 CONSOLIDADA — as 32 medidas do modal

⚠️ **A coluna "Implementado" foi conferida no CÓDIGO, com grep/script, não copiada dos planos nem
das SUMMARYs das waves.** Protótipo = `new_layout_publicador/PublicadorHeader.dc.html`, com a linha
citada em cada item (as linhas foram **re-conferidas** por `grep -n`: template **132-160**, lógica
**204 / 212-214 / 229-230**).

### A.1 — Casca (12 medidas, da wave 3) · fonte: `ModalNovaPublicacao.jsx:88,101`

| # | Elemento | Protótipo | Implementado (medido na fonte) |
|---|---|---|---|
| 1 | fundo — posição | `position:fixed; inset:0` (`:133`) | `fixed inset-0` em `CLASSES_FUNDO` |
| 2 | fundo — camada | `z-index:80` (`:133`) | `z-[80]` |
| 3 | fundo — cor | `rgba(0,0,0,0.65)` (`:133`) | `bg-black/[0.65]` → **`#000000a6` no CSS** ✔ |
| 4 | caixa — largura | `width:620px` (`:134`) | `w-[620px]` → **`620px` no CSS** ✔ |
| 5 | caixa — topo | `padding-top:110px` no fundo (`:133`) | `top-[110px]` na caixa (divergência **D-2**) |
| 6 | caixa — centro | `justify-content:center` + `align-items:flex-start` (`:133`) | `left-1/2 -translate-x-1/2` (divergência **D-2**) |
| 7 | caixa — fundo | `background:#111111` (`:134`) | `bg-pub-surface` → **`#111111`** ✔ |
| 8 | caixa — borda | `1px solid #2E2E2E` (`:134`) | `border border-pub-line-strong` → **`#2e2e2e`** ✔ |
| 9 | caixa — raio | `border-radius:16px` (`:134`) | `rounded-pub-card` → **`16px`** ✔ |
| 10 | caixa — sombra | `0 30px 80px rgba(0,0,0,0.8)` (`:134`) | `shadow-pub-modal` → **valor idêntico** ✔ |
| 11 | caixa — corte | `overflow:hidden` (`:134`) | `overflow-hidden` |
| 12 | caixa — fonte/cor | Geist no documento; `#F4F4F5` nos filhos | `font-pub text-pub-txt` **na caixa** — obrigatório: o conteúdo portado não herda nada do header |

### A.2 — Cabeçalho, lista, vazio e rodapé (20 medidas, da wave 2) · fonte: `PainelNovaPublicacao.jsx`

| # | Elemento | Protótipo | Implementado (medido na fonte) |
|---|---|---|---|
| 1 | cabeçalho | `flex; gap:12px; padding:0 16px; height:60px; border-bottom:1px solid #1F1F1F` (`:135`) | `flex h-[60px] items-center gap-[12px] border-b border-pub-line-subtle px-[16px]` (L177) |
| 2 | ícone de busca | svg 18×18, `viewBox 0 0 16 16`, `stroke #A1A1AA`, `stroke-width:1.6` (`:136`) | `<Search size={18} strokeWidth={2.4} className="flex-none text-pub-txt-2" />` — **2,4 = 1,6 × 1,5** (`viewBox` 16 → 24 do Lucide) |
| 3 | input | `flex:1; height:100%; border:none; background:transparent; color:#F4F4F5; font-size:16px; outline:none` (`:137`) | `h-full flex-1 border-none bg-transparent text-[16px] text-pub-txt outline-none placeholder:text-pub-txt-4` + **`type="text"`** |
| 4 | tecla Esc | mono 11px, `padding:2px 6px`, `1px #2E2E2E`, raio 5px, `#A1A1AA` (`:138`) | `flex-none rounded-[5px] border border-pub-line-strong px-[6px] py-[2px] font-pub-mono text-[11px] text-pub-txt-2` |
| 5 | corpo | `max-height:420px; overflow-y:auto; padding:8px` (`:140`) | `max-h-[420px] overflow-y-auto p-[8px]` |
| 6 | grupo | `display:flex; flex-direction:column` (`:142`) | `flex flex-col` |
| 7 | título do grupo | `padding:10px 10px 6px; 11px/600; letter-spacing:0.08em; #71717A` (`:143`) | `flex items-center justify-between px-[10px] pb-[6px] pt-[10px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4` |
| 8 | hint do grupo | `font-weight:500; letter-spacing:0; #52525B` (`:143`, span interno) | `font-medium tracking-[0] text-pub-txt-off` |
| 9 | item | `white-space:nowrap; gap:12px; width:100%; height:50px; padding:0 10px; border:none; raio 10px; transparent; cursor:pointer; text-align:left`; hover `#1A1A1A` (`:145`) | `flex h-[50px] w-full items-center gap-[12px] whitespace-nowrap rounded-pub-sub border-none bg-transparent px-[10px] text-left text-pub-txt cursor-pointer hover:bg-pub-hover-3` (L115) |
| 10 | tile | 34×34, raio 8px, grid center, **`font-size:11px`**, 600, `flex:none` (`:146`) | `<TileDoProduto … tamanho={34} letra="text-[11px]" />` (L123) |
| 11 | coluna nome/meta | `flex column; gap:2px; flex:1; min-width:0` (`:147`) | `flex min-w-0 flex-1 flex-col gap-[2px]` |
| 12 | nome | `font-size:13.5px; font-weight:500` (`:148`) | `truncate text-[13.5px] font-medium` (o `truncate` é adição declarada) |
| 13 | meta | `11.5px #8A8A90`, SKU em `'Geist Mono'` 11px, separador ` · ` (`:149`) | `text-[11.5px] text-pub-txt-3` + `<span className="font-pub-mono text-[11px]">{sku}</span>` + `{' · '}` + nota |
| 14 | CTA (pill) | `12px/600; padding:5px 10px; border-radius:7px` (`:151`) | `flex-none rounded-pub-control-sm px-[10px] py-[5px] text-[12px] font-semibold` |
| 15 | CTA — 3 tons | `ready` `rgba(250,204,21,0.14)`/`#FACC15` · `none` `#1F1F1F`/`#F4F4F5` · `draft` `#1F1F1F`/`#A1A1AA` (`:213`) | `accent` `bg-pub-accent/[0.14] text-pub-accent` · `solido` `bg-pub-line-subtle text-pub-txt` · `discreto` `bg-pub-line-subtle text-pub-txt-2` — **os 6 valores conferidos no CSS** ✔ |
| 16 | vazio | `padding:36px; text-align:center; 13px #8A8A90` (`:156`) | `p-[36px] text-center text-[13px] text-pub-txt-3` + o literal `VAZIO` |
| 17 | rodapé | `flex; gap:8px; padding:12px 16px; border-top:1px solid #1F1F1F; background:#0D0D0D; 12px #8A8A90` (`:158`) | `flex items-center gap-[8px] border-t border-pub-line-subtle bg-pub-sunken px-[16px] py-[12px] text-[12px] text-pub-txt-3` |
| 18 | glyph do rodapé | caixa 16×16, 2 retângulos 9×12, raio 2px, borda 1.4px `#D4D4D8`, trás `#0D0D0D`, frente `#FACC15`, offsets 0/0 e 5/3 (`:159`) | `<GlyphDaFase fase={2} tamanho="rodape" />` → `h-[16px] w-[16px]` · `h-[12px] w-[9px] rounded-[2px] border-[1.4px]` · `border-pub-fase1` · `left-0 top-0` / `left-[5px] top-[3px]` · `bg-pub-sunken` / `bg-pub-accent`. **REUSADO, não redesenhado** |
| 19 | frase do rodapé | literal (`:160`) | a constante `RODAPE`, **byte a byte** (ver A.3) |
| 20 | tipografia | nada de 14px, nenhuma escala nomeada | só `text-[11px]`, `[11.5px]`, `[12px]`, `[13px]`, `[13.5px]`, `[16px]` — e o gate de vocabulário reprova escala nomeada |

### A.3 — Os 12 literais de tela, medidos BYTE A BYTE contra o protótipo

| literal | origem no protótipo | no protótipo | no código |
|---|---|---|---|
| `PRONTOS PARA FASE 2` | `:213` rótulo ready | sim | sim |
| `reaproveita a Fase 1` | `:213` hint ready | sim | sim |
| `Criar combo` | `:213` CTA ready | sim | sim |
| `SEM PUBLICAÇÃO` | `:213` rótulo none | sim | sim |
| `1 unidade por oferta` | `:213` hint none | sim | sim |
| `Publicar Fase 1` | `:213` CTA none | sim | sim |
| `RASCUNHOS` | `:213` rótulo draft | sim | sim |
| `retome de onde parou` | `:213` hint draft | sim | sim |
| `Continuar` | `:213` CTA draft | sim | sim |
| `Qual produto você quer publicar?` | `:137` placeholder | sim | sim |
| `Nenhum produto encontrado` | `:156` vazio | sim | sim |
| `A Fase 2 usa o mesmo Publicador e reaproveita categoria, atributos e ficha técnica da Fase 1.` | `:160` rodapé | sim | sim |

**0 falhas de 12.** **Contra-prova:** a mesma frase **sem o acento** em "técnica" está **ausente** —
a sonda compara byte a byte, não aproximadamente.

---

## B. ⚠️ As medidas em que o PROTÓTIPO venceu o PLANO

> *"Quero que reproduza fielmente o layout, já tentei fazer um redesign e não saiu como eu esperava
> **porque você adaptava muito o novo layout ao sistema atual**"* — usuário, 2026-10-11

**A tabela FID-01 dos planos é artefato derivado; o protótipo é a fonte.** Na Fase 177 foram **21**
medidas, e nas 21 o protótipo estava certo. Nesta fase foram **3**, todas da wave 2, todas resolvidas
a favor do protótipo — e **re-conferidas por mim agora, na fonte do protótipo**:

| # | A tabela do plano dizia | O protótipo diz (medido agora) | Implementado |
|---|---|---|---|
| **1** | `letra="text-[11px]"` **se** `:146` medisse 11px; "se medir 10.5px, o protótipo ganha e a prop NÃO nasce" | **`PublicadorHeader.dc.html:146` → `font-size:11px`** no tile de 34px. E `:54` confirma 11px no tile de 30px do dropdown | **Mediu 11px → a prop `letra` NASCEU** (aditiva; ausente = escada intacta). A escada do `TileDoProduto` devolve `text-[10.5px]` para `lado <= 34` — certa onde foi medida, errada nesta tela |
| **2** | a linha do item não listava `cursor` | **`:145` → `cursor:pointer`** explícito | `cursor-pointer` entrou. O default do navegador para `<button>` é `cursor: default`, então sem a classe o cursor divergiria do desenho |
| **3** | a linha do input não mencionava `type` | **`:137` → `<input>` sem `type`** (= `text`) | `type="text"`, e **não** `type="search"`: `search` acrescenta o botão nativo de limpar do Chrome, que o desenho não tem |

### A candidata que o plano levantou e que NÃO é divergência de medida

O plano pedia confirmar o `strokeWidth` do ícone de busca. Medido: `:136` declara `stroke-width:1.6`
em `viewBox="0 0 16 16"`; o Lucide desenha em `0 0 24 24`. `strokeWidth={2.4}` = `1.6 × 24/16`. **O
traço renderizado é geometricamente idêntico** — é **conversão de mecanismo**, não divergência de
medida. (A conta está escrita em `PublicadorHeader.jsx:52` e **vale para todo ícone do Lucide neste
módulo**.)

---

## C. Divergências DECLARADAS, separadas por tipo de restrição

Todas estão escritas no topo de `PainelNovaPublicacao.jsx` / `ModalNovaPublicacao.jsx`, para a
próxima pessoa não "consertar".

### C.1 — Falta de dado (o sistema não tem a informação nessa forma)

| # | Protótipo | Implementado | A restrição CONCRETA |
|---|---|---|---|
| **D-1** | `"Fase 1 publicada · Clássico e Premium"` · `"Recebido hoje · Portal do Cliente"` · `"Rascunho parado em Imagens"` (`:145`, mock `PRODS`) | `"Fase 1 publicada · {n} no ar"` · `"Recebido do Portal do Cliente"` · `"Rascunho em preenchimento · faltam {n}"` | O sistema **não sabe em que etapa o rascunho parou** (não existe coluna nem derivação de "última etapa tocada" — existe `faltam`, a contagem de bloqueios); **não existe a data relativa** naquele formato (existe `atualizado_em` ISO, que é o que ordena). ⚠️ **A geometria da nota (11,5px, `pub-txt-3`, mono no SKU, separador ` · `) está cumprida INTACTA.** `"Clássico e Premium"` é o caso em aberto — ver §Perguntas |
| **D-5** | lista mock constante, **sem estado de rede** (`:141`) | `Carregando…` e a frase de erro, **na geometria do vazio** (`:156`) | Com fetch real os dois estados existem, e **lista vazia em silêncio é proibida**. Usar a mesma geometria evita um segundo desenho de "nada aqui". `carregando` vence `erro`, e nenhum dos dois desenha lista |

### C.2 — Restrição técnica (o mecanismo mudou; o resultado computado não)

| # | Protótipo | Implementado | A restrição CONCRETA |
|---|---|---|---|
| **D-2** | backdrop + centralização num **contêiner flex** (`:133`), com a caixa como FILHA | `Dialog.Overlay` + `Dialog.Content` **irmãos** no portal, com `fixed left-1/2 top-[110px] -translate-x-1/2` | O `backdrop-filter` do header **cria containing block para `position:fixed`**, então um overlay escrito ali dentro nasceria **recortado** na faixa de 109px; o header vive num `<main>` com `overflow-y-auto`; e **D2/HEAD-14 proíbem tocar no `AppLayout`**. No Radix o Content **não é filho** do Overlay. **Para caixa de largura fixa (620px) o resultado computado é idêntico.** Corolário: o `stop` (`:229`, um `stopPropagation` na caixa) **não tem equivalente** — ele existe só porque lá o backdrop é o PAI; há asserção negativa provando que nenhum `stopPropagation` entrou |
| **D-3** | `rgba(0,0,0,0.65)` (`:133`) | `bg-black/[0.65]` → **`#000000a6`, medido no CSS** | Não há token `pub-*` para preto puro com alfa e **TOK-15 proíbe hex literal**; `black` é cor padrão do Tailwind, não hex. **Valor exato** |
| **D-4** | foco por `setTimeout(…, 30)` (`:204`) | `onOpenAutoFocus` + `preventDefault()` + `inputRef.current?.focus()` | O protótipo precisa do timer porque lá não há gerenciador de foco nenhum. Com o do Radix, o alvo fica **determinístico** (o input de busca) em vez de depender da ordem do DOM — e **sem timer**, que é o que poderia correr contra a montagem. Há asserção negativa de `setTimeout` |
| **D-6** | nenhum título acessível | `<Dialog.Title className="sr-only">` + `aria-describedby={undefined}` | Exigência do Radix 1.1.15 (`@radix-ui/react-dialog/dist/index.mjs:288`, lido antes de decidir) e o protótipo não tem título visível. `sr-only` é classe padrão do Tailwind — **nenhum pixel** é acrescentado à tela |
| **D-8** | lista de produtos **embutida** na tela (mock) | **endpoint lazy**, 1 chamada por montagem e por conta | `produtosParaTela()` é a consulta mais pesada do módulo e o header vive nas **9 páginas**: prop faria toda página pagar em todo request, inclusive para quem nunca abre o modal. É o padrão já estabelecido pelo seletor de empresa (177-03/177-06) |
| **extra** | `font-family: inherit` no input (`:137`) | **não virou classe** | O **preflight do Tailwind v3 já aplica** `font-family: inherit` a `input`/`button`/`textarea`. O protótipo precisa declarar porque é HTML cru, sem preflight |
| **extra** | `flex:none` só no tile (`:146`) | `flex-none` também no ícone, na tecla `Esc` e no CTA | Sob `white-space:nowrap` com a coluna do meio em `flex-1`, sem `flex-none` os três encolhem. O dado dos três é curto e fixo no mock, que por isso não precisa declarar |
| **extra** | não declara cor de placeholder | `placeholder:text-pub-txt-4` | O protótipo deixa a do navegador; é o mesmo token que o input do dropdown de empresa usa (177-06), para os dois campos de busca do header não divergirem |
| **extra** | `white-space:nowrap` no botão, sem `truncate` (`:148`) | `truncate` no **nome** | Nome longo estouraria a caixa de 620px. ⚠️ **A meta (`:149`) NÃO recebeu `truncate`** — ver §Perguntas |

### C.3 — Decisão de contrato

| # | Protótipo | Implementado | Por quê |
|---|---|---|---|
| **D-7** | grupo vazio filtrado **no render** (`:214`: `.filter(g => g.items.length)`) | o servidor manda **as 3 chaves sempre**; o **cliente** filtra | **NOVA-03 é regra de RENDER**, não de payload. Shape estável é testável, e o cliente precisa filtrar de qualquer jeito **depois da busca local**. Há **2 testes no servidor + 16 no cliente** que reprovam quem "consertar" isso no servidor (mutação 7 da wave 1 derrubou 12 casos; mutação 13 da wave 2, 16) |

### ⚠️ Auditoria FID-02: nenhuma justificativa da lista proibida apareceu

Varri os arquivos da fase **e as 3 SUMMARYs das waves** por *"ficou mais parecido com o resto do
sistema"*, *"mantive o token `ecf-*` por consistência"*, *"o componente que já existe faz parecido"* e
*"o Tailwind não tem esse passo"*.

**Resultado: 0 usos.** Os únicos 2 casamentos são as **próprias frases de proibição** escritas em
`178-02-PLAN.md:217` e `178-04-PLAN.md:202`. ⚠️ **Mais:** a wave 2 encontrou um caso em que a
justificativa proibida *seria* a tentadora — o item do dropdown de empresa (177-06) não tem
`cursor-pointer` — e **recusou usá-la explicitamente**: *"'o componente que já existe faz parecido' é
justificativa vetada por FID-02"*. Foi a decisão certa, e o §Achados abaixo mostra por quê.

---

## D. Cirurgia de gates e mutação — MEDIDAS, não declaradas

### D.1 — Asserções que saíram do diff: **(b) REMOVIDAS = ZERO**

```
git diff da54a9ee..HEAD -- tests/ | grep '^-' | grep assert
→ 1 linha, exatamente uma:
  -        assert.match(fonte, /router\.get\(route\('mlb\.anuncios\.publicador\.produtos'/);
```

| Classificação | Quantas | Quais |
|---|---|---|
| **(a) REAPONTADAS** | **1** | a linha acima, em `publicador-header-render.test.js` — o destino antigo do CTA (navegar para a seleção de produtos), que nesta fase virou "abrir o modal" |
| **(b) REMOVIDAS** | **0** | — ✔ |
| **(c) ENDURECIDAS** | **0** | nenhuma asserção da wave 2 ficou frouxa com a casca no ar (o corpo é renderizado direto, fora do portal, e a casca não toca nenhum dos 11 elementos travados na constante `FID`) |

**As 4 substitutas, todas nomeadas e todas conferidas presentes no arquivo agora:**

1. `assert.doesNotMatch(fonte, /router\.get\(route\('mlb\.anuncios\.publicador\.produtos'/, 'o CTA
   voltou a NAVEGAR em vez de abrir o modal (NOVA-09)')` — o **veto explícito** do destino antigo
   (`:657`). Sem ele, o nome novo do bloco apagaria a memória de que aquele destino é proibido — é a
   lição do item 1 da cirurgia da 177-09;
2. `assert.doesNotMatch(fonte, /\brouter\b/, 'import morto…')` (`:665`) — o `router` saiu do import
   porque era usado **só** no destino antigo (conferido por grep **antes** de remover);
3. `assert.match(fonte, /<ModalNovaPublicacao/)` (`:644`) + o import do arquivo;
4. `assert.match(fonte, /function abrirNovaPublicacao\(\)\s*\{\s*if \(!temConta\) return;\s*setNovaPublicacaoAberta\(true\);\s*\}/, 'abrirNovaPublicacao tem de barrar sem conta ANTES de abrir')`
   (`:651`) — prova que a abertura está **depois** da guarda de conta, não num `onClick` solto que a
   pularia.

**As outras 4 linhas removidas em `tests/` (total: 5) NÃO são asserções** — medidas uma por uma:

| linha removida | o que é |
|---|---|
| 2× `external: ['react', …, '@radix-ui/react-popover'],` | config do esbuild, substituída pela versão **com** `'@radix-ui/react-dialog'` (Regra 3 — sem ela o bundle quebra com `Dynamic require of "react"`). Conferido presente nos dois harnesses: `publicador-header-render.test.js:71` e `publicador-configuracoes-render.test.js:141` |
| 1× `test('PublicadorHeader — o CTA e o ⌘K têm destino REAL até a Fase 178 (EXMP-03)', …)` | **título** de bloco, renomeado (o "até a Fase 178" expirou) |
| 1× `await contexto.test('o destino é a seleção de produtos da conta, via router.get', …)` | **título** de sub-bloco, renomeado |

A asserção dos **dois** `onClick={abrirNovaPublicacao}` **não foi tocada** — e eu a re-medi:
`grep -c` devolve **2**, com `assert.equal(…, 2, 'CTA e busca devem chamar a MESMA função')` no gate.

### D.2 — Mutação: 69 na fase, 69 reprovações, 3 recusadas antes de aplicar

| wave | mutações | reprovações | onde |
|---|---|---|---|
| 01 (contrato do servidor) | 14 | 14 | `NovaPublicacaoService.php`, a action, a rota |
| 02 (o corpo) | 26 | 26 | `PainelNovaPublicacao.jsx`, `novaPublicacao.js`, `TileDoProduto.jsx` |
| 03 (a casca) | 29 | 29 | `ModalNovaPublicacao.jsx`, `PublicadorHeader.jsx` |
| **total** | **69** | **69** | — |

Todas com **restauração por cópia pristina conferida por `sha256`** depois de cada uma, e com três
registros por mutação: (a) que o padrão **casou**, (b) **qual linha** mudou, (c) **quais casos
caíram**.

⚠️ **Os 3 furos que o detector fechou ANTES de aplicar** — e é o achado metodológico da fase:

| wave | mutação | por que foi recusada |
|---|---|---|
| 02 | `strokeWidth={2.4}` → `{1.6}` | **2 ocorrências, esperava 1** — a segunda vive no **comentário** que explica a conta `1,6 × 1,5` |
| 03 | `top-[110px]` → `top-[100px]` | **2 ocorrências** — a segunda no comentário que declara a divergência D-2 |
| 03 | tirar `-translate-x-1/2` | **2 ocorrências** — idem |

Com `sed`/`String.replace` as três teriam caído no **comentário**, o teste passaria e o relatório
registraria **três furos que não existem**. É literalmente a origem de 3 dos "furos" da Fase 177.
**Travar a contagem de ocorrências do alvo antes de aplicar é o que impede a prova por mutação de
mentir.**

### D.3 — Auditoria GATE-05: **0 achados**

Varri os **5 arquivos de teste** da fase (**673 linhas com `assert`**) por asserção de desabilitado
sem o sinal de igual. `assert.match(tag, /disabled/)` é **asserção vazia neste projeto**, porque as
classes têm `disabled:opacity-40`.

**Resultado: 0.** Toda asserção de desabilitado usa `/disabled=/` ou `/aria-disabled="true"/` — por
exemplo `publicador-header-render.test.js:148-149`:
`assert.doesNotMatch(html, /disabled=/)` e `assert.doesNotMatch(html, /aria-disabled=/)`, **com o `=`**.

**Contra-prova:** injetei `assert.match(tag, /disabled/);` na sonda e ela **pegou**. A auditoria não é
decorativa.

### D.4 — Auditoria de prefixo: 6 acusações, **as 6 são falso positivo DA MINHA SONDA**

| arquivo:linha | o que a sonda viu | o que é de verdade |
|---|---|---|
| `publicador-modal-nova-publicacao.test.js:638` | `includes('text-[11px]')` | **comentário** que ALERTA: *"`includes('text-[11px]')` passaria mesmo se o tile…"* |
| `publicador-modal-nova-publicacao.test.js:1093` | `includes('font-pub')` | **comentário** que ALERTA: *"…perderia a Geist EM SILÊNCIO. `includes('font-pub')` passaria com…"* |
| `publicador-header-render.test.js:748` | `includes('font-pub')` | **comentário** que ALERTA: *"`/font-pub[\s"]/` e não `includes('font-pub')`: substring casa com…"* |
| `publicador-identidade-nova-render.test.js:507` | `includes('text-[11px]')` | **comentário** que ALERTA o mesmo |
| `publicador-identidade-nova-render.test.js:514` | `assert.ok(!semLetra.includes('text-[11px]'))` | **`Array.prototype.includes`** — `classesDe()` devolve `(...).split(/\s+/).filter(Boolean)`, uma **lista de tokens**. É **igualdade exata**, não substring |
| `publicador-identidade-nova-render.test.js:517` | `assert.ok(comLetra.includes('text-[11px]'))` | idem |

**Nenhuma asserção confere `font-pub` por `includes` cru sobre string, nem `text-[11px]` por
substring.** As 4 primeiras são justamente os avisos contra o padrão; as 2 últimas operam sobre array.
**Registrado como achado da sonda, não do código** — e é a terceira vez nesta wave que a medida errada
foi a minha, não a do código.

---

## E. Rastro dos 10 REQ-IDs — NOVA-01 … NOVA-10

⚠️ **`ROADMAP.md` e `REQUIREMENTS-v26.md` NÃO foram tocados** (gate 10, diff vazio). Quem marca é
quem fecha a fase — ver §Problemas para a armadilha medida.

| REQ | Estado | Onde está | O gate que prova |
|---|---|---|---|
| **NOVA-01** — modal 620px, `top:110px`, backdrop `rgba(0,0,0,0.65)`, raio 16px, sombra `0 30px 80px rgba(0,0,0,0.8)`; cabeçalho 60px com input 16px e `Esc` em mono 11px; corpo `max-height:420px` com scroll | **entregue** | `ModalNovaPublicacao.jsx:88,101` (casca) + `PainelNovaPublicacao.jsx:177-200` (cabeçalho e corpo) | `CLASSES_FUNDO`/`CLASSES_CAIXA` por **igualdade da string inteira** + token exato de `Set`; os 12 elementos do corpo por **igualdade de conjunto de classes**; **os valores conferidos no CSS construído** (§2b); mutações 1-11 da wave 3 e 1-5 da wave 2 |
| **NOVA-02** — 3 grupos por próximo passo, nesta ordem, com os rótulos/hints/CTAs e os 3 tons; grupo sem item não aparece | **entregue** | `novaPublicacao.js` (`GRUPOS`) + `NovaPublicacaoService.php` (`GRUPOS`, a classificação) | os **9 literais medidos byte a byte** contra `:213` (§A.3); ordem travada em 11 casos (mutação 12 da wave 2) e 6 no servidor (mutação 6 da wave 1); os 3 tons cada um **no item do seu grupo** |
| **NOVA-03** — item de 50px: tile 34px, nome 13.5px/500, SKU mono 11px + nota 11.5px `#8A8A90`, CTA 12px/600 em pill de 7px. Grupo sem item não aparece | **entregue** | `PainelNovaPublicacao.jsx:108-140` + `filtrarGrupos()` | as medidas 9-15 da FID-01 (§A.2) por conjunto de classes; o filtro de grupo vazio por **16 casos** (mutação 13 da wave 2) e **12** no servidor (mutação 7 da wave 1) |
| **NOVA-04** — busca ignora acento e caixa (NFD), sobre nome **e** SKU | **entregue** | `novaPublicacao.js` → `filtrarGrupos()` + `palheiro()`, usando `normalizar()` de `identidade.js` | 6 cenários medidos (`espelho`/`ESPELHO`/`bisote`/`BISOTÊ`/`esp-rd`/`60 cm esp`/`  espelho  `/`cadeira gamer`); `normalizar` aplicada **campo por campo** (template literal com campo-objeto viraria `[object Object]` e "object" acharia o produto) |
| **NOVA-05** — vazio "Nenhum produto encontrado", 13px, centralizado, padding 36px | **entregue** | `PainelNovaPublicacao.jsx:202-206` | literal byte a byte (§A.3); `p-[36px] text-center text-[13px] text-pub-txt-3` por conjunto; mutação 23 da wave 2; **e** a prova de que `carregando`/`erro` usam a MESMA geometria |
| **NOVA-06** — rodapé `#0D0D0D` com o glyph de Fase 2 e a frase literal | **entregue** | `PainelNovaPublicacao.jsx:237-242` + `GlyphDaFase` (`tamanho="rodape"`) | a frase **inteira**, byte a byte, com o ponto final (mutação 14 da wave 2); os **2 retângulos** do glyph por contagem — é a FORMA da Fase 2 (mutações 15 e 16); `bg-pub-sunken` = `#0d0d0d` no CSS |
| **NOVA-07** — todo CTA tem destino real: "Criar combo" → tela do Produto, "Publicar Fase 1" e "Continuar" → editor. Nenhum CTA sem destino | **entregue** | `NovaPublicacaoService.php` (a `url` montada **no servidor** por `route()`) + `ModalNovaPublicacao.jsx:256` (`router.get(destino)`) | `url` nunca montada no cliente: `doesNotMatch(/route\(['"]mlb\.anuncios\.publicador\.(editor\|produto\|produtos)['"]/)` e contagem de `route(` no arquivo = **1**; **item sem destino não entra** em `prontos_fase2` (mutação 9 da wave 1) e, quando a `url` chega inútil, o item **aparece desabilitado** com `disabled=`+`aria-disabled="true"`+`title` (6 formas de url inválida testadas); mutações 8 e 14 |
| **NOVA-08** — os 3 grupos vêm do **servidor**, das fontes que já existem; nenhuma classificação no cliente | **entregue** | `NovaPublicacaoService::grupos()` + `GET mlb/anuncios/publicador/empresas/{conta}/nova-publicacao` | a regra de `prontos_fase2` conferida **por reflexão** contra `PainelVisaoGeralService::prontosParaFase2()` (`:592`, `private`) nos 5 casos de fronteira; **1** chamada a `produtosParaTela()` por `->once()`; 18 consultas estáveis entre 6 e 12 produtos; rota nomeada conferida agora: `artisan route:list --name=nova-publicacao` → **1 rota** |
| **NOVA-09** — abre por ⌘K/Ctrl+K, por **N** fora de input e pelos **dois** botões; fecha com Esc ou clique fora; o input recebe foco ao abrir | **entregue** | `PublicadorHeader.jsx` (`abrirNovaPublicacao`/`fecharTudo` + `useAtalhosDoHeader`) + `ModalNovaPublicacao.jsx` (`onOpenAutoFocus`) | os **2** `onClick={abrirNovaPublicacao}` (contagem exata, re-medida = 2); `aoAbrirBusca: abrirNovaPublicacao` e `aoFechar: fecharTudo` por regex da função inteira; `atalhosDoHeader.js` **não mudou** (gate 8 vazio) e o `ignoraAtalho()` que já existe barra `INPUT`/`TEXTAREA`/`SELECT`; mutações 12, 13, 23, 24, 25, 27 da wave 3 |
| **NOVA-10** — este modal é a resposta nova para a "entrada da Fase 2", adiada pelo usuário em 10/10 | **entregue** | a fase inteira; `prontos_fase2` → `mlb.anuncios.publicador.produto`, onde mora o painel "Criar Fase N" | o destino do grupo `prontos_fase2` aponta para a tela do Produto e **não** para o editor (mutação 8 da wave 1 derrubou 2 casos) |

**10 de 10 entregues. Nenhum parcial.** ⚠️ **A única ressalva é de CONTEÚDO, não de requisito:** a
nota de `prontos_fase2` diz `"{n} no ar"` e o protótipo diz `"Clássico e Premium"` (D-1). **NOVA-02 e
NOVA-03 não especificam o texto da nota** — especificam a geometria, que está cumprida intacta. Fica
como pergunta ao usuário.

---

## F. Known Stubs e o que NÃO foi feito

### F.1 — Stubs: **nenhum**

Varri os 7 arquivos criados/modificados da fase por `=[]`/`={}`/`=null`/`=""` fluindo para render,
`TODO`, `FIXME`, "não disponível", "coming soon", "placeholder", `SeloExemplo`, `dadosDeExemplo`.

**0 stubs.** Os casamentos são todos falso positivo e foram conferidos um por um: o `placeholder` é o
**atributo HTML do input** (`PainelNovaPublicacao.jsx:188`) e seu docblock
(`novaPublicacao.js:68`); "TODO/TODOS" é a palavra portuguesa em comentários (`"⚠️ ?? null em TODOS os
acessos"`); e `TileDoProduto.jsx:8` cita o README (*"Tiles de produto (placeholder até haver foto
real)"*) — descrição do token, não stub.

⚠️ **Nenhum item desta fase nasceu decorativo** (D5/D6 da milestone). Todo CTA tem destino real
montado no servidor; a lista vem de consulta real; não há `SeloExemplo` nem `dadosDeExemplo` em
nenhum dos 3 arquivos novos.

### F.2 — O que NÃO foi feito, **de propósito**

| item | por quê |
|---|---|
| **Os corpos das 9 páginas** seguem antigos, com `ecf-*` | **HEAD-15**: *"as páginas convivem com corpo antigo e header novo até as fases seguintes. Esse estado intermediário é aceito e declarado, não é defeito — e não autoriza 'harmonizar' o header com o corpo antigo (FID-02)."* Fases **179/180/181** |
| **`font-pub` NÃO aplicado ao corpo de nenhuma página** | **D-20 da 177**: a família é declarada **uma vez** na tag do `<header>` (cobre a subárvore) e nos componentes que **saem** dela por portal — os 2 `Popover.Content` do 177-06 e, agora, a `Dialog.Content` deste modal. TOK-01 diz "ao lado de Inter/Manrope, **sem trocar o `sans` global**", e `<body class="font-sans antialiased">` está travado por gate |
| **O fail pré-existente da grade** (`estrutura-grade-glide`) | outro território, outra fase (§3) |
| **`BarraDaConta` / `AbasDaConta` órfãos** (§10 do 177-09) | herdados da 177, fora do escopo desta fase. `BarraDaConta` **não** está órfão de fato — `PainelNovaPublicacao.jsx` importa `textoSeguro`/`textoUtil` dele |
| **As 47 / 40 / 92 `PHPUnit Deprecations`** | pré-existentes, medidas e declaradas (§3) |
| **O `cursor-pointer` que falta no item do dropdown de empresa (177-06)** | **ACHADO NOVO desta wave** — fora de escopo: é território da Fase 177 e este plano só modifica a SUMMARY. Ver §Achados |
| **`truncate` na meta do item** | o protótipo não resolve e nenhum plano pediu. Ver §Perguntas |
| **Deploy, push, qualquer comando no VPS** | **o deploy é decisão do usuário**, e a milestone v26.0 tem **outro dev ativo na mesma árvore**. Nenhum executor das 4 waves deployou, deu push ou tocou no VPS |
| **Marcar os 10 checkboxes NOVA-01..10 e o progresso da fase** | território proibido neste plano **e** os handlers falham em silêncio. Ver §Problemas |

---

## ⚠️ Achados desta wave (medidos agora, não herdados)

### 1. FECHADO — o protótipo declara `cursor:pointer` TAMBÉM no `:53`, e a Fase 177-06 ficou sem ele

A wave 2 deixou isto como pergunta aberta: *"o protótipo declara `cursor:pointer` em `:145` (item do
modal) e também em `:53`? — **não foi conferido em `:53`**"*. **Conferido agora, nos dois lados:**

| | protótipo | implementado | bate? |
|---|---|---|---|
| item do **modal** (Fase 178-02) | `PublicadorHeader.dc.html:145` → **`cursor:pointer`** | `PainelNovaPublicacao.jsx:115` → **`cursor-pointer`** | ✔ |
| item do **dropdown de empresa** (Fase 177-06) | `PublicadorHeader.dc.html:53` → **`cursor:pointer`** | `SeletorDeEmpresaDoHeader.jsx:171` → **SEM `cursor-pointer`** | ✖ |

`grep -rn "cursor-pointer" resources/js/Components/Mlb/Publicador/header/` devolve **uma única
linha**, a do modal. **A Fase 177-06 ficou com uma divergência real de fidelidade.** O default do
navegador para `<button>` é `cursor: default`, então o cursor sobre o item do dropdown de empresa
**divergirá** do desenho.

⚠️ **NÃO consertado aqui.** É território da Fase 177, e `files_modified` deste plano é só a SUMMARY —
o plano diz, literalmente: *"Este plano MEDE e CONSOLIDA; não corrige. Defeito achado aqui vira
pergunta ao usuário, não commit de código."* **O conserto é de uma linha** (`cursor-pointer` no
`cn()` da linha 171) **mais a atualização do conjunto de classes no gate** daquele arquivo — cabe
numa quick task. ⚠️ **E não cabe em `deferred-items.md` por decisão do plano**, que restringe
`files_modified` à SUMMARY; quem fechar a fase decide se registra lá.

### 2. A divergência de contagem de páginas do manifest é de vocabulário (§1), não de fato

### 3. A minha sonda de CSS errou 14 de 30 na primeira rodada — 3 causas, todas da sonda (§2b)

### 4. A minha auditoria de prefixo acusou 6 linhas — as 6 falso positivo (§D.4)

**Os achados 2, 3 e 4 têm a mesma natureza e vale nomeá-la:** em três de três tentativas, **a medida
errada foi a minha, não a do código**. É exatamente o motivo pelo qual o fechamento é uma wave
separada — e o motivo pelo qual cada sonda desta wave carrega **isca** e **contra-prova**: sem elas,
eu não teria como distinguir "a sonda está certa e o código está errado" de "a sonda está errada".

---

## Verificação

| # | Gate | Comando | Resultado |
|---|---|---|---|
| 1 | build | `npm run build` | **verde, exit 0, 31,91s** |
| 2 | manifest pelo JSON | `node --input-type=commonjs` + `require('./public/build/manifest.json')` | **11 de 11** · 480 entradas · 0 fora · 0 entrada própria para os 3 Components |
| 3 | **classes no CSS construído** | sonda que **desescapa** `app-Cg8qkwwN.css` (212.455 bytes) | **70 presentes, 0 faltando** · **5 iscas ausentes** · `font-pub` ≠ `font-pub-mono` |
| 4 | **valores no CSS construído** | corpo da regra × hex do protótipo | **0 falhas de 16** · contra-prova OK |
| 5 | literais × protótipo | byte a byte | **0 falhas de 12** · contra-prova OK |
| 6 | baseline PHP | `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1848 · 11.421 asserções · 0 falhas** (47 deprecations pré-existentes) |
| 7 | baseline JS | `npm run test:js` | **3012 · 27 suítes · 3011 pass · 1 fail** (o pré-existente nomeado) |
| 8 | Phase134 | `phpunit tests/Feature/Phase134` | **37 · 152 asserções · 0 falhas** |
| 9 | Phase135 | `phpunit tests/Feature/Phase135` | **243 · 1.122 asserções · 0 falhas** |
| 10 | os **14 gates de diff** | `git diff --stat da54a9ee..HEAD -- <alvo>` | **14 VAZIOS**, com os **13 alvos conferidos existentes no disco** |
| 11 | range vivo | `git diff --stat da54a9ee..HEAD -- app routes tests resources/js` | **14 arquivos, 3382 inserções, 22 remoções** — o range está vivo |
| 12 | asserções removidas | `git diff da54a9ee..HEAD -- tests/ \| grep '^-' \| grep assert` | **1 linha**, a reapontada, com **4 substitutas nomeadas e conferidas presentes** |
| 13 | GATE-05 | sonda sobre 673 linhas de `assert` em 5 arquivos | **0 achados** · contra-prova OK |
| 14 | prefixo | sonda `includes('font-pub')` / `includes('text-[11px]')` | **6 acusações, 6 falso positivo** (§D.4) |
| 15 | FID-02 | busca das 4 justificativas proibidas | **0 usos** (só as frases de proibição nos PLANs) |
| 16 | rota nomeada | `artisan route:list --name=nova-publicacao` | **1 rota**: `mlb.anuncios.publicador.nova-publicacao` |
| 17 | árvore | `git status --short` | `tests/js` **vazio** · **0 rastreados modificados** · 0 fixtures órfãs |
| 18 | stubs | varredura dos 7 arquivos da fase | **0 stubs** |

**Nenhuma migration nesta fase inteira, nada a validar no VPS.** Nenhuma coluna nova, nenhum `enum`,
nenhum índice, nenhuma FK — as armadilhas de MariaDB que o SQLite dos testes não pega **não se
aplicam**. **Nenhum deploy, nenhum push, nenhum comando no VPS em nenhuma das 4 waves.**

### Registro de ameaças

| Threat ID | Como ficou mitigado | Prova |
|---|---|---|
| **T-178-12** (página fora do `manifest.json`) | as **11** entradas conferidas **pelo JSON** com `require()`, nunca por grep (o hash sai com hífen) | `manifest ok 11 de 11`, 480 entradas, 0 fora; e as 8 páginas do diretório conferidas também, 8/8 |
| **T-178-13** (afirmação visual sem prova) | declarado **2×** (no topo desta SUMMARY e no checkpoint) que **nenhum executor viu tela renderizada em nenhuma das 4 waves**; o roteiro de conferência é do usuário, na conta 459 | este documento; o checkpoint abaixo |
| **T-178-07** (commit na árvore compartilhada) | `git add -- <caminho>` + `git commit -m "…" -- <caminho>`; **nenhum** `git add -A`, `git stash`, `git reset`, `git checkout`, `git clean`, `--amend` | `git status --short \| grep -v '^??'` vazio; os 2 não-rastreados de outra sessão **registrados e intocados** |
| **T-178-14** (deploy não autorizado) | **nenhum** comando de deploy, push ou VPS neste plano nem nas 3 waves anteriores | o deploy é decisão do usuário, no checkpoint |
| **T-178-SC** (pacote novo / slopsquatting) | **nenhum pacote instalado em 4 planos.** `@radix-ui/react-dialog ^1.1.15` já era dependência (`package.json:36`) | gate 12: diff **vazio** nos 4 arquivos de pacote no range |

---

## Task Commits

| Task | Commit | O que entrou |
|---|---|---|
| 1 + 2 | (este) | `docs(publicador): fechamento da fase 178 — build, baselines, FID-01 e os gates` — a SUMMARY inteira |
| 3 | — | **checkpoint humano**, entregue e **não resolvido**: não produz commit |

Com pathspec (`git add -- <caminho>` e `git commit -F <arquivo> -- <caminho>`), porque a árvore é
**compartilhada** com outro dev ativo e outras sessões.

## Arquivos criados/modificados

- `.planning/phases/178-modal-nova-publicacao/178-04-SUMMARY.md` **(novo, este arquivo)**
- **`0` arquivos de código.** Este plano MEDE, não corrige — e **nenhuma medida do código reprovou**.

## Desvios do plano

### 1. [informativo] A contagem de entradas do manifest não divergiu, mas a NOMENCLATURA sim

O plano previa: *"se o número divergir de 11, registre a divergência em vez de ajustar o número"*. O
número **não divergiu** (11/11). O que divergiu foi o **vocabulário** entre o plano/177-09 ("11
entradas") e as SUMMARYs das waves 2/3 ("8 páginas"). **Registrado em §1 com os dois conjuntos
medidos**, e com a recomendação de nomenclatura para as Fases 179/180/181.

### 2. [informativo] A sonda de CSS precisou de 2 rodadas, e a correção foi DELA

O plano previa exatamente isto: *"Se alguma classe aparecer ausente, confira o escape antes de
registrar defeito — e registre o que mediu, nos dois casos."* Foi o que aconteceu, e está em §2b com
as 3 causas. **Nenhuma linha de código foi tocada.**

### 3. [adição] Medi VALOR, não só presença

O plano pedia sondar a **presença** das classes no CSS. Acrescentei a comparação do **valor** de cada
token contra o hex do protótipo (§2b) porque presença não é fidelidade: **classe presente com valor
errado é a falha silenciosa que a FID-01 existe para pegar**, e nenhuma das 3 waves havia medido isso.
As 16 bateram.

### 4. [adição] Isca e contra-prova em toda sonda

Nenhuma das 4 sondas desta wave é só positiva: a do CSS carrega 5 iscas, a de valor compara contra um
valor errado de propósito, a de literais busca a frase sem acento, a de GATE-05 injeta a asserção
vazia. **Gate sem isca não se distingue de gate decorativo** — e esta wave achou, com elas, três
erros na própria medição.

### 5. [informativo] A pergunta aberta #2 da wave 2 foi RESPONDIDA, não resolvida

O plano não pedia conferir o `:53`. Conferi porque era resposta de **uma linha de medição** e a wave 2
a deixou explicitamente em aberto. **Resultado: a divergência existe.** Registrada em §Achados;
**não consertada** (território da Fase 177, e este plano não toca código).

**Total de desvios:** 5. **Impacto:** nenhum aumento de escopo; três são medições a mais, duas são
registros.

## Problemas encontrados

- ⚠️ **`roadmap.update-plan-progress 178` reporta sucesso e NÃO escreve nada.** Medido pela wave 1:
  respondeu `{"updated": true, "plan_count": 4, "summary_count": 1, "status": "In Progress"}` e o
  `ROADMAP.md` ficou **idêntico** (conferido por `diff` contra cópia prévia e por `git status`). **A
  Fase 178 não tem linha na tabela de progresso do ROADMAP**, então o handler não tinha o que
  atualizar — mas reportou sucesso. **Não foi re-executado nesta wave** (território proibido).
- ⚠️ **`requirements.mark-complete` devolve `not_found` em silêncio para IDs de `REQUIREMENTS-vNN.md`**
  — o `REQUIREMENTS.md` da raiz parou na v17.0 (já registrado na memória do projeto).
  ➡️ **OS 10 CHECKBOXES `NOVA-01..NOVA-10` EM `.planning/REQUIREMENTS-v26.md` E O PROGRESSO DA FASE
  178 NO `ROADMAP.md` PRECISAM SER MARCADOS À MÃO POR QUEM FECHAR A FASE.** Não foram marcados aqui —
  gate 10 prova os dois arquivos intocados.
- `STATE.md` recebeu um **bloco aditivo escrito à mão**, **0 linhas removidas**, conferido por
  `git diff --numstat` antes do commit. `state.advance-plan` **não foi executado** — os handlers
  `state.*` já corromperam este arquivo **seis vezes** (o próprio topo do frontmatter registra as
  seis) e não existe bloco de milestone para a v26.0. Mesma disciplina das 3 waves (+207/−0 no range).
- `?? tests/Feature/CompanyPortfolioAccessTest.php` e `public/images/*` seguem não rastreados. **Não
  são meus** — já estavam assim antes da fase. **Não tocados, não commitados, não apagados.**
- **Um item para `deferred-items.md`** — o `cursor-pointer` que falta no item do dropdown de empresa
  (Fase 177-06). **Não escrito no arquivo** porque este plano restringe `files_modified` à SUMMARY;
  está em §Achados e nas perguntas ao usuário.

## Perguntas em aberto — TODAS para o usuário, nenhuma decidida aqui

1. ⚠️ **A nota de `PRONTOS PARA FASE 2` diz `"Fase 1 publicada · {n} no ar"`; o protótipo diz
   `"Fase 1 publicada · Clássico e Premium"`.** Herdada das waves 1, 2 e 3 — **a única divergência de
   CONTEÚDO da fase**. O dado existe (`anuncios[].listing_type_id`), mas a tradução
   `gold_special → "Clássico"` / `gold_pro → "Premium"` é literal de UI e **o contrato do item proíbe
   array**. As duas saídas mudam o **contrato do servidor**, não o render: (a) o servidor manda um
   campo **escalar** novo (ex.: `"tipos": "gold_special,gold_pro"`) e o cliente traduz; (b) o servidor
   manda a nota já com o texto, contrariando "nenhum rótulo de UI no payload". **A geometria da nota
   está cumprida intacta.**
2. **O `cursor-pointer` que falta no item do dropdown de empresa (Fase 177-06).** Provado nesta wave
   (§Achados 1). Conserto de 1 linha + o conjunto do gate. Vale uma quick task?
3. **A meta do item não recebeu `truncate`** (`:149`). Nota longa com `white-space:nowrap` pode
   estourar a caixa de 620px. O protótipo não resolve e nenhum plano pediu. Conserto: `truncate` na
   meta + atualizar `FID.meta` no gate.
4. **O modal abre a 110px do topo da JANELA, não centrado** — é o que o protótipo desenha (`:133`),
   mas o header real é `sticky` dentro de um `<main>` que rola: com a página rolada, o modal continua
   a 110px da janela, e **não** "logo abaixo do header" (que ali tem 109px). **Vale perguntar se ele
   gosta assim.**
5. **Herdadas da Fase 177, para o olho dele:** (a) o `sticky top-[108px]` dos filtros de Produtos ×
   `109px` — 1px sob a borda do header; (b) a **espessura dos traços dos ícones**, multiplicada por
   **1,5** na conversão de `viewBox` (16 → 24). Só o olho julga.
6. **Produto com `status.chave = 'erro'`** não aparece em lugar nenhum no modal, por decisão da wave 1
   (a casa dele é "O que fazer agora" da **Fase 179**). Se a 179 mudar de desenho, vale reconferir se
   o modal precisa de um quarto grupo — seria mudança de **contrato**, não de render.

---
*Fase: 178-modal-nova-publicacao · Plano 04 (fechamento)*
*Concluído: 2026-10-12*
</content>
</invoke>

## Self-Check: PASSED

- `.planning/phases/178-modal-nova-publicacao/178-04-SUMMARY.md` — existe (**804 linhas**, ≥ 120)
- Gate automatizado da Task 2 (`node --input-type=commonjs`) — **ok**: os 10 REQ-IDs `NOVA-01..10`,
  `FID-01` e o commit de partida `da54a9ee` todos citados
- `public/build/manifest.json` — existe, **480 entradas**, as **11** conferidas pelo JSON
- `public/build/assets/app-Cg8qkwwN.css` — existe, **70 classes** do modal presentes, **16 valores**
  de token batendo com o protótipo, **5 iscas ausentes**
- Os 3 arquivos novos de `resources/js` da fase — existem, e os **3 estão em `ARQUIVOS_NOVOS`** do
  gate de vocabulário
- `app/Services/Publicador/NovaPublicacaoService.php` — existe; rota
  `mlb.anuncios.publicador.nova-publicacao` registrada (`route:list` devolve **1** rota)
- Os **13 commits** citados (`78d2ef9c`, `9270fa41`, `aae3006b`, `f61a9ebe`, `e77d703f`, `71b76afb`,
  `64ff7f83`, `61703b8f`, `04244330`, `daff4649`, `755419be`, `acc24239`, `da54a9ee`) — **todos no
  histórico**
- As 4 baselines re-medidas: **1848/0** · **3012/3011/1** · **37/0** · **243/0**
- Os **14 gates de diff** vazios no range, com os **13 alvos conferidos existentes no disco**
- Asserções **removidas = 0**; a única linha de `assert` que saiu é a **reapontada**, com 4
  substitutas nomeadas e conferidas presentes
- `git status --short \| grep -v '^??'` — vazio (nenhum arquivo rastreado modificado)
- ⚠️ **Nenhuma tela foi renderizada nem conferida visualmente** — não há navegador neste ambiente, em
  nenhuma das 4 waves. A Task 3 é o checkpoint do usuário e **não foi resolvida por mim**
