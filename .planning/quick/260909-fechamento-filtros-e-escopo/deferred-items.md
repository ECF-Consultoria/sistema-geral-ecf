# Itens fora de escopo encontrados durante a execução

## `Phase13ComercialTest` — 10 falhas, todas pré-existentes

Rodado fora do gate do plano (`Phase122|Phase136|Phase137|Phase138|Phase139|Phase140`),
mas toca `/administrativo/financeiro` num dos testes, então foi conferido por segurança.

Todas as 10 falhas vêm da mesma causa raiz: `/comercial/empresas` ainda valida e tenta
gravar o campo legacy `service_type`, que a migration
`2026_05_27_100003_drop_legacy_service_columns_from_companies` (Plan 14-06) já dropou da
tabela `companies`. O teste `empresa_visivel_no_financeiro` falha porque a criação da
empresa (setup do teste) já falha antes de chegar na tela de fechamento — "the table is
empty" é sintoma, não causa.

Não é regressão deste quick (260909-lge): confirmado isolando o teste
`test_financeiro_props_inclui_servicos_contratados` e `Phase14FechamentoUiTest`, que
tinham a mesma causa raiz mascarada por uma asserção de escopo que eu já corrigi — aqui
o controller de origem (`/comercial/empresas`, provavelmente `ComercialController` ou
similar) nunca foi atualizado pós Plan 14-06. Fora do escopo dos arquivos deste plano
(`AdminController.php`, `Financeiro.jsx`) — não toquei.

**Ação sugerida:** abrir uma quick task dedicada para atualizar `/comercial/empresas`
(controller + form request) para o modelo N:N de `contratos_servico`, mesma migração que
`AdminController::fechamento()` já fez há tempo.
