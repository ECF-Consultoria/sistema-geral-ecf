# Phase 88: Camada de contexto — CarteiraContextService (v17.0) - Research

**Researched:** 2026-07-16
**Domain:** Service de leitura Laravel — resolução de vínculos carteira×serviço sobre schema já existente
**Confidence:** HIGH (código lido diretamente; nenhuma lib nova; único ponto MEDIUM é o volume real de linhas `servico_id NULL` em prod — MySQL local indisponível para contagem ao vivo)

<user_constraints>
## User Constraints (from REQUIREMENTS.md / plano canônico)

### Locked Decisions (REQUIREMENTS.md CTX-01..05)

- **CTX-01**: `CarteiraContextService::forUser($user, $filters)` retorna os vínculos de serviço ativos do profissional, cada um com `company_id`, `company_name`, `servico_id`, `servico_nome`, `setor`, `role`, `role_label`
- **CTX-02**: Cada vínculo marca `has_financial_source` / `financial_source` / `financial_metrics_eligible` — `true`/`'adman'` para setor `performance`, `false`/`null` para `shopee` (até existir fonte Shopee)
- **CTX-03**: O serviço resolve elegibilidade financeira por `servicos.setor`, cobrindo TODOS os serviços de performance (Gestão id 6 E Mentoria id 7), sem hardcode de `servico_id`
- **CTX-04**: O serviço deduplica corretamente — distingue "empresas únicas" de "vínculos de serviço"; a mesma empresa com dois vínculos do mesmo profissional não é contada duas vezes como empresa
- **CTX-05**: Compatibilidade legado — `servico_id` preenchido tem prioridade; `servico_id null` com contrato performance ativo é tratado como Performance legado; `servico_id null` com contrato Shopee ativo NÃO assume responsável Shopee automaticamente

### Critérios de aceite globais (REQUIREMENTS.md, valem para toda a v17.0)

- Nenhuma empresa é duplicada em nenhuma tela
- Nenhuma atribuição Shopee altera responsável Performance, e vice-versa
- `company_users.servico_id` é respeitado em todos os fluxos novos; `servico_id null` segue como legado
- `User::companies()` NÃO é removido — permanece como fallback legado documentado

### Out of Scope (v17.0, herda para Fase 88)

- Fonte financeira de Shopee (não existe API/importação Shopee ainda)
- Régua de bônus para Shopee sem financeiro
- Nova tabela `company_services` (plano decide reusar `contratos_servico` + `company_users.servico_id`)
- Score separado por marketplace

### Shape sugerido pelo plano canônico (`plano-carteira-desempenho-multi-servico.md`, seção "Camada tecnica proposta")

```php
[
    'user_id' => 10,
    'company_id' => 123,
    'company_name' => 'Camillo Parts',
    'servico_id' => 7,
    'servico_nome' => 'Gestao de ADS Shopee',
    'setor' => 'shopee',
    'role' => 'consultor',
    'role_label' => 'Analista',
    'has_financial_source' => false,
    'financial_source' => null,
    'financial_metrics_eligible' => false,
]
```

Regras de compatibilidade textuais do plano (§"Regras de compatibilidade"):
- `servico_id` preenchido tem prioridade.
- `servico_id null` + contrato performance ativo → Performance legado.
- `servico_id null` + contrato Shopee ativo → **NÃO** assumir responsável Shopee automaticamente.
- Nunca duplicar empresas, nunca apagar `User::companies()`, nunca usar `AdmanMetric` no vínculo Shopee, nunca criar score separado por marketplace.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Research Support |
|----|-----------|-------------------|
| CTX-01 | `forUser($user, $filters)` retorna vínculos com shape completo | §Shape e assinatura + §Código de referência |
| CTX-02 | Cada vínculo expõe `has_financial_source`/`financial_source`/`financial_metrics_eligible` | §Elegibilidade financeira |
| CTX-03 | Elegibilidade resolvida por `servicos.setor`, sem hardcode de `servico_id` | §Elegibilidade financeira (confirma que Gestão+Mentoria já compartilham `setor='performance'`) |
| CTX-04 | Dedup empresas únicas vs vínculos de serviço | §Dedup |
| CTX-05 | Compatibilidade legado (`servico_id` null + contrato performance/Shopee) | §Realidade dos dados do pivot |
</phase_requirements>

