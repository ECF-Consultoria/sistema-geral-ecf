---
quick_id: 260914-ly9
slug: a-ancora-volta-a-vigiar-a-nota-oficial
date: 2026-09-14
type: quick
status: completo
---

# A âncora voltou a vigiar a nota que paga bônus

O entregável não foi "11 testes verdes" — foi **devolver a vigilância ao número certo**.
A âncora bloqueante do motor de bonificação (`fixture carlos`) estava conferindo
`nota_final_legado` (metadado de auditoria) em vez de `nota_final` (a que define faixa
de bônus) desde **2026-08-05**, e não acusava porque morria antes, numa asserção de
margem. Recalibrar o golden e seguir em frente teria silenciado o alarme.

**Resultado:**

| gate | antes | depois |
|---|---|---|
| `--filter="Phase74\|Phase110"` | 11 failed / 28 passed (exit 1) | **39 passed, 0 falhas (exit 0)**, 170 asserções |
| gate do fechamento (`Phase122\|Phase136..143\|Quick260909..11`) | 736 passed / 0 falhas | **736 passed, 0 falhas (exit 0)**, 3337 asserções |

Exit code capturado **antes** de qualquer pipe, nas duas medições.

Nenhuma linha de `app/` foi tocada. Nenhuma asserção foi afrouxada.
`MARGEM_COBERTURA_MINIMA_CONGELAMENTO` segue intocada.

## Commits

| commit | o quê |
|---|---|
| `dbdb5ed2` | `test(260914-ly9)`: âncora do bônus volta a vigiar a nota oficial (3,72), não o legado — T1 + T2 + T3 |
| `3b047ab3` | `test(260914-ly9)`: fixture do consolidar-mes volta a produzir margem e NPS legado — T4 (grupos C e D juntos) |

Arquivos: `tests/Concerns/FakeAdmanMargemDaFixture.php` (novo),
`tests/Feature/Phase74/DesempenhoScoreServiceTest.php`,
`tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php`,
`tests/Feature/Phase110/ConsolidarMesMargemResilienteTest.php`.

## T1 — O stub da Adman, derivado da fixture

Novo trait `Tests\Concerns\FakeAdmanMargemDaFixture`. Responde
`/{marketplace}/accounts/{custId}/metrics` com `percentageMargin {value, diff, prev}`
**calculado a partir das mesmas linhas de `adman_metrics` que a fixture semeia** —
nunca de constantes escritas à mão:

- `value` = `SUM(contribution_margin) / SUM(revenue) × 100` na janela pedida,
  **sobre os dias em que a margem existe** (numerador e denominador das MESMAS linhas);
- `prev` = o mesmo cálculo na janela de mesmo tamanho imediatamente anterior — é o que
  a Adman devolve de verdade (medido ao vivo em 2026-08-10, caso LUCCMAX), e em mês
  fechado coincide por construção com o `baseline_start..baseline_end` do
  `MetricPeriodResolver`, que é onde as fixtures semeiam o "antes";
- `diff` = `(value − prev) / prev × 100`.

O payload **não** traz `billing` nem `liquidMargin` de propósito: o faturamento segue
vindo do `calculated_fallback` local (`/performance/*` continua 404), então **todos os
goldens de `var_faturamento_pct` ficaram intocados**.

`Http::swap(new Factory($app['events']))` abre o helper — sem isso o `Http::fake()`
anterior tem precedência e o stub é ignorado em silêncio (armadilha já apurada pela
investigação, confirmada aqui).

**Por que o 404 tinha que sair:** o hotfix `a413e823` (2026-07-24) revogou o
`calculated_fallback` local da margem, e `AdmanMetricDiffService::fallbackMargemPct()`
virou **código morto** — o próprio `CompanyScoreService.php:58` documenta isso. Fakear
404 deixou de exercitar o fallback e passou a exercitar apenas a AUSÊNCIA de margem.
Um teste de margem que não fala com a Adman não testa nada que exista.

## T2 — A asserção nova: a nota OFICIAL (entregável principal)

`test_fixture_carlos_retorna_nota_4_42_basico` →
`test_fixture_carlos_nota_oficial_3_72_sem_bonus_e_legado_4_42`.

Medido com o stub (valores conferidos por sonda antes de escrever a asserção):

| campo | valor |
|---|---|
| `nota_final` (**oficial, define bônus**) | **3,72** → faixa **`sem_bonus`** |
| `nota_final_legado` (auditoria) | 4,42 |
| `componentes.var_margem_pct` (relativa) | +4,50% |
| `componentes.var_margem_pp` (p.p.) | +0,90 p.p. |
| `pontos_componentes` | nps 4,1667 · faturamento 4 · margem **3** |

O teste agora trava **os dois** números, com comentário longo explicando que são
grandezas diferentes: o legado aplica a régua uma vez sobre a % agregada e pontua a
margem pela variação RELATIVA (+4,50% → 5 pts); a oficial aplica a régua loja a loja e
pontua em PONTOS PERCENTUAIS (+0,90 p.p. → 3 pts). Também travei
`assertNotEqualsWithDelta` entre as duas — se um dia coincidirem nesta fixture, é sinal
de que alguém uniformizou o que deve divergir.

