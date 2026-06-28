# Telaris instance — Docker image (DRAFT scaffold, 2026-06-04).
#
# One Dockerfile, two runtime targets:
#   app  — php-fpm 8.3 + the Telaris code + media tooling (the federation node)
#   web  — nginx serving the static code and proxying PHP to app:9000
#
# The bundled DB (PostgreSQL) and the auto-TLS proxy (Caddy) are stock images,
# wired in docker-compose.yml. See docker/README.md.
#
# Status: scaffold for review. Marked TODO where a value needs confirming.

# ---------------------------------------------------------------------------
# base: php-fpm + extensions + the binaries inc/media-optimize.php shells out to
# ---------------------------------------------------------------------------
FROM php:8.3-fpm AS base

# media-optimize.php calls: convert (ImageMagick), cwebp, ffmpeg, jpegoptim,
# optipng. gd handles in-process image work; pdo_pgsql + apcu + sodium are the
# load-bearing extensions (apcu = rate limiting; sodium = federation signing,
# bundled+enabled in the official php:8.3 image). cron drives the schedulers.
# libpq-dev builds pdo_pgsql (and leaves libpq for it at runtime); the
# postgresql-client gives operators psql / pg_dump inside the container.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libzip-dev \
        imagemagick webp ffmpeg jpegoptim optipng \
        libpq-dev postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd opcache exif zip \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && printf 'apc.enable_cli=1\n' > /usr/local/etc/php/conf.d/zz-apcu-cli.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps first for layer caching. composer.lock is gitignored in this
# repo, so this resolves fresh; --no-scripts since there are no build scripts.
# Fail the build if deps do not install: a half-built image (missing PHPMailer,
# swagger-php) would only break at runtime, which is worse than failing here.
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

# App code (vendor/, config.php, secrets/, uploads/, etc. excluded via .dockerignore).
COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-telaris.ini
# clear_env=no so php-fpm workers can read DB_*/MAIL_* from the container env
# (config.php uses getenv(), so no secret value is ever written into a file).
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-telaris.conf
COPY docker/render-config.sh /usr/local/bin/render-config.sh
RUN chmod +x /usr/local/bin/render-config.sh

# ---------------------------------------------------------------------------
# app: php-fpm runtime (entrypoint renders config.php + first-run secrets)
# ---------------------------------------------------------------------------
FROM base AS app
COPY docker/entrypoint-app.sh /usr/local/bin/entrypoint-app.sh
COPY docker/entrypoint-cron.sh /usr/local/bin/entrypoint-cron.sh
RUN chmod +x /usr/local/bin/entrypoint-app.sh /usr/local/bin/entrypoint-cron.sh
ENTRYPOINT ["/usr/local/bin/entrypoint-app.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# web: nginx serving the static code, proxying .php + /uploads/ to app:9000
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web
COPY --from=base /var/www/html /var/www/html
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
