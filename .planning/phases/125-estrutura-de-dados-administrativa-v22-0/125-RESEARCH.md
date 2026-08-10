# Phase 125: Estrutura de dados administrativa (v22.0) - Research

**Researched:** 2026-08-10
**Domain:** Schema Laravel puro (migration + model + factory + teste) — persistência do processo de
assinatura de contrato via Clicksign
**Confidence:** HIGH

## Summary

Esta fase não tem nenhuma incógnita de API ou de produto: o Gate #9 já foi fechado empiricamente
(`CLICKSIGN-SANDBOX-EMPIRICO.md`) e as 10 decisões de schema já estão travadas no `125-CONTEXT.md`.
O que resta é **aplicar exatamente os padrões que este projeto já usa** para migration, model,
factory e teste de schema — e não inventar nada novo.

O projeto tem três cicatrizes documentadas (enum+SQLite, FK `nullOnDelete` sem `nullable()`, nome
de índice >64 chars) e as três têm exemplo real e recente no repositório, prontos para copiar. O
único elemento genuinamente novo nesta fase é o padrão "coluna auxiliar nullable + índice único"
para garantir no máximo um contrato em andamento por empresa (D-01) — não existe precedente exato
no codebase, mas é comportamento padrão de MariaDB e SQLite (múltiplos `NULL` não colidem em
índice único) e não exige nenhuma técnica exótica.

**Primary recommendation:** Modelar as duas tabelas seguindo o par
`desempenho_company_score_snapshots` (migration mais recente e mais didática sobre nome de índice)
+ `nps_response_covered_services`/`nps_score_assignments` (par `nullable()->constrained()->nullOnDelete()`
correto) como moldes de migration; `HubspotEvento` como molde de model de processo com `status`
string; `ContratoServico` como molde de model com `LogsActivity` + `array` cast; e
`CompanyScoreSnapshotSchemaTest` como molde do teste de schema desta fase.

## User Constraints

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Ciclo de vida do contrato**
- **D-01** — Unicidade garantida no banco E no código. Uma empresa pode ter vários contratos ao
  longo do tempo (o cancelado/expirado fica como histórico), mas no máximo um **em andamento**.
  Garantia dupla: (banco) coluna auxiliar nullable preenchida só enquanto em andamento + índice
  único sobre ela — MariaDB e SQLite tratam múltiplos `NULL` como distintos; (código) guard antes
  de criar, para erro amigável em vez de 500 de constraint. ⚠️ Nome do índice precisa caber em 64
  caracteres.
- **D-02** — Reemissão cria registro novo, nunca reaproveita. Contrato recusado/expirado/cancelado
  permanece intacto como histórico.
- **D-03** — `expira_em` fica para a Fase 127. O estado `expirado` existe desde já, mas não há
  data que o calcule ainda. Nada nesta fase deve fingir que consegue expirar contrato sozinho.

**Como o estado é gravado**
- **D-04** — Coluna `string` + constantes públicas no model, **não** `enum` de banco. Segue o
  padrão já estabelecido (`Sugador::STATUS_*`, `HubspotEvento`).
- **D-05** — `liberado` NÃO é estado. O contrato para em `assinado`; `liberado_em` é uma data
  separada (a REDE-03/Fase 130 preenche). Contrato `assinado` com `liberado_em` nulo é o caso que
  o alerta REDE-02 precisa enxergar.
- **D-06** — Lista de estados (discricionário do Claude, apresentada e não objetada):
  `rascunho`, `aguardando_assinaturas`, `assinado`, `recusado`, `expirado`, `cancelado`, `erro`.
  `recusado` e `expirado` nunca colapsam em `cancelado`/`erro`. `enviado` +
  `aguardando_assinaturas` do plano canônico colapsam num único estado (Clicksign mapeia ambos
  para `running`).

**Signatário**
- **D-07** — `user_id` nullable + dados sempre copiados (nome, e-mail, CPF gravados no próprio
  registro). Evidência jurídica não pode depender de FK viva. ⚠️ FK `user_id` com `nullOnDelete`
  exige `nullable()`.
- **D-08** — Papéis fixos em constantes: `contratante`, `contratada`, `testemunha`. Texto livre
  recusado (PDF precisa posicionar por papel; tela precisa de rótulo estável).
- **D-09** — Situação individual tem lista própria e curta: `pendente`, `assinou`, `recusou`. Não
  reusa as constantes do contrato.

**Serviços e valores**
- **D-10** — Congelados em coluna JSON no próprio `contrato_assinaturas`, no instante da geração.
  Não é lido ao vivo de `contratos_servico`, e não vira terceira tabela. Precedente do projeto:
  `contratos_servico.hubspot_snapshot` com cast `array`. Tabela filha `contrato_assinatura_itens`
  foi considerada e recusada nesta fase.

### Claude's Discretion
- Lista exata de estados (D-06) — apresentada ao usuário e não objetada.
- `LogsActivity` (spatie) no `ContratoAssinatura` — convenção do projeto para todo model de
  domínio; segue sem perguntar.
- Nomes finais de colunas, ordem das migrations, estrutura das factories e formato dos testes.
- Se `signatarios` recebe `cascadeOnDelete` a partir do contrato (provável) — decidir no plano,
  respeitando as três armadilhas de schema.

### Deferred Ideas (OUT OF SCOPE)
- Tabela `contrato_assinatura_itens` (uma linha por serviço) — recusada; se o relatório por SQL
  for pedido, é fase própria.
- Coluna `expira_em` e cálculo de expiração → Fase 127 (DADOS-06, D-03).
- Painel de taxa de assinatura e tempo médio até assinar — dados (`sent_at`/`signed_at`) ficam
  gravados por esta fase, painel é fase futura.
