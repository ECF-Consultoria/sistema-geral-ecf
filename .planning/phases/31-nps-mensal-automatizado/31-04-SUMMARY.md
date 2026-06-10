---
phase: 31-nps-mensal-automatizado
plan: 04
subsystem: companies
tags: [ui, controller, validation, nps, email]
requires:
  - 31-01 (companies.email_cliente nullable varchar 255 + fillable)
provides:
  - "CompanyController::store/update validam email_cliente (nullable|email|max:255)"
  - "CompanyController::index/show enviam email_cliente no payload Inertia"
  - "ComercialController::update valida email_cliente"
  - "ComercialController::empresas inclui email_cliente no select"
  - "Companies/Index.jsx modal admin com input Email do Cliente para NPS"
  - "Comercial/Empresas.jsx FormularioEditar com input Email do Cliente para NPS"
affects:
  - "Companies/Show.jsx (linhas 377, 848) ainda usa chaves legacy nps_surveys[*].response.score_overall/consultant/mentor — Plan 31-05"
  - "PerformanceController e DashboardController continuam quebrados em prod ate Plan 31-05"
tech_stack:
  added: []
  patterns:
    - "Inertia useForm com setData controlado para campo email"
    - "validate `nullable|email|max:255` (alinhado com D-04 — silent skip quando vazio)"
    - "Pre-preenchimento via openEdit(c) recebendo c.email_cliente do payload"
key_files:
  created: []
  modified:
    - app/Http/Controllers/CompanyController.php
    - app/Http/Controllers/ComercialController.php
    - resources/js/Pages/Companies/Index.jsx
    - resources/js/Pages/Comercial/Empresas.jsx
decisions:
  - "Gotcha Plan 31-02 linhas 309-311 do CompanyController::show: OPCAO A escolhida (corrigir agora). Substitui chaves score_overall/score_consultant/score_mentor por score_empresa/score_analista/score_estrategista no payload Inertia. Mudanca e ZERO-coupled com este plan (Show.jsx nao e tocado por 31-04), so previne SQL error em /companies/{id} pos-deploy. Companies/Show.jsx (linhas 377, 848) que CONSOME essas chaves antigas continua delegado para Plan 31-05."
  - "Decisao Comercial/Empresas: campo email_cliente vai no FormularioEditar mas NAO no fluxo de criacao (Comercial/NovaEmpresa.jsx). Razao: cadastro pelo Comercial e centralizado em servicos contratados; email do cliente e dado operacional preenchido depois pela operacao de relacionamento. Empresa nova entra com email_cliente=null e e silenciosamente pulada pelo comando NPS ate ser editada (D-04 explicito)."
  - "Posicionamento do input: ambas UIs colocam o campo IMEDIATAMENTE APOS Observacoes/Notes — fluxo visual: dados fiscais (nome, CNPJ, IDs) → contexto (segmento, equipe) → notas → contatos (email). Help text pt-BR explicito: 'Destinatario do email mensal de NPS. Deixe em branco para pausar o envio.' alinhado com D-13 (opt-out = zerar campo)."
metrics:
  duration_minutes: 4
  tasks_completed: 3
  files_modified: 4
  commits: 3
  completed_at: "2026-06-10T22:01:13Z"
---

# Phase 31 Plan 04: UI email_cliente em Empresas (Summary)

**One-liner:** Expoe a coluna `companies.email_cliente` (criada no Plan 31-01) nos dois fluxos de edicao de empresa (admin `/companies` e comercial `/comercial/empresas`) com validacao backend `nullable|email|max:255`, habilitando o operador a preencher o destinatario do email NPS mensal disparado pelo comando do Plan 31-02.

## O que foi feito

