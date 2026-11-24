#!/bin/sh

set -eu

render_port="${PORT:-10000}"

case "$render_port" in
    ''|*[!0-9]*)
        echo "PORT must be a numeric value." >&2
        exit 1
        ;;
esac

sed -ri "s/^Listen [0-9]+/Listen ${render_port}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \\*:80>#<VirtualHost *:${render_port}>#" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
