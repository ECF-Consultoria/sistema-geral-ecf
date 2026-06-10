---
phase: quick-260609-nqr
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Controllers/ComercialController.php
  - resources/js/Pages/Comercial/Empresas.jsx
  - app/Services/DashboardEcfService.php
  - app/Console/Commands/DashboardEcfSync.php
  - app/Http/Controllers/DashboardEcfController.php
  - resources/js/Pages/DashboardEcf/Index.jsx
autonomous: true
requirements: [AGRUP-VINCULO, AGRUP-FILTRO]

must_haves:
  truths:
    - "No modal de editar empresa do Comercial, o usuário escolhe a empresa principal (dono) à qual a empresa pertence, gravando parent_company_id"
    - "O sistema impede ciclo (empresa não pode ser pai dela mesma nem de um ancestral) e limita a 1 nível de profundidade"
    - "Na listagem de empresas do Comercial, uma empresa principal mostra visualmente que é principal e quantas vinculadas tem"
    - "No Dashboard ECF, o usuário troca entre 'Geral' e cada grupo cadastrado e os KPIs/gráficos refletem só os custIds daquele grupo"
    - "O sync lê os arquivos brutos do ECF Drive uma única vez e produz o agregado geral + um agregado por grupo"
  artifacts:
    - path: "app/Http/Controllers/ComercialController.php"
      provides: "Validação + persistência de parent_company_id no update/store com anti-ciclo e limite de 1 nível; prop de candidatas a principal"
      contains: "parent_company_id"
    - path: "resources/js/Pages/Comercial/Empresas.jsx"
      provides: "Seletor de empresa principal no FormularioEditar + indicador visual de principal na EmpresaRow"
      contains: "parent_company_id"
    - path: "app/Services/DashboardEcfService.php"
      provides: "Acumulação por custId em leitura única + redução para agregado geral e por grupo"
      contains: "porCustId"
    - path: "app/Console/Commands/DashboardEcfSync.php"
      provides: "Carrega grupos (principais + filhas com custId) e passa ao service"
      contains: "filhas"
    - path: "resources/js/Pages/DashboardEcf/Index.jsx"
      provides: "Seletor de grupo que troca o payload exibido entre Geral e cada grupo"
      contains: "grupo"
  key_links:
    - from: "ComercialController::update"
      to: "companies.parent_company_id"
      via: "validação + $company->update"
      pattern: "parent_company_id"
    - from: "DashboardEcfService::computar"
      to: "Company::cust_id"
      via: "mapa custId→grupo construído a partir dos grupos passados pelo comando"
      pattern: "porCustId"
    - from: "DashboardEcf/Index.jsx"
      to: "dados.grupos"
      via: "seletor client-side troca o payload"
      pattern: "grupos"
---

<objective>
Entregar o agrupamento de empresas por dono (empresa principal + vinculadas via
`companies.parent_company_id`, que JÁ existe) com duas frentes:

- **Frente 1 — Vinculação no Comercial:** UI no modal de editar empresa de
  `Comercial/Empresas.jsx` para definir a qual empresa principal a empresa
  pertence, com validação de integridade (anti-ciclo + limite de 1 nível) no
  `ComercialController`.
- **Frente 2 — Filtro por grupo no Dashboard ECF:** refatorar o sync para, numa
  ÚNICA leitura dos arquivos brutos do ECF Drive, acumular o detalhe por custId e
  reduzir para o agregado "geral" (retrocompat) + um agregado por grupo cadastrado.
  Adicionar um seletor de grupo na página que troca o payload exibido.

Purpose: tornar consumível na UI o conceito de grupo que o Relatório de
Fechamento já usa (principal + ↳ vinculadas + "Total do grupo"), e permitir
recortar o Dashboard ECF por grupo do dono.

Output: vínculo editável no Comercial + filtro por grupo no Dashboard ECF.
SOMENTE localhost — sem deploy.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@./CLAUDE.md

# Frente 1 — Comercial
@app/Http/Controllers/ComercialController.php
@resources/js/Pages/Comercial/Empresas.jsx

# Frente 2 — Dashboard ECF
@app/Services/DashboardEcfService.php
@app/Console/Commands/DashboardEcfSync.php
@app/Http/Controllers/DashboardEcfController.php
@resources/js/Pages/DashboardEcf/Index.jsx

<interfaces>
<!-- Contratos já existentes no codebase. O executor deve usar estes diretamente — sem exploração. -->

