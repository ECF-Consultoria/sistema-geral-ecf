---
phase: 151
plano: 151-01
registrado_em: 2026-09-02
sha_head: fb7ddfd0266f74cbdc5019dbe9047c0673981612
---

# Baseline de testes — Fase 151 (antes de qualquer migration)

Coletada **antes** de a migration do plano `151-03` existir. Aquela migration cria três colunas
aditivas em `companies` — `hubspot_owner_id`, `hubspot_owner_nome` e `data_venda` — tabela com
~500 registros em produção. É essa migration que dispara o gate do `CLAUDE.md` § "GSD
obrigatório" ("migration que altere tabela existente com dado em produção"), e esta baseline é
o registro exigido por ele. Este plano (`151-01`) **não** modificou nenhum arquivo em `app/`,
`database/` nem `resources/` — confirmado por `git status --porcelain app/ database/ resources/`
vazio ao final da execução.

## Ambiente

`node_modules/` e `public/build/manifest.json` já existiam neste worktree antes de qualquer
comando ser rodado (medido também pelo `151-VALIDATION.md`, HEAD `2a9562af`). Não foi preciso
`npm install` nem `npm run build`. Nenhuma linha da saída das suítes abaixo contém
`Vite manifest not found`. `package.json` e `package-lock.json` permaneceram inalterados
(`git status --porcelain package.json package-lock.json` vazio).

## Comando 1 — Baseline específica da fase (suítes que tocam `companies`, Contrato e a máquina de estados)

Comando:
```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php tests/Feature/Phase131/ContratoAdminPermissaoTest.php tests/Unit/Phase150 tests/Feature/Phase150 --colors=never
```

Resultado: **OK (86 tests, 214 assertions)** — código de saída 0.

Isolando as duas suítes de `/companies` sozinhas (o mesmo par que a Fase 150 mediu como
24 testes / 44 assertions) o número permanece **idêntico** — nenhuma divergência a registrar
aqui: `tests/Feature/Phase37CompaniesPerformanceFilterTest.php` e
`tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` continuam batendo com o
número da Fase 150. O total de 86/214 é maior porque este comando também soma
`tests/Feature/Phase131/ContratoAdminPermissaoTest.php`, `tests/Unit/Phase150` e
`tests/Feature/Phase150` — suítes que não existiam (ou não entravam neste comando) na baseline
da 137.

## Comando 2 — Verificação isolada de regressão de D-15 e da máquina de estados da 137

Comando:
```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase131/ContratoAdminPermissaoTest.php tests/Unit/Phase150 --colors=never
```

Resultado: **OK (26 tests, 60 assertions)** — código de saída 0. Confirma que `admin.contratos.*`
segue sob `permission:admin.contratos` (D-15) e que a máquina de estados dos 9 status (Fase 150)
está verde antes de a Fase 151 tocar em qualquer coisa.

## Comando 3 — `tests/Feature/Phase38/PolosControllerTest.php`

Comando:
```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase38/PolosControllerTest.php --colors=never
```

Resultado: **`Tests: 12, Assertions: 158, Failures: 6`** — código de saída 0 (PHPUnit reporta
falha de teste sem sinalizar erro de processo neste comando). **Idêntico** ao número registrado
em `150-BASELINE-TESTES.md`: `test_meta_por_estagio`, `test_status_sim`,
`test_status_em_progresso`, `test_status_problema_precedencia`, `test_status_dist` e
`test_filtro_por_mes` — as mesmas 6, pela mesma causa raiz (ver seção abaixo).

## Comando 4 — `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php`

Comando:
```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Polos/PolosFaturamentoSnapshotTest.php --colors=never
```

Resultado: **`Tests: 4, Assertions: 32, Errors: 2, Failures: 2, Risky: 2`** — código de saída 0.
Idêntico ao número da Fase 150: 2 `ArgumentCountError` em `SyncPolosFaturamentoJob::handle()`
(assinatura mudou, teste chama com 1 argumento) e 2 falhas de faturamento zerado no payload.

## Comando 5 — Suíte Unit completa

Comando:
```
C:\xampp\php\php.exe vendor/bin/phpunit --testsuite=Unit --colors=never
```

Resultado: **`Tests: 307, Assertions: 1041, Errors: 9, Failures: 3, PHPUnit Deprecations: 55`** —
código de saída 0.

