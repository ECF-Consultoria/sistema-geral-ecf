# Itens deferidos — quick task 260805-eqk

Descobertos durante a verificação final. **Nenhum deles foi causado por esta task**
(provado por execução comparativa contra o commit `7ecf449f`, imediatamente anterior
aos 4 commits). Ficam registrados aqui em vez de corrigidos, por estarem fora do
escopo desta task.

## 1. A suíte de testes não roda num único processo PHP

`php artisan test` (e `vendor/bin/phpunit` direto) morre com:

```
Fatal error: Maximum execution time of 300 seconds exceeded in
app/Services/Sugadores/MercadoLivreAdsService.php on line 215
```

**Causa real** (não é o `MercadoLivreAdsService`, ele só é a vítima):
`SyncGrantsFromEcfDrive.php:23` e `SyncGrantsFromSftp.php:22` chamam
`set_time_limit(300)`. Quando um teste exercita esses comandos, o limite do
**processo inteiro** do phpunit é reiniciado para 300s. Como a suíte completa leva
bem mais que isso — há `usleep` real de backoff nos testes de Sugadores —, o
processo é morto antes de o PHPUnit imprimir o relatório. `-d max_execution_time=0`
na linha de comando **não** resolve: `set_time_limit` em runtime sobrepõe a flag.

**Contorno usado nesta task:** rodar a suíte em blocos de 25 arquivos, cada bloco num
processo novo, somando os totais.

**Correção sugerida (fase futura):** trocar `set_time_limit(300)` por algo sensível a
ambiente (`if (! app()->runningUnitTests())`) nos dois comandos, ou substituir o
`usleep` real dos testes de backoff por um fake de sleep.

## 2. Baseline de 117 testes falhando, sem relação com HubSpot/Comercial

Estado do repositório na branch `main` em 2026-08-05 (após esta task):

```
Tests: 2431, Assertions: 12951, Failures: 100, Errors: 17, Skipped: 1
```

Contra o baseline `7ecf449f` (2418 testes, 107 failures, 24 errors, 131 falhando):
**zero falhas novas**, 14 a menos.

Concentração das 117 falhas remanescentes:

| Domínio | Classes | Aprox. |
|---|---|---|
| Desempenho / bonificação | `Phase119\CompanyScoreService*`, `Phase74\*`, `Phase110\ConsolidarMesMargemResiliente`, `V16\*`, `V18\*`, `Polos\PolosFaturamentoSnapshot`, `DesempenhoShopeeScore`, `Unit\CalcularFaixa` | ~45 |
| Comercial legado (`service_type` × `servicos[]`) | `Phase13ComercialTest`, `Phase14ComercialTest`, `Phase13MigrationTest`, `Phase14MigrationTest`, `Phase14*Cobranca*` | ~22 |
| Fechamento / contratos | `AdminFechamentoControllerTest`, `FechamentoMigrationTest`, `Unit\CompanyServiceTypeTest` | ~7 |
| Sugadores ML | `Phase42\*`, `Unit\Phase39\MercadoLivreSugadoresProvider`, `Sugadores\SugadoresIndex` | ~8 |
| Publicação / Polos / NPS / diversos | `Phase38\PolosController`, `Phase38Publicador\*`, `Phase75\*`, `Phase31NpsSubmit`, `Phase33OnboardingFicha`, `Phase37ServicoSetor`, `Phase61\*`, `Phase69\*`, `Phase77\*`, `Phase18\CompaniesCustIdFilter`, `ExampleTest` | ~35 |

Duas observações que merecem uma fase própria:

- **`Phase13ComercialTest` / `Phase14ComercialTest` estão obsoletos, não quebrados.**
  Eles ainda postam `service_type`; o `ComercialController` passou a exigir
  `servicos[]` (catálogo Frente A) numa fase anterior e **já não tinha**
  `service_type` em `7ecf449f`. São ~22 testes que testam uma API que não existe
  mais. Ou se atualiza a fixture para `servicos[]`, ou se deletam.
- **`AdminFechamentoControllerTest::test_empresa_ok_recebe_periodo_coberto`** falha
  com `'01/08'` esperado × `'06/07'` recebido — teste sensível à data corrente,
  sem congelamento de relógio (`Carbon::setTestNow`).

## 3. `Phase70\NpsTemplateCrudTest::test_toggle_active_bloqueia_desativacao_do_is_default`

Falhou no baseline e passou depois, **sem nenhuma mudança relacionada**. Provável
flakiness (dependência de ordem/estado). Vale confirmar antes de tratar como
resolvido.
