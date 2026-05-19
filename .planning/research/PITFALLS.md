# Armadilhas do Módulo de Fechamento Administrativo

**Domínio:** Módulo de fechamento com dados de API externa (Adman) em sistema Laravel/Inertia existente
**Pesquisado:** 2026-05-19
**Contexto:** ADM-01 a ADM-06 — faturamento mensal por empresa, faixas de investimento, progress bar, total consolidado

---

## Armadilhas Críticas

Erros que causam reescrita ou falha silenciosa de dados.

---

### Armadilha 1: Chamar `AdmanService::fetchPerformance()` de forma síncrona no page load

**O que dá errado:**
O controller de fechamento chama `$admanService->fetchPerformance($custId, $dateFrom, $dateTo)` diretamente durante o request, para buscar o faturamento mensal de cada empresa. `fetchPerformance()` tem timeout de 120s (vide `AdmanService.php:195`) e 3 tentativas com backoff. Para 20 empresas ativas, o page load pode levar minutos — ou simplesmente expirar o timeout do nginx (geralmente 60s).

**Por que ocorre:**
`AdmanService::syncAll()` já resolve isso com 700ms de sleep por empresa em background. O módulo de fechamento pode ser tentado a "só ler os dados" direto da API para ter o número mais recente, ignorando que a latência acumulada é proibitiva em request HTTP.

**Consequências:**
- Timeout HTTP 504 para o usuário
- Timeout de PHP-FPM bloqueando worker por 2+ minutos
- Rate limit 429 da Adman para chamadas consecutivas sem throttle

**Prevenção:**
Nunca chamar a API Adman de forma síncrona em page load. O faturamento mensal deve vir exclusivamente da tabela `adman_metrics` já populada pelo scheduler `adman:sync`. A query correta é:

```php
// Faturamento mensal = soma de revenue diário no mês de referência
AdmanMetric::where('company_id', $company->id)
    ->whereBetween('reference_date', [$inicioMes, $fimMes])
    ->sum('revenue');
```

**Sinal de alerta:**
Se o controller de fechamento instanciar `AdmanService` ou chamar qualquer método `fetch*` — é o sinal. O único lugar para chamar a API é dentro de Jobs ou Artisan commands.

**Fase:** ADM-02 — definir desde o plano que faturamento vem de `adman_metrics`, não da API ao vivo.

---

### Armadilha 2: Assumir que `adman_metrics` tem cobertura completa do mês corrente

**O que dá errado:**
O scheduler `adman:sync` popula `adman_metrics` com granularidade **diária** — uma linha por empresa por dia. `syncCompany()` usa `now()->subDay()->toDateString()` como data padrão (dados do dia corrente ficam incompletos até o processamento noturno da Adman). Portanto, para o mês corrente, a soma sempre será parcial: faltam os dias do fim do mês que ainda não ocorreram + o próprio dia de hoje.

**Por que ocorre:**
A convenção "sempre ontem" existe por boa razão (dados do dia corrente são incompletos na Adman). Mas o módulo de fechamento precisa de "faturamento do mês", que para o mês em curso será sempre um valor parcial.

**Consequências:**
- Admin vê R$320k para o mês corrente quando o mês ainda está no dia 15 — confunde com o total final
- Total consolidado subestimado sem indicação de que é parcial
- Faixa de investimento calculada incorretamente para o mês em curso

**Prevenção:**
1. Sempre exibir o período de referência explicitamente: "Faturamento de 01/05 a 18/05 (mês em curso)" — nunca apenas "Faturamento de Maio"
2. Incluir a data do último dado disponível por empresa (o `max(reference_date)` da query)
3. Para meses fechados (mês anterior completo), a soma é confiável — considerar exibir mês anterior como padrão para o fechamento

**Sinal de alerta:**
UI que exibe apenas "Maio 2026" sem a data do último registro disponível — é o sinal de que o contexto temporal está invisível.

**Fase:** ADM-02 e ADM-04 (progress bar) — o período deve ser parte do dado retornado pelo controller, não assumido como "mês cheio".

---

### Armadilha 3: Empresa sem `adman_account_id` ou sem dados no mês quebrando o total consolidado