3 tasks executadas, 4 arquivos modificados, 0 testes quebrados (19/19 Phase 31 verdes). Operador (admin ou comercial) agora consegue cadastrar/editar o email do cliente que recebera o NPS mensal automatico; sem o campo preenchido, o comando `nps:disparar-mensal` continua pulando a empresa silenciosamente (D-04). Tambem corrigido inline um gotcha pre-existente em `CompanyController::show` que ainda lia colunas legacy dropadas no Plan 31-01 (`score_overall/score_consultant/score_mentor`).

### Task 1 — Backend (CompanyController + ComercialController)

**`app/Http/Controllers/CompanyController.php`:**
- `store()` (linha ~362): adicionada regra `'email_cliente' => 'nullable|email|max:255'` ao validate. `Company::create($data)` persiste — `email_cliente` ja esta no `$fillable` desde Plan 31-01.
- `update()` (linha ~387): mesma regra adicionada. `$company->update($data)` persiste.
- `index()` (linha ~53): payload Inertia de cada empresa agora inclui `'email_cliente' => $c->email_cliente` (apos `notes`, antes de `adman_account_id`).
- `show()` (linha ~280): payload Inertia inclui `'email_cliente' => $company->email_cliente` (apos `notes`, antes de `adman_account_id`).

**Gotcha Plan 31-02 corrigido inline (Opcao A escolhida):**
- Linhas 309-311 da view de `show()` carregavam `nps_surveys[*].response` com chaves `score_overall / score_consultant / score_mentor` — colunas dropadas no Plan 31-01.
- Substituidas por `score_empresa / score_analista / score_estrategista` (nova taxonomia 1-5).
- Comentario `// Phase 31 Plan 31-04 (Gotcha Plan 31-02)` documenta a decisao + aponta que `Companies/Show.jsx` (linhas 377, 848) ainda referencia os nomes antigos e sera ajustado no Plan 31-05.
- Justificativa: era one-liner obvio (mapping direto 1:1 entre nomes), e deixar o controller enviando chaves de colunas inexistentes causa SQL error pos-deploy — sintoma muito pior que o JSX renderizar `undefined` (que o Plan 31-05 cobre).

**`app/Http/Controllers/ComercialController.php`:**
- `empresas()` (linha ~78): coluna `'email_cliente'` adicionada ao `get([...])` para popular o payload Inertia.
- `update()` (linha ~314): adicionada regra `'email_cliente' => 'nullable|email|max:255'` ao validate. `$company->update($validated)` ja persiste.

**Verificacao automatizada:** suite Phase 31 completa rodada apos as mudancas → **19/19 verdes**, sem regressao.

### Task 2 — Companies/Index.jsx (modal admin)

- `useForm({ ... })` inicializa com `email_cliente: ''` (campo adicionado ao state).
- `openEdit(c)` pre-preenche com `email_cliente: c.email_cliente || ''`.
- Modal renderiza novo bloco `col-span-2` apos Observacoes:
  ```jsx
  <div className="col-span-2 space-y-1.5">
      <Label>Email do Cliente para NPS</Label>
      <Input type="email" value={data.email_cliente}
             onChange={e => setData('email_cliente', e.target.value)}
             placeholder="cliente@empresa.com.br" />
      {errors.email_cliente && <p className="text-destructive text-xs">{errors.email_cliente}</p>}
      <p className="text-white/30 text-[11px]">Destinatário do email mensal de NPS. Deixe em branco para pausar o envio.</p>
  </div>
  ```
- Reusa componentes `Label` e `Input` ja importados (sem novo import).
- `openCreate()` invoca `reset()` que zera todos os campos do `useForm` initial state — `email_cliente` vai junto sem mudanca adicional.

### Task 3 — Comercial/Empresas.jsx (FormularioEditar)

