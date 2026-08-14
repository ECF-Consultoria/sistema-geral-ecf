# Fase 131: Tela administrativa — mapa de padrões

**Mapeado:** 2026-08-14
**Arquivos analisados:** 13 novos/modificados (+ 2 reusados sem alteração)
**Análogos encontrados:** 12 / 13

> Nomes de rota/arquivo do UI-SPEC (`Admin/Contratos.jsx`, `Admin/ContratoDetalhe.jsx`,
> `ContratoAdminController`) são propostas do RESEARCH — o planejador pode ajustar, a divisão em
> duas telas (D-01) é que é contrato travado.

---

## File Classification

| Novo/Modificado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade |
|---|---|---|---|---|
| `app/Http/Controllers/ContratoAdminController.php` (novo) | controller | request-response (CRUD + ações externas) | `app/Http/Controllers/ContratoLiberacaoManualController.php` | exato (mesmo domínio, absorve D-10) |
| `routes/web.php` (grupo `admin/contratos`, novo) | route | request-response | `routes/web.php:639-654` (`permission:core.empresas` / `permission:shopee.empresas`) | exato — **NÃO** o grupo `role:admin` de `routes/web.php:1021` |
| `app/Support/Permissions.php` (editar) | config | — | Bloco `ADMIN_*` já existente (linhas 68-71, 168-172) | exato |
| `resources/js/Layouts/AppLayout.jsx` (editar, ~linha 269-273) | config/menu | — | Item `admin.empresas` no mesmo grupo "Administrativo" | exato |
| `database/migrations/..._add_cancelamento_solicitado_to_contrato_assinaturas_table.php` (novo, CLICK-10) | migration | batch (DDL aditivo) | `2026_08_15_100000_add_motivo_slug_to_contrato_liberacoes_table.php` + `2026_08_15_100001_add_ultimo_alerta_em_to_contrato_assinaturas_table.php` | exato (mesmíssimo padrão: string nullable, sem índice, sem FK) |
| `resources/js/Pages/Admin/Contratos.jsx` (novo) | component (página lista) | request-response + filtro client-driven | `resources/js/Pages/Comercial/EmpresasListagem.jsx` (grid de resumo/filtro) + `resources/js/Pages/Admin/Empresas.jsx` (família visual `Admin/`) | role-match forte |
| `resources/js/Pages/Admin/ContratoDetalhe.jsx` (novo) | component (página detalhe + ações) | request-response + POST de ação | Nenhuma página `Admin/*.jsx` existente tem "formulário + várias ações + modais" juntos — ver seção "Sem análogo" | parcial |
| Badge em `resources/js/Pages/Comercial/EmpresasListagem.jsx` (editar) | component (célula de tabela) | transform (map de classe por chave) | `PENDENCIAS_CLS`/`SETOR_CLS` no mesmo arquivo (linhas 43-51, 77-81) | exato |
| `app/Http/Controllers/ComercialController.php::listagem()` (editar, badge em lote) | controller (query) | CRUD + agregação em lote | O próprio método, linhas 180-310 (eager load + cálculo em PHP, sem N+1) | exato — é o análogo E o arquivo a editar |
| `resources/js/Pages/Comercial/ContratosLiberacaoManual` — **REMOVIDO** | — | — | — | N/A (deleção, D-10) |
| `routes/web.php` (rotas antigas `contratos.liberacao-manual.*`) — **REMOVIDAS** | route | — | `routes/web.php:112-117` (a rota que está saindo) | N/A (deleção, D-10) |
| `tests/Feature/Phase131/*.php` (10 arquivos, ver lista) | test | Feature/Inertia | `tests/Feature/Phase130/LiberacaoManualTest.php` + `tests/Feature/Phase126/ClicksignClientEnvelopeTest.php` | exato |

---

## Pattern Assignments

### `app/Http/Controllers/ContratoAdminController.php` (controller, request-response)

**Análogo:** `app/Http/Controllers/ContratoLiberacaoManualController.php` (arquivo inteiro lido, 125 linhas)

**Estrutura de classe / imports** (linhas 1-15):
```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use App\Services\Contratos\ContratosPresosService;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
```
Para o controller novo, acrescentar `ContratoDadosMinimosService`, `ClicksignClient`,
`ClicksignException` (para tratar 429 no CLICK-07).

**Padrão de listagem — array achatado, nunca o model inteiro** (linhas 51-75):
```php
public function index(ContratosPresosService $presos): \Inertia\Response
{
    $contratos = $presos->listar()->map(function (ContratoAssinatura $c) use ($presos) {
        // Array ACHATADO — nunca o model inteiro nem dados de
        // signatário (e-mail/CPF) atravessam para o browser (T-130-04-05).
        return [
            'id'            => $c->id,
            'company_id'    => $c->company_id,
            'company_nome'  => $c->company?->name,
            'servico_id'    => $c->servico_id,
            'servico_nome'  => $c->servico?->nome,
            'status'        => $c->status,
            'causa'         => $presos->causa($c),
            'dias_parado'   => $presos->diasParado($c),
            'enviado_em'    => $c->enviado_em?->toIso8601String(),
            'assinado_em'   => $c->assinado_em?->toIso8601String(),
        ];
    })->values();

    return Inertia::render('Admin/ContratosLiberacaoManual', [
        'contratos' => $contratos,
        'motivos'   => ContratoLiberacao::MOTIVOS_MANUAIS_LABELS,
    ]);
}
```
⚠️ Para `Admin/Contratos.jsx` (UI-01), a fonte de dados é diferente: `ContratosPresosService::listar()`
**filtra por "preso"** (D-05 do 130-CONTEXT), o que esconde `assinado`/`cancelado` sem atraso e
qualquer contrato dentro do limiar. UI-01 precisa dos **7 estados sempre**, com resumo de 7
contagens — usar `ContratoAssinatura::whereIn('status', ContratoAssinatura::STATUS_TODOS)` direto
(ver Pattern 3 do RESEARCH, "N+1 do badge" abaixo) e aplicar `dataBase()`/`causa()`/`diasParado()`
em memória sobre essa coleção, **não** `listar()`.

