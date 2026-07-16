# Fase 91: Desempenho único com elegibilidade — DesempenhoScoreService (v17.0) - Pesquisa

**Pesquisado em:** 2026-07-16
**Domínio:** Refatoração de service PHP interno (motor de cálculo de bônus) — sem stack nova, sem dependências externas
**Confiança:** ALTA (código-fonte lido integralmente; decisões rastreadas em ROADMAP.md + plano canônico + memória do projeto)

## Summary

Esta fase troca a origem do universo de empresas do `DesempenhoScoreService` de `$user->companies()` (carteira consolidada por `company_id`) para `CarteiraContextService::forUser()` (vínculos por serviço, já pronto e testado desde a Fase 88). O impacto é cirúrgico: **só `computeUniverso()` muda de fonte** — `computeNpsMedio` (dual-path da Fase 79/80), `computeVarFaturamento`/`computeVarMargem` (réguas 1-5, comparação justa de período, recorte por dias comuns) e `computeNotaFinal` continuam com a MESMA matemática, agora alimentados por um subconjunto diferente de empresas (só as com `financial_metrics_eligible=true` para os dois componentes financeiros).

O maior risco não é a lógica nova — é a **omissão de dois passos operacionais** que já morderam este service duas vezes na Fase 80: (1) esquecer o bump da chave de cache versionada (`desempenho.compute.v3` → `v4`), o que serviria a nota antiga em prod por até 7 dias mesmo com o código certo deployado; e (2) não confirmar que meses fechados (snapshots já gravados em `desempenho_score_snapshots.breakdown_json`) permanecem com o número da régua da época — mudar `computeUniverso` SÓ afeta `compute()` daqui pra frente, nunca reescreve histórico automaticamente.

**Recomendação primária:** Trocar a linha `$user->companies()->where('active', true)->get()` por uma chamada a `CarteiraContextService::forUser($user)` + `contadores()`, propagar os 4 novos contadores + `score_status` + `componentes_disponiveis` no shape de retorno do `compute()`, bumpar a chave de cache para `v4`, e não tocar em nenhuma linha de `computeNpsMedio`, `computeVarFaturamento`, `computeVarMargem` ou `computeNotaFinal` além dos parâmetros de entrada (que passam a vir de uma lista de `company_id` filtrada por `financial_metrics_eligible`, não mais da `EloquentCollection<Company>` inteira).

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DESEMP-01 | `computeUniverso` deriva o universo dos vínculos de serviço ativos do profissional (não de `company_id` consolidado), retornando empresas únicas e empresas elegíveis para financeiro | Seção "Pattern 1" — troca `$user->companies()` por `CarteiraContextService::forUser()`; Pitfall 1 documenta o ajuste de fixture necessário para não quebrar o teste âncora Carlos |
| DESEMP-02 | O score permanece ÚNICO por profissional — nenhum score separado por marketplace | Seção "Anti-Patterns to Avoid" — proibição explícita de segundo score/ranking; Wave 0 Gap lista teste dedicado (nenhum teste automático prova uma ausência por si só) |
| DESEMP-03 | `computeNpsMedio` continua lendo `nps_score_assignments` — NPS Shopee E Performance no mesmo NPS médio | Seção "Regressões que NÃO podem quebrar" item 2 — `BonusDualPathRegressaoTest` 5/5 é a prova formal; Pitfall 5 explicita que nenhuma linha de `computeNpsMedio`/`notasPorAtribuicao`/`notasLegado` deve ser tocada |
| DESEMP-04 | `computeVarFaturamento`/`computeVarMargem` usam apenas vínculos com `financial_metrics_eligible = true` | Seção "Pattern 2" — assinatura dos métodos não muda, só o `EloquentCollection<Company>` de entrada; Pitfall 4 cobre o risco de dedup ausente |
| DESEMP-05 | Retorno expõe `empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`, `vinculos_sem_fonte_financeira`, `score_status`, `componentes_disponiveis` | Seção "Interação com a Fase 92" — shape completo proposto + nota de schema confirmando que `breakdown_json` (JSON cast) absorve as chaves novas sem migração |
| DESEMP-06 | Nota expõe status `official`/`partial`/`blocked`; só-Shopee sem fonte financeira recebe `blocked` | Seção "Status official/partial/blocked — semântica proposta" — fórmula `computeScoreStatus()` proposta com base em `vinculos_financeiros`/`vinculos_sem_fonte_financeira`; marcado `[ASSUMED]` (A1/A2 no Assumptions Log) — precisa confirmação antes do plano travar |
| DESEMP-07 | Regra `sem_carteira` remove do ranking só quem NÃO tem nenhum vínculo ativo — quem tem vínculo Shopee (sem financeiro) permanece | Seção "DESEMP-07 — regra sem_carteira revisada" — muda o predicado de `$companies->isEmpty()` para `$vinculos->isEmpty()`; documenta efeito colateral em `ConsolidarMesDesempenho` (Matheus passa a gerar snapshot mensal) |

</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Resolução do universo de vínculos (quem é responsável por quê) | API/Backend — `CarteiraContextService` | — | Já existe, testado, sem cache (query local trivial); é a fonte única desde a Fase 88 |
| Cálculo do score (NPS/faturamento/margem/nota final) | API/Backend — `DesempenhoScoreService` | — | Lógica de negócio pura, stateless entre chamadas exceto cache in-memory de `BonusFaixa` |
| Cache do resultado computado | API/Backend — `Cache::remember` (Redis via `database`/config padrão) | — | TTL adaptativo (mês fechado 7d / mês corrente 10min); chave versionada é o mecanismo de invalidação de schema |
| Persistência de snapshot mensal fechado | Database — `desempenho_score_snapshots` | API/Backend — `ConsolidarMesDesempenho` | `breakdown_json` é a fonte de verdade histórica; NÃO se reescreve sozinha |
| Consumo do ranking/status por profissional | API/Backend — `PerformanceController::index` | Browser (Fase 92, fora de escopo aqui) | Controller já lê `resultado['...']` do shape do service; só precisa propagar chaves novas |

