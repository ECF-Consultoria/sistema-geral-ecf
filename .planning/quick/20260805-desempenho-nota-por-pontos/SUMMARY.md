---
task: Nota de desempenho por pontos + filtro por mês nas telas
slug: desempenho-nota-por-pontos
created: 2026-08-05
completed: 2026-08-05
status: complete
deployed: false
---

# Sumário — nota por pontos + filtro por mês

## O que mudou

**Cálculo.** A nota final deixou de aplicar a régua uma vez sobre a % agregada
da carteira e passou a usar a régua já aplicada **loja a loja**, promediando
depois — o modelo do fechamento manual do time. `computeNotaFinalPorIndicador()`
faz a média por indicador com **denominador independente**, então loja sem
margem continua contando no faturamento e no NPS. Sem arredondamento em etapa
intermediária.

**Réguas intocadas.** Da planilha veio só a lógica de agregação. Os cortes
numéricos de faturamento e margem continuam os de sempre.

**Shopee.** Loja Shopee saiu da média de margem (entrava com placeholder 1,0, a
nota mínima). Ganhou `componentes_esperados = 2` para não trocar a punição de
lugar — sem isso ficaria eternamente `partial` e derrubaria o `score_status`.

**NPS.** Mantido como está: não gerado e não respondido valem 1,00. Regra
reafirmada pelo usuário. É a maior causa de a nota do sistema ficar abaixo do
fechamento manual (92 das 286 lojas da competência 2026-06), e isso é
deliberado — está documentado no código para ninguém "corrigir" depois.

**Telas.** Toggle "Em curso / Bônus atual / Mês fechado" removido das 4 telas;
o mês passa a ser escolhido só pelo dropdown. `Portfolio/Carteiras` não tinha
seletor de mês e ganhou um (o `?mes=` já era aceito no backend).

**Formatação.** Nota sempre com 2 casas (`4,03`). O helper antigo exibia "4"
para inteiro e "4,1" para o resto, então a mesma tela mostrava "4", "4,1" e
"4,03" lado a lado.

**Cache.** Bump `v16 → v17`.

**Novo comando.** `desempenho:backup-snapshots --mes=YYYY-MM` grava as duas
tabelas de snapshot em JSON antes de reconsolidar competência congelada.

## Regressão encontrada e corrigida

`computeScoreStatusPorEmpresa()` ganhou a trava explícita de **D-91-01**:
carteira com zero vínculos financeiros elegíveis volta a ser `blocked`. Sem
ela, um profissional só-Polos passava a receber nota calculada apenas com NPS —
faturamento e margem ausentes saíam do denominador independente — e entrava no
ranking medido por uma única dimensão, podendo alcançar faixa de bônus. O
cálculo legado barrava isso olhando `vinculos_financeiros`; com o status
derivando da distribuição por empresa, a trava precisava ser explícita.

## Impacto medido em produção (leitura apenas)

Competência **2026-06** (única congelada por `consolidar_mes`), 11 profissionais
com carteira:

- **Contemplados: 5 → 1.** O único que permanece cai de faixa (de
  `intermediario` para `basico`).
- **8 das 11 notas caem**, em média ~0,7 ponto; as 3 que sobem são as carteiras
  com participação Shopee, porque a margem deixou de puxá-las para baixo.
- A maior queda isolada passa de `basico` para `sem_bonus` por ~1,1 ponto.

Nome pareado com nota/faixa NÃO é versionado aqui — ver
`.planning/learnings/desempenho-bonificacao.md` §11. Para conferir por
profissional, reconsulte `desempenho_company_score_snapshots` na competência.

Não é defeito de cálculo — é o efeito da mudança sob as faixas atuais (básico
começa em 4,00). **Recalibrar faixa é pauta de diretoria e não entrou aqui.**

## Ponto de atenção não resolvido

Carteira só-Shopee sem NPS coletado pode produzir nota vinda **de uma única
dimensão**. Caso real na competência 2026-07: um profissional fecha 4,82
(faixa intermediário) com margem ausente por ser Shopee e NPS ainda não
coletado — a nota é o faturamento puro. O `score_status` marca a fragilidade,
mas a faixa de bônus é calculada assim mesmo. Uma exigência de cobertura mínima
de dimensões para valer bônus resolveria, e é decisão de negócio.

## Testes

Baseline do módulo antes da mudança: **31 falhas**. Depois: **31 falhas** — as
mesmas, conferidas por diff dos nomes de teste, não por contagem. Nenhuma falha
nova introduzida.

O gate dourado (`PayloadBaselineFlagOffTest`) foi reescrito com os valores
derivados da fixture **à mão antes de rodar**; a previsão (2,75) bateu com a
execução. Removidos os 4 espelhos "com flag ligada" de
`DesempenhoShopeeScoreTest`, que viraram duplicatas exatas sem a bifurcação.

`npm run build` executado.

## Não feito (deliberadamente)

- **Deploy** — exige autorização explícita.
- **Reconsolidar competência fechada** — junho segue congelado com os 5
  contemplados. O comando de backup está pronto para quando for autorizado.
- **Recalibrar faixas de bônus.**
- **Decompor delta contra a nota oficial** — `desempenho:comparar-score-empresa`
  segue comparando legado × company-first, que é o par que a matemática dele
  modela. Para o efeito desta mudança, comparar `nota_final_legado` ×
  `nota_final`, ambos expostos no payload.
