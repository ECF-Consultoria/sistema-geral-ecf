# Phase 150: Máquina de estados — os 9 status de `companies.etapa` (v23.0) - Research

**Researched:** 2026-09-01
**Domain:** Migration aditiva + máquina de estados em Laravel/Eloquent sobre tabela de produção (`companies`, ~500 registros)
**Confidence:** HIGH

## Summary

Esta pesquisa não investiga "como fazer máquina de estados em Laravel" — o CONTEXT.md já
travou D-01..D-23 e o PDF (`fluxo-entrada-novas-empresas-260901.md`) já é a especificação
funcional (D0 do REQUIREMENTS-v23). O que faltava, e que este documento fecha com leitura
direta do código de `origin/main`/worktree atual, é: (1) confirmar que os precedentes que o
CONTEXT aponta são exatamente o que ele descreve, com linha e assinatura reais; (2) resolver
com evidência os dois pontos que o CONTEXT deixou em aberto para o planejamento (D-16 histórico,
D-18 onde a pendência mora); e (3) estabelecer a baseline de testes que o `CLAUDE.md` exige
antes de qualquer migration em tabela com dado de produção.

Três achados mudam o que o planner precisa saber em relação ao que está escrito no CONTEXT/
REQUIREMENTS: **(a)** a suíte de testes desta tela SÓ roda neste worktree com um workaround —
não existe `node_modules/` nem `public/build/manifest.json` aqui, e toda `Feature` test que
renderiza uma página Inertia quebra com "Vite manifest not found" até se criar um arquivo
`public/hot` apontando pro dev server (mesmo sem o dev server rodar de verdade — é só o Blade
que precisa do arquivo pra não tentar ler o manifest); **(b)** a linha exata do cálculo
`em_operacao` que o REQUIREMENTS-v23/D2 cita como `CompanyController.php:179` está hoje em
**`CompanyController.php:227`** — o arquivo cresceu 48 linhas desde que a nota foi escrita, e
qualquer busca literal pela linha 179 vai apontar pro lugar errado; **(c)** existe um precedente
de tabela dedicada MELHOR que `spatie/laravel-activitylog` para o D-16 — `CompanyManagerHistory`
(Fase 108) é praticamente o desenho que a Fase 150 precisa para transição de etapa, só trocando
os nomes dos campos.

**Recomendação principal:** trate a Fase 150 como puramente aditiva e sem chamador de produção
obrigatório — even ETAPA-05 (filtro) e ETAPA-06 (recusa nomeada) podem ser provados com o
serviço `podeTransicionar()`/`transicionar()` chamado só a partir de testes e, no máximo, de um
endpoint isolado — seguindo o precedente `EmpresaOperacionalRouter` (Fase 124), que nasceu **sem
chamador de produção** de propósito. Isso separa o risco da migration (irreversível de fato, por
ter dado real) do risco de qualquer tela nova, e é consistente com o próprio ROADMAP: a Fase 150
**preserva** a tela `/companies`, a Fase 155 é que troca o critério.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Vocabulário:**
- **D-01:** Os 9 valores são constantes `public const ETAPA_*` no model `Company`, em
  `snake_case`, mais uma `public const ETAPAS` com a ordem do §10:

  | Ordem | Constante | Valor |
  |---|---|---|
  | 1 | `ETAPA_AGUARDANDO_ADMINISTRATIVO` | `aguardando_administrativo` |
  | 2 | `ETAPA_ADMINISTRATIVO_ANDAMENTO` | `administrativo_andamento` |
  | 3 | `ETAPA_AGUARDANDO_ASSINATURA` | `aguardando_assinatura` |
  | 4 | `ETAPA_ADMINISTRATIVO_CONCLUIDO` | `administrativo_concluido` |
  | 5 | `ETAPA_AGUARDANDO_DISTRIBUICAO` | `aguardando_distribuicao` |
  | 6 | `ETAPA_AGUARDANDO_ONBOARDING` | `aguardando_onboarding` |
  | 7 | `ETAPA_ONBOARDING_ANDAMENTO` | `onboarding_andamento` |
  | 8 | `ETAPA_ONBOARDING_CONCLUIDO` | `onboarding_concluido` |
  | 9 | `ETAPA_EM_OPERACAO` | `em_operacao` |

  O valor 9 é deliberadamente igual à chave `em_operacao` que o payload de `CompanyController`
  já expõe — a Fase 155 troca a fonte sem trocar o nome.
- **D-02:** Docblock de uma linha em cada uma das duas propriedades no model `Company` — `status`
  = "contrato ativo/inativo, string livre, escrita por `ComercialController`; NÃO é etapa do
  fluxo de entrada" e `etapa` = "etapa do fluxo de entrada (§10 do PDF v23.0), escrita SÓ pelo
  serviço de transição". Medido na base local: `status` tem 128 `ativo` / 52 `pendente`, e o
  `pendente` vem de `ComercialController.php:594`, não de contrato inativo.

**Backfill (ETAPA-02):**
- **D-03:** A coluna nasce `nullable`, sem default. `NULL` significa "empresa legada, resolve
  pelo fallback derivado".
- **D-04:** O backfill tem exatamente dois baldes, nesta ordem, e nenhum terceiro: (1) empresa
  que satisfaz o cálculo atual (`analistaPerformance` OU `estrategistaPerformance` não vazio) →
  etapa 9; (2) todo o resto → `etapa` fica `NULL`.
- **D-05:** O backfill NUNCA deduz etapa intermediária (2..8) a partir de estado externo (nem de
  `ContratoAssinatura`, nem de `Onboarding.status`).
- **D-06:** A colisão "distribuir já é em operação" é aceita para o legado e resolvida só para
  o fluxo novo. Empresa já distribuída com onboarding em `rascunho`/`andamento` recebe etapa 9
  e não volta para 6/7/8.
- **D-07:** A migration é reversível de verdade: `down()` derruba a coluna e nada mais — não
  toca em `status`, `service_type` nem em nenhuma outra coluna.
- **D-08:** Antes e depois do backfill, contagem por balde gravada na verificação (`total`, `com
  etapa 9`, `com etapa NULL`) e conferida por reconsulta ao banco, nunca por stdout. A base local
  não serve de medida: tem 180 empresas contra ~500 em produção.

**Etapa por empresa × onboarding por serviço:**
- **D-09:** A etapa é uma só, na `companies`. Regra travada: manda o onboarding mais atrasado.
  A empresa só chega em `onboarding_concluido` quando todos os onboardings considerados
  concluírem.
- **D-10:** A Fase 150 declara essa regra e desenha o serviço para comportá-la, mas não a
  implementa — quem liga onboarding à etapa é a Fase 155.

**Serviço único de transição (ETAPA-03, ETAPA-06):**
- **D-11:** O serviço não deriva etapa de estado externo — transiciona por chamada explícita.
- **D-12:** Duas superfícies, espelhando `GatilhoContratoAdministrativoService`: `podeTransicionar
  (Company, $destino): array` — puro, zero efeito colateral, devolve `permitido` + o requisito
  faltante nomeado; `transicionar(Company, $destino, User $por, ?string $motivo)` — grava, e é o
  único lugar do sistema que escreve `companies.etapa`.
- **D-13:** O serviço recebe o `User` que agiu e o registra. A Fase 150 não constrói a matriz de
  permissão por papel.
- **D-14:** A ordem do §10 é o domínio, não uma corrente `+1` rígida. O serviço guarda uma tabela
  explícita de transições permitidas. Um salto já é conhecido: empresa `isento` (nenhum serviço
  ativo exige contrato) vai de 2 → 4 sem passar por `aguardando_assinatura`.
- **D-15:** Retrocesso é permitido, mas só pelo mesmo serviço, com motivo obrigatório e
  registrado como retrocesso. Nunca automático.
- **D-16:** Toda transição emite evento/log com ator, origem, destino e instante. A 137 emite e
  registra; timeline e SLA são da 143. `spatie/laravel-activitylog` já está aplicado a `Company`
  e é a base natural, mas escolher entre ele e tabela dedicada é decisão de planejamento — o que
  está travado é que a 137 não pode deixar de registrar.

**Pendência paralela (ETAPA-04):**
- **D-17:** Pendência é campo declarado à mão, não derivação. As duas listas de pendência
  derivadas que já existem (`PendenciasComerciaisService::calcularUniversais()` e o array de
  `CompanyController.php:257`) não são o conceito do PDF.