## Standard Stack

Não há biblioteca nova nesta fase — é refatoração interna de um service PHP existente consumindo outro service PHP existente (`CarteiraContextService`, já na base de código, Fase 88). Nenhuma dependência de `composer.json`/`package.json` muda.

### Package Legitimacy Audit

**Não aplicável** — esta fase não instala nenhum pacote externo. `CarteiraContextService` é código interno do próprio repositório (`app/Services/Portfolio/CarteiraContextService.php`), não uma dependência de terceiros.

## Architecture Patterns

### Diagrama de fluxo (compute() pós-refatoração)

```
PerformanceController::index()
  │
  ├─ users elegíveis (analista/estrategista via user_setores→cargos)
  │
  └─▶ DesempenhoScoreService::computeCached(user, mesReferencia)
        │
        ├─ Cache::remember('desempenho.compute.v4.{user_id}.{Y-m}', ttl)
        │     │
        │     └─▶ compute(user, mesReferencia)
        │           │
        │           ├─ computeUniverso(user, mes)                    ◀── ÚNICO MÉTODO QUE MUDA
        │           │     │
        │           │     ├─▶ CarteiraContextService::forUser(user, ['active'=>true])
        │           │     │     → Collection<vínculo: company_id, servico_id, setor,
        │           │     │       role, financial_metrics_eligible, ...>
        │           │     │
        │           │     ├─▶ CarteiraContextService::contadores($vinculos)
        │           │     │     → empresas_unicas, vinculos_servico,
        │           │     │       vinculos_financeiros, vinculos_sem_fonte_financeira
        │           │     │
        │           │     ├─ SE $vinculos->isEmpty() → sem_carteira=true (DESEMP-07: só
        │           │     │  quando NÃO há NENHUM vínculo, não mais "sem company_id ativo")
        │           │     │
        │           │     └─ SE $vinculos->where('financial_metrics_eligible', true)
        │           │        ->isEmpty() → score_status='blocked' (decisão 2026-07-16)
        │           │
        │           ├─ computeNpsMedio(user, mes)                    ◀── INTOCADO
        │           │     (nps_score_assignments — dual-path Fase 79/80, não usa companies())
        │           │
        │           ├─ computeVarFaturamento(user, mes, $companiesElegiveis)  ◀── só filtro de entrada muda
        │           ├─ computeVarMargem(user, mes, $companiesElegiveis)       ◀── só filtro de entrada muda
        │           │
        │           ├─ computeNotaFinal(nps, varFat, varMargem)       ◀── INTOCADO
        │           │
        │           ├─ classificarFaixa + promoverPor2MesesConsecutivos  ◀── INTOCADO
        │           │
        │           └─ monta shape com metadados novos (empresas_unicas,
        │              vinculos_servico, vinculos_financeiros,
        │              vinculos_sem_fonte_financeira, score_status,
        │              componentes_disponiveis)
        │
        └─▶ retorno consumido por PerformanceController (ranking),
            dashboardCarteira (view individual), WarmDesempenhoCache,
            ConsolidarMesDesempenho (grava breakdown_json)
```

### Pattern 1: `computeUniverso` delega para `CarteiraContextService`, não reimplementa

**O quê:** `computeUniverso(User $user, Carbon $mes)` para de fazer `$user->companies()->where('active', true)->get()` e passa a chamar `app(CarteiraContextService::class)->forUser($user, ['active' => true])`.

**Quando usar:** Sempre — é o único ponto de entrada do universo no service inteiro. `CarteiraContextService` já resolve `servico_id` preenchido vs. legado NULL, já deduplica, já marca `financial_metrics_eligible` por setor. Reimplementar essa lógica dentro de `DesempenhoScoreService` duplicaria os Pitfalls 1-3 documentados no `88-RESEARCH.md` (roles da pivot sem 'mentor', setor só resolvível pós-join, dedup que não pode colapsar vínculos).

**Exemplo (shape de retorno de `CarteiraContextService::forUser`):**
```php
// Source: app/Services/Portfolio/CarteiraContextService.php:99-104
[
  'user_id' => 10, 'company_id' => 123, 'company_name' => 'Camillo Parts',
  'servico_id' => 7, 'servico_nome' => 'Gestão de ADS Shopee', 'setor' => 'shopee',
  'role' => 'consultor', 'role_label' => 'Analista',
  'has_financial_source' => false, 'financial_source' => null,
  'financial_metrics_eligible' => false,
]
```

**IMPORTANTE:** `CarteiraContextService::forUser()` retorna **um vínculo por (company_id, servico_id)** — se um profissional é responsável por Performance E Shopee da mesma empresa, isso é 2 linhas na Collection, não 1. `computeVarFaturamento`/`computeVarMargem` esperam uma lista de `company_id` (não vínculos) — a conversão precisa ser `->where('financial_metrics_eligible', true)->pluck('company_id')->unique()` para não somar a MESMA empresa 2× no SUM se por algum motivo ela tiver 2 vínculos elegíveis (hoje só `performance` é elegível, então isso não ocorre na prática, mas o código defensivo evita duplicar SUM(revenue) se um dia Shopee ganhar fonte financeira e um profissional acumular 2 vínculos financeiros na mesma empresa).

