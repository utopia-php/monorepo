#!/bin/sh
# Run the e2e suite against local workers and a kind cluster. Expects the
# compose services (redis, redis-cluster) to be up — see docker-compose.yml.
# The KubernetesJob suite needs docker + kind + tilt; missing CLI tools are
# downloaded on the fly, and the suite self-skips when no cluster is available.
set -e
cd "$(dirname "$0")/.."

KIND_CLUSTER="${KIND_CLUSTER:-utopia-queue-e2e}"
KUBECONFIG_FILE="$(pwd)/.kubeconfig.e2e"
TOOLS="$(pwd)/.e2e-bin"
KIND_READY=0
mkdir -p "$TOOLS"
PATH="$TOOLS:$PATH"

setup_kind() {
    command -v docker > /dev/null 2>&1 || { echo "docker unavailable — skipping KubernetesJob e2e"; return 1; }

    if ! command -v kind > /dev/null 2>&1; then
        echo "installing kind..."
        curl -fsSL -o "$TOOLS/kind" "https://kind.sigs.k8s.io/dl/v0.30.0/kind-$(uname | tr '[:upper:]' '[:lower:]')-amd64"
        chmod +x "$TOOLS/kind"
    fi
    if ! command -v kubectl > /dev/null 2>&1; then
        echo "installing kubectl..."
        curl -fsSL -o "$TOOLS/kubectl" "https://dl.k8s.io/release/$(curl -fsSL https://dl.k8s.io/release/stable.txt)/bin/$(uname | tr '[:upper:]' '[:lower:]')/amd64/kubectl"
        chmod +x "$TOOLS/kubectl"
    fi
    if ! command -v tilt > /dev/null 2>&1; then
        echo "installing tilt..."
        curl -fsSL https://github.com/tilt-dev/tilt/releases/download/v0.35.0/tilt.0.35.0.linux.x86_64.tar.gz | tar -xz -C "$TOOLS" tilt
    fi

    if ! kind get clusters 2> /dev/null | grep -qx "$KIND_CLUSTER"; then
        kind create cluster --name "$KIND_CLUSTER" --wait 120s
    fi
    kind get kubeconfig --name "$KIND_CLUSTER" > "$KUBECONFIG_FILE"

    KIND_CLUSTER="$KIND_CLUSTER" KUBECONFIG="$KUBECONFIG_FILE" tilt ci
}

php tests/Queue/servers/Swoole/worker.php & SWOOLE=$!
php tests/Queue/servers/SwooleRedisCluster/worker.php & CLUSTER=$!
php tests/Queue/servers/Workerman/worker.php start & WORKERMAN=$!

cleanup() {
    kill -INT "$SWOOLE" "$CLUSTER" "$WORKERMAN" 2> /dev/null || true
    wait 2> /dev/null || true
    if [ "$KIND_READY" = "1" ]; then
        kind delete cluster --name "$KIND_CLUSTER" > /dev/null 2>&1 || true
    fi
    rm -f "$KUBECONFIG_FILE"
}
trap cleanup EXIT INT TERM

if setup_kind; then
    KIND_READY=1
    export KUBERNETES_E2E=true
    export KUBECONFIG="$KUBECONFIG_FILE"
fi

sleep 3

phpunit --testsuite e2e
