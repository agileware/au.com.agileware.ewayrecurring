#!/usr/bin/env bash
# Provisions the local docker-compose environment (WordPress + CiviCRM +
# this extension) from scratch, and sets up the dedicated headless test
# database that Civi\Test (HeadlessInterface tests) requires. Mirrors the
# steps in agileware/ci-workflows' civicrm-phpunit-tests.yml, so results
# here should match CI.
#
# Usage: docker compose up -d && ./docker/setup.sh
set -euo pipefail

WP_URL=http://localhost:8080

echo "==> Waiting for wp-config.php..."
for i in $(seq 1 30); do
  if docker compose exec -T wordpress test -f /var/www/html/wp-config.php; then
    echo "wp-config.php found"
    break
  fi
  sleep 2
done
docker compose exec -T wordpress test -f /var/www/html/wp-config.php

echo "==> Installing WordPress..."
docker compose exec -T -u www-data wordpress wp core install \
  --url="$WP_URL" \
  --title="Local Test Site" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=test@example.com \
  --skip-email || echo "(already installed)"

echo "==> Increasing PHP memory limit..."
docker compose exec -T wordpress bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-memory-limit.ini"
docker compose exec -T wordpress apache2ctl graceful 2>/dev/null || true

echo "==> Installing cv..."
curl -Ls https://download.civicrm.org/cv/cv.phar -o /tmp/cv-local
docker compose cp /tmp/cv-local wordpress:/usr/local/bin/cv
docker compose exec -T wordpress chmod +x /usr/local/bin/cv
rm -f /tmp/cv-local

echo "==> Installing civicrm..."
docker compose exec -T wordpress chown www-data wp-content/ wp-content/uploads wp-content/uploads/civicrm
docker compose exec -T -u www-data wordpress cv core:install || echo "(already installed)"
docker compose exec -T -u www-data wordpress wp plugin activate civicrm || true

echo "==> Installing pdo_mysql PHP extension..."
docker compose exec -T wordpress docker-php-ext-install pdo_mysql
docker compose exec -T wordpress apache2ctl graceful 2>/dev/null || true

echo "==> Creating CiviCRM headless test database..."
docker compose exec -T mariadb mysql -uroot -proot_pass -e "CREATE DATABASE IF NOT EXISTS civicrm_test;"
docker compose exec -T mariadb sh -c 'mysqldump -uroot -proot_pass wordpress | mysql -uroot -proot_pass civicrm_test'

echo "==> Configuring cv's headless test database DSN..."
docker compose exec -T -u www-data wordpress cv ev '\Civi\Cv\Config::update(function ($config) { $config["sites"][CIVICRM_SETTINGS_PATH]["TEST_DB_DSN"] = "mysql://root:root_pass@mariadb:3306/civicrm_test?new_link=true"; return $config; });'

echo "==> Installing phpunit9..."
if ! docker compose exec -T wordpress bash -c 'command -v phpunit9 >/dev/null 2>&1'; then
  curl -Ls https://phar.phpunit.de/phpunit-9.phar -o /tmp/phpunit9-local
  docker compose cp /tmp/phpunit9-local wordpress:/usr/local/bin/phpunit9
  docker compose exec -T wordpress chmod +x /usr/local/bin/phpunit9
  rm -f /tmp/phpunit9-local
fi

echo ""
echo "Done. Run tests with: ./docker/run-tests.sh [path/to/Test.php]"
echo "Site: $WP_URL (admin/admin)"
