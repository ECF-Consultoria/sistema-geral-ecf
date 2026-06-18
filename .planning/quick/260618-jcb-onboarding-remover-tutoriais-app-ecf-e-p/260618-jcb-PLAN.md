---
quick_id: 260618-jcb
description: "Onboarding — remover tutoriais App ECF e Planilha de Produtos + tornar link App ECF (Adman) global"
date: 2026-06-18
status: in-progress
---

# Quick Task 260618-jcb — Onboarding: tutoriais e link global

## Contexto

No módulo de Onboarding (MLB Implementação) o checklist é definido em
`MlbImplementacao::CHECKLIST`. Itens com `tem_tutorial => true` ganham um
campo de URL de tutorial nos modais de admin (`ConfigurarForm` por-empresa e
`PadroesModal` global) e um botão "Tutorial" no workspace público do cliente.

Mapeamento dos termos do usuário → código:
- "App ECF" = "Adman" = item `app_ecf` (tipo `link_admin`). O "link da Adman
  por conta" é o `links_admin.app_ecf`, rotulado "Link — App ECF".
- "Planilha de Produtos" = item `planilha_produtos`.

## Objetivo

1. Remover o tutorial do item **App ECF** (não precisa mais dessa opção).
2. Remover o tutorial do item **Planilha de Produtos**.
3. Transformar o link do **App ECF** de per-conta para **global** (Padrões
   Globais), servindo todas as contas.

Tutoriais de **Acesso Colaborador** e **Inscrição Estadual** permanecem.

## Tarefas

### Tarefa 1 — Backend: checklist + defaults
- `app/Models/MlbImplementacao.php`:
  - `CHECKLIST`: `app_ecf` e `planilha_produtos` → `tem_tutorial => false`.
  - `dadosPadrao()['tutoriais']`: remover chaves `app_ecf` e `planilha_produtos`
    (manter `acesso_colaborador`, `inscricao_estadual`).
- `app/Models/MlbConfiguracao.php`:
  - `implementacaoPadroes()` base `tutoriais`: remover `app_ecf`,
    `planilha_produtos`.
  - base `links_admin_extra`: adicionar `'app_ecf' => ''`.
- **verify:** `app_ecf` e `planilha_produtos` não aparecem mais em nenhum
  array de tutoriais; `links_admin_extra` contém `app_ecf`.

### Tarefa 2 — Backend: injeção do link global no workspace público
- `app/Http/Controllers/MlbImplementacaoController.php`:
  - Em `workspace()` e `publicador()`, antes do `Inertia::render`, injetar:
    `$dados['links_admin']['app_ecf'] = MlbConfiguracao::implementacaoPadroes()['links_admin_extra']['app_ecf'] ?? '';`
    (padrão idêntico ao `tabela_frete_url` já existente).
- **verify:** o item `app_ecf` (tipo `link_admin`) passa a ler sempre o link
  global; contas existentes pegam o link sem migração.

### Tarefa 3 — Frontend: mover campo de link App ECF para Padrões
- `resources/js/Pages/Mlb/Implementacao.jsx`:
  - `ConfigurarForm` (per-empresa): remover o campo de link `app_ecf` da lista
    "Links configurados por vocês" e do estado `form.links_admin`.
  - `PadroesModal` (global): adicionar campo "Link — App ECF" na lista
    `links_admin_extra` (junto de `programa_decola`/`tabela_frete`) e no estado
    do form.
- **verify:** campo App ECF some do modal por-empresa e aparece no modal de
  Padrões Globais.

### Tarefa 4 — Build
- `npm run build` (convenção do projeto). Sem deploy.

## Fora de escopo (NÃO mexer)
- Tutoriais de Acesso Colaborador e Inscrição Estadual.
- Link `programa_decola` (continua per-conta + seed global como está).
- `DadosView` (visão interna admin dos dados do cliente).
- Factory de criação (`MlbImplementacaoFactory`) — render-injection já cobre.
