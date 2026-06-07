FROM php:8.2-apache

# Extension PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copier TOUS les fichiers du repo
COPY . /var/www/html/

# Si le fichier s'appelle index2.php sur GitHub, le renommer
RUN if [ -f /var/www/html/index2.php ] && [ ! -f /var/www/html/index.php ]; then \
        mv /var/www/html/index2.php /var/www/html/index.php; \
    fi

# Supprimer les fichiers inutiles
RUN rm -f /var/www/html/Dockerfile /var/www/html/railway.toml /var/www/html/backup_unco.sql

# Apache config
RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

# Script de démarrage : Railway impose PORT=8080, on configure Apache dessus
RUN printf '#!/bin/bash\nPORT=${PORT:-8080}\necho "Listen $PORT" > /etc/apache2/ports.conf\nsed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-enabled/000-default.conf\nexec apache2-foreground\n' > /start.sh \
    && chmod +x /start.sh

EXPOSE 8080

CMD ["/start.sh"]
