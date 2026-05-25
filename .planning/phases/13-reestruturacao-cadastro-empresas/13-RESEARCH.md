# Phase 13: Reestruturação do Cadastro de Empresas — Pesquisa

**Pesquisado:** 2026-05-25
**Domínio:** Cadastro centralizado de empresas, migração de dados, sistema de permissões, notificações
**Confiança:** HIGH

---

<user_constraints>
## Restrições do Usuário (de CONTEXT.md)

### Decisões Bloqueadas
- **D-01:** Criar setor "Comercial" na tabela `setores` (is_system=false) + permission_key `comercial.cadastrar_empresa` no catálogo `app/Support/Permissions.php`. Membros do setor Comercial ganham a permissão automaticamente via `setor_permissoes`.
- **D-02:** Admin sempre tem acesso à tela do Comercial via `isAdmin()` short-circuit — padrão existente do sistema (não criar exceção).
- **D-03:** Item "Comercial" aparece no sidebar do `AppLayout.jsx` para usuários com `comercial.cadastrar_empresa`, com sub-item "Cadastro de Empresas". Segue o padrão de gating por permissão já usado em outros setores.
- **D-04:** Cadastro mínimo — Comercial preenche apenas: Nome (obrigatório), CNPJ (opcional, único), service_type (obrigatório), e subtipo quando service_type='publicacao'.
- **D-05:** Dados contratuais NÃO são obrigatórios no cadastro inicial — preenchidos depois pelo Financeiro/Admin.
- **D-06:** Quando service_type = Publicação, o formulário exibe dinamicamente um segundo campo de subtipo: POLOS ou Assessoria.
- **D-07:** Duplicatas bloqueadas — validação case-insensitive contra `companies.name` e `mlb_empresas.nome`.
- **D-08:** Comercial NÃO atribui consultor/estrategista no cadastro.
- **D-09:** Adicionar coluna `companies.status` (enum/string: `'pendente'`, `'ativo'`). Cadastros novos iniciam como `'pendente'`.
- **D-10:** Duplo aviso: notificação automática para líderes do setor de destino + seção "Pendentes" nas páginas existentes.
- **D-11:** Seções "Pendentes" nas páginas existentes de cada setor (não criar novas páginas).
- **D-12:** Renomear `'polo'` → `'polos'` no banco e validações. Migration atualiza registros existentes.
- **D-13:** Valores finais de `companies.service_type`: `'polos'`, `'assessoria'`, `'incubadora'`, `'publicidade'`, `'gestao'`.
- **D-14:** Migration de dados cria um registro em `companies` para cada `mlb_empresa` sem `company_id`.
- **D-15:** `service_type` das companies retroativas: POLO/POLOS→'polos', ASSESSORIA→'assessoria', Incubadora→'incubadora'.
- **D-16:** Companies criadas retroativamente recebem `status = 'ativo'`.
- **D-17:** Todos os registros já existentes em `companies` também recebem `status = 'ativo'` via migration.
- **D-18:** Adicionar coluna `mlb_empresas.company_id` (FK nullable, nullOnDelete → companies).
- **D-19:** `companies` e `mlb_empresas` NÃO são fundidas.
- **D-20:** Ao cadastrar empresa POLO, reutilizar exatamente a lógica de `MlbImplementacaoController@criar` (linhas 176-213). Não duplicar — extrair para método privado compartilhado ou chamar diretamente.
- **D-21:** Guard de duplicata por nome via `MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)')`.

### Discrição do Claude
- Estrutura interna do `ComercialController` (service layer ou lógica inline — seguir o padrão do CompanyController existente)
- URL da tela de cadastro: `/comercial/empresas/novo` ou `/comercial/cadastro`
- Texto exato das notificações disparadas para líderes de setor
- Estilo visual da seção "Pendentes"

### Ideias Adiadas (FORA DO ESCOPO)
- Tela de progresso de onboarding por empresa
- Notificação de rememoração após X dias
- Histórico de cadastros do Comercial (pode ser derivado do `activity_log`)
</user_constraints>

<phase_requirements>
## Requisitos da Fase

