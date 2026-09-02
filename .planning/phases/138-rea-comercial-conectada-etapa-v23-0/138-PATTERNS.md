# Phase 138: Área Comercial conectada à etapa (v23.0) - Mapa de Padrões

**Mapeado:** 2026-09-02
**Arquivos analisados:** 14 (7 novos, 7 modificados)
**Análogos encontrados:** 14 / 14 (todos por leitura direta neste worktree — `ecf_fluxo_entrada`, branch `feat/fluxo-entrada-empresas`)

Todos os trechos abaixo foram confirmados por leitura direta nesta sessão (não copiados do RESEARCH sem checar). Onde o número de linha do RESEARCH divergiu da leitura atual, o número usado aqui é o medido agora.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `database/migrations/2026_09_0X_HHMMSS_add_hubspot_owner_data_venda_to_companies_table.php` | migration | batch (schema) | `database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php` | exact |
| `config/services.php` (bloco `hubspot.props.deal`) | config | transform | próprio arquivo, chaves irmãs em `props.deal` (linhas 134-182) | exact |
| `app/Services/HubspotApiClient.php` (+`fetchOwner()`) | service | request-response (HTTP GET externo) | `fetchCompany()` / `fetchAssociatedCompanyId()` no mesmo arquivo | exact |
| `app/Services/Hubspot/HubspotOwnerResolver.php` | service | CRUD (cache) | `NpsDigisacDispatchService::resolveUserId()` (ator/ID via config) + `MercadoLivreService::resolveAdvertiserId()` (Cache::remember) | role-match (composição de dois análogos) |
| `app/Http/Controllers/Api/HubspotWebhookController.php` (ponto de chamada em `processar()`) | controller | event-driven (webhook) | próprio arquivo — chamada já existente a `GatilhoContratoAdministrativoService::dispararSeElegivel()` nas mesmas linhas | exact |
| `app/Http/Controllers/ComercialController.php::store()` | controller | request-response | próprio arquivo — mesma chamada em `store()` | exact |
| `app/Http/Controllers/ComercialEntradaController.php` (novo) | controller | CRUD (listagem) | `ComercialController::listagem()` (linhas 190-3xx) + `ContratoAdminController::index()` (critério de universo) | role-match |
| `app/Http/Controllers/ContratoAdminController.php::index()` (payload +8 campos) | controller | CRUD (listagem) | próprio arquivo — shape de linha já existe (linhas 179-243) | exact |
| `resources/js/Pages/Comercial/Entrada.jsx` (novo) | component | request-response (SSR Inertia) | `resources/js/Pages/Comercial/EmpresasListagem.jsx` | role-match |
| `resources/js/Layouts/AppLayout.jsx` (`NAV_TREE`) | provider (config de navegação) | transform (array estático) | próprio arquivo — grupos `Comercial`/`Administrativo` já existentes | exact |
| `app/Support/Permissions.php` (+`COMERCIAL_ENTRADA`) | config (catálogo) | transform | próprio arquivo — padrão `ADMIN_CONTRATOS` / `COMERCIAL_CADASTRAR_EMPRESA` | exact |
| `app/Support/Modules.php` (+entrada no grupo `Comercial`) | config (catálogo) | transform | próprio arquivo — entrada `COMERCIAL_CADASTRAR_EMPRESA` (linha 221) | exact |
| `routes/web.php` (novo grupo `comercial.entrada.*`) | route | request-response | próprio arquivo — grupo `admin.contratos.*` (linhas 1433-1462), fora de `role:admin`, com permission própria | exact |
| `tests/Feature/Phase138/*.php` (6 arquivos) | test | request-response / event-driven | `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` + `tests/Unit/Phase137/EtapaTransicaoServiceTest.php` + `tests/Feature/Phase34HubspotWebhookTest.php` (mock `Http::fake`) | exact (composto) |

## Pattern Assignments

### `database/migrations/2026_09_0X_HHMMSS_add_hubspot_owner_data_venda_to_companies_table.php` (migration, schema aditivo)

**Analog:** `database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php` (confirmado íntegro)

**Padrão completo a copiar** (`Schema::hasColumn()` defensivo + `down()` reversível, nada mais tocado):
```php
// Source: database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php:19-83
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'hubspot_deal_id')) {
                $table->string('hubspot_deal_id', 255)->nullable()->index();
            }
            // ...uma coluna por bloco if, sempre nullable() para colunas aditivas...
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $cols = ['hubspot_deal_id', 'hubspot_company_id', /* ... */];
            foreach ($cols as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
```

**Segunda referência — por que NÃO usar `default()` nem tocar outra coluna no mesmo `up()`/`down()`** (`database/migrations/2026_09_01_110000_add_etapa_to_companies_table.php:12-24`, a migration da Fase 137 que criou `companies.etapa`): o docblock cita o próprio repositório como anti-padrão a não repetir (`2026_05_25_100001_add_status_to_companies.php`, que mistura criação de coluna com rename no mesmo `up()`). D-09 pede exatamente 3 colunas nullable sem default — `hubspot_owner_id` (string), `hubspot_owner_nome` (string), `data_venda` (date, formato `'Y-m-d'` — mesmo formato que `closedate` já chega, confirmado em `config/services.php:170-174`).

**Checklist obrigatório do `CLAUDE.md`** para esta migration (tabela `companies` com dado de produção): baseline de testes específica ANTES de rodar (ver seção Testes abaixo) e `VERIFICATION.md` depois.