- **D-18:** Forma travada: uma pendência aberta por vez — booleano + `motivo` + autor + timestamp.
  Onde ela mora fisicamente (colunas na `companies` × tabela própria) é decisão de planejamento.
- **D-19:** Um único ponto de decisão de leitura, um accessor/helper no `Company`, cópia do
  padrão `PolosController::desconsideraDaMeta()`.

**Filtro por etapa e pendência (ETAPA-05):**
- **D-20:** A listagem que ganha o filtro nesta fase é `/companies`
  (`CompanyController::index` + `resources/js/Pages/Companies/Index.jsx`).
- **D-21:** Filtro server-side por query param, como os filtros que já existem ali
  (`cust_id_status`, `sort`), e não filtro de cliente.
- **D-22:** Armadilha a evitar: depois do backfill a maioria das linhas legadas fica com `etapa`
  `NULL`, então o filtro precisa oferecer "Sem etapa (legado)" como opção de primeira classe, e
  a visão padrão continua sem filtro nenhum.
- **D-23:** Etapa e pendência são dois filtros independentes, combináveis.

### Claude's Discretion

**Livre para o planner decidir:** onde a pendência mora fisicamente (colunas × tabela); nome da
classe do serviço de transição e do método de registro; se o histórico usa `activitylog` ou
tabela dedicada; forma exata do componente de filtro na tela.

**Travado — mudar exige voltar ao usuário:** os 9 valores e a ordem (D-01); backfill em dois
baldes sem etapa intermediária (D-04, D-05); ponto único de escrita (D-12); pendência declarada
e não derivada (D-17); a regra "manda o onboarding mais atrasado" (D-09).

### Deferred Ideas (OUT OF SCOPE)

- Múltiplas pendências simultâneas por empresa (D-18 trava uma pendência por vez).
- Matriz de permissão por papel nas transições (Comercial × Administrativo × Coordenação) —
  Fases 151/139/141.
- Reprocessar o legado para etapas intermediárias — a D-05 proíbe deduzir 2..8 no backfill.
- Painel de SLA agregado — HIST-03 (Fase 156) entrega o dado por empresa; o painel é produto
  separado.
- Fechar a v22.0 (Fase 133 / plano `133-05`) — não é trabalho desta milestone.
- `260818-portas-extras-criam-mlbempresa-fora-do-router.md` — deixado fora por decisão do
  usuário, sem vínculo de fase.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| ETAPA-01 | `companies.etapa` existe com os 9 valores do §10 na ordem definida, aditiva a `companies.status`, com constante de domínio no model `Company` | Migration confirmada como aditiva pura (padrão `2026_05_25_100001_add_status_to_companies` identificado como precedente RUIM, ver "Anti-Patterns"); `Company` hoje **não tem nenhuma constante** — confirmado por leitura direta (`grep public const` não retorna nada em `Company.php`) |
| ETAPA-02 | Empresas já existentes recebem etapa no backfill — as em operação entram na etapa 9, preservando o comportamento atual das telas que ainda leem o derivado | Consulta real rodada nesta pesquisa: balde 1 local = **1 empresa** (das 180); balde 2 (NULL) = **179**. Query exata documentada em "Contagem real" |
| ETAPA-03 | Um serviço único decide se uma transição de etapa é válida; nenhum controller muda etapa na mão | `CompanyController::update()` lido linha a linha (829-869): `$company->update($data)` usa só chaves de `$request->validate([...])` — `etapa` não está lá hoje, mas nada impede um dev futuro de adicionar `'etapa' => '...'` ali e furar o ponto único **em silêncio**. Ver "Common Pitfalls" |
| ETAPA-04 | Pendência é campo paralelo à etapa — nunca muda a etapa | Precedente `problema_desconsidera_meta` lido em `PolosController::desconsideraDaMeta()` (linha 1865-1869) — assinatura real documentada em "Code Examples" |
| ETAPA-05 | Filtrar listagens por etapa e por existência de pendência | Mecanismo real de `?cust_id_status=`/`?sort=` em `CompanyController::index()` (linhas 89-100, 150-157) e em `Companies/Index.jsx` (`router.get` com `preserveState`) documentado como o padrão a replicar |
| ETAPA-06 | Tentativa de transição inválida é recusada com mensagem que nomeia o requisito faltante | Molde `GatilhoContratoAdministrativoService::avaliar()`/`dispararSeElegivel()` lido por inteiro — devolve `['status' => ..., 'pendencias' => [...], 'motivo' => ...]`, o mesmo shape que `podeTransicionar()` precisa |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Armazenar a etapa atual (`companies.etapa`) | Database / Storage | — | Coluna nova na tabela `companies`; nenhuma outra tabela é fonte de verdade |
| Decidir se uma transição é válida (`podeTransicionar`) | API / Backend | — | Regra de negócio pura, sem I/O — vive em `app/Services/`, nunca no controller nem no frontend |
| Gravar a transição (`transicionar`) | API / Backend | Database / Storage | O serviço escreve; o banco persiste. Nenhuma escrita fora deste par |
| Registrar histórico da transição (D-16) | Database / Storage | API / Backend | Nasce como efeito colateral de `transicionar()`, mas a leitura (Fase 156) é backend puro sobre a tabela/activity_log |
| Pendência paralela (booleano + motivo + autor + timestamp) | Database / Storage | API / Backend | Mesma tabela `companies` OU tabela própria (decisão do planner) — sempre lida por um único accessor no model, nunca direto pelo controller (D-19) |
| Filtro por etapa/pendência na listagem | API / Backend | Frontend Server (SSR via Inertia) | Server-side por query param (D-21) — o controller filtra, o Inertia devolve `filters` como prop; o React só reflete o estado da URL, não filtra em memória |
| Botão/UI de transição | Browser / Client | — | Fora do escopo declarado desta fase (ADMIN-05/DISTRIB-03/ONBRD-01 são das Fases 152/141/142); a 137 só garante que o serviço existe e está pronto para ser chamado |

## Standard Stack

Esta fase **não introduz nenhuma dependência nova**. Tudo que ela precisa já está no
`composer.json`/`package.json` do projeto:

### Core (já instalado, reusado)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 (instalado) | Migrations, Eloquent, Query Builder | Já é o ORM/framework do projeto |
| `spatie/laravel-activitylog` | ^4.9 (instalado) | Candidato a D-16 (histórico de transição) | Já aplicado a `Company` (`getActivitylogOptions()`, linha 20-32); precedente de uso custom em `app/Services/Portal/PortalAuditoria.php` e `app/Services/RevisaoService.php::log()` |

### Alternativas consideradas
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `spatie/laravel-activitylog` para D-16 | Tabela dedicada `company_etapa_transicoes` (molde: `company_manager_history`, Fase 108) | Ver seção dedicada "D-16 — Trade-off histórico" abaixo. Nenhuma das duas opções exige pacote novo |

**Instalação:** nenhuma. `composer.json`/`package.json` não mudam nesta fase.

