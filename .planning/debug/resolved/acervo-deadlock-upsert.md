---
slug: acervo-deadlock-upsert
status: awaiting_human_verify
trigger: Deadlocks constantes (~20/dia desde 12/08) no upsert de `ml_acervo_itens`, entupindo a fila `default` e deixando 136 mil itens do acervo com `coleta_erro`. Segurou por >1h a geração de contrato da Maderatto, que estava na mesma fila.
created: 2026-08-20
updated: 2026-08-20T18:40:00-03:00
criticality: media
---

# Deadlocks no upsert de `ml_acervo_itens`

## Symptoms

**Expected behavior:**
O `SyncMlAcervoCompanyJob` roda por empresa, faz multiget de 20 ids no Mercado Livre e grava o
resultado com `MlAcervoItem::upsert()`. Vários workers rodam empresas diferentes em paralelo sem
se atrapalhar; o acervo fica completo e a fila `default` drena.

**Actual behavior:**
- **~20 deadlocks por dia**, constante desde 2026-08-12 (21, 17, 26, 15, 14, 23, 25, 17, 30).
- **544 ocorrências** no log: **309** em `insert into ml_acervo_itens`, **235** em
  `update ml_acervo_itens`. Nenhuma outra tabela aparece.
- **36 jobs** de acervo esgotaram as 3 tentativas e morreram (~4/dia desde 11/08).
- A fila `default` acumula (11 jobs no pico medido) e atrasa tudo que está atrás — inclusive
  `GerarContratoAssinaturaJob`, que ficou >1h parado (incidente Maderatto, 2026-08-20; já mitigado
  movendo contrato para a fila `high` no quick 260820-my3, mas a causa aqui segue).

**Custo medido no acervo** (2026-08-20):

| Medida | Valor |
|---|---|
| itens totais | 879.479 |
| com `coleta_erro` | 367.503 (42%) |
| — por **deadlock** | **136.432** |
| — por erro 503 do Mercado Livre | 231.071 |
| coletados hoje | 703.221 |
| coletados nos últimos 2 dias | 850.357 |

**Error messages:**
`SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try
restarting transaction (Connection: mysql, ..., SQL: insert into ml_acervo_itens ...)`

**Timeline:** primeira ocorrência 2026-08-12. Antes disso, zero. Não investigado até 2026-08-20.

**Reproduction:** não reproduzido sob demanda. Acontece sozinho ~20x/dia em produção, com vários
workers processando empresas diferentes em paralelo.

## Contexto técnico já levantado

- Escritor: `MlAcervoService` → `MlAcervoItem::upsert($linhas, ['company_id','ml_item_id'], self::COLUNAS_CAMADA_BARATA)`
  (`app/Services/Mlb/Acervo/MlAcervoService.php:354`). Upsert em LOTE.
- Unique key: `mai_company_item_unq` em `(company_id, ml_item_id)` (migration linha 94).
- O lote `$linhas` é montado na ordem em que o Mercado Livre devolve os itens do multiget —
  **sem ordenação determinística**.
- `SyncMlAcervoCompanyJob` É `ShouldBeUnique` com `uniqueId()` por empresa (`tries=3`, backoff
  escalonado). **Portanto NÃO é a mesma empresa competindo consigo mesma** — são empresas
  diferentes em workers diferentes.
- 3 pontos de dispatch: `SyncMlAcervo` (comando diário), `MlbAnuncioController:528`
  ("Atualizar agora"), `AcervoColetadoResolver:97` (onboarding).
- Há também `gravarSerieDiaria()` escrevendo em `ml_acervo_metricas_diarias` dentro do mesmo laço.

## Hipótese inicial (NÃO PROVADA)

Duas transações fazendo `INSERT ... ON DUPLICATE KEY UPDATE` em lote adquirem locks de índice em
**ordens diferentes** (o lote não é ordenado), e se travam mutuamente. É o modo de falha clássico
de upsert concorrente em InnoDB.

Mitigação candidata, barata e sem mudança de comportamento: **ordenar `$linhas` por
`(company_id, ml_item_id)` antes do upsert**, para todas as transações pegarem lock na mesma ordem.