App\Models\Company (app/Models/Company.php):
- $fillable inclui 'parent_company_id' (já fillable — não precisa de mudança no model)
- filhas(): hasMany(Company::class, 'parent_company_id')  // empresas vinculadas a esta
- pai():   belongsTo(Company::class, 'parent_company_id') // empresa principal desta
- getCustIdAttribute(): ?string  →  ml_store_id ?: adman_account_id (null se vazio)
  Acesso: $company->cust_id

Migration JÁ existe (NÃO criar nova):
- database/migrations/2026_05_20_100003_add_parent_company_id_to_companies.php

Padrão de referência de gestão de vínculo + validação anti-self
(App\Http\Controllers\AdminController::updateEmpresa, linhas ~76-107):
```php
$validator = Validator::make($request->all(), [
    'parent_company_id' => ['nullable', 'exists:companies,id', Rule::notIn([$company->id])],
]);
// ... e reatribuição em massa via Company::whereIn(...)->update(['parent_company_id' => ...])
```
ATENÇÃO: esse padrão de referência cobre apenas o anti-self (notIn id próprio).
A Frente 1 deste plano EXIGE também: anti-ciclo (ancestral) + limite de 1 nível.

ComercialController::update (atual) valida APENAS name/cnpj/notes:
```php
$validated = $request->validate([
    'name'  => 'required|string|max:255',
    'cnpj'  => 'nullable|string|max:20|unique:companies,cnpj,' . $company->id,
    'notes' => 'nullable|string|max:2000',
]);
$company->update($validated);
```

ComercialController::empresas (atual) renderiza:
Inertia::render('Comercial/Empresas', [
    'companies' => $companies,            // cada uma com id,name,cnpj,status,created_at,notes,servicos_contratados[]
    'servicos_disponiveis' => $servicosDisponiveis,
]);

DashboardEcfService (app/Services/DashboardEcfService.php):
- CACHE_KEY = 'dashboard_ecf:agregado'
- computar(): array  // monta o payload {mes,atualizadoEm,kpis,kpisDelta,grants,reputacao,niveis,programas,evolucao}
- Campos do custId nas linhas brutas: $r['CUS_CUST_ID_SEL'] ?? $r['CUST_ID']  (MENSAL)
                                       $r['CUS_CUST_ID_SEL']                    (BASE_VENDEDORES)
- sincronizar(): chama computar() e grava em cache por 24h

DashboardEcf/Index.jsx (atual):
- const { dados, erro } = usePage().props;
- dados.kpis / dados.kpisDelta / dados.grants / dados.reputacao / dados.niveis / dados.programas / dados.evolucao
- Componentes: KpiCard, HistoricoChart, GrantStatusPie, DistribuicaoBar (todos já recebem seus dados via props simples)
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Frente 1 — backend do vínculo (parent_company_id) no ComercialController com anti-ciclo e limite de 1 nível</name>
  <files>app/Http/Controllers/ComercialController.php</files>
  <action>
No método `empresas()`: enriquecer a query e o payload para suportar a UI de vínculo.
- Adicionar ao `->with([...])` o eager load de `'pai:id,name'` e
  `'filhas:id,name,parent_company_id'` (siga o padrão de AdminController::empresas).
- Incluir `'parent_company_id'` na lista de colunas do `->get([...])`.
- No `transform`, expor por empresa: `parent_company_id`, `nome_pai` (= `$c->pai?->name`),
  `filhas_count` (= `$c->filhas->count()`), e `is_principal` (= `$c->filhas->isNotEmpty()`).
- Adicionar uma nova prop `candidatas_principal` ao `Inertia::render`: lista de empresas
  ELEGÍVEIS para serem principais — ou seja, empresas ativas que NÃO são filhas de
  ninguém (`whereNull('parent_company_id')`), retornando apenas `['id','name']`,
  ordenadas por nome. (Empresas que já são filhas não podem virar principais — regra
  do limite de 1 nível.) Cada empresa NÃO deve aparecer como candidata principal de si
  mesma; o filtro disso é feito no front, mas envie a lista completa de elegíveis.

No método `update()`: adicionar `parent_company_id` à validação e à persistência.
- Acrescentar à regra de validação:
  `'parent_company_id' => ['nullable', 'integer', 'exists:companies,id', Rule::notIn([$company->id])]`
  (use `Illuminate\Validation\Rule`, já importado no arquivo).
