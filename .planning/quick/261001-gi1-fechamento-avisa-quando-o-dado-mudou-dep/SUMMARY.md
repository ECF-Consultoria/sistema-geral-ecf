---
quick_id: 261001-gi1
slug: fechamento-avisa-quando-o-dado-mudou-depois
date: 2026-10-01
status: complete
commits:
  - 97a56cf7
  - 60cdbdbb
---

# O fechamento avisa quando foi fechado com dado que mudou depois

Dois commits, na ordem do plano. **Nada foi para produção** — nenhum comando
rodou contra o VPS, nenhuma chave foi virada. O T3 ficou **deferido de
propósito** (registrado em `deferred-items.md`).

| commit | o que entrou |
|---|---|
| `97a56cf7` | T1 — `fechamento:verificar-consolidacao` passa a conferir faturamento |
| `60cdbdbb` | T2 — aviso discreto na tela, em mês fechado |

---

## A decisão que estrutura o quick inteiro: o que é informação e o que é urgência

A cobrança é o **valor da faixa** — `CobrancaCalculator::mensalidade()` com
classificação devolve `$classificacao['valor']`, e nada ali é proporcional ao
faturamento. Conferido no código antes de decidir, não presumido. Daí:

| situação | onde aparece | exit code |
|---|---|---|
| faturamento diferente, **mesma faixa** | `avisos.faturamento` | **0** — informação |
| faturamento diferente, **faixa mudaria** | `inconsistencias[FAIXA_MUDARIA]` | **1** — dinheiro errado |
| linha sem faixa (valor fixo / sem tabela) | `avisos.faturamento`, `faixa_mudaria: null` | 0 |
| não deu para conferir | `avisos.faturamento.nao_comparaveis` | 0 |

A chave `avisos` é **nova e fica FORA de `inconsistencias`** de propósito:
`ok`/exit code continua significando o que significava antes (estrutura),
mais a mudança de faixa. Se divergência de centavos derrubasse o exit code, o
comando passaria a falhar todo dia — a Adman revisa dias passados por design
(é para isso que existe `adman:reler-dias`) — e um verificador que falha
sempre deixa de ser consultado.

---

## COMO EVITEI O FALSO POSITIVO DIÁRIO (a pergunta do plano)

**Decisão: o aviso sai pela DIVERGÊNCIA DE VALOR, nunca pela existência de
escrita posterior.** A hora da escrita entra no aviso só como *contexto*,
depois que já se sabe que algum valor mudou.

O porquê, com o número na mão: `adman:reler-dias` (19:00) reescreve os últimos
5 dias **todo dia**, de propósito — ~420 linhas de `adman_metrics` por noite —
e `adman:sync` (11:00) grava D-1. Se o critério fosse `max(updated_at) >
gerado_em`, o aviso apareceria em **todo** mês fechado, **todo** dia, para
sempre. Aviso que aparece sempre ensina a ignorar o aviso; depois de uma semana
ninguém leria justamente o dia em que o número está errado. É o mesmo
raciocínio que deixou o `ProcedenciaFaturamentoNota` calado no estado normal
(quick 260930-njd) e que mantém o `nao_participam` como lista discreta.

Na prática:

- `dado_mudou_depois` + `dado_atualizado_em` **são calculados e saem no JSON**
  (`max(updated_at)`/`max(synced_at)` de `adman_metrics` e `shopee_metrics` na
  janela do mês) — servem para dizer **QUANDO**;
- mas **não** são o gatilho. A prop da tela só existe quando
  `total_divergentes > 0`.

Com teste dos dois lados: `escrita_posterior_que_nao_muda_valor_nao_alarma`
(releitura das 19h reescreve o dia com o MESMO número, `dado_mudou_depois` é
`true` e `divergentes` é `[]`, exit 0) e
`escrita_posterior_sem_mudanca_de_valor_nao_mostra_aviso` (a tela não mostra
nada).

---

## T1 — Como ficou o comando

Entrou `App\Services\Fechamento\FechamentoConferenciaFaturamentoService`, e o
comando passou a chamá-lo. **O faturamento "de agora" vem do MESMO
`FechamentoRollupService::porEmpresa()`** que a consolidação usa, com as
MESMAS flags lidas do mesmo jeito (`FechamentoRegraTabela::ativa()` e
`$mesFechado && FechamentoFonteFaturamento::ativa()`). Nenhuma segunda fórmula
de faturamento — duas fórmulas divergem com o tempo e aí a verificação passa a
mentir.

