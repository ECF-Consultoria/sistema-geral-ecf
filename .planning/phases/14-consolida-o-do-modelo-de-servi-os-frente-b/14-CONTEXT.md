---
phase: 14
phase_name: Consolidação do Modelo de Serviços (Frente B)
gathered: 2026-05-26
status: Ready for planning
---

# Phase 14 Context — Consolidação do Modelo de Serviços (Frente B)

<domain>
## Domain Boundary

Migrar empresas existentes do **modelo legacy de serviços** (5 colunas em `companies`: `service_type` JSON array enum + `additional_service` texto livre + `additional_service_price` decimal + `contract_start/end` date) para o **modelo N:N introduzido na Frente A** (catálogo `servicos` + `contratos_servico`), refatorando os 7 arquivos que consomem os campos legacy hoje, **sem alterar o resultado financeiro do Fechamento**.

**Capacidade entregue:** Sistema unificado onde toda gestão de serviços (catálogo + contratos por empresa + cobranças mensais + roteamento por tipo no cadastro Comercial) usa exclusivamente o modelo N:N. Após esta fase, os 5 campos legacy de `companies` deixam de existir.

**Out of scope desta fase:**
- Mudanças na tabela de FAIXAS (continua como hoje — decisão D-02)
- Reescrita da progressão por faturamento (FAIXAS pode mudar em fase futura, mas não aqui)
- Mudanças no cálculo de Adman/dashboard (Frente A já cobriu o que precisava)
- Permissões/RBAC do módulo Serviços (já entregue na Frente A)

</domain>

<decisions>
## Implementation Decisions

### D-01 — Mapeamento dos 6 tipos legacy → catálogo

**Locked: Hard-coded na data migration**

A migration cria os 6 registros em `servicos` com nomes humanos (pt-BR), tipo_cobranca padrão `mensal`, valor_padrao=0, ativo=true. Idempotente via `firstOrCreate` por `nome`.

Mapeamento exato (sem flexibilidade — downstream agents devem usar isso literalmente):

```php
$mapaLegacy = [
    'publicacao'  => 'Publicação',
    'polos'       => 'Polos',
    'assessoria'  => 'Assessoria',
    'incubadora'  => 'Incubadora',
    'publicidade' => 'Publicidade',
    'gestao'      => 'Gestão',
];

foreach ($mapaLegacy as $legacy => $nome) {
    Servico::firstOrCreate(
        ['nome' => $nome],
        ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
    );
}
```

Pós-migration o usuário ajusta `valor_padrao` real via UI da Frente A (`/servicos`). Não bloqueia a migration.

### D-02 — Dedupe de `additional_service` (texto livre)

**Locked: Normalização agressiva (trim + Title Case UTF-8)**

```php
$nome = mb_convert_case(trim($company->additional_service), MB_CASE_TITLE, 'UTF-8');
Servico::firstOrCreate(['nome' => $nome], [...]);
```

Resultado: "consultoria", "Consultoria", "  CONSULTORIA  " todos viram "Consultoria" (1 registro no catálogo).

**Caveat aceito:** "Treinamento 1h" e "Treinamento 1H" virariam distintos (case do "h"). Aceitável — o usuário limpa via UI depois se aparecer caso assim.

### D-03 — Tabela de FAIXAS

**Locked: FAIXAS continua intacta. Contratos novos são ADICIONAIS, não substitutos.**

O cálculo de `cobranca_mensal` em `AdminController::fechamento` muda apenas a fonte do **componente extra**, mantendo `faixaData['valor']` como base:

```php
// ANTES (legacy):
$cobrancaMensal = ($faixaData['valor'] ?? 0)
                + (float) ($company->additional_service_price ?? 0);

// DEPOIS (Phase 14):
$cobrancaMensal = ($faixaData['valor'] ?? 0)
                + $company->contratosServico()
                    ->where('ativo', true)
                    ->whereHas('servico', fn($q) => $q->where('tipo_cobranca', 'mensal'))
                    ->sum('valor_contratado');
```

**Invariante para verifier (SVC-02):** Para toda empresa que tinha `additional_service_price` preenchido, o valor de `cobranca_mensal` calculado pós-migration deve bater (até R$ 0,01) com o cálculo pré-migration. Empresas sem additional_service_price também devem bater (faixaData['valor'] vs faixaData['valor'] + SUM(0) = identical).