---

### `config/services.php` — nova property `owner_id` no bloco `deal` (D-08)

**Analog:** o próprio bloco `hubspot.props.deal`, confirmado íntegro em `config/services.php:120-227`.

**Padrão exato a seguir** (uma linha por property, `env()` com default = nome interno medido):
```php
// Source: config/services.php:134-181 (bloco 'deal' já existente — ex.: linhas 139, 163-164)
'deal' => [
    'closedate'          => env('HUBSPOT_PROP_DEAL_CLOSEDATE', 'closedate'),
    'cnpj_da_empresa'    => env('HUBSPOT_PROP_DEAL_CNPJ', 'cnpj_da_empresa'),
    'razao_social'       => env('HUBSPOT_PROP_DEAL_RAZAO_SOCIAL', 'razao_social'),
    // NOVO (D-08) — property PADRÃO do HubSpot (não customizada), mas medida
    // do mesmo jeito que todas as outras (disciplina do comentário acima,
    // linhas 125-133 — precedente quick 260805-eqk de adivinhar e quebrar em silêncio):
    'owner_id' => env('HUBSPOT_PROP_DEAL_OWNER_ID', 'hubspot_owner_id'),
],
```
**Regra do próprio arquivo, citada na letra no comentário de topo do bloco (linha 129):** "propriedade ausente na conta HubSpot = null no payload, nunca quebra o fluxo." Rodar `php artisan hubspot:inspect-properties --objects=deals` para CONFIRMAR o nome antes de fixar o default (Pitfall 4 do RESEARCH).

Se a Opção 1 do "ator de sistema" (D-17, já travada pelo CONTEXT) for implementada, a mesma disciplina de `env()` com default se aplica a `services.hubspot.webhook_user_id` (`env('HUBSPOT_WEBHOOK_USER_ID')`, **sem default fixo** — precisa ser configurado por ambiente).

---

### `app/Services/HubspotApiClient.php::fetchOwner()` (service, request-response HTTP)

**Analog:** `fetchCompany()` (linhas 94-102) para o shape "GET com properties"; `fetchAssociatedCompanyId()` (linhas 67-83) para a resiliência a 404 (nunca lança, sempre `null`).

**Imports do arquivo** (confirmado linhas 1-6):
```php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
```

**Padrão a copiar — GET simples com `properties`** (`fetchCompany`, linhas 94-102):
```php
public function fetchCompany(string $id, array $properties): array
{
    $res = Http::withToken($this->token)
        ->get(self::BASE . "/crm/v3/objects/companies/{$id}", [
            'properties' => implode(',', $properties),
        ]);
    $res->throw();
    return $res->json();
}
```

**Padrão a copiar — resiliente a 404/erro, nunca lança** (`fetchAssociatedCompanyId`, linhas 67-83):
```php
public function fetchAssociatedCompanyId(string $dealId): ?string
{
    $res = Http::withToken($this->token)
        ->get(self::BASE . "/crm/v3/objects/deals/{$dealId}/associations/companies");

    if (!$res->ok()) {
        return null;
    }
    // ...parse do payload...
}
```

`fetchOwner(string $ownerId): ?array` deve seguir o segundo padrão (owner pode ter sido removido/arquivado — não é erro fatal do fluxo). `self::BASE = 'https://api.hubapi.com'` (linha 31) e `$this->token` (linha 36-38, injetável para teste, fallback `config('services.hubspot.access_token')`) já existem na classe — reusar, não duplicar.

**Log — nunca logar o token, só `owner_id` + status** (disciplina T-111-03, já seguida em todo o arquivo): usar `Log::channel('ecf-webhooks')->warning(...)` com `['owner_id' => $ownerId, 'status' => $res->status()]`.

---

### `app/Services/Hubspot/HubspotOwnerResolver.php` (service novo, cache + fetch)

**Analog 1 — resolução de ID default via config**, `app/Services/Digisac/NpsDigisacDispatchService.php:161-168` (confirmado):
```php
// Source: app/Services/Digisac/NpsDigisacDispatchService.php:154-168
/**
 * Resolve userId em ordem de precedência:
 *   1. `Configuracao::nps_digisac_user_id` (default do admin)
 *   2. `config('digisac.default_user_id')` (env)
 *
 * Empresa NÃO define userId proprio — sempre parte do mesmo usuário-bot global.
 */
private function resolveUserId(): string
{
    $doAdmin = trim((string) Configuracao::get('nps_digisac_user_id', ''));
    if ($doAdmin !== '') {
        return $doAdmin;
    }
    return (string) config('digisac.default_user_id', '');
}
```
Este é o precedente literal citado pela D-17 do CONTEXT para o "ator de sistema" do webhook — mesma forma (`config('...')` com fallback), aplicável tanto ao resolver de owner quanto (se a Opção 1 do Pitfall 1 for a escolhida) à resolução do `User` do webhook.