### Pattern 2: `computeVarFaturamento`/`computeVarMargem` recebem `EloquentCollection<Company>`, não `Collection<vínculo>`

**O quê:** As assinaturas atuais são `computeVarFaturamento(User $user, Carbon $mes, EloquentCollection $companies)` — esperam objetos `Company` completos (usam `$company->created_at`, `$company->id`, iteram com `$this->metricsFactory->caseFor($company)`).

**Implicação para o plano:** `computeUniverso` precisa devolver, além dos vínculos brutos, uma `EloquentCollection<Company>` filtrada por `financial_metrics_eligible=true` E deduplicada por `company_id` (`Company::whereIn('id', $companyIdsElegiveis)->get()`), para não reescrever a assinatura dos dois métodos financeiros nem os testes que já os cobrem via `$service->compute()`. **Não mudar a assinatura desses dois métodos** — só o que é passado para eles muda (menos empresas, nunca a forma do parâmetro).

### Anti-Patterns to Avoid

- **Reimplementar a query de vínculos dentro do `DesempenhoScoreService`:** `CarteiraContextService` já existe, já testado (Fase 88), já é a fonte única documentada no plano canônico ("consumidores devem SEMPRE usar `forUser()`, nunca fazer join direto em `company_users.servico_id`"). Duplicar essa query aqui recria os 3 pitfalls que a Fase 88 resolveu.
- **Filtrar `computeNpsMedio` pelos vínculos elegíveis financeiramente:** NPS já lê de `nps_score_assignments` (todas as áreas — DESEMP-03/DEC-80-A), independente de elegibilidade financeira. Aplicar o filtro de `financial_metrics_eligible` ao NPS quebraria a regra "NPS Shopee E NPS Performance entram no mesmo NPS médio" (DESEMP-03/Success Criterion 3).
- **Criar um segundo score/ranking para "Shopee":** proibido explicitamente pelo plano canônico e pelo Success Criterion 2. O único lugar onde o conceito de "setor" aparece é dentro dos metadados de auditoria (`vinculos_financeiros`, `vinculos_sem_fonte_financeira`) — nunca como uma segunda nota.
- **Rodar `desempenho:consolidar-mes --mes=<mês fechado>` "para corrigir o histórico" após o deploy:** isso REESCREVE o `breakdown_json` daquele mês com a régua NOVA, mudando retroativamente quanto alguém "deveria" ter recebido de bônus em um mês já pago. Ver seção Pitfalls abaixo.

## Snapshots de meses fechados — mecânica confirmada (CRÍTICO)

Lido em `app/Console/Commands/ConsolidarMesDesempenho.php` (linhas 95-125):

```php
foreach ($users as $user) {
    $result = $this->scoreService->compute($user, $mes);   // compute() DIRETO, nunca computeCached()
    // ...
    DesempenhoScoreSnapshot::updateOrCreate(
        ['user_id' => $user->id, 'mes_referencia' => $mesStr],
        ['score' => ..., 'classificacao' => $result['faixa_bonus'] ?? '', 'breakdown_json' => $result]
    );
}
```

**Mecânica confirmada:**
1. O comando roda 1× por mês (cron dia 1, 14:00 BRT) via `--mes` default = mês anterior.
2. `updateOrCreate` é idempotente na CHAVE `(user_id, mes_referencia)` — rodar de novo no MESMO mês sobrescreve a mesma row.
3. **Nada dispara esse comando automaticamente de novo para meses passados.** Um mês fechado só é reescrito se alguém rodar manualmente `desempenho:consolidar-mes --mes=YYYY-MM` apontando para o passado.
4. `PerformanceController::index()` e `PerformanceController::show()` preferem o snapshot mensal (`breakdown_json`) para meses fechados e só caem em `computeCached()` ao vivo se **não existir** snapshot para aquele user/mês.

**O que isso significa para esta fase:**
- Depois do deploy, o mês CORRENTE (calculado ao vivo via `compute()`/`computeCached()`) passa a refletir a régua nova (universo por vínculo) imediatamente.
- Meses JÁ FECHADOS (com `breakdown_json` já gravado) continuam mostrando o número da régua ANTIGA (universo por `company_id` consolidado) até que alguém rode `consolidar-mes --mes=<mês antigo>` manualmente — e isso é o comportamento CORRETO: a régua da época era a `company_id` consolidada, reescrever mudaria retroativamente quanto uma pessoa "deveria" ter ganho de bônus já pago.
- **O plano DEVE declarar explicitamente que esta fase NÃO reprocessa meses fechados** — é uma decisão de escopo, não um esquecimento. Se a diretoria quiser reprocessar julho/2026 (por exemplo) sob a régua nova, isso é uma decisão de negócio separada (rodar o comando manualmente, avisando que muda um bônus já potencialmente pago), fora do escopo automático desta fase.
- Consequência prática: um profissional só-Shopee (ex.: Matheus) que hoje está `sem_carteira=false` mas puxando faturamento ML errado em meses fechados PASSADOS continuará com esse número errado nesses meses até reprocessamento manual — só o mês corrente em diante fica correto automaticamente.

## Cache — bump obrigatório v3 → v4 (CRÍTICO)

Confirmado em `app/Services/DesempenhoScoreService.php` linha 150:

```php
$cacheKey = sprintf('desempenho.compute.v3.%d.%s', $user->id, $mes->format('Y-m'));
```