| ID | Descrição | Suporte de Pesquisa |
|----|-----------|---------------------|
| COM-01 | Usuário do setor Comercial vê item "Comercial" no sidebar e acessa o formulário; sem permissão recebe 403 | Padrão de permissão/middleware `EnsurePermission` + NAV_ITEMS do AppLayout verificados |
| COM-02 | Formulário com Nome, CNPJ, service_type e subtipo dinâmico POLOS/Assessoria | Padrão de formulário Inertia + useForm verificado via CompanyController/Financeiro.jsx |
| COM-03 | Guard de duplicatas case-insensitive contra `companies.name` e `mlb_empresas.nome` | `whereRaw('LOWER(nome) = LOWER(?)')` já existe em MlbImplementacaoController — reutilizar |
| COM-04 | Criação atômica POLOS: companies + mlb_empresas + mlb_implementacao em DB::transaction | Lógica canônica nas linhas 176-213 do MlbImplementacaoController verificada |
| COM-05 | Criação atômica Assessoria: companies + mlb_empresas | Subconjunto da lógica do COM-04 |
| COM-06 | Publicidade/Gestão: apenas companies | Padrão mais simples; sem mlb_empresas |
| COM-07 | Empresa aparece em `/administrativo/financeiro` automaticamente | AdminController::fechamento() lê TODAS as companies — basta o registro existir |
| COM-08 | Notificação automática para líderes do setor de destino | `Notification::send()` com `BaseNotification` — infraestrutura Phase 8-12 verificada |
| COM-09 | Seção "Pendentes" nas páginas existentes dos setores de destino | Filtro por `companies.status='pendente'` via company_id nas queries existentes |
| COM-10 | Migration idempotente cria companies para mlb_empresas sem company_id | Padrão idempotente de `2026_05_07_000011` verificado |
| COM-11 | `mlb_empresas.company_id` FK nullable nullOnDelete preenchida para todos os registros | Padrão de FK de `2026_05_20_100003_add_parent_company_id_to_companies.php` verificado |
</phase_requirements>

---

## Resumo

Esta fase cria um fluxo centralizado de cadastro de empresas pelo setor Comercial. A pesquisa verificou o estado exato do banco de dados, os padrões de código existentes e os pontos de integração críticos.

**Estado atual do banco:** `companies` tem 5 registros (2 com `service_type='polo'`, 3 com `NULL`). `mlb_empresas` tem 37 registros (todos com `tipo='POLO'`, distribuídos entre `projeto=NULL`, `POLOS` e `Assessoria`). As colunas `companies.status` e `mlb_empresas.company_id` **ainda não existem** — precisam ser criadas por migration.

**Setores:** A tabela `setores` está vazia no banco local (`SELECT * FROM setores` retorna 0 linhas). O setor "Comercial" precisa ser inserido via migration de dados (seeder ou migration PHP), não apenas criado na UI.

**Infraestrutura de notificações:** Completamente funcional (Phases 8-12). `BaseNotification` + `Categoria` enum + `Notification::send()` prontos para uso. Basta criar subclasse `EmpresaCadastradaNotification extends BaseNotification` e disparar para `$setor->lideres`.

**Recomendação principal:** Criar `ComercialController` inline (sem service layer separado), extrair a lógica de criação de POLO + Implementação de `MlbImplementacaoController::criar` (linhas 176-213) para um método privado estático ou trait compartilhado, e usar `permission:comercial.cadastrar_empresa` como middleware de rota — mesmo padrão de `permission:notificacoes.criar`.

## Mapa de Responsabilidades Arquiteturais

| Capacidade | Camada Primária | Camada Secundária | Justificativa |
|-----------|-----------------|-------------------|---------------|
| Validação e criação do cadastro | API/Backend (Controller) | — | Lógica transacional; sem exposição de API REST |
| Roteamento por service_type | API/Backend (Controller) | — | Decisão baseada em dados do formulário |
| Guard de duplicatas | API/Backend (Controller) | — | Validação server-side obrigatória |
| Migração retroativa | Database (Migration) | — | Operação única de dados existentes |
| Notificações para líderes | API/Backend (Controller) | — | Dispatch pós-transação; sistema já existe |
| Exibição de Pendentes | Frontend (JSX pages existentes) | Backend (shared props) | Seção adicional nas páginas MLB/Companies |
| Controle de acesso | Middleware (`EnsurePermission`) | — | Padrão `permission:comercial.cadastrar_empresa` |
| Item no sidebar | Frontend (AppLayout.jsx NAV_ITEMS) | — | Gating por permissão já implementado |

---

## Stack Padrão

### Core (sem novas dependências — tudo reutilizado)

| Componente | Versão/Local | Propósito | Por que padrão |
|-----------|--------------|-----------|---------------|
| `DB::transaction()` | Laravel 12 | Atomicidade criação Company + MlbEmpresa + MlbImplementacao | Padrão estabelecido no MlbImplementacaoController |
| `EnsurePermission` middleware | `app/Http/Middleware/` | Gate de rota por permission key | Já usado em `permission:notificacoes.criar` — mesmo padrão |
| `Notification::send()` | Laravel + Phase 8-12 | Notificar líderes de setor | Infraestrutura completa e testada |
| `BaseNotification` | `app/Notifications/` | Classe base para nova `EmpresaCadastradaNotification` | Canal 'database', payload canônico 6 chaves |
| `activity()->log()` | Spatie activitylog ^4.9 | Log de criação de empresa | Obrigatório em toda criação de entidade |
| `MlbImplementacao::dadosPadrao()` + `MlbConfiguracao::implementacaoPadroes()` | `app/Models/` | Dados iniciais de implementação POLO | Não alterar — reutilizar exatamente |
| `Str::random(48)` | Laravel | Token de implementação | Padrão da linha 205 do MlbImplementacaoController |
| `Inertia::render()` | Inertia.js ^2.0 | Resposta de página | Padrão único — sem API separada |
| `useForm()` | `@inertiajs/react ^2.0` | Estado de formulário no frontend | Padrão de todos os formulários do projeto |

