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

## Confirmação por reconsulta ao banco (plano 138-09, Task 1)

Nunca pelo que o comando imprimiu na criação — reconsultado agora, direto no MariaDB local
(`DB_DATABASE=ecf_admin`), por `SELECT` explícito:

```sql
SELECT id, name, email, role, active FROM users WHERE email='sistema.hubspot@ecfconsultoria.com.br';
```

Resultado: `{"id":49,"name":"Sistema HubSpot","email":"sistema.hubspot@ecfconsultoria.com.br","role":"consultor","active":0}`

- `user_setores` para `user_id=49`: **0 linhas**
- `company_users` para `user_id=49`: **0 linhas**
- `.env` local: `HUBSPOT_WEBHOOK_USER_ID=49` — confirmado, bate com o id reconsultado

## Suíte da fase × baseline pré-migration (`138-BASELINE-TESTES.md`) — medido 2026-09-09

| Suíte | Baseline (`138-01`) | Medido agora (`138-09`) | Veredito |
|---|---|---|---|
| `tests/Unit/Phase138 tests/Feature/Phase138` | não existia ainda | **72 tests, 291 assertions — OK** | verde, sem baseline anterior para comparar (suíte nova da própria fase) |
| `tests/Feature/Phase131 tests/Unit/Phase137 tests/Feature/Phase137 tests/Feature/Phase34HubspotWebhookTest.php` | — (comando de wave não media este conjunto exato) | **186 tests, 675 assertions — OK** | verde, nenhuma regressão |
| `tests/Feature/Phase37CompaniesPerformanceFilterTest.php` + `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` | 24 tests, 44 assertions — OK | **24 tests, 44 assertions — OK** | idêntico à baseline |
| `tests/Feature/Phase38/PolosControllerTest.php` | 6 falhas (12 testes, 158 assertions) | **6 falhas (12 testes, 158 assertions)** — mesmos 6 nomes: `test_meta_por_estagio`, `test_status_sim`, `test_status_em_progresso`, `test_status_problema_precedencia`, `test_status_dist`, `test_filtro_por_mes` | idêntico à baseline — não é regressão da 138 |
| `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` | 2 erros + 2 falhas (4 testes, 32 assertions) | **2 erros + 2 falhas (4 testes, 32 assertions)** — mesmas causas (`ArgumentCountError` em `SyncPolosFaturamentoJob::handle()` e faturamento zerado) | idêntico à baseline — não é regressão da 138 |

Nota sobre exit code: `138-BASELINE-TESTES.md` registra exit code 0 para os comandos de Polos com
falha; nesta execução (mesmo PHPUnit 11.5.55) os mesmos comandos saíram com exit code 1 (Polos
controller) e 2 (Polos snapshot). O **conteúdo** da falha (contagem de testes/assertions, nomes
dos testes, causa raiz) é idêntico ao registrado — a diferença de exit code é do executor, não do
código sob teste, e não muda o veredito de "não é regressão".

`npm run build`: exit code 0. `public/build/manifest.json` contém `resources/js/Pages/Admin/Contratos.jsx`,
`resources/js/Pages/Comercial/Entrada.jsx` e o chunk compartilhado `AppLayout` (`_AppLayout-zlHHTMFF.js`,
importado por praticamente toda página). `public/hot` não existe no worktree.
