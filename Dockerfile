FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql

COPY phpBB /var/www/html/phpBB

# The historical web installer appends the selected database settings here.
RUN chmod 666 /var/www/html/phpBB/config.php \
    && printf '%s\n' \
       'display_errors = Off' \
       'log_errors = On' \
       'error_reporting = E_ALL' \
       'short_open_tag = Off' \
       > /usr/local/etc/php/conf.d/masterbb.ini

EXPOSE 80

CMD ["apache2-foreground"]
