# Itens fora do escopo, achados durante a Fase 187 — registrados, NÃO consertados

> Regra do executor: só se conserta o que o próprio trabalho causou. Achado em arquivo alheio é
> **registrado aqui** e deixado para quem é dono dele.

---

## 2026-10-17 — plano 187-08 — DUAS falhas de `CockpitDaContaTest` (Fase 179), dependentes do RELÓGIO

**Onde:** `tests/Feature/Publicador/CockpitDaContaTest.php`
**Serviço:** `app/Services/Publicador/CockpitDaContaService.php`
**Fase dona:** **179** (Visão geral / cockpit da conta) — **não é território do 187**.

| caso | asserção | sintoma medido |
|---|---|---|
| `publicacoes criadas mede as duas janelas e a serie de trinta dias` | `:966` `assertSame(12, $kpi['serie'][29], 'a última posição é HOJE')` | obteve **0** em vez de 12 |
| `evento sem data vai para o ultimo grupo nunca para o topo` | `:1318` `assertSame(['hoje', …], array_column($grupos,'chave'))` | obteve **`'ontem'`** no lugar de `'hoje'` |

### Por que NÃO é regressão deste plano — medido, não suposto

1. **Nenhum dos arquivos tocados pelo plano 187-08 tem relação com o cockpit.** `git diff
   44d0a364..HEAD --name-only | grep -i cockpit` → **vazio**. Os 12 arquivos do escopo estão listados
   no `187-08-SUMMARY.md` §13.
2. **Os últimos commits a tocar esses dois arquivos são da Fase 179:** `43fa4d4a` (2026-10-12,
   *"feat(179-03): Publicacoes recentes e a Atividade agrupada"*) e — significativo —
   **`0edc4462` (2026-10-13, `test(179): ancora o relogio no caso de agrupamento da Atividade`)**:
   alguém **já** teve de ancorar o relógio em UM caso desse arquivo.
3. **O arquivo usa `now()` 52 vezes.** As duas asserções que falham comparam o agrupamento contra
   `'hoje'` / `serie[29]`, que é a última posição de uma série que **termina hoje**.
4. As duas apareceram na **virada de 2026-10-16 para 2026-10-17** e **continuam** falhando depois da
   virada — então não é só a corrida da meia-noite: é fragilidade de FRONTEIRA DE DIA que o caso
   `0edc4462` consertou para um irmão e não para estes dois.

### O conserto certo (para quem é dono)

Ancorar o relógio nos dois casos, como `0edc4462` fez no terceiro — `Carbon::setTestNow()` numa data
fixa, em vez de semear com `now()->subDays(...)` e comparar contra `'hoje'`. ⚠️ **Não mexer no
serviço**: o serviço está certo; é o teste que depende da hora em que roda.

**Registrado em:** `187-08-SUMMARY.md` §1 (baselines) e §15.

DONO: quem mantiver a Fase 179 (ou o orquestrador, numa quick task)

⚠️ **ATUALIZAÇÃO de 2026-10-18, pelo plano 187-11:** as duas voltaram a falhar hoje, com o virar do
dia — e o histórico completo é **VERMELHAS na wave 08, VERDES nas waves 09 e 10, VERMELHAS outra vez
hoje**. ⚠️ Medido: o arquivo usa `now()` **52** vezes (**53** contando `setTestNow`/`today()`).
⚠️⚠️ **E o caminho citado no briefing do orquestrador —
`tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` — NÃO EXISTE**: é o **nono caminho
fantasma** desta milestone. O real é o que está escrito acima, **fora** de `Aprovacao/`.
⚠️⚠️ **A régua para quem medir depois: 2 falhas desta família é o "antes" herdado. Se a contagem
passar de 2, ou se falhar um teste DIFERENTE, isso É da fase e tem de ser investigado.**

---

## 2026-10-18 — plano 187-11 (fechamento) — AS SEIS DÍVIDAS DA FASE 187

### 1. O risco de **custo da composição** por linha (pergunta P2)

