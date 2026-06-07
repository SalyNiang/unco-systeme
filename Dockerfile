FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

# Installer Nginx + PHP-FPM + extension PostgreSQL
RUN apt-get update && apt-get install -y \
    nginx \
    php8.1-fpm \
    php8.1-pgsql \
    php8.1-pdo \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copier index.php
COPY index.php /var/www/html/index.php
RUN chown -R www-data:www-data /var/www/html

# Config Nginx qui lit PORT au runtime
RUN echo 'server { \n\
    listen PORT_PLACEHOLDER; \n\
    root /var/www/html; \n\
    index index.php; \n\
    location / { try_files $uri $uri/ /index.php?$query_string; } \n\
    location ~ \.php$ { \n\
        include snippets/fastcgi-php.conf; \n\
        fastcgi_pass unix:/run/php/php8.1-fpm.sock; \n\
    } \n\
}' > /etc/nginx/sites-available/default

# Script de démarrage
RUN echo '#!/bin/bash'                                                                    > /start.sh && \
    echo 'set -e'                                                                        >> /start.sh && \
    echo 'PORT="${PORT:-8080}"'                                                          >> /start.sh && \
    echo 'sed -i "s/PORT_PLACEHOLDER/$PORT/" /etc/nginx/sites-available/default'        >> /start.sh && \
    echo 'service php8.1-fpm start'                                                     >> /start.sh && \
    echo 'exec nginx -g "daemon off;"'                                                   >> /start.sh && \
    chmod +x /start.sh

EXPOSE 8080

CMD ["/bin/bash", "/start.sh"]