## Summary

O `CarteiraContextService` é um service de LEITURA puro, sem HTTP, sem cache necessário, construído inteiramente sobre schema que já existe (`company_users.servico_id`, `contratos_servico`, `servicos.setor`) — fundação da Fase 76 (v16.0) e usado ativamente pelo `NpsSnapshotService` como precedente direto de como resolver responsável-por-serviço no codebase (`Company::consultorDoServico()`/`estrategistaDoServico()`). Não há biblioteca nova a instalar, não há endpoint HTTP nesta fase (é service puro, consumido pelas Fases 89-92).

O ponto crítico de CTX-03 (Gestão id 6 E Mentoria id 7 cobertos sem hardcode) já está resolvido no schema: a migration `2026_06_18_100002_seed_servicos_setor.php` faz `UPDATE servicos SET setor='performance' WHERE nome LIKE '%Gestão%' OR nome LIKE '%Mentoria%'` — ambos os serviços JÁ compartilham `setor='performance'`. Resolver elegibilidade por `$servico->setor === Servico::SETOR_PERFORMANCE` cobre os dois automaticamente; não é preciso (nem deve) comparar `servico_id` contra uma lista fixa.

**Recomendação primária:** implementar como método único que monta 2 fontes de vínculo (linhas com `servico_id` preenchido direto da pivot + linhas `servico_id NULL` resolvidas via `contratos_servico` ativo de setor performance), normaliza ambas no mesmo shape de array documentado em docblock (padrão do projeto — `DesempenhoScoreService` não usa DTOs), e agrupa por `company_id` só para computar os metadados de dedup (`empresas_unicas`) sem colapsar os vínculos individuais.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Resolução de vínculo carteira×serviço | API/Backend (`app/Services/Portfolio/CarteiraContextService`) | Database (`company_users`, `contratos_servico`, `servicos`) | Query pura Eloquent/DB, sem chamada externa; nenhuma lógica pertence ao frontend nesta fase (consumo em 89-92) |
| Elegibilidade financeira (setor→fonte) | API/Backend | — | Regra de negócio central de v17.0; não delega a controllers |
| Dedup empresas vs vínculos | API/Backend | — | Precisa ver TODOS os vínculos do user antes de agregar — não dá para fazer no banco com `distinct()` simples sem perder a granularidade de vínculo |

## Realidade dos dados do pivot (CTX-05)

**Confiança: MEDIUM** — análise 100% baseada em leitura de código (migration + único writer de `servico_id`), não em contagem ao vivo. `[ASSUMED]` no sentido de "não verificado contra o banco nesta sessão" — ver Ambiente abaixo.

A migration `2026_07_14_000001_add_servico_id_to_company_users.php::migrarLinhasExistentes()` é o ÚNICO backfill em massa que já rodou:

```php
// mapa company_id → servico_id do contrato performance ativo
DB::table('contratos_servico as ct')
    ->join('servicos as s', ...)
    ->where('ct.ativo', true)
    ->where('s.setor', 'performance')
    ->groupBy('ct.company_id')
    ->selectRaw('ct.company_id, MIN(ct.servico_id) as servico_id')
    ->get();
// UPDATE company_users SET servico_id = ... WHERE servico_id IS NULL (por company_id)
```

