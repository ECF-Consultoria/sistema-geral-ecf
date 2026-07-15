# Phase 80: Bônus e relatórios — DesempenhoScoreService lê atribuições por serviço - Research

**Researched:** 2026-07-15
**Domain:** Régua de bônus (PHP/Laravel 12) — refactor de leitura de agregação NPS com dual-path
**Confidence:** HIGH (100% do material é código deste repositório, lido linha a linha; zero dependência externa nova)

## Summary

Esta fase NÃO precisa de biblioteca nova, pesquisa de ecossistema ou decisão de stack. É uma cirurgia num único método privado (`DesempenhoScoreService::computeNpsMedio`, :281-348) que hoje agrega NPS por *cruzamento read-time* (carteira × dimensão do cargo × `->principal()`) e precisa passar a agregar pelas **atribuições congeladas** (`nps_score_assignments`) gravadas pelo `NpsSnapshotService` na Fase 79, mantendo o caminho legado por-resposta para não reescrever bônus histórico.

Três achados alteram o planejamento e devem ser lidos antes de escrever qualquer tarefa:

1. **A tabela `nps_score_assignments` NÃO tem coluna de mês.** O mês do bônus tem que sair de um JOIN até `nps_surveys.completed_at` — a MESMA fonte que o caminho legado usa hoje. Usar `assigned_at` (parece equivalente, e hoje é) ou `survey.month_reference` (é o mês do *disparo*, não o da resposta) introduz divergência silenciosa entre os dois caminhos do dual-path. Detalhe em Pitfall 1.
2. **"Aposentar o `->principal()`" (DEC-80-D) vale só para o caminho das ATRIBUIÇÕES — o `->principal()` tem que FICAR no caminho legado.** Removê-lo do legado faz o NPS Shopee vazar para o analista de ML da mesma empresa via fallback, que é exatamente a super-atribuição que o congelamento da Fase 79 existe para impedir. Detalhe em Pitfall 2.
3. **A âncora Carlos NÃO é 3.35/`sem_bonus`.** O teste real em `tests/Feature/Phase74/DesempenhoScoreServiceTest.php:388` é `test_fixture_carlos_retorna_nota_4_08_basico` e trava `nota_final=4.08` + `faixa_bonus='basico'`. O valor 3.35 foi superado em 2026-07-09 (réguas 1-5) — o CONTEXT está desatualizado nesse ponto. Detalhe em "Correções factuais ao CONTEXT".

Confirmado o **Ajuste 3 (DEC-80-A)**: o Gustavo (analista ML + Shopee) recebe atribuições das DUAS áreas. A migration `2026_07_14_200002` Passo D linkou o NPS Padrão a TODOS os serviços ativos de setor `performance`, e a `2026_07_14_000001` fez o backfill de `company_users.servico_id` para o serviço performance de toda empresa com contrato performance ativo. Os dois lados da engrenagem estão montados — falta só o consumo.

**Primary recommendation:** reescrever `computeNpsMedio` como união por-resposta de dois conjuntos disjuntos — (A) atribuições do user no mês, deduped por `(nps_response_id, role)`, mês via JOIN em `nps_surveys.completed_at`; (B) o loop legado ATUAL intacto (com `->principal()`), pulando toda resposta que já apareceu em (A). Manter a assinatura `computeNpsMedio(User, Carbon): float` (dois testes a invocam por reflection). Bumpar o cache para v3.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Agregar NPS da pessoa no mês (bônus) | Service (`DesempenhoScoreService`) | Database (JOIN) | É a régua de bônus — fonte única; controllers só consomem o shape |
| Dedup por (resposta, role) | Database (`groupBy`) | Service (Collection) | Volume baixo (unidades por user/mês); qualquer um serve — SQL é mais explícito |
| Resolução do mês da atribuição | Database (JOIN `nps_surveys`) | — | Tem que ser a MESMA fonte do legado, senão o dual-path desalinha |
| Congelar atribuição | Service (`NpsSnapshotService`) | — | Fase 79 — **fora de escopo**, não tocar |
| Cache do bônus | Service (`computeCached`) | Redis (prod) / array (testes) | Chave versionada, TTL adaptativo — só bumpar v2→v3 |
| Exibir nota Shopee (widget/heatmap) | Controller (`PerformanceController`) | Service | Leitura de apresentação; o headline `nps.media` já vem do service |
| Preservar bônus fechado | Command (`ConsolidarMesDesempenho`) | DB (`desempenho_score_snapshots`) | Passado já persistido — não reprocessar |

## Standard Stack

**Nenhuma dependência nova.** Toda a fase usa o que já está instalado e em uso no repositório.

### Core (já presentes — nada a instalar)
| Componente | Onde | Papel nesta fase |
|-----------|------|------------------|
| `App\Models\NpsScoreAssignment` | `app/Models/NpsScoreAssignment.php` | Fonte nova da nota por pessoa [VERIFIED: leitura do arquivo] |
| `App\Services\Nps\NpsScoreCalculator` | `app/Services/Nps/NpsScoreCalculator.php` | Caminho legado (v15) — injetado no service, **manter** |
| `App\Services\DesempenhoScoreService` | `app/Services/DesempenhoScoreService.php` | Alvo do refactor |
| Eloquent `join`/`groupBy`/`selectRaw` | Laravel 12 | Query da atribuição — cross-driver SQLite/MariaDB |
| `Illuminate\Support\Facades\Cache` | `computeCached` :135-156 | Bump v2→v3 |

### Alternatives Considered
| Em vez de | Poderia usar | Tradeoff |
|-----------|--------------|----------|
| Método privado no `DesempenhoScoreService` | Service novo (`NpsAssignmentReader`) | O CONTEXT deixa à discrição. **Recomendo o método privado**: `computeNpsMedio` já é privado, tem 2 testes por reflection amarrados nele e o dual-path é ~40 linhas. Service novo só se o `PerformanceController` for reusar a mesma agregação (aí sim vale extrair) |
| Dedup via `groupBy` SQL | Dedup via `Collection::unique()` | Ambos corretos. SQL é explícito e não traz linhas redundantes; PHP casa melhor com o estilo Collection do arquivo. Volume é irrelevante (unidades) |
| Mês via `nps_surveys.completed_at` | `assigned_at` / `month_reference` | **Não é escolha — é correção.** Ver Pitfall 1 |

**Installation:** N/A — nenhum pacote novo.

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhuma dependência externa (npm/composer). Todo o código é interno ao repositório. Nenhum pacote a auditar; slopcheck não foi executado por ausência de superfície de risco.

## Project Constraints (from CLAUDE.md)

| Diretriz | Impacto nesta fase |
|----------|--------------------|
| Stack Laravel 12 + Inertia + React — sem mudança | Respeitado (zero dep nova) |
| Comentários em **pt-BR** | Todo comentário novo em pt-BR |
| Artefatos GSD em pt-BR | Este documento e os planos em pt-BR |
| **Não deployar sem autorização explícita** | Milestone v16.0 tem dev em paralelo (anunciar-ml) → confirmar caso-a-caso antes do `deploy.sh` (memória `feedback_perguntar_antes_deploy_v9`) |
| `npm run build` após mudança de frontend | Só se tocar `Performance/Dashboard.jsx` (widget/heatmap) |
| Nomenclatura: método privado `camelCase`, sem prefixo | `computeNpsMedio`, helpers privados novos seguem o padrão |
| Séparadores `// ═══ SEÇÃO ═══` em arquivos grandes | Manter o estilo do `DesempenhoScoreService` |
| GSD workflow obrigatório antes de editar | Fase entra via `/gsd:execute-phase` |

## User Constraints (from CONTEXT.md)

### Locked Decisions

**DEC-80-A — NPS da pessoa = média das atribuições dela (Ajuste 3 do usuário — LOCKED)**
- A nota de NPS de um profissional no mês = média dos `nps_score_assignments.average_score` onde `user_id` = ele, no mês de referência — **somando TODAS as áreas** (ML **e** Shopee). O Gustavo NÃO cuida só de Shopee: os NPS das empresas de Mercado Livre dele **devem** agregar na média dele. NUNCA filtrar por um único `service_setor` no cálculo do bônus.
- A `role` da atribuição (`consultor`=Analista / `estrategista`) já encoda a dimensão — não depender mais do cargo do user para escolher a dimensão.
- **Dedup:** contar 1× por (`nps_response_id`, `role`) por pessoa. Se a mesma pessoa for responsável por 2 serviços cobertos da MESMA resposta, não inflar (o spec exige "1× por papel"; recortes por setor podem usar `service_setor`).

