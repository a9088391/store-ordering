FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer
FROM php:8.5.12-cli-bookworm@sha256:050713ce008e2360ea9dc96e1f9a075690132388d917fc3552806f7a7be6f2a0 AS php-deps

# 應用程式與測試使用同一個 PHP／PostgreSQL 驅動，建置時驗證鎖檔可還原。
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

FROM node:24-bookworm-slim@sha256:01d566e8d94d8c619591d78652babfccd3c6992a3dffa9ed5ab39e11cf6c70e9 AS frontend
WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN node --version && npm run typecheck && npm run build

FROM php-deps AS app
COPY backend/ ./
COPY --from=frontend /frontend/dist/ public/build/
# PHP 會在應用程式之前讀取原生 POST，讓 SAPI 也限制大小並保留純 JSON 錯誤回應。
RUN printf 'post_max_size=64K\ndisplay_errors=Off\n' > "$PHP_INI_DIR/conf.d/store-ordering-http.ini"
RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

# 本機服務與遷移使用非 root 帳號，工作目錄只有 Laravel 執行資料可寫。
USER www-data
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