- `useForm` do `FormularioEditar` (linha ~96): adiciona `email_cliente: company.email_cliente ?? ''`.
- Novo bloco renderizado entre `Descricao / Observacoes` e `ContratosSection`:
  ```jsx
  <div className="space-y-1.5">
      <label className="block text-xs text-white/60 font-medium">
          Email do Cliente para NPS{' '}
          <span className="text-white/30 text-[11px] font-normal">(opcional)</span>
      </label>
      <input type="email" value={data.email_cliente}
             onChange={e => setData('email_cliente', e.target.value)}
             placeholder="cliente@empresa.com.br"
             className={cn(
                 'w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                 errors.email_cliente ? 'border-red-500/50' : 'border-white/[0.08]'
             )} />
      {errors.email_cliente && <p className="text-red-400 text-xs">{errors.email_cliente}</p>}
      <p className="text-white/30 text-[11px]">Destinatário do email mensal de NPS. Deixe em branco para pausar o envio.</p>
  </div>
  ```
- Segue o padrao visual dos demais inputs do FormularioEditar (dark theme `ecf-card`, borda condicional via `cn()`, error em vermelho).
- `Comercial/NovaEmpresa.jsx` NAO foi modificado por decisao explicita (ver "Decisions" no frontmatter).

**Build:** `npm run build` verde em 10.34s, sem warnings novos.

## Arquivos afetados

### Modificados
- `app/Http/Controllers/CompanyController.php` — store/update validate + index/show payload Inertia + Gotcha 31-02 corrigido
- `app/Http/Controllers/ComercialController.php` — update validate + empresas get() incluindo email_cliente
- `resources/js/Pages/Companies/Index.jsx` — useForm state + openEdit + input no modal
- `resources/js/Pages/Comercial/Empresas.jsx` — useForm do FormularioEditar + input apos Observacoes

### Criados
- Nenhum.

## Commits

| Hash      | Mensagem                                                                                       |
| --------- | ---------------------------------------------------------------------------------------------- |
| `77b493c` | `feat(31-04): valida e persiste email_cliente em Company/Comercial controllers`                |
| `c22bf9a` | `feat(31-04): adiciona campo Email do Cliente no modal admin Companies/Index`                  |
| `7ec46dc` | `feat(31-04): adiciona campo Email do Cliente no form Comercial/Empresas`                      |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Gotcha Plan 31-02: CompanyController::show payload usando chaves de colunas dropadas**
- **Found during:** Task 1 (planejado explicitamente — opcao A/B no prompt)
- **Issue:** Linhas 309-311 montavam `nps_surveys[*].response` com `score_overall / score_consultant / score_mentor`. Essas colunas foram **dropadas no Plan 31-01** (drop+recreate da `nps_responses` com escala 1-5). Sem essa correcao, abrir `/companies/{id}` em prod causaria SQL error `Unknown column 'score_overall' in 'field list'`.
- **Fix:** Substituido o trio por `score_empresa / score_analista / score_estrategista` no payload Inertia. Comentario explicito no codigo apontando que `Companies/Show.jsx` (linhas 377, 848) ainda consome os nomes antigos e sera ajustado no Plan 31-05 — escopo de 31-04 cobre apenas o BACKEND envio das chaves corretas.
- **Files modified:** `app/Http/Controllers/CompanyController.php` (linhas 305-321 atuais)
- **Commit:** `77b493c`

### Out-of-scope deferrals (logged here, not fixed)

Mantidos os mesmos da SUMMARY do Plan 31-02. **Plan 31-04 NAO cobriu:**

| Arquivo | Linhas | Por que nao cobrir | Plan downstream |
|---------|--------|--------------------|-----------------|
| `resources/js/Pages/Companies/Show.jsx` | 377, 848 | Plan 31-04 nao toca em Show.jsx (escopo limitado a modal de edicao na Index). O backend ja envia as chaves novas — JSX precisa ser atualizado para LER as chaves novas. | **Plan 31-05** |
| `app/Http/Controllers/PerformanceController.php` | 58-59, 264 | Sem ligacao com email_cliente. Lista de ranking por papel (mentor/consultor). | **Plan 31-05** |
| `app/Http/Controllers/DashboardController.php` | 363, 395-397, 605, 727 | Widget NPS no dashboard. D-09 manda mudar para `score_empresa`. | **Plan 31-05** |
| `app/Http/Controllers/NpsController.php::index()` | 36-38 | Listagem admin de surveys. | **Plan 31-05** |
| `resources/js/Pages/Nps/Index.jsx` | 82-84 | Coluna NpsScore na tabela admin. | **Plan 31-05** |

