---
phase: 35-fix-cadastro-hubspot-v2
plan: 03
subsystem: notifications + hubspot-webhook
tags: [notifications, hubspot, audiencia, comercial, pendencias]
requires:
  - Phase 8 (BaseNotification + Categoria enum + canal database)
  - Phase 34 Plan 34-01 (campo empresa_nova + email_colaborador)
  - Phase 34 Plan 34-04 (webhook HubSpot HMAC v3 + criarEmpresa)
  - Plan 35-01 (relacao Company::mlbEmpresa + filtro CompanyController)
provides:
  - App\Notifications\EmpresaHubspotPendenteNotification (subclasse de BaseNotification)
  - App\Support\AudienciaComercial::lideresEPermissionados() (helper estatico de lookup)
  - HubspotWebhookController::calcularPendencias (private helper)
  - HubspotWebhookController::notificarComercialSePendente (dispatch defensivo)
affects:
  - tabela notifications (1 linha por destinatario, canal database)
  - log channel ecf-webhooks (info de envio + warning em casos vazios)
tech-stack:
  added: []
  patterns:
    - "Notification subclasse com parent::__construct (canal database via BaseNotification)"
    - "Audiencia union distinct: setor_lideres + effectivePermissions"
    - "Dispatch defensivo (try/catch \\Throwable) — falha aqui nao reverte estado do evento"
    - "Reflection privada em teste para invocar helper sem mudar visibilidade"
key-files:
  created:
    - app/Notifications/EmpresaHubspotPendenteNotification.php
    - app/Support/AudienciaComercial.php
    - tests/Feature/Phase35HubspotNotifyTest.php
  modified:
    - app/Http/Controllers/Api/HubspotWebhookController.php
decisions:
  - "D-09: Reutiliza Categoria::MANUAL — enum nao tem categoria HubSpot-especifica; adicionar nova esta em Deferred Ideas do MVP v3.0."
  - "D-10: Audiencia simplificada para 2 fontes (sem cargo lider-comercial — nao existe seed). Lideres via setor_lideres + permissionados via hasPermission cobrem o requisito do D-06."
  - "D-11: Dispatch APOS evento marcado processado (nao dentro da transaction) — falha de notificacao nunca reverte criacao da empresa."
  - "D-12: try/catch \\Throwable no dispatch loga warning mas nao rethrow — webhook ja respondeu 200 e empresa esta no banco."
  - "D-13: Idempotencia herdada do Plan 34-04 — 2o webhook do mesmo deal cai como ignorado antes de chamar criarEmpresa, sem dispatch redundante."
metrics:
  duration_minutes: ~22
  tasks_completed: 4
  files_created: 3
  files_modified: 1
  commits: 4
completed_at: 2026-06-17T15:08Z
---

# Phase 35 Plan 35-03: Notificação Comercial quando empresa via HubSpot tem pendências — Summary

Wave 2 da Phase 35 (paralelo com Plan 35-02 — sem conflito). Implementa a regra
**D-06**: empresa nova criada pelo webhook HubSpot com algum campo faltando
(responsável, cust_id, email_colaborador, serviço) dispara uma notificação para
o setor Comercial para que a pessoa complete o cadastro manualmente.

## Escopo Entregue

### 1. `App\Notifications\EmpresaHubspotPendenteNotification` (NOVO)

Subclasse concreta de `BaseNotification` (Phase 8) — mesmo padrão arquitetural
de `EmpresaCadastradaNotification` (Phase 14) e `AlertaEcfNotification` (Phase 29).

```php
public function __construct(Company $company, array $pendencias)
{
    $pendenciasHumanizadas = collect($pendencias)
        ->map(fn (string $slug) => self::LABELS_PENDENCIAS[$slug] ?? $slug)
        ->implode(', ');

    parent::__construct(
        titulo:      "Empresa nova via HubSpot com pendências: {$company->name}",
        mensagem:    "Pendências: {$pendenciasHumanizadas}",
        categoria:   Categoria::MANUAL,
        autorUserId: null, // Sistema (webhook) — sem autor humano
        url:         route('companies.show', $company->id),
        meta:        [
            'company_id' => $company->id,
            'pendencias' => array_values($pendencias),
            'fonte'      => 'hubspot',
        ],
    );
}
```

- **Categoria fixa = `MANUAL`** (D-09): o enum `Categoria` do MVP v3.0 não tem
  categoria específica HubSpot; `MANUAL` cobre "ação humana requerida".
- **LABELS_PENDENCIAS** mapeia slugs → pt-BR (`sem_responsavel` → "sem responsável" etc).
  Duplicação consciente das chaves do `CompanyController::index` (linhas 134-141)
  para desacoplar texto de UI vs notificação.
- **Não sobrescreve** `via()` nem `toArray()` — canal único `database` e payload
  canônico de 6 chaves vêm 100% do `BaseNotification`.

### 2. `App\Support\AudienciaComercial::lideresEPermissionados()` (NOVO)