Consequência determinística (confirmada por leitura, `[VERIFIED: código]`):
- Toda empresa com **contrato performance ATIVO** teve seu(s) responsável(is) preenchidos com `servico_id` = o contrato performance (MIN se houver mais de um). **Não sobra NULL** para essas empresas — CTX-05 "servico_id null + contrato performance ativo → legado" é um caso RESIDUAL, não o caso comum: só ocorre se uma linha `company_users` foi criada DEPOIS do backfill sem passar por um writer que preenche `servico_id` (ex.: atribuição manual antiga fora do fluxo Shopee).
- Empresas SEM contrato performance ativo (Shopee-only, Polos-only, Publicação-only, ou sem contrato algum) **permanecem com `servico_id = NULL`** — o backfill nunca inventa serviço para elas (comentário explícito no código: "NUNCA usar `where('servico_id', null)`" e "empresas SEM contrato performance ativo → permanece NULL").
- O ÚNICO outro escritor de `servico_id` é `ShopeeEmpresasController::gravarResponsavelShopee()` (Fase 78), que grava sempre com `servico_id` preenchido (nunca grava NULL para Shopee). Logo, hoje, uma linha `servico_id NULL` associada a uma empresa Shopee-only representa responsável atribuído **antes** da Fase 78 (não via o fluxo atual) — é exatamente o caso que CTX-05 manda NÃO promover automaticamente a Shopee.
- Nenhum código no repo escreve `servico_id` para os setores `polos`, `publicacao` ou `outros` — essas linhas de `company_users`, se existirem, ficam eternamente `servico_id NULL` e sem contrato performance associado (fora do escopo do backfill). Ver §Setores não cobertos abaixo.

**Ambiente: MySQL local (XAMPP/MariaDB 10.4.32) está fora do ar nesta sessão** — `mysqld` recusa iniciar com `[ERROR] Fatal error: Can't open and lock privilege tables: Incorrect file format 'db'` (mesmo incidente documentado em `project_mariadb_local_corrompido.md`, 2026-06-25). Não foi possível rodar `SELECT COUNT(*) FROM company_users WHERE servico_id IS NULL` para confirmar o volume real. **Ação recomendada para o planner:** incluir uma verificação leve (`php artisan tinker` ou query rápida) no início da execução (Wave 0/1), rodada contra o ambiente disponível (dev quando o MySQL local voltar, ou VPS), para confirmar a contagem antes de escrever os testes de regressão com números fixos.

## Setores não cobertos pelo plano canônico (`polos`, `publicacao`, `outros`)

CTX-03 só especifica `performance` (adman) e implicitamente `shopee` (sem fonte). O catálogo `Servico::SETORES` tem 5 valores: `performance`, `publicacao`, `polos`, `shopee`, `outros`. Tratamento recomendado, documentado explicitamente (não hardcoded como exceção — cai no `default` do resolver):

```php
'performance' => ['has_financial_source' => true,  'financial_source' => 'adman', 'financial_metrics_eligible' => true],
'shopee'      => ['has_financial_source' => false, 'financial_source' => null,    'financial_metrics_eligible' => false],
default       => ['has_financial_source' => false, 'financial_source' => null,    'financial_metrics_eligible' => false],
// cobre polos, publicacao, outros — sem fonte financeira até segunda ordem
```

Isso é seguro pela regra `[VERIFIED: código]` acima: nenhum vínculo de `company_users` hoje tem `servico_id` apontando para um serviço de setor `polos`/`publicacao`/`outros` (nenhum writer grava isso), então este branch é defensivo/futuro-proof, não corrige nenhum bug latente. Documentar a decisão explicitamente no docblock do service para o próximo profissional que ligar um serviço desses setores.

## Shape e assinatura do service

**Decisão recomendada: array associativo puro, um por vínculo** — não DTO, não Collection de objetos tipados. Justificativa:

- Convenção do projeto: `DesempenhoScoreService::compute()` documenta shape de retorno em docblock (não usa DTO próprio); a nota do arquivo é explícita: *"Métodos privados retornam tipos escalares (`?float`/`array`) — nunca DTOs próprios"*. Zero DTOs no projeto hoje (`grep -r "final class.*Dto\|readonly class" app/` não retorna nada em `app/Services`).
- Consumidores futuros (`PortfolioController`, `DesempenhoScoreService::computeUniverso`) já leem `$user->companies()` como `EloquentCollection` de `Company` — trocar para array de vínculos é mudança de shape suficiente sem também introduzir um tipo de objeto novo no projeto.

