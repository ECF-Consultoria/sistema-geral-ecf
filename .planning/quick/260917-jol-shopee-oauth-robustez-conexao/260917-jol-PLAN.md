---
quick_id: 260917-jol
slug: shopee-oauth-robustez-conexao
date: 2026-09-17
---

# Shopee OAuth: robustez e visibilidade da conexão

## Contexto (diagnóstico em produção, 2026-09-17)

- As 17 lojas com token (ERP + Ads) estão `active` e foram renovadas hoje (11:15 / 11:30). Nenhuma conexão foi perdida.
- "Link expirado" no painel `/shopee-oauth` = 10 empresas com convite de 7 dias gerado entre 22/07 e 03/08 e **zero tokens** — o cliente nunca concluiu. Nenhum acesso a `/shopee/conectar` ou ao callback nos últimos 15 dias de log do nginx.
- Bug de tela: depois de "Regerar link" o badge continua "Link expirado" (o front não atualiza a expiração) — a Visammer teve 7 cliques seguidos hoje.
- Ponto cego: a renovação só acontece no sync diário; falha de renovação vira `Log::warning`, que produção (`LOG_LEVEL=error`) descarta, e o painel segue "Conectada".

## Tarefas

1. **Migration** `shopee_tokens.last_error` (text, nullable) + `last_error_at` (timestamp, nullable).
2. **ShopeeService::refreshToken** — parâmetro `$force`; grava `last_error`/`last_error_at` em erro (transitório, de conexão ou revogação) e limpa no sucesso; falhas passam a `Log::error`.
3. **Comando `shopee:refresh-tokens`** — keep-alive independente do sync: renova tokens `active` com `last_refreshed_at` > 12h (ERP e Ads) e tenta recuperar revogados há ≤ 7 dias; agendado 03:00.
4. **Painel** — `initiate()` devolve `expires_at`; o front atualiza a expiração ao regerar; conectadas mostram "renovado há X", status do Ads e alerta "Falha na renovação" (erro mais novo que a última renovação, ou renovação > 36h); texto "Convite expirado — cliente não concluiu".
5. **Testes Feature** — refresh com erro grava `last_error`; sucesso limpa; comando renova só os antigos; `initiate` devolve `expires_at`.
6. `npm run build`. Sem deploy.
