# NPS não respondido conta nota mínima (1)

Este documento explica, de forma curta e operacional, a regra "NPS não
respondido conta nota 1 (piso da escala 1-5)" — quando ela vale, onde ela
aparece, como funciona o disparo manual e o NPS de grupo (Fase 119.1), como
rodar o backfill retroativo, como reverter e a armadilha das duas réguas de
dedupe que coexistem no sistema. Fonte da verdade do cálculo: `app/Services/
Nps/NpsImputationService.php` (empresa COM link, não respondido) e
`app/Services/Desempenho/NpsSemLinkService.php` (empresa elegível SEM
nenhum link — Fase 119.1). Fonte da verdade operacional (backfill):
`app/Console/Commands/NpsMaterializarNaoRespondidos.php`.

**Atualização mais recente (Fase 119.1, 2026-07-29/30):** o disparo
automático mensal foi descontinuado (só o agendamento — o comando continua
manual), passou a existir bloqueio de link duplicado e nasceu o NPS de
grupo. Essas três mudanças, mais a contrapartida que elas exigiram
(empresa elegível sem NENHUM link também conta 1), estão descritas nas
seções 3 a 5 abaixo. Quem já conhece a regra da Fase 116 e só quer saber o
que mudou: vá direto à seção 1 (o invariante foi invertido) e à seção 3.

## 1. A regra

Toda empresa que passou o mês sem receber uma nota real conta **1** — o
piso da escala real do produto, que vai de 1 a 5 — em todos os lugares que
consomem a média de NPS: área NPS, bônus/Desempenho, carteira do
profissional, dashboards, página da empresa, relatório por empresa e meta
de NPS. Isso acontece de duas formas, com fontes de dados diferentes:

- **NPS efetivamente disparado e não respondido** (existe `NpsSurvey` para
  aquela empresa + responsável + competência, mas ninguém respondeu) —
  regra original da Fase 116, materializada em `nps_imputed_assignments`
  pelo comando de backfill (seção 6 abaixo).
- **Empresa elegível que passou o mês inteiro SEM NENHUM link gerado**
  (nem disparo automático — descontinuado — nem manual) — regra da Fase
  119.1 (D1), calculada em tempo real por `NpsSemLinkService`, NUNCA
  materializada em tabela nenhuma (é sempre um cálculo de leitura).

Dois invariantes protegem a regra de virar punição indevida:

- **Empresa NÃO elegível nunca entra.** ⚠️ **Este invariante foi
  INVERTIDO pela Fase 119.1** — até a Fase 116, o invariante era "empresa
  sem disparo nunca entra" (não importava o motivo). Com o disparo
  automático desligado (seção 3), aquele invariante virou a brecha "não
  enviar o NPS sai mais barato que enviar e arriscar uma nota ruim" — e o
  usuário, confrontado com essa consequência, decidiu fechá-la
  (`119.1-CONTEXT.md`, decisão D1). A partir da Fase 119.1: **empresa
  elegível que passou o mês sem nenhum link TAMBÉM conta nota 1** —
  "elegível" significa contrato ativo em serviço coberto por um modelo de
  NPS automático (`envio_automatico_mensal=true`) e estrategista atribuído.
  Empresa **NÃO elegível** (sem contrato ativo no serviço coberto, sem
  nenhum modelo automático aplicável, sem estrategista, inativa, ou
  invalidada na competência — `bonus_invalidacoes`) continua de fora, como
  sempre. A fonte única de "quem é elegível" é `App\Services\Nps\
  NpsElegibilidadeService::empresasElegiveis()` — qualquer consumidor novo
  de NPS deve reusar este serviço, nunca reimplementar a regra.
- **Empresa sem contato cadastrado conta 1 do mesmo jeito (D5).** Empresa
  elegível que não tem e-mail nem WhatsApp/Digisac cadastrado **também**
  conta nota 1 — a ausência de canal NÃO é motivo de exclusão. Decisão do
  usuário (2026-07-29): cadastrar o contato do cliente também é
  responsabilidade de quem cuida da conta; sem essa regra, "deixar o
  contato em branco" viraria a nova brecha. A tela (área NPS, lista de
  "Faltantes") mostra explicitamente o motivo "Sem contato cadastrado"
  nesses casos, para quem cuida da empresa saber o que resolver.
