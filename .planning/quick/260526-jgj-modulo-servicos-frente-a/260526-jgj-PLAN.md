---
phase: quick-260526-jgj
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_26_120001_create_servicos_table.php
  - database/migrations/2026_05_26_120002_create_contratos_servico_table.php
  - app/Models/Servico.php
  - app/Models/ContratoServico.php
  - app/Models/Company.php
  - app/Http/Controllers/ServicoController.php
  - app/Http/Controllers/CompanyController.php
  - routes/web.php
  - resources/js/Pages/Servicos/Index.jsx
  - resources/js/Pages/Companies/Index.jsx
  - resources/js/Pages/Companies/Show.jsx
  - resources/js/Layouts/AppLayout.jsx
autonomous: true
requirements:
  - QUICK-SERVICOS-FRENTE-A
must_haves:
  truths:
    - "Admin acessa /servicos (rota nomeada `servicos.index`) e vê o catálogo de serviços cadastrados em tabela"
    - "Admin cria novo serviço (nome, valor_padrao, tipo_cobranca mensal|unica, ativo) via modal/form; após salvar, registro persiste em `servicos` e aparece na lista"
    - "Admin edita serviço existente; campos atualizados refletem na lista; activity_log registra a alteração via spatie/laravel-activitylog"
    - "Admin desativa ou exclui serviço (delete se sem contratos ativos, soft-deactivate via ativo=false caso contrário); UI reflete estado"
    - "Lista de empresas (`/empresas`, Companies/Index.jsx) NÃO exibe mais colunas 'TACOS' nem 'Faturamento (30d)'; passa a exibir coluna 'Serviço' com badges dos contratos ativos da empresa"
    - "Página de detalhes da empresa (`Companies/Show.jsx`) tem seção 'Serviços contratados' com lista dos contratos da empresa, botão 'Adicionar contrato' (modal com select de serviço, valor_contratado, datas, observações), e ações de editar/desativar por contrato"
    - "Item 'Serviços' aparece no sidebar admin (AppLayout.jsx) com ícone Lucide, navega para /servicos"
    - "Campos legacy em companies (service_type, contract_start/end, additional_service, additional_service_price) permanecem intactos; Admin/Financeiro.jsx e AdminController::fechamento NÃO são alterados"
    - "`npm run build` finaliza com 0 erros; `php -l` passa em todos os arquivos PHP novos/alterados"
  artifacts:
    - path: "database/migrations/2026_05_26_120001_create_servicos_table.php"
      provides: "Schema da tabela `servicos` (catálogo): id, nome (string 120), valor_padrao (decimal 12,2 default 0), tipo_cobranca (string), ativo (bool default true), timestamps"
      contains: "Schema::create('servicos'"
    - path: "database/migrations/2026_05_26_120002_create_contratos_servico_table.php"
      provides: "Schema da tabela `contratos_servico` (N:N enriquecida): id, company_id (FK cascade), servico_id (FK restrict), valor_contratado (decimal 12,2), data_contratacao (date), data_vencimento (date nullable), ativo (bool default true), observacoes (text nullable), timestamps. Index composto em (company_id, ativo)"
      contains: "Schema::create('contratos_servico'"
    - path: "app/Models/Servico.php"
      provides: "Model Eloquent Servico com LogsActivity (logOnly nome/valor_padrao/tipo_cobranca/ativo + logOnlyDirty), hasMany contratos, casts (ativo bool, valor_padrao decimal:2), constants TIPO_MENSAL/TIPO_UNICA, scopeActive"
      contains: "class Servico extends Model"
    - path: "app/Models/ContratoServico.php"
      provides: "Model Eloquent ContratoServico com LogsActivity, belongsTo Company + Servico, casts (ativo bool, valor_contratado decimal:2, data_contratacao/data_vencimento date:Y-m-d), scopeActive"
      contains: "class ContratoServico extends Model"
    - path: "app/Http/Controllers/ServicoController.php"
      provides: "CRUD do catálogo: index() (Inertia render Servicos/Index com lista + counts de contratos ativos), store/update (validação completa), destroy (delete se sem contratos ativos, abort 422 senão — exceto se já estiver inativo)"
      contains: "class ServicoController extends Controller"
    - path: "resources/js/Pages/Servicos/Index.jsx"
      provides: "Tabela do catálogo: Nome, Valor padrão (fmtBRL), Tipo (badge), Ativo (toggle), Contratos ativos (count), Ações. Botão 'Novo serviço' abre modal com form (nome, valor_padrao, tipo_cobranca radio, ativo). Padrão DevCard/cn()/tokens ecf-*"
      min_lines: 150
    - path: "resources/js/Pages/Companies/Show.jsx"
      provides: "Seção 'Serviços contratados' com lista de contratos da empresa (ativos + inativos com filtro), modal 'Adicionar contrato' (select servico, valor pre-fill com valor_padrao mas editável, data_contratacao, data_vencimento opcional, observacoes), ações editar/desativar"
    - path: "resources/js/Pages/Companies/Index.jsx"
      provides: "Remoção de colunas TACOS e Faturamento (30d); adição de coluna 'Serviço' com badges dos contratos ativos (max 2 + '+N', tooltip com valor/datas); empresa sem contratos exibe '—'"
  key_links:
    - from: "resources/js/Layouts/AppLayout.jsx (sidebar admin)"
      to: "GET /servicos"
      via: "Inertia Link com route('servicos.index')"
      pattern: "servicos\\.index"
    - from: "resources/js/Pages/Servicos/Index.jsx (form submit)"
      to: "POST /servicos | PUT /servicos/{servico} | DELETE /servicos/{servico}"
      via: "useForm + Inertia router"
      pattern: "route\\('servicos\\.(store|update|destroy)'\\)"
    - from: "resources/js/Pages/Companies/Show.jsx (form contrato)"
      to: "POST /empresas/{company}/contratos-servico | PUT/DELETE /empresas/{company}/contratos-servico/{contrato}"
      via: "useForm + Inertia router"
      pattern: "contratos-servico"
    - from: "app/Http/Controllers/CompanyController.php (index/show)"
      to: "Company::with('contratosServico.servico')"
      via: "Eloquent eager load"
      pattern: "with\\(\\['contratosServico"
---

# Quick Task 260526-jgj: Módulo Serviços (Frente A)

## Contexto

Decisões locked pelo usuário (não revisitar):

- **Frente A apenas** — não fazer data migration, não mexer em campos legacy de `companies` (`service_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price`)
- **Coexistir com legacy** — `Admin/Financeiro.jsx` e `AdminController::fechamento` ficam intactos
- **Sem deploy**
- **pt-BR** em comentários, mensagens, labels
- **admin-only** via middleware `role:admin` já existente
- Frente B (refatoração do Fechamento + drop legacy + data migration) fica para sessão futura

## Tarefas

### Task 1 — Backend foundation (migrations + models + controller + routes)

**Files:**
- `database/migrations/2026_05_26_120001_create_servicos_table.php` (novo)
- `database/migrations/2026_05_26_120002_create_contratos_servico_table.php` (novo)
- `app/Models/Servico.php` (novo)
- `app/Models/ContratoServico.php` (novo)
- `app/Models/Company.php` (alterado — adicionar relation `contratosServico()` hasMany)
- `app/Http/Controllers/ServicoController.php` (novo)
- `app/Http/Controllers/CompanyController.php` (alterado — métodos `storeContrato`, `updateContrato`, `destroyContrato`; eager load em `index()` e `show()`)
- `routes/web.php` (alterado — rotas resource servicos + 3 sub-rotas contrato)

**Action:**

1. Migration `servicos`:
   ```php
   $table->id();
   $table->string('nome', 120);
   $table->decimal('valor_padrao', 12, 2)->default(0);
   $table->string('tipo_cobranca'); // 'mensal' | 'unica'
   $table->boolean('ativo')->default(true);
   $table->timestamps();
   ```