- Reemissão de contrato expirado com revisão humana — Future Requirements; esta fase só garante
  que o schema permite (D-02).
- `contrato_assinatura_eventos` (DADOS-03) → Fase 129.
- Qualquer chamada à API da Clicksign → Fase 126.
- Geração de PDF → Fase 126.
- Tela, permissão `admin.contratos`, botão de gerar → Fase 131.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| DADOS-01 | O sistema registra o processo de assinatura de cada empresa, com o estado atual e as datas de envio, assinatura e liberação | Migration `contrato_assinaturas` com `status` (D-04/D-06), `enviado_em`/`sent_at`, `assinado_em`, `liberado_em` (D-05); molde de migration em `desempenho_company_score_snapshots` (nome de índice) e `HubspotEvento` (model de processo) |
| DADOS-02 | O sistema registra cada signatário do contrato com seu papel, contato e situação individual de assinatura | Migration `contrato_assinatura_signatarios` com `papel` (D-08), `nome`/`email`/`cpf` copiados (D-07), `situacao` (D-09); formato do certificado de evidência confirmado no Gate #9 (`CLICKSIGN-SANDBOX-EMPIRICO.md` §3-4) — persistir `evidencia_signer` (JSON com o bloco `data.signer` inteiro) + colunas promovidas `auths` (JSON de lista), `ip_address`, `assinado_em` |
| DADOS-04 | Recusa de assinatura e prazo expirado são estados próprios, distintos de cancelamento e de falha técnica (D5) | D-06 trava a lista de 7 estados com `recusado` e `expirado` nunca colapsados; teste unitário/schema comprova que os 7 valores persistem e round-trip sem tipo restrito (molde: `status_aceita_string_arbitraria_sem_tipo_restrito` em `CompanyScoreSnapshotSchemaTest`) |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Persistência do estado do contrato | Database / Storage | — | `contrato_assinaturas` é a fonte de verdade local; a Clicksign não é reconsultável para "fulano assinou?" fora de eventos (Gate #9) |
| Persistência do signatário e evidência | Database / Storage | — | `contrato_assinatura_signatarios`; evidência jurídica não pode depender de FK viva nem de URL de terceiro (princípio D-07/D-10) |
| Regra "quais estados existem" | API / Backend (Model) | — | Constantes públicas no model (`ContratoAssinatura::STATUS_*`), não enum de banco (D-04) — mantém a regra em PHP, testável e sem CHECK de banco |
| Unicidade "um contrato em andamento por empresa" | Database / Storage | API / Backend | Garantia dupla (D-01): índice único no banco é a garantia real; guard no controller (fase futura, não aqui) é só UX |
| Congelamento de serviços/valores no instante da assinatura | Database / Storage | — | Coluna JSON em `contrato_assinaturas` (D-10), não uma tabela filha nem leitura ao vivo |

Esta fase não tem componente de Browser/Client, Frontend Server (SSR) nem CDN/Static — é
puramente Database/Storage + as constantes de domínio que vivem no tier API/Backend via Model.
Não há controller, rota, nem tela nesta fase (fora de escopo, ver `<deferred>`).

## Standard Stack

Nenhuma dependência nova. Toda a stack já está instalada e em uso.

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 (instalado) | Migrations, Eloquent, Schema Builder | já em uso em todo o projeto |
| `spatie/laravel-activitylog` | ^4.9 (instalado) | `LogsActivity` no `ContratoAssinatura` (Claude's Discretion, decisão já tomada) | convenção do projeto para todo model de domínio |
| PHPUnit | ^11.5 (instalado) | Testes de schema/model/factory | já em uso, `phpunit.xml` configurado |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `fakerphp/faker` | ^1.23 (instalado, via `laravel/framework` dev deps) | Dados fake nas factories (`fake()->name()`, `fake()->email()`) | Sempre que a factory precisar de valor plausível não-determinístico |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Coluna `string` + constantes PHP (D-04) | `enum` nativo do banco | Recusado por decisão travada — CHECK do SQLite quebra a suíte de testes e adicionar valor exige migration com branch por driver (cicatriz já documentada, ver Pitfalls) |
| Coluna JSON `evidencia_signer` (D-07/Gate #9) | Tabela `contrato_assinatura_evidencias` separada | Fora do desenho de duas tabelas do roadmap; JSON + colunas promovidas (`auths`, `ip_address`) já dá consulta SQL no que importa |

**Installation:** Nenhuma — não há `composer require` nesta fase.

**Version verification:** `laravel/framework` e `spatie/laravel-activitylog` já constam em
`composer.lock` com as versões acima; confirmado por leitura do `composer.json`/`CLAUDE.md`, não
há necessidade de `composer show` para esta fase (nenhum pacote novo).

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote novo — usa exclusivamente dependências já
presentes em `composer.json` (`laravel/framework`, `spatie/laravel-activitylog`). Gate de
legitimidade de pacotes não é acionado.

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│  ESCOPO DESTA FASE — camada de dados, sem controller/rota/tela        │
└─────────────────────────────────────────────────────────────────────┘

  [migration]                    [model]                    [factory]
  contrato_assinaturas   ──cria──▶ ContratoAssinatura ◀──gera dados── ContratoAssinaturaFactory
        │                              │  ▲
        │ hasMany                     │  │ belongsTo
        ▼                              ▼  │
  contrato_assinatura_    ──cria──▶ ContratoAssinaturaSignatario ◀── ContratoAssinaturaSignatarioFactory
  signatarios                           │
                                         │ belongsTo (nullable, D-07)
                                         ▼
                                    User (existente, nullOnDelete)

  Company (existente) ──hasMany──▶ ContratoAssinatura
                                    (Company ganha a relação — único ponto de
                                     contato desta fase com código existente)

  [teste de schema]
  tabela existe + colunas certas → índice único barra 2º "em andamento" →
  round-trip do cast array (JSON) → round-trip dos 7 estados sem CHECK →
  situação do signatário some do vocabulário do contrato e vice-versa
```

A Fase 126 (fora de escopo aqui) vai preencher `clicksign_*` e o PDF a partir do JSON congelado
desta fase; a Fase 129 vai anexar `contrato_assinatura_eventos` e preencher `assinado_em`; a Fase
130 vai preencher `liberado_em` na liberação manual. Esta fase só entrega o continente vazio, mas
correto, para esses preenchimentos futuros.

### Recommended Project Structure
```
database/
├── migrations/
│   ├── 2026_08_10_HHMMSS_create_contrato_assinaturas_table.php
│   └── 2026_08_10_HHMMSS_create_contrato_assinatura_signatarios_table.php
├── factories/
│   ├── ContratoAssinaturaFactory.php
│   └── ContratoAssinaturaSignatarioFactory.php
app/Models/
├── ContratoAssinatura.php
└── ContratoAssinaturaSignatario.php
tests/Feature/Phase125/
└── ContratoAssinaturaSchemaTest.php   (ou 2 arquivos, um por tabela — ver discretion do plano)
```
Nomenclatura de diretório de teste segue o padrão observado (`tests/Feature/Phase122/...`,
`tests/Feature/Phase41/...`) — subpasta por fase quando o teste é específico da fase.

### Pattern 1: Migration com nome de índice explícito e curto (evita erro 1059)
**What:** Nomear manualmente todo índice único/composto em vez de deixar o Laravel autogerar o
nome, quando o nome da tabela é longo.
**When to use:** Sempre que a tabela tiver nome >20 caracteres e o índice for composto ou sobre
coluna longa — `contrato_assinatura_signatarios` (32 caracteres) é exatamente esse caso.
**Example:**
```php
// Source: database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php
// O nome que o Laravel geraria sozinho
// (`desempenho_company_score_snapshots_user_id_company_id_mes_referencia_unique`)
// tem 75 caracteres e o MariaDB recusa identificadores acima de 64
// (erro 1059) — o SQLite dos testes aceita, então isso SÓ aparece
// em produção.
$table->unique(['user_id', 'company_id', 'mes_referencia'], 'dcss_user_company_mes_unique');
$table->index(['mes_referencia', 'user_id'], 'dcss_mes_user_idx');
```
Nesta fase, aplicar o mesmo raciocínio ao índice único de "um contrato em andamento por empresa"
(D-01) e a qualquer índice/unique em `contrato_assinatura_signatarios`. Calcular o comprimento do
nome autogerado ANTES de decidir se precisa nomear — `<tabela>_<col1>_<col2>_unique` do nome
`contrato_assinatura_signatarios` já estoura sozinho com qualquer segunda coluna.

### Pattern 2: FK nullable + nullOnDelete (evita erro 1830)
**What:** Toda FK com `->nullOnDelete()` precisa da coluna `->nullable()` explicitamente — o
MariaDB de produção rejeita `ON DELETE SET NULL` numa coluna `NOT NULL` (erro 1830); o SQLite dos
testes aceita e mascara o problema.
**When to use:** `user_id` do signatário (D-07) e qualquer outra FK "viva mas dispensável" desta
fase.
**Example:**
```php
// Source: database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php
// FK viva pro catálogo — nullOnDelete preserva o snapshot mesmo se o registro
// referenciado for removido depois.
// nullable() OBRIGATÓRIO: o MySQL exige coluna NULLABLE quando a FK é
// ON DELETE SET NULL (erro 1830) — o SQLite dos testes não pega.
$table->foreignId('servico_id')
    ->nullable()
    ->constrained('servicos')
    ->nullOnDelete();
```
Outro precedente idêntico com "quem fez isso" (mais próximo semanticamente do `user_id` do
signatário):
```php
// Source: database/migrations/2026_07_23_100000_create_company_manager_history_table.php
$table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
```

### Pattern 3: Coluna `string` + constantes no Model (nunca `enum` de banco)
**What:** Toda coluna de estado/lista fixa é `$table->string(...)`, sem `enum()`, com as
constantes vivendo em `public const` no Model.
**When to use:** `status` de `contrato_assinaturas` (D-04/D-06) e `situacao`/`papel` de
`contrato_assinatura_signatarios` (D-08/D-09).
**Example (migration):**
```php
// Source: database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php
// STRING sempre — nunca coluna de tipo restrito por lista fixa:
// o CHECK é enforçado no SQLite dos testes e quebra ao surgir
// valor novo (armadilha registrada na memória do projeto).
$table->string('status')->nullable();
```
**Example (model):**
```php
// Source: app/Models/Sugador.php
public const STATUS_PENDENTE       = 'pendente';
public const STATUS_EM_ACAO        = 'em_acao';
// ...
/** Status que NÃO devem ser sobrescritos por uma re-análise (idempotência). */
public const STATUS_TRAVADOS = [
    self::STATUS_EM_ACAO,
    self::STATUS_RESOLVIDO,
    // ...
];
```
Aplicar o mesmo molde a `ContratoAssinatura`:
```php
public const STATUS_RASCUNHO               = 'rascunho';
public const STATUS_AGUARDANDO_ASSINATURAS = 'aguardando_assinaturas';
public const STATUS_ASSINADO               = 'assinado';
public const STATUS_RECUSADO               = 'recusado';
public const STATUS_EXPIRADO               = 'expirado';
public const STATUS_CANCELADO              = 'cancelado';
public const STATUS_ERRO                   = 'erro';
```
E a `ContratoAssinaturaSignatario` (D-08, D-09 — vocabulários SEPARADOS, não reusar constantes):
```php
public const PAPEL_CONTRATANTE = 'contratante';
public const PAPEL_CONTRATADA  = 'contratada';
public const PAPEL_TESTEMUNHA  = 'testemunha';

public const SITUACAO_PENDENTE = 'pendente';
public const SITUACAO_ASSINOU  = 'assinou';
public const SITUACAO_RECUSOU  = 'recusou';
```

### Pattern 4: Índice único com coluna auxiliar nullable (D-01 — "no máximo um em andamento")
**What:** Uma coluna auxiliar (`nullable`) que só recebe valor enquanto o registro está no estado
que deve ser único; índice `unique` sobre essa coluna. Nenhum precedente idêntico existe hoje no
codebase (não há grep de `company_id.*unique` combinando com estado), mas é comportamento SQL
padrão em ambos os drivers do projeto: MariaDB e SQLite tratam múltiplas linhas com valor `NULL`
em coluna indexada como não-conflitantes — só um valor NÃO-NULO repetido colide.
**When to use:** Garantir "empresa X tem no máximo 1 contrato em andamento" sem lógica de
aplicação sozinha (que falha sob duplo clique/retry de fila).
**Example (proposta, a decidir no plano — nome final é discretion):**
```php
// company_id_em_andamento replica company_id SOMENTE enquanto status está
// em {rascunho, aguardando_assinaturas}; fica NULL quando o contrato sai
// desse estado (assinado/recusado/expirado/cancelado/erro). O índice único
// sobre essa coluna torna duplicidade IMPOSSÍVEL no banco — MariaDB e
// SQLite não colidem múltiplos NULL num índice único.
$table->unsignedBigInteger('company_id_em_andamento')->nullable();
$table->unique('company_id_em_andamento', 'contrato_assin_company_andamento_uniq');
```
Manter a lógica de "zerar essa coluna ao sair do estado em andamento" no Model (mutator/observer)
ou no service que muda o `status` — não é escopo desta fase decidir onde (fora do continente de
schema), mas o teste de schema desta fase DEVE provar que o índice existe e barra duplicata
(molde: `chave_unica_barra_duplicata_de_user_company_mes` em `CompanyScoreSnapshotSchemaTest`).
Nome do índice: `contrato_assin_company_andamento_uniq` tem 38 caracteres — seguro, mas confirmar
o comprimento de QUALQUER nome final escolhido no plano (ver Pattern 1).

### Pattern 5: Model de processo com `array` cast para snapshot JSON (D-10)
**What:** Coluna JSON com cast `array`, preenchida uma vez e nunca relida ao vivo da fonte.
**When to use:** `servicos_snapshot` (ou nome equivalente) em `contrato_assinaturas` (D-10) e
`evidencia_signer` em `contrato_assinatura_signatarios` (Gate #9).
**Example:**
```php
// Source: app/Models/ContratoServico.php
protected $casts = [
    // ...
    'hubspot_snapshot' => 'array',
];
```
Round-trip deste cast já tem teste-molde pronto para copiar (`round_trip_de_quality_via_cast_array`
em `CompanyScoreSnapshotSchemaTest`).

### Anti-Patterns to Avoid
- **`enum()` do Laravel/banco para `status`, `situacao` ou `papel`:** quebra a suíte de testes
  no SQLite quando um valor novo é adicionado depois (CHECK enforçado) e exige migration com
  branch por driver (D-04 já decide isso, mas vale para QUALQUER coluna nova desta fase).
- **FK `nullOnDelete()` sem `nullable()`:** passa limpo no SQLite dos testes e quebra só no
  deploy em MariaDB (erro 1830) — sempre grepar por `nullOnDelete` recém-escrito e confirmar
  `nullable()` na mesma linha/bloco.
- **Deixar o Laravel nomear o índice sozinho em tabela de nome longo:** `contrato_assinatura_signatarios`
  já tem 32 caracteres; qualquer `unique(['a','b'], ...)` sem nome explícito é risco de estourar
  64 caracteres e criar a tabela SEM o índice em produção (migration fica `Pending`, sem erro
  visível até alguém procurar).
- **Reler `contratos_servico` ao vivo para saber o que foi contratado:** D-10 exige congelamento;
  reler ao vivo reabre o mesmo bug que já zerou 3 contratos de R$ 3.000 via `hs_mrr=0` do HubSpot.
- **Reusar as constantes de `status` do contrato como valor de `situacao` do signatário:** D-09 é
  explícito — vocabulário separado; gravar `rascunho`/`erro`/`cancelado`/`expirado` do lado do
  signatário é estado impossível.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|--------------|-----|
| Garantir "no máximo 1 contrato em andamento por empresa" | Validação só na aplicação (`Company::hasContratoEmAndamento()` antes do `create`) | Índice único no banco (Pattern 4) + guard de aplicação como CAMADA ADICIONAL, não única | Validação de aplicação sozinha falha sob duplo clique/retry de fila (race condition); o índice único é a garantia real, o guard é só UX (mensagem amigável em vez de 500) |
| Auditoria de mudança de estado do contrato | Tabela de log manual escrita à mão em cada transição | `LogsActivity` (spatie) já configurado no projeto — `getActivitylogOptions()` + `logOnlyDirty()` | Convenção já estabelecida (`ContratoServico`, `BonusInvalidacao`); reescrever duplica o que o pacote já resolve |
| Dados fake determinísticos para teste de round-trip de JSON | Montar array literal repetido em cada teste | Factory com `definition()` retornando o shape padrão + `state()` para variações (molde `NpsSurveyFactory::completed()`) | Reduz duplicação e documenta o shape canônico num único lugar |

**Key insight:** Nesta fase, o "hand-roll" mais tentador é a garantia de unicidade só em PHP — o
projeto já sofreu com dado corrompido por corrida (ver `project_sessoes_paralelas_working_tree`
e o próprio D-01 do CONTEXT, que explicitamente cita "duplo clique ou retry de fila"). O índice
único no banco não é opcional aqui.

## Common Pitfalls

### Pitfall 1: `enum` de banco + SQLite (CHECK enforçado)
**What goes wrong:** Adicionar um valor novo a uma coluna `enum()` do Laravel quebra os Feature
tests com `SQLSTATE[23000] CHECK constraint failed` — o SQLite dos testes ENFORÇA o CHECK que o
`enum()` gera, diferente do que se poderia supor.
**Why it happens:** O Laravel traduz `enum()` para um CHECK constraint tanto em MySQL/MariaDB
quanto em SQLite; só o MariaDB de produção permite `ALTER ... MODIFY COLUMN` para adicionar
valor sem dor, o SQLite exige recriar a coluna.
**How to avoid:** **Evitado por design nesta fase** — D-04 já decide `string` + constantes PHP
para `status`, `papel` e `situacao`. Não introduzir NENHUM `$table->enum(...)` nesta fase, nem
para colunas aparentemente "fechadas para sempre" (ex.: `papel` tem só 3 valores hoje, mas ainda
assim deve ser `string`).
**Warning signs:** Qualquer `$table->enum(` em uma migration desta fase é sinal de desvio da
decisão travada — bloquear no code review.

### Pitfall 2: FK `nullOnDelete()` sem `nullable()` (erro 1830 no MariaDB)
**What goes wrong:** `foreignId('user_id')->constrained()->nullOnDelete()` sem `->nullable()`
passa limpo nos testes (SQLite) e quebra o `php artisan migrate --force` em produção com
`SQLSTATE[HY000]: General error: 1830 Column 'user_id' cannot be NOT NULL`.
**Why it happens:** MariaDB valida em DDL time que `ON DELETE SET NULL` só é válido em coluna
nullable; SQLite não faz essa validação.
**How to avoid:** Toda vez que escrever `->nullOnDelete()` nesta fase (a única FK candidata é
`user_id` do signatário, D-07), confirmar visualmente que `->nullable()` está na mesma cadeia,
ANTES de `->constrained()`.
**Warning signs:** `grep -n "nullOnDelete" <nova migration>` sem `nullable()` na mesma linha ou
bloco imediatamente anterior.

### Pitfall 3: Nome de índice automático >64 caracteres (erro 1059 no MariaDB)
**What goes wrong:** Índice único/composto sem nome explícito, em tabela de nome longo, gera um
identificador autogerado pelo Laravel que estoura o limite de 64 caracteres do MariaDB. O SQLite
aceita sem reclamar. O deploy quebra e a **tabela fica criada, mas sem o índice**, com a migration
marcada `Pending` — silencioso até alguém notar a ausência da constraint.
**Why it happens:** O nome autogerado é
`{tabela}_{coluna1}_{coluna2}_..._unique/index`; `contrato_assinatura_signatarios` (32 chars) já
consome mais da metade do limite sozinho.
**How to avoid:** Nomear TODO índice único/composto manualmente nesta fase, com prefixo curto
(ex.: `ca_` para `contrato_assinaturas`, `cas_` para `contrato_assinatura_signatarios`) — seguir
o molde `dcss_user_company_mes_unique` (Pattern 1). Calcular o comprimento antes de decidir.
**Warning signs:** Qualquer `$table->unique([...])` ou `$table->index([...])` sem segundo
argumento de nome nesta fase, em qualquer uma das duas tabelas.

## Code Examples

### Migration completa — molde adaptado de `desempenho_company_score_snapshots` + `nps_snapshot_tables`
```php
// Source: composto a partir de database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php
//         e database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php
Schema::create('contrato_assinaturas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();

    // STRING sempre — D-04, nunca enum (Pitfall 1).
    $table->string('status')->default('rascunho');

    // Coluna auxiliar do índice único de "1 em andamento" — D-01, Pattern 4.
    $table->unsignedBigInteger('company_id_em_andamento')->nullable();

    $table->timestamp('enviado_em')->nullable();
    $table->timestamp('assinado_em')->nullable();
    $table->timestamp('liberado_em')->nullable(); // D-05 — NÃO é estado

    // D-10 — congelamento de serviços/valores no instante da geração.
    $table->json('servicos_snapshot')->nullable();

    $table->timestamps();

    $table->unique('company_id_em_andamento', 'contrato_assin_company_andamento_uniq');
    $table->index(['company_id', 'status'], 'contrato_assin_company_status_idx');
});
```

### Model completo — molde adaptado de `HubspotEvento` + `ContratoServico`
```php
// Source: composto a partir de app/Models/HubspotEvento.php e app/Models/ContratoServico.php
class ContratoAssinatura extends Model
{
    use LogsActivity;

    protected $table = 'contrato_assinaturas';

    protected $fillable = [
        'company_id', 'status', 'company_id_em_andamento',
        'enviado_em', 'assinado_em', 'liberado_em', 'servicos_snapshot',
    ];

    protected $casts = [
        'enviado_em'         => 'datetime',
        'assinado_em'        => 'datetime',
        'liberado_em'        => 'datetime',
        'servicos_snapshot'  => 'array',
    ];

    public const STATUS_RASCUNHO               = 'rascunho';
    public const STATUS_AGUARDANDO_ASSINATURAS = 'aguardando_assinaturas';
    public const STATUS_ASSINADO               = 'assinado';
    public const STATUS_RECUSADO               = 'recusado';
    public const STATUS_EXPIRADO               = 'expirado';
    public const STATUS_CANCELADO              = 'cancelado';
    public const STATUS_ERRO                   = 'erro';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'enviado_em', 'assinado_em', 'liberado_em'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Contrato de assinatura criado',
                'updated' => 'Contrato de assinatura atualizado',
                default   => $event,
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function signatarios(): HasMany
    {
        return $this->hasMany(ContratoAssinaturaSignatario::class);
    }
}
```

### Factory — molde adaptado de `NpsSurveyFactory`
```php
// Source: padrão de database/factories/NpsSurveyFactory.php
class ContratoAssinaturaFactory extends Factory
{
    protected $model = ContratoAssinatura::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'status'     => ContratoAssinatura::STATUS_RASCUNHO,
            'company_id_em_andamento' => null, // preenchido só em estado "em andamento"
            'servicos_snapshot' => null,
        ];
    }

    /** State: contrato em andamento — ocupa o slot do índice único (D-01). */
    public function emAndamento(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'company_id_em_andamento' => $attrs['company_id'],
        ]);
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| Modelar o campo de evidência do signatário como JSON genérico (assunção pré-sandbox) | Persistir `data.signer` inteiro como JSON + promover `auths`/`address`/timestamp a colunas próprias | 2026-08-10 (rodada empírica) | Gate #9 fechado; a forma exata do JSON agora é conhecida, não mais um placeholder |
| Tratar `deadline_at`/`expira_em` como algo a implementar já | Confirmado que a Clicksign preenche `deadline_at` sozinha (+30 dias); coluna local é espelho | 2026-08-10 | Reforça D-03 — nada a fazer aqui além de não fingir suporte |

**Deprecated/outdated:** Nenhum — esta é a primeira fase que cria este schema, não há versão
anterior a substituir.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|----------------|
| A1 | Nome exato das colunas (`company_id_em_andamento`, `servicos_snapshot`, `evidencia_signer`, `auths`, `ip_address`) é sugestão desta pesquisa, não decisão travada — CONTEXT.md deixa "nomes finais de colunas" como discretion do plano | Architecture Patterns, Code Examples | Baixo — são só nomes; o plano pode renomear livremente sem violar nenhuma decisão travada, desde que preserve a semântica (auxiliar nullable p/ unicidade, JSON p/ congelamento) |
| A2 | O padrão "coluna auxiliar nullable + índice único" (Pattern 4) não tem precedente idêntico no codebase — comportamento de múltiplos NULL em índice único é conhecimento geral de SQL (MariaDB/SQLite), não verificado experimentalmente nesta sessão contra o MariaDB real do projeto | Pattern 4 | Baixo-médio — é comportamento documentado do padrão SQL e ambos os drivers (InnoDB e SQLite) seguem a mesma regra; ainda assim, o teste de schema desta fase (Validation Architecture) deve provar isso na suíte, não só confiar na teoria |
| A3 | O nome de índice sugerido `contrato_assin_company_andamento_uniq` (38 chars) e os demais nomes de índice sugeridos nesta pesquisa cabem em 64 caracteres — contagem manual, não executada via script | Pattern 1, Pattern 4 | Baixo — contagem simples de string; o plano/execução deve recontar o nome FINAL escolhido antes de aplicar |

## Open Questions

1. **Um arquivo de migration ou dois?**
   - O que sabemos: o projeto tem exemplos dos dois formatos — uma migration com múltiplas
     `Schema::create` (molde `nps_snapshot_tables.php`, 3 tabelas relacionadas por FK em ordem) e
     migrations separadas por tabela (a maioria dos outros exemplos).
   - O que é incerto: qual formato o planner deve escolher para as 2 tabelas desta fase, dado que
     `contrato_assinatura_signatarios` tem FK para `contrato_assinaturas`.
   - Recomendação: seguir o molde `nps_snapshot_tables.php` (uma migration, ordem de criação
     respeitando dependência de FK: `contrato_assinaturas` antes de
     `contrato_assinatura_signatarios`) — mantém as duas tabelas desta fase atômicas num único
     commit de schema, como a Fase 79 fez com 3 tabelas relacionadas. É discretion do plano, mas
     a pesquisa recomenda por consistência com o precedente mais próximo em forma.

2. **`cascadeOnDelete` de `signatarios` a partir de `contrato_assinaturas` — confirmar consequência.**
   - O que sabemos: CONTEXT.md já marca isso como "provável" e deixa a decisão final para o plano.
   - O que é incerto: se apagar um `ContratoAssinatura` (histórico) deve arrastar os signatários
     junto, ou se soft-delete/preservação seria mais alinhado ao princípio "evidência jurídica não
     pode sumir" que perpassa D-06, D-07 e D-10.
   - Recomendação: como esta fase não implementa nenhuma rota de "apagar contrato" (fora de
     escopo), `cascadeOnDelete` é seguro na prática — só dispara se alguém rodar
     `ContratoAssinatura::destroy()` manualmente, o que não é um fluxo desta milestone. Mas
     documentar a escolha no PLAN.md para a Fase 131 (tela) saber que apagar (se algum dia
     existir) apaga em cascata.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI | Rodar migrations/testes | ✓ (fora do PATH) | 8.2.12 | Chamar via `C:\xampp\php\php.exe` explicitamente em todo comando desta fase |
| PHPUnit | Suíte de testes | ✓ | 11.5.x (via `vendor/bin/phpunit`) | — |
| SQLite (`:memory:`) | `RefreshDatabase` nos testes Feature | ✓ (confirmado via `phpunit.xml`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) | — | — |
| MariaDB de produção | Deploy real (fora do escopo de execução desta fase) | não verificado nesta sessão | — | Os 3 pitfalls documentados são justamente sobre divergência SQLite×MariaDB — não há como emular MariaDB localmente nesta pesquisa; seguir os padrões já provados em produção (Fases 79/108/122) é o fallback |

**Missing dependencies com bloqueio:** nenhuma.

**Missing dependencies com fallback:** MariaDB real não disponível para teste local — mitigado
seguindo religiosamente os 3 padrões já comprovados em produção (Patterns 1-2-3).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.x (via `laravel/framework` test kit) |
| Config file | `phpunit.xml` (raiz do projeto) |
| Runtime PHP nesta máquina | `C:\xampp\php\php.exe` (PHP 8.2.12) — **fora do PATH**, sempre chamar pelo caminho completo |
| Quick run command | `"/c/xampp/php/php.exe" vendor/bin/phpunit --testdox tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php` |
| Full suite command | ⚠️ **NUNCA** `phpunit` sem argumentos — `SyncGrantsFromEcfDrive.php:23` e `SyncGrantsFromSftp.php:22` chamam `set_time_limit(300)`, e a suíte completa historicamente trava/mata o processo. Rodar por arquivo/diretório explícito, nunca a suíte inteira de uma vez. |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DADOS-01 | `contrato_assinaturas` existe com `status` + `enviado_em`/`assinado_em`/`liberado_em` | schema | `"/c/xampp/php/php.exe" vendor/bin/phpunit --testdox tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php --filter tabela_existe` | ❌ Wave 0 |
| DADOS-01 | Índice único barra 2º contrato "em andamento" pela mesma empresa | schema | mesmo arquivo, `--filter indice_unico_barra_duplicata` | ❌ Wave 0 |
| DADOS-01 | Factory `ContratoAssinaturaFactory` produz registro válido (Success Criteria 1) | unit/factory | mesmo arquivo ou `ContratoAssinaturaFactoryTest.php`, teste `factory_cria_registro_valido` | ❌ Wave 0 |
| DADOS-02 | `contrato_assinatura_signatarios` existe com `papel`/`situacao`/dados copiados/`user_id` nullable | schema | `tests/Feature/Phase125/ContratoAssinaturaSignatarioSchemaTest.php --filter tabela_existe` | ❌ Wave 0 |
| DADOS-02 | Signatário vinculado ao contrato correto (`belongsTo`/`hasMany` funcionam) | unit | mesmo arquivo, `--filter signatario_pertence_ao_contrato` | ❌ Wave 0 |
| DADOS-02 | Campo de evidência (JSON) faz round-trip do bloco `data.signer` do Gate #9 | unit | mesmo arquivo, `--filter round_trip_evidencia_signer` | ❌ Wave 0 |
| DADOS-04 | Os 7 estados persistem e sobrevivem a round-trip, sem CHECK/enum restritivo | schema | `--filter status_aceita_qualquer_um_dos_7_estados` | ❌ Wave 0 |
| DADOS-04 | `recusado` e `expirado` nunca colapsam — teste explícito de que são valores distintos persistidos, não sinônimos de `cancelado`/`erro` | unit | `--filter recusado_e_expirado_sao_distintos_de_cancelado_e_erro` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `"/c/xampp/php/php.exe" vendor/bin/phpunit --testdox tests/Feature/Phase125/` (roda só os arquivos desta fase, rápido)
- **Per wave merge:** adicionar os arquivos de fases adjacentes que tocam `Company` ou schema
  recente (ex.: `tests/Feature/Phase122/`, `tests/Feature/BonusInvalidacaoEmpresaTest.php`) para
  garantir que nada de `Company::class` quebrou — seguir o padrão de "lista de 5 arquivos" visto
  em `124-RESEARCH.md`
- **Phase gate:** todos os arquivos de `tests/Feature/Phase125/` + `tests/Unit/` relacionados a
  `ContratoAssinatura*` verdes antes de `/gsd:verify-work`. **Não** rodar `phpunit` sem filtro —
  risco de travar o processo (ver acima).

### Wave 0 Gaps
- [ ] `tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php` — cobre DADOS-01, parte de DADOS-04
- [ ] `tests/Feature/Phase125/ContratoAssinaturaSignatarioSchemaTest.php` — cobre DADOS-02
- [ ] `database/factories/ContratoAssinaturaFactory.php` — não existe, Success Criteria 1 exige
- [ ] `database/factories/ContratoAssinaturaSignatarioFactory.php` — não existe, Success Criteria 2 exige
- [ ] `database/migrations/..._create_contrato_assinaturas_table.php` — não existe
- [ ] `database/migrations/..._create_contrato_assinatura_signatarios_table.php` — não existe
- [ ] `app/Models/ContratoAssinatura.php` — não existe
- [ ] `app/Models/ContratoAssinaturaSignatario.php` — não existe
- [ ] Nenhum framework/config novo necessário — `phpunit.xml` já cobre tudo

## Security Domain

`security_enforcement` está ausente do `.planning/config.json` → tratado como habilitado. Esta
fase, porém, **não expõe nenhuma superfície de ataque nova**: não há controller, rota, request
HTTP nem input de usuário — é puramente migration + model + factory + teste, consumido só por
código PHP interno (fases futuras) e pela suíte de testes.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-------------------|
| V2 Authentication | não | sem rota/controller nesta fase |
| V3 Session Management | não | idem |
| V4 Access Control | não | idem — `admin.contratos` só nasce na Fase 131 |
| V5 Input Validation | parcial | não há input de usuário HTTP aqui, mas o `$fillable` do Model já restringe mass-assignment (convenção do projeto, seguida em `ContratoServico`/`HubspotEvento`) |
| V6 Cryptography | não diretamente, mas nota importante | CPF do signatário é gravado em texto puro na coluna copiada (D-07) — mesmo padrão que o projeto já usa para dados de contato (`email_cliente`, `telefone` em `Company`); não é escopo desta fase introduzir criptografia em repouso, mas registrar a decisão para quem revisar segurança da milestone: CPF em claro é dado pessoal sensível (LGPD) e a política de proteção (se houver) é decisão de produto fora do escopo desta pesquisa |

### Known Threat Patterns for este domínio (schema only)

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|-----------------------|
| Mass assignment indevido no Model (se algum código futuro passar array de request direto pro `create()`) | Tampering | `$fillable` explícito no Model (já planejado, seguindo `ContratoServico`) — nunca `$guarded = []` |
| Corrida de duplo clique/retry de fila criando 2 contratos "em andamento" para a mesma empresa | Tampering / Denial of Service (integridade de dado) | Índice único no banco (D-01, Pattern 4) — não depender só de validação de aplicação |
| Evidência jurídica (`evidencia_signer`, CPF, nome) apagada ou perdida por FK cascade indevida | Repudiation | `user_id` nullable + dados sempre copiados (D-07) garante que apagar o `User` NUNCA apaga a evidência de quem assinou |

## Sources

### Primary (HIGH confidence — leitura direta do codebase nesta sessão)
- `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` — molde de nome de índice explícito, coluna `status` string, decimal de alta precisão
- `database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php` — molde de FK `nullable()->constrained()->nullOnDelete()`, `enum` cross-driver seguro (não usado nesta fase, mas o comentário explica a diferença), múltiplas tabelas numa migration com ordem de FK
- `database/migrations/2026_07_14_100001_add_shopee_to_servicos_setor_enum.php` — molde do branch `Schema::getConnection()->getDriverName() === 'mysql'` para enum+SQLite (não necessário nesta fase por D-04, mas confirma a armadilha 1)
- `database/migrations/2026_07_23_100000_create_company_manager_history_table.php` — molde de `changed_by` nullable + nullOnDelete (mais próximo semanticamente do `user_id` do signatário)
- `app/Models/ContratoServico.php` — molde de model com `LogsActivity`, `array` cast, `getActivitylogOptions()`
- `app/Models/HubspotEvento.php` — molde de model de processo com `status` string documentado em docblock
- `app/Models/BonusInvalidacao.php` — molde de model com FK nullable (`invalidated_by`) e método estático de consulta
- `app/Models/Sugador.php` — molde de constantes `public const` + array agregador (`STATUS_TRAVADOS`)
- `database/factories/CompanyFactory.php`, `NpsSurveyFactory.php`, `NpsResponseAnswerFactory.php`, `CompanyMarketplaceFactory.php` — moldes de factory (definition + state)
- `tests/Feature/Phase122/CompanyScoreSnapshotSchemaTest.php` — molde direto de teste de schema (colunas, índice único, round-trip decimal, round-trip array, string sem tipo restrito)
- `tests/Feature/BonusInvalidacaoEmpresaTest.php` — molde de teste de model com FK nullable e `RefreshDatabase`
- `.planning/phases/124-.../124-RESEARCH.md` §Q6 — comando exato de PHPUnit, aviso sobre `set_time_limit`, formato da tabela Requirements → Test Map
- `.planning/phases/125-.../125-CONTEXT.md` — as 10 decisões travadas (fonte primária desta fase)
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — Gate #9 fechado, formato exato da evidência
- `.planning/REQUIREMENTS-v22.md` — DADOS-01/02/04 e rastreabilidade da milestone
- `app/Console/Commands/SyncGrantsFromEcfDrive.php:23`, `SyncGrantsFromSftp.php:22` — confirmação do `set_time_limit(300)` que trava a suíte completa
- `phpunit.xml` — confirmação de `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`
- `.planning/config.json` — `nyquist_validation: true`, `security_enforcement` ausente (default habilitado)

### Secondary (MEDIUM confidence)
- Nenhuma — toda a pesquisa desta fase foi feita por leitura direta do codebase e dos documentos
  canônicos da milestone, sem necessidade de WebSearch/Context7 (não há biblioteca externa nova).

### Tertiary (LOW confidence)
- Nenhuma.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhuma dependência nova, tudo já em `composer.json`
- Architecture: HIGH — padrões extraídos de código real e recente do próprio projeto (Fases 79,
  108, 111, 122)
- Pitfalls: HIGH — os 3 pitfalls já são cicatrizes documentadas do projeto com exemplo real de
  como foram resolvidos antes
- Padrão do índice único com coluna auxiliar (D-01): MEDIUM — comportamento SQL padrão bem
  conhecido, mas sem precedente idêntico no codebase para copiar linha a linha (ver Assumption A2)

**Research date:** 2026-08-10
**Valid until:** 2026-09-09 (30 dias — schema Laravel é estável; revalidar se a Fase 126/127
descobrir necessidade de coluna adicional que mude o desenho aqui proposto)