**Validação + IDOR guard + delegação a service** (linhas 81-123, preservar literalmente na absorção D-10):
```php
public function store(Request $request, EmpresaOperacionalRouter $router): RedirectResponse
{
    $data = $request->validate([
        'company_id'             => ['required', 'integer', 'exists:companies,id'],
        'servico_id'             => ['required', 'integer', 'exists:servicos,id'],
        'contrato_assinatura_id' => ['nullable', 'integer', 'exists:contrato_assinaturas,id'],
        // D-12 — slug é lista fechada, nunca string livre.
        'motivo_slug'            => ['required', Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)],
        // D-12 — o detalhe é obrigatório MESMO com o slug preenchido.
        'motivo_detalhe'         => ['required', 'string', 'min:5', 'max:1000'],
    ]);

    $company = Company::findOrFail($data['company_id']);
    $servico = Servico::findOrFail($data['servico_id']);

    $contrato = null;
    if (! empty($data['contrato_assinatura_id'])) {
        $contrato = ContratoAssinatura::findOrFail($data['contrato_assinatura_id']);

        // T-130-04-03 (IDOR) — o contrato informado tem que pertencer à
        // MESMA empresa/serviço do POST, senão a liberação de uma
        // empresa ficaria amarrada à evidência de outra.
        if ($contrato->company_id !== $company->id || $contrato->servico_id !== $servico->id) {
            abort(422, 'O contrato informado não pertence a esta empresa/serviço.');
        }
    }

    $router->liberarEmpresa(
        $company, $servico, ContratoLiberacao::VIA_MANUAL,
        contrato: $contrato,
        liberadoPorUserId: $request->user()->id,
        motivo: $data['motivo_detalhe'],
        motivoSlug: $data['motivo_slug'],
    );

    return back()->with('success', 'Empresa liberada para o operacional.');
}
```
Esta é a **action** `liberarManual()` da tela nova (D-10) — só o `Inertia::render()`/redirect de
destino muda (não mais `Admin/ContratosLiberacaoManual`, e sim de volta ao
`admin.contratos.show`).

**Padrão de prop `faltantes`/`pode_gerar_contrato` para o detalhe** (Pattern 2 do RESEARCH,
`131-RESEARCH.md` linhas 350-361):
```php
return Inertia::render('Admin/ContratoDetalhe', [
    'company'             => [...],
    'faltantes'           => app(ContratoDadosMinimosService::class)->faltantes($company),
    'pode_gerar_contrato' => app(ContratoDadosMinimosService::class)->estaPronta($company),
]);
```
⚠️ `email_colaborador` (D-11) **não passa** por `faltantes()` — validar/exibir como campo à parte
na mesma prop `company`, nunca dentro do array de pendências.

**Reenvio de notificação (CLICK-07) — tratar 429 como esperado**, do próprio RESEARCH
(`131-RESEARCH.md` linhas 452-464), a chamar dentro de uma nova action `reenviar()`:
```php
try {
    app(ClicksignClient::class)->reenviarNotificacao($envelopeId, $signatario->clicksign_signer_key);
    // sucesso: toast de confirmação
} catch (ClicksignException $e) {
    if ($e->httpStatus === 429) {
        // NÃO é erro — UI-SPEC: "Aguarde um pouco antes de reenviar", estilo âmbar/neutro
    } else {
        // erro real — mostrar mensagem genérica, nunca a resposta crua da Clicksign
    }
}
```

**Erro handling geral:** este projeto usa `abort(403/422, 'mensagem')` para falhas de
autorização/validação (nunca lança exceção manual), `back()->with('success', ...)` para sucesso de
formulário Inertia, e `Rule::in()` para toda lista fechada nova — replicar os três na action nova
de "registrar cancelamento" (CLICK-10/D-13): validar `motivo` (min:10), gravar autor+data+motivo,
`return back()->with('success', 'Cancelamento registrado. Agora conclua no painel da Clicksign.')`.

---

### `routes/web.php` — grupo `admin/contratos` (route, request-response)

