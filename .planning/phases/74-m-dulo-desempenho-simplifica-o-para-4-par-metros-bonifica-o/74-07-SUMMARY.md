---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 07
subsystem: Frontend Desempenho (Configuração admin da régua de bônus)
tags: [frontend, react, jsx, admin, config, form, useForm]
requires:
  - .planning/phases/74-.../74-05-SUMMARY.md (DesempenhoConfigController + UpdateBonusFaixaRequest + rotas)
provides:
  - Desempenho/Configuracao.jsx (UI CRUD-ish para faixas de bônus)
affects:
  - resources/js/Pages/Desempenho/Configuracao.jsx (novo)
tech-stack:
  patterns:
    - useForm() do @inertiajs/react para state do form
    - router.patch para toggle sem body
    - flash.success via usePage().props
    - Toast auto-dismiss (setTimeout 3s)
    - Design tokens dark/glass (ecf-card, ecf-yellow, cn())
key-files:
  created:
    - resources/js/Pages/Desempenho/Configuracao.jsx
decisions:
  - D-12 · Página React lista todas as faixas (ativas + inativas)
  - D-13 · Erros de validação backend exibidos ao lado do campo
  - D-21 · Sem CRUD de nova faixa (admin trabalha com seed fixo de 4)
metrics:
  duration_min: 20
  completed: 2026-07-09
  tasks_completed: 1-of-2-jsx (build deferido para final da Wave 4)
requirements:
  - DESEMP-12 (UI CRUD faixas de bônus)
---

# Phase 74 Plan 07: Página Admin da Régua de Bônus

**One-liner:** Página `Desempenho/Configuracao.jsx` para admin editar inline as 4 faixas de `bonus_faixas` (nome, descrição, nota_min/max, ordem) + toggle ativo/inativo preservando histórico + validação de sobreposição exibida em pt-BR ao lado do campo.

## Objetivo

Criar a página Inertia `Desempenho/Configuracao.jsx` — UI admin para editar as faixas de `bonus_faixas` inline. Fechar o lado frontend do REQ DESEMP-12 (D-12).

## Arquivo entregue

### `resources/js/Pages/Desempenho/Configuracao.jsx` (371 linhas)

**Estrutura:**
- Componente principal `Configuracao({ faixas })` consumindo `usePage().props.flash`
- Sub-componentes internos:
  - `Toast` — flash.success com auto-dismiss em 3s
  - `Cabecalho` — título + subtítulo + link "Ver artigo do Manual"
  - `AlertaRegras` — box amarelo com regras de validação em pt-BR
  - `FaixaCard` — card individual editável por faixa

**Recursos por FaixaCard:**
- Header: input inline para nome + chip do slug + badge "Inativa" (se aplicável)
- Botão toggle ativo/inativo (canto superior direito, verde/cinza)
- Grid 3 colunas: `nota_min` / `nota_max` / `ordem` (inputs numéricos com step 0.01)
- Textarea `descricao` com placeholder pt-BR
- Preview inline atualiza on-change: "Faixa cobre notas de X.XX a Y.YY"
- Botão "Salvar alterações" disabled quando `!isDirty || processing`
- Erros de validação renderizados ao lado do campo com ícone AlertCircle

**Design tokens dark/glass:**
- `bg-ecf-card`, `border-white/[0.08]`, `rounded-2xl`, `p-6` — card padrão
- `bg-ecf-yellow`, `text-ecf-yellow`, badges/links de destaque
- `cn()` utility para composição condicional (opacity-60 quando inativa)
- Ícones `lucide-react`: Save, Power, PowerOff, AlertCircle, Info, X, Trophy, BookOpen, CheckCircle2

**Consumo de rotas:**
- `route('desempenho.configuracao.faixas.update', faixa.id)` — PATCH via `useForm().patch`
- `route('desempenho.configuracao.faixas.toggle', faixa.id)` — PATCH via `router.patch`

## Deviations from Plan

Nenhuma — plano executado conforme especificação.

## Commits gerados

| Task | Commit  | Descrição |
|------|---------|-----------|
| 1    | a257441 | feat(74-07): cria Desempenho/Configuracao.jsx — UI admin da régua de bônus |

## Verificações

- Grep de tokens obrigatórios: **OK** (`faixa.slug`, `nota_min`, `nota_max`, `desempenho.configuracao.faixas.update`, `desempenho.configuracao.faixas.toggle`, `useForm`, `Inativa`, `ativo`, `bg-ecf-card`, `border-white/[0.08]`)
- Build do frontend: **DEFERIDO** para o final da Wave 4

## Requisitos endereçados

- **DESEMP-12** (UI CRUD faixas de bônus) — fechado inteiramente pelo frontend deste plan

## Self-Check: PASSED

- FOUND: `resources/js/Pages/Desempenho/Configuracao.jsx`
- FOUND: commit a257441