**DEC-80-B — Dual-path POR RESPOSTA (não quebrar o bônus histórico)**
- Respostas anteriores à Fase 79 (e o mês de transição, que é misto) NÃO têm atribuição. Regra: para cada resposta no escopo do usuário no mês — **se existe atribuição para (user, resposta)** → usar `average_score` da atribuição; **senão** → cair no cálculo legado atual (carteira `company_users` × dimensão do cargo × `NpsScoreCalculator::compute`), preservando também o fallback das colunas legacy `score_*`.
- Isso mantém meses históricos idênticos e faz o mês de transição somar corretamente (respostas novas via atribuição + antigas via legado). NÃO usar corte por mês inteiro (perderia respostas pré-deploy do mês corrente).
- Preservar a semântica atual de "sem respostas → 0.0" (DESEMP-03 penaliza).

**DEC-80-C — Bump de cache**
- `computeCached` usa a chave `desempenho.compute.v2.{user}.{mês}` — bumpar para **v3** (senão bônus servido do cache antigo). Precedente: bump v1→v2 em 2026-07-13 por correção de dimensão do NPS.

**DEC-80-D — Aposentar "só o principal conta"**
- Remover a dependência do escopo `->principal()` no caminho do bônus (respostas de qualquer modelo com atribuição contam). A memória `project_nps_modelo_principal` fica **superada** nesse ponto — atualizar ao fim da fase. O `is_default` segue sendo o fallback de resolução de template (Fase 79) — não confundir os dois papéis.

**DEC-80-E — Recortes/relatórios**
- Habilitar recorte por `service_setor` (ML vs Shopee) e por `role` nas leituras que fizerem sentido (widget/ranking/telas de desempenho), sem inflar a média da pessoa (dedup do DEC-80-A).
- Preservar `desempenho_score_snapshots` já consolidados (histórico de bônus fechado) — NÃO reescrever o passado.

### Claude's Discretion
- Se o dual-path vira um método privado no `DesempenhoScoreService` ou um service novo.
- Como o `PerformanceController` (dashboardCarteira/index) e o widget consomem (podem reusar o service).
- Formato exato dos recortes por setor na UI (pode ser mínimo nesta fase).

### Deferred Ideas (OUT OF SCOPE)
- Atualizar a memória `project_nps_modelo_principal` ao fim da fase (regra "só o principal conta" superada no bônus).
- Display por-serviço no `respond()` (deferido da Fase 79) — polish.

## Correções factuais ao CONTEXT

Dois pontos do CONTEXT divergem do código real. **Não são mudanças de decisão** — são erros de referência que quebrariam o plano se copiados.

| # | CONTEXT diz | Código real | Ação |
|---|-------------|-------------|------|
| C1 | "Fixture âncora Carlos … trava `nota_final=3.35` + `faixa_bonus=sem_bonus`" | `tests/Feature/Phase74/DesempenhoScoreServiceTest.php:388` → `test_fixture_carlos_retorna_nota_4_08_basico`, assert `4.08` + `'basico'` (:415-420). O 3.35 foi superado em 2026-07-09 quando as réguas 1-5 passaram a normalizar as variações (docblock :326-352) | A âncora a preservar é **4.08 / basico**. Se o plano "consertar" para 3.35, quebra a régua aprovada pela diretoria |
| C2 | Leitores a alinhar: `PerformanceController`, `NpsController::index`, `DashboardController`, `CalculateGoalResults` | Falta o **`PortfolioController`**, que chama `computeCached` em 2 pontos (:1251 performance do profissional, :1277 comparação com pares) e agrega NPS com `->principal()` + dimensão por cargo (:1383) | Incluir `PortfolioController` no blast radius do bump de cache e na varredura de leitores |

Fora isso o CONTEXT bate 1:1 com o código (linhas :281-348, :158-238, :139-141 conferidas).

## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|---------------------|
| DEC-80-A | NPS = média das atribuições, todas as áreas, dedup 1×/(resposta, role) | Query em Pattern 2; confirmação do Ajuste 3 em "Confirmação do Ajuste 3" |
| DEC-80-B | Dual-path por resposta | Pattern 1 (união disjunta) + Pitfall 1 (mês) + Pitfall 2 (`->principal()`) |
| DEC-80-C | Cache v2 → v3 | "Cache: onde bumpar e quem consome" |
| DEC-80-D | Aposentar `->principal()` no caminho do bônus | Pitfall 2 — escopo correto da decisão |
| DEC-80-E | Recortes por setor/role; preservar snapshots | "Leitores a alinhar" + "Blast radius" |

## Architecture Patterns

### System Architecture Diagram

```
                          ┌──────────── ESCRITA (Fase 79 — NÃO TOCAR) ────────────┐
  cliente responde NPS    │                                                        │
        │                 │  NpsController::submitResponseV15                      │
        └────────────────►│    └─ DB::transaction                                  │
                          │         ├─ grava nps_response_answers (snapshot/row)   │
                          │         ├─ NpsSnapshotService::registrar($response)    │
                          │         │    ├─► nps_response_scores      (por dimensão)│
                          │         │    ├─► nps_response_covered_services         │
                          │         │    └─► nps_score_assignments    (por pessoa) │
                          │         └─ survey.update(status=completed,             │
                          │                          completed_at=now())  ◄── MÊS  │
                          └────────────────────────────────────────────────────────┘
                                                    │
                     ═══════════ FRONTEIRA DA FASE 80 (só LEITURA) ═══════════
                                                    │
                                                    ▼
        DesempenhoScoreService::compute(User, mês)
              │
              ├─ computeUniverso ──► sem carteira? ──► shape nulls (early return)
              │
              ├─ computeNpsMedio(User, mês)  ◄══════ ALVO DO REFACTOR
              │     │
              │     ├── (A) CAMINHO ATRIBUIÇÃO ──────────────────────┐
              │     │      nps_score_assignments (user_id = ele)      │
              │     │        JOIN nps_responses  (r.id = a.resp_id)   │
              │     │        JOIN nps_surveys    (s.id = r.survey_id) │
              │     │        WHERE s.completed_at ∈ mês               │  união
              │     │        GROUP BY (nps_response_id, role) ─dedup  │  disjunta
              │     │        → notas[] + responseIdsCobertos{}        │  (sem
              │     │                                                  │  interseção)
              │     └── (B) CAMINHO LEGADO (intacto) ─────────────────┤
              │            NpsSurvey ->principal()  ◄── PERMANECE      │
              │              WHERE company_id ∈ carteira ativa         │
              │              AND completed_at ∈ mês                    │
              │              SKIP se response.id ∈ responseIdsCobertos │
              │              → NpsScoreCalculator::compute(resp, dim)  │
              │              → fallback colunas legacy score_*         │
              │                                                        │
              │            notas.isEmpty() ? 0.0 : round(avg, 2) ◄─────┘
              │
              ├─ computeVarFaturamento / computeVarMargem / computeAbsenteismo  (intactos)
              └─ computeNotaFinal → classificarFaixa → promover  (intactos)
                                                    │
                    ┌───────────────────────────────┼────────────────────────────┐
                    ▼                               ▼                            ▼
        computeCached (v2→v3)              compute() DIRETO           breakdown_json
         ├ PerformanceController           ├ SnapshotDesempenhoScores  (mês fechado —
         ├ DashboardController :797        └ ConsolidarMesDesempenho    passado NÃO
         ├ PortfolioController :1251/:1277    (fecha o bônus do mês)    é reescrito)
         └ WarmDesempenhoCache (cron 8min)
```

### Pattern 1: Dual-path como união DISJUNTA por resposta (DEC-80-B)

**O quê:** dois conjuntos de notas que nunca se sobrepõem, unidos numa única `Collection` antes da média.

**Por que disjunto importa:** a média final é `$notas->avg()`. Se uma resposta entrar pelos dois caminhos, ela pesa 2× na média — o bônus infla silenciosamente. O predicado de exclusão é o que garante a disjunção.

**Predicado de exclusão (literal do DEC-80-B):** pular a resposta no legado se existe atribuição para **(este user, esta resposta)** — não "se a resposta tem qualquer atribuição". Um user sem atribuição numa resposta que atribuiu a outras pessoas continua caindo no legado, e o `->principal()` do legado é o que impede isso de virar vazamento (Pitfall 2).

**Estrutura recomendada:**