### Sem novos pacotes
Esta fase não instala dependências externas. Tudo reutiliza infraestrutura existente.

---

## Auditoria de Legitimidade de Pacotes

> Não aplicável — esta fase não instala pacotes externos.

---

## Padrões de Arquitetura

### Diagrama de Fluxo do Cadastro

```
Browser (Comercial user)
    │
    │ POST /comercial/empresas
    ▼
EnsurePermission middleware ('comercial.cadastrar_empresa')
    │ [403 se sem permissão]
    ▼
ComercialController::store()
    ├─ Validar: nome (req), cnpj (opt,unique), service_type (req), subtipo (cond)
    ├─ Guard duplicata: whereRaw LOWER(nome) contra companies + mlb_empresas
    │
    └─ DB::transaction()
           ├─ service_type = 'polos'
           │      ├── Company::create(status='pendente', service_type='polos')
           │      ├── MlbEmpresa::create(tipo='POLO', projeto='POLOS', fase='M0')
           │      └── MlbImplementacao::create(token, dadosPadrao() + implementacaoPadroes())
           │
           ├─ service_type = 'assessoria'
           │      ├── Company::create(status='pendente', service_type='assessoria')
           │      └── MlbEmpresa::create(tipo='ASSESSORIA')
           │
           └─ service_type = 'publicidade' | 'gestao'
                  └── Company::create(status='pendente', service_type=...)
    │
    ├─ activity()->causedBy()->withProperties()->log(...)
    ├─ Notification::send($setor->lideres, new EmpresaCadastradaNotification(...))
    └─ return back()->with('success', ...)
```

### Estrutura de Arquivos Novos

```
app/
├── Http/Controllers/
│   └── ComercialController.php          # Novo — index() + store()
├── Notifications/
│   └── EmpresaCadastradaNotification.php # Nova — extends BaseNotification
database/migrations/
├── 2026_05_25_100001_add_status_to_companies.php
├── 2026_05_25_100002_add_company_id_to_mlb_empresas.php
└── 2026_05_25_100003_migrate_companies_and_link_mlb_empresas.php  # idempotente
resources/js/Pages/
└── Comercial/
    └── NovaEmpresa.jsx                  # Formulário de cadastro
```

### Padrão 1: Middleware `permission:` para nova rota

**O que é:** Rota gated por permission key usando `EnsurePermission` já existente.

**Quando usar:** Toda rota do módulo Comercial.

```php
// Fonte: routes/web.php — padrão da Phase 12 (VERIFICADO: codebase)
Route::middleware(['auth', 'verified', 'permission:comercial.cadastrar_empresa'])
    ->prefix('comercial')
    ->name('comercial.')
    ->group(function () {
        Route::get('/empresas/novo',  [ComercialController::class, 'index'])->name('empresas.novo');
        Route::post('/empresas',      [ComercialController::class, 'store'])->name('empresas.store');
    });
```

### Padrão 2: Criação atômica com DB::transaction

**O que é:** Garantia de que todos os registros são criados juntos ou nenhum é criado.

**Quando usar:** Sempre que o Comercial cadastrar empresa tipo POLOS ou Assessoria.

```php
// Fonte: MlbImplementacaoController.php linha 176 (VERIFICADO: codebase)
DB::transaction(function () use ($validated, $request, &$company) {
    $company = Company::create([
        'name'         => $validated['nome'],
        'cnpj'         => $validated['cnpj'] ?? null,
        'service_type' => 'polos',
        'status'       => 'pendente',
    ]);

    $empresa = MlbEmpresa::create([
        'nome'       => $validated['nome'],
        'tipo'       => 'POLO',
        'projeto'    => 'POLOS',
        'fase'       => 'M0',
        'estagio'    => 'Não Listado',
        'criado_por' => $request->user()->id,
        'company_id' => $company->id,
    ]);

    $dados = MlbImplementacao::dadosPadrao();
    $p     = MlbConfiguracao::implementacaoPadroes();
    if ($p['tutorial_intro'])        $dados['tutorial_intro'] = $p['tutorial_intro'];
    if (!empty($p['tutoriais']))     $dados['tutoriais']      = array_merge($dados['tutoriais'], $p['tutoriais']);
    if (!empty($p['links_admin_extra'])) {
        $dados['links_admin']['programa_decola'] = $p['links_admin_extra']['programa_decola'] ?? '';
    }

    MlbImplementacao::create([
        'empresa_id' => $empresa->id,
        'token'      => Str::random(48),
        'dados'      => $dados,
    ]);
});
```

### Padrão 3: Adição de item no sidebar (NAV_ITEMS)

