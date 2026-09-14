---
quick_id: 260914-ly9
slug: a-ancora-volta-a-vigiar-a-nota-oficial
date: 2026-09-14
type: quick
---

# A âncora volta a vigiar a nota que paga bônus

**Leia antes de tudo:** `.planning/debug/motor-desempenho-11-falhas.md` (a investigação completa) e
`.planning/learnings/desempenho-bonificacao.md` (**obrigatório** pelo `CLAUDE.md` antes de tocar em
nota, ranking, carteira, snapshot mensal ou bonificação).

---

## O que a investigação estabeleceu

11 testes de `Phase74`/`Phase110` falham. **Todos são teste desatualizado — nenhum é defeito de
cálculo, e nenhuma pessoa real foi afetada.**

O elo comum é o hotfix **`a413e823` (24/07/2026)**: o fallback local da margem virou `null`
("nativo-ou-null"), enquanto o **faturamento manteve** o fallback. As fixtures semeiam
`adman_metrics` local e fakeiam a Adman com 404 — por isso `var_faturamento_pct` passa e
`var_margem_pct` vem `null` **na mesma asserção do mesmo teste**.

Prova de que o teste é que ficou para trás: o `setUp()` (commit `c270a714`, **20/07**) diz
textualmente que o fake "sem `.diff`" força o fallback local determinístico. O hotfix revogou essa
premissa **4 dias depois**.

---

## ⚠️ O achado que dá nome a esta tarefa

O golden **4,42** do `fixture carlos` corresponde a **`nota_final_legado`** — metadado de auditoria.
Desde **05/08** a nota OFICIAL vem de `computeNotaFinalPorIndicador()` sobre `empresasScore`, e ali
a margem é medida em **pontos percentuais** (`margem_var_pp`), não em variação relativa.

Com a Adman respondendo os números que a fixture modela:

| | |
|---|---|
| `nota_final_legado` | **4,42** ← é o que o teste vigia hoje |
| **`nota_final` (oficial)** | **3,72** → faixa "sem_bonus" |

Os dois estão certos: **+4,5% relativo = +0,9 p.p.**, e a mesma régua dá 5 pontos numa grandeza e 3
na outra. A mudança é decisão travada (EMPS-03 / D2 da v21.0, `CompanyScoreService.php:555`) — **não
é bug**.

⛔ **A armadilha desta tarefa:** recalibrar 4,42 → 3,72 e seguir em frente **silencia o alarme em vez
de consertá-lo**. A âncora bloqueante do motor de bonificação está vigiando o número errado há mais
de um mês; o objetivo aqui é devolver a vigilância, não pintar o teste de verde.

---

## T1 — O stub da Adman (a base de tudo)

Substituir o `Http::fake(404)` por stub de `*/accounts/*/metrics*` devolvendo
`percentageMargin {value, diff, prev}` derivado da própria fixture.

⚠️ **O `Http::fake()` do `setUp()` tem precedência sobre um segundo `Http::fake()`.** Só
`Http::swap(new Factory($app['events']))` reseta. Descoberto na investigação — sem isso o stub novo
é ignorado em silêncio e você vai perseguir um fantasma.

---

## T2 — A asserção nova: a nota OFICIAL

**Este é o entregável principal.** No `fixture carlos`, além do golden de `nota_final_legado`, travar
`nota_final` (a oficial, a que define faixa de bônus).

Com o stub do T1, o valor esperado é **3,72**, faixa `sem_bonus` — conferido na investigação.

⚠️ Deixe **explícito no teste**, em comentário, que são **duas grandezas diferentes** e por quê:
`nota_final_legado` usa variação relativa da margem, `nota_final` usa pontos percentuais. Quem ler
daqui a seis meses precisa entender antes de "corrigir" uma das duas.

---

## T3 — Recalibrar os goldens, em pontos percentuais

⚠️ **Não converta "na mão" de % relativa para p.p.** — derive da fixture.

| teste | o que a investigação apurou |
|---|---|
| `nota 5 exata retorna maximo` | volta a 5,00 **só com o stub** (diff_pp +5,0 p.p. → 5 pts) |
| `2 meses consecutivos intermediario promove para maximo` | ⚠️ **não volta com golden novo.** Em p.p. dá **4,00**, não 4,67 — isso muda a faixa e **derruba a premissa da promoção** (DESEMP-08). **Precisa de fixture nova**, montada para exercer a promoção de verdade |
| `var margem nao inverte sinal...` | roda em **mês em curso**, onde `diff_pct` é `null` **por design** (ramo `adman_janela_baseline`, `3e6d0eab`, 10/08). Não volta só com stub: migrar para `var_margem_pp` **ou** mudar para mês fechado |

---

## T4 — Grupo C e Grupo D, juntos

**Grupo C** (4 de `ConsolidarMesDesempenhoCommandTest` + 2 de `Phase110`): o gate FIXMARG-03 recusa
persistir com `margem_amostra = {n_real: 0, n_elegivel: 1, cobertura: 0}`. **O gate está certo** — é
a armadilha de fixture que o `desempenho-bonificacao.md` §10.1 já descreve. Aplicar o mesmo stub nos
helpers `criarEmpresaComMargemReal` e `preencherDadosDaCarteira`.

⛔ **Não afrouxar `MARGEM_COBERTURA_MINIMA_CONGELAMENTO`.** O gate é a proteção contra "a Adman
mudou" virar bônus errado.

**Grupo D — latente, e morde depois.** `notasLegado()` usa `->principal()` (filtra por
`template_id`) e o `NpsSurveyFactory` cria `template_id => null`: nunca casa, e `nps_medio` vem
`null` para todo mundo. Sonda da investigação, 3 users com NPS 5/4/3 → **os três com
`nota_final = 1.3333`**.

⚠️ **Conserte D junto com C.** Senão o C fica verde e `comando popular ranking pos por mes
referencia` volta a falhar por **ranks empatados** — por outro motivo, e você vai achar que quebrou
algo novo.

É defeito de **factory**, não de produção: `NpsDispararMensal`, `NpsController` e
`NpsGrupoReplicacaoService` **todos** preenchem `template_id`. Passe o template principal no
`NpsSurvey::factory()` dos dois helpers de NPS legado.

---

## Travas

⛔ **Não afrouxe nenhuma asserção para passar.** Se um teste não fechar com fixture honesta, **pare e
reporte** — pode ser o 12º defeito, escondido atrás dos 11.

⛔ **Não toque em código de produção.** Esta tarefa é `tests/`. Se concluir que a única saída é mexer
em `app/`, **pare e reporte antes**.

⚠️ **A versão da cacheKey de desempenho é hardcoded em vários testes** — se precisar mexer nela,
lembre que quebra um lote de uma vez (Phase96/V16/V18).

⚠️ **`ConsolidarMesDesempenho.php:121` faz `ini_set('memory_limit','512M')` em runtime** e rebaixa o
teto do processo PHPUnit. **Rode por filtro**, nunca a suíte inteira num processo só.

⚠️ Árvore compartilhada, outra sessão ativa: nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. `git status --porcelain tests/` antes de cada commit.
`tests/Feature/CompanyPortfolioAccessTest.php` **não é seu**.

⚠️ **Não use `gsd-sdk query state.advance-plan`.** ⛔ Sem deploy, sem `.env`.

PHP: `C:\xampp\php\php.exe`. Comentários e commits em **pt-BR**.

**Alvo:** `--filter="Phase74|Phase110"` de **11 failed / 28 passed** para **0 failed**, e o gate do
fechamento (`Phase122|...|Quick260911`) seguindo em **736 passando / 0 falhas**.
