---
phase: 33-perguntas-customizadas-nps
plan: 01
subsystem: nps
tags: [nps, backend, migrations, models, validation, perguntas-customizadas]
requires: [phase-31-nps-mensal, phase-32-customizacao]
provides:
  - migration: nps_perguntas_customizadas (texto, tipo enum, opcoes JSON, ordem, ativa, obrigatorio)
  - migration: nps_respostas_customizadas (response_id cascade, pergunta_id set null, snapshots)
  - model: App\Models\NpsPerguntaCustomizada (casts opcoes=array, const TIPOS)
  - model: App\Models\NpsRespostaCustomizada (snapshots de texto/tipo)
  - relation: NpsResponse::respostasCustomizadas() hasMany
  - controller_methods: criarPerguntaExtra/atualizarPerguntaExtra/excluirPerguntaExtra/moverPerguntaExtra
  - controller_adaptation: respond() (perguntas_extras no payload) + submitResponse() (validacao dinamica + persistencia em transaction) + configuracao() (perguntas_extras na 3a tab) + index() (eager load + respostas_customizadas no row)
  - routes: 4 rotas /nps/configuracao/perguntas{,/{p},/{p}/mover} sob role:admin
  - test_suite: Tests\Feature\Phase33NpsPerguntasExtrasTest (8 cases)
affects:
  - app/Http/Controllers/NpsController.php
  - app/Models/NpsResponse.php
  - routes/web.php
tech-stack:
  added: []
  patterns:
    - "Dynamic validation rules via Validator (foreach perguntas ativas → match tipo D-02)"
    - "Snapshot pattern (pergunta_texto_snapshot, tipo_snapshot) — defesa contra edit/delete posterior"
    - "Swap-based reorder (O(1) updates entre vizinhas) ao inves de shift-all-rows"
    - "Soft+hard delete dual (DELETE padrao = ativa=false se ha respostas; ?force=1 = hard)"
key-files:
  created:
    - database/migrations/2026_06_12_100001_create_nps_perguntas_customizadas_table.php
    - database/migrations/2026_06_12_100002_create_nps_respostas_customizadas_table.php
    - app/Models/NpsPerguntaCustomizada.php
    - app/Models/NpsRespostaCustomizada.php
    - tests/Feature/Phase33NpsPerguntasExtrasTest.php
  modified:
    - app/Models/NpsResponse.php (relacao respostasCustomizadas hasMany)
    - app/Http/Controllers/NpsController.php (imports + respond/submitResponse/configuracao/index adaptados + 4 metodos CRUD)
    - routes/web.php (4 rotas no grupo role:admin existente)
decisions:
  - "opcoes forcadas a null quando tipo != multipla (defensivo no criar/atualizar)"
  - "Validacao dinamica em submitResponse: itera perguntas ativas no momento da submissao e monta rules por tipo (escala_1_5/texto/sim_nao/multipla)"
  - "Snapshot do texto/tipo da pergunta na nps_respostas_customizadas: defende contra edit posterior e permite hard-delete sem perder historico"
  - "Reorder via swap O(1) entre vizinha (ordem ASC desc para up, ASC asc para down)"
  - "DELETE padrao = soft (ativa=false) se ha respostas; ?force=1 = hard (FK respostas vira null)"
  - "Validacao requer min:2 opcoes em tipo=multipla (1 opcao nao faz sentido como pergunta)"
  - "respond() retorna apenas perguntas ATIVAS (perguntas_extras com filtro ativa=true); configuracao() retorna TODAS para a UI admin gerenciar"
  - "index() eager loads response.respostasCustomizadas e expoe respostas_customizadas no payload da linha — desbloqueia Plan 33-04 (modal Abrir) sem N+1"
metrics:
  duration: ~30min
  completed_date: 2026-06-12
  tasks: 4 commits atomicos
  files: 7 (2 created migrations + 2 created models + 1 created test + 2 modified)
  tests_added: 8 (Phase 33)
  tests_passing: 27/27 (19 Phase 31 + 8 Phase 33)
---

# Phase 33 Plan 01: Fundacao — schema + models + endpoints + submit dinamico

JWT/auth/etc. — nao aplicavel.

**One-liner:** Backend completo das perguntas customizadas NPS — 2 tabelas (perguntas + respostas com snapshot), 2 models com casts, validacao dinamica por tipo no submitResponse() + 4 endpoints REST CRUD admin, zero regressao na Phase 31 (19/19 verdes) e 8 novos testes Phase 33 verdes.