```php
private function computeNpsMedio(User $user, Carbon $mes): float
{
    $inicio = $mes->copy()->startOfMonth();
    $fim    = $mes->copy()->endOfMonth();

    $notas = collect();

    // ── (A) Caminho ATRIBUIÇÃO (Fase 79) — todas as áreas, dedup por (resposta, role) ──
    $atribuicoes = $this->notasPorAtribuicao($user, $inicio, $fim);
    $notas = $notas->merge($atribuicoes->pluck('average_score'));

    // Chave da disjunção: respostas já contabilizadas via atribuição.
    $respostasCobertas = $atribuicoes->pluck('response_id')->unique()->flip();

    // ── (B) Caminho LEGADO — inalterado, exceto o skip das já cobertas ──
    $notas = $notas->merge($this->notasLegado($user, $inicio, $fim, $respostasCobertas));

    if ($notas->isEmpty()) {
        return 0.0; // DESEMP-03 — penaliza (decisão da diretoria). NÃO retornar null.
    }

    return round($notas->avg(), 2);
}
```

**Atenção ao guard de carteira vazia:** hoje `computeNpsMedio` :293-295 faz `if ($companyIds->isEmpty()) return 0.0;` ANTES de qualquer coisa. Esse early return é inalcançável via `compute()` (o gate `computeUniverso` já retornou o shape `sem_carteira`), mas os testes chamam `computeNpsMedio` por reflection. No desenho novo o guard tem que descer para dentro do ramo legado — um user com atribuições e carteira vazia deve receber a média das atribuições, não 0.0.

### Pattern 2: Query da atribuição — mês por JOIN + dedup por groupBy

```php
/**
 * Notas atribuídas ao user no mês — 1× por (nps_response_id, role) (DEC-80-A).
 *
 * O mês vem de `nps_surveys.completed_at` — a MESMA coluna do caminho legado.
 * `nps_score_assignments` não tem coluna de mês; usar `assigned_at` desalinharia
 * os dois caminhos do dual-path num backfill futuro (ver 80-RESEARCH Pitfall 1).
 *
 * Dedup: as N linhas de um mesmo (resposta, role) vêm do MESMO nps_response_score
 * (NpsSnapshotService itera serviços dentro da dimensão), logo têm average_score
 * idêntico — MAX() é determinístico e satisfaz ONLY_FULL_GROUP_BY do MariaDB.
 *
 * @return \Illuminate\Support\Collection<int, object{response_id:int, role:string, average_score:float}>
 */
private function notasPorAtribuicao(User $user, Carbon $inicio, Carbon $fim): Collection
{
    return NpsScoreAssignment::query()
        ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
        ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
        ->where('nps_score_assignments.user_id', $user->id)
        ->where('s.status', 'completed')
        ->whereBetween('s.completed_at', [$inicio, $fim])
        ->groupBy('nps_score_assignments.nps_response_id', 'nps_score_assignments.role')
        ->selectRaw(
            'nps_score_assignments.nps_response_id as response_id,'
            .' nps_score_assignments.role as role,'
            .' MAX(nps_score_assignments.average_score) as average_score'
        )
        ->get()
        ->map(fn ($row) => (object) [
            'response_id'   => (int) $row->response_id,
            'role'          => (string) $row->role,
            'average_score' => (float) $row->average_score,
        ]);
}
```

**Notas de implementação:**
- Índice existente `nps_score_assign_user_role_idx (user_id, role)` cobre o `WHERE user_id` (migration :195) [VERIFIED: leitura da migration].
- **Não** filtrar por `service_setor` aqui — DEC-80-A proíbe explicitamente (é o Ajuste 3).
- **Não** filtrar pela carteira atual (`company_users`) — a atribuição já congelou "ele era o responsável no dia". Refiltrar por carteira viva reintroduz o acoplamento read-time que a Fase 79 eliminou.
- `average_score` é `decimal(5,2)` cast `float` — ver Pitfall 4 sobre a diferença de arredondamento vs o legado.
- Alternativa aceitável (discrição): trazer as linhas e dedupar em PHP com `->unique(fn ($a) => $a->nps_response_id.'|'.$a->role)`. Volume é de unidades por user/mês; ambos servem. O `groupBy` foi escolhido por ser explícito sobre a intenção.

### Pattern 3: Caminho legado — cirurgia mínima

O ramo (B) é **cópia literal** de :289-339 com uma única linha nova (o skip). Quanto menos ele mudar, mais barato é provar a regressão histórica.

```php
private function notasLegado(User $user, Carbon $inicio, Carbon $fim, Collection $respostasCobertas): Collection
{
    // Dimensão POR CARGO (2026-07-13) — preservada: é o que reproduz o histórico.
    $dim = $user->dimensaoNpsDesempenho();

    $companyIds = $user->companies()->where('active', true)->pluck('companies.id');
    if ($companyIds->isEmpty()) {
        return collect(); // guard movido pra cá — atribuições já foram somadas
    }

    // ->principal() PERMANECE (ver Pitfall 2): sem escopo de serviço nas respostas
    // pré-Fase 79, o modelo principal é o único proxy de "este NPS é sobre o
    // trabalho desta pessoa". Removê-lo vaza NPS Shopee pro analista de ML.
    $surveys = NpsSurvey::with('response')
        ->principal()
        ->whereIn('company_id', $companyIds)
        ->where('status', 'completed')
        ->whereBetween('completed_at', [$inicio, $fim])
        ->get();

    $notas = collect();

    foreach ($surveys as $survey) {
        $response = $survey->response;
        if ($response === null) {
            continue;
        }

        // ÚNICA linha nova no ramo legado — garante a disjunção da união.
        if ($respostasCobertas->has($response->id)) {
            continue;
        }

        $nota = $this->npsCalculator->compute($response, $dim);

        if ($nota === null) {
            $legacyField = $dim === 'estrategista' ? 'score_estrategista' : 'score_analista';
            $legacyScore = $response->{$legacyField} ?? null;
            if ($legacyScore !== null && $legacyScore > 0) {
                $nota = (float) $legacyScore;
            }
        }

        if ($nota !== null) {
            $notas->push($nota);
        }
    }

    return $notas;
}
```

### Anti-Patterns to Avoid

- **Cortar o dual-path por data ("depois do deploy X usa atribuição").** O CONTEXT proíbe (DEC-80-B) e por bom motivo: o mês de transição tem respostas dos dois tipos. O corte tem que ser POR RESPOSTA.
- **Filtrar as atribuições por `service_setor`.** Zera o Ajuste 3 — o ML do Gustavo sumiria da média dele.
- **Refiltrar as atribuições pela carteira viva.** Desfaz o congelamento: trocar o responsável hoje reescreveria o bônus de ontem.
- **Remover o `->principal()` do ramo legado.** Ver Pitfall 2.
- **Mudar a assinatura de `computeNpsMedio`.** Dois testes a invocam por reflection (`DesempenhoScoreServiceTest.php:892` e :440 para `computeNotaFinal`) — assinatura `(User, Carbon): float` é contrato de fato.
- **Rodar `desempenho:consolidar-mes` para meses passados pós-deploy.** Reescreveria bônus fechado (violação direta do DEC-80-E).

## Don't Hand-Roll

| Problema | Não construir | Usar | Por quê |
|----------|---------------|------|---------|
| Nota por dimensão de uma resposta v15 | AVG próprio sobre `nps_response_answers` | `NpsScoreCalculator::compute()` | O divisor é `question_count` do template (perguntas puladas puxam a média pra baixo), não `COUNT(answers)` — regra de negócio de 2026-07-08 (:76-82) |
| Atribuir nota → pessoa | Novo cruzamento carteira × dimensão | Ler `nps_score_assignments` | Já congelado pela Fase 79 com interseção coberto ∩ ativo + blindagem de responsável faltante |
| Mês de referência da resposta | Coluna nova / `assigned_at` / `month_reference` | JOIN `nps_surveys.completed_at` | Fonte única compartilhada com o legado — ver Pitfall 1 |
| Classificar faixa de bônus | Thresholds hardcoded | `BonusFaixa::classificar()` via `classificarFaixa()` | DESEMP-07 — régua editável pelo admin |
| Invalidação de cache | `Cache::forget` em massa | Bump da versão na chave | Padrão já estabelecido (v1→v2 em 2026-07-13); TTL cuida do lixo |

**Key insight:** a Fase 79 já resolveu o problema difícil (quem é responsável pelo quê, no dia da resposta, com serviço coberto ∩ contrato ativo). A Fase 80 é uma leitura. Qualquer lógica de atribuição reconstruída aqui é uma segunda fonte de verdade que vai divergir.

## Runtime State Inventory

Fase de refactor de leitura — sem rename/migração de dados. Categorias verificadas explicitamente:

| Categoria | Encontrado | Ação necessária |
|-----------|-----------|------------------|
| **Stored data** | `nps_score_assignments` já populada em prod (caso Decoral validado 2026-07-14: Gustavo 3.11 / Felipe 2.25). `desempenho_score_snapshots` com histórico de bônus fechado | **Nenhuma migração de dados.** A fase só LÊ atribuições e não reescreve snapshots (DEC-80-E) |
| **Live service config** | Nenhuma. Nenhum serviço externo (n8n/Datadog/Cloudflare) referencia a régua de bônus | Nenhuma — verificado por ausência de integração no `DesempenhoScoreService` |
| **OS-registered state** | 3 entradas de cron (`routes/console.php`): `desempenho:snapshot-scores` (:172), `desempenho:consolidar-mes` (:183), `desempenho:warm-cache` (:208, a cada 8min) | Nenhuma re-registração — os comandos não mudam de nome/assinatura. Mas o warm-cache repopula a chave v3 automaticamente pós-deploy (comportamento desejado) |
| **Secrets/env vars** | Nenhum. `CACHE_STORE` já configurado (Redis prod / array testes) | Nenhuma |
| **Build artifacts** | Cache de config/route/view do Laravel; bundle Vite | `php artisan config:clear` não é necessário (nada de config muda). `npm run build` **somente se** o plano tocar `Performance/Dashboard.jsx` |
| **Cache em voo (extra)** | Chaves `desempenho.compute.v2.*` no Redis de prod, TTL até 7 dias (mês fechado) | O bump p/ v3 as torna órfãs — expiram sozinhas. **Não** precisa `cache:clear`, mas ele é seguro se quiser acelerar |

## Common Pitfalls

### Pitfall 1: `nps_score_assignments` não tem coluna de mês — e a fonte errada desalinha o dual-path

**O que dá errado:** o dev procura o mês na tabela, acha `assigned_at`, e usa. Funciona nos testes e em prod hoje. Quebra silenciosamente depois.

**Por quê:** a tabela tem `assigned_at` (`timestamp`, = `now()` no submit — `NpsSnapshotService:97,188`), mas o mês do bônus é definido, no caminho legado, por `nps_surveys.completed_at` (:305-308). São colunas diferentes preenchidas em pontos diferentes do fluxo:

```php
// NpsController::submitResponseV15 — dentro da MESMA transação
app(NpsSnapshotService::class)->registrar($response);   // :718 → assigned_at = now()
$survey->update([
    'status'       => 'completed',
    'completed_at' => now(),                            // :724 → milissegundos DEPOIS
]);
```

Hoje elas são equivalentes (mesma request, mesma transação). O risco é futuro e assimétrico: **qualquer backfill/reprocessamento** de atribuições gravaria `assigned_at` = data do backfill. Aí a resposta de junho ganharia atribuição com `assigned_at` = agosto → some do bônus de junho (o legado a exclui, porque *tem* atribuição) E aparece no de agosto. Zera o mês de um analista real sem erro nenhum no log.

**Como evitar:** derivar o mês por JOIN em `nps_surveys.completed_at`, exatamente como o legado. Fonte única = os dois ramos concordam por construção sobre "em que mês esta resposta cai".

**`month_reference` é armadilha diferente e pior:** é o mês de *referência do disparo mensal* (Fase 31 D-12), não o mês da resposta — um NPS de junho respondido em julho tem `month_reference=2026-06-01` e `completed_at` em julho. Além disso é **NULL** em muitas linhas, incluindo o fixture Carlos, que zera `month_reference` de propósito para driblar o unique de dedup (`DesempenhoScoreServiceTest:266-283`). Usá-lo quebraria a âncora.

**Sinais de alerta:** teste de mês misto passa mas o de regressão histórica falha por uma resposta; ou uma nota "some" de um mês e aparece em outro.

### Pitfall 2: "Aposentar o `->principal()`" (DEC-80-D) NÃO vale para o ramo legado

**O que dá errado:** lê-se DEC-80-D como "tirar `->principal()` do `computeNpsMedio`" e remove-se o scope do único lugar onde ele aparece — a query legada (:302).

**Por quê é grave:** o ramo das atribuições já é model-agnostic por construção (a atribuição existe independente do template). O `->principal()` só existe no ramo legado, e ali ele é o **isolamento**. Cenário concreto, com dados reais de prod:

- Decoral tem NPS Padrão (performance, principal) e NPS Shopee (não-principal).
- A resposta do NPS Shopee gera atribuição para Gustavo (consultor/shopee) e Felipe (estrategista/shopee) — e para **mais ninguém** (interseção coberto ∩ ativo, `NpsSnapshotService:151-152`).
- Suponha uma analista de ML da Decoral, sem atribuição nessa resposta.
- **Com `->principal()`:** a resposta Shopee não entra na query legada dela → ela não recebe a nota. Correto.
- **Sem `->principal()`:** a resposta Shopee entra no escopo legado dela (é da carteira, é `completed`, é do mês), não tem atribuição *para ela* → cai no fallback → `NpsScoreCalculator::compute($response, 'analista')` retorna a nota da dimensão analista do NPS **Shopee** → **ela recebe uma nota de um trabalho que não é dela.** É exatamente a super-atribuição que a Fase 79 foi construída para impedir.

**Como evitar:** o escopo correto do DEC-80-D é "respostas de qualquer modelo contam **via atribuição**". Traduzido em código: o ramo (A) não filtra por template; o ramo (B) mantém `->principal()`. Documentar isso no docblock, senão a próxima pessoa "limpa" o scope.

**Sinais de alerta:** o teste de isolamento (analista ML não recebe nota Shopee) some ou nunca foi escrito. A média de um analista ML sobe sem explicação no mês em que a empresa dele ganhou Shopee.

### Pitfall 3: dupla contagem na união (a média infla sem erro)

**O que dá errado:** esquecer o skip no ramo legado, ou aplicar o predicado errado ("a resposta tem atribuição?" em vez de "**este user** tem atribuição nesta resposta?").

**Por quê:** a média é `$notas->avg()`. Uma resposta contada 2× vira peso duplo. Com poucas respostas no mês (o caso normal), o efeito é grande — e nada quebra: sem exception, sem log, só um bônus errado.

**Como evitar:** derivar `$respostasCobertas` **da mesma Collection** que produziu as notas do ramo (A) — nunca de uma segunda query (as duas podem divergir no filtro). Usar `->flip()` + `->has()` (lookup O(1) por hash) em vez de `->contains()` num loop.

**Sinais de alerta:** o teste de mês misto dá uma média mais alta que a esperada; a contagem de notas não bate com a de respostas do mês.

### Pitfall 4: arredondamento diferente entre os dois caminhos

**O que dá errado:** o teste de mês misto falha por ~0.003.

**Por quê:** o legado usa `NpsScoreCalculator::compute()`, que retorna `$soma / $nPerguntas` **sem arredondar** (:112) — ex. `14/3 = 4.666666…`. A atribuição guarda o mesmo valor numa coluna `decimal(5,2)` → o banco arredonda para `4.67`. A mesma resposta rende 4.6666… pelo legado e 4.67 pela atribuição.

**Como evitar:** é comportamento correto (a atribuição é um snapshot; a precisão de 2 casas é a decisão de schema da Fase 79) — não "consertar". Nos testes, usar `assertEqualsWithDelta` com delta ≥ 0.01 (padrão já adotado na suite Phase 74) e escolher pesos que dividam exato quando o assert precisa ser fechado.

**Sinais de alerta:** falha de teste na 3ª casa decimal com valores "quase certos".

### Pitfall 5: `->principal()` força conjunto VAZIO quando não há template principal

**O que dá errado:** um teste novo cria templates com `NpsTemplate::factory()` sem `is_default=true`, ou não chama `NpsTemplate::resetPrincipalCache()`, e o ramo legado devolve zero notas (`whereRaw('1 = 0')`, `NpsSurvey:84-86`). O dev conclui que o dual-path está quebrado.

**Como evitar:** replicar o setup da suite Phase 74 — `NpsTemplate::query()->update(['is_default' => false])` → cria o principal → **`NpsTemplate::resetPrincipalCache()`** (`DesempenhoScoreServiceTest:234-256`). O `principalId()` é cacheado em memória entre chamadas dentro do mesmo teste.

**Sinais de alerta:** o teste de regressão histórica dá `nps_medio = 0.0` em vez da média esperada.

### Pitfall 6: os testes que exercitam atribuições precisam gerar atribuições de verdade

**O que dá errado:** o cenário "Gustavo" é montado com `NpsScoreAssignment::create([...])` na mão, com dados que a Fase 79 nunca produziria (ex.: atribuição num serviço sem contrato ativo). O teste passa e não prova nada sobre o fluxo real.

