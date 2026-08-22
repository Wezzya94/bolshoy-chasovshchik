FROM mcr.microsoft.com/devcontainers/php:4-8.4-bookworm

ENV TV_EXTERNAL_HTTPS=1

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        rsync \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd mbstring \
    && a2enmod rewrite headers expires deflate mime \
    && usermod -aG www-data vscode \
    && printf '%s\n' 'export TV_EXTERNAL_HTTPS=1' >> /etc/apache2/envvars \
    && printf '%s\n' \
        'ServerName localhost' \
        'ServerTokens Prod' \
        'ServerSignature Off' \
        'TraceEnable Off' \
        '<Directory /var/www/html>' \
        '    Options FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        '<Directory /workspaces>' \
        '    Options FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/tv-preview.conf \
    && a2enconf tv-preview \
    && printf '%s\n' \
        'expose_php=Off' \
        'display_errors=Off' \
        'log_errors=On' \
        'file_uploads=On' \
        'upload_max_filesize=16M' \
        'post_max_size=18M' \
        'memory_limit=256M' \
        'max_execution_time=60' \
        > /usr/local/etc/php/conf.d/tv-preview.ini \
    && rm -rf /var/lib/apt/lists/*
