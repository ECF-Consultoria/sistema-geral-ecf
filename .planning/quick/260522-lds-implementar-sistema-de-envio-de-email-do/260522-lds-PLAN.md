---
phase: quick-260522-lds
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_22_100002_create_configuracoes_table.php
  - app/Models/Configuracao.php
  - app/Mail/RelatorioFechamentoMail.php
  - resources/views/emails/relatorio-fechamento.blade.php
  - app/Jobs/EnviarRelatorioFechamentoJob.php
  - app/Http/Controllers/AdminController.php
  - routes/web.php
  - routes/console.php
  - resources/js/Pages/Admin/ConfiguracoesFinanceiro.jsx
  - resources/js/Pages/Admin/Financeiro.jsx
  - .env.example
autonomous: true
requirements:
  - QUICK-EMAIL-RELATORIO
must_haves:
  truths:
    - "Admin pode acessar /administrativo/configuracoes/financeiro e ver a lista atual de emails destinatários"
    - "Admin pode adicionar e remover emails da lista de destinatários, e ao salvar os dados persistem em configuracoes"
    - "Admin pode clicar em 'Enviar relatório geral por email' no dropdown da página Financeiro, que despacha o job e retorna mensagem de sucesso"
    - "O Job EnviarRelatorioFechamentoJob monta os mesmos dados que AdminController::gerarRelatorioGeral() e envia o email via Mailable para todos os destinatários configurados"
    - "Schedule executa EnviarRelatorioFechamentoJob automaticamente todo dia 5 do mês às 09:00"
    - "Rota /configuracoes/financeiro está declarada antes de /financeiro/{company} no grupo admin. para evitar conflito de rota"
  artifacts:
    - path: "database/migrations/2026_05_22_100002_create_configuracoes_table.php"
      provides: "Tabela configuracoes (chave/valor) para armazenar destinatários e metadata de último envio"
      contains: "Schema::create('configuracoes'"
    - path: "app/Models/Configuracao.php"
      provides: "Model Configuracao com helpers estáticos get()/set()"
      contains: "public static function get"
    - path: "app/Mail/RelatorioFechamentoMail.php"
      provides: "Mailable que recebe dados do relatório e renderiza email HTML"
      contains: "class RelatorioFechamentoMail extends Mailable"
    - path: "resources/views/emails/relatorio-fechamento.blade.php"
      provides: "View Blade do email HTML simples (dark/clean) com tabela de relatório"
      min_lines: 30
    - path: "app/Jobs/EnviarRelatorioFechamentoJob.php"
      provides: "Job assíncrono que busca destinatários, monta dados e envia email"
      contains: "class EnviarRelatorioFechamentoJob implements ShouldQueue"
    - path: "resources/js/Pages/Admin/ConfiguracoesFinanceiro.jsx"
      provides: "Página de configuração de destinatários do relatório financeiro"
      min_lines: 80
  key_links:
    - from: "resources/js/Pages/Admin/Financeiro.jsx (GerarRelatoriosBtn)"
      to: "POST /administrativo/financeiro/relatorio-geral/enviar"
      via: "axios.post via route('admin.financeiro.relatorio.enviar')"
      pattern: "admin\\.financeiro\\.relatorio\\.enviar"
    - from: "AdminController::enviarRelatorioGeral()"
      to: "EnviarRelatorioFechamentoJob::dispatch()"
      via: "queue dispatch"
      pattern: "EnviarRelatorioFechamentoJob::dispatch"
    - from: "EnviarRelatorioFechamentoJob::handle()"
      to: "Configuracao::get('email_destinatarios_fechamento')"
      via: "lookup chave/valor"
      pattern: "Configuracao::get\\(['\"]email_destinatarios_fechamento"
    - from: "routes/console.php"
      to: "EnviarRelatorioFechamentoJob"
      via: "Schedule::job(...)->monthlyOn(5, '09:00')"
      pattern: "EnviarRelatorioFechamentoJob.*monthlyOn"
---