**Analog 2 — `Cache::remember` com TTL longo**, `app/Services/MercadoLivreService.php:693-709` (confirmado):
```php
// Source: app/Services/MercadoLivreService.php:693-710
// Cacheado 24h por empresa (o advertiser_id é estável). Retorna null quando a
// conta não possui Mercado Ads — nesse caso cacheia 0 como sentinela.
private function resolveAdvertiserId(Company $company): ?int
{
    $cached = Cache::remember("ml_advertiser_{$company->id}", now()->addHours(24), function () use ($company) {
        try {
            $data = $this->get(/* ... */);
            $id = $data['advertisers'][0]['advertiser_id'] ?? null;
            return $id !== null ? (int) $id : 0; // 0 = sentinela cacheável
        } catch (\Throwable $e) {
            Log::info("[MercadoLivre] Sem advertiser PADS empresa {$company->id}: {$e->getMessage()}");
            return 0;
        }
    });
    // ...
}
```
`HubspotOwnerResolver::resolverNome(string $ownerId): ?string` deve compor os dois: `Cache::remember("hubspot_owner_{$ownerId}", now()->addDays(N), fn () => $this->api->fetchOwner($ownerId))`, TTL longo porque "a ECF tem poucos vendedores" (D-08). Nome de classe/serviço: `app/Services/Hubspot/` já concentra `HubspotCompanyMatcher`, `HubspotContactSelector`, `HubspotNameNormalizer` (confirmado nos imports de `HubspotWebhookController.php:13-17`) — mesma pasta, mesmo padrão de classe pequena de um propósito só.

---

### `app/Http/Controllers/Api/HubspotWebhookController.php` — ponto de chamada do `EtapaTransicaoService` (controller, event-driven)

**Analog:** o próprio arquivo — a chamada já existente ao gate administrativo, na MESMA posição onde a chamada nova entra.

**Imports confirmados** (linhas 1-25):
```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
// ...
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use App\Services\Hubspot\HubspotCompanyMatcher;
// ...
use App\Services\HubspotApiClient;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
```
Adicionar: `use App\Services\FluxoEntrada\EtapaTransicaoService;` e `use App\Models\User;`.

**Ponto de inserção exato — confirmado linhas 264-276** (dentro do método privado que processa o evento, chamado por `receive()` e por `reprocessarEvento()`):
```php
// Source: app/Http/Controllers/Api/HubspotWebhookController.php:264-276
// ── Fase 128 (REDE-06) — gate administrativo de contrato ───────────
// FORA da transaction acima, de proposito: uma excecao aqui (ex.:
// QueryException da trava composta em corrida) nao pode desfazer a
// Company/ContratoServico ja commitados — a empresa TEM que chegar
// ao operacional independente do desfecho do gate. dispararSeElegivel()
// ja engole \Throwable internamente (plano 03), entao nenhum try/catch
// redundante aqui — isso esconderia regressao no proprio service.
// refresh() garante que contratosServico lido pelo gate reflete os
// registros criados dentro da transaction (a colecao em memoria de
// $company pode nao contar com eles).
$company->refresh();
app(GatilhoContratoAdministrativoService::class)->dispararSeElegivel($company);
```
A chamada a `EtapaTransicaoService::transicionar()` entra IMEDIATAMENTE ANTES desta (mesmo `$company->refresh()` serve às duas), seguindo a mesma disciplina "fora da transaction, engole erro sem propagar":
```php
// NOVO — mesma posição, mesma disciplina "nunca derruba o webhook":
$porSistema = User::find(config('services.hubspot.webhook_user_id'));
if ($porSistema === null) {
    Log::channel('ecf-webhooks')->error('[HubSpot Webhook] HUBSPOT_WEBHOOK_USER_ID não configurado ou usuário não existe — empresa NÃO transicionada para etapa 1', [
        'company_id' => $company->id,
    ]);
} else {
    $resultado = app(EtapaTransicaoService::class)->transicionar($company, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $porSistema);
    if (!in_array($resultado['status'], ['transicionado', 'recusado'], true)) {
        // 'recusado' é esperado em reprocessamento (empresa já está lá) — só 'erro' é anômalo.
        Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] transição de nascimento com status inesperado', [
            'company_id' => $company->id, 'resultado' => $resultado,
        ]);
    }
}
```
**Por que não dentro do `try/catch` externo que já existe (linhas 276-288 do arquivo original, deslocado pela inserção):** o `catch (\Throwable $e)` do bloco externo marca `$evento->status = 'erro'`, mas a `Company` já foi commitada — igual ao racional já documentado para `dispararSeElegivel()` logo acima. `transicionar()` já engole `\Throwable` internamente (visto em `EtapaTransicaoService.php:297-307`) e devolve `status: 'erro'` no array — nunca lança.

---

### `app/Http/Controllers/ComercialController.php::store()` — segunda porta de nascimento (controller, request-response)

**Analog:** o próprio arquivo, mesmo padrão de "gate fora da transaction", confirmado linhas 659-671.

**Ponto de inserção exato:**
```php
// Source: app/Http/Controllers/ComercialController.php:659-671 (comentário + código reais)
// (6) Fase 128 (REDE-06) — gate administrativo de contrato, FORA da
// DB::transaction acima. Colocar a chamada logo depois do
// rotearCadastro() (dentro da transaction) seria o erro óbvio: uma
// exceção do gate ali faria rollback da Company inteira, e a empresa
// nem chegaria ao operacional — violaria o invariante da fase.
// dispararSeElegivel() já não relança (plano 03); sem try/catch
// redundante aqui, que esconderia regressão no próprio service.
// refresh() garante que o gate leia os ContratoServico recém-criados
// dentro da transaction, não a coleção em memória.
$company->refresh();
app(GatilhoContratoAdministrativoService::class)->dispararSeElegivel($company);

return back()->with('success', 'Empresa "' . $company->name . '" cadastrada com sucesso.');
```
Este é o CASO FÁCIL da D-13: `$request->user()` já está disponível de graça (rota autenticada por sessão). A chamada nova entra entre `$company->refresh();` e o `dispararSeElegivel(...)`:
```php
// NOVO:
$resultado = app(EtapaTransicaoService::class)->transicionar(
    $company,
    Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
    $request->user(),   // NUNCA um user_id cru — sempre $request->user()/auth()->user() (T-137-02)
);
if ($resultado['status'] !== 'transicionado') {
    Log::warning('[Comercial] transição de nascimento inesperada', ['company_id' => $company->id, 'resultado' => $resultado]);
}
```
Adicionar aos imports do topo do arquivo (confirmado linhas 1-24, faltam `EtapaTransicaoService` e `Log`): `use App\Services\FluxoEntrada\EtapaTransicaoService;` e `use Illuminate\Support\Facades\Log;`.