**Verificação de versão:** não aplicável — sem pacote novo.

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote Composer ou npm novo — reusa
`spatie/laravel-activitylog` (já instalado) e, se o planner optar por tabela dedicada para o
histórico (D-16), é só migration + model Eloquent, sem dependência externa nova. O protocolo de
`slopcheck`/verificação de registro não se aplica.

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│ Quem chama a transição (FORA do escopo da 137 — webhook Clicksign,  │
│ motor de onboarding, tela da Coordenação — nascem nas Fases 151-142) │
└───────────────────────────────┬───────────────────────────────────────┘
                                 │ transicionar(Company, $destino, $user, $motivo)
                                 ▼
                    ┌────────────────────────────┐
                    │  Serviço único de transição │   (nome livre — D-12)
                    │  app/Services/.../*.php     │
                    │                              │
                    │  podeTransicionar() — PURO  │──┐
                    │  ↳ consulta tabela de        │  │ usado também pela UI
                    │    transições permitidas     │  │ para desabilitar botão
                    │    (D-14)                    │  │ (Fases futuras)
                    │                              │  │
                    │  transicionar() — GRAVA      │  │
                    │  ↳ chama podeTransicionar()  │  │
                    │  ↳ se inválido: recusa NOMEADA│ │
                    │    (ETAPA-06)                 │  │
                    │  ↳ se válido:                │  │
                    │     1. Company::etapa = X    │  │
                    │     2. registra histórico     │  │
                    │        (D-16)                │  │
                    └───────────────┬──────────────┘  │
                                    │                  │
                    ┌───────────────▼──────────────┐   │
                    │  companies.etapa (coluna nova)│  │
                    │  nullable, sem default (D-03) │  │
                    └───────────────┬──────────────┘  │
                                    │                  │
        ┌───────────────────────────┼──────────────────┘
        │                           │
        ▼                           ▼
┌───────────────────┐   ┌──────────────────────────────┐
│ Histórico (D-16)   │   │ CompanyController::index()    │
│ activitylog custom │   │ + Companies/Index.jsx          │
│ OU tabela dedicada │   │                                │
│ (consumido só pela │   │ Filtro ?etapa=&com_pendencia=  │
│ Fase 156)           │   │ (ETAPA-05, server-side, D-21) │
└───────────────────┘   │ em_operacao continua sendo o   │
                          │ critério da aba "Empresas"     │
                          │ (D2 — só a 142 troca isso)     │
                          └──────────────────────────────┘

Pendência paralela (ETAPA-04) — caminho SEPARADO, nunca cruza o diagrama acima:
  ação humana → grava campo(s) de pendência (D-18) → único accessor de leitura no
  Company (D-19, molde PolosController::desconsideraDaMeta()) → nunca chama transicionar()
```

### Recommended Project Structure

Convenção já em uso no projeto: subpastas de `app/Services/` por domínio (`Contratos/`,
`Operacional/`, `Comercial/`, `Onboarding/`, `Portal/`). Sugestão consistente (o nome exato é
discricionário — CONTEXT.md):

```
app/
├── Models/
│   └── Company.php                  # +const ETAPA_*, ETAPAS; +docblock D-02; +accessor de pendência (D-19)
├── Services/
│   └── FluxoEntrada/                 # (nome sugerido — segue o padrão de subpasta por domínio)
│       └── EtapaTransicaoService.php # podeTransicionar() / transicionar() — D-12
├── Http/Controllers/
│   └── CompanyController.php         # +filtro etapa/com_pendencia em index() (ETAPA-05)
database/
├── migrations/
│   └── 2026_09_01_1XXXXX_add_etapa_to_companies_table.php   # aditiva, nullable, sem default
│   └── 2026_09_01_1YYYYY_create_company_etapa_transicoes_table.php  # SE tabela dedicada (D-16)
tests/
└── Feature/Phase150/
    ├── EtapaBackfillTest.php
    ├── EtapaTransicaoServiceTest.php
    ├── EtapaPendenciaParaleloTest.php
    └── EtapaFiltroListagemTest.php
```

### Pattern 1: Par puro/efeito (D-12)

**What:** um método público sem nenhum efeito colateral que só avalia, e um segundo método que
chama o primeiro e, só se aprovado, grava.
**When to use:** toda vez que a mesma regra de decisão precisa servir tanto uma recusa
programática (ETAPA-06) quanto uma checagem de UI (desabilitar botão) sem duplicar a régua.
**Example (molde real do projeto, `GatilhoContratoAdministrativoService.php`):**
```php
// Source: app/Services/Contratos/GatilhoContratoAdministrativoService.php:62-105, 131-185

public function avaliar(Company $company): array
{
    // ...regras em sequência, cada uma podendo retornar cedo...
    return ['status' => 'elegivel', 'pendencias' => []];
}

public function dispararSeElegivel(Company $company): array
{
    // guard de reentrância estático por company_id (linha 46, 160-164)
    if (self::$emAvaliacao[$company->id] ?? false) {
        return ['status' => 'reentrancia_ignorada'];
    }
    self::$emAvaliacao[$company->id] = true;
    try {
        $avaliacao = $this->avaliar($company);
        if ($avaliacao['status'] !== 'elegivel') {
            return $avaliacao;
        }
        // ...efeito real...
    } finally {
        unset(self::$emAvaliacao[$company->id]);
    }
}
```
Para a Fase 150, `podeTransicionar()` faz o papel de `avaliar()` e `transicionar()` faz o papel
de `dispararSeElegivel()` — mas **sem** precisar do guard de reentrância estático: a transição
de etapa é sempre por chamada explícita de humano/job (D-11), não por Observer reagindo a
`Company::updated()`, então não existe o laço Observer→escrita→Observer que motivou o guard no
molde original.

### Pattern 2: Ponto único de decisão de leitura de flag paralelo (D-19)

**What:** um accessor/helper — nunca um `where()` espalhado — decide o efeito de um campo
booleano paralelo.
**When to use:** exatamente o caso da pendência (ETAPA-04): a pendência não pode influenciar
nada além do que este único ponto decidir, ou o bug do Painel Polos (learning §1) se repete.
**Example (precedente real, `PolosController.php:1865-1869`):**
```php
// Source: app/Http/Controllers/PolosController.php:1865-1869
private function desconsideraDaMeta(array $ativo): bool
{
    return (bool) ($ativo['problema'] ?? false)
        && (bool) ($ativo['problema_desconsidera_meta'] ?? false);
}
```
Nota: este precedente opera sobre um **array** (dado vindo do CSV), não sobre um model Eloquent
— para `Company` o equivalente correto é um **accessor no model** (`Company::pendenciaAberta():
bool` ou similar), porque D-19 explicitamente pede "accessor/helper no `Company`", não um método
de controller.

### Pattern 3: Serviço extraído sem chamador de produção (precedente `EmpresaOperacionalRouter`, Fase 124)

**What:** escrever o serviço novo inteiro, testado, sem plugá-lo em nenhuma rota/controller de
produção ainda.
**When to use:** quando o risco de escrever a lógica nova deve ser separado do risco de trocar
o caminho que produção já usa. Fase 124 fez isso deliberadamente:

> "Este service NÃO tem chamador ainda — os dois controllers continuam com o código inline de
> hoje. Religar os dois caminhos para consumir este router é escopo do plano 124-05, de
> propósito: separa o risco de escrever o service novo do risco de trocar o caminho de
> produção." — `app/Services/Operacional/EmpresaOperacionalRouter.php:36-39`

**Aplicação à Fase 150:** o `EtapaTransicaoService` pode (e talvez deva) nascer sem nenhum
controller/job de produção chamando `transicionar()` ainda — só testes. Quem liga o serviço a um
gatilho real de produção é a Fase 151 (webhook → etapa 1), 139 (FINALIZAR → etapa 5), 141
(distribuição → etapa 6) e 142 (onboarding → 7/8/9). Isso é consistente com o próprio Success
Criteria 2 da Fase 150 ("não existe outro ponto do código que grave `companies.etapa`") — a
forma mais segura de garantir isso é a Fase 150 não plugar nenhum chamador de verdade, e sim
provar via teste que o serviço funciona isoladamente.

### Anti-Patterns to Avoid

- **Migration que mistura criação de coluna com rename/alteração de outra coluna no mesmo
  `up()`/`down()`.** Precedente ruim real no repositório —
  `database/migrations/2026_05_25_100001_add_status_to_companies.php` faz isso:
  ```php
  // Source: database/migrations/2026_05_25_100001_add_status_to_companies.php:17-47
  public function up(): void {
      // ...cria a coluna status...
      DB::table('companies')->whereNull('status')->update(['status' => 'ativo']); // backfill OK
      DB::table('companies')->where('service_type', 'polo')->update(['service_type' => 'polos']); // ← RENAME, fora do escopo da migration
  }
  public function down(): void {
      DB::table('companies')->where('service_type', 'polos')->update(['service_type' => 'polo']); // reverte o rename
      Schema::table('companies', fn ($table) => $table->dropColumn('status'));
  }
  ```
  **Por que não repetir (D-07):** um `down()` que reverte DUAS mudanças de propósitos diferentes
  não é "reversível de verdade" para nenhuma delas isoladamente — se algo der errado só na parte
  do `etapa`, não existe como desfazer só isso sem também desfazer o resto. A migration da Fase
  137 deve tocar **só** `companies.etapa`, criação e nada mais; qualquer rename futuro de
  `status` (se um dia acontecer) é migration própria.
- **Ler a coluna de pendência direto num controller/query, em vez de passar pelo accessor único
  (D-19).** É exatamente o bug histórico do Painel Polos (`.planning/learnings/
  painel-polos-status-e-meta.md` §1): "Se aparecer um cálculo novo de status, use o helper — ler
  `$ativo['problema']` direto reintroduz o bug."
- **Adicionar `'etapa' => 'nullable|string'` ao `$request->validate()` de
  `CompanyController::update()`.** Ver "Common Pitfalls" — hoje isso NÃO acontece (o `$data`
  passado a `$company->update($data)` não inclui `etapa`), mas nada no código impede que aconteça
  depois, e a essa altura o ponto único de escrita (ETAPA-03) já estaria furado sem nenhum erro
  visível.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Registrar ator+timestamp de uma mudança de estado | Uma tabela de log genérica do zero | `spatie/laravel-activitylog` (`activity($canal)->performedOn()->causedBy()->withProperties()->log()`) — já instalado, com dois precedentes de uso "custom" no projeto (`PortalAuditoria`, `RevisaoService::log()`) | O pacote já resolve serialização de `properties`, paginação por `subject`, e retenção (`delete_records_older_than_days`, hoje 365) — reescrever perde tudo isso |
| Histórico append-only por-linha de uma entidade | Uma tabela ad hoc sem padrão | Molde `company_manager_history` (Fase 108) — `UPDATED_AT = null`, FK com `cascadeOnDelete`/`nullOnDelete`, índice composto `(company_id, created_at)` | É o precedente MAIS PRÓXIMO do que a Fase 150 precisa (ver seção D-16 abaixo) — replicar a forma evita reinventar decisões já tomadas (nullable `changed_by`, imutabilidade) |
| Verificar se o backfill preservou a tela | Comparar prints/contagem de tela antes/depois | Reconsulta SQL direta (`SELECT COUNT(*) FROM companies WHERE ...`) — disciplina do `.planning/learnings/desempenho-bonificacao.md` §4 | Já custou caro no projeto confiar em stdout/tela: consolidações "bem-sucedidas" na tela sem terem gravado o esperado (ver §10.1 do mesmo learning) |

**Key insight:** nada nesta fase precisa de biblioteca nova porque o projeto já tem os DOIS
precedentes que ela precisa (log rico via `activitylog` custom, tabela dedicada via
`company_manager_history`) — a decisão real não é "o que usar", é "qual dos dois precedentes
seguir", e isso está detalhado abaixo.

## D-16 — Trade-off histórico (decisão de planejamento, não veredito)

O CONTEXT pede trade-off documentado, não escolha. Aqui estão as duas opções com evidência real
do código, para o planner decidir.

### Opção A — `spatie/laravel-activitylog` custom (log rico, não o trait automático)

`Company` já usa o trait `LogsActivity`, mas o `getActivitylogOptions()` atual
(`logOnly(['name', 'cnpj', 'segment', 'active', 'status', 'notes', ...])`, linha 23) só grava
**diffs automáticos de atributos dirty** — não inclui `etapa` hoje, e mesmo incluindo, o log
automático não tem como carregar "motivo" nem distinguir "avanço normal" de "retrocesso" (D-15)
sem gambiarra em `withProperties`.

A forma que D-16 pede (ator + origem + destino + instante + possivelmente motivo/retrocesso) já
tem DOIS precedentes de uso **manual/custom** do mesmo pacote, com um `log_name`/canal próprio
(não o trait automático):

```php
// Source: app/Services/Portal/PortalAuditoria.php:31-37 (um dos 7 métodos análogos no arquivo)
activity(self::CANAL)
    ->performedOn($usuario)
    ->causedBy($usuario)
    ->withProperties(['evento' => 'codigo_enviado', 'ip' => $ip])
    ->log("Código de acesso enviado para {$usuario->email}");
```

```php
// Source: app/Services/RevisaoService.php:313-320
private function log(User $user, Publicacao $pub, string $acao, array $props = []): void
{
    activity('mlb')
        ->causedBy($user)
        ->withProperties(array_merge(['mlb_code' => $pub->mlb_code, ...], $props))
        ->log($acao);
}
```

Para a Fase 150, o equivalente seria algo como
`activity('etapa')->performedOn($company)->causedBy($user)->withProperties(['de' => $etapaAnterior, 'para' => $etapaNova, 'motivo' => $motivo, 'retrocesso' => $retrocesso])->log(...)`.

**Prós:** reusa infraestrutura e tabela existentes (`activity_log`, hoje 3.403 linhas totais /
100 de `Company` na base local — volume pequeno); zero migration nova; retenção já configurada
(365 dias); dois precedentes de uso já revisados/testados no projeto.

**Contras:** "quanto tempo em cada etapa" (HIST-03, Fase 156) exige olhar `properties->de`/
`properties->para` de linhas consecutivas e calcular a diferença entre `created_at`s em código —
não há coluna "entrou_em"/"saiu_em" pronta. `properties` é uma coluna JSON — consultas SQL
diretas (`WHERE properties->>'$.para' = ...`) funcionam em MariaDB mas são mais frágeis a erro
de digitação de chave do que uma coluna tipada.

### Opção B — Tabela dedicada, molde `company_manager_history` (Fase 108)

```php
// Source: app/Models/CompanyManagerHistory.php (integral) — o modelo mais próximo do que D-16 pede
class CompanyManagerHistory extends Model
{
    protected $table = 'company_manager_history';
    public const UPDATED_AT = null;   // log append-only
    protected $fillable = ['company_id', 'user_id', 'papel', 'evento', 'changed_by'];
}
```
```php
// Source: database/migrations/2026_07_23_100000_create_company_manager_history_table.php:20-31
Schema::create('company_manager_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('papel', 20);
    $table->string('evento', 10);
    $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('created_at')->nullable();
    $table->index(['company_id', 'created_at']);
});
```
Uma `company_etapa_transicoes` análoga trocaria `papel`/`evento` por `etapa_anterior` (nullable,
para a primeira transição a partir de `NULL`)/`etapa_nova`, `user_id` seria o ator (D-13),
`changed_by` não se aplica (não há "quem mudou quem mudou"), e ganharia `motivo` (nullable) +
`retrocesso` (boolean, D-15).

**Prós:** colunas tipadas — "quanto tempo em cada etapa" (HIST-03) vira uma auto-junção simples
por `company_id` ordenada por `created_at`, sem parsear JSON; índice `(company_id, created_at)`
já é exatamente o que a consulta de SLA precisa; motivo/retrocesso são colunas de primeira
classe, não `properties` genérico.

**Contras:** mais uma tabela pra manter; sem a infraestrutura de retenção/purge que o
`activitylog` já tem configurada; precisa de migration nova (mas é aditiva e sem risco — tabela
nova, não coluna em tabela com dado).

### Recomendação desta pesquisa (não vinculante — decisão é do planner/CONTEXT)

`company_manager_history` é estruturalmente mais parecido com a necessidade de D-16 do que
`activitylog` — é histórico de UMA entidade específica (`Company`), com um ator e um evento
discreto, exatamente como transição de etapa. `activitylog` foi desenhado pra diffs de qualquer
model; aqui não há diff de atributo arbitrário, há uma máquina de estados com vocabulário
fechado (9 valores + retrocesso). Se HIST-03 (Fase 156, "quanto tempo em cada etapa, sem abrir o
banco") é a motivação central de D-16, colunas tipadas evitam que a Fase 156 tenha que reescrever
lógica de parsing de JSON que uma tabela dedicada dispensaria de origem.

## Common Pitfalls

### Pitfall 1: `CompanyController::update()` pode furar o ponto único em silêncio

**What goes wrong:** ETAPA-03 exige que só o serviço de transição escreva `companies.etapa`.
Hoje `CompanyController::update()` (linhas 829-869) faz `$company->update($data)` onde `$data`
vem só de `$request->validate([...])` — `etapa` não está na lista de chaves validadas, então
hoje está seguro. Mas nada no código impede um dev futuro de adicionar
`'etapa' => 'nullable|string'` à validação, e a partir daí toda edição manual de empresa passaria
a poder mudar a etapa sem passar pelo serviço, sem erro, sem teste quebrando (a menos que exista
um teste específico pra isso).
**Why it happens:** `$fillable` e `$request->validate()` são dois pontos de decisão
independentes; adicionar `etapa` ao `$fillable` (necessário pra qualquer escrita, inclusive pelo
serviço) não impede que APAREÇA também na validação do controller.
**How to avoid:** um teste de regressão explícito — `PUT /companies/{company}` com `etapa` no
payload não pode mudar `companies.etapa`, hoje e para sempre. Ver "Validation Architecture".
**Warning signs:** qualquer PR que adicione uma chave nova ao array de `$request->validate()` de
`CompanyController::update()` sem também tocar em `EtapaTransicaoServiceTest.php`.

### Pitfall 2: linha citada no REQUIREMENTS/ROADMAP para `em_operacao` está desatualizada

**What goes wrong:** REQUIREMENTS-v23.md (D2) e o seed técnico citam
`CompanyController.php:179`. Nesta pesquisa, a linha real é **227**:
```php
// Source: app/Http/Controllers/CompanyController.php:227
'em_operacao' => ! ($c->analistaPerformance->isEmpty() && $c->estrategistaPerformance->isEmpty()),
```
**Why it happens:** o arquivo cresceu desde que a nota foi escrita (comentários extensos entre a
linha 179 e a 227 — ver bloco de comentário "fast-260806" nas linhas 213-226).
**How to avoid:** buscar pelo texto (`em_operacao`), nunca pela linha, ao ler este arquivo.
**Warning signs:** nenhum — é só desatualização de referência, sem efeito funcional.

### Pitfall 3: query base de `/companies` filtra MUITO mais do que `em_operacao`

**What goes wrong:** o Success Criteria 1 ("a tela não perde nenhuma empresa que mostra hoje")
é sobre o conjunto que **já chega** em `CompanyController::index()`, não sobre todas as 500
empresas de produção. A query base (linhas 106-158) já exclui quem tem `MlbEmpresa`
(`whereDoesntHave('mlbEmpresa')`, linha 139) **e** exige contrato ativo em `Servico::
SETOR_PERFORMANCE` (linhas 144-149) — `em_operacao` (linha 227) é só o filtro CLIENTE aplicado
DEPOIS, dentro de `Companies/Index.jsx:242` (`companies.filter(c => c.em_operacao)`).
**Why it happens:** são três filtros empilhados (dois no backend, um no frontend), e é fácil
testar só o último e achar que basta.
**How to avoid:** ao medir "antes/depois" do backfill (D-08), medir NOS DOIS NÍVEIS: (1) contagem
total de `companies` por balde (o que o backfill em si precisa provar); (2) contagem do payload
que `/companies` devolve hoje vs depois — que já é filtrado pelos dois `where`s de backend. O
Success Criteria 1 fala da tela, que é o nível (2).
**Warning signs:** comparar "quantas empresas tem `etapa=9`" com "quantas empresas a tela mostra"
sem passar pelos mesmos dois filtros de backend vai sempre divergir — não é bug do backfill.

### Pitfall 4: Feature tests que renderizam página Inertia quebram sem `public/build/manifest.json`

**What goes wrong:** medido nesta pesquisa — rodar `tests/Feature/Phase37CompaniesPerformanceFilterTest.php`
e `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` neste worktree, do jeito
que ele está agora, dá **20 de 24 falhas**, todas com a mesma causa raiz:
```
Vite manifest not found at: C:\xampp\htdocs\ecf_fluxo_entrada\public\build/manifest.json
```
Este worktree **não tem `node_modules/`** (confirmado: `node_modules` não existe na raiz) nem
`public/build/` (confirmado: diretório inexistente). Toda resposta Inertia bem-sucedida
(`response->assertOk()`/`assertStatus(200)`) renderiza `resources/views/app.blade.php`, que chama
a diretiva `@vite`, que por padrão tenta ler o manifest.
**Why it happens:** o `npm install && npm run build` deste worktree específico nunca rodou —
diferente do worktree principal (`ecf_admin`), que tem `public/build/` populado.
**How to avoid (duas opções, nenhuma delas é "rodar `npm run build` de verdade" se não houver
tempo/necessidade de testar o front real):**
1. **Real:** `npm install` (repõe `node_modules/`, ausente hoje) seguido de `npm run build`.
2. **Atalho para rodar só a suíte PHP** (o que esta pesquisa usou para confirmar a baseline):
   criar `public/hot` com o conteúdo `http://localhost:5173` (a URL do dev server, mesmo que ele
   não esteja rodando de verdade). O Blade do Laravel/`laravel-vite-plugin` detecta esse arquivo
   e emite `<script src="http://localhost:5173/...">` em vez de ler o manifest — suficiente para
   os testes PHPUnit passarem, porque eles nunca de fato buscam o JS pela rede.
   ```bash
   echo "http://localhost:5173" > public/hot
   # ...rodar a suíte...
   rm public/hot   # REMOVER depois — ver aviso abaixo
   ```
**Warning signs / aviso importante:** `.planning/learnings/painel-polos-status-e-meta.md` não
cobre isso, mas existe um learning irmão (`project_vite_hot_orfao_local.md`, citado no
`MEMORY.md`) sobre exatamente o efeito colateral inverso: um `public/hot` **esquecido** faz o
`php artisan serve` local tentar servir assets de um dev server que não está rodando, e o app
"não carrega o JSX" sem erro óbvio. **Nunca deixar `public/hot` no worktree fora de uma sessão de
teste** — remover assim que a suíte terminar, ou usar `npm run build` de verdade se o objetivo é
também navegar a tela manualmente.

### Pitfall 5: `analistaPerformance()`/`estrategistaPerformance()` filtram pelo papel `'consultor'`, não `'analista'`

**What goes wrong:** o backfill (D-04, balde 1) precisa reusar EXATAMENTE
`analistaPerformance()`/`estrategistaPerformance()` — não reescrever a query à mão. Um dev que
tentar reescrever pode razoavelmente supor que o papel de analista é `'analista'` na pivot
`company_users`; não é.
```php
// Source: app/Models/Company.php:305-326 (analistaPerformance) — repara em wherePivot('role', 'consultor')
public function analistaPerformance()
{
    return $this->belongsToMany(User::class, 'company_users')
        ->withPivot('assigned_at')
        ->wherePivot('role', 'consultor')   // ← "analista" de negócio = role 'consultor' na pivot
        ->where(function ($q) { /* servico_id em setor performance OU (NULL + contrato performance ativo) */ })
        ->distinct('users.id');
}
```
**Why it happens:** taxonomia dupla documentada em `.planning/learnings/
desempenho-bonificacao.md` §7 — "o cargo vive em `user_setores → cargos.slug = 'analista'`; o
papel na pivot `company_users` **nunca** é `analista` (só `consultor` ou `estrategista`)".
**How to avoid:** backfill chama `$company->analistaPerformance()->exists() ||
$company->estrategistaPerformance()->exists()` (ou o equivalente em massa via `whereHas`),
nunca `where('company_users.role', 'analista')`.
**Warning signs:** uma query de backfill que filtra `role = 'analista'` sempre devolve zero
linhas — silenciosamente, sem erro.

### Pitfall 6: `#[ObservedBy(CompanyGatilhoContratoObserver::class)]` reage a QUALQUER `Company::updated()`