**O que é:** Entrada no array `NAV_ITEMS` de `AppLayout.jsx` com separador de seção.

**Quando usar:** Para adicionar o item "Cadastro de Empresas" ao sidebar.

```jsx
// Fonte: AppLayout.jsx NAV_ITEMS array (VERIFICADO: codebase)
// Adicionar ANTES dos itens MLB (mlbSeparatorBefore):
{ label: 'Cadastro de Empresas', routeName: 'comercial.empresas.novo',
  page: 'Comercial/NovaEmpresa', icon: PlusCircle,
  permission: 'comercial.cadastrar_empresa', comercialSeparatorBefore: true },
```

E no `SidebarInner`, adicionar handler para `item.comercialSeparatorBefore`:
```jsx
{item.comercialSeparatorBefore && (!collapsed || mobile) && (
    <div className="flex items-center gap-2 px-3 pt-4 pb-1.5">
        <div className="h-px flex-1 bg-white/[0.06]" />
        <span className="text-white/20 text-[10px] font-semibold uppercase tracking-wider">Comercial</span>
        <div className="h-px flex-1 bg-white/[0.06]" />
    </div>
)}
```

### Padrão 4: Notificação para líderes de setor

**O que é:** Dispatch de `EmpresaCadastradaNotification` para os líderes do setor de destino após o transaction.

**Quando usar:** Sempre após criação bem-sucedida de empresa pelo Comercial.

```php
// Fonte: NotificacaoController::criar + BaseNotification (VERIFICADO: codebase)
// Após o DB::transaction:
$setorDestino = Setor::where('slug', $this->resolverSlugSetor($serviceType))->first();
if ($setorDestino) {
    $lideres = $setorDestino->lideres;
    if ($lideres->isNotEmpty()) {
        Notification::send(
            $lideres,
            new EmpresaCadastradaNotification(
                nomeEmpresa: $validated['nome'],
                serviceType: $serviceType,
                autorUserId: $request->user()->id,
            )
        );
    }
}
```

### Padrão 5: Migration idempotente de dados

**O que é:** Migration que pode ser re-executada sem criar duplicatas.

**Quando usar:** Migration de criação retroativa de companies para mlb_empresas.

```php
// Fonte: 2026_05_07_000011_fix_fase_projeto_mlb_empresas.php (VERIFICADO: codebase)
// Padrão: verificar se já foi migrado antes de agir
DB::table('mlb_empresas')
    ->whereNull('company_id')  // <-- idempotência: só processa os não migrados
    ->each(function ($empresa) {
        $company = DB::table('companies')->insertGetId([
            'name'         => $empresa->nome,
            'service_type' => $this->derivarServiceType($empresa),
            'status'        => 'ativo',
            'active'        => true,
            'created_at'   => $empresa->created_at,
            'updated_at'   => now(),
        ]);
        DB::table('mlb_empresas')
            ->where('id', $empresa->id)
            ->update(['company_id' => $company]);
    });
```

### Anti-Padrões a Evitar

- **Duplicar a lógica de criação POLO:** A lógica de `dadosPadrao() + implementacaoPadroes()` das linhas 176-213 do `MlbImplementacaoController` deve ser extraída, não copiada. Manter duas cópias vai desincronizar quando a lógica mudar.
- **Criar companies sem `status`:** Após as migrations da Phase 13, toda criação de company via Comercial inicia com `status='pendente'`. Não omitir o campo.
- **Renomear 'polo' sem migration de dados:** Existem 2 companies com `service_type='polo'` no banco. A migration deve atualizá-las para `'polos'` antes de qualquer validação que rejeite `'polo'`.
- **Disparar notificação dentro do DB::transaction:** A notificação deve ser disparada APÓS o commit da transaction, não dentro dela — uma falha de notificação não deve fazer rollback do cadastro.
- **Criar setor "Comercial" via seed manual:** Deve ser criado via migration de dados (migration PHP) para garantir reprodutibilidade em qualquer ambiente, inclusive CI.

---

## Não Construir do Zero

| Problema | Não Construir | Usar | Por quê |
|----------|---------------|------|---------|
| Gate de acesso por permissão | Middleware customizado | `EnsurePermission` + `permission:comercial.cadastrar_empresa` | Middleware já existe, testado, com short-circuit admin |
| Notificação de evento | Sistema de email/webhook | `BaseNotification` + `Notification::send()` | Infraestrutura completa das Phases 8-12 |
| Criação de implementação POLO | Lógica duplicada no ComercialController | Método privado extraído de `MlbImplementacaoController::criar` (linhas 176-213) | D-20 explícito |
| Slug de setor | Geração manual | `Setor::booted()` já gera slug via `Str::slug($nome)` | Automático no model |
| Guard de duplicata por nome | Query manual com LIKE | `MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)')` | Padrão já funcional no MlbImplementacaoController |

---

## Inventário de Estado em Runtime

> Esta fase inclui rename/migration de dados — inventário explícito obrigatório.

