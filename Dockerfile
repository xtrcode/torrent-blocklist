# syntax=docker/dockerfile:1
#
# Stages:
#   base       PHP runtime (official image; curl, zlib, Phar and mbstring are compiled in)
#   toolchain  base + Composer, used for local development with the source mounted
#   vendor     production dependencies + optimized autoloader
#   ci         full dev setup baked in (PHPUnit, PHPStan, PHP-CS-Fixer); default command: composer check
#   runtime    the generator image (default target)

FROM php:8.4-cli-alpine AS base
ENV TZ=UTC
WORKDIR /app

FROM base AS toolchain
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1
CMD ["composer", "list"]

FROM toolchain AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY src/ ./src/
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM toolchain AS ci
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload
CMD ["composer", "check"]

FROM base AS runtime
LABEL org.opencontainers.image.title="torrent-blocklist" \
      org.opencontainers.image.description="Builds qBittorrent IP filter files (.p2p, .dat, .p2b) from Best-blocklist and ipdeny.com country zones" \
      org.opencontainers.image.source="https://github.com/xtrcode/torrent-blocklist" \
      org.opencontainers.image.licenses="MIT"
ENV CACHE_DIR=/cache \
    OUTPUT_DIR=/output
COPY --from=vendor /app/vendor/ ./vendor/
COPY bin/ ./bin/
COPY src/ ./src/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
# World-writable so the container also works with an arbitrary --user and bind mounts.
RUN chmod +x /usr/local/bin/docker-entrypoint.sh bin/generate.php \
    && mkdir -p /cache /output \
    && chmod 1777 /cache /output
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["generate"]