## O que foi entregue

### Schema (2 migrations)

**`nps_perguntas_customizadas`** (D-01):
- `id`, `texto` (varchar 500), `tipo` enum (`escala_1_5|texto|sim_nao|multipla`)
- `opcoes` JSON nullable (so usado em tipo=multipla — array de strings)
- `obrigatorio` bool default false, `ordem` int default 0, `ativa` bool default true
- timestamps + index composto `(ativa, ordem)` para acelerar query de respond()

**`nps_respostas_customizadas`** (D-01):
- `id`, `response_id` FK cascade onDelete, `pergunta_id` FK set null onDelete
- `pergunta_texto_snapshot` (varchar 500), `tipo_snapshot` (varchar 20), `valor` (text)
- timestamps + index em `response_id` (acelera eager load do modal Abrir do Plan 33-04)

### Models

**`NpsPerguntaCustomizada`**: fillable + casts (opcoes=array, obrigatorio/ativa=bool, ordem=int), const `TIPOS`, relacao `respostas()` hasMany.

**`NpsRespostaCustomizada`**: fillable + relacoes `pergunta()` belongsTo (nullable) e `response()` belongsTo.

**`NpsResponse`**: ganha `respostasCustomizadas()` hasMany — desbloqueia eager load no `NpsController::index()`.

### Controller (NpsController) — 4 metodos novos + 4 adapts

| Metodo | Comportamento |
|--------|---------------|
| `criarPerguntaExtra` (POST) | Valida + forca opcoes=null se tipo != multipla + ordem=max+1, redireciona com flash |
| `atualizarPerguntaExtra` (PUT) | Campos `sometimes`, recalcula tipo efetivo, zera opcoes se tipo != multipla |
| `excluirPerguntaExtra` (DELETE) | Se tem respostas e sem `?force=1`: soft (ativa=false); senao: hard delete (FK respostas vira null via set null) |
| `moverPerguntaExtra` (POST) | Swap atomico de `ordem` com vizinha (up = maior ordem que ainda e menor; down = oposto), DB::transaction |
| `respond()` | Carrega `perguntas_extras` ativas ordenadas (id, texto, tipo, opcoes, obrigatorio) |
| `submitResponse()` | **Validacao dinamica**: monta rules por tipo (matriz D-02) para cada pergunta ativa. Persiste em DB::transaction (NpsResponse + N NpsRespostaCustomizada com snapshot de texto/tipo) |
| `configuracao()` | Retorna `perguntas_extras` (TODAS, ordenadas) para a 3a tab do Plan 33-02 |
| `index()` | Eager loads `response.respostasCustomizadas` + expoe `respostas_customizadas` no payload de cada linha — Plan 33-04 le isso direto |

### Rotas (4 novas em `routes/web.php`)

Adicionadas no grupo `['auth', 'verified', 'role:admin']` da Phase 32 (ANTES da rota publica `/nps/{token}` para nao colidir):

```
POST   nps/configuracao/perguntas                  nps.configuracao.perguntas.criar
PUT    nps/configuracao/perguntas/{pergunta}       nps.configuracao.perguntas.atualizar
DELETE nps/configuracao/perguntas/{pergunta}       nps.configuracao.perguntas.excluir
POST   nps/configuracao/perguntas/{pergunta}/mover nps.configuracao.perguntas.mover
```

### Testes (`Phase33NpsPerguntasExtrasTest`)

8 cases:
1. `submit rejeita quando pergunta obrigatoria omitida` (422 com `respostas_extras.{id}`)
2. `submit grava resposta extra com snapshot` (texto/tipo congelados)
3. `submit rejeita opcao invalida em multipla` (out of opcoes)
4. `submit rejeita valor invalido em sim_nao` ("talvez")
5. `respond carrega perguntas_extras ativas ordenadas` (skip inativa, ordena por `ordem`)
6. `criar gera ordem incremental` (max+1)
7. `excluir com respostas vira soft delete` (ativa=false)
8. `mover up troca ordem com vizinha` (swap)

**Resultado: 27/27 verdes (19 Phase 31 + 8 Phase 33)**.

## Decisoes Made

Listadas no frontmatter `decisions`. Destaques:

- **opcoes forcadas a null fora de multipla** — defesa contra payload sujo do cliente. UI nao precisa zerar, o backend faz.
- **Snapshot de texto/tipo** — congela o que o cliente VIU. Se admin editar a pergunta depois, respostas historicas continuam mostrando o texto original.
- **Dual delete (soft+hard via ?force=1)** — protege historico por padrao; admin consciente pode hard-delete forcando.
- **Reorder por swap** — O(1) updates, suficiente sem concorrencia real (admin sozinho na UI).

