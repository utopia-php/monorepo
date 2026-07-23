#!/usr/bin/env bash
# Benchmark the DNS server: start the sample Swoole server from the test
# resources on offset ports, drive it with tests/benchmark.php over UDP,
# and tear down. Requires ext-swoole.
set -eu

cd "$(dirname "$0")/../.." # packages/dns

php -r 'exit(extension_loaded("swoole") ? 0 : 1);' || { echo "ext-swoole is required" >&2; exit 1; }

PORT="${PORT:-8053}"
ITERATIONS="${ITERATIONS:-1000}"
CONCURRENCY="${CONCURRENCY:-20}"

PORT=$PORT HTTP_PORT=$((PORT + 1)) PROXY_PORT=$((PORT + 2)) \
    php tests/resources/server.php >/tmp/dns-bench-server.log 2>&1 &
pid=$!
trap 'kill $pid 2>/dev/null || true' EXIT

up=
for _ in $(seq 1 40); do
    if php tests/bench/probe.php "$PORT" 2>/dev/null; then up=1; break; fi
    sleep 0.5
done
if [ -z "$up" ]; then
    echo "server failed to start" >&2
    cat /tmp/dns-bench-server.log >&2
    exit 1
fi

php tests/benchmark.php --server=127.0.0.1 --port="$PORT" --iterations="$ITERATIONS" --concurrency="$CONCURRENCY"
