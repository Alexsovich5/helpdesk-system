FROM php:5.6-apache

# The stretch archive keys shipped in this image have expired, so the archive
# source is marked trusted and the Valid-Until check is skipped (SPEC section 7).
RUN echo 'deb [trusted=yes] http://archive.debian.org/debian stretch main' > /etc/apt/sources.list \
 && for i in 1 2 3; do apt-get -o Acquire::Check-Valid-Until=false update && break; sleep 5; done \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      libmcrypt-dev libldap2-dev unzip git mysql-client \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure ldap --with-libdir=lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH) \
 && docker-php-ext-install mcrypt pdo_mysql ldap

RUN a2enmod rewrite
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/helpdesk.ini

RUN for i in 1 2 3; do curl -fsSL -o /usr/local/bin/composer https://getcomposer.org/download/2.2.24/composer.phar && break; sleep 5; done \
 && chmod +x /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1
WORKDIR /var/www/html

# Dependencies first so this layer is cached until the manifest changes.
# The class map needs the app/ directories, so it is dumped after the source copy.
COPY composer.json composer.lock ./
RUN for i in 1 2 3; do composer install --prefer-dist --no-scripts --no-autoloader --no-interaction && break; sleep 5; done

COPY . ./
RUN composer dump-autoload --optimize \
 && chown -R www-data:www-data app/storage
