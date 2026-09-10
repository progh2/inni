FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
      libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Document root = public/
ENV APACHE_DOCUMENT_ROOT=/var/www/inni/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/inni
COPY . /var/www/inni

RUN mkdir -p data public/uploads \
 && chown -R www-data:www-data data public/uploads \
 && chmod -R ug+rwX data public/uploads

EXPOSE 80
