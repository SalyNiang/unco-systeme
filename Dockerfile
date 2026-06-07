FROM php:8.2-apache

# Extension PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copier UNIQUEMENT le fichier PHP (pas les configs Apache)
COPY index.php /var/www/html/index.php

# Apache config de base
RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

# Script de démarrage
RUN echo '#!/bin/bash'                                                                          > /start.sh && \
    echo 'PORT="${PORT:-8080}"'                                                                >> /start.sh && \
    echo 'echo "Listen $PORT" > /etc/apache2/ports.conf'                                      >> /start.sh && \
    echo 'sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf' >> /start.sh && \
    echo 'exec apache2-foreground'                                                             >> /start.sh && \
    chmod +x /start.sh

EXPOSE 8080

CMD ["/bin/bash", "/start.sh"]
