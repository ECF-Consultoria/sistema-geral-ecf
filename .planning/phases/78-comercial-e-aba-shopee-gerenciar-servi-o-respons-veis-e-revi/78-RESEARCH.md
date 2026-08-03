# Phase 78: Comercial e aba Shopee — gerenciar serviço/responsáveis e revisar ações - Research

**Researched:** 2026-07-14
**Domain:** Laravel 12 + Inertia/React — RBAC por setor, responsáveis por-serviço (pivot `company_users.servico_id`), contratos de serviço (`contratos_servico`), UI dark ECF
**Confidence:** HIGH (tudo verificado no código-fonte da própria base — Fases 75/76/77 já mergeadas)

## Summary

Esta fase é 100% intra-codebase: nenhuma biblioteca nova, nenhum serviço externo, nenhuma decisão de stack. Toda a infraestrutura já existe e foi verificada linha-a-linha:

- **Escrita por-serviço** já implementada em `ShopeeEmpresasController::bulkAssign` (servico_id shopee, apaga só o slot Shopee) e em `CompanyController::update` (servico_id performance) — o padrão de "detach escopado + attach com servico_id" é o molde canônico. `[VERIFIED: código]`
- **Setor Shopee (RBAC)** criado na Phase 77 (migration `2026_07_14_120000`): `setores.slug='shopee'`, cargos `analista`/`estrategista` escopados por `setor_id`, permission `shopee.empresas`. O eixo `setores` é DISTINTO de `servico.setor` (catálogo). `[VERIFIED: código]`
- **Contratos**: `ContratoServico.ativo` (bool) é o soft-cancel canônico; `destroyContrato` (ativo=false) já existe em CompanyController e ComercialController — mas ambos sob gate errado para Shopee (role:admin / comercial.cadastrar_empresa). `[VERIFIED: código]`

**Primary recommendation:** Adicionar 2 rotas novas no grupo `permission:shopee.empresas` — `resolver` (POST: grava analista/estrategista Shopee por-serviço + email_cliente, atômico) e `cancelar-servico` (grava `ativo=false` só no contrato shopee ativo da empresa). Ambas com o MESMO guard de escopo do `bulkAssign` (`empresasShopeeBaseQuery()->whereKey()->exists()`). Reaproveitar o `usersPorCargo` existente, mas ESCOPADO por `setor_id` do Shopee. No Comercial, estender `storeContrato` para gravar responsáveis por-serviço apenas quando o serviço criado for do setor shopee.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Listar analistas/estrategistas do Setor Shopee | API/Backend (`ShopeeEmpresasController::index`) | — | Resolução de setor→cargo→users é query server-side; frontend só recebe listas prontas |
| Popup "Resolver" (form) | Frontend (`Shopee/Empresas.jsx`) | API (novo `resolve`) | UI de edição; escrita atômica no backend |
| Gravar responsáveis por-serviço | API/Backend (pivot `company_users`) | — | Regra de negócio (servico_id escopado) — NUNCA client |
| Cancelar contrato shopee | API/Backend (`ContratoServico.ativo=false`) | — | Mutação de dados + guard de escopo anti-IDOR |
| Comercial: criar contrato + responsáveis | API/Backend (`storeContrato`) | Frontend (`AtribuirServico.jsx`) | Form no comercial; escrita por-serviço no backend |
| Remover "Gerar NPS" | Frontend (`Shopee/Empresas.jsx`) | — | Puramente remoção de UI; motor NPS (`nps.generate`) permanece |

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote externo (npm ou composer). Toda a implementação usa APIs já presentes: Eloquent, Inertia, `@/Components/ui/dialog` (Radix, já instalado), `lucide-react` (já instalado). Nenhuma linha de `npm install` / `composer require`.

## Standard Stack

Nenhuma dependência nova. Componentes/utilitários já disponíveis a reusar:

| Recurso | Origem | Uso nesta fase |
|---------|--------|----------------|
| `Dialog`/`DialogContent`/`DialogHeader`/`DialogFooter` | `@/Components/ui/dialog` (Radix) | Modal "Resolver pendência" |
| `useForm` (Inertia) | `@inertiajs/react` | Estado do form Resolver + submit |
| `router.post`/`router.delete` | `@inertiajs/react` | Submits de resolver/cancelar |
| `cn`, `formatCurrency`, `formatDate` | `@/lib/utils` | Composição de classe + formatação |
| `ContratoServico` (`ativo` bool, `scopeActive`) | `app/Models/ContratoServico.php` | Cancelar serviço (soft) |
| `Company::consultorDoServico/estrategistaDoServico(servicoId)` | `app/Models/Company.php:197-209` | Leitura service-aware (se precisar exibir responsável por-serviço) |
| `Servico::SETOR_SHOPEE` (`'shopee'`) + `isShopee()`-tipo `Servico.php:157` | `app/Models/Servico.php` | Filtro de setor do serviço |

## Architecture Patterns

### Pattern 1 — Selects escopados ao Setor Shopee (DEC-78-1)

**O que:** substituir o `usersPorCargo` GLOBAL por consulta escopada ao `setor_id` do Shopee.

O global atual (`ShopeeEmpresasController.php:121-134` e `CompanyController.php:206-219`) filtra `Cargo::where('slug', $slug)` — pega analistas/estrategistas de TODOS os setores (Performance, Publicação, Shopee…). Para a aba Shopee precisamos APENAS os do Setor Shopee.

```php
// Source: adaptação de ShopeeEmpresasController::index :121-134 (VERIFIED: código)
// Resolve o setor organizacional Shopee (eixo `setores`, NÃO servico.setor).
$setorShopeeId = DB::table('setores')->where('slug', 'shopee')->value('id');

// Helper escopado: cargo do slug DENTRO do setor Shopee → users via user_setores.
$usersPorCargoShopee = function (string $slug) use ($setorShopeeId) {
    // Fallback defensivo (DEC-78-1): setor não existe → lista vazia, nunca quebra.
    if (! $setorShopeeId) {
        return collect();
    }
    $cargoIds = \App\Models\Cargo::where('setor_id', $setorShopeeId)
        ->where('slug', $slug)
        ->pluck('id');
    if ($cargoIds->isEmpty()) {
        return collect();
    }
    return User::where('active', true)
        ->whereIn('id', DB::table('user_setores')->whereIn('cargo_id', $cargoIds)->pluck('user_id'))
        ->orderBy('name')
        ->get(['id', 'name'])
        ->values();
};

$estrategistas = $usersPorCargoShopee('estrategista');
$analistas     = $usersPorCargoShopee('analista');
```

**Diferença-chave vs. global:** o global faz `Cargo::where('slug', $slug)` (todos os setores). O escopado adiciona `->where('setor_id', $setorShopeeId)`. Como o unique dos cargos é `(setor_id, slug)` (verificado no seeder `2026_07_14_120000:51-83`), há um `analista`/`estrategista` por setor — o filtro por `setor_id` isola exatamente o do Shopee. `[VERIFIED: código]`

**Nota sobre slugs duplicados:** o comentário no código global menciona "slugs duplicados em prod (2x analista)" — isso é justamente PORQUE existem em múltiplos setores. Com o filtro `setor_id`, o `pluck`/`whereIn` continua correto (pode haver >1 id só se houver cargo duplicado no MESMO setor, o que o unique impede). `[VERIFIED: código]`

### Pattern 2 — Escrita por-serviço (molde canônico já implementado)

O `bulkAssign` (`ShopeeEmpresasController.php:178-210`) é o padrão a reusar dentro do `resolve`:

```php
// Source: ShopeeEmpresasController::bulkAssign :183-209 (VERIFIED: código)
// 1. Resolve o servico_id do contrato Shopee ATIVO da empresa.
$servicoShopeeId = DB::table('contratos_servico as ct')
    ->join('servicos as s', 's.id', '=', 'ct.servico_id')
    ->where('ct.company_id', $c->id)
    ->where('ct.ativo', true)
    ->where('s.setor', Servico::SETOR_SHOPEE)
    ->value('ct.servico_id');
if ($servicoShopeeId === null) { /* pula — nunca grava linha órfã */ }

// 2. Apaga SÓ o slot Shopee daquele papel (company_id, role, servico_id shopee).
DB::table('company_users')
    ->where('company_id', $c->id)->where('role', $role)
    ->where('servico_id', $servicoShopeeId)->delete();

// 3. Attach com servico_id no array de pivot.
$c->users()->attach($userId, ['role' => $role, 'servico_id' => $servicoShopeeId, 'assigned_at' => now()->toDateString()]);
```

