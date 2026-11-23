FROM php:8.4-cli-alpine

WORKDIR /app

RUN apk add --no-cache ca-certificates msmtp

COPY --chown=www-data:www-data index.html index.php form.js styles.css ./
COPY --chown=www-data:www-data docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod 0555 /usr/local/bin/docker-entrypoint.sh

USER www-data

ENV PORT=8080

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
