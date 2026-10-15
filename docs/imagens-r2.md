# Imagens do Publicador no Cloudflare R2

Milestone v28.0 — `.planning/REQUIREMENTS-v28.md`.

## Como funciona

- As imagens **definitivas** do Publicador (upload manual e imagem de IA **aprovada**) vão para o disco
  configurado em `IMAGENS_DISK`. Em produção esse disco é o `r2`; em qualquer outro lugar fica `local`.
- O bucket é **privado**. Ninguém acessa uma imagem por URL direta:
  - o **Mercado Livre** recebe os bytes da imagem pelo upload (`POST /pictures/items/upload`);
  - o **navegador** recebe a imagem por um controller do Laravel, que confere a empresa antes de entregar.
- **Não vão para o R2:** a foto de referência enviada ao Gemini (efêmera, apagada em até 48 h) e as gerações de
  IA que não foram aprovadas.
- Um único bucket, com uma pasta por ambiente: `production/` e `dev/` (variável `R2_PREFIX`).

## Variáveis de ambiente

| Variável | O que é |
|---|---|
| `IMAGENS_DISK` | `local` (padrão) ou `r2` |
| `R2_ACCOUNT_ID` | Account ID da Cloudflare (monta o endpoint) |
| `R2_ACCESS_KEY_ID` | Access Key ID do token de API do R2 |
| `R2_SECRET_ACCESS_KEY` | Secret Access Key do token de API do R2 |
| `R2_BUCKET` | Nome do bucket |
| `R2_PREFIX` | `production` na VPS de produção, `dev` no resto |
| `R2_ENDPOINT` | Opcional — só se o endpoint não for o padrão da conta |
| `R2_CONNECT_TIMEOUT` / `R2_TIMEOUT` | Opcional — segundos (padrão 5 / 30) |

## Criar o bucket e a chave (painel da Cloudflare)

1. **R2 → Create bucket.** Nome sugerido: `ecf-imagens`. Deixe o acesso público **desligado**
   (não conecte domínio nem ative `r2.dev`).
2. **R2 → Manage R2 API Tokens → Create API token.** Permissão **Object Read & Write**, restrita ao bucket
   `ecf-imagens`. Copie o **Access Key ID** e o **Secret Access Key** (o segredo só aparece uma vez).
3. O **Account ID** aparece na página inicial do R2.
4. Coloque os valores **direto no `.env` da VPS** — nunca em chat, ticket ou commit.

⚠️ A chave do R2 vale para o bucket inteiro, não por pasta. Ela só existe no `.env` da VPS; máquinas de dev usam
o disco local e os testes automáticos usam `Storage::fake`.

## Virar a chave em produção

```bash
php artisan config:clear
php artisan imagens:testar-conexao --disco=r2   # tem de terminar em "Conexão OK"
# só então: IMAGENS_DISK=r2 no .env
php artisan config:cache
```

**Rollback:** voltar `IMAGENS_DISK=local` e `php artisan config:cache`. Enquanto a migração do legado não
terminar (Fase 200), os arquivos antigos continuam no disco local e seguem sendo lidos de lá.