Helper estático sem dependência de HTTP/auth — pura união Eloquent.

**União de 2 fontes distintas** (D-10):
- **(a) Líderes do setor Comercial**: `setor_lideres` pivot para `setores.slug='comercial'`.
  Reflete o conceito real do MVP — liderança é atribuição via `Setor::lideres()`,
  não cargo. Não existe cargo `lider-comercial` no seed atual.
- **(b) Permissionados**: users ativos onde `hasPermission('comercial.cadastrar_empresa')` retorna true.
  Cobre membros do setor Comercial (via `setor_permissoes`), admins (short-circuit
  em `User::hasPermission` linha 106), e qualquer setor futuro que conceda a mesma permission.

Distinct por `id`, retorna `Collection<User>`. Performance: ~50 users ativos = ~50ms (aceitável para webhook síncrono).

### 3. `HubspotWebhookController` — dispatch defensivo

3 mudanças localizadas em `app/Http/Controllers/Api/HubspotWebhookController.php`:

**a)** Imports adicionados:
```php
use App\Notifications\EmpresaHubspotPendenteNotification;
use App\Support\AudienciaComercial;
use Illuminate\Support\Facades\Notification;
```

**b)** Dispatch APÓS `criarEmpresa()` retornar e evento ser marcado `processado` (D-11):
```php
$this->notificarComercialSePendente($company, $evento);
```

**c)** 2 helpers privados:
- `calcularPendencias(Company $company): array` — espelha CompanyController::index linhas 134-139,
  EXCLUI `empresa_nova` (esperado em toda empresa criada por webhook). Retorna array de slugs.
- `notificarComercialSePendente(Company $company, HubspotEvento $evento): void` — wrapper
  defensivo (try/catch `\Throwable`) que chama `AudienciaComercial` + `Notification::send`.
  Log `info` em sucesso, `warning` em audiência vazia ou falha — **nunca rethrow** (D-12).

**Idempotência (D-13)** herdada do Plan 34-04: o filtro `jaProcessado` antes de `criarEmpresa`
garante que o 2º webhook do mesmo `object_id` é marcado `ignorado` e o método `processar`
retorna ANTES de chegar no dispatch. Resultado: 1 notification por deal, sem redundância.

### 4. `tests/Feature/Phase35HubspotNotifyTest.php` (NOVO) — 4 cases, 16 assertions

| Test | Cobertura |
|------|-----------|
| `test_empresa_completa_nao_notifica` | Cria empresa com responsável + cust_id + email_colaborador + ContratoServico ativo. Invoca `calcularPendencias` via reflection → retorna `[]`. Invoca `notificarComercialSePendente` via reflection → `Notification::assertNothingSent()`. |
| `test_empresa_sem_responsavel_notifica` | Webhook end-to-end com setup de líder Comercial. Verifica que `EmpresaHubspotPendenteNotification` foi enviada com `meta.company_id`, `meta.fonte='hubspot'`, `meta.pendencias` incluindo `sem_responsavel/sem_cust_id/sem_email_colaborador`. |
| `test_audiencia_inclui_lideres_e_permissionados` | Cria 1 líder via `setor_lideres` + 1 membro via `user_setores` (que herda `comercial.cadastrar_empresa` via `setor_permissoes`). Webhook → ambos recebem. Sanity check de `AudienciaComercial::lideresEPermissionados()` antes do dispatch. |
| `test_idempotencia_retry_nao_dispara_segunda_notificacao` | 2 webhooks consecutivos do mesmo deal — 1 Company, 1 evento processado + 1 ignorado, e `Notification::assertSentToTimes($lider, 1)`. |

## Verificação

- `php artisan test --filter="Phase35HubspotNotifyTest"` → **4 passed (16 assertions)** em 1.47s
- `php artisan test --filter="Phase34HubspotWebhook|Phase35Hubspot"` → **17 passed (95 assertions)** em 2.07s (zero regressão Plan 34-04 + Plan 35-02 + Plan 35-03 juntos)
- `php artisan test --filter="Phase31|Phase33|Phase34|Phase35"` → **70 passed (526 assertions)** em 50.43s (baseline expandido verde)
- `npm run build` → verde em 13.44s, sem warnings novos

## Deviations from Plan

**Mínimas, todas documentadas:**

1. **[Rule 2 - Security/Robustness] Dispatch defensivo com try/catch `\Throwable` (D-12).** 
   O plano original previa dispatch inline sem proteção. Adicionado wrapper porque:
   - `route()` pode falhar em ambiente sem named route resolvida (defensivo).
   - `Notification::send` toca DB (insert na `notifications`) — qualquer deadlock/timeout
     transientes não deve reverter o `evento->update(['status' => 'processado'])` já commitado.
   - Webhook já respondeu 200 ao HubSpot quando chegamos aqui — falha de notificação
     é problema interno, não algo que mereça rethrow.

