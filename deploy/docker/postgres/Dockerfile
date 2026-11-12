# The database's image (docs/docker-deployment.md): PostgreSQL 16 with the script that makes the application's
# role and database on the first start. Built from the repository's root: docker compose build db
FROM postgres:16-bookworm
COPY deploy/docker/postgres/init-db.sh /docker-entrypoint-initdb.d/10-recruitment.sh
