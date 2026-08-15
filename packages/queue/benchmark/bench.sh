#!/usr/bin/env bash
# Queue consume benchmark: current branch (single + multi) and optional
# baseline from BENCH_BASELINE_REF (default origin/main).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO="$(cd "$ROOT/../.." && pwd)"
cd "$ROOT"

if ! php -m | grep -qi '^swoole$'; then
  echo "ext-swoole is required for the queue benchmark" >&2
  exit 1
fi

export BENCH_MESSAGES="${BENCH_MESSAGES:-2000}"
export BENCH_CONCURRENCY="${BENCH_CONCURRENCY:-8}"
export BENCH_QUEUES="${BENCH_QUEUES:-4}"
export BENCH_ROUNDS="${BENCH_ROUNDS:-12}"
export BENCH_WARMUP="${BENCH_WARMUP:-2}"
export BENCH_MEMORY_LIMIT_BYTES="${BENCH_MEMORY_LIMIT_BYTES:-2097152}"
export BENCH_BASELINE_REF="${BENCH_BASELINE_REF:-origin/main}"
export BENCH_SKIP_BASELINE="${BENCH_SKIP_BASELINE:-0}"

STATUS=0

echo "==> queue bench (current)"
php benchmark/run.php || STATUS=$?

if [ "$BENCH_SKIP_BASELINE" = "1" ]; then
  echo "==> skipping baseline (BENCH_SKIP_BASELINE=1)"
  exit "$STATUS"
fi

if ! git -C "$REPO" rev-parse --verify "$BENCH_BASELINE_REF" >/dev/null 2>&1; then
  echo "==> fetching $BENCH_BASELINE_REF"
  if [[ "$BENCH_BASELINE_REF" == origin/* ]]; then
    git -C "$REPO" fetch --no-tags --depth=1 origin "${BENCH_BASELINE_REF#origin/}:${BENCH_BASELINE_REF}" || true
  else
    git -C "$REPO" fetch --no-tags --depth=1 origin "$BENCH_BASELINE_REF" || true
  fi
fi

if ! git -C "$REPO" rev-parse --verify "$BENCH_BASELINE_REF" >/dev/null 2>&1; then
  echo "==> baseline ref $BENCH_BASELINE_REF not found; set BENCH_BASELINE_REF or fetch it" >&2
  echo "==> continuing without baseline"
  exit "$STATUS"
fi

WORKTREE="${BENCH_WORKTREE:-$ROOT/benchmark/.worktree-baseline}"
cleanup() {
  if [ -d "$WORKTREE" ]; then
    git -C "$REPO" worktree remove --force "$WORKTREE" >/dev/null 2>&1 || rm -rf "$WORKTREE"
  fi
}
trap cleanup EXIT

cleanup
echo "==> queue bench (baseline $BENCH_BASELINE_REF)"
git -C "$REPO" worktree add --detach "$WORKTREE" "$BENCH_BASELINE_REF"

BASE_QUEUE="$WORKTREE/packages/queue"
mkdir -p "$BASE_QUEUE/benchmark"
cp "$ROOT/benchmark/InMemoryConnection.php" "$BASE_QUEUE/benchmark/InMemoryConnection.php"
cp "$ROOT/benchmark/run-baseline.php" "$BASE_QUEUE/benchmark/run-baseline.php"

(
  cd "$BASE_QUEUE"
  composer update --no-interaction --prefer-dist --no-progress --ignore-platform-reqs
  php benchmark/run-baseline.php
) || STATUS=$?

exit "$STATUS"
