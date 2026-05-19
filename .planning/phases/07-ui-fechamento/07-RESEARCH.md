# Phase 7: UI Fechamento — Research

**Pesquisado:** 2026-05-19
**Domínio:** React / JSX — expansão de Financeiro.jsx com consumo de props Phase 6
**Confiança:** HIGH (todos os padrões verificados diretamente no codebase)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Props por empresa incluem: `estado`, `faturamento`, `periodo_inicio`, `periodo_fim`, `faixa`, `valor_mensal`, `additional_service` (além dos campos Phase 5)
- **D-02:** Total consolidado = soma de `faturamento` de todas as empresas com `estado === 'ok'`
- **D-03:** Total consolidado exibido no topo da página, acima da lista; exibir também o total de `valor_mensal` das empresas `ok`
- **D-04:** Se nenhuma empresa tem `ok`, exibir "—" nos totais
- **D-05:** Período coberto visível na linha da empresa (FechamentoRow), sem precisar expandir; apenas para `estado === 'ok'`
- **D-06:** Formato: `empresa.periodo_inicio + ' a ' + empresa.periodo_fim` (ex: "01/05 a 18/05")
- **D-07:** Barra de progresso exibida no accordion expandido (FechamentoAccordion), acima do ServiceForm
- **D-08:** Calculada em JS a partir de constante `FAIXAS_LIMITES` espelhando `AdminController::FAIXAS`
- **D-09:** Faixa `maxima`: exibir bloco "Faixa máxima" com texto e ícone, SEM barra
- **D-10:** Outras faixas: barra com `pct = (faturamento - min) / (proximo - min) * 100` + texto "Falta R$ X para a próxima faixa"
- **D-11:** Barra usa `ecf-yellow/30` de fundo e `ecf-yellow` de preenchimento
- **D-12:** `additional_service` exibido no accordion, abaixo da barra e acima do ServiceForm; somente leitura
- **D-13:** Se `additional_service` é null ou vazio: exibir "—"; se preenchido: exibir o valor
- **D-14:** Formatação BRL sem centavos: `Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0 })`
- **D-15:** Phase 7 tem `autonomous: false`
- **D-16:** `Financeiro.jsx` expandido a partir da versão Phase 5; sub-componentes existentes preservados
- **D-17:** Novos sub-componentes: `TotalConsolidado({ empresas })`, `FaixaProgresso({ faturamento, faixa })`

### Claude's Discretion

- Layout exato do stat block do total consolidado (horizontal vs. vertical)
- Ícone para "Faixa máxima" (TrendingUp, Award, etc.)
- Exatamente onde no FechamentoRow exibir faturamento + período (direita da linha? abaixo do nome?)
- Número de casas decimais nos valores monetários

### Deferred Ideas (OUT OF SCOPE)

- Edição do campo `additional_service` via UI (somente leitura nesta fase)
- Histórico mensal de fechamentos (v2.1+)
- Agrupamento por tipo de serviço
- Exportação CSV
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da Pesquisa |
|----|-----------|---------------------|
| FCH-06 | Admin vê barra de progresso com posição na faixa atual e quanto falta para a próxima; faixa máxima exibe "Faixa máxima" sem barra | Padrão div-based verificado em Mlb/Empresas.jsx, Mlb/Dashboard.jsx, Performance/Index.jsx |
| FCH-07 | Admin vê total consolidado (soma das empresas `ok`); período coberto visível por empresa | Padrão KpiCard verificado em Dashboard/Admin.jsx; padrão stat-mini em Admin.jsx linhas 338–344 |
| FCH-08 | Admin vê campo de serviço adicional por empresa (visível ou "—") | Campo já presente nos props Phase 6 — apenas display no accordion |
| CFG-01 | Label "Financeiro" renomeado para "Fechamento" no sidebar e na página | Já implementado em Phase 5; sidebar AppLayout.jsx linha 51 já usa "Fechamento" |
</phase_requirements>

---

## Summary

Phase 7 é uma expansão pura de `resources/js/Pages/Admin/Financeiro.jsx` — nenhuma nova rota, nenhum novo pacote, nenhuma mudança de backend. O arquivo recebe três blocos novos de UI: (1) um stat block de totais consolidados no topo, (2) faturamento + período visíveis diretamente na linha da empresa (FechamentoRow), e (3) um bloco de barra de progresso por faixa dentro do accordion expandido (FechamentoAccordion).

Todos os padrões necessários já existem no codebase. O padrão de stat block canônico é o `KpiCard` de `Dashboard/Admin.jsx` — ou sua versão compacta (mini-stat) nos blocos "Adman · Performance de Vendas" da mesma página. O padrão de barra de progresso div-based é usado em ao menos sete arquivos (`Mlb/Empresas.jsx`, `Mlb/Dashboard.jsx`, `Performance/Index.jsx`, `Mlb/ImplementacaoIndicadores.jsx`, etc.). A formatação BRL sem centavos é definida localmente em `Sugadores/Index.jsx` como `fmtBRL` e difere do `formatCurrency` de `@/lib/utils` (que inclui centavos).

