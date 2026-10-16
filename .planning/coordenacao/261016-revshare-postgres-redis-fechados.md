# Revshare: Postgres e Redis fechados para a internet — 16/10/2026, 11:17

**Para:** quem mantém o revshare (`/opt/revshare` na VPS `177.7.53.164`, fora do git) e todos os devs.
**Autorizado por:** usuário, na sessão da v28.0.

## O que estava errado

- `revshare-postgres` publicava `0.0.0.0:5433` e `[::]:5433`; `revshare-redis` publicava `0.0.0.0:6380` e `[::]:6380`.
- `ufw` **desligado** e cadeia `DOCKER-USER` vazia: as duas portas respondiam de qualquer lugar da internet
  (testado de fora).
- **Redis sem senha e com `protected-mode no`**: qualquer pessoa conectava, lia e apagava. Postgres exigia senha
  (`scram-sha-256`), mas ficava exposto a força bruta.

## O que foi feito

- Backup: `/opt/revshare/docker-compose.yml.bak-20261016141710`.
- `docker-compose.yml`: `"5433:5432"` → `"127.0.0.1:5433:5432"` e `"6380:6379"` → `"127.0.0.1:6380:6379"`.
- `docker compose up -d --no-deps postgres redis` — só os dois containers; dados nos volumes nomeados
  (`revshare_revshare_pg_data`, `revshare_revshare_redis_data`).

## Conferido depois

- 5433 e 6380 **fechadas de fora**; escutando só em `127.0.0.1`.
- Postgres `healthy`, 24 tabelas; Redis `healthy`, 44 chaves.
- `revshare-agno-api` alcança `postgres:5432` e `redis:6379` pela rede interna (é assim que os apps se conectam —
  `DATABASE_URL`/`REDIS_URL` usam os nomes dos serviços, não as portas do host).
- `revshare-agno-arq-worker` reiniciou 1 vez no instante da recriação e voltou (`arq_worker_started` 11:17:19), sem
  erro depois.
- ECF Admin: HTTP 200.

## Para quem precisar acessar o banco de fora

Use túnel SSH: `ssh -L 5433:127.0.0.1:5433 root@177.7.53.164` e conecte em `localhost:5433`.
**Não volte a publicar em `0.0.0.0`.** Se o Redis precisar ser exposto algum dia, antes ponha `requirepass`.

## Ainda abertos na VPS (não mexidos — decidir com os donos)

`2222` (`ecf-sftp-mock`), `631` (cupsd, impressão), `49821` (openclaw da Hostinger), `3000`/`3001` (node/next direto,
sem nginx), `3101`/`3102` (revshare web/api direto). E: senha root no git público, `PermitRootLogin yes`,
`PasswordAuthentication yes`, fail2ban inativo — ver a conversa da v28.0 de 16/10.