**Análogo correto:** `routes/web.php:639-654` (padrão `permission:` dedicado)
```php
// (antes role:admin). Lider de Performance ganha a permission via
// AUTO_LIDERANCA_PERFORMANCE; admin tem implicito.
// Mutations (PUT/DELETE/POST) ficam no grupo role:admin abaixo.
Route::middleware('permission:core.empresas')->group(function () {
    Route::get('/companies',            [CompanyController::class, 'index'])->name('companies.index');
});

// ─── Shopee · Empresas (Phase 75 Plan 75-04 — DEC-4) ─────────────────────
// Aba enxuta das empresas atendidas na Shopee (habilita NPS). Gate DEDICADO
// permission:shopee.empresas — NUNCA core.empresas (T-75-09 EoP).
Route::middleware('permission:shopee.empresas')->group(function () {
    Route::get('/shopee/empresas',                  [ShopeeEmpresasController::class, 'index'])->name('shopee.empresas.index');
    Route::post('/shopee/empresas/bulk-assign',     [ShopeeEmpresasController::class, 'bulkAssign'])->name('shopee.empresas.bulk-assign');
    Route::post('/shopee/empresas/resolver',        [ShopeeEmpresasController::class, 'resolver'])->name('shopee.empresas.resolver');
    Route::post('/shopee/empresas/cancelar-servico', [ShopeeEmpresasController::class, 'cancelarServico'])->name('shopee.empresas.cancelar-servico');
});
```

⚠️ **Molde a NÃO copiar** — `routes/web.php:1021-1058`, o grupo `/administrativo` inteiro (onde
vivem `admin.empresas`/`admin.relatorio`/`admin.financeiro`/`admin.inventario`) roda sob
`role:admin`, **não** `permission:admin.empresas` apesar de a permission existir no catálogo:
```php
// ─── Módulo Administrativo ───────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('administrativo')->name('admin.')->group(function () {
    Route::get('/empresas',              [AdminController::class, 'empresas'])->name('empresas');
    ...
});
```
Se as rotas de `admin.contratos`/`admin.contratos.show` forem coladas aqui "porque é onde moram as
outras telas Admin", a UI-05 (permissão própria, refinável na tela de setores sem deploy) fica
inoperante: `permission:admin.contratos` concedida via `setores.permissoes.sync` a um não-admin
continuaria batendo 403, porque o `role:admin` do grupo bloqueia primeiro.

**Rota isolada que está sendo absorvida (D-10) — remover, não migrar** (`routes/web.php:107-117`):
```php
// ─── Liberação manual da rede de segurança (Fase 130 Plano 04, REDE-03/DADOS-05) ──
Route::get('/admin/contratos/liberacao-manual', [\App\Http\Controllers\ContratoLiberacaoManualController::class, 'index'])
    ->middleware(['auth', 'verified', 'role:admin'])
    ->name('contratos.liberacao-manual.index');
Route::post('/admin/contratos/liberacao-manual', [\App\Http\Controllers\ContratoLiberacaoManualController::class, 'store'])
    ->middleware(['auth', 'verified', 'role:admin'])
    ->name('contratos.liberacao-manual.store');
```
D-10 exige que este par de rotas **saia** do `web.php` (teste dedicado
`LiberacaoManualRotaAntigaRemovidaTest` espera 404, não redirect).

**Rota irmã que fica INTOCADA** (não faz parte da absorção, `routes/web.php:98-105`):
```php
Route::get('/admin/contratos/{contratoAssinatura}/pdf-assinado', [\App\Http\Controllers\ContratoPdfAssinadoController::class, 'download'])
    ->middleware(['auth', 'role:admin'])
    ->name('contratos.pdf-assinado');
```
Continua `role:admin` — não está no escopo da D-10/UI-05 desta fase (o download de PDF é outra
funcionalidade, não citada nas decisões).

---

### `app/Support/Permissions.php` (config)

**Análogo:** o próprio arquivo, bloco `ADMIN_*` (linhas 68-71 e 168-172).
```php
public const ADMIN_EMPRESAS            = 'admin.empresas';
public const ADMIN_RELATORIO           = 'admin.relatorio';
public const ADMIN_FINANCEIRO          = 'admin.financeiro';
public const ADMIN_INVENTARIO          = 'admin.inventario';
```
```php
'Administrativo' => [
    ['key' => self::ADMIN_EMPRESAS,    'label' => 'Adm · Empresas',    'description' => 'Cadastro administrativo de empresas'],
    ['key' => self::ADMIN_RELATORIO,   'label' => 'Adm · Relatório',   'description' => 'Relatórios administrativos'],
    ['key' => self::ADMIN_FINANCEIRO,  'label' => 'Adm · Fechamento',  'description' => 'Fechamento financeiro mensal'],
    ['key' => self::ADMIN_INVENTARIO,  'label' => 'Adm · Inventário',  'description' => 'Inventário de ativos'],
],
```
Adicionar `public const ADMIN_CONTRATOS = 'admin.contratos';` junto às outras `ADMIN_*` e uma
entrada `['key' => self::ADMIN_CONTRATOS, 'label' => 'Adm · Contratos', 'description' => '...']`
no mesmo grupo `'Administrativo'`.

**Por que D-09 não precisa de migration/seeder** — `app/Models/User.php:193-198`:
```php
public function hasPermission(string $key): bool
{
    if ($this->isAdmin()) return true; // short-circuit superuser

    return \in_array($key, $this->effectivePermissions(), true);
}
```
Qualquer permission key nova, só de existir no catálogo, já é `true` para `role:admin` — nenhum
dado a gravar em `setor_permissoes` no dia do deploy.

---

### `resources/js/Layouts/AppLayout.jsx` (config/menu)

