---
quick_id: 260601-ml1
slug: oauth-ml-resiliencia
status: complete
completed: 2026-06-01
commits: [563b062, 8d21fed]
---

# Summary — Resiliência OAuth Mercado Livre

## Problema 1 — Revogação indevida (causa-raiz + correção)

**Causa-raiz:** `MercadoLivreService::refreshToken()` marcava `status='revoked'`
em **qualquer** resposta não-2xx. Um erro transitório (5xx, 429 rate-limit,
timeout, falha de rede) no cron `ml:refresh-tokens` (08:00) revogava
permanentemente. Como `ensureValidToken()` retorna null para `revoked` e o cron
só busca `active`, **nunca havia recuperação** — exatamente o sintoma reportado.

**Correções (`app/Services/MercadoLivreService.php`):**
- Revoga **somente** em `invalid_grant`. Transitórios mantêm `active` e lançam
  exceção retentável. `ConnectionException` (rede) também não revoga.
- Serializa a renovação com `Cache::lock("ml-refresh-{companyId}")` — o refresh
  token do ML é *single-use*; sem isso, cron + sync manual podiam usar o mesmo
  token e o segundo levava `invalid_grant`. Recarrega do banco após o lock e
  pula refresh redundante (já renovado há <30s e longe de expirar).
- Refresh bem-sucedido de um token `revoked` o **reativa**.

**Recuperação (`app/Console/Commands/RefreshMlTokens.php`):**
- O cron passa a tentar reativar revogados recentes (≤7 dias) automaticamente.
- Flag `php artisan ml:refresh-tokens --recover` força retry de **todos** os
  revogados (uso único para reviver clientes revogados há mais tempo).

✅ **Storage do refresh token está correto** (text + cast `encrypted` + fillable,
salvo em `saveToken` e `refreshToken`). Não era a causa.

## Problema 2 — Display de expiração

`expires_at` é do **access token (6h)**, não da conexão. UI ajustada:
- `Companies/Show`: bloco "Conexão ativa — renovação automática" em destaque;
  `Expira em` → `Próxima renovação do token`.
- `MlOAuth/Index`: `token expira {data}` → `renovação automática`.

## Problema 3 — Consentimento OAuth (sem bug)

A auth URL está **correta** conforme a doc (auth.mercadolivre.com.br/authorization
+ PKCE S256). Confirmado com o usuário: o cliente "cai logado, sem ver
permissões" porque **já autorizou o app antes** — o ML pula a tela de
consentimento (comportamento padrão OAuth). Não há `prompt=consent` confiável no
ML. Adicionada **nota explicativa** no modal do link. Nenhuma mudança de fluxo.

## Problema 4 — Inconsistência de IDs

`ml_user_id` do token é o Seller ID autoritativo (= Cust ID). O callback só
preenchia `ml_store_id` se vazio (não corrigia divergência).
- **Callback** agora grava `ml_store_id = ml_user_id` sempre (sobrescreve
  divergência; auditado pelo Spatie activitylog; log da correção). Tela de
  resultado informa o ajuste automático.
- **UI** `Dados da Empresa`: um único "Cust ID (Seller ID ML)"; campos Adman
  legados ocultos quando vazios.

## Arquivos
| Arquivo | Problema |
|---------|----------|
| `app/Services/MercadoLivreService.php` | 1 (refresh resiliente + lock) |
| `app/Console/Commands/RefreshMlTokens.php` | 1 (auto-recuperação + `--recover`) |
| `app/Http/Controllers/MercadoLivreOAuthController.php` | 4 (ml_store_id canônico) |
| `resources/views/oauth/ml-result.blade.php` | 4 (mensagem de ajuste) |
| `resources/js/Pages/Companies/Show.jsx` | 2, 3, 4 |
| `resources/js/Pages/MlOAuth/Index.jsx` | 2 |

## Verificação
- `php -l` ok nos 3 PHP; `npm run build` ✓ (4356 módulos, sem erros).

## Follow-up / ações no servidor
- **Reviver o cliente revogado:** após o deploy, rodar na VPS
  `php artisan ml:refresh-tokens --recover` (ou aguardar o cron das 08:00, que
  agora recupera revogados ≤7d). Se o refresh token ainda for válido, a conta
  reativa sozinha; se o cliente revogou de fato no ML, precisará reconectar.
- **Não deployado** — aguardando autorização explícita.

## Commits
- `563b062` — fix(ml-oauth): resiliência no refresh de token e ID canônico
- `8d21fed` — feat(ml-oauth): status amigável, Cust ID único e nota de consentimento
(branch `quick/260601-ml-oauth-resiliencia`)
