FROM php:8.2-apache
RUN apt-get update && apt-get install -y libpq-dev \
  && docker-php-ext-install pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*
COPY index.php /var/www/html/index.php