2. Migration `contratos_servico`:
   ```php
   $table->id();
   $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
   $table->foreignId('servico_id')->constrained('servicos')->restrictOnDelete();
   $table->decimal('valor_contratado', 12, 2);
   $table->date('data_contratacao');
   $table->date('data_vencimento')->nullable();
   $table->boolean('ativo')->default(true);
   $table->text('observacoes')->nullable();
   $table->timestamps();
   $table->index(['company_id', 'ativo']);
   ```

3. `Servico.php`:
   - Extends `Model`, uses `LogsActivity`
   - `protected $fillable = ['nome', 'valor_padrao', 'tipo_cobranca', 'ativo']`
   - `$casts = ['valor_padrao' => 'decimal:2', 'ativo' => 'boolean']`
   - `const TIPO_MENSAL = 'mensal';`  `const TIPO_UNICA = 'unica';`
   - `public static function tiposCobranca(): array { return [self::TIPO_MENSAL => 'Mensal', self::TIPO_UNICA => 'Única']; }`
   - `getActivitylogOptions()` → logOnly(['nome','valor_padrao','tipo_cobranca','ativo'])->logOnlyDirty()->setDescriptionForEvent(fn($e) => "Serviço {$e}")
   - `public function contratos(): HasMany { return $this->hasMany(ContratoServico::class); }`
   - `public function scopeActive($q) { return $q->where('ativo', true); }`

4. `ContratoServico.php`:
   - Extends `Model`, uses `LogsActivity`, `$table = 'contratos_servico'`
   - `protected $fillable = ['company_id','servico_id','valor_contratado','data_contratacao','data_vencimento','ativo','observacoes']`
   - `$casts = ['valor_contratado' => 'decimal:2', 'data_contratacao' => 'date:Y-m-d', 'data_vencimento' => 'date:Y-m-d', 'ativo' => 'boolean']`
   - `getActivitylogOptions()` → logOnly(['company_id','servico_id','valor_contratado','data_contratacao','data_vencimento','ativo'])->logOnlyDirty()
   - `belongsTo Company` (relation `company`) e `belongsTo Servico` (relation `servico`)
   - `scopeActive`

5. `Company.php` — adicionar APENAS:
   ```php
   public function contratosServico(): HasMany
   {
       return $this->hasMany(ContratoServico::class);
   }
   ```
   Não tocar em mais nada do model (legacy intacto).

6. `ServicoController.php`:
   - `index()`: `Servico::withCount(['contratos as contratos_ativos_count' => fn($q) => $q->where('ativo', true)])->orderBy('nome')->get()` → Inertia render 'Servicos/Index' com a coleção
   - `store(Request)`: validate `nome required string max:120`, `valor_padrao required numeric min:0`, `tipo_cobranca required in:mensal,unica`, `ativo boolean`; cria; `return back()->with('success', 'Serviço criado.')`
   - `update(Request, Servico $servico)`: idem; cria → atualiza
   - `destroy(Servico $servico)`:
     - Se `$servico->contratos()->where('ativo', true)->exists()` → desativar via `$servico->update(['ativo' => false])` + `return back()->with('success', 'Serviço desativado (mantido por ter contratos ativos).')`
     - Caso contrário → delete + `return back()->with('success', 'Serviço excluído.')`

7. `CompanyController.php` — adicionar 3 métodos:
   - `storeContrato(Request, Company $company)`: validate `servico_id required exists:servicos,id`, `valor_contratado required numeric min:0`, `data_contratacao required date`, `data_vencimento nullable date after_or_equal:data_contratacao`, `observacoes nullable string max:1000`; cria contrato com `ativo=true` por default; `return back()->with('success', 'Contrato adicionado.')`
   - `updateContrato(Request, Company $company, ContratoServico $contrato)`: aborta 404 se `$contrato->company_id !== $company->id`; valida campos editáveis (`valor_contratado`, `data_contratacao`, `data_vencimento`, `ativo`, `observacoes`); atualiza; `return back()`
   - `destroyContrato(Company $company, ContratoServico $contrato)`: aborta 404 se mismatch; soft-deactivate via `update(['ativo' => false])` (não delete físico — preservar histórico); `return back()`
   - Ajustar `index()` e `show()`: `$company->load(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')])` ou via eager load no query principal

