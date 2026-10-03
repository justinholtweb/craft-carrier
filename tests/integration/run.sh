#!/usr/bin/env bash
# Runs a test script in the plugin-testing harness, starting the containers first if something
# stopped them (they are stopped at intervals on this machine) — never via ddev, which loops.
ready () {
  docker exec ddev-plugin-testing-web curl -s -o /dev/null http://127.0.0.1/admin/login 2>/dev/null \
    && docker exec ddev-plugin-testing-db mysqladmin ping -uroot -proot --silent >/dev/null 2>&1
}
if ! ready; then
  for _ in $(seq 1 45); do docker start ddev-plugin-testing-db ddev-plugin-testing-web >/dev/null 2>&1; ready && break; sleep 4; done
fi
docker exec -w /var/www/html ddev-plugin-testing-web php "$@"