Os 6 serviços-tipo (Publicação, Polos, etc.) têm `valor_contratado=0` por padrão pós-migration, então não somam nada à cobrança — apenas servem como **classificação** (substituem o uso de `service_type` para filtros/badges/Fechamento). O componente "valor por faturamento" continua sendo lookup FAIXAS exatamente como hoje.

### D-04 — Migração de dados (empresas órfãs e variantes)

**Locked: Migration cria contratos derivando de TODOS os campos legacy disponíveis na empresa**

Para cada empresa, regra cumulativa:

1. **Para cada valor em `service_type`** (JSON array): cria 1 contrato apontando para o `servico` do catálogo (lookup via `$mapaLegacy[$tipo]`)
   - `valor_contratado` = 0
   - `data_contratacao` = `contract_start ?? company.created_at::toDateString()`
   - `data_vencimento` = `contract_end` (nullable, default null)
   - `ativo` = true

2. **Se `additional_service` != null/vazio:** cria 1 contrato adicional (separado dos do passo 1)
   - `servico_id` = lookup find-or-create por nome normalizado (D-02)
   - `valor_contratado` = `(float) ($additional_service_price ?? 0)`
   - `data_contratacao` = `contract_start ?? company.created_at::toDateString()`
   - `data_vencimento` = `contract_end`
   - `ativo` = true

3. **Se ambos `service_type` e `additional_service` vazios:** empresa não recebe nenhum contrato (estado neutro, ex: cadastros do Comercial em status 'pendente' sem definição ainda).

4. **Se `additional_service` set mas `service_type` vazio:** cria APENAS o contrato do passo 2.

Migration é idempotente — pode rodar 2× sem duplicar (find-or-create por nome no catálogo + verificação `whereDoesntHave` ou explicit check antes de criar contrato).

### D-05 — Form do Comercial (`Comercial/NovaEmpresa.jsx`) pós-refatoração

**Locked: Inline single-step com seletor multi do catálogo + roteamento por nome do serviço**

UI do form passa a ter:
- Nome (mantido)
- CNPJ (mantido)
- **`servicos[]`** — multi-select do catálogo de serviços ativos (substitui `service_type`)
- Subtipo POLOS/Assessoria condicional (mantido — só aparece se algum serviço selecionado disparar essa categoria)

Backend (`ComercialController`) em uma única `DB::transaction`:
1. Cria `companies` (status='pendente')
2. Cria `contratos_servico` (um por servico selecionado, valor_contratado=valor_padrao do catálogo editável no form se necessário, data_contratacao=today, data_vencimento=null, ativo=true)
3. Inspeciona nomes dos serviços via switch de palavras-chave (helper `servicoDisparaImplementacao($nome)`) para decidir se cria `mlb_empresas` + `mlb_implementacao` — mantém o roteamento Phase 13 sem quebra:

```php
function servicoDisparaImplementacao(string $nome): ?string {
    return match(true) {
        str_contains($nome, 'Polos')      => 'polos',
        str_contains($nome, 'Assessoria') => 'assessoria',
        str_contains($nome, 'Incubadora') => 'incubadora',
        default                            => null,
    };
}
```

Phase 13 success criteria (COM-04, COM-05, COM-06) continuam válidas — o roteamento por tipo é preservado, mudou só a fonte do tipo (de `service_type` enum para `servicos.nome` do catálogo).

### D-06 — Estrutura da migration (Claude's discretion)

Sequência de migrations (3 arquivos, datas posteriores a `2026_05_26_120002`):

1. **`2026_05_27_100001_seed_servicos_catalog.php`** — popula catálogo com os 6 tipos canônicos (idempotente via firstOrCreate)
2. **`2026_05_27_100002_migrate_legacy_service_data.php`** — cria contratos para todas as empresas existentes (D-04). Não dropa colunas legacy.
3. **`2026_05_27_100003_drop_legacy_service_columns_from_companies.php`** — drop das 5 colunas legacy. `down()` recria o schema vazio (sem rollback de dados pós-drop, documentado).

Separação em 3 migrations permite rollback parcial: se algo der errado na migration 2, ainda dá pra `migrate:rollback --step=1` sem perder dados legacy. Migration 3 só roda depois de validação humana do Fechamento.

