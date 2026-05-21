---
phase: 07-ui-fechamento
plan: "01"
subsystem: frontend
tags: [financeiro, ui, faturamento, faixa-progresso, total-consolidado]
dependency_graph:
  requires: [06-02]
  provides: [FCH-06, FCH-07, FCH-08]
  affects: [resources/js/Pages/Admin/Financeiro.jsx]
tech_stack:
  added: []
  patterns: [accordion-expansion, local-fmtBRL, inline-progress-bar]
key_files:
  created: []
  modified:
    - resources/js/Pages/Admin/Financeiro.jsx
decisions:
  - fmtBRL local com minimumFractionDigits:0 (nao usa formatCurrency de utils.js que inclui centavos — D-14)
  - FAIXAS_LIMITES sem entry 'maxima' (D-09: maxima e tratada por branch separado)
  - style={{ background: '#ffe600' }} na barra de progresso (nao className bg-ecf-yellow — D-11, padrao verificado)
  - TotalConsolidado inserido via space-y-6 sem wrapper extra (D-03)
metrics:
  duration: "~8 min"
  completed: "2026-05-19"
  tasks_completed: 2
  files_modified: 1
---

# Phase 7 Plan 01: Financeiro.jsx — TotalConsolidado + FaixaProgresso Summary

**One-liner:** Expandiu Financeiro.jsx com bloco de total consolidado, barra de progresso por faixa de investimento, sub-linha de faturamento na row e campo additional_service no accordion.

## Status: AWAITING_HUMAN_VERIFY

Plano `autonomous: false`. Tasks 1 e 2 concluidas com sucesso. Aguardando verificacao visual humana (Task 3 — checkpoint:human-verify).

## Tasks Executadas

| Task | Nome | Commit | Arquivos |
|------|------|--------|----------|
| 1 | Expandir Financeiro.jsx com 5 novos elementos de UI | 89e0088 | resources/js/Pages/Admin/Financeiro.jsx (+106 linhas) |
| 2 | npm run build — verificar 0 erros | 89e0088 | public/build/assets/Financeiro-BJ8xYYK4.js gerado |

## O Que Foi Adicionado

### 1. Constante FAIXAS_LIMITES (linhas 19-26)
Espelha `AdminController::FAIXAS` do backend. Seis faixas (ate_499k ate 4m_4999k), sem entry 'maxima' — maxima e tratada por branch separado no componente FaixaProgresso.

### 2. Helper fmtBRL local (linhas 28-30)
`Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 0 })` — BRL sem centavos. Guard `== null` cobre undefined tambem. Nao usa `!n` para evitar tratar zero monetario como nulo.

### 3. Componente TotalConsolidado (linhas 56-86)
- Filtra apenas empresas com `estado === 'ok'`
- Soma `faturamento` e `valor_mensal` separadamente
- Dois mini-stats lado a lado: "Faturado (mes)" em ecf-yellow + "A cobrar (mes)" em emerald-400
- Exibe "—" se nenhuma empresa tem dados (ok.length === 0)
- Inserido no export default Financeiro entre header e FechamentoList

### 4. Componente FaixaProgresso (linhas 88-126)
- Branch 'maxima': ícone TrendingUp + texto "Faixa maxima" + "acima de R$ 5.000.000"
- Branch normal: barra de progresso com pct calculado, texto "Falta R$X para a proxima faixa"
- Guard `Math.min(100, Math.max(0, pct))` impede overflow visual (T-07-02 mitigado)
- Guard `faixaData` null antes de acessar `.proximo`
- Exibido no FechamentoAccordion condicionalmente para `estado === 'ok'`

### 5. FechamentoRow expandido (linhas 151-158)
- `<span>` do nome substituido por `<div className="flex-1 min-w-0">`
- Sub-linha condicional `{empresa.estado === 'ok' && ...}` com fmtBRL + periodo_inicio + periodo_fim
- Todos os outros elementos do row preservados (ChevronDown, ServiceBadge, IntegrationBadge, datas)

### 6. FechamentoAccordion expandido (linhas 251-266)
- FaixaProgresso condicional para estado ok (acima do formulario)
- Bloco "Servico adicional" sempre visivel — valor ou "—" se null/vazio
- Divider border-t antes do ServiceForm preservado com pt-4

### 7. Subtitulo atualizado
De: "Tipo de servico e datas de contrato por empresa ativa."
Para: "Faturamento mensal, faixa de investimento e dados de contrato por empresa ativa."

## Build Result

```
vite v7.3.2 building client environment for production...
4324 modules transformed.
Financeiro-BJ8xYYK4.js  9.86 kB │ gzip: 3.09 kB
built in 9.91s
```

0 erros. 0 warnings relacionados ao arquivo editado.

## Deviations from Plan

Nenhum — plano executado exatamente como especificado.

## Known Stubs

Nenhum — todos os campos exibidos consomem props reais entregues pela Phase 6 (AdminController::fechamento()). Nenhum valor hardcoded ou placeholder.

## Threat Flags

Nenhum — nenhuma superficie nova de seguranca introduzida. Rota protegida por EnsureUserHasRole (role:admin) sem alteracao.

## Self-Check: PASSED

- [x] resources/js/Pages/Admin/Financeiro.jsx existe e contem 327 linhas
- [x] Commit 89e0088 existe no git log
- [x] Build concluido com "built in 9.91s" sem erros
- [x] Marcadores verificados: FAIXAS_LIMITES, fmtBRL, TotalConsolidado, FaixaProgresso, faixa === 'maxima', bg-ecf-yellow/30, additional_service, minimumFractionDigits: 0

## Awaiting

**Task 3 — Verificacao visual humana.**

Acesse `http://localhost/ecf_admin/public/administrativo/financeiro` com usuario admin logado e verifique:

1. Sidebar exibe "Fechamento" (nao "Financeiro")
2. Bloco total consolidado no topo — dois cards lado a lado (Faturado + A cobrar), valores sem centavos
3. Empresa ok: sub-linha com faturamento e periodo (ex: "R$ 1.000.000 · 01/05 a 18/05")
4. Empresa sem_dados/sem_integracao: apenas nome, sem sub-linha
5. Accordion de empresa ok (faixa != maxima): barra de progresso + "Falta R$X para proxima faixa"
6. Accordion de empresa faixa maxima: ícone TrendingUp + "Faixa maxima" (sem barra)
7. Campo "Servico adicional" no accordion (valor ou "—"), acima do formulario
8. ServiceForm ainda funciona (salvar tipo de servico + datas de contrato)
9. Sem erros de console JavaScript

Responda "aprovado" se todos os 9 itens estiverem corretos.