### Saída literal (cenário com os números do incidente)

```
[VerificarConsolidacao] Competência 2026-09 (fechada) — 3 empresa(s) elegível(is), 0 grupo(s).
[VerificarConsolidacao] Fechamento gravado em 01/10/2026 10:23 — 3 de 3 linha(s) conferida(s).
[VerificarConsolidacao] Dado mais recente deste mês gravado em 01/10/2026 10:42 — DEPOIS do fechamento.
  MUDA DE FAIXA #2 (MPozenato): gravado 40.000,00 | atual 61.000,00 | diferença 21.000,00 | origem da diferença: shopee
  difere #1 (Gabs Folheados): gravado 6.378,91 | atual 40.154,54 | diferença 33.775,63 | origem da diferença: shopee
[VerificarConsolidacao] Faturamento: 2 empresa(s) com valor diferente do gravado; 1 mudaria(m) de faixa.
+---------------+------------+
| Classe        | Quantidade |
+---------------+------------+
| FAIXA_MUDARIA | 1          |
+---------------+------------+
[VerificarConsolidacao] AVISO: esta tabela é CONVENIÊNCIA HUMANA. A conferência OFICIAL é o EXIT CODE (0 = sem inconsistências) ou a saída --json — nunca este texto.
```

exit **1**. Quem muda de dinheiro aparece **primeiro**; depois, maior diferença
em módulo. O texto segue sendo conveniência humana — nenhum teste depende dele.

### A faixa é conferida pelos limites CONGELADOS na linha

`faixa_limite_inferior`/`faixa_limite_superior` da própria linha, com a mesma
régua de intervalo de `FechamentoFaixaResolver::classificar()` (a faixa cobre
de "maior que o inferior" até "menor ou igual ao superior", o piso da primeira
inclui o zero, e `superior = null` é a máxima). **Não** reclassifiquei pela
tabela de hoje: a tabela pode ter sido editada depois do fechamento, e aí a
resposta seria sobre outra pergunta. `FechamentoFaixaResolver` ficou intocado,
como manda a trava.

Linha sem faixa (valor fixo, Mentoria) devolve `faixa_mudaria: null` — "não
sei" nunca pode virar "mudaria", e por isso não entra na contagem de urgência.

### A causa da diferença, separada (o remédio é diferente)

Comparo **lado a lado**, não só o total: `faturamento_ml` contra
`faturamento_ml` e `faturamento_shopee` contra `faturamento_shopee`. O campo
`causa` sai como `shopee`, `ml` ou `ambas`.

### `nao_comparaveis` — e por que ele é a parte mais importante do T1

Com a chave `fechamento_faturamento_da_api_ativo` **ligada**, o lado ML de uma
linha pode ter sido gravado com o total do `/performance` da Adman. Recalcular
sem chamar a Adman devolve a soma diária — e a API é **sistematicamente ~3,5%
maior** (medição de 260930-njd). Comparar os dois acusaria divergência em
**toda** empresa, todo dia: o falso positivo diário de novo, agora pela porta
dos fundos.

Então: quando a régua gravada e a régua recalculada diferem, o **lado ML sai
como NÃO CONFERIDO** (com o motivo), e o **lado Shopee continua valendo** — foi
a Shopee que causou o incidente, e perder a conferência dela para proteger o ML
seria trocar o certo pelo duvidoso. O total "de agora" nesse caso é recomposto
com o ML **gravado** mais o Shopee **de hoje**; nunca somando réguas
diferentes. `ml_conferido: false` viaja na linha.

Hoje a chave está **desligada**, então todas as linhas são `soma_diaria` e a
conferência é exata sem HTTP nenhum. `null` em `faturamento_fonte` (linhas
anteriores à coluna) conta como "não veio da API" — a coluna nasceu junto com
o caminho da API, então linha sem fonte é de quando esse caminho não existia.

### `--com-api` é a única porta de HTTP, e é opt-in