---

### `app/Http/Controllers/ComercialEntradaController.php` (controller NOVO, CRUD/listagem)

**Analog principal:** `ComercialController::listagem()`, confirmado íntegro linhas 190-424 (limite lido).

**Imports do arquivo de origem** (linhas 1-24 — reusar o que fizer sentido, não copiar tudo):
```php
// Source: app/Http/Controllers/ComercialController.php:1-24
namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Comercial\PendenciasComerciaisService;
use Illuminate\Http\Request;
use Inertia\Inertia;
```

**Padrão a copiar — sanitização snake_case com whitelist em PHP** (linhas 197-212, `listagem()`):
```php
// Source: app/Http/Controllers/ComercialController.php:197-212
$filters = [
    'servico'   => $request->input('servico'),
    'setor'     => in_array($request->input('setor'), [Servico::SETOR_PERFORMANCE, Servico::SETOR_PUBLICACAO, Servico::SETOR_OUTROS], true)
        ? $request->input('setor')
        : null,
    'ordem'     => in_array($request->input('ordem'), ['recentes', 'antigas'], true)
        ? $request->input('ordem')
        : 'recentes',
    // ... 'pendencia' com whitelist explícita das 7 chaves de PendenciasComerciaisService ...
];
```

**Padrão a copiar — critério de universo/query** (achado (c) do RESEARCH, `Company::query()->whereIn('etapa', [...])`) — **NUNCA usar `companies.etapa` para separar Contrato de Entrada entre si (D-05), mas USAR como limite externo de "quem está em fluxo de entrada"** (é o mesmo corte que fecha COMERC-03):
```php
// Novo — ComercialEntradaController::index()
$companies = Company::query()
    ->where('active', true)
    ->whereIn('etapa', [
        Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
        Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
        Company::ETAPA_AGUARDANDO_ASSINATURA,
        Company::ETAPA_ADMINISTRATIVO_CONCLUIDO,
    ])
    // D-14: etapa NULL (legado) nunca entra — whereIn já exclui NULL por
    // padrão em SQL; comentário explícito para não ser "consertado" depois.
    ->get();
```

**Padrão a copiar — contagens ANTES do filtro, paginação manual DEPOIS** (linhas 248-293, `listagem()`):
```php
// Source: app/Http/Controllers/ComercialController.php:248-293 (estrutura, não o conteúdo literal)
$todasEmpresas = $query->get();
$todasEmpresas->each(function (Company $c) use ($pendencias) { /* anota pendencias_comerciais */ });

// pendencia_counts calculado ANTES do filtro (contagens absolutas)
$pendenciaCounts = [ /* ... */ ];
foreach ($todasEmpresas as $c) { /* incrementa */ }

// filtro aplicado DEPOIS, em memória
$companies = $filters['pendencia']
    ? $todasEmpresas->filter(/* ... */)->values()
    : $todasEmpresas;

// paginação manual via LengthAwarePaginator, preservando path/query
$perPage = 50;
$page    = max(1, (int) $request->input('page', 1));
$paginator = new \Illuminate\Pagination\LengthAwarePaginator(
    $companies->forPage($page, $perPage)->values(),
    $companies->count(), $perPage, $page,
    ['path' => $request->url(), 'query' => $request->query()],
);
```

**Duas pendências, NUNCA somadas (D-11)** — payload precisa de duas chaves separadas e nomeadas:
- `pendencia_fluxo` (a da Fase 137, declarada à mão — ver `app/Models/CompanyEtapaTransicao.php` / colunas de pendência criadas por `2026_09_01_130000_add_pendencia_to_companies_table.php`)
- `pendencias_comerciais` (as 7 — não 8, ver Pitfall 5 do RESEARCH — de `PendenciasComerciaisService::calcular($c)`, mesmo helper já usado em `ComercialController.php:254`)

**Os 8 campos mínimos do §2** — nome, CNPJ, responsável comercial (`hubspot_owner_nome`), data da venda (`data_venda`), setor ECF (`setor_dominante`, já calculado em `ComercialController.php:313`), pendência do fluxo, pendências do cadastro, etapa. `hubspot_owner_nome === null` é estado NORMAL (Pitfall 2 do RESEARCH) — nunca tratar como erro na UI.

---

### `app/Http/Controllers/ContratoAdminController.php::index()` — adicionar os 8 campos ao payload (controller, CRUD)

**Analog:** o próprio arquivo — shape de linha já existe, confirmado linhas 176-243.

