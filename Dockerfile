FROM php:8.2-apache

# Actualizar e instalar dependencias necesarias
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite de Apache si se necesita
RUN a2enmod rewrite

# Instalar extensiones de PHP necesarias (mysqli, pdo_mysql)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copiar la App Web de Flutter (si existe build/web) a la raíz de Apache
COPY build/web/ /var/www/html/

# Copiar el Backend PHP a la carpeta server_php dentro del servidor
COPY server_php/ /var/www/html/server_php/

# Dar permisos adecuados
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

EXPOSE 80