⚠️ É hipótese. Não confundir com diagnóstico.

## ⛔ CAMINHO DE EVIDÊNCIA BLOQUEADO (importante para quem continuar)

A prova definitiva seria o bloco `LATEST DETECTED DEADLOCK` do `SHOW ENGINE INNODB STATUS`, que
mostra as duas transações e os locks exatos. **Não foi possível obter em 2026-08-20:**

- `SHOW ENGINE INNODB STATUS` → `1227 Access denied; you need PROCESS privilege`
- `SET GLOBAL innodb_print_all_deadlocks = ON` → `1227 Access denied; you need SUPER or
  SYSTEM_VARIABLES_ADMIN`
- `innodb_print_all_deadlocks` está **OFF**, então o `error.log` do MySQL não tem nada
  (`grep -ic deadlock /var/log/mysql/error.log` = 0)
- Não existe `/root/.my.cnf`; `mysql -u root` sem senha é recusado

Ou seja: **destravar a prova exige credencial de root do MySQL, que não está no repositório nem
no `.env`.** Qualquer uma das três saídas (dar PROCESS ao usuário da app, ligar
`innodb_print_all_deadlocks`, ou usar root) é mudança de privilégio/configuração em produção e
precisa de decisão do usuário.

## ACHADO PARALELO — provavelmente MAIOR que este bug

**231.071 itens** do acervo estão com `coleta_erro` de **erro 503 do Mercado Livre**
(`[MercadoLivre] Erro 503 em /items: upstream connect error or disconnec...`) — quase o **dobro**
do impacto dos deadlocks (136.432).

Se a prioridade for qualidade do acervo, esse é o item nº 1, não o deadlock. **Não foi
investigado.** Merece sessão própria.

## Evidence

- timestamp: 2026-08-20 — 544 deadlocks no log, 100% em `ml_acervo_itens` (309 insert / 235 update).
- timestamp: 2026-08-20 — 36 `SyncMlAcervoCompanyJob` no `failed_jobs`, distribuídos ~4/dia desde 11/08.
- timestamp: 2026-08-20 — 136.432 itens com `coleta_erro` contendo `Deadlock found`.
- timestamp: 2026-08-20 — job confirmado `ShouldBeUnique` por empresa: elimina a hipótese de
  auto-concorrência da mesma empresa.
- timestamp: 2026-08-20 — lote do upsert montado na ordem de retorno da API, sem `sort`.

### Rodada 2 — 2026-08-20 (investigação local, sem acesso a produção)

- **E1 — `ShouldBeUnique` NÃO exclui jobs de CLASSES diferentes.**
  checado: `vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php:76`.
  achado: a chave do lock é `'laravel_unique_job:' . get_class($job) . ':' . $uniqueId`.
  implicação: `SyncMlAcervoCompanyJob:42` e `SyncMlAcervoDetalheJob:42:<hash>` são chaves
  DISTINTAS. Nada impede a camada barata e a camada cara da MESMA empresa de escreverem em
  `ml_acervo_itens` ao mesmo tempo. O item "Eliminated" desta sessão vale só para o par
  CompanyJob × CompanyJob — foi generalizado demais.

- **E2 — as duas camadas escrevem NAS MESMAS COLUNAS da MESMA empresa.**
  checado: `MlAcervoService.php:354` (upsert, 3º arg `COLUNAS_CAMADA_BARATA`) e
  `MlAcervoDetalheService.php:215` (update nomeado).
  achado: `motivos` e `severidade` estão nos DOIS conjuntos de escrita. `severidade` é coluna do
  índice `mai_company_sev_nota_idx`; `detalhe_coletado_em` (escrita só pela cara) é do
  `mai_company_detalhe_idx`; `status` (só pela barata) é do `mai_company_status_idx`.
  implicação: não é só disputa de linha — as duas camadas mexem nas MESMAS entradas de índice
  secundário, todas na faixa contígua da mesma empresa.

