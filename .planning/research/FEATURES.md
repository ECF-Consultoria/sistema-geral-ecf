# Feature Landscape — Módulo Administrativo: Fechamento/Billing

**Domínio:** Billing interno B2B — cálculo de mensalidade por faixa de faturamento MLB
**Pesquisado:** 2026-05-19
**Milestone:** v2.0 Administrativo — Fechamento

---

## Contexto do Domínio

O módulo resolve um problema de visibilidade financeira interna: o admin precisa saber
quanto cobrar de cada cliente no fechamento mensal sem acessar planilhas ou o servidor.
A lógica de negócio é determinística (7 faixas fixas) e a fonte de dados já existe
(`adman_metrics.revenue` diário por empresa). Não é um sistema de cobrança automatizado —
é um painel de consulta e conferência para uso humano.

**Distinção importante:** Não é SaaS de billing genérico (Stripe, invoices, dunning). É
um painel de leitura + cálculo para um admin que fecha manualmente com cada cliente.

---

## Table Stakes

Features que o admin espera ter. Ausência torna o módulo inútil ou recusado.

| Feature | Por Que Obrigatória | Complexidade | Notas |
|---------|---------------------|--------------|-------|
| **Lista de empresas ativas com tipo de serviço** | Sem isso o admin não sabe o que está olhando; POLO vs Assessoria é contexto mínimo | Baixa | `Company.segment` existe mas não tem enum POLO/Assessoria/Incubadora — requer coluna nova ou reutilização de `segment` com valores fixos |
| **Faturamento mensal por empresa (mês corrente)** | É o dado central de toda a lógica; sem faturamento não há faixa nem valor a cobrar | Média | Fonte: SUM de `adman_metrics.revenue` do mês. Atenção: mês corrente = dados parciais até hoje |
| **Faixa de investimento calculada automaticamente** | A tabela de 7 faixas é a regra de negócio principal; o cálculo deve ser automático, não manual | Baixa | Lógica pura: 7 if/elseif; último tier (>R$5M) retorna R$12.000 como base |
| **Valor a cobrar por empresa** | Output principal do módulo; é o que o admin usa no fechamento | Baixa | Derivado direto da faixa; tier >R$5M requer anotação "a partir de R$12.000" |
| **Total consolidado do mês (soma de todos os valores a cobrar)** | Visão macro: quanto a ECF vai faturar no fechamento | Baixa | Soma das mensalidades calculadas; excluir empresas sem faturamento ainda |
| **Indicação de dados parciais para mês corrente** | O faturamento do mês atual está incompleto até o último dia | Baixa | Badge ou aviso "dados até DD/MM"; crítico para evitar leitura errada da faixa |
| **Empresas sem faturamento Adman (sem `adman_account_id`)** | Admin precisa saber quais empresas estão fora do cálculo | Baixa | Exibir separado ou com estado "sem integração" |

## Table Stakes — Progressão de Faixa

| Feature | Por Que Obrigatória | Complexidade | Notas |
|---------|---------------------|--------------|-------|
| **Barra de progresso: posição na faixa atual** | Mostra onde o cliente está dentro da faixa; contexto visual imediato | Baixa | Percentual = (faturamento - início da faixa) / (fim da faixa - início da faixa) |
| **Distância para a próxima faixa (em R$)** | Informa quanto falta para mudança de mensalidade; dado de negócio relevante | Baixa | Derivado do mesmo cálculo da barra |
| **Indicação quando está no último tier (>R$5M)** | Faixa aberta — sem "próxima faixa"; UI deve adaptar | Baixa | Sem barra de progresso para próximo nível; exibir "faixa máxima atingida" |

---

## Diferenciadores

Features que agregam valor mas não são obrigatórias para o MVP.

