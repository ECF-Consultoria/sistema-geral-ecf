---
quick_id: 261020-nsz
status: complete
---
# AG-01 — resumo

**Entregue (sem rota, sem tela, sem gatilho — nada roda sozinho ainda):**
- 3 tabelas novas: `pub_agente_configuracoes` (uma por conta canônica, nasce desligada, `versao` otimista), `pub_agente_configuracao_versoes` (snapshot de cada versão) e `pub_agente_execucoes` (uma VIVA por produto via coluna-sombra `chave_ativa` unique; `chave_logica` para deduplicar por fatos).
- `ConfiguracaoDoAgenteService`: salvar exige versão vista (`AG-01-VERSAO`); pausar/suspender/retomar não exigem versão e deixam snapshot.
- `PoliticaDoAgente`: lida do banco a cada chamada (desligamento sem deploy). Recusa desligado, pausado/suspenso, produto de outra conta, fora do escopo e fora da janela (janela só para origem automática). `recusaParaSeguir()` para o worker rechecar antes de cada etapa.
- `ExecucoesDoAgente`: admite sob lock da configuração da conta; pedido repetido devolve a viva; mesmos fatos já preparados devolvem a terminada; falha/cancelamento não seguram novo pedido; corrida no insert (23000) devolve a do outro processo.

**Testes:** `tests/Feature/Publicador/Agente/ConfiguracaoEExecucaoDoAgenteTest.php` — 14 passaram (53 asserções), SQLite. Um defeito real achado e corrigido no caminho: o snapshot da v1 gravava `controle` NULL porque a configuração nova não partia dos defaults.

**Pendente:**
- Prova da migration no MariaDB local (`migrate --path` só deste arquivo) — o MariaDB local estava desligado em 20/10; não foi iniciado por esta sessão.
- Diferença pausado × suspenso para chamada em voo, reserva de limite diário/teto de fila e heartbeat: AG-06.