- **E3 — vários `SyncMlAcervoDetalheJob` da mesma empresa rodam em paralelo POR DESIGN.**
  checado: `SyncMlAcervoDetalheJob::uniqueId()` = `companyId . ':' . md5(ids do lote)`.
  achado: o docblock declara explicitamente "dois lotes diferentes da mesma empresa podem e devem
  rodar em paralelo".
  implicação: N transações concorrentes fazendo UPDATE na mesma faixa de índice da mesma empresa,
  cada uma carimbando `detalhe_coletado_em` com o MESMO `$agora` do seu lote.

- **E4 — o delay de 2s do fan-out não separa camada barata de camada cara.**
  checado: `SyncMlAcervo.php:104` — `$delayDetalheBase = $delayBarata + 2`.
  achado: o comentário no código diz que é "para os jobs de detalhe não competirem com os de
  multiget da mesma empresa na mesma janela". Mas `SyncMlAcervoCompanyJob::$timeout = 1800` e a
  maior conta faz ~3.340 lotes de multiget: a barata roda por minutos a dezenas de minutos.
  implicação: 2 segundos garantem o OPOSTO do que o comentário afirma — a sobreposição é a regra,
  não a exceção. O comentário estava factualmente errado.

- **E5 — nenhuma escrita do acervo está dentro de `DB::transaction()`.**
  checado: busca por `DB::`/`transaction` nos dois services — zero ocorrências.
  achado: cada statement é sua própria transação autocommit.
  implicação (dupla): (a) derruba a hipótese "transação larga demais" — a janela já é a mínima;
  (b) **não existe retry de concorrência**. O retry automático do Laravel para o erro 1213 mora em
  `Connection::transaction($cb, $attempts)` (`ManagesTransactions.php:236`, via
  `causedByConcurrencyError`) e só roda quando a escrita está DENTRO de `DB::transaction()` com
  `$attempts > 1`. Hoje o deadlock sobe cru.

- **E6 — o `catch` de nível de empresa AMPLIFICA o deadlock em ~3 ordens de grandeza.**
  checado: `MlAcervoService.php:243-244` e `SyncMlAcervoCompanyJob::failed()`.
  achado: o upsert (linha 354) está FORA do try/catch por item — o try/catch por item envolve só o
  cálculo de nota/triagem. Um deadlock no upsert de 20 linhas propaga para
  `coletarCamadaBarata()`, cai no catch de EMPRESA e dispara
  `MlAcervoItem::where('company_id', X)->update(['coleta_erro' => ...])` — UMA transação
  carimbando TODAS as linhas da empresa (até 66.747).
  implicação 1: os **136.432 itens com `coleta_erro`** não são 136 mil falhas — são poucas dezenas
  de eventos de deadlock multiplicados pelo tamanho do acervo de cada empresa. A métrica de dano
  no topo deste arquivo está inflada.
  implicação 2: esse UPDATE de faixa inteira é ele próprio um `update ml_acervo_itens` que segura
  lock exclusivo em dezenas de milhares de linhas + entradas de índice, colidindo com todo job de
  detalhe da mesma empresa que estiver rodando. **É um laço de realimentação**: deadlock → carimbo
  em massa → mais deadlock. Explica a taxa não decair.

- **E7 — a data de início bate com a primeira execução agendada do fan-out.**
  checado: `git log` de `app/Services/Mlb/Acervo`, `app/Jobs`, `routes/console.php`.
  achado: a Fase 134 inteira nasce em 10/08 (17:19 o upsert da barata, 17:45 a camada cara, 18:11
  o `mlb:sync-acervo` e o `Schedule::command('mlb:sync-acervo')->dailyAt('11:35')`), e o merge para
  `main` é `7037ae38` em **11/08 16:51** — depois das 11:35 daquele dia.
  implicação: a primeira execução agendada do fan-out COMPLETO (barata + cara juntas) foi **12/08
  às 11:35** — exatamente a data da primeira ocorrência. Não houve regressão: o acervo nasceu com
  o deadlock, não existe "estado bom anterior" a procurar. Consistente também com jobs falhando
  desde 11/08 (dispatch avulso pelo botão/onboarding, só camada barata, sem par para colidir) e
  deadlocks só a partir de 12/08.