**Padrão do array achatado por linha, a estender** (NUNCA o model inteiro, nunca dado de signatário):
```php
// Source: app/Http/Controllers/ContratoAdminController.php:180-211 (ramo "com contrato")
$linhas->push([
    'contrato_id'                => $contrato->id,
    'company_id'                 => $company->id,
    'company_nome'                => $company->name,
    'servico_id'                  => $contratoServico->servico_id,
    'servico_nome'                => $contratoServico->servico?->nome,
    'status'                       => $contrato->status,
    'dias_parado'                  => $presos->diasParado($contrato),
    // ... 'enviado_em' / 'assinado_em' / 'liberado_em' / 'data_vencimento' já existem ...
    // NOVO (D-06/COMERC-02) — os 8 campos do §2 que ainda faltam:
    'hubspot_owner_nome'           => $company->hubspot_owner_nome,
    'data_venda'                   => optional($company->data_venda)->format('Y-m-d'),
    'etapa'                        => $company->etapa,
    // pendência do fluxo (D-11) — mesma disciplina de nunca somar com pendências comerciais.
]);
```
D-06 é explícito: "só ganha os 8 campos do §2 e a etapa" — a QUERY em si (linhas 66-81, universo por `exige_contrato`) **não muda**. `$company` já está disponível no laço externo (linha 107: `foreach ($companies as $company)`); só precisa entrar no array achatado.

---

### `resources/js/Pages/Comercial/Entrada.jsx` (componente NOVO — anti-padrão explícito)

**Analog:** `resources/js/Pages/Comercial/EmpresasListagem.jsx` (842 linhas, confirmado import block e constantes linhas 1-95).

**Imports de referência** (linhas 1-19):
```jsx
// Source: resources/js/Pages/Comercial/EmpresasListagem.jsx:1-19
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';
import { Building2, Search, /* ...ícones lucide-react... */ } from 'lucide-react';
import { cn, formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
```

**Padrão de constantes pt-BR no topo do módulo** (linhas 33-84 — labels/classes de badge por chave):
```jsx
// Source: resources/js/Pages/Comercial/EmpresasListagem.jsx:35-44
const PENDENCIAS_LABELS = {
    sem_servico:             'Sem serviço',
    sem_valor:               'Sem valor',
    servico_nao_reconhecido: 'Serviço não reconhecido',
    sem_setor:               'Sem setor (catálogo)',
    sem_contato:             'Sem contato',
    valor_revisar:           'Revisar valor',
    possivel_duplicidade:    'Possível duplicidade',
};
```
Reusar exatamente estas 7 chaves (não inventar uma 8ª — ver Pitfall 5 do RESEARCH) para a coluna de pendências comerciais.

### ⚠️ ANTI-PADRÃO EXPLÍCITO — página nova NUNCA como re-export puro

**Achado do learning do projeto** (`.planning/learnings/painel-polos-status-e-meta.md:83-88`, confirmado):
> `export { default } from '../../Ppa/Index'` **não entra no manifest do Vite**: o bundler elimina o módulo e a página morre em runtime com *"Unable to locate file in Vite manifest"*. O erro não aparece no build — só quando a rota é acessada (ou num teste Inertia).

```jsx
// NUNCA — para Entrada.jsx (página NOVA, ao contrário de Admin/Contratos.jsx que só MUDA de grupo no menu):
export { default } from '../EmpresasListagem';
```
```jsx
// CORRETO — componente de verdade, mesmo reusando bastante padrão visual de EmpresasListagem.jsx:
import AppLayout from '@/Layouts/AppLayout';
// ...imports de Table/Badge/etc...

export default function Entrada({ companies, filters }) {
    return (
        <AppLayout title="Entrada">
            {/* ...tabela com os 8 campos do §2... */}
        </AppLayout>
    );
}
```
Isto se aplica SÓ a `Entrada.jsx` (módulo novo). `Admin/Contratos.jsx` e `Admin/ContratoDetalhe.jsx` **não mudam de lugar nem de conteúdo** (D-06/Pattern 1 do RESEARCH) — só o item do `NAV_TREE` aponta para o mesmo arquivo de dentro de outro grupo.

---

### `resources/js/Layouts/AppLayout.jsx` (`NAV_TREE`) — reorganização de navegação

**Analog:** o próprio arquivo, confirmado linhas 210-292 (`NAV_TREE`, grupos `Comercial` e `Administrativo`).

**Estado ATUAL confirmado por leitura direta:**
```jsx
// Source: resources/js/Layouts/AppLayout.jsx:215-252 (grupo Comercial, hoje)
{
    group: 'Comercial',
    icon: Briefcase,
    children: [
        { label: 'Cadastro de Empresas', routeName: 'comercial.empresas.listagem', page: 'Comercial/EmpresasListagem', icon: Building2, permission: 'comercial.cadastrar_empresa' },
        { label: 'Grupos', routeName: 'comercial.empresas.listagem', routeParams: { tab: 'grupos' }, page: 'Comercial/EmpresasListagem', icon: ListChecks, permission: 'comercial.cadastrar_empresa' },
        { label: 'Onboarding', routeName: 'onboarding.painel.index', page: ['Onboarding/Painel', 'Onboarding/Detalhe'], icon: ListChecks, permission: 'core.onboarding' },
        { label: 'Serviços', routeName: 'servicos.index', page: 'Servicos', icon: Briefcase, permission: 'sistema.servicos' },
        { label: 'HubSpot Line Items', routeName: 'sistema.hubspot-line-items.index', page: 'Sistema/HubspotLineItems', icon: Link2, excludeRoles: [...] },
        { label: 'Projetos', routeName: 'mlb.projetos', page: 'Mlb/Projetos', icon: FolderKanban, permission: 'mlb.projetos' },
    ],
},

// Source: resources/js/Layouts/AppLayout.jsx:278-288 (grupo Administrativo, hoje)
{
    group: 'Administrativo',
    icon: Shield,
    children: [
        { label: 'Empresas',   routeName: 'admin.empresas',   page: 'Admin/Empresas',   icon: Building2,    permission: 'admin.empresas' },
        { label: 'Contratos',  routeName: 'admin.contratos.index', page: 'Admin/Contratos', icon: FileSignature, permission: 'admin.contratos' },
        { label: 'Relatório',  routeName: 'admin.relatorio',  page: 'Admin/Relatorio',  icon: FileBarChart, permission: 'admin.relatorio' },
        { label: 'Fechamento', routeName: 'admin.financeiro', page: 'Admin/Financeiro', icon: Banknote,     permission: 'admin.financeiro' },
        { label: 'Inventário', routeName: 'admin.inventario', page: 'Admin/Inventario', icon: Package2,     permission: 'admin.inventario' },
    ],
},
```

