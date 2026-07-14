# Phase 79: NPS multi-modelo — disparo por serviços cobertos + snapshot de atribuições por serviço - Research

**Researched:** 2026-07-14
**Domain:** Laravel 12 / Eloquent — schema NPS v15, disparo agendado (Artisan), submit público, snapshot imutável
**Confidence:** HIGH (tudo verificado contra o código real do repo; nenhuma lib nova)

## Summary

Esta fase estende o motor NPS v15 (já vivo) para operar **multi-modelo por serviços cobertos**. Toda a infraestrutura de suporte já existe e foi lida linha a linha: `nps_templates` + `nps_template_service_scopes` (pivot modelo↔serviço), `nps_surveys.template_id`, dedup composto `(company_id, month_reference, template_id)`, snapshot per-row em `nps_response_answers` com `question_dimensao_snapshot`/`option_peso_snapshot`, o `NpsScoreCalculator::compute($response, $dim)` (SUM(peso)/N_perguntas do template na dimensão), e — da Phase 76 — `company_users.servico_id` + `Company::consultorDoServico($id)`/`estrategistaDoServico($id)`.

O trabalho é: (1) criar 3 tabelas de snapshot (`nps_response_scores`, `nps_response_covered_services`, `nps_score_assignments`) seguindo o padrão cross-driver do projeto; (2) semear o modelo "NPS Shopee" espelhando o seed 100004 do "NPS Padrão" + linkar o serviço Shopee no scope, e linkar os serviços setor=performance ao "NPS Padrão"; (3) reescrever o loop do `NpsDispararMensal` de "força o principal para todos" → "1 envio por modelo com `envio_automatico_mensal` cujos serviços cobertos ∩ contratos ativos da empresa ≠ ∅"; (4) plugar no `NpsController::submitResponseV15`, **dentro da transação existente e depois da gravação das answers**, o cálculo dos scores por dimensão + snapshot dos serviços cobertos + geração das atribuições aos responsáveis por-serviço.

**Primary recommendation:** Reusar 100% do padrão já consolidado — migration cross-driver estilo Phase 76 (`2026_07_14_000001`), seed idempotente estilo 100004, e um novo Service `NpsSnapshotService` (helper de atribuição) chamado pelo submit. NÃO tocar `DesempenhoScoreService`/`->principal()` — o bônus continua lendo o `is_default` (NPS Padrão permanece principal). As atribuições novas são **aditivas** e ficam prontas para a Fase 80 consumir.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Schema das 3 tabelas de snapshot | Database (migration) | — | FK + índices + cross-driver; congelamento histórico |
| Seed "NPS Shopee" + scopes performance | Database (migration/seed) | — | Dados semânticos idempotentes, espelham 100004 |
| Disparo estrito por serviços cobertos | Console Command (`NpsDispararMensal`) | Service (query de modelos aplicáveis) | Loop agendado; decide N envios/empresa |
| Cálculo de scores + snapshot + atribuições | Controller (`submitResponseV15`) → Service novo | `NpsScoreCalculator` (já existe) | Persiste no submit dentro da transação |
| Resolução responsável por-serviço | Model (`Company::consultorDoServico/estrategistaDoServico`) | pivot `company_users(role, servico_id)` | Já implementado na Phase 76 |
| Regressão do bônus | Service (`DesempenhoScoreService`) — **INTOCADO** | — | Continua lendo `->principal()` (is_default) |

## Standard Stack

Nenhuma biblioteca nova. Toda a fase usa o que já está no `composer.json`/`package-lock.json`.

| Recurso | Origem | Papel nesta fase |
|---------|--------|------------------|
| Eloquent Migrations/Schema | Laravel 12 (já instalado) | 3 tabelas novas + seed | `[VERIFIED: composer.lock]` |
| `NpsScoreCalculator` | `app/Services/Nps/NpsScoreCalculator.php` | Média por dimensão (reusar) | `[VERIFIED: leitura do arquivo]` |
| `Company::consultorDoServico/estrategistaDoServico` | `app/Models/Company.php:197-209` | Resolver responsável por-serviço | `[VERIFIED]` |
| `Servico::SETOR_PERFORMANCE/SETOR_SHOPEE` | `app/Models/Servico.php:52,57` | Filtro de setor | `[VERIFIED]` |
| PHPUnit 11 + Mockery + Faker | `composer.json` (require-dev) | Feature tests em `tests/Feature/V16/` | `[VERIFIED]` |

**Installation:** nenhuma. Rodar migrations: `php artisan migrate`. Testes: `php artisan test --filter=V16`.