- ANTES de persistir, aplicar as REGRAS DE INTEGRIDADE quando `parent_company_id` vier
  preenchido (não-null). Implementar um helper privado `validarVinculoPrincipal(Company $company, ?int $paiId): void`
  que lança `ValidationException::withMessages(['parent_company_id' => '...'])` (mensagens em pt-BR)
  nos casos proibidos:
    (a) ANTI-SELF: `$paiId === $company->id` → "Uma empresa não pode ser principal de si mesma."
        (já coberto por notIn na validação, mas reforce no helper por defesa).
    (b) LIMITE DE 1 NÍVEL — a empresa que está virando filha não pode ela mesma ter filhas:
        se `$company->filhas()->exists()` → "Esta empresa já é principal de outras; não pode ser vinculada a outra empresa. Desvincule as filhas primeiro."
    (c) LIMITE DE 1 NÍVEL — o pai escolhido não pode ser ele mesmo uma filha:
        se a company alvo (`Company::find($paiId)`) tiver `parent_company_id !== null` →
        "A empresa principal escolhida já está vinculada a outra; escolha uma empresa principal de topo."
    (d) ANTI-CICLO defensivo: subir a cadeia de `pai` a partir de `$paiId` e abortar se
        encontrar `$company->id` antes de chegar ao topo → "Vínculo inválido: criaria um ciclo."
        (Com o limite de 1 nível, (d) é redundante na prática, mas mantenha como guard.)
- Persistir `parent_company_id` no `$company->update(...)` junto com name/cnpj/notes.
  Tratar `parent_company_id` ausente como "não alterar" e string vazia/`null` como
  "desvincular" (gravar null) — normalize antes do update.
- Manter o activity log existente.

Comentários em pt-BR, padrão do projeto. NÃO criar migration (parent_company_id já existe).
  </action>
  <verify>
    <automated>php -l app/Http/Controllers/ComercialController.php</automated>
  </verify>
  <done>
`empresas()` envia `parent_company_id`, `nome_pai`, `filhas_count`, `is_principal` por
empresa + prop `candidatas_principal`. `update()` valida e persiste `parent_company_id`
com helper `validarVinculoPrincipal` cobrindo anti-self, limite de 1 nível (filha com
filhas; pai já-filha) e anti-ciclo. `php -l` sem erros de sintaxe.
  </done>
</task>

<task type="auto">
  <name>Task 2: Frente 1 — UI do vínculo no Comercial/Empresas.jsx (seletor de principal + indicador na listagem)</name>
  <files>resources/js/Pages/Comercial/Empresas.jsx</files>
  <action>
Consumir as novas props do controller (Task 1) e adicionar a UI do vínculo, mantendo
tokens `ecf-*`, dark theme e os componentes já existentes (Modal, `cn()`).

No componente `Empresas(...)`: receber a nova prop `candidatas_principal = []` no
destructuring de props (ao lado de `companies`, `servicos_disponiveis`).

No `FormularioEditar`:
- Receber `candidatasPrincipal` por prop (repassada de `Empresas` → `FormularioEditar`).
- Adicionar `parent_company_id: company.parent_company_id ?? ''` ao `useForm({...})`.
- Adicionar um `<select>` "Empresa principal (dono)" (estilo idêntico aos selects já
  presentes no arquivo: `bg-white/[0.04] border border-white/[0.08] rounded-lg ...
  focus:border-ecf-yellow/40`), com:
    - opção vazia: "— Nenhuma (empresa de topo) —"
    - opções = `candidatasPrincipal` FILTRADAS para excluir a própria empresa
      (`c.id !== company.id`).
    - desabilitar/ocultar o select com uma nota pt-BR quando `company.is_principal`
      for true (a empresa já é principal de outras → não pode virar filha; espelha a
      regra (b) do backend). Mostrar texto: "Esta empresa é principal de
      {filhas_count} vinculada(s). Para vinculá-la a outra, desvincule as filhas primeiro."
    - exibir `errors.parent_company_id` em vermelho (padrão do form).
- Enviar `parent_company_id` no `put(route('comercial.empresas.update', ...))`
  (o `useForm` já inclui o campo automaticamente).

Na `EmpresaRow`:
- Quando `c.is_principal` (ou `c.filhas_count > 0`): exibir um badge pt-BR ao lado do
  nome — ex.: "Principal · {filhas_count} vinculada(s)" — com estilo de badge já usado
  no arquivo (`text-xs font-medium px-2 py-0.5 rounded-full border ...`, paleta
  `ecf-yellow/10` ou `white/[0.06]`).