**What goes wrong:** `Company` tem um Observer de classe (`app/Models/Company.php:15`) que roda
em todo `updated()`. Se `transicionar()` gravar `etapa` via `$company->save()` (ou
`$company->update([...])`), esse evento dispara o Observer.
**Why it happens:** o Observer só reage quando `$company->wasChanged(CAMPOS_GATILHO)` é
verdadeiro, e `CAMPOS_GATILHO = ['email_cliente', 'cnpj', 'nome_contato']`
(`CompanyGatilhoContratoObserver.php:49`) — `etapa` **não** está nessa lista, então hoje é seguro:
gravar só `etapa` não reavalia o gate administrativo. Mas se `transicionar()` algum dia também
tocar em campos além de `etapa` no mesmo `save()` (por exemplo, ao popular dados do onboarding
junto com a mudança de etapa 6→7), e algum desses campos coincidir com `CAMPOS_GATILHO`, o gate
dispara de carona.
**How to avoid:** `transicionar()` deve gravar **só** `etapa` (e os campos do próprio histórico
de transição, que vivem em outra tabela/log) no `save()`/`update()` que toca `Company` —
qualquer outro campo relacionado à transição (ex.: dados do onboarding) deve ser gravado em
chamada separada, fora do mesmo `save()`.
**Warning signs:** teste de transição que também aciona `GatilhoContratoAdministrativoService`
por engano (log `[GatilhoContrato]` aparecendo onde não devia).

