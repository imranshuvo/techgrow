# TechGrow Ltd — production image for Coolify (or any Docker host).
# PHP + Apache, document root pointed at /public, SQLite stored on a
# persistent volume mounted at /var/www/html/storage.

FROM php:8.3-apache

# Apache modules used by our .htaccess (rewrite + security headers).
RUN a2enmod rewrite headers

# Fail the build loudly if the SQLite PDO driver is ever missing.
# (pdo_sqlite + sqlite3 are bundled and enabled in the official PHP image.)
RUN php -m | grep -q pdo_sqlite || (echo "ERROR: pdo_sqlite extension missing" && exit 1)

# Point Apache at the public/ web root so src/ and storage/ are never served.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Honour the public/.htaccess (DirectoryIndex, no listings, security headers).
RUN printf '<Directory %s>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
    "${APACHE_DOCUMENT_ROOT}" > /etc/apache2/conf-available/docroot.conf \
 && a2enconf docroot

WORKDIR /var/www/html
COPY . /var/www/html

# Recommended production PHP settings (don't leak errors to visitors).
RUN { \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/techgrow.ini

# Make the tree owned by Apache; storage is fixed up again at runtime
# (a freshly mounted volume comes up root-owned — see entrypoint).
RUN mkdir -p storage \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 775 storage

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
