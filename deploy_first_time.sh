#!/bin/bash
# ─── Primeiro deploy — roda UMA VEZ apenas ───────────────────────────────────
SSH_HOST="PREENCHER"
SSH_USER="u315409235"
SSH_PORT="22"
REMOTE_PATH="PREENCHER"       # ex: /home/u315409235/ecf_admin

# ─────────────────────────────────────────────────────────────────────────────
set -e

echo "▶ Build dos assets..."
npm run build

echo "▶ Enviando projeto completo (exceto vendor e node_modules)..."
rsync -az --no-perms --progress -e "ssh -p $SSH_PORT" \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='.env' \
  --exclude='storage/logs/*.log' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/cache/data/*' \
  . "$SSH_USER@$SSH_HOST:$REMOTE_PATH/"

echo "▶ Enviando .env de produção..."
scp -P "$SSH_PORT" .env.production "$SSH_USER@$SSH_HOST:$REMOTE_PATH/.env"

echo "▶ Instalando dependências PHP no servidor..."
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" \
  "cd $REMOTE_PATH && composer install --no-dev --optimize-autoloader --no-interaction"

echo "▶ Permissões de storage..."
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" \
  "chmod -R 775 $REMOTE_PATH/storage $REMOTE_PATH/bootstrap/cache"

echo "▶ Rodando migrations..."
ssh -p "$SSH_HOST" "$SSH_USER@$SSH_HOST" \
  "cd $REMOTE_PATH && php artisan migrate --force"

echo "▶ Caches de produção..."
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" \
  "cd $REMOTE_PATH && php artisan config:cache && php artisan route:cache && php artisan view:cache"

echo ""
echo "✅ Primeiro deploy concluído!"
echo ""
echo "Próximo passo: adicionar cron job no hPanel:"
echo "* * * * * /usr/local/bin/php $REMOTE_PATH/artisan schedule:run >> /dev/null 2>&1"
