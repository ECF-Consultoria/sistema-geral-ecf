# Débora Lima cai em "sem carteira" na consolidação mensal, mas tem carteira real

**Criado:** 2026-07-28
**Criticidade:** média — não nega bônus hoje, mas some do registro oficial da competência
**Descoberto em:** deploy da Fase 117 / aplicação do backfill de NPS da Fase 116

## O sintoma

`php artisan desempenho:consolidar-mes --mes=2026-06` reporta consistentemente:

```
[Desempenho Mensal] Consolidando mês 2026-06 — 12 users elegíveis.
[Desempenho Mensal] Mes 2026-06 — OK: 11 · Falhas: 0 · Sem carteira: 1 · Degradados: 0
```

Reproduzido duas vezes (uma via `nps:materializar-nao-respondidos`, outra direto). Sempre 11 de 12.

Consulta ao banco confirma: existem **11 linhas** em `desempenho_score_snapshots` com
`mes_referencia = 2026-06-01`, e **Débora Lima (user_id 12) não é uma delas**.

## Por que parece errado

Débora tem carteira real:

| Empresa | role | servico_id | contratos ativos |
|---|---|---|---|
| CARAIBAALUMINIO alumen | estrategista | 6 | 2 de 2 |
| ByMobille - Teste | estrategista | 6 | 2 de 2 |
| Lenonn Milani | estrategista | 6 | 1 de 1 |
| LOJA SHEEP | estrategista | 6 | 1 de 1 |
| MILANILENONN | estrategista | 6 | 1 de 1 |

São 5 vínculos, todos com `servico_id = 6` (Gestão — setor performance, portanto
elegível a financeiro) e **todos com contrato ativo**.

Mais: o caminho **diário** calcula a nota dela sem problema — há snapshots com
`mes_referencia` nulo e `ref_date` de 11, 12 e 13/07, todos com `score = 77`,
`classificacao = sem_bonus`. Ou seja, o motor consegue montar o universo dela no diário,
mas não no mensal.

## Diagnóstico descartado

O comando `nps:materializar-nao-respondidos` emitiu o aviso:

> "motivo provável: cobertura de margem < 0,7 (gate FIXMARG-03 ...)"

**Esse palpite está errado.** Se fosse FIXMARG-03, o contador seria `Degradados`, e ele
está em `0`. O contador que sobe é `Sem carteira`. O texto do aviso deveria diferenciar os
dois casos — hoje ele assume FIXMARG-03 para qualquer divergência, o que manda quem for
investigar para o lado errado (mandou a mim).

## Impacto

Baixo **hoje**: a faixa prevista dela para 2026-06 é `sem_bonus` tanto antes quanto depois
do backfill de NPS, então nenhum bônus está sendo negado. Mas ela **não aparece** no
registro congelado da competência — some do ranking oficial e do Relatório de Bonificação
de junho.

Se a carteira dela mudar de composição num mês futuro e ela passar a bater faixa, o mesmo
bug a deixaria de fora do pagamento.

## Onde investigar

1. `DesempenhoScoreService::computeUniverso` / `CarteiraContextService::forUser` — por que
   o universo mensal fica vazio para o user 12 e o diário não.
2. A diferença provável está na **janela de período**: o mensal usa competência fechada
   (`previous_equal_length_window` sobre 2026-06) e o diário usa o mês em curso. Se a
   resolução de vínculo ativo considerar data de contrato/vínculo, algo em junho pode
   estar excluindo os 5 vínculos.
3. Conferir se `company_users` tem coluna de vigência que o mensal respeita e o diário não.

## Correção separada (aviso enganoso)

Em `NpsMaterializarNaoRespondidos::conferirSnapshotsReconsolidados()`, distinguir
`Degradados > 0` (FIXMARG-03) de `Sem carteira > 0` (universo vazio) na mensagem de
atenção, em vez de atribuir tudo à cobertura de margem.
