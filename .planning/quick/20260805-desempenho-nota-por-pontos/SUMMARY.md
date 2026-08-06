---
task: Nota de desempenho por pontos + filtro por mês nas telas
slug: desempenho-nota-por-pontos
created: 2026-08-05
completed: 2026-08-05
status: complete
deployed: true
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

- **Recalibrar faixas de bônus** — pauta de diretoria.
- **Reconsolidar 2026-07 e 2026-08** — julho ainda está sem NPS (coletado em
  agosto) e agosto está em curso. Reconsolidar agora congelaria número sem
  significado. Abril e maio não têm base (o histórico de métricas começa em
  ~21/05).
- **Decompor delta contra a nota oficial** — `desempenho:comparar-score-empresa`
  segue comparando legado × company-first, que é o par que a matemática dele
  modela. Para o efeito desta mudança, comparar `nota_final_legado` ×
  `nota_final`, ambos expostos no payload.

## Deploy e reconsolidação de junho (2026-08-05)

**DEPLOYADO** — commits `8b33f39e..e113b066`, dois deploys isolados (o segundo
corrigiu o seletor de mês da tela individual, regressão descrita abaixo).
Workers travaram em `STOPPING` nas duas vezes e foram destravados com
`supervisorctl signal KILL` — comportamento já conhecido neste projeto.

**Competência 2026-06 RECONSOLIDADA** com autorização do usuário, após ele ver
na tela que junho ainda exibia o cálculo antigo (4,91 numa carteira cujas lojas
não sustentavam aquilo).

- Backup ANTES: `storage/app/private/backups/desempenho/desempenho-2026-06-20260805-145721.json`
  (11 resumos + 286 linhas por empresa, 501 KB) — preservado na VPS.
- `desempenho:consolidar-mes --mes=2026-06` → **exit code 0**, 11 consolidados,
  0 falhas, 0 degradados, 286 linhas por empresa. O gate FIXMARG-03 não recusou.
- Conferido por **reconsulta ao banco**, nunca por stdout.

Resultado: **de 5 contemplados para 1**. O que permanece caiu de
`intermediario` para `basico`.

### O caso que motivou a reconsolidação

O snapshot antigo de uma carteira mostrava `(4,72 + 5,00 + 5,00) / 3 = 4,91`.
Os dois 5,00 vinham da régua aplicada UMA vez sobre a % agregada: faturamento
da carteira 7,87% (> 5% → 5,00) e margem 13,51% (> 4% → 5,00).

Só que as 25 lojas dessa carteira pontuavam assim, uma a uma:

- faturamento: 5 lojas com 1 · 2 com 2 · 4 com 3 · 1 com 4 · 13 com 5 → média **3,60**
- margem: 3 lojas com 2 · 6 com 3 · 9 com 4 · 5 com 5 → média **3,70**

Cinco lojas em queda severa, e a carteira marcava 5/5 em crescimento. É a
demonstração mais limpa do vício que a mudança corrige — e o motivo de a régua
sobre a média ter sido abandonada.

Pelo método novo: `(3,60 + 3,70 + 4,72) / 3 = 4,0051`, contra 4,03 no
fechamento manual da equipe.

## Regressão pós-deploy corrigida

Seletor de mês da tela individual (`/performance/{user}`) ficou preso a um
único mês. `meses_disponiveis` do `show()` listava apenas competências
CONGELADAS, e em produção só 2026-06 estava consolidada. Enquanto existia o
toggle, o dropdown só precisava cobrir os fechados; sem ele, virou o único
controle de período. Passou a listar os últimos 6 meses, como as demais telas.
## Reconciliação com o fechamento manual (2026-08-06)

Cruzadas as 286 lojas da competência contra as 10 abas da planilha: **74
divergências**, separadas em dois grupos porque `BonusInvalidacao` é por
EMPRESA, não por profissional.

- **30 empresas invalidadas** — não contavam para ninguém no fechamento
  manual: lojas de teste, produto M.A.P, cliente novo/sem 30 dias, cliente em
  bônus de saída, e as ausentes da planilha. Duas delas (ARMONARE,
  EZIOFREDIANI) o usuário já havia invalidado pela tela; as outras 28 entraram
  por comando, com dry-run conferido antes.
- **16 empresas NÃO invalidadas** — contam para OUTRO profissional na
  planilha. Invalidar tiraria a loja de quem legitimamente a tem. Decisão do
  usuário: nesses casos **o sistema está certo** (a loja é atendida por dois,
  um por ML e outro por Shopee) e a planilha é que não reflete isso.

Depois de reconsolidar (exit 0, 11 profissionais, 219 linhas, 42 podadas):

| | antes | depois |
|---|---|---|
| erro médio vs planilha | 0,363 | **0,190** |
| carteiras com contagem de lojas idêntica à planilha | 3 | **5** |
| caso âncora (Ruben) | 4,01 | **4,03 — idêntico** |

A diferença que resta é o **NPS de loja sem link**, regra que o usuário
manteve deliberadamente.

### Incidente diagnosticado no caminho

Invalidar empresa pela tela de Auditoria **apaga** o snapshot mensal de todos
os profissionais vinculados a ela e **não regrava** — comportamento
documentado no controller, mas sem aviso na interface. As duas invalidações
feitas pela tela deixaram **três profissionais sem nota nenhuma** em junho
(resumo e detalhe zerados), o que só apareceu quando o universo caiu de 11/286
para 8/211. A reconsolidação trouxe os três de volta.

Regra registrada em `learnings/desempenho-bonificacao.md` §0.05: toda
invalidação pela tela exige `desempenho:consolidar-mes` em seguida.