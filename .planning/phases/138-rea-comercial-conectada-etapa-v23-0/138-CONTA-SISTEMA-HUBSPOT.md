# Fase 138 — Conta de sistema "Sistema HubSpot" (D-17)

Ator da transição de nascimento na etapa 1 quando o webhook do HubSpot roda
sem sessão autenticada. Criada por `php artisan hubspot:criar-usuario-sistema --apply`.

- **id_local:** 49
- **id_vps:** (preencher no plano 138-09, depois de rodar o mesmo comando na VPS)
- **email:** sistema.hubspot@ecfconsultoria.com.br
- **criado_em:** 2026-09-03
- **role:** consultor (menor valor do enum — nunca admin)
- **active:** false (defesa em profundidade)
- **não-logável de fato por:** senha aleatória de 64 caracteres, hasheada e descartada
  na criação — NÃO pelo `active=false` (o login deste projeto não checa `users.active`).
- **sem `user_setores` e sem `company_users`** — nenhuma permissão efetiva.

⚠️ Lembrete registrado por escrito (D-17): não deixe esta conta virar login
esquecido. Precedente: usuário de review da Shopee (`users.id=30`), ativo em
produção desde 2026-07-16 porque ninguém anotou que precisava sair.

Depois de criar em cada ambiente, colocar `HUBSPOT_WEBHOOK_USER_ID={id}` no
`.env` daquele ambiente (local e VPS, separadamente).
