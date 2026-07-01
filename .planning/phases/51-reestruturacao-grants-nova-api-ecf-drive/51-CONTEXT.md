# Phase 51: Reestruturação /grants com nova API ECF Drive — Context

**Gathered:** 2026-06-30
**Status:** Ready for research
**Source:** Síntese lean — briefing do operador 2026-06-30 + recon de `/grants` atual + ROADMAP entry

<domain>
## Phase Boundary

O operador entregou novas capacidades na API ECF Drive em 2026-06-30:

1. **`/clientes/grants`** ganhou 8 campos novos opcionais — sem breaking change
2. **`GET /grants/resumo`** (NOVO) — fonte de verdade remota: totais + buckets 7/15/30/60/90d + comparação CSV cru vs banco ContatosCPP
3. **`GET /grants/distribuicao?dimensao=...`** (NOVO) — distribuição por dimensão

A página `/grants` atual ([GrantController::index()](app/Http/Controllers/GrantController.php#L13)) calcula contagens localmente em cima de `CompanyGrant`. Funciona, mas tem 4 gaps que esta phase resolve:

1. **Lógica "Empresas sem grants" usa universo errado** — hoje filtra por `company_id`. O correto = empresas com `cust_id` cadastrado OR com OAuth ML ativo (decisão do operador 2026-06-30). Empresas sem nenhum dos dois não cabem nesse número (não é "sem grant", é "não onboardada no ML").
2. **Não consome `/grants/resumo`** — perde a fonte de verdade remota e a comparação CSV vs banco
3. **726 sellers em BASE_VENDEDORES sem ContatosCPP** ficam invisíveis (divergência ML)
4. **Alerta de expiração é binário** (30d ou não) — não progressivo

**Esta phase entrega:**

- Migration aditiva: 8 campos opcionais em `company_grants`
- `EcfDriveService::grantsResumo()` + `grantsDistribuicao(string $dimensao)`
- `SyncGrantsFromEcfDrive` popula os 8 novos campos quando vierem
- `GrantController::index()` consome `/grants/resumo` como fonte primária; fallback local quando API offline
- Universo "sem grants" corrigido por SQL ajustado
- Card novo de "Divergência ML"
- StatCards reorganizados em 5 buckets de expiração com cores progressivas

</domain>

<decisions>
## Implementation Decisions

### Escopo LOCKED

- **DENTRO** da Phase 51:
  - Migration `add_8_columns_to_company_grants` (nullable, aditiva, sem default)
  - Atualizar `CompanyGrant` model: adicionar 8 campos ao `$fillable`
  - `EcfDriveService::grantsResumo()` retorna array tipado do payload da API
  - `EcfDriveService::grantsDistribuicao(string $dimensao)` aceita apenas `programa` por enquanto (validação) — outras dimensões locked como fora de escopo
  - `SyncGrantsFromEcfDrive` (Phase 20) mapeia 8 campos novos do payload `/clientes/grants` para colunas via `updateOrCreate`
  - `GrantController::index()` chama `EcfDriveService::grantsResumo()` num try/catch; sucesso = popula stats com payload remoto; falha = log warning + usa contagem local (graceful fallback)
  - SQL corrigido para `no_grant`:
    ```sql
    companies
      WHERE active = true
      AND (cust_id IS NOT NULL OR EXISTS(ml_tokens WHERE company_id=companies.id AND status='active'))
      AND NOT EXISTS(company_grants WHERE company_id=companies.id AND status='active')
    ```
  - Card UI "Divergência ML" mostra contagem de sellers em BASE_VENDEDORES sem ContatosCPP (do payload remoto). Click no card abre tooltip/modal com detalhe (lista se vier no payload; senão só explica)
  - StatCards: 5 buckets 7d/15d/30d/60d/90d com cores progressivas (vermelho/laranja/amarelo/cinza/cinza claro)
  - Tabela de grants: colunas opcionais `programa`, `nivel_solucion`, `medalha_fecha_in/out` — só renderiza quando empresa tiver valor; ocultar célula quando NULL pra não poluir
  - Tests Feature PHPUnit: stats vêm do remoto quando OK; stats fallback quando exceção; SQL no_grant respeita universo cust_id || ml_token; sync command persiste 8 campos

- **FORA** da Phase 51:
  - Distribuição por iniciativa/nivelSolucion/parceiro/localidade — 100% uniformes hoje no ML; nada para visualizar. Seed pra retomar quando ML diversificar.
  - Restruturação visual completa da página — manter layout atual; só refinar/adicionar
  - Endpoint próprio para divergência (não criar `GrantController@divergencia`) — consumir direto do `/grants/resumo`
  - Notificações ou alertas push de grants expirando (Phase futura)
  - Caching agressivo do `/grants/resumo` no Laravel — chamada é leve (1× por request); fica simples
  - Mudança no schedule de sync (Phase 20 já roda 03:00 diariamente — segue intacto)

### Universo "Empresas sem grants" — LOCKED pelo operador 2026-06-30

```
"Empresas com cust id cadastrado ou que conectaram a conta vendedor do
 mercado livre com nosso sistema através do OAuth"
```

Tradução SQL:
```sql
companies.active = true
  AND (
    cust_id IS NOT NULL
    OR EXISTS(SELECT 1 FROM ml_tokens WHERE ml_tokens.company_id = companies.id AND ml_tokens.status = 'active')
  )
  AND NOT EXISTS(SELECT 1 FROM company_grants WHERE company_id = companies.id AND status = 'active')
```

### Abordagem técnica

- **Migration**: blueprint enxuto:
  ```php
  Schema::table('company_grants', function (Blueprint $table) {
      $table->string('programa', 50)->nullable()->after('segmento');
      $table->string('iniciativa', 50)->nullable()->after('programa');
      $table->string('nivel_solucion', 50)->nullable()->after('iniciativa');
      $table->string('nombre_solucion', 100)->nullable()->after('nivel_solucion');
      $table->string('parceiro', 100)->nullable()->after('nombre_solucion');
      $table->string('localidade', 100)->nullable()->after('parceiro');
      $table->string('medalha_fecha_in', 50)->nullable()->after('localidade');
      $table->string('medalha_fecha_out', 50)->nullable()->after('medalha_fecha_in');
  });
  ```
- **Naming**: snake_case nas colunas DB (`nivel_solucion`, `medalha_fecha_in`). Payload da API vem em camelCase (`nivelSolucion`, `medalhaFechaIn`) — mapeamento explícito no `SyncGrantsFromEcfDrive`.
- **EcfDriveService**: novos métodos seguem pattern dos existentes (`get('/grants/resumo', $params)`). Retornam array decodificado.
- **GrantController fallback**: log com `Log::warning('[Grants] /grants/resumo offline — usando contagem local', ['error' => $e->getMessage()])`. Stats virá com flag `source: 'remote' | 'local'` pro frontend mostrar badge "API offline".
- **UI buckets**: 5 StatCards em uma linha (ou flexbox que quebra em mobile). Cada bucket mostra `count + label`. Click no card filtra a tabela de expirando_soon.
- **UI divergência**: 1 StatCard adicional com cor neutra (informativo); tooltip explica "Sellers em BASE_VENDEDORES sem cadastro ContatosCPP — divergência de cadastro ML".

### Claude's Discretion

- Nome dos métodos do EcfDriveService: `grantsResumo()` vs `getGrantsResumo()` — seguir padrão existente do service (research confirma)
- Posição dos StatCards de bucket na UI: linha separada vs substituir os 5 cards atuais — Plan decide com base no layout vigente
- Componente de bucket: criar `BucketCard` local em `Grants/Index.jsx` (consistente com Phase 46 que fez `ScoreDelta` inline) — não criar componente reusável global
- Polling: manter o pattern de sync existente intacto
- Testes: Mock do `EcfDriveService` (já patterns no projeto via Mockery)

</decisions>

<specifics>
## Specific Ideas

### Payload esperado do `/grants/resumo` (estimado pelo briefing)

```json
{
  "totais": {
    "ativos": 396,
    "vigentes": 345,
    "expirados": 51
  },
  "buckets": {
    "expirando_em_7d": 2,
    "expirando_em_15d": 51,
    "expirando_em_30d": 73,
    "expirando_em_60d": null,
    "expirando_em_90d": null
  },
  "divergencia": {
    "sellers_em_base_sem_contatos_cpp": 726
  }
}
```

**Nota:** estrutura exata será confirmada na research lendo o `EcfDriveService` + smoke da API. O CONTEXT registra a expectativa; planos amarram o mapping no momento da execução.

### Comportamento esperado

**`/grants` (atualizado):**
- StatCards top-line: `Total companies | Active grants | Expired | No grant`
- Nova linha de StatCards: 5 buckets de expiração (7/15/30/60/90d)
- StatCard adicional ao lado: "Divergência ML" com contagem de sellers extras
- Badge "API offline" no header quando stats vier de fallback local
- Tabela principal: colunas atuais + opcionais `Programa`, `Nível`, `Medalha In`, `Medalha Out` (ocultas quando todas NULL)
- Sync button: comportamento inalterado

### Sync command — mapeamento dos 8 campos

```php
// SyncGrantsFromEcfDrive — em updateOrCreate values:
'programa'         => $payload['programa']        ?? null,
'iniciativa'       => $payload['iniciativa']      ?? null,
'nivel_solucion'   => $payload['nivelSolucion']   ?? null,
'nombre_solucion'  => $payload['nombreSolucion']  ?? null,
'parceiro'         => $payload['parceiro']        ?? null,
'localidade'       => $payload['localidade']      ?? null,
'medalha_fecha_in' => $payload['medalhaFechaIn']  ?? null,
'medalha_fecha_out'=> $payload['medalhaFechaOut'] ?? null,
```

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning ou implementing.**

### Patterns existentes (a investigar/reusar)

- `app/Services/EcfDriveService.php` (linhas 75, 552) — pattern de get() + endpoints existentes; novos métodos seguem mesma assinatura
- `app/Http/Controllers/GrantController.php` (linhas 13-76) — index() atual; refator INSEPARÁVEL da contagem local pro consumo remoto
- `app/Console/Commands/SyncGrantsFromEcfDrive.php` (227 linhas) — onde adicionar mapeamento dos 8 campos no `updateOrCreate`
- `app/Models/CompanyGrant.php` (linhas 17, 31, 53-68) — `$fillable`, scopes `expiringSoon()`, accessor `days_remaining`; estender `$fillable` com 8 campos
- `app/Models/MlToken.php` — model usado no critério "OAuth ML ativo" (status='active')
- `app/Models/Company.php` — campo `cust_id`; tabela `companies`
- `resources/js/Pages/Grants/Index.jsx` (395 linhas) — page React atual; StatCards, tabelas; ganha buckets + colunas opcionais + badge "API offline"
- `tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php` — pattern dos testes da Phase 20; reusar mock do EcfDriveService

### Memory cross-refs

- `feedback_project_priorities` — acertividade + praticidade ("100% certeiros" é o que o operador exige)
- `feedback_gsd_language_pt_br` — pt-BR
- `feedback_lean_planning` — pular discuss/research/plan-check overhead — APLICADO nesta phase

</canonical_refs>

<deferred>
## Deferred Ideas

- Distribuições por iniciativa/nivelSolucion/parceiro/localidade — todos 100% uniformes hoje no ML. Reativar quando ML diversificar.
- Endpoint próprio `GrantController@divergencia` — consumir direto do `/grants/resumo` por enquanto
- Notificações automáticas (Slack/email) de grants expirando — fora do escopo
- Detalhe da divergência (lista dos 726 sellers) com link pra investigar — phase futura
- Cache agressivo do `/grants/resumo` — desnecessário hoje (1 chamada/request)
- Redesign visual completo — manter atual, só refinar

</deferred>

---

*Phase: 51-reestruturacao-grants-nova-api-ecf-drive*
*Context gerado: 2026-06-30 (síntese lean — sem discuss-phase interativo; decisão de universo "sem grants" confirmada pelo operador)*