<objective>
Implementar sistema completo de envio de email do Relatório Geral de Fechamento — modelo de
configurações chave/valor para armazenar destinatários, Mailable + view Blade do email,
Job assíncrono que reusa a lógica de montagem de dados do `gerarRelatorioGeral()`,
rotas de configuração e disparo manual, scheduler mensal automático no dia 5 às 09:00,
página React de configuração de destinatários e botão de envio integrado ao dropdown
"Gerar relatórios" da página Financeiro.

Purpose: Eliminar a etapa manual de gerar PDF e enviar email — admin centraliza destinatários
em uma tela, envia sob demanda com 1 clique, e o sistema dispara automaticamente todo
mês para garantir consistência do fechamento financeiro.

Output: 11 arquivos criados/modificados cobrindo migration, model, mailable, view,
job, controller methods, rotas, scheduler, página de configuração, modificação no
dropdown da página Financeiro, e variáveis de exemplo SMTP no .env.example.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Http/Controllers/AdminController.php
@routes/web.php
@routes/console.php
@resources/js/Pages/Admin/Financeiro.jsx
@resources/views/admin/relatorio-geral.blade.php

<interfaces>
<!-- Padrões do projeto extraídos do codebase -->

Convenção de model com LogsActivity (vide app/Models/User.php, Company.php):
- use Spatie\Activitylog\Traits\LogsActivity
- use Spatie\Activitylog\LogOptions
- public function getActivitylogOptions(): LogOptions

Convenção de Job (vide app/Jobs/AnalyzeCompanySugadoresJob.php):
- implements ShouldQueue
- use Dispatchable, InteractsWithQueue, Queueable, SerializesModels
- public function handle(): void
- public function failed(\Throwable $e): void { Log::error("[Tag] ..."); }

Convenção de rota admin (routes/web.php linha 253):
- Prefix /administrativo, name admin., middleware ['auth', 'verified', 'role:admin']
- Rota /financeiro/{company} existe na linha 258 — novas rotas DEVEM vir ANTES

Convenção de log (vide CLAUDE.md "Logging"):
- Prefix: "[Fechamento]" para este módulo
- Log::info('[Fechamento] mensagem')

Convenção de validação no controller (vide AdminController::updateEmpresa):
- Validator::make() + return response()->json(['errors' => ...], 422) quando falha JSON
- OU $request->validate([...]) direto quando Inertia + back()->with('success')

Helper de mês (vide AdminController::gerarRelatorioGeral linhas 360-376):
- Carbon::createFromFormat('Y-m', $request->input('mes'))->startOfMonth()
- ucfirst($ref->translatedFormat('F Y')) → "Maio 2026"

Design tokens Tailwind (vide Financeiro.jsx):
- bg-ecf-card, text-ecf-yellow, border-white/[0.08], bg-white/[0.03]
- cn() de @/lib/utils para composição condicional
- Inertia useForm() para forms, router.get/post() para navegação

Página Inertia (vide Financeiro.jsx):
- import AppLayout from '@/Layouts/AppLayout';
- export default function NomeDaPage({ prop1, prop2 }) { return <AppLayout title="X">...</AppLayout> }

Axios disponível globalmente via resources/js/bootstrap.js (window.axios)
- Em componentes pode-se importar: import axios from 'axios';
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Backend — migration, model Configuracao, Mailable + view do email, Job de envio</name>
  <files>
    database/migrations/2026_05_22_100002_create_configuracoes_table.php,
    app/Models/Configuracao.php,
    app/Mail/RelatorioFechamentoMail.php,
    resources/views/emails/relatorio-fechamento.blade.php,
    app/Jobs/EnviarRelatorioFechamentoJob.php
  </files>
  <action>
Criar a fundação backend de configurações + sistema de email.

1) Migration `database/migrations/2026_05_22_100002_create_configuracoes_table.php`:
   - Tabela `configuracoes` com: id (bigIncrements), chave (string unique), valor (text nullable), timestamps.
   - Use a estrutura padrão Laravel: `return new class extends Migration { public function up(): void { Schema::create('configuracoes', function (Blueprint $table) { ... }); } public function down(): void { Schema::dropIfExists('configuracoes'); } };`.
   - Rode `php artisan migrate` ao final da task para criar a tabela.