| Categoria | Itens Encontrados | Ação Necessária |
|-----------|------------------|-----------------|
| Dados armazenados (banco) | `companies.service_type='polo'` em 2 registros | Migration: UPDATE companies SET service_type='polos' WHERE service_type='polo' |
| Dados armazenados (banco) | `mlb_empresas`: 37 registros sem `company_id` (coluna não existe ainda) | Migration: adicionar coluna + criar companies retroativas + preencher company_id |
| Dados armazenados (banco) | `companies`: 5 registros existentes sem coluna `status` | Migration: ADD COLUMN status + UPDATE SET status='ativo' para todos existentes |
| Dados armazenados (banco) | `setores`: tabela vazia — setor "Comercial" não existe | Migration: INSERT setor Comercial + INSERT permission 'comercial.cadastrar_empresa' em setor_permissoes |
| Config de serviço externo | Nenhum — apenas banco de dados local | — |
| Estado do SO / schedulers | Nenhum impacto — sem comandos agendados novos | — |
| Secrets/env vars | Nenhum novo campo — sem credenciais externas | — |
| Artefatos de build | `npm run build` obrigatório após edição de JSX | Executar após cada wave com edição frontend |

**Nada encontrado em:** Serviços externos, agendadores de OS, secrets. Verificado por: ausência de referências ao módulo Comercial em qualquer config existente.

---

## Armadilhas Comuns

### Armadilha 1: AdminController valida service_type com enum antigo

**O que dá errado:** `AdminController::updateEmpresa()` (linha 53) valida `service_type` com `'nullable|in:polo,assessoria,incubadora'`. Após a Phase 13, `'polo'` é inválido — mas dados existentes no banco terão `'polos'`.

**Por que acontece:** A migration renomeia `'polo'` → `'polos'` no banco, mas o código de validação não é atualizado simultaneamente.

**Como evitar:** A migration de dados (Wave 1) deve rodar ANTES de qualquer code change. O `AdminController::updateEmpresa()` deve ser atualizado na mesma wave que a migration: `'nullable|in:polos,assessoria,incubadora,publicidade,gestao'`.

**Sinais de alerta:** Erro 422 ao tentar editar empresa existente via Fechamento após a migration.

---

### Armadilha 2: Financeiro.jsx tem SERVICE_LABELS/COLORS com 'polo' hardcoded

**O que dá errado:** `Financeiro.jsx` (linhas 9-19) tem `SERVICE_LABELS = { polo: 'POLO', assessoria: ..., incubadora: ... }`. Após a renomeação para `'polos'`, empresas exibirão label undefined.

**Por que acontece:** Constantes frontend não são atualizadas junto com a migration de banco.

**Como evitar:** Atualizar `SERVICE_LABELS` e `SERVICE_COLORS` para usar `'polos'` como chave. Também adicionar `'publicidade'` e `'gestao'` ao objeto.

**Sinais de alerta:** Badge de service_type aparece vazio ou como texto undefined na tela de Fechamento.

---

### Armadilha 3: Guard de duplicata deve verificar AMBAS as tabelas

**O que dá errado:** Verificar só `companies.name` OU só `mlb_empresas.nome` — ambas podem ter o nome sem a outra existir (dados pré-existentes ou empresa recém-criada sem mlb_empresa).

**Por que acontece:** Após a migration retroativa, toda mlb_empresa terá uma company vinculada. Mas em edge cases (rollback parcial, dados sujos), pode não haver company.

**Como evitar:** Guard de duplicata no `ComercialController::store()` deve fazer DOIS queries: `Company::whereRaw('LOWER(name) = LOWER(?)', [$nome])->exists()` E `MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)', [$nome])->exists()`.

---

### Armadilha 4: Setor "Comercial" sem líderes não dispara notificação

**O que dá errado:** `$setor->lideres` retorna collection vazia → `Notification::send([], ...)` não causa erro, mas a notificação nunca é disparada e o usuário não recebe feedback.

**Por que acontece:** A migration cria o setor mas não cadastra líderes (correto — não há líderes pré-definidos).

**Como evitar:** O `ComercialController` deve verificar `$lideres->isNotEmpty()` antes de chamar `Notification::send()`. A UI do Financeiro e das páginas de setor não depende de notificações — a seção "Pendentes" aparece independentemente.

---

### Armadilha 5: Migration retroativa sem idempotência cria companies duplicadas

**O que dá errado:** Se a migration for re-executada (ex: durante desenvolvimento), cria múltiplas companies para a mesma mlb_empresa.

**Por que acontece:** `DB::table('companies')->insertGetId(...)` sem verificar existência prévia.

**Como evitar:** Filtrar `->whereNull('company_id')` antes de iterar — empresas já migradas têm `company_id` preenchido, então não são reprocessadas. Padrão do `2026_05_07_000011_fix_fase_projeto_mlb_empresas.php`.

---