## Package Legitimacy Audit

Não aplicável — esta fase **não instala nenhum pacote externo** (constraint explícita "sem libs novas" no CONTEXT). Todas as dependências já estão no `composer.lock`/`package-lock.json` versionados.

## Architecture Patterns

### Fluxo de dados (submit v15 estendido)

```
POST /nps/{token}  (público)
   │
   ▼
NpsController::submitResponse ──(template_id != null)──► submitResponseV15
   │                                                          │
   │                                                          ▼
   │                                              DB::transaction {
   │                                                 1. NpsResponse::create (scores legacy NULL)
   │                                                 2. foreach answers → NpsResponseAnswer::create (snapshot)
   │  ┌───────────────── NOVO (Phase 79) ──────────────────────────────────┐
   │  │  3. NpsSnapshotService::registrar($response, $survey) {            │
   │  │       a. p/ cada dim [estrategista, analista, empresa]:            │
   │  │            média = NpsScoreCalculator::compute($response, $dim)    │
   │  │            → nps_response_scores (sum, count, average)             │
   │  │       b. serviços cobertos = template.serviceScopes                │
   │  │            → nps_response_covered_services (snapshot setor)        │
   │  │       c. interseção = cobertos ∩ contratos ATIVOS da empresa       │
   │  │            p/ cada serviço: analista(role=consultor,servico_id)    │
   │  │                             estrateg.(role=estrategista,servico_id)│
   │  │            → nps_score_assignments (média×user×role×setor)         │
   │  │            responsável faltante → Log::warning (pendência)         │
   │  │  }                                                                  │
   │  └────────────────────────────────────────────────────────────────────┘
   │                                                 4. survey->update(completed) ← pode 23000
   │                                              }
   ▼
Nps/ThankYou   (ou Nps/AlreadyCompleted em 23000)
```

### Fluxo de dados (disparo estrito)

```
nps:disparar-mensal (schedule 09:00 BRT)
   │
   ▼  modelos = NpsTemplate::where(envio_automatico_mensal=true, active=true)->with('serviceScopes')
   │
   Company::where(active) ->chunkById
      │
      foreach empresa (dia-alvo == hoje):
         guards: estrategista existe? canal (email/digisac)? 
         servicosAtivos = contratosServico()->active()->pluck(servico_id)
         modelosAplicaveis = modelos.filter(m => m.scopes ∩ servicosAtivos ≠ ∅)   ← ESTRITO
         │
         ├─ modelosAplicaveis vazio → Log::warning "empresa X ficaria SEM NPS" ; continue
         │
         foreach modelo em modelosAplicaveis:
            dedup: NpsSurvey::where(company_id, month_reference, template_id=modelo)->exists()? skip
            NpsSurvey::create(template_id=modelo, ...)
            envia email/digisac (título por área — ver Open Questions)
```

### Pattern 1: Migration cross-driver (SQLite testes + MySQL prod)

O projeto tem um padrão consolidado e testado. Referência canônica: `2026_07_14_000001_add_servico_id_to_company_users.php` (FK só no MySQL branch, guards de existência) e `2026_07_07_100005` (índice parcial: `CREATE UNIQUE INDEX ... WHERE` no SQLite vs coluna virtual gerada no MySQL).

Para as 3 tabelas novas, use `Schema::create` com `foreignId()->constrained()`. FK simples em `create` funciona cross-driver (SQLite aceita FK em CREATE TABLE; o gotcha 1553/1553 só aparece em ALTER de índice usado por FK — não é o caso aqui).

```php
// Source: padrão de 2026_07_07_100001 (tabelas v15) — VERIFIED
Schema::create('nps_response_scores', function (Blueprint $t) {
    $t->id();
    $t->foreignId('nps_response_id')->constrained('nps_responses')->cascadeOnDelete();
    $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
    $t->enum('dimensao', ['estrategista', 'analista', 'empresa']); // NÃO incluir 'geral' se não atribui
    $t->decimal('score_sum', 8, 2);          // SUM(option_peso_snapshot)
    $t->unsignedInteger('question_count');   // N_perguntas do template na dimensão
    $t->decimal('average_score', 5, 2);      // score_sum / question_count
    $t->timestamp('calculated_at');
    $t->timestamps();
    $t->unique(['nps_response_id', 'dimensao'], 'nps_resp_scores_dim_uniq'); // 1 linha/dimensão
    $t->index(['company_id', 'dimensao']);
});
```

