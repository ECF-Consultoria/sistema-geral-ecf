---
quick_id: 261030-hsw
status: complete
---

# Resumo — 261030-hsw

- `151-CONTA-SISTEMA-HUBSPOT.md`: `id_vps: 41` preenchido.
- Learning novo: `.planning/learnings/hubspot-webhook-empresa-sem-etapa.md`.
- `CLAUDE.md`: entrada do learning na lista de leitura obrigatória.

Correção em produção (feita pelo usuário na VPS em 30/10, conferida por reconsulta ao banco): conta
"Sistema HubSpot" id 41, `HUBSPOT_WEBHOOK_USER_ID=41`, e as empresas 460, 464 e 468–474 em
`aguardando_administrativo`.

Suspeita descartada: "a idempotência não recria empresa apagada" é falsa. A FK `company_id_criada` é `ON DELETE SET NULL` (conferida em produção), e apagar a empresa libera o deal.
