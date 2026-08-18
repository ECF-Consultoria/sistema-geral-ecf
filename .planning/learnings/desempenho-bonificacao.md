# Desempenho e Bonificação — o que já custou caro descobrir

Contexto durável para quem for mexer em nota de desempenho, ranking, carteira ou bônus.
Escrito em 2026-08-03, ao fim da Fase 122.

**Por que este arquivo existe:** boa parte deste conhecimento vivia só na memória local do Claude Code de uma máquina. Quem abrisse o projeto em outro computador começava sem nada disso e corria o risco de refazer análises caras ou desfazer decisões conscientes sem saber. Se você descobrir algo desta natureza, acrescente aqui — não deixe só na memória da sua sessão.

---

## 0.00. A nota divide SEMPRE por 3 desde 2026-08-10 — e a margem voltou ao mês corrente

Duas mudanças no mesmo dia, pedidas pelo Maycon no PDF "Demandas e Fluxos –
Sistema ECF" (seção Desempenho). Leia junto com o §0 abaixo, que continua
válido no resto.

**(a) Divisor fixo.** `computeNotaFinalPorIndicador()` promediava só os
indicadores presentes — dividia por 2 quando faltava um. Agora o divisor é a
constante `DIVISOR_NOTA_FINAL = 3` e o indicador ausente entra como **zero**.

- O zero entra no nível do **indicador**, nunca por loja. O denominador
  independente dentro de cada indicador (§0 abaixo) continua igual: loja sem
  margem segue apenas fora da média de margem, contando no faturamento e no
  NPS. Zerar por loja seria outra regra, muito mais severa — eram 75 das 286
  lojas em 2026-06.
- Carteira sem **nenhum** indicador continua `null`, jamais 0. A trava D-91-01
  (§0.1) depende disso.
- **Carteira só-Shopee passa a ter teto de (5+0+5)/3 = 3,33 e nunca alcança os
  4,00 da primeira faixa de bônus.** A Shopee não fornece CMV, então a margem
  dessa carteira é estruturalmente ausente. Isso foi perguntado ao Maycon com o
  caso na mesa e ele escolheu a opção mais severa das três (a alternativa era o
  ausente entrar com 1,0). O ponto aberto do §0.3 deixa de ser "nota de uma
  dimensão só" e vira "carteira só-Shopee tem teto".
- Efeito na mesma carteira só-Shopee do teste âncora, nos três métodos:
  placeholder 1,0 (até 05/08) → **2,33**; média dos presentes (05/08→10/08) →
  **3,00**; divisor fixo → **2,00**.
- `pontos_componentes` continua expondo `null` no ausente. O payload nunca
  fabrica o zero — senão "a carteira não tem o indicador" fica indistinguível
  de "tirou zero". Quem soma como zero é a nota e a conta na tela.

**(b) A margem voltou a existir no mês corrente.** O mês em curso resolve
`comparison_mode='same_interval_previous_month'`, e o `diff_pp` só nascia em
`previous_equal_length_window` (gate D-07/MPP-02 da Fase 117) — resultado: a
coluna Margem do ranking, o card do profissional e a célula da tabela ficavam
**vazios o mês inteiro**.

O gate não era descuido: **o `prev` que a Adman devolve é a janela
imediatamente anterior, não o mesmo intervalo do mês passado.** Medido ao vivo
(LUCCMAX, cust 1039099160, 2026-08-10):

| janela | value | prev (Adman) |
|---|---|---|
| 2026-08-01..10 | 27,23 | 22,51 |
| 2026-07-01..10 | 21,64 | 19,87 |

Aceitar o `prev` cru daria +4,72 p.p. no lugar dos **+5,59** corretos. A saída
foi pedir à Adman a margem % da **janela baseline exata** do período
(`fetchMargemPctBaseline()`) e fazer `diff_pp = atual − baseline`, com
`prev_value` apontando para essa mesma janela (senão o "antes → depois" da
tabela não fecha com o p.p. ao lado). `diff_source='adman_janela_baseline'`.

