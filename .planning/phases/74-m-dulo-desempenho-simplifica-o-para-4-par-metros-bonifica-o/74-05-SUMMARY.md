---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 05
subsystem: desempenho
tags: [controller, formrequest, rotas, admin, sidebar, config]
requires:
  - Plan 74-02 (BonusFaixa Model + seed 4 faixas)
provides:
  - `App\Http\Controllers\DesempenhoConfigController` (index/updateFaixa/toggleActive)
  - `App\Http\Requests\UpdateBonusFaixaRequest` com validação de sobreposição e range
  - 3 rotas admin (`desempenho.configuracao.*`) sob middleware `role:admin`
  - Item na sidebar "Configuração Desempenho" gated por admin-only
affects:
  - Plan 74-07 (Desempenho/Configuracao.jsx — página React consumindo estas rotas)
  - Plan 74-08 (Manual/Artigos/DesempenhoBonificacao.jsx — artigo dinâmico paralelo)
tech-stack:
  added: []
  patterns:
    - Controller admin com `Inertia::render` + Model binding no método (padrão NpsTemplateController)
    - `FormRequest::authorize()` como camada dupla de defesa junto ao middleware `role:admin`
    - `withValidator` com `after` hook para regras compostas (padrão UpdateNpsTemplateRequest)
    - Algoritmo canônico de sobreposição de intervalos fechados (`a1 <= b2 AND a2 >= b1`)
    - Route model binding implícito em `{faixa}` → `App\Models\BonusFaixa`
    - Item sidebar com `excludeRoles` para admin-only (mesmo padrão de "Configuração NPS")
key-files:
  created:
    - app/Http/Controllers/DesempenhoConfigController.php
    - app/Http/Requests/UpdateBonusFaixaRequest.php
  modified:
    - routes/web.php (3 rotas admin adicionadas no grupo role:admin existente)
    - resources/js/Layouts/AppLayout.jsx (1 item na sidebar dentro do grupo Mercado Livre)
decisions:
  - D-10 · Rota admin `/desempenho/configuracao` seguindo padrão `/nps/configuracao`
  - D-11 · Controller `DesempenhoConfigController` (namespace `App\Http\Controllers`)
  - D-13 · Validação: range [0,5], min<max (exceto slug=maximo), sem sobreposição entre faixas ativas
  - D-21 · Item sidebar "Configuração Desempenho" gated por `role:admin` (via `excludeRoles`)
metrics:
  duration: 20min
  completed: 2026-07-09
  tasks: 3
  files: 4
  commits: [7a16ce6, 24f8b2b, fdd8e4e]
---

# Phase 74 Plan 05: Backend admin da UI de configuração das faixas de bônus

Fecha o requisito DESEMP-12 entregando o backend completo da UI admin de configuração da régua de bonificação: Controller, FormRequest com validação anti-sobreposição, 3 rotas REST admin-only e item de sidebar. Destrava a Plan 74-07 (frontend React) e a Plan 74-08 (artigo dinâmico do Manual sincronizado com a mesma tabela).

## O que foi feito

### Task 1 — Controller + FormRequest (commit `7a16ce6`)

**`app/Http/Controllers/DesempenhoConfigController.php`:**

- Namespace `App\Http\Controllers` (extends `Controller` base). Sem constructor — model binding no método.
- **`index()`** — retorna `Inertia::render('Desempenho/Configuracao', ['faixas' => ...])` com `BonusFaixa::orderBy('ordem')->orderBy('nota_min')->get()` (INCLUI inativas para admin poder reativar).
- **`updateFaixa(UpdateBonusFaixaRequest $request, BonusFaixa $faixa)`** — `$faixa->update($request->validated())` + `back()->with('success', "Faixa \"{$faixa->nome}\" atualizada com sucesso.")` (pt-BR).
- **`toggleActive(BonusFaixa $faixa)`** — flip `ativo`. Antes de persistir, se está desativando: `Log::warning('[Desempenho Config] Faixa desativada — cobertura pode ficar incompleta', ...)` para auditoria pós-fato (activity_log já cobre a mudança da row). Retorna `back()->with('success', ...)` com mensagem contextual pt-BR ("reativada" vs "desativada").
- Helper privado `currentUserId()` — id do user autenticado (só usado no log).
- Docblock pt-BR cita Phase 74 D-10/D-11 + SPEC DESEMP-12.

**`app/Http/Requests/UpdateBonusFaixaRequest.php`:**