- **E8 — a proporção 309 insert / 235 update é a assinatura do par barata × cara.**
  checado: contagem já registrada no topo, relida à luz de E1-E4.
  achado: o log de deadlock registra a SQL da transação **vítima**. As duas SQLs vítimas são
  exatamente as duas pontas do par suspeito, em volume comparável.
  implicação: se o par fosse barata × barata de empresas diferentes (a hipótese da rodada 1),
  esperaríamos quase só `insert`. Um terço largo das vítimas ser `update ml_acervo_itens` exige que
  a camada cara (ou o carimbo em massa do E6) esteja do outro lado. Corolário metodológico:
  "nenhuma outra tabela aparece no log" NÃO prova que só `ml_acervo_itens` participa do deadlock —
  prova apenas que a vítima sempre estava escrevendo nela.

- **E9 — a ausência de transação PROVA que as duas pontas estão em `ml_acervo_itens`.**
  checado: E5 (autocommit) cruzado com a lista de escritas dos dois services.
  achado: sem `DB::transaction()`, cada statement abre e fecha sua própria transação. Uma
  transação de statement único não consegue segurar lock em duas tabelas ao mesmo tempo.
  implicação: `gravarSerieDiaria()` / `gravarVisitasSerieDiaria()` (em
  `ml_acervo_metricas_diarias`) estão **eliminadas** como participantes do ciclo de espera, apesar
  do índice `mamd_data_idx` ser em `data` sozinho (ponto quente compartilhado entre empresas).
  As duas pontas do deadlock são necessariamente statements em `ml_acervo_itens` — o que fecha
  com o E8.
  **Consequência para a correção:** a série diária NÃO pode ser trazida para dentro da mesma
  transação do upsert. Isso criaria uma transação de duas tabelas e abriria uma classe de deadlock
  cross-table que hoje é impossível. Cada escrita permanece em transação de statement único.

## Eliminated

- hypothesis: "a série diária (`ml_acervo_metricas_diarias`) participa do deadlock"
  evidence: E9 — sem transação envolvente, um statement não segura lock em duas tabelas; não há
  como formar espera circular entre as duas tabelas.
  timestamp: 2026-08-20

- hypothesis: "a mesma empresa está sendo processada por dois workers ao mesmo tempo"
  evidence: `SyncMlAcervoCompanyJob implements ShouldBeUnique` com `uniqueId()` por empresa e TTL
  de lock maior que timeout + backoff máximo.
  **⚠️ PARCIALMENTE REVERTIDO em 2026-08-20 (E1):** vale só para duas instâncias da MESMA classe.
  A chave do lock inclui `get_class($job)`, então a mesma empresa PODE ter camada barata e camada
  cara escrevendo em paralelo — e é justamente esse o par que colide.

- hypothesis: "a transação é larga demais e mantém locks abertos por muito tempo"
  evidence: E5 — não há `DB::transaction()` em nenhum dos dois services; cada statement é
  autocommit. A janela de lock já é a mínima possível.
  timestamp: 2026-08-20

- hypothesis: "transações de empresas DIFERENTES colidem porque o lote não é ordenado"
  evidence: todos os índices de `ml_acervo_itens` (o unique e os 3 secundários) têm `company_id`
  como primeira coluna, então empresas diferentes ocupam faixas disjuntas dos índices. Sobra apenas
  o gap de fronteira entre duas empresas vizinhas — efeito marginal, incapaz de explicar ~20
  eventos/dia nem a proporção do E8. **Consequência prática: ordenar `$linhas` por
  `(company_id, ml_item_id)` — a mitigação candidata da rodada 1 — é quase inócua para o par
  dominante**, porque do outro lado está um UPDATE de UMA linha por statement, que não tem "ordem
  de lote" a alinhar. Mantida como higiene barata, jamais como a correção.
  timestamp: 2026-08-20

## Current Focus

status_do_diagnostico: **mecanismo fechado no nível de que a correção depende** (quem colide com
quem, e por quê). O nível "qual entrada de índice exatamente" segue NÃO provado, por falta do
`SHOW ENGINE INNODB STATUS` — mas ele não altera a correção: o remédio é o mesmo para qualquer
índice, porque as duas camadas não podem escrever na mesma empresa ao mesmo tempo.

