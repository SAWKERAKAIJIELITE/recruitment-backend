# The web server's image (docs/docker-deployment.md): Caddy, the frontend at /, /api/… passed to the app.
# Built from the repository's root: docker compose build web
#
# Only Caddy's program is taken from the official image, onto a plain Alpine: the official image declares ports 80
# and 443 (EXPOSE), and a platform that publishes an image's declared ports (Openship) then asks for port 80 of
# the server, which its own proxy holds. This image declares 8080 only.
FROM caddy:2-alpine AS caddy

FROM alpine:3.22
RUN apk add --no-cache ca-certificates mailcap
COPY --from=caddy /usr/bin/caddy /usr/bin/caddy

# Where Caddy keeps its state and certificates (the caddy-config and caddy-data volumes).
ENV XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data

COPY deploy/docker/caddy/ /etc/caddy/
# Caddy looks for the front controller at the same path as PHP; nothing else of the backend is in this image.
COPY public /var/www/recruitment/public
# The frontend's web build: whatever is in deploy/docker/frontend when the image is built (a placeholder page
# in the repository).
COPY deploy/docker/frontend /srv/frontend

EXPOSE 8080
CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
