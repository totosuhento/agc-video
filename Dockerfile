# Nexora Content Engine — image Docker (Nusapod, VPS dengan Docker, hosting container lain)
FROM php:8.3-apache

# Apache: rewrite untuk URL cantik; opcache untuk kecepatan.
# Port dibaca dari variabel ${AGC_PORT} (default 8080, bisa diganti lewat env PORT).
# intl: format tanggal & angka sesuai bahasa (10 bahasa) dan slug untuk judul non-Latin
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev \
 && docker-php-ext-install intl \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite expires \
 && printf 'Listen ${AGC_PORT}\n' > /etc/apache2/ports.conf \
 && docker-php-ext-enable opcache \
 && { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=32'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=60'; \
      echo 'memory_limit=128M'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/agc.ini

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/tuning.conf /etc/apache2/conf-enabled/agc-tuning.conf
COPY docker/entrypoint.sh /usr/local/bin/agc-entrypoint

WORKDIR /var/www/html
COPY . /var/www/html

# Folder storage dibuat saat build dan bisa ditulis oleh user 1000 (NusaPod) maupun www-data
RUN chmod +x /usr/local/bin/agc-entrypoint \
 && rm -rf /var/www/html/docker /var/www/html/.github \
 && mkdir -p /var/www/html/storage/sites /var/www/html/storage/sessions \
 && chown -R 1000:1000 /var/www/html/storage \
 && chmod -R a+rwX /var/www/html/storage

ENV AGC_PORT=8080
EXPOSE 8080

# Database, cache & log disimpan di sini → pasang sebagai persistent volume
VOLUME ["/var/www/html/storage"]

# NusaPod menjalankan container sebagai user 1000 (non-root) di port 8080
USER 1000:1000

ENTRYPOINT ["agc-entrypoint"]
CMD ["apache2-foreground"]
