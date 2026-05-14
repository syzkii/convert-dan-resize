FROM php:8.2-apache

# Install dependency converter
RUN apt-get update && apt-get install -y \
    libreoffice \
    ghostscript \
    imagemagick \
    zip \
    unzip \
    libzip-dev

# Enable PHP zip
RUN docker-php-ext-install zip

# Enable Apache rewrite
RUN a2enmod rewrite

# Copy project converter saja
COPY . /var/www/html/

# Permission
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