Comparado à baseline da Fase 150 (285 testes, 987 assertions, mesmos 9 erros + 3 falhas), o total
de testes cresceu (+22 testes / +54 assertions) — atribuível aos testes Unit que a própria Fase
137 adicionou entre a baseline dela e hoje (`tests/Unit/Phase150/CompanyEtapaConstantesTest.php` e
`tests/Unit/Phase150/EtapaTransicaoServiceTest.php`, ambos incluídos na contagem `--testsuite=Unit`).
Os 9 erros e as 3 falhas são **exatamente os mesmos nominalmente** que a Fase 150 já havia
documentado como pré-existentes, não relacionados a nenhuma fase em curso:

- **9 erros** — `Tests\Unit\CalcularFaixaTest` (as 9 variações de faixa, de
  `test_faturamento_zero_retorna_ate_499k` a `test_acima_5m_retorna_maxima`):
  `ArgumentCountError: Too few arguments to function App\Http\Controllers\AdminController::__construct()`.
- **1 falha** — `Tests\Unit\CompanyServiceTypeTest::test_service_type_aceita_polo`:
  `assertDatabaseHas` falha por o SQLite devolver a coluna citada (`"service_type"`) em vez de
  `service_type` puro.
- **2 falhas** — `Tests\Unit\Phase39\MercadoLivreSugadoresProviderTest`
  (`test_fetchAdgroupsMetrics_normalizes_ml_payload_to_contract_keys` e
  `test_fetchAdgroupMlbs_extracts_mlb_ids_from_ads_payload`) — contrato de normalização do
  payload ML diverge do esperado pelo teste.

Nenhuma falha nova apareceu fora deste conjunto de 12 (9+3). Não há falha a listar fora dos dois
arquivos de Polos e destas três classes Unit.

## Falhas pré-existentes de Polos — NÃO são regressão da 138

Conforme `.planning/learnings/painel-polos-status-e-meta.md` §2, medidas isoladamente:

### `tests/Feature/Phase38/PolosControllerTest.php`

**6 falhas** (12 testes, 158 assertions) — os testes montam faturamento pelo **CSV**
(`TGMV_LC`), mas o cálculo migrou para a **Adman** (`gross_billing`, sem fallback CSV), e datas
fixas (`202606`) estão desatualizadas contra o mês corrente (hoje `202609`).

### `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php`

**2 erros + 2 falhas** (4 testes, 32 assertions) — `SyncPolosFaturamentoJob::handle()` mudou de
assinatura (`ArgumentCountError`, 1 argumento passado, 2 esperados) e faturamento zerado no
payload de teste.

**Total: 10 falhas** (6 + 2 + 2) — bate exatamente com "~10 falhas antigas" citado no learning
§2 e com o número que a Fase 150 já havia registrado. Causa raiz documentada lá, não é
dedutível do código sem ler o learning.

## Aviso de execução

`php artisan test` e `vendor/bin/phpunit --testsuite=Feature` (a suíte Feature inteira) **não
terminam neste ambiente** — travam numa cascata de timeout de rede (~300s de `max_execution_time`
do PHP CLI, chamada HTTP real não mockada) e o processo morre sem imprimir o resumo final
(`Tests: X, Assertions: Y`). Achado herdado e reconfirmado do `150-BASELINE-TESTES.md`; não
foi repetido aqui para não queimar o orçamento de tempo desta task sem produzir número novo.

Todo comando desta fase (151-01 em diante) roda **sempre por diretório/arquivo**, com o binário
`C:\xampp\php\php.exe vendor/bin/phpunit` — nunca `php artisan test`, nunca `--testsuite=Feature`
sozinho. Ver `151-VALIDATION.md` § "Infraestrutura de Testes" para o comando rápido por task
(`tests/Unit/Phase151 tests/Feature/Phase151`) e o comando de suíte por fechamento de wave.

## Regra de comparação

Nenhuma falha listada acima é regressão da Fase 151 — este documento é a baseline "antes".
Comparação de regressão nos planos 151-02 em diante é sempre contra estes números, **nunca
contra zero**:

| Suíte | Resultado baseline |
|---|---|
| Comando 1 — Baseline específica da fase (companies + Contrato + Phase150) | 0 falhas — 86/86 verde, 214 assertions |
| Comando 2 — D-15 + máquina de estados isoladas | 0 falhas — 26/26 verde, 60 assertions |
| Polos — `PolosControllerTest` | 6 falhas (12 testes, 158 assertions) |
| Polos — `PolosFaturamentoSnapshotTest` | 2 erros + 2 falhas (4 testes, 32 assertions) |
| Unit suite completa | 9 erros + 3 falhas (307 testes, 1041 assertions) |
| Feature suite completa | não capturável numa única execução neste ambiente — ver "Aviso de execução" |

Este documento é o "antes" — não editar depois de criado.
