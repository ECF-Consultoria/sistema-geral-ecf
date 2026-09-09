---
phase: 139
plano: 139-01
registrado_em: 2026-09-09
sha_head: a68ff5f39a122c638ad986c051d248b1993f262d
---

# Baseline de testes — Fase 139 (antes de qualquer código novo)

Coletada **antes** da primeira linha de código desta fase, conforme exigido por
`139-VALIDATION.md` § "Baseline obrigatória ANTES de qualquer código novo". Esta fase acrescenta
**novos chamadores** de `EtapaTransicaoService::transicionar()` (etapas 2/3/4/5, D-15) sobre uma
máquina de estados que já tem baseline própria (`137-BASELINE-TESTES.md`, `138-BASELINE-TESTES.md`).
Sem este documento não há como distinguir falha nova de falha herdada quando os planos 139-02 em
diante começarem a acrescentar chamadores.

Confirmado por `git status --porcelain app/ database/ routes/ resources/ config/` vazio ao final
desta task — nenhum arquivo de código foi tocado.

## Comando executado

Forma Bash (a que roda neste ambiente):

```
/c/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase137 tests/Feature/Phase137 tests/Feature/Phase138 --colors=never
```

Forma equivalente em cmd.exe (não usada nesta captura, registrada por completude):

```
C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\Phase137 tests\Feature\Phase137 tests\Feature\Phase138 --colors=never
```

**Data/hora da execução:** 2026-09-09, 15:31 (horário local, America/Sao_Paulo, UTC-3) — HEAD
`a68ff5f3`.

## Resultado

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\xampp\htdocs\ecf_fluxo_entrada\phpunit.xml

...............................................................  63 / 123 ( 51%)
............................................................    123 / 123 (100%)

Time: 01:38.471, Memory: 100.00 MB

OK (123 tests, 444 assertions)
```

**Código de saída: 0.**

**Baseline verde.** As três suítes (`tests/Unit/Phase137`, `tests/Feature/Phase137`,
`tests/Feature/Phase138`) fecham **100% verdes** — 123 testes, 444 assertions, **nenhuma falha,
nenhum erro**. A ausência de falha é informação registrada explicitamente, não silêncio: qualquer
falha nova que aparecer a partir do plano 139-02 é regressão desta fase, não herança de 137/138.

Não há lista nominal de teste falho a registrar, porque nenhum teste falhou.

## Suítes deliberadamente FORA da baseline

As duas suítes abaixo **não** entram no comando acima e não são gate desta fase:

- `tests/Feature/Phase38/PolosControllerTest.php`
- `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php`

**Razão:** falhas antigas, documentadas em `.planning/learnings/painel-polos-status-e-meta.md` §2
e reconfirmadas em `138-BASELINE-TESTES.md` (6 falhas em `PolosControllerTest` por o cálculo de
faturamento ter migrado de CSV/`TGMV_LC` para Adman/`gross_billing` e por datas fixas
desatualizadas contra o mês corrente; 2 erros + 2 falhas em `PolosFaturamentoSnapshotTest` por
`SyncPolosFaturamentoJob::handle()` ter mudado de assinatura). Nenhuma delas é regressão desta
fase — a Fase 139 não toca em nada do módulo Polos.

## Advertência de execução — restrições duras deste ambiente

- **`php artisan test` e `php artisan test --testsuite=Feature` NÃO terminam.** Travam numa
  cascata de timeout de rede (~300s de `max_execution_time` do PHP CLI, chamada HTTP real não
  mockada) e o processo morre sem imprimir o resumo final (`Tests: X, Assertions: Y`). Herdado de
  `137-BASELINE-TESTES.md` e `138-BASELINE-TESTES.md`, reconfirmado por `139-VALIDATION.md`. Não
  foi repetido aqui para não queimar orçamento de tempo sem produzir número novo.
- **`C:\xampp\php\php.exe` (barras invertidas) não funciona no Bash** — o shell come as barras e o
  comando vira `C:xamppphpphp.exe`. A forma que funciona neste ambiente é
  `/c/xampp/php/php.exe` (POSIX). A forma com barras invertidas só vale no PowerShell/cmd.exe.
- Todo comando desta fase roda **sempre por diretório/arquivo**, nunca `--testsuite=Feature`
  sozinho nem a suíte inteira.

## Regra de comparação

Nenhuma falha herdada existe neste conjunto de suítes — este documento é o "antes" e é 100% verde.
Comparação de regressão nos planos 139-02 em diante é sempre contra este número:

| Suíte | Resultado baseline |
|---|---|
| `tests/Unit/Phase137` + `tests/Feature/Phase137` + `tests/Feature/Phase138` (comando único) | 0 falhas — **123/123 verde**, 444 assertions |
| `tests/Feature/Phase38/PolosControllerTest.php` | **fora do gate** — falhas antigas, não é regressão desta fase (ver seção acima) |
| `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` | **fora do gate** — falhas antigas, não é regressão desta fase (ver seção acima) |

Este documento é o "antes" — não editar depois de criado.