2) Model `app/Models/Configuracao.php`:
   - Namespace `App\Models`, extends `Model`.
   - `protected $table = 'configuracoes';` (Laravel pluralizaria para `configuracaos`).
   - `protected $fillable = ['chave', 'valor'];`.
   - Helper estático `public static function get(string $chave, $default = null)`: retorna `static::where('chave', $chave)->value('valor') ?? $default`.
   - Helper estático `public static function set(string $chave, $valor): void`: `static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);`.
   - Comentário pt-BR no topo da classe explicando o uso (chave/valor genérico para flags do painel).

3) Mailable `app/Mail/RelatorioFechamentoMail.php`:
   - Criar diretório `app/Mail/` se não existir.
   - Namespace `App\Mail`, extends `Illuminate\Mail\Mailable`.
   - Use `Queueable`, `SerializesModels`.
   - Construtor: `public function __construct(public array $dados)` — apenas $dados (mesLabel, relatorios, totais). Destinatários são passados via `->to()` no Job, não no constructor.
   - Método `public function envelope(): \Illuminate\Mail\Mailables\Envelope` retornando `new Envelope(subject: 'Relatório de Fechamento — ' . ($this->dados['mesLabel'] ?? ''))`.
   - Método `public function content(): \Illuminate\Mail\Mailables\Content` retornando `new Content(view: 'emails.relatorio-fechamento', with: ['dados' => $this->dados])`.

4) View do email `resources/views/emails/relatorio-fechamento.blade.php`:
   - Criar diretório `resources/views/emails/` se não existir.
   - HTML simples e auto-contido (style inline para compatibilidade com clients de email) com tema dark/clean (fundo `#0f1116`, texto `#fff`, accent `#ffe600`).
   - Cabeçalho com título "Relatório de Fechamento — {{ $dados['mesLabel'] }}" e gerado em (agora).
   - Tabela `<table>` com colunas: Empresa, Faturamento, Faixa, Mensalidade, Status (Recebido / Pendente).
   - Itere `@foreach ($dados['relatorios'] as $r)` — cada $r tem chaves `company`, `recebido`, `faturamento`, `faixa_label`, `valor_mensal`, `total_mensalidade`.
   - Formate valores em pt-BR: `R$ {{ number_format($r['valor_mensal'] ?? 0, 2, ',', '.') }}`.
   - Rodapé com totais agregados se disponíveis em `$dados['totais']` (total_mensalidade, total_recebido, total_pendente).
   - Mínimo ~30 linhas. Comentários `{{-- pt-BR --}}`.

5) Job `app/Jobs/EnviarRelatorioFechamentoJob.php`:
   - Namespace `App\Jobs`, implements `ShouldQueue`.
   - Traits: `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`.
   - `public int $timeout = 120; public int $tries = 2;`.
   - Construtor: `public function __construct(public string $mes, public ?int $enviadoPorId = null)` (mês 'Y-m').
   - `public function handle(): void`:
     a) Buscar destinatários: `$json = Configuracao::get('email_destinatarios_fechamento'); $destinatarios = $json ? json_decode($json, true) : [];`. Se vazio, `Log::warning('[Fechamento] Tentativa de envio sem destinatários configurados')` e `return`.
     b) Montar `$dados` REPLICANDO a lógica de `AdminController::gerarRelatorioGeral()` (linhas ~359-470 do controller). Para evitar duplicação, extrair a montagem para um método privado helper (ou injetar/instanciar uma classe utilitária). Como o controller é stateless, o caminho mais simples é COPIAR a lógica de montagem de `$relatorios`, `$mesLabel`, totais para o Job — comentar em pt-BR que é espelho do controller. Não modifique o controller para isso (mantenha self-contained no Job).
     c) Calcular totais agregados: `total_mensalidade = sum($r['total_mensalidade']); total_recebido = sum apenas dos recebidos; total_pendente = total - recebido`.
     d) Enviar: `Mail::to($destinatarios)->send(new RelatorioFechamentoMail(['mesLabel' => $mesLabel, 'relatorios' => $relatorios, 'totais' => [...]]));`.
     e) Persistir metadata: `Configuracao::set('email_ultimo_envio_fechamento', now()->toIso8601String()); Configuracao::set('email_ultimo_envio_fechamento_por', (string) $this->enviadoPorId);`.
     f) `Log::info('[Fechamento] Relatório enviado para ' . count($destinatarios) . ' destinatários (mês ' . $this->mes . ')');`.
   - `public function failed(\Throwable $e): void { Log::error('[Fechamento] Falha no envio: ' . $e->getMessage()); }`.