Sem ela: **zero** chamada (o rollup roda com `apiSomenteDoCache: true`). Com
ela, o lado ML é conferido ao vivo — mais exato, mais lento, sujeito ao limite
de 10 rpm. A docblock do comando (que prometia "nenhuma chamada HTTP") foi
corrigida junto; prometer o que não se cumpre mais é pior que a chamada.

### O comando não conserta nada — provado por reconsulta

`o_comando_nao_escreve_nenhuma_linha_de_fechamento` compara **a linha inteira**
de `fechamento_snapshots` (todas as colunas, via `DB::table()->get()`) antes e
depois da execução, mais a contagem das duas tabelas de snapshot e o valor da
métrica. Prova por banco, nunca por stdout — a disciplina de
`.planning/learnings/desempenho-bonificacao.md` §4.

---

## T2 — Como o aviso entrou na tela

**Entrou.** Prop de **página** `dado_mudou_depois_do_fechamento`, `null` no
estado normal; componente `DadoMudouDepoisAviso` no `Financeiro.jsx`, logo
abaixo do `NaoParticipamAviso`.

⚠️ **Nenhuma chave nova nos cinco literais de linha** — a trava do plano. O
aviso é da página, calculado em
`AdminController::fechamentoAvisoDadoMudouDepois()`, e os cinco montadores de
linha ficaram intocados.

| decisão | por quê |
|---|---|
| só em competência **fechada** | mês aberto recalcula ao vivo a cada carregamento — não tem como estar velho, e avisar ali seria mentira (com teste) |
| `null` quando bate | sem alarme no estado normal; o guard `if (!info) return null;` tem teste próprio |
| **zero HTTP** no request | a conferência roda em modo cache-only; o que não dá para conferir fica fora do aviso em vez de virar alarme falso. `Http::assertNotSent('/performance/')` com teste |
| 3 exemplos, não a lista | o suficiente para saber onde olhar sem a tela virar relatório |
| borda âmbar só quando muda mensalidade | divergência que não muda dinheiro é cinza e discreta |

Copy (sem jargão, com teste que proíbe "snapshot", "rollup", "sync", "cache",
"endpoint", "fallback", "consolida" e "api" no bloco do componente):

> **Este mês foi fechado com número que mudou depois**
> O fechamento foi gravado em 01/10/2026 10:23 e o faturamento de 2 empresas
> está diferente agora. O número mais recente deste mês chegou às 10:42. Em uma
> delas a mensalidade a cobrar sairia diferente — vale refazer o fechamento.
> Gabs Folheados: fechou com R$ 6.379, hoje está R$ 40.155

Quando nenhuma faixa muda, a última frase vira "A mensalidade a cobrar continua
a mesma." — o aviso ainda aparece (o número está errado), mas sem urgência.

`npm run build` rodado, exit **0**.

---

## T3 — deferido (com desenho pronto)

Não agendei a consolidação. O motivo é o mesmo que faz o verificador não
consertar nada: **fechar competência é decisão de quem responde pela
cobrança**, e um agendamento que rodasse num dia de Adman atrasada gravaria
número velho sem ninguém por perto. O essencial o T1 e o T2 já entregam:
ninguém mais descobre pela própria empresa.

O desenho (horário, por que 13:30, a armadilha do "primeiro dia útil" que não
existe pronto no agendador, e a colisão com o limite de 10 rpm) está em
`deferred-items.md` desta pasta, pronto para o usuário decidir.

---

## Os dois gates, antes e depois

| gate | antes de editar | ao final | |
|---|---|---|---|
| Phase137/139/140/141/142/143 + Quick260915/260916/260922/260930/261001 | **730 passed** (3226 assertions), exit **0** | **755 passed** (3329 assertions), exit **0** | +25 testes novos, zero regressão |
| Phase74 + Phase110 | **39 passed** (170 assertions), exit **0** | **39 passed** (170 assertions), exit **0** | inalterado |

Exit code capturado ANTES de qualquer pipe nas quatro rodadas.

**Falha pré-existente registrada:** `AdminFechamentoControllerTest` dá
**6 failed, 10 passed** — e dá o **mesmo** resultado com o `AdminController.php`
restaurado ao commit anterior (medido nos dois estados, não presumido). Está
fora dos dois gates; detalhe em `deferred-items.md`.

---