- `authorize()`: `return $this->user()?->isAdmin() === true;` (defesa em profundidade).
- `rules()`:
  - `nome` required|string|max:100
  - `descricao` nullable|string|max:2000
  - `nota_min` required|numeric|between:0,5
  - `nota_max` required|numeric|between:0,5|gte:nota_min
  - `ordem` required|integer|min:0
- `withValidator(Validator $v)` com `after` hook:
  - **Regra 1** — `nota_min < nota_max` EXCETO quando `$this->route('faixa')?->slug === 'maximo'` (permite igualdade `5.00 == 5.00` para a faixa `maximo`, invariante do seed D-16).
  - **Regra 2** — sem sobreposição contra outras faixas `ativo=true`: algoritmo canônico de intervalos fechados (`novoMin <= outraMax AND novoMax >= outraMin`). Mensagem inline em pt-BR cita nome e range da faixa que sobrepõe. Para no primeiro conflito encontrado (uma mensagem já basta).
- `messages()` retorna array pt-BR completo (required, string, max, between, gte, integer, min).
- Docblock pt-BR cita Phase 74 D-13 + lista as regras.

### Task 2 — Registrar 3 rotas em routes/web.php (commit `24f8b2b`)

Adicionadas dentro do grupo `Route::middleware(['auth', 'verified', 'role:admin'])->group(...)` existente (o mesmo que já protege `nps.configuracao.*`) — imediatamente antes do fechamento `});`:

```php
Route::get   ('/desempenho/configuracao',
    [\App\Http\Controllers\DesempenhoConfigController::class, 'index'])
    ->name('desempenho.configuracao.index');
Route::patch ('/desempenho/configuracao/faixas/{faixa}',
    [\App\Http\Controllers\DesempenhoConfigController::class, 'updateFaixa'])
    ->name('desempenho.configuracao.faixas.update');
Route::patch ('/desempenho/configuracao/faixas/{faixa}/toggle-active',
    [\App\Http\Controllers\DesempenhoConfigController::class, 'toggleActive'])
    ->name('desempenho.configuracao.faixas.toggle');
```

Route model binding em `{faixa}` resolve `App\Models\BonusFaixa` implicitamente.

Comentário pt-BR cita Phase 74 D-10/D-12.

**Validação executada** (`php artisan route:list --name=desempenho.configuracao -v`):

```
GET|HEAD  desempenho/configuracao                                 desempenho.configuracao.index      → DesempenhoConfigController@index
          ⇂ web / Authenticate / EnsureEmailIsVerified / EnsureUserHasRole:admin
PATCH     desempenho/configuracao/faixas/{faixa}                  desempenho.configuracao.faixas.update
          ⇂ web / Authenticate / EnsureEmailIsVerified / EnsureUserHasRole:admin
PATCH     desempenho/configuracao/faixas/{faixa}/toggle-active    desempenho.configuracao.faixas.toggle
          ⇂ web / Authenticate / EnsureEmailIsVerified / EnsureUserHasRole:admin
```

Ver todas 3 rotas listadas com middleware `EnsureUserHasRole:admin`.

### Task 3 — Item na sidebar AppLayout.jsx (commit `fdd8e4e`)

Editado `resources/js/Layouts/AppLayout.jsx` — item novo inserido IMEDIATAMENTE após o item existente `{ label: 'Desempenho', routeName: 'performance.index', ... }` dentro dos children do grupo "Mercado Livre":

```jsx
{ label: 'Configuração Desempenho', routeName: 'desempenho.configuracao.index', page: 'Desempenho/Configuracao', icon: SlidersHorizontal, excludeRoles: ['consultor', 'mentor', 'publicador', 'analista', 'gestor', 'lider'] },
```

- `excludeRoles` esconde para todos os papeis não-admin (mesmo padrão do "Configuração NPS" existente).
- `SlidersHorizontal` já estava importado no top do arquivo (linha 7 do lucide-react import).
- Comentário pt-BR cita D-21.

`npm run build` NÃO rodado nesta task (convenção do projeto é rodar build na wave frontend — Plan 74-07).

## Decisões implementadas

- **D-10** · Rota admin `/desempenho/configuracao` seguindo padrão `/nps/configuracao`.
- **D-11** · Controller `DesempenhoConfigController` com métodos `index`, `updateFaixa`, `toggleActive`. Não implementei `createFaixa` (o CONTEXT.md sugere 4 métodos incluindo `createFaixa` mas o SPEC-12 acceptance criteria só menciona edit inline + validação de sobreposição — criação de novas faixas fica para Plan 74-07 se a UI decidir expor, sem lock aqui).
- **D-13** · Validação completa: range [0,5], `nota_min < nota_max` (exceto slug=maximo), sem sobreposição entre faixas `ativo=true`.
- **D-21** · Item sidebar "Configuração Desempenho" no grupo Desempenho (na prática dentro do grupo Mercado Livre da estrutura atual do AppLayout), gated por `role:admin` via `excludeRoles`.