Não rode npm run build neste task — fica para o task 3 (frontend).
  </action>
  <verify>
    <automated>php artisan migrate:status | grep configuracoes && php -r "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo class_exists('App\Models\Configuracao') && class_exists('App\Mail\RelatorioFechamentoMail') && class_exists('App\Jobs\EnviarRelatorioFechamentoJob') ? 'OK' : 'FAIL';"</automated>
  </verify>
  <done>
    Migration aplicada (tabela `configuracoes` existe). Classes `Configuracao`, `RelatorioFechamentoMail`, `EnviarRelatorioFechamentoJob` são carregáveis via autoload. View `resources/views/emails/relatorio-fechamento.blade.php` existe com tabela de relatório.
  </done>
</task>

<task type="auto">
  <name>Task 2: Rotas, métodos no AdminController e scheduler mensal</name>
  <files>
    routes/web.php,
    routes/console.php,
    app/Http/Controllers/AdminController.php,
    .env.example
  </files>
  <action>
Wirear backend: 3 rotas novas + 3 métodos no controller + 1 schedule + comentário SMTP no .env.example.

1) `routes/web.php` — dentro do grupo `Route::middleware(['auth', 'verified', 'role:admin'])->prefix('administrativo')->name('admin.')->group(...)` (linha ~253), adicione as 3 rotas. CRÍTICO: a rota `/configuracoes/financeiro` (GET e POST) DEVE ser declarada ANTES da rota `/financeiro/{company}` existente (linha 258) para evitar que Laravel interprete `configuracoes` como `{company}`. Posicione assim, logo após `Route::get('/relatorio', ...)` (linha 256) e ANTES de `Route::get('/financeiro', ...)`:

   ```
   Route::get('/configuracoes/financeiro',  [AdminController::class, 'configuracoesFinanceiro'])->name('configuracoes.financeiro');
   Route::post('/configuracoes/financeiro', [AdminController::class, 'salvarConfiguracoesFinanceiro'])->name('configuracoes.financeiro.salvar');
   Route::post('/financeiro/relatorio-geral/enviar', [AdminController::class, 'enviarRelatorioGeral'])->name('financeiro.relatorio.enviar');
   ```

   A terceira rota (`/financeiro/relatorio-geral/enviar`) também deve vir ANTES de `/financeiro/{company}/relatorio` para o mesmo motivo. Coloque-a logo após `Route::get('/financeiro/relatorio-geral', ...)` (linha 260).

2) `app/Http/Controllers/AdminController.php` — adicionar 3 métodos públicos. Adicione no topo: `use App\Jobs\EnviarRelatorioFechamentoJob; use App\Models\Configuracao;`.

   a) `public function configuracoesFinanceiro()`:
      - Buscar destinatários: `$json = Configuracao::get('email_destinatarios_fechamento'); $destinatarios = $json ? json_decode($json, true) : [];`.
      - Buscar último envio: `$ultimoEnvio = Configuracao::get('email_ultimo_envio_fechamento');`.
      - Return `Inertia::render('Admin/ConfiguracoesFinanceiro', ['destinatarios' => $destinatarios, 'ultimo_envio' => $ultimoEnvio]);`.

   b) `public function salvarConfiguracoesFinanceiro(Request $request)`:
      - Validar: `$validated = $request->validate(['destinatarios' => 'array', 'destinatarios.*' => 'email']);`.
      - Salvar: `Configuracao::set('email_destinatarios_fechamento', json_encode($validated['destinatarios'] ?? []));`.
      - Return `back()->with('success', 'Destinatários atualizados com sucesso.');`.

   c) `public function enviarRelatorioGeral(Request $request)`:
      - Validar: `$request->validate(['mes' => 'required|string|regex:/^\d{4}-\d{2}$/']);`.
      - Verificar que existem destinatários configurados: `$json = Configuracao::get('email_destinatarios_fechamento'); $destinatarios = $json ? json_decode($json, true) : []; if (empty($destinatarios)) { return response()->json(['message' => 'Nenhum destinatário configurado.'], 422); }`.
      - Despachar: `EnviarRelatorioFechamentoJob::dispatch($request->input('mes'), auth()->id());`.
      - Return `response()->json(['message' => 'Relatório enviado para ' . count($destinatarios) . ' email(s).']);`.

