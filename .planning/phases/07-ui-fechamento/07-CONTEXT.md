# Phase 7: UI Fechamento - Context

**Gathered:** 2026-05-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Reescrever / expandir `Financeiro.jsx` para consumir os props entregues pela Phase 6 e exibir:
- Faturamento mensal por empresa com período coberto
- Barra de progresso na faixa atual (quanto falta para a próxima)
- "Faixa máxima" para empresas acima de R$5M (sem barra)
- Total consolidado no topo (soma apenas empresas com estado `ok`)
- Campo de serviço adicional por empresa (valor visível ou "—")

Escopo estrito: FCH-06 (progresso de faixa), FCH-07 (total consolidado + período coberto na UI), FCH-08 (serviço adicional visível). Nenhuma mudança de backend — apenas `Financeiro.jsx` e `npm run build`.

</domain>

<decisions>
## Implementation Decisions

### Props disponíveis (Phase 6 — confirmadas)

- **D-01:** Props por empresa agora incluem, além dos campos de Phase 5: `estado` ('sem_integracao' | 'sem_dados' | 'ok'), `faturamento` (int|null — JSON int após round-trip), `periodo_inicio` (string 'd/m'|null), `periodo_fim` (string 'd/m'|null), `faixa` (string|null), `valor_mensal` (int|null — JSON int após round-trip), `additional_service` (string|null)

### Total consolidado

- **D-02:** Total consolidado = soma de `faturamento` de todas as empresas com `estado === 'ok'`. Empresas `sem_integracao` e `sem_dados` são excluídas.
- **D-03:** Total consolidado exibido no topo da página, acima da lista de empresas, como stat block. Exibir também o total de `valor_mensal` das empresas `ok`.
- **D-04:** Se nenhuma empresa tem estado `ok`, exibir "—" nos totais.

### Período coberto

- **D-05:** Período coberto ("01/05 a 18/05") exibido na linha da empresa (FechamentoRow), visível sem precisar expandir o accordion. Visível apenas para empresas com estado `ok`.
- **D-06:** Formato: `empresa.periodo_inicio + ' a ' + empresa.periodo_fim` (ex: "01/05 a 18/05").

### Barra de progresso (FCH-06)

- **D-07:** Exibida no accordion expandido (FechamentoAccordion), acima do ServiceForm.
- **D-08:** Calculada em JS a partir de constante `FAIXAS_LIMITES` com `min` e `proximo` por faixa — espelha a `FAIXAS` do backend.
- **D-09:** Para faixa `maxima`: exibir bloco "Faixa máxima" com texto e ícone, SEM barra de progresso.
- **D-10:** Para outras faixas: barra de progresso com `pct = (faturamento - min) / (proximo - min) * 100` + texto "Falta R$ X para a próxima faixa".
- **D-11:** Barra usa cor `ecf-yellow/30` de fundo e `ecf-yellow` de preenchimento.

### Serviço adicional (FCH-08)

- **D-12:** Campo `additional_service` exibido no accordion, abaixo da barra de progresso e acima do ServiceForm. Sem lógica de edição nesta fase — apenas display.
- **D-13:** Se `additional_service` é null ou vazio: exibir "—". Se preenchido: exibir o valor.

### Formatação de valores monetários

- **D-14:** Faturamento e valor_mensal formatados como BRL (ex: "R$ 1.000.000"). Usar `Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0 })` ou função local `fmtBRL`. Confirmar se existe `formatCurrency` em `@/lib/utils` — se não, definir local.

### Checkpoint humano

- **D-15:** Phase 7 tem `autonomous: false` — requer verificação visual humana antes de marcar como completa.

### Estrutura do arquivo

- **D-16:** `Financeiro.jsx` não é recriado do zero — é expandido a partir da versão atual (Phase 5). Sub-componentes existentes (`ServiceBadge`, `IntegrationBadge`, `FechamentoRow`, `FechamentoAccordion`, `ServiceForm`, `FechamentoList`) são mantidos e alguns expandidos.
- **D-17:** Novos sub-componentes a adicionar: `TotalConsolidado({ empresas })`, `FaixaProgresso({ faturamento, faixa })` (ou inline no accordion).

### Claude's Discretion