## Code Examples

### `podeTransicionar()` — shape sugerido, a partir do molde real

```php
// Baseado em app/Services/Contratos/GatilhoContratoAdministrativoService.php:56-105
// (mesmo shape de retorno — status + lista + motivo nomeado)

/**
 * @return array{permitido: bool, requisito_faltante?: string, motivo?: string}
 */
public function podeTransicionar(Company $company, string $etapaDestino): array
{
    $etapaAtual = $company->etapa; // pode ser NULL (legado, D-03)

    if (! $this->transicaoPermitida($etapaAtual, $etapaDestino)) {
        return [
            'permitido' => false,
            'requisito_faltante' => "transição de '{$etapaAtual}' para '{$etapaDestino}' não é permitida",
        ];
    }

    // ...checagens específicas por destino (ex.: destino=4 exige contrato assinado)...

    return ['permitido' => true];
}
```

### Filtro server-side por query param — shape a partir do molde real de `/companies`

```php
// Baseado em app/Http/Controllers/CompanyController.php:86-100 (cust_id_status) e 150-157 (aplicação)
$etapaFilter = $request->input('etapa'); // aceita um dos 9 valores OU o sentinela 'sem_etapa' (D-22)
if (! in_array($etapaFilter, [...Company::ETAPAS, 'sem_etapa'], true)) {
    $etapaFilter = null;
}

$comPendenciaFilter = $request->boolean('com_pendencia'); // ETAPA-05, independente do de etapa (D-23)

// ...
->when($etapaFilter === 'sem_etapa', fn($q) => $q->whereNull('etapa'))
->when($etapaFilter && $etapaFilter !== 'sem_etapa', fn($q) => $q->where('etapa', $etapaFilter))
->when($comPendenciaFilter, fn($q) => $q->where('pendencia_aberta', true)) // ou join, se tabela própria (D-18)
```