O hotfix de 2026-07-24 (§1 do que vem depois — "variação de margem sempre do
valor nativo, nunca cálculo local") **continua valendo**: os dois lados da
subtração são valor nativo da Adman. Muda a janela apontada, não a fonte.
Custo: +1 chamada ao endpoint detalhado por empresa, **só no mês corrente**,
com cache próprio de 1 dia.

**Mês fechado / competência de bônus: intocado por (b).** Mas (a) muda a nota
de qualquer competência que for reconsolidada — **inclusive as já pagas**.
Nenhuma consolidação foi rodada em 2026-08-10.

Chaves de cache: `desempenho.compute` foi de **v17 → v18 → v19** no mesmo dia,
e `adman:diff` de **v6 → v7**.

## 0.01. O gate de hash da Fase 119 estava vermelho havia semanas

`assertHashDesempenhoScoreServiceIntocado()`, repetido nos 5 arquivos de
`tests/Feature/Phase119/`, comparava o SHA-256 do `DesempenhoScoreService.php`
com uma constante congelada na Fase 119.1. As Fases 120/122 e a troca de método
da nota (v17, 2026-08-05) alteraram o arquivo **sem rotacionar a constante** —
resultado: **17 testes falhavam na PRIMEIRA asserção** e mascaravam tudo que
vinha depois.

Rotacionado em 2026-08-10. Com o gate verde, 3 testes revelaram que afirmavam
contrato revogado em 2026-08-05 (loja Shopee entrando na margem com placeholder
1,0, quando hoje ela fica fora do denominador). **Quem alterar o
`DesempenhoScoreService.php` precisa rotacionar a constante nos 5 arquivos** —
senão a suíte inteira volta a mentir.

## 0.02. As 9 falhas de teste que NÃO são regressão sua

Medidas em 2026-08-10 com as mudanças em `git stash`, falham idênticas com o
código revertido:

- `V18\CarteiraPeriodoDiffTest` (2), `V18\DesempenhoPeriodoOficialTest` (1),
  `DesempenhoShopeeScoreTest` (3) — todas cobram a variação **relativa** de
  margem vinda do `calculated_fallback` local, revogado pelo hotfix de
  2026-07-24.
- `V18\ConsolidarMesJanelaNpsTest` (2), `V18\JanelaNpsBonusTest` (1).

Antes de investigar "regressão" nessas suítes, meça a baseline com as suas
mudanças em stash. É barato e evita perseguir fantasma.

## 0.03. Marketplace: `companies.marketplace` não diz de qual marketplace é a conta

Parece o campo certo e não é. É o marketplace da conta **Adman**, usado para
montar a URL da API (`/{marketplace}/accounts/...`), com default `'meli'`.
Medido em 2026-08-10: **171 de 171** empresas com valor estavam em `meli`, e a
pivot `company_marketplaces` (Fase 57) tinha **ZERO linhas**. Usá-lo diria
"Mercado Livre" para toda loja Shopee.

Quem responde "qual marketplace?" no Desempenho é a **fonte financeira**,
derivada do serviço contratado em
`CarteiraContextService::flagsFinanceirasPorSetor()`: setor `performance` →
`adman` → Mercado Livre; setor `shopee` → Shopee; polos/publicação → sem fonte.
Traduzido no front por `marketplaceLabel()` — e a tradução vale igual em mês
corrente e em competência fechada porque `fonte_financeira` também é **coluna**
do snapshot congelado.

Empresa com os dois vínculos elegíveis resolve `adman` pela regra de desempate
e aparece como Mercado Livre. É o marketplace que produziu os números daquela
linha — não é omissão.

## 0.04. "Faturamento não vem" quase nunca é bug de cálculo — e o desempate de fonte tem um furo

Levantado em 2026-08-11 a partir de `/performance/21?mes=2026-07` (Felipe, 11 de
30 empresas sem faturamento). A coluna Faturamento exibe `faturamento_var_pct`;
ela fica vazia quando não há baseline. **Antes de suspeitar do motor, cheque a
origem** — em produção, todas as ocorrências vinham de dado inexistente:

| causa | como confirmar | quantas em 2026-07 |
|---|---|---|
| vínculo Shopee sem conexão OAuth | `shopee_tokens` (app `erp`) vazio **e** `shopee_metrics` com 0 linhas | 10 empresas |
| conta nova, backfill sem o mês-base | `MIN(reference_date)` cai dentro do mês atual | 1 (Tuki Pet, conectou 28/07) |
| desempate de fonte (abaixo) | `fonte_financeira='adman'` com `adman_account_id` NULL | 1 (Interior Magazine) |
| cadastro de teste | empresa sem conta em lugar nenhum | 3 |

A armadilha que faz perder tempo: **7 das 10 empresas Shopee sem OAuth TÊM dado
na Adman** no mesmo período. Olhar `adman_metrics` e ver linha lá dá a impressão
de que o dado existe e o motor está errado — mas o vínculo daquele profissional
naquela empresa é do setor `shopee`, então o dispatcher lê `shopee_metrics`, que
está vazia. A fonte é resolvida por VÍNCULO, não pela existência de dado.

**O furo real:** `CompanyScoreService::computeEmpresasScore()` resolve o
desempate com `$sources->contains('adman') ? 'adman' : $sources->first()` —
`'adman'` vence **sem verificar se a empresa tem conta Adman**. Interior
Magazine não tem `adman_account_id` nem uma linha em `adman_metrics`, mas tem
token Shopee e 71 linhas de métrica: para o Felipe (só vínculo shopee) a empresa
aparece com **+37,77%**; para Douglas e Gabriela (vínculo performance) resolve
`adman`, lê da conta que não existe e aparece **em branco**. Mesma empresa,
mesmo mês, dois profissionais vendo coisas diferentes.

Corrigir isso **muda nota** — Interior Magazine sairia de ausente para 5 pontos
de faturamento nas duas carteiras. Não é um fix cosmético; trate como mudança de
número de bônus (§2). Endereçado na Fase 136.

## 0.041. O "calculando…" que não sai é quase sempre a fila `default` parada

Mesmo dia, mesma investigação. O gate quente/frio enfileira
`desempenho:warm-cache` via `Artisan::queue`, que cai na fila **`default`**. Em
produção, quem consome `default` é só o `ecf-worker` (2 processos) — o
`ecf-worker-high` escuta **exclusivamente** a fila `high` e **não drena
`default`**. Quando os dois `ecf-worker` travam (o modo de falha conhecido:
`STOPPING` preso num job longo, aqui `SyncTodasVendasAdmanJob`), o warm nunca
roda e toda tela de desempenho fria fica em "calculando…" para sempre. "Carrega
alguns só" é o sintoma clássico: quem já tinha cache quente aparece, quem não
tinha nunca aquece.

Diagnóstico em 3 comandos, nesta ordem — o primeiro sozinho já responde:

```
supervisorctl status                                          # algum ecf-worker em STOPPING?
redis-cli -n 1 llen "ecf-admin-database-queues:default"       # fila acumulando?
redis-cli -n 2 --scan --pattern "*desempenho.compute.v19*"    # quais users/meses estão quentes
```

Destrave com `supervisorctl signal KILL ecf-worker:ecf-worker_00 ...` (o
`stop`/`restart` não resolve: eles JÁ estão em STOPPING). Mata o job em voo, que
volta no próximo ciclo.

**Defeito de desenho que sobrevive ao incidente e ainda não foi corrigido:** o
teto de poll do front é de 2 min (`Show.jsx`, 20 × 6s) mas o lock do warm é de
**3 min e global por MÊS**, não por usuário (`WarmDesempenhoDispatcher`, chave
`desempenho.warm.lock.YYYY-MM`). Se outro request pegou o lock, o job desta
pessoa não chega a ser enfileirado e ela queima o poll inteiro esperando algo
que nunca foi agendado — trava mesmo com worker saudável. Ainda sem fase.

## 0. A nota mudou de MÉTODO em 2026-08-05 — leia antes de tudo

A nota final deixou de aplicar a régua **uma vez sobre a % agregada da
carteira** e passou a usar a régua já aplicada **loja a loja**, promediando
depois (`DesempenhoScoreService::computeNotaFinalPorIndicador()`). Média por
indicador, com **denominador independente** por indicador — loja sem margem
continua contando no faturamento e no NPS.

É o modelo do fechamento manual do time (`Fechamento Junho _ Time de
performance.xlsx`). Consequências que valem saber antes de mexer:

- **Régua-da-média ≠ média-das-réguas.** Não é refinamento, é outra conta. Sob
  as faixas atuais, a competência 2026-06 sai de 5 contemplados para 1.
- **A mediana do faturamento (item 1) perdeu a razão de existir**, embora
  continue no código como metadado. Com a régua por loja, o outlier de
  +20.738% vira um voto de 5 pontos entre N lojas, em vez de arrastar a média
  da carteira. Se um dia a mediana for removida, o teste que prova isso é
  `Phase74\DesempenhoScoreServiceTest::test_var_faturamento_usa_mediana_e_outlier_nao_manda_na_carteira`.
- Os dois métodos anteriores seguem calculados como metadado — `nota_final_legado`
  (régua da média) e `nota_final_por_empresa` (company-first, exige loja
  completa) — e **não decidem bônus**. Servem para comparar sem recomputar.
- **`desempenho:comparar-score-empresa` NÃO compara contra a nota oficial.** A
  decomposição do delta dele (P1 margem pp×relativa, P2 régua-por-empresa×
  régua-da-média, P3 denominador) modela legado × company-first. Apontá-la para
  a agregação por indicador dá parcelas que não somam o delta.

**Nenhum arredondamento intermediário.** A régua de bônus tem fronteira dura em
4,00 e somar componentes já arredondados desloca o resultado. Quem arredonda é
a exibição, com 2 casas fixas.

**DEPLOYADO em 2026-08-05, e a competência 2026-06 FOI RECONSOLIDADA** com
backup prévio em `storage/app/private/backups/desempenho/`. Junho saiu de 5
contemplados para 1 — o histórico do sistema deixou de justificar o pagamento
já feito daquela competência. Julho e agosto NÃO foram reconsolidados: julho
ainda está sem NPS (coletado em agosto) e agosto está em curso.

O caso que tornou o vício do método antigo inegável: uma carteira exibia
`(4,72 + 5,00 + 5,00)/3 = 4,91`, com os dois 5,00 vindo da régua sobre a %
agregada (faturamento 7,87% > 5%; margem 13,51% > 4%). Loja a loja, porém, a
mesma carteira tinha **5 das 25 lojas tirando 1 ponto em faturamento** — média
real 3,60 — e a margem em 3,70. Cinco lojas em queda severa, e o conjunto
marcava 5/5 em crescimento.

## 0.05. Invalidar empresa pela tela APAGA snapshot — e exige reconsolidar

`BonusAuditoriaController::bustarCacheDaEmpresa()` **só apaga**. Ao invalidar
(ou reativar) uma empresa numa competência, ele deleta o snapshot mensal e o
detalhe por empresa de **TODOS os profissionais vinculados àquela empresa** em
`company_users` — e nunca regrava. Está no docblock: "Quem REGRAVA as linhas é
o `desempenho:consolidar-mes`".

Consequência real, observada em 2026-08-05: duas empresas foram invalidadas
pela tela e **três profissionais sumiram inteiros do fechamento de junho** —
sem resumo, sem linha por empresa, sem nota. Ninguém percebeu na hora, porque a
tela não avisa e o profissional simplesmente deixa de aparecer.

**Toda invalidação pela tela precisa ser seguida de
`desempenho:consolidar-mes --mes=YYYY-MM`.** Se você invalidar várias empresas,
faça todas e reconsolide UMA vez no fim.

## 0.06. `BonusInvalidacao` é por EMPRESA, não por profissional

A invalidação tira a empresa da conta de **todo mundo** que a tem na carteira.
Isso importa porque o sistema atribui a mesma loja a mais profissionais que o
fechamento manual: uma loja pode estar na carteira de quem atende ML **e** de
quem atende Shopee, enquanto a planilha lista só um deles.

Ao reconciliar sistema × planilha em 2026-08-05, das 74 divergências, **30 eram
seguras** (a loja não contava para ninguém) e **16 eram armadilha** (a loja
conta para OUTRO profissional — invalidar tiraria dele também). Exemplos:
TSOCKS BRASIL sobrava para dois, mas era loja legítima de outros dois;
LYAMDECOR, MPozenato, GRAN BELO e mais quatro sobravam para a dupla Shopee mas
eram da carteira de ML de outra pessoa.

Antes de invalidar em lote, cheque se a loja conta para alguém em QUALQUER aba
do fechamento. Decisão do usuário para esses casos: **o sistema está certo**, a
loja é atendida pelos dois e a planilha é que não reflete isso.

## 0.1. A trava D-91-01 precisa ser EXPLÍCITA no caminho por empresa

Carteira com zero vínculos financeiros elegíveis (só-Polos, só-Publicação) não
recebe nota oficial — decisão do usuário em 2026-07-16, até a diretoria aprovar
régua própria.

O cálculo legado barrava isso sozinho, olhando `vinculos_financeiros`. Com o
status derivando da distribuição por empresa, **a trava sumiu por omissão**: o
denominador independente simplesmente tirava faturamento e margem da conta, e a
pessoa passava a receber nota calculada **só com NPS**, entrando no ranking
medida por uma única dimensão e podendo alcançar faixa de bônus.

Hoje `computeScoreStatusPorEmpresa()` devolve `blocked` quando nenhuma linha
tem `fonte_financeira`. Foi regressão pega por teste; se você reescrever esse
método, essa guarda tem que sobreviver.

## 0.2. O NPS de loja sem link é a maior divergência contra o fechamento manual

Empresa elegível que passou o mês **sem nenhum link de pesquisa** recebe nota
1,00 (`NpsSemLinkService`, Fase 119.1). Isso atingia **92 das 286 lojas** da
competência 2026-06 — quase um terço da base — e é a maior causa isolada de a
nota do sistema ficar abaixo do fechamento manual, que não aplica essa
penalidade.

**É deliberado e foi reafirmado pelo usuário em 2026-08-05**: sem isso, deixar
de disparar a pesquisa sairia mais barato do que disparar e receber nota baixa.
Não "conserte" para bater com a planilha — a diferença é a regra, não um bug.
Medida de referência: excluindo os não-gerados do denominador, o sistema passa a
reproduzir o fechamento manual com erro médio de 0,17.

## 0.3. Ponto aberto: nota vinda de uma única dimensão

Com a Shopee fora da média de margem (ela não fornece CMV) e o NPS de um mês só
sendo coletado no mês seguinte, uma carteira **só-Shopee** pode produzir nota
formada **apenas pelo faturamento**. Caso real na competência 2026-07: 4,82,
faixa `intermediario`, com margem ausente por ser Shopee e NPS ainda não
coletado.

O `score_status` sinaliza a fragilidade, mas a faixa de bônus é calculada assim
mesmo. Uma exigência de cobertura mínima de dimensões para valer bônus
resolveria — é decisão de negócio, não foi tomada.

## 0.4. A planilha de fechamento tem erros de digitação

Ao usar `Fechamento Junho _ Time de performance.xlsx` como referência, saiba que
ela contém pelo menos 4 erros de entrada, todos confirmados contra produção:
faturamento de 12.450% (real: 124,46%), −7.520% (real: −75,16%), margem de 880%
(real: 7,79%) e uma margem com o sinal trocado (−9,34 na planilha, +9,26 real).

Fora esses, os dados batem: erro médio de **0,03 p.p.** no faturamento (96 lojas
pareadas) e **0,62 p.p.** na margem (92 lojas). E a coluna "Margem Mr" da
planilha É pontos percentuais, a mesma grandeza de `margem_var_pp` — comparada
contra variação relativa, o erro médio sobe para 16,66.

## 1. Regras de cálculo que divergem DE PROPÓSITO

**Faturamento usa MEDIANA. Margem usa MÉDIA.** Não uniformize.

`DesempenhoScoreService::computeVarFaturamento()` agrega por `Collection::median()` desde 31/07/2026; `computeVarMargem()` continua com `avg()`.

Por que a mediana no faturamento: uma empresa com faturamento quase-zero no mês-base fazia a Adman devolver variações de milhares por cento, e a média deixava isso dominar a carteira. Caso real: a empresa "Lojão do Bras" faturou R$ 79,98 em maio e R$ 16.666 em junho → `.diff` de +20.738% → a carteira do Douglas, que **encolheu 2,3% de verdade**, pontuou como +766,25% e ganhou nota máxima em crescimento.

Por que **não** na margem: foi simulado. Mediana na margem levaria o bônus de 6 para 1 em 10 profissionais, porque a distribuição é diferente — a maioria das empresas fica perto de zero e poucas puxam para cima. Detalhe completo em `.planning/todos/pending/margem-regua-decisao-2026-08-03.md`.

**As réguas não serão modificadas.** Decisão explícita do usuário em 2026-08-03. A régua de faturamento dá 5 pontos para qualquer coisa acima de +5%, e a de margem para qualquer coisa acima de +4% — as duas comprimem no topo. É calibragem de política de bônus, pauta de diretoria, não conserto de código.

## 2. A régua de margem é frágil na fronteira

Danilo perdeu o bônus de junho/2026 **sem nenhuma mudança de código**: estava a 0,24 ponto percentual da fronteira de 4% e a releitura da mesma competência fechada, 14 horas depois, deu 2,52% em vez de 4,24%. Margem caiu de 5 para 4 pontos, nota de 4,22 para 3,89, faixa de `basico` para `sem_bonus`.

Gustavo oscilou 7,8 pontos percentuais na mesma competência fechada.

Consequência prática: **qualquer recompute pode mexer em pagamento** de quem estiver perto de uma fronteira. Não recalcule competência fechada sem necessidade, e nunca sem registrar o estado anterior.

## 3. Mês fechado lê snapshot CONGELADO

Ranking, dashboard e Relatório de Bonificação leem `desempenho_score_snapshots`, não o cálculo ao vivo. Corrigir o código **não muda nada** numa competência já fechada — só `desempenho:consolidar-mes --mes=YYYY-MM` regrava.

Desde a Fase 122 existe também `desempenho_company_score_snapshots` (detalhe por empresa), com trava: escrita de `snapshot_diario` ou `warm_cache` **nunca** sobrescreve competência já gravada por `consolidar_mes`. Sem isso o `desempenho:warm-cache`, que roda a cada 8 minutos, reescreveria o mês congelado com leitura nova da Adman.

## 4. Conferência é por reconsulta ao banco, NUNCA por stdout

O gate `FIXMARG-03` recusa congelar quando a cobertura de margem fica abaixo de 0,7, e reporta **apenas uma contagem** na saída — os nomes vão só para `Log::error`. Uma consolidação pode parecer bem-sucedida na tela sem ter gravado o que deveria.

Use `desempenho:verificar-consolidacao --mes=YYYY-MM --json` (read-only, criado na Fase 122). **O veredito é o exit code.**

Armadilha de shell que já enganou nesta casa: `comando | tail -20; echo $?` devolve o exit code do `tail`, não do comando. Capture antes do pipe ou redirecione para arquivo.

## 5. Cache

`DesempenhoScoreService` cacheia por `desempenho.compute.vNN` (hoje `v17`). **Qualquer mudança de cálculo exige bump** — sem ele o dashboard serve a nota velha. O bump quebra strings hardcoded em testes (Phase96/V16/V18/Phase116/Phase110): atualize todas no mesmo commit.

**NUNCA rodar `php artisan cache:clear` no VPS.** Em 30/07/2026 isso derrubou o site inteiro: apaga o cache aquecido da Adman, o dashboard passa a esperar a API, as requisições lentas ocupam os workers do php-fpm e até o login para. Depois de um bump de chave o clear é desnecessário. Se precisar recarregar config: `systemctl reload php8.2-fpm` e reaquecer com `adman:warm-diff`.

Em controllers use `DesempenhoScoreService::computeCached()`; `compute()` puro só em jobs e commands. Sem cache o dashboard levava 70 segundos, 99% esperando HTTP da Adman.

## 6. Armadilhas de banco que o SQLite dos testes não pega

Os testes rodam em SQLite; produção é MariaDB. Estas três já quebraram deploy:

- **Nome de índice acima de 64 caracteres** (erro 1059). Tabela de nome longo precisa de índice nomeado à mão. Pior: a falha não é limpa — cria a tabela, morre no índice, e deixa a migration como `Pending` com a tabela existente e sem o índice.
- **`nullOnDelete` exige coluna `nullable()`** (erro 1830).
- **Dropar índice usado por FK falha** (erro 1553). Adicione o índice novo ANTES de dropar o antigo, e faça a migration idempotente.

Enum em migration precisa de branch SQLite (`string()->change()` sem CHECK) ou os testes quebram.

## 7. Carteira e vínculos

`company_users` tem **várias linhas por (empresa, papel)** — uma por serviço, desde a Fase 76. Quem filtra só por `role` pega a pessoa errada e conta a mesma empresa na carteira de dois profissionais (dupla contagem no bônus). Use `consultorDoServico` / `estrategistaDoServico`, e `distinct` ao contar empresas.

Analista tem taxonomia **dupla**: o cargo vive em `user_setores → cargos.slug = 'analista'`; o papel na pivot `company_users` nunca é `analista` (só `consultor` ou `estrategista`).

Elegível pelo cargo **não** significa ter carteira. Quem tem zero empresas vinculadas não gera row mensal, e isso é correto — não inconsistência.

## 8. Fontes financeiras

Adman e Shopee. `shopee_metrics` tem faturamento e investimento, **não tem margem** — carteira só-Shopee usa placeholder de margem 1.0, que puxa a nota para baixo. A integração Shopee começou em 01/06/2026, então não há baseline antes disso.

O histórico de `adman_metrics` começa por volta de 21/05/2026, e `companies.created_at` é artefato de reimport em massa — filtrar "empresa nova" por data zera todo mundo.

## 9. Estado da milestone v21.0 (nota individual por empresa)

Fases 117 a 123. A 123 (telas e relatórios) é a única pendente.

A flag `metrics.performance_company_first_score` está **`false`** e não deve ser ligada sem os dois gates:
- **ROLL** (delta antigo × novo): aprovado com ressalva, ressalva já resolvida.
- **MPP-04** (estabilidade): continua **reprovado**, faltam 2 rodadas.

Número que precisa estar na mesa antes de qualquer ativação: pelo método atual, 8 de 11 profissionais recebiam bônus na competência 2026-06; **pelo método novo, 1 de 11**. Isso não vem de bug — vem da régua passar a ser aplicada empresa por empresa. Evidência reconsultável com `desempenho:comparar-score-empresa --run=03787204-51a7-49fb-8478-da56a5b07e2a` (0,3s, sem custo de API).

## 10. Árvore de trabalho compartilhada

Várias sessões de Claude Code e mais de um dev editam a **mesma** árvore. Sempre `git commit -- <caminhos>`; **nunca** `git add -A` ou `git add .`.

`deploy.sh` publica exatamente `origin/main` — ou seja, **quem deploya publica o trabalho de todo mundo** que já estiver lá, tenha essa pessoa escolhido o momento ou não. Confira `git log HEAD..origin/main` antes.

`.planning/REQUIREMENTS.md` na raiz parou na v17.0; os IDs das milestones novas só existem em `REQUIREMENTS-vNN.md`, e o `phase.complete` não os alcança — marque os checkboxes à mão ao fechar fase.

## 11. Checkpoint visual com dado real de bônus — nunca versionar resultado individual

Autorização para trazer dado de compensação (score/bônus por profissional) para o **banco local**, a fim de conferir tela com dado real, **não é** autorização para **versionar** esse resultado individual no histórico do git — são duas decisões diferentes, e a segunda exige consentimento à parte, mesmo com a primeira já dada. Aconteceu na Fase 123 (checkpoint 123-06): um commit gravou, num arquivo versionado, tabela nominal pareando profissional com faixa de bônus e nota final; foi corrigido por amend antes de ir mais longe no histórico.

Regra prática para qualquer documento de checkpoint/verificação que toque dado real de bônus: **contadores de carteira por profissional (entraram/total) podem ser versionados** — é o dado que a tela exibe. **Nome pareado com faixa de bônus, nota final ou valor de bonificação, não** — refira-se a "o contemplado da faixa X" e aponte pra conferência no banco local de quem executa.
