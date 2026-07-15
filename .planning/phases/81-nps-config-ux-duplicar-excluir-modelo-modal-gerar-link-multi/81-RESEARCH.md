# Phase 81: NPS config UX — duplicar/excluir modelo + modal gerar-link multi-step — Research

**Researched:** 2026-07-14
**Domain:** Laravel 12 + Inertia/React — CRUD de templates NPS (multi-modelo v15/v16) e UX de geração de link
**Confidence:** HIGH (todo o COMO técnico foi lido no código existente — nenhuma dependência nova, nenhum tool externo)

## Summary

As 3 features são extensões diretas de padrões já cristalizados no módulo NPS. O `NpsTemplateController`
(Phase 70) já tem `store`/`update`/`toggleActive`/`setPrincipal`/`syncServicos` — os moldes exatos para
`duplicate()` e `destroy()`. As FKs do schema (Phase 68/79) já estão desenhadas para preservar histórico
via **snapshot per-row imutável** (`nps_response_answers` + tabelas Fase 79 `nps_response_scores` etc.),
então "excluir modelo sem perder histórico" é uma decisão de **política de exclusão**, não de schema.

**Descoberta crítica (Pitfall 1):** apagar um template faz `nps_surveys.template_id` virar NULL
(`nullOnDelete`). Mas `NpsScoreCalculator::compute()` **retorna `null` quando `!$survey->template_id`**, e o
`NpsController::index` usa `template_id !== null` para escolher entre o path v15 (lê snapshot) e o path
legacy (lê colunas `score_*`, que são NULL em respostas v15). Resultado: apagar um modelo com respostas
**some com as notas daquelas respostas no dashboard NPS** — mesmo com o snapshot intacto no banco. Isso
torna `nullOnDelete` insuficiente sozinho. **Recomendação forte: BLOQUEAR a exclusão quando o template tiver
qualquer survey com resposta** (preserva histórico E leitura correta); oferecer o `toggleActive` (arquivar)
como alternativa não-destrutiva já existente.

O modal "Gerar link" é reordenação de UX + 1 endpoint novo de empresas elegíveis, reusando a lógica de
"serviços cobertos ∩ contratos ativos" já presente em `NpsTemplateService::resolveForCompany` (invertida).

**Primary recommendation:** `duplicate()` clona em transação (config + perguntas + opções + service_scopes,
`is_default=false`); `destroy()` bloqueia is_default E bloqueia se houver respostas; modal vira modelo-first
com endpoint `GET .../templates/{template}/empresas-elegiveis`. Tudo no grupo `role:admin` existente, rotas
`nps.configuracao.templates.*`. Testes em `tests/Feature/V16/`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Duplicar modelo (clone config+árvore) | API/Backend (`NpsTemplateController@duplicate`) | Database (transação + LogsActivity) | Regra de clonagem e integridade de FK são responsabilidade do controller/model |
| Excluir modelo (guardas is_default/respostas) | API/Backend (`NpsTemplateController@destroy`) | Database (política de FK) | Guarda de negócio no controller; FK só é rede de segurança |
| Botões Duplicar/Excluir na config | Frontend (`TemplateEditForm.jsx` / `TemplatesGrid.jsx`) | — | UX de ação, espelha `toggleActive`/`setPrincipal` |
| Empresas elegíveis por modelo | API/Backend (endpoint JSON dedicado) | Database (query scope∩contrato) | Filtro de dados é server-side (evita inflar payload inicial) |
| Modal gerar-link multi-step | Frontend (`Nps/Index.jsx`) | API (fetch das elegíveis) | Reordenação/estado do wizard é client-side; a fonte de verdade é o endpoint |

## Standard Stack

Nenhuma dependência nova. 100% reuso do stack já instalado.

