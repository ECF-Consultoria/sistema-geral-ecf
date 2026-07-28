# Phase 118: NPS por empresa - Research

**Pesquisado em:** 2026-07-28
**Domínio:** Leitura agregada de NPS (Laravel/Eloquent) — nenhum pacote novo, nenhuma tela nova
**Confiança:** MEDIUM (a maior parte é verificada por leitura direta do código-fonte atual; os pontos de design do serviço novo são recomendação, não código já existente)

## Summary

Esta fase não introduz tecnologia nova: é 100% Laravel/Eloquent sobre tabelas que já existem
(`nps_score_assignments`, `nps_surveys`, `nps_responses`, `nps_imputed_assignments`,
`company_users`, `bonus_invalidacoes`). O trabalho real é de **desenho de agregação**: pegar os
três ramos que hoje alimentam `DesempenhoScoreService::computeNpsMedio()` (uma média GLOBAL por
usuário) e produzir a MESMA informação agrupada por `company_id`, preservando bit-a-bit as regras
de dedupe, janela M+1 e invalidação já em produção.

A descoberta mais importante da pesquisa: **os dois ramos que a D-03 precisa (atribuições e
imputadas) já têm `company_id` E `servico_id` como colunas nativas** em `nps_score_assignments` e
`nps_imputed_assignments` — não é necessário nenhum JOIN com `nps_templates`/`serviceScopes` para
resolver "qual survey serve qual serviço" nesses dois ramos; o dado já está na linha. O ramo legado
(`->principal()`) é, por construção, service-agnostic (só existe 1 survey candidato por empresa
nesse ramo), então ele já satisfaz o fallback "consolidado" (D-03) sem nenhum trabalho extra.

O ponto mais delicado NÃO é técnico, é de coerência de produto: a D-04 desta fase **reverte
deliberadamente a D3 da Fase 116**, e a Fase 116 entregou um teste (`NpsFloorRegressaoTest.php`)
cujo propósito EXPLÍCITO é travar exatamente esse tipo de divergência entre call-sites. A pesquisa
confirma que esse teste **não quebra** com a introdução do novo serviço (ele não invoca o serviço
novo, então as 6 asserções existentes continuam válidas) — mas NPSE-06 exige que o plano ADICIONE
uma 7ª verificação a esse mesmo arquivo, provando que a divergência é INTENCIONAL e testada, não
uma omissão. Ver seção "Q7 — NPSE-06 em detalhe" abaixo — é o achado mais importante para o plano.

**Recomendação primária:** criar `App\Services\Desempenho\NpsPorEmpresaService` com um método por
ramo (réplicas enxutas de `notasPorAtribuicao`/`notasLegado`/`notasDaEmpresa`, sem tocar nos
originais — D-06), fazer o dedupe do ramo 1 em PHP (não em SQL) para poder expor `servico_id` sem
quebrar `ONLY_FULL_GROUP_BY`, e extrair a checagem "M+1 já fechou?" de `computeNpsWindow()` para um
helper compartilhado em vez de replicar a lógica de data (evita um 5º call-site da regra de janela).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01 · A `nota_empresa` usa a nota da dimensão do PAPEL do profissional naquela empresa.**
  Estrategista entra com a nota de estrategista; analista, com a de analista. **Não** se usa a
  dimensão `empresa`. `empresas_score` é, portanto, por `(user_id, company_id)`, nunca só por
  `company_id`.
- **D-02 · Profissional que acumula papéis na mesma empresa entra com a MÉDIA das dimensões que
  ele exerce.** Se João é estrategista e analista da empresa X (4,8 e 3,2), o NPS dele para X é
  4,0.
- **D-03 · Empresa com mais de um survey na competência usa o survey do SERVIÇO DO VÍNCULO.**
  Fallback obrigatório para o vínculo consolidado: quando `company_users.servico_id` é `NULL`, usa
  a média de TODOS os surveys da empresa na competência. Não é opcional — sem ele recria o bug de
  produção documentado em `project_nps_assignment_consolidado_gap`.
- **D-04 · Empresa da carteira sem NENHUM NPS na competência entra com nota 1**, inclusive quando
  nunca houve disparo. **Isto reverte a D3 da Fase 116** (que dizia o oposto e era decisão
  travada), reversão confirmada pelo usuário em 2026-07-28. O "1" é fallback de leitura, **não**
  materializado em `nps_imputed_assignments`.

### Claude's Discretion

- **D-05 · Assinatura e local do serviço.** `NpsPorEmpresaService::notasNpsPorEmpresa()` conforme
  NPSE-01, em `app/Services/Desempenho/` ou `app/Services/Nps/` — o planner escolhe pelo que for
  coerente com os vizinhos. O retorno deve permitir auditar a origem (quantas notas, de qual ramo,
  qual dimensão, qual survey).
- **D-06 · Esta fase não muda nenhum consumidor.** É aditiva. `DesempenhoScoreService` continua
  calculando a média agregada como hoje; quem passa a ler por empresa é a Fase 119.

### Deferred Ideas (OUT OF SCOPE)

- Alinhar a área de NPS ao fallback da D-04 (se a tela também deve mostrar 1 para empresa sem
  disparo) — fase própria, fora deste escopo.
- Materializar "sem disparo" em `nps_imputed_assignments` — deliberadamente fora; a D-04 é regra de
  leitura para o bônus, não mudança de semântica da imputação.
- Usar a dimensão `empresa` em algum lugar do bônus — descartado pela D-01.
- Corrigir o gap do responsável consolidado na origem (fazer o disparo gerar assignment para
  `servico_id NULL`) — outro escopo, registrado em `project_nps_assignment_consolidado_gap`.

### Risco registrado (do CONTEXT — decisão que o plano precisa registrar explicitamente)

A D-04 cria divergência deliberada entre bônus (empresa sem disparo = nota 1, a partir da Fase 119)
e área de NPS (Fase 116, empresa sem disparo não aparece — sem nota). O teste de coerência
`116-08` (`NpsFloorRegressaoTest.php`) existe para impedir exatamente esse tipo de divergência
silenciosa. **Recomendação do CONTEXT (adotada nesta pesquisa): opção 1 — o teste passa a tolerar
a divergência, documentando-a explicitamente, em vez de estender o fallback à área de NPS.**
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| NPSE-01 | `NpsPorEmpresaService::notasNpsPorEmpresa($user, $mesNps, $invalidadas)` retorna nota de NPS agrupada por `company_id`, com contagem e origem por ramo | Contrato confirmado no plano canônico §Fase 2 (linha 378-392); shape de retorno documentado abaixo em "Contrato de retorno recomendado" |
| NPSE-02 | Os três ramos preservados sem mudança semântica, mantendo dedupe `(response_id, role)` e `(survey_id, role)` | Ver Q1/Q2/Q4 — dedupe do ramo 1 recomendado em PHP (não SQL) para não colidir com `ONLY_FULL_GROUP_BY` ao expor `servico_id` |
| NPSE-03 | Janela M+1 preservada: mês em curso → piso 1.0; M+1 em coleta → null; M+1 encerrado sem resposta → 0.0 que vira 1.0 pelo clamp | Ver Q8 — `computeNpsWindow()` documentado linha a linha; recomendação de extrair o boundary check em vez de replicar |
| NPSE-04 | Empresa invalidada na competência não entra no NPS por empresa | `BonusInvalidacao::companyIdsInvalidadas($mes)` já usado pelos 3 ramos atuais; ver Q6/Q9 sobre a ordem de checagem em relação à D-04 |
| NPSE-05 | Empresa com Performance e Shopee não duplica NPS por serviço | Ver Q5 — `servico_id` nativo em `nps_score_assignments`/`nps_imputed_assignments` resolve isso sem JOIN em `nps_templates` |
| NPSE-06 | O teste de coerência entre call-sites da Fase 116 (`116-08`) conhece este novo call-site e continua verde | Ver "Q7 — NPSE-06 em detalhe" — a mudança mínima está detalhada com nome de arquivo, método e asserção exata |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Tier Primário | Tier Secundário | Racional |
|------------|---------------|-------------------|----------|
| Leitura/agregação de nota de NPS por empresa | API/Backend (Service Layer) | Database (Eloquent/query builder) | É puramente cálculo de domínio sobre dados já persistidos — não há UI, não há endpoint HTTP novo nesta fase (D-06) |
| Resolução do universo de empresas da carteira | API/Backend (`CarteiraContextService`) | Database (`company_users`, `contratos_servico`) | Fonte única já estabelecida na v17.0 — não deve ser reimplementada |
| Resolução de invalidação por competência | API/Backend (`BonusInvalidacao`) | Database (`bonus_invalidacoes`) | Já usado pelos 3 ramos atuais; o novo serviço só precisa REUSAR, não recalcular |
| Teste de coerência entre call-sites | Testing (PHPUnit/Feature) | — | Vive em `tests/Feature/Phase116/`, fora do tier de produção, mas é o gate de aceite da fase |