### Armadilha 6: Timestamp da migration deve ser posterior a 2026_05_22

**O que dá errado:** Migration com timestamp anterior a migrations existentes pode ser executada fora de ordem.

**Por que acontece:** Laravel executa migrations em ordem cronológica pelo timestamp do nome do arquivo.

**Como evitar:** Usar timestamps `2026_05_25_100001`, `2026_05_25_100002`, `2026_05_25_100003` para as 3 migrations desta fase.

---

### Armadilha 7: Coluna mlb_empresas.company_id no fillable

**O que dá errado:** `MlbEmpresa::create([..., 'company_id' => $company->id])` falha silenciosamente (mass assignment protection) se `company_id` não estiver no `$fillable`.

**Por que acontece:** `MlbEmpresa::$fillable` atual não inclui `company_id` (coluna ainda não existe).

**Como evitar:** Adicionar `'company_id'` ao `$fillable` do `MlbEmpresa` model na mesma wave da migration que cria a coluna.

---

## Exemplos de Código

### Guard de Duplicata (padrão canônico)
```php
// Fonte: MlbImplementacaoController::criar linha 159 (VERIFICADO: codebase)
// Para o ComercialController, verificar AMBAS as tabelas:
$existeEmCompanies  = Company::whereRaw('LOWER(name) = LOWER(?)', [$nome])->exists();
$existeEmMlbEmpresa = MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)', [$nome])->exists();

if ($existeEmCompanies || $existeEmMlbEmpresa) {
    throw ValidationException::withMessages([
        'nome' => "Já existe uma empresa com o nome \"{$nome}\" no sistema.",
    ]);
}
```

### Activity Log obrigatório após criação
```php
// Fonte: MlbImplementacaoController linha 209 (VERIFICADO: codebase)
activity('comercial')
    ->causedBy($request->user())
    ->withProperties([
        'empresa'      => $company->name,
        'service_type' => $company->service_type,
    ])
    ->log('Empresa cadastrada pelo Comercial: "' . $company->name . '"');
```

### Nova Notificação (subclasse de BaseNotification)
```php
// Fonte: ManualNotification.php + BaseNotification.php (VERIFICADO: codebase)
namespace App\Notifications;

class EmpresaCadastradaNotification extends BaseNotification
{
    public function __construct(string $nomeEmpresa, string $serviceType, ?int $autorUserId)
    {
        parent::__construct(
            titulo:      'Nova empresa cadastrada: ' . $nomeEmpresa,
            mensagem:    "O setor Comercial cadastrou a empresa \"{$nomeEmpresa}\" (tipo: {$serviceType}). Verifique os pendentes.",
            categoria:   Categoria::MANUAL, // usar MANUAL até uma nova categoria ser definida
            autorUserId: $autorUserId,
            url:         route('notificacoes.index'),
            meta:        ['empresa' => $nomeEmpresa, 'service_type' => $serviceType],
        );
    }
}
```

### Padrão FK nullable nullOnDelete (para mlb_empresas.company_id)
```php
// Fonte: 2026_05_20_100003_add_parent_company_id_to_companies.php (VERIFICADO: codebase)
Schema::table('mlb_empresas', function (Blueprint $table) {
    $table->unsignedBigInteger('company_id')->nullable()->after('id');
    $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
});
```

### Derivação de service_type na migration retroativa
```php
// Fonte: CONTEXT.md D-15 + mlb_empresas data verificada (VERIFICADO: codebase)
private function derivarServiceType(object $empresa): string
{
    $tipo    = strtoupper($empresa->tipo ?? '');
    $projeto = strtoupper($empresa->projeto ?? '');

    if ($tipo === 'POLO' && ($projeto === 'POLOS' || $projeto === '')) {
        return 'polos';
    }
    if ($tipo === 'POLO' && $projeto === 'ASSESSORIA') {
        return 'assessoria';
    }
    if ($tipo === 'ASSESSORIA') {
        return 'assessoria';
    }
    if ($tipo === 'INCUBADORA') {
        return 'incubadora';
    }
    return 'polos'; // fallback conservador
}
```

**Atenção:** No banco atual existem 23 registros com `tipo='POLO'` e `projeto=NULL`, 13 com `tipo='POLO'` e `projeto='POLOS'`, e 1 com `tipo='POLO'` e `projeto='Assessoria'`. A lógica acima cobre todos os casos. Não existe `tipo='ASSESSORIA'` ou `tipo='INCUBADORA'` no banco local — mas a lógica é defensiva para produção.

---

## Estado da Arte / Mudanças Necessárias