**Invariante crítica:** o delete é SEMPRE por `(company_id, role, servico_id)` — nunca por `(company_id, role)` só, senão apaga a linha ML/consolidada do outro canal (Pattern 4 / T-76-02 documentado no código). `[VERIFIED: código]`

### Pattern 3 — Popup "Resolver pendência" (DEC-78-2)

**Recomendação:** endpoint dedicado `resolver` (POST atômico) em vez de encadear `bulkAssign` + update de email (2 requests, sem atomicidade).

Backend (novo método em `ShopeeEmpresasController`):
```php
// Source: novo — combina bulkAssign :178-210 + guard de escopo :168-172 (VERIFIED: padrão)
public function resolver(Request $request, Company $company)
{
    // Guard de escopo (mesmo builder do index/bulkAssign) — anti-IDOR (T-75-10).
    abort_unless($this->empresasShopeeBaseQuery()->whereKey($company->id)->exists(), 403,
        'Empresa fora do escopo Shopee.');

    $data = $request->validate([
        'consultor_id'    => 'nullable|integer|exists:users,id',   // Analista Shopee
        'estrategista_id' => 'nullable|integer|exists:users,id',   // Estrategista Shopee
        'email_cliente'   => 'nullable|email|max:255',
        'marcar_visto'    => 'nullable|boolean',                   // opcional, só admin
    ]);

    // Atualiza email (limpa pendência sem_contato).
    if ($request->has('email_cliente')) {
        $company->update(['email_cliente' => $data['email_cliente'] ?: null]);
    }
    // (opcional) marcar "empresa vista" — reusar CompanyController::marcarVisto :784-794.
    // Grava responsáveis por-serviço (Pattern 2) para cada papel informado.
    // ... resolve $servicoShopeeId, detach escopado, attach com servico_id ...

    return back()->with('success', 'Pendência resolvida.');
}
```

Frontend: reusar `Dialog` (`@/Components/ui/dialog`, já importado no arquivo linha 6) + `useForm`. O modal enxuto tem 3 campos: select Analista Shopee (`analistas`), select Estrategista Shopee (`estrategistas`), input email. Molde de select = a `BulkBar` existente (linhas 181-196). Molde de modal de edição com campos = `Companies/Index.jsx:680-748` (Dialog "Editar Empresa" com `email_cliente`/`consultor_id`/`estrategista_id`). `[VERIFIED: código]`

### Pattern 4 — Excluir = cancelar só o serviço Shopee (DEC-78-4)

**Não reusar** `CompanyController::destroyContrato` (web.php:647, grupo `role:admin`) nem `ComercialController::destroyContrato` (gate `comercial.cadastrar_empresa`) — gates errados. Criar método dedicado gated por `shopee.empresas`:

```php
// Source: novo — padrão de destroyContrato :850-857 + guard de escopo (VERIFIED: padrão)
public function cancelarServico(Request $request, Company $company)
{
    abort_unless($this->empresasShopeeBaseQuery()->whereKey($company->id)->exists(), 403);

    // Resolve o contrato Shopee ATIVO (só ele — ML/outros ficam intactos).
    $contrato = $company->contratosServico()
        ->where('ativo', true)
        ->whereHas('servico', fn($q) => $q->where('setor', Servico::SETOR_SHOPEE))
        ->first();
    abort_if(! $contrato, 404, 'Nenhum contrato Shopee ativo.');

    $contrato->update(['ativo' => false]);   // soft-cancel (Claude's Discretion → ativo=false)
    // Após ativo=false, empresasShopeeBaseQuery() não retorna mais a empresa → some da aba.
    return back()->with('success', 'Serviço Shopee cancelado.');
}
```

**Confirmação de isolamento:** `contratos_servico` tem 1 linha por serviço da empresa. Cancelar o contrato shopee (`ativo=false`) NÃO toca a linha ML nem a empresa (`companies.active` inalterado). A empresa some da aba porque `empresasShopeeBaseQuery()` (`ShopeeEmpresasController.php:42-50`) exige `contratos_servico.ativo=true` de setor shopee. `[VERIFIED: código]`

### Pattern 5 — Comercial grava responsáveis por-serviço (DEC-78-5)

`Comercial/AtribuirServico.jsx` faz `router.post('/empresas/{id}/contratos-servico', form)` → `CompanyController::storeContrato` (`:805-825`). Estender:

1. `ComercialController::atribuirServico` (`:115-159`) passa `analistas`/`estrategistas` escopados Shopee como props novas.
2. `AtribuirServico.jsx` adiciona 2 selects opcionais (Analista/Estrategista Shopee) ao form — mostrar SÓ quando o serviço selecionado for setor shopee (`servicoSelecionado.setor === 'shopee'`; hoje `servicos_disponiveis` não traz `setor` — adicionar ao select de `atribuirServico :141-143`).
3. `storeContrato` grava responsáveis por-serviço APÓS criar o contrato, usando o `servico_id` recém-criado, SÓ se o serviço for shopee. NUNCA tocar ML.

```php
// Source: extensão de storeContrato :815-822 (VERIFIED: padrão)
$contrato = $company->contratosServico()->create([...]);
$servico = Servico::find($data['servico_id']);
if ($servico?->setor === Servico::SETOR_SHOPEE) {
    foreach (['consultor' => $data['consultor_id'] ?? null,
              'estrategista' => $data['estrategista_id'] ?? null] as $role => $userId) {
        if (! $userId) continue;
        DB::table('company_users')->where('company_id', $company->id)
            ->where('role', $role)->where('servico_id', $contrato->servico_id)->delete();
        $company->users()->attach($userId, ['role' => $role, 'servico_id' => $contrato->servico_id, 'assigned_at' => now()->toDateString()]);
    }
}
```

**Cuidado:** `storeContrato` hoje está no grupo `role:admin` (web.php:645). `AtribuirServico.jsx` é acessado via `comercial.cadastrar_empresa` mas o POST vai para a rota admin — verificar se o comercial consegue POST (a rota admin exige role:admin). Isso é comportamento PRÉ-EXISTENTE — não regredir; se o comercial já usa hoje, o middleware deve permitir (confirmar no plano com um teste RBAC do comercial).

### Anti-Patterns to Avoid

- **Detach por `(company_id, role)` sem `servico_id`** → apaga o responsável do outro canal. SEMPRE incluir `servico_id` no where (Pattern 2). `[VERIFIED: T-76-02 no código]`
- **Reusar `usersPorCargo` global na aba Shopee** → lista analistas de todos os setores (viola DEC-78-1).
- **Reusar `destroyContrato` core/comercial** → gate errado (role:admin / comercial), sem guard de escopo shopee → IDOR.
- **Cancelar via `companies.active=false`** → desativa a empresa inteira (mata ML também). Usar `contratos_servico.ativo=false`.

## Don't Hand-Roll

| Problema | Não construir | Usar | Por quê |
|----------|---------------|------|---------|
| Guard de escopo shopee | Nova checagem de contrato | `empresasShopeeBaseQuery()->whereKey()->exists()` (`:42-50`) | Já é o critério canônico do index/bulkAssign — consistência garantida |
| Escrita por-serviço | Novo helper de pivot | Padrão `bulkAssign :183-209` | Já trata linha órfã, detach escopado, servico_id |
| Soft-cancel de contrato | Coluna nova de status | `ContratoServico.ativo=false` | Schema já tem `ativo` bool + `scopeActive` |
| Modal de edição | Modal do zero | `Dialog` de `Companies/Index.jsx:680-748` | Molde com email/responsáveis pronto |

## Runtime State Inventory

Fase de UI + endpoints (não é rename/refactor). Mesmo assim, itens de estado a considerar:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `company_users` (pivot com `servico_id`) — responsáveis por-serviço já existem (Phase 76) | Escrita nova via resolver/comercial; nenhuma migração de dados |
| Live service config | Nenhum | None — verificado: fase não toca serviços externos |
| OS-registered state | Nenhum | None |
| Secrets/env vars | Nenhum | None |
| Build artifacts | Bundle Vite (`npm run build` ao final) | Rebuild obrigatório após editar `Empresas.jsx`/`AtribuirServico.jsx` |

