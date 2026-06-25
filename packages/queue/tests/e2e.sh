#!/bin/sh
# Run the e2e suite against local workers and a kind cluster. Expects the
# compose services (redis, redis-cluster) to be up — see docker-compose.yml.
# The KubernetesJob suite needs docker + kind + tilt; missing CLI tools are
# downloaded at pinned versions and checksum-verified before use, and the suite
# self-skips when no cluster is available.
set -e
cd "$(dirname "$0")/.."

KIND_CLUSTER="${KIND_CLUSTER:-utopia-queue-e2e}"
KUBECONFIG_FILE="$(pwd)/.kubeconfig.e2e"
TOOLS="$(pwd)/.e2e-bin"
KIND_READY=0
mkdir -p "$TOOLS"
PATH="$TOOLS:$PATH"

# Pinned tool versions + linux/amd64 SHA-256 digests (CI runners). Bump together.
KIND_VERSION='v0.30.0'
KIND_SHA256='517ab7fc89ddeed5fa65abf71530d90648d9638ef0c4cde22c2c11f8097b8889'
KUBECTL_VERSION='v1.31.4'
KUBECTL_SHA256='298e19e9c6c17199011404278f0ff8168a7eca4217edad9097af577023a5620f'
TILT_VERSION='0.35.0'
TILT_SHA256='801d79890dfa884f732c310fb2af8b7a959e4ec1352cd5ee7d91d0972305cf2b'

verify() {
    # $1 = file, $2 = expected sha256
    echo "$2  $1" | sha256sum -c - > /dev/null 2>&1 || { echo "checksum mismatch for $1"; return 1; }
}

setup_kind() {
    command -v docker > /dev/null 2>&1 || { echo "docker unavailable — skipping KubernetesJob e2e"; return 1; }

    if ! command -v kind > /dev/null 2>&1; then
        echo "installing kind $KIND_VERSION..."
        curl -fsSL -o "$TOOLS/kind" "https://github.com/kubernetes-sigs/kind/releases/download/$KIND_VERSION/kind-linux-amd64" || return 1
        verify "$TOOLS/kind" "$KIND_SHA256" || return 1
        chmod +x "$TOOLS/kind"
    fi
    if ! command -v kubectl > /dev/null 2>&1; then
        echo "installing kubectl $KUBECTL_VERSION..."
        curl -fsSL -o "$TOOLS/kubectl" "https://dl.k8s.io/release/$KUBECTL_VERSION/bin/linux/amd64/kubectl" || return 1
        verify "$TOOLS/kubectl" "$KUBECTL_SHA256" || return 1
        chmod +x "$TOOLS/kubectl"
    fi
    if ! command -v tilt > /dev/null 2>&1; then
        echo "installing tilt $TILT_VERSION..."
        curl -fsSL -o "$TOOLS/tilt.tar.gz" "https://github.com/tilt-dev/tilt/releases/download/v$TILT_VERSION/tilt.$TILT_VERSION.linux.x86_64.tar.gz" || return 1
        verify "$TOOLS/tilt.tar.gz" "$TILT_SHA256" || return 1
        tar -xz -C "$TOOLS" -f "$TOOLS/tilt.tar.gz" tilt
        rm -f "$TOOLS/tilt.tar.gz"
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