**Recomendação principal:** Expandir `Financeiro.jsx` em um único plano (1-plan), pois todas as mudanças são no mesmo arquivo e os padrões são diretos. Os novos sub-componentes `TotalConsolidado` e `FaixaProgresso` podem ser definidos no topo do arquivo, antes da lista existente.

---

## Architectural Responsibility Map

| Capacidade | Tier Principal | Tier Secundário | Justificativa |
|------------|---------------|-----------------|---------------|
| Total consolidado (soma faturamentos) | Frontend (cálculo JS) | — | Dados já chegam prontos via props; somar no JS é trivial e dispensa round-trip ao backend |
| Barra de progresso por faixa | Frontend (cálculo JS) | — | `FAIXAS_LIMITES` espelha `AdminController::FAIXAS`; cálculo `pct` é aritmética simples |
| Exibição de período coberto | Frontend (display) | — | Strings `periodo_inicio`/`periodo_fim` já formatadas pelo backend como `d/m` |
| Exibição de `additional_service` | Frontend (display) | — | Campo somente leitura; sem lógica de negócio nesta fase |
| Formatação monetária | Frontend (helper local) | — | `fmtBRL` local com `minimumFractionDigits: 0` — diferente do `formatCurrency` de utils |

---

## Standard Stack

Esta fase não instala nenhum pacote novo. Todo o stack já está presente.

### Core (já instalado)
| Biblioteca | Versão | Propósito | Fonte |
|-----------|--------|-----------|-------|
| React | ^18.2.0 | UI components | `package.json` [VERIFIED: codebase] |
| `@inertiajs/react` | ^2.0 | `useForm`, `usePage` hooks | `package.json` [VERIFIED: codebase] |
| lucide-react | ^1.11.0 | Ícones | `package.json` [VERIFIED: codebase] |
| Tailwind CSS | ^3.2.1 | Utilitários de estilo | `tailwind.config.js` [VERIFIED: codebase] |

### Pacotes não instalados nesta fase
Nenhum. Phase 7 é 100% expansão de arquivo JSX existente.

## Package Legitimacy Audit

> Nenhum pacote novo instalado nesta fase. Auditoria não aplicável.

---

## Architecture Patterns

### Diagrama — fluxo de dados em Financeiro.jsx (Phase 7)

```
AdminController::fechamento()
  └─ Inertia::render('Admin/Financeiro', compact('companies'))
        └─ Financeiro({ companies })
              ├─ TotalConsolidado({ empresas: companies })   ← NOVO (topo da página)
              │     Calcula: empresas.filter(e => e.estado === 'ok')
              │     Exibe: soma faturamento + soma valor_mensal
              │
              └─ FechamentoList({ empresas: companies })
                    └─ FechamentoRow (expandida=false)         ← EXPANDIDO
                    │     Exibe: nome | ServiceBadge | IntegrationBadge |
                    │            [periodo + faturamento se ok] | datas contrato
                    │
                    └─ FechamentoRow (expandida=true)
                          └─ FechamentoAccordion               ← EXPANDIDO
                                ├─ FaixaProgresso({ faturamento, faixa })  ← NOVO
                                │     Se faixa === 'maxima': bloco "Faixa máxima"
                                │     Se outra faixa: barra de progresso + "Falta R$ X"
                                ├─ Serviço adicional (display)             ← NOVO
                                └─ ServiceForm (existente, sem mudança)
```

### Estrutura do arquivo (Phase 7)

```
Admin/Financeiro.jsx
├── imports
├── SERVICE_LABELS / SERVICE_COLORS (existente)
├── FAIXAS_LIMITES (NOVO — constante JS espelhando AdminController::FAIXAS)
├── fmtBRL (NOVO — helper local BRL sem centavos)
├── ServiceBadge (existente)
├── IntegrationBadge (existente)
├── TotalConsolidado (NOVO — stat block topo)
├── FaixaProgresso (NOVO — barra ou badge faixa máxima)
├── FechamentoRow (EXPANDIDO — add faturamento + período)
├── ServiceForm (existente — sem mudança)
├── FechamentoAccordion (EXPANDIDO — add FaixaProgresso + serviço adicional)
├── FechamentoList (sem mudança)
└── export default Financeiro (sem mudança na assinatura)
```

---

## 1. Stat Block Pattern — classes exatas do codebase

### Padrão KpiCard completo (Dashboard/Admin.jsx linhas 38–63) [VERIFIED: codebase]