- **Empresa invalidada na competência não entra.** Continua igual à Fase
  116: uma empresa marcada em `bonus_invalidacoes` (tela
  `/desempenho/auditoria-bonus`) para a competência M sai do bônus
  financeiro E do NPS naquela competência — o não respondido/sem link dela
  não vira nota 1 em lugar nenhum. Invalidar uma empresa não pode, de
  quebra, derrubar a nota de quem já não vai receber o bônus daquela
  empresa.

## 2. Quando vira definitivo

Para o ramo **efetivamente disparado e não respondido** (Fase 116): a nota
1 vale **desde o disparo**, enquanto a competência do survey ainda está
aberta — a média já reflete o não respondido em tempo real, no mês
corrente. Quando o mês do disparo **termina** sem resposta, a nota 1 fica
**congelada** ("definitivo", estado gravado, nunca recalculado) — uma
resposta que chegue depois disso não reescreve a nota daquela competência.

O gate de fechamento compara **datas** (`hoje > último dia do mês do
disparo`), nunca "é o último dia" — porque o link do NPS vale até 23:59:59
do último dia do mês (`expires_at = endOfMonth`). Se o fechamento
comparasse "é o último dia" às 00:00, o sistema congelaria a nota 1
enquanto o cliente ainda tem 24 horas de prazo para responder. Por isso o
gate usa "o mês virou" (estritamente depois do último dia), não "chegou o
último dia".

Para o ramo **elegível sem nenhum link** (Fase 119.1): não existe estado
"provisório/definitivo" — é sempre um cálculo de leitura, refeito a cada
vez, e só passa a valer 1 depois que o mês de coleta **fecha** (mesma régua
de data acima). Enquanto o mês de coleta ainda está em curso, ninguém é
penalizado — o motivo mostrado é "janela aberta", nunca "não elegível".

### Piso retroativo (só nas leituras em janela rolante)

Quatro consumidores leem uma **janela rolante** que se estende para trás no
tempo a partir de "hoje" (carteira do profissional, histórico mensal do
Portfolio, página da empresa) em vez de uma competência fixa. Sem um
limite, o ramo "elegível sem link" fabricaria nota 1 em **qualquer mês do
passado** em que a empresa seja elegível **hoje** — mesmo em meses onde ela
nem tinha contrato ainda — porque `NpsElegibilidadeService::
empresasElegiveis()` lê o estado **atual** da empresa, não reconstrói
elegibilidade histórica. Isso faria o **backfill retroativo acontecer por
efeito colateral de abrir uma tela**, exatamente o que a seção 7 mantém sob
gate humano do usuário.

Por isso essas quatro leituras aplicam um **piso**: nunca voltam além do
mês anterior a "hoje" (`now()->subMonthNoOverflow()->startOfMonth()`) — a
MESMA janela que a rotina diária da seção 7 usa, pelo mesmo motivo.

**O piso é PROIBIDO em leitura de competência FIXA** — o bônus
(`DesempenhoScoreService::computeNpsMedio()`) e a meta de NPS
(`CalculateGoalResults::computeNps()`) nunca recebem esse piso, porque eles
leem uma competência **fechada e específica** (ex.: "junho/2026"). Um piso
relativo a `now()` ali faria a MESMA competência fechada devolver um número
diferente dependendo da hora em que o recompute roda — a mesma classe de
bug já registrada no projeto na instabilidade da margem Adman, e o oposto
do que `desempenho:consolidar-mes` precisa para congelar um snapshot
reprodutível.

## 3. O NPS não sai mais sozinho

A Fase 119.1 removeu o `Schedule::command('nps:disparar-mensal')` de
`routes/console.php` — o disparo automático mensal (o link que nascia
sozinho, uma vez por mês, por aniversário do cadastro) **não existe mais**.

O que isso muda na prática:

- O comando `php artisan nps:disparar-mensal` **continua existindo** e
  continua funcionando exatamente como antes — só não roda mais sozinho.
  Serve para disparo manual em massa (ex.: disparar o mês inteiro de uma
  carteira de uma vez).
- E-mail (`NpsMonthlyMail`) e envio via Digisac só saem quando alguém
  **invoca o comando à mão**, ou gera um link individual/de grupo pela
  tela.