3) `routes/console.php` — adicionar no final:

   ```
   // Envio automático mensal do relatório de fechamento — dia 5 às 09:00
   Schedule::job(new \App\Jobs\EnviarRelatorioFechamentoJob(now()->format('Y-m'), null))
       ->monthlyOn(5, '09:00')
       ->name('envio-relatorio-fechamento-auto');
   ```

4) `.env.example` — após a linha `MAIL_FROM_NAME="${APP_NAME}"` (linha 60), adicionar bloco de comentário em pt-BR documentando configuração SMTP para Google Workspace (ex: smtp.gmail.com, porta 587, TLS, app password). Não alterar os valores default; apenas adicionar comentário acima ou abaixo do bloco MAIL_ existente. Exemplo:

   ```
   # ── Configuração SMTP para Google Workspace (produção) ─────────────────────
   # Para usar Google Workspace como SMTP, configure:
   #   MAIL_MAILER=smtp
   #   MAIL_HOST=smtp.gmail.com
   #   MAIL_PORT=587
   #   MAIL_USERNAME=seu-email@seudominio.com
   #   MAIL_PASSWORD=app-password-gerado-no-google
   #   MAIL_ENCRYPTION=tls
   #   MAIL_FROM_ADDRESS=seu-email@seudominio.com
   #   MAIL_FROM_NAME="${APP_NAME}"
   ```
  </action>
  <verify>
    <automated>php artisan route:list --columns=method,uri,name 2>&1 | grep -E "configuracoes\.financeiro|financeiro\.relatorio\.enviar" | grep -c "admin\." && php artisan schedule:list 2>&1 | grep -c "envio-relatorio-fechamento-auto"</automated>
  </verify>
  <done>
    3 rotas registradas no Artisan (admin.configuracoes.financeiro GET/POST + admin.financeiro.relatorio.enviar POST). Schedule `envio-relatorio-fechamento-auto` listado em `schedule:list`. `.env.example` contém comentário SMTP Google Workspace.
  </done>
</task>

<task type="auto">
  <name>Task 3: Frontend — página ConfiguracoesFinanceiro.jsx + modificação no dropdown da Financeiro.jsx + build</name>
  <files>
    resources/js/Pages/Admin/ConfiguracoesFinanceiro.jsx,
    resources/js/Pages/Admin/Financeiro.jsx
  </files>
  <action>
Criar a UI de configuração de destinatários e adicionar o botão de envio + link de configuração no dropdown existente "Gerar relatórios".

