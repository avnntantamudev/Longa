#!/bin/sh
# Arranque do contentor "app": prepara o projeto na primeira execução e inicia o PHP-FPM.
set -e

rm -f storage/framework/.ready

if [ ! -f .env ]; then
    echo "[longa] .env não encontrado: a copiar de .env.example"
    cp .env.example .env
fi

if [ ! -f vendor/autoload.php ]; then
    echo "[longa] A instalar dependências PHP..."
    composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "[longa] A gerar APP_KEY..."
    php artisan key:generate --force
fi

php artisan storage:link >/dev/null 2>&1 || true
php artisan migrate --force

touch storage/framework/.ready
echo "[longa] Pronto."

exec php-fpm