Assinatura recomendada:

```php
/**
 * @param  array{setor?: string|null, role?: string|null, active?: bool}  $filters
 * @return \Illuminate\Support\Collection<int, array{
 *   user_id: int, company_id: int, company_name: string,
 *   servico_id: ?int, servico_nome: ?string, setor: string,
 *   role: string, role_label: string,
 *   has_financial_source: bool, financial_source: ?string, financial_metrics_eligible: bool,
 * }>
 */
public function forUser(User $user, array $filters = []): Collection
```

Retornar `Illuminate\Support\Collection` (não array puro) — todo o resto do projeto (`DesempenhoScoreService`, `NpsSnapshotService`) já retorna/recebe `Collection` de dentro dos services; os consumidores (Fase 89/90) vão filtrar/agrupar em cima (`->groupBy('company_id')`, `->where('financial_metrics_eligible', true)`).

## Elegibilidade financeira (CTX-02/CTX-03)

**Não confundir dois vocabulários diferentes que já existem no código** (pitfall real, nomes parecidos):

1. `MetricsProviderFactory::caseFor(Company $company): string` retorna `'ambos'|'so-ml'|'so-adman'|'none'` — é sobre **qual provider técnico** lê revenue/margem de uma empresa (ML OAuth vs Adman local), setor-agnóstico.
2. O `financial_source` do plano canônico (CTX-02) é sobre **qual setor de serviço** tem qualquer fonte financeira associável — hoje só `performance` (valor fixo `'adman'`), nunca `'ml'`/`'unified'`.

O `CarteiraContextService` NÃO deve chamar `MetricsProviderFactory` nem reusar seu vocabulário — `financial_source` aqui é derivado 100% de `servicos.setor`, é um valor constante (`'adman'` ou `null`), não uma leitura de estado de integração ML. Consumidores futuros (Fase 89+) combinam os dois: primeiro filtram por `financial_metrics_eligible=true` (este service), DEPOIS decidem ML vs Adman via `MetricsProviderFactory` para ler o dado de fato.

## Dedup — empresa única vs vínculo de serviço (CTX-04)

Semântica confirmada pelo plano canônico: dedup é **por (user, company)**, não por company global — o service já filtra por `$user` (parâmetro), então "empresas únicas" = `count(distinct company_id)` DENTRO do resultado já filtrado para aquele user. Não usar o padrão `->distinct('companies.id')` do `User::companies()` (que colapsa a LINHA, perdendo o vínculo por serviço) — aqui o objetivo é o oposto: manter 1 linha por vínculo e computar a contagem de empresas únicas como metadado separado, não como resultado do dedup do dataset.

```php
$vinculos = $this->forUser($user); // Collection de N vínculos (pode ter 2 linhas pra mesma company_id)
$empresasUnicas = $vinculos->pluck('company_id')->unique()->count();
$vinculosServico = $vinculos->count();
```

Cenário explícito do plano/ROADMAP a cobrir em teste: "mesmo profissional nos dois serviços da mesma empresa" (ex.: Gustavo analista ML **e** Shopee da mesma Camillo Parts) → 1 empresa única, 2 vínculos, cada um com seu `setor`/`financial_metrics_eligible` próprio.

## Filtros (`$filters`)

Baseado no que as Fases 89-92 vão precisar (filtro Todos/Performance/Shopee na UI, ROADMAP Fase 89/90):

| Filtro | Tipo | Comportamento |
|--------|------|----------------|
| `setor` | `?string` | `null`/ausente = todos; `'performance'`/`'shopee'` filtra por `setor` resolvido do vínculo (aplicado DEPOIS da resolução, não antes — o setor de um vínculo `servico_id NULL` só é conhecido após resolver via contrato) |
| `role` | `?string` | `'consultor'`/`'estrategista'`/null=ambos —útil para telas que separam Analista/Estrategista |
| `active` | `bool` | default `true` — só empresas com `companies.active=true`; expor o parâmetro para a Fase 90 (carteira consolidada admin pode precisar ver inativas) |