```jsx
// Fonte: resources/js/Pages/Dashboard/Admin.jsx linhas 38–63
function KpiCard({ title, value, sub, icon: Icon, color = 'yellow', empty = false }) {
    const colors = {
        yellow: { text: 'text-ecf-yellow', bg: 'bg-ecf-yellow/10', border: 'border-ecf-yellow/20', dot: 'bg-ecf-yellow' },
        // ...
    };
    const c = colors[color];
    return (
        <div className="card-ecf rounded-2xl p-5 flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <p className="text-white/50 text-[12px] font-semibold tracking-wide uppercase">{title}</p>
                <div className={cn('w-8 h-8 rounded-xl flex items-center justify-center', c.bg, 'border', c.border)}>
                    <Icon size={15} className={c.text} />
                </div>
            </div>
            <div>
                <p className={cn('font-display font-extrabold text-3xl tracking-tight', empty ? 'text-white/20' : c.text)}>
                    {empty ? '—' : value}
                </p>
                {sub && <p className="text-white/30 text-xs mt-1">{sub}</p>}
            </div>
        </div>
    );
}
```

`card-ecf` é uma classe CSS definida em `resources/css/app.css` linhas 125–132:
```css
.card-ecf {
    border: 1px solid rgba(255,255,255,.07);
    background: #0f1116;
    transition: border-color .2s ease;
}
.card-ecf:hover {
    border-color: rgba(255,230,0,.18);
}
```

### Padrão mini-stat (células internas — Dashboard/Admin.jsx linhas 337–345) [VERIFIED: codebase]

```jsx
// Fonte: resources/js/Pages/Dashboard/Admin.jsx linhas 337–344
<div className="rounded-xl bg-white/[0.03] border border-white/[0.06] p-3">
    <p className="text-white/40 text-[11px] mb-1">{label}</p>
    <p className={`font-display font-bold text-xl ${color}`}>{value}</p>
</div>
```

### Recomendação para TotalConsolidado

Usar **dois mini-stats lado a lado** dentro de um container `card-ecf rounded-xl p-4`, com grid `grid grid-cols-2 gap-3`:

```jsx
// Padrão recomendado para TotalConsolidado (baseado em Admin.jsx + contexto Financeiro.jsx)
function TotalConsolidado({ empresas }) {
    const ok = empresas.filter(e => e.estado === 'ok');
    const totalFat    = ok.reduce((s, e) => s + Number(e.faturamento ?? 0), 0);
    const totalMensal = ok.reduce((s, e) => s + Number(e.valor_mensal ?? 0), 0);
    const temDados    = ok.length > 0;

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
            <div className="flex items-center gap-2 mb-3">
                <Banknote size={15} className="text-ecf-yellow/60" />
                <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase">
                    Total consolidado · {ok.length} empresa{ok.length !== 1 ? 's' : ''} com dados
                </p>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl bg-white/[0.03] border border-white/[0.06] p-3">
                    <p className="text-white/40 text-[11px] mb-1">Faturado (mês)</p>
                    <p className="font-display font-bold text-xl text-ecf-yellow">
                        {temDados ? fmtBRL(totalFat) : '—'}
                    </p>
                </div>
                <div className="rounded-xl bg-white/[0.03] border border-white/[0.06] p-3">
                    <p className="text-white/40 text-[11px] mb-1">A cobrar (mês)</p>
                    <p className="font-display font-bold text-xl text-emerald-400">
                        {temDados ? fmtBRL(totalMensal) : '—'}
                    </p>
                </div>
            </div>
        </div>
    );
}
```

**Justificativa de layout horizontal (2 colunas):** Consistente com o bloco "Adman · Performance" em Dashboard/Admin.jsx (grid 4 colunas, padrão mini-stat). O `max-w-4xl` do container pai já limita a largura — duas colunas é equilibrado sem desperdiçar espaço.

---

## 2. Progress Bar Pattern — abordagem recomendada

### Padrões existentes no codebase (div-based, sem biblioteca)

**Padrão mais simples — Mlb/Empresas.jsx linhas 21–32** [VERIFIED: codebase]
```jsx
// Fonte: resources/js/Pages/Mlb/Empresas.jsx linhas 21–32
<div className="flex-1 h-1.5 bg-white/10 rounded-full overflow-hidden">
    <div style={{ width: `${pct}%`, background: color }} className="h-full rounded-full transition-all" />
</div>
```

**Padrão com `overflow-hidden` no pai — Mlb/Dashboard.jsx linha 74** [VERIFIED: codebase]
```jsx
// Fonte: resources/js/Pages/Mlb/Dashboard.jsx linha 74
<div className="h-1 bg-white/10 rounded-full overflow-hidden">
    <div className="h-full rounded-full" style={{ width: `${item.pct}%`, background: cor.hex }} />
</div>
```

