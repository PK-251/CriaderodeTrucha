# api-core — Laravel 11 sobre PHP 8.3 (arquitectura hexagonal)
# Fase 0: solo el entorno base y un endpoint de salud.
# El framework y el núcleo de dominio se incorporan en las Fases 2 y 3.
FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip \
    # pcov, y no Xdebug, como driver de cobertura: solo mide líneas ejecutadas,
    # que es lo que exige el DoD, y lo hace sin el sobrecoste de depuración que
    # multiplicaría la duración del pipeline.
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