8. `routes/web.php` — dentro do grupo `auth + role:admin`:
   ```php
   Route::resource('servicos', ServicoController::class)->only(['index','store','update','destroy']);
   Route::post('empresas/{company}/contratos-servico', [CompanyController::class, 'storeContrato'])->name('empresas.contratos.store');
   Route::put('empresas/{company}/contratos-servico/{contrato}', [CompanyController::class, 'updateContrato'])->name('empresas.contratos.update');
   Route::delete('empresas/{company}/contratos-servico/{contrato}', [CompanyController::class, 'destroyContrato'])->name('empresas.contratos.destroy');
   ```

**Verify:**
- `php -l` em todos os 6 arquivos PHP novos/alterados → "No syntax errors"
- `php artisan migrate` roda sem erro; `php artisan migrate:rollback --step=2` reverte sem erro
- `php artisan route:list | grep servicos` mostra as 4 rotas + as 3 de contrato
- `php artisan tinker --execute="App\Models\Servico::create(['nome'=>'Teste','valor_padrao'=>100,'tipo_cobranca'=>'mensal'])->delete()"` → sem erro

**Done:** Commit `feat(servicos): cria modelo, controller e rotas — backend foundation (Frente A)` com co-author footer.

---

### Task 2 — Frontend catálogo de serviços + sidebar

**Files:**
- `resources/js/Pages/Servicos/Index.jsx` (novo)
- `resources/js/Layouts/AppLayout.jsx` (alterado — adicionar item 'Serviços' visível só para admin)

**Action:**

1. `Servicos/Index.jsx`:
   - Recebe prop `servicos` (coleção com `contratos_ativos_count`)
   - Layout: AppLayout + DevCard + header com botão "Novo serviço"
   - Tabela colunas: Nome | Valor padrão (`fmtBRL`) | Tipo (badge "Mensal" `bg-ecf-yellow text-black` / "Única" `bg-white/10 text-white/80`) | Ativo (toggle visual — `Switch` se houver, senão checkbox custom; submete PUT inline com `ativo` toggled) | Contratos ativos (texto pequeno, ex: "3 ativos") | Ações (botões editar/excluir)
   - Modal "Novo/Editar serviço" — `useState` controlando aberto + serviço sendo editado
     - Form fields: `nome` (input text), `valor_padrao` (input number step=0.01), `tipo_cobranca` (radio group ou ECFSelect com 2 opções), `ativo` (checkbox, default true só no create)
     - Submit via `useForm`/`router.post|put`, fecha modal no sucesso (via `onSuccess`)
   - Confirmação de exclusão: `if (confirm('Excluir serviço?')) router.delete(...)`
   - Usar `fmtBRL` local ou importar de `@/lib/utils` se existir
   - Ícones Lucide: `Plus`, `Pencil`, `Trash2`, `Power`, `PowerOff`
   - Padrão visual: cores `ecf-*`, `border-white/[0.08]`, `bg-white/[0.03]`, `cn()` para condicionais

2. `AppLayout.jsx`:
   - Adicionar item no sidebar admin (junto com outros itens admin-only), entre algum item natural como "Empresas" e "Fechamento"
   - Visível apenas para `auth.user.role === 'admin'` (replicar padrão existente)
   - Ícone Lucide `Briefcase` (semântica de serviços/contratos)
   - Label: "Serviços"
   - Link: `route('servicos.index')`
   - Active state: replicar padrão do projeto

**Verify:**
- `npm run build` → 0 erros, bundle `Servicos-*.js` criado
- Em runtime (não testar agora, deixar pra UAT): clicar Serviços no sidebar → carrega tabela; criar serviço → aparece na tabela; editar → atualiza; excluir sem contratos → some; excluir com contratos → fica inativo

**Done:** Commit `feat(servicos): catálogo de serviços + entrada no sidebar admin` com co-author footer.

---

### Task 3 — Frontend gestão de contratos na empresa + ajustes na lista de empresas