**Padrão alternativo com Tailwind classes no filho — Mlb/Dashboard.jsx linha 183** [VERIFIED: codebase]
```jsx
<div className="h-1.5 bg-white/10 rounded-full overflow-hidden">
    <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: '#ffe600' }} />
</div>
```

### Implementação recomendada para FaixaProgresso

D-11 especifica `ecf-yellow/30` de fundo e `ecf-yellow` de preenchimento. Como `ecf-yellow` é uma cor customizada (`#ffe600`), usar `style={{ background: '#ffe600' }}` no filho e classe Tailwind `bg-ecf-yellow/30` no pai:

```jsx
// Fonte: padrão codebase (Mlb/Empresas.jsx, Mlb/Dashboard.jsx) + tokens D-11
function FaixaProgresso({ faturamento, faixa }) {
    if (faixa === 'maxima') {
        return (
            <div className="flex items-center gap-2 py-3">
                <TrendingUp size={14} className="text-ecf-yellow shrink-0" />
                <p className="text-ecf-yellow text-[13px] font-semibold">Faixa máxima</p>
                <span className="text-white/30 text-[12px]">acima de R$ 5.000.000</span>
            </div>
        );
    }

    if (!faixa || faturamento == null) return null;

    const faixaData = FAIXAS_LIMITES[faixa];
    if (!faixaData) return null;

    const pct = Math.min(100, Math.max(0,
        ((Number(faturamento) - faixaData.min) / (faixaData.proximo - faixaData.min)) * 100
    ));
    const falta = Math.max(0, faixaData.proximo - Number(faturamento));

    return (
        <div className="py-3">
            <div className="flex items-center justify-between mb-1.5">
                <p className="text-white/50 text-[11px] uppercase tracking-wider">Posição na faixa</p>
                <p className="text-white/50 text-[11px]">{Math.round(pct)}%</p>
            </div>
            <div className="h-1.5 bg-ecf-yellow/30 rounded-full overflow-hidden">
                <div
                    className="h-full rounded-full transition-all"
                    style={{ width: `${pct}%`, background: '#ffe600' }}
                />
            </div>
            <p className="text-white/40 text-[12px] mt-1.5">
                Falta {fmtBRL(falta)} para a próxima faixa
            </p>
        </div>
    );
}
```

**Por que div-based e não recharts:** O Progress de recharts não é para barras de progresso lineares simples. O padrão div-based é usado em 7+ lugares no codebase, é consistente e não requer import extra.

---

## 3. Currency Formatting — decisão

### Situação atual

`utils.js` exporta `formatCurrency` (linhas 8–10) [VERIFIED: codebase]:
```js
export function formatCurrency(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}
```
Isto produz "R$ 1.000.000,00" — **com** dois decimais. Não atende D-14 (sem centavos).

`Sugadores/Index.jsx` linha 45 define uma `fmtBRL` local [VERIFIED: codebase]:
```js
const fmtBRL = (n) => 'R$ ' + Number(n ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
```
Esta também usa 2 decimais e tem um formato levemente diferente (concatenação manual vs. `style: 'currency'`).

### Decisão recomendada (opção b do contexto): definir `fmtBRL` local em Financeiro.jsx

```js
// Helper local — BRL sem centavos, retorna "—" para null
const fmtBRL = (n) => n == null ? '—'
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL',
        minimumFractionDigits: 0, maximumFractionDigits: 0 });
```

**Justificativa:**
- D-14 do CONTEXT.md especifica exatamente este padrão com `minimumFractionDigits: 0`
- Definir localmente mantém `utils.js` estável (outras páginas dependem do comportamento com 2 decimais)
- O padrão de helper local é estabelecido em `Sugadores/Index.jsx` e `Mlb/Vendas.jsx`
- `fmtBRL(null)` retorna "—" — essencial para empresas sem faturamento (D-04/D-13)

**Não adicionar a `utils.js`:** Mudança de assinatura quebraria todos os usos de `formatCurrency` em Dashboard/Admin.jsx, Dashboard/User.jsx, Portfolio/Show.jsx, etc. que dependem dos decimais para valores fracionados.

---

## 4. Row Layout Pattern — como estender FechamentoRow

### Estado atual de FechamentoRow (Phase 5) [VERIFIED: codebase — Financeiro.jsx linhas 43–72]

```
flex items-center gap-4 px-4 py-3
│
├── ChevronDown (14px, shrink-0)
├── span name (flex-1, truncate)
├── ServiceBadge (shrink-0)
├── IntegrationBadge (shrink-0, condicional)
└── span datas (text-white/40 text-[13px] font-mono shrink-0)
```

### Padrão de múltiplas colunas condicionais no codebase