| Feature | Proposta de Valor | Complexidade | Quando Construir |
|---------|-------------------|--------------|-----------------|
| **Datas de contrato por empresa** | Saber quando o cliente entrou e há quanto tempo está na ECF; contexto de relacionamento | Baixa | v2.1 — requer 2 colunas novas (`contract_start`, `contract_end`) na tabela `companies` |
| **Comparativo com mês anterior** | Mostrar se o cliente cresceu ou regrediu de faixa; detecta mudança de mensalidade | Média | v2.1 — dados históricos já existem em `adman_metrics`; basta comparar com mês anterior |
| **Alerta visual de mudança de faixa iminente** | Cliente a <10% do teto da faixa → highlight amarelo; prepara conversa comercial | Baixa | v2.1 — regra simples sobre o percentual já calculado |
| **Histórico de faixas por empresa (últimos N meses)** | Tendência de crescimento do cliente; argumento de renovação | Média | v3.0 — necessita UI de histórico e mais queries |
| **Campo de serviço adicional (reservado)** | Placeholder para cobranças extras no futuro; sem lógica de valor agora | Baixíssima | Já no escopo do ADM-06 — exibir campo, não calcular |
| **Exportação CSV do fechamento** | Admin pode colar em planilha ou enviar por email | Média | v2.2 — útil mas workaround manual é viável agora |
| **Seletor de mês de referência** | Consultar fechamento de meses anteriores | Média | v2.2 — dados históricos existem; requer UI de seleção de período |
| **Ordenação/filtro da lista (por faixa, por tipo, por nome)** | Agiliza conferência quando há muitas empresas | Baixa | v2.1 — frontend puro, sem backend |

---

## Anti-Features

Features a não construir neste milestone e os motivos.

| Anti-Feature | Por Que Evitar | O Que Fazer em Vez Disso |
|--------------|---------------|--------------------------|
| **Emissão de boleto ou NF** | Integração com gateway/ERP; complexidade alta; fora do domínio do ECF Admin | Calcular valor; admin fecha manualmente com cliente |
| **Régua de cobrança automatizada (dunning)** | Requer integração de pagamento, rastreamento de inadimplência; scope creep | Painel é leitura, não ação financeira |
| **Edição da tabela de faixas via painel** | Mudança de regra de negócio é rara e controlada; UI de configuração gera risco de erro | Faixas ficam como constante no código PHP; alterar via PR |
| **Multi-moeda ou moeda configurável** | Contexto é 100% BRL; adiciona complexidade sem retorno | Fixar BRL com símbolo R$ |
| **Divisão de fatura entre múltiplos responsáveis** | Modelo de negócio ECF é 1 empresa = 1 mensalidade fixa | Campo de observações no máximo |
| **Dashboard de receita acumulada anual da ECF** | Distância grande do MVP; cálculo envolve todos os meses históricos | Diferenciador de v3.0+ |
| **Notificação automática por email para o cliente** | Comunicação com cliente não é responsabilidade deste painel interno | Admin notifica manualmente |
| **Cálculo proporcional por dias de contrato (pro-rata)** | Regra de negócio que não existe no modelo atual; adiciona ambiguidade | Mês cheio sempre; pro-rata é decisão comercial case-by-case |
| **Integração direta com Mercado Livre API** | Faturamento já vem pela Adman API — não duplicar fontes | Usar `adman_metrics.revenue` como fonte única |

---

## Dependências Entre Features

```
adman_metrics (dados diários) → faturamento_mensal (SUM por mês) → faixa_calculada → valor_a_cobrar
                                                                ↘
                                                                 barra_de_progresso + distância_próxima_faixa

faixa_calculada × todas_empresas → total_consolidado_mês

Company.service_type (nova coluna) → lista_com_tipo_de_serviço

indicação_dados_parciais ← (data_atual < último_dia_do_mês)
```

**Ordem de construção imposta pelas dependências:**
1. Coluna `service_type` na tabela `companies` (ADM-01) — unblocks a lista
2. Agregação mensal de `adman_metrics` (ADM-02) — unblocks todos os cálculos
3. Cálculo de faixa (ADM-03) — depende de (2)
4. Barra de progressão (ADM-04) — depende de (3)
5. Total consolidado (ADM-05) — depende de (3) de todas as empresas
6. Campo serviço adicional (ADM-06) — independente, pode ser feito junto com (1)

---

## Visualização de Progressão de Faturamento

Pesquisa de padrões de UI para billing B2B com lógica de tiers.

### Recomendação: Progress Bar Horizontal com Labels de Faixa

**Padrão mais comum e cognitivamente eficiente para tiers lineares.**

```
[Faixa: R$500k–R$999k]  Faturamento: R$720.000
|████████████████░░░░░░░░░░░░| 44%
R$500k                        R$1M
                 Falta: R$280.000 para próxima faixa (R$4.500 → R$6.000)
```

**Por que progress bar e não gauge ou sparkline:**
- **Gauge (velocímetro):** Adequado para KPIs instantâneos (velocidade, temperatura); não para progresso linear entre dois pontos fixos. Ocupa mais espaço e é menos legível em lista densa.
- **Sparkline:** Representa série temporal (tendência). Útil para comparar meses, não para mostrar onde o cliente está dentro de uma faixa fixa hoje.
- **Progress bar:** Representa posição entre dois extremos (início/fim da faixa atual). Mapeamento cognitivo direto com o conceito de "faixa". Compacto o suficiente para usar em linha na tabela.

