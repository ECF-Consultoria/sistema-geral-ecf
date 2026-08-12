---
phase: 136
plano: 07
tipo: gate-de-regressao + verificacao-humana
executado_em: 2026-08-12
executado_por: sessao Claude Code (orquestrador), ambiente localhost
head_na_verificacao: ae0f61fd
ambiente: "Windows + XAMPP (Apache :80, MariaDB 10.4.32), banco ecf_admin local"
veredito_automatizado: aprovado
veredito_humano: PENDENTE — aguarda leitura e aceite do responsavel
---

# Fase 136 — Gate de regressao e verificacao manual

Este documento fecha a Fase 136 (metricas manuais por empresa/mes no Desempenho).
Ele registra duas coisas separadas: o **gate de regressao automatizado**, que roda sozinho, e as
**quatro verificacoes que nenhum teste cobre**, feitas na tela e por reconsulta ao banco.

A fase mexe em numero que decide pagamento de bonus. `learnings/desempenho-bonificacao.md` §2
documenta uma pessoa que perdeu o bonus de junho **sem nenhuma mudanca de codigo**, por estar a
0,24 ponto percentual da fronteira. Um "passou nos testes" nao e suficiente para declarar isso
pronto — e §4 e explicito: conferencia e por reconsulta ao banco e por exit code, nunca por stdout.

---

## 1. Gate de regressao contra a BASELINE congelada

A referencia e `136-BASELINE-TESTES.md`, coletada no Plano 01 **antes** de qualquer arquivo de
aplicacao desta fase existir (HEAD `f71f8aa9`). Regressao se mede contra aquele numero, nunca
contra zero.

| Suite | BASELINE (antes) | Depois da fase | Veredito |
|---|---|---|---|
| Filtro das 5 suites de risco | 9 failed / 18 passed | **9 failed / 18 passed** | sem regressao |
| `--filter=Phase136` | (nao existia) | **60 passed / exit 0** | ok |
| `--filter=Phase119` (gate de hash) | 137 passed | **137 passed** | intacto |

Comando exato do gate (identico ao da baseline):

```
C:\xampp\php\php.exe artisan test --filter="CarteiraPeriodoDiffTest|DesempenhoPeriodoOficialTest|DesempenhoShopeeScoreTest|ConsolidarMesJanelaNpsTest|JanelaNpsBonusTest"
```

Os 9 nomes que falham foram conferidos **um a um** contra a lista do documento de baseline.
Nenhuma falha nova, nenhuma falha fora da lista.

### Limitacao conhecida: a suite Feature COMPLETA nao termina neste ambiente

`artisan test --testsuite=Feature` roda **1613 testes** e entao morre com:

```
Fatal error: Maximum execution time of 300 seconds exceeded
in vendor\guzzlehttp\guzzle\src\Handler\CurlFactory.php:695
```

Algum teste faz **HTTP real** (Guzzle/cURL) contra uma API externa que nao responde a partir
desta maquina, e o processo estoura o `max_execution_time`. O travamento ocorre logo apos
`Phase58\DashboardRoutesTest`, mas **nao reproduz isoladamente** — `Phase58\DashboardShellsBackendTest`
e `Phase60\BaselineRegressionTest` passam sozinhos (2 e 6 testes, exit 0). Depende de estado
acumulado da suite.

**Isto e divida pre-existente, nao regressao da Fase 136**: nenhum arquivo desta fase faz chamada
de rede em teste (a suite `Phase136` inteira roda sobre fonte `shopee`, leitura 100% local, e o
unico cenario Adman usa empresa sem `cust_id`, cujo dispatcher devolve shape vazio sem tocar rede).

Consequencia pratica: o `must_have` "a suite Feature completa esta verde" **nao pode ser
satisfeito neste ambiente**. A evidencia de nao-regressao passa a ser a tabela acima — as suites
de risco identificadas no Plano 01, mais as suites da propria fase e o gate de hash da Fase 119.
Fica registrado como pendencia de ambiente, nao da fase.

---

## 2. As quatro verificacoes humanas

Ambiente: Apache do XAMPP em `http://localhost/ecf_admin/public` (nao `artisan serve` — o
`ASSET_URL` aponta para o Apache, e sob `serve` o bundle nao carrega e o React nao monta).
Logado como `admin@ecfconsultoria.com.br` (user 2, role admin).

### 2.1 — A grade admin em uso ✅

`/desempenho/metricas-manuais`, competencia agosto/2026, 26 empresas, eixos `faturamento` e
`margem_cmv`, item de menu "Metricas manuais" visivel no grupo Gestao ECF.

| O que | Resultado |
|---|---|
| Celula sem valor manual e sem API aquecida | mostra `—` e "automatico" — **nunca `R$ 0,00`** |
| Toggle `manual` sem valor guardado | abre o input em vez de mandar vazio |
| Lancar `12.345,67` (Ale Pecas, faturamento) | flash "Valor manual lancado para esta competencia", celula em amarelo, toggle em `manual` |
| Sinal de divergencia | icone de alerta aparece quando o manual diverge da API |
| Reverter para `auto` | flash "Metrica revertida... o lancamento anterior foi preservado no historico"; linha de contexto passa a exibir "automatico · ultimo manual R$ 12.345,67" |
| Religar `manual` com valor preservado | religa direto, sem redigitar |
| Independencia dos eixos | mexer no faturamento nao alterou o CMV da mesma empresa |

