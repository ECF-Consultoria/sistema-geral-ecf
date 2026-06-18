---
phase: quick-260618-l4u
plan: "01"
subsystem: mlb-implementacao-publica
tags: [ux, precificacao, simulador, frete, alerta]
dependency_graph:
  requires: []
  provides: [alerta-frete-nao-inserido, link-tabela-frete-simulador]
  affects: [resources/js/Pages/Mlb/ImplementacaoPublica.jsx]
tech_stack:
  added: []
  patterns: [amber-alert-pattern, ecf-yellow-external-link-pattern]
key_files:
  modified:
    - resources/js/Pages/Mlb/ImplementacaoPublica.jsx
decisions:
  - "Link Tabela de Frete reaproveita URL global tabela_frete_url (prop já existente no controller) — sem seção interna nem scroll/âncora"
  - "Alerta âmbar usa freteN <= 0 como condição (variável já existente no SimuladorPreco)"
  - "tabelaFreteUrl propagado via prop chain: ImplementacaoPublica → PrecificacaoModal → SimuladorPreco"
  - "CampoValor não foi alterado; wrapper div externo absorve o link e o alerta"
metrics:
  duration: "~10 min"
  completed: "2026-06-18T18:20:32Z"
  tasks_completed: 2
  files_modified: 1
---

# Phase quick-260618-l4u Plan 01: Alerta de frete não inserido + link Tabela de Frete no Simulador de Precificação

**One-liner:** Alerta âmbar condicional e link ecf-yellow "Tabela de Frete" (target=_blank) adicionados ao campo Frete do SimuladorPreco via prop chain tabelaFreteUrl.

## O que foi entregue

Duas melhorias de UX no Simulador de Precificação do workspace público de implementação MLB:

1. **Alerta "Frete não inserido"** — quando `freteN <= 0` para o produto+tier selecionado, exibe aviso âmbar `bg-amber-500/[0.08] border border-amber-500/20` com ícone `AlertCircle` e mensagem em pt-BR logo abaixo do campo Frete. Some ao informar um frete > 0.

2. **Link "Tabela de Frete"** — ao lado do rótulo "Frete (Clássico)"/"(Premium)", renderiza `<a href={tabelaFreteUrl} target="_blank" rel="noopener noreferrer">` em estilo `text-ecf-yellow` com ícone `ExternalLink size={12}`. Não renderiza quando `tabelaFreteUrl` está vazia/ausente (sem link quebrado).

## Commits

| Task | Commit | Descrição |
|------|--------|-----------|
| Task 1 — JSX | `60d0356` | feat(quick-260618-l4u-01): alerta de frete não inserido + link Tabela de Frete no SimuladorPreco |
| Task 2 — Build | (sem commit — public/build está em .gitignore) | `npm run build` finalizado em 10.23s, 0 erros, exit code 0 |

## Decisões Tomadas

- **DIVERGÊNCIA resolvida pelo usuário antes da execução:** O briefing original supunha que existiria uma seção interna "Tabela de Frete" para scroll/âncora. A investigação do código (PLAN.md) confirmou que não existe essa seção — a Tabela de Frete é URL externa (`tabela_frete_url`). O usuário confirmou a abordagem: reutilizar a mesma URL, abrir em nova aba.
- **`CampoValor` não alterado** — sem slot para link extra no label. Solução: wrapper `<div>` externo com linha `flex items-center justify-between` para o link (quando URL presente) e bloco de alerta logo abaixo.
- **Prop chain limpa** — `tabelaFreteUrl` adicionada em 4 pontos: chamada do `<PrecificacaoModal>`, desestruturação do `PrecificacaoModal`, chamada do `<SimuladorPreco>`, desestruturação do `SimuladorPreco`. Total: 12 ocorrências no arquivo (verificação automática exigia ≥4).

## Deviações do Plano

Nenhuma — plano executado exatamente como escrito.

## Known Stubs

Nenhum stub identificado. Dados reais: `tabelaFreteUrl` vem de `MlbConfiguracao::implementacaoPadroes()['links_admin_extra']['tabela_frete']` via controller; `freteN` é calculado de dados reais do produto selecionado.

## Threat Flags

Nenhuma superfície nova de segurança introduzida. O link é read-only (`href` renderizado no cliente) e a URL vem de configuração interna admin (não de input do cliente).

## Self-Check: PASSED

- `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` existe e foi modificado
- Commit `60d0356` presente em `git log`
- `tabelaFreteUrl` x12 no arquivo (verificação automática passou: `OK tabelaFreteUrl x12 + alerta presente`)
- `npm run build` saiu com código 0, sem erros de compilação/JSX
- Nenhum outro arquivo de código modificado