**Análogo:** grupo "Administrativo" completo, `resources/js/Layouts/AppLayout.jsx:262-274`:
```jsx
{
    group: 'Administrativo',
    icon: Shield,
    children: [
        { label: 'Empresas',   routeName: 'admin.empresas',   page: 'Admin/Empresas',   icon: Building2,    permission: 'admin.empresas' },
        { label: 'Relatório',  routeName: 'admin.relatorio',  page: 'Admin/Relatorio',  icon: FileBarChart, permission: 'admin.relatorio' },
        { label: 'Fechamento', routeName: 'admin.financeiro', page: 'Admin/Financeiro', icon: Banknote,     permission: 'admin.financeiro' },
        { label: 'Inventário', routeName: 'admin.inventario', page: 'Admin/Inventario', icon: Package2,     permission: 'admin.inventario' },
    ],
},
```
Acrescentar uma linha nesse mesmo array (`{ label: 'Contratos', routeName: 'admin.contratos.index',
page: 'Admin/Contratos', icon: FileSignature, permission: 'admin.contratos' }` — `FileSignature` já
é o ícone sugerido pelo UI-SPEC). `icon` precisa de import novo em `lucide-react` no topo do
arquivo se `FileSignature` ainda não estiver importado (conferir antes de assumir).

---

### `database/migrations/..._add_cancelamento_solicitado_to_contrato_assinaturas_table.php` (migration, CLICK-10/D-13)

**Análogos (ambos lidos por inteiro, mesmo padrão, escolher qualquer um como molde):**

