FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
COPY public public
COPY src src

ENV PORT=8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public public/index.php"]