## Desvios do plano

1. **A conferência virou um SERVIÇO, não lógica dentro do comando.** O plano
   pedia a conferência no comando; ela está em
   `FechamentoConferenciaFaturamentoService` porque o T2 (a tela) precisa do
   **mesmo** veredito. Duplicar a comparação entre comando e controller criaria
   exatamente o problema que o plano proíbe uma linha abaixo (duas fórmulas que
   divergem com o tempo) — só que na camada acima.

2. **`avisos` é chave nova no JSON, fora de `inconsistencias`.** O plano não
   diz onde a divergência-informação deveria sair. Pôr tudo em
   `inconsistencias` derrubaria o exit code por centavos, todo dia.

3. **Existe `nao_comparaveis`, que o plano não previa.** Sem essa classe, a
   conferência mentiria ~3,5% em toda empresa no dia em que a chave da API for
   ligada (e seria mentira *convincente*, porque o número divergiria mesmo).

4. **`--com-api` é opt-in.** O plano não falava de chamada à Adman na
   verificação; a alternativa (chamar sempre) tornaria o verificador um
   consumidor de rate-limit, e a alternativa oposta (nunca chamar) deixaria o
   lado ML sem conferência possível depois que a chave ligar.

5. **O aviso da tela mostra 3 exemplos.** O plano pedia uma frase. Sem o nome
   de pelo menos uma empresa, quem lê sabe que algo mudou e não sabe onde
   olhar — e o incidente de hoje foi descoberto exatamente por alguém olhando
   empresa por empresa.

---

## Armadilhas encontradas (valem para o próximo)

**`json_decode` devolve `int` para `40000.00`.** Cinco asserções do teste
nasceram vermelhas com "Failed asserting that 18000 is identical to 18000.0" —
o valor viaja correto, mas `assertSame` compara tipo. Em teste que lê a saída
`--json` de comando, use `assertEqualsWithDelta` para número.

**`whereBetween` em `reference_date` com string curta exclui o último dia do
mês.** Herdada de 260930-njd e ainda viva: a coluna é persistida como
datetime e o SQLite compara como TEXTO, então `2026-09-30` fica FORA de
`[2026-09-01, 2026-09-30 00:00:00]`. Por isso os testes criam métricas pelo
**model** (`ShopeeMetric::create`), nunca por `DB::table()` com data crua — e o
novo `dadoMaisRecenteDoMes()` usa `whereDate`, não `whereBetween`.

---

## Arquivos

**Código**
- `app/Services/Fechamento/FechamentoConferenciaFaturamentoService.php` — **novo**
- `app/Console/Commands/VerificarConsolidacaoFechamento.php` — classe
  `FAIXA_MUDARIA`, chave `avisos`, `--com-api`, saída humana da conferência
- `app/Http/Controllers/AdminController.php` — serviço no construtor,
  `fechamentoAvisoDadoMudouDepois()`, prop `dado_mudou_depois_do_fechamento`
- `resources/js/Pages/Admin/Financeiro.jsx` — `DadoMudouDepoisAviso`
  (`npm run build` rodado, exit 0)

**Testes** (25 novos)
- `tests/Feature/Quick261001/ConferenciaFaturamentoDoFechamentoTest.php` (16)
- `tests/Feature/Quick261001/AvisoDadoMudouDepoisUiTest.php` (9)

**Planejamento**
- `deferred-items.md` nesta pasta — T3, defeito gêmeo de
  `syncCompanyMarginOnly()` e as 6 falhas pré-existentes medidas

**Intocados, como manda o plano:** `FechamentoFaixaResolver::classificar()`,
`FechamentoSnapshotWriter`, `FechamentoEmpresasDoMes`, `podeUsarApiDaAdman()`.

---

## Para o orquestrador

Nada precisa ser ligado: o comando e o aviso valem com qualquer estado das
chaves. Depois do deploy, o caminho de conferência é

    php artisan fechamento:verificar-consolidacao --mes=2026-09 --json

e, se a competência de setembro já tiver sido refeita às 11:38 (como o usuário
fez hoje à mão), o esperado é `divergentes: []` e exit 0. Se aparecer
`FAIXA_MUDARIA`, **quem refaz é uma pessoa** — o comando não conserta, por
desenho.