`database/migrations/2026_08_15_100001_add_ultimo_alerta_em_to_contrato_assinaturas_table.php`
(MESMA tabela alvo — `contrato_assinaturas` — o molde mais direto):
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (! Schema::hasColumn('contrato_assinaturas', 'ultimo_alerta_em')) {
                $table->timestamp('ultimo_alerta_em')->nullable()->after('pdf_assinado_erro');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (Schema::hasColumn('contrato_assinaturas', 'ultimo_alerta_em')) {
                $table->dropColumn('ultimo_alerta_em');
            }
        });
    }
};
```

`database/migrations/2026_08_15_100000_add_motivo_slug_to_contrato_liberacoes_table.php`
(molde para a PARELHA slug+texto, se D-13 quiser categorizar o motivo do cancelamento depois):
```php
Schema::table('contrato_liberacoes', function (Blueprint $table) {
    if (! Schema::hasColumn('contrato_liberacoes', 'motivo_slug')) {
        $table->string('motivo_slug', 40)->nullable()->after('motivo');
    }
});
```

Colunas sugeridas para a migration nova (D-13: "provavelmente exige coluna(s) nova(s) para
motivo/autor/data"):
```php
$table->text('cancelamento_motivo')->nullable()->after('...');
$table->foreignId('cancelamento_solicitado_por_user_id')->nullable()->after('cancelamento_motivo');
$table->timestamp('cancelamento_solicitado_em')->nullable()->after('cancelamento_solicitado_por_user_id');
```
⚠️ Se `cancelamento_solicitado_por_user_id` virar `->constrained('users')->nullOnDelete()`, a
coluna **precisa** de `->nullable()` (armadilha de MariaDB, ver seção dedicada abaixo) — os dois
análogos lidos acima **não** têm FK, então não servem de exemplo para essa parte; usar o padrão de
`created_by`/`comentario_autor_id` da seção de armadilhas.

**Docblock obrigatório (convenção do projeto, replicar a estrutura):**
```php
// pt-BR: Migration ADITIVA — Fase 131 (D-13 do CONTEXT).
//
// [explicar o que a coluna guarda e por que não existe hoje]
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (ver <pitfalls> do
// 130-CONTEXT.md/131-CONTEXT.md) — declarar explicitamente quais se aplicam
// e quais não, como as duas migrations-análogo fazem.
```

---

### `resources/js/Pages/Admin/Contratos.jsx` (component, página lista)

**Análogo primário — grid de resumo clicável como filtro:** `resources/js/Pages/Comercial/EmpresasListagem.jsx:614-638`
```jsx
{/* 8 cards de pendência comercial (clicáveis) — 5 atuais + 3 novas (114-02) */}
<div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2">
    {Object.entries(PENDENCIAS_LABELS).map(([key, label]) => {
        const active = filters.pendencia === key;
        const count = pendencia_counts?.[key] ?? 0;
        return (
            <button
                key={key}
                onClick={() => applyFilter('pendencia', active ? null : key)}
                className={cn(
                    'rounded-xl border px-3 py-3 text-left transition-colors',
                    active
                        ? 'border-ecf-yellow bg-ecf-yellow/[0.06]'
                        : 'border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.04]'
                )}
            >
                <div className="flex items-center justify-between">
                    <div className="text-2xl font-bold tabular-nums text-white">{count}</div>
                    <AlertCircle size={14} className={active ? 'text-ecf-yellow' : 'text-white/30'} />
                </div>
                <div className="text-[12px] text-white/60 mt-0.5">{label}</div>
            </button>
        );
    })}
</div>
```
⚠️ UI-SPEC já avisa: trocar `xl:grid-cols-8` por `xl:grid-cols-7` (7 estados da D-04, não 8
pendências) — não copiar o número de colunas literalmente.

**Imports de topo da mesma página** (`EmpresasListagem.jsx:1-16`):
```jsx
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';
import { Building2, Search, ... } from 'lucide-react';
import { cn, formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
```

**Paginação manual Inertia (mesmo arquivo, componente `Paginator`, linhas 161+)** — reusar o mesmo
padrão se `Admin/Contratos.jsx` paginar por `LengthAwarePaginator` manual (ver o controller
`ComercialController::listagem()` abaixo, que monta esse paginator).

**Segundo análogo (família visual `Admin/`, header + linha expansível):**
`resources/js/Pages/Admin/Empresas.jsx:1-70` — mostra o padrão de `Chip`/badge local por arquivo
(`function Chip({ label, color })`), `cn()` para estado ativo/expandido, e o comentário de topo em
pt-BR explicando o histórico da tela (convenção a seguir no arquivo novo também).

---

### `resources/js/Pages/Admin/ContratoDetalhe.jsx` (component, página detalhe)

**Sem análogo direto no projeto** — nenhuma página `Admin/*.jsx` hoje combina "formulário de
completar dados + bloco de pendências + várias ações de API externa + modais de confirmação" no
mesmo componente. Compor a partir de três fontes:
1. `Comercial/EmpresasListagem.jsx` — para os componentes `Dialog`/`DialogContent`/`DialogHeader`/
   `DialogTitle`/`DialogFooter` de modal de confirmação (import já mostrado acima) e o padrão de
   `useForm()` do Inertia para o modal de liberação manual / cancelamento.
2. `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx` (a tela **descartável** da Fase 130,
   ainda existente no repo até a D-10 removê-la) — é a fonte do texto e do fluxo do modal "Liberar
   manualmente" que a D-10 pede para **reusar literalmente**, não a fonte de layout da página
   inteira.
3. O contrato de copy do `131-UI-SPEC.md` (seção "Ações do contrato — CTA por estado" e "Estado
   `erro`") — a página não recalcula nada, só troca de bloco conforme `status` + `pode_gerar_contrato`
   vindos como prop.

---

### Badge em `resources/js/Pages/Comercial/EmpresasListagem.jsx` (component, célula de tabela)

**Análogo exato, mesmo arquivo — mapa de classe por chave** (linhas 43-51 e 77-81):
```jsx
const PENDENCIAS_CLS = {
    sem_servico:             'bg-red-500/10 text-red-400 border-red-500/20',
    sem_valor:               'bg-orange-500/10 text-orange-400 border-orange-500/20',
    servico_nao_reconhecido: 'bg-amber-500/10 text-amber-300 border-amber-500/20',
    sem_setor:               'bg-sky-500/10 text-sky-400 border-sky-500/20',
    sem_contato:             'bg-slate-500/10 text-slate-300 border-slate-500/20',
    valor_revisar:           'bg-yellow-500/10 text-yellow-300 border-yellow-500/20',
    possivel_duplicidade:    'bg-rose-500/10 text-rose-400 border-rose-500/20',
};
// ...
const SETOR_CLS = {
    performance: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/25',
    publicacao:  'bg-sky-500/15 text-sky-300 border-sky-500/25',
    outros:      'bg-white/10 text-white/60 border-white/10',
};
```
E o componente que consome o mapa (linhas 100-107, `SetorBadge`):
```jsx
function SetorBadge({ setor }) {
    if (!setor) return <span className="text-white/30">—</span>;
    return (
        <span className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', SETOR_CLS[setor] ?? SETOR_CLS.outros)}>
            {SETOR_LABELS[setor] ?? setor}
        </span>
    );
}
```
Este é o molde EXATO para o mapa das 7 cores da D-04 (`STATUS_CLS`) e o componente `ContratoBadge`
que renderiza "{Rótulo} há N dias" (D-08) — inclusive o fallback (`?? SETOR_CLS.outros`) para
não quebrar se um estado inesperado chegar. Para o caso Polos (D9 — sem badge, célula `—`), seguir
o padrão do próprio `SetorBadge` (`if (!setor) return <span className="text-white/30">—</span>`),
com `title="Este serviço não passa por contrato"` acrescentado no `<span>`.

---

### `app/Http/Controllers/ComercialController.php::listagem()` — N+1 do badge (D-08, seção obrigatória)

**Análogo e arquivo-alvo são o MESMO método** (linhas 180-310, lido por inteiro). A listagem do
Comercial hoje já resolve exatamente este problema (pendências comerciais calculadas para MUITAS
empresas sem N+1) — o padrão a replicar para o badge de contrato é idêntico:

**1. Eager load de tudo que o cálculo por linha vai precisar, ANTES do `.map()`** (linhas 207-215):
```php
$query = Company::with([
        'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
        'consultor',
        'estrategista',
        'grupo:id,name,color',
        'hubspotEventos' => fn($q) => $q->orderByDesc('id')->limit(3),
    ])
    ->where('active', true)
    ->withExists(['hubspotEventoOrigem']);
```

**2. Materializa a coleção UMA vez, depois calcula em PHP (não em SQL, não por linha)** (linhas 240-245):
```php
$todasEmpresas = $query->get();

$todasEmpresas->each(function (Company $c) use ($pendencias) {
    $c->is_origem_hubspot      = (bool) ($c->hubspot_evento_origem_exists ?? false);
    $c->pendencias_comerciais  = $pendencias->calcular($c);
});
```

**3. Contagens agregadas ANTES do filtro, sobre a coleção já materializada** (linhas 247-264):
```php
$pendenciaCounts = [ /* ... zeros ... */ ];
foreach ($todasEmpresas as $c) {
    foreach ($c->pendencias_comerciais as $p) {
        if (isset($pendenciaCounts[$p])) {
            $pendenciaCounts[$p]++;
        }
    }
}
```

**4. Paginação manual via `LengthAwarePaginator`** (linhas 271-283) — preserva `queryString`,
mesmo padrão a reusar se `Admin/Contratos.jsx` paginar com filtro calculado em PHP (como o resumo
de 7 estados exige).

**Para o badge de contrato especificamente, o RESEARCH já monta o equivalente do passo 1**
(`131-RESEARCH.md` Pattern 3, linhas 363-378) — buscar TODOS os `ContratoAssinatura` relevantes da
página numa query só, indexados por `company_id`, e usar em memória dentro do `.map()`:
```php
$contratosPorEmpresa = ContratoAssinatura::whereIn('company_id', $companiesDaPagina->pluck('id'))
    ->whereHas('servico', fn ($q) => $q->where('exige_contrato', true))
    ->latest('id')
    ->get()
    ->groupBy('company_id')
    ->map(fn ($grupo) => $grupo->first()); // mais recente por empresa
```
Depois, para cada linha da paginação, `$contratosPorEmpresa->get($company->id)` (sem nova query) +
`ContratosPresosService::dataBase()`/`causa()`/`diasParado()` (services puros, sem I/O) — nunca
`ContratosPresosService::listar()`, que filtra por "preso" e esconderia contratos saudáveis do
badge (Pitfall 4 do RESEARCH).

**Teste que prova ausência de N+1** (já previsto em `131-VALIDATION.md`,
`EmpresasListagemBadgeContratoTest`) — usar `DB::enableQueryLog()` / `assertQueryCount` (ou
biblioteca equivalente já usada no projeto) ao redor da chamada de listagem com múltiplas empresas
com contrato, e assertar que o número de queries não escala com o número de empresas.

---

### Testes — `tests/Feature/Phase131/*.php`

**Análogo obrigatório 1 — rota admin + validação + IDOR + props Inertia:**
`tests/Feature/Phase130/LiberacaoManualTest.php` (arquivo inteiro lido, 200 linhas). Padrão de
helpers privados + asserção por reconsulta ao banco:
```php
private function admin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

private function naoAdmin(): User
{
    return User::factory()->create(['role' => 'consultor']);
}
```
```php
public function test_usuario_sem_role_admin_recebe_403_no_get_e_no_post(): void
{
    $usuario = $this->naoAdmin();

    $getResponse = $this->actingAs($usuario)->get(route('contratos.liberacao-manual.index'));
    $getResponse->assertForbidden();

    $postResponse = $this->actingAs($usuario)->post(route('contratos.liberacao-manual.store'), []);
    $postResponse->assertForbidden();
}
```
```php
public function test_admin_no_get_recebe_200_e_a_empresa_presa_na_prop_contratos(): void
{
    $admin    = $this->admin();
    $company  = Company::factory()->create(['name' => 'Empresa Presa Teste']);
    $contrato = $this->contratoPreso($company);

    $response = $this->actingAs($admin)->get(route('contratos.liberacao-manual.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/ContratosLiberacaoManual')
        ->has('contratos', 1)
        ->where('contratos.0.id', $contrato->id)
        ->where('contratos.0.company_id', $company->id)
    );
}
```
```php
public function test_post_sem_motivo_detalhe_falha_validacao_e_nao_grava_nada(): void
{
    // ...
    $response->assertSessionHasErrors('motivo_detalhe');
    $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->count());
}
```
Este arquivo vira `LiberacaoManualAbsorvidaTest.php` (mesmo corpo, rota/component renomeados para
o destino novo) — a `131-VALIDATION.md` já lista este teste como Wave 0.

**Análogo obrigatório 2 — asserção do CORPO da requisição via `Http::assertSent()`:**
`tests/Feature/Phase126/ClicksignClientEnvelopeTest.php` (trecho lido, linhas 213-286 e 296-322).
O teste que corrige exatamente o tipo de falha que a missão pede para não repetir (teste inócuo que
faz `Http::fake()` mas nunca confere o corpo enviado):
```php
/**
 * O teste que faltava (quick 260814-d9s): o único teste anterior deste
 * método fazia `Http::fake()` de 429 e nunca afirmava nada sobre o CORPO
 * enviado — um `POST` vazio (o bug original) passava despercebido e
 * quebrava contra a API real com "data deve ser informado(a)".
 */
#[Test]
public function reenviar_notificacao_envia_corpo_jsonapi_com_data_type_notifications(): void
{
    Http::fake([
        self::BASE . '/envelopes/*/signers/*/notifications' => Http::response(ClicksignSandboxFixtures::notificacaoEnviada(), 201),
    ]);

    $this->client()->reenviarNotificacao(self::ENVELOPE_ID, self::SIGNER_ID);

    Http::assertSent(function ($request) {
        $data = $request['data'] ?? null;

        return $data !== null
            && ($data['type'] ?? null) === 'notifications'
            && array_key_exists('attributes', $data);
    });
}
```
```php
#[Test]
public function reenviar_notificacao_429_nao_retenta_e_expoe_status_429(): void
{
    Http::fake([
        self::BASE . '/envelopes/*/signers/*/notifications' => Http::response(
            ClicksignSandboxFixtures::erro429NotificacaoTexto()['body'],
            429
        ),
    ]);

    try {
        $this->client()->reenviarNotificacao(self::ENVELOPE_ID, self::SIGNER_ID);
        $this->fail('Esperava ClicksignException por 429.');
    } catch (ClicksignException $e) {
        $this->assertSame(429, $e->httpStatus);
    }

    Http::assertSentCount(1);
}
```
Para `ContratoAdminReenviarTest.php` (CLICK-07), aplicar o MESMO padrão: `Http::fake()` do endpoint
Clicksign com 429 em **texto puro** (não JSON), disparar a action do controller (não o client
direto), e assertar que a resposta HTTP da rota é sucesso/toast informativo — nunca 500.
`Http::assertSentCount(1)` garante que o controller não retenta sozinho.

**Análogo 3 — cancelamento não chama a API (CLICK-10/D-13), padrão de "verbo certo, sem
side-effect indevido"** — mesmo arquivo, linhas 296-322 (`cancelar_envelope_usa_delete_e_devolve_true`
e `cancelar_envelope_com_resposta_500_devolve_false_sem_lancar`) mostram como o projeto já testa
`cancelarEnvelope()` isoladamente. Para `ContratoAdminCancelarTest.php`, o padrão inverte: usar
`Http::fake()` **sem nenhuma rota registrada** (equivalente a `Http::assertNothingSent()`, já usado
em `ClicksignClientEnvelopeTest.php:116-126` para "papel desconhecido lança exceção sem
requisição") para provar que a action de registrar cancelamento **não** chama `ClicksignClient`:
```php
#[Test]
public function criar_requisito_qualificacao_com_papel_desconhecido_lanca_excecao_sem_requisicao(): void
{
    Http::fake();
    // ...
    Http::assertNothingSent();
}
```

**Fixture disponível — não recriar:** `database/factories/ContratoAssinaturaSignatarioFactory.php`
e `database/factories/ContratoAssinaturaFactory.php` já existem (confirmado por `Glob`), incluindo
o state `->emAndamento()` usado em `LiberacaoManualTest.php` (`ContratoAssinatura::factory()->emAndamento()->create([...])`).
`131-VALIDATION.md` já cita a necessidade de conferir a factory de signatário `situacao='pendente'`
antes de criar uma nova — `app/Models/ContratoAssinaturaSignatario.php:82` define
`SITUACAO_PENDENTE = 'pendente'`, usar essa constante no teste, nunca a string solta.

**Padrão de teste de permissão via setor (para `ContratoAdminPermissaoTest`, "200 para quem recebeu
a permission via setor")** — `app/Models/Setor.php:65` expõe `permissoes(): HasMany` sobre
`SetorPermissao` (`permission_key`), e `User::effectivePermissions()` (`app/Models/User.php:221-227`)
lê `SetorPermissao::whereIn('setor_id', $this->setores()->pluck('setores.id'))`. O teste
`Phase75ShopeeEmpresasTest.php` (linhas 517-546) já mostra o padrão de 3 cenários de gate
(`test_gate_403_sem_a_key`, `test_gate_200_para_admin`, `test_gate_200_para_lider_do_setor_...`) a
replicar para `admin.contratos`, substituindo "líder do setor" por "membro do setor com
`SetorPermissao` gravado para `admin.contratos`" (o cenário que a `131-RESEARCH.md` pede
explicitamente: conceder via `setores.permissoes.sync` e esperar 200).

---

## Shared Patterns

### Autenticação/Autorização
**Fonte:** `app/Http/Middleware/EnsurePermission.php` (arquivo inteiro, 41 linhas) + `User::hasPermission()`
(`app/Models/User.php:193-198`).
**Aplicar a:** todas as rotas novas do grupo `admin/contratos`.
```php
public function handle(Request $request, Closure $next, string ...$keys): Response
{
    $user = $request->user();
    if (!$user) { abort(403, 'Acesso não autorizado.'); }
    foreach ($keys as $key) {
        if ($user->hasPermission($key)) { return $next($request); }
    }
    abort(403, 'Você não tem permissão para acessar esta área.');
}
```

### Migrations aditivas — armadilhas do projeto (seção obrigatória)

Três armadilhas de MariaDB que o SQLite da suíte de testes **não pega** (confirmado pelo
`CLAUDE.md`/memória do projeto e pelos dois análogos de migration lidos nesta fase):

**1. `enum()` de banco quebra a suíte SQLite (CHECK) e é convenção do projeto evitar.** Os dois
análogos desta fase (`motivo_slug`, `ultimo_alerta_em`) documentam explicitamente por que NÃO
usam `enum`:
```php
// `enum` + SQLite (CHECK derruba a suíte) — não usado; coluna `string`
// livre. A lista fechada é imposta em código (Rule::in() no
// controller do plano 130-04), nunca no schema.
$table->string('motivo_slug', 40)->nullable()->after('motivo');
```
Para o motivo de cancelamento (CLICK-10), usar `text()`/`string()` nullable, nunca `enum()` — a
UI-SPEC já confirma que é texto livre, sem lista fechada no backend.

**2. FK `nullOnDelete()` exige `->nullable()` — senão quebra no MariaDB de produção (erro 1830),
não no SQLite dos testes.** Exemplos reais do próprio projeto (`database/migrations/2026_04_26_152218_create_meetings_table.php:23`,
`2026_04_27_000002_create_ppa_tasks_table.php:18`, `2026_04_30_000001_create_mlb_publicacoes_table.php:21`):
```php
$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
$table->foreignId('comentario_autor_id')->nullable()->constrained('users')->nullOnDelete();
```
Se a coluna `cancelamento_solicitado_por_user_id` (D-13) usar `->constrained()->nullOnDelete()`,
ela **precisa** de `->nullable()` na mesma linha — o padrão acima é o molde exato (mesmo domínio:
"autor de uma ação opcional").

**3. Nome de índice/unique acima de 64 caracteres — MariaDB recusa (erro 1059), SQLite aceita e
mascara.** O projeto já nomeia TODO índice/unique à mão, mesmo quando o Laravel geraria um nome
válido — convenção reforçada por vários exemplos reais:
```php
$table->unique(['company_id', 'reference_date', 'campaign_id'], 'campaign_metrics_unique');
$table->index(['company_id', 'campanha_destino_id'], 'sugadores_destino_idx');
$table->unique(['template_id', 'servico_id'], 'nps_tpl_scope_uniq');
$table->index(['ativo', 'ordem'], 'bonus_faixas_ativo_ordem_idx');
```
A migration de CLICK-10 desta fase não declara índice novo (as duas migrations-análogo também não
declaram, e documentam por quê: volume baixo, coluna não é critério de busca) — mas se o
planejamento decidir indexar (ex.: `cancelamento_solicitado_por_user_id` para relatório), nomear
explicitamente com nome curto, nunca deixar o Laravel gerar (`contrato_assinaturas_cancelamento_solicitado_por_user_id_foreign`
estouraria os 64 chars).

### Reuso — NÃO recriar (confirmado por leitura direta)

| Peça | Onde | Método(s) confirmados | Nota |
|---|---|---|---|
| Pendências para gerar contrato | `app/Services/Contratos/ContratoDadosMinimosService.php` | `faltantes(Company)` (retorna `campo`/`rotulo`/`motivo`/`servico_id`), `estaPronta(Company)` | `email_colaborador` **não** entra aqui (D-11) — validar/exibir à parte |
| Short-circuit de permissão do admin | `app/Models/User.php:193-198` | `hasPermission()` | Resolve D-09 sem migration/seeder |
| Guard de rota | `app/Http/Middleware/EnsurePermission.php` | `handle()` | Já registrado como alias `permission:` (confirmado pelo uso em `routes/web.php`) |
| Reenvio de aviso Clicksign | `app/Services/Clicksign/ClicksignClient.php:486-529` | `reenviarNotificacao(string $envelopeId, string $signerId)` | Corpo `{"data":{"type":"notifications","attributes":{}}}`; 429 lança `ClicksignException` com `httpStatus=429` |
| Cancelamento de envelope (client) | `app/Services/Clicksign/ClicksignClient.php:550-564` | `cancelarEnvelope(string $envelopeId): bool` | **Nunca lança**, devolve `false` em falha — mas D-13 decidiu **não chamar este método** no fluxo novo (medido: 403 em `running`) |
| Consulta de envelope | `app/Services/Clicksign/ClicksignClient.php:323-326` | `consultarEnvelope(string $envelopeId): array` | Devolve o bloco `data` DESEMBRULHADO — `attributes` no topo |
| Causa/dias parado | `app/Services/Contratos/ContratosPresosService.php` | `dataBase()` (linhas 84-92), `diasParado()` (111-115), `causa()` (117-130) | `dataBase()` NUNCA usa `updated_at` (bug já corrigido, não reintroduzir) |
| Motivos de liberação manual | `app/Models/ContratoLiberacao.php` | `MOTIVOS_MANUAIS` / `MOTIVOS_MANUAIS_LABELS` (linhas 80-96) | D-10 reusa literalmente o texto — não reescrever |
| Backend da liberação manual | `app/Http/Controllers/ContratoLiberacaoManualController.php` + `EmpresaOperacionalRouter::liberarEmpresa()` | `index()`/`store()` | Absorver a SUPERFÍCIE (rota/tela), preservar a lógica inteira |
| `companies.email_colaborador` | Coluna já existe (`$fillable` desde Fase 34) | Editável hoje em `CompanyController`; tratado como pendência `sem_email_colaborador` em `PendenciasComerciaisService` e em `HubspotWebhookController` (~linha 957) | É este campo que ADM-01 preenche — **não** criar coluna nova |

---

## Sem Análogo Direto

| Arquivo | Papel | Fluxo de dados | Motivo |
|---|---|---|---|
| `resources/js/Pages/Admin/ContratoDetalhe.jsx` | component (página) | request-response + múltiplas ações | Nenhuma página `Admin/*.jsx` combina hoje "formulário + pendências + várias ações de API externa + modais" no mesmo componente — compor a partir de 3 fontes distintas listadas na seção acima (`EmpresasListagem.jsx` para Dialog/useForm, `ContratosLiberacaoManual.jsx` para o texto do modal de liberação, `131-UI-SPEC.md` para o resto da copy) |

---

## Metadata

**Escopo da busca de análogos:** `app/Http/Controllers/`, `app/Models/`, `app/Services/Contratos/`,
`app/Services/Clicksign/`, `app/Support/`, `app/Http/Middleware/`, `routes/web.php`,
`resources/js/Pages/Admin/`, `resources/js/Pages/Comercial/`, `resources/js/Layouts/`,
`database/migrations/` (filtro por data 2026-08-15), `tests/Feature/Phase130/`, `tests/Feature/Phase126/`,
`tests/Feature/Phase75/`.
**Arquivos lidos por inteiro ou em trechos concretos:** 20.
**Data de mapeamento:** 2026-08-14