### D-07 — Refatoração de consumers (lista completa)

7 arquivos que referenciam os campos legacy (confirmado via grep — `service_type|additional_service|contract_start|contract_end|additional_service_price`):

1. **`app/Models/Company.php`** — remove `$fillable`/`$casts`/`logOnly` dos 5 campos; mantém `contratosServico()` relation (criada na Frente A)
2. **`app/Http/Controllers/AdminController.php`** — `fechamento()` e `updateFechamento()` reescritos: `cobranca_mensal` segue D-03; editor da UI passa contratos no lugar de additional_service; filtros via JOIN servicos.nome
3. **`app/Http/Controllers/CompanyController.php`** — qualquer referência aos legacy fields removida (já parcialmente limpo na Frente A, validar)
4. **`app/Http/Controllers/ComercialController.php`** — refatora cadastro conforme D-05
5. **`app/Http/Controllers/MlbController.php`** — inspecionar uso (provavelmente filtro por service_type); migrar para JOIN em contratos_servico
6. **`app/Notifications/EmpresaCadastradaNotification.php`** — conteúdo da notificação cita service_type; substituir por nomes dos contratos da empresa
7. **`app/Jobs/EnviarRelatorioFechamentoJob.php`** — conteúdo do email cita additional_service; substituir por lista de contratos ativos

Cada arquivo = 1 commit atômico no executor.

### D-08 — Verificação financeira (claude's discretion + obrigatório)

Antes do drop das colunas legacy (migration 3), executar **comparação automatizada** que itera todas as empresas e confere:

```php
foreach (Company::all() as $c) {
    $legacy = ($faixaData[$c->id]['valor'] ?? 0) + (float) ($c->additional_service_price ?? 0);
    $novo   = ($faixaData[$c->id]['valor'] ?? 0) + $c->contratosServico()->active()->mensal()->sum('valor_contratado');
    if (abs($legacy - $novo) > 0.01) {
        Log::error("[Phase14] cobranca divergente empresa {$c->id} ({$c->name}): legacy=R$ {$legacy} novo=R$ {$novo}");
        $divergencias++;
    }
}
abort_if($divergencias > 0, 500, "Phase 14: {$divergencias} empresas com divergência de cobrança — corrigir antes do drop.");
```

Se houver divergência, a migration 3 (drop) é abortada e o usuário decide. Falha-loud é melhor que erro silencioso em fatura.

### Folded Todos
Nenhum.

### Claude's Discretion
Decisões técnicas sem ambiguidade que serão tomadas pelo planner/executor:
- Schema das migrations (já listado em D-06, mas formato específico de cada CREATE/ALTER fica com o executor)
- Nomes exatos dos métodos novos em ComercialController
- Layout exato do form de NovaEmpresa.jsx (continua usando o componente atual com modificação cirúrgica do input service_type → seletor multi)
- Estratégia de teste (sugestão: 1 teste de integração por consumer refatorado + 1 teste de migration idempotência + 1 teste de comparação financeira; deixar para o planner detalhar)
- Activity log: ContratoServico já loga via LogsActivity (Frente A) — Phase 14 não precisa adicionar nada nesse aspecto

</decisions>

<specifics>
## Specific References

- **Frente A entregue como quick task `260526-jgj`** — tabelas `servicos` + `contratos_servico` já existem, UI funcionando em `/servicos` e `Companies/Show.jsx`. Ver `.planning/quick/260526-jgj-modulo-servicos-frente-a/260526-jgj-SUMMARY.md` para arquivos exatos.
- **Constant da migration legacy:** `2026_05_19_100001_add_service_fields_to_companies.php` (criou os 4 campos); `2026_05_20_100002_add_additional_service_price_to_companies.php` (criou o 5º); `2026_05_25_300001_convert_service_type_to_json_array.php` (converteu service_type de string para JSON array).
- **Cálculo atual de cobrança:** `AdminController::fechamento` ~linha 280 — `($faixaData['valor'] ?? 0) + (float) ($c->additional_service_price ?? 0)`.
- **Tabela de FAIXAS:** constante hardcoded no backend (introduzida na Phase 6, ver `06-01-PLAN.md`). NÃO é modificada nesta fase.
- **Roteamento Phase 13:** `ComercialController` em `DB::transaction` cria companies + mlb_empresas + mlb_implementacao conforme o `service_type` do cadastro. Phase 14 preserva esse roteamento via D-05.

