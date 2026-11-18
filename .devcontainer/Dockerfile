# Development container for GitHub Codespaces and VS Code Dev Containers.
FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

# PHP extensions WordPress and WooCommerce need (gd for images, intl, zip, mysqli for MySQL).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libzip-dev unzip default-mysql-client \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd intl zip mysqli exif \
 && rm -rf /var/lib/apt/lists/*

# WP-CLI, pinned.
RUN curl -fsSL -o /usr/local/bin/wp https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar \
 && chmod +x /usr/local/bin/wp
