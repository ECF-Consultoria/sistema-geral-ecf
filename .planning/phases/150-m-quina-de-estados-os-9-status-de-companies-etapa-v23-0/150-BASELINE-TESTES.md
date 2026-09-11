---
phase: 150
plano: 150-01
registrado_em: 2026-09-01
sha_head: dfb86c88bc942e198d73efff286dfb4865fff881
caminho: npm-build
---

# Baseline de testes — Fase 150 (antes de qualquer migration)

Coletada **antes** de qualquer migration desta fase existir (150-02 e 150-05 são as que
alteram `companies` com dado de produção). Exigência do `CLAUDE.md` § "GSD obrigatório". Este
plano (`150-01`) **não** modifica nenhum arquivo em `app/`, `database/` ou `resources/` — todo
achado abaixo é estado do worktree, não regressão introduzida aqui.

## Ambiente (Task 1)

`caminho: npm-build` — foi usado o caminho definitivo, não o atalho `public/hot`:

1. `npm install` restaurou `node_modules/` (511 pacotes) a partir do `package-lock.json` já
   versionado. Nenhum pacote novo foi adicionado.
2. `npm run build` gerou `public/build/manifest.json` (build ok, ~49s, sem erros).
3. `node_modules/` e `public/build/` são gitignored — `git status --short` não lista nada
   novo por causa deles.

**Consequência para os planos seguintes (150-02..07):** como o caminho foi `npm-build`, **não**
é preciso recriar/remover `public/hot` antes de cada suíte Feature — o ambiente já fica
destravado de forma persistente neste worktree (a menos que alguém apague `node_modules/` ou
`public/build/` manualmente). O aviso do plano sobre "recriar `public/hot` antes de cada task"
só se aplicaria se o atalho tivesse sido usado, o que não foi o caso.

### Nota sobre `package-lock.json`

O `npm install` reescreveu momentaneamente o campo `"name"` do lockfile de `"ecf_admin_onb"`
(nome do worktree de onde este `package-lock.json` foi originalmente copiado — ver
`project_onboarding_fluxo_v13_260819.md` no MEMORY.md) para `"ecf_fluxo_entrada"` (nome real
deste diretório — `package.json` não declara `name` explícito, então o npm deriva do path).
Revertido com `git checkout -- package-lock.json` para manter o arquivo byte-idêntico ao
versionado, conforme critério de aceite da Task 1 (`git status --porcelain package.json
package-lock.json` vazio). Não é um pacote novo nem mudança de dependência — apenas metadado
de nome sincronizado pelo npm; documentado aqui para não ser confundido com manipulação
deliberada do lockfile numa sessão futura.

## Baseline command (exigência do CLAUDE.md — antes da migration)

Comando:
```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php --colors=never
```

Resultado: **OK (24 tests, 44 assertions)** — código de saída 0. Bate exatamente com o número
que a pesquisa mediu (`150-VALIDATION.md` linha 26: "medido: 24 testes / 44 assertions").

## Quick run command (suíte dedicada da fase)

`tests/Unit/Phase150` e `tests/Feature/Phase150` **ainda não existem** — nascem nos planos
150-02 em diante (tdd_mode desligado nesta fase; cada suíte nasce junto da implementação que
prova, ver `150-VALIDATION.md` § "Wave 0 Requirements"). Nada a rodar aqui.

## Suíte completa (`php artisan test`)

**Não foi possível capturar como execução única neste ambiente.** `php artisan test` estourou
o timeout de 600s sem terminar. Ao isolar em `--testsuite=Feature`, o processo trava numa
cascata de timeout de rede (detalhe abaixo) e morre sem imprimir o resumo final
(`Tests: X, Assertions: Y`). Por isso a suíte completa foi dividida em Unit (capturado por
inteiro) + Feature (achado documentado, não capturado por inteiro) — contingência prevista na
própria Task 2 do plano ("Se estourar o timeout, rode em duas partes").

### Unit suite (`vendor/bin/phpunit --testsuite=Unit`)

Comando:
```
C:\xampp\php\php.exe vendor/bin/phpunit --testsuite=Unit --colors=never
```

Resultado: **`Tests: 285, Assertions: 987, Errors: 9, Failures: 3`** (código de saída 2 —
vermelho). Todas as 12 ocorrências são pré-existentes, não relacionadas à Fase 150:

- **9 erros** — `Tests\Unit\CalcularFaixaTest` (as 9 variações de faixa: `test_faturamento_zero_retorna_ate_499k`
  até `test_acima_5m_retorna_maxima`). `ArgumentCountError: Too few arguments to function
  App\Http\Controllers\AdminController::__construct(), 0 passed ... and exactly 1 expected`
  — o teste instancia `AdminController` sem argumento; o construtor hoje exige 1 (dependência
  injetada depois que o teste foi escrito, presumivelmente).
- **1 falha** — `Tests\Unit\CompanyServiceTypeTest::test_service_type_aceita_polo` —
  `assertDatabaseHas` falha porque o SQLite devolve a coluna citada (`"service_type"`) em vez
  de `service_type` puro na comparação de atributos.