| Camada | Ferramenta existente | Uso nesta fase |
|--------|----------------------|----------------|
| Backend controller | `App\Http\Controllers\NpsTemplateController` | Adicionar `duplicate()` + `destroy()` + `empresasElegiveis()` |
| Transação/clone | `Illuminate\Support\Facades\DB::transaction` | Clonagem atômica (já usado em `setPrincipal`) |
| Models/relations | `NpsTemplate` (`questions`, `options` via question, `servicos`/`serviceScopes`, `surveys`) | Ler para clonar / contar para guarda |
| Resolver serviço∩contrato | `NpsTemplateService` / `Company::contratosServico()->active()` | Base para inverter em "empresas elegíveis" |
| Frontend ação | Padrão `router.patch/post/delete` do `@inertiajs/react` | Botões Duplicar/Excluir (espelha `alternarAtivo`/`definirPrincipal`) |
| UI | shadcn/ui (`Dialog`, `Select`, `Button`), tokens `ecf-*`, `cn()` | Modal multi-step + botões |
| Rotas JS | Ziggy `route()` | Nomes `nps.configuracao.templates.*` |

**Installation:** nenhuma. `npm run build` após alterações de JSX (gotcha do `.map()` — ver Pitfall 4).

> Sem `## Package Legitimacy Audit` e sem `## Environment Availability` — a fase não instala pacotes nem
> depende de tools externas (só código/rotas/DB já presentes).

## Architecture Patterns

### Fluxo das 3 features

```
DUPLICAR
  TemplateEditForm/TemplatesGrid (botão "Duplicar")
    → POST nps.configuracao.templates.{template}.duplicar   [role:admin]
      → NpsTemplateController@duplicate
        → DB::transaction:
             clone NpsTemplate (is_default=FALSE, nome="… (cópia)")
             foreach questions → clone NpsTemplateQuestion (novo template_id)
                foreach options → clone NpsTemplateOption (novo question_id)
             clone pivot nps_template_service_scopes (servicos()->sync(ids originais))
        → back()/redirect editor do novo modelo

EXCLUIR
  TemplateEditForm (botão "Excluir")
    → DELETE nps.configuracao.templates.{template}   [role:admin]
      → NpsTemplateController@destroy
        → GUARDA 1: is_default → abort(422) "troque o principal antes"
        → GUARDA 2: surveys()->whereHas('response')->exists() → abort(422) "tem respostas; arquive"
        → $template->delete()   (cascade limpa questions/options/scopes; surveys.template_id nullOnDelete)

GERAR LINK (modal modelo-first)
  Nps/Index.jsx
    passo 1: Select MODELO (obrigatório)
      → onChange: GET nps.configuracao.templates.{template}.empresas-elegiveis  [fetch JSON]
    passo 2: Select EMPRESA (só as elegíveis retornadas)
      → POST nps.generate {company_id, template_id}   (já existe)
```

### Pattern 1: Clonagem atômica de árvore (duplicate)

**What:** clonar template + filhos numa transação, resetando invariantes.
**When:** `NpsTemplateController@duplicate`.
**Molde:** o `setPrincipal` (linhas 188-210) já mostra o uso de `DB::transaction`. Os fillables são conhecidos:
`NpsTemplate` (`nome, descricao, active, is_default, priority, envio_automatico_mensal, mensagem_whatsapp`),
`NpsTemplateQuestion` (`template_id, texto, tipo, dimensao, obrigatoria, ordem`),
`NpsTemplateOption` (`question_id, label, peso, ordem`).

```php
// [VERIFIED: código lido — app/Http/Controllers/NpsTemplateController.php store/setPrincipal + models fillable]
public function duplicate(NpsTemplate $template)
{
    $novo = DB::transaction(function () use ($template) {
        $clone = $template->replicate([
            // nunca copiar timestamps; forçar invariantes
        ]);
        $clone->is_default = false;              // INVARIANTE — só o seed pode ser default
        $clone->nome       = $template->nome . ' (cópia)';
        // active/priority/envio_automatico_mensal/mensagem_whatsapp herdam do original
        $clone->save();

        // Perguntas + opções (relations já ordenadas por ordem/id)
        foreach ($template->questions as $q) {
            $qClone = $q->replicate();
            $qClone->template_id = $clone->id;
            $qClone->save();
            foreach ($q->options as $o) {
                $oClone = $o->replicate();
                $oClone->question_id = $qClone->id;
                $oClone->save();
            }
        }

        // Service scopes (pivot) — sync com os ids do original
        $clone->servicos()->sync($template->servicos()->pluck('servicos.id')->all());

        return $clone;
    });

    return back()->with('success', "Modelo \"{$novo->nome}\" criado a partir de \"{$template->nome}\".");
}
```