**Dashboard/Admin.jsx linhas 429–446 — companies_performance** [VERIFIED: codebase]:
```jsx
<div className="flex items-center justify-between py-2.5 border-b border-white/[0.04] last:border-0">
    <div>
        <p className="text-white/80 text-[13px] font-semibold">{c.name}</p>
        <p className="text-white/30 text-xs mt-0.5">{c.consultor ?? '—'} / {c.mentor ?? '—'}</p>
    </div>
    <div className="text-right space-y-0.5">
        <p className="text-[11px] text-white/30">
            TACOS <span className="text-ecf-yellow font-bold">{c.tacos ? formatPercent(c.tacos) : '—'}</span>
        </p>
        <p className="text-[11px] text-white/30">
            Fat <span className="text-blue-400 font-bold">{c.revenue ? formatCurrency(c.revenue) : '—'}</span>
        </p>
    </div>
</div>
```

Este padrão — nome + sub-info em `div` aninhada — é o modelo para exibir faturamento + período abaixo do nome da empresa.

### Recomendação: info de faturamento como sub-linha abaixo do nome

A abordagem "abaixo do nome" (sub-linha) é mais limpa que "coluna à direita" porque:
1. O row já tem 5 itens (`shrink-0`) pressionando o `flex-1` do nome — adicionar mais um `shrink-0` no meio comprime o nome
2. O padrão de sub-linha já é usado em Admin.jsx (company row) e Desenvolvimento.jsx (`EmpresaRow`)

```jsx
// FechamentoRow expandido — padrão sub-linha (recomendado)
<div className="flex items-center gap-4 px-4 py-3 cursor-pointer transition-colors ...">
    <ChevronDown ... />
    <div className="flex-1 min-w-0">
        <span className="text-white font-semibold text-[13px] truncate block">{empresa.name}</span>
        {empresa.estado === 'ok' && (
            <span className="text-white/40 text-[12px] mt-0.5 block">
                {fmtBRL(empresa.faturamento)} · {empresa.periodo_inicio} a {empresa.periodo_fim}
            </span>
        )}
    </div>
    <ServiceBadge tipo={empresa.service_type} />
    {!empresa.has_adman && <IntegrationBadge />}
    <span className="text-white/40 text-[13px] font-mono shrink-0">{datas}</span>
</div>
```

**Alternativa (coluna à direita):** Se o planner preferir manter o nome em `span` simples (sem wrapper `div`), faturamento pode ir como coluna `shrink-0` entre `ServiceBadge` e `datas`. Isso é razoável se as empresas tiverem nomes curtos — mas dado `truncate` já aplicado, a sub-linha é mais segura.

---

## 5. Accordion Billing Section — onde e como adicionar

### Estrutura atual de FechamentoAccordion (Phase 5) [VERIFIED: codebase — Financeiro.jsx linhas 159–165]

```jsx
function FechamentoAccordion({ empresa, onClose }) {
    return (
        <div className="px-4 py-4 bg-black/30 border-t border-white/[0.04]">
            <ServiceForm empresa={empresa} onClose={onClose} />
        </div>
    );
}
```

### Padrão de seção secundária dentro de accordion (Desenvolvimento.jsx linhas 102–123) [VERIFIED: codebase]

```jsx
// Fonte: resources/js/Pages/Dev/Desenvolvimento.jsx linhas 102–123
function EmpresaAccordion({ empresa }) {
    return (
        <div className="px-4 py-3 bg-black/30 border border-white/[0.04]">
            {empresa.error && (
                <>
                    <p className="text-[11px] uppercase tracking-wider text-white/40">Erro do último sync</p>
                    <pre ...>{empresa.error}</pre>
                </>
            )}
            <p className={cn('text-[11px] uppercase tracking-wider text-white/40 mb-2', empresa.error && 'mt-3')}>
                Resultado do último sync
            </p>
            ...
        </div>
    );
}
```

Padrão de label de seção: `text-[11px] uppercase tracking-wider text-white/40 mb-2`
Separador entre seções: `mt-3` (margem top ao segundo bloco) ou `border-t border-white/[0.04]`

### Recomendação para FechamentoAccordion expandido

```jsx
function FechamentoAccordion({ empresa, onClose }) {
    return (
        <div className="px-4 py-4 bg-black/30 border-t border-white/[0.04]">

            {/* Bloco de fatura: FaixaProgresso — acima do ServiceForm (D-07) */}
            {empresa.estado === 'ok' && (
                <FaixaProgresso faturamento={empresa.faturamento} faixa={empresa.faixa} />
            )}

            {/* Serviço adicional — abaixo da barra, acima do ServiceForm (D-12) */}
            <div className="mb-4">
                <p className="text-[11px] uppercase tracking-wider text-white/40 mb-1">
                    Serviço adicional
                </p>
                <p className="text-white/80 text-[13px]">
                    {empresa.additional_service || '—'}
                </p>
            </div>

            {/* Divider antes do formulário de edição */}
            <div className="border-t border-white/[0.04] pt-4">
                <ServiceForm empresa={empresa} onClose={onClose} />
            </div>
        </div>
    );
}
```

