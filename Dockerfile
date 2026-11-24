FROM php:8.2-apache

ENV CHAT_WS_ENABLED=false

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
COPY docker/render-start.sh /usr/local/bin/render-start

RUN printf '%s\n' \
        '<Directory /var/www/html>' \
        '    AllowOverride All' \
        '    Options FollowSymLinks' \
        '    Require all granted' \
        '</Directory>' \
    > /etc/apache2/conf-available/php-chat-app.conf \
    && a2enconf php-chat-app \
    && mkdir -p /var/www/html/storage /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/uploads \
    && chmod +x /usr/local/bin/render-start

CMD ["render-start"]
