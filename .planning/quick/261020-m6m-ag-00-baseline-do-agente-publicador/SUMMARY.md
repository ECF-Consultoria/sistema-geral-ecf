---
quick_id: 261020-m6m
status: complete
---
# AG-00 — resumo

- Intenção durável: adotada a forma da Fase 193 (`fila_item_id` + reconciliador); sem outbox.
- Saem do plano do agente: G1 e a intenção durável (cobertos pela 193). Ficam: G5 (versão obrigatória), G3 (granularidade), AG-05b (bypass), G8 (promoção herdada), G9 (UNKNOWN), G10 (EditorEmUso).
- Pendente de decisão: guarda "todo alvo ativo aprovado / piloto 1 alvo + 1 variante" como emenda ao 193-02 ou fatia separada.
- AG-03 vira o produtor de ofertas (nenhum código de produção cria `PubAprovacaoOferta` hoje); a 194 deve reutilizá-lo.
- AG-01..03 rodam em paralelo com a 189 (área própria). Integrar `origin/main` antes de mexer em `PublicacaoService`.