**Como evitar:** para os testes de atribuição, usar o trait `CriaCenarioResponsaveis` (`tests/Feature/V16/CriaCenarioResponsaveis.php`) — ele já monta serviço + contrato + pivot com `servico_id` explícito, e tem `inserirLinhaShopee()` para o cenário ML+Shopee. Preferir passar pelo `NpsSnapshotService::registrar()` (como `AtribuicaoPorServicoNpsTest` faz) a inserir atribuição na mão; assim o teste cobre escrita→leitura de ponta a ponta. Inserção direta é aceitável só no teste de dedup, onde o objetivo é fabricar o par (resposta, role) duplicado.

**Sinais de alerta:** o teste do Ajuste 3 passa mas em prod a média do Gustavo não muda.

### Pitfall 7: carteira dobrada (regressão já blindada — não reintroduzir)

**O que dá errado:** o ramo legado ou um recorte novo re-declara `belongsToMany(Company::class, 'company_users')` com `withPivot(...)`, e a empresa com linha ML + linha Shopee volta 2×.

**Por quê:** `User::companies()` foi blindado com `->select('companies.*')->distinct()` justamente porque `withPivot('assigned_at')`/`withTimestamps()` reinjetam colunas aliasadas divergentes que furam o distinct (`User.php:206-220`; teste `CarteiraBonusNaoDobraTest`).

**Como evitar:** o ramo legado usa `$user->companies()` como já usa (:289-291) — sem `withPivot`. Se um recorte novo precisar do `role` da pivot, re-declarar por conta própria (padrão do `PortfolioController`) e checar o dedup.

## Confirmação do Ajuste 3 (foco #4 — VERIFICADO no código)

**Pergunta:** o NPS Padrão gera atribuição para o analista de ML das empresas ML dele — ou o Gustavo só receberia Shopee?

**Resposta: gera.** Cadeia verificada ponta a ponta:

| Elo | Evidência | Status |
|-----|-----------|--------|
| 1. NPS Padrão cobre os serviços de performance | `2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php` Passo D (:150-175): para cada `servicos` com `setor='performance'` e `ativo=true`, `updateOrInsert` em `nps_template_service_scopes` com `template_id` = o `is_default` | ✅ VERIFIED |
| 2. `registrar()` lê esses scopes | `NpsSnapshotService:138` → `$survey->template->serviceScopes()->get()` | ✅ VERIFIED |
| 3. Interseção com contrato ativo | `NpsSnapshotService:151-152` → `$company->contratosServico()->active()->pluck('servico_id')` ∩ cobertos | ✅ VERIFIED |
| 4. `company_users.servico_id` populado para performance | `2026_07_14_000001_add_servico_id_to_company_users.php::migrarLinhasExistentes()` (:122-147): toda empresa com contrato performance ativo teve as linhas `whereNull('servico_id')` atualizadas para o `servico_id` do performance | ✅ VERIFIED |
| 5. Responsável resolvido por serviço | `Company::consultorDoServico($id)` = `wherePivot('role','consultor')->wherePivot('servico_id',$id)` (`Company.php:197-203`) | ✅ VERIFIED |
| 6. Atribuição criada por (serviço × role × responsável) | `NpsSnapshotService:154-191` | ✅ VERIFIED |

**Conclusão:** para uma empresa ML com contrato performance ativo e pivot backfillada, a resposta do NPS Padrão gera atribuição `role='consultor'`, `service_setor='performance'` para o analista de ML. O Gustavo acumula performance (ML) + shopee. **Somar tudo, sem filtro de setor** — é literalmente o Ajuste 3.

**Buraco conhecido (relevante para o plano):** se a empresa **não** tem contrato performance ativo, o backfill deixou `servico_id = NULL` (a migration nunca inventa serviço, :143-145). Aí `consultorDoServico()` volta vazio → `Log::warning('[NPS Snapshot] responsável faltante')` (:169-174) → **nenhuma atribuição**. Consequência direta: **o dual-path não é só uma ponte para o histórico — é o fallback permanente** para empresas com dado de contrato/pivot incompleto. Reforça Pitfall 2: o ramo legado não pode ser removido nem afrouxado numa fase futura sem antes reconciliar essas pendências. Vale sugerir uma verificação operacional pós-deploy: `grep '[NPS Snapshot] responsável faltante'` nos logs para dimensionar o buraco.

## Cache: onde bumpar e quem consome (DEC-80-C)

**Onde:** `DesempenhoScoreService.php:141` — única ocorrência da string da chave.

```php
// Bump v2→v3 em 2026-07-15: computeNpsMedio passa a somar as atribuições
// congeladas (nps_score_assignments) — valores v2 têm a nota antiga (sem Shopee).
$cacheKey = sprintf('desempenho.compute.v3.%d.%s', $user->id, $mes->format('Y-m'));
```

**Consumidores de `computeCached` (varredura completa):**

| Consumidor | Linha | Observação |
|-----------|-------|-----------|
| `PerformanceController::index` | :108, :114 | Ranking `/performance` — o alvo visível da fase |
| `PerformanceController::dashboardCarteira` | :272 | Widget analista/estrategista |
| `PerformanceController::show` | :907 | View individual |
| `DashboardController` | :797 | Widget "Desempenho da equipe" |
| **`PortfolioController`** | **:1251, :1277** | **Não listado no CONTEXT** — performance do profissional + comparação com pares |
| `WarmDesempenhoCache` (cron 8min) | :71 | Repopula a v3 sozinho pós-deploy |

**Consumidores de `compute()` direto (sem cache — pegam o valor novo na hora):** `SnapshotDesempenhoScores:89`, `ConsolidarMesDesempenho:97`. Correto por design (docblock :132-133: "não use `computeCached` em jobs/commands de snapshot").

**Chaves v2 órfãs:** expiram por TTL (≤7 dias no mês fechado, 10 min no corrente). `cache:clear` não é obrigatório; é seguro se quiser efeito imediato (memória `project_ecf_drive_cache_stale` mostra o precedente de cache mascarando fix de dados).

## Leitores a alinhar (foco #6)

| Leitor | Linha | Estado hoje | O que muda |
|--------|-------|-------------|-----------|
| `PerformanceController::index` (ranking) | :98-141 | Consome `componentes.nps_medio` do service | **Nada.** Conserta sozinho quando o service muda — é o objetivo da fase |
| `dashboardCarteira` → `nps.media` | :490, :576 | `$data['componentes']['nps_medio']` | **Nada** — vem do service |
| `dashboardCarteira` → `npsByCompany` (coluna NPS da tabela) | :298-307, :333-340 | `->principal()` + dimensão por cargo, últimos 60d | NPS Shopee nunca aparece. Para acender: ler atribuição da resposta por empresa. **Escopo mínimo aceitável** (DEC-80-E dá discrição) |
| `dashboardCarteira` → `npsRespostas` (últimas 4) | :380-413 | `->principal()` | Idem — a resposta Shopee não lista |
| `dashboardCarteira` → `heatmap` | :424-484 | `->principal()`, 6 meses | Idem. Maior esforço (matriz empresa×mês) — candidato natural a adiar |
| `PerformanceController::show` | :898-908 | Snapshot mensal OU `computeCached` | **Nada** — herda do service |
| `NpsController::index` (cards por dimensão) | :235-253 | Agrega `NpsResponse` por dimensão, sem `->principal()`, escopo = carteira do user | É agregação **por dimensão da empresa**, não por pessoa. **Avaliar, provavelmente não mexer** — não alimenta bônus |
| `DashboardController` | :554, :1144 | NPS agregado dimensão `empresa`/por cargo | Fora da régua de bônus — não mexer |
| `PortfolioController` | :1383 | `->principal()` + dimensão por cargo | Mesma classe do widget; alinhar só se o plano decidir cobrir |
| `CalculateGoalResults` | :234 | Dimensão fixa `'empresa'` | **Intocado** — confirmado: `empresa` nunca vira nota de pessoa (`NpsSnapshotService:66-71` exclui `empresa` do `DIMENSAO_ROLE`) |

**Recomendação de escopo:** a fase é crítica (bônus). O ganho central — a nota Shopee entrar no ranking e no headline do widget — vem **inteiro** da mudança no service. As leituras de apresentação (coluna NPS por empresa, últimas respostas, heatmap) são cosméticas e podem ir para um plano separado ou para a fase de polish. Se entrarem, entram DEPOIS de a suite de bônus estar verde, e exigem `npm run build` se o payload do `Dashboard.jsx` mudar.