**Mudança a fazer (edição do array só, D-16 + reorganização):**
1. Remover a linha `{ label: 'Empresas', routeName: 'admin.empresas', ... }` do grupo `Administrativo` (D-16 — rota/controller/página sobrevivem por URL direta).
2. Remover a linha `{ label: 'Contratos', ... }` do grupo `Administrativo` e adicioná-la (idêntica: mesma `routeName`, `page`, `permission`) dentro do grupo `Comercial`.
3. Adicionar item novo `{ label: 'Entrada', routeName: 'comercial.entrada.index', page: 'Comercial/Entrada', icon: ListChecks, permission: 'comercial.entrada' }` no grupo `Comercial`.

**Nenhum arquivo `.jsx`/rota/controller de Contrato muda** — só este array. `ContratoAdminPermissaoTest` (que resolve por NOME de rota, não por grupo de menu — confirmado abaixo) continua verde.

---

### `app/Support/Permissions.php` (+`COMERCIAL_ENTRADA`)

**Analog:** o próprio arquivo — padrão `ADMIN_CONTRATOS` (linha 75) e `COMERCIAL_CADASTRAR_EMPRESA` (linha 91), confirmados.

```php
// Source: app/Support/Permissions.php:74-75 e 90-91 (padrão a replicar)
/** Fase 131 (UI-05/D-09) — tela de contratos do Administrativo: [...] */
public const ADMIN_CONTRATOS           = 'admin.contratos';
// ...
/** Cadastrar novas empresas pelo setor Comercial (acesso por setor, não automático para líderes). */
public const COMERCIAL_CADASTRAR_EMPRESA = 'comercial.cadastrar_empresa';

// NOVO (D-15 — Entrada ganha chave própria):
/** Fase 138 (COMERC-01/02/03) — módulo Entrada: casca da listagem de empresas em fluxo de entrada. */
public const COMERCIAL_ENTRADA = 'comercial.entrada';
```
E no `catalog()`, dentro do grupo `'Comercial'` já existente (linha 190-192):
```php
// Source: app/Support/Permissions.php:190-192 (grupo a estender)
'Comercial' => [
    ['key' => self::COMERCIAL_CADASTRAR_EMPRESA, 'label' => 'Cadastro de Empresas', 'description' => 'Cadastrar novas empresas pelo setor Comercial'],
    // NOVO:
    ['key' => self::COMERCIAL_ENTRADA, 'label' => 'Entrada', 'description' => 'Módulo Entrada — empresas em fluxo de entrada (checklist chega na Fase 139)'],
],
```
**Não reusar `comercial.cadastrar_empresa` para Entrada** — D-15 exige chave própria, liberável por setor sem deploy independentemente da permissão de cadastro.

---

### `app/Support/Modules.php` (+entrada no grupo `'Comercial'`)

**Analog:** o próprio arquivo, confirmado linha 220-223.

```php
// Source: app/Support/Modules.php:220-223 (grupo a estender)
'Comercial' => [
    ['key' => self::COMERCIAL_CADASTRAR_EMPRESA,  'name' => 'Comercial · Empresas', 'grupo' => 'Comercial', 'route_prefix' => 'comercial.empresas.listagem',      'permission_key' => Permissions::COMERCIAL_CADASTRAR_EMPRESA, 'stage' => 'producao'],
    ['key' => self::COMERCIAL_HUBSPOT_LINE_ITEMS, 'name' => 'HubSpot Line Items',   'grupo' => 'Comercial', 'route_prefix' => 'sistema.hubspot-line-items.index', 'permission_key' => null,                                      'stage' => 'producao'],
    // NOVO:
    ['key' => self::COMERCIAL_ENTRADA, 'name' => 'Comercial · Entrada', 'grupo' => 'Comercial', 'route_prefix' => 'comercial.entrada.index', 'permission_key' => Permissions::COMERCIAL_ENTRADA, 'stage' => 'producao'],
],
```
A constante `self::COMERCIAL_ENTRADA` (nova) segue o padrão de const já visto em `Modules.php:139-140` (`COMERCIAL_CADASTRAR_EMPRESA`, `COMERCIAL_HUBSPOT_LINE_ITEMS`).

---

### `routes/web.php` (novo grupo `comercial.entrada.*`)