### Query do backfill — balde 1, reusando as relações corretas (D-04)

```php
// Fora de escopo desta pesquisa escrever o comando/migration completos — só o
// núcleo da query, que DEVE reusar analistaPerformance()/estrategistaPerformance()
// (Company.php:305-353), nunca reescrever o filtro por role/servico_id à mão.
$idsBalde1 = Company::query()
    ->where(function ($q) {
        $q->whereHas('analistaPerformance')
          ->orWhereHas('estrategistaPerformance');
    })
    ->pluck('id');

DB::table('companies')->whereIn('id', $idsBalde1)->update(['etapa' => Company::ETAPA_EM_OPERACAO]);
// Todo o resto fica NULL — não precisa de update (nasce NULL, D-03).
```

## Contagem real (medida nesta pesquisa, base LOCAL — não é medida de produção, ver D-08)

Rodado via `php artisan tinker` contra o banco local (MariaDB 10.4.32, `ecf_admin`, 180
empresas):

| Consulta | Resultado local |
|---|---:|
| `Company::count()` (total) | 180 |
| Balde 1 (`analistaPerformance` OU `estrategistaPerformance` não vazio — critério exato de D-04) | **1** |
| Balde 2 (resto, ficaria `etapa = NULL`) | **179** |
| Com contrato Performance ATIVO (query base de `/companies`, sem o `whereDoesntHave('mlbEmpresa')`) | 9 |
| Sem `MlbEmpresa` + contrato Performance ativo (query base REAL de `/companies`, linhas 139+144-149) | 9 |
| `status = 'ativo'` | 128 |
| `status = 'pendente'` | 52 |
| `companies.etapa` já existe? | Não (confirmado via `Schema::hasColumn`) |

**Leitura:** localmente, das 9 empresas que a tela `/companies` mostra hoje, só **1** ficaria com
`etapa = 9` no backfill — as outras 8 mostram na tela porque têm contrato Performance ativo, mas
NENHUM analista/estrategista designado (por isso `em_operacao = false` pra elas hoje, e é
justamente esse subconjunto que o Success Criteria 1 exige que a tela continue mostrando via
fallback, já que ficam com `etapa = NULL`). **Isto é exatamente o comportamento que D-04/D2
esperam** — não é uma surpresa, é a confirmação de que o balde 2 (NULL) domina, inclusive dentro
do próprio recorte que a tela já filtra hoje.

O CONTEXT já avisa: 180 local × ~500 produção é uma amostra pequena demais para extrapolar
proporção — a única coisa que esta contagem prova é que a **query** do backfill (reusar as
relações do model) roda e produz os dois baldes esperados, não o valor absoluto de produção.

## Runtime State Inventory

> Esta fase não é rename/rebrand — é migration ADITIVA (coluna nova, sem tocar nome nem
> semântica de nada que já existe). Os 5 itens abaixo são adaptados ao espírito da disciplina
> (o que sobrevive à mudança sem ser tocado por ela), não uma auditoria de string renomeada.

| Categoria | O que foi encontrado | Ação necessária |
|----------|-------------|------------------|
| Dado armazenado | `companies.status` (128 `ativo`/52 `pendente`) e o cálculo `em_operacao` — **nenhum dos dois muda de significado nesta fase** (D-01, D-02, D2 do REQUIREMENTS). `etapa` nasce do zero, sem migrar dado de nenhuma coluna existente. | Nenhuma migração de dado além do backfill descrito em D-04/D-05 — não hà "reinterpretação" de coluna existente |
| Configuração viva em serviço externo | Nenhuma. Nenhum serviço externo (HubSpot, Clicksign, Digisac, Adman) lê ou grava `companies.etapa` — é campo 100% interno ao Laravel | Nenhuma |
| Estado registrado no SO | Nenhum. Não há tarefa agendada (Task Scheduler/cron), processo `pm2` nem unit `systemd` referenciando `etapa`, `status` ou nomes relacionados a esta fase | Nenhuma |
| Segredos/env vars | Nenhum. Nenhuma chave de config/`.env` referencia `etapa` ou os 9 valores do §10 | Nenhuma |
| Artefato de build/pacote instalado | **Risco real, mas conhecido do projeto:** `.planning` registra em `MEMORY.md` que "Autoloader Composer aponta pra worktree" já causou "edição PHP sem efeito" antes. Se o deploy usa autoloader otimizado/classmap (`composer install --no-dev --optimize-autoloader`, padrão em produção), uma classe NOVA (o serviço de transição) só fica resolvível depois de `composer dump-autoload` rodar como parte do `composer install` do deploy — **isso já está coberto pelo processo de deploy padrão do projeto** (`composer.json` scripts + `deploy.sh`), não é um passo extra a inventar | Nenhuma ação além do deploy padrão — só ficar ciente de que uma classe nova só existe em produção depois do próximo `composer install` |

**Achado central desta seção:** não há runtime state externo em risco nesta fase — o motivo é
estrutural (D-01/D-11): a etapa é campo 100% interno, escrito só por chamada explícita de código
Laravel, nunca derivado de nem espelhado em sistema externo. O risco real desta fase está
inteiramente dentro do banco de dados do próprio projeto (a migration aditiva + backfill), coberto
pela seção "Validation Architecture" abaixo.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Nome sugerido de classe/pasta (`app/Services/FluxoEntrada/EtapaTransicaoService.php`) é só uma sugestão consistente com a convenção do projeto — CONTEXT.md deixa isso explicitamente livre para o planner | Recommended Project Structure | Nenhum — é só sugestão, não travado |
| A2 | Recomendação de tabela dedicada (Opção B) sobre `activitylog` custom (Opção A) para D-16 é uma opinião desta pesquisa, baseada em HIST-03 precisar de leitura tipada — o CONTEXT explicitamente deixa a escolha para o planejamento | D-16 — Trade-off histórico | Se o planner escolher Opção A por outro motivo válido (ex.: preferir não criar tabela nova), não há perda funcional — é trade-off documentado, não erro a corrigir |
| A3 | O deploy de produção usa autoloader otimizado (`--optimize-autoloader`), então uma classe PHP nova só é resolvível após `composer install` rodar | Runtime State Inventory | Se o autoloader de produção NÃO for otimizado, o risco descrito simplesmente não existe (autoload PSR-4 dinâmico resolve classes novas sem `dump-autoload`) — impacto é nulo em qualquer dos dois casos, é só uma nota de contexto, não uma trava de plano |