## Blast radius / regressão (foco #7)

| Superfície | Risco | Mitigação |
|-----------|-------|-----------|
| **`desempenho_score_snapshots` já consolidados** | DEC-80-E: não reescrever passado | Os snapshots são lidos, não recalculados: `PerformanceController:105-109` usa `breakdown_json` quando o mês é fechado e o snapshot existe. **Não rodar `desempenho:consolidar-mes` com `--mes` passado pós-deploy** |
| **`SnapshotDesempenhoScores` (diário, 13:30)** | Grava `mes_referencia=NULL` (modo diário) com o `compute()` novo | Comportamento correto — o snapshot diário é do mês em curso. Os deltas do ranking (`delta_vs_ontem`) vão registrar um degrau no dia do deploy: é o efeito real da correção, não bug. Vale avisar o time |
| **`ConsolidarMesDesempenho` (mensal, dia 1)** | Fecha o bônus do mês anterior com o motor novo | Desejado a partir do próximo fechamento. Meses já fechados ficam intocados enquanto ninguém reprocessar |
| **Fixture Carlos** (`DesempenhoScoreServiceTest:388`) | Âncora da diretoria | Passa por construção: `mockNpsRespostaPrincipal` cria survey/response via factory **sem** `NpsSnapshotService` → zero atribuições → 100% ramo legado → `nps_medio=4.25`, `nota_final=4.08`. **É a prova de regressão histórica embutida.** Se quebrar, o dual-path vazou |
| **`test_nps_dimensao_por_cargo_...`** (:822-901) | Invoca `computeNpsMedio` por reflection, assert `assertSame(2.0/4.0)` | Manter assinatura `(User, Carbon): float` e o retorno `float` estrito (`assertSame` recusa int) |
| **`test_nps_medio_e_zero_quando_user_sem_respostas_no_mes`** (:470) | `assertSame(0.0, ...)` | Preservar o `return 0.0` literal (não `null`, não `0`) |
| **`DesempenhoScoreSnapshotTest`, `DesempenhoEvolucaoTest`, `ConsolidarMesDesempenhoCommandTest`, `PerformanceCargoFilterTest`** | Consomem o shape do compute | Rodar na regressão (`--filter=Desempenho`, `--filter=Performance`) |
| **Suite V16 existente** (`SubmitSnapshotTest`, `AtribuicaoPorServicoNpsTest`, `AtribuicaoPorServicoIsolamentoTest`, `CarteiraBonusNaoDobraTest`) | Contrato de escrita da Fase 79 | Não devem mudar. Se mudarem, a Fase 80 invadiu a escrita |
| **Dev em paralelo (anunciar-ml)** | Conflito de merge / deploy | Reconciliar antes; confirmar deploy caso-a-caso (v9.0/v16.0 têm a regra de perguntar) |

## Code Examples

Todos os trechos abaixo foram lidos deste repositório nesta sessão [VERIFIED: leitura direta dos arquivos].

### Mapa linha-a-linha do `computeNpsMedio` atual (foco #1)

```php
// app/Services/DesempenhoScoreService.php:281-348
private function computeNpsMedio(User $user, Carbon $mes): float
{
    // :287 — dimensão POR CARGO (user_setores→cargos). Fix de 2026-07-13:
    //        antes era isMentor() e estrategistas caíam em 'analista'.
    $dim = $user->dimensaoNpsDesempenho();

    // :289-291 — carteira ATIVA (User::companies() já é ->select('companies.*')->distinct()).
    $companyIds = $user->companies()->where('active', true)->pluck('companies.id');

    // :293-295 — guard inalcançável via compute() (computeUniverso já barrou),
    //            mas ATIVO quando os testes chamam por reflection.
    //            ⚠ No dual-path tem que descer pro ramo legado.
    if ($companyIds->isEmpty()) {
        return 0.0;
    }

    // :301-309 — SÓ o modelo principal. É AQUI que o NPS Shopee morre hoje.
    //            ->principal() força 1=0 se nenhum template for is_default.
    $surveys = NpsSurvey::with('response')
        ->principal()
        ->whereIn('company_id', $companyIds)
        ->where('status', 'completed')
        ->whereBetween('completed_at', [                 // ◄── fonte do MÊS
            $mes->copy()->startOfMonth(),
            $mes->copy()->endOfMonth(),
        ])
        ->get();

    $notas = collect();                                   // :311

    foreach ($surveys as $survey) {                       // :313
        $response = $survey->response;                    // :315
        if ($response === null) { continue; }             // :316-318

        // :321 — caminho v15 canônico (SUM(pesos) / N_perguntas do template).
        $nota = $this->npsCalculator->compute($response, $dim);

        // :328-334 — fallback legacy Phase 72/73: template sem pergunta na
        //            dimensão OU survey pré-v15 → colunas score_* do response.
        if ($nota === null) {
            $legacyField = $dim === 'estrategista' ? 'score_estrategista' : 'score_analista';
            $legacyScore = $response->{$legacyField} ?? null;
            if ($legacyScore !== null && $legacyScore > 0) {
                $nota = (float) $legacyScore;
            }
        }

        if ($nota !== null) { $notas->push($nota); }      // :336-338
    }

    // :341-345 — DESEMP-03: sem respostas FORÇA 0.0 (penaliza). NUNCA null.
    if ($notas->isEmpty()) { return 0.0; }

    return round($notas->avg(), 2);                        // :347
}
```

**Leitura do mapa:** só 3 coisas mudam — (a) o guard de :293-295 desce, (b) entra o bloco (A) das atribuições antes do loop, (c) entra 1 linha de skip dentro do loop. `$dim`, o `NpsScoreCalculator`, o fallback `score_*`, o `0.0` e o `round(avg, 2)` ficam **intactos**.

### Ordem crítica no submit (fonte do mês)

```php
// app/Http/Controllers/NpsController.php:712-725
app(\App\Services\Nps\NpsSnapshotService::class)->registrar($response);  // assigned_at = now()
$survey->update([
    'status'       => 'completed',
    'completed_at' => now(),                                             // ◄── mês do bônus
]);
```

### Escrita da atribuição (o formato que a Fase 80 lê)

```php
// app/Services/Nps/NpsSnapshotService.php:154-191 (resumido)
foreach ($intersecao as $servico) {                   // cobertos ∩ contratos ATIVOS
    foreach (self::DIMENSAO_ROLE as $dimensao => $role) {   // analista→consultor, estrategista→estrategista
        $score = $scoresPorDimensao[$dimensao] ?? null;     // 'empresa' NÃO está no mapa
        if (! $score) { continue; }

        $responsaveis = $role === 'consultor'
            ? $company->consultorDoServico($servico->id)->get()
            : $company->estrategistaDoServico($servico->id)->get();

        if ($responsaveis->isEmpty()) {
            Log::warning('[NPS Snapshot] responsável faltante — atribuição não gerada', [...]);
            continue;                                        // ← sem atribuição → dual-path cai no legado
        }

        foreach ($responsaveis as $user) {
            NpsScoreAssignment::create([
                'nps_response_id'       => $response->id,
                'nps_response_score_id' => $score->id,
                'company_id'            => $company->id,
                'servico_id'            => $servico->id,
                'service_setor'         => $servico->setor,   // 'performance' | 'shopee'
                'role'                  => $role,
                'user_id'               => $user->id,
                'average_score'         => $score->average_score,  // ← idêntico p/ todo serviço da MESMA dimensão
                'assigned_at'           => $agora,
            ]);
        }
    }
}
```

**Origem exata do dedup (DEC-80-A):** o `foreach ($intersecao as $servico)` externo. 2 serviços cobertos + mesmo responsável + mesmo role = **2 linhas** com `average_score` idêntico (vem do mesmo `$score`). Daí `MAX()` no `groupBy` ser determinístico.

### Schema relevante de `nps_score_assignments`

```php
// database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php:144-199
$table->foreignId('nps_response_id')->constrained('nps_responses')->cascadeOnDelete();
$table->foreignId('nps_response_score_id')->constrained('nps_response_scores')->cascadeOnDelete();
$table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
$table->foreignId('servico_id')->nullable()->constrained('servicos')->nullOnDelete();
$table->string('service_setor');                       // congelado
$table->enum('role', ['consultor', 'estrategista']);   // pivot company_users
$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
$table->decimal('average_score', 5, 2);                // ⚠ 2 casas — ver Pitfall 4
$table->timestamp('assigned_at');                      // ⚠ NÃO é o mês — ver Pitfall 1
$table->timestamps();
$table->index(['user_id', 'role'], 'nps_score_assign_user_role_idx');  // cobre o WHERE da fase
$table->index(['service_setor'], 'nps_score_assign_setor_idx');        // cobre os recortes DEC-80-E
// ⚠ SEM unique — o dedup é responsabilidade do LEITOR (esta fase)
```