**Histórico de bumps documentado no próprio código** (comentários linhas 140-149):
- v1→v2 (2026-07-13): correção da dimensão do NPS por cargo.
- v2→v3 (2026-07-15, Fase 80 DEC-80-C): `computeNpsMedio` passou a somar `nps_score_assignments`.

**Esta fase exige v3→v4**, pelo mesmo motivo documentado nos dois bumps anteriores: sem o bump, o `computeCached()` continuaria devolvendo, por até 7 dias (TTL de mês fechado) ou 10 minutos (mês corrente), o resultado computado sob a régua ANTIGA — universo por `company_id`. O código novo sobe em prod, mas o Redis serve o número velho silenciosamente, sem log de erro, sem exception. Este é exatamente o padrão coberto por `BonusDualPathRegressaoTest::test_cache_bumpado_para_v3` — a Fase 91 deve escrever um teste equivalente (`test_cache_bumpado_para_v4`) seguindo o mesmo padrão: gravar lixo reconhecível sob a chave v3, confirmar que `computeCached()` NUNCA devolve esse lixo, e confirmar que a chave v4 passa a existir.

**8 consumidores confirmados de `computeCached()` / `compute()` (grep no código-fonte):**

| Consumidor | Método chamado | Contexto |
|---|---|---|
| `PerformanceController::index()` | `computeCached()` | Ranking — mês corrente ou fallback de mês fechado sem snapshot |
| `PerformanceController::dashboardCarteira()` | `computeCached()` | View individual "Meu Painel" |
| `PerformanceController::show()` | `computeCached()` | View admin de detalhe (fallback quando não há snapshot) |
| `WarmDesempenhoCache` (command) | `computeCached()` | Cron 8min — pré-aquece Redis pra 11-20 users |
| `ConsolidarMesDesempenho` (command) | `compute()` DIRETO (não cacheado) | Snapshot mensal fechado |
| `SnapshotDesempenhoScores` (command) | a confirmar no plano — não lido nesta pesquisa, mas o docblock do `computeCached()` avisa "Não use dentro de jobs/commands de snapshot ou consolidação — chame `compute()` direto" | Snapshot diário |
| Testes `Phase74/DesempenhoScoreServiceTest` | `compute()` DIRETO | Suite bloqueante (âncora Carlos) |
| Testes `V16/BonusDualPathRegressaoTest` | `computeCached()` (teste do bump) + `computeNpsMedio` via reflection | Regressão do bump de cache |

**Ação obrigatória do plano:** localizar e atualizar a linha 150 (`v3` → `v4`) COM um comentário explicando o motivo do bump (padrão dos 2 anteriores), e verificar se `SnapshotDesempenhoScores.php` chama `compute()` direto (esperado, dado o aviso do docblock) — se chamar `computeCached()` por engano, é um bug pré-existente a documentar, não a corrigir nesta fase (fora de escopo, seria uma mudança de comportamento não pedida).

## Status `official`/`partial`/`blocked` — semântica proposta

**O que está travado no ROADMAP.md (Success Criterion 6, DESEMP-06):** "profissional apenas-Shopee sem fonte financeira recebe `blocked` (decisão do usuário 2026-07-16, até a diretoria aprovar régua de bônus sem financeiro)."

**O que o plano canônico propõe genericamente** (`plano-carteira-desempenho-multi-servico.md` linha 189-194): "Se só NPS estiver disponível, mostrar nota como parcial até a diretoria aprovar a regra de bônus para Shopee sem financeiro." — este texto usa "parcial" onde o ROADMAP (mais recente, 2026-07-16) usa "blocked" para o MESMO cenário (só-Shopee sem financeiro). **O ROADMAP é a fonte mais recente e deve prevalecer** — o plano canônico é anterior à decisão do usuário que renomeou esse caso específico de "parcial" para "blocked".

Isso deixa a semântica de `partial` sem definição explícita em nenhum documento. `[ASSUMED]` — proposta de síntese baseada nos dois documentos e no shape de metadados já locked (`vinculos_financeiros`, `vinculos_sem_fonte_financeira`):

```php
private function computeScoreStatus(int $vinculosFinanceiros, int $vinculosSemFonteFinanceira): string
{
    if ($vinculosFinanceiros === 0) {
        return 'blocked';   // 100% da carteira sem fonte financeira (ex.: só-Shopee) — DESEMP-06
    }
    if ($vinculosSemFonteFinanceira > 0) {
        return 'partial';   // carteira mista: parte tem fonte financeira, parte não
    }
    return 'official';      // 100% da carteira com fonte financeira elegível — caso padrão hoje
}
```

**Por que essa é a leitura mais defensável:**
- `blocked` bate 1:1 com a frase travada no ROADMAP ("apenas-Shopee sem fonte financeira") — `vinculos_financeiros === 0` é exatamente "nenhum vínculo elegível", que é o que caracteriza "apenas-Shopee".
- `partial` cobre o caso que o plano canônico original descrevia como intermediário (carteira mista Performance+Shopee) — a nota é calculada normalmente (só os vínculos elegíveis entram no financeiro, como sempre), mas o carimbo avisa a auditoria de que uma fatia da carteira do profissional não está representada nos componentes financeiros.
- `official` é o comportamento ATUAL do sistema (sem mudança) — 100% da carteira com fonte financeira, sem ressalva.

