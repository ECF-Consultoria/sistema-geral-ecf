# Itens diferidos — Phase 76

## Fora de escopo (descobertos durante 76-02)

- **`Tests\Feature\PublicacaoDesempenhoRouteTest > user com mlb dashboard acessa rota e recebe 200`**
  - Sintoma: retorna 403 em vez de 200.
  - Verificado PRÉ-EXISTENTE: falha idêntica no commit HEAD~1 (76-01), antes de qualquer mudança do 76-02.
  - Não relacionado à dedup de carteira/`company_users` (é permissão de rota `/publicacao/desempenho`).
  - Ação: NÃO corrigido nesta fase (regra scope boundary). Investigar separadamente.

## Fora de escopo (descobertos durante 76-03)

Falhas em `--filter=Companies`. Confirmadas PRÉ-EXISTENTES (reproduzem com os
controllers ANTES das edições do 76-03, via `git stash`). Todas exercitam
`CompanyController::index`/comercial/migration — nenhuma toca as 3 escritas
escopadas por `servico_id` (bulkAssign/update sync). NÃO corrigidas (scope boundary).

- **`Tests\Feature\Phase18\CompaniesCustIdFilterTest > sem filtro retorna todas as empresas`** — prop `companies` size 0 (esperado 3); `index` depende de dados/serviços externos ausentes no ambiente de teste.
- **`Tests\Feature\Phase18\CompaniesCustIdFilterTest > filtro invalido retorna apenas invalidas`** — mesma causa (size 0).
- **`Tests\Feature\Phase13ComercialTest > guard duplicata companies`**.
- **`Tests\Feature\Phase13MigrationTest > companies retroativas tem status ativo`**.
- **`Tests\Feature\Phase14MlbControllerFiltroTest > companies index filtra pendentes publicidade gestao`**.