## State of the Art

| Abordagem antiga | Abordagem atual | Quando mudou | Impacto |
|------------------|-----------------|--------------|---------|
| Dimensão por `isMentor()` (role do sistema) | Dimensão por cargo (`user_setores→cargos`) via `User::dimensaoNpsDesempenho()` | 2026-07-13 (bump cache v1→v2) | Preservar no ramo legado — é o que reproduz o histórico |
| Nota via colunas `score_*` de `nps_responses` | `NpsScoreCalculator` sobre `nps_response_answers` (snapshot per-row) | Phase 68/69 (v15.0) | `score_*` continua como fallback dual-path — não remover |
| AVG das answers respondidas | `SUM(pesos) / N_perguntas do template` | 2026-07-08 (feedback UX) | Perguntas puladas puxam a média pra baixo — não recalcular por fora |
| Nota crua (%) direto na média final | Variações passam pelas réguas 1-5 antes da média | 2026-07-09 | Fixture Carlos 3.35 → **4.08** (o CONTEXT tem o valor velho) |
| "Só o modelo principal conta" (bônus) | Qualquer modelo conta **via atribuição**; principal segue no ramo legado | **Esta fase** | Memória `project_nps_modelo_principal` fica parcialmente superada (deferido) |
| Cruzamento read-time carteira × dimensão | Atribuição congelada no submit | Phase 79 (escrita) + **Phase 80 (leitura)** | Trocar responsável não reescreve mais o bônus passado |

**Depreciado / não usar:**
- `NpsResponse::score_analista/score_estrategista` como fonte primária — só fallback histórico.
- `survey.month_reference` como mês do bônus — é o mês do disparo e é NULL em muitas linhas.
- `NpsSurvey::scopePrincipal()` no ramo das atribuições — irrelevante lá (e perigoso remover do legado).

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|-----------------|
| A1 | `assigned_at` e `completed_at` são hoje sempre do mesmo mês em prod (mesma transação) | Pitfall 1 | Baixo. Se falso (backfill já rodado), a query por `completed_at` continua sendo a correta — o risco é o inverso (usar `assigned_at`). Não checado contra o banco de prod |
| A2 | Não existe backfill/reprocessamento de `nps_score_assignments` para respostas antigas | Pitfall 1, Runtime State | Baixo. Grep por `NpsScoreAssignment` achou 0 commands/jobs — só o `NpsSnapshotService`, os testes e docs de planning. Se surgir depois, o JOIN por `completed_at` já protege |
| A3 | Excluir do legado por **(user, resposta)** — e não por (user, resposta, role) | Pattern 1 | Médio-baixo. É a leitura literal do DEC-80-B. Divergem só num caso raro: user com atribuição `role=consultor` numa resposta cuja dimensão do cargo dele é `estrategista` — o legado deixaria de somar a nota de estrategista dessa resposta. Ver Open Question 1 |
| A4 | Os recortes por `service_setor`/`role` na UI (DEC-80-E) podem ficar mínimos nesta fase | Leitores a alinhar | Baixo — o próprio CONTEXT põe o formato na discrição do Claude |
| A5 | `vendor/bin/phpunit` está operacional (SQLite in-memory, sem MariaDB local) | Validation Architecture | Baixo. `phpunit.xml` confirma `DB_CONNECTION=sqlite` / `:memory:` e `CACHE_STORE=array`; a suite não depende do MariaDB local (memória `project_mariadb_local_corrompido` não se aplica) |

## Open Questions

1. **Exclusão por (user, resposta) ou por (user, resposta, role)?**
   - O que sabemos: DEC-80-B diz "se existe atribuição para (user, resposta) → usar a atribuição". O dedup do DEC-80-A é por (resposta, role).
   - O que não está claro: um user pode ter atribuição `consultor` numa resposta e, pelo cargo, o legado lhe daria a nota da dimensão `estrategista` da mesma resposta. Exclusão por (user, resposta) descarta essa nota do legado; por (user, resposta, role) somaria as duas.
   - Recomendação: **exclusão por (user, resposta)** — literal ao DEC-80-B, e a alternativa somaria 2 notas da mesma resposta para a mesma pessoa (o oposto do espírito do dedup "1× por papel"). O caso é raríssimo (exige a pessoa acumular papéis conflitantes na mesma empresa). Documentar no docblock e seguir.

2. **A coluna NPS por empresa / heatmap / últimas respostas entram nesta fase?**
   - O que sabemos: o headline (`nps.media`, ranking) conserta sozinho via service. As três leituras usam `->principal()` e não mostram Shopee.
   - O que não está claro: se "a nota Shopee aparecer" para o usuário significa só o ranking/nota ou também os widgets.
   - Recomendação: plano separado, DEPOIS de a suite de bônus estar verde. Se entrar, exige `npm run build`. Vale confirmar com o usuário no início da fase — é a diferença entre uma fase cirúrgica e uma fase com frontend.

3. **Quantas empresas estão com `company_users.servico_id = NULL` (sem contrato performance ativo)?**
   - O que sabemos: essas empresas não geram atribuição (Log::warning de pendência) e ficam permanentemente no ramo legado.
   - O que não está claro: o tamanho do buraco em prod.
   - Recomendação: fora do escopo desta fase (o dual-path já cobre), mas vale um `grep '[NPS Snapshot] responsável faltante'` no VPS pós-deploy para dimensionar e, se for grande, abrir um seed de reconciliação numa fase futura.

## Environment Availability

| Dependência | Requerida por | Disponível | Versão | Fallback |
|-------------|--------------|-----------|--------|----------|
| PHP 8.2+ | Todo o código | ✓ | 8.2+ (`composer.json`) | — |
| PHPUnit 11 | `tests/Feature/V16/` | ✓ | ^11.5.50 (`composer.json`) | — |
| SQLite in-memory | Suite de testes | ✓ | `phpunit.xml`: `DB_CONNECTION=sqlite`, `:memory:` | — |
| Cache array (testes) | Teste do bump v3 | ✓ | `phpunit.xml`: `CACHE_STORE=array` | — |
| MariaDB local | **Não requerido** | — | — | Suite roda em SQLite; validar ALTER/FK em prod não se aplica (sem migration nesta fase) |
| Redis (prod) | `computeCached` | ✓ (prod) | — | Bump de chave não depende do store |
| Node/npm (`npm run build`) | Só se tocar `Dashboard.jsx` | ✓ | v24.15.0 | Evitável mantendo a fase backend-only |

**Sem dependências faltando.** A fase não introduz migration, pacote nem serviço externo — o gotcha MySQL 1830/1553 das memórias **não se aplica** (nenhum `ALTER`).

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|------------|-------|
| Framework | PHPUnit 11.5 + Laravel `RefreshDatabase` |
| Config file | `phpunit.xml` (SQLite `:memory:`, `CACHE_STORE=array`) |
| Quick run command | `vendor/bin/phpunit --filter=Desempenho` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map
Todos os testes novos em `tests/Feature/V16/`. Sugestão de arquivo: `BonusAtribuicoesNpsTest.php` (+ `BonusDualPathRegressaoTest.php` se ficar grande).