**Estado de prod pos-deploy de 31-01+31-02+31-03+31-04:** acessos a `/dashboard`, `/companies/{id}` (Show.jsx — backend ja correto mas JSX consome chaves erradas), `/performance`, `/nps` admin AINDA retornarao erros (SQL errors ou undefineds). **NAO FAZER DEPLOY** sem o Plan 31-05.

## Gotchas / Proximos passos

### Para Plan 31-05 (cleanup geral + UI admin NPS)

1. **`Companies/Show.jsx` linhas 377 e 848** consomem `nps_surveys[*].response.score_overall / score_consultant / score_mentor`. O **backend ja envia as chaves novas** (`score_empresa / score_analista / score_estrategista`) — basta trocar os nomes no JSX e ajustar labels visuais (1-5 em vez de 0-10).

2. **DashboardController + widget NPS no Admin.jsx**: D-09 dita mapeamento promotor=5, neutro=4, detrator=1-3 sobre `score_empresa`. Ou simplificar para "media de score_empresa do mes" + count.

3. **PerformanceController**: re-pensar ranking — colunas legacy nao existem mais.

4. **NpsController::index()** + `Nps/Index.jsx`: precisa ser reescrito para nova taxonomia + filtros por `month_reference` + flag `auto_generated` (manual vs. automatico).

### Verificacao manual recomendada antes do deploy

1. `/companies` (admin): abrir modal Nova Empresa → preencher email valido → salvar → empresa persiste. Editar → email aparece pre-preenchido. Email invalido → erro 422.
2. `/comercial/empresas`: editar empresa → campo Email do Cliente aparece apos Observacoes → preencher e salvar → persiste. Voltar para `/companies` admin → mesmo email visivel.
3. Conferir via tinker: `Company::find(X)->email_cliente` retorna o que foi salvo.

### Comando NPS continua silencioso para empresas sem email

D-04 explicito: sem `email_cliente`, comando `nps:disparar-mensal` pula a empresa sem warning. Operador precisa preencher proativamente via essa UI. **Considerar** durante deploy: enviar comunicado interno pra equipe Comercial preencher os emails das empresas ativas (cerca de 170 empresas), senao primeiro disparo automatico tem volume baixo.

## Threat Flags

Nenhuma. As mudancas sao:
- Adicionar campo `email_cliente` (nullable) ja validado backend — sem expansao de superficie de auth.
- Corrigir nomes de chaves no payload de leitura — sem mudar boundaries de autorizacao.
- Validacao `nullable|email|max:255` segue padrao Laravel ja em uso em outros controllers.
- Inputs `type="email"` no frontend sao puramente clientside hint — server-side `email` rule e a defesa real.

## Self-Check: PASSED

- ✓ `app/Http/Controllers/CompanyController.php` modificado (4 hits email_cliente: linhas 65, 280, 382, 409)
- ✓ `app/Http/Controllers/ComercialController.php` modificado (2 hits email_cliente: linhas 83, 320)
- ✓ `resources/js/Pages/Companies/Index.jsx` modificado (5 hits email_cliente: linhas 162, 183, 449, 450, 453)
- ✓ `resources/js/Pages/Comercial/Empresas.jsx` modificado (4 hits email_cliente: linhas 101, 180, 181, 183, 185)
- ✓ Commits `77b493c`, `c22bf9a`, `7ec46dc` existem em `git log`
- ✓ Build `npm run build` verde (10.34s)
- ✓ Suite Phase 31 completa rodada apos backend: **19/19 testes verdes**
- ✓ Gotcha Plan 31-02 (linhas 309-311 do CompanyController::show) resolvido com Opcao A
