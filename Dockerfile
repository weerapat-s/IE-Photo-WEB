FROM php:8.2-cli

# System dependencies for GD + MySQL
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg62-turbo-dev libwebp-dev \
    libfreetype6-dev libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd \
        --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql mysqli mbstring gd

WORKDIR /app
COPY . /app

# Railway injects $PORT at runtime
# PHP built-in server serves index.php automatically for "/"
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app"]
