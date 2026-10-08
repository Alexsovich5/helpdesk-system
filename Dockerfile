# Stage 1: download and verify the Debian packages the app image needs, on
# a current Debian, over HTTPS from snapshot.debian.org (signature, index
# and package checksums; see docker/debs/fetch_verified_debs.sh). Nothing
# from this stage but the verified .debs reaches the app image.
FROM debian:bookworm-slim@sha256:7c7b2c966bc9ee8cedfeef67e0e279108992c77681fa595db4a9d65c06ccc587 AS debs
RUN ok=; for i in 1 2 3; do \
        if apt-get update \
           && apt-get install -y --no-install-recommends ca-certificates curl gpgv debian-archive-keyring; \
        then ok=1; break; fi; \
        sleep 10; \
    done; [ -n "$ok" ]; rm -rf /var/lib/apt/lists/*
COPY docker/debs/fetch_verified_debs.sh docker/debs/app.list /build/
RUN sh /build/fetch_verified_debs.sh /build/app.list /debs

# Stage 2: the app image. apt is not used; dpkg installs only the verified
# files, in manifest order, and fails if a dependency is missing from them.
FROM php:5.6-apache@sha256:0a40fd273961b99d8afe69a61a68c73c04bc0caa9de384d3b2dd9e7986eec86d

COPY --from=debs /debs /tmp/debs
RUN rm -f /etc/apt/sources.list /etc/apt/sources.list.d/* \
 && rm -rf /var/lib/apt/lists/* \
 && cd /tmp/debs \
 && sha256sum -c SHA256SUMS \
 && DEBIAN_FRONTEND=noninteractive dpkg -i $(awk '{ print $2 }' SHA256SUMS) \
 && cd / && rm -rf /tmp/debs

RUN docker-php-ext-configure ldap --with-libdir=lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH) \
 && docker-php-ext-install mcrypt pdo_mysql ldap

RUN a2enmod rewrite
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/helpdesk.ini

# Composer 2.2.24, checked against the SHA256 published on getcomposer.org.
RUN for i in 1 2 3; do curl -fsSL --proto '=https' -o /usr/local/bin/composer https://getcomposer.org/download/2.2.24/composer.phar && break; sleep 5; done \
 && echo "b0c383b1f430a80a74c006f20199d1e0226848a0a90afa5c0a7d01fb90ee9075  /usr/local/bin/composer" | sha256sum -c - \
 && chmod +x /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1
WORKDIR /var/www/html

# Dependencies first so this layer is cached until the manifest changes.
# The class map needs the app/ directories, so it is dumped after the source copy.
COPY composer.json composer.lock ./
RUN for i in 1 2 3; do composer install --prefer-dist --no-scripts --no-autoloader --no-interaction && break; sleep 5; done

COPY . ./
RUN composer dump-autoload --optimize \
 && rm -rf public/vendor/bootstrap && mkdir -p public/vendor \
 && cp -r vendor/twbs/bootstrap/dist public/vendor/bootstrap \
 && chown -R www-data:www-data app/storage \
 && install -m 0755 docker/app/entrypoint.sh /usr/local/bin/helpdesk-entrypoint

# Reads or creates APP_KEY (docker/app/entrypoint.sh), then runs the command.
ENTRYPOINT ["helpdesk-entrypoint"]
CMD ["apache2-foreground"]