- Quando `c.nome_pai`: exibir abaixo do nome, discreto (`text-white/30 text-xs`), com o
  prefixo "↳ vinculada a {nome_pai}" (espelha o ↳ do Relatório de Fechamento).

Passar `candidatasPrincipal={candidatas_principal}` na renderização do `FormularioEditar`
dentro do `<Modal>`. Comentários em pt-BR onde a decisão de UI não for óbvia.
  </action>
  <verify>
    <automated>node -e "require('fs').readFileSync('resources/js/Pages/Comercial/Empresas.jsx','utf8').match(/parent_company_id/) ? process.exit(0) : process.exit(1)"</automated>
  </verify>
  <done>
O modal de editar mostra o seletor "Empresa principal (dono)" populado com
`candidatas_principal` (exceto a própria), trava quando a empresa já é principal, e
envia `parent_company_id` no update. A listagem mostra badge de "Principal · N
vinculada(s)" e "↳ vinculada a {nome_pai}" quando aplicável. Token `ecf-*` e Modal/cn()
preservados.
  </done>
</task>

<task type="auto">
  <name>Task 3: Frente 2 — refatorar DashboardEcfService para acumular por custId em leitura única e reduzir para geral + por grupo</name>
  <files>app/Services/DashboardEcfService.php</files>
  <action>
Refatorar `computar()` para suportar agregado por grupo SEM reler os arquivos brutos
(são ~5000 linhas × 170 colunas — leitura cara). Estratégia: UMA leitura, acumular o
detalhe por custId, depois REDUZIR para o agregado geral + um agregado por grupo.

Mudar a assinatura para `computar(array $grupos = []): array`, onde `$grupos` é uma lista
no formato `[['id' => int, 'nome' => string, 'cust_ids' => string[]], ...]` (montada pelo
comando na Task 4). Manter `sincronizar(array $grupos = [])` repassando `$grupos` a `computar`.

Plano de refatoração interna:
1. Extrair a lógica de montagem do payload final (do "mês alvo" + evolução + delta) que
   hoje está no fim de `computar()` para um helper privado puro
   `montarPayload(array $porMes, array $grant): array` que recebe os acumuladores e
   devolve o payload no MESMO formato atual (`mes, atualizadoEm, kpis, kpisDelta, grants,
   reputacao, niveis, programas, evolucao`). Isso permite reusar exatamente as mesmas
   contas para o geral e para cada grupo.
2. Na leitura MENSAL e BASE_VENDEDORES, além de acumular em `$porMes`/`$grant` GERAIS
   (como hoje), acumular TAMBÉM por grupo: para cada linha, descobrir a qual grupo o
   `cid` pertence (via um índice `custId → groupId` pré-construído a partir de `$grupos`)
   e, se pertencer, alimentar acumuladores espelhados `$porMesPorGrupo[$gid]` e
   `$grantPorGrupo[$gid]` com a MESMA lógica de soma/contagem do geral.
   - Construir uma única vez, no topo, `$mapaCustIdGrupo = [];` percorrendo `$grupos` e
     mapeando cada `cust_id` (string) → `id` do grupo. custIds duplicados entre grupos
     não devem ocorrer (cada empresa pertence a 1 grupo), mas se ocorrer, o primeiro vence.
   - Para evitar duplicação de código, extraia a lógica de "acumular uma linha MENSAL num
     acumulador `$porMes`" e "acumular uma linha BASE_VENDEDORES num `$grant`" em helpers
     privados que recebem o acumulador por referência, e chame-os para o geral E para o
     grupo correspondente na MESMA iteração da linha. Releitura dos arquivos = ZERO.
3. Ao final:
   - `$geral = $this->montarPayload($porMes, $grant);` (idêntico ao comportamento atual).
   - Para cada grupo com acumulador não-vazio, `montarPayload($porMesPorGrupo[$gid], $grantPorGrupo[$gid] ?? [...])`.
     Grupos sem nenhuma linha (custIds sem dados no ECF Drive) podem ser omitidos ou
     incluídos com payload vazio — prefira OMITIR para o seletor só listar grupos com dados.
   - Retornar o payload final como o GERAL de hoje (retrocompat: as chaves de topo
     `mes,kpis,...` continuam sendo as do geral) MAIS uma chave nova `grupos` =
     lista de `['id' => ..., 'nome' => ..., 'agregado' => <payload no mesmo formato>]`.

Atenção:
- Só entram no filtro empresas cadastradas com custId preenchido; sellers do ECF Drive
  sem company cadastrada continuam apenas no "geral" (comportamento natural: eles não
  estão em nenhum `$grupos`).
