# Phase 137 — Discussion Log

**Data:** 2026-09-02
**Modo:** discuss (padrão), 4 áreas selecionadas de 4 oferecidas

> Registro humano da conversa. Não é lido por agentes downstream — o canônico é o `137-CONTEXT.md`.

---

## Escaneamento antes das perguntas

O scout mudou as perguntas. Achados que não estavam no brief do usuário:

| achado | consequência |
|---|---|
| A tabela progressiva **já existe** como constante `FAIXAS` em `AdminController` | A fase não cria a tabela do zero; ela troca uma constante única por dado configurável |
| A constante começa em **R$ 3.000**; o contrato de Gestão diz **R$ 3.500** | Divergência de dinheiro real, virou pergunta própria |
| `CompanyMonthlyRevenue` já guarda faturamento por mês fechado (571 linhas, 5 meses) | O lado ML já tem fonte; falta Shopee |
| `shopee_metrics` é diária, 19 empresas, sem rollup mensal | Virou risco explícito (D-07) |
| Grupos antigos (`parent_company_id`): 5 empresas / 2 pais | vs. 46 empresas / 15 grupos no Comercial |
| `MOVELOVEOFICIAL` é pai de 3 filhas mas **não tem grupo** no Comercial | Conflito real, virou pergunta própria |

---

## Área 1 — Onde mora a tabela de faixas

**Pergunta:** Como a tabela deve ser organizada?
**Opções:** por serviço com exceção por empresa (recomendada) / sempre por empresa / só por serviço
**Escolha:** **por serviço, com exceção por empresa**

Motivo apresentado: cobre os três casos do usuário (três tabelas de contrato diferentes, empresas
fora do padrão, e a maioria sem contrato no sistema) sem exigir cadastro de ~190 tabelas antes do
primeiro fechamento.

**Pergunta de acompanhamento:** qual das duas tabelas divergentes vira o padrão do serviço Gestão?
**Opções:** a do contrato (R$ 3.500) / a de hoje (R$ 3.000) / conferir antes
**Escolha:** **a de hoje (R$ 3.000)**

⚠️ Consequência registrada em D-03 e comunicada ao usuário: como todo contrato novo sai com
R$ 3.500, as exceções crescem com o tempo e o "padrão" vira o caso minoritário.

---

## Área 2 — De onde vem o faturamento

**Pergunta:** empresa nas duas plataformas — soma ou faixas separadas?
**Opções:** soma das duas (recomendada) / cada plataforma na sua faixa
**Escolha:** **soma das duas**

Motivo apresentado: é o que o contrato combinado (quick `260901-gj7`) já diz — o título da tabela é
"Faturamento Mensal (Mercado Livre e Shopee)", uma tabela só.

O fim do acumulativo (D-06) não foi perguntado: já estava travado pelo brief do usuário.

---

## Área 3 — Como os grupos funcionam

**Pergunta:** o `MOVELOVEOFICIAL` soma com as 3 filhas hoje, mas no Comercial elas estão em grupos
diferentes. O que vale?
**Opções:** grupos do Comercial mandam / corrigir o Comercial antes / somar os dois mecanismos
**Escolha:** **grupos do Comercial mandam**

Consequência aceita e registrada em D-09: o MOVELOVEOFICIAL deixa de somar com as três. Se estiver
errado, o conserto é no Comercial.

---

## Área 4 — O fechamento vira registro

**Pergunta:** congela por competência ou recalcula sempre?
**Opções:** congela ao fechar (recomendada) / sempre recalcula / congela mas dá para refazer
**Escolha:** **congela ao fechar o mês**

⚠️ A terceira opção (com caminho de reabertura) foi apresentada e **não** escolhida. Registrado em
D-12 e em `<deferred>`: hoje não há caminho de correção depois do fechamento, e o caso real existe.

---

## Escopo — nada foi redirecionado

Nenhuma sugestão de scope creep apareceu. As ideias que ficaram de fora estão em `<deferred>` por
serem consequência das decisões, não por terem sido propostas e recusadas.

---

*Log: 2026-09-02*
