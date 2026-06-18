# Quick Task 260618-m0g: Imposto individual por produto na Precificação - Context

**Gathered:** 2026-06-18
**Status:** Ready for planning

<domain>
## Task Boundary

Na Precificação do link do cliente (`resources/js/Pages/Mlb/ImplementacaoPublica.jsx`,
componente `PrecificacaoModal` + `SimuladorPreco` + modo Lote via `SpreadsheetGrid`),
suportar empresas cujo regime tributário cobra **imposto por produto**.

Hoje o imposto é **global por tier** (`cfg.classico.imposto` / `cfg.premium.imposto`),
igual para todos os produtos. Vamos adicionar a opção de **imposto individual por produto**,
mantendo o modo atual (massa) como padrão.
</domain>

<decisions>
## Implementation Decisions (TRAVADAS pelo usuário — não revisitar)

### 1. Imposto individual = UM por produto (1 coluna)
- Um único campo de imposto por produto (não separado por tier). O mesmo imposto individual
  do produto vale tanto para Clássico quanto para Premium daquele produto.
- No Lote: **uma** nova coluna "Imposto Individual" ao lado do Frete.

### 2. Modo = toggle único da precificação (Massa × Individual)
- Um único botão/toggle "Imposto: Massa | Individual" que vale para TODA a lista de produtos
  daquele cliente (não é por produto).
- **Massa** = comportamento atual (imposto global por tier, igual para todos).
- **Individual** = cada produto usa o seu próprio imposto.
- O modo é uma configuração persistida da precificação (nova chave, ex.: `modo_imposto`
  = `'massa' | 'individual'`, default `'massa'`).

### 3. Só o IMPOSTO vira por produto
- Apenas o imposto passa a ser por produto no modo individual.
- Comissão, Margem de Contribuição (MC), Lucro Líquido (LL) e Acréscimo **continuam globais**
  (iguais para todos os produtos), exatamente como hoje.

### Claude's Discretion
- Onde exatamente posicionar a coluna "Imposto Individual" no Lote (sugestão: logo após o
  Custo / primeiro Frete, como coluna de produto e não dentro do bloco Clássico/Premium, já
  que vale para os dois tiers). Visível/relevante apenas no modo individual — pode ocultar a
  coluna no modo massa para reduzir ruído, OU mantê-la sempre visível; decidir pelo que ficar
  mais limpo e consistente com o `SpreadsheetGrid`.
- Como expor o imposto no Simulador em cada modo: em **massa**, o campo de imposto edita o
  global (afeta todos — comportamento atual); em **individual**, o campo de imposto edita
  apenas o `imposto_individual` do produto selecionado.
</decisions>

<specifics>
## Specific Ideas

- O usuário descreveu: "se eu editar o imposto em massa, vai para todos" (manter). E quando
  individual, "eu colocaria o imposto individualmente por produto", "principalmente margem não
  interferir em outros" → cada produto calcula de forma independente no modo individual.
- Campo novo por produto: `imposto_individual` (decimal, ex.: 0.12 para 12%), na mesma linha
  de produto (`{ sku, descricao, custo, frete_classico, frete_premium, imposto_individual }`).
- `calcPreco(custo, frete, comissao, imposto, mc, ll)` já recebe `imposto` como parâmetro —
  no modo individual basta passar `imposto_individual` do produto no lugar do imposto global
  do tier (em ambos os tiers usa o mesmo imposto individual do produto).
</specifics>

<canonical_refs>
## Canonical References

- Persistência: `PrecificacaoModal` salva via `onSave(rows)` (produtos) e `onSaveCfg(campo, decimal)`
  (config), que no root viram `onChange('precificacao', campo, valor, true)` →
  `MlbImplementacaoController`. VERIFICAR no controller se o blob de precificação aceita chaves
  novas (`modo_imposto`) sem whitelist e se `imposto_individual` nas linhas é persistido sem
  validação restritiva — objetivo é NÃO precisar de migration (é JSON). Se houver whitelist,
  ajustar o controller.
</canonical_refs>
