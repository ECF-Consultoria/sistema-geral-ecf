# NPS não respondido conta nota mínima (1)

Este documento explica, de forma curta e operacional, a regra "NPS efetivamente
disparado e não respondido conta nota 1 (piso da escala 1-5)" — quando ela
vale, onde ela aparece, como rodar o backfill retroativo, como reverter e a
armadilha das duas réguas de dedupe que coexistem no sistema. Fonte da
verdade do cálculo: `app/Services/Nps/NpsImputationService.php`. Fonte da
verdade operacional (backfill): `app/Console/Commands/NpsMaterializarNaoRespondidos.php`.

## 1. A regra

Todo NPS **efetivamente disparado** (existe `NpsSurvey` para aquela empresa +
responsável + competência) e **não respondido** conta nota **1** — o piso da
escala real do produto, que vai de 1 a 5 — em todos os lugares que consomem a
média de NPS: área NPS, bônus/Desempenho, carteira do profissional,
dashboards, página da empresa e meta de NPS.

Dois invariantes protegem a regra de virar punição indevida:

- **Empresa sem disparo nunca entra.** Se não existe survey nenhum no
  período, a empresa simplesmente não aparece na conta — nem como nota 1, nem
  como nota 0. Ela some da média do mesmo jeito que sempre sumiu, antes desta
  regra existir.
- **Empresa invalidada na competência não entra.** Uma empresa marcada em
  `bonus_invalidacoes` (tela `/desempenho/auditoria-bonus`) para a competência
  M sai do bônus financeiro E do NPS naquela competência — o não respondido
  dela não vira nota 1 em lugar nenhum. Invalidar uma empresa não pode, de
  quebra, derrubar a nota de quem já não vai receber o bônus daquela empresa.

## 2. Quando vira definitivo

A nota 1 vale **desde o disparo**, enquanto a competência do survey ainda está
aberta — a média já reflete o não respondido em tempo real, no mês corrente.

Quando o mês do disparo **termina** sem resposta, a nota 1 fica **congelada**
("definitivo", estado gravado, nunca recalculado) — uma resposta que chegue
depois disso não reescreve a nota daquela competência.

O gate de fechamento compara **datas** (`hoje > último dia do mês do
disparo`), nunca "é o último dia" — porque o link do NPS vale até 23:59:59 do
último dia do mês (`expires_at = endOfMonth`). Se o fechamento comparasse "é o
último dia" às 00:00, o sistema congelaria a nota 1 enquanto o cliente ainda
tem 24 horas de prazo para responder. Por isso o gate usa "o mês virou"
(estritamente depois do último dia), não "chegou o último dia".

## 3. Onde a regra aparece

Checklist de todo consumidor de média de NPS que respeita a regra hoje — se
você for mexer em agregação de NPS no futuro, comece por aqui:

| Consumidor | Classe | Método |
|---|---|---|
| Área NPS (cards, série 12 meses, contadores) | `App\Http\Controllers\NpsController` | `index()` / `notasImputadasPorDimensao()` |
| Bônus / Desempenho | `App\Services\DesempenhoScoreService` | `computeNpsMedio()` / `notasImputadas()` |
| Carteira — coluna NPS, heatmap, últimas notas | `App\Http\Controllers\PerformanceController` | `dashboardCarteira()` / `notasNpsDoUsuarioPorResposta()` |
| Carteira — histórico NPS mensal do profissional | `App\Http\Controllers\PortfolioController` | `renderPortfolio()` |
| Dashboard admin (widget `stats.avg_nps`) | `App\Http\Controllers\DashboardController` | `adminDashboard()` / `avgNotaDimensao()` |
| Dashboard do usuário (widget `stats.avg_nps`) | `App\Http\Controllers\DashboardController` | `userDashboard()` |
| Ranking "Desempenho da equipe" | `App\Http\Controllers\DashboardController` | `buildRanking()` |
| Página da empresa (`nps_avg`) | `App\Http\Controllers\CompanyController` | `show()` |
| Meta de NPS | `App\Jobs\CalculateGoalResults` | `computeNps()` |

Nenhum destes reimplementa a resolução de responsável, competência ou
invalidação — todos delegam para `NpsImputationService` (leitura via
`notasDoUsuario()`/`notasDaEmpresa()`/`surveyIdsComNotaDefinitiva()`). Isso é
deliberado: é o que garante que os números não divirjam por um call-site
"esquecer" a regra.