- A lista de "Faltantes" da área NPS (`/nps`) é a ferramenta operacional
  para saber o que falta enviar — a partir do dia de cobrança,
  praticamente toda a carteira aparece como pendente ali, e isso é
  **esperado**, não um bug.
- A contrapartida direta do desligamento é a inversão do invariante da
  seção 1: sem ela, "não disparar" viraria a saída mais barata para quem
  cuida de uma conta.

## 4. NPS de grupo

A ECF atende grupos empresariais com várias contas no Mercado Livre — antes
da Fase 119.1, cada conta recebia um link de NPS separado, o que é
desgastante para o cliente quando é a mesma pessoa respondendo por todas.
Agora dá para gerar **um único link para o grupo**, e a nota que o cliente
der é replicada para as empresas do grupo em que os mesmos responsáveis
cuidam do serviço coberto pelo modelo.

**Regra de elegibilidade do grupo — "a maioria manda":** a nota só replica
para as empresas do grupo cujo **estrategista E analista** (os dois
papéis, sempre) são os mesmos, **por serviço coberto pelo modelo**, da
classe de responsáveis **majoritária** do grupo — nunca da empresa de
menor id, nem da mais antiga. Um grupo de 4 empresas em que 3 têm
Luis/Ana e 1 tem outra dupla: as 3 recebem a nota; a divergente fica de
fora, **mesmo que seja a empresa fundadora do grupo**. Quem diverge da
maioria continua contando nota 1 (regra da seção 1) até alguém gerar um
link individual para ela e o cliente responder.

Internamente, cada empresa coberta pelo grupo ganha o **próprio registro**
de pesquisa (`NpsSurvey`/`NpsResponse` reais, um por empresa) — a nota não
é "compartilhada" por referência; é replicada de verdade. Isso garante que
as duas réguas de dedupe da seção 6.1 (área NPS e bônus/carteira) contem
certo: nem a mais, nem a menos, mesmo quando N empresas do grupo respondem
"como se fosse uma só".