- Manter `throw new \RuntimeException(...)` se `$porMes` GERAL ficar vazio (não quebrar
  por causa de grupos vazios).
- NÃO reordene as contas existentes; só fatore-as. O payload GERAL deve permanecer
  byte-equivalente ao de hoje para preservar retrocompat com a UI.
- Comentários em pt-BR explicando a estratégia de leitura única.
  </action>
  <verify>
    <automated>php -l app/Services/DashboardEcfService.php</automated>
  </verify>
  <done>
`computar(array $grupos = [])` lê os arquivos brutos UMA vez, acumula geral + por grupo
na mesma passada via `$mapaCustIdGrupo`, e retorna o payload geral (retrocompat) + chave
`grupos` (lista de `{id, nome, agregado}` no mesmo formato do geral). Helper
`montarPayload` reusa as contas. `php -l` sem erros.
  </done>
</task>

<task type="auto">
  <name>Task 4: Frente 2 — comando ecf:dashboard-sync carrega os grupos e passa ao service</name>
  <files>app/Console/Commands/DashboardEcfSync.php</files>
  <action>
No `handle()`, ANTES de chamar `$service->sincronizar(...)`, carregar os grupos cadastrados
e passá-los ao service no formato esperado pela Task 3.

- Carregar empresas principais ativas (sem pai) que tenham filhas, com suas filhas:
  `Company::query()->whereNull('parent_company_id')->where('active', true)
      ->with(['filhas' => fn($q) => $q->where('active', true)])->get()`
  (importar `use App\Models\Company;`).
- Para cada principal, montar `['id' => $principal->id, 'nome' => $principal->name,
  'cust_ids' => collect([$principal])->merge($principal->filhas)
      ->map->cust_id->filter()->unique()->values()->all()]`
  (usa o accessor `$company->cust_id`). Incluir o custId da PRÓPRIA principal + os das
  filhas (a principal também é uma empresa do grupo).
- Filtrar grupos cujo `cust_ids` ficou vazio (grupo sem nenhuma empresa com integração
  ML/Adman não rende agregado) — não passar grupos vazios.
- Passar a lista resultante: `$dados = $service->sincronizar($grupos);`.
- No log de sucesso (`$this->info(sprintf(...))`), acrescentar a contagem de grupos
  sincronizados (ex.: "· N grupos") lendo `count($dados['grupos'] ?? [])`.
- Manter `ini_set('memory_limit', '1024M')` e o try/catch atuais. Comentários em pt-BR.
  </action>
  <verify>
    <automated>php -l app/Console/Commands/DashboardEcfSync.php</automated>
  </verify>
  <done>
O comando carrega principais ativas com filhas, monta `[{id,nome,cust_ids}]` via accessor
`cust_id`, descarta grupos sem custId e passa ao `sincronizar($grupos)`. Log de sucesso
reporta a contagem de grupos. `php -l` sem erros.
  </done>
</task>

<task type="auto">
  <name>Task 5: Frente 2 — seletor de grupo no DashboardEcfController + Index.jsx + build final</name>
  <files>app/Http/Controllers/DashboardEcfController.php, resources/js/Pages/DashboardEcf/Index.jsx</files>
  <action>
DashboardEcfController::index():
- O payload em cache já contém `dados['grupos']` (da Task 3). Como o controller só lê o
  cache e repassa `dados`, NÃO é necessário consultar o DB aqui. Apenas garantir que a
  prop `dados` (que inclui `grupos`) chega ao front. Manter o tratamento de `erro`.
  (Se `dados` for null — sync nunca rodou — nada muda.)

resources/js/Pages/DashboardEcf/Index.jsx:
- Ler `dados.grupos` (lista de `{id, nome, agregado}`; pode ser undefined em caches
  antigos → tratar como `[]`).
- Adicionar estado `const [grupoSel, setGrupoSel] = useState('geral');`.
- Derivar o payload exibido: quando `grupoSel === 'geral'`, usar `dados` (o agregado de
  topo, como hoje); senão, achar `dados.grupos.find(g => String(g.id) === grupoSel)?.agregado`
  e usar como fonte de `kpis/kpisDelta/grants/reputacao/niveis/programas/evolucao`.
  Refatorar o componente para que TODAS as seções (KPI cards, GrantStatusPie,
  DistribuicaoBar reputação/programas/níveis, HistoricoChart) leiam de uma variável
  `view` (= o agregado selecionado) em vez de `dados.*` diretamente. Manter fallback
  seguro (`view?.kpis ?? {}`, etc.) para não quebrar se o agregado vier incompleto.
