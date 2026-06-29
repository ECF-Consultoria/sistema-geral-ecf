---
phase: 48-redesign-da-carteira-individual-analista-estrategista
plan: "03"
status: complete
executed_at: 2026-06-29
commits:
  - sha: deaa8b8
    desc: "feat(48-03): cria SparklineCrescimento.jsx — badge colorido por threshold ±2% MoM"
  - sha: 8174767
    desc: "feat(48-03): tabela empresas — reintroduz Status e Ação + adiciona coluna Crescimento"
  - sha: 4455bd9
    desc: "feat(48-03): responsividade mobile — tabela vira cards empilhados em < md"
---

# SUMMARY — Plan 48-03: Tabela Empresas + Sparkline Crescimento

## Status: COMPLETO

## O que foi feito

### Tarefa 1 — Componente SparklineCrescimento.jsx

- Criado `resources/js/Components/Carteira/SparklineCrescimento.jsx`.
- Diretório `resources/js/Components/Carteira/` criado (não existia).
- Lógica de cor por threshold ±2%:
  - `queda_mom_pct > +2%` → verde emerald-400 (`#34d399`)
  - `queda_mom_pct < -2%` → vermelho red-400 (`#f87171`)
  - entre ±2% ou null → cinza (`rgba(255,255,255,0.4)`)
- Quando `data` array >= 2 pontos fornecido: renderiza mini `LineChart` Recharts (60×28px, sem eixos, sem grid, sem tooltip).
- Sem `data`: badge textual com seta + percentual formatado com 1 decimal e sinal explícito.
- Null: exibe `—` em cinza.
- Export default; importa `LineChart`, `Line`, `ResponsiveContainer` do Recharts (já no projeto — sem nova dependência).

### Tarefa 2 — Colunas Status, Ação e Crescimento na tabela desktop

- Importado `SparklineCrescimento` no topo de `Show.jsx`.
- Tabela passou de 5 para 8 colunas: **Empresa | Status | Faturamento | Meta | Margem | Ads | Ação | Crescimento**
- **Status** (`c.status`): badge colorido usando `STATUS_LABEL` e `STATUS_CLS` (já definidos nas linhas 30-40 do arquivo). Exibe `—` se null.
- **Ação recomendada** (`c.acao_recomendada`): texto truncado em `max-w-[120px]` com `title` para leitura completa. Exibe `—` se null.
- **Crescimento**: `<SparklineCrescimento queda_mom_pct={c.queda_mom_pct} />` — dado já presente no payload do controller.
- `colSpan` do empty state atualizado de 5 para 8.
- Comentários inline em pt-BR explicando que Status e Ação foram removidos no hotfix 2026-06-19 e agora reintroduzidos (dado sempre existiu no payload).

### Tarefa 3 — Responsividade mobile

- Container da tabela desktop alterado para `hidden md:block overflow-x-auto -mx-1`.
- Adicionado bloco `md:hidden` com cards empilhados (`space-y-2`) antes da tabela.
- Cada card mobile contém:
  - **Linha 1**: link nome (truncado) + badge Status + SparklineCrescimento + badges grant (vencido / vencendo)
  - **Linha 2**: faturamento em destaque + meta%
  - **Linha 3**: margem% + ads compacto
  - **Linha 4**: ação recomendada (texto completo, sem truncamento)
- Nenhum overflow horizontal em mobile: sem `overflow-x-auto` no bloco de cards.
- Reutiliza o mesmo `empresasView` do estado — sem duplicação de lógica.

### Tarefa 4 — Build

- `npm run build` concluído sem erros: `✓ built in 12.60s`.
- `Show-B3sU9hmN.js` gerado: 31.64 kB / 8.78 kB gzip (crescimento esperado vs 27.20 kB pós 48-02).

## Verificações de Success Criteria

- [x] `SparklineCrescimento.jsx` criado em `resources/js/Components/Carteira/`
- [x] Componente usa threshold ±2% para verde/vermelho/cinza
- [x] Tabela tem 8 colunas: Empresa | Status | Faturamento | Meta | Margem | Ads | Ação | Crescimento
- [x] Status e Ação reintroduzidos (dados já existiam no payload `c.status` e `c.acao_recomendada`)
- [x] Indicador de crescimento usa `queda_mom_pct` (já no payload do controller)
- [x] Mobile: tabela vira cards (`hidden md:block` / `md:hidden`)
- [x] `colSpan` do empty state atualizado para 8
- [x] `npm run build` sem erros
- [x] 3 commits isolados em pt-BR (Tarefa 1, 2, 3; build sem commit separado — só verificação)
- [x] Não tocou em hero card (Plan 48-02)
- [x] Não tocou em KPIs / bloco diferenciado analista/estrategista (Plan 48-02)
- [x] Não tocou em modais de meta ou painel lateral Metas (Plan 48-02)
- [x] Não tocou em NPS history (escopo Plan 48-04)
- [x] Comentários em pt-BR

## Desvios do Plan

| Desvio | Justificativa |
|--------|---------------|
| `max-w` do link nome reduzido de `max-w-[200px]` para `max-w-[160px]` no desktop | Tabela ganhou 3 colunas extras; coluna Empresa precisou encolher levemente para caber. Em `md:max-w-none` (desktop largo) o truncamento não se aplica. |
| Tarefa 4 (build) sem commit separado | Plan indicava "rodar build e verificar" — build é verificação, não entrega de artefato. Mantido como checagem final sem commit extra (seria commit vazio). |
