FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    $PHPIZE_DEPS \
    imagemagick \
    imagemagick-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    libxml2-dev

RUN pecl install imagick \
    && docker-php-ext-enable imagick

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp \
 && docker-php-ext-install -j$(nproc) gd

RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    zip \
    bcmath \
    exif \
    intl \
    opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 9000
