# Deferred Items — Phase 111

## Plan 111-02

- **`Tests\Feature\Phase37ServicoSetorTest::test_constants_setores_expostas`** falha
  pré-existente (confirmado via `git stash` antes das mudanças deste plano —
  falha idêntica sem as alterações do 111-02). `Servico::SETORES` tem 5
  entradas, o teste espera 3 (`performance`/`publicacao`/`outros`). Não
  relacionado a `HubspotApiClient` nem a este plano — fora de escopo (Rule:
  scope boundary). Não corrigido aqui.

## Plan 111-03

- Mesma falha pré-existente (`Phase37ServicoSetorTest::constants_setores_expostas`)
  reconfirmada durante a suite completa deste plano — não relacionada às
  migrations/models de `companies`/`contratos_servico` HubSpot. Fora de escopo.
- Ao rodar `--filter="Phase34|Phase35|Phase37|Phase14"` (regressão ampla, além do
  exigido pelo plano) apareceram mais 8 falhas pré-existentes, todas em código
  não tocado por este plano (só adiciona colunas nullable + fillable/casts):
  - `Phase14MigrationTest` (4 métodos) e `Phase14AdminControllerCobrancaTest`:
    `InvalidFormatException` do Carbon ao parsear a string literal
    `contract_start` — "The timezone could not be found in the database"
    (ambiente Windows local sem dados de timezone do PHP/ICU; não é causado
    pelas migrations deste plano).
  - `Phase14ComercialTest::update_ignora_campos_legacy`, `Phase14MlbControllerFiltroTest`
    (`empresas_pendentes` prop ausente) e `Phase14VerificarCobrancaTest::aborta_com_divergencia`
    — falhas de lógica de negócio pré-existente em código de fases anteriores
    (ComercialController/MlbController/comando `phase14:verificar-cobranca`),
    nada relacionado a `Company`/`ContratoServico` HubSpot. Não corrigidos aqui
    (scope boundary — fora do escopo deste plano). `Phase111HubspotSchemaTest`
    e a regressão exigida pelo plano (`Phase34HubspotWebhookTest`,
    `Phase37WebhookLineItemsTest`) permanecem 100% verdes.
