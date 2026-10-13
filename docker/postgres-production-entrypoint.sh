#!/bin/sh
set -eu
sed "s|__DATABASE_SUBNET__|${DATABASE_SUBNET:?Set DATABASE_SUBNET}|g" /etc/postgresql/pg_hba.conf.template > /tmp/pg_hba.conf
chmod 644 /tmp/pg_hba.conf
exec /usr/local/bin/docker-entrypoint.sh "$@"