## Deviations from Plan

**Rule 2 — Adição de correctness/security check.** No `toggleActive`, adicionei `Log::warning` estruturado quando o admin está DESATIVANDO uma faixa. Motivo: se a régua ficar com gap (faixa desativada cobria [3.00, 4.00]), o `classificarFaixa` do Service (Plan 74-03) retornaria `null` para notas nesse intervalo — sem log, o debug pós-fato seria caro. O plano não pedia esse log explicitamente, mas o CONTEXT (D-11) menciona "Log::warning" e a spec DESEMP-12 exige que a UX de config seja segura (auditoria). Não bloqueia a operação (admin sabe o que faz).

**Método `createFaixa` NÃO implementado.** O CONTEXT §D-11 lista 4 métodos, mas o `<interfaces>` do 74-05-PLAN e o SPEC DESEMP-12 acceptance só cobrem 3 (index/update/toggle). A criação de novas faixas (além das 4 seed) é edge case que a UI React do Plan 74-07 pode ou não decidir expor — deixar de fora aqui evita over-engineering. Se o Plan 74-07 precisar, adiciona-se o método + rota POST no futuro sem quebrar nada.

## Success Criteria

- [x] Ambos arquivos existem e passam `php -l` (`No syntax errors detected`).
- [x] Controller tem métodos `index()`, `updateFaixa()`, `toggleActive()`.
- [x] FormRequest tem `authorize()`, `rules()`, `withValidator()`, `messages()`.
- [x] `grep -q "isAdmin()" app/Http/Requests/UpdateBonusFaixaRequest.php` retorna 0 (encontrado).
- [x] `grep -q "Sobreposição" app/Http/Requests/UpdateBonusFaixaRequest.php` retorna 0 (encontrado).
- [x] `grep -q "BonusFaixa" app/Http/Controllers/DesempenhoConfigController.php` retorna 0 (encontrado).
- [x] `grep -q "Inertia::render.*Desempenho/Configuracao" app/Http/Controllers/DesempenhoConfigController.php` retorna 0 (encontrado).
- [x] Mensagens de validação em pt-BR (nenhuma em inglês default).
- [x] `php artisan route:list --name=desempenho.configuracao` mostra 3 rotas.
- [x] Cada rota lista middleware `EnsureUserHasRole:admin`.
- [x] PATCH corretamente aplicado aos 2 endpoints de edição.
- [x] Bloco de rotas dentro do grupo `role:admin` existente (não criou grupo novo).
- [x] Comentário pt-BR cita Phase 74 D-10/D-12.
- [x] `grep -c "desempenho.configuracao.index" resources/js/Layouts/AppLayout.jsx` retorna 1.
- [x] Item aparece IMEDIATAMENTE após `performance.index` (linha 70, logo após linha 65).
- [x] `SlidersHorizontal` importado do lucide-react (já estava no import do topo).

## Threat Flags

Nenhum novo. Superfície adicionada:
- 3 rotas `role:admin` — mesma trust boundary do resto da UI admin (Envio automático NPS, Config NPS, Setores). Sem novo endpoint público.
- CSRF protegido pelo grupo padrão `web` (não isento).
- Validação anti-sobreposição no FormRequest evita que admin corrompa a régua acidentalmente — melhora correctness da engine v2 (Plan 74-03).

## Links

- SPEC: `.planning/phases/74-.../74-SPEC.md` DESEMP-12
- CONTEXT decisões: `.planning/phases/74-.../74-CONTEXT.md` §D-10, D-11, D-13, D-21
- Consumidor imediato: `.planning/phases/74-.../74-07-PLAN.md` (Desempenho/Configuracao.jsx)

## Self-Check: PASSED

- FOUND: `app/Http/Controllers/DesempenhoConfigController.php`
- FOUND: `app/Http/Requests/UpdateBonusFaixaRequest.php`
- FOUND commit `7a16ce6` (Task 1 — Controller + FormRequest)
- FOUND commit `24f8b2b` (Task 2 — 3 rotas admin)
- FOUND commit `fdd8e4e` (Task 3 — item na sidebar)
- FOUND route `desempenho.configuracao.index` (GET) via `artisan route:list`
- FOUND route `desempenho.configuracao.faixas.update` (PATCH) via `artisan route:list`
- FOUND route `desempenho.configuracao.faixas.toggle` (PATCH) via `artisan route:list`
