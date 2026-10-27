#!/bin/sh
# Test d'intégration : WordPress + WooCommerce jetables, API Axonaut simulée (mu-plugins/mock-axonaut.php).
# Aucune requête ne part vers axonaut.com. Nécessite Docker.
set -e
cd "$(dirname "$0")"

wpcli() {
  docker run --rm --network axotest_default --volumes-from axotest-wp-1 --user 33:33 \
    -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp \
    wordpress:cli wp --path=/var/www/html "$@"
}

docker compose up -d
sleep 8
if ! wpcli core is-installed 2>/dev/null; then
  wpcli core install --url=http://localhost:8089 --title=AxoTest --admin_user=admin --admin_password=admin123 --admin_email=a@example.test --skip-email
  wpcli core update || true
  wpcli core update-db || true
fi
wpcli plugin is-installed woocommerce || wpcli plugin install woocommerce
wpcli plugin activate woocommerce axonaut
wpcli eval-file /var/www/html/wp-content/mu-plugins/scripts/scenario1.php
wpcli eval-file /var/www/html/wp-content/mu-plugins/scripts/scenario2.php
echo "Pour tout supprimer : docker compose -f tests/integration/docker-compose.yml down -v"