2. **[Rule 3 - Schema reality] AudienciaComercial simplificada para 2 fontes (D-10).**
   O plano sugeria 3 fontes incluindo "cargo direto `lider-comercial`". Verificação
   de schema (migrations em `database/migrations/*comercial*`) confirmou que **NÃO existe
   cargo com slug `lider-comercial`** — a liderança do setor Comercial é registrada
   exclusivamente via `setor_lideres` (pivot). Removida fonte (c) por não ter destinatários
   possíveis no schema atual. Comportamento equivalente: líderes via `setor_lideres` (a)
   + permissionados via `hasPermission` (b) já cobrem 100% do requisito D-06.

3. **[Rule 1 - Schema-aware test] Test T1 usa reflection ao invés de webhook completo.**
   O webhook **sempre** cria empresa com pendências (sem responsável, sem cust_id, sem
   email_colaborador são intrínsecos a um CREATE por webhook). Para validar a regra
   "empresa completa não notifica" sem mudar o fluxo do webhook, T1 testa diretamente
   `calcularPendencias` + `notificarComercialSePendente` via reflection — invocação
   funcional do código real sem cosmética. Os testes T2/T3/T4 cobrem o fluxo end-to-end.

## Authentication Gates

Nenhum — execução autônoma, sem APIs autenticadas.

## Known Stubs

Nenhum stub. Plano não adiciona placeholders, props vazias ou TODOs.

## Pre-existing Issues (Out of Scope)

Suite completa (`php artisan test` sem filtro) tem ~45 falhas pré-existentes em testes
Phase 13/14 relacionados a colunas legacy do User renomeadas na Phase 7
(`project_legacy_columns_rename.md` na MEMORY.md). **Não causadas** por este plano —
mantidas como deferred do baseline da Phase 35-01.

## Smoke Manual Recomendado

Em ambiente dev local (não necessário para passar verificação automatizada, mas registrado para o usuário):

```bash
# 1. Garante setor Comercial + um líder
php artisan tinker
>>> $setor = Setor::firstWhere('slug', 'comercial');
>>> $lider = User::factory()->create(['role' => 'consultor', 'active' => true]);
>>> $setor->lideres()->attach($lider->id, ['assigned_at' => now()]);

# 2. Simula webhook de empresa incompleta (sem servico no catalogo)
#    Use o gerador de assinatura do Phase34HubspotWebhookTest.php (linhas 63-68).

# 3. Verifica notification persistida
>>> DB::table('notifications')->latest()->limit(5)->get();
# Espera 1 linha com data->categoria='manual', data->meta->fonte='hubspot'
```

## Gotchas / Próximos passos

- **Categoria genérica `MANUAL`** funciona mas mistura origens (notification manual
  do gestor + notification automática do webhook HubSpot). Se a UI da Phase 9 (`Notificacoes/Index.jsx`)
  precisar segmentar por origem, ler `meta.fonte === 'hubspot'` para filtrar.
- **Performance da audiência**: hoje `~50 users ativos = ~50ms`. Se a base crescer >500,
  refatorar `lideresEPermissionados` para JOIN direto com `setor_permissoes` (ver comentário
  no helper). Não bloqueia esta fase.
- **Sem categoria HubSpot dedicada**: adicionar `Categoria::EMPRESA_HUBSPOT_PENDENTE`
  está em Deferred Ideas do MVP v3.0 — quando a estrutura de Categorias for ampliada,
  trocar 1 linha em `EmpresaHubspotPendenteNotification` (`Categoria::MANUAL` → nova).

## Commits

| Hash      | Mensagem                                                                        |
| --------- | ------------------------------------------------------------------------------- |
| `fffa130` | feat(35-03): EmpresaHubspotPendenteNotification estende BaseNotification        |
| `372df78` | feat(35-03): AudienciaComercial::lideresEPermissionados — helper de lookup     |
| `6bdb0a8` | feat(35-03): HubspotWebhookController dispara notificacao Comercial pos-criacao |
| `6c3514e` | test(35-03): Phase35HubspotNotifyTest — 4 cases (16 assertions)                 |

## Self-Check

- [x] `app/Notifications/EmpresaHubspotPendenteNotification.php` FOUND
- [x] `app/Support/AudienciaComercial.php` FOUND
- [x] `tests/Feature/Phase35HubspotNotifyTest.php` FOUND
- [x] `app/Http/Controllers/Api/HubspotWebhookController.php` modified (3 imports + dispatch + 2 helpers privados)
- [x] Commits `fffa130`, `372df78`, `6bdb0a8`, `6c3514e` present in `git log`
- [x] `php artisan test --filter="Phase35HubspotNotifyTest"` → 4 passed (16 assertions)
- [x] `php artisan test --filter="Phase34HubspotWebhook|Phase35Hubspot"` → 17 passed (95 assertions)
- [x] `php artisan test --filter="Phase31|Phase33|Phase34|Phase35"` → 70 passed (526 assertions)
- [x] `npm run build` verde (sem warnings novos)

**Self-Check: PASSED**