- **2 falhas** — `Tests\Unit\Phase39\MercadoLivreSugadoresProviderTest`
  (`test_fetchAdgroupsMetrics_normalizes_ml_payload_to_contract_keys` e
  `test_fetchAdgroupMlbs_extracts_mlb_ids_from_ads_payload`) — contrato de normalização do
  payload ML diverge do que o teste espera.

### Feature suite — achado não resolvido: cascata de timeout de rede

Ao rodar `vendor/bin/phpunit --testsuite=Feature --colors=never` isoladamente (531 arquivos de
teste), o processo:

1. Roda por ~300s reais até travar numa chamada de rede real via Guzzle (fatal em
   `CurlFactory.php:695`) que não foi mockada nesse teste — alvo externo inacessível deste
   ambiente offline (candidatos prováveis, não confirmados: Adman `ad-man.io`, OAuth do
   Mercado Livre/Google, ou SFTP de grants; nenhum teste referencia SFTP diretamente, então é
   mais provável ser um dos serviços HTTP).
2. Ao estourar `max_execution_time` (300s, configurado no ambiente PHP CLI), o PHP emite
   `Fatal error: Maximum execution time of 300 seconds exceeded`. Esse fatal não é recuperável
   pelo PHPUnit dentro do mesmo processo — toda operação seguinte que checa o orçamento de
   tempo volta a falhar quase instantaneamente, produzindo ~33 blocos idênticos de erro em
   sequência, até o processo finalmente morrer sem imprimir `Tests: X, Assertions: Y`.
3. Duas tentativas de isolar a suíte ofensora via `--stop-on-failure` e `--stop-on-error`
   pararam antes, em falhas mais rápidas e não relacionadas (`AdminFechamentoControllerTest` —
   sessão sem chave `success`; depois `UniqueConstraintViolationException` em
   `adman_metrics.company_id/reference_date`). **O teste exato que dispara o timeout de 300s
   não foi identificado** — não há `@group network` nem exclusão configurada em `phpunit.xml`
   para bisectar rapidamente, e cada tentativa de reprodução custa vários minutos reais.

**Isto não é regressão da Fase 150** — nenhum arquivo de `app/`, `database/` ou `resources/`
foi tocado por este plano (150-01). É estado pré-existente do worktree: aparentemente nenhuma
sessão anterior tinha rodado a suíte Feature completa neste ambiente sem rede antes de hoje.

**Para as próximas sessões desta fase (150-02..150-07) e para `/gsd:verify-work`:** não rodar
`php artisan test` / `--testsuite=Feature` completo esperando que ele termine — trava do mesmo
jeito e consome o orçamento de tempo da task sem produzir resumo final. Preferir:
- `Quick run command` (`tests/Unit/Phase150 tests/Feature/Phase150`) a cada task — é o que o
  `150-VALIDATION.md` já prescreve como sampling rate.
- `Baseline command` (as duas suítes de `/companies`) a cada fim de wave.
- Se for indispensável rodar a suíte Feature inteira, isolar por diretório (ex.:
  `--testsuite=Feature tests/Feature/Phase60`) em vez do `--testsuite=Feature` inteiro, para
  não herdar o orçamento de tempo já gasto por um teste anterior no mesmo processo.

## Falhas pré-existentes de Polos — NÃO são regressão da 137

Conforme `.planning/learnings/painel-polos-status-e-meta.md` §2, medidas isoladamente (fora da
suíte Feature completa, que não termina neste ambiente — ver acima):

### `tests/Feature/Phase38/PolosControllerTest.php`

Comando: `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase38/PolosControllerTest.php --colors=never`

Resultado: **`Tests: 12, Assertions: 158, Failures: 6`**

Falhas: `test_status_problema_precedencia`, `test_status_dist`, `test_filtro_por_mes` e mais 3
— todas por faturamento calculado via CSV (`TGMV_LC`) nos testes vs. Adman
(`gross_billing`) em produção, e datas fixas (`202606`) desatualizadas contra o mês corrente.

### `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php`

Comando: `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Polos/PolosFaturamentoSnapshotTest.php --colors=never`

Resultado: **`Tests: 4, Assertions: 32, Errors: 2, Failures: 2, Risky: 2`**

2 erros por `ArgumentCountError` (assinatura de `SyncPolosFaturamentoJob` mudou), 2 falhas de
faturamento zerado no payload.

**Total: 10 falhas** (6 + 2 + 2) — bate exatamente com "~10 falhas antigas" citado no learning
§2. Causa raiz documentada lá, não é dedutível do código sem ler o learning.

## Regra de comparação

Nenhuma falha listada acima é regressão da Fase 150 — este documento é a baseline "antes".
Comparação de regressão nos próximos planos (150-02..07) é sempre contra estes números, nunca
contra zero:

| Suíte | Resultado baseline |
|---|---|
| Baseline command (`/companies`) | 0 falhas — 24/24 verde, 44 assertions |
| Unit suite completa | 9 erros + 3 falhas (285 testes, 987 assertions) |
| Polos — `PolosControllerTest` | 6 falhas (12 testes, 158 assertions) |
| Polos — `PolosFaturamentoSnapshotTest` | 2 erros + 2 falhas (4 testes, 32 assertions) |
| Feature suite completa | não capturável numa única execução neste ambiente — ver achado acima |

Este documento é o "antes" — não editar depois de criado.