## Standard Stack

Nenhum pacote novo. A fase usa exclusivamente:

| Componente | Já em uso | Papel nesta fase |
|------------|-----------|-------------------|
| Eloquent / Query Builder (`illuminate/database`, já no `composer.lock`) | Sim | Consultas aos 3 ramos e ao universo de carteira |
| `Illuminate\Support\Collection` | Sim | Agregação em memória (dedupe/merge/groupBy) — mesmo padrão de `NpsImputationService` |
| `Carbon` | Sim | Resolução de janela/competência |
| PHPUnit 11 (`phpunit/phpunit ^11.5.50`) | Sim | Teste de coerência + testes novos do serviço |

**Installation:** nenhuma — não há `composer require`/`npm install` nesta fase.

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote novo (backend puro, Eloquent + Collections
já disponíveis). Nenhuma linha de `composer.json`/`package.json` muda.

## Architecture Patterns

### Diagrama de fluxo de dados

```
                     ┌─────────────────────────────────────────┐
                     │   CarteiraContextService::forUser()      │
                     │  (universo: company_id × servico_id ×    │
                     │   role, por vínculo — NÃO colapsado)      │
                     └───────────────────┬───────────────────────┘
                                         │
                                         ▼
┌───────────────────────────────────────────────────────────────────────┐
│                  NpsPorEmpresaService::notasNpsPorEmpresa()             │
│                                                                         │
│  1) BonusInvalidacao::companyIdsInvalidadas($mes) ──► exclui empresa   │
│                                                         inteira         │
│                                                                         │
│  2) Ramo 1 — notasPorAtribuicaoPorEmpresa()                            │
│     nps_score_assignments (já tem company_id + servico_id nativos)     │
│     JOIN nps_responses/nps_surveys (status=completed, janela, invál.)  │
│     dedupe (response_id, role) EM PHP ──► preserva servico_id por nota │
│                                                                         │
│  3) Ramo 2 — notasLegadoPorEmpresa()                                   │
│     NpsSurvey::principal() WHERE company_id IN (...)                   │
│     skip quando papel já coberto por atribuição (mesma régua atual)    │
│     agrupa por survey->company_id (sem servico_id — ramo é agnóstico)  │
│                                                                         │
│  4) Ramo 3 — NpsImputationService::notasDoUsuario()                    │
│     JÁ retorna company_id + servico_id + role — só reagrupar           │
│                                                                         │
│  5) Merge dos 3 ramos ──► GROUP BY (company_id, role, servico_id)      │
│     resolvendo D-03 (servico do vínculo vs. consolidado NULL)           │
│                                                                         │
│  6) GROUP BY company_id colapsando roles (D-02 — média dos papéis)     │
│                                                                         │
│  7) Para cada vínculo do universo (passo 0) sem nota no passo 6 e      │
│     NÃO invalidado (passo 1) ──► fallback nota = 1.0 (D-04)            │
│                                                                         │
│  ▼                                                                     │
│  Collection<company_id, {nota, total_notas, fontes, origem}>           │
└───────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
                    (consumido pela Fase 119 — NÃO nesta fase, D-06)
```

### Recommended Project Structure

```
app/Services/Desempenho/
└── NpsPorEmpresaService.php     # novo — D-05, local recomendado (ver racional abaixo)

tests/Feature/Phase118/
├── NpsPorEmpresaAtribuicaoTest.php   # ramo 1 (NPSE-02)
├── NpsPorEmpresaLegadoTest.php       # ramo 2 (NPSE-02)
├── NpsPorEmpresaImputadaTest.php     # ramo 3 (NPSE-02)
├── NpsPorEmpresaJanelaTest.php       # NPSE-03 (3 casos da janela M+1)
├── NpsPorEmpresaInvalidacaoTest.php  # NPSE-04
├── NpsPorEmpresaMultiServicoTest.php # NPSE-05 (D-03, Performance+Shopee)
└── NpsPorEmpresaSemDisparoTest.php   # D-04 isolado

tests/Feature/Phase116/
└── NpsFloorRegressaoTest.php    # MODIFICADO — 1 método novo (ver "Q7" abaixo), 0 métodos removidos
```

**Racional do local (`app/Services/Desempenho/` vs `app/Services/Nps/`):** o plano canônico
(`plano-implementacao-desempenho-por-empresa.md` §Fase 2, linha 373) já sugere
`app\Services\Desempenho\NpsPorEmpresaService.php` — e a Fase 3 do mesmo plano (score por empresa)
também propõe `app\Services\Desempenho\CompanyScoreService.php` como vizinho direto. Colocar os
dois em `Desempenho/` deixa claro que ambos existem para alimentar o motor de bônus (consumidor),
não a área de NPS (que já tem seus próprios services em `app/Services/Nps/`). `NpsImputationService`
continua em `Nps/` porque ele é a fonte de verdade da REGRA de imputação (usada tanto pela área NPS
quanto pelo bônus); `NpsPorEmpresaService` é puramente um AGREGADOR para o bônus, papel diferente.

### Pattern 1 — Dedupe em PHP em vez de SQL (ramo 1)

**O quê:** em vez de `groupBy()`/`selectRaw()` no SQL (como faz `notasPorAtribuicao()` hoje),
buscar as linhas cruas de `nps_score_assignments` (uma por combinação response×role×servico) e
fazer o dedupe com `Collection::groupBy()`/`->map()` em PHP.

**Quando usar:** sempre que o dedupe precisar preservar uma coluna (aqui, `servico_id`) que NÃO é
funcionalmente determinística dentro do grupo de dedupe (um mesmo `(response_id, role)` pode ter
1 linha por serviço coberto, cada uma com `servico_id` diferente e `average_score` idêntico —
"Ajuste 3", ver docblock de `notasPorAtribuicao()` linha 870-879).

**Por que SQL puro não serve aqui:** `GROUP BY nps_response_id, role` no MariaDB
(`ONLY_FULL_GROUP_BY`, modo padrão) rejeita selecionar `servico_id` sem agregá-lo — e agregar com
`MAX(servico_id)` (o mesmo truque já usado para `average_score`) descartaria silenciosamente todos
os `servico_id` menos um, exatamente o dado que a D-03 precisa para saber "esta nota é do survey de
qual serviço".

