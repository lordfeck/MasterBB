FROM --platform=linux/amd64 debian/eol:squeeze

RUN echo 'deb http://archive.debian.org/debian squeeze main' \
      > /etc/apt/sources.list \
    && echo 'Acquire::Check-Valid-Until "false";' \
      > /etc/apt/apt.conf.d/99no-check-valid \
    && apt-get update \
    && apt-get install -y \
       apache2 \
       php5 \
       libapache2-mod-php5 \
       php5-mysql

COPY phpBB /var/www/phpBB

RUN chmod 666 /var/www/phpBB/config.php

RUN printf '\
register_globals = On\n\
display_errors = Off\n\
register_long_arrays = On\n\
short_open_tag = On\n\
log_errors = On\n\
error_reporting = E_ALL & ~E_NOTICE & ~E_DEPRECATED\n\
' >> /etc/php5/apache2/php.ini

EXPOSE 80

CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
