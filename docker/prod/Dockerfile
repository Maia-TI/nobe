FROM ubuntu:24.04

LABEL maintainer="Laravel CLI Production"

ARG DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

WORKDIR /var/www/html

# Install dependencies and PHP 8.5 (excluding FPM & Nginx)
RUN apt-get update && apt-get upgrade -y \
    && apt-get install -y gnupg gosu curl ca-certificates zip unzip git libcap2-bin libpng-dev dnsutils \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y \
    php8.5-cli \
    php8.5-pgsql \
    php8.5-gd \
    php8.5-curl \
    php8.5-mbstring \
    php8.5-xml \
    php8.5-zip \
    php8.5-bcmath \
    php8.5-intl \
    php8.5-readline \
    php8.5-redis \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Copy app code
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Production optimization
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Set default command to tinker
ENTRYPOINT ["php", "artisan"]
CMD ["tinker"]