**Exemplo (baseado no padrão real de `notasPorAtribuicao()` e `notasDoUsuario()`):**
```php
// Fonte: app/Services/DesempenhoScoreService.php:883-911 (adaptado — NÃO modifica o original, D-06)
$linhasCruas = NpsScoreAssignment::query()
    ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
    ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
    ->where('nps_score_assignments.user_id', $user->id)
    ->where('s.status', 'completed')
    ->whereBetween('s.completed_at', [$inicio, $fim])
    ->whereNull('r.invalidated_at')
    ->when($invalidadas->isNotEmpty(), fn ($q) => $q->whereNotIn('s.company_id', $invalidadas->all()))
    ->select(
        'nps_score_assignments.nps_response_id',
        'nps_score_assignments.role',
        'nps_score_assignments.company_id',   // já nativo na tabela — sem JOIN extra
        'nps_score_assignments.servico_id',   // idem
        'nps_score_assignments.average_score',
    )
    ->get();

// Dedupe em PHP — preserva servico_id de CADA linha (não colapsa antes da hora).
$notas = $linhasCruas
    ->groupBy(fn ($row) => $row->nps_response_id . '|' . $row->role)
    ->map(fn ($grupo) => (object) [
        'company_id'    => (int) $grupo->first()->company_id,   // 1:1 com response_id — seguro
        'role'          => $grupo->first()->role,
        'servico_ids'   => $grupo->pluck('servico_id')->unique()->values(),
        'average_score' => (float) $grupo->max('average_score'), // mesmo critério de hoje
    ])
    ->values();
```

### Anti-Patterns to Avoid

- **Não reescrever `notasPorAtribuicao()`/`notasLegado()`/`notasImputadas()` em
  `DesempenhoScoreService`.** São `private`, e D-06 exige zero mudança de comportamento nesse
  service. O novo serviço tem suas PRÓPRIAS queries equivalentes.
- **Não usar `MAX(servico_id)` para "resolver" qual serviço uma nota deduped pertence.** Descarta
  dado silenciosamente (ver Pattern 1 acima).
- **Não usar `$user->companies()` como universo de empresas.** É a carteira CONSOLIDADA legada —
  colapsa 2 vínculos de serviço da mesma empresa em 1, exatamente o bug que `CarteiraContextService`
  existe para evitar (ver docblock da Fase 88). O universo correto é
  `CarteiraContextService::forUser($user, ['active' => true])`.
- **Não recalcular a checagem "M+1 já fechou?" com lógica própria.** Já existe UM bug de boundary
  documentado (`computeNpsWindow()` usa `gte`; `NpsImputationService::materializar()` usa `gt`,
  DELIBERADAMENTE diferente — ver linha 122-126 de `NpsImputationService.php`). Uma 3ª implementação
  desta checagem é um 3º lugar para esse bug se repetir. Extrair, não replicar (ver Q8).

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|----------|----------------|---------------------|---------|
| Universo de empresas da carteira por serviço | Query direta em `company_users` | `CarteiraContextService::forUser()` | Único ponto que já resolve o ramo legado `servico_id NULL` + contrato ativo (CTX-05) |
| Empresas invalidadas na competência | Query direta em `bonus_invalidacoes` | `BonusInvalidacao::companyIdsInvalidadas($mes)` | Já é o método usado pelos 3 ramos atuais — reusar garante a MESMA definição de "invalidada" |
| Notas imputadas (ramo 3) | Query direta em `nps_imputed_assignments` | `NpsImputationService::notasDoUsuario()` (já filtra `vigentes()`, dedupe `(survey_id, role)` e devolve `company_id`) | É a "ÚNICA fonte de verdade" declarada no próprio docblock da classe — reimplementar diverge silenciosamente da regra de "provisório/definitivo" |
| Resolução de servico → survey via template | JOIN manual com `nps_templates`/`nps_template_service_scopes` | `servico_id` nativo de `nps_score_assignments`/`nps_imputed_assignments` | O dado já está na linha (materializado por `NpsSnapshotService`/`NpsImputationService` no momento da atribuição) — resolver via template é redundante e mais lento |

**Key insight:** quase todo "Don't Hand-Roll" desta fase é "não reimplemente o que a Fase 79/88/116
já resolveu — reuse os serviços/scopes existentes". O único código genuinamente NOVO é a camada de
agrupamento por `company_id` e o fallback D-04.

## Common Pitfalls

### Pitfall 1 — Dupla contagem de vínculo (servico_id diferente, mesmo role, mesma empresa)

**O que dá errado:** `CarteiraContextService::forUser()` NÃO colapsa vínculos por design (é o
contrário do bug que ela corrige — ver `contadores()` docblock: "Deliberadamente NÃO colapsa
vínculos"). Um profissional com 2 linhas em `company_users` para a MESMA empresa e o MESMO `role`
(ex.: analista de Performance E analista de Shopee da empresa X) aparece 2× no universo.

**Por que acontece:** a memória `project_company_users_multi_linha_servico` confirma que desde a
Fase 76 `company_users` tem várias linhas por (empresa, role) — uma por serviço.

**Como evitar:** `nota_empresa` é por `(user_id, company_id)` — nunca por `(user_id, company_id,
servico_id)`. Ao montar o universo para D-04 (detectar empresa sem nota), DEDUPLICAR por
`company_id` ANTES de checar "tem nota?" — senão a mesma empresa pode ser avaliada 2× (uma vez por
vínculo) e, em teoria, gerar 2 fallbacks 1.0 que colidem/sobrescrevem-se sem erro visível.

**Caso não coberto explicitamente por D-01/D-02:** os exemplos do CONTEXT (`<specifics>`) só
cobrem colisão de ROLE (estrategista + analista). Não há exemplo para colisão de SERVIÇO com o
MESMO role (2 vínculos analista, servicos diferentes, mesma empresa). Sinalizado como Open Question
abaixo — não é possível responder com evidência de código porque não há teste/exemplo do CONTEXT
que resolva isso; é uma decisão de produto que falta.

### Pitfall 2 — Gap do responsável consolidado mascarado pelo fallback D-04

**O que dá errado:** a memória `project_nps_assignment_consolidado_gap` documenta que respostas de
NPS de responsável consolidado (`company_users.servico_id NULL`) podem existir SEM gerar linha de
atribuição, por um bug histórico (corrigido na ORIGEM em 2026-07-22 via
`responsavelDoServicoOuConsolidado()`, mas com backfill de bônus ainda pendente para competências
anteriores a essa data). Se o novo serviço detecta "zero notas para este (user, company)" e aplica
D-04 cegamente, uma competência ANTIGA onde a resposta EXISTE mas a atribuição falhou por esse bug
histórico vira nota 1 (fallback "sem disparo") quando na verdade HOUVE disparo e resposta — só não
gerou atribuição.

**Como evitar:** antes de aplicar o fallback D-04, distinguir "não existe NENHUM survey
`completed`/`pending` da empresa na janela" (fallback correto, D-04 genuíno) de "existe survey
`completed` da empresa na janela mas nenhuma das 3 fontes gerou nota para ESTE usuário" (gap de
atribuição — mesmo padrão do C3 da Fase 116: "distinguir pela existência do disparo/survey, não
pela ausência de assignment"). Recomendação: logar (`Log::warning`, mesmo padrão de
`NpsImputationService::materializar()` linha 185-191) quando o segundo caso ocorrer, em vez de
aplicar 1.0 silenciosamente — para não confundir dois bugs de naturezas diferentes sob o mesmo
número.