**Nota crítica sobre `NpsScoreCalculator`:** ele retorna `SUM(peso) / N_perguntas_do_template` (não `AVG(answers)` — ver bugfix documentado no arquivo, linhas 74-102). Portanto:
- `question_count` = `NpsTemplateQuestion::where(template_id, dimensao)->count()` (perguntas do template, NÃO count de answers).
- `score_sum` = `$response->answers()->where('question_dimensao_snapshot', $dim)->sum('option_peso_snapshot')`.
- `average_score` = o próprio retorno de `compute()`. Reusar `compute()` para o average e computar sum/count separadamente para gravar (ou extrair um método que devolva o triplo — Claude's discretion).
- `compute()` retorna `null` quando a dimensão não existe no template (ex.: NPS Shopee sem pergunta analista se a empresa não tiver analista) → **não gravar linha de score** para dimensão nula.

### Pattern 2: Seed idempotente espelhando 100004

O seed do "NPS Padrão" (`2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php`) é o molde exato. Estrutura idempotente via guards `where(...)->first()`:

```php
// Molde: 100004 (VERIFIED). Para "NPS Shopee":
DB::transaction(function () {
    // 1. Template (guard por nome, NÃO is_default — o Shopee é is_default=false)
    $shopeeRow = DB::table('nps_templates')->where('nome', 'NPS Shopee')->first();
    $shopeeId = $shopeeRow?->id ?? DB::table('nps_templates')->insertGetId([
        'nome' => 'NPS Shopee',
        'descricao' => 'Modelo NPS para o serviço Shopee (Gestão de ADS Shopee).',
        'active' => true,
        'is_default' => false,          // NÃO principal — o bônus continua no NPS Padrão
        'priority' => 10,               // > 0 p/ preceder o fallback na resolução manual
        'envio_automatico_mensal' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // 2. 3 perguntas (estrategista obrigatória, analista opcional, empresa obrigatória)
    //    idempotente por (template_id, dimensao) — MESMOS textos/placeholders do padrão
    //    {nome_estrategista}/{nome_analista} resolvidos em runtime pelo NpsTextRenderer.
    // 3. 5 options por pergunta (peso 1..5) idempotente por (question_id, peso)
    //    → ver loop for($i=1;$i<=5) do 100004

    // 4. Service scope → serviço Shopee (setor='shopee'). Idempotente.
    $servicoShopeeId = DB::table('servicos')->where('setor', 'shopee')->where('ativo', true)->value('id');
    if ($servicoShopeeId) {
        DB::table('nps_template_service_scopes')->updateOrInsert(
            ['template_id' => $shopeeId, 'servico_id' => $servicoShopeeId],
            ['created_at' => now(), 'updated_at' => now()]
        );
    }
});
```

**Linkar NPS Padrão a performance (DEC-79-A):** na mesma migration (ou numa segunda), para cada serviço `setor=performance ativo`, `updateOrInsert` em `nps_template_service_scopes(template_id=PADRAO, servico_id)`. Buscar o PADRAO por `where('is_default', true)->value('id')`. Idempotente. Isso garante que a query estrita do disparo encontre o NPS Padrão para empresas ML.

### Anti-Patterns to Avoid

- **Rodar o snapshot ANTES de inserir as answers:** `NpsScoreCalculator::compute` lê de `$response->answers()`. O bloco novo (passo 3) DEVE vir depois do `foreach` que grava as answers e antes/depois do `survey->update(completed)` — mas **dentro da mesma `DB::transaction`** para atomicidade. Se o dedup 23000 estourar no `update`, tudo reverte junto (correto).
- **Usar `resolveForCompany` no disparo:** ele retorna **UM** template (o de maior priority + fallback is_default). O disparo multi-modelo precisa de **TODOS** os modelos aplicáveis → query própria `whereHas('serviceScopes', whereIn servicoIds)`. Não reutilizar `resolveForCompany` no loop de disparo.
- **Idempotência de disparo por-empresa (bug atual):** o guard de hoje é `NpsSurvey::where(company_id)->whereDate(month_reference)->exists()` (linha 187-189) — **por empresa**, não por template. Isso BLOQUEARIA o 2º modelo. **Trocar para `where(company_id)->whereDate(month_reference)->where(template_id, $modelo->id)->exists()`.** O dedup composto do banco já suporta isso.
- **Inventar assignment vazio:** responsável faltante (serviço coberto sem consultor/estrategista na pivot) → NÃO criar `nps_score_assignments` com `user_id` NULL; apenas `Log::warning` estruturado (pendência). DEC-79-D item 3.
- **Nota da empresa como nota de pessoa:** dimensão `empresa` fica só em `nps_response_scores` (ligada a `company_id`), sem `nps_score_assignments`. DEC-79-D item 4.

## Don't Hand-Roll

| Problema | Não construir | Usar | Por quê |
|----------|---------------|------|---------|
| Média por dimensão | AVG manual das answers | `NpsScoreCalculator::compute($response, $dim)` | Já implementa SUM/N com semântica de perguntas puladas (bugfix 2026-07-08) |
| Índice parcial cross-driver | Lógica ad-hoc | Padrão `if driver==sqlite ... else ...` de 100005 | Testado em prod; evita bug do `virtualAs` no SQLite in-memory |
| Resolução do responsável por-serviço | Query na pivot manual | `Company::consultorDoServico($id)`/`estrategistaDoServico($id)` | Phase 76 já entregou; filtra `(role, servico_id)` |
| Serviços ativos da empresa | Query manual | `$company->contratosServico()->active()->pluck('servico_id')` | Scope `active()` (coluna `ativo`) já existe |

**Key insight:** quase tudo já existe. A fase é orquestração + 3 tabelas + 1 seed, não engenharia nova.

## Runtime State Inventory

> Fase é majoritariamente aditiva (novas tabelas + seed + lógica). Estados runtime relevantes:

| Categoria | Itens encontrados | Ação necessária |
|-----------|-------------------|-----------------|
| Stored data | `nps_templates` (só NPS Padrão is_default hoje); `nps_template_service_scopes` (serviço Shopee já pode aparecer no picker, mas o NPS Padrão provavelmente NÃO tem scopes performance ainda) | Seed: criar NPS Shopee + linkar performance ao Padrão. Verificar em prod se algum scope já foi criado manualmente (idempotência com `updateOrInsert` cobre) |
| Live service config | Schedule `nps:disparar-mensal` em `routes/console.php` (09:00 BRT) — muda de comportamento; `Configuracao::nps_envio_email_ativo`/`nps_envio_digisac_ativo` inalterados | Nenhuma alteração de registro; só a lógica interna do comando muda |
| OS-registered state | Cron `* * * * * php artisan schedule:run` (Supervisor no VPS) | Nenhuma — mesmo comando, mesmo horário |
| Secrets/env vars | Nenhum novo | None — verificado: sem `.env` novo |
| Build artifacts | Frontend `Respond.jsx` já consome `template.perguntas` (Phase 71) — inalterado. Rodar `npm run build` só se tocar UI (não previsto nesta fase) | Provavelmente nenhum — fase é backend-only |

**Ponto de atenção (prod):** o NPS Padrão pode NÃO ter service_scopes de performance hoje. Sem o seed que os cria, o disparo estrito faria TODAS as empresas ML caírem em "sem NPS". O seed do NPS Padrão→performance é **obrigatório e deve rodar junto** com a mudança do disparo (não separar em deploys distintos).

## Common Pitfalls

### Pitfall 1: Disparo estrito zera empresas ML sem scope performance
**O que dá errado:** ativar a lógica estrita antes de o NPS Padrão ter scopes de performance → toda empresa ML fica sem NPS.
**Por que acontece:** DEC-79-A é estrito (sem fallback is_default no disparo).
**Como evitar:** a migration de seed (linka performance→Padrão) e a mudança do comando entram no MESMO deploy. O `Log::warning` de "empresa ficaria sem NPS" é a blindagem de observabilidade — validar os logs no primeiro ciclo (dry-run em prod com `--dry-run`).
**Sinal de alerta:** contador `puladosSemModelo` alto no output do comando.

### Pitfall 2: Idempotência de disparo por-empresa bloqueia 2º modelo
**O que dá errado:** empresa ML+Shopee recebe só 1 NPS porque o guard atual é por (company, mês).
**Como evitar:** incluir `template_id` no guard de existência (ver Anti-Patterns). O dedup composto do banco `(company_id, month_reference, template_id)` já permite N modelos/empresa/mês.

### Pitfall 3: Cálculo de score antes das answers existirem
**O que dá errado:** `NpsScoreCalculator::compute` retorna 0/null porque roda antes do `foreach` que grava answers.
**Como evitar:** ordem no submit — answers primeiro, snapshot depois, tudo na mesma transação.

### Pitfall 4: enum `dimensao` divergente entre tabelas
**O que dá errado:** `nps_response_answers.question_dimensao_snapshot` é `enum('estrategista','analista','empresa','geral')`. Se a nova `nps_response_scores.dimensao` usar valores diferentes, os JOINs/filtros quebram.
**Como evitar:** reusar exatamente `estrategista/analista/empresa` (o `geral` não gera atribuição nem score de dimensão de pessoa — decidir se inclui; recomendo NÃO incluir `geral` em scores/assignments).

### Pitfall 5: SQLite enum + CHECK nos testes (memória do projeto)
**O que dá errado:** `enum()` no MySQL vira coluna nativa; no SQLite o Laravel gera CHECK constraint. Valores fora da lista quebram Feature tests.
**Como evitar:** usar exatamente os valores válidos nos testes/seed. Se a coluna `role` de `nps_score_assignments` for enum, definir `['consultor','estrategista']` (ou `['analyst','strategist']`) e usar consistentemente. Ver memória `project_enum_setor_sqlite_check`.

## Code Examples

### Resolver responsáveis de um serviço coberto (no submit)
```php
// Source: Company.php:197-209 (Phase 76) — VERIFIED
$analistas     = $company->consultorDoServico($servicoId)->get();     // role=consultor
$estrategistas = $company->estrategistaDoServico($servicoId)->get();   // role=estrategista

// Para cada, 1 assignment com a média da dimensão correspondente:
// analista → nps_response_scores(dim=analista); estrategista → dim=estrategista
```

### Query dos modelos aplicáveis (no disparo)
```php
// Todos os modelos automáticos cujos serviços cobertos batem com um contrato ativo
$servicoIds = $empresa->contratosServico()->active()->pluck('servico_id');

$modelosAplicaveis = NpsTemplate::query()
    ->where('active', true)
    ->where('envio_automatico_mensal', true)
    ->whereHas('serviceScopes', fn ($q) => $q->whereIn('servico_id', $servicoIds))
    ->get();

if ($modelosAplicaveis->isEmpty()) {
    Log::warning("[NPS Mensal] empresa {$empresa->id} ({$empresa->name}) sem modelo aplicável — NENHUM NPS gerado (serviços ativos sem cobertura).");
    continue; // DEC-79-A estrito: sem fallback
}
```

## State of the Art

| Abordagem antiga | Abordagem nova (Phase 79) | Impacto |
|------------------|---------------------------|---------|
| Disparo força `is_default` p/ todos (2026-07-13) | 1 envio por modelo com serviços cobertos ∩ ativos | Empresa ML+Shopee recebe 2 NPS |
| Associação dimensão→pessoa em tempo de LEITURA (`DesempenhoScoreService`) | Snapshot congelado no submit (`nps_score_assignments`) | Histórico imutável; Fase 80 lê das atribuições |
| Média por dimensão só via `NpsScoreCalculator` no dashboard | Mesma média, mas persistida em `nps_response_scores` | Persistência aditiva; cálculo idêntico |

**Não deprecar nesta fase:** `->principal()`, `DesempenhoScoreService::computeNpsMedio`, submit legacy Phase 31. Tudo continua funcionando (DEC-79-E). A aposentadoria do "só o principal conta" é da Fase 80.

## Assumptions Log

| # | Claim | Section | Risco se errado |
|---|-------|---------|-----------------|
| A1 | O NPS Padrão (is_default) NÃO tem service_scopes de performance em prod hoje | Runtime State / Pitfall 1 | Se já tiver, o seed é no-op (idempotente) — risco baixo; validar via `SELECT` em prod |
| A2 | O serviço Shopee (setor='shopee', ativo=true) já foi semeado (Phase 75, migration `2026_07_14_100002`) | Seed | Se ausente, o scope do NPS Shopee não é criado; o seed deve fazer guard `if ($servicoShopeeId)` |
| A3 | A coluna `role` de `nps_score_assignments` deve espelhar os valores da pivot (`consultor`/`estrategista`) OU normalizar (`analyst`/`strategist`) — DEC-79-D deixa "analyst↔consultor, strategist↔estrategista" | Schema | Escolha de nomenclatura; Fase 80 (relatórios) precisa saber. Recomendo persistir o valor da pivot (`consultor`/`estrategista`) para JOIN direto, e mapear rótulo na leitura |
| A4 | Título por área do email ("NPS Performance" vs "NPS Shopee") — os textos vêm de `Configuracao::nps_textos` (conjunto único global) | Open Questions | O disparo atual não diferencia título por template; ver Open Questions |

## Open Questions

1. **Título/textos do email por modelo**
   - O que sabemos: `NpsDispararMensal` monta o assunto via `NpsTextRenderer::render($textos['email_assunto'])` — `$textos` vem de `Configuracao::nps_textos` (global, único). O `NpsTemplate` tem `mensagem_whatsapp` (por-template, p/ Digisac) mas **não** tem campo de assunto/corpo de email por-template.
   - O que não está claro: o brief pede títulos por área ("NPS ECF Performance" vs "NPS Gestão de ADS Shopee"). O CONTEXT (DEC-79-B) foca no seed, não no texto do email.
   - Recomendação: MVP desta fase pode usar `$template->nome` no assunto (ex.: prefixar com o nome do modelo) sem criar campos novos. Se a gerência exigir textos customizados por modelo, é um item pequeno para a Fase 80 ou um follow-up. **Confirmar com o usuário no discuss/plan.**

2. **Nomes exibidos na página de resposta (`respond` GET, linhas 449-454)**
   - O que sabemos: a página busca estrategista/analista via `users()->wherePivot('role', ...)->first()` (consolidado, primeiro da pivot).
   - O que não está claro: para o NPS Shopee, os placeholders `{nome_estrategista}`/`{nome_analista}` deveriam mostrar os responsáveis Shopee, não o primeiro da pivot.
   - Recomendação: o survey conhece o `template_id`. Se o template tem scope de um serviço, resolver os responsáveis **daquele serviço** (`estrategistaDoServico`/`consultorDoServico`) para o display. Item de UX; pode ser incluído nesta fase (baixo custo) ou deferido. **Flag para o planner.**

3. **Papel `role` de `nps_score_assignments`: `consultor`/`estrategista` vs `analyst`/`strategist`** (ver A3) — decisão de nomenclatura a travar no plan.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | Backend | ✓ | 8.2+ | — |
| Laravel migrate | Schema/seed | ✓ | 12.x | — |
| SQLite | Feature tests (`tests/Feature/V16/`) | ✓ (padrão do projeto) | — | — |
| MySQL/MariaDB | Prod (VPS) | ✓ (177.7.53.164) | — | — |

Sem dependências externas novas. Nota: memória `project_mariadb_local_corrompido` — verificar `tasklist | grep mysqld` antes de comandos que dependam de DB local; os testes usam SQLite in-memory e não são afetados.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5 (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test --filter=V16` |
| Full suite command | `php artisan test` |
| Fixture reusável | `tests/Feature/V16/CriaCenarioResponsaveis.php` (trait: `criarCenarioMlComResponsaveis`, `inserirLinhaShopee`, `criarServico`, `criarContrato`, `inserirPivot`) — **reusar** |

### Phase Requirements → Test Map
| Behavior | Test Type | Automated Command | File Exists? |
|----------|-----------|-------------------|-------------|
| 3 tabelas criadas (schema + FKs) cross-driver | migration/feature | `php artisan test --filter=V16 tests/Feature/V16/SnapshotSchemaTest.php` | ❌ Wave 0 |
| Seed NPS Shopee (template+3 perguntas+15 opções+scope shopee) idempotente | feature | `.../SeedNpsShopeeTest.php` | ❌ Wave 0 |
| Seed NPS Padrão→performance scopes idempotente | feature | `.../SeedNpsPadraoPerformanceTest.php` (ou junto do anterior) | ❌ Wave 0 |
| Disparo: empresa performance → 1 survey NPS Padrão | feature | `.../DisparoEstritoTest.php` | ❌ Wave 0 |
| Disparo: empresa shopee → 1 survey NPS Shopee | feature | `.../DisparoEstritoTest.php` | ❌ Wave 0 |
| Disparo: empresa performance+shopee → 2 surveys | feature | `.../DisparoEstritoTest.php` | ❌ Wave 0 |
| Disparo: empresa sem serviço coberto → 0 survey + Log::warning | feature | `.../DisparoEstritoTest.php` (Log::spy) | ❌ Wave 0 |
| Disparo: dedup por (company, mês, template) não duplica | feature | `.../DisparoEstritoTest.php` | ❌ Wave 0 |
| Submit: grava nps_response_scores por dimensão (sum/count/avg) | feature | `.../SubmitSnapshotTest.php` | ❌ Wave 0 |
| Submit: congela nps_response_covered_services (setor snapshot) | feature | `.../SubmitSnapshotTest.php` | ❌ Wave 0 |
| Submit: assignments só p/ responsáveis dos serviços cobertos ∩ ativos | feature | `.../AtribuicaoPorServicoNpsTest.php` | ❌ Wave 0 |
| Atribuição: NPS Shopee → analista Shopee (servico_id shopee), NÃO analista ML | feature | `.../AtribuicaoPorServicoNpsTest.php` | ❌ Wave 0 |
| Atribuição: responsável faltante → sem assignment + Log pendência | feature | `.../AtribuicaoPorServicoNpsTest.php` | ❌ Wave 0 |
| Empresa: média empresa em scores (dim=empresa), sem assignment de pessoa | feature | `.../SubmitSnapshotTest.php` | ❌ Wave 0 |
| Regressão: submit legacy Phase 31 + submit v15 + `->principal()`/bônus inalterados | feature | `php artisan test` (suite NPS + Desempenho verde) | ⚠️ suites existentes |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=V16`
- **Per wave merge:** `php artisan test` (suite completa — garante NPS + Desempenho verdes, regressão do bônus)
- **Phase gate:** suite completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/V16/SnapshotSchemaTest.php` — schema + FKs cross-driver das 3 tabelas
- [ ] `tests/Feature/V16/SeedNpsShopeeTest.php` — seed idempotente (2ª execução afeta 0 rows) + scope shopee + performance→Padrão
- [ ] `tests/Feature/V16/DisparoEstritoTest.php` — matriz performance/shopee/ambos/nenhum + dedup + Log::warning
- [ ] `tests/Feature/V16/SubmitSnapshotTest.php` — scores + covered_services + empresa sem assignment
- [ ] `tests/Feature/V16/AtribuicaoPorServicoNpsTest.php` — isolamento por serviço + pendência
- [ ] Reusar trait `CriaCenarioResponsaveis` (já existe) — pode precisar de helper novo para criar template+survey+answers (candidato a método no trait ou um `CriaCenarioNps` novo)
- [ ] Framework install: nenhum (PHPUnit já configurado)

## Security Domain

`security_enforcement` não está explicitamente `false` no config — porém esta fase não introduz superfície de ataque nova relevante. Avaliação enxuta:

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V4 Access Control | parcial | Submit NPS é público via token (comportamento existente, inalterado); rotas admin já protegidas por `EnsureUserHasRole` |
| V5 Input Validation | sim | Submit já valida via `Rule::in($optionIds)` (snapshot per-row barra option_id de outro template) — **inalterado** |
| V6 Cryptography | não | Sem cripto nova |

### Known Threat Patterns
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Tampering de option_id no submit | Tampering | `Rule::in($optionIds)` do template já barra (VERIFIED linha 643) |
| Assignment forjado via serviço não contratado | Tampering | Interseção **serviços cobertos ∩ contratos ATIVOS** — nunca atribui a serviço não ativo |
| SQL injection | Tampering | Eloquent/query builder parametrizado em todo o fluxo |

Sem novos endpoints públicos. Sem novos secrets. Nota de compliance: dados de snapshot são PII indireta (nome de responsável + nota) — mesma sensibilidade dos dados NPS já existentes; sem mudança de retenção.

## Project Constraints (from CLAUDE.md)

- **Stack fixa:** Laravel 12 + Inertia + React — sem mudança de stack, sem libs novas (também no CONTEXT).
- **Comentários e artefatos GSD em pt-BR.**
- **Migrations:** naming `YYYY_MM_DD_HHMMSS_verb_noun_table.php`; cross-driver (SQLite testes + MySQL prod).
- **Error handling:** catch `\Throwable`, log com tag de módulo `[NPS Mensal]`/`[NPS]`; jobs/commands seguem log de stats.
- **Deploy:** NÃO deployar sem autorização; **v16.0 tem dev em paralelo (anunciar-ml)** → `fetch`+reconciliar antes de cada deploy; confirmar caso-a-caso (memória `feedback_perguntar_antes_deploy_v9` reforçada no brief).
- **Testes:** `tests/Feature/V16/` (NÃO `Phase{76..80}` — colide com o outro dev).

## User Constraints (from CONTEXT.md)

### Locked Decisions (verbatim do CONTEXT)
- **DEC-79-A** — Disparo ESTRITO por serviços cobertos: 1 envio por empresa×modelo com `envio_automatico_mensal=true` SE a empresa tem contrato ATIVO de serviço em `nps_template_service_scopes` do modelo. Sem serviço coberto → NENHUM NPS (sem fallback). Log::warning das empresas que ficariam sem NPS. Preservar guards (active + canal + estrategista). NPS Padrão cobre serviços setor=performance via seed.
- **DEC-79-B** — Seed idempotente do "NPS Shopee" (`is_default=false`, `active=true`, `envio_automatico_mensal=true`, `priority>0`) espelhando o NPS Padrão (3 perguntas + 5 opções peso 1..5) + `nps_template_service_scopes` → serviço Shopee. Molde: migration 100004.
- **DEC-79-C** — 3 tabelas de snapshot: `nps_response_scores` (id, nps_response_id FK, company_id, dimensao, score_sum, question_count, average_score, calculated_at); `nps_response_covered_services` (id, nps_response_id FK, servico_id FK, service_setor, captured_at); `nps_score_assignments` (id, nps_response_id FK, nps_response_score_id FK, company_id, servico_id, service_setor, role [analyst/strategist→consultor/estrategista], user_id FK, average_score, assigned_at). FKs nullOnDelete/cascade; cross-driver; índices `(user_id, role)` e `(service_setor)`.
- **DEC-79-D** — Cálculo/atribuição no submit: (1) média por dimensão via `NpsScoreCalculator` → nps_response_scores; (2) congelar serviços cobertos → nps_response_covered_services; (3) interseção cobertos ∩ ATIVOS → para cada serviço: média Analista→consultor(servico_id), média Estrategista→estrategista(servico_id) → nps_score_assignments; responsável faltante → sem assignment + log pendência; (4) média Empresa fica em scores (dimensao=empresa), sem assignment; (5) idempotência máx 1 resposta por company+ciclo+template (dedup composto Phase 68-04).
- **DEC-79-E** — Bônus INTOCADO: NÃO alterar `DesempenhoScoreService`/`->principal()`. NPS Padrão continua is_default=true. Atribuições ficam prontas para a Fase 80. Fallback dual-path mantido.

### Claude's Discretion
- Nomes exatos de colunas/tabelas (adaptar ao existente).
- Reuso do `NpsScoreCalculator` (sim) e onde extrair o helper de atribuição (recomendação: novo `NpsSnapshotService`).
- Mapeamento role: analyst↔consultor, strategist↔estrategista.
- Como o seed do NPS Padrão linka serviços performance (recomendação: todos os serviços ativos setor=performance).

### Deferred Ideas (OUT OF SCOPE — Fase 80)
- Reescrever `DesempenhoScoreService::computeNpsMedio` para ler de `nps_score_assignments` (fallback dual-path + bump cache).
- Relatórios por serviço/papel/pessoa + dedup por profissional.
- Aposentar "só o principal conta".

## Sources

### Primary (HIGH confidence — leitura direta do código)
- `database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php` — schema v15 (5 tabelas, enum dimensao, índices)
- `database/migrations/2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php` — molde do seed idempotente
- `database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php` — dedup composto + padrão cross-driver
- `database/migrations/2026_07_14_000001_add_servico_id_to_company_users.php` — padrão cross-driver FK/índice (Phase 76)
- `database/migrations/2026_07_14_100002_seed_servico_shopee.php` — serviço Shopee (Phase 75)
- `app/Console/Commands/NpsDispararMensal.php:133-368` — disparo atual (força principal)
- `app/Http/Controllers/NpsController.php:449-731` — respond + submitResponseV15/Legacy
- `app/Services/Nps/NpsScoreCalculator.php:65-113` — média por dimensão (SUM/N)
- `app/Services/Nps/NpsTemplateService.php:70-112` — resolveForCompany
- `app/Models/Company.php:157-209` — consultor/estrategista + consultorDoServico/estrategistaDoServico
- `app/Models/{NpsTemplate,NpsSurvey,NpsResponse,NpsResponseAnswer,Servico,ContratoServico}.php`
- `app/Services/DesempenhoScoreService.php:281-348` — computeNpsMedio (`->principal()`, INTOCADO)
- `tests/Feature/V16/CriaCenarioResponsaveis.php` — fixture reusável

### Secondary (MEDIUM confidence)
- `.planning/milestones/v16.0-brief.md` — DEC-A/DEC-B, âncoras de código
- `.planning/phases/79-.../79-CONTEXT.md` — decisões travadas

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nada novo, tudo verificado no repo
- Architecture/schema: HIGH — padrões cross-driver e snapshot lidos linha a linha
- Disparo/submit: HIGH — mapeamento linha a linha do código atual
- Pitfalls: HIGH — derivados de bugs/decisões documentados no próprio código (idempotência por-template, ordem do cálculo, enum SQLite)
- Título de email por modelo / display de nomes: MEDIUM — Open Questions a resolver no plan

**Research date:** 2026-07-14
**Valid until:** ~2026-08-14 (código estável; revalidar se o dev paralelo anunciar-ml mexer em NpsController/NpsDispararMensal/company_users)