## Commits

- `06892a1` — feat(33-01): cria migrations + models
- `4394f81` — feat(33-01): adapta NpsController + 4 rotas CRUD
- `388d4a6` — test(33-01): suite Phase 33 backend (8 cases)

## Deviations from Plan

**Auto-fixed (Rule 2 — Missing critical):**

1. **[Rule 2] Adicionada validacao `min:2` em opcoes (multipla)** — Plan nao especificava minimo, mas pergunta de multipla escolha com 1 opcao nao faz sentido. UX safeguard.

2. **[Rule 2] Adicionado `min` no shape de mover** — Plan dizia "encontra vizinha" sem especificar `orderByDesc('id')` como tie-breaker. Sem isso, se 2 perguntas tem mesmo `ordem`, o swap ficaria ambiguo. Adicionado tie-breaker `id` desc/asc para determinismo.

3. **[Rule 2] Adicionado `respostas_customizadas` no payload da linha em `index()`** — Plan menciona eager load mas nao especifica o mapeamento exato. Necessario para o Plan 33-04 conseguir ler `row.respostas_customizadas` no modal Abrir. Adicionado o mapping completo com `id, pergunta_id, pergunta_texto, tipo, valor`.

4. **[Rule 2] Suite de testes Phase 33 adicionada** — Plan nao pediu testes explicitamente (so verificacao tinker), mas adicionei 8 cases Feature para garantir contratos (validacao dinamica + snapshot + CRUD) e evitar regressao em proximos plans.

5. **[Rule 2] `configuracao()` retorna `perguntas_extras` (TODAS)** — Plan menciona "ver Plan 33-02" para adicao no payload da configuracao. Adicionei aqui para desbloquear paralelizacao do W2 (Plan 33-02 ja recebe a chave pronta).

**Nenhuma alteracao arquitetural** — todos auto-fixes foram pequenos, dentro do escopo "fundacao", e nao mudam a estrutura D-01..D-07.

## Self-Check

- [x] `database/migrations/2026_06_12_100001_create_nps_perguntas_customizadas_table.php` existe
- [x] `database/migrations/2026_06_12_100002_create_nps_respostas_customizadas_table.php` existe
- [x] `app/Models/NpsPerguntaCustomizada.php` existe
- [x] `app/Models/NpsRespostaCustomizada.php` existe
- [x] `app/Models/NpsResponse.php` modificado (relacao respostasCustomizadas)
- [x] `app/Http/Controllers/NpsController.php` modificado (4 metodos + adapts)
- [x] `routes/web.php` modificado (4 rotas novas)
- [x] `tests/Feature/Phase33NpsPerguntasExtrasTest.php` existe (8 cases)
- [x] Commit `06892a1` no git log
- [x] Commit `4394f81` no git log
- [x] Commit `388d4a6` no git log
- [x] `php artisan migrate` roda (2 migrations aplicadas: ~100ms + ~191ms)
- [x] `php artisan route:list | grep perguntas` mostra 4 rotas
- [x] `php artisan test --filter=Phase31` = 19/19 verdes (zero regressao)
- [x] `php artisan test --filter=Phase33` = 8/8 verdes
- [x] Combinacao = 27/27 verdes (151 assertions)

## Self-Check: PASSED

## Gotchas para os 3 plans paralelos da Wave 2

### Plan 33-02 (UI admin — 3a tab "Perguntas extras" em /nps/configuracao)

- **Prop ja vem pronta:** `perguntas_extras` (collection completa, TODAS perguntas ativas e inativas, ordenadas por `ordem` ASC, fallback `id` ASC). Shape: `[{ id, texto, tipo, opcoes, obrigatorio, ordem, ativa, created_at, updated_at }, ...]`.
- **Endpoints prontos:**
  - POST `route('nps.configuracao.perguntas.criar')` — body: `{ texto, tipo, opcoes[], obrigatorio, ativa }` — `opcoes` so faz sentido em `tipo=multipla` (mas o backend silenciosamente zera se outro tipo); validacao `min:2 opcoes` em multipla.
  - PUT `route('nps.configuracao.perguntas.atualizar', { pergunta: id })` — body: campos `sometimes`.
  - DELETE `route('nps.configuracao.perguntas.excluir', { pergunta: id })` — adiciona `?force=1` na URL para hard-delete (UI deve oferecer essa opcao apenas se a admin clicar 2x).
  - POST `route('nps.configuracao.perguntas.mover', { pergunta: id })` — body: `{ direcao: 'up' | 'down' }`. No-op silencioso se ja esta no extremo.
