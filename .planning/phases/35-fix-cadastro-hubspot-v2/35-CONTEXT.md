# Phase 35: Fix Cadastro Empresas + HubSpot v2 + UX

**Gathered:** 2026-06-13
**Status:** Ready for execution (lean — sem discuss/research/plan-check)
**Source:** Pedido direto do usuário — feedback pós-uso real da Phase 34.

<domain>
## Phase Boundary

### O que esta fase entrega

Bateria de fixes + melhorias da entrada de empresas no sistema (manual via Comercial e automática via HubSpot):

1. **UX: clarificar "marketplaces extras"** — copy confunde se é serviço extra que prestamos ou marketplace que o cliente já vende. Reforçar visualmente o segundo.
2. **Valor sempre como BRL** — auditar onde valor de serviço/contrato aparece como número cru e formatar consistentemente.
3. **Backfill `empresa_nova=false` nas empresas existentes em prod** — a migration da Phase 34 marcou TODAS as 168 ativas como `empresa_nova=true` (default da coluna). Só faz sentido a tag para empresas cadastradas A PARTIR de hoje.
4. **Ordenação por created_at** na lista de empresas filtradas por `empresa_nova` — admin precisa atacar mais recentes primeiro, mas também ver antigas se quiser.
5. **HubSpot v2: criação completa** — webhook deve criar `MlbEmpresa` quando serviço for polos/publicação/etc (hoje só cria `Company`, empresa some de `/mlb/empresas`). Também fetchar contato associado pra trazer nome + telefone + email do contato comercial.
6. **Notificação Comercial** — empresa entrando via HubSpot com pendências (sem CNPJ, sem responsável, sem email colaborador, sem serviço — exclui `empresa_nova` que é esperado) notifica o time Comercial pra completar.
7. **`/companies` esconde empresas com MlbEmpresa** — não duplicar contagem entre `/companies` e `/mlb/empresas`. Empresas "puras" (sem entrada em mlb_empresas) ficam só em `/companies`. Empresas com MlbEmpresa (Polos/Publicação/Assessoria/Incubadora) só aparecem em `/mlb/empresas`.

### Estado atual investigado (2026-06-13)

- **Caso UNIQPRIME (cust=3178418055) NÃO é bug**: tem 1 grant ativo no banco local (id=84, status=active, expires 2027-03-19). Pendência "Sem grant ativo" foi um snapshot velho da UI; agora só `sem_email_colaborador` aparece. Esclarecimento: pendência lê de `company_grants` (sync local), widget Show consulta Drive em real-time. Divergência temporária = timing entre sync e API.
- **/companies vs /mlb/empresas hoje**: `/companies` lê de `companies` (tudo). `/mlb/empresas` lê de `mlb_empresas` (Polos/Publicação/etc criadas pelo Comercial via `ComercialController::servicoDisparaImplementacao`). HubSpot webhook hoje **só cria Company**, nunca MlbEmpresa.
- **`servicoDisparaImplementacao` helper** (`ComercialController` linha 53): `match` case-sensitive em `str_contains` de "Polos", "Assessoria", "Incubadora" → retorna 'polos'|'assessoria'|'incubadora'. Publicidade/Gestão/Publicação retornam null (sem mlb_empresas, só Company).
- **Empresa nova**: migration `300001` adicionou `empresa_nova` com default 1; tabela `companies` tem 168 ativas todas marcadas `empresa_nova=true` em prod.
- **HubSpot v2 mapeamento atual** (Plan 34-04 D-05): só 5 props de deal + 4 de company. Falta contato vinculado (deal → contacts) e o serviço do catálogo Comercial.

</domain>

<decisions>
## Implementation Decisions

### Backfill empresa_nova (D-01)

**D-01 — Migration de backfill em produção (LOCKED).**

Cria migration `2026_06_13_400001_backfill_empresa_nova_existentes.php`:
```php
public function up(): void
{
    // Zera empresa_nova para empresas criadas ANTES da migration original (12/06).
    // A partir de hoje (13/06), novas empresas continuam ganhando empresa_nova=true.
    DB::table('companies')
        ->where('created_at', '<', '2026-06-13 00:00:00')
        ->update(['empresa_nova' => false, 'empresa_nova_visto_em' => now(), 'empresa_nova_visto_por' => null]);
}
public function down(): void
{
    // No-op — não dá pra reverter sem perder estado humano de "marcar como visto".
}
```

`empresa_nova_visto_por=null` deixa claro que foi backfill automático.

### Ordenação por created_at (D-02)

**D-02 — Sort opcional no payload do controller (LOCKED).**

`CompanyController::index` aceita query param `?sort=nova_recente|nova_antiga`. Default mantém ordem alfabética por nome. Quando filter ativo `pendencias=empresa_nova` (a ser implementado, separado), permite ordenar.