### Pitfall 3 — D-04 aplicado a empresa invalidada

**O que dá errado:** se a checagem de invalidação (`BonusInvalidacao::companyIdsInvalidadas`) não
rodar ANTES da checagem "empresa sem nota → 1.0", uma empresa invalidada na competência (que já
deveria estar 100% fora do NPS, per NPSE-04/D5 da Fase 116) receberia nota 1 pelo fallback D-04 —
exatamente o oposto do que a invalidação existe para fazer (excluir a empresa da conta, não
puni-la com o piso).

**Como evitar:** ordem de operações fixa — (1) resolver `$invalidadas`; (2) filtrar o universo de
empresas removendo as invalidadas; (3) só ENTÃO aplicar D-04 sobre o universo já filtrado. Mesma
ordem que `compute()` já usa hoje (linha 416-421) para os outros 3 componentes.

### Pitfall 4 — Replicar a checagem "M+1 já fechou?" com o boundary errado

**O que dá errado:** existem DUAS implementações desta checagem hoje, DELIBERADAMENTE diferentes:
`computeNpsWindow()` usa `now()->startOfDay()->gte(...)` (linha 769); `NpsImputationService::
materializar()` usa `now()->startOfDay()->gt(...)` (linha 127) — a diferença de 1 dia é proposital
(ver comentário linha 121-126: o link do NPS vale até 23:59:59 do último dia). Uma 3ª implementação
que copie a lógica errada (ou que misture os dois `gte`/`gt`) reproduz o "BLOCKER 1" já documentado
no docblock de `computeNpsWindow()` (linha 735-743): comparar por timestamp cru faz TODA competência
com 0 NPS cair no ramo errado no exato instante em que o cron `desempenho:consolidar-mes` roda.

**Como evitar:** extrair a checagem para um helper único (ver Q8) reutilizado pelos DOIS
call-sites existentes E pelo novo — não copiar a expressão de data.

## Code Examples

### Ramo 2 (legado) — adição mínima de `company_id`

```php
// Fonte: app/Services/DesempenhoScoreService.php:953-1037 (adaptado — cópia no serviço novo, D-06)
foreach ($surveys as $survey) {
    $response = $survey->response;
    if ($response === null) {
        continue;
    }
    if ($cobertasNoPapel->has($response->id)) {
        continue; // preserva o skip que garante a disjunção da união (NPSE-02)
    }

    $nota = $this->npsCalculator->compute($response, $dim);
    // ... fallback legacy igual ao original ...

    if ($nota !== null) {
        $notas->push((object) [
            'company_id' => $survey->company_id, // ÚNICA adição — já estava disponível, só não era retornado
            'role'       => $papelDaDimensao,
            'nota'       => $nota,
        ]);
    }
}
```

### Ramo 3 (imputadas) — já pronto, só reagrupar

```php
// NpsImputationService::notasDoUsuario() já devolve company_id, role, servico_id, nota (linha 279-286)
// Nenhuma mudança na fonte — o serviço novo só faz ->groupBy('company_id') sobre o retorno.
$notasImputadas = $this->imputationService->notasDoUsuario($user, $inicio, $fim, $invalidadas);
```

### D-03 — resolvendo servico do vínculo vs. consolidado

```php
// Vínculo vem de CarteiraContextService::forUser() — servico_id pode ser null (consolidado).
foreach ($vinculosDoUsuario as $vinculo) {
    $companyId = $vinculo['company_id'];
    $servicoId = $vinculo['servico_id']; // null = consolidado (D-03 fallback)
    $role      = $vinculo['role'];       // 'consultor'|'estrategista'

    $notasDoRole = $notasPorCompanyERole->get($companyId . '|' . $role, collect());

    $notaResolvida = $servicoId === null
        // Consolidado: média de TODOS os surveys/servicos da empresa nesse role (D-03 fallback).
        ? $notasDoRole->avg('average_score')
        // Vínculo específico: só a(s) nota(s) cujo servico_id bate com o do vínculo.
        : $notasDoRole->filter(fn ($n) => in_array($servicoId, $n->servico_ids ?? [$n->servico_id ?? null], true))
                       ->avg('average_score');
}
```

*(Pseudocódigo de recomendação — não é código já existente no repositório. O planner deve validar
contra fixtures reais antes de fechar a implementação, especialmente o caso `servico_ids` vindo do
Pattern 1 acima vs. `servico_id` singular do ramo legado/imputado.)*

## Q1 — Ramo 1 (`notasPorAtribuicao`, linha 883): selectRaw atual e proposta

**SelectRaw atual (íntegra, `app/Services/DesempenhoScoreService.php:900-904`):**
```php
->selectRaw(
    'nps_score_assignments.nps_response_id as response_id,'
    .' nps_score_assignments.role as role,'
    .' MAX(nps_score_assignments.average_score) as average_score'
)
```
com `->groupBy('nps_score_assignments.nps_response_id', 'nps_score_assignments.role')`.

**Achado-chave:** `nps_score_assignments` JÁ TEM as colunas `company_id` e `servico_id` nativas
(migration `2026_07_14_200001_create_nps_snapshot_tables.php`, linhas 160-169) — não vêm do JOIN
com `s` (nps_surveys), são gravadas diretamente pelo `NpsSnapshotService` no momento da resposta.

**`company_id` é seguro de expor via `MAX()`** porque é funcionalmente 1:1 com `nps_response_id`
(uma resposta pertence a uma única empresa) — mesmo padrão do `MAX(average_score)` já usado hoje.
Isto NÃO quebra `ONLY_FULL_GROUP_BY` nem a dedupe.

**`servico_id` NÃO pode ser exposto por `MAX()`** porque o "Ajuste 3" (docblock linha 870-879)
descreve exatamente o caso em que múltiplas linhas do MESMO `(response_id, role)` têm `servico_id`
DIFERENTE (uma pessoa responsável por 2 serviços cobertos da MESMA resposta) — `MAX(servico_id)`
descartaria silenciosamente o(s) outro(s) serviço(s), exatamente o dado que a D-03 precisa para
saber a qual survey/serviço a nota se refere.

**Recomendação (ver Pattern 1 acima):** no serviço NOVO (não no `notasPorAtribuicao()` original —
D-06), fazer o dedupe em PHP (`Collection::groupBy()`), preservando `servico_id` como uma LISTA por
grupo (pode haver mais de um) em vez de colapsar em SQL. Isso preserva 100% a semântica de dedupe
atual `(response_id, role)` — o "1 nota por pessoa por resposta" continua valendo — só que agora a
nota carrega a informação de quais serviços ela cobre.

## Q2 — Ramo 2 (`notasLegado`, linha 953): agrupar por `survey.company_id`

O ramo 2 não usa SQL `groupBy`/`selectRaw` — é um `foreach` em PHP sobre `$surveys` (Eloquent
Collection, linha 1001-1036). `$survey->company_id` já está disponível no objeto (é coluna nativa
de `NpsSurvey`, via `whereIn('company_id', $companyIds)` na query, linha 978) — só não é incluído
no `object`/valor empurrado para `$notas`. A mudança é acrescentar `company_id` ao objeto retornado
em vez de empurrar só o float `$nota` (ver "Code Examples" acima). O skip de papel já congelado
(`$cobertasNoPapel->has($response->id)`, linha 1010-1013) precisa ser preservado EXATAMENTE — é a
única linha que garante a disjunção da união com o ramo 1 (docblock linha 1010).

