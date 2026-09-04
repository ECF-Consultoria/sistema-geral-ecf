---
quick_id: 260904-jpn
slug: tabela-progressiva-fiel-a-referencia
date: 2026-09-04
type: quick
status: in-progress
files_modified:
  - resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx
  - resources/js/Pages/Admin/Financeiro.jsx
  - tests/Feature/Phase139/Phase139TabelaProgressivaFielTest.php
---

# Tabela progressiva e área expandida fiéis à referência

## O que o usuário disse (2026-09-04, olhando a tela em produção)

> "O layout não ficou igual ao que era para ser baseado na referência (...) ao clicar sobre uma
> empresa que abre o dropdown ficou bem diferente dentro (...) as fontes estão pequenas, a tabela
> progressiva não está igual (...) seja mais fidedigno à referência."

Ele mandou um print da referência mostrando a área expandida.

**Referência:** `design_handoff_fechamento/Fechamento.dc.html` e `README.md`.
⚠️ Ler como **especificação**. Nada de lá vai para produção.

## Por que ficou assim (contexto honesto)

No planejamento da Fase 139 optou-se por **reaproveitar a tabela que já existia** — a das Fases
137/138, que também faz o cadastro de faixas — em vez de criar uma segunda no accordion. A decisão
evitou duplicação, mas aquela tabela foi desenhada para outro fim e ficou com a densidade errada.

## Decisão do usuário já tomada — NÃO reabrir

**A fonte não muda.** Fica a do projeto (`font-mono` do Tailwind). O usuário recusou explicitamente
adotar `JetBrains Mono` quando perguntado, ciente de que ela é parte do visual da referência.

⛔ Não introduzir `JetBrains Mono`, `Instrument Sans` nem `fonts.googleapis`.
⛔ A paleta continua a do projeto (tokens `ecf-*`).

**O que muda é espaçamento, tamanho de texto e estrutura** — que é onde está o desvio real.

## O desvio, medido

`TabelaFaixasSection.jsx` tem **três cópias** da mesma tabela (`grep "Faturamento até"` = 3), todas
com a densidade errada:

| aspecto | referência | está no ar |
|---|---|---|
| texto das linhas | 13px | **11px** |
| padding das linhas | 12px 18px | **py-1.5 px-2.5** (6px/10px) |
| padding do cabeçalho | 10px 18px | py-1.5 px-2.5 |
| colunas | grade `80px 1fr 160px`, gap 16px | `<table>` com larguras automáticas |
| "Faturamento até" | à **esquerda** | à direita |
| divisor entre linhas | 1px sutil | `white/[0.03]` |
| raio da caixa | 12px | `rounded-lg` (8px) |

Texto quase pela metade e respiro pela metade: é exatamente o "fontes pequenas".

## Tarefas

### 1. Uma subcomponente, não três cópias

Extrair **uma** subcomponente de tabela progressiva e usar nas três ocorrências. Ela recebe as
faixas, a ordem da faixa atual e o rótulo.

> Três cópias já divergiram entre si. Corrigir três vezes é garantir que divergem de novo.

### 2. A densidade da referência

Grade `80px 1fr 160px` com gap 16px; linhas com padding 12px 18px e texto 13px; cabeçalho com
padding 10px 18px, 11px/600, maiúsculo, `letter-spacing 0.05em`, sobre a superfície interna;
divisor de 1px entre linhas; caixa com raio 12px e borda.

- "Faixa" e "Faturamento até" à **esquerda**; "Mensalidade" à **direita**.
- A faixa **atual** continua destacada (fundo acento suave + texto no acento) — já existe, preservar.
- Última faixa (sem teto) continua exibindo "acima" no limite.

### 3. O resto da área expandida

Conferir contra a referência e corrigir o que estiver apertado: cards de passo com padding 16px 18px,
raio 12px, gap 6px; rótulo 11px/600 maiúsculo `ls 0.05em`; valor 22px (o terceiro em peso maior);
sub-linha 12px. Grade dos três passos `1fr 1fr 1fr` com gap 12px; área expandida com padding
4px 22px 24px e gap 20px entre blocos.

⚠️ **Não inventar medida.** Se o handoff especifica, usar o que ele diz. Se não especifica, manter o
que existe.

### 4. Cores traduzidas

Onde o handoff dá um hex de superfície interna, usar o equivalente do projeto
(`ecf-card` / `ecf-card-2` / `white/[0.0x]`) — nunca o hex do handoff.

## Armadilhas

⚠️ **Escala do Tailwind:** `px-4.5`, `gap-4.5`, `py-5.5` **não existem** (a escala pula de `3.5` para
`4`). O build passa e **nenhum CSS é gerado** — o espaçamento simplesmente não se aplica, sem aviso.
Já mordeu um executor desta fase. Usar a escala real ou valor arbitrário em px, e **conferir no CSS
compilado** que a regra existe. Um executor anterior fez essa conferência com script Node, porque o
shell escapava mal os colchetes do seletor.

⚠️ **Não quebrar o cadastro.** `TabelaFaixasSection` tem 4 ramos (tabela do serviço, tabela própria
da empresa, tabela do grupo, herança) e faz CRUD. `Phase138FaixasGrupoCrudTest` trava o
comportamento; `Phase139FechamentoUiContratoTest` trava a copy.

⚠️ **Preservar a frase de herança** que nomeia a empresa: "Este grupo está usando a tabela da empresa
X" + "Quem manda é a empresa do grupo que mais faturou no mês".

⚠️ **Copy sem jargão** — sete palavras banidas: snapshot, competência, reconsolidação, rollup,
âncora, origem, faixa piso. Há teste travando, e ele olha só texto visível (comentários e nomes de
prop são legítimos).

## Testes

- Não regredir `Phase137*`, `Phase138*`, `Phase139*`.
- Trava de que a tabela progressiva é **uma** subcomponente reusada, não três cópias.

**Gate:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139"` em **322 testes / 1694 asserções
/ 0 falhas**.

⚠️ `Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa...` é **flaky pré-existente** (registrado
em `.planning/todos/pending/260904-teste-flaky-aviso-mudanca-faixa.md`) — se falhar, rodar isolado e
**não consertar**.

## Restrições

- Árvore compartilhada: nunca `git add -A` / `git add .` / `git commit -a` / `git stash`. Só os
  próprios paths, `git status --porcelain` antes **sem** `--untracked-files=no`.
  Não são seus: `tests/Feature/CompanyPortfolioAccessTest.php`, `public/images/*`, os `.docx` da
  raiz, `design_handoff_fechamento/`.
- `npm run build` ao final, timeout generoso.
- PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em pt-BR. Commits atômicos.
- ⛔ Não fazer deploy. Não mexer no `.env`.