Reconsulta ao banco apos cada passo (nunca por stdout):

```
apos lancar:    company=363 metrica=faturamento mes=2026-08-01 valor=12345.67 valor_anterior=NULL ativo=1 lancado_por=2
apos reverter:  ativo=false  valor=12345.67  valor_anterior=12345.67       <- D-02/D-12: linha e valor preservados
apos religar:   ativo=true   valor=12345.67
```

**Nota sobre um falso alarme:** depois do primeiro lancamento a tela exibiu "API: R$ 0,00", que e
justamente o texto que o desenho proibe quando nao ha dado. Conferido nos props: `api_valor: 0`
com `api_aquecida: true` — e **zero real** da API (loja Shopee sem faturamento em agosto), nao um
`null` disfarcado. O eixo CMV da mesma empresa, com `api_aquecida: false`, seguiu exibindo `—`.
A distincao funciona.

**Read-only de competencia consolidada:** nao foi possivel exercitar na tela neste ambiente —
a unica competencia consolidada localmente e junho/2026, que **nao aparece no seletor da grade**
(o seletor lista as competencias abertas). A trava esta coberta por teste automatizado
(`post em competencia consolidada devolve 422 e nao grava nada` e `competencia consolidada
continua listada e vem marcada como read only`, ambos verdes) e pela dupla camada
middleware + FormRequest. **Fica como item a conferir em producao.**

### 2.2 — O selo em `/performance/{user}` ✅

Competencia julho/2026 (fechada pelo calendario, `tem_detalhe_empresas: true`), user 21 (Felipe),
que tem Ale Pecas na carteira.

- O selo (icone de lapis) aparece **so** na coluna FATURAMENTO da Ale Pecas.
- **Nao** aparece na coluna MARGEM da mesma empresa (`margem_fonte: auto`).
- **Nao** aparece nas outras 29 empresas da carteira.
- Tooltip: *"Valor lancado manualmente: este numero veio de lancamento do administrador para a
  competencia, nao da API do marketplace"* — **sem o nome de quem lancou**, conforme D-04.
- O tooltip esta num `<span>`, nao no `<svg>` do icone. Este foi um desvio deliberado do Plano 05
  e ele era necessario: `title` em SVG nao e o `title` do HTML, e nenhum navegador o renderiza
  como tooltip. Sem o desvio o selo apareceria mudo.

`quality` da Ale Pecas conferido nos props (mes em curso e julho):

```
revenue_diff_source: "manual_mes_calendario"      <- identificador de D-EXC-01
faturamento_fonte:   "manual"
margem_fonte:        "auto"
```

Nenhum campo do `quality` carrega identidade de quem lancou.

**Observacao de comportamento (nao e defeito):** no **mes em curso** a tabela por empresa nao
renderiza — a tela exibe "Detalhe por empresa indisponivel... aparece so depois que o mes e
fechado". E a trava da Fase 109. O selo, portanto, so e visivel em competencia fechada.

### 2.3 — Efeito do CMV manual na nota de carteira so-Shopee ✅ (por reconsulta ao banco)

Cenario montado pela tela, empresa Ale Pecas (`fonte=shopee`):

| Competencia | Faturamento manual | CMV manual | Margem |
|---|---|---|---|
| julho/2026 | 100.000,00 | 60.000,00 | 40% |
| agosto/2026 | 120.000,00 | 60.000,00 | 50% |

Reconsulta a `desempenho_company_score_snapshots` (mes 2026-08-01) **depois** do warm:

```
fonte_financeira      = shopee
faturamento_atual     = 120000.00     anterior = 100000.00
margem_pct_atual      = 50.000000     anterior = 40.000000     var_pp = +10.000000
margem_pontos         = 5.00          <- NAO E NULL: este e o desbloqueio
faturamento_pontos    = 5.00          nps_pontos = 1.00 (piso, mes em curso)
componentes_presentes = 3             status = complete
nota_empresa          = 3.67          = (1.00 + 5.00 + 5.00) / 3
motivos               = []            <- "margem_nao_fornecida_shopee" desapareceu
quality               = faturamento_fonte: manual, margem_fonte: manual,
                        margin_diff_source: "manual_mes_calendario"
```

Contraprova, feita ao desfazer o cenario (lancamentos removidos + `cache:clear` + rewarm):

```
margem_pontos = NULL   componentes_presentes = 1   status = partial
motivos = ["faturamento_sem_baseline", "margem_nao_fornecida_shopee"]
quality = faturamento_fonte: auto, margem_fonte: auto
```

Sem o CMV manual a empresa fecharia em `componentes=1` e `status=partial`. Com ele, fecha em
`componentes=3`, `status=complete` e a margem entra na nota. A cascata de D-06 pegou a base do
mes anterior no CMV **manual** de julho, exatamente como especificado (nao ha fallback de API
para CMV).