## Common Pitfalls

### Pitfall 1 — Variável de escopo dentro de `.map()` (gotcha do bundle)
**O que goes wrong:** ao adicionar o botão "Resolver" + flags dentro do `.map()` das linhas em `Empresas.jsx`, uma variável computada FORA do callback e usada DENTRO some no bundle Rollup (ReferenceError em produção, funciona em dev).
**Como evitar:** computar flags booleanas (ex: `const podeResolver = ...`) DENTRO do callback do `.map()`. `[VERIFIED: memória feedback_rollup_map_scope_bug]`
**Sinal de alerta:** funciona em `npm run dev`, quebra após `npm run build`.

### Pitfall 2 — Setor Shopee ausente em SQLite dos testes
**O que goes wrong:** os selects escopados dependem de `setores.slug='shopee'`. A migration `2026_07_14_120000` cria o setor, mas o wiring de users é pulado sem os emails reais. Nos testes, criar fixtures de user+user_setores+cargo shopee explicitamente.
**Como evitar:** molde em `SetorShopeeSeedTest` — criar setor/cargo/user_setores via `DB::table(...)->insert`. Fallback defensivo (setor null → lista vazia) deve ter teste próprio. `[VERIFIED: código]`

### Pitfall 3 — `= NULL` nunca casa no detach
**O que goes wrong:** se um responsável consolidado tiver `servico_id=NULL`, `where('servico_id', null)` compila `= NULL` (nunca casa). Na aba Shopee o servico_id é sempre resolvido (não-null), então menos crítico, mas o `storeContrato` do comercial deve usar `whereNull` se algum dia gravar consolidado.
**Como evitar:** Shopee sempre tem servico_id resolvido; não usar NULL nos writes desta fase. `[VERIFIED: CompanyController::update :644-646]`

### Pitfall 4 — CSRF/rota de mutação por-empresa
**O que goes wrong:** rotas novas `resolver`/`cancelar-servico` precisam de `{company}` no path e route model binding. Garantir que ficam DENTRO do grupo `permission:shopee.empresas` (web.php:511-514), não no grupo `role:admin`.
**Como evitar:** adicionar as rotas ao grupo shopee existente; nomear `shopee.empresas.resolver` / `shopee.empresas.cancelar-servico`.

## Code Examples

### Rotas novas (web.php, grupo `permission:shopee.empresas` :511-514)
```php
// Source: extensão do grupo existente web.php:511-514 (VERIFIED: código)
Route::middleware('permission:shopee.empresas')->group(function () {
    Route::get('/shopee/empresas',                          [ShopeeEmpresasController::class, 'index'])->name('shopee.empresas.index');
    Route::post('/shopee/empresas/bulk-assign',             [ShopeeEmpresasController::class, 'bulkAssign'])->name('shopee.empresas.bulk-assign');
    // NOVAS (Phase 78):
    Route::post('/shopee/empresas/{company}/resolver',        [ShopeeEmpresasController::class, 'resolver'])->name('shopee.empresas.resolver');
    Route::delete('/shopee/empresas/{company}/servico',       [ShopeeEmpresasController::class, 'cancelarServico'])->name('shopee.empresas.cancelar-servico');
});
```