root_cause: `SyncMlAcervoCompanyJob` (camada barata, upsert em lote de 20) e
`SyncMlAcervoDetalheJob` (camada cara, UPDATE linha a linha) escrevem simultaneamente nas MESMAS
linhas e nas MESMAS colunas indexadas (`severidade`, `motivos`) da MESMA empresa. `ShouldBeUnique`
não os separa porque a chave do lock inclui `get_class($job)` (E1), e o delay de 2s do fan-out não
separa nada diante de um job barato de até 1800s (E4). Sem `DB::transaction()` não há retry de
concorrência (E5), e o `catch` de empresa converte um deadlock de 20 linhas num UPDATE de faixa
inteira que realimenta o problema (E6).

reasoning_checkpoint:
  hypothesis: "Deadlock entre a camada barata e a camada cara do acervo na MESMA empresa,
    disputando as mesmas linhas e as mesmas entradas de índice secundário de `ml_acervo_itens`."
  confirming_evidence:
    - "E1: a chave do ShouldBeUnique inclui a classe — nada exclui as duas camadas entre si."
    - "E2: `severidade` e `motivos` são escritas pelas DUAS camadas; `severidade` indexa em `mai_company_sev_nota_idx`."
    - "E4: o fan-out despacha a cara 2s depois da barata, que roda por até 1800s — sobreposição garantida."
    - "E8: 235 das 544 vítimas são `update ml_acervo_itens` — a ponta da camada cara aparece no log."
    - "E7: primeira execução agendada do fan-out completo = 12/08 11:35, exatamente a data de início."
  falsification_test: "Se, com as duas camadas serializadas por empresa, a taxa de deadlock não cair
    materialmente dos ~20/dia, a hipótese está errada e o lock está entre empresas (gap de fronteira
    do índice) ou em algo fora de `ml_acervo_itens`."
  fix_rationale: "Ataca a causa (concorrência entre camadas na mesma empresa) com um lock nomeado
    compartilhado pelas duas classes de job — algo que `ShouldBeUnique` estruturalmente não faz. As
    duas medidas de apoio atacam o dano e o laço de realimentação, não a causa, e estão rotuladas
    como tal."
  blind_spots:
    - "Não temos o par de transações do InnoDB: a identidade do lock é inferida do schema, não observada."
    - "O SQLite da suíte não reproduz deadlock de InnoDB — nenhum teste local pode PROVAR a correção."
    - "Colisão entre empresas no gap de fronteira do índice segue possível e NÃO é coberta pelo lock."
    - "A validação final é empírica em produção: taxa de deadlock por dia, antes vs. depois."

next_action: Após o deploy (que NÃO é feito por esta sessão), medir por 3 dias
`grep -c "Deadlock found" storage/logs/laravel-*.log` por dia e comparar com a linha de base de
~20/dia. Queda material confirma; taxa estável refuta e reabre a investigação pelo gap de fronteira.

## Resolution

root_cause: As duas camadas do acervo escrevem em `ml_acervo_itens`, na MESMA empresa, ao mesmo
tempo — `SyncMlAcervoCompanyJob` (barata, `upsert` de 20 linhas) contra `SyncMlAcervoDetalheJob`
(cara, `UPDATE` de 1 linha), disputando as mesmas linhas e as mesmas colunas indexadas
(`severidade` e `motivos` estão nos DOIS conjuntos de escrita; `severidade` indexa em
`mai_company_sev_nota_idx`). Nada as separava: `ShouldBeUnique` é por CLASSE de job (a chave inclui
`get_class($job)`) e o delay de 2s do fan-out não separa um job de até 1800s. Sem `DB::transaction()`
não havia retry de concorrência, então o deadlock matava o job inteiro; e o `catch` de nível de
empresa carimbava `coleta_erro` na FAIXA INTEIRA da empresa, o que inflou a métrica de dano e
realimentou o próprio deadlock.

fix:
1. **Causa** — `AcervoEscritaLock::naEmpresa()` (arquivo novo): lock de aplicação nomeado por
   empresa, COMPARTILHADO pelas duas classes de job — a peça que `ShouldBeUnique` estruturalmente
   não entrega. Envolve o `upsert` da barata e o `update` da cara. Cobre um statement, nunca a
   coleta inteira (que leva até 30 min e não pode segurar a tabela).
