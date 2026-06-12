---
phase: 34-cadastro-comercial-otimizado-hubspot
plan: 02
subsystem: frontend+backend
tags: [wizard, comercial, react-imask, validation, close-fields, mascaras]
dependency_graph:
  requires:
    - 34-01 (companies.nicho + companies.dor + companies.vende_ml + companies.faturamento_mensal + companies.marketplaces_extras + companies.email_colaborador + fillable + casts)
  provides:
    - ComercialController::store valida e persiste 6 campos do close
    - Wizard Comercial/NovaEmpresa captura os 6 campos do close (passo 2, bloco opcional)
    - Mascaras CNPJ + Telefone via react-imask (D-08)
  affects:
    - resources/js/Pages/Comercial/NovaEmpresa.jsx
    - app/Http/Controllers/ComercialController.php
tech_stack:
  added:
    - react-imask ^7.6.1 (mascaras controladas no front)
  patterns:
    - useForm.transform() para converter vende_ml string → null/true/false antes do POST
    - IMaskInput com mask array dinamico (auto-switch 10/11 digitos telefone)
    - Bloco opcional sem destaque amarelo (diferencia de onboarding Polos)
key_files:
  modified:
    - app/Http/Controllers/ComercialController.php
    - resources/js/Pages/Comercial/NovaEmpresa.jsx
    - package.json
    - package-lock.json
decisions:
  - D-08 mascaras CNPJ/Telefone — fricicao UX so no front; backend NAO valida formato
  - D-09 marketplaces_extras fixos em 5 opcoes (shopee/amazon/magalu/temu/tiktok) via Rule::in
  - vende_ml renderizado como Select 3-estados (nao radio) — mais compacto e claro
  - Bloco "Informacoes do close" colocado dentro do passo 2 (nao criou passo 3) — preserva estetica do stepper de 2 passos atual
  - transform() do useForm vs normalize manual no submit — mais limpo e nao precisa duplicar conversao
metrics:
  duration_min: 22
  completed_date: 2026-06-12
  tasks_completed: 2
  files_modified: 4
  commits: 2
  tests_total_green: 36
requirements:
  - REQ-34-05
  - REQ-34-06
---

# Phase 34 Plan 02: UI cadastro Comercial — campos do close + mascaras CNPJ/Telefone Summary

Wizard `Comercial/NovaEmpresa.jsx` ganha bloco "Informacoes do close (opcional)" no passo 2 com 6 campos novos (nicho, dor, vende_ml, faturamento_mensal, marketplaces_extras, email_colaborador), mascaras CNPJ/Telefone via `react-imask`, e `ComercialController::store` valida e persiste os mesmos 6 campos. Tudo opcional (nullable) — Comercial preenche o que tem na mao no fechamento; estrategista/analista consomem depois sem reentrevistar o cliente.

## Tasks Executadas

| # | Task | Commit | Arquivos |
|---|------|--------|----------|
| 1 | (skipped — react-imask ja instalado pelo sub-agente anterior) | — | — |
| 2 | ComercialController::store valida + persiste 6 campos novos | `4961793` | ComercialController.php |
| 3 | Wizard NovaEmpresa.jsx + IMaskInput + bloco close | `7fb0197` | NovaEmpresa.jsx + package.json + package-lock.json |

## Decisoes Tomadas

### Bloco close DENTRO do passo 2 (nao um passo 3 novo)

O plano sugeria executor decidir entre "passo 2 expansivel" ou "passo 3 novo". Optei pelo passo 2 porque:
- Stepper de 2 passos atual fica claro e curto (Quick 260612-jyx desenhou assim).
- Header "Informacoes do close (opcional)" com badge `(opcional)` deixa explicito que NAO bloqueia o submit.
- Box neutro (border-white/[0.06] bg-white/[0.015]) — diferenciado visualmente do bloco Polos (border ecf-yellow) pra deixar claro que NAO eh onboarding.
- Posicionado depois do bloco condicional Polos + antes da nota final.

### Select 3-estados para vende_ml (nao radio)

O cast no model eh `boolean` mas a coluna eh `tinyint nullable` — null significa "nao sei". Select com 3 opcoes (`'' / 'true' / 'false'`) eh mais compacto que 3 radios + mais facil de entender que "sem selecao = nao sei". `useForm.transform()` converte para `null/true/false` no submit.

### Mascara CNPJ + Telefone via IMaskInput controlado

`IMaskInput` recebe `value={data.cnpj}` + `onAccept={(v) => setData('cnpj', v)}` — mantem o controle no useForm. Para telefone usei `mask` como **array** de 2 layouts (`(00) 0000-0000` e `(00) 00000-0000`) — react-imask auto-switcheia conforme o usuario digita (10 ou 11 digitos).

### Backend NAO valida formato CNPJ/Telefone (D-08 confirmado)

A mascara entrega ao backend uma string com pontuacao (ex: `12.345.678/0001-90`). Validar formato no backend bloquearia retoques manuais futuros (ex: admin colando CNPJ sem mascara). Mantive so `max:20` no telefone e `max:20` + `unique:companies,cnpj` no CNPJ — paridade com o estado anterior.

### Marketplaces extras como array nativo + Rule::in no backend

Front envia `marketplaces_extras: ['shopee','amazon']` direto (axios serializa como `marketplaces_extras[]=shopee&...`). Backend valida com `'nullable|array'` + `'marketplaces_extras.*' => Rule::in([...])` — qualquer slug fora dos 5 da lista (D-09) rejeita. Model `Company` ja tem cast `'array'` (Plan 34-01).