### Remoção do "Gerar NPS" (DEC-78-3)
Em `Shopee/Empresas.jsx`, remover:
- `npsForm`, `gerarNps` (`:133-137`), `npsLink`/`npsDialog`/`npsCopied` states (`:139-141`), o `useEffect` de `flash.nps_link` (`:142-147`), `copiarNps` (`:149-154`), o componente `NpsButton` (`:162-173`), os usos em `:281` e `:358`, e o `Dialog` de NPS (`:380-402`).
- Manter a rota `nps.generate` intacta (motor NPS, usado por Fase 79). Só remover o USO nesta página.
- Após remover, `grep -r "nps.generate" resources/js/Pages/Shopee/` deve retornar vazio. `[VERIFIED: código]`

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Responsáveis por-empresa (`company_users` sem serviço) | Por-serviço (`company_users.servico_id`) | Phase 76 | Escritas DEVEM escopar por servico_id |
| Aba Shopee gera NPS avulso (`nps.generate`) | NPS por modelo/disparo (Fase 79) | Phase 78 (esta) | Remover botão da aba |
| `usersPorCargo` global (todos setores) | Escopado por `setor_id` shopee | Phase 78 (esta) | Selects só do Setor Shopee |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `storeContrato` (grupo role:admin) é acessível pelo fluxo Comercial hoje (pré-existente) | Pattern 5 | Se o comercial NÃO consegue POST hoje, DEC-78-5 precisa de rota comercial dedicada — adicionar teste RBAC no plano |
| A2 | `servicos_disponiveis` não traz `setor` hoje; adicionar ao SELECT | Pattern 5 | Se já trouxer, ignorar; verificar em `atribuirServico :141-143` |
| A3 | Popup "Resolver" atômico (endpoint único) é preferível a 2 requests | Pattern 3 | Claude's Discretion no CONTEXT — planner pode optar por reuso do bulkAssign + update email |

## Validation Architecture

Config: `workflow.nyquist_validation` não desabilitado → seção incluída. Testes em `tests/Feature/V16/` (namespace `Tests\Feature\V16`).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (SQLite `:memory:`, `RefreshDatabase`) |
| Quick run command | `php artisan test --filter=V16 tests/Feature/V16` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map
| Behavior | Test Type | Automated Command | File |
|----------|-----------|-------------------|------|
| Selects escopados: só analista/estrategista do Setor Shopee (user de outro setor NÃO aparece) | Feature | `php artisan test --filter=test_selects_escopados_shopee` | `tests/Feature/V16/ShopeeResponsaveisEscopoTest.php` ❌ Wave 0 |
| Fallback: setor shopee ausente → listas vazias, sem erro | Feature | `php artisan test --filter=test_fallback_setor_ausente` | idem ❌ Wave 0 |
| Resolver: grava analista/estrategista por-serviço (servico_id shopee) + email → pendências somem | Feature | `php artisan test --filter=test_resolver_limpa_pendencias` | `tests/Feature/V16/ShopeeResolverTest.php` ❌ Wave 0 |
| Excluir: cancela só contrato shopee (ativo=false); empresa + contrato ML permanecem; some da aba | Feature | `php artisan test --filter=test_cancelar_so_shopee` | `tests/Feature/V16/ShopeeCancelarServicoTest.php` ❌ Wave 0 |
| RBAC/escopo: resolver/cancelar sem `shopee.empresas` → 403; empresa fora do escopo → 403/422 | Feature | `php artisan test --filter=test_rbac_escopo` | idem (nos 2 acima) ❌ Wave 0 |
| Comercial: adicionar serviço shopee com responsáveis grava por-serviço, NÃO toca ML | Feature | `php artisan test --filter=test_comercial_grava_por_servico` | `tests/Feature/V16/ComercialContratoShopeeTest.php` ❌ Wave 0 |
| "Gerar NPS" removido: grep de `nps.generate` em `Shopee/Empresas.jsx` vazio + `npm run build` verde | Static/manual | `grep -r "nps.generate" resources/js/Pages/Shopee/` (esperar vazio) + `npm run build` | verificação manual no VERIFICATION |

