---
task: Nota de desempenho por pontos + filtro por mês nas telas
slug: desempenho-nota-por-pontos
created: 2026-08-05
status: in-progress
---

# Nota de desempenho por pontos + filtro por mês

Alinhar a nota final do módulo Desempenho ao fechamento manual feito em
`Fechamento Junho _ Time de performance.xlsx`, e trocar o toggle de contexto de
período por um seletor de mês simples.

## Diagnóstico (feito contra a VPS, leitura apenas — 2026-08-05)

A nota de hoje aplica a régua **uma vez** sobre a % agregada da carteira
(mediana no faturamento, média na margem). O fechamento manual aplica a régua
**loja a loja** e só depois promedia. Os dois divergem sistematicamente.

Medições sobre a competência 2026-06 (286 linhas de
`desempenho_company_score_snapshots`, congeladas por `consolidar_mes`):

| Causa | Medição |
|---|---|
| Agregação (régua da média × média das réguas) | já existe como `nota_final_por_empresa`; no Rubens dá 4,03 — idêntico à planilha |
| NPS de loja sem link de pesquisa valendo 1,00 | 92 de 286 lojas (32%); maior causa isolada da diferença |
| Loja Shopee entrando na margem com placeholder 1,0 | 51 de 286 lojas |
| Unidade da margem | planilha e sistema usam a MESMA grandeza (pontos percentuais): erro médio 0,62 p.p. contra 16,66 se fosse variação relativa |
| Faturamento | erro médio 0,03 p.p. em 96 lojas pareadas — as fontes concordam |

Com as três correções aplicadas, o erro médio contra as 9 abas da planilha cai
para **0,173**.

Erros de digitação encontrados na planilha (não são divergência de sistema):
SPINELLADECOR faturamento 12.450% (real 124,46%), LAURA LAR −7.520% (real
−75,16%), JBDECORHOME margem 880% (real 7,79%), AVF2K margem com sinal trocado
(−9,34 na planilha, +9,26 real).

## Decisões do usuário (2026-08-05)

1. **Agregação** — régua por loja, depois média por indicador com denominador
   independente (cada indicador com seu próprio denominador, como o `AVERAGE`
   do Excel ignora célula vazia). Loja sem margem continua contando no
   faturamento e no NPS.
2. **Régua** — INTOCADA. Da planilha vem apenas a LÓGICA de calcular a nota
   final (régua por loja → média), nunca os cortes numéricos. Decisão do
   usuário em 2026-08-05, confirmada pela medição (as duas réguas empatam:
   0,173 × 0,183) e coerente com a decisão de 2026-08-03 registrada em
   `.planning/learnings/desempenho-bonificacao.md` §1 de não recalibrar régua
   fora da diretoria. Seguem valendo:
   - faturamento `≤ -6 → 1 · ≤ -1 → 2 · < 1 → 3 · ≤ 5 → 4 · > 5 → 5`
   - margem `≤ -5 → 1 · ≤ -2 → 2 · ≤ 1 → 3 · ≤ 4 → 4 · > 4 → 5`
3. **NPS de loja sem link** (`origem = 'sem_nps'`) — sai da conta em vez de
   valer 1,00. Loja que RECEBEU link e não foi respondida (`origem =
   'imputada'`) continua valendo 1,00 — o incentivo de cobrar resposta fica de
   pé, o que cai é a punição por link não enviado.
4. **Shopee** — fica fora da média de margem (hoje entra com placeholder 1,0).
   A Shopee não fornece CMV; tratar como célula vazia, não como nota mínima.
5. **Arredondamento** — sem arredondar em etapa intermediária; exibição sempre
   com 2 casas (`4,03`, nunca `4` ou `4,1`).
6. **Telas** — remover o toggle "Em curso / Bônus atual / Mês fechado";
   o mês passa a ser escolhido só pelo seletor de mês.
7. **Competência fechada** — NÃO reconsolidar junho nesta tarefa. O comando de
   reconsolidação fica pronto, com backup e `--dry-run`, e só roda sob
   autorização explícita.

## Impacto conhecido e ainda não resolvido

Com o método novo, junho sai de **5 contemplados para 0** sob as faixas atuais
(básico começa em 4,00). Não é defeito: é a régua passando a valer loja a loja.
Recalibrar faixa é pauta de diretoria — não entra nesta tarefa.

## Tarefas

- [ ] T2 · NPS `sem_nps` fora do denominador (`CompanyScoreService`)
- [ ] T3 · Shopee fora da média de margem (`CompanyScoreService`)
- [ ] T4 · Agregador por indicador com denominador independente + virar oficial
- [ ] T5 · Bump da chave de cache + atualizar strings hardcoded nos testes
- [ ] T6 · Remover o toggle de período das 4 telas, manter o seletor de mês
- [ ] T7 · Exibição com 2 casas fixas, sem arredondamento intermediário
- [ ] T8 · Comando de reconsolidação com backup e `--dry-run` (sem executar)
- [ ] T9 · `npm run build`

## Fora de escopo

- Deploy (exige autorização explícita)
- Reconsolidar competência fechada
- Recalibrar faixas de bônus
- Alterar os cortes das réguas de faturamento/margem