### transform() do useForm vs normalize manual

Em vez de fazer `const payload = {...data, vende_ml: ...}; post(payload)`, usei `transform((d) => ({...d, vende_ml: d.vende_ml === '' ? null : d.vende_ml === 'true'}))` — useForm aplica essa transformacao automaticamente em todo POST/PUT. Mais limpo + se um dia precisar reusar o submit em outro botao, a conversao acontece sozinha.

## Verificacao

- [x] `react-imask` ja estava em `package.json` + `node_modules/` (sub-agente anterior instalou)
- [x] `npm run build` verde (12.92s; NovaEmpresa.js 80.29 kB / gzip 22.79 kB)
- [x] Tinker round-trip: `Company::create([...6 campos...])` + `refresh()` retorna todos populados (nicho=string, dor=string, vende_ml=true bool, faturamento_mensal="50000.75" decimal:2, marketplaces_extras=array JSON, email_colaborador=email)
- [x] Suite `php artisan test --filter="Phase31|Phase33|Phase34CompaniesCloseFieldsTest"` — 36/36 verdes (184 assertions), zero regressao no meu escopo
- [x] Suite completa Phase31|Phase33|Phase34 reporta 4 falhas no `Phase34HubspotWebhookTest` — Plan 34-04 paralelo do outro dev, FORA do meu escopo (documentado em Deferred Issues)

## Desvios do Plano

### Auto-fixed (Rule 1/2/3)

**1. [Rule 3 - Escopo cruzado] Commit `4961793` capturou tambem alteracoes do Plan 34-03 no metodo update() e empresas()**

- **Encontrado durante:** Task 2 (apos rodar `git commit`)
- **Issue:** O outro sub-agente (Plan 34-03) editou o `ComercialController.php` em paralelo — alteracoes no `empresas()` (linhas 90-95, 125-126) e no `update()` (linhas 389-397) ja estavam no working tree. Como `git add` adiciona o snapshot inteiro, o commit `4961793` levou todas as mudancas pendentes do arquivo, mesmo eu querendo so as do `store()`.
- **Fix:** Documento aqui (nao da pra retroativamente separar sem `git reset`). O conteudo eh semanticamente correto (paridade store/update) — apenas a separacao por plan ficou misturada.
- **Impacto:** Zero — Plan 34-03 SUMMARY deve apenas registrar que essas mudancas dele foram absorvidas no commit `4961793` deste plan.

### Architectural (Rule 4)

Nenhum.

## Deferred Issues

### Plan 34-04 (HubSpot Webhook) — 4 testes vermelhos

`Tests\Feature\Phase34HubspotWebhookTest` reporta 4 falhas:
- `evento ignorado quando propriedade irrelevante`
- `evento processado cria company`
- `idempotencia nao duplica company`
- `erro no fetch marca status erro e retorna 200`

NAO eh do meu escopo (Plan 34-04 — controller em `app/Http/Controllers/Api/HubspotWebhookController.php` ainda em andamento pelo outro sub-agente). Surfacing aqui para o orquestrador ciente.

## Gotchas para Quem For Consumir

### Wizard agora envia `vende_ml` como bool/null (nao mais string)

`transform()` do useForm faz a conversao SO no momento do submit. Em qualquer leitura via `useForm.data.vende_ml` dentro do componente, ainda eh string (`'' | 'true' | 'false'`). Nao confiar em `data.vende_ml === true` no JSX — eh sempre string.

### IMaskInput envia string com mascara (com pontuacao) para o backend

`data.cnpj` vai como `'12.345.678/0001-90'` (nao `'12345678000190'`). Se algum lugar futuro precisar comparar/buscar por CNPJ, normalizar removendo pontuacao antes (`str_replace(['.', '/', '-'], '', $cnpj)`).

### marketplaces_extras vazio = array vazio, nao null

Se o usuario NAO marcar nenhum marketplace, `data.marketplaces_extras` eh `[]`. O backend recebe `[]` → validate('nullable|array') aceita → persiste como `[]` (array vazio JSON). Diferente de null. Se quiser distinguir "nao perguntou" vs "perguntou e nao tem", olhar a contagem.

### Bloco close compartilha o componente `<Field>` do form principal

`Field` usa `error={errors.X}` — se o backend rejeita `nicho`, `dor`, etc., o erro aparece in-place no bloco close. `STEP1_FIELDS` NAO inclui esses campos (eles sao do passo 2), entao `onError` do `post()` NAO volta pro passo 1 quando o erro eh em campo do close — comportamento desejado.

## Self-Check: PASSED

- [x] FOUND: `app/Http/Controllers/ComercialController.php` (modificado, com `nicho|dor|vende_ml|...` no `store()`)
- [x] FOUND: `resources/js/Pages/Comercial/NovaEmpresa.jsx` (modificado, import `IMaskInput`, useForm com 6 campos novos, bloco close no passo 2)
- [x] FOUND commit: `4961793` (`feat(34-02): valida e persiste campos do close no ComercialController::store`)
- [x] FOUND commit: `7fb0197` (`feat(34-02): wizard NovaEmpresa com bloco close + mascaras CNPJ/Telefone`)
- [x] `npm run build` verde
- [x] Suite Phase 31/33/34 (escopo proprio) 36/36 verdes
- [x] Tinker round-trip dos 6 campos OK