- Layout exato do stat block do total consolidado (horizontal vs. vertical)
- Ícone para "Faixa máxima" (TrendingUp, Award, etc.)
- Exatamente onde no FechamentoRow exibir faturamento + período (direita da linha? abaixo do nome?)
- Número de casas decimais nos valores monetários

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Frontend
- `resources/js/Pages/Admin/Financeiro.jsx` — arquivo alvo (estado atual pós-Phase 5); LER COMPLETO antes de qualquer edição
- `resources/js/Pages/Dev/Desenvolvimento.jsx` — analog de accordion e DevCard (para padrão de stat block)
- `resources/js/Pages/Sugadores/Index.jsx` — analog de badges e exibição de valores monetários (fmtBRL)
- `resources/js/lib/utils.js` — confirmar funções disponíveis (cn, formatDate, formatCurrency)
- `resources/js/Layouts/AppLayout.jsx` — não precisa de alteração nesta fase

### Props shape confirmada (Phase 6)
- `c:\xampp\htdocs\ecf_admin\.planning\phases\06-backend-fechamento\06-02-SUMMARY.md` — props shape confirmado
- `app/Http/Controllers/AdminController.php` — verificar props exatas entregues

### Design system
- `tailwind.config.js` — tokens `ecf-*` (ecf-yellow #ffe600, ecf-bg #050507)
- `.planning/phases/05-funda-o-fechamento/05-UI-SPEC.md` — design contract de Phase 5 (manter consistência)
- `resources/js/lib/utils.js` — cn(), formatDate()

### Faixas de investimento
- `faturamento_adm.md` — tabela de progressão (min/max de cada faixa)
- `app/Http/Controllers/AdminController.php` — const FAIXAS (espelhar em JS como FAIXAS_LIMITES)

### Requisitos
- `.planning/REQUIREMENTS.md` — FCH-06, FCH-07, FCH-08
- `.planning/ROADMAP.md` — Phase 7 Success Criteria (5 critérios)

</canonical_refs>

<code_context>
## Existing Code Insights

### Props atualmente consumidas em Financeiro.jsx (Phase 5)
```jsx
export default function Financeiro({ companies }) {
    // companies: Array<{ id, name, service_type, contract_start, contract_end,
    //                      additional_service, has_adman }>
```
Phase 6 expandiu o shape para incluir: `estado`, `faturamento`, `periodo_inicio`, `periodo_fim`, `faixa`, `valor_mensal`

### Constante JS necessária (espelha AdminController::FAIXAS)
```js
const FAIXAS_LIMITES = {
    ate_499k:    { min: 0,        proximo: 500000   },
    '500k_999k': { min: 500000,   proximo: 1000000  },
    '1m_1999k':  { min: 1000000,  proximo: 2000000  },
    '2m_2999k':  { min: 2000000,  proximo: 3000000  },
    '3m_3999k':  { min: 3000000,  proximo: 4000000  },
    '4m_4999k':  { min: 4000000,  proximo: 5000000  },
};
```

### Padrão de formatação monetária (de Sugadores/Index.jsx)
```js
const fmtBRL = (n) => n == null ? '—'
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0 });
```

### Padrão de stat block para totais (de DashboardController / Pages)
Verificar em `resources/js/Pages/Dashboard/` ou `resources/js/Pages/Admin/` como totais são exibidos.

### Tipos de dados das props (atenção ao JSON round-trip)
- `faturamento`: int quando valor é inteiro exato (ex: 3000, 1000000) — tratar com Number() no frontend
- `valor_mensal`: idem
- `periodo_inicio`/`periodo_fim`: strings 'd/m' (ex: '01/05') ou null

</code_context>

<specifics>
## Specific Ideas

- Total consolidado: dois stats lado a lado — "Total faturado" (soma faturamentos) e "Total a cobrar" (soma valor_mensal). Estilo de `DevCard` com ícone/valor proeminente.
- Barra de progresso: elemento simples `<div>` com `w-full bg-white/[0.05] rounded-full h-1.5` e filho `<div style={{ width: pct% }} className="bg-ecf-yellow h-1.5 rounded-full transition-all"`.
- "Faixa máxima": badge ou bloco com TrendingUp icon + texto em ecf-yellow.
- Período coberto no row: exibido abaixo do nome ou em coluna à direita, em `text-white/40 text-[12px]`.

</specifics>

<deferred>
## Deferred (out of Phase 7 scope)

- Edição do campo additional_service via UI (apenas leitura nesta fase)
- Histórico mensal de fechamentos (v2.1+)
- Agrupamento por tipo de serviço
- Exportação CSV

</deferred>

---

*Phase: 7-UI Fechamento*
*Context gathered: 2026-05-19*