| Código Antigo | Código Atualizado | Onde Muda | Impacto |
|---------------|-------------------|-----------|---------|
| `service_type='polo'` (banco + código) | `service_type='polos'` | Migration de dados + AdminController + Financeiro.jsx + CompanyController | 2 registros no banco, validação inline, labels frontend |
| `'nullable\|in:polo,assessoria,incubadora'` (AdminController linha 53) | `'nullable\|in:polos,assessoria,incubadora,publicidade,gestao'` | `AdminController::updateEmpresa()` | Aceita os novos service_types |
| `SERVICE_LABELS = { polo: ... }` (Financeiro.jsx) | `SERVICE_LABELS = { polos: ..., publicidade: ..., gestao: ... }` | `Financeiro.jsx` + `Admin/Empresas.jsx` (se existir) | Labels corretos no frontend |
| `companies` sem coluna `status` | `companies.status` enum 'pendente'/'ativo' | Migration + Company model + AdminController | Controle de onboarding |
| `mlb_empresas` sem FK `company_id` | `mlb_empresas.company_id` nullable FK | Migration + MlbEmpresa model (fillable) | Rastreabilidade origin |

---

## Log de Suposições

| # | Afirmação | Seção | Risco se Errado |
|---|-----------|-------|-----------------|
| A1 | A tabela `setores` está vazia no banco de produção (assim como no local) | Runtime State Inventory | Se já existir um setor "Comercial" em produção, a migration de INSERT falharia por conflito de slug |
| A2 | `Categoria::MANUAL` é categoria adequada para notificação de cadastro de empresa | Exemplos de Código | Se o produto quiser filtrar notificações por tipo, precisaria de `Categoria::EMPRESA_CADASTRADA` — requer Phase nova de enum |
| A3 | O banco de dados local reflete o estado de produção para mlb_empresas (sem tipo ASSESSORIA ou INCUBADORA puro) | Runtime State Inventory | Se produção tiver outros tipos, a derivação de service_type pode retornar 'polos' indevidamente |

---

## Perguntas em Aberto

1. **Novo Categoria enum ou reutilizar MANUAL?**
   - O que sabemos: `Categoria::MANUAL` existe e funciona; adicionar nova categoria exige mudança no enum PHP + UI label no frontend
   - O que não está claro: o produto vai querer filtrar notificações de "empresa cadastrada" separado de "manual"?
   - Recomendação: Usar `Categoria::MANUAL` no MVP (Phase 13). Adicionar `Categoria::COMERCIAL` em milestone futuro se demanda surgir.

2. **Setor de destino para Publicidade e Gestão**
   - O que sabemos: Para POLOS → setor Implementação/MLB; para Publicidade → `/companies`; para Gestão → `/companies`
   - O que não está claro: Existe um setor específico "Publicidade" e "Gestão" nos setores para buscar líderes?
   - Recomendação: No cadastro inicial da Phase 13, o mapeamento service_type → setor slug pode ser uma constante no ComercialController. Se o setor não existir (ainda vazio no banco local), simplesmente não dispara notificação.

---

## Disponibilidade do Ambiente

| Dependência | Requerida por | Disponível | Versão | Fallback |
|-------------|--------------|------------|--------|---------|
| PHP 8.2 (XAMPP) | Migrations, Controller | Verificado via artisan tinker | XAMPP local | — |
| MySQL/SQLite | Banco de dados | Verificado (artisan tinker respondeu) | Local | — |
| npm/Vite 7 | Build frontend | Padrão do projeto | v7 | — |

**Nenhuma dependência bloqueante identificada.**

---

## Arquitetura de Validação

### Framework de Testes
| Propriedade | Valor |
|-------------|-------|
| Framework | PHPUnit 11.x |
| Config | `phpunit.xml` |
| Comando rápido | `C:\xampp\php\php.exe artisan test --filter=Phase13` |
| Suíte completa | `C:\xampp\php\php.exe artisan test` |

### Mapa Requisito → Teste

| Req ID | Comportamento | Tipo de Teste | Comando Automatizado | Arquivo Existe? |
|--------|---------------|---------------|---------------------|----------------|
| COM-01 | 403 sem permissão, 200 com permissão `comercial.cadastrar_empresa` | feature | `php artisan test --filter=Phase13ComercialTest::test_sem_permissao_recebe_403` | ❌ Wave 0 |
| COM-02 | Formulário aceita/rejeita campos conforme validação | feature | `php artisan test --filter=Phase13ComercialTest::test_validacao_cadastro` | ❌ Wave 0 |
| COM-03 | Guard duplicata bloqueia nome igual em companies + mlb_empresas | feature | `php artisan test --filter=Phase13ComercialTest::test_guard_duplicata` | ❌ Wave 0 |
| COM-04 | Criação atômica POLOS: company + mlb_empresa + mlb_implementacao | feature | `php artisan test --filter=Phase13ComercialTest::test_cria_empresa_polos_atomico` | ❌ Wave 0 |
| COM-05 | Criação Assessoria: company + mlb_empresa | feature | `php artisan test --filter=Phase13ComercialTest::test_cria_empresa_assessoria` | ❌ Wave 0 |
| COM-06 | Publicidade/Gestão: apenas company | feature | `php artisan test --filter=Phase13ComercialTest::test_cria_empresa_publicidade_gestao` | ❌ Wave 0 |
| COM-07 | Company recém-criada aparece em fechamento() | feature | `php artisan test --filter=Phase13ComercialTest::test_empresa_visivel_no_financeiro` | ❌ Wave 0 |
| COM-08 | Notificação disparada para líderes | feature | `php artisan test --filter=Phase13ComercialTest::test_notificacao_lideres` | ❌ Wave 0 |
| COM-09 | Seção Pendentes aparece nas páginas de setor | manual (visual) | — | ❌ checkpoint humano |
| COM-10 | Migration idempotente: re-executar não cria duplicatas | feature | `php artisan test --filter=Phase13MigrationTest::test_migration_idempotente` | ❌ Wave 0 |
| COM-11 | mlb_empresas.company_id preenchido após migration | feature | `php artisan test --filter=Phase13MigrationTest::test_company_id_preenchido` | ❌ Wave 0 |

