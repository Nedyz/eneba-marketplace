# Production image for the Laravel API (built and run by Render).
#
# Base image: serversideup/php "fpm-nginx" = PHP-FPM + nginx in one container,
# tuned for Laravel, runs as the unprivileged www-data user, listens on 8080.
# PHP 8.4 matches local development. pdo_pgsql and Composer are included.
FROM serversideup/php:8.4-fpm-nginx

# Build steps run as root (Composer needs to write vendor/), the app runs as www-data.
USER root
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# 1) Dependencies first, in their own layer: this layer is only rebuilt when
#    composer.json/lock change, not on every code change (faster builds).
#    --no-dev: no PHPUnit, Faker etc. in production.
#    --no-scripts/--no-autoloader: the app code is not copied yet.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# 2) Application code (.dockerignore keeps out .env, vendor, tests...).
COPY . .

# 3) Optimized class map, then package discovery (post-autoload-dump script).
#    Finally hand all files to www-data, the user the app runs as.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && chown -R www-data:www-data /var/www/html

USER www-data

# Non-secret production defaults. Secrets and URLs (APP_KEY, DB_URL,
# APP_URL, FRONTEND_URL) are set in the Render dashboard, never here.
# - LOG_CHANNEL=stderr: Render's filesystem is ephemeral; logs to stderr show in the dashboard.
# - SESSION_DRIVER=array: the API is stateless (bearer tokens); no session row per request.
ENV APP_NAME="Eneba Marketplace" \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=array \
    PHP_OPCACHE_ENABLE=1

# serversideup "Laravel automations", run on every container start, in order:
# wait for the DB (up to 30 s) -> php artisan migrate --force -> php artisan optimize.
# If any step fails, the container exits before nginx starts, so the new version
# never passes Render's health check and never receives traffic (DECISIONS.md, D19).
# optimize (config/route caches) runs at start, not at build time, because Render
# only provides the environment variables at runtime.
# - STORAGE_LINK=false: no public file uploads, so no storage symlink needed.
# - VIEW_CACHE=false: JSON API without Blade views; view:cache fails when
#   resources/views does not exist (found by simulating the startup locally),
#   which would stop the container. The script then runs "optimize --except=views".
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_STORAGE_LINK=false \
    AUTORUN_LARAVEL_VIEW_CACHE=false

EXPOSE 8080