| # | Comportamento | Tipo | Comando automatizado | Existe? |
|---|--------------|------|---------------------|---------|
| 1 | **Ajuste 3 (âncora)** — user analista de empresa ML **e** de empresa Shopee → média soma as DUAS atribuições (cenário Gustavo) | feature | `vendor/bin/phpunit --filter=test_media_soma_atribuicoes_ml_e_shopee` | ❌ Wave 0 |
| 2 | **Shopee acende** — resposta NPS Shopee com atribuição entra na média (hoje não entra) | feature | `--filter=test_nps_shopee_entra_na_media_da_pessoa` | ❌ Wave 0 |
| 3 | **Dedup** — 2 atribuições da MESMA resposta+role (2 serviços cobertos) → conta 1× | feature | `--filter=test_dedup_conta_uma_vez_por_resposta_e_role` | ❌ Wave 0 |
| 4a | **Regressão histórica** — mês SEM atribuições → nota IDÊNTICA ao legado | feature | `--filter=test_mes_sem_atribuicoes_mantem_nota_legada` | ❌ Wave 0 |
| 4b | **Mês misto** — respostas novas (atribuição) + antigas (legado) somam sem duplicar | feature | `--filter=test_mes_misto_soma_os_dois_caminhos_sem_duplicar` | ❌ Wave 0 |
| 4c | **Isolamento (extra — Pitfall 2)** — analista de ML NÃO recebe a nota do NPS Shopee da mesma empresa | feature | `--filter=test_analista_ml_nao_recebe_nota_shopee` | ❌ Wave 0 |
| 5 | **Sem respostas → 0.0** preservado (`assertSame(0.0, ...)`) | feature | `--filter=test_nps_medio_e_zero_quando_user_sem_respostas_no_mes` | ✅ `Phase74/DesempenhoScoreServiceTest.php:470` |
| 6 | **Cache v3** — nota nova não vem do cache v2 (semear `desempenho.compute.v2.{id}.{Y-m}` com lixo e provar que `computeCached` ignora) | feature | `--filter=test_cache_bumpado_para_v3` | ❌ Wave 0 |
| 7 | **Fixture Carlos** verde — `nota_final=4.08`, `basico` (⚠ **não** 3.35/`sem_bonus` — ver C1) | feature | `--filter=test_fixture_carlos_retorna_nota_4_08_basico` | ✅ `Phase74/DesempenhoScoreServiceTest.php:388` |
| 8 | Regressão ampla | feature | `--filter=Desempenho` · `--filter=Performance` · `--filter=Nps` | ✅ existentes |

**Notas de construção dos testes (economizam um ciclo RED inútil):**
- Usar o trait `CriaCenarioResponsaveis` (serviço + contrato + pivot com `servico_id`; `inserirLinhaShopee()` para o cenário ML+Shopee).
- Gerar atribuições via `NpsSnapshotService::registrar()` (padrão do `AtribuicaoPorServicoNpsTest`), não por `create()` na mão — exceto no teste de dedup, onde fabricar o par duplicado é o ponto.
- Template principal: `NpsTemplate::query()->update(['is_default' => false])` → cria → **`NpsTemplate::resetPrincipalCache()`** (Pitfall 5).
- Congelar `Carbon::setTestNow()` (a suite Phase 74 usa `2026-08-01 14:05:00`) — o dual-path depende de janelas de mês.
- Para o teste 4a, a forma mais barata de provar "idêntico ao legado" é o próprio fixture Carlos (zero atribuições by construction) + um assert de valor exato num cenário controlado.
- `assertEqualsWithDelta` com delta ≥ 0.01 nos cenários que misturam os caminhos (Pitfall 4).

### Sampling Rate
- **Por task commit:** `vendor/bin/phpunit --filter=Desempenho` (~segundos, SQLite in-memory)
- **Por wave merge:** `vendor/bin/phpunit --filter=Desempenho && vendor/bin/phpunit --filter=Performance && vendor/bin/phpunit --filter=Nps`
- **Phase gate:** `vendor/bin/phpunit` (suite completa) verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/V16/BonusAtribuicoesNpsTest.php` — cobre 1, 2, 3, 4c
- [ ] `tests/Feature/V16/BonusDualPathRegressaoTest.php` — cobre 4a, 4b, 6
- [ ] Nenhum framework/fixture a instalar — `CriaCenarioResponsaveis` e as factories NPS já existem

## Security Domain

Fase interna, sem superfície nova de entrada (nenhuma rota, nenhum input de usuário, nenhuma dependência nova).

### Applicable ASVS Categories

| Categoria ASVS | Aplica | Controle padrão |
|----------------|--------|-----------------|
| V2 Authentication | não | Sem rota nova; leituras existentes já atrás de `auth` |
| V3 Session Management | não | Sem mudança |
| V4 Access Control | **sim (indireto)** | `/performance` já protegida por `permission:core.performance`; `dashboardCarteira` é escopada ao `$request->user()`. **A fase não pode alargar o escopo de leitura** — a query de atribuição filtra por `user_id` do user computado |
| V5 Input Validation | não | Nenhum input externo; o único parâmetro é `Carbon $mes`, vindo de rota já validada por regex (`PerformanceController:75`, :879) |
| V6 Cryptography | não | Nada de cripto |

### Known Threat Patterns

| Padrão | STRIDE | Mitigação |
|--------|--------|-----------|
| SQL injection na query nova | Tampering | `selectRaw` sem interpolação de variável (só nomes de coluna literais); binds via `where`/`whereBetween` do query builder |
| Vazamento de nota entre pessoas (analista ML vê/recebe nota Shopee) | Information Disclosure / **Integridade do bônus** | Manter `->principal()` no legado (Pitfall 2) + teste 4c de isolamento |
| Inflar o próprio bônus via 2ª linha na pivot | Elevation of Privilege (financeiro) | Dedup por (resposta, role) (teste 3) + `User::companies()` já blindado contra carteira dobrada (`CarteiraBonusNaoDobraTest`) |
| Reprocessar mês fechado e alterar bônus pago | Tampering | DEC-80-E: não rodar `consolidar-mes --mes` passado; leitura de mês fechado vem do `breakdown_json` do snapshot |

## Sources

### Primary (HIGH confidence) — código lido nesta sessão
- `app/Services/DesempenhoScoreService.php` — `computeNpsMedio` :281-348, `compute` :158-238, `computeCached` :135-156, réguas :756-785
- `app/Services/Nps/NpsSnapshotService.php` — `registrar()` :84-193, `DIMENSAO_ROLE` :68-71, interseção :151-152, warning :169-174
- `app/Services/Nps/NpsScoreCalculator.php` — `compute()` :65-113 (divisor = `question_count`; sem round)
- `app/Models/NpsScoreAssignment.php`, `app/Models/NpsSurvey.php` (`scopePrincipal` :80-87), `app/Models/NpsResponse.php`
- `app/Models/User.php` — `companies()` :206-220, `cargoDesempenhoSlug`/`dimensaoNpsDesempenho` :79-98
- `app/Models/Company.php` — `consultorDoServico`/`estrategistaDoServico` :197-209
- `app/Http/Controllers/NpsController.php` — `submitResponseV15` :601-726 (ordem `registrar()` → `completed_at`), cards :235-253
- `app/Http/Controllers/PerformanceController.php` — index :25-240, dashboardCarteira :265-584, show :862-923
- `app/Http/Controllers/PortfolioController.php` — :1251, :1277, :1383 (consumidor não listado no CONTEXT)
- `app/Console/Commands/SnapshotDesempenhoScores.php` :89, `ConsolidarMesDesempenho.php` :97, `WarmDesempenhoCache.php` :71
- `database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php` — schema completo das 3 tabelas
- `database/migrations/2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php` — Passo D (prova do Ajuste 3)
- `database/migrations/2026_07_14_000001_add_servico_id_to_company_users.php` — `migrarLinhasExistentes()` :122-147
- `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` — âncora Carlos :388-425, reflection :892
- `tests/Feature/V16/CriaCenarioResponsaveis.php`, `CarteiraBonusNaoDobraTest.php`, `AtribuicaoPorServicoNpsTest.php`
- `routes/console.php` :166-212 (crons), `phpunit.xml`, `.planning/config.json`, `CLAUDE.md`

### Secondary (MEDIUM confidence)
- `.planning/phases/80-.../80-CONTEXT.md` — decisões travadas (2 divergências factuais vs código: C1, C2)
- Memórias: `project_nps_modelo_principal`, `project_desempenho_compute_cache`, `feedback_perguntar_antes_deploy_v9`, `project_ecf_drive_cache_stale`, `project_mariadb_local_corrompido`

### Tertiary (LOW confidence)
- Nenhuma. Zero WebSearch — a fase é 100% código interno; buscar na web só traria ruído.

## Metadata

**Confidence breakdown:**
- Standard stack: **HIGH** — nenhuma dependência nova; tudo verificado por leitura direta
- Arquitetura / dual-path: **HIGH** — os dois caminhos foram mapeados linha a linha nos arquivos reais
- Ajuste 3 (o ML do Gustavo conta): **HIGH** — cadeia de 6 elos verificada em migrations + models + service
- Fonte do mês (Pitfall 1): **HIGH** para o schema/ordem do submit; **MEDIUM** para o risco futuro de backfill (A2 — inferido de grep, não de garantia)
- Pitfalls: **HIGH** — todos derivados de código lido, não de conhecimento genérico
- Blast radius: **HIGH** — varredura completa de `computeCached`/`compute(` em `app/`

**Research date:** 2026-07-15
**Valid until:** ~2026-08-15 (ou até a próxima mudança em `DesempenhoScoreService`/`NpsSnapshotService` — código interno de evolução rápida; revalidar se a Fase 80 não executar em 30 dias)
