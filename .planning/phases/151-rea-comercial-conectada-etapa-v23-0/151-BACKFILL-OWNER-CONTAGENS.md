# 151-04 — Contagens do retroativo `hubspot:backfill-owner-venda`

**Data:** 2026-09-02
**Ambiente:** MariaDB local (`.env` deste worktree, `DB_DATABASE=ecf_admin`, `DB_HOST=127.0.0.1`) — **não é produção/VPS**.

## Comando 1 — dry-run (padrão, sem `--apply`)

```
C:\xampp\php\php.exe artisan hubspot:backfill-owner-venda
```

Saída literal (stdout):

```
MODO DRY-RUN — nada será gravado. Rode de novo com --apply para gravar.
Passagem 1 (data_venda, sem custo de API): 1 candidata(s), 0 gravada(s), 1 sem closedate no snapshot.
Passagem 2 (owner, com custo de API): 0 candidata(s) com hubspot_deal_id, 0 gravada(s), 0 falha(s) de fetch, 180 sem_deal_id (fora da varredura — nunca terão owner).
Este stdout NÃO é prova — confira por reconsulta direta ao banco (SELECT COUNT(*) ...).
```

## Comando 2 — `--apply` (gravação real, contra o MariaDB local)

```
C:\xampp\php\php.exe artisan hubspot:backfill-owner-venda --apply
```

Saída literal (stdout):

```
Modo APPLY — gravando de verdade.
Passagem 1 (data_venda, sem custo de API): 1 candidata(s), 0 gravada(s), 1 sem closedate no snapshot.
Passagem 2 (owner, com custo de API): 0 candidata(s) com hubspot_deal_id, 0 gravada(s), 0 falha(s) de fetch, 180 sem_deal_id (fora da varredura — nunca terão owner).
Este stdout NÃO é prova — confira por reconsulta direta ao banco (SELECT COUNT(*) ...).
```

## Reconsulta SQL direta (pós-`--apply`) — a única prova aceita

Conforme `.planning/learnings/desempenho-bonificacao.md` §6/§10.1, o stdout acima **não** é
prova de nada — foi conferido por reconsulta direta ao banco via `DB::selectOne()` no mesmo
processo `artisan tinker`, sem reaproveitar nenhum valor impresso pelo comando:

```sql
SELECT COUNT(*) FROM companies WHERE hubspot_owner_id IS NOT NULL;    -- 0
SELECT COUNT(*) FROM companies WHERE hubspot_owner_nome IS NOT NULL;  -- 0
SELECT COUNT(*) FROM companies WHERE data_venda IS NOT NULL;          -- 0
SELECT COUNT(*) FROM companies WHERE hubspot_deal_id IS NOT NULL;     -- 0
SELECT COUNT(*) FROM companies;                                       -- 180
```

## Leitura do resultado

O acervo local (`ecf_admin`, 180 empresas) tem **zero** empresas com `hubspot_deal_id`
preenchido e apenas **1** com `hubspot_snapshot` preenchido — e essa única empresa não tem
`closedate` dentro do snapshot (dado de teste local antigo, sem o formato real de um deal
HubSpot fechado). Por isso as duas passagens não gravaram nada, e a reconsulta confirma: os
quatro `SELECT COUNT(*)` batem exatamente com o que o comando reportou (0 em todas as três
colunas novas), sem nenhuma escrita colateral. O comando é seguro por construção (dry-run
padrão, `chunkById(100)` nas duas passagens, falha de fetch isolada por empresa) e o teste
contra o banco real não revelou nenhuma escrita inesperada.

Este resultado **não substitui** a execução real contra o acervo de produção (~500 empresas,
uma fração real com `hubspot_deal_id`/`hubspot_snapshot` preenchidos pelo webhook em produção
desde a Fase 34) — só prova que o comando não corrompe dado nenhum contra um banco real
(MariaDB, não SQLite dos testes).

## Execução em produção — PENDENTE, depende de autorização explícita do usuário

**A execução deste comando contra a VPS de produção NÃO faz parte desta fase.** Por
`CLAUDE.md` ("Deploy: Não executar deploy sem autorização explícita do usuário") e pela
disciplina de escrita em massa numa tabela com ~500 registros reais (T-151-12 do threat
model deste plano), rodar `hubspot:backfill-owner-venda --apply` na VPS exige:

1. Deploy desta fase já realizado e autorizado.
2. `HUBSPOT_ACCESS_TOKEN` de produção configurado e a medição da property real de owner
   (pendência já registrada em `138-HUBSPOT-MEDICOES.md`, responsabilidade do plano 151-09).
3. Autorização explícita do usuário para rodar o comando com `--apply` contra produção,
   seguida de reconsulta SQL direta na VPS (mesmo molde deste documento) — nunca confiar no
   stdout da execução remota.
