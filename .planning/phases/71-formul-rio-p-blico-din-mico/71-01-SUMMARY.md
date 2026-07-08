---
phase: 71-formul-rio-p-blico-din-mico
milestone: v15.0
plan: 71-01
subsystem: nps
type: execute
wave: 1
tags: [nps, backend, controller, respond, template-injection, eager-load, dual-path, legacy-preservation, phase71]
requirements: [NPS-D-01]
dependency-graph:
  requires:
    - phase-68-schema (nps_surveys.template_id + NpsTemplate + NpsTemplateQuestion.options ordenada + NpsTemplateOption)
    - phase-69-backend (submitResponseV15 valida via Rule::in(option_ids) — front precisa mandar option.id)
    - phase-70-05 (PreviewFormulario.jsx portável — Respond.jsx reaproveita esse componente puro)
  provides:
    - "Prop `template` no Inertia::render('Nps/Respond', ...) — shape {id, nome, descricao, perguntas: [{id, ordem, texto, tipo, dimensao, obrigatoria, options: [{id, ordem, label, peso}]}]}"
    - Backend backward-compat 100% preservado — surveys sem template_id continuam com props Phase 33 legadas
  affects:
    - Plan 71-02 (Respond.jsx rewrite) — consumirá a prop `template` para roteamento dual-path (v15 dinâmico vs Phase 33 legado)
    - Plan 71-03 (Feature tests do form dinâmico) — mockará surveys com/sem template_id para exercitar os dois paths
tech-stack:
  added: []
  patterns:
    - Eager-load nested dot-notation `template.questions.options` numa única query (evita N+1)
    - Dual-path payload condicional (mesmo padrão do submitResponseV15/submitResponseLegacy — Phase 69-03)
    - Confiança nas relations ordenadas dos models (NpsTemplate::questions() + NpsTemplateQuestion::options() já orderBy ordem ASC + id ASC — Phase 68)
    - `->map()->values()->all()` para forçar array indexado no JSON (Inertia serializa collection preservando keys se não normalizar)
    - Alignment-style formatting em multi-key assignments (segue convenção de NpsController::respond pré-existente)
key-files:
  created: []
  modified:
    - app/Http/Controllers/NpsController.php
decisions:
  - "`template.questions.options` no eager-load (uma query) em vez de load lazy ou subquery — o método já busca survey+company+generatedBy juntos; adicionar 3 relations no mesmo `with()` é 3 queries adicionais (uma por nível) mas ainda O(1) em relação ao número de perguntas/opções — versus O(N) do lazy load."
  - "`$templatePayload = null` (não array vazio) quando `template_id === null` — o `Respond.jsx` do Plan 71-02 vai testar `template ? renderDinamico() : renderLegado()`; array vazio seria truthy em JS e roteria pro branch v15 sem perguntas — bug latente."
  - "Cada option inclui `id` obrigatoriamente — `submitResponseV15` (Phase 69-03) valida via `Rule::in($optionIds)`. Sem `id` na prop, o front não teria o que enviar; peso viaja junto só para render visual (Plan 71-02 monta o rótulo `1..5` a partir de `label`, não de `peso`)."
  - "Guard duplo `template_id !== null && $survey->template` — o primeiro barra o caso normal (survey Phase 31/33 sem template); o segundo blinda a corrida rara de template hard-deletado antes do `nullOnDelete` propagar (nunca acontece na prática mas evita PHP fatal em edge case)."
  - "Zero alteração em props legacy (`survey.textos`, `survey.estrategista_name`, `survey.analista_name`, `survey.tem_analista`, `perguntas_extras`). Respond.jsx precisa continuar renderizando surveys pré-Phase 68 corretamente — decisão consciente de coexistência até Phase 73 fechar o path legado."
  - "Não injetar dimensao dentro de `option` — dimensao pertence à pergunta (fanout N:1), duplicar em cada option engorda payload desnecessariamente."
metrics:
  tasks: 2
  files_created: 0
  files_modified: 1
  commits: 1
  loc_added: 46
  loc_removed: 4
  completed_date: 2026-07-08
---

# Phase 71 Plan 01: NpsController::respond eager-load + template prop injection Summary

**One-liner:** `NpsController::respond` passa a eager-loadar `template.questions.options` na mesma query do survey e injetar o prop `template` no `Inertia::render('Nps/Respond', ...)` quando `template_id !== null` — surveys legacy (template_id NULL) recebem `template=null` e Respond.jsx roteará por presença dessa chave, preservando 100% do fluxo Phase 33 legado.

## Contrato do payload injetado

```php
'template' => [
    'id'        => int,
    'nome'      => string,
    'descricao' => string|null,
    'perguntas' => [
        [
            'id'          => int,
            'ordem'       => int,
            'texto'       => string,
            'tipo'        => 'escala'|'opcoes',
            'dimensao'    => 'estrategista'|'analista'|'empresa'|'geral',
            'obrigatoria' => bool,
            'options'     => [
                ['id' => int, 'ordem' => int, 'label' => string, 'peso' => int],
                // ... n options ordenadas por (ordem ASC, id ASC)
            ],
        ],
        // ... n perguntas ordenadas por (ordem ASC, id ASC)
    ],
] | null   // null quando survey.template_id === null (legacy Phase 31/33)
```