**O que dá errado:**
`Company::where('active', true)->get()` inclui empresas sem `adman_account_id` (ex: Incubadoras que ainda não foram onboardadas na Adman) e empresas que não tiveram sync no mês (ex: conta nova adicionada semana passada). A soma `SUM(revenue)` para essas empresas retorna `null`, e `null + número = null` em PHP sem tratamento explícito.

**Por que ocorre:**
O `AdmanService::syncAll()` já filtra por `whereNotNull('adman_account_id')` — mas o módulo de fechamento pode listar todas as empresas ativas para mostrar a situação geral, incluindo as sem dados.

**Consequências:**
- Total consolidado retorna `null` ou valor errado silenciosamente
- Empresa com `adman_account_id` mas sem sync no mês aparece como R$0 (confunde com empresa que realmente faturou zero)
- Faixa de investimento de empresa sem dados é calculada incorretamente (null → faixa mínima por default)

**Prevenção:**
Tratar os três estados distintos explicitamente:

```php
// Três estados, três tratamentos diferentes
'faturamento' => match(true) {
    !$company->adman_account_id        => null,  // sem conta Adman — exibir "Sem integração"
    $somaMes === null                  => null,  // tem conta, mas sem dados no período — "Sem dados"
    default                            => (float) $somaMes,  // faturamento real
},
'status_dados' => match(true) {
    !$company->adman_account_id        => 'sem_integracao',
    $somaMes === null                  => 'sem_dados',
    default                            => 'ok',
},
```

O total consolidado deve somar apenas empresas com `status_dados === 'ok'` e incluir indicador de quantas empresas foram excluídas da soma.

**Sinal de alerta:**
Query `->sum('revenue')` sem verificar se o resultado é null ou zero — verificar se o controller distingue os dois casos.

**Fase:** ADM-02 e ADM-05 — definir o contrato de dados antes de implementar a UI.

---

### Armadilha 4: Lógica de faixas de investimento duplicada entre PHP e JavaScript

**O que dá errado:**
A tabela de 7 faixas (faturamento_adm.md) é implementada como array em PHP no controller E como objeto JS no componente React para calcular "distância para a próxima faixa" no frontend. Quando as faixas mudam, há dois locais para atualizar — e a inconsistência passa sem teste.

**Por que ocorre:**
O padrão do projeto de espelhar constantes PHP como objetos JS (documentado em CLAUDE.md: "Mirrored as plain JS objects in the corresponding React page file") existe para lookup tables simples. Para cálculo de faixas com lógica (verificar em qual faixa está, calcular distância, encontrar próxima faixa), a tentação é fazer o cálculo no JavaScript para a progress bar — e manter o PHP apenas para o valor de cobrança.

**Consequências:**
- Faixa exibida na progress bar (calculada em JS) diverge da faixa usada no total a cobrar (calculada em PHP)
- Atualização de contrato requer mudança em 2 arquivos sem garantia de sincronismo
- Sem teste automatizado para detectar a divergência

**Prevenção:**
Centralizar toda a lógica de faixas **exclusivamente no PHP**, no controller ou em um service dedicado. O controller calcula e passa para o React como props já resolvidas:

```php
// PHP calcula TUDO — React apenas exibe
[
    'faixa_atual'       => 1,          // índice 0-6
    'valor_cobranca'    => 3000.00,    // R$ 3.000
    'faturamento_mes'   => 320000.00,
    'proximo_limiar'    => 500000.00,  // próxima faixa começa em R$500k
    'falta_para_proxima'=> 180000.00,  // R$500k - R$320k
    'progresso_faixa_pct' => 64.0,    // 320k / 500k * 100
]
```

O React recebe números prontos e apenas renderiza a barra. Nenhum cálculo de negócio no JavaScript.

**Sinal de alerta:**
Qualquer array de faixas definido em `.jsx` — é o sinal de que a lógica vazou para o frontend.

**Fase:** ADM-03 e ADM-04 — a decisão de arquitetura deve ser feita no plano da fase, não no código.

---

### Armadilha 5: Faixas definidas como literais hard-coded sem isolamento

**O que dá errado:**
Os 7 níveis da tabela (Até R$499.999,99 → R$3.000, De R$500k → R$4.500, etc.) são escritos diretamente em `if/elseif` no controller. Quando o contrato com um cliente muda (faixa extra, desconto), o dev precisa editar o controller — código de negócio misturado com orquestração HTTP.