Bônus de clareza que a sonda revelou e ficou documentado no teste: o NPS também difere
entre os caminhos (legado promedia as 4 respostas → 4,25; o oficial promedia **por
loja** → 4,1667, porque a empresa A recebeu duas respostas).

## T3 — Goldens em pontos percentuais

| teste | o que foi feito |
|---|---|
| `nota 5 exata retorna maximo` | voltou a 5,00 **só com o stub** (+5,00 p.p. → 5 pts). Nenhuma mudança de fixture. |
| `2 meses consecutivos ... promove para maximo` | **fixture refeita**, não golden recalibrado. |
| `var margem nao inverte sinal ...` | **migrado para `var_margem_pp`**. |

### A promoção DESEMP-08 precisou de fixture nova

Como a investigação previu, a fixture antiga (faturamento +4,75%, margem 2200/10475)
dá **4,00** em p.p., não 4,67 — e 4,00 é faixa `basico`, que **não exerce a promoção**.
Recalibrar o golden para 4,00 teria trocado o assunto do teste: ele deixaria de provar
DESEMP-08 e passaria a provar "básico não promove".

Fixture nova, derivada (não convertida à mão):

- NPS 5,00 por loja → 5 pts
- faturamento +6,00% (10.600/dia vs 10.000/dia), acima de 5% → 5 pts
- margem 22,00% vs 20,00% = +2,00 p.p., faixa (1;4] → 4 pts
- nota = (5 + 5 + 4) / 3 = 4,6667 → 4,67 → `intermediario` [4,50;4,99] → promove

O 4,67 sobrevive por derivação da fixture nova. Acrescentei asserções dos três pontos
por indicador antes da nota — se um bucket mudar, a mensagem diz **qual**.

### O teste "Tomelin" mudou de grandeza — e isso precisa ser lido

O teste roda em **mês em curso**, onde `var_margem_pct` (relativa) é `null`
**por design** desde `3e6d0eab` (2026-08-10): o ramo `adman_janela_baseline` compara a
margem % da janela atual contra a da janela baseline, ambas nativas, e expõe o resultado
em p.p. Migrei a asserção para `var_margem_pp`.

**O invariante do bug continua vigiado, e a asserção continua discriminando:** a janela
atual vale 15,00% (margem 150×5 sobre faturamento 1.000×5 dos MESMOS dias) contra 10,00%
da baseline → **+5,00 p.p., positivo**. Se os dias finais sem margem voltassem a
contaminar o denominador (margem de 5 dias dividida pelo faturamento de 9), a janela
atual valeria 8,33% e o resultado seria **−1,67 p.p.**, negativo. A asserção de sinal
separa exatamente esses dois mundos.

**O que esse teste NÃO prova mais:** o guard local (`somasComGuards` aplicado à margem)
é código morto desde 24/07. Quem faz o recorte like-with-like hoje é a Adman, do lado
dela. O teste virou uma regressão de **encanamento + sinal**, não do guard. Está escrito
assim, em pt-BR, dentro do próprio teste.

## T4 — Grupos C e D, obrigatoriamente juntos

**Grupo C (6 testes).** As duas suítes de `consolidar-mes` fakeavam a Adman com 404
enquanto suas empresas **têm** `adman_account_id` (desde a unificação de 2026-07-22 —
o comentário antigo dizendo "estas empresas NÃO têm custId" estava desatualizado).
Resultado: `margem_amostra.legado.cobertura` = 0,0 e o gate **FIXMARG-03 recusava
congelar**, deixando 6 testes com "snapshot ausente".

**O gate estava certo e não foi tocado.** É a proteção contra "a Adman mudou" virar
bônus errado (`.planning/learnings/desempenho-bonificacao.md` §10.1 descreve
exatamente esta armadilha de fixture). O stub do T1 resolve devolvendo margem apenas
para empresa com custId **e** linhas em `adman_metrics` — o que preserva a assimetria
boa/degradada que a Phase110 mede: `criarEmpresaSemMargem` continua sem margem
(cobertura 1/4 = 0,25, abaixo de 0,7 → degradada), `criarEmpresaComMargemReal` passa a
ter (1/1 = 1,0 → saudável).

**Grupo D (latente).** `notasLegado()` filtra por `->principal()` desde `299cf63e`
(2026-07-13) e `NpsSurveyFactory` cria `template_id => null`, que nunca casa: o survey
ficava invisível, `nps_medio` vinha `null` para todos e os 3 usuários do teste de
ranking empatavam. É defeito de **fixture**, não de produção — `NpsDispararMensal`,
`NpsController` e `NpsGrupoReplicacaoService` preenchem `template_id` em todos os
caminhos. Os dois helpers passam agora `NpsTemplate::principalId()`, com asserção
explícita de que o modelo principal existe (falha alto se a migration parar de semear).

