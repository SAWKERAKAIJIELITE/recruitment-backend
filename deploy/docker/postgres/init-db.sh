#!/bin/bash
# Runs once, when the database volume is empty (the postgres image's /docker-entrypoint-initdb.d): the
# application's role and its database. The role owns its database and nothing else; it is not a superuser.
# The migrations create the btree_gist extension themselves (a trusted extension: the owner may).
set -e

psql -v ON_ERROR_STOP=1 --username postgres --dbname postgres \
     -v app_user="$APP_DB_USER" -v app_password="$APP_DB_PASSWORD" -v app_db="$APP_DB_NAME" <<'SQL'
CREATE ROLE :"app_user" LOGIN PASSWORD :'app_password';
CREATE DATABASE :"app_db" OWNER :"app_user" ENCODING 'UTF8'
       LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0;
REVOKE ALL ON DATABASE :"app_db" FROM PUBLIC;
SQL
