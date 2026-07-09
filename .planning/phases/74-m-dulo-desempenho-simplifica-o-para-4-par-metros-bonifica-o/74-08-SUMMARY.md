---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 08
subsystem: Manual do Sistema (artigo dinâmico da Régua de Bonificação)
tags: [frontend, react, jsx, backend, controller, manual, artigo]
requires:
  - .planning/phases/74-.../74-02-SUMMARY.md (BonusFaixa Model + seed)
provides:
  - Rota /manual/desempenho-bonificacao sincronizada em tempo real com bonus_faixas
  - Padrão para artigos dinâmicos no Manual (spread de artigoProps)
affects:
  - app/Http/Controllers/ManualController.php
  - resources/js/Pages/Manual/Show.jsx
  - resources/js/Pages/Manual/artigos.js
  - resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx (novo)
tech-stack:
  patterns:
    - Backend assembly de props por slug (sem fetch client-side)
    - Spread de props no wrapper Show.jsx (backwards-compat com Cronograma)
    - Sem cache (Cache::remember ausente) — D-25 garante refresh imediato pós-edit
key-files:
  created:
    - resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx
  modified:
    - app/Http/Controllers/ManualController.php
    - resources/js/Pages/Manual/Show.jsx
    - resources/js/Pages/Manual/artigos.js
decisions:
  - D-22 · Componente DesempenhoBonificacao.jsx segue padrão do Cronograma.jsx
  - D-23 · Entry 'desempenho-bonificacao' registrada em artigos.js
  - D-24 · ManualController::show() faz lookup condicional por slug e passa artigoProps
  - D-25 · Render dinâmico sem cache — BonusFaixa::where('ativo',true)->orderBy('ordem')->get()
metrics:
  duration_min: 25
  completed: 2026-07-09
  tasks_completed: 3-of-4-jsx+php (build compartilhado ao final da Wave 4)
requirements:
  - DESEMP-13 (artigo dinâmico com tabela em sync)
---

# Phase 74 Plan 08: Artigo Dinâmico `/manual/desempenho-bonificacao`

**One-liner:** Rota `/manual/desempenho-bonificacao` renderiza artigo do Manual explicando os 4 parâmetros da nota final + tabela DINÂMICA de faixas de bônus vinda direto do banco (`bonus_faixas` ativas), sem cache — admin edita em `/desempenho/configuracao` e o artigo reflete no próximo page load.

## Objetivo

Criar o artigo dinâmico do Manual sincronizado em tempo real com a config `bonus_faixas`. Fechar REQ DESEMP-13 (D-22, D-23, D-24, D-25).

## Arquivos entregues

### `app/Http/Controllers/ManualController.php` (modificado)
- Import `use App\Models\BonusFaixa;`
- `show($slug)` faz lookup condicional por slug:
  - Se `$slug === 'desempenho-bonificacao'`, monta `$artigoProps = ['bonus_faixas' => ..., 'metodologia_texto' => '...']`
  - Query com colunas explícitas (`select id,slug,nome,descricao,nota_min,nota_max,ordem,ativo`)
  - Texto explanatório pt-BR hardcoded no controller (mais fácil de traduzir/editar do que armazenar no DB)
- `Inertia::render` passa `$slug` + `$artigoProps` para o wrapper Show.jsx
- Docblock class-level atualizado com referência à Phase 74 D-24/DESEMP-13

### `resources/js/Pages/Manual/Show.jsx` (modificado)
- Assinatura: `Show({ slug, artigoProps = {} })` — default `{}` mantém backwards-compat com artigos legados (Cronograma) que não declaram props
- `<Component {...artigoProps} />` — spread propaga dados do backend para o componente do artigo
- Preservada a branch "Artigo não encontrado"