**Notas:**
- `FaixaProgresso` renderiza condicional apenas para `estado === 'ok'` — empresas `sem_dados` e `sem_integracao` não têm `faturamento`/`faixa`, então o componente receberia `null` e retornaria `null`
- O divider `border-t border-white/[0.04] pt-4` antes do `ServiceForm` separa visualmente os dados de faturamento (read-only) do formulário de edição — padrão consistente com `EmpresaAccordion` de Desenvolvimento.jsx

---

## 6. Icon Selection — evidência do codebase

### Ícone para "Faixa máxima" (Claude's Discretion)

| Ícone | Arquivo | Contexto de uso | Adequação para "Faixa máxima" |
|-------|---------|-----------------|-------------------------------|
| `TrendingUp` | Mlb/Vendas.jsx:374, Performance/Index.jsx:137, Portfolio/Show.jsx:187 | Crescimento, evolução, alta de faturamento | **ALTO** — semântica de "cresceu além do limite" |
| `Trophy` | Dashboard/Admin.jsx:543, AppLayout.jsx:30 | Ranking, desempenho de pico | ALTO — semântica de conquista |
| `Star` | Dashboard/Admin.jsx:184, AppLayout.jsx:27 | NPS, avaliações | MÉDIO — muito associado a NPS no projeto |
| `Zap` | Mlb/Publicacoes.jsx:919 | Velocidade, produtividade | BAIXO — sem relação direta com faixa |

**Recomendação: `TrendingUp`** — já importado em vários módulos financeiros/operacionais do projeto (Mlb/Vendas.jsx usa para "Faturamento", Mlb/Dashboard.jsx para "Projeção"). Semântica de crescimento é a mais correta para "acima de R$5M". `Trophy` é alternativa válida.

### Ícone para TotalConsolidado

`Banknote` — já importado em `Financeiro.jsx` linha 4 e `AppLayout.jsx` linha 8 (item de menu Fechamento). Usar o mesmo ícone do header da página cria coerência visual. Alternativa: `DollarSign` (usado em Dashboard para "Faturamento Total" — Dashboard/Admin.jsx linha 324, Dashboard/User.jsx linha 37). `DollarSign` seria adequado para o stat block; `Banknote` é o ícone canônico deste módulo.

**Recomendação: reusar `Banknote` para o label do bloco, `TrendingUp` somente para o badge "Faixa máxima".**

---

## 7. Implementation Approach — 1 plano único

### Decisão: 1 plano

Toda a mudança está em `Financeiro.jsx` — um único arquivo. Os novos sub-componentes (`TotalConsolidado`, `FaixaProgresso`) são definidos localmente no mesmo arquivo (padrão estabelecido pelo projeto: `StatusBadge`, `MotivoBadge` em Sugadores/Index.jsx, `KpiCard` em Dashboard/Admin.jsx). Não há nova rota, não há novo controller, não há migração.

**Wave única:**
- Task 1: Adicionar constante `FAIXAS_LIMITES` + helper `fmtBRL` local
- Task 2: Implementar `TotalConsolidado({ empresas })`
- Task 3: Expandir `FechamentoRow` para exibir faturamento + período
- Task 4: Implementar `FaixaProgresso({ faturamento, faixa })`
- Task 5: Expandir `FechamentoAccordion` para incluir `FaixaProgresso` + serviço adicional
- Task 6: Wiring no `export default Financeiro` (passar `companies` para `TotalConsolidado`)
- Task 7: `npm run build` + checkpoint humano (D-15)

**Por que não 2 planos:**
- Sem TDD obrigatório para componentes puramente visuais nesta fase (FCH-06/07/08 são requisitos de exibição, não de lógica de backend)
- Sem novo arquivo de teste necessário — a validação é visual (D-15: `autonomous: false`)
- Todas as decisões de dados já estão bloqueadas no CONTEXT.md

---

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|----------|--------------|-------------------|---------|
| Barra de progresso | Componente customizado com SVG/canvas | `div` + `style={{ width }}` (padrão codebase) | 7+ ocorrências no codebase, zero overhead |
| Formatação BRL | Parser manual de moeda | `Number.toLocaleString('pt-BR', { style: 'currency' })` | API nativa, sem dependência |
| Animação da barra | CSS keyframes custom | Tailwind `transition-all` | Já usado em todos os `ProgressBar` do codebase |
| Cálculo de faixa | Lógica de negócio nova | `FAIXAS_LIMITES` constante espelhando `AdminController::FAIXAS` | Lógica de domínio já testada no backend |