**O que esta pesquisa NÃO resolve (precisa de decisão explícita no plano ou confirmação do usuário):**
1. **`blocked` afeta `faixa_bonus`/`nota_final`?** A leitura mais segura (que não inventa regra de negócio nova) é: `nota_final` e `faixa_bonus` continuam sendo calculados exatamente como hoje (média dos componentes disponíveis — para um só-Shopee isso normalmente é só `nps_medio`, então `nota_final = nps_medio` e `faixa_bonus` classifica normalmente pela régua). O status `blocked` é uma ANOTAÇÃO para a UI/processo de pagamento saber que esse número não deve gerar pagamento automático até a diretoria aprovar a régua — não uma instrução para zerar `nota_final`. Isso preserva DESEMP-07 (permanece no ranking) sem inventar um "nota_final = null forçado" que nenhum requisito pede.
2. **`blocked` no ranking:** como `nota_final` continua populado (via NPS), a ordenação atual (`sortByDesc(nota_final ?? -1)`) posiciona o `blocked` normalmente entre os demais — ele NÃO cai artificialmente pro fim. Se a intenção de negócio é diferente (ex.: sempre mostrar blocked no fim, ou sem posição numérica), isso precisa ser uma decisão explícita da Fase 92 (UI), não desta fase 91 (que só produz o dado).

Confiança desta seção: **MÉDIA** — a mecânica de cálculo é ALTA confiança (deriva direto do código lido), mas o mapeamento exato dos 3 status é uma síntese entre dois documentos que não usam o mesmo vocabulário; recomenda-se confirmar com o usuário/discuss-phase antes de travar como decisão de plano.

## DESEMP-07 — regra `sem_carteira` revisada

**Hoje** (`computeUniverso`, linha 258-273): `sem_carteira=true` quando `$user->companies()->where('active', true)->get()->isEmpty()` — ou seja, zero empresas na carteira CONSOLIDADA.

**Regra nova:** `sem_carteira=true` só quando o profissional não tem **nenhum vínculo ativo em `CarteiraContextService::forUser()`** — ou seja, `$vinculos->isEmpty()`, não `$vinculos->where('financial_metrics_eligible', true)->isEmpty()`. Um profissional só-Shopee (Matheus) tem vínculos (Shopee), então `sem_carteira=false` — ele PERMANECE no ranking com `score_status='blocked'`.

**Onde a flag é consumida hoje:**
- `PerformanceController::index()` linha 144: `$rankingRaw = $rankingRaw->reject(fn ($r) => $r['sem_carteira'] === true);` — remove do ranking ANTES do sort. Este filtro não muda de lugar nem de lógica — só o que `sem_carteira` significa dentro de `computeUniverso` muda.
- `ConsolidarMesDesempenho::handle()` linha 100: pula a gravação do snapshot mensal quando `sem_carteira=true` (não grava row nenhuma pra esse user naquele mês). Com a regra nova, Matheus (só-Shopee) PASSA a ganhar snapshot mensal (porque não é mais `sem_carteira`) — o snapshot vai gravar `nota_final` calculada só do NPS e `classificacao` da régua de bônus normal, com `score_status='blocked'` dentro do `breakdown_json`. **Isso é uma mudança de comportamento em produção que o plano precisa citar explicitamente** — hoje Matheus provavelmente é pulado (`sem_carteira`); depois desta fase ele passa a ter uma row de snapshot todo mês.

## Regressões que NÃO podem quebrar

1. **Fixture Carlos — `test_fixture_carlos_retorna_nota_4_08_basico`** (`tests/Feature/Phase74/DesempenhoScoreServiceTest.php:388`). Carlos tem 3 empresas 100% Performance (todas com `financial_metrics_eligible=true` pela regra nova, já que `criarEmpresaNaCarteira()` só insere `company_users` sem `servico_id` — cai no ramo legado do `CarteiraContextService`, resolvido como Performance legado porque a empresa tem contrato performance ativo... **EXCETO que o teste atual NÃO cria `contratos_servico`** para as empresas do Carlos). Isso é um ponto de atenção real: `CarteiraContextService::vinculosLegadoNull` exige `whereExists` de `contratos_servico` com `ativo=true` e `setor=performance` para resolver o vínculo legado (linha 199-206 do service). Se o fixture Carlos não tiver essa tabela populada, ele passará a cair em `sem_carteira=true` sob a régua nova, MESMO com `company_users` preenchido — **quebrando a âncora bloqueante**. O plano precisa criar `contratos_servico` ativo para as empresas do fixture (ou usar `servico_id` preenchido diretamente no helper `criarEmpresaNaCarteira`), senão o teste Carlos quebra por uma razão que não é a intenção da fase.
2. **`BonusDualPathRegressaoTest` 5/5** (`tests/Feature/V16/BonusDualPathRegressaoTest.php`) — todos os 5 testes chamam `computeNpsMedio` diretamente por reflection ou testam o bump de cache; NENHUM deles depende de `computeUniverso`/`$user->companies()`. Devem passar inalterados — são a prova formal de que `computeNpsMedio` continua intocado.
3. **"Carteira usa média por empresa, nunca total agregado"** (memória do projeto) — `computeVarFaturamento`/`computeVarMargem` já fazem `$vars->avg()` (média das % por empresa, não soma de receita/soma de receita). Essa regra não muda — só o conjunto de empresas que entra no `foreach` muda (filtrado por elegibilidade).
4. **"Adman atrasa margem vs revenue no mês em curso"** (memória do projeto / fix Tomelin, teste `test_var_margem_nao_inverte_sinal_quando_janela_atual_tem_dias_finais_sem_margem`) — o recorte por dias comuns dentro de `computeVarMargem` é código que não muda nesta fase; só recebe menos empresas na entrada.
5. **`test_2_meses_consecutivos_intermediario_promove_para_maximo` (DESEMP-08)** — depende de `DesempenhoScoreSnapshot::mensal()` do mês anterior; não depende de `computeUniverso`. Deve seguir passando.
6. **`test_provider_ml_first_com_adman_fallback` (DESEMP-11)** — testa `MetricsProviderFactory::caseFor()`, ortogonal a `CarteiraContextService`. Deve seguir passando sem alteração.