> Nota: `$q->options` — a relation em `NpsTemplateQuestion` é `options()` (question_id). Fazer eager-load
> (`$template->load('questions.options')`) antes do loop para evitar N+1. `replicate()` já ignora PK e
> timestamps; ainda assim setar explicitamente os campos-invariante.

### Pattern 2: Destroy com guardas de negócio (não confiar só na FK)

**What:** bloquear exclusão perigosa ANTES do delete; delegar limpeza de config à FK cascade.
**When:** `NpsTemplateController@destroy`.

```php
// [VERIFIED: FKs lidas nas migrations 100001/100002 + NpsScoreCalculator]
public function destroy(NpsTemplate $template)
{
    // Guarda 1 — nunca apagar o principal (espelha update/toggleActive).
    if ($template->is_default) {
        abort(422, 'O modelo principal não pode ser excluído. Defina outro modelo como principal antes.');
    }

    // Guarda 2 — preservar histórico E leitura do dashboard (ver Pitfall 1).
    // Se houver QUALQUER survey respondida, bloquear e sugerir arquivar (toggleActive).
    $temRespostas = $template->surveys()->whereHas('response')->exists();
    if ($temRespostas) {
        abort(422, 'Este modelo tem respostas associadas. Desative-o (arquivar) em vez de excluir — '
                 . 'assim o histórico e as métricas continuam corretos.');
    }

    $nome = $template->nome;
    $template->delete();  // cascade: questions/options/service_scopes; surveys pendentes → template_id NULL

    return back()->with('success', "Modelo \"{$nome}\" excluído.");
}
```

**Comportamento das FKs (verificado nas migrations):**

| FK | Estratégia | Efeito no delete do template |
|----|-----------|------------------------------|
| `nps_template_questions.template_id` | `cascadeOnDelete` | Perguntas apagadas (config) |
| `nps_template_options.question_id` | `cascadeOnDelete` | Opções apagadas (via cascade da pergunta) |
| `nps_template_service_scopes.template_id` | `cascadeOnDelete` | Pivot limpo |
| `nps_surveys.template_id` | `nullOnDelete` | Survey sobrevive, vira `template_id=NULL` |
| `nps_response_answers.template_question_id/option_id` | `nullOnDelete` | Snapshot (`*_snapshot`) preservado; FK viva zerada |
| `nps_response_scores/covered_services/assignments` (Fase 79) | Cascade de `nps_responses`, NÃO referenciam template | Sobrevivem intactos |

Por isso a Guarda 2 é a escolha segura: mesmo com snapshot preservado, o `template_id=NULL` degrada a
leitura do dashboard NPS (Pitfall 1). Bloquear é mais simples e correto que tentar reescrever o path de
leitura.

### Pattern 3: Empresas elegíveis por modelo (inversão do resolveForCompany)

**What:** dado um template, listar empresas com contrato ativo de um serviço coberto.
**When:** `NpsTemplateController@empresasElegiveis` (JSON, chamado via fetch no modal).

```php
// [VERIFIED: NpsTemplateService::resolveForCompany + Company::contratosServico + ContratoServico::scopeActive]
public function empresasElegiveis(Request $request, NpsTemplate $template)
{
    $user = $request->user();

    $servicoIds = $template->servicos()->pluck('servicos.id');

    $query = Company::query()
        ->where('active', true)
        ->whereHas('contratosServico', fn ($q) => $q->active()->whereIn('servico_id', $servicoIds))
        ->orderBy('name');

    // Escopo por carteira para não-admin (espelha NpsController::generate/index).
    if (! $user->isAdmin()) {
        $query->whereIn('id', $user->companies()->pluck('companies.id'));
    }

    return response()->json([
        'template_id' => $template->id,
        'empresas'    => $query->get(['id', 'name']),
    ]);
}
```