- **Flash messages:** todos os endpoints retornam `back()->with('success', '...')`, entao a UI pode confiar no toast pattern existente da Phase 32.
- **Const TIPOS exposta:** se precisar listar tipos no select do form, copie do model PHP (`NpsPerguntaCustomizada::TIPOS = ['escala_1_5','texto','sim_nao','multipla']`) e mantenha em sync manual no JSX (padrao do projeto — NpsResponse Phase 31 faz isso com STATUS).
- **Labels sugeridos:** `escala_1_5 → "Escala 1-5"`, `texto → "Resposta livre"`, `sim_nao → "Sim/Nao"`, `multipla → "Multipla escolha"`.
- **Opcoes input dinamico:** mostra so se tipo=multipla. Mantenha array vazio nao nulo no useForm inicial (ex: `opcoes: []`) para evitar "required_if" quebrar.

### Plan 33-03 (UI cliente — Respond.jsx renderiza perguntas custom)

- **Prop ja vem pronta:** `perguntas_extras` (array ordenado). Shape: `[{ id, texto, tipo, opcoes, obrigatorio }, ...]`. NAO contem perguntas inativas (filtro `ativa=true` no respond()).
- **Local no JSX:** entre o ultimo RatingPicker fixo (`empresa`) e o textarea `comment`, conforme D-03.
- **Form shape:** Adicione `respostas_extras: {}` ao `useForm()`. Para cada pergunta, set `respostas_extras[pergunta.id] = valor`. Backend valida assim mesmo (chave numerica string e ok via Validator).
- **Por tipo, render:**
  - `escala_1_5`: reutiliza RatingPicker (mesmo componente local das fixas)
  - `texto`: Textarea max 2000 chars (espelhar validacao)
  - `sim_nao`: 2 botoes lado a lado (Sim/Nao) — valor enviado: `'sim' | 'nao'` (lowercase)
  - `multipla`: Radio group vertical. Valor enviado = texto da opcao (NAO indice)
- **Validacao client espelhada:** marcar required nos obrigatorios, mas confie no 422 backend para erros — ele ja retorna `respostas_extras.{id}` na sessao de erros.
- **Botao submit disabled:** se ha pelo menos 1 obrigatoria sem resposta, desabilita submit (UX igual ao das fixas).

### Plan 33-04 (modal Abrir na lista /nps)

- **Prop ja vem pronta:** Cada linha do paginate `surveys` ja tem `respostas_customizadas` (array). Shape por item: `{ id, pergunta_id, pergunta_texto, tipo, valor }`. **Use o snapshot `pergunta_texto` como fonte canonica** (nao a pergunta atual — pode ter sido editada ou hard-deleted).
- **Quando array vazio:** linha pode ter `respostas_customizadas: []` (survey respondida antes de qualquer pergunta extra existir, ou nao respondida — `status != completed`). Render o bloco "Respostas extras" condicionalmente.
- **Display por tipo:**
  - `escala_1_5`: badge ou pill com nota 1-5 colorida (segue cores do Respond.jsx Phase 31 Plan 03)
  - `texto`: paragrafo em fonte normal, max-width para nao explodir o modal
  - `sim_nao`: badge verde "Sim" / vermelho "Nao"
  - `multipla`: chip neutro com o texto da opcao
- **Header do modal:** ja tem dados — `row.company_name`, `row.completed_at`, `row.respondent` (pode ser null), `row.score_estrategista/analista/empresa`, `row.comment`. Linha SO ganha botao Abrir quando `row.status === 'completed'`.

### Cuidado compartilhado

- **Backend NAO retorna `opcoes` em payloads where tipo != multipla** (forca null no criar/atualizar). UIs devem tratar `opcoes === null` como ausencia.
- **`ordem`** so e visivel/relevante no Plan 33-02. Plans 33-03 e 33-04 confiam na ordenacao ja feita pelo backend.
- **Nao acessar `pergunta_id` diretamente para display no Plan 33-04** — usa `pergunta_texto` (snapshot). Pergunta pode ter sido hard-deleted (`pergunta_id = null`) e o display continua funcional via snapshot.

---

*Phase: 33-perguntas-customizadas-nps*
*Plan: 01 — Fundacao backend*
*Completed: 2026-06-12*
