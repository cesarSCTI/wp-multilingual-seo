#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")"
docker compose up -d db wordpress
until docker compose run --rm cli wp core is-installed >/dev/null 2>&1; do
  if docker compose run --rm cli wp core install --url=http://localhost:8099 --title=MLS --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null 2>&1; then break; fi
  sleep 3
done
docker compose run --rm cli wp rewrite structure '/%postname%/' --hard
docker compose run --rm cli wp plugin install elementor --version=3.32.5 --force --activate
docker compose run --rm cli wp plugin activate wp-multilingual-seo-translator
docker compose run --rm cli wp eval-file wp-content/plugins/wp-multilingual-seo-translator/tests/Integration/run.php
echo "Integration suite completed successfully."