Não adicionar filtro de `company_id` nesta fase (YAGNI — nenhum requirement de Fase 88 pede).

## Cache

**Decisão: NÃO usar cache.** Diferente do `DesempenhoScoreService::computeCached()` (que existe porque `compute()` faz até 4 HTTP calls síncronas por empresa à API do ML — 70s cold em prod, ver `project_desempenho_compute_cache.md`), o `CarteiraContextService::forUser()` faz apenas queries locais (`company_users` JOIN `companies`/`servicos`/`contratos_servico`) — zero HTTP. Volume real: ~15 users × até ~2 vínculos por empresa da carteira (115+ empresas total no sistema, mas cada user só vê a própria carteira, tipicamente <30 empresas) — trivial para MySQL sem cache. Se um profiling futuro (Fase 89+) revelar N+1 ao integrar com o Portfolio, resolver com eager loading (`->with(['servico', 'company'])`), não com `Cache::remember`.

## Código de referência (precedente direto no codebase)

`Company::consultorDoServico()`/`estrategistaDoServico()` (`app/Models/Company.php:197-209`) já resolvem responsável por serviço específico via `wherePivot('servico_id', $id)` — é o padrão que o novo service deve espelhar para a query “servico_id preenchido”:

```php
// Vínculos com servico_id PREENCHIDO — prioridade (CTX-05)
DB::table('company_users as cu')
    ->join('companies as c', 'c.id', '=', 'cu.company_id')
    ->join('servicos as s', 's.id', '=', 'cu.servico_id')
    ->where('cu.user_id', $user->id)
    ->whereNotNull('cu.servico_id')
    ->whereIn('cu.role', ['consultor', 'estrategista'])
    ->where('c.active', true)
    ->select('cu.company_id', 'c.name as company_name', 'cu.servico_id',
             's.nome as servico_nome', 's.setor', 'cu.role')
    ->get();
```

```php
// Vínculos com servico_id NULL — resolve via contrato performance ativo (CTX-05 legado);
// Shopee NUNCA resolve por este caminho (regra explícita do plano canônico).
DB::table('company_users as cu')
    ->join('companies as c', 'c.id', '=', 'cu.company_id')
    ->whereNull('cu.servico_id')
    ->where('cu.user_id', $user->id)
    ->whereIn('cu.role', ['consultor', 'estrategista'])
    ->where('c.active', true)
    ->whereExists(function ($q) {
        $q->select(DB::raw(1))
          ->from('contratos_servico as ct')
          ->join('servicos as s', 's.id', '=', 'ct.servico_id')
          ->whereColumn('ct.company_id', 'cu.company_id')
          ->where('ct.ativo', true)
          ->where('s.setor', Servico::SETOR_PERFORMANCE);
    })
    ->get();
```

`NpsSnapshotService::registrar()` (`app/Services/Nps/NpsSnapshotService.php:150-193`) é o precedente mais recente de "interseção cobertos ∩ ativos" e do padrão de log estruturado (`Log::warning('[NPS Snapshot] ...')`) para responsável faltante — seguir a mesma convenção de logging se o service precisar sinalizar inconsistência (ex.: vínculo Shopee com `servico_id` de um serviço que não existe mais no catálogo).

## Common Pitfalls

### Pitfall 1: `company_users.role` ≠ `users.role`
**O que dá errado:** confundir os dois enums. `users.role` é `admin`/`consultor`/`mentor` (papel do sistema). `company_users.role` (pivot) é `consultor`/`estrategista` (papel NA EMPRESA) — **não** existe `'mentor'` na pivot desde a renomeação de 2026-05-22.
**Como evitar:** filtrar SEMPRE `company_users.role IN ('consultor', 'estrategista')`; nunca reusar `$user->role` para decidir o `role` do vínculo.
**Sinal de alerta:** teste que popula pivot com `role => 'mentor'` (copiar de código antigo) vai silenciosamente não bater com nada.

