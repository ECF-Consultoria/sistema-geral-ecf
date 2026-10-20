---
quick_id: 261020-oeg
type: quick
---
# AG-03 — produtor de ofertas para a Aprovação (D1)

Fatia AG-03 de `PLANO_AGENTE_ECF_MVP_CLAUDE_CODE.md`. Critério: D1 demonstrado (produto completo chega à Aprovação com fotos e motivo rastreáveis, sem POST de publicação); repetir mantém os mesmos IDs; a fila da loja encontra a oferta; nenhuma aprovação automática.

Escrita: `app/Services/Publicador/Agente/PreparadorDaAprovacao.php`, `app/Console/Commands/PublicadorAgenteEncaminhar.php`, agenda em `routes/console.php`, `textoEmAndamento()` em `PreparoIaDoRascunhoService`, ajustes em `EntradaDoPreparo`/`ElegibilidadeDoAgente`, testes em `tests/Feature/Publicador/Agente/`. Nada em `app/Services/Publicador/Aprovacao/` nem da Fase 189.
