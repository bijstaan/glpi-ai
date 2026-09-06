#!/bin/sh
# Every PHP suite, with the mock vendor and MCP servers running behind them.
#
# Run inside the GLPI container, from the plugin directory:
#   docker compose -p glpi exec glpi sh -c 'cd /var/www/glpi/plugins/glpiai && tests/run.sh'
#
# adapters.php, tools-wire.php and streaming.php need nothing but PHP — they
# assert on what the adapters send and on what they make of what comes back. integration.php, tools.php, triage.php,
# drafts.php, reply.php, native-tools.php and assistant.php need the plugin to be active, and each restores the configuration and fixtures it
# touches on the way out.
set -e

cd "$(dirname "$0")/.."

php -S 127.0.0.1:9099 tests/mock-provider.php >/tmp/glpiai-mock.log 2>&1 &
vendor=$!
php -S 127.0.0.1:9098 tests/mock-mcp.php >/tmp/glpiai-mcp.log 2>&1 &
mcp=$!
trap 'kill $vendor $mcp 2>/dev/null' EXIT
sleep 1

rm -f /tmp/glpiai-mcp.jsonl /tmp/glpiai-oauth.json

status=0
php tests/adapters.php   || status=1
php tests/streaming.php  || status=1
php tests/tools-wire.php || status=1
php tests/integration.php || status=1
php tests/tools.php      || status=1
php tests/native-tools.php || status=1
php tests/triage.php     || status=1
php tests/drafts.php     || status=1
php tests/reply.php      || status=1
php tests/assistant.php  || status=1
php tests/oauth-toolbox.php || status=1
php tests/mcp-user-auth.php || status=1

exit $status