### Pitfall 2: resolver setor ANTES de decidir servico_id preenchido vs NULL
**O que dá errado:** tentar filtrar por `setor` como primeiro passo da query é impossível para linhas `servico_id NULL` — o setor delas só existe via `contratos_servico`, não via a pivot diretamente.
**Como evitar:** resolver o vínculo completo primeiro (union das duas fontes), aplicar filtro `setor` como último passo em memória (Collection), não em SQL WHERE cru.

### Pitfall 3: `distinct('companies.id')` decisão errada aqui
**O que dá errado:** copiar o padrão de `User::companies()` (`->select('companies.*')->distinct('companies.id')`) para este service colapsaria os 2 vínculos de uma empresa Performance+Shopee em 1 única linha — exatamente o bug que a Fase 88 existe para corrigir (CTX-04 pede o oposto: manter os vínculos, deduplicar só a CONTAGEM de empresas).
**Sinal de alerta:** teste "mesmo profissional nos dois serviços da mesma empresa" retorna 1 vínculo em vez de 2.

### Pitfall 4: MIN(ct.servico_id) do backfill pode não ser o contrato que a UI mostra depois
**O que dá errado:** se uma empresa (raro, mas possível) tiver 2 contratos performance ativos simultâneos (ex.: Gestão + Mentoria ambos ativos), o backfill já fixou `servico_id = MIN(id)` na pivot — então uma linha `servico_id` preenchida pode apontar pro contrato "errado" sob a ótica de qual dos dois é o principal. Isso é herdado do backfill da Fase 76, não um bug novo da Fase 88 — mas o resolver de `servico_id` PREENCHIDO deve usar o `servico_id` da pivot tal como está (fonte de verdade), não recalcular.
**Como evitar:** não tentar "corrigir" isso na Fase 88 — está fora de escopo; documentar se aparecer em teste.

### Pitfall 5: dev paralelo no módulo MLB/Anúncios
As Fases 82/83 (planilha glide-data-grid em `resources/js/Pages/Mlb/AnunciarMassa.jsx`) estão em andamento por outro dev em paralelo. A Fase 88 não toca nenhum arquivo do módulo MLB/Anúncios — não há overlap de arquivos esperado, mas evitar rodar `npm run build` de forma que sobrescreva trabalho não commitado desse módulo sem necessidade.

## Testes

- **Local:** `tests/Feature/V17/` (novo diretório, seguindo o padrão `tests/Feature/V16/` da milestone anterior).
- **Trait de fixture:** reusar `tests/Feature/V16/CriaCenarioResponsaveis.php` via `use` (está em namespace `Tests\Feature\V16`, importável de qualquer teste) — já tem `criarServico()`, `criarContrato()`, `inserirPivot()`, `criarCenarioMlComResponsaveis()`, `inserirLinhaShopee()`, exatamente os helpers que os 4 cenários obrigatórios do plano canônico precisam. Não recriar equivalentes em V17 — importar o trait.
- **DB:** `phpunit.xml` já configura `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` — nenhuma migration deste service precisa branch cross-driver (é leitura, não schema).
- **4 cenários obrigatórios (ROADMAP SC1 + plano canônico):**
  1. Profissional só Performance (via `criarCenarioMlComResponsaveis()`)
  2. Profissional só Shopee (via `inserirLinhaShopee()` isolado, sem contrato performance)
  3. Performance + Shopee na MESMA empresa, responsáveis DIFERENTES
  4. MESMO profissional nos dois serviços da MESMA empresa (`inserirPivot()` 2x pro mesmo `user_id`/`company_id`, `servico_id` diferente)