**Por que ocorre:**
Tabelas de lookup simples frequentemente são codificadas inline na primeira implementação por serem "só uma consulta". Mas a tabela de faixas tem lógica (faixa aberta no topo — "acima de R$5M, a partir de R$12k") que não é trivial.

**Consequências:**
- Faixa acima de R$5M não tem limiar superior — se implementada como `elseif($rev > 5_000_000)`, o `proximo_limiar` e `progresso_faixa_pct` ficam indefinidos ou erram
- Mudança de valores de cobrança requer alterar código PHP e redeploy

**Prevenção:**
Definir a tabela como constante estruturada no PHP, com tratamento explícito da faixa aberta:

```php
// Em um BillingService ou como constante no controller
const FAIXAS = [
    ['de' => 0,          'ate' => 499_999.99,   'valor' => 3_000.00],
    ['de' => 500_000.00, 'ate' => 999_999.99,   'valor' => 4_500.00],
    ['de' => 1_000_000,  'ate' => 1_999_999.99, 'valor' => 6_000.00],
    ['de' => 2_000_000,  'ate' => 2_999_999.99, 'valor' => 7_500.00],
    ['de' => 3_000_000,  'ate' => 3_999_999.99, 'valor' => 9_000.00],
    ['de' => 4_000_000,  'ate' => 4_999_999.99, 'valor' => 10_500.00],
    ['de' => 5_000_000,  'ate' => null,          'valor' => 12_000.00], // faixa aberta
];
```

A faixa com `ate => null` é a última — sem próximo limiar, progress bar mostra 100% (ou "na faixa máxima").

**Sinal de alerta:**
Sequência de `if ($rev < 500000)` ... `elseif ($rev < 1000000)` no controller — refatorar para array iterável imediatamente.

**Fase:** ADM-03 — isolamento de dados de negócio antes de conectar à UI.

---

## Armadilhas Moderadas

---

### Armadilha 6: Exibir "carregando..." infinito enquanto dados existem no banco

**O que dá errado:**
O módulo de fechamento é implementado com dois estados: "dados carregados da API" e "aguardando". Como os dados vêm do banco (não da API ao vivo), não há estado de carregamento real — o controller entrega os dados sincronamente via `Inertia::render()`. Mas a UI implementa um spinner/skeleton "aguardando sync" que nunca resolve porque não há job de fechamento — há apenas dados estáticos no banco.

**Por que ocorre:**
O padrão da Fase 1 (Diagnóstico Adman) tem o ciclo: dispatch → worker executa → usuário aguarda resultado. O módulo de fechamento não tem esse ciclo — os dados já estão no banco e são lidos diretamente.

**Consequências:**
- UI complexa desnecessariamente (polling, estados de loading)
- Confusão do usuário sobre quando os dados são "reais"

**Prevenção:**
O controller de fechamento deve entregar todos os dados no page load sem assincronismo. O único indicador temporal necessário é a data do último dado disponível por empresa (ex: "último dado: 18/05"). Não há botão "Atualizar agora" que chama a API — o sync já acontece via scheduler.

**Fase:** ADM-02 — clareza sobre o fluxo de dados no plano da fase.

---

### Armadilha 7: N+1 query ao calcular faturamento mensal de todas as empresas

**O que dá errado:**
O controller lista empresas com `Company::where('active', true)->get()` e então, para cada empresa, faz uma query separada: `AdmanMetric::where('company_id', $c->id)->whereBetween('reference_date', [...])->sum('revenue')`. Com 30 empresas = 31 queries no page load.

**Por que ocorre:**
O padrão `->with(['latestMetrics'])` carrega apenas a última métrica (eager load de relacionamento). Para soma mensal, não há relacionamento pré-definido — a tendência é fazer a query dentro do `->map()`.

**Consequências:**
- Page load lento (30+ queries para dados simples)
- O bug N+1 já existe em `MlbController` (documentado em CONCERNS.md) — este módulo pode reproduzi-lo

**Prevenção:**
Usar uma única query agregada antes do map:

```php
// UMA query para todos os faturamentos do mês
$faturamentos = AdmanMetric::selectRaw('company_id, SUM(revenue) as total_mes, MAX(reference_date) as ultimo_dado')
    ->whereBetween('reference_date', [$inicioMes, $fimMes])
    ->groupBy('company_id')
    ->get()
    ->keyBy('company_id');

// Depois: $faturamentos[$company->id]?->total_mes ?? null
```

**Sinal de alerta:**
Query dentro de `->map()` que usa `company_id` como filtro — extrair para query prévia com `groupBy`.

**Fase:** ADM-02 — revisar o controller antes do code review da fase.

---

### Armadilha 8: Campos de contrato (`tipo_servico`, `contrato_inicio`, `contrato_fim`) adicionados sem migration cuidadosa

**O que dá errado:**
`Company` não tem os campos `tipo_servico` (POLO/Assessoria/Incubadora), `contrato_inicio` e `contrato_fim`. Esses campos são adicionados via nova migration — mas se o `fillable` do model não for atualizado e o `activitylog` não incluir esses campos, há dois problemas: (1) mass assignment silenciosamente descarta os valores, (2) o activity log não rastreia mudanças nesses campos sensíveis.

**Por que ocorre:**
`Company::$fillable` atual não inclui os campos novos. O `getActivitylogOptions()` usa `->logOnly(['name', 'cnpj', 'segment', 'active', 'notes', 'adman_account_id', 'ml_store_id'])` — lista explícita, não `logAll()`.

**Consequências:**
- `Company::update(['tipo_servico' => 'POLO'])` silenciosamente descarta o valor (não está no `fillable`)
- Mudanças de tipo de serviço e datas de contrato não aparecem no activity log
- Dados de contrato nunca são salvos mas nenhum erro é lançado

**Prevenção:**
1. Adicionar ao `$fillable`: `'tipo_servico', 'contrato_inicio', 'contrato_fim'`
2. Adicionar ao `->logOnly()` no `getActivitylogOptions()`: os três campos novos
3. Definir constante `TIPOS_SERVICO = ['POLO', 'Assessoria', 'Incubadora']` no model para evitar strings mágicas

**Sinal de alerta:**
Migration que adiciona colunas em `companies` sem checklist correspondente no model — verificar `fillable` e `activitylog` antes de considerar a task completa.

**Fase:** ADM-01 — incluso no plano de migration da fase.

---

### Armadilha 9: `adman_metrics` armazena dados diários, mas o sync roda a cada 5 minutos

**O que dá errado:**
O scheduler chama `adman:sync` a cada 5 minutos. Cada execução chama `syncCompany()` que usa `now()->subDay()` como data de referência. Portanto, `adman_metrics` tem **uma linha por empresa por dia** (unique constraint em `company_id + reference_date`). A soma mensal de faturamento funciona corretamente — `SUM(revenue)` sobre as linhas diárias do mês.

O problema ocorre quando o desenvolvedor assume que `adman_metrics` tem granularidade de hora/minuto e tenta usar `synced_at` para determinar "quanto faturou hoje" — `synced_at` é o timestamp do último sync, não a data de referência do dado.

**Por que ocorre:**
`synced_at` atualiza a cada sync (5 em 5 minutos), mas `reference_date` é sempre "ontem". Um registro `reference_date = 2026-05-18, synced_at = 2026-05-19 14:32` significa "dados de 18/05, sincronizados hoje às 14h32" — não "dados do dia 19/05 às 14h32".

**Consequências:**
- Soma de "faturamento do mês corrente" inclui até ontem, nunca hoje — correto, mas precisa ser comunicado
- Usar `synced_at` como data de referência para cálculo de faixa está errado

**Prevenção:**
Sempre usar `reference_date` (não `synced_at`) nos filtros de período. A query de faturamento mensal deve filtrar por `whereBetween('reference_date', [$inicioMes, $fimMes])`. Para comunicar "última atualização", exibir `MAX(synced_at)` como metadado informativo, não como dado calculado.

**Sinal de alerta:**
`whereBetween('synced_at', ...)` em qualquer query de faturamento — usar `reference_date` sempre.

**Fase:** ADM-02 — documentar no plano de fase a semântica das duas colunas de data.

---

## Armadilhas Menores

---

### Armadilha 10: Faixa máxima (acima de R$5M) sem tratamento especial na progress bar

