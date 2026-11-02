#!/usr/bin/env bash
# Serialised quality gate for parallel redesign agents: PHPStan + full PHPUnit.
# Uses a mkdir lock so two agents never run the suite against the shared test DB at once.
#   docs/redesign/tools/gate.sh            # phpstan + phpunit
#   docs/redesign/tools/gate.sh --filter X # extra args go to phpunit
#   docs/redesign/tools/gate.sh --clear-assets  # the ONLY allowed way to clear var/cache/dev + public/assets (waits for a running suite)
set -u
LOCK=/tmp/wboost-redesign-gate.lock
until mkdir "$LOCK" 2>/dev/null; do echo "gate: waiting for lock held by $(cat $LOCK/owner 2>/dev/null)"; sleep 20; done
echo "$$ $(date +%T)" > "$LOCK/owner"
trap 'rm -rf "$LOCK"' EXIT
cd "$(dirname "$0")/../../.."
if [ "${1:-}" = "--clear-assets" ]; then
  docker compose exec -T web rm -rf var/cache/dev public/assets && echo "gate: dev cache + compiled assets cleared"; exit 0
fi
docker compose exec -T web composer phpstan || { echo "gate: PHPSTAN FAILED"; exit 1; }
docker compose exec -T web vendor/bin/phpunit "$@" || { echo "gate: PHPUNIT FAILED"; exit 1; }
echo "gate: GREEN"