**Nota sobre D-03 neste ramo:** o ramo legado não tem noção de `servico_id` — ele só existe porque
`->principal()` filtra para o ÚNICO template marcado `is_default=true`, que é model-agnostic por
construção (pré-multi-modelo v16.0). Não há "qual serviço" a resolver aqui: o ramo já é,
estruturalmente, a versão "1 survey por empresa" — o que corresponde ao caso degenerado do
fallback consolidado da D-03 (média de tudo, porque só existe 1 "tudo" possível neste ramo).

## Q3 — Assinatura real de `notasDaEmpresa()`

**Assinatura (`app/Services/Nps/NpsImputationService.php:295-302`):**
```php
public function notasDaEmpresa(
    Collection $companyIds,      // empresas a considerar
    string $dimensao,             // 'estrategista'|'analista'|'empresa' — CALLER escolhe
    Carbon $de,                   // início da janela (competência)
    Carbon $ate,                  // fim da janela
    ?Collection $invalidadas = null,  // empresas a excluir (bonus_invalidacoes)
    ?Collection $templateIds = null,  // filtro opcional por modelo(s) — usado por widgets "só principal"
): Collection
```

**O que `vigentes()` filtra** (`app/Models/NpsImputedAssignment.php:98-107`): linhas com
`status = 'definitivo'` OU (`status = 'provisorio'` E o survey relacionado AINDA NÃO está
`completed`). Protege contra o gap entre "resposta chegou" e o próximo `materializarLote()` limpar
a linha provisória órfã.

**Shape do retorno** (linha 319-330): `Collection<object{survey_id, company_id, role, service_setor,
competencia_nps, nota}>`, dedupe por `unique('survey_id')` (**NÃO** por `(survey_id, role)`, ao
contrário de `notasDoUsuario()` — porque a query já filtrou por UMA `$dimensao` específica, então
só existe 1 `role` possível no resultado).

**Ela NÃO resolve a dimensão pelo papel do vínculo (D-01) automaticamente.** O CALLER (o serviço
novo) precisa passar `$dimensao` explicitamente, derivada do `role` do vínculo (`company_users.role`
via `CarteiraContextService`) usando o MESMO mapa que `NpsImputationService::DIMENSAO_ROLE` já usa
ao contrário (linha 67-70): `role='consultor' → dimensao='analista'`, `role='estrategista' →
dimensao='estrategista'`. Este mapa não está exposto publicamente na classe (é `private const`) —
o serviço novo precisa reproduzir esse mapeamento (2 linhas) ou propor tornar a constante `public`.

**Nota:** `notasDaEmpresa()` é útil para consumidores que já sabem a dimensão de antemão (ex.: um
dashboard fixo "média de analistas"). Para `NpsPorEmpresaService`, que soma TODAS as áreas por
usuário (D-01 é por papel do profissional, e um usuário só tem 1 dimensão ativa POR VÍNCULO — mas
pode ter vínculos de papéis diferentes em empresas diferentes, ou no MESMO, D-02), pode ser mais
direto usar `notasDoUsuario()` (que já filtra por `user_id`, ver Q anterior) em vez de
`notasDaEmpresa()` (que filtra por dimensão fixa) — a escolha entre as duas é uma decisão de design
que o plano deve registrar explicitamente, com o trade-off: `notasDoUsuario()` é mais direto para
"a nota deste usuário nesta empresa"; `notasDaEmpresa()` seria necessário só se o serviço precisar
também da nota de OUTROS usuários na mesma empresa (não é o caso da assinatura de NPSE-01, que é
por `$user`).

## Q4 — D-02 (média dos papéis): onde aplicar, ordem correta

A sequência correta tem 3 estágios, NÃO 2:

1. **Dedupe intra-ramo** (preserva NPSE-02): ramo 1 por `(response_id, role)`; ramo 3 por
   `(survey_id, role)`; ramo 2 não precisa (já é 1 nota por resposta, sem duplicação estrutural).
   Produz: 1 nota por (ramo, response/survey, role).
2. **Merge dos 3 ramos + resolução D-03** por `(company_id, role)`: dentro de um mesmo papel
   (ex.: "analista" da empresa X), pode haver MAIS de uma nota no mês (múltiplos surveys/serviços)
   — aqui entra a lógica de D-03 (vínculo específico filtra por `servico_id`; consolidado tira a
   média de tudo). Produz: 1 nota por `(company_id, role)`.
3. **Colapso D-02** por `company_id`, tirando a média das notas de TODOS os papéis que o MESMO
   usuário exerce naquela empresa (estrategista + analista, se ambos existirem). Produz: 1 nota
   final por `company_id` (nota_empresa daquele usuário).

