---
quick_id: 261020-nsz
type: quick
---
# AG-01 — configuração, controle e execução do agente por loja

Fatia AG-01 de `PLANO_AGENTE_ECF_MVP_CLAUDE_CODE.md` (baseline em `.planning/quick/261020-m6m-ag-00-baseline-do-agente-publicador/AG-00-BASELINE.md`).

Critério de fechamento: duas contas isoladas; dupla solicitação devolve uma execução; conta desligada não admite preparo; alteração concorrente de configuração recusa.

Escrita delimitada (nenhum arquivo compartilhado com a Fase 189): migration `2026_10_20_100000_create_pub_agente_tables.php`, models `PubAgenteConfiguracao`/`PubAgenteConfiguracaoVersao`/`PubAgenteExecucao`, `app/Services/Publicador/Agente/{ConfiguracaoDoAgenteService,PoliticaDoAgente,ExecucoesDoAgente}.php`, `tests/Feature/Publicador/Agente/ConfiguracaoEExecucaoDoAgenteTest.php`.
