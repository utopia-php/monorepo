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

out=$(php tests/benchmark.php --server=127.0.0.1 --port="$PORT" --iterations="$ITERATIONS" --concurrency="$CONCURRENCY")
echo "$out"

metric() { echo "$out" | grep -m1 "^$1:" | awk -F': ' '{print $2}' | cut -d' ' -f1 || true; }

CORES=$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo '?')
section="### dns — UDP throughput (${CORES} cores, ${ITERATIONS} queries per record, concurrency ${CONCURRENCY})

| req/s | avg ms | p50 | p95 | p99 | max |
|---|---|---|---|---|---|
| $(metric 'Requests Per Second') | $(metric 'Avg') | $(metric 'p50') | $(metric 'p95') | $(metric 'p99') | $(metric 'Max') |"

# GITHUB_STEP_SUMMARY: the run's own job summary.
# BENCH_REPORT: shared file a bench script appends its section to, so a
# caller (the Benchmark workflow) can collect every package into one place.
[ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '%s\n\n' "$section" >> "$GITHUB_STEP_SUMMARY"
[ -n "${BENCH_REPORT:-}" ] && printf '%s\n\n' "$section" >> "$BENCH_REPORT"

exit 0