> `whereIn('servico_id', $servicoIds)` com coleção vazia gera `WHERE 0=1` → retorna vazio. Isso casa com a
> validação "modelo sem cobertura → vazio". **Ver Open Question 1** sobre o caso especial do modelo
> principal/`is_default` (que hoje é o fallback universal e provavelmente deveria listar TODAS as empresas).

### Recommended Project Structure (arquivos tocados)

```
app/Http/Controllers/NpsTemplateController.php   # + duplicate(), destroy(), empresasElegiveis()
routes/web.php                                    # + 3 rotas no grupo role:admin (bloco templates ~L157-169)
resources/js/Components/Nps/Config/TemplateEditForm.jsx   # + botões Duplicar/Excluir (header do editor)
resources/js/Components/Nps/Config/TemplatesGrid.jsx      # (opcional) ação Duplicar direto no card
resources/js/Pages/Nps/Index.jsx                 # modal gerar-link: modelo-first + filtro elegíveis
tests/Feature/V16/                                # novos testes (ver Validation Architecture)
```

### Anti-Patterns to Avoid

- **Confiar no `nullOnDelete` para "preservar histórico" na exclusão:** preserva o registro mas quebra a
  leitura do dashboard (Pitfall 1). Bloquear se houver respostas.
- **Clonar sem transação:** um erro no meio deixa um template órfão sem perguntas. Sempre `DB::transaction`.
- **Copiar `is_default=true` no clone:** viola o unique parcial (`QueryException 23000`) e o invariante do
  seed. Forçar `false`.
- **Inflar o payload inicial de `/nps` com o mapa modelo→empresas:** preferir o endpoint dedicado
  (decisão DEC-81-3).
- **Computar variável de escopo do componente dentro do `.map()` e usá-la fora:** gotcha de bundling Rollup
  (Pitfall 4).

## Don't Hand-Roll

| Problema | Não construir | Usar | Porquê |
|----------|---------------|------|--------|
| Clonar model + PK/timestamps | Copiar campo a campo manual | `Model::replicate($except)` | Ignora PK/timestamps automaticamente; menos erro |
| Clonar pivot de serviços | Loop de insert manual na pivot | `$clone->servicos()->sync($ids)` | Já usado em `syncServicos`; atômico |
| Guarda "único principal" | Checar unicidade em PHP | Unique parcial `nps_templates_default_uniq` + `is_default=false` no clone | Banco garante; PHP só evita a colisão |
| Resolver serviço∩contrato ativo | Query nova do zero | Inverter `NpsTemplateService` / `ContratoServico::scopeActive` | Lógica canônica já testada (Fase 79) |
| Filtro por carteira | Reimplementar RBAC | `$user->companies()->pluck(...)` + `$user->isAdmin()` | Padrão consolidado (Phase 62) |

**Key insight:** todo o "difícil" (schema, snapshot, resolver, RBAC, unique parcial) já existe. Esta fase é
composição de peças testadas — o risco real é o Pitfall 1 (política de exclusão), não a implementação.

## Common Pitfalls

### Pitfall 1: Excluir modelo com respostas some com as notas no dashboard
**What goes wrong:** apagar o template zera `nps_surveys.template_id` (nullOnDelete). O `NpsController::index`
usa `survey->template_id !== null` para decidir o path de leitura; o `NpsScoreCalculator::compute()` retorna
`null` quando `!$survey->template_id`. Respostas v15 têm `score_estrategista/analista/empresa = NULL`. Logo,
as respostas do modelo apagado passam a ler como legacy → notas nulas → cards/série/lista do dashboard
mostram 0/vazio para aquele histórico.
**Why it happens:** o snapshot em `nps_response_answers` sobrevive, mas o **gatilho de leitura** depende do
`template_id` vivo, que foi anulado.
**How to avoid:** **bloquear a exclusão** quando `surveys()->whereHas('response')->exists()`. Oferecer
`toggleActive` (arquivar) como caminho não-destrutivo. As tabelas de snapshot da Fase 79
(`nps_response_scores`) sobrevivem e o bônus da Fase 80 não é afetado — mas o dashboard NPS sim.
**Warning signs:** teste que apaga template com resposta e depois checa que o card do mês caiu para 0.

