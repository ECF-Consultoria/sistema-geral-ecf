# "Empresas da carteira" some da tela — duas causas que se somam

Investigado em 2026-08-24, em produção. Leitura obrigatória antes de mexer em
`desempenho_company_score_snapshots`, no `CompanyScoreSnapshotWriter`, no
`desempenho:warm-cache` ou no `desempenho:consolidar-mes`.

Complementa [`desempenho-bonificacao.md`](desempenho-bonificacao.md) — nada aqui
é dedutível do código: uma das causas é **estado do banco de produção**, a outra
é uma discrepância entre duas linhas que parecem concordar.

---

## 1. Abrir a tela é o que apaga a lista

Sintoma: a seção "Empresas da carteira" aparecia para uns profissionais e não
para outros na MESMA competência, e sumia de quem já tinha a lista. Parecia dado
faltando; era dado **deletado**.

A cadeia:

1. `DesempenhoScoreService::compute()` **sempre** define a chave
   `empresas_score` — vazia quando `incluirEmpresasScore` é `false`
   (`'empresas_score' => $incluirEmpresasScore ? ... : []`).
2. `cacheKey()` **não** distingue esse flag. Toda leitura interativa
   (`/performance/{user}`, o ranking, o Portfolio) chama sem o flag e grava esse
   payload vazio. Competência fechada tem TTL de **7 dias**.
3. O warm lê a mesma chave. O guard que existia para esse caso testava
   `! array_key_exists('empresas_score', $resultado)` — que **nunca dispara**,
   porque a chave existe. Não recomputava.
4. `CompanyScoreSnapshotWriter::sync()` com coleção vazia **apaga todas as
   linhas** do par (user, competência) — contrato deliberado da D-122-03,
   provado em `CompanyScoreSnapshotWriterTest`. O warm seguinte (≤ 8 min)
   deletava o detalhe já gravado.

Ou seja: bastava alguém abrir a tela de um profissional com o cache frio para a
lista dele sumir minutos depois. Corrigido em `2aba315e` — o guard passou para
dentro de `computeCached()` e testa lista VAZIA com carteira não-vazia; o warm
nunca mais chama `sync()` com lista vazia nesse caso.

**Competência congelada por `consolidar_mes` é imune** — a trava de congelamento
do writer retorna antes da poda. Só competência não consolidada corre esse risco,
o que liga esta causa diretamente à segunda.

## 2. `consolidar-mes` quebrado desde julho/2026 — índice legado vivo em produção

A migration `2026_07_09_140001_alter_desempenho_score_snapshots_add_mes_referencia`
troca a UNIQUE `(user_id, ref_date)` pela nova `(user_id, ref_date,
mes_referencia)`, com o `dropUnique` dentro de um `try/catch` silencioso. **O
drop nunca funcionou** — em produção E no banco local os dois índices convivem:

```
desempenho_score_snapshots_user_id_ref_date_unique   (user_id, ref_date)      ← legado, VIVO
desempenho_score_snapshots_user_ref_mes_unique       (user_id, ref_date, mes_referencia)
```

(Causa provável do drop falhar: MariaDB recusa dropar o índice que sustenta a FK
de `user_id` enquanto não existe outro cobrindo a coluna — e o novo unique só é
criado DEPOIS do drop.)

Com o legado vivo, `consolidar-mes` colide com a linha **diária** do dia 1º da
competência (`mes_referencia = NULL`, mesmo `(user_id, ref_date)`):

```
[Desempenho Mensal] Falha user 15 (Danilo) mês 2026-07: SQLSTATE[23000] ...
Duplicate entry '15-2026-07-01' for key 'desempenho_score_snapshots_user_id_ref_date_unique'
```

Três tentativas registradas (20, 21 e 22/08), 8 de 9 profissionais falharam. O
único que passou não tinha linha diária em 01/07. Como o `sync()` do detalhe por
empresa só roda **depois** do `updateOrCreate` bem-sucedido (D-122-06), a
competência ficou sem congelamento E sem detalhe.

**Junho escapou por acidente de calendário**, não por estar correto: a tabela
nasceu em `2026_06_30`, então não existe linha diária de 01/06. Não há poda de
linhas diárias em lugar nenhum do código — o `min(ref_date)` é simplesmente o
dia em que a tabela foi criada.

**Isso não se resolve sozinho.** Já existem 11 linhas diárias com
`ref_date = 2026-08-01` esperando a consolidação de agosto (agendada para 30/09),
e o agendado de 31/08 para julho vai bater no mesmo muro. Vale para toda
competência daqui em diante. O que está em jogo não é a tela: é o registro
mensal que paga bônus, alimenta o Relatório de Bonificação e a regra DESEMP-08
(promoção por 2 meses consecutivos).

## 3. `LOG_LEVEL=error` em produção esconde a metade do diagnóstico

O `.env` de produção roda `LOG_LEVEL=error`. Todo `Log::warning` é descartado —
incluindo o do `try/catch` por usuário do warm. Contar falhas de warm pelo log
dá **zero** mesmo quando elas acontecem. Por isso o aviso de payload degradado
que este commit adicionou é `Log::error`, não `warning`.

## Como diagnosticar isso de novo em 3 queries

```sql
-- 1. Qual competência tem detalhe, de que origem, e quando foi gravada
SELECT mes_referencia, origem, COUNT(*), COUNT(DISTINCT user_id), MAX(gerado_em)
FROM desempenho_company_score_snapshots GROUP BY mes_referencia, origem;

-- 2. Quem tem snapshot mensal congelado na competência (paga bônus)
SELECT user_id FROM desempenho_score_snapshots WHERE mes_referencia = '2026-07-01';

-- 3. A linha diária que colide com a consolidação
SELECT COUNT(*) FROM desempenho_score_snapshots
WHERE mes_referencia IS NULL AND ref_date = '2026-07-01';
```

`origem = 'warm_cache'` numa competência fechada é sinal de que ela **não** foi
consolidada: quando a consolidação funciona, a origem é `consolidar_mes` e a
trava de congelamento impede o warm de tocar.