Consertados juntos como o plano exigia: o teste de ranking passou de primeira, sem a
segunda rodada vermelha por ranks empatados.

## Verificação de colateral (medida, não presumida)

Como a árvore é compartilhada e `git stash` está proibido, medi a baseline restaurando
os arquivos do commit anterior (`git checkout 2edaeb51 -- <meus 3 arquivos>`), rodando,
e restaurando de volta com `git checkout HEAD -- ...`. Só arquivos meus, todos já
commitados.

`--filter="V16|V18|Phase96|Phase119|Phase120|Phase121|Desempenho"`:

- antes: **43 failed / 521 passed**
- depois: **34 failed / 530 passed**
- diff dos nomes de teste que falham: **zero regressão nova**, 9 resolvidos.

`--filter="Phase116"` (única outra suíte que dá `require_once` no arquivo da Phase74):
**1 failed / 78 passed antes e depois** — idêntico, pré-existente
(`desfazer remove linhas reconsolida e devolve score anterior`), não é meu.

## Decisões que tomei sozinho

1. **O stub virou trait compartilhado** (`tests/Concerns/FakeAdmanMargemDaFixture.php`)
   em vez de três cópias nos `setUp()`. Três cópias divergiriam no próximo ajuste, e a
   Phase110 já reusa código da Phase74 por `require_once`.
2. **Regra de derivação do `value`: like-with-like** (numerador e denominador dos mesmos
   dias). É a única regra uniforme que (a) não altera nenhuma fixture densa, (b) é a
   leitura fiel de `liquidMargin / netBilling` do período, e (c) faz o teste Tomelin
   discriminar o bug. A alternativa (margem dos dias que têm, dividida pelo faturamento
   de todos) reproduziria o próprio bug dentro do stub.
3. **`prev` = janela de mesmo tamanho imediatamente anterior**, e não o baseline do
   resolver. É o que a Adman real devolve (§0.00b do learnings); em mês fechado os dois
   coincidem, então nada se perde, e em mês em curso o stub não finge um `prev` que a
   Adman não daria.
4. **O stub não devolve `billing` nem `liquidMargin`.** Devolver `billing` faria o
   faturamento passar a vir do `.diff` nativo e mexeria em goldens de
   `var_faturamento_pct` que não são assunto desta tarefa.
5. **Renomeei o teste âncora.** O nome antigo (`..._retorna_nota_4_42_basico`) afirmava
   exatamente o que deixou de ser verdade; nome mentiroso sobrevive a qualquer comentário.
6. **Fixture nova na promoção, em vez de golden novo** (justificado acima).
7. **Removi os `use Illuminate\Support\Facades\Http;` que ficaram órfãos** nos três
   arquivos.

## O que decidi NÃO fazer

- **Não toquei em `app/`.** Nem para "consertar" o buraco entre 3,99 e 4,00 (§0.043 do
  learnings), nem para remover `fallbackMargemPct()`/`coberturaMargem()`, que são código
  morto confirmado. Remoção de código morto no motor de bônus é mudança de produção e
  pede decisão à parte.
- **Não afrouxei `MARGEM_COBERTURA_MINIMA_CONGELAMENTO`** nem nenhuma asserção.
- **Não reescrevi o teste Tomelin para provar o guard local.** Ele é inalcançável hoje;
  fabricar um caminho até ele seria testar código que produção não executa. Documentei
  a perda de cobertura dentro do teste em vez de simulá-la.
- **Não mexi na versão da cacheKey** (`desempenho.compute.vNN`) — não foi necessário, e
  bumpá-la quebraria strings hardcoded em Phase96/V16/V18 de uma vez.
- **Não corri a suíte inteira num processo só** (`ConsolidarMesDesempenho.php:121`
  rebaixa o `memory_limit` do processo PHPUnit em runtime). Tudo por filtro.
- **Não usei `gsd-sdk query state.advance-plan`**, não houve deploy, `.env` intocado.
- **Não toquei em `tests/Feature/CompanyPortfolioAccessTest.php`** (não é meu; segue
  untracked). `git status --porcelain tests/` conferido antes de cada commit; nenhum
  `git add -A`, `git add .`, `git commit -a` ou `git stash`.

## Para quem vier depois

**As 34 falhas restantes em `V16|V18|Phase96|Phase119|Phase120|Phase121|Desempenho` não
são regressão desta tarefa** — são a mesma classe já listada no §0.02 do learnings
(suítes que cobram a variação **relativa** da margem, vinda do `calculated_fallback`
revogado em 24/07), mais as que o quick `260914-gmp` desbloqueou em 2026-09-14. Várias
delas provavelmente caem com o mesmo trait aplicado ao `setUp()`; não estavam no escopo
desta tarefa e não foram tocadas.

**A lição que vale além deste quick:** quando a nota mudou de método em 2026-08-05,
`nota_final` e `nota_final_legado` passaram a ser números diferentes, e um teste que
verifica "a nota" sem dizer **qual** deixa de proteger o pagamento sem ficar vermelho.
Vale conferir se há outros testes no módulo nessa situação.
