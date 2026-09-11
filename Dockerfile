FROM php:8.3-apache

# php:8.3-apache already ships these; re-running docker-php-ext-install
# on them fails (deleted source tree / missing config.m4). Verify instead.
RUN a2enmod rewrite headers \
    && php -r 'foreach (["pdo_sqlite","sqlite3","curl","fileinfo","mbstring"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "missing PHP extension: {$ext}\n"); exit(1);} }'

COPY docker/apache-vhost.conf /etc/apache2/sites-available/inni.conf
RUN a2dissite 000-default.conf && a2ensite inni.conf

WORKDIR /var/www/inni
COPY . /var/www/inni

# Seed config.php at build time so first request does not need a writable app root.
RUN if [ ! -f config.php ]; then cp config.example.php config.php; fi \
    && mkdir -p data public/uploads \
    && chown -R www-data:www-data data public/uploads config.php \
    && chmod -R ug+rwX data public/uploads \
    && chmod 664 config.php \
    && chmod +x docker/entrypoint.sh \
    && cp docker/entrypoint.sh /usr/local/bin/inni-entrypoint

EXPOSE 80
ENTRYPOINT ["inni-entrypoint"]
CMD ["apache2-foreground"]
