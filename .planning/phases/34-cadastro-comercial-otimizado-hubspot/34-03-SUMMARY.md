---
phase: 34-cadastro-comercial-otimizado-hubspot
plan: 03
subsystem: ui-admin-comercial
tags: [companies, comercial, show, modal, mascaras, pendencia, empresa-nova, react-imask]
dependency_graph:
  requires:
    - 34-01 (schema 9 colunas + pendencia empresa_nova + rota marcar-visto)
    - 34-02 (react-imask instalado em package.json + ComercialController::store)
  provides:
    - Modal admin /companies edita 6 campos do close + email_colaborador SEPARADO de email_cliente
    - Companies/Index PENDENCIAS const inclui empresa_nova (D-06)
    - Botao "Marcar como visto" inline na linha (icone Check emerald, role:admin)
    - Companies/Show secao "Informacoes do Close" (read-only, 6 campos + dor multiline)
    - Comercial/Empresas edit form ganha 6 campos + mascaras CNPJ/Telefone
    - Mascaras IMaskInput em /companies modal e /comercial/empresas (D-08)
  affects:
    - app/Http/Controllers/CompanyController.php (validation update + payload show)
    - resources/js/Pages/Companies/Index.jsx
    - resources/js/Pages/Companies/Show.jsx
    - resources/js/Pages/Comercial/Empresas.jsx
tech_stack:
  added:
    - react-imask IMaskInput em 2 sites (Companies/Index modal + Comercial/Empresas form)
  patterns:
    - useForm.transform converte vende_ml ''/'true'/'false' -> null/bool e faturamento_mensal '' -> null no submit
    - MARKETPLACES_EXTRAS constante duplicada em 3 sites (Index, Empresas, NovaEmpresa do Plan 34-02) — espelha Rule::in backend
    - Mascara dinamica Telefone IMaskInput aceita 8 ou 9 digitos (sem fricao pra fixo nem celular)
    - usePage().props.auth.user.role gate para botao admin-only
key_files:
  created: []
  modified:
    - app/Http/Controllers/CompanyController.php
    - resources/js/Pages/Companies/Index.jsx
    - resources/js/Pages/Companies/Show.jsx
    - resources/js/Pages/Comercial/Empresas.jsx
decisions:
  - D-06 botao marcar-visto inline na linha (vs modal de confirmacao) — fluxo enxuto pro admin
  - D-07 email_colaborador SEPARADO de email_cliente com labels claras NPS vs ECF nos 3 sites
  - D-08 mascaras IMaskInput so no front (backend nao valida formato) — fricao UX mas aceita string livre
  - D-09 MARKETPLACES_EXTRAS 5 opcoes canonicas (shopee/amazon/magalu/temu/tiktok) com Rule::in
metrics:
  duration_min: 30
  completed_date: 2026-06-12
  tasks_completed: 4
  files_modified: 4
  files_created: 0
  commits: 4
  tests_total_green: 42
requirements:
  - REQ-34-07
  - REQ-34-08
  - REQ-34-09
---

# Phase 34 Plan 03: Admin UI + Comercial edit + Show — novos campos + máscaras Summary

Wave 2 complete (paralela com 34-02 e 34-04). Plan 34-03 fecha a UI dos campos do close em 3 sites JSX restantes: modal admin de `/companies`, view `Companies/Show`, e edit form de `/comercial/empresas`. Adiciona o badge + botao "Marcar como visto" inline (D-06), aplica mascaras CNPJ/Telefone via IMaskInput (D-08), e separa visualmente `email_colaborador` (ECF) de `email_cliente` (NPS) nos 3 sites (D-07).

## Tasks Executadas

| # | Task | Commit | Arquivos |
|---|------|--------|----------|
| 1 | CompanyController backend (validation update + payload show) | `47372c4` | app/Http/Controllers/CompanyController.php |
| 2 | Companies/Index.jsx (modal + PENDENCIAS + botao marcar-visto + mascaras) | `6261edb` | resources/js/Pages/Companies/Index.jsx |
| 3 | Companies/Show.jsx (nova secao "Informacoes do Close") | `ddf1d81` | resources/js/Pages/Companies/Show.jsx |
| 4 | Comercial/Empresas.jsx (edit form + 6 campos + mascaras) | `322abcc` | resources/js/Pages/Comercial/Empresas.jsx |

## Decisoes Tomadas

### `ComercialController::update` ja estava com as validations (commits prevenidos do 34-02)

Ao tentar commitar a Task 1, `git status` reportou ComercialController limpo apos meu Edit — investigacao revelou que o commit `4961793` (oficialmente "Plan 34-02") tambem incluiu minhas adicoes a `::update()` e ao payload de `empresas()`. Conflito de merge previsto na entrada do plan se materializou em **co-autoria silenciosa** — o desenvolvedor que rodou 34-02 antes preservou meu trabalho 34-03 no mesmo commit. Sem conflito real, sem retrabalho — adaptei o escopo da Task 1 (so CompanyController).

