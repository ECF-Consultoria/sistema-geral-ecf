---
quick_id: 261020-oeg
status: complete
---
# AG-03 — resumo

**Entregue:**
- `PreparadorDaAprovacao` — o primeiro código de produção que cria `PubAprovacaoOferta` (a 194 AUTO-01/02 deve reaproveitá-lo). Execução `preparando` → reavalia a elegibilidade com os fatos de agora → espera a cadeia de texto terminar (`textoEmAndamento`) → oferta do alvo da modalidade com as DUAS âncoras pelo `resolver` da própria fila (G4/decisão 17) → conjuntos por grupo das atribuições de foto e uma linha `pending` por foto original, principal = posição 0 → `mover(aguardando_aprovacao)` com ator de sistema. Execução termina `aguardando_aprovacao`.
- Idempotência: `firstOrCreate` em oferta (unique `alvo_id`, corrida tratada), conjunto e imagem — nunca `updateOrCreate`, então parecer humano (estado, quem, motivo) fica. Oferta que já saiu de `preparando` não é tocada. Nada aprova, nada publica.
- `empresa-N` sem Company → `bloqueada` (`conta_sem_fila`): a rota da Aprovação é por Company.
- Comando `publicador:agente-encaminhar` agendado todo minuto (`withoutOverlapping(5)->onOneServer()`); texto já em dia encaminha na hora pela `EntradaDoPreparo`.
- Elegibilidade: "foto utilizável" passou a exigir foto ATRIBUÍDA a um grupo (é o que vai ao anúncio e o que vira conjunto da revisão).

**Testes:** pasta `Agente` 28/28 (123 asserções) — D1 de ponta a ponta (pedir → cadeia → encaminhar), repetição sem oferta/conjunto/imagem/evento/versão a mais, parecer humano preservado, oferta em revisão intocada, modalidade desativada no meio bloqueia, comando agendado. Regressão: `Aprovacao/*`, `Lote/*`, `PreparoIaAoSalvarNoPortalTest`, `CriativosAutomaticosTest` — 430/430. Gates `186-04 unica-porta` e `oferta-do-portao` PASSARAM.

**Limites declarados:**
- Fatos que mudam DEPOIS de a oferta sair de `preparando` não chegam a ela por aqui (a versão da oferta não sobe por mudança do rascunho vinda do Portal). É o mesmo comportamento do domínio para qualquer oferta; endurecimento do contexto revisado é da AG-04'.
- Foto removida do rascunho depois da projeção não sai do conjunto pelo agente — fica para os serviços da revisão.
- D1 não foi demonstrado com dados reais: só em teste.