### Sampling Rate
- **Per task commit:** `php artisan test tests/Feature/V16`
- **Per wave merge:** `php artisan test` (suite completa — bônus/carteira intactos)
- **Phase gate:** suite verde + `npm run build` verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/V16/ShopeeResponsaveisEscopoTest.php` — selects escopados + fallback
- [ ] `tests/Feature/V16/ShopeeResolverTest.php` — resolver + RBAC/escopo
- [ ] `tests/Feature/V16/ShopeeCancelarServicoTest.php` — cancelar-só-shopee + RBAC/escopo
- [ ] `tests/Feature/V16/ComercialContratoShopeeTest.php` — comercial por-serviço
- [ ] Fixtures compartilhadas: helper `criarUserComSetorShopee` (molde `Phase75ShopeeEmpresasTest:125-159` + `SetorShopeeSeedTest`) — considerar `tests/Feature/V16/Concerns/` ou trait

Molde de teste (RBAC positivo/negativo, criação de setor+cargo+user_setores, empresa+contrato shopee) já existe verbatim em `Phase75ShopeeEmpresasTest` e `SetorShopeeSeedTest` — copiar e adaptar. `[VERIFIED: código]`

## Security Domain

`security_enforcement` não desabilitado → seção incluída.

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V4 Access Control | yes | Gate `permission:shopee.empresas` (nunca core.empresas) + guard de escopo `empresasShopeeBaseQuery` (anti-IDOR) |
| V5 Input Validation | yes | `$request->validate` (email, exists:users, integer) em resolver/cancelar/comercial |
| V2 Authentication | no | Sessão Inertia já herdada |
| V6 Cryptography | no | Sem cripto |

### Known Threat Patterns
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR — empresa fora do escopo shopee em resolver/cancelar | Tampering | `empresasShopeeBaseQuery()->whereKey()->exists()` → 403 (fail-closed, herdado de T-75-10) |
| Elevation of Privilege — usar core.empresas p/ ação shopee | EoP | Rotas SÓ no grupo `permission:shopee.empresas` (T-75-09) |
| Cancelar serviço errado (ML em vez de shopee) | Tampering | Filtro `whereHas('servico', setor=shopee)` no resolve do contrato |
| Sobrescrever responsável do outro canal | Tampering | Detach escopado por `servico_id` (Pattern 2 / T-76-02) |

## Environment Availability

**SKIPPED** — fase é puramente código/config (controllers, rotas, React). Sem dependências externas (DB local já em uso; `npm run build` já no fluxo padrão do projeto).

## Sources

### Primary (HIGH confidence — código-fonte verificado)
- `app/Http/Controllers/ShopeeEmpresasController.php` — index, bulkAssign, empresasShopeeBaseQuery, guard IDOR
- `app/Http/Controllers/CompanyController.php` — usersPorCargo (:206-219), update (:579-648), storeContrato/updateContrato/destroyContrato (:805-857), marcarVisto (:784-794)
- `app/Http/Controllers/ComercialController.php` — atribuirServico (:115-159), updateContrato/destroyContrato (:760-806)
- `app/Models/Company.php` — consultor/estrategista/consultorDoServico/estrategistaDoServico (:157-209)
- `app/Models/Servico.php` — SETOR_SHOPEE (:57), isShopee (:157)
- `app/Models/Cargo.php` — setor_id, belongsTo Setor
- `database/migrations/2026_07_14_120000_seed_setor_shopee_e_usuarios.php` — setor/cargo/permission Shopee
- `resources/js/Pages/Shopee/Empresas.jsx` — aba atual, NpsButton, BulkBar, Dialog
- `resources/js/Pages/Comercial/AtribuirServico.jsx` — form de contrato
- `resources/js/Pages/Companies/Index.jsx` — molde do modal Editar (:680-748)
- `routes/web.php` — grupo shopee.empresas (:511-514), contratos-servico (:645-647)
- `tests/Feature/Phase75/Phase75ShopeeEmpresasTest.php` + `tests/Feature/V16/SetorShopeeSeedTest.php` — moldes de teste

### Secondary
- Memória do projeto `feedback_rollup_map_scope_bug` (gotcha do `.map()` no bundle)
- `.planning/milestones/v16.0-brief.md` — contexto da milestone

## Metadata

**Confidence breakdown:**
- Selects escopados (DEC-78-1): HIGH — query e schema verificados no seeder + models
- Resolver popup (DEC-78-2): HIGH — molde de escrita + modal existem verbatim
- Cancelar serviço (DEC-78-4): HIGH — `ativo=false` + guard de escopo verificados
- Comercial (DEC-78-5): MEDIUM — depende de A1 (acesso do comercial ao storeContrato); resolver no plano com teste RBAC
- Remover NPS (DEC-78-3): HIGH — usos localizados linha-a-linha

**Research date:** 2026-07-14
**Valid until:** ~2026-08-14 (estável; base interna, sem deps externas voláteis)