### Pitfall 2: Clone herdando `is_default=true` → QueryException 23000
**What goes wrong:** `replicate()` copia `is_default`. Se o original for o principal, o insert do clone colide
no unique parcial.
**How to avoid:** setar `is_default=false` explicitamente no clone (ver Pattern 1). Já é decisão travada
(DEC-81-1: "is_default=false SEMPRE").
**Warning signs:** duplicar o "NPS Padrão" (principal) estoura 23000.

### Pitfall 3: Modal filtra empresas mas o modelo principal não tem service scopes
**What goes wrong:** o seed "NPS Padrão" (`is_default`) é o fallback universal e normalmente **não tem
serviços no pivot**. O filtro `scope∩contrato` retorna vazio → admin não consegue gerar link do principal
para ninguém pelo modal.
**How to avoid:** decidir o caso especial do `is_default` (Open Question 1). Recomendação: para o template
`is_default`, retornar todas as empresas ativas (espelha a semântica de fallback do `resolveForCompany`);
para os demais, `scope∩contrato` estrito.
**Warning signs:** selecionar o modelo principal no modal e a lista de empresas vir vazia.

### Pitfall 4: Variável de escopo do componente usada dentro do `.map()` quebra no bundle
**What goes wrong:** computar uma flag no escopo do componente e referenciá-la dentro do callback do `.map()`
pode gerar `ReferenceError` no bundle Rollup/Vite de produção (histórico do projeto).
**How to avoid:** computar flags booleanas **dentro** do callback do `.map()`. Ao renderizar a lista de
empresas elegíveis / modelos no modal, derivar tudo por item dentro do map.
**Warning signs:** funciona em `npm run dev`, quebra em `npm run build`.

### Pitfall 5: Duplicar sem eager-load → N+1 em `questions.options`
**How to avoid:** `$template->load('questions.options')` antes do loop de clonagem.

## Code Examples

### Rotas (adicionar ao grupo `role:admin` existente, junto ao bloco de templates ~L157-169)
```php
// [VERIFIED: routes/web.php L117 grupo ['auth','verified','role:admin'] + bloco templates L157-169]
Route::post  ('/nps/configuracao/templates/{template}/duplicar',
    [NpsTemplateController::class, 'duplicate'])
    ->name('nps.configuracao.templates.duplicate');

Route::delete('/nps/configuracao/templates/{template}',
    [NpsTemplateController::class, 'destroy'])
    ->name('nps.configuracao.templates.destroy');

Route::get   ('/nps/configuracao/templates/{template}/empresas-elegiveis',
    [NpsTemplateController::class, 'empresasElegiveis'])
    ->name('nps.configuracao.templates.empresas-elegiveis');
```
> `{template}` único não precisa de `scopeBindings()` (só rotas aninhadas usam). O route model binding já dá
> 404 se o id não existir.

### Frontend — botão Excluir (espelha `alternarAtivo`/`definirPrincipal` do TemplateEditForm)
```jsx
// [VERIFIED: padrão lido em resources/js/Components/Nps/Config/TemplateEditForm.jsx L219-254]
const duplicar = () => {
    router.post(route('nps.configuracao.templates.duplicate', template.id), {}, {
        preserveScroll: true,
        onSuccess: () => { mostrarToast?.(); onSaved?.(); },  // parent recarrega only:['templates']
    });
};

const excluir = () => {
    if (template.is_default) return; // UI guard — botão deve vir disabled nesse caso
    if (!confirm(`Excluir o modelo "${template.nome}"? Esta ação não pode ser desfeita.`)) return;
    router.delete(route('nps.configuracao.templates.destroy', template.id), {
        preserveScroll: true,
        onSuccess: () => { mostrarToast?.(); onSaved?.(); },
        // erro 422 (is_default / tem respostas) cai no flash de erro global
    });
};
```
> UI: desabilitar "Excluir" quando `template.is_default` (mesma lógica de `bloquearDesativacao`). O flash de
> erro do `abort(422)` já é surfaced pelo `HandleInertiaRequests`.