</specifics>

<canonical_refs>
## Canonical References

**MUST read before planning:**

- `.planning/ROADMAP.md` — Phase 14 declaration (Goal + 9 Success Criteria)
- `.planning/REQUIREMENTS.md` — SVC-01..07 (mapeados para Phase 14)
- `.planning/quick/260526-jgj-modulo-servicos-frente-a/260526-jgj-PLAN.md` — Frente A entregue (modelo já existe no DB)
- `.planning/quick/260526-jgj-modulo-servicos-frente-a/260526-jgj-SUMMARY.md` — Estado final da Frente A
- `app/Http/Controllers/AdminController.php` — método `fechamento` (linha ~280, cálculo de cobrança_mensal)
- `app/Http/Controllers/ComercialController.php` — método de cadastro de nova empresa (Phase 13)
- `database/migrations/2026_05_19_100001_add_service_fields_to_companies.php` — schema dos campos legacy
- `database/migrations/2026_05_25_300001_convert_service_type_to_json_array.php` — formato atual de service_type (JSON array)
- `app/Models/Company.php` — fillable, casts, LogsActivity (campos legacy listados)
- `resources/js/Pages/Admin/Financeiro.jsx` — UI atual do Fechamento (editor de service_type/contract_start/additional_service)
- `resources/js/Pages/Comercial/NovaEmpresa.jsx` — form a refatorar

**Frente A artifacts (já no banco):**
- `app/Models/Servico.php`
- `app/Models/ContratoServico.php`
- `database/migrations/2026_05_26_120001_create_servicos_table.php`
- `database/migrations/2026_05_26_120002_create_contratos_servico_table.php`

</canonical_refs>

<code_context>
## Reusable Assets

**Modelo N:N pronto:** `Servico` e `ContratoServico` (Frente A) já têm casts, LogsActivity, scopeActive, relations. Reusa sem modificação na Phase 14.

**UI de gestão de contratos:** A seção "Serviços contratados" em `Companies/Show.jsx` (Frente A) já tem modal de adicionar/editar contrato com select de catálogo, valor pré-preenchido, datas, observações. A refatoração do `Admin/Financeiro.jsx` (SVC-03) DEVE reusar exatamente esse padrão de UI (mesmo modal, mesmas validações, mesmas chamadas de rota) — não criar UI nova.

**Rotas existentes (Frente A):**
- `POST /empresas/{company}/contratos-servico` → store
- `PUT  /empresas/{company}/contratos-servico/{contrato}` → update
- `DELETE /empresas/{company}/contratos-servico/{contrato}` → destroy
- `GET /servicos` → catálogo

Não precisa criar rotas novas — só consumir.

**Padrão de Activity Log:** Servico e ContratoServico já usam LogsActivity. Não duplicar.

</code_context>

<deferred>
## Deferred Ideas (não nesta fase)

- **Reescrita da tabela FAIXAS** — D-02 mantém FAIXAS como está. Se o usuário quiser mover faixas pro novo modelo (cada faixa = um valor diferente em valor_contratado), isso é fase futura dedicada.
- **UI dedicada para reativar contratos desativados** — hoje só via editar+marcar ativo. Botão "Reativar" pode entrar em sessão futura.
- **Permissão `sistema.servicos` para outros setores** — admin via short-circuit já recebe. Grant explícito pra setores como Administrativo/Financeiro fica pendente até demanda real.
- **Histórico/auditoria de contratos** — Activity log já registra criação/edição via LogsActivity. UI de histórico de mudanças por contrato é refinamento futuro.
- **Validação de unicidade de contrato ativo** — hoje não há constraint que impeça 2 contratos ativos do mesmo serviço pra mesma empresa. Se aparecer demanda, vira fase futura (pode quebrar fluxos existentes).
- **Migration de pre-cadastro de valor_padrao realista** — a migration de Phase 14 cria os 6 com valor_padrao=0. Pode entrar pre-data com valores realistas em fase futura (ou via UI manual).

</deferred>