---

## Common Pitfalls

### Pitfall 1: JSON round-trip converte float sem decimal para int

**O que dá errado:** `faturamento: 3000.0` serializado pelo PHP vira `3000` (int) no JSON — `typeof empresa.faturamento` é `"number"` mas `empresa.faturamento === 3000` (int, não float).

**Por que acontece:** `json_encode(3000.0)` → `"3000"` → `json_decode` → `int(3000)`. Documentado em `06-02-SUMMARY.md`.

**Como evitar:** Sempre usar `Number(empresa.faturamento)` antes de aritmética ou formatação. O `fmtBRL` já faz isso: `Number(n).toLocaleString(...)`.

### Pitfall 2: `pct` acima de 100 quando faturamento passa do `proximo`

**O que dá errado:** Uma empresa na faixa `ate_499k` com faturamento de R$600k (após reclassificação atrasada) resulta em `pct > 100` — a barra ultrapassaria o container.

**Por que acontece:** `calcularFaixa` pode ter defasagem de 1 ciclo de sync.

**Como evitar:** `Math.min(100, Math.max(0, pct))` — padrão já usado em Dashboard/Admin.jsx linha 252 (`Math.min(pct, 100)`).

### Pitfall 3: Faixa `maxima` não está em `FAIXAS_LIMITES`

**O que dá errado:** `FAIXAS_LIMITES['maxima']` retorna `undefined` — `faixaData.proximo` lança TypeError.

**Por que acontece:** `calcularFaixa()` retorna `'maxima'` para faturamento > R$5M, mas `FAIXAS_LIMITES` só tem 6 entradas (até `4m_4999k`). Não há entrada `maxima` porque não existe faixa superior.

**Como evitar:** Checar `faixa === 'maxima'` antes de acessar `FAIXAS_LIMITES` — branch separada no `FaixaProgresso` (D-09 já especifica isso).

### Pitfall 4: `periodo_inicio`/`periodo_fim` nulos exibidos em `FechamentoRow`

**O que dá errado:** Empresas com `estado === 'sem_dados'` têm `periodo_inicio = null` — exibir a sub-linha sem verificar `estado` gera "null a null".

**Como evitar:** Condicional `{empresa.estado === 'ok' && ...}` envolve todo o bloco de período/faturamento no FechamentoRow.

### Pitfall 5: `fmtBRL(0)` retornando "R$ 0" em vez de "—"

**O que dá errado:** `0` é falsy em JS — `fmtBRL(0)` poderia ser mal-escrito como `n == null ? '—' : ...` que retorna "R$ 0" (correto) ou `!n ? '—' : ...` que retorna "—" para zero (errado).

**Como evitar:** Verificação explícita `n == null ? '—'` — loose equality cobre `undefined` também. Nunca usar `!n` como guard para zero monetário.

---

## Code Examples

### FAIXAS_LIMITES (constante JS — espelha AdminController::FAIXAS)

```js
// Fonte: espelha app/Http/Controllers/AdminController.php linhas 14–21
const FAIXAS_LIMITES = {
    ate_499k:    { min: 0,        proximo: 500_000   },
    '500k_999k': { min: 500_000,  proximo: 1_000_000 },
    '1m_1999k':  { min: 1_000_000, proximo: 2_000_000 },
    '2m_2999k':  { min: 2_000_000, proximo: 3_000_000 },
    '3m_3999k':  { min: 3_000_000, proximo: 4_000_000 },
    '4m_4999k':  { min: 4_000_000, proximo: 5_000_000 },
};
```

### fmtBRL local (BRL sem centavos)

```js
// Padrão: Sugadores/Index.jsx linha 45, adaptado para minimumFractionDigits: 0 (D-14)
const fmtBRL = (n) => n == null ? '—'
    : Number(n).toLocaleString('pt-BR', {
        style: 'currency', currency: 'BRL',
        minimumFractionDigits: 0, maximumFractionDigits: 0,
    });
```

### Import final necessário (Phase 7 adds)

```jsx
// Adicionar TrendingUp ao import existente de lucide-react
import { Banknote, ChevronDown, Building2, WifiOff, TrendingUp } from 'lucide-react';
```

Nenhum outro import novo. `cn`, `formatDate` já importados. `useForm`, `useState` já importados.

---

## State of the Art

| Abordagem anterior | Abordagem Phase 7 | Impacto |
|-------------------|--------------------|---------|
| `Financeiro.jsx` sem dados de faturamento | Props `faturamento`, `faixa`, `periodo_*` chegam do backend (Phase 6) | Página passa de cadastro puro para dashboard de fechamento |
| Sem total consolidado | `TotalConsolidado` no topo calculado no JS a partir das props | Admin vê sumário imediato sem expandir nenhuma empresa |
| Sem barra de progresso | `FaixaProgresso` por empresa no accordion | Admin vê posição exata na faixa e valor faltante |