- Adicionar um `<select>` "Grupo" no header (ou logo abaixo dele), estilo idêntico aos
  selects já usados no projeto (tokens `ecf-*`, dark): primeira opção `value="geral"`
  rotulada "Geral (todos os sellers)", seguida de uma opção por grupo
  (`value={String(g.id)}`, label `g.nome`). Só renderizar o seletor quando
  `dados.grupos?.length > 0`.
- O texto do header que mostra "mês {fmtMesAno(dados.mes)}" deve refletir o `view.mes`
  selecionado (ou manter o geral; preferir `view.mes` para consistência).
- Manter o visual atual (recharts, dark theme, tokens `ecf-*`), banners explicativos e a
  nota final intactos. Comentários em pt-BR onde a decisão não for óbvia.

PASSO FINAL OBRIGATÓRIO (convenção do projeto — sem deploy):
- Rodar `npm run build` e garantir 0 erros de build. SOMENTE localhost; NÃO executar
  nenhum passo de deploy.
  </action>
  <verify>
    <automated>npm run build</automated>
  </verify>
  <done>
A página mostra um seletor "Grupo" (Geral + cada grupo com dados) que troca client-side
o agregado exibido em TODAS as seções, lendo `dados.grupos`. Controller só repassa `dados`
(inclui `grupos`). Caches antigos sem `grupos` não quebram (fallback `[]`). `npm run build`
conclui com 0 erros.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → ComercialController.update | `parent_company_id` enviado pelo cliente (Inertia) cruza para o backend |
| ECF Drive (HTTP) → DashboardEcfService | linhas brutas de terceiros lidas no sync CLI |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-nqr-01 | Tampering | ComercialController::update `parent_company_id` | mitigate | `exists:companies,id` + `Rule::notIn([$company->id])` + helper `validarVinculoPrincipal` (anti-self, limite de 1 nível, anti-ciclo) antes do persist |
| T-nqr-02 | Elevation | rota comercial.empresas.update | accept | `abort_unless(hasPermission('comercial.cadastrar_empresa') || isAdmin())` já existe no método; nenhum acesso novo introduzido |
| T-nqr-03 | DoS | DashboardEcfService leitura dos arquivos brutos | mitigate | leitura ÚNICA + acumulação por custId numa só passada (não reler por grupo); roda só na CLI `ecf:dashboard-sync` com `memory_limit=1024M` |
| T-nqr-04 | Information Disclosure | payload `grupos` no Dashboard ECF | accept | página é admin-only (rota já protegida); agregados por grupo são dados internos já visíveis ao admin |
</threat_model>

<verification>
- `php -l` limpo nos 4 arquivos PHP alterados (Tasks 1, 3, 4) + controller (Task 5).
- `parent_company_id` referenciado em ComercialController.php e Comercial/Empresas.jsx.
- `npm run build` conclui sem erros (Task 5).
- Confirmado: NENHUMA migration nova — `parent_company_id` já existe
  (database/migrations/2026_05_20_100003_add_parent_company_id_to_companies.php).
- Smoke manual sugerido (localhost): editar uma empresa no Comercial, vinculá-la a uma
  principal, conferir badge na listagem; rodar `php artisan ecf:dashboard-sync` e trocar
  o seletor de grupo no Dashboard ECF.
</verification>

<success_criteria>
- Frente 1: no modal de editar do Comercial é possível escolher a empresa principal
  (grava `parent_company_id`); o backend rejeita anti-self, filha-com-filhas, pai-já-filha
  e ciclo com mensagens pt-BR; a listagem indica empresas principais e vinculadas.
- Frente 2: `ecf:dashboard-sync` lê os arquivos UMA vez e produz `geral` + `grupos`;
  o Dashboard ECF tem seletor "Grupo" que troca todos os KPIs/gráficos entre Geral e cada
  grupo; caches antigos sem `grupos` não quebram a página.
- `npm run build` verde. Sem deploy. Comentários em pt-BR. Tokens `ecf-*` e componentes
  existentes (Modal, cn(), KpiCard, DistribuicaoBar, GrantStatusPie, HistoricoChart) preservados.
</success_criteria>

<output>
Criar `.planning/quick/260609-nqr-agrupamento-empresas-por-dono-e-filtro-p/260609-nqr-SUMMARY.md` ao concluir.
</output>