### `useForm` desestruturado em duas etapas + chamada a `transform()` fora do hook

`useForm` retorna um objeto com `transform()` como METODO, nao funcao retornada. Inicialmente desestruturei `put` direto, perdendo acesso ao `form.transform()` (que precisa ser chamado UMA vez apos o useForm). Corrigi capturando o objeto inteiro como `form`, desestruturando `data/setData/processing/errors`, chamando `form.transform(...)` fora do JSX, e pegando `put = form.put`. Pattern aplicado tambem em Comercial/Empresas.jsx pra paridade.

### `vende_ml` no UI usa '' / 'true' / 'false' e converte no transform

Backend valida `nullable|boolean`. Mas Select da shadcn rejeita `value=""` (assume "nao controlado"). Solucao: estado interno do form usa string vazia/'true'/'false', e `transform()` converte para `null|true|false` no submit. No JSX do modal admin, valor exibido eh `data.vende_ml || 'unknown'` (Select aceita 'unknown' como sentinel e converte de volta no onValueChange). No JSX comercial, native `<select>` aceita string vazia sem hack.

### `faturamento_mensal` '' -> null no transform

Validation backend `nullable|numeric|min:0` REJEITA string vazia (passa pelo `nullable` mas falha no `numeric` quando recebe ''). Transform converte '' -> null antes do submit.

### Botao "Marcar como visto" inline + icone Check (sem modal de confirmacao)

D-06: admin marca 5-10 empresas por dia ao abrir a tela. Modal de confirmacao = atrito desnecessario. Botao Check icon-only, hover emerald — clica e a pendencia some via `preserveScroll` (sem reload de pagina). Implementado como `<button>` HTML nativo (nao `<Button>` shadcn) para nao herdar padding/focus-ring do design system.

### Pre-population de `marketplaces_extras` faz copia defensiva

`[...c.marketplaces_extras || []]` em vez de `c.marketplaces_extras` direto — evita compartilhar referencia do array com o backend payload (se o form mutar via `setData`, o backend recebe a mutacao em payloads futuros sem submeter). Pattern aplicado em ambos useForm.

### `pendCounts` corrigido (faltava `sem_servico`)

Codigo legado do Phase 14 esqueceu de adicionar `sem_servico: 0` ao `pendCounts` quando o tipo foi introduzido — o badge de filtro contava `undefined` (logica defensiva no forEach: `if (pendCounts[p] !== undefined)` pulava). Resultado: o card "Sem servico" sempre mostrava 0 mesmo com empresas reais. Corrigi como bonus (Rule 1) ja que estava no mesmo bloco do `empresa_nova: 0`.

## Verificacao

- [x] `npm run build` verde (3 vezes, apos cada Task JSX)
- [x] Suite Phase 31+33+34 — 42/42 verdes (233 assertions), zero regressao
- [x] Tinker: Company::create com os 6 campos novos persiste corretamente + empresa_nova=true default
- [x] Tinker: marketplaces_extras lido como array (cast 'array' funcionando)
- [x] Rota `companies.marcar-visto` registrada (verificada no 34-01, intacta)

## Desvios do Plano

### Auto-fixed (Rule 1/2/3)

**1. [Rule 1 - Bug] `pendCounts` faltava `sem_servico: 0`**

- **Encontrado durante:** Task 2 (ao adicionar `empresa_nova: 0` percebi o mesmo gap pra `sem_servico`)
- **Issue:** O card de filtro "Sem servico" sempre mostrava 0 — o `forEach` que incrementa pulava o slot por ele ser `undefined`.
- **Fix:** Adicionado `sem_servico: 0` no init do `pendCounts` (linha original).
- **Justificativa:** Bug pre-existente do Phase 14; corrigir junto evita confusao no UX da aba Pendencias e aproveita o commit.
- **Commit:** `6261edb`

**2. [Rule 2 - Robustez] CompanyController::show() agora exporta os 6 campos do close**

- **Encontrado durante:** Task 1 (preparando o payload pra Show.jsx)
- **Issue:** O `show()` so exportava `email_cliente`/`telefone`, sem os 6 campos novos. A secao "Informacoes do Close" do Show.jsx ficaria so com placeholders.
- **Fix:** Adicionado bloco identico ao do `index()` no payload do `show()`, incluindo conversao `(float)` defensiva pro `faturamento_mensal`.
- **Justificativa:** Sem isso o Plan 34-03 nao entrega o que o ROADMAP promete (REQ-34-09).
- **Commit:** `47372c4`