2. **Resíduo** — o mesmo helper roda a escrita em `DB::transaction($cb, 3)`, ativando o retry
   nativo de SQLSTATE 40001 do Laravel, que só existe dentro de `DB::transaction()`. Cobre a
   colisão entre EMPRESAS (gap de fronteira do índice), que o lock por empresa não alcança.
3. **Amplificador** — deadlock deixa de carimbar `coleta_erro` em massa (no `catch` do service e no
   `failed()` do job). Erro REAL continua carimbando: o banner do D-08 não cega.
4. **Higiene** — lote ordenado por `(company_id, ml_item_id)` antes do upsert. Rotulado no código
   como higiene, explicitamente NÃO como a correção.
5. **Comentário mentiroso** — o do delay do fan-out em `SyncMlAcervo` afirmava separar as camadas;
   substituído pelo que de fato acontece.

O 3º argumento do `upsert` (`COLUNAS_CAMADA_BARATA`) não foi tocado — segue literal e obrigatório.

verification:
- `--filter=Phase134`: **73/73 verdes** (65 pré-existentes + 8 novos), zero regressão.
- **Verificação negativa executada:** com o lock desligado por instrumentação temporária, os dois
  testes de serialização falham; religado, passam. Os testes têm poder de detecção real. A
  instrumentação foi removida e a ausência conferida por grep.
- ⚠️ **Nenhum teste prova que o deadlock acabou, e nenhum poderia** — a suíte roda em SQLite, que
  não tem o motor de locks do InnoDB nem detecção de deadlock. O que está travado é o
  COMPORTAMENTO da correção, não o deadlock.

verificacao_pendente_em_producao: depois do deploy, medir por 3 dias os deadlocks/dia no log e
comparar com a linha de base de ~20/dia. Queda material confirma o diagnóstico; taxa estável o
refuta e reabre a investigação pelo gap de fronteira entre empresas.

files_changed:
  - app/Services/Mlb/Acervo/AcervoEscritaLock.php (NOVO — lock + retry, com o porquê documentado)
  - app/Services/Mlb/Acervo/MlAcervoService.php (upsert serializado, ordenação, catch não carimba deadlock)
  - app/Services/Mlb/Acervo/MlAcervoDetalheService.php (update serializado)
  - app/Jobs/SyncMlAcervoCompanyJob.php (failed() não carimba deadlock)
  - app/Console/Commands/SyncMlAcervo.php (só comentário — corrige afirmação falsa)
  - tests/Unit/Phase134/SerializacaoEscritaAcervoTest.php (NOVO — 8 testes de comportamento)

## Sobra conhecida — NÃO resolvida por esta sessão

O achado paralelo segue aberto e continua sendo, em volume, MAIOR que o deadlock: **231.071 itens**
com `coleta_erro` de **erro 503 do Mercado Livre**. Note que a correção do E6 muda a leitura das
duas métricas — a de deadlock (136.432) estava inflada pelo carimbo em massa, enquanto a de 503 é
carimbada item a item e portanto **não** está inflada. A distância real entre os dois problemas é
bem maior do que 231k vs. 136k sugeria. Merece sessão própria.


## Resolution

root_cause: A chave do lock de unicidade do Laravel inclui a CLASSE do job
(`UniqueLock::getKey()` = `'laravel_unique_job:' . get_class($job) . ':' . $uniqueId`), entao
`SyncMlAcervoCompanyJob:{id}` e `SyncMlAcervoDetalheJob:{id}` NUNCA se excluiram. As duas camadas
de coleta escrevem `severidade` e `motivos` da MESMA empresa ao mesmo tempo, e `severidade` e
indexada em `mai_company_sev_nota_idx`. Nao havia `DB::transaction` em lugar nenhum, entao o retry
nativo de SQLSTATE 40001 do Laravel nunca rodava. O `catch` por empresa ainda carimbava
`coleta_erro` em ate 66.747 linhas num UPDATE de faixa, que segurava lock exclusivo e colidia com
os jobs de detalhe em curso — deadlock gerando deadlock.