1) Criar `resources/js/Pages/Admin/ConfiguracoesFinanceiro.jsx`:
   - Imports: `AppLayout`, `useForm` e `Link` de `@inertiajs/react`, `useState` de `react`, ícones `Mail, Plus, X, ArrowLeft` de `lucide-react`, `cn` de `@/lib/utils`.
   - Componente exportado: `export default function ConfiguracoesFinanceiro({ destinatarios, ultimo_envio })`.
   - Estado local: `const { data, setData, post, processing } = useForm({ destinatarios: destinatarios || [] });` e `const [novoEmail, setNovoEmail] = useState('');`.
   - Função `adicionarEmail()`: valida regex de email básico (`/^[^@\s]+@[^@\s]+\.[^@\s]+$/`), checa duplicata, faz `setData('destinatarios', [...data.destinatarios, novoEmail.trim()])`, limpa o input.
   - Função `removerEmail(idx)`: `setData('destinatarios', data.destinatarios.filter((_, i) => i !== idx))`.
   - Função `salvar()`: `post(route('admin.configuracoes.financeiro.salvar'), { preserveScroll: true });`.
   - Layout: `<AppLayout title="Configurações do Financeiro">` envolvendo `<main className="p-6">`.
   - Topo: `<Link href={route('admin.financeiro')}>` com ícone `ArrowLeft` e texto "Voltar para Fechamento" (text-white/40 hover:text-white/70 text-[13px]).
   - Card principal `rounded-xl border border-white/[0.08] bg-white/[0.02] p-6`:
     - Header: ícone Mail + título "Destinatários do Relatório Mensal" (text-xl font-display).
     - Texto descritivo (text-white/40 text-[13px]) explicando: "Os emails listados receberão o Relatório Geral de Fechamento no dia 5 de cada mês às 09:00, e quando enviado manualmente pelo botão na página de Fechamento."
     - Input + botão "Adicionar": `<input type="email" value={novoEmail} onChange={...}>` (mesmo style dos inputs em Financeiro.jsx) + botão amarelo `bg-ecf-yellow/10 text-ecf-yellow` com ícone Plus.
     - Lista de emails: cada item em `flex items-center justify-between` com texto do email + botão X (vermelho) que chama `removerEmail`.
     - Se `data.destinatarios.length === 0`, mostrar placeholder "Nenhum destinatário cadastrado."
     - Botão "Salvar configurações" no final (bg-ecf-yellow text-black font-semibold), `disabled={processing}`.
   - Card secundário (info): texto "Último envio automático: " + (ultimo_envio ? formatar com `new Date(ultimo_envio).toLocaleString('pt-BR')` : 'Nunca').
   - Design 100% consistente com `Financeiro.jsx` (dark, tokens ecf-*, classes white/[0.0X]).
   - Comentários pt-BR.

2) Modificar `resources/js/Pages/Admin/Financeiro.jsx` — apenas o componente `GerarRelatoriosBtn` (linha 607). Não tocar nos outros componentes.
   - Adicionar imports no topo: `import { Link } from '@inertiajs/react';` (verificar se já está, senão adicionar), `import axios from 'axios';`, `import { Mail, Settings, Send } from 'lucide-react';` (mesclar com import existente de lucide-react na linha 4).
   - No `GerarRelatoriosBtn`: adicionar useState para envio: `const [enviando, setEnviando] = useState(false); const [feedback, setFeedback] = useState(null);` (feedback = `{ tipo: 'success' | 'error', msg: string } | null`).
   - Função `enviarPorEmail()`:
     ```
     setEnviando(true); setFeedback(null);
     axios.post(route('admin.financeiro.relatorio.enviar'), { mes: mesSelecionado })
       .then(r => setFeedback({ tipo: 'success', msg: r.data.message }))
       .catch(e => setFeedback({ tipo: 'error', msg: e.response?.data?.message || 'Erro ao enviar relatório.' }))
       .finally(() => setEnviando(false));
     ```
   - Dentro do dropdown panel (após o último `<a>` do `urlGeral('nao')`, ainda dentro da div `absolute right-0 top-full mt-1.5 w-56 ...`), adicionar divider e nova seção:
     - `<div className="px-3 py-2 border-t border-white/[0.04]"><p className="text-[10px] uppercase tracking-widest text-white/30 font-semibold">Enviar por email</p></div>`.
     - Botão de envio (NÃO `<a>`, é `<button>`): on click chama `enviarPorEmail()`, disabled enquanto `enviando`. Mostra ícone `Send` + texto "Enviar relatório geral" + (enviando ? "..." : "").
     - Se `feedback`, mostrar abaixo do botão em `px-3 py-1.5` com cor (`text-emerald-400` se success, `text-red-400` se error) e texto `feedback.msg`.
     - `<Link href={route('admin.configuracoes.financeiro')}>` com ícone `Settings` + texto "Configurar destinatários", classes `flex items-center gap-2 px-3 py-2.5 hover:bg-white/[0.04] transition-colors text-[13px] text-white/60`.
   - O dropdown deve fechar (`setAberto(false)`) ao clicar no link de configurar, mas NÃO ao clicar no botão de enviar (para o usuário ver o feedback).
   - Manter todo o resto do componente intacto.