### `resources/js/Pages/Manual/artigos.js` (modificado)
- Import `DesempenhoBonificacao` de `./Artigos/DesempenhoBonificacao`
- Entry `'desempenho-bonificacao'` adicionada ao objeto `ARTIGOS`:
  - `slug: 'desempenho-bonificacao'`
  - `titulo: 'Régua de Bonificação — Desempenho'`
  - `categoria: 'Módulo Desempenho'`
  - `descricao: 'Como calculamos a nota final e a faixa de bônus mensal do time Performance.'`
  - `Component: DesempenhoBonificacao`

### `resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx` (novo · 175 linhas)
- Assinatura: `DesempenhoBonificacao({ bonus_faixas = [], metodologia_texto = '' })`
- **Seções:**
  - Cabeçalho (Trophy ícone + título + parágrafo `metodologia_texto`)
  - "Os 4 parâmetros da nota final" — grid 2×2 de sub-blocos explicativos (NPS/Faturamento/Margem/Absenteísmo). Absenteísmo recebe badge "Em breve".
  - "Fórmula da nota final" — box amarelo com `<code>nota_final = média(NPS, Var. Faturamento, Var. Margem)</code>`
  - "Faixas de bonificação" — tabela DINÂMICA a partir de `bonus_faixas.map`:
    - Colunas: Faixa (nome + slug) · Nota mín. · Nota máx. · Descrição
    - Placeholder cinza "Nenhuma faixa ativa configurada" quando lista vazia
  - "Regra especial · promoção por 2 meses consecutivos" — bloco verde/emerald sinalizando a regra DESEMP-08
  - Rodapé apontando para `/desempenho/configuracao` como fonte da edição
- Design consistente com Cronograma.jsx (dark/glass tokens `bg-ecf-card`, `border-white/[0.08]`, `ecf-yellow`)
- Sub-componente interno `ParametroItem` reutilizado nos 4 blocos

## Deviations from Plan

Nenhuma — plano executado conforme especificação. O componente `DesempenhoBonificacao.jsx` teve estrutura visual ampliada (ícones, badges, boxes coloridos) em relação ao mínimo especificado, mas mantendo 100% do contrato de dados e das seções obrigatórias.

## Commits gerados

| Task | Commit  | Descrição |
|------|---------|-----------|
| 1-3  | 1d42f92 | feat(74-08): artigo dinâmico /manual/desempenho-bonificacao com faixas em sync com bonus_faixas |

Commit único agrupou os 4 arquivos (backend + 3 frontend) — mudança coesa que só faz sentido junta.

## Verificações

- Grep de tokens obrigatórios (DesempenhoBonificacao.jsx): **OK** (`bonus_faixas`, `metodologia_texto`, `2 meses consecutivos`, `nota_final`, `Absenteísmo`)
- Grep de entry (artigos.js): **OK** (`desempenho-bonificacao` + `DesempenhoBonificacao`)
- Grep spread (Show.jsx): **OK** (`artigoProps`)
- `php -l ManualController.php`: **No syntax errors**
- `npm run build`: **✓ built in 55.79s** — manifest inclui `resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx` e todos os demais arquivos da Wave 4

## Requisitos endereçados

- **DESEMP-13** (artigo dinâmico) — fechado. Rota `/manual/desempenho-bonificacao` funciona, renderiza tabela em sync com `bonus_faixas`, sem cache.

## Fluxo esperado

```
Admin abre /desempenho/configuracao
  → edita intermediario.nota_min de 4.50 para 4.60
  → clica Salvar → toast de sucesso, DB atualizado
Admin abre /manual/desempenho-bonificacao (nova aba)
  → controller executa BonusFaixa::where('ativo',true)->orderBy('ordem')->get()
  → tabela mostra 4.60 na linha "Intermediário"
  → SEM cache, SEM deploy, SEM fetch client-side
```

## Self-Check: PASSED

- FOUND: `app/Http/Controllers/ManualController.php` (mod)
- FOUND: `resources/js/Pages/Manual/Show.jsx` (mod)
- FOUND: `resources/js/Pages/Manual/artigos.js` (mod)
- FOUND: `resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx` (novo)
- FOUND: commit 1d42f92 (via `git log`)
- FOUND: manifest inclui `resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx`
