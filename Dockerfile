# Image de base FrankenPHP (PHP 8.2 & Alpine)
FROM dunglas/frankenphp:1.2-php8.2-alpine

# Argument pour l'environnement (prod, dev, test)
ARG APP_ENV=dev
ENV APP_ENV=${APP_ENV}

# Variables d'environnement
ENV FRANKENPHP_DOCUMENT_ROOT=/var/www/html/public
ENV SERVER_NAME=:8000
ENV PHP_MEMORY_LIMIT=256M

# Configurer les limites PHP globales
RUN echo "memory_limit=256M" > /usr/local/etc/php/conf.d/docker-php-settings.ini \
    && echo "max_execution_time=240" >> /usr/local/etc/php/conf.d/docker-php-settings.ini

# Installer les dépendances système
RUN apk add --no-cache \
    bash \
    git \
    unzip \
    icu-dev \
    libpq-dev \
    libzip-dev \
    openssl

# Installer les extensions PHP
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && install-php-extensions \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    apcu \
    opcache \
    redis \
    bcmath

# Récupérer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Définir le dossier de travail
WORKDIR /var/www/html

# Copier uniquement les fichiers Composer (layer optimisation)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --optimize-autoloader \
    --no-scripts \
    --no-progress \
    --prefer-dist

# Copier le reste du code source
COPY . .

# Exécuter les scripts post-installation
RUN DATABASE_URL=sqlite:///:memory: composer run-script post-install-cmd --no-interaction || true

# Créer les dossiers de cache, logs, uploads
RUN mkdir -p var/cache var/log var/uploads public/uploads \
    && chown -R www-data:www-data var public/uploads \
    && chmod -R 775 var public/uploads

# Exposer le port
EXPOSE 8000

# Commande de démarrage
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]