3) Ao final, rodar `npm run build` (convenção do projeto — vide CLAUDE.md e MEMORY.md).
  </action>
  <verify>
    <automated>npm run build 2>&1 | tail -20 | grep -E "(built in|error)" && test -f resources/js/Pages/Admin/ConfiguracoesFinanceiro.jsx</automated>
  </verify>
  <done>
    `npm run build` completa sem erros. `ConfiguracoesFinanceiro.jsx` existe com formulário de adicionar/remover emails + botão salvar + link de volta. `Financeiro.jsx` tem o dropdown "Gerar relatórios" estendido com nova seção "Enviar por email" (botão de envio com loading/feedback + link "Configurar destinatários" via Inertia Link).
  </done>
</task>

</tasks>

<verification>
Smoke checks manuais (não automatizáveis sem fixtures completas):

1. Acesso à página de configuração: navegar para `/administrativo/configuracoes/financeiro` (autenticado como admin) e ver o formulário renderizado, sem 404.
2. Adicionar 2 emails de teste → clicar Salvar → recarregar página → ver os emails persistidos.
3. Voltar para `/administrativo/financeiro` → abrir dropdown "Gerar relatórios" → ver nova seção "Enviar por email" com botão + link.
4. Clicar "Enviar relatório geral" → ver loading no botão → mensagem de sucesso aparecer com "Relatório enviado para 2 email(s)".
5. Verificar `storage/logs/laravel.log` para linha `[Fechamento] Relatório enviado para 2 destinatários (mês 2026-05)`.
6. Como MAIL_MAILER=log no .env padrão, conferir o conteúdo do email renderizado no laravel.log — tabela com colunas Empresa, Faturamento, Faixa, Mensalidade, Status.
7. Verificar `schedule:list` mostra `envio-relatorio-fechamento-auto` agendado para dia 5 às 09:00.

Verificações automatizadas em cada task já cobrem: tabela criada, classes carregáveis, rotas registradas, schedule ativo, build sem erro.
</verification>

<success_criteria>
- [ ] Tabela `configuracoes` existe e aceita registros chave/valor
- [ ] Model `Configuracao` carregável com helpers `get()` e `set()` funcionais
- [ ] Mailable `RelatorioFechamentoMail` renderiza a view com `mesLabel`, `relatorios`, `totais`
- [ ] View `resources/views/emails/relatorio-fechamento.blade.php` tem tabela HTML dark/clean com colunas corretas
- [ ] Job `EnviarRelatorioFechamentoJob` busca destinatários de Configuracao, monta dados idênticos ao `gerarRelatorioGeral()`, envia email e loga sucesso/falha
- [ ] 3 rotas admin novas: `admin.configuracoes.financeiro` (GET/POST) e `admin.financeiro.relatorio.enviar` (POST) — declaradas ANTES da rota `/financeiro/{company}` para evitar conflito
- [ ] 3 métodos no `AdminController`: `configuracoesFinanceiro`, `salvarConfiguracoesFinanceiro`, `enviarRelatorioGeral`
- [ ] Scheduler em `routes/console.php` dispara o job todo dia 5 às 09:00
- [ ] Página `ConfiguracoesFinanceiro.jsx` renderiza, permite adicionar/remover emails dinamicamente e salvar via Inertia
- [ ] Dropdown "Gerar relatórios" da página Financeiro tem nova seção "Enviar por email" com botão (axios POST + loading + feedback) e link "Configurar destinatários"
- [ ] `.env.example` documenta SMTP Google Workspace via comentário
- [ ] `npm run build` completa sem erros
- [ ] Sem deploy executado (per CLAUDE.md + MEMORY.md)
</success_criteria>

<output>
Create `.planning/quick/260522-lds-implementar-sistema-de-envio-de-email-do/260522-lds-SUMMARY.md` when done
</output>