**3. [Rule 2 - Robustez] `email_cliente` re-rotulado de "Email do Colaborador" para "Email do cliente (NPS)"**

- **Encontrado durante:** Task 2
- **Issue:** O modal admin tinha o label "Email do Colaborador (acesso ML do cliente / NPS)" historicamente confuso — misturava semanticas que o Phase 34 separa em 2 campos.
- **Fix:** Renomeado label do `email_cliente` para "Email do cliente (NPS)" + helper text "destinatario do NPS mensal", para casar com o novo `email_colaborador` "Email colaborador ECF".
- **Justificativa:** D-07 exige labels claras nos 3 sites. UX consistency.

### Architectural (Rule 4)

Nenhum.

## Conflito com 34-02 / 34-04

**NAO houve conflito de merge no `ComercialController`.** O commit oficial do Plan 34-02 (`4961793`) ja contem minhas adicoes a `::update()` e ao payload de `empresas()` — provavel que o dev executando 34-02 em paralelo capturou meu working tree e commitou junto. Sem retrabalho. Adaptei a Task 1 do meu plan para commitar so o CompanyController (cuja diff era exclusiva do 34-03).

## Gotchas para Plans Futuros

### `useForm.transform()` chamado uma vez fora do useEffect

`useForm` da Inertia retorna um objeto onde `transform()` eh METODO mutador — chamar duas vezes ANULA o anterior. Codigo atual chama `form.transform(...)` no body do componente em todo render — funciona porque o callback eh estavel (sem deps mutaveis), mas se virar dinamico (ex: transform diferente para edit vs create), envolver em `useEffect([deps], ...)` ou `useMemo` para nao reanexar a cada render.

### `IMaskInput` `value` precisa ser controlado por `onAccept` (nao `onChange`)

react-imask dispara `onAccept(value, mask)` apos aplicar a mascara — `onChange` recebe o valor RAW (sem mascara). Se controlar por `onChange`, o `data.cnpj` no useForm fica sem pontuacao, e ao re-renderizar o input perde o cursor + pula caracteres. Pattern correto: `onAccept={(v) => setData('cnpj', v)}`. Aplicado em todos os 4 IMaskInput novos.

### Botao `<button type="button">` dentro de `<form>` evita submit acidental

O botao "Marcar como visto" no Pendencias tab vive dentro do form do modal de edicao? **NAO.** Tabela Pendencias eh sibling do form. Mas defensivamente usei `type="button"` para garantir compatibilidade se a estrutura mudar (Rule 2). Mesma defesa aplicada ao botao "Marcar como visto" no Companies/Index modal — `marcarVisto` eh handler separado, fora do `submit`.

### `Companies/Show.jsx` payload depende de `company.faturamento_mensal != null`

Cast `decimal:2` retorna string em SQLite, numero em MySQL. Para evitar bug sutil em prod (string nao bate em `formatCurrency`), o controller converte `(float)` no payload. Pattern documentado no Plan 34-01 (Gotcha Pitfall 4) e replicado aqui em 2 lugares (index e show payloads de CompanyController).

## Self-Check: PASSED

- [x] FOUND: `app/Http/Controllers/CompanyController.php` (modificado)
- [x] FOUND: `resources/js/Pages/Companies/Index.jsx` (modificado)
- [x] FOUND: `resources/js/Pages/Companies/Show.jsx` (modificado)
- [x] FOUND: `resources/js/Pages/Comercial/Empresas.jsx` (modificado)
- [x] FOUND commit: `47372c4` (CompanyController)
- [x] FOUND commit: `6261edb` (Companies/Index)
- [x] FOUND commit: `ddf1d81` (Companies/Show)
- [x] FOUND commit: `322abcc` (Comercial/Empresas)

```bash
$ git log --oneline -4
322abcc feat(34-03): Comercial/Empresas edit form ganha 6 campos do close + mascaras IMaskInput
ddf1d81 feat(34-03): Companies/Show adiciona secao "Informacoes do Close" (6 campos do D-01)
6261edb feat(34-03): Companies/Index modal admin + botao marcar-visto + mascaras CNPJ/Telefone
47372c4 feat(34-03): adiciona validacao + payload show dos 6 campos de close em CompanyController
```

## CRITICO: NAO Deployar Sozinho

Plan 34-03 entrega a UI dos 6 campos novos. Funciona standalone (sem 34-04) porque consome so o schema do 34-01. Mas para garantir paridade dos 4 plans da Phase 34 — **agrupar deploy** dos quatro (34-01 schema, 34-02 wizard comercial, 34-03 admin UI + Comercial edit, 34-04 webhook HubSpot). Subir so 34-03 nao traz o ponto de entrada automatico (webhook) e nao faz uso da auditoria `hubspot_eventos` ainda.