**Consequência prática para o plano:** o helper de teste `criarEmpresaNaCarteira()` em `DesempenhoScoreServiceTest.php` (usado por TODOS os 12 testes da suite) precisa ganhar um `contratos_servico` ativo de setor `performance` (ou inserir `company_users.servico_id` apontando pro serviço de Gestão/Mentoria) para continuar sendo resolvido como elegível financeiramente sob a régua nova — **sem isso, a suite inteira quebra em massa**, não só o Carlos.

## Interação com a Fase 92

A Fase 92 (fora de escopo aqui, mas descrita em DESEMP-08 no ROADMAP) consome o payload de `PerformanceController` — não chama `DesempenhoScoreService` diretamente. Os metadados que esta fase precisa expor no shape de `compute()` (e propagar em `PerformanceController::index()`, linha ~119-140) são exatamente os do Success Criterion 5:

```php
[
    // ... campos existentes (user_id, nota_final, faixa_bonus, componentes, ...)
    'empresas_unicas'               => int,     // de CarteiraContextService::contadores()
    'vinculos_servico'               => int,     // idem
    'vinculos_financeiros'           => int,     // idem
    'vinculos_sem_fonte_financeira'  => int,     // idem
    'score_status'                   => string,  // 'official'|'partial'|'blocked'
    'componentes_disponiveis'        => [
        'nps_medio'           => bool,  // sempre true (NPS força 0.0, nunca null — DESEMP-03)
        'var_faturamento_pct' => bool,  // true se != null
        'var_margem_pct'      => bool,  // true se != null
    ],
]
```

**Nota de schema:** `DesempenhoScoreSnapshot.breakdown_json` é uma coluna `array`-cast (JSON) que já persiste o array INTEIRO retornado por `compute()` (`ConsolidarMesDesempenho` linha 123: `'breakdown_json' => $result`). Isso significa que **nenhuma migração de banco é necessária** para os 6 metadados novos — eles entram automaticamente no JSON assim que o `compute()` os retornar. As colunas tipadas dedicadas (`empresas_carteira`, `empresas_eligiveis`) já existem e podem continuar sendo preenchidas com os equivalentes antigos (compat) OU o plano pode decidir se `empresas_carteira` agora deve significar `empresas_unicas` (mudança semântica sutil que merece decisão explícita, já que `PerformanceController` e `dashboardCarteira` leem `empresas_carteira` hoje).

## Pitfalls

### Pitfall 1: Fixture de teste sem `contratos_servico` cai em `sem_carteira` sob a régua nova
**O que dá errado:** Testes existentes que só inserem `company_users` (sem `contratos_servico`) deixam de resolver como "Performance legado" no `CarteiraContextService`, porque `vinculosLegadoNull()` exige `whereExists` de contrato ativo do setor performance.
**Por que acontece:** `CarteiraContextService` foi desenhado para produção (onde 100% dos vínculos legado real já têm contrato — ver comentário do service, "dos 268 vínculos, 7 têm servico_id NULL e NENHUM pertence a empresa com contrato ativo... o backfill da Fase 76 já cobriu tudo"), mas os helpers de teste da Fase 74 nunca precisaram criar `contratos_servico` porque usavam `$user->companies()` direto.
**Como evitar:** Atualizar `criarEmpresaNaCarteira()` (e equivalentes) para inserir `contratos_servico` ativo OU `company_users.servico_id` preenchido apontando pro serviço de setor `performance`, seguindo o padrão já usado em `tests/Feature/V16/CriaCenarioResponsaveis.php` (trait usado pelo `BonusDualPathRegressaoTest`, que já sabe criar `criarServico()`/`criarContrato()`/`inserirPivot()` corretamente).
**Sinais de alerta:** Suite inteira do `Phase74/DesempenhoScoreServiceTest` retornando `sem_carteira=true` onde antes retornava dados — sintoma claro de fixture desatualizado, não de bug na lógica nova.

### Pitfall 2: Cache servindo nota antiga silenciosamente
**O que dá errado:** Deploy sobe o código novo, mas `/performance` continua mostrando ranking calculado com `company_id` consolidado por até 7 dias.
**Por que acontece:** Esquecer o bump `v3`→`v4` na chave de `computeCached()` (linha 150).
**Como evitar:** Bump obrigatório + teste `test_cache_bumpado_para_v4` espelhando `BonusDualPathRegressaoTest::test_cache_bumpado_para_v3`.
**Sinais de alerta:** Ranking em prod não muda mesmo após deploy confirmado; Matheus (só-Shopee) continua sumindo do ranking ou aparecendo com faturamento ML.

### Pitfall 3: Reescrever meses fechados sem intenção
**O que dá errado:** Alguém roda `desempenho:consolidar-mes --mes=2026-06` "para testar" e acidentalmente reescreve o bônus histórico de junho sob a régua nova.
**Por que acontece:** O comando é idempotente por design (`updateOrCreate`) — não tem proteção contra reprocessar um mês já fechado e potencialmente já pago.
**Como evitar:** O plano NÃO deve incluir nenhum passo de reprocessamento de meses fechados. Se precisar validar a régua nova contra dado real, usar `compute()` isolado (script tinker, não o command de consolidação) ou rodar contra um mês de teste/sandbox.
**Sinais de alerta:** `breakdown_json` de um snapshot com `mes_referencia` no passado mudando de valor sem uma decisão de negócio explícita documentada.

