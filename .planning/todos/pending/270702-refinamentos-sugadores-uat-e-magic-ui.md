---
id: 270702-refinamentos-sugadores-uat-e-magic-ui
created: 2026-07-02
title: Refinamentos /sugadores — pós-UAT Phase 52 + modernização visual
area: sugadores
resolves_phase: 67
status: pending
---

# Refinamentos /sugadores — pós-UAT Phase 52 + modernização visual

**Capturado:** 2026-07-02
**Fonte:** briefing UAT do operador com screenshot (referência: tabela shadcn/ui estilo Magic UI)
**Contexto:** Phase 52 deployada. Operador testou e listou 7 itens de refinamento — mistura de correção pós-UAT com features novas e upgrade visual.

---

## Bloco A — Correções pós-UAT (rápidas, ajustam decisões que ficaram fora do ponto)

### A1 — Layout ConfigResumoCard mal posicionado em `/sugadores/empresa/{id}`
Hoje o card está em cima da lista. Operador quer **ao lado** (layout 2 colunas: lista principal + painel lateral com config). Referencia do print: layout tipo dashboard com sidebar.

### A2 — View individual `/sugadores/{id}` NÃO deve ter ConfigResumoCard nem botão "Rodar análise"
Wave 3 adicionou os 2 no Show.jsx individual (interpretei A3 e A8 do briefing original como aplicando também na view individual). Operador esclarece: só a listagem por empresa faz sentido ter esses widgets. View individual é sobre UM sugador específico — nem config nem análise cabem lá.

### A3 — Cards de empresas: substituir botão "Configurar" por filtros
Aba `/sugadores` (Index.jsx) hoje tem botão "Configurar" no header (leva ao picker de empresa → config). Operador quer no lugar:
- **Busca de empresa** (input livre)
- **Filtro por analista** (só admin — mostrar empresas de determinado analista)

O botão "Configurar" continua existindo mas via **outro caminho** (dentro do drilldown por empresa, via ConfigResumoCard).

---

## Bloco B — Features novas

### B1 — Filtro de período em `/sugadores/empresa/{id}`
Coluna "Detectado em" filtrada por data. Default = **hoje**. Presets: hoje / últimos 7d / últimos 30d / customizado.

### B2 — Click na linha do sugador na listagem → detalhes
Hoje só o botão "Ver detalhes" navega. Operador quer que **linha inteira** seja clicável (padrão de row hover + cursor pointer). Checkbox + ações continuam clicáveis sem propagar.

---

## Bloco C — Modernização visual (referência: Magic UI + shadcn/ui table do print)

### C1 — Redesign da tabela de sugadores estilo referência do print
Elementos identificados no print:
- Header cinza claro (tabela shadcn/ui table)
- Checkboxes minimalistas quadrados (não redondos)
- Drag handles à esquerda de cada linha (⋮⋮)
- Colunas: Header, Section Type, Status (badge pill), Target, Limit, Reviewer (avatar/dropdown), ações (⋯)
- Row hover subtle
- Tabs superiores no cabeçalho (Outline · Past Performance 3 · Key Personnel 2 · Focus Documents)
- Botões "Customize Columns" e "+ Add Section" no topo direito
- Tipografia limpa, tokens dark theme mantidos

### C2 — Adotar Magic UI onde fizer sentido
[Magic UI](https://magicui.design) é uma lib de componentes React. Operador estava explorando. Componentes potencialmente úteis:
- Marquee (rolagem infinita — não parece útil aqui)
- Animated List (para chegada de novos sugadores?)
- Number Ticker (contadores animados no header)
- Shimmer Button / Rainbow Button (CTAs)
- Confetti (feedback de análise concluída?)
- Blur Fade (transições de página)
- Bento Grid (layout dashboard?)

Não usar tudo — só o que agrega. Manter tokens `ecf-*` e dark theme como base. Magic UI complementa, não substitui shadcn/ui existente.

---

## Roteamento sugerido

Sugestão de 2 phases separadas para não misturar bug fix rápido com evolução visual:

| Bloco | Phase | Escopo | Estimativa |
|---|---|---|---|
| A + B | **Phase 54** (Refinamentos /sugadores UAT + filtros) | A1-A3 + B1-B2 | Rápida (~2h de execução) |
| C | **Phase 55** (Modernização visual /sugadores com Magic UI) | C1 + C2 | Média (~4-6h; research Magic UI + refactor tabelas) |

Alternativa: uma única phase que faz tudo. Mais lenta mas 1 deploy + 1 UAT.

---

## Sobre a Phase 52 (contexto)

Phase 52 entregou os 9 itens do briefing original (A1-A9). UAT identificou 7 itens de refinamento (este briefing). Fica a critério do operador:
- Fechar Phase 52 como "UAT parcial aprovado" e migrar refinamentos para Phase 54
- Deixar Phase 52 aberta até Phase 54 rodar (fluxo mais linear)