### Gotcha — DUAS réguas de dedupe DIFERENTES (não é bug)

O mesmo survey não respondido pode gerar mais de uma linha imputada (uma por
serviço coberto × responsável × dimensão). Dois grupos de consumidores
agregam essas linhas de formas **diferentes e propositais**:

- **Área NPS** (`NpsController`): dedupe por (`survey_id`, `dimensao`) — cada
  survey vale **1 nota por dimensão**. Se não fosse assim, uma empresa com 2
  serviços cobertos pelo mesmo modelo pesaria o dobro de uma empresa com 1 só
  serviço na média da tela.
- **Desempenho/bônus e carteira** (`DesempenhoScoreService`,
  `PerformanceController`): dedupe por (`survey_id`, `role`) **por pessoa** —
  cada responsável carrega a própria nota do survey que lhe cabe. Se fosse
  deduplicado só por survey (como a área NPS), a nota de uma pessoa passaria a
  depender de quantos colegas dividem a mesma empresa/serviço — o que não faz
  sentido para um número que mede o desempenho INDIVIDUAL dela.

**As duas réguas são corretas para os seus respectivos consumidores.**
Unificá-las quebra um dos dois números. Se um dia alguém notar que a área NPS
e o bônus mostram contagens diferentes para o "mesmo" NPS e achar que é bug —
NÃO É. É a granularidade certa para cada tela. Antes de "corrigir" a
divergência, releia esta seção.

## 4. Operação — sequência completa do backfill retroativo

O comando é `php artisan nps:materializar-nao-respondidos` — idempotente,
roda diariamente às 09:30 BRT via `routes/console.php` para manter o mês
corrente em dia, e também serve para o backfill retroativo manual das
competências já fechadas. Sequência obrigatória, sem pular passo:

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

   Depois de aplicar, rodar `php artisan cache:clear` no VPS (ver seção 6).

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
dia, sem esperar o backfill manual. A nota 1 também é materializada **no
próprio disparo** (gancho em `NpsDispararMensal` e em
`NpsController::generate()`) — vale desde o disparo (D2), sem esperar o cron.
O comando é **idempotente**: rodar de novo sobre um período já processado não
duplica linha nenhuma.

## 5. Rollback

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
real, só nas linhas sintéticas de "não respondido".

## 6. Cache

A chave de cache do bônus está em `desempenho.compute.v12`
(`DesempenhoScoreService::cacheKey()`). Depois de aplicar o backfill em
produção (aplicação normal ou rollback), rodar:

```
php artisan cache:clear
```

no VPS. Sem isso, o Redis pode servir o bônus com o número antigo por até 7
dias (TTL do cache), mesmo com o snapshot já reconsolidado no banco.

## Achados operacionais do fechamento da fase (registro)

- **`public/build/` está no `.gitignore`.** O deploy precisa rodar
  `npm run build` no servidor (ou no pipeline de deploy) — sem isso, as
  mudanças de `resources/js/Pages/Nps/Index.jsx` e
  `resources/js/Pages/Companies/Show.jsx` (UI que explica a regra) não
  chegam ao bundle servido em produção, mesmo com o código-fonte já no git.
- **Gate de deploy da Wave 2.** O gancho de escrita no disparo e o
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
  não introduzida por ela. A parte imputada (`nps_nao_respondidos`) não tem
  esse corte. Em empresas com histórico de NPS muito longo, a composição
  exibida na tela (`X respondida(s) · Y sem resposta`) pode não somar
  exatamente o total de surveys da empresa.

## Onde ficam os testes

- `tests/Feature/Phase116/NpsImputacaoServiceTest.php` — fundação
  (`NpsImputationService`).
- `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`,
  `NpsFloorAreaNpsTest.php`, `NpsFloorMultiModeloTest.php`,
  `NpsFloorCarteiraTest.php`, `NpsFloorDashboardsTest.php` — 1 suíte por
  call-site/plano.
- `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` —
  comando de operação (relatório, reconsolidação, conferência, rollback).
- `tests/Feature/Phase116/NpsFloorRegressaoTest.php` — checklist de
  COERÊNCIA entre todos os call-sites acima, sobre o mesmo cenário (mesmo
  molde do Pitfall 4 da Fase 96,
  `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php`).
