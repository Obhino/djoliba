# Image de base FrankenPHP (PHP 8.2 & Alpine)
FROM dunglas/frankenphp:1.2-php8.2-alpine AS base

# Configuration commune PHP
RUN echo "memory_limit=256M" > /usr/local/etc/php/conf.d/docker-php-settings.ini \
    && echo "max_execution_time=240" >> /usr/local/etc/php/conf.d/docker-php-settings.ini

# Installer les dépendances système communes
RUN apk add --no-cache \
    bash \
    git \
    unzip \
    icu-dev \
    libpq-dev \
    libzip-dev \
    openssl

# Installer l'outil d'installation d'extensions PHP et les extensions nécessaires
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

# ==========================================
# Cible : Développement (local dev)
# ==========================================
FROM base AS dev

ENV APP_ENV=dev
ENV FRANKENPHP_DOCUMENT_ROOT=/var/www/html/public
ENV SERVER_NAME=:8000

# Dans l'environnement de développement, on s'attend à ce que le code 
# soit monté en volume. On installe juste les dépendances avec dev.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-progress

EXPOSE 8000
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ==========================================
# Cible : Builder (compilation production)
# ==========================================
FROM base AS builder

ENV APP_ENV=prod
ENV FRANKENPHP_DOCUMENT_ROOT=/var/www/html/public

# Copier uniquement les fichiers Composer pour optimiser le cache Docker
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts --no-progress --prefer-dist

# Copier le reste des sources
COPY . .

# Variables fictives pour passer la validation de boot/compilation
ENV DATABASE_URL=sqlite:///:memory:
ENV DEEPSEEK_API_KEY=dummy_build_key_for_deepseek
ENV OPENSERP_API_KEY=dummy_build_key_for_openserp
ENV DB_PASSWORD=dummy_build_password_for_db

# Compilation des assets (Tailwind CSS et AssetMapper)
RUN php bin/console importmap:install --no-interaction
RUN php bin/console tailwind:build --minify --no-interaction
RUN php bin/console asset-map:compile --no-interaction

# Exécuter les scripts de post-installation pour réchauffer le cache Symfony
RUN composer run-script post-install-cmd --no-interaction

# ==========================================
# Cible : Production (image finale allégée)
# ==========================================
FROM base AS prod

ENV APP_ENV=prod
ENV FRANKENPHP_DOCUMENT_ROOT=/var/www/html/public
ENV SERVER_NAME=:8000

# Copier le code préparé et optimisé depuis le builder
COPY --from=builder /var/www/html /var/www/html
COPY Caddyfile /etc/caddy/Caddyfile

# Créer les répertoires et ajuster les permissions pour la production
RUN mkdir -p var/cache var/log var/uploads public/uploads \
    && chown -R www-data:www-data var public/uploads \
    && chmod -R 775 var public/uploads

EXPOSE 8000

# Commande de démarrage avec FrankenPHP
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]