### Frontend — modal gerar-link modelo-first (Nps/Index.jsx)
```jsx
// [CITED: resources/js/Pages/Nps/Index.jsx L864, L1106-1157 — form atual company_id/template_id]
// Passo 1: template_id passa a ser required (remover a opção "__auto__").
// Ao escolher o modelo, buscar elegíveis e resetar company_id.
const onSelectModelo = async (id) => {
    setData(d => ({ ...d, template_id: id, company_id: '' }));
    const { data: res } = await window.axios.get(
        route('nps.configuracao.templates.empresas-elegiveis', id)
    );
    setEmpresasElegiveis(res.empresas); // renderizar no Select de empresa
};
// submit continua POST nps.generate {company_id, template_id} — backend já aceita (L353-412).
```

## Runtime State Inventory

> Não se aplica. Esta fase é adição de features (novos métodos/rotas/UX) sobre schema existente — não é
> rename/refactor/migração de strings. Nenhum estado runtime (dados armazenados, config de serviço externo,
> registro de OS, secrets, artefatos de build) muda de nome ou chave. **Verificado:** nenhuma migration de
> dados nem renomeação envolvida; `duplicate`/`destroy` operam sobre tabelas já existentes.

## Validation Architecture

**Framework:** PHPUnit 11.x. Config `phpunit.xml`. Testes em `tests/Feature/V16/` (convenção da milestone;
`namespace Tests\Feature\V16;`, `use RefreshDatabase;`). SQLite in-memory nos testes (atenção ao gotcha de
enum/CHECK do MEMORY.md — não aplicável aqui, sem migration nova).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5 (Laravel) |
| Config file | `phpunit.xml` |
| Quick run | `php artisan test --filter=<Classe>` |
| Full suite (fase) | `php artisan test tests/Feature/V16` |
| Build front | `npm run build` (checkpoint visual do modal) |

### Phase Requirements → Test Map
| Req (DEC) | Comportamento | Tipo | Comando | Arquivo |
|-----------|---------------|------|---------|---------|
| DEC-81-1 | duplicate clona config+perguntas+opções+scopes; clone `is_default=false`; original **intocado** | feature | `php artisan test --filter=DuplicarModeloTest` | ❌ Wave 0 `tests/Feature/V16/DuplicarModeloTest.php` |
| DEC-81-1 | duplicar o principal NÃO propaga is_default (sem 23000) | feature | idem | idem |
| DEC-81-2 | destroy de modelo não-principal SEM respostas funciona (cascade limpa filhos) | feature | `php artisan test --filter=ExcluirModeloTest` | ❌ Wave 0 `tests/Feature/V16/ExcluirModeloTest.php` |
| DEC-81-2 | destroy do `is_default` → 422 (bloqueado) | feature | idem | idem |
| DEC-81-2 | destroy de modelo COM resposta → 422 (histórico/dashboard preservado) | feature | idem | idem |
| DEC-81-3 | empresas-elegiveis: modelo Shopee → só empresas c/ Gestão ADS Shopee ativa | feature | `php artisan test --filter=EmpresasElegiveisTest` | ❌ Wave 0 `tests/Feature/V16/EmpresasElegiveisTest.php` |
| DEC-81-3 | modelo Performance → empresas ML; modelo sem scopes → vazio (+ caso is_default, ver OQ1) | feature | idem | idem |
| DEC-81-3 | não-admin: elegíveis limitado à carteira | feature | idem | idem |
| Regressão | CRUD templates (store/update/toggle/setPrincipal) e `nps.generate` intactos | feature | `php artisan test tests/Feature/Phase70 tests/Feature/Phase71` | ✅ existe |
| Frontend | build verde + modal modelo-first com filtro | manual/checkpoint | `npm run build` | checkpoint visual |