### Pitfall 4: `computeVarFaturamento`/`computeVarMargem` recebendo `Company` duplicada
**O que dá errado:** Se `computeUniverso` passar a `EloquentCollection<Company>` derivada de `$vinculos->pluck('company_id')` SEM `->unique()`, uma empresa com 2 vínculos elegíveis (hipotético, hoje não ocorre pois só `performance` é elegível) entraria 2× no `foreach` de `computeVarFaturamento`, inflando a média artificialmente.
**Como evitar:** Sempre `->unique('company_id')` (ou `Company::whereIn('id', $ids->unique())->get()`) entre o filtro de elegibilidade e a passagem para os 2 métodos financeiros.

### Pitfall 5: Sessão paralela ativa em NPS (Fase 94) — não tocar
**O que dá errado:** Alterar qualquer parte de `computeNpsMedio`, `notasPorAtribuicao`, `notasLegado` ou os testes NPS (`BonusDualPathRegressaoTest`) enquanto outra sessão trabalha na Fase 94 (NPS) no MESMO working tree causa conflito de merge ou regressão cruzada.
**Como evitar:** Esta fase só toca `computeUniverso`, `compute()` (shape de retorno), a chave de cache, `computeVarFaturamento`/`computeVarMargem` (só o parâmetro de entrada), e os testes de fixture (`criarEmpresaNaCarteira` e afins). Nenhuma linha de `computeNpsMedio`/`notasPorAtribuicao`/`notasLegado` deve ser tocada — o Success Criterion 3 exige exatamente isso ("preservado").

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP (local) | Rodar testes/tinker | ✓ | 8.2.12 (XAMPP, `C:\xampp\php\php.exe`) | — |
| MySQL/MariaDB (local) | Nada nesta fase — testes usam SQLite in-memory | ✗ (mysqld não está rodando) | — | `phpunit.xml` já força `DB_CONNECTION=sqlite`/`:memory:` para toda a suite Feature — sem impacto |
| SQLite (testes) | Toda a suite Feature | ✓ | via driver PDO do PHP | — |
| Redis (cache) | `computeCached()` em prod | Não verificável localmente | — | Cache config padrão do projeto (`database`/Redis conforme `.env`); testes usam `Cache::put`/`Cache::get` do driver de teste sem dependência de Redis real |

