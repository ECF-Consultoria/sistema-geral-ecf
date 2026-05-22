#!/bin/bash
# ─── Deploy ECF Admin → VPS Hostinger ────────────────────────────────────────
# Uso: bash deploy.sh
# Fluxo: git push → VPS faz git pull → npm build na VPS → artisan migrate
#
# PRÉ-REQUISITO: commitar e fazer push para o GitHub antes de rodar este script.
# O deploy puxa exatamente o que está em origin/main — nada mais, nada menos.

VPS_HOST="177.7.53.164"
VPS_USER="root"
VPS_PORT="22"
VPS_PASS="ECF-100376vps"
VPS_HOSTKEY="SHA256:rA83w6TmPmvPKqDsjUNZclPFdeiZq4mduyBUhNzvKc8"
REMOTE="/var/www/ecf_admin"
# Plink ao lado do script (portátil entre máquinas — funciona pra qualquer
# checkout, seja /c/xampp/htdocs/ecf_admin/ ou outro path).
PLINK="$(dirname "$0")/plink.exe"

SSH_ARGS=(-ssh -P "$VPS_PORT" -pw "$VPS_PASS" -hostkey "$VPS_HOSTKEY" -batch)

set -e

# Garante que não há mudanças rastreadas pendentes (ignora arquivos não rastreados)
if [ -n "$(git status --porcelain | grep -v '^??')" ]; then
  echo "❌ Há mudanças não commitadas. Faça commit e push antes de deployar."
  exit 1
fi

echo "▶ Verificando que o push já foi feito..."
LOCAL=$(git rev-parse HEAD)
REMOTE_SHA=$(git rev-parse origin/main 2>/dev/null || echo "")
if [ "$LOCAL" != "$REMOTE_SHA" ]; then
  echo "❌ HEAD local ($LOCAL) difere de origin/main ($REMOTE_SHA)."
  echo "   Execute: git push origin main"
  exit 1
fi

echo "▶ Deploy via git pull na VPS..."
"$PLINK" "${SSH_ARGS[@]}" "$VPS_USER@$VPS_HOST" "
  set -e
  cd $REMOTE

  # Puxa exatamente o que está no GitHub
  git fetch origin main
  git reset --hard origin/main

  # Build do frontend com os arquivos corretos da VPS
  npm install --no-audit --no-fund --silent 2>/dev/null || true
  npx vite build

  # Pós-deploy
  composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs
  php artisan migrate --force
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  chown -R www-data:www-data $REMOTE
  supervisorctl restart ecf-worker:*
  echo 'Pós-deploy OK'
"

echo ""
echo "✅ Deploy VPS concluído! https://admin.ecfconsultoria.com.br"