**Analog:** o grupo `admin.contratos.*`, confirmado íntegro linhas 1433-1462 — a MELHOR referência porque é permission própria, FORA de qualquer `role:*`, exatamente o padrão que D-15 exige para `comercial.entrada`.

```php
// Source: routes/web.php:1433-1462 (estrutura a replicar, adaptada)
// ─── Contratos administrativos (Fase 131, UI-01/UI-05, D-09/D-10) ────────────
// FORA do grupo `role:admin` acima DE PROPÓSITO: `admin.contratos` é
// permissão PRÓPRIA, refinável na tela de setores sem deploy (D-09).
Route::middleware(['auth', 'verified', 'permission:admin.contratos'])->prefix('administrativo/contratos')->name('admin.contratos.')->group(function () {
    Route::get('/', [ContratoAdminController::class, 'index'])->name('index');
    // ...
});
```
```php
// NOVO — mesmo padrão, permission comercial.entrada (D-15), dentro do escopo
// do módulo Comercial (não misturar com o grupo 'comercial' existente em
// routes/web.php:684-687, que usa 'permission:comercial.cadastrar_empresa'
// — Entrada precisa da SUA PRÓPRIA permission, não da de cadastro):
Route::middleware(['auth', 'verified', 'permission:comercial.entrada'])->prefix('comercial/entrada')->name('comercial.entrada.')->group(function () {
    Route::get('/', [ComercialEntradaController::class, 'index'])->name('index');
});
```
**Confirmado:** o grupo Comercial existente em `routes/web.php:684-687` usa `Route::middleware('permission:comercial.cadastrar_empresa')->prefix('comercial')->name('comercial.')`. Colocar Entrada DENTRO desse grupo herdaria a permission errada — precisa ser grupo próprio, mesmo que o prefixo de URL fique aninhado (`comercial/entrada`).

**Não tocar** no grupo `admin.contratos.*` (linhas 1433-1462) além de nada — ele já está fora de `role:admin` e a permission já é a certa (D-15 preservado por construção, sem editar este bloco).

---

## Shared Patterns

### Ponto único de escrita de `companies.etapa`
**Source:** `app/Services/FluxoEntrada/EtapaTransicaoService.php::transicionar()` (linhas 212-308, confirmado íntegro)
**Apply to:** `HubspotWebhookController` e `ComercialController::store()` — os dois únicos chamadores novos desta fase.
```php
// Assinatura fixa — nunca alterar nesta fase (D-17 do CONTEXT proíbe explicitamente
// tornar $por nullable: "exigiria re-baseline de um serviço já em produção"):
public function transicionar(Company $company, string $etapaDestino, User $por, ?string $motivo = null): array
```
Retorno sempre `['status' => 'transicionado'|'recusado'|'erro', 'de' => ?string, 'para' => string, 'requisito_faltante' => ?string, 'erro' => ?string]` — nunca lança exceção (try/catch interno, linhas 214/297-307).

### Ator "nunca aceitar user_id cru de payload/request" (T-137-02, reafirmado pela D-17)
**Source:** docblock de `EtapaTransicaoService::transicionar()`, linhas 166-170.
**Apply to:** qualquer chamador — `$request->user()` quando há sessão; `User::find(config('services.hubspot.webhook_user_id'))` quando não há (webhook).

### Sanitização de filtro com whitelist snake_case em PHP
**Source:** `app/Http/Controllers/ComercialController.php:197-212` (`listagem()`) e `app/Http/Controllers/ContratoAdminController.php:266-273, 301-302, 344-346` (`index()`)
**Apply to:** `ComercialEntradaController::index()` — todo filtro vindo de query string passa por `in_array(..., $whitelist, true) ? valor : default`, nunca eco cru do `$request->input()`.

### Paginação manual via `LengthAwarePaginator`, contagens ANTES do filtro
**Source:** `ComercialController.php:257-293` e `ContratoAdminController.php:248-375`
**Apply to:** `ComercialEntradaController::index()` — contagens de pendência/resumo sempre sobre a coleção COMPLETA, filtro aplicado depois em memória, paginação por cima do resultado já filtrado.

### `Http::fake()` para mockar HubSpot em teste — nunca chamada real
**Source:** `tests/Feature/Phase34HubspotWebhookTest.php:100, 194-216, 259-281, 316-321` (confirmado, padrão consistente em toda a suíte)
```php
// Source: tests/Feature/Phase34HubspotWebhookTest.php:194-216
Http::fake([
    'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
        'results' => [['toObjectId' => 55501]],
    ]),
    'api.hubapi.com/crm/v3/objects/deals/9876*' => Http::response([
        'id' => '9876', 'properties' => [/* ... */],
    ]),
    'api.hubapi.com/crm/v3/objects/companies/55501*' => Http::response([
        'id' => '55501', 'properties' => [/* ... */],
    ]),
]);
```
**Apply to:** todo teste de `tests/Feature/Phase138/` que exercite o webhook ou `fetchOwner()` — adicionar rota fake `api.hubapi.com/crm/v3/owners/{id}*` à mesma chamada `Http::fake([...])`.

