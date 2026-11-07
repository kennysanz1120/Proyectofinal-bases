FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev postgresql-client && docker-php-ext-install pdo_pgsql && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY index.php config.php Database.php /var/www/html/
COPY assets/ /var/www/html/assets/
COPY proyecto.sql crear_usuario_app.sql crear_administrador.sql /opt/
COPY entrypoint.sh /usr/local/bin/eps-entrypoint
RUN chmod +x /usr/local/bin/eps-entrypoint
ENV PORT=10000
EXPOSE 10000
CMD ["/usr/local/bin/eps-entrypoint"]
