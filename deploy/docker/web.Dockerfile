# The web server's image (docs/docker-deployment.md): Caddy with HTTPS for the site's address, the frontend at /,
# /api/… passed to the app. Built from the repository's root: docker compose build web
FROM caddy:2-alpine
COPY deploy/docker/Caddyfile /etc/caddy/Caddyfile
# Caddy looks for the front controller at the same path as PHP; nothing else of the backend is in this image.
COPY public /var/www/recruitment/public
