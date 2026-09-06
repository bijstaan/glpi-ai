#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright (C) 2026 Bijstaan
# Start the mock vendor API inside the GLPI container, so ai-check.js has
# something to point a provider at.
#
# A mock rather than a real vendor because the settings page is what is under
# test, not anyone's model: the check needs a credential that works and an
# endpoint that answers, and neither has to be genuine for that.
#
# It no longer touches the plugin's configuration. It used to reset it to
# as-installed, because ai-check.js asserts on the initial state — but this
# development instance has a real provider configured against a real Ollama
# host, and a script whose job is "start two mock servers" has no business
# destroying that. The checks that need a fresh plugin now snapshot it, reset
# it themselves, and put it back; see browser/config-guard.js.
#
#   ./ai-setup.sh start   # or stop, or reset
set -eu

CONTAINER=${CONTAINER:-glpi-glpi-1}
VENDOR=/var/www/glpi/plugins/glpiai/tests/mock-provider.php
MCP=/var/www/glpi/plugins/glpiai/tests/mock-mcp.php

reset_config() {
  cat >&2 <<'WARNING'
ai-setup.sh no longer resets the plugin configuration.

The browser checks snapshot it, reset what they need, and restore it — see
browser/config-guard.js. If you genuinely want an as-installed plugin, reinstall
it from the Plugins page, which is explicit about what it is doing.
WARNING
  return 1
}

case "${1:-start}" in
  reset)
    reset_config
    ;;
  start)
    docker exec "$CONTAINER" pkill -f '[m]ock-provider.php' >/dev/null 2>&1 || true
    docker exec "$CONTAINER" pkill -f '[m]ock-mcp.php' >/dev/null 2>&1 || true
    docker exec -d "$CONTAINER" php -S 127.0.0.1:9099 "$VENDOR"
    docker exec -d "$CONTAINER" php -S 127.0.0.1:9098 "$MCP"
    sleep 1
    docker exec "$CONTAINER" sh -c \
      'curl -s -o /dev/null -w "%{http_code}" -X POST http://127.0.0.1:9099/v1/chat/completions' \
      | grep -q 200 || { echo "the mock vendor did not come up" >&2; exit 1; }
    docker exec "$CONTAINER" sh -c \
      'curl -s -X POST http://127.0.0.1:9098/mcp -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{}}"' \
      | grep -q mock-mcp || { echo "the mock MCP server did not come up" >&2; exit 1; }
    echo "mocks listening on 127.0.0.1:9099 (vendor) and :9098 (mcp) inside $CONTAINER"
    ;;
  stop)
    docker exec "$CONTAINER" pkill -f '[m]ock-provider.php' >/dev/null 2>&1 || true
    docker exec "$CONTAINER" pkill -f '[m]ock-mcp.php' >/dev/null 2>&1 || true
    echo "mocks stopped"
    ;;
  *)
    echo "usage: $0 [start|stop|reset]" >&2
    exit 2
    ;;
esac