Antes de enviar, quem gera o link de grupo vê uma prévia ("Quem vai receber
esta nota") com as empresas **incluídas** e as **excluídas**, cada exclusão
com um motivo. Os 5 motivos são **intencionalmente distintos** — nunca
colapsar em um motivo genérico tipo "não qualificada":

| Motivo | Significado |
|---|---|
| `responsavel_diferente` | O serviço coberto pelo modelo, nesta empresa, é cuidado por outra dupla de responsáveis — diverge da maioria do grupo. |
| `sem_servico_contratado` | A empresa não tem o serviço que o modelo de NPS cobre — o modelo não se aplica a ela. |
| `sem_servico_em_comum` | A empresa não tem nenhum serviço em comum com as demais do grupo neste modelo. |
| `ja_tem_link` | A empresa já tem um link (individual ou de outro grupo) deste modelo neste mês — ela responde pelo link dela, não pelo do grupo. |
| `empresa_inativa` | A empresa está inativa no sistema. |

Cada motivo aponta para uma ação diferente (gerar link individual,
contratar o serviço, nada a fazer) — um dev futuro que "simplificar" essa
lista para um motivo único quebraria essa clareza operacional. Foi decisão
de produto explícita, não descuido.

## 5. Não dá para gerar dois links iguais

Empresa + modelo + mês só admite **um** link — tanto no NPS individual
quanto no de grupo. Ao tentar gerar de novo (empresa já tem link no mês
que ainda não respondeu, ou o grupo já tem link do modelo no mês), o
sistema **não cria um segundo link**: devolve o link que já existe, para
copiar e reenviar. A mensagem explica o motivo em linguagem simples ("já
existe um link deste mês... usar o link abaixo") — nunca um 422 seco.

O motivo de existir: dois links da mesma empresa/mês podiam fazer o
cliente responder duas vezes, ou o segundo link ficar sem resposta e pesar
sozinho na média do profissional (uma nota "fantasma" a mais no
denominador). O índice único do banco (`(company_id, month_reference,
template_id)`, desde a Fase 68) só protege quando `month_reference` está
preenchido — o disparo manual, que gera `month_reference = NULL`, precisou
de um guard de aplicação equivalente (resolvendo a competência pelo mesmo
fallback `month_reference ?? created_at` que a Fase 116 já usa).

## 6. Onde a regra aparece

Checklist de todo consumidor de média de NPS que respeita a regra hoje — se
você for mexer em agregação de NPS no futuro, comece por aqui:

| Consumidor | Classe | Método |
|---|---|---|
| Área NPS (cards, série 12 meses, contadores, lista de faltantes) | `App\Http\Controllers\NpsController` | `index()` |
| Bônus / Desempenho | `App\Services\DesempenhoScoreService` | `computeNpsMedio()` |
| Carteira — coluna NPS, heatmap, últimas notas | `App\Http\Controllers\PerformanceController` | `dashboardCarteira()` |
| Carteira — histórico NPS mensal do profissional | `App\Http\Controllers\PortfolioController` | `renderPortfolio()` |
| Dashboard admin (widget `stats.avg_nps`) | `App\Http\Controllers\DashboardController` | `adminDashboard()` |
| Dashboard do usuário (widget `stats.avg_nps`) | `App\Http\Controllers\DashboardController` | `userDashboard()` |
| Ranking "Desempenho da equipe" | `App\Http\Controllers\DashboardController` | `buildRanking()` |
| Página da empresa (`nps_avg`) | `App\Http\Controllers\CompanyController` | `show()` |
| Relatório por empresa (bônus por empresa, Fase 118/119, v21.0) | `App\Services\Desempenho\NpsPorEmpresaService` | `notasNpsPorEmpresa()` |
| Score por empresa (Fase 119, v21.0 — aditiva, ainda sem consumidor de produção) | `App\Services\Desempenho\CompanyScoreService` | `computeEmpresasScore()` |
| Meta de NPS | `App\Jobs\CalculateGoalResults` | `computeNps()` |

Todos delegam a resolução de responsável/competência/invalidação para
`NpsImputationService` (ramo "disparado e não respondido") e
`NpsSemLinkService` (ramo "elegível sem link", Fase 119.1) — nenhum
reimplementa a regra por conta própria. Isso é deliberado: é o que garante
que os números não divirjam por um call-site "esquecer" a regra. A Fase
119.1 fechou o gate de coerência que prova isso nos 6 consumidores acima
que produzem um NÚMERO de bônus/nota (`tests/Feature/Phase119_1/
NpsCoerenciaD1Test.php`) — se um deles divergir no futuro, o teste fica
vermelho.

**`NpsPorEmpresaService` e `CompanyScoreService` usam a MESMA definição de
elegível que o bônus.** Até a Fase 118, o relatório por empresa dava nota 1
para QUALQUER empresa da carteira viva sem NPS (sem checar elegibilidade) —
uma divergência conhecida e aprovada na época. A Fase 119.1 fechou essa
divergência: empresa **não elegível** aparece com o motivo próprio
`nao_elegivel`/`nps_nao_elegivel` (em vez de continuar contando 1, ou de
ser confundida com `janela_aberta`/`nps_janela_aberta`, que é o motivo de
quando a coleta do mês ainda está em curso). Número e motivo precisam
concordar — é o que o gate de coerência acima verifica.

### 6.1 Gotcha — DUAS réguas de dedupe DIFERENTES (não é bug)

O mesmo survey não respondido (ou a mesma resposta replicada de grupo) pode
gerar mais de uma linha "candidata" (uma por serviço coberto × responsável
× dimensão). Dois grupos de consumidores agregam essas linhas de formas
**diferentes e propositais**:

- **Área NPS** (`NpsController`): dedupe por (`survey_id`, `dimensao`) —
  cada survey vale **1 nota por dimensão**. Se não fosse assim, uma empresa
  com 2 serviços cobertos pelo mesmo modelo pesaria o dobro de uma empresa
  com 1 só serviço na média da tela.
- **Desempenho/bônus e carteira** (`DesempenhoScoreService`,
  `PerformanceController`): dedupe por (`survey_id`, `role`) **por
  pessoa** — cada responsável carrega a própria nota do survey que lhe
  cabe. Se fosse deduplicado só por survey (como a área NPS), a nota de
  uma pessoa passaria a depender de quantos colegas dividem a mesma
  empresa/serviço — o que não faz sentido para um número que mede o
  desempenho INDIVIDUAL dela.

**As duas réguas são corretas para os seus respectivos consumidores.**
Unificá-las quebra um dos dois números. Isso vale também para o NPS de
grupo (seção 4): a replicação em N `NpsSurvey`/`NpsResponse` reais (um por
empresa coberta) garante que as duas réguas continuem contando certo sem
precisar aprender o conceito de "grupo" — foi provado por equivalência
exata contra N links individuais respondidos com a mesma nota
(`tests/Feature/Phase119_1/NpsGrupoDedupeTest.php`). Se um dia alguém notar
que a área NPS e o bônus mostram contagens diferentes para o "mesmo" NPS e
achar que é bug — **NÃO É**. É a granularidade certa para cada tela. Antes
de "corrigir" a divergência, releia esta seção.

## 7. Operação — sequência completa do backfill retroativo

> **STATUS DO BACKFILL RETROATIVO DO HISTÓRICO (atualizar esta linha a
> cada mudança de estado):** **AINDA NÃO EXECUTADO em produção** para as
> competências fechadas ANTES do deploy da Fase 116 (2026-07-28). O
> código, o comando e o procedimento abaixo foram revisados e aprovados
> pelo usuário; a decisão foi **adiar a aplicação retroativa em massa**
> para rodar o `--dry-run` e aplicar depois, no tempo dele — é gate de
> negócio do usuário, não bloqueio técnico. Distinto disto: no dia do
> deploy da Fase 116/117 (28-29/07/2026), a execução normal da rotina
> diária (seção "Rotina automática" abaixo) já cobriu a competência
> **junho/2026** sob o mesmo gate (dry-run revisado e só então aplicado com
> `--force`) — isso NÃO é o backfill retroativo do histórico completo, é a
> operação normal da rotina alcançando a primeira competência fechada após
> o deploy. Competências fechadas ANTES de junho/2026 continuam com a
> média antiga (sem o piso/sem D1) em todas as telas, nas quatro contas de
> mês fechado (`/nps`, ranking, Relatório de Bonificação, auditoria) e no
> snapshot congelado do bônus, até o backfill retroativo do histórico
> completo rodar de fato. Quando for aplicado, atualizar esta linha com a
> data e as competências cobertas (ex.: "Aplicado em AAAA-MM-DD,
> competências X a Y — conferência OK").

O comando é `php artisan nps:materializar-nao-respondidos` — idempotente,
roda diariamente às 09:30 BRT via `routes/console.php` para manter o mês
corrente em dia, e também serve para o backfill retroativo manual das
competências já fechadas. Cobre os DOIS ramos da seção 1 (disparado e não
respondido; elegível sem link) na mesma passada. Sequência obrigatória,
sem pular passo:

1. **Dry-run**:
   ```
   php artisan nps:materializar-nao-respondidos --dry-run
   ```
   Opcionalmente restrito a uma competência do disparo: `--mes=YYYY-MM`. Não
   grava nada — só mostra o relatório.

2. **Conferir** a tabela impressa "Impacto por pessoa e competência": por
   pessoa e competência financeira, `NPS antes` × `NPS depois`, `Nota antes` ×
   `Nota depois` e `Faixa antes` × `Faixa depois` (`muda_faixa`). E o **plano
   de reconsolidação**: quais competências terão o `DesempenhoScoreSnapshot`
   (o registro congelado do bônus) reescrito, e quantas pessoas mudam de
   faixa **nesse snapshot** — não confundir com a coluna `muda_faixa` do
   relatório de impacto acima (que é a classificação PURA, sem a promoção
   DESEMP-08); o plano de reconsolidação é o que de fato vai mudar nas telas
   oficiais.

3. **Aplicar**, uma competência por vez, confirmando a cada uma:
   ```
   php artisan nps:materializar-nao-respondidos --mes=YYYY-MM
   ```
   `--force` pula a confirmação interativa — usar **só** em execução não
   interativa (nunca como atalho para "não ler o relatório").

4. **Reconsolidar + conferir** (o comando já faz isso sozinho, automaticamente,
   ao final da aplicação — não é um passo manual separado):
   - O comando chama `php artisan desempenho:consolidar-mes --mes=YYYY-MM`
     das competências financeiras afetadas (competência do disparo do NPS
     MENOS 1 mês) — é o reuso do mesmo comando que já congela o snapshot
     mensal, com o mesmo gate de margem `FIXMARG-03` (Fase 110): cobertura de
     amostra de margem abaixo de 0,7 (tipicamente rate-limit da API da
     Adman) faz o congelamento daquela pessoa ser **recusado**.
   - Logo depois, o comando **re-consulta o `DesempenhoScoreSnapshot`** de
     cada pessoa/competência que estava no relatório aprovado no passo 2, e
     compara com o valor esperado. Dois desfechos possíveis:
     - `Conferência OK — todas as N pessoa(s) do relatório tiveram o
       snapshot atualizado.` → seguir para o passo 5.
     - Uma tabela nomeando **pessoa e competência** cujo snapshot NÃO foi
       atualizado, com exit code de falha. As linhas de NPS foram gravadas
       normalmente — é só o congelamento do bônus daquela pessoa que foi
       recusado pelo gate de margem. **Ação:** re-rodar
       `php artisan desempenho:consolidar-mes --mes=YYYY-MM` mais tarde
       (quando a cobertura de margem se normalizar) e, em seguida, rodar de
       novo `php artisan nps:materializar-nao-respondidos --mes=YYYY-MM
       --force` (idempotente, não duplica nada) só para reconferir. **Não
       considerar o backfill daquela competência concluído enquanto houver
       nome nessa lista.**

   **Importante:** a contagem `Degradados: N` que aparece na saída do
   `desempenho:consolidar-mes` **não nomeia ninguém** — é só um agregado.
   Quem nomeia as pessoas com o snapshot recusado é a conferência do comando
   de materialização acima (e, em paralelo, o `Log::error` estruturado com a
   tag `[Desempenho Mensal]`, que traz `user_id`/`user_name`/`cobertura`).

   Depois de aplicar, rodar `php artisan cache:clear` no VPS (ver seção 9).

5. **Validar nas telas**: abrir a competência aplicada em `/nps`, no ranking
   de `/performance`, no Relatório de Bonificação e na auditoria de bônus —
   os quatro têm que contar a mesma história e mostrar as mesmas pessoas do
   relatório aprovado no passo 2.

### Por que o passo 4 é obrigatório (não é um "extra")

Uma competência **fechada** é servida pelo `DesempenhoScoreSnapshot` — o
registro autoritativo declarado em `RelatorioBonificacaoController` — e não
por `computeCached()` (que só reflete o mês em curso). Sem reconsolidar, o
backfill muda apenas o cálculo ao vivo; as telas oficiais de mês fechado
(ranking, Relatório de Bonificação, auditoria de bônus) continuariam
mostrando o número antigo. E sem **conferir** por re-consulta ao banco, uma
reconsolidação parcialmente recusada pelo gate de margem passaria por sucesso
silencioso — o operador acharia ter aprovado a mudança para todo mundo,
quando na verdade parte das pessoas ficou de fora.

### Rotina automática

O comando roda **diariamente às 09:30 BRT** (`routes/console.php`,
`onOneServer()->withoutOverlapping()`) para manter o mês corrente sempre em
dia, sem esperar o backfill manual. A nota 1 do ramo "disparado e não
respondido" também é materializada **no próprio disparo** (gancho em
`NpsController::generate()` e no NPS de grupo) — vale desde o disparo, sem
esperar o cron. O comando é **idempotente**: rodar de novo sobre um período
já processado não duplica linha nenhuma.

**Janela restrita: mês anterior + mês corrente (correção 2026-07-28,
preservada pela Fase 119.1).** A versão original desta rotina rodava SEM
`--mes`, o que varre o histórico INTEIRO. Combinado com `--force`, isso
executaria sozinho o backfill retroativo de TODAS as competências
fechadas — reescrevendo os `DesempenhoScoreSnapshot` congelados e mudando
quem bateu o bônus — sem o relatório antes/depois que o usuário exige
aprovar. O backfill retroativo do histórico é operação MANUAL e ÚNICA, com
gate humano; nunca rotina automática. Por isso o agendamento roda o
comando **duas vezes por dia** (mês anterior + mês corrente,
`foreach ([now()->subMonthNoOverflow(), now()] as $mes)`), sempre com
`--mes` explícito — nunca em range aberto. A Fase 119.1 desligou o disparo
automático (seção 3) mas **não mexeu nesta rotina de propósito**: ela não
depende do disparo automático para funcionar (varre `nps_surveys` de
qualquer origem, manual ou automática antiga) e o ramo "elegível sem link"
(seção 1) precisa dessa mesma varredura diária para não deixar a área NPS
com dado desatualizado. Regressão de teste explícita:
`tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php`
(casos "119.1 ...").

## 8. Rollback

```
php artisan nps:materializar-nao-respondidos --desfazer --mes=YYYY-MM
```

Com confirmação interativa (a menos que `--force`). O que ele faz:

- Apaga as linhas de `nps_imputed_assignments` daquela competência (inclusive
  as já `definitivo`).
- **Também reconsolida e confere** o snapshot da mesma forma que a aplicação
  original — devolvendo o registro congelado ao valor **pré-backfill**, e
  nomeando qualquer pessoa cujo snapshot não voltou ao esperado, com a mesma
  régua de conferência do passo 4 acima.

A tabela `nps_imputed_assignments` é **isolada**: nenhuma resposta real
(`nps_responses`, `nps_response_answers`, `nps_score_assignments`) é alterada
pela fase, em nenhum momento — o rollback nunca toca em dado de resposta
real, só nas linhas sintéticas de "não respondido". O ramo "elegível sem
link" (Fase 119.1) não tem rollback próprio porque nunca escreve nada — é
sempre um cálculo de leitura, refeito a cada vez.

## 9. Cache

A chave de cache do bônus está em `desempenho.compute.v13`
(`DesempenhoScoreService::cacheKey()`) — subida de `v12` para `v13` pela
Fase 119.1 (Plano 03), quando o 4º ramo de leitura ("elegível sem link")
foi plugado em `computeNpsMedio()`. **A Fase 120 deve subir para `v14`**
quando tocar `cacheKey()` de novo — o ROADMAP dela previa consumir `v13`,
que já foi usado por esta fase. Depois de aplicar o backfill em produção
(aplicação normal ou rollback), rodar:

```
php artisan cache:clear
```

no VPS. Sem isso, o Redis pode servir o bônus com o número antigo por até 7
dias (TTL do cache), mesmo com o snapshot já reconsolidado no banco.

## Achados operacionais do fechamento da fase (registro)

- **`public/build/` está no `.gitignore`.** O deploy precisa rodar
  `npm run build` no servidor (ou no pipeline de deploy) — sem isso, as
  mudanças de frontend (`resources/js/Pages/Nps/Index.jsx`,
  `resources/js/Pages/Nps/Respond.jsx`, `resources/js/Pages/Companies/
  Show.jsx`) não chegam ao bundle servido em produção, mesmo com o
  código-fonte já no git. Reconfirmado na Fase 119.1 (planos 07/08).
- **Gate de deploy da Wave 2 (Fase 116).** O gancho de escrita no disparo e o
  agendamento diário (Plan 116-06) só podem ir para produção com toda a
  Wave 2 mesclada junto: área NPS (116-03), carteira (116-04) e
  dashboards/UI (116-05). Sem os três, o Desempenho refletiria o piso e as
  demais telas não — os números discordariam entre si em produção. Os três
  commits de Wave 2 (`55a239e4`..`ab2e8934`, `87bf2148`..`3d1129f3`,
  `55be2206`..`127049ae`) estão confirmados na `main` neste fechamento.
- **Pitfall de ambiente em `ConsolidarMesDesempenho` (SQLite, NÃO
  corrigido).** `ConsolidarMesDesempenho::updateOrCreate(['mes_referencia' =>
  'YYYY-MM-01'])` usa uma string de data crua (bare-date) na cláusula WHERE.
  Em MySQL/MariaDB (produção) a coluna `mes_referencia` é `DATE` nativa — o
  motor trunca a hora tanto na gravação quanto na comparação, então o WHERE
  bare-date casa normalmente contra uma linha já existente. Em SQLite (usado
  pelos testes) a coluna não tem tipagem real: a linha é persistida como
  `'YYYY-MM-01 00:00:00'` e o WHERE bare-date nunca casa contra ela — o
  `updateOrCreate` tenta um INSERT que colide com o unique key em vez de
  atualizar. Este é um candidato provável para explicar as 2 falhas
  pré-existentes de `V18/ConsolidarMesJanelaNpsTest` (documentadas em
  `deferred-items.md` desde o Plan 116-01). Registrado aqui como observação —
  **não corrigido** (arquivo fora do escopo desta fase, conforme instrução
  explícita do executor).
- **Limitação herdada: lista de respostas reais da página da empresa.** O
  eager-load `nps_surveys` de `CompanyController::show()` (usado pela lista
  "NPS Respondidos" e pela parte "real" de `nps_avg`) é limitado aos **10
  surveys respondidos mais recentes** — limitação pré-existente à Fase 116,
  não introduzida por ela. A parte imputada/sem-link não tem esse corte. Em
  empresas com histórico de NPS muito longo, a composição exibida na tela
  (`X respondida(s) · Y sem resposta`) pode não somar exatamente o total de
  surveys da empresa.
- **A armadilha da migration em `nps_surveys` (Fase 119.1, corrigida em
  `973add64`).** Qualquer `Schema::table('nps_surveys', ...)` que adicione
  uma FK (ex.: `group_survey_id` apontando para `nps_group_surveys`) faz o
  SQLite **recriar a tabela inteira** por baixo dos panos para satisfazer a
  nova constraint — e nessa recriação, o SQLite **perde o predicado do
  índice único PARCIAL de dedup** (`(company_id, month_reference,
  template_id)` com `WHERE deleted_at IS NULL`, Fase 68 Plan 04): o índice
  parcial vira um índice **total**, silenciosamente. Isso reabriria a
  brecha de duplicidade (seção 5) só em ambiente de teste (SQLite), sem
  sintoma nenhum em produção (MySQL não tem esse comportamento) — o tipo de
  regressão que só aparece meses depois. Corrigido com um helper de
  restauração do índice parcial logo após a migration que adiciona a FK.
  **Quem escrever a próxima migration que toca `nps_surveys` precisa chamar
  o mesmo helper** — há um teste de regressão guardando isso
  (`tests/Feature/Phase119_1/NpsSurveysDedupParcialSobreviveMigrationTest.php`).

## Onde ficam os testes

- `tests/Feature/Phase116/NpsImputacaoServiceTest.php` — fundação
  (`NpsImputationService`, ramo "disparado e não respondido").
- `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`,
  `NpsFloorAreaNpsTest.php`, `NpsFloorMultiModeloTest.php`,
  `NpsFloorCarteiraTest.php`, `NpsFloorDashboardsTest.php` — 1 suíte por
  call-site/plano da Fase 116 (segmentados pela Fase 119.1 em "não
  elegível" e "elegível", nunca apagados).
- `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` —
  comando de operação (relatório, reconsolidação, conferência, rollback, e
  a regressão da janela restrita da Fase 119.1).
- `tests/Feature/Phase116/NpsFloorRegressaoTest.php` — checklist de
  COERÊNCIA entre todos os call-sites acima, sobre o mesmo cenário (mesmo
  molde do Pitfall 4 da Fase 96,
  `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php`).
- `tests/Feature/Phase119_1/` (Fase 119.1, o disparo manual sem
  duplicidade e o NPS de grupo) — entre outros:
  `NpsElegibilidadeServiceTest.php` (fonte única de elegibilidade),
  `NpsAgendamentoRemovidoTest.php` (desligamento do automático),
  `NpsGeracaoDuplicidadeTest.php` (bloqueio de link duplicado),
  `NpsFloorD1Test.php`/`NpsAreaD1Test.php`/`NpsD1CarteiraTest.php`/
  `NpsD1DashboardsMetaTest.php`/`NpsSemLinkJanelaTest.php` (o ramo
  "elegível sem link" em cada consumidor, incluindo o piso retroativo),
  `NpsPorEmpresaElegibilidadeTest.php` (gate local bônus × relatório por
  empresa), `NpsGrupoCoberturaTest.php`/`NpsGrupoSurveyTest.php`/
  `NpsGrupoDedupeTest.php` (NPS de grupo ponta a ponta, incluindo a regra
  da maioria e as duas réguas de dedupe), `NpsSurveysDedupParcialSobreviveMigrationTest.php`
  (a armadilha da migration acima), e `NpsCoerenciaD1Test.php` — o gate
  AMPLO de coerência entre os 6 consumidores que produzem número de
  bônus/nota, citado na seção 6.
