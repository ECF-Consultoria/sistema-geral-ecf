# Acervo de imagens no Cloudflare R2 (v28.0) — o que não dá para deduzir do código

Leitura obrigatória antes de mexer em `ImagemAssetService`, `ArquivosDeImagem`, `acervo_imagens`, nos comandos
`acervo:*` ou nas credenciais do R2. Virada em produção feita em 16/10/2026.

## Como está montado (resumo)

- **Fotos definitivas** do Publicador (upload manual e IA **aprovada**) gravam no disco `filesystems.imagens`
  (`IMAGENS_DISK`, hoje `r2` em produção). Bucket `ecfadmin`, prefixo `production/`, **privado**.
- **O Mercado Livre recebe BYTES** (`/pictures/items/upload`), nunca URL nossa — por isso não há URL pública nem
  CORS. **Apagar no R2 não derruba anúncio publicado**: o ML guarda a própria cópia.
- **Foto de referência do Gemini e geração não aprovada ficam no disco local** (transitórias), de propósito.
- Toda leitura/gravação de `pub_imagens.caminho` passa por `ArquivosDeImagem`: grava no disco das imagens, lê dele
  e cai no `local` e depois no `r2` (quando o bucket está configurado). É isso que torna o rollback seguro.
- `acervo_imagens` = um registro por imagem por **conta do ML** (`c{company}`/`m{mlb_empresa}`), único por sha256.
  `pub_imagens` é cada USO. Remover a foto do anúncio desfaz o uso; o objeto só sai pela exclusão do acervo.

## Armadilhas que já custaram tempo

1. **A VPS sai pela internet também por IPv6** (`2a02:4780:75:7408::1`). Token do R2 com filtro só no IPv4
   `177.7.53.164` dá `AccessDenied` no `PutObject` — o pedido sai por IPv6. Se filtrar IP no token, incluir os dois.
2. **`.env.example` é versionado e NÃO é lido pela aplicação.** Credencial colada nele não funciona e vai para o
   GitHub no próximo commit. Credencial vai só no `.env` da VPS.
3. **`tinker` em produção** precisa de `HOME=/tmp XDG_CONFIG_HOME=/tmp` (o PsySH tenta gravar em `/var/www/.config`).
4. **Depois de mexer no `.env` da VPS:** `php artisan config:cache`, `chown www-data` no `bootstrap/cache/config.php`
   e `sudo -u www-data php artisan queue:restart` — senão os workers seguem com o disco antigo.
5. **Instalar pacote com `--ignore-platform-reqs`** (como o `deploy.sh`) pode puxar versão que exige PHP 8.4; a
   produção é 8.2. O `symfony/filesystem` foi fixado em 7.4 por isso. Conferir o `require.php` de pacote novo no lock.
6. **Comando Artisan com estado em propriedade** precisa zerar no início do `handle()`: o Artisan reaproveita a
   instância entre chamadas no mesmo processo (o dry-run vazava para a rodada real nos testes).

## Pendências com data

- **A partir de 15/11/2026:** `acervo:limpar-local-migrado` (simulação) → `--aplicar` apaga da VPS as cópias locais
  dos 49 objetos migrados em 16/10 (~130 MB com as cópias redundantes).
- `acervo:limpar-geracoes` (gerações de IA não aprovadas > 30 dias) existe mas **não está agendado**.
- "Conta inativa" para retenção ainda não foi definida pela operação — `acervo:relatorio` usa critério provisório e
  **só lista**.

Mais detalhes: `docs/imagens-r2.md` (operação) e `.planning/REQUIREMENTS-v28.md` (decisões D1..D9).