**Risco de ordem errada:** se o estágio 3 (D-02) rodar ANTES do estágio 1 (dedupe intra-ramo), uma
resposta com 2 serviços cobertos (ex.: Gestão + Mentoria, mesmo `role`, "Ajuste 3") entraria 2× na
média de papéis ANTES de ser colapsada para 1 nota por papel — inflando o peso daquela resposta
especificamente na média do usuário para aquela empresa, silenciosamente (sem erro, sem exceção —
exatamente o tipo de bug que o docblock de `computeNpsMedio()` alerta na linha 793-799: "uma
resposta contada 2× infla o bônus em silêncio").

## Q5 — D-03: cadeia serviço → survey e o fallback consolidado

**Cadeia teórica via template** (para quando NÃO há `servico_id` direto disponível — ver abaixo
por que raramente é necessário): `nps_surveys.template_id` → `NpsTemplate::serviceScopes()`
(`BelongsToMany` via pivot `nps_template_service_scopes`, `app/Models/NpsTemplate.php:129-132`) →
`Servico`. Query equivalente:
```php
NpsSurvey::where('company_id', $companyId)
    ->whereHas('template.serviceScopes', fn ($q) => $q->where('servicos.id', $servicoId))
    ->whereBetween('completed_at', [$inicio, $fim])
    ->where('status', 'completed')
    ->get();
```

**Por que raramente é necessário:** para os ramos 1 e 3 (os únicos onde D-03 realmente importa —
ramo 2 é service-agnostic, ver Q2), o `servico_id` JÁ ESTÁ gravado diretamente na linha
(`nps_score_assignments.servico_id`, `nps_imputed_assignments.servico_id`) — populado no momento da
materialização, iterando `$survey->template->serviceScopes()->get()` UMA VEZ (ver
`NpsImputationService::materializar()` linha 159-160 e `materializarLinha()` loop). Ou seja, o
trabalho de "resolver serviço → survey via template" já foi feito e persistido; o serviço novo só
precisa LER a coluna, não recalcular a cadeia.

**Query real para D-03 (dado o vínculo `(company_id, servico_id)`):**
```php
// servico_id NÃO NULL (vínculo específico) — filtra pela coluna nativa.
$notasDoServico = $todasAsNotasDaEmpresa->filter(fn ($n) => $n->servico_id === $servicoId);

// servico_id NULL (consolidado, D-03 fallback obrigatório) — média de TUDO da empresa no mês.
$notasConsolidado = $todasAsNotasDaEmpresa; // sem filtro de servico_id
$notaFinal = $notasConsolidado->avg('nota');
```

**Exemplo do CONTEXT confirmado pela estrutura de dados:** empresa Y com survey Performance
(servico_id=6, nota 4,6) e survey Shopee (servico_id=9, nota 3,0) — responsável com vínculo
`servico_id=6` filtra e pega 4,6; vínculo `servico_id=9` filtra e pega 3,0; vínculo consolidado
(`servico_id=NULL` em `company_users`) tira a média das duas notas = 3,8. A estrutura de dados
(coluna `servico_id` nativa nas 2 tabelas relevantes) suporta esse exemplo sem nenhuma tabela nova.

## Q6 — D-04: onde detectar "sem disparo" e onde entra o fallback 1.0

**Universo:** `CarteiraContextService::forUser($user, ['active' => true])` — é a fonte
explicitamente citada no `<code_context>` do CONTEXT.md ("é ali que a D-03 resolve serviço→survey e
detecta o vínculo consolidado"), e é o MESMO método que `DesempenhoScoreService::computeUniverso()`
já usa para o universo financeiro (linha 630) — reusar aqui é consistente com o resto do service.

**Detecção:** para cada `company_id` único do universo (deduplicado — ver Pitfall 1), após rodar os
estágios 1-3 da Q4, se NENHUMA nota resultou (a empresa não aparece na `Collection` agregada do
passo 3), E a empresa NÃO está em `$invalidadas` (checagem ANTES, ver Pitfall 3), então
`nota_empresa = 1.0`, com origem marcada distintamente (ex.: `'sem_disparo'`) — DIFERENTE da origem
`'imputada'` do ramo 3 (que representa "disparou e não respondeu"). Essa distinção de origem é o
que a Fase 121 (ROLL-01, "maior causa do delta") vai precisar para explicar por que uma nota mudou.

**Confirmação de que D-04 não exige gravação:** correto — nenhuma linha nova em
`nps_imputed_assignments`. A tabela é populada exclusivamente por `NpsImputationService`
(model tem comentário explícito "única fonte de escrita — NUNCA criar/editar linha fora dele",
`NpsImputedAssignment.php:19`). O fallback 1.0 de D-04 é computado in-memory dentro de
`NpsPorEmpresaService`, nunca persistido — exatamente como o CONTEXT exige ("materializar
contradiria a D3 da Fase 116 no nível do dado").

## Q7 — NPSE-06 em detalhe (a pergunta mais importante para o plano)

**Arquivo:** `tests/Feature/Phase116/NpsFloorRegressaoTest.php` (7 métodos de teste, 92 asserções,
criado no Plan 08 da Fase 116, commit `111077fc`).

**O que ele assere:** 1 cenário-base (`montarCenarioBase()` — 1 empresa, 1 analista + 1
estrategista, 1 resposta real nota 5, 1 survey não respondido no MESMO mês) verificado em 6
consumidores (área NPS, bônus, carteira, ranking, página da empresa, meta de NPS) — todos devem
refletir a nota 3,0 (média de 5 real + 1 imputada), SEM nenhum ignorar o não respondido. Um SEGUNDO
cenário (`montarCenarioVazio()` — mesma empresa/pessoas, ZERO surveys) é verificado nos MESMOS 6
consumidores para provar o invariante D3 da Fase 116 ("empresa sem disparo nunca vira nota 1"):
todos retornam sentinela de vazio (0.0 no bônus/ranking, `null` na carteira/página da
empresa/meta, `0`/média zero na área NPS) — método
`test_cenario_espelho_sem_survey_preserva_sentinela_em_todos_consumidores()` (linhas 454-489).

**A D-04 quebra este teste?** NÃO, literalmente — o arquivo não invoca nenhum código da Fase 118
(que ainda não existe) e nenhum dos 6 consumidores testados foi alterado por esta fase (D-06). As
92 asserções existentes continuam 100% válidas e verdes sem nenhuma mudança neste arquivo.

**Mas NPSE-06 exige mais do que "não quebrar" — exige que o teste "conheça" o novo call-site.**
Deixar o arquivo como está (0 menções ao NPSE-01) NÃO satisfaz NPSE-06: o requirement pede
explicitamente que o teste de coerência "conheça este novo call-site e continue verde" — ou seja,
o novo call-site precisa aparecer NO ARQUIVO, com uma asserção que prove que a divergência
D-04-vs-D3 é INTENCIONAL, não uma omissão que alguém vai "corrigir" no futuro achando que é bug
(risco explícito no `<risks>` do CONTEXT).

**Mudança mínima recomendada (não quebra nenhuma asserção existente):**
1. Adicionar 1 método de teste novo neste MESMO arquivo (ou uma classe irmã no mesmo diretório,
   mas o molde de `NpsInvalidacaoCallSitesTest`/`NpsFloorRegressaoTest` sugere manter tudo em 1
   arquivo — "checklist executável"), reaproveitando `montarCenarioVazio()`:
   ```php
   #[Test]
   public function test_nps_por_empresa_diverge_deliberadamente_do_d3_no_cenario_vazio(): void
   {
       $cenario = $this->montarCenarioVazio();

       // Fase 118 (D-04, REVERTE a D3 desta fase) — diferente dos 6 consumidores acima
       // (que preservam a sentinela 0.0/null), o serviço por-empresa do BÔNUS
       // trata "sem disparo" como nota 1. Divergência APROVADA e documentada em
       // 118-CONTEXT.md <risks> — opção 1: bônus e área de NPS respondem
       // perguntas diferentes ("quanto vale a empresa pro bônus" x "o que o
       // cliente respondeu"). Este teste existe para que NINGUÉM "corrija" essa
       // divergência no futuro achando que é a mesma regressão do Pitfall 4/96.
       $service = app(\App\Services\Desempenho\NpsPorEmpresaService::class);
       $notas   = $service->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-07-01'), collect());

       $this->assertEquals(1.0, $notas->get($cenario['empresa']->id)['pontos'] ?? $notas->get($cenario['empresa']->id)['nota']);
   }
   ```
2. Atualizar o docblock da classe (linhas 28-65) para listar este 7º consumidor explicitamente
   como EXCEÇÃO conhecida, com referência a `118-CONTEXT.md`.
3. NÃO alterar nenhum dos 6 métodos existentes — eles continuam provando que os OUTROS
   consumidores preservam D3 (a decisão do CONTEXT foi que só o bônus muda, não a área de NPS).

**Se o planner NÃO fizer isso:** NPSE-06 fica tecnicamente "não quebrado" (nenhum teste vermelho)
mas materialmente NÃO SATISFEITO (o requirement pede conhecimento explícito, não ausência). O plano
deve tratar a adição deste teste como uma tarefa de aceite obrigatória, não opcional.

## Q8 — Janela M+1 e pisos: reusar `computeNpsWindow()` ou replicar?

**Os 3 casos, linha a linha (`DesempenhoScoreService.php:748-772`):**
1. **Mês em curso** (`!$mesFechado`): retorna `1.0` IMEDIATAMENTE, sem consultar nenhum ramo (linha
   750-755) — "o NPS do mês em curso só é coletado no mês seguinte... piso 1.0 mantém o NPS na
   média desde o dia 1".
2. **Mês fechado, M+1 ainda em coleta, zero notas:** `$mesNps = $mes->addMonthNoOverflow()`;
   `computeNpsMedio() === 0.0` (sentinela vazia); checagem
   `now()->startOfDay()->gte($mesNps->endOfMonth()->startOfDay())` retorna `false` (janela ainda
   não fechou) → retorna `null` (EXCLUÍDO, não penaliza — "a competência ainda vai receber NPS").
3. **Mês fechado, M+1 encerrado, zero notas:** mesma checagem retorna `true` → retorna `0.0`
   (PENALIZA — decisão da diretoria), que o CALLER (`compute()`, linha 532) clampa para `1.0` via
   `max(1.0, min(5.0, $nps))`.

**Reusar ou replicar?** **Recomendação: NEM reusar diretamente NEM replicar a lógica de data —
extrair o boundary check para um helper compartilhado.** Razões:
- `computeNpsWindow()` é `private` e chama `computeNpsMedio()` (GLOBAL por usuário, não por
  empresa) — não dá para reusar como está, ele não tem o shape que a Fase 118 precisa.
- Replicar a expressão `now()->startOfDay()->gte(...)` cria o "5º call-site da regra" citado na
  pergunta — e já existem 2 implementações desta checagem DELIBERADAMENTE diferentes (`gte` aqui,
  `gt` em `NpsImputationService::materializar()`, ver Pitfall 4). Um 3º ponto que copie a fórmula
  errada (ou que não saiba QUAL fórmula usar, já que existem 2 corretas para propósitos
  diferentes) é o pior resultado possível.
- A pergunta certa para o serviço novo não é "M+1 fechou?" no sentido de `materializar()` (que
  decide se uma LINHA imputada vira definitiva), é no sentido de `computeNpsWindow()` (que decide
  se o COMPONENTE de NPS do bônus é `null`/excluído ou `0.0`/penalizado) — MESMO propósito,
  MESMA fórmula (`gte`), fórmula diferente do outro caso.

**Ação recomendada para o plano:** extrair `private function janelaNpsFechada(Carbon $mesNps):
bool` (ou método `protected`/helper de classe utilitária) contendo EXATAMENTE a expressão de
`computeNpsWindow()` linha 769, e fazer `computeNpsWindow()` PASSAR A CHAMAR esse helper (mudança
mínima, comportamento idêntico, coberto pelos testes já existentes de `JanelaNpsBonusTest`) — e o
serviço novo usa o MESMO helper. Isso elimina a possibilidade de um 3º valor de boundary divergente
sem duplicar a lógica.

**Quanto ao caso 1 (mês em curso → 1.0 flat para TODAS as empresas):** a assinatura canônica do
plano (`notasNpsPorEmpresa(User $user, Carbon $mesNps, ?Collection $invalidadas)`) não recebe um
flag de "mês fechado" — sugerindo que o desenho pretendido é que o serviço SEMPRE receba `$mesNps`
já como o mês de COLETA (M+1) de uma competência FECHADA, e que o caso "mês em curso" continue
sendo tratado pelo CALLER (Fase 119/120), do MESMO jeito que `compute()` hoje decide `is_closed`
ANTES de chamar `computeNpsWindow()` (linha 436) em vez de empurrar essa decisão para dentro do
método de janela. **Isto é uma lacuna de design, não uma resposta fechada — ver Open Questions.**

## Q9 — Riscos e armadilhas específicos desta fase

Já detalhados na seção "Common Pitfalls" acima (Pitfall 1, 2, 3). Resumo de prioridade para o
plano:

1. **Pitfall 3 (invalidação × D-04) é o mais fácil de testar e o mais grave se errado** — uma
   linha de código na ordem errada transforma uma empresa PUNIDA-POR-EXCLUSÃO em
   PUNIDA-COM-NOTA-MÍNIMA, o oposto do propósito da invalidação. Teste dedicado obrigatório
   (NPSE-04 já pede isso).
2. **Pitfall 2 (gap consolidado mascarado) é o mais fácil de esquecer e o mais caro de descobrir
   depois** — não tem teste que hoje cubra "existe resposta mas falta atribuição" no contexto do
   serviço novo, porque o bug de origem já foi corrigido para daqui pra frente (só afeta dados
   HISTÓRICOS anteriores a 2026-07-22). Recomendação: pelo menos logar quando ocorrer, para não
   silenciar um sintoma de um bug já conhecido sob um número que parece "normal" (nota 1 por falta
   de disparo).
3. **Pitfall 1 (dupla contagem de vínculo por serviço) é o mais provável de gerar teste flaky** se
   o planner não deduplicar `company_id` explicitamente antes de aplicar D-04.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|--------|-------------------|
| A1 | `NpsPorEmpresaService` deve usar `notasDoUsuario()` em vez de `notasDaEmpresa()` para o ramo 3 | Q3 | Se a Fase 119/120 precisar da nota de OUTROS usuários na mesma empresa (não só o `$user` passado), a escolha erra e o serviço precisa ser refeito |
| A2 | O caso "mês em curso → 1.0 flat" deve ser tratado pelo CALLER (Fase 119/120), não dentro do serviço desta fase | Q8 | Se NPSE-03 for interpretado como "o serviço da Fase 118 tem que cobrir os 3 casos sozinho", falta um parâmetro `$mesFechado`/`is_closed` na assinatura — mudança de contrato depois de codificado |
| A3 | Colisão de vínculo (mesmo role, servicos diferentes, mesma empresa) deve ser resolvida com a MESMA régua de D-02 (média) | Pitfall 1 | Não há exemplo do CONTEXT que cubra esse caso — pode ser que o produto queira o vínculo mais recente, ou soma ponderada, não média simples |
| A4 | O local do arquivo é `app/Services/Desempenho/NpsPorEmpresaService.php` (não `app/Services/Nps/`) | Architecture Patterns | D-05 deixa a decisão a critério do planner — esta é só uma recomendação, não um fato verificado |

## Open Questions (RESOLVED)

> **Ambas resolvidas em 2026-07-28 durante o planejamento.**
>
> - **Q1 (parâmetro `$mesFechado`): SIM.** Resolvida pela **Decisão 2 do `118-01-PLAN.md`** — a assinatura espelha `computeNpsWindow()`, incluindo `$mesFechado`, para não criar uma segunda régua de janela.
> - **Q2 (colisão de vínculo por serviço — mesmo `role`, `servicos` diferentes, mesma empresa): usar a mesma régua da D-02.** Resolvida pela **Decisão 6 do `118-01-PLAN.md`** — média, coerente com o tratamento de papéis acumulados.
>
> ⚠️ Uma terceira correção, vinda do plan-check e **não** originada aqui: a asserção sugerida na §Q7 desta pesquisa (`assertEquals(1.0, ...)` reusando `montarCenarioVazio()`) **falharia** — o cenário fixa `now = 2026-07-15`, quando a janela M+1 ainda está em coleta, e o serviço devolve `null`/`janela_aberta`, não `1.0`. A Task 3 do `118-02-PLAN.md` corrige avançando o tempo e alinhando os argumentos das duas chamadas.

1. **A assinatura de `notasNpsPorEmpresa()` precisa de um parâmetro `$mesFechado`/`is_closed`
   para cobrir o caso 1 da janela M+1 (mês em curso → 1.0 flat)?**
   - O que sabemos: o contrato canônico (`plano-implementacao-desempenho-por-empresa.md`) só lista
     `(User, Carbon $mesNps, ?Collection $invalidadas)`. `computeNpsWindow()` hoje resolve o caso
     "mês em curso" ANTES de chegar em `computeNpsMedio()`.
   - O que não está claro: se NPSE-03 exige que ESTE serviço (Fase 118) cubra os 3 casos
     internamente, ou se cobre apenas os casos 2 e 3 (mês fechado) e delega o caso 1 ao chamador
     futuro (Fase 119/120, fora do escopo desta fase).
   - Recomendação: o plano deve decidir explicitamente e testar os 3 casos dentro desta fase (já
     que NPSE-03 está na lista de requirements DESTA fase, não da 119/120) — mesmo que isso
     signifique adicionar um parâmetro à assinatura canônica do plano de arquitetura.

2. **Colisão de vínculo por serviço (mesmo role, servicos diferentes, mesma empresa) — média
   simples como D-02, ou outra régua?**
   - O que sabemos: D-02 cobre colisão de ROLE (estrategista + analista). `company_users` permite
     2 linhas do mesmo role com `servico_id` diferentes.
   - O que não está claro: se esse caso é raro o bastante para ignorar (delegar para D-02 tratar
     igual) ou se merece exemplo/decisão própria.
   - Recomendação: levantar a contagem real em produção (`SELECT company_id, user_id, role, COUNT(*)
     FROM company_users GROUP BY company_id, user_id, role HAVING COUNT(*) > 1`) antes de decidir —
     se a contagem for 0 (como o caso análogo de `servico_id NULL` documentado em
     `CarteiraContextService`, que tinha 7 casos e nenhum com contrato ativo), pode ser
     documentado como "não ocorre hoje, tratado defensivamente igual a D-02" sem gastar mais
     tempo de design.

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|--------------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), atributo `#[Test]` (padrão já usado em `NpsFloorRegressaoTest.php`) |
| Config file | `phpunit.xml` (raiz do projeto) — SQLite in-memory, `RefreshDatabase` |
| Comando rápido | `php artisan test --filter=Phase118` |
| Suíte completa | `php artisan test --filter='Nps|Desempenho|Phase116|Phase118'` (evitar `php artisan test` sem filtro — ver limitação de ambiente documentada em `116-08-SUMMARY.md`, travamento por chamada HTTP real não mockada em clusters não relacionados a esta fase) |

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|--------|----------------|-----------------|--------------------------|-------------------|
| NPSE-01 | `notasNpsPorEmpresa()` retorna Collection por `company_id` com nota, contagem e origem por ramo | unit/feature | `php artisan test --filter=NpsPorEmpresaContratoTest` | ❌ Wave 0 |
| NPSE-02 | Dedupe `(response_id, role)` e `(survey_id, role)` preservados; nenhuma resposta conta 2× | feature | `php artisan test --filter=NpsPorEmpresaAtribuicaoTest` / `NpsPorEmpresaImputadaTest` | ❌ Wave 0 |
| NPSE-03 | Mês em curso → 1.0 flat; M+1 coletando → null; M+1 encerrado sem nota → 0.0/clamp 1.0 | feature | `php artisan test --filter=NpsPorEmpresaJanelaTest` | ❌ Wave 0 |
| NPSE-04 | Empresa invalidada não entra (nem nota real, nem fallback D-04) | feature | `php artisan test --filter=NpsPorEmpresaInvalidacaoTest` | ❌ Wave 0 |
| NPSE-05 | Empresa Performance+Shopee não duplica NPS por serviço (D-03) | feature | `php artisan test --filter=NpsPorEmpresaMultiServicoTest` | ❌ Wave 0 |
| NPSE-06 | `NpsFloorRegressaoTest.php` ganha o 7º método e continua 100% verde | feature | `php artisan test --filter=NpsFloorRegressaoTest` | ✅ (arquivo existe, precisa de 1 método novo) |

### Sampling Rate

- **Por commit de tarefa:** `php artisan test --filter=Phase118`
- **Por merge de wave:** `php artisan test --filter='Nps|Desempenho|Phase116|Phase118'`
- **Gate de fase:** suíte combinada verde (ou falhas pré-existentes nominalmente provadas, mesmo
  padrão usado em `116-08-SUMMARY.md`) antes de `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` — cobre NPSE-01 (shape do retorno)
- [ ] `tests/Feature/Phase118/NpsPorEmpresaAtribuicaoTest.php` — cobre NPSE-02 (ramo 1)
- [ ] `tests/Feature/Phase118/NpsPorEmpresaImputadaTest.php` — cobre NPSE-02 (ramo 3)
- [ ] `tests/Feature/Phase118/NpsPorEmpresaJanelaTest.php` — cobre NPSE-03 (3 casos)
- [ ] `tests/Feature/Phase118/NpsPorEmpresaInvalidacaoTest.php` — cobre NPSE-04
- [ ] `tests/Feature/Phase118/NpsPorEmpresaMultiServicoTest.php` — cobre NPSE-05/D-03
- [ ] 1 método novo em `tests/Feature/Phase116/NpsFloorRegressaoTest.php` — cobre NPSE-06 (ver Q7)
- Framework: nenhuma instalação nova — PHPUnit já configurado e em uso extensivo no projeto

## Sources

### Primary (HIGH confidence — leitura direta do código-fonte desta sessão)
- `app/Services/DesempenhoScoreService.php:700-1063` — `computeNpsWindow`, `computeNpsMedio`,
  `notasPorAtribuicao`, `notasLegado`, `notasImputadas`
- `app/Services/Nps/NpsImputationService.php` (arquivo completo) — `materializar`, `notasDoUsuario`,
  `notasDaEmpresa`, `surveyIdsComNotaDefinitiva`
- `app/Models/NpsImputedAssignment.php`, `app/Models/NpsScoreAssignment.php` — schema e scopes
- `app/Services/Portfolio/CarteiraContextService.php` (arquivo completo) — `forUser`, `contadores`
- `app/Models/Company.php:190-331` — `responsavelDoServicoOuConsolidado`, `contratosServico`,
  `analistaPerformance`/`estrategistaPerformance`
- `app/Models/NpsTemplate.php`, `app/Models/NpsSurvey.php` — relações `serviceScopes`/`template`
- `database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php` — confirma `company_id` e
  `servico_id` nativos em `nps_score_assignments`
- `app/Models/User.php:134-138` — `dimensaoNpsDesempenho()`
- `tests/Feature/Phase116/NpsFloorRegressaoTest.php` (arquivo completo) — teste de coerência NPSE-06
- `.planning/phases/116-.../116-08-PLAN.md`, `116-08-SUMMARY.md` — contexto e limitações de ambiente
- `plano-implementacao-desempenho-por-empresa.md` §2.6, §Fase 2/3 — contrato canônico

### Secondary (MEDIUM confidence)
- Memórias do projeto (`project_company_users_multi_linha_servico`,
  `project_nps_assignment_consolidado_gap`, `project_nps_modelo_principal`) — citadas no CLAUDE.md
  do usuário, tratadas como contexto histórico confiável mas não re-verificadas nesta sessão contra
  o banco de produção

### Tertiary (LOW confidence)
- Nenhuma — esta pesquisa não usou WebSearch/Context7 (domínio é 100% código interno do projeto,
  sem biblioteca externa envolvida)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhum pacote novo, 100% verificado por leitura de `composer.lock`/código
- Arquitetura (3 ramos, tabelas, colunas nativas): HIGH — verificado por leitura direta de models e
  migrations, não por suposição
- Desenho do serviço novo (dedupe em PHP, extração do helper de janela, local do arquivo): MEDIUM —
  são recomendações fundamentadas em padrões já existentes no código, mas não são fatos já
  implementados; o planner deve validar contra testes reais
- Pitfalls (dupla contagem, gap consolidado, invalidação): HIGH — todos rastreáveis a memórias de
  produção já documentadas e a código/comentários explícitos no repositório

**Data da pesquisa:** 2026-07-28
**Válido até:** ~30 dias (domínio estável — NPS multi-modelo não muda com frequência; revalidar se
a Fase 117 ou qualquer hotfix tocar `DesempenhoScoreService`/`NpsImputationService` antes do
planejamento desta fase começar)
