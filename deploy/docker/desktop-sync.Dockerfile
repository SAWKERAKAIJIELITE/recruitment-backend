# The desktop-sync container (docs/docker-deployment.md, step 13): copies the latest GitHub release of the desktop
# app into the desktop-releases volume, which the web container serves at /desktop/.
# Built from the repository's root: docker compose build desktop-sync
FROM alpine:3.22
RUN apk add --no-cache ca-certificates curl jq
COPY deploy/docker/desktop-sync.sh /usr/local/bin/desktop-sync
RUN chmod 755 /usr/local/bin/desktop-sync
CMD ["desktop-sync"]