**Nota:** as claims que fundamentam este documento (linhas de código, assinaturas de método,
contagens locais, resultado da suíte de teste) foram todas `[VERIFIED]` por leitura direta do
código deste worktree ou execução real de comando nesta sessão — não há claim de biblioteca
externa não verificada, porque a fase não introduz nenhuma.

## Open Questions (RESOLVED)

> As duas perguntas foram decididas no planejamento da fase. Mantidas aqui com o raciocínio
> original porque o *motivo* da escolha continua útil — mas **não reabrir**: a decisão está
> travada nos planos citados.

1. **A migration deve indexar `companies.etapa`?** — **RESOLVED: sim, indexa. Ver `150-02-PLAN.md`.**
   - What we know: o filtro ETAPA-05 vai fazer `WHERE etapa = ?` com frequência na tela mais
     usada do admin (`/companies`). A tabela tem ~500 linhas em produção — volume pequeno.
   - What's unclear: se vale a pena um índice numa tabela desse tamanho, ou se é
     over-engineering para 500 linhas (um full scan já é rápido).
   - Recommendation: decisão do planner/executor na hora de escrever a migration; não é um risco
     que precise de decisão do usuário. Se indexar, nomear o índice explicitamente
     (`companies_etapa_index` já cabe nos 64 caracteres do MariaDB sem problema — ver learning
     `desempenho-bonificacao.md` §6 sobre nomes de índice longos, que não se aplica aqui pelo
     nome curto da tabela/coluna).

2. **`pendencia_aberta`/`pendencia_motivo`/`pendencia_por`/`pendencia_em` como colunas de
   `companies`, ou tabela própria (D-18)?** — **RESOLVED: 4 colunas em `companies`. Ver
   `150-04-PLAN.md`.** (O §10 do PDF não pede histórico de pendência, então tabela própria não
   se justificou. Histórico de **etapa** é outra coisa — D-16, resolvido como tabela dedicada
   `company_etapa_transicoes` no `150-03-PLAN.md`.)
   - What we know: cardinalidade travada em "uma pendência aberta por vez" — o formato mais
     simples é 4 colunas nullable em `companies` (booleano + motivo + FK de autor + timestamp),
     exatamente como o precedente `problema`/`problema_desconsidera_meta` em `mlb_empresas`.
   - What's unclear: se uma pendência **fechada** precisa deixar rastro (histórico de pendências
     passadas), ou se "desmarcar" simplesmente limpa os 4 campos sem deixar trilha. O PDF (§10)
     só fala de "sinalizador paralelo", sem mencionar histórico de pendências.
   - Recommendation: se não houver necessidade de histórico de pendência (diferente do
     histórico de ETAPA, que D-16 exige), colunas direto em `companies` é mais simples e
     suficiente — tabela própria só se justifica se o planner decidir que pendências passadas
     também precisam ficar rastreáveis (nesse caso, o mesmo molde `company_manager_history`
     serve).

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (testsuites `Unit` → `tests/Unit`, `Feature` → `tests/Feature`; DB de teste = SQLite `:memory:`, ver `<env name="DB_CONNECTION" value="sqlite"/>`) |
| Quick run command | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php --colors=never` (as duas suítes que exercitam `CompanyController::index()`/`em_operacao` — impacto direto do Success Criteria 1) |
| Full suite command | `C:\xampp\php\php.exe artisan test` (equivalente a `composer test`) |

**Pré-requisito de ambiente, medido nesta pesquisa (ver Pitfall 4):** qualquer teste `Feature`
que renderiza uma página Inertia (`assertOk()`/`assertStatus(200)` sobre uma rota que devolve
`Inertia::render()`) precisa de `public/build/manifest.json` OU de um `public/hot` apontando
para uma URL de dev server (mesmo que ele não esteja rodando). Neste worktree, HOJE, nenhum dos
dois existe (`node_modules/` também está ausente). **Antes de rodar a baseline desta fase**,
escolher uma das duas opções descritas no Pitfall 4 — a mais rápida é o `public/hot` temporário
(confirmado nesta pesquisa: com ele, as duas suítes acima passam **24/24 testes, 44
assertions**).

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ETAPA-01 | `Company::ETAPAS` tem os 9 valores, na ordem, e a migration cria `etapa` nullable sem default | unit | `phpunit tests/Unit/Phase150/CompanyEtapaConstantesTest.php` | ❌ Wave 0 |
| ETAPA-02 | Backfill: balde 1 (analista OU estrategista) → etapa 9; balde 2 → NULL; tela `/companies` mostra o mesmo conjunto de antes | feature | `phpunit tests/Feature/Phase150/EtapaBackfillTest.php` (molde: `Phase37CompaniesPerformanceFilterTest.php`, que já prova o payload de `/companies` linha a linha) | ❌ Wave 0 |
| ETAPA-03 | `PUT /companies/{company}` com `etapa` no payload NÃO muda `companies.etapa`; nenhum outro `Company::etapa =`/`update(['etapa' => ...])` existe fora do serviço | feature + grep estático | `phpunit tests/Feature/Phase150/EtapaPontoUnicoTest.php` + `grep -rn "'etapa'\s*=>" app/ --include=*.php` limitado ao arquivo do serviço | ❌ Wave 0 |
| ETAPA-04 | Marcar/desmarcar pendência não muda `etapa`; accessor único de leitura devolve o valor certo em todos os casos | unit + feature | `phpunit tests/Feature/Phase150/EtapaPendenciaParaleloTest.php` | ❌ Wave 0 |
| ETAPA-05 | `?etapa=X` e `?com_pendencia=1` filtram server-side, combináveis, com `sem_etapa` como opção de primeira classe (D-22) | feature | `phpunit tests/Feature/Phase150/EtapaFiltroListagemTest.php` (molde: filtro `cust_id_status` já testado em `Phase37CompaniesPerformanceFilterTest.php::test_filtro_cust_id_status_invalido_continua_funcional`) | ❌ Wave 0 |
| ETAPA-06 | `podeTransicionar()` recusa transição inválida com `requisito_faltante` nomeado (nunca mensagem genérica); tabela de transições da D-14 cobre o salto conhecido 2→4 | unit | `phpunit tests/Unit/Phase150/EtapaTransicaoServiceTest.php` | ❌ Wave 0 |

### Sampling Rate

- **Por commit de task:** rodar as suítes específicas de `tests/{Unit,Feature}/Phase150/` (rápido,
  segundos).
- **Por merge de wave:** rodar a Quick run command acima (as duas suítes de `/companies` que já
  existem) + `tests/Phase150/*` completo.
- **Gate da fase:** full suite (`php artisan test`) verde ANTES de `/gsd:verify-work` — exigência
  explícita do `CLAUDE.md` ("GSD obrigatório") para migration em tabela com dado de produção.
  **Aviso conhecido:** `tests/Feature/Phase38/PolosControllerTest.php` e
  `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` têm ~10 falhas pré-existentes,
  documentadas em `.planning/learnings/painel-polos-status-e-meta.md` §2 — não são regressão
  desta fase; não gastar tempo investigando-as se aparecerem no full suite.

### Wave 0 Gaps

- [ ] `tests/Unit/Phase150/CompanyEtapaConstantesTest.php` — cobre ETAPA-01
- [ ] `tests/Feature/Phase150/EtapaBackfillTest.php` — cobre ETAPA-02 (D-04/D-05/D-08)
- [ ] `tests/Feature/Phase150/EtapaPontoUnicoTest.php` — cobre ETAPA-03 (incluindo o caso do
  Pitfall 1: `PUT /companies/{company}` com `etapa` no payload)
- [ ] `tests/Feature/Phase150/EtapaPendenciaParaleloTest.php` — cobre ETAPA-04
- [ ] `tests/Feature/Phase150/EtapaFiltroListagemTest.php` — cobre ETAPA-05
- [ ] `tests/Unit/Phase150/EtapaTransicaoServiceTest.php` — cobre ETAPA-06
- [ ] Fixture compartilhada opcional: trait `CriaEmpresaComEtapa` (molde:
  `tests/Feature/V16/CriaCenarioResponsaveis.php`, já usado por `CompanyControllerResponsavelPerformanceTest.php`) —
  reduz duplicação de setup de `Company`+`Servico`+`ContratoServico`+vínculos entre os testes
  acima.
- [ ] Framework: nenhum a instalar — PHPUnit já configurado. Só o workaround do Pitfall 4
  (`public/hot` temporário OU `npm install && npm run build`) precisa rodar antes de qualquer
  suíte `Feature` neste worktree.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Não diretamente — a fase não cria login/sessão nova | — |
| V3 Session Management | Não | — |
| V4 Access Control | **Sim** | Middleware existente (`EnsurePermission`/`role:admin`) — ver "Superfícies de risco" abaixo. A matriz de permissão por papel de QUEM pode transicionar é deferida (D-13), mas o mínimo de "só quem já tem acesso admin/`core.empresas` hoje" precisa se manter |
| V5 Input Validation | **Sim** | `podeTransicionar($etapaDestino)` deve validar `$etapaDestino` contra `Company::ETAPAS` antes de qualquer lógica — nunca aceitar string arbitrária como destino |
| V6 Cryptography | Não | — |

### Superfícies de risco identificadas (sem construir a matriz de permissão — deferida às Fases 151/139/141)

1. **Mass assignment via `$fillable`.** ETAPA-01 exige adicionar `etapa` a `Company::$fillable`
   (necessário para o serviço gravar via Eloquent). Isso, por si só, torna `etapa` gravável por
   QUALQUER `Company::create()`/`update()` que receba um array não filtrado. Hoje
   `CompanyController::update()` usa `$request->validate([...])` com lista fechada de chaves
   (linhas 831-853) — `etapa` não está lá, então está protegido *hoje*, mas é proteção por
   ausência, não por desenho. **Mitigação recomendada:** o teste de ETAPA-03 (Pitfall 1) deve
   ser tratado como regra de segurança, não só de correção funcional — é a defesa contra
   escalação silenciosa de "editar cadastro" para "pular etapas do fluxo".
2. **Endpoint de transição, quando nascer (fora do escopo da 137, mas o serviço precisa estar
   pronto para ser chamado com segurança).** Seguindo D-13, `transicionar()` recebe `User $por`
   — mas nada garante hoje que o `$por` passado é de fato `auth()->user()` e não um ID arbitrário
   vindo de um payload. **Mitigação recomendada para quem plugar o primeiro chamador real
   (Fases 151+):** sempre passar `$request->user()`/`auth()->user()`, nunca aceitar `user_id` do
   corpo da requisição para esse parâmetro.
3. **Exposição de `etapa` em payload público.** `CompanyController::index()` já expõe bastante
   dado de cliente na aba admin (autenticada, `role:admin`/`core.empresas`). Não há indício de
   nenhum endpoint PÚBLICO (ex.: `/implementacao/*`, que tem CSRF desabilitado — ver
   `ARCHITECTURE.md`/`CLAUDE.md`) que precise ler `etapa` nesta fase. **Atenção para fases
   futuras:** se `etapa`/pendência algum dia aparecer no Portal do Cliente (autenticação por
   token, `RestringeDominioDoPortal`), motivo de pendência pode conter texto livre sensível
   (ex.: nome de responsável interno) — não é risco desta fase, mas vale registrar para quando
   D-18 definir o campo `motivo`.
4. **`podeTransicionar($destino)` como superfície de enumeração.** Se este método (ou um
   endpoint fino sobre ele) for exposto para desabilitar botão de UI nas fases futuras, o
   `requisito_faltante` nomeado (ETAPA-06) pode revelar detalhe de processo interno (ex.: "falta
   assinatura do contrato") a quem não deveria ver isso. Não é um risco alto nesta fase (o
   público é interno, autenticado, admin), mas se algum dia esse método for chamado a partir de
   um contexto de acesso mais amplo (ex.: Portal do Cliente), a mensagem nomeada precisa ser
   revisada para não vazar processo interno ao cliente.

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Mass assignment de coluna sensível via `$fillable` genérico | Tampering | Lista explícita de chaves em `$request->validate()` por endpoint (já é o padrão do projeto) + teste de regressão dedicado (Pitfall 1) |
| Ator forjado numa gravação que registra "quem fez" (D-13, D-16) | Spoofing | Nunca aceitar `user_id`/ator via payload — sempre `auth()->user()`/`$request->user()` |
| Escalação silenciosa de estado (pular etapa sem os requisitos, ETAPA-06) | Elevation of Privilege | `podeTransicionar()` como único portão, chamado SEMPRE antes de qualquer escrita — nunca condicional só na UI |

## Sources

### Primary (HIGH confidence — leitura direta do código deste worktree)
- `app/Models/Company.php` (linhas 1-110, 290-358) — `$fillable`, `$casts`, `getActivitylogOptions()`, `analistaPerformance()`, `estrategistaPerformance()`, `managerHistory()`
- `app/Http/Controllers/CompanyController.php` (linhas 1-350, 829-869) — query base de `/companies`, payload, `em_operacao`, `update()`
- `app/Services/Contratos/GatilhoContratoAdministrativoService.php` (integral) — molde `avaliar()`/`dispararSeElegivel()`
- `app/Services/Operacional/EmpresaOperacionalRouter.php` (integral) — precedente "serviço sem chamador"
- `app/Observers/CompanyGatilhoContratoObserver.php` (integral) — `CAMPOS_GATILHO`, risco de laço
- `database/migrations/2026_05_25_100001_add_status_to_companies.php` (integral) — anti-padrão de migration
- `app/Models/CompanyManagerHistory.php` + `database/migrations/2026_07_23_100000_create_company_manager_history_table.php` — precedente de tabela dedicada para D-16
- `app/Services/Portal/PortalAuditoria.php` (linhas 1-193) + `app/Services/RevisaoService.php` (linhas 313-320) — precedente de `activitylog` custom
- `app/Http/Controllers/PolosController.php` (linhas 1865-1876) — `desconsideraDaMeta()`
- `app/Http/Controllers/ComercialController.php` (linhas 580-596) — origem de `status='pendente'`
- `app/Models/Onboarding.php` (linhas 54-61) — `STATUS_RASCUNHO`/`ANDAMENTO`/`CONCLUIDO`
- `resources/js/Pages/Companies/Index.jsx` (linhas 160-260) — filtro server-side existente, `em_operacao` client-side
- `routes/web.php` (linhas 861-1024) — middleware de `/companies`
- `tests/Feature/Phase37CompaniesPerformanceFilterTest.php` + `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` (íntegros) — baseline executada nesta sessão: **24/24 verde, 44 assertions**, com o workaround do Pitfall 4
- `config/activitylog.php` — retenção (365 dias), tabela `activity_log`
- Execução real nesta sessão: `php artisan tinker` (contagens locais), `vendor/bin/phpunit` (baseline), `php --version`/`composer --version`/`node --version`/`npm --version`/`DB::select('select version()')` (ambiente)

### Secondary (MEDIUM confidence)
- `.planning/CONTEXT.md` da Fase 150 — decisões do usuário (D-01..D-23), tratadas como travadas, não re-verificadas
- `.planning/REQUIREMENTS-v23.md`, `.planning/ROADMAP.md` (bloco v23.0), `.planning/seeds/fluxo-entrada-novas-empresas-260901.md` — especificação funcional, decisão D0 de não pesquisar domínio

### Tertiary (LOW confidence)
- Nenhuma — esta pesquisa não usou WebSearch/Context7 porque o domínio é 100% interno ao
  código do projeto (nenhuma biblioteca nova, nenhuma API externa nova).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhuma dependência nova, tudo confirmado já instalado
- Architecture: HIGH — todos os precedentes citados foram lidos por inteiro, com linha real
- Pitfalls: HIGH — 4 dos 6 pitfalls foram REPRODUZIDOS nesta sessão (linha desatualizada
  confirmada por grep, teste de mass assignment confirmado por leitura de `update()`, ausência
  de `node_modules`/manifest confirmada por `ls`, taxonomia `consultor`/`analista` confirmada
  por leitura de `Company.php`); os outros 2 (Observer, migration anti-padrão) confirmados por
  leitura direta do arquivo citado

**Research date:** 2026-09-01
**Valid until:** 2026-10-01 (30 dias — domínio estável, mas o arquivo `CompanyController.php`
já mostrou crescer rápido; reconferir números de linha se esta pesquisa for reaberta depois de
qualquer outra fase tocar nesse controller)
