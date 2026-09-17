FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql

COPY phpBB /var/www/html/phpBB

# Keep generated configuration outside the application tree so a dedicated
# volume can preserve it across image replacement. The installer owns this one
# file during bootstrap and makes it read-only after writing the install lock.
RUN mkdir -p /var/lib/masterbb \
    && mv /var/www/html/phpBB/config.php /var/lib/masterbb/config.php \
    && ln -s /var/lib/masterbb/config.php /var/www/html/phpBB/config.php \
    && chown -R www-data:www-data /var/lib/masterbb \
    && chmod 600 /var/lib/masterbb/config.php \
    && printf '%s\n' \
       'display_errors = Off' \
       'expose_php = Off' \
       'log_errors = On' \
       'error_reporting = E_ALL' \
       'post_max_size = 256K' \
       'upload_max_filesize = 256K' \
       'max_input_vars = 200' \
       'max_input_nesting_level = 16' \
       'short_open_tag = Off' \
       > /usr/local/etc/php/conf.d/masterbb.ini

RUN printf '%s\n' \
       'ServerTokens Prod' \
       'ServerSignature Off' \
       '<Directory /var/www/html/phpBB>' \
       '    Options -Indexes' \
       '    LimitRequestBody 262144' \
       '    <FilesMatch "^(config|database|auth|functions)\.php$|^extention\.inc$">' \
       '        Require all denied' \
       '    </FilesMatch>' \
       '</Directory>' \
       > /etc/apache2/conf-available/zz-masterbb-security.conf \
    && a2enconf zz-masterbb-security

EXPOSE 80

CMD ["apache2-foreground"]
