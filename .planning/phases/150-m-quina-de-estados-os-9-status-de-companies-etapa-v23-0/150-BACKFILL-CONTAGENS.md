# Fase 150 Plano 05 — Contagens do backfill (D-08, reconsulta ao banco)

> Execução real do `etapa:backfill --apply` contra o MariaDB LOCAL de
> desenvolvimento (`ecf_admin`, XAMPP). Todos os números abaixo foram obtidos
> por **reconsulta SQL direta** ao banco, via `DB::table(...)` num
> `artisan tinker` **separado** da execução do comando — nunca por leitura do
> stdout de `etapa:backfill`. Disciplina de
> `.planning/learnings/desempenho-bonificacao.md` §4/§10.1: consolidação
> "bem-sucedida" na tela já custou caro no passado quando não gravou o
> esperado.

## Contagens

```
data: 2026-09-01
banco: MariaDB local (XAMPP), database ecf_admin, conexão DB_CONNECTION=mysql
total: 180
com_etapa_9_antes: 0
com_etapa_null_antes: 180
com_etapa_9_depois: 1
com_etapa_null_depois: 179
fonte: reconsulta SQL direta
```

Conferência: `com_etapa_9_depois` (1) + `com_etapa_null_depois` (179) = `total` (180). ✓

`SELECT DISTINCT etapa FROM companies WHERE etapa IS NOT NULL` (reconsulta direta) devolveu
exatamente `["em_operacao"]` — nenhuma etapa intermediária apareceu (D-05).

`SELECT COUNT(*) FROM company_etapa_transicoes` devolveu `0` **antes** e `0` **depois** do
`--apply` — `carimbarBackfill()` não gera histórico, como documentado no docblock do método
(deliberado, para não inventar duração fictícia no painel de gargalo da Fase 156).

## Ids do balde 1 (recebem `etapa = em_operacao`)

```
{"asdadassdsad": 55}
```

Localmente só **1** empresa cai no balde 1 (id 55). Bate com a contagem já registrada em
`150-RESEARCH.md` § "Contagem real" (medida em pesquisa: balde 1 = 1, balde 2 = 179, total 180).

## Roteiro seguido nesta execução (ordem obrigatória)

1. **Antes** — três consultas SQL diretas contra `companies` (total, `etapa = 'em_operacao'`,
   `etapa IS NULL`) + `company_etapa_transicoes` (contagem).
2. `php artisan etapa:backfill` **sem** `--apply` — stdout confirmou `DRY-RUN` e a listagem do
   balde 1 (1 empresa). Reconsulta imediatamente depois confirmou `0` empresas com `etapa` não
   nula — o dry-run não escreveu nada.
3. `php artisan etapa:backfill --apply`.
4. **Depois** — as mesmas consultas SQL diretas, repetidas. Este passo é a prova; o stdout do
   comando (que também imprime "Depois: ...") **não é** a prova, é só um resumo de conveniência
   para debug.

---

## ⚠️ Aviso 1 — a base local NÃO é medida de produção

Local tem **180** empresas e apenas **1** cai no balde 1. Produção tem aproximadamente **500**
empresas. O que esta execução prova é que a **regra** (dois baldes, reuso de
`analistaPerformance()`/`estrategistaPerformance()`, escrita exclusiva via
`EtapaTransicaoService::carimbarBackfill()`) roda corretamente contra MariaDB real — nunca o
volume ou a proporção esperados em produção. `150-RESEARCH.md` já registrava essa ressalva antes
desta execução; ela se confirma aqui.

## ⚠️ Aviso 2 — a execução de produção é outro momento, com autorização explícita

Nenhum deploy nem `--apply` em produção sai desta fase/plano. Quando a execução em produção
acontecer (autorizada explicitamente pelo usuário, por `CLAUDE.md` § "Constraints" — "Não
executar deploy sem autorização explícita"), o roteiro é:

1. Rodar as mesmas três consultas SQL diretas contra o banco de produção (total, `etapa =
   'em_operacao'`, `etapa IS NULL`) — **antes** de qualquer coisa.
2. Capturar a lista de empresas (ids + nomes) que a aba "Empresas" de `/companies` mostra em
   produção **antes** do backfill (Manual-Only Verification do `150-VALIDATION.md`).
3. Rodar `php artisan etapa:backfill --apply` em produção.
4. Repetir as três consultas SQL diretas — **depois**.
5. Recapturar a lista da aba "Empresas" e comparar contra o passo 2. **Diferença de uma empresa
   já é falha** (Success Criteria nº 1 da fase) — não seguir adiante sem investigar.
6. Registrar os seis números (antes/depois × total/etapa9/etapaNull) e o resultado da comparação
   da aba num `VERIFICATION.md` de produção, no mesmo formato greppável deste arquivo.

## ⚠️ Aviso 3 — como reverter localmente, se necessário

```sql
UPDATE companies SET etapa = NULL;
```

Seguro localmente: nenhum código de produção lê `companies.etapa` ainda nesta fase (o serviço de
transição não tem chamador de produção — ver `150-03-SUMMARY.md`), e `carimbarBackfill()` não
gerou histórico para desfazer em `company_etapa_transicoes`. Este comando **não** deve ser usado
em produção sem a mesma autorização explícita exigida para o `--apply` original.