**O que dá errado:**
A progress bar mostra "posição na faixa atual e distância para a próxima". Para a faixa máxima (acima de R$5M), não há "próxima faixa". Se o componente React tenta calcular `(faturamento / proximo_limiar) * 100` e `proximo_limiar` é `null`, o resultado é `NaN` — a barra não renderiza ou exibe 0%.

**Prevenção:**
O PHP deve passar `proximo_limiar: null` e `na_faixa_maxima: true` para o React. O componente trata esse caso mostrando "Acima de R$5M — faixa máxima" com barra em 100%.

**Fase:** ADM-04.

---

### Armadilha 11: `contrato_fim` no passado não filtra empresa como inativa

**O que dá errado:**
Uma empresa com `active = true` mas `contrato_fim = 2026-03-31` (contrato encerrado) ainda aparece na lista de fechamento com faturamento calculado. O admin pode cobrar por serviço que deveria ter sido encerrado.

**Prevenção:**
A query de fechamento deve incluir as empresas com contrato encerrado visivelmente — com badge "Contrato encerrado em dd/mm/aaaa" — não silenciosamente excluídas. A decisão de desativar a empresa é manual do admin.

**Fase:** ADM-01.

---

### Armadilha 12: Total consolidado somando empresas com dados de meses diferentes

**O que dá errado:**
Se o admin seleciona "Maio 2026" como período, mas a empresa X teve o último sync em Abril (nunca sincronizou em Maio), a query de faturamento de Maio para X retorna `null`. O total consolidado seria a soma de "Maio" para empresas que têm dados em Maio + zero contribuição das que não têm — mas sem indicação clara de qual é qual.

**Prevenção:**
O rodapé do total deve exibir "Total de N de M empresas" onde N é o número com dados no período e M é o total. Empresas sem dados no período aparecem na lista com estado "Sem dados em Maio/2026".

**Fase:** ADM-05.

---

## Mapeamento por Fase

| Fase | Armadilha Mais Provável | Mitigação Prioritária |
|------|------------------------|----------------------|
| ADM-01: Lista de empresas | Armadilha 8 — campos novos sem `fillable` e sem activity log | Checklist de migration: `fillable` + `logOnly` + constante `TIPOS_SERVICO` |
| ADM-02: Faturamento via Adman | Armadilha 1 (chamada síncrona), Armadilha 2 (dado parcial), Armadilha 3 (null), Armadilha 9 (`synced_at` vs `reference_date`) | Plano deve especificar: dados vêm de `adman_metrics`, não da API; semântica das datas; tratamento de null |
| ADM-03: Cálculo de faixas | Armadilha 4 (lógica duplicada PHP/JS), Armadilha 5 (hard-coded sem isolamento) | Constante estruturada em PHP com `ate => null` para última faixa; zero cálculo no React |
| ADM-04: Progress bar | Armadilha 10 (faixa máxima sem próximo limiar), Armadilha 4 | Props PHP já calculadas: `progresso_faixa_pct`, `falta_para_proxima`, `na_faixa_maxima` |
| ADM-05: Total consolidado | Armadilha 7 (N+1), Armadilha 3 (null no total), Armadilha 12 (períodos misturados) | Query agregada prévia; exibir "N de M empresas" no rodapé |

---

## Fontes

- Codebase — `app/Services/AdmanService.php`: `fetchPerformance()` timeout 120s, `syncCompany()` usa `subDay()`, `syncAll()` com 700ms sleep
- Codebase — `app/Models/AdmanMetric.php`: unique constraint `(company_id, reference_date)`, campos `revenue`, `synced_at`, `reference_date`
- Codebase — `app/Models/Company.php`: `$fillable` atual, `getActivitylogOptions()` com `->logOnly()`
- Codebase — `.planning/codebase/CONCERNS.md`: N+1 documentado em `MlbController::publicacoes()` e `projetos()`
- Codebase — `faturamento_adm.md`: tabela de 7 faixas com limiar aberto em R$5M
- Codebase — `.planning/phases/01-diagn-stico-adman/01-RESEARCH.md`: anti-padrões já identificados para o módulo de Diagnóstico Adman
- Codebase — `database/migrations/2026_04_26_152220_create_adman_metrics_table.php`: granularidade diária, unique em `(company_id, reference_date)`