**Missing dependencies with no fallback:** nenhuma — MySQL local não é necessário (SQLite cobre 100% dos testes desta fase, confirmado pelas 2 suites existentes já rodando em SQLite).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config `phpunit.xml` |
| Config file | `C:\xampp\htdocs\ecf_admin\ecf_admin\phpunit.xml` (SQLite in-memory forçado via `<env>`) |
| Quick run command | `C:\xampp\php\php.exe vendor/bin/phpunit --filter=DesempenhoScoreServiceTest` |
| Full suite command | `C:\xampp\php\php.exe vendor/bin/phpunit --testsuite=Feature` (ou `php artisan test`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DESEMP-01 | `computeUniverso` deriva de vínculos, não de `company_id` consolidado | unit/feature | `phpunit --filter=test_fixture_carlos_retorna_nota_4_08_basico` | ✅ (precisa de ajuste de fixture — Pitfall 1) |
| DESEMP-02 | Score permanece único — sem score separado por marketplace | feature (grep de código, não testável por asserção única) | `phpunit --filter=Phase74` (nenhuma nova rota/model "Score Shopee" deve existir) | ❌ Wave 0 — precisa de teste dedicado (ex.: `assertArrayNotHasKey` num shape hipotético, ou revisão manual de rotas) |
| DESEMP-03 | `computeNpsMedio` continua via `nps_score_assignments`, intocado | feature (regressão) | `phpunit --filter=BonusDualPathRegressaoTest` | ✅ (suite já existe, 5/5) |
| DESEMP-04 | `computeVarFaturamento`/`computeVarMargem` só usam vínculos elegíveis | feature | novo teste `test_var_faturamento_exclui_vinculo_shopee_sem_fonte` | ❌ Wave 0 |
| DESEMP-05 | Retorno expõe os 6 metadados novos | feature | novo teste `test_compute_expoe_metadados_de_elegibilidade` | ❌ Wave 0 |
| DESEMP-06 | Status `official`/`partial`/`blocked`; só-Shopee sem financeiro = blocked | feature | novo teste `test_score_status_blocked_quando_so_shopee_sem_financeiro` + `test_score_status_official_quando_100pct_performance` + `test_score_status_partial_quando_carteira_mista` | ❌ Wave 0 |
| DESEMP-07 | `sem_carteira` só quando ZERO vínculos (não zero financeiro) | feature | novo teste `test_sem_carteira_false_para_profissional_so_shopee` | ❌ Wave 0 |
| Regressão cache | Bump v3→v4 | feature | novo teste `test_cache_bumpado_para_v4` (espelha `test_cache_bumpado_para_v3`) | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `phpunit --filter=DesempenhoScoreServiceTest` (suite bloqueante, ~12 testes, roda em segundos por ser SQLite in-memory)
- **Per wave merge:** `phpunit --filter=Phase74\|V16\|Phase91` (Feature completo do domínio Desempenho + regressão NPS)
- **Phase gate:** Suite Feature completa verde antes de `/gsd:verify-work` — especialmente `BonusDualPathRegressaoTest` (5/5) e o fixture Carlos, que são as 2 âncoras nomeadas explicitamente pelo usuário no `<focus>` desta pesquisa.

### Wave 0 Gaps
- [ ] Atualizar `criarEmpresaNaCarteira()` em `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` para criar `contratos_servico` ativo (setor performance) — sem isso a suite inteira quebra sob a régua nova (Pitfall 1).
- [ ] Novo arquivo de teste (sugestão: `tests/Feature/Phase91/DesempenhoScoreServiceElegibilidadeTest.php`) cobrindo DESEMP-04/05/06/07 com os 4 cenários do plano canônico já provados pela Fase 88 (só Performance, só Shopee, Performance+Shopee na mesma empresa, mesmo profissional nos dois serviços) — reaproveitar o padrão de fixtures do `tests/Feature/V16/CriaCenarioResponsaveis.php` (trait já pronta com `criarServico()`/`criarContrato()`/`inserirPivot()`).
- [ ] Teste de bump de cache `test_cache_bumpado_para_v4`, mesmo padrão de `BonusDualPathRegressaoTest::test_cache_bumpado_para_v3`.

## Security Domain

Fora do escopo prático de ASVS — esta fase não expõe endpoint novo, não recebe input de usuário novo, não lida com autenticação/autorização (o controller já é protegido por `permission:core.performance` existente). O único vetor de risco é de INTEGRIDADE DE DADO FINANCEIRO (bônus calculado errado), coberto extensivamente nas seções de Pitfalls e Regressões acima, não por ASVS.

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V5 Input Validation | não (sem input HTTP novo) | — |
| V6 Cryptography | não | — |
| Demais categorias | não | — |

## Sources

### Primary (HIGH confidence — código-fonte lido integralmente nesta sessão)
- `app/Services/DesempenhoScoreService.php` (1055 linhas, leitura completa)
- `app/Services/Portfolio/CarteiraContextService.php` (264 linhas, leitura completa — Fase 88, já testado)
- `app/Console/Commands/ConsolidarMesDesempenho.php` (leitura completa)
- `app/Console/Commands/WarmDesempenhoCache.php` (leitura completa)
- `app/Http/Controllers/PerformanceController.php` (leitura completa, 1032 linhas)
- `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` (leitura completa, 12 testes)
- `tests/Feature/V16/BonusDualPathRegressaoTest.php` (leitura completa, 5 testes)
- `app/Models/DesempenhoScoreSnapshot.php` (schema/casts)
- `app/Models/Servico.php` (constantes de setor)
- `.planning/ROADMAP.md` §Fase 91, Fase 88, Fase 89, Fase 90 (Success Criteria travados)
- `.planning/REQUIREMENTS.md` §DESEMP-01..08

### Secondary (MEDIUM confidence)
- `plano-carteira-desempenho-multi-servico.md` (plano canônico da milestone — algumas seções, notavelmente a nomenclatura "parcial" para o caso só-Shopee, foram SUPERADAS pela decisão do usuário registrada no ROADMAP em 2026-07-16; tratado como histórico de intenção, não como spec travada)

### Tertiary (LOW confidence — inferências desta pesquisa, marcadas [ASSUMED])
- Semântica exata de `official`/`partial`/`blocked` (proposta na seção dedicada acima) — síntese entre 2 documentos que usam vocabulário diferente para o mesmo caso.
- Se `blocked` deve ou não zerar `nota_final`/`faixa_bonus` — proposta é NÃO zerar (preservar cálculo, só anotar status), mas não há requisito explícito confirmando isso.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `partial` = carteira mista (`vinculos_financeiros > 0` E `vinculos_sem_fonte_financeira > 0`); `blocked` = `vinculos_financeiros === 0`; `official` = 100% elegível | Status official/partial/blocked | Se a diretoria quis dizer algo diferente por "parcial" (ex.: componente financeiro null por falta de baseline, não por elegibilidade), a UI da Fase 92 mostraria o badge errado — mas o CÁLCULO da nota não muda, só o rótulo, então o risco é de comunicação/UX, não de bônus pago errado |
| A2 | `score_status='blocked'` NÃO zera `nota_final`/`faixa_bonus` — só anota, não impede o cálculo | Status official/partial/blocked, item 1 | Se a intenção real é "blocked = sem nota nenhuma, sem faixa", isso muda o shape de retorno e a UI da Fase 92 depende dessa decisão. Precisa confirmação explícita antes do plano travar a implementação |
| A3 | `DesempenhoScoreSnapshot.empresas_carteira` continua significando o mesmo que hoje (não é renomeado para `empresas_unicas`) | Interação com Fase 92 | Se o plano decidir remapear essa coluna, `PerformanceController`/`dashboardCarteira` que já leem `empresas_carteira` (várias linhas) precisam ser revisados na mesma fase — risco de quebra silenciosa em telas não cobertas por teste |

## Metadata

**Confidence breakdown:**
- Standard stack: N/A — sem stack nova
- Arquitetura (fluxo compute/cache/snapshot): ALTA — lido em código-fonte completo
- Pitfalls (fixture, cache, snapshot fechado): ALTA — 2 dos 3 já documentados no próprio código-fonte como incidentes passados (bumps v1→v2, v2→v3; fix Tomelin)
- Semântica dos 3 status: MÉDIA — síntese entre documentos, não uma citação única e inequívoca

**Data da pesquisa:** 2026-07-16
**Válida até:** 2026-08-15 (30 dias — domínio estável, mas sensível a decisões de negócio da diretoria sobre a régua de bônus sem financeiro, que podem mudar a qualquer momento)
