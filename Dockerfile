# MeshCore klient – PHP 8.3 + Apache. Jeden image pro tři role (viz docker/entrypoint.sh):
#   web     – webové rozhraní (výchozí)
#   worker  – spojení s meshcore-serverem / Home Assistantem
#   heard   – kontrola heard přes analyzer a úklid (každých 5 s)
FROM php:8.3-apache

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl \
 && a2enmod headers expires \
 && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/meshcore.ini
COPY docker/entrypoint.sh /usr/local/bin/meshcore-entrypoint
COPY --chown=www-data:www-data . /app

RUN chmod +x /usr/local/bin/meshcore-entrypoint \
 && mkdir -p /app/data/backgrounds \
 && chown -R www-data:www-data /app/data \
 && rm -f /app/config/config.local.php

WORKDIR /app
VOLUME ["/app/data"]
EXPOSE 80

ENTRYPOINT ["meshcore-entrypoint"]
CMD ["web"]