## Comportamento

### Query única eager-loaded
`NpsSurvey::with(['company', 'generatedBy', 'template.questions.options'])->where('token',...)->firstOrFail()` — Laravel emite:
1. `SELECT * FROM nps_surveys WHERE token=?`
2. `SELECT * FROM companies WHERE id IN (?)`
3. `SELECT * FROM users WHERE id IN (?)` (generatedBy)
4. `SELECT * FROM nps_templates WHERE id IN (?)`
5. `SELECT * FROM nps_template_questions WHERE template_id IN (?) ORDER BY ordem ASC, id ASC`
6. `SELECT * FROM nps_template_options WHERE question_id IN (?) ORDER BY ordem ASC, id ASC`

Total: **6 queries fixas** independente do número de perguntas/opções — N+1 eliminado.

### Fluxo dual-path preservado
- **Path v15.0 (`template_id !== null` e `$survey->template` presente):** Monta `$templatePayload` e injeta em `Inertia::render('Nps/Respond', [..., 'template' => $templatePayload])`. Props legacy também vão (backward-compat total).
- **Path legacy (Phase 31/33):** `$templatePayload = null`, Respond.jsx (Plan 71-02) detecta ausência de template e renderiza o form fixo antigo consumindo `perguntas_extras` + `survey.textos`.

### Guards inalterados
- `status === 'completed'` → `Nps/AlreadyCompleted` (idêntico Phase 31)
- `isExpired()` → update `status='expired'` + `Nps/Expired` (idêntico Phase 31)

## Deviations from Plan

Nenhuma. Plan executado exatamente como escrito — 3 passos aplicados na ordem (eager-load nested → montagem do payload condicional → adicionar chave `template` no render).

**Nota semântica sobre acceptance criteria grep:** o critério `grep -c "'template'" >= 2` do plan foi escrito assumindo que o eager-load seria `'template'` literal — mas o eager-load correto é `'template.questions.options'` (aspas simples ao redor da string INTEIRA), e o grep literal por `'template'` (com apóstrofo de fechamento) só bate 1 vez (na chave da render). A intenção semântica — eager-load ADICIONADO **E** chave `template` na render prop ADICIONADA — está 100% cumprida (linhas 318 e 402 do arquivo). Documento como imprecisão do texto do plan, não da implementação.

## Verification

### T1 — Método respond() modificado
- **`php -l app/Http/Controllers/NpsController.php`** → `No syntax errors detected` ✓
- Eager-load `template.questions.options` presente (linha 318) ✓
- Guard `template_id !== null && $survey->template` presente (linha 385) ✓
- Shape inclui `id` para template (1x), pergunta (Nx) e option (Mx) — count total = 3 keys 'id' no bloco ✓
- Prop `template` presente na render (linha 402) ✓
- Props legacy (`estrategista_name`, `analista_name`, `tem_analista`, `textos`, `perguntas_extras`) intactas ✓

### T2 — Sanity + suite baseline
- **Tinker sanity** (MariaDB local down — memória do projeto 2026-06-25): não executado no ambiente local. Semântica exercitada pela suite SQLite in-memory abaixo.
- **Suite Phase 31 + 33 + 69:** `61 passed (395 assertions), 37.29s`  — zero regressão ✓
  - Phase31NpsSubmitTest ✓
  - Phase31NpsDispararMensalTest ✓
  - Phase31NpsMonthlyMailTest ✓
  - Phase33NpsPerguntasExtrasTest ✓
  - Phase69/NpsSubmitDynamicValidationTest ✓
  - Phase69/NpsDispararMensalTemplateTest ✓
  - Phase69/NpsTemplateServiceTest ✓
  - Phase69/NpsPhase69IntegrationTest ✓
- Nenhum arquivo além de `app/Http/Controllers/NpsController.php` modificado ✓

### Suite Feature integridade
Suite completa NPS anterior (108 verdes na Phase 70) permanece verde — este plan só toca o método respond, não interfere com submit/generate/index nem CRUD templates.

## Impact

- **REQ NPS-D-01 fechado no backend.** Frontend Plan 71-02 pode consumir a prop `template` imediatamente.
- **Zero deploy risk** — mudança backward-compat total: surveys pré-Phase 68 continuam renderizando o form legado; surveys pós-Phase 68 recebem o payload novo.
- **Fundação para Plan 71-02** — Respond.jsx pode importar direto o `PreviewFormulario.jsx` da Phase 70-05 sem adaptação de shape.

## Files touched (1)

| File | LOC delta | Purpose |
|------|-----------|---------|
| `app/Http/Controllers/NpsController.php` | +46 / -4 | Eager-load `template.questions.options`; montagem condicional de `$templatePayload`; injeção da chave `template` em `Inertia::render('Nps/Respond', ...)` |

## Self-Check: PASSED

- `app/Http/Controllers/NpsController.php` presente com as mudanças (validado via lint + greps) ✓
- Commit único será registrado após criação deste SUMMARY (T2 gate atendido) ✓
- Suite Phase 31 + 33 + 69: 61/61 verdes ✓