**Files:**
- `resources/js/Pages/Companies/Show.jsx` (alterado — adicionar seção "Serviços contratados")
- `resources/js/Pages/Companies/Index.jsx` (alterado — remover TACOS e Faturamento 30d, adicionar coluna Serviço)

**Action:**

1. `Companies/Show.jsx`:
   - Recebe prop `company` com `contratosServico` (lista de ativos com `servico` embedado) e `servicos_disponiveis` (lista do catálogo ativo, passada pelo controller)
   - Nova seção "Serviços contratados" entre as seções existentes (decidir posição razoável dentro do layout atual)
   - Toggle "Mostrar inativos" (filtra contratos cuja `ativo=false`)
   - Tabela/cards listando contratos: Serviço (nome) | Valor contratado (`fmtBRL`) | Tipo (badge mesma cor do catálogo) | Início (`fmtDate`) | Vencimento (`fmtDate` ou "—" se null) | Status (Ativo/Inativo) | Ações (Editar/Desativar)
   - Botão "Adicionar contrato" abre modal:
     - Select `servico_id` (opções vindas de `servicos_disponiveis`, label = "Nome — Mensal R$ X,XX")
     - Input `valor_contratado` — `onChange` do select preenche com `valor_padrao` do serviço, mas usuário pode editar
     - Input `data_contratacao` (type=date, required)
     - Input `data_vencimento` (type=date, opcional, com label "Deixe em branco para serviço vigente sem fim")
     - Textarea `observacoes` (opcional)
     - Submete via `router.post(route('empresas.contratos.store', company.id), data, {onSuccess: closeModal})`
   - Editar contrato: mesmo modal, pré-populado, action PUT
   - Desativar contrato: `router.delete(route('empresas.contratos.destroy', [company.id, contrato.id]))` com confirm
   - Para o controller passar `servicos_disponiveis`, ajustar `CompanyController::show` para `Servico::active()->orderBy('nome')->get(['id','nome','valor_padrao','tipo_cobranca'])` na prop

2. `Companies/Index.jsx`:
   - **Remover**: colunas TACOS e Faturamento (30d) — tanto do header quanto das células de cada row
   - **Adicionar**: coluna "Serviço" — header simples; célula renderiza:
     - Se `company.contratos_servico` (ou `contratosServico`) tem itens ativos:
       - Renderizar até 2 badges pequenas com `bg-white/10 border border-white/10 text-white/85 text-[10px] px-1.5 py-0.5 rounded-full`, mostrando `servico.nome`
       - Se >2 contratos, renderizar terceira badge `+N` (onde N = total - 2) com mesma estilização porém `text-white/50`
       - Tooltip ao hover (title attribute ou componente Tooltip do projeto se houver) mostrando `nome — fmtBRL(valor_contratado) — fmtDate(data_contratacao) → fmtDate(data_vencimento) || 'sem vencimento'`
     - Se vazio: traço cinza discreto `<span className="text-white/30">—</span>`
   - O controller (CompanyController::index) já passa `contratosServico` filtrado por ativo=true (configurado em Task 1) — apenas consumir aqui

**Verify:**
- `npm run build` → 0 erros
- Inspeção textual:
  - `Companies/Index.jsx` não tem mais referências a `tacos` nem a `revenue_30d` / `faturamento_30d` (case insensitive)
  - `Companies/Show.jsx` tem a string "Serviços contratados"

**Done:** Commit `feat(servicos): gestão de contratos na empresa + ajusta coluna Serviço na lista` com co-author footer.

---

## Restrições gerais

- Cada Task = 1 commit atômico
- Mensagens em pt-BR, prefixo convencional (`feat(servicos):`)
- Footer co-author: `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`
- Sem deploy, sem push
- Não tocar em: AdminController, ComercialController, MlbController, Financeiro.jsx, EmpresaCadastradaNotification, EnviarRelatorioFechamentoJob
- Após Task 3, executar verificação final: `npm run build` + `php -l` em todos PHP alterados + escrever SUMMARY.md em `.planning/quick/260526-jgj-modulo-servicos-frente-a/260526-jgj-SUMMARY.md` com lista de arquivos, hashes de commits e qualquer ressalva.
