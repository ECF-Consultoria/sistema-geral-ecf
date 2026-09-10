---
phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 10
tipo: regressao-final
data: 2026-09-10
compara_com: 139-BASELINE-TESTES.md
---

# Regressão final — Fase 139 (plano 139-10, Task 1)

O "depois" do `139-BASELINE-TESTES.md`. Este documento existe porque a Fase 139 acrescentou o
**terceiro** chamador de produção de `EtapaTransicaoService` e **alargou a permissão de uma rota de
contrato** — as duas coisas encostam em código que as Fases 131/137/138 entregaram e verificaram.

## Comando 1 — suíte da fase

```bash
/c/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase139 tests/Feature/Phase139 --colors=never
```

Saída literal:

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\xampp\htdocs\ecf_fluxo_entrada\phpunit.xml

................................................................. 65 / 92 ( 70%)
...........................                                       92 / 92 (100%)

Time: 00:09.541, Memory: 88.00 MB

OK (92 tests, 364 assertions)
```

**Exit code 0. Nenhuma falha, nenhum erro.** 92 testes, 364 assertions.

Distribuição por arquivo (13 arquivos): `tests/Unit/Phase139` — `AdmanRegisterUrlConfigTest`,
`ChecklistDefinicaoCatalogoTest`, `ChecklistItemModelTest`, `ChecklistProgressoTest`.
`tests/Feature/Phase139` — `ChecklistAcessoPorEntradaTest`, `ChecklistContagemItensTest`,
`ChecklistDirigeEtapaTest`, `ChecklistEndpointsTest`, `ChecklistGrupoContratoAutoTest`,
`ChecklistGrupoEntradaAutoTest`, `ChecklistMarcacaoManualAutoriaTest`,
`ContratoAssinadoPorLiberacaoTest`, `FinalizarTransicaoEtapaTest`, `FinalizarTravaTest`,
`MultiplosEnvelopesTest`.

## Comando 2 — regressão das fases que esta encosta

```bash
/c/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase137 tests/Feature/Phase137 tests/Feature/Phase138 tests/Feature/Phase131 --colors=never
```

Saída literal:

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\xampp\htdocs\ecf_fluxo_entrada\phpunit.xml

...............................................................  63 / 245 ( 25%)
............................................................... 126 / 245 ( 51%)
............................................................... 189 / 245 ( 77%)
........................................................        245 / 245 (100%)

Time: 01:36.257, Memory: 116.00 MB

OK (245 tests, 910 assertions)
```

**Exit code 0. Nenhuma falha, nenhum erro.** 245 testes, 910 assertions.

## Comparação nominal contra a baseline

A `139-BASELINE-TESTES.md` registra, na letra: *"As três suítes fecham 100% verdes — 123 testes,
444 assertions, nenhuma falha, nenhum erro. Não há lista nominal de teste falho a registrar, porque
nenhum teste falhou."*

| Conjunto | Baseline (139-01) | Agora (139-10) | Veredito |
|---|---|---|---|
| `Phase137` (Unit+Feature) + `Phase138` | 123 testes / 444 assertions, **0 falhas** | 123 testes / 444 assertions, **0 falhas** | **idêntico** |
| `Phase131` | não fazia parte do comando de baseline (a fase ainda não tocava naquela rota) | 122 testes / 466 assertions, **0 falhas** | verde |
| Soma do comando 2 | — | 245 testes / 910 assertions | 123 + 122 confere |

**Comparação nominal por teste: não há lista a comparar.** A baseline não tem nenhum teste falho
nominalmente registrado, e a execução de agora também não produziu nenhum. Zero contra zero — não
existe falha "conhecida" sendo carregada nem falha nova sendo relatada como herdada.

A parte 137/138 fecha **número por número idêntica** à baseline: mesmos 123 testes, mesmas 444
assertions. A Fase 139 acrescentou três chamadores de `EtapaTransicaoService`
(`ChecklistEtapaSincronizadorService`, `FinalizarEntradaAdministrativaService` e o funil do
`ContratoAdminController`) sem mover a agulha da máquina de estados existente.

`Phase131` entrou no comando de regressão porque o plano 139-08 **tirou `admin.contratos.show` do
grupo de permissão** e alterou o payload daquela tela. Os 122 testes daquela fase seguem verdes,
incluindo `ContratoAdminPermissaoTest` — o OR da D-17 não afrouxou nenhuma das outras rotas do grupo.

## Suítes deliberadamente FORA do gate

Mantidas fora, pelo mesmo motivo da baseline:

- `tests/Feature/Phase38/PolosControllerTest.php`
- `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php`

**Razão:** falhas **antigas**, documentadas em `.planning/learnings/painel-polos-status-e-meta.md`
§2 e reconfirmadas em `138-BASELINE-TESTES.md` — 6 falhas em `PolosControllerTest` (o cálculo de
faturamento migrou de CSV/`TGMV_LC` para Adman/`gross_billing`, e há datas fixas desatualizadas
contra o mês corrente) e 2 erros + 2 falhas em `PolosFaturamentoSnapshotTest`
(`SyncPolosFaturamentoJob::handle()` mudou de assinatura). **Nenhuma é regressão desta fase** — a
Fase 139 não toca em nada do módulo Polos, e `git diff` da fase não lista arquivo algum de Polos.

## Migration da fase — aplicada e com o índice

```bash
/c/xampp/php/php.exe artisan migrate:status | grep checklist_administrativo
```

```
2026_09_09_170000_create_checklist_administrativo_itens_table .... [106] Ran
```

E, contra o MariaDB local, a conferência que o `migrate:status` **não** dá — se o índice nomeado
sobreviveu (o erro 1059 de identificador longo, que motivou nomear o índice à mão no plano 139-02,
pode deixar a tabela criada **sem** ele):

```
PRIMARY                                          | col=id         | unique=YES
cai_company_chave_unique                         | col=company_id | unique=YES
cai_company_chave_unique                         | col=chave      | unique=YES
checklist_administrativo_itens_feito_por_foreign | col=feito_por  | unique=no
checklist_administrativo_itens_chave_index       | col=chave      | unique=no
```

**O índice único composto `cai_company_chave_unique` existe** sobre (`company_id`, `chave`). A
tabela não ficou sem a trava de duplicidade.

As duas migrations da Fase 137 que esta fase depende também constam como aplicadas:
`2026_09_01_110000_add_etapa_to_companies_table` [103] e
`2026_09_01_120000_create_company_etapa_transicoes_table` [104].

## Veredito

**Nenhuma falha nova. Nada bloqueante.** A Task 1 do plano 139-10 está cumprida; a fase segue para
os dois checkpoints humanos (Tasks 2 e 3), que verificam o que teste PHP não alcança: o render real
no navegador, o comportamento da área de transferência no item 7 e o efeito do FINALIZAR ponta a
ponta.

⚠️ **Nada deployado.** Tudo permanece na branch `feat/fluxo-entrada-empresas`, no worktree
`C:\xampp\htdocs\ecf_fluxo_entrada`.