Para versão mínima inicial: dropdown de sort no Companies/Index.jsx visível só quando aba=Pendências filtro=empresa_nova ativo. 2 opções: Mais recente / Mais antiga.

### Filtro /companies sem MlbEmpresa (D-03)

**D-03 — JOIN LEFT + WHERE NULL no controller (LOCKED).**

`CompanyController::index` adiciona `whereDoesntHave('mlbEmpresa')` (assumindo `Company::mlbEmpresa()` hasOne — verificar; senão adicionar). Lista e contadores excluem empresas Polos/Publicação/Assessoria/Incubadora.

Empresas "puras" (Publicidade/Gestão/Publicação sem mlb_empresas — verificar caso Publicação) continuam em /companies. Companies sem nenhum contrato também continuam.

**Trade-off documentado:** se uma empresa for adicionada a `mlb_empresas` depois do cadastro inicial, ela "some" de /companies. Aceitável — o admin vai pra /mlb/empresas pra ver. Não tem dupla contagem.

### HubSpot v2: contato vinculado (D-04)

**D-04 — Fetch associated contacts via API (LOCKED).**

`HubspotApiClient` ganha 2 métodos:
- `fetchAssociatedContactId(string $dealId): ?string` — `GET /crm/v3/objects/deals/{id}/associations/contacts` retorna primeiro `toObjectId`
- `fetchContact(string $id, array $properties): array` — `GET /crm/v3/objects/contacts/{id}`

Propriedades padrão buscadas no contato: `firstname`, `lastname`, `email`, `phone`. Configurável via `services.hubspot.props.contact.*`.

No webhook, contato preenche:
- `companies.email_cliente` (se contato.email && !company.email — pega do contato como fallback)
- `companies.telefone` (idem para phone)
- E o `nome_contato` (futuro — sem coluna ainda, mas registrar em `notes` neste momento — gancho pra phase futura)

### HubSpot v2: criar MlbEmpresa + MlbImplementacao (D-05)

**D-05 — Reaproveitar lógica do ComercialController (LOCKED).**

Após criar `Company` no `HubspotWebhookController::criarEmpresa`, replicar a parte de `ComercialController::store` que cria mlb_empresas. Forma:

1. Helper `ComercialController::servicoDisparaImplementacao(string $nome): ?string` é `public static` — já reutilizável.
2. Helper `ComercialController::criarImplementacaoPolo` é `private` — extrair para `app/Services/MlbImplementacaoFactory.php` ou método `public static` para webhook poder chamar.
3. Webhook depois de criar Company + (opcional) ContratoServico:
   ```php
   $tipoImpl = $servicoNome ? ComercialController::servicoDisparaImplementacao($servicoNome) : null;
   if ($tipoImpl === 'polos') {
       $mlbEmp = MlbEmpresa::create(['nome' => $company->name, 'tipo' => 'POLO', 'projeto' => 'POLOS', 'company_id' => $company->id]);
       MlbImplementacaoFactory::criarParaPolo($mlbEmp);  // ou método estático equivalente
   } elseif ($tipoImpl === 'assessoria' || $tipoImpl === 'incubadora') {
       MlbEmpresa::create(['nome' => $company->name, 'tipo' => strtoupper($tipoImpl), 'company_id' => $company->id]);
   }
   ```

### Notificação Comercial (D-06)

**D-06 — Notification + audiência composta (LOCKED).**

Criar `App\Notifications\EmpresaHubspotPendenteNotification` estendendo `BaseNotification` (Phase 8). Disparada inline no `HubspotWebhookController::criarEmpresa` após gravar evento, condicional a `hasPendencias($company)`.

`hasPendencias($company)` retorna true se faltar alguma de:
- consultor + estrategista ambos vazios (sem responsável)
- `cust_id` vazio
- `email_colaborador` vazio
- contratos ativos vazios (sem serviço)

(Exclui `empresa_nova` — é esperado nessas empresas.)

Audiência: union de 3 fontes (distinct user_ids):
- Líderes do setor Comercial: query `users` com cargo `lider-comercial` OR users em `user_setores` com `setor.slug='comercial'` AND `cargo.slug LIKE '%lider%'`
- Users com permission `comercial.cadastrar_empresa`
- Líder-de-comercial registrado no setor (similar Phase 8 AUTO_LIDERANCA)

Implementação prática: helper `App\Support\AudienciaComercial::lideresPlusPermissionados(): Collection<User>`. Reusable em outros lugares.

Conteúdo:
- title: `"Empresa nova via HubSpot com pendências: {company.name}"`
- body: lista as pendências
- link: `/companies/{id}` direto na ficha pra completar

### Audit de formato BRL (D-07)

