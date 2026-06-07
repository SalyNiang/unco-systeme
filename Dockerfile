FROM php:8.2-apache

# Extension PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copier le fichier PHP
COPY index.php /var/www/html/index.php

# Apache écoute sur le port Railway
ENV PORT=80
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:${PORT}>/' /etc/apache2/sites-enabled/000-default.conf

EXPOSE 80