**Ressalva de ambiente:** nenhuma loja Shopee do banco local tem faturamento vindo da API
(`api_aquecida && api_valor > 0` deu **0 empresas** em julho). Por isso o cenario usou os dois
eixos manuais. E um cenario legitimo — e justamente o caso de uso da fase — mas nao exercita o
caminho "faturamento da API + CMV manual". Esse caminho esta coberto por teste automatizado no
Plano 03 e **deve ser conferido em producao**.

### 2.4 — FIXMARG-03 medido por EXIT CODE ✅

```
artisan desempenho:verificar-consolidacao --mes=2026-06 --json
>>> EXIT CODE = 0
resumo: total_usuarios=11, total_inconsistencias=0, por_tipo=[]
```

Junho/2026 e a unica competencia consolidada no banco local (`origem=consolidar_mes`, 286 linhas).
Exit 0 depois de todas as mudancas da fase — inclusive D-10, o desempate de fonte financeira —
significa que a competencia congelada **nao foi afetada**. Nenhuma consolidacao foi disparada.

**Armadilha que quase produziu falso positivo, registrada de proposito:** rodado **sem** `--mes`,
o comando cai no mes anterior (julho) e devolve **exit 1 com 32 inconsistencias**
(`SEM_SNAPSHOT` 10, `LINHAS_ORFAS` 11, `ORIGEM_NAO_CONGELADA` 11). Isso **nao e regressao**: o
localhost nunca consolidou julho. Conferido por reconsulta:

```
desempenho_score_snapshots          -> so 2026-06-01 tem 11 linhas
desempenho_company_score_snapshots  -> 2026-06-01 | consolidar_mes | 286
                                       2026-07-01 | warm_cache     | 288
                                       2026-08-01 | warm_cache     | 288
```

`warm_cache` e cache quente, nao congelamento. E a condicao que o proprio ROADMAP da fase
descreve: julho esta fechado pelo calendario e nao consolidado, esperando o NPS coletado em
agosto. **Quem repetir este gate precisa passar `--mes` explicitamente.**

---

## 3. Bonus: relatorio de impacto (D-11) rodado contra o banco

```
artisan desempenho:relatorio-impacto-fonte --mes=2026-07
>>> EXIT CODE = 0
0 empresa(s) mudam de fonte financeira, 0 profissional(is) impactado(s), 0 celula(s) manual(is) ativa(s).
```

O Plano 06 nao conseguiu rodar isto — o MySQL local estava fora no momento da execucao dele.

**Este zero NAO e conclusivo para producao.** O RESEARCH da fase (A1) exige a reconciliacao
contra producao justamente porque o banco local ja mentiu sobre o `cust_id` de pelo menos uma
empresa conhecida. O comando existe para colocar o numero na mesa antes de qualquer decisao de
retroatividade — e essa decisao continua sendo humana e separada (D-11).

---

## 4. Estado do ambiente apos a verificacao

Tudo que foi fabricado para testar foi removido:

- `desempenho_metricas_manuais` -> **0 linhas** (as 4 celulas de teste da company 363 deletadas)
- `cache:clear` + `desempenho:warm-cache --user=21` rodados, snapshot de agosto de volta ao
  estado original (`margem_pontos=NULL`, `componentes=1`, `status=partial`)
- Nenhuma consolidacao disparada, nenhum deploy executado

**Detalhe que vale saber:** apagar lancamento manual **direto no banco** nao invalida o cache do
`compute()` — o snapshot seguiu mostrando `margem_pontos=5.00` ate rodar `cache:clear`. Nao e
defeito: a invalidacao existe no fluxo normal, pela tela. Quem mexer via SQL precisa limpar o
cache na mao.

---

## 5. Pendencias que so fecham em producao

1. **Rodar `desempenho:relatorio-impacto-fonte` contra producao** e reconciliar — o RESEARCH (A1)
   trata isto como condicao para considerar D-10 encerrado.
2. **Rodar `desempenho:verificar-consolidacao --mes=<competencia real>` em producao**, lendo o
   exit code, antes de qualquer consolidacao.
3. **Conferir o read-only de competencia consolidada na tela** — nao exercitavel no localhost.
4. **Conferir o caminho "faturamento da API + CMV manual"** — o localhost nao tem faturamento
   Shopee vindo da API.
5. **A migration `desempenho_metricas_manuais` ainda nao subiu contra o MariaDB de producao**
   (pendencia herdada do Plano 02). Ja aplicada no localhost, batch 77.
6. **Reconsolidacao de competencia fechada continua FORA DE ESCOPO** (D-11). Se for feita, e ato
   separado, deliberado e com backup previo em `storage/app/private/backups/desempenho/`.

---

## 6. Veredito

**Automatizado: aprovado.** Zero regressao contra a baseline congelada; as tres verificacoes
exercitaveis no localhost passaram, duas delas por reconsulta ao banco e uma por exit code.

**Humano: PENDENTE.** Este documento e o que se submete ao responsavel. Os itens da secao 5
precisam de decisao ou de acesso a producao, e a fase mexe em numero que decide bonus — nada
aqui autoriza deploy.