- **Casos adicionais de CTX-05 a cobrir:** `servico_id NULL` + contrato performance ativo → resolve como performance; `servico_id NULL` + contrato Shopee ativo (sem contrato performance) → **não aparece como Shopee** (vínculo ignorado ou aparece sem setor resolvido — decisão de implementação a definir no plan, mas o teste deve provar que NÃO vira `setor='shopee'` automaticamente).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|----------------|
| A1 | Volume real de `company_users.servico_id NULL` em prod não foi contado ao vivo (MySQL local fora do ar) | Realidade dos dados do pivot | Se o volume for muito maior que o esperado pela análise de código, os testes de regressão podem precisar de mais cenários; risco baixo pois a análise de código é determinística (só 1 backfill + 1 writer Shopee existem) |
| A2 | Nenhum vínculo `company_users.servico_id` aponta hoje para setor `polos`/`publicacao`/`outros` (nenhum writer grava isso) | Setores não cobertos | Se algum script manual (fora do grep) já gravou isso, o branch `default` ainda cobre com segurança (sem fonte financeira) — risco baixo |

**Se a tabela acima só tem itens de baixo risco:** ambos os itens têm mitigação segura já embutida na recomendação (branch default cobre, análise de código é auditável). Recomenda-se ainda assim rodar a contagem real assim que o MySQL local ou VPS estiver acessível, antes de travar números exatos em testes de regressão "quantidade de vínculos".

## Ambiente

MySQL/MariaDB local (XAMPP) está indisponível nesta sessão de pesquisa — `mysqld` aborta com `Incorrect file format 'db'` (mesmo incidente de `project_mariadb_local_corrompido.md`, 2026-06-25, não resolvido desde então). Isso NÃO bloqueia a Fase 88 (testes rodam em SQLite `:memory:` via `phpunit.xml`, sem dependência do MySQL local), mas bloqueia qualquer verificação de contagem real contra dados de produção/dev nesta pesquisa. Recomenda-se ao executor rodar a query de contagem (`SELECT servico_id IS NULL, COUNT(*) FROM company_users GROUP BY 1`) assim que tiver acesso a um MySQL vivo (dev restaurado ou VPS), como checkpoint informativo — não bloqueante para a implementação em si.

## Sources

### Primary (HIGH confidence — leitura direta de código neste repositório)
- `app/Models/User.php`, `app/Models/Company.php`, `app/Models/Servico.php`, `app/Models/ContratoServico.php`
- `app/Services/DesempenhoScoreService.php` (convenção de shape array documentado, cache, computeUniverso atual)
- `app/Services/Nps/NpsSnapshotService.php` (precedente de resolução por-serviço + logging)
- `app/Http/Controllers/PortfolioController.php` (uso atual de `$user->companies()` a substituir nas Fases 89/90)
- `app/Http/Controllers/ShopeeEmpresasController.php` (único writer de `servico_id` para Shopee)
- `database/migrations/2026_07_14_000001_add_servico_id_to_company_users.php` (backfill + invariantes)
- `database/migrations/2026_06_18_100002_seed_servicos_setor.php` (confirma Gestão+Mentoria → `setor='performance'`)
- `tests/Feature/V16/CriaCenarioResponsaveis.php` (trait de fixture reutilizável)
- `phpunit.xml` (config SQLite `:memory:`)
- `plano-carteira-desempenho-multi-servico.md` (plano canônico do usuário)

### Tertiary (LOW confidence — não verificado ao vivo)
- Contagem real de linhas `servico_id NULL` em produção/dev (MySQL local indisponível nesta sessão)

## Metadata

**Confidence breakdown:**
- Standard stack / shape do service: HIGH — sem lib nova, convenção já estabelecida no projeto (array documentado, sem DTO)
- Elegibilidade financeira: HIGH — schema já resolve Gestão+Mentoria via `setor`, confirmado por leitura da migration de seed
- Realidade dos dados do pivot: MEDIUM — análise de código determinística, mas sem contagem ao vivo (MySQL local fora do ar)
- Pitfalls: HIGH — todos derivados de comentários explícitos já presentes no código (`consultor()`, `NpsSnapshotService`, migration)

**Research date:** 2026-07-16
**Valid until:** 30 dias (schema estável; nenhuma dependência externa versionada)
