FROM php:8.2-apache

# Устанавливаем системные пакеты
RUN apt-get update && apt-get install -y \
    libssh2-1-dev \
    libssh2-1 \
    cron \
    sqlite3 \
    git \
    build-essential \
    autoconf \
    automake \
    libtool

# Компилируем и устанавливаем SSH2 вручную
RUN cd /tmp && \
    git clone https://github.com/php/pecl-networking-ssh2.git && \
    cd pecl-networking-ssh2 && \
    phpize && \
    ./configure && \
    make && \
    make install && \
    echo "extension=ssh2.so" > /usr/local/etc/php/conf.d/ssh2.ini

# Создаем директории
RUN mkdir -p /var/www/html/backup/bkp /var/www/html/backup/rsc /var/www/html/db

# Настраиваем права
RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/

# Включаем модули Apache
RUN a2enmod rewrite

# Копируем ВСЕ файлы приложения
COPY www/ /var/www/html/
COPY version.json /var/www/html/

# Копируем cron задание
COPY cronfile /etc/cron.d/backup-cron
RUN chmod 0644 /etc/cron.d/backup-cron

# Запускаем cron и apache
CMD ["sh", "-c", "cron && apache2-foreground"]
