#!/bin/bash
# ─── Deploy automático ECF Admin → Hostinger ─────────────────────────────────
# Uso: bash deploy.sh
# Roda build local, sobe apenas os arquivos que mudam em cada deploy.

SSH_HOST="77.37.127.198"
SSH_USER="u315409235"
SSH_PORT="65002"
SSH_PASS="mb.ECF-100376"
SSH_HOSTKEY="SHA256:4G8V3JVk0PJ9HkghFh3CaXSt5ed87NKOM4EUA07ZOnw"
REMOTE="/home/u315409235/domains/ecfconsultoria.com.br/public_html/ecf_admin"
PLINK="/c/xampp/htdocs/ecf_admin/plink.exe"
PSCP="/c/xampp/htdocs/ecf_admin/pscp.exe"

# Chave PPK local para acesso SFTP do Mercado Livre
ML_KEY_LOCAL="$HOME/.ssh/id_rsa2.ppk"
ML_KEY_REMOTE="/home/u315409235/.ssh/id_rsa2.ppk"

set -e

echo "▶ Build..."
npm run build

echo "▶ Subindo public/build..."
"$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  -r public/build/ "$SSH_USER@$SSH_HOST:$REMOTE/public/build/"

echo "▶ Subindo app/..."
"$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  -r app/ "$SSH_USER@$SSH_HOST:$REMOTE/app/"

echo "▶ Subindo routes/..."
"$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  -r routes/ "$SSH_USER@$SSH_HOST:$REMOTE/routes/"

echo "▶ Subindo config/..."
"$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  -r config/ "$SSH_USER@$SSH_HOST:$REMOTE/config/"

echo "▶ Subindo database/..."
"$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  -r database/ "$SSH_USER@$SSH_HOST:$REMOTE/database/"

echo "▶ Subindo resources/..."
"$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  -r resources/ "$SSH_USER@$SSH_HOST:$REMOTE/resources/"

echo "▶ Subindo composer.json e composer.lock..."
"$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  composer.json composer.lock "$SSH_USER@$SSH_HOST:$REMOTE/"

# Sobe a chave PPK do ML para o servidor (necessária para o sync SFTP de grants)
if [ -f "$ML_KEY_LOCAL" ]; then
  echo "▶ Subindo chave PPK do Mercado Livre para o servidor..."
  "$PLINK" -ssh -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
    "$SSH_USER@$SSH_HOST" "mkdir -p /home/u315409235/.ssh && chmod 700 /home/u315409235/.ssh"
  "$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
    "$ML_KEY_LOCAL" "$SSH_USER@$SSH_HOST:$ML_KEY_REMOTE"
  "$PLINK" -ssh -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
    "$SSH_USER@$SSH_HOST" "chmod 600 $ML_KEY_REMOTE"
  echo "   Chave enviada para $ML_KEY_REMOTE"
else
  echo "   [aviso] Chave PPK não encontrada em $ML_KEY_LOCAL — ignorando."
fi

echo "▶ Atualizando variáveis ML_SFTP no .env do servidor (via PHP)..."

# Gera script PHP que atualiza o .env sem problemas de quoting
cat > /tmp/update_ml_env.php << 'PHPEOF'
<?php
$f = '/home/u315409235/domains/ecfconsultoria.com.br/public_html/ecf_admin/.env';
$env = file_exists($f) ? file_get_contents($f) : '';

$vars = [
    'ML_SFTP_HOST'             => 'sftp.mercadolibre.io',
    'ML_SFTP_PORT'             => '22',
    'ML_SFTP_USER'             => 'dev01',
    'ML_SFTP_PRIVATE_KEY_PATH' => '/home/u315409235/.ssh/id_rsa2.ppk',
    'ML_SFTP_PASSPHRASE'       => '',
    'ML_SFTP_ROOT'             => '/sftp_seller_analytics_mlb_full_potential/ContatosCPP/',
    'ML_SFTP_GRANTS_FILE'      => 'ECF CONSULTORIA & ASSESSORIA (6).xlsx',
];

// Remove todas as ocorrências existentes das chaves (inclusive duplicatas corrompidas)
foreach (array_keys($vars) as $k) {
    $env = preg_replace('/^' . preg_quote($k, '/') . '=.*$/m', '', $env);
}

// Limpa linhas em branco extras
$env = preg_replace('/\n{3,}/', "\n\n", rtrim($env));

// Adiciona bloco ML_SFTP limpo no final
$env .= "\n\n# Mercado Livre SFTP — Grants\n";
foreach ($vars as $k => $v) {
    $env .= $k . '="' . $v . "\"\n";
}

file_put_contents($f, $env);
echo "Env atualizado com sucesso.\n";
PHPEOF

# Sobe o script e executa no servidor
"$PSCP" -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  /tmp/update_ml_env.php "$SSH_USER@$SSH_HOST:/tmp/update_ml_env.php"
"$PLINK" -ssh -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  "$SSH_USER@$SSH_HOST" "php /tmp/update_ml_env.php && rm /tmp/update_ml_env.php"

rm -f /tmp/update_ml_env.php

echo "▶ Rodando composer install, migrations e limpando caches no servidor..."
"$PLINK" -ssh -P "$SSH_PORT" -pw "$SSH_PASS" -hostkey "$SSH_HOSTKEY" \
  "$SSH_USER@$SSH_HOST" \
  "cd $REMOTE && composer install --no-dev --optimize-autoloader --no-interaction && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && echo 'Deploy OK'"

echo ""
echo "✅ Deploy concluído! https://admin.ecfconsultoria.com.br"