---

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|-----------------|
| A1 | `faturamento` e `valor_mensal` chegam como `int` no JSON quando valores inteiros exatos (3000, 6000) — tratar com `Number()` é seguro | §3 Currency | Nulo — `Number(int)` é idempotente |
| A2 | `TrendingUp` está disponível em `lucide-react ^1.11.0` sem update | §6 Icons | Risco BAIXO — ícone estável na lib desde v0.x |

**Nenhuma assumption de alto risco.** Todos os padrões verificados diretamente no codebase.

---

## Open Questions

1. **Layout do row: sub-linha vs. coluna direita**
   - O que sabemos: a decisão é "Claude's Discretion" no CONTEXT.md
   - O que está indefinido: se a sub-linha altera a altura do row a ponto de quebrar o visual — depende de quantas empresas têm `estado === 'ok'` em produção
   - Recomendação: sub-linha (mais espaço para o nome, sem comprimir os badges existentes)

2. **`additional_service` no accordion: label e posicionamento relativo ao `FaixaProgresso`**
   - O que sabemos: D-12 diz "abaixo da barra e acima do ServiceForm"
   - Não há ambiguidade — o order é definido

---

## Environment Availability

Fase de expansão de arquivo JSX único. Nenhuma dependência externa nova.

| Dependência | Requerida por | Disponível | Versão | Fallback |
|-------------|--------------|-----------|--------|---------|
| Node.js + npm | `npm run build` | ✓ | v24.15.0 | — |
| Vite | build | ✓ | ^7.0.7 | — |
| lucide-react | `TrendingUp` | ✓ | ^1.11.0 | — |

---

## Validation Architecture

Esta fase é puramente visual (`autonomous: false`). Nenhum teste automatizado é criado — validação é checkpoint humano (D-15).

| Req ID | Comportamento | Tipo de teste | Comando | Arquivo existe? |
|--------|--------------|---------------|---------|----------------|
| FCH-06 | Barra de progresso visível no accordion | visual/manual | Checkpoint humano | N/A |
| FCH-07 | Total consolidado no topo | visual/manual | Checkpoint humano | N/A |
| FCH-08 | `additional_service` visível no accordion | visual/manual | Checkpoint humano | N/A |
| CFG-01 | Label "Fechamento" no sidebar | visual/manual | Já implementado Phase 5 | N/A |

**Wave 0 Gaps:** Nenhum. Validação é inteiramente visual conforme D-15.

---

## Security Domain

Esta fase não introduz: rotas novas, formulários de POST/PATCH novos, input de usuário, autenticação. O único acesso é via `EnsureUserHasRole` já configurado para a rota `admin.financeiro`. Nenhuma categoria ASVS aplicável além das existentes.

---

## Sources

### Primary (HIGH confidence)
- `resources/js/Pages/Admin/Financeiro.jsx` — arquivo alvo; lido integralmente
- `resources/js/Pages/Dashboard/Admin.jsx` — padrão KpiCard e mini-stat; lido integralmente
- `resources/js/Pages/Dev/Desenvolvimento.jsx` — padrão DevCard e EmpresaAccordion; lido integralmente
- `resources/js/Pages/Mlb/Empresas.jsx` linhas 21–32 — padrão ProgressBar canônico
- `resources/js/Pages/Mlb/Dashboard.jsx` linhas 74, 183, 248–254 — padrões ProgressBar alternativos
- `resources/js/Pages/Performance/Index.jsx` linhas 241–245 — mini-barra horizontal
- `resources/js/Pages/Sugadores/Index.jsx` linhas 44–47 — padrão `fmtBRL` local
- `resources/js/lib/utils.js` — `formatCurrency` com decimais confirmado
- `app/Http/Controllers/AdminController.php` — `FAIXAS` constante PHP para espelhar em JS
- `tailwind.config.js` — tokens `ecf-*`, `card-ecf` em `app.css`
- `.planning/phases/07-ui-fechamento/07-CONTEXT.md` — decisões bloqueadas D-01..D-17
- `.planning/phases/06-backend-fechamento/06-02-SUMMARY.md` — props shape confirmado

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhum pacote novo; tudo verificado no codebase
- Architecture: HIGH — padrões localizados linha a linha no codebase
- Pitfalls: HIGH — documentados no Summary de Phase 6 (JSON round-trip) + inspeção direta do codebase
- Icons: HIGH — todos os candidatos verificados por grep no codebase

**Research date:** 2026-05-19
**Valid until:** 2026-06-19 (estável — baseado em código commitado)