O pré-aquecimento da tarifa do Mercado Livre (`AquecimentoDeTarifasService` + `AquecerTarifasDaFilaJob`
na fila `high`, plano 187-10) **mitiga** o risco, **não o elimina**: cache frio continua dando o número
**certo**, só mais devagar, e **a tela nunca degrada** (zero cap por request, zero "sem tarifa", zero
"incompleto por timeout"). ⚠️ O que **não** foi medido é o comportamento com fila real e volume real —
isso só aparece quando a **Fase 194** começar a criar ofertas. ⚠️ O comando **não entra no cron** de
propósito: o cache é de 1 h, então `dailyAt()` seria decorativo e `hourly()` gastaria 24 varreduras/dia.

DONO: Fase 188 / operação (medir quando houver volume)

### 2. ⚠️⚠️ A `CascaDeModal.jsx` nasceu nesta fase e a **Fase 188 REUSA**

`resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx`. O Coverage Map atribui a "casca
comum" à Fase 188 (MOD-01..05), mas o **modal de lote** é da 187 — então a casca **nasceu aqui**.
⚠️ **Se a Fase 188 recriar, haverá DUAS CASCAS DIVERGINDO.** Está declarado dentro do arquivo e
**tem gate** (`fechamento-arquivos-e-manifestos`, em `contagens-187-11.txt`).

DONO: Fase 188

### 3. A **legenda do rodapé** não lista o anel de *falha* nem o quadrado de *regenerando*

Os dois estados **existem na tabela**, e a legenda **não os lista** — e isso é **FIEL ao protótipo**
(`design_handoff_publicador/Aprovacao.dc.html:193`-`:198`). A wave 09 **reproduziu e PERGUNTOU**; a
decisão foi **MANTER**, porque acrescentar seria **adaptar o layout**, e o protótipo ganha nos rótulos
e na ordem. ⚠️ **Item para fase futura, NÃO lacuna desta.**

DONO: fase futura (melhoria de legenda)

### 4. A divergência **D7** — o `title` do quadrado em `regenerating` diz "a conferir"

`Aprovacao.dc.html:1115`-`:1122`: o ternário de `sq()` só distingue `approved` e `com alerta`, então o
estado `regenerating` herda "a conferir", **embora a borda seja `1.5px dashed #71717A`**.
⚠️ **REPRODUZIDA DE PROPÓSITO** — o protótipo é a autoridade do rótulo. Registrada, não consertada.

DONO: protótipo / decisão de design futura

### 5. A divergência **D9** — o checkbox do cabeçalho marca linhas em `preparando`

`Aprovacao.dc.html:1226` (`ids.push`) contra `:1263` (`_nav`): `ids` **inclui** as ofertas em
`preparando`; só `_nav` as exclui. ⚠️ **REPRODUZIDA DE PROPÓSITO.** ⚠️ **A segurança NÃO depende
disto:** a trava real é `aprovarEmLote` **no servidor** (BLOQ-04), e o modal de lote lista todas as
selecionadas com ✓/× **e o motivo** — então oferta em `preparando` aparece marcada e **sai com ×**.

DONO: protótipo / decisão de design futura

### 6. A dívida **DIV-01 da Fase 186** segue ABERTA, e **não é desta fase**

Três das quatro estruturas não preenchidas declaram a fase dona **só fora do docblock**. ⚠️ **Não é
território da Fase 187** — é do orquestrador ou da **Fase 191**.

DONO: orquestrador ou Fase 191

---

## 2026-10-18 — plano 187-11 — ⚠️ O QUE **NÃO** É DÍVIDA, e está aqui para não ser lido como dívida

| item | por que não é dívida |
|---|---|
| a **fila nascer vazia** | decisão **P3** do usuário: é honesto e não é defeito. Quem cria oferta é a **Fase 194** |
| o **runtime do timer dos toasts** não ser provado | **limitação do ambiente** (não há navegador), declarada no CRIT-12 como RESSALVA. A função pura **está** provada |
| `tests/js/estrutura-grade-glide.test.js` falhando (Fase 87) | falha **pré-existente e intencional**, aceita por `REF_JS_FALHAS=1` desde a Fase 186 |
| `VinculoDuravelDaOriginalTest::terr_11` (Fase 186) falhando | gate de território **por RANGE** acusando a **sessão paralela** (`feat(196)`). O arquivo acusado é **proibido** a esta fase; reapontar o gate seria fazê-lo parar de provar algo |