### Sampling Rate
- **Por task/commit:** `php artisan test --filter=<Classe do task>`
- **Por wave merge:** `php artisan test tests/Feature/V16`
- **Phase gate:** `php artisan test` (suíte cheia) verde + `npm run build` verde antes de `/gsd:verify-work`.

### Wave 0 Gaps
- [ ] `tests/Feature/V16/DuplicarModeloTest.php` — cobre DEC-81-1
- [ ] `tests/Feature/V16/ExcluirModeloTest.php` — cobre DEC-81-2 (3 cenários)
- [ ] `tests/Feature/V16/EmpresasElegiveisTest.php` — cobre DEC-81-3
- [ ] Fixtures: helper para criar template+perguntas+opções+scopes + empresa com contrato ativo. Reusar o
      padrão de `tests/Feature/V16/SubmitSnapshotTest.php` e o trait `CriaCenarioResponsaveis` (já em V16).

## Security Domain

Baixa superfície — as 3 features são admin-only. `security_enforcement` não está setado no config.json;
tratar como habilitado com controles mínimos.

### ASVS aplicável
| Categoria | Aplica | Controle padrão |
|-----------|--------|-----------------|
| V4 Access Control | sim | Grupo `role:admin` (middleware `EnsureUserHasRole`) em todas as 3 rotas; endpoint elegíveis também escopa por carteira p/ não-admin |
| V5 Input Validation | sim | Route model binding (404 em id inválido); `destroy` valida invariantes de negócio via `abort(422)` |
| V6 Cryptography | não | — |

### Threats do stack
| Padrão | STRIDE | Mitigação |
|--------|--------|-----------|
| Excluir o modelo principal (quebrar `resolveForCompany`) | Denial of Service | Guarda `is_default` → 422 (espelha `update`/`toggleActive`) |
| Excluir modelo com histórico (perda de métricas) | Tampering | Guarda "tem respostas" → 422 (Pitfall 1) |
| Não-admin enumerar/gerar link de empresa fora da carteira | Info Disclosure | `whereIn` por carteira no endpoint + guard já existente em `nps.generate` |
| Duplicata promovida a principal via payload | Elevation | `is_default=false` forçado no clone (não vem do request) |

## Assumptions Log

| # | Claim | Section | Risco se errado |
|---|-------|---------|-----------------|
| A1 | Bloquear exclusão quando há respostas é a política preferida vs `nullOnDelete` | Pitfall 1 / DEC-81-2 | Se o usuário preferir permitir excluir preservando via snapshot, precisaria também corrigir o path de leitura do dashboard (mais trabalho). CONTEXT já pede "preservar histórico" → bloquear é conservador. |
| A2 | Para o modelo `is_default`, o modal deveria listar TODAS as empresas (fallback universal) | Pitfall 3 / OQ1 | Se mantido estrito (vazio), admin não gera link do principal pelo modal — provável reclamação de UX. |
| A3 | `active` do original é herdado no clone (clone nasce ativo se o original for ativo) | Pattern 1 | Se o esperado for clone sempre ativo, ajustar; baixo impacto. |
| A4 | Botões Duplicar/Excluir vão no `TemplateEditForm` (tela de edição), não no card | Structure | Pura escolha de UX; discretion do CONTEXT. |

## Open Questions

1. **Modelo principal (`is_default`) no modal gerar-link.** O `is_default` normalmente não tem service
   scopes (é fallback). Com filtro estrito, sua lista de empresas elegíveis vem vazia.
   - Sei: `resolveForCompany` usa o `is_default` como fallback universal para empresas sem serviço coberto.
   - Não sei: se produto quer o principal gerável para qualquer empresa via modal.
   - Recomendação: caso especial — `is_default` → todas as empresas ativas (escopadas por carteira p/
     não-admin); demais modelos → `scope∩contrato` estrito. Confirmar com o usuário no plan/discuss.