**D-07 — Auditar fmtBRL existentes + ajustar inconsistências (LOCKED).**

Sites a auditar (em ordem):
- `resources/js/Pages/Comercial/Empresas.jsx` (cards de contrato)
- `resources/js/Pages/Companies/Show.jsx` (campos faturamento + contratos)
- `resources/js/Pages/Companies/Index.jsx` (modal admin + cell faturamento)
- `resources/js/Pages/Comercial/NovaEmpresa.jsx` (input + summary serviços)
- Outros achados via `grep -r "valor_contratado\|valor_padrao\|valor_padrão\|faturamento_mensal"` em `resources/js/Pages`

Padrão: usar `formatCurrency` de `@/lib/utils` ou helper local `fmtBRL` que retorna `Number(n).toLocaleString('pt-BR', { style:'currency', currency:'BRL' })`.

### UX: clarificar marketplaces extras (D-08)

**D-08 — Label explícito + tooltip (LOCKED).**

Substituir label atual "Vende em outras marketplaces?" por:
```
"Em quais outros marketplaces o cliente já vende?"
[Tooltip ⓘ] "Esses são marketplaces que o cliente já opera por conta própria — NÃO serviços que vamos prestar."
```

Aplicar em: `Comercial/NovaEmpresa.jsx`, `Comercial/Empresas.jsx`, `Companies/Index.jsx` (modal admin).

### Claude's Discretion

- Posição do dropdown sort (D-02) — pode ser inline ao lado do filtro de pendência ou em select próprio
- Comportamento dos sub-itens "Polos"/"Assessoria" dentro do dashboard (Não é escopo desta phase)
- Layout do contato no Show — pode usar Phase 35 pra adicionar `companies.nome_contato` mas vou deferir

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read.**

### Phase 34 (entrada empresas — base)
- `app/Http/Controllers/Api/HubspotWebhookController.php` — receiver + criarEmpresa
- `app/Services/HubspotApiClient.php` — fetchDeal/fetchAssociatedCompanyId/fetchCompany
- `config/services.php` bloco hubspot.props.*
- `app/Http/Controllers/ComercialController.php::store` — referência da lógica MlbEmpresa
- `app/Http/Controllers/ComercialController.php::servicoDisparaImplementacao` linha 53 — helper static reutilizável

### Companies + pendências
- `app/Http/Controllers/CompanyController.php::index` linhas 32-150 — payload + pendências
- `resources/js/Pages/Companies/Index.jsx` — PENDENCIAS const + tabs

### Notifications (Phase 8)
- `app/Notifications/AlertaEcfNotification.php` — referência arquitetural Phase 29 (BaseNotification)
- `app/Notifications/BaseNotification.php` — base class
- `app/Http/Middleware/HandleInertiaRequests.php::sugadores_pendentes` — exposição global (mesmo pattern serve pra notificações via sino)

### MLB empresas
- `app/Models/MlbEmpresa.php` — `$fillable`, constantes TIPO, FASE
- `app/Http/Controllers/MlbController.php::empresas` linha 883 — listagem MLB

</canonical_refs>

<specifics>
## Specific Ideas

- **Idempotência da notificação Comercial**: se HubSpot re-enviar webhook (retry), Plan 34-04 idempotência já evita criar empresa duplicada. Notificação não duplica.
- **Cargo "comercial"**: verificar via `Cargo::where('slug','comercial')->exists()`. Se não existir, query da audiência adapta pra `users.role` ou permission.
- **Migration de backfill** roda só em prod (na verdade em todo lugar); em dev local provavelmente não tem 168 empresas. Idempotente — se rodar 2x, no-op pra registros já zerados.
- **Performance do `whereDoesntHave('mlbEmpresa')`**: usa subquery NOT EXISTS, ok pra 168 empresas. Verificar se cresce muito (>10k) — mas não nesta phase.
- **Fmt BRL no input** (NovaEmpresa.jsx faturamento_mensal): input cru fica `<input type="number">`, mas display ao lado pode mostrar BRL renderizado. Decisão UX livre.

</specifics>

<deferred>
## Deferred Ideas

- Coluna `companies.nome_contato` separada (hoje captura via wizard mas não persiste fora de notes)
- UI admin pra visualizar `hubspot_eventos` (já era deferred da Phase 34)
- Comando `hubspot:reprocessar-evento {id}` (idem)
- Atualização de empresa existente via HubSpot (hoje só CREATE)
- Webhook bidirecional (ECF Admin → HubSpot)
- Toggle "Marcar como nova" manual no admin (caso quiser reativar a tag)
- Sort customizado em outras pendências (não apenas empresa_nova)

</deferred>

---

*Phase: 35-fix-cadastro-hubspot-v2*
*Context gathered: 2026-06-13*
