---
slug: acervo-deadlock-upsert
status: investigating
trigger: Deadlocks constantes (~20/dia desde 12/08) no upsert de `ml_acervo_itens`, entupindo a fila `default` e deixando 136 mil itens do acervo com `coleta_erro`. Segurou por >1h a geração de contrato da Maderatto, que estava na mesma fila.
created: 2026-08-20
updated: 2026-08-20
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

## Eliminated

- hypothesis: "a mesma empresa está sendo processada por dois workers ao mesmo tempo"
  evidence: `SyncMlAcervoCompanyJob implements ShouldBeUnique` com `uniqueId()` por empresa e TTL
  de lock maior que timeout + backoff máximo.

## Current Focus

hypothesis: Upsert em lote não ordenado faz transações concorrentes adquirirem locks de índice em ordens diferentes, causando deadlock em `mai_company_item_unq` / PK.
test: Ordenar `$linhas` por `(company_id, ml_item_id)` antes de `MlAcervoItem::upsert()` e medir a taxa de deadlock por dia depois.
expecting: Queda material nos ~20/dia. Se NÃO cair, a hipótese está errada e o lock está em outro lugar (série diária, PK auto-inc, ou o `update` nomeado da linha 244).
next_action: Decidir com o usuário se libera a evidência do InnoDB (privilégio/config em produção). Sem ela, a validação é empírica: aplicar a ordenação e medir a taxa antes/depois.
