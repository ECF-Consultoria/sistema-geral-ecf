# Deferred Items — Phase 75

Descobertas fora do escopo do plano atual, registradas para tratamento futuro.

## Testes legacy pré-existentes quebrados (não causados pela Phase 75)

**Descoberto em:** Plan 75-03 (verificação de regressão `--filter=Comercial`)

- `tests/Feature/Phase13ComercialTest.php` — 10 de 12 casos falham em isolamento.
- `tests/Feature/Phase14ComercialTest.php` — caso `update ignora campos legacy` falha.

**Causa:** Os testes montam o payload de cadastro com o campo legacy `service_type`
(enum antigo), removido no refactor da Phase 14 quando `ComercialController::store()`
passou a exigir `servicos[]`. O `store()` atual retorna `validation.required` em `nome`/
`servicos` para esses payloads antigos. Falham identicamente com ou sem as mudanças da
Phase 75 (o Plan 75-03 apenas adicionou um arquivo de teste novo, sem tocar no controller).

**Ação:** NÃO corrigido nesta phase (fora de escopo — SCOPE BOUNDARY do executor).
Recomendação: atualizar ou aposentar esses testes legacy numa quick task de manutenção
de suíte, alinhando o payload ao contrato atual (`servicos[]`).

## Falhas pré-existentes no filtro `Companies` (verificação do Plan 75-04)

**Descoberto em:** Plan 75-04 (verificação de regressão `--filter=Companies`)

Confirmadas **pré-existentes**: rodando com o `CompanyController.php` do commit anterior
ao 75-04 (2283e5a), elas já falhavam de forma idêntica (5 failed, 3 passed). O 75-04 não
toca `CompanyController@index` nem essas suítes.

- `Phase18\CompaniesCustIdFilterTest > filtro invalido retorna apenas invalidas` — `companies` size 0 vs 1.
- `Phase18\CompaniesCustIdFilterTest > sem filtro retorna todas as empresas` — `companies` size 0 vs 3.
- `Phase75\AnunciosEscopoResponsavelTest > admin ve apenas companies com ml token` — render/erro de sessão (suíte do plan Anunciar-ML, não do 75-04).
- Demais falhas do filtro `Companies` — render blade / manifest Vite (páginas React não buildadas no ambiente de teste).

**Ação:** NÃO corrigidas (SCOPE BOUNDARY). As suítes do 75-04
(`Phase75ShopeeEmpresasTest` 16/16, `Phase75NpsShopeeTest` 2/2) e a regressão
`--filter=Nps` (157 passed) estão 100% verdes.