### Teste de permissão por NOME de rota, não por prefixo/grupo de menu
**Source:** `tests/Feature/Phase131/ContratoAdminPermissaoTest.php:84-101` (confirmado íntegro)
```php
// Source: tests/Feature/Phase131/ContratoAdminPermissaoTest.php:84-101
public function test_a_rota_usa_permission_admin_contratos_e_nunca_role_admin(): void
{
    $rota = Route::getRoutes()->getByName('admin.contratos.index');
    $middlewares = $rota->gatherMiddleware();

    $temPermissionCerta = collect($middlewares)->contains(fn (string $m) => $m === 'permission:'.Permissions::ADMIN_CONTRATOS);
    $temRoleAdmin = collect($middlewares)->contains(fn (string $m) => $m === 'role:admin');

    $this->assertTrue($temPermissionCerta, /* ... */);
    $this->assertFalse($temRoleAdmin, /* ... */);
}
```
**Apply to:** `tests/Feature/Phase138/ComercContratoPermissaoPreservadaTest.php` (regressão, reafirma o mesmo teste após a mudança de `NAV_TREE`) e um teste irmão para `comercial.entrada.index` (`permission:comercial.entrada`, nunca `role:admin` nem `permission:comercial.cadastrar_empresa`).

### Teste unitário do serviço de transição — reler do banco, nunca confiar no objeto em memória
**Source:** `tests/Unit/Phase137/EtapaTransicaoServiceTest.php:18-70` (confirmado)
```php
// Source: tests/Unit/Phase137/EtapaTransicaoServiceTest.php:31-44
private function empresaNaEtapa(?string $etapa): Company
{
    return Company::factory()->create(['etapa' => $etapa]);
}

public function test_destino_inexistente_e_recusado_com_requisito_nomeado(): void
{
    $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);
    $resultado = $this->service->podeTransicionar($empresa, 'etapa_que_nao_existe');
    $this->assertFalse($resultado['permitido']);
    $this->assertStringContainsString('etapa_que_nao_existe', $resultado['requisito_faltante']);
}
```
**Apply to:** `tests/Feature/Phase138/ComercEtapaNascimentoWebhookTest.php`/`ComercEtapaNascimentoCadastroManualTest.php` — não reabrir nem retestar `EtapaTransicaoService` em si (já coberto pela Fase 137); testar só o CALL SITE novo (webhook/store chamam o serviço, tratam `recusado`/`erro` sem quebrar).

## No Analog Found

| File / Tarefa | Role | Data Flow | Motivo |
|---|---|---|---|
| Conta "Sistema HubSpot" (`User` não-logável, D-17) | setup/dado | — | **Confirmado por busca nesta sessão**: não existe seeder, factory nem convenção de "usuário sistema" no projeto. `database/seeders/` só tem `DatabaseSeeder.php`, `DemoDesempenhoSeeder.php`, `DemoPainelPublicadorSeeder.php` — nenhum cria usuário não-logável. `UserFactory.php:31` sempre gera `Hash::make('password')` (senha válida, hasheada) — não serve de molde para "senha inutilizável". É tarefa de setup (migration de dados / comando artisan `db:seed` dedicado ou inserção manual), não há padrão de código a copiar. O precedente mais próximo é só o *padrão de resolução* (`config('digisac.default_user_id')`, já documentado acima como Shared Pattern) — não a criação do usuário em si. **Checkpoint humano obrigatório** (D-17 do CONTEXT já exige `checkpoint:human-verify` antes do deploy, conferindo que a conta não é logável). |
| Escopo OAuth `crm.objects.owners.read` no Private App HubSpot | configuração externa | — | Vive no painel do HubSpot, não no git. Nenhum arquivo do repositório configura escopos de Private App — só o `HUBSPOT_ACCESS_TOKEN` consome o que já foi concedido. Pitfall 3 do RESEARCH: checkpoint humano na primeira chamada real de `fetchOwner()`. |

## Metadata

**Analog search scope:** `app/Http/Controllers/`, `app/Services/`, `app/Support/`, `resources/js/Pages/Comercial/`, `resources/js/Layouts/`, `routes/web.php`, `config/services.php`, `database/migrations/`, `tests/Feature/`, `tests/Unit/Phase137/`
**Files scanned (lidos diretamente nesta sessão):** `app/Http/Controllers/ComercialController.php` (imports + linhas 185-424 + 520-680), `app/Http/Controllers/ContratoAdminController.php` (linhas 1-130, 160-405), `app/Http/Controllers/Api/HubspotWebhookController.php` (linhas 1-30, 225-305), `app/Services/HubspotApiClient.php` (linhas 1-120), `app/Services/MercadoLivreService.php` (linhas 685-710), `app/Services/Digisac/NpsDigisacDispatchService.php` (linhas 125-169), `app/Services/FluxoEntrada/EtapaTransicaoService.php` (íntegro, 333 linhas), `app/Support/Permissions.php` (íntegro, 222 linhas), `app/Support/Modules.php` (linhas 195-253), `app/Models/Company.php` (constantes ETAPA), `resources/js/Layouts/AppLayout.jsx` (linhas 190-292), `resources/js/Pages/Comercial/EmpresasListagem.jsx` (linhas 1-95), `routes/web.php` (linhas 680-730, 1388-1463), `database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php` (íntegro), `database/migrations/2026_09_01_110000_add_etapa_to_companies_table.php` (íntegro), `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` (íntegro), `tests/Unit/Phase137/EtapaTransicaoServiceTest.php` (linhas 1-70), `tests/Feature/Phase34HubspotWebhookTest.php` (linhas 1-90, 100-332), `config/services.php` (linhas 100-229), `database/factories/UserFactory.php`, `.planning/learnings/painel-polos-status-e-meta.md` (linhas 82-88), `database/seeders/` (listagem).
**Pattern extraction date:** 2026-09-02