### Taxa de Amostragem
- **Por commit de task:** `php artisan test --filter=Phase13`
- **Por merge de wave:** `php artisan test`
- **Gate da fase:** Suíte completa verde antes de `/gsd:verify-work`

### Gaps do Wave 0
- [ ] `tests/Feature/Phase13ComercialTest.php` — cobre COM-01 a COM-08
- [ ] `tests/Feature/Phase13MigrationTest.php` — cobre COM-10 e COM-11
- [ ] Stub de factory para `Setor` (se não existir) para criar setor Comercial nos testes

---

## Domínio de Segurança

### Categorias ASVS Aplicáveis

| Categoria ASVS | Aplica | Controle Padrão |
|----------------|--------|-----------------|
| V2 Autenticação | Sim (rota autenticada) | Middleware `auth` + `verified` já aplicado |
| V3 Gerenciamento de Sessão | Não (sem estado extra) | — |
| V4 Controle de Acesso | Sim (permission gate) | `EnsurePermission` middleware |
| V5 Validação de Input | Sim (formulário de cadastro) | `$request->validate([...])` + guard duplicata |
| V6 Criptografia | Não | — |

### Padrões de Ameaça

| Padrão | STRIDE | Mitigação Padrão |
|--------|--------|-----------------|
| Usuário sem permissão acessa `/comercial/empresas` | Spoofing / Elevation | `permission:comercial.cadastrar_empresa` via EnsurePermission |
| Injeção SQL no campo nome via guard de duplicata | Tampering | `whereRaw()` com binding parametrizado — seguro |
| Criação de empresa com CNPJ duplicado | Tampering | `unique:companies` na validação do campo CNPJ |
| Mass assignment em Company::create | Tampering | `$fillable` explícito no model — `status` e `service_type` devem estar no fillable |

---

## Fontes

### Primárias (confiança HIGH)

- Codebase verificado: `app/Http/Controllers/MlbImplementacaoController.php` linhas 176-213 — lógica canônica de criação POLO
- Codebase verificado: `app/Http/Controllers/NotificacaoController.php` — padrão completo de dispatch de notificação
- Codebase verificado: `app/Notifications/BaseNotification.php` + `Categoria.php` — infraestrutura de notificações Phase 8-12
- Codebase verificado: `app/Support/Permissions.php` — catálogo de permissões, AUTO_LIDERANCA, padrão de adição de nova key
- Codebase verificado: `resources/js/Layouts/AppLayout.jsx` — padrão NAV_ITEMS + separadores de seção
- Codebase verificado: `app/Http/Middleware/EnsurePermission.php` — middleware `permission:` já registrado em `bootstrap/app.php`
- Codebase verificado: `database/migrations/2026_05_20_100003_*` — padrão FK nullable nullOnDelete
- Codebase verificado: `database/migrations/2026_05_07_000011_*` — padrão de migration idempotente
- DB verificado via artisan tinker: estado atual de companies (5 registros, 2 com service_type='polo'), mlb_empresas (37 registros, distribuição de tipos), ausência das colunas `status` e `company_id`

### Secundárias (confiança MEDIUM)

- Codebase verificado: `app/Http/Controllers/AdminController.php` linhas 50-60 — validação atual com `'in:polo,assessoria,incubadora'` que DEVE ser atualizada
- Codebase verificado: `resources/js/Pages/Admin/Financeiro.jsx` linhas 9-19 — `SERVICE_LABELS` com 'polo' hardcoded que DEVE ser atualizado

---

## Metadados

**Breakdown de confiança:**
- Stack padrão: HIGH — tudo verificado no codebase
- Arquitetura: HIGH — padrões existentes confirmados por leitura direta do código
- Armadilhas: HIGH — confirmadas por leitura de código e estado real do banco
- Migration retroativa: MEDIUM — lógica de derivação de service_type baseada em dados do banco local; produção pode diferir (A3)

**Data de pesquisa:** 2026-05-25
**Válido até:** 2026-06-25 (dados estáticos do codebase; banco de produção pode mudar)