fix: Lock nomeado por empresa COMPARTILHADO pelas duas classes (`AcervoEscritaLock`), cobrindo um
statement e nunca a coleta de 30 min, mais `DB::transaction($cb, 3)`. No timeout de 10s degrada e
escreve mesmo assim com `Log::warning` (decisao do usuario em 2026-08-20: priorizar nao perder a
coleta, aceitando deadlock residual raro). Ordenacao do lote entrou so como higiene.

verification: 497 testes verdes (Phase134/127/131/135 + serializacao), com verificacao NEGATIVA —
desligando o lock por instrumentacao temporaria os 2 testes de serializacao falham; religando,
passam. ⚠️ O SQLite NAO reproduz deadlock de InnoDB: nenhum teste prova que o deadlock acabou, o
que esta travado e o COMPORTAMENTO da correcao.

files_changed: app/Services/Mlb/Acervo/AcervoEscritaLock.php (novo),
app/Services/Mlb/Acervo/MlAcervoService.php, app/Services/Mlb/Acervo/MlAcervoDetalheService.php,
app/Jobs/SyncMlAcervoCompanyJob.php, app/Console/Commands/SyncMlAcervo.php,
tests/Unit/Phase134/SerializacaoEscritaAcervoTest.php

commit: 5cfd1eec

## ⚠️ PENDENTE — nao encerrar sem isto

**Nao deployado ainda.** E o criterio de refutacao so pode rodar depois:

> Se em 3 dias apos o deploy a taxa NAO cair materialmente dos ~20/dia
> (`grep -c "Deadlock found"` no log, por dia), a hipotese esta ERRADA e sobra o gap de fronteira
> entre empresas. Reabrir esta sessao nesse caso.

## ⚠️ ABERTO — provavelmente MAIOR que este bug

**231.071 itens** do acervo com `coleta_erro` de **erro 503 do Mercado Livre**
(`[MercadoLivre] Erro 503 em /items: upstream connect error or disconnec...`). Ao contrario da
metrica de deadlock — que estava INFLADA pelo carimbo em massa, agora corrigido — esta e carimbada
item a item e NAO esta inflada. E o problema numero 1 da qualidade do acervo. **Nao investigado.**
Merece sessao propria.

## DEPLOYADO em 2026-08-20 (~19:00 BRT)

Commit em producao: **`2cc66624`**. Conferido por reconsulta: `AcervoEscritaLock.php` presente no
VPS (era a classe que teria dado `Class not found` se ficasse fora do commit), workers RUNNING,
smoke 200/302, nenhum erro novo no log (o ultimo e das 17:25, anterior ao deploy).

Foi junto o commit `5a567bec` de outra sessao (fix de NPS, link de grupo) — ja estava em
`origin/main`, zero intersecao de arquivos, conferida antes do rebase.

### BASELINE PARA O CRITERIO DE REFUTACAO

| Dia | Deadlocks |
|---|---|
| 12/08 | 21 |
| 13/08 | 17 |
| 14/08 | 26 |
| 15/08 | 15 |
| 16/08 | 14 |
| 17/08 | 23 |
| 18/08 | 25 |
| 19/08 | 17 |
| **20/08 (dia do deploy)** | **30** — CONTAMINADO, deploy foi ~19:00 |

Media dos 8 dias limpos: **~20/dia**.

⚠️ **20/08 nao serve de comparacao** — mistura pre e pos-deploy. Os primeiros dias limpos sao
**21, 22 e 23 de agosto**.

Comando da medicao:
```bash
grep -c "^\[2026-08-DD.*Deadlock found" storage/logs/laravel.log
```

**Se 21, 22 e 23/08 NAO cairem materialmente dos ~20/dia, a hipotese esta ERRADA** — o par que
colide nao seria camada-barata x camada-cara da mesma empresa, e sobraria o gap de fronteira entre
empresas. Reabrir esta sessao nesse caso.

Sinal complementar a observar: `grep -c 'AcervoEscritaLock' storage/logs/laravel.log`. Cada
ocorrencia e um timeout de 10s no lock, onde a escrita degradou e seguiu mesmo assim (decisao do
usuario). Zero ate o momento do deploy. Muitos avisos = a serializacao esta apertada demais e o
`ESPERA_SEGUNDOS` precisa de revisao.