### Implementação no Contexto ECF

```jsx
// Dentro de cada linha da tabela ou DevCard de empresa
<div className="flex items-center gap-2">
  <div className="flex-1 h-1.5 rounded-full bg-white/[0.08]">
    <div
      className="h-full rounded-full bg-ecf-yellow transition-all"
      style={{ width: `${progressoPct}%` }}
    />
  </div>
  <span className="text-white/40 text-[11px] shrink-0">{progressoPct}%</span>
</div>
<p className="text-white/40 text-[11px] mt-1">
  Falta <span className="text-white/70">{fmtBRL(distanciaProxima)}</span> para faixa de {fmtBRL(valorProximaFaixa)}/mês
</p>
```

**Casos especiais:**
- Tier máximo (>R$5M): ocultar barra; exibir badge "Faixa máxima" em vez de progresso
- Dados parciais (mês corrente): barra em cor diferente (amber) com tooltip "dados até hoje"
- Empresa sem `adman_account_id`: estado vazio "sem integração Adman"

---

## MVP Recomendado (ADM-01 a ADM-06)

**Priorizar:**
1. ADM-01: Lista de empresas com tipo de serviço (POLO/Assessoria/Incubadora) — foundation
2. ADM-02: Faturamento mensal via SUM de `adman_metrics` + indicação de dados parciais
3. ADM-03: Faixa calculada automaticamente
4. ADM-04: Barra de progresso + distância para próxima faixa
5. ADM-05: Total consolidado (headline number no topo da página)
6. ADM-06: Campo de serviço adicional (input visível, sem lógica de valor)

**Diferir:**
- Datas de contrato: baixo valor no MVP, requer migration, pode esperar v2.1
- Seletor de mês de referência: mês corrente atende o uso imediato; histórico é v2.2
- Exportação CSV: workaround manual é viável enquanto o volume de empresas for <50
- Filtros/ordenação da lista: adicionar em v2.1 se o número de empresas justificar

---

## Considerações Técnicas de Implementação

### Cálculo de Faturamento Mensal
- **Fonte:** `adman_metrics` — dados diários. Faturamento mensal = `SUM(revenue)` WHERE `company_id = X` AND `reference_date` BETWEEN início e fim do mês.
- **Mês corrente:** `SUM` do dia 1 até ontem (`now()->subDay()`), pois o sync Adman opera com dados de D-1.
- **Empresa sem dados:** `adman_account_id` NULL ou sem registros no mês → exibir como "sem dados".
- **Alternativa:** `fetchPerformance($custId, $dateFrom, $dateTo)` com range mensal — evita dependência de registros diários completos, mas adiciona carga na API Adman. Preferir banco local quando possível.

### Lógica de Faixa
- Implementar como método estático em classe de domínio (ex: `InvestimentoFaixa`) ou helper no controller.
- Retornar struct: `['faixa_min' => X, 'faixa_max' => Y|null, 'mensalidade' => Z, 'label' => '...']`
- Tier aberto (>R$5M): `faixa_max = null`; UI exibe "a partir de R$12.000".

### Service Type no Model Company
- A tabela `companies` tem `segment` (string livre). O enum POLO/Assessoria/Incubadora requer ou (a) reutilizar `segment` com valores controlados, ou (b) adicionar coluna `service_type` ENUM.
- Preferir coluna nova `service_type` para não poluir o campo `segment` já em uso.

---

## Fontes

- Análise direta do código: `app/Models/Company.php`, `app/Models/AdmanMetric.php`, `app/Services/AdmanService.php`
- Migrações: `2026_04_26_152220_create_adman_metrics_table.php`, `2026_04_26_152217_create_companies_table.php`
- Regras de negócio: `faturamento_adm.md` (tabela de faixas validada pelo usuário)
- Escopo do milestone: `.planning/PROJECT.md` (ADM-01 a ADM-06)
- Padrões de UI: análise de `resources/js/Pages/Dev/Desenvolvimento.jsx` (DevCard, design tokens ECF)
- Confiança HIGH: lógica de negócio derivada de documento primário (`faturamento_adm.md`) + código real
- Confiança HIGH: dados disponíveis confirmados pela leitura do schema de banco
- Confiança MEDIUM: recomendação de progress bar vs gauge/sparkline — baseada em padrões gerais de UI para billing tiers, sem fonte primária específica