2. **Pós-duplicar: abrir o editor do novo modelo ou voltar à lista?** DEC-81-1 aceita ambos.
   - Recomendação: `back()` + `router.reload({only:['templates']})` e abrir o editor do clone (setSelectedId
     com o id retornado). Requer o controller retornar o id (usar flash ou `Inertia::location`/prop). Se
     preferir simplicidade, só recarregar a lista.

3. **Nome do clone editável imediatamente?** DEC-81-1 diz `"{nome} (cópia)"` editável. O `TemplateEditForm`
   já tem autosave (debounced PUT) — abrir o editor do clone já permite renomear. Sem trabalho extra.

## State of the Art

Nada externo mudou — é um módulo interno. Contexto de versões do módulo:

| Antes | Agora | Impacto |
|-------|-------|---------|
| Templates só criáveis do zero (`store`) | + `duplicate()` (clone) | Admin parte de um modelo existente (ex.: Performance → Shopee) |
| Templates só arquiváveis (`toggleActive`, sem DELETE) | + `destroy()` com guardas | Exclusão real de modelos sem histórico; arquivar continua p/ os com histórico |
| Modal gerar-link: empresa-first, modelo opcional (`__auto__`) | modelo-first obrigatório + filtro de empresas | Evita gerar link com empresa fora da cobertura do modelo |

**Nota de reconciliação (dev paralelo anunciar-ml):** existem `tests/Feature/Phase81/DuplicarComoTemplateTest.php`
e `tests/Feature/Phase79/*` que NÃO pertencem a esta fase NPS (são do fluxo Product Ads/ML paralelo). Esta
fase escreve em `tests/Feature/V16/`. Reconciliar antes do deploy conforme o CONTEXT.

## Sources

### Primary (HIGH — código lido nesta sessão)
- `app/Http/Controllers/NpsTemplateController.php` — store/update/toggleActive/setPrincipal/syncServicos/empresasAfetadas (moldes)
- `app/Http/Controllers/NpsController.php` — index (props do /nps + path v15/legacy `notaDe`), generate (aceita template_id)
- `app/Models/NpsTemplate.php` — fillable, casts, relations (questions/perguntas/servicos/serviceScopes/surveys), principalId
- `app/Models/NpsTemplateQuestion.php` / `NpsTemplateOption.php` — fillable, TIPOS/DIMENSOES
- `app/Services/Nps/NpsTemplateService.php` — resolveForCompany (serviço∩contrato)
- `app/Services/Nps/NpsScoreCalculator.php` — retorna null se `!survey->template_id` (Pitfall 1)
- `database/migrations/2026_07_07_100001` / `100002` / `2026_07_14_200001` — FKs (cascade/nullOnDelete)
- `routes/web.php` L117-248 — grupo role:admin + bloco `nps.configuracao.templates.*`
- `resources/js/Pages/Nps/Index.jsx` — modal gerar-link L1106-1157, props/useForm L832-909
- `resources/js/Pages/Nps/Configuracao.jsx` + `Components/Nps/Config/TemplatesGrid.jsx` / `TemplateEditForm.jsx` — UI + padrão de mutação
- `app/Models/ContratoServico.php` (scopeActive `ativo=true`) / `Company.php` (contratosServico)
- `.planning/phases/81-.../81-CONTEXT.md` — decisões travadas

### Secondary
- `tests/Feature/V16/SubmitSnapshotTest.php` / `ShopeeSelectsEscopadosTest.php` — convenção de teste V16
- `CLAUDE.md`, `MEMORY.md` — convenções, gotcha `.map()`/Rollup, enum+SQLite

## Metadata

**Confidence breakdown:**
- Duplicar: HIGH — molde `store`/`setPrincipal` + fillables lidos; `replicate()` é padrão Laravel.
- Excluir: HIGH — FKs verificadas nas migrations; Pitfall 1 confirmado no `NpsScoreCalculator`/`NpsController`.
- Modal/elegíveis: HIGH — `resolveForCompany` e relações de contrato lidos; inversão trivial. Única
  incerteza é de PRODUTO (OQ1), não técnica.

**Research date:** 2026-07-14
**Valid until:** enquanto o schema NPS v15/v16 não mudar (estável; ~30 dias).
