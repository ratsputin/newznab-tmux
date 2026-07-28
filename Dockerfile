FROM composer:latest AS composer-base
FROM dunglas/frankenphp:1-php8.5

LABEL maintainer="ratsputin"

ENV APP_PORT=80
ENV SERVER_NAME=:${APP_PORT:-80}
ARG MYSQL_CLIENT="mariadb-client"

WORKDIR /app

# 1. Install Node.js safely without overwriting FrankenPHP system binaries
COPY --from=node:22 /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22 /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY --from=composer-base --link /usr/bin/composer /usr/bin/composer

# 2. System Packages & MediaArea Repo (Single clean layer)
RUN apt-get update && apt-get install -y --no-install-recommends \
        unrar-free lame libcap2-bin python3 gettext-base \
        curl zip unzip git nano bash-completion sudo wget tmux time fonts-powerline \
        gnupg dnsutils jq htop iputils-ping net-tools ffmpeg \
        jpegoptim webp optipng pngquant libavif-bin watch iproute2 nmon \
        $MYSQL_CLIENT \
        libdbi-perl libdbd-mysql-perl libterm-readkey-perl \
 && wget https://mediaarea.net/repo/deb/repo-mediaarea_1.0-27_all.deb \
 && dpkg -i repo-mediaarea_1.0-27_all.deb \
 && rm repo-mediaarea_1.0-27_all.deb \
 && apt-get update && apt-get install -y --no-install-recommends \
        libmediainfo0v5 mediainfo libzen0v5 \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# 3. PHP Extensions (install-php-extensions manages dev headers automatically)
RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        pdo_mysql \
        sockets \
        pcntl \
        redis \
        imagick

# 4. Configuration & Entrypoint
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY ./docker/8.5/php.ini "$PHP_INI_DIR/conf.d/custom-conf.ini"
COPY --chmod=755 ./docker-entrypoint.sh /usr/local/bin/docker-entrypoint

# ==============================================================================
# CACHE LAYER: Composer Dependencies
# Re-runs ONLY when composer.json or composer.lock changes
# ==============================================================================
COPY composer.json composer.lock ./
RUN composer install --no-plugins --no-scripts --no-autoloader --no-interaction --prefer-dist

# ==============================================================================
# CACHE LAYER: NPM Dependencies
# Re-runs ONLY when package.json or package-lock.json changes
# ==============================================================================
COPY package.json package-lock.json* ./
RUN npm ci || npm install

# ==============================================================================
# APPLICATION CODE LAYER
# Re-runs when your application source code changes
# ==============================================================================
COPY . /app

# Generate autoloader, discovery, typescript definitions, and build frontend assets
RUN composer dump-autoload --no-plugins --no-scripts --optimize \
 && (php artisan package:discover --ansi || true) \
 && (php artisan typescript:transform --quiet || true) \
 && npx vite build

# Cleanup & Permissions
RUN rm -rf tests/ \
 && chmod -R 755 /app/vendor/ \
 && chmod -R 777 /app/storage /app/resources /app/public

EXPOSE ${APP_PORT:-80}

ENTRYPOINT ["docker-entrypoint"]
CMD ["--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
