---
quick_id: 261020-nzr
type: quick
---
# AG-02 — elegibilidade e entrada única do preparo

Fatia AG-02 de `PLANO_AGENTE_ECF_MVP_CLAUDE_CODE.md`. Critério: produto completo prepara; incompleto informa motivos antes da IA; edição manual preservada; save + manual simultâneos não duplicam etapa.

Escrita: `app/Services/Publicador/Agente/{ElegibilidadeDoAgente,EntradaDoPreparo}.php`, `tests/Feature/Publicador/Agente/EntradaDoPreparoDoAgenteTest.php` e UMA linha (+import) em `PreparoIaDoRascunhoService::preparar()` — o gancho do save. Nada da Fase 189.
