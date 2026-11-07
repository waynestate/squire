#!/bin/sh
# scripts/contract-image.sh: prove that release.sh runs inside an image, on the fallback base (SQUIRE-40).
#
# The real Citadel image is internal, so the stand-in is the fallback base Squire already builds
# (.squire/base-fallback.Dockerfile, tag squire/php-parity-base:8.4): php:8.4-apache plus Node 22, multi-arch.
#
#   scripts/contract-image.sh [--platform=linux/arm64|linux/amd64] [--keep]
#
# It makes a throwaway fixture app (squire init --target=raw, composer.json and composer.lock seeded from deploy/,
# public/index.php and public/up.php from the raw template), builds the fallback base and the app image for the
# platform, runs the container with a free local port and the fixture mounted at /citadel/src the way the kit's
# compose file mounts it, runs scripts/release.sh inside as the citadel user with the smoke test on, and asserts:
#
#   - release.sh exited 0
#   - /up.php answers HTTP 200 from the host
#   - php health.php exits 0 inside the container
#   - the first-release deployment log exists with no failed sibling
#
# and then the hardening, inside the running image (scripts/contract-image-checks.php, piped to php in the container):
#
#   - Apache: Server is exactly "Apache", no X-Powered-By, TRACE answers 405 without a version footer (SQUIRE-9)
#   - the web's opcache is on with validate_timestamps 0, and the release warns about it in place (SQUIRE-10)
#   - the web disables exec and the rest, keeps proc_open and popen; the CLI disables nothing, and a Composer script
#     that shells out runs in the release (SQUIRE-29)
#   - a secret mounted at /run/secrets reaches the web and the release by name, and its value is in no log, no layer
#     and no image config (SQUIRE-18)
#
# One line per assertion, PASS or FAIL; exit 1 on any FAIL. Unless --keep, the container, the app image, the logs
# volume and the temp dir are removed (the shared base image stays, like any pulled image).
#
# The mirror holds the signature: this proves the release mechanics, not parity, because it is not the real image.
set -eu

REPO="$(cd "$(dirname "$0")/.." && pwd)"

# Under Git Bash (MSYS) the shell would rewrite arguments that look like Unix paths before docker sees them, so
# container paths such as /citadel/src arrive mangled. Stop that rewriting and convert host paths to Windows form
# with cygpath instead. On Linux neither is needed and win_path is the identity.
if command -v cygpath >/dev/null 2>&1; then
    export MSYS2_ARG_CONV_EXCL="*"
    win_path() { cygpath -w "$1"; }
else
    win_path() { printf '%s' "$1"; }
fi

BASE_TAG="squire/php-parity-base:8.4"
APP_TAG="squire/contract-app:$$"
CID="squire-contract-$$"
LOGVOL="squire-contract-logs-$$"
# A throwaway secret, mounted as a file the way Docker, Compose and Kubernetes mount one (SQUIRE-18).
SECRET_NAME="SQUIRE_CONTRACT_SECRET"
SECRET_VALUE="contract-secret-$$-$(date +%s)"
KEEP=0
PLATFORM=""
TMPROOT=""
FAILURES=0

usage() {
    printf 'usage: scripts/contract-image.sh [--platform=linux/arm64|linux/amd64] [--keep]\n'
    printf '\n'
    printf 'Proves that scripts/release.sh runs inside the fallback image: builds a fixture app, builds the\n'
    printf 'fallback base and the app image for the platform, runs the container, runs release.sh as citadel\n'
    printf 'with the smoke test on, and asserts the smoke URL, health.php and the first-release log.\n'
    printf '\n'
    printf '  --platform=linux/arm64|linux/amd64  build and run for this platform (default: the machine%s)\n' \
        "'s own"
    printf '  --keep                              keep the container, the app image, the volume and the temp dir\n'
}

die() {
    printf 'contract-image: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    [ -n "$TMPROOT" ] || return 0
    docker rm -f "$CID" >/dev/null 2>&1 || true
    if [ "$KEEP" = "1" ]; then
        printf 'kept: container %s, image %s, volume %s, dir %s\n' "$CID" "$APP_TAG" "$LOGVOL" "$TMPROOT"
    else
        docker rmi "$APP_TAG" >/dev/null 2>&1 || true
        docker volume rm "$LOGVOL" >/dev/null 2>&1 || true
        rm -rf "$TMPROOT"
    fi
}
trap cleanup EXIT

# Print the HTTP status of a URL, or 000 when nothing answers. curl first, then PHP (on the host, then in Docker).
http_status() {
    cs_url="$1"
    cs_code=""
    if command -v curl >/dev/null 2>&1; then
        cs_code="$(curl --silent --output /dev/null --max-time 10 --write-out '%{http_code}' "$cs_url" 2>/dev/null || true)"
    elif command -v php >/dev/null 2>&1; then
        # shellcheck disable=SC2016  # the PHP program is single-quoted on purpose
        cs_code="$(php -r '$c = stream_context_create(["http" => ["timeout" => 10, "ignore_errors" => true]]); @file_get_contents($argv[1], false, $c); preg_match("#^HTTP/\S+\s+(\d{3})#", $http_response_header[0] ?? "", $m); echo $m[1] ?? "000";' -- "$cs_url" 2>/dev/null || true)"
    else
        # shellcheck disable=SC2016  # the PHP program is single-quoted on purpose
        cs_code="$(docker run --rm php:8.4-cli php -r '$c = stream_context_create(["http" => ["timeout" => 10, "ignore_errors" => true]]); @file_get_contents($argv[1], false, $c); preg_match("#^HTTP/\S+\s+(\d{3})#", $http_response_header[0] ?? "", $m); echo $m[1] ?? "000";' -- "$cs_url" 2>/dev/null || true)"
    fi
    [ -n "$cs_code" ] || cs_code="000"
    printf '%s' "$cs_code"
}

# Ask the kernel for a free TCP port on loopback: bind to port 0, read the assigned port, release it.
pick_port() {
    if command -v php >/dev/null 2>&1; then
        # shellcheck disable=SC2016  # the PHP program is single-quoted on purpose
        php -r '$s = @stream_socket_server("tcp://127.0.0.1:0", $e, $m); if ($s === false) { fwrite(STDERR, "port: $m\n"); exit(1); } $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1), "\n";'
    else
        # shellcheck disable=SC2016  # the PHP program is single-quoted on purpose
        docker run --rm php:8.4-cli php -r '$s = @stream_socket_server("tcp://127.0.0.1:0", $e, $m); if ($s === false) { fwrite(STDERR, "port: $m\n"); exit(1); } $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1), "\n";'
    fi
}

make_fixture() {
    FIXTURE="$TMPROOT/app"
    mkdir -p "$FIXTURE"
    cp "$REPO/deploy/composer.json" "$FIXTURE/composer.json"
    cp "$REPO/deploy/composer.lock" "$FIXTURE/composer.lock"
    if command -v php >/dev/null 2>&1; then
        [ -f "$REPO/vendor/autoload.php" ] || die "php bin/squire needs vendor/autoload.php; run 'composer install' in the Squire repository first"
        (cd "$REPO" && php bin/squire init --path="$FIXTURE" --target=raw)
    else
        # No PHP on this machine: run the initializer in Docker. The named vendor volume keeps composer install
        # out of the way and fast on later runs, the same way the composer ci check does it.
        docker run --rm \
            -v "$(win_path "$REPO"):/app" \
            -v "$(win_path "$TMPROOT"):/work" \
            -v squire-vendor:/app/vendor \
            -w /app \
            composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 \
            sh -c 'if [ ! -f vendor/autoload.php ]; then composer install --no-interaction --quiet; fi; php bin/squire init --path=/work/app --target=raw'
    fi
    # public/index.php and public/up.php come from the raw template; init writes them, but make sure they are here.
    [ -f "$FIXTURE/public/index.php" ] || cp "$REPO/templates/raw/public/index.php" "$FIXTURE/public/index.php"
    [ -f "$FIXTURE/public/up.php" ] || cp "$REPO/templates/raw/public/up.php" "$FIXTURE/public/up.php"
    # The app's entry: a probe page that reports the web's own PHP settings to contract-image-checks.php.
    cp "$REPO/scripts/contract-image-probe.php" "$FIXTURE/app.php"
    mkdir -p "$TMPROOT/secrets"
    printf '%s' "$SECRET_VALUE" >"$TMPROOT/secrets/$SECRET_NAME"
    chmod 0644 "$TMPROOT/secrets/$SECRET_NAME"
    for f in build.sh deployments/001-first-release.sh scripts/release.sh health.php public/index.php public/up.php .squire/Dockerfile .squire/base-fallback.Dockerfile; do
        [ -f "$FIXTURE/$f" ] || die "init did not write $f into the fixture"
    done
    printf 'fixture ready in %s\n' "$FIXTURE" >&2
}

build_images() {
    # Match the container's citadel user to the host user where that makes sense (Linux bind mounts); the base
    # Dockerfile defaults to 1000:1000, which is also right for Windows, where ownership is emulated.
    uid="$(id -u 2>/dev/null || printf '1000')"
    gid="$(id -g 2>/dev/null || printf '1000')"
    case "$uid" in '' | *[!0-9]*) uid=1000 ;; esac
    case "$gid" in '' | *[!0-9]*) gid=1000 ;; esac
    if [ "$uid" -lt 1 ] || [ "$uid" -gt 65534 ]; then uid=1000; fi
    if [ "$gid" -lt 1 ] || [ "$gid" -gt 65534 ]; then gid=1000; fi
    printf 'building the fallback base image %s for %s\n' "$BASE_TAG" "$PLATFORM" >&2
    docker buildx build --load --platform "$PLATFORM" \
        --build-arg "CITADEL_SOURCE_UID=$uid" \
        --build-arg "CITADEL_SOURCE_GID=$gid" \
        -f "$(win_path "$FIXTURE/.squire/base-fallback.Dockerfile")" \
        -t "$BASE_TAG" \
        "$(win_path "$FIXTURE/.squire")"
    printf 'building the app image %s for %s\n' "$APP_TAG" "$PLATFORM" >&2
    docker buildx build --load --platform "$PLATFORM" \
        --build-arg "BASE_IMAGE=$BASE_TAG" \
        -f "$(win_path "$FIXTURE/.squire/Dockerfile")" \
        -t "$APP_TAG" \
        "$(win_path "$FIXTURE")"
}

run_container() {
    PORT="$(pick_port)"
    docker volume create "$LOGVOL" >/dev/null
    # The kit's compose file starts a one-shot logs-init container that makes the log dirs with the right owners.
    docker run --rm --entrypoint sh \
        -v "$LOGVOL":/citadel/logs \
        "$APP_TAG" \
        -c 'mkdir -p /citadel/logs/deployments /citadel/logs/apache && chown citadel:citadel /citadel/logs/deployments && chown -R prisoner:prisoner /citadel/logs/apache 2>/dev/null || true'
    # The app source, as the kit's compose file mounts it, plus the logs volume.
    docker run -d \
        --name "$CID" \
        -p "127.0.0.1:$PORT:80" \
        -v "$(win_path "$FIXTURE")":/citadel/src \
        -v "$LOGVOL":/citadel/logs \
        -v "$(win_path "$TMPROOT/secrets/$SECRET_NAME")":"/run/secrets/$SECRET_NAME":ro \
        "$APP_TAG" >/dev/null
    printf 'container %s up on http://127.0.0.1:%s (the first release runs before Apache starts)\n' "$CID" "$PORT" >&2
}

wait_for_apache() {
    i=0
    while [ "$i" -lt 120 ]; do
        running="$(docker inspect -f '{{.State.Running}}' "$CID" 2>/dev/null || printf 'false')"
        if [ "$running" != "true" ]; then
            docker logs "$CID" >&2 || true
            die "the container stopped before Apache came up; see the log above"
        fi
        code="$(http_status "http://127.0.0.1:$PORT/up.php")"
        if [ "$code" = "200" ]; then
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done
    die "Apache did not answer 200 on /up.php within 240s"
}

report() {
    if [ "$1" = "0" ]; then
        printf 'PASS: %s\n' "$2"
    else
        printf 'FAIL: %s\n' "$2"
        FAILURES=$((FAILURES + 1))
    fi
}

for arg in "$@"; do
    case "$arg" in
        --platform=*) PLATFORM="${arg#--platform=}" ;;
        --keep) KEEP=1 ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $arg" ;;
    esac
done

if [ -z "$PLATFORM" ]; then
    case "$(uname -m)" in
        aarch64 | arm64) PLATFORM="linux/arm64" ;;
        x86_64 | amd64) PLATFORM="linux/amd64" ;;
        *) die "cannot guess a platform for $(uname -m); pass --platform=linux/arm64 or --platform=linux/amd64" ;;
    esac
fi
case "$PLATFORM" in
    linux/arm64 | linux/amd64) ;;
    *) die "unsupported platform: $PLATFORM (use linux/arm64 or linux/amd64)" ;;
esac

command -v docker >/dev/null 2>&1 || die "docker is not installed or not on PATH"
docker buildx version >/dev/null 2>&1 || die "'docker buildx' is required"
[ -d "$REPO/deploy" ] || die "deploy/ not found; run this from a checkout of the Squire repository"

TMPROOT="$(mktemp -d)" || die "mktemp -d failed"

make_fixture
build_images
run_container
wait_for_apache

# A Composer script that shells out, so the release proves the CLI keeps what the web loses (SQUIRE-29).
docker exec -u citadel -w /citadel/src "$CID" composer config scripts.post-install-cmd \
    "@php -r \"file_put_contents('.contract-composer-script', shell_exec('echo ok'));\""

# release.sh again, as the citadel user, with the smoke test on (its default). The first release already ran it
# without the smoke test at container start, so this is the idempotency half of the contract.
rc=0
docker exec -u citadel -w /citadel/src "$CID" bash ./scripts/release.sh >"$TMPROOT/release.log" 2>&1 || rc=$?
if [ "$rc" != "0" ]; then
    tail -n 30 "$TMPROOT/release.log" >&2 || true
fi
report "$rc" "release.sh ran as citadel with the smoke test on (exit $rc)"

status="$(http_status "http://127.0.0.1:$PORT/up.php")"
rc=0
[ "$status" = "200" ] || rc=1
report "$rc" "/up.php answered HTTP 200 from the host (got $status)"

rc=0
docker exec -u citadel -w /citadel/src "$CID" php health.php >"$TMPROOT/health.log" 2>&1 || rc=$?
if [ "$rc" != "0" ]; then
    cat "$TMPROOT/health.log" >&2 || true
fi
report "$rc" "php health.php exited 0 inside the container"

rc=0
docker exec -u citadel "$CID" sh -c '[ -f /citadel/logs/deployments/001-first-release.sh.log ] && [ ! -e /citadel/logs/deployments/001-first-release.sh.failed.log ]' || rc=1
report "$rc" "the first-release deployment log exists with no failed sibling"

# --- the hardening, checked in the running image (SQUIRE-40) ------------------------------------------------------

rc=0
grep -q "secrets from /run/secrets: $SECRET_NAME" "$TMPROOT/release.log" || rc=1
report "$rc" "the release loaded the mounted secret by name"

rc=0
if grep -q "$SECRET_VALUE" "$TMPROOT/release.log" "$TMPROOT/health.log"; then rc=1; fi
if docker exec -u citadel "$CID" sh -c 'cat /citadel/logs/deployments/*.log' 2>/dev/null | grep -q "$SECRET_VALUE"; then rc=1; fi
report "$rc" "the secret's value is in no release or deployment log"

rc=0
if docker history --no-trunc "$APP_TAG" 2>/dev/null | grep -q "$SECRET_VALUE"; then rc=1; fi
if docker image inspect "$APP_TAG" 2>/dev/null | grep -q "$SECRET_VALUE"; then rc=1; fi
if docker image inspect "$BASE_TAG" 2>/dev/null | grep -q "$SECRET_VALUE"; then rc=1; fi
report "$rc" "the secret's value is in no image layer or image config"

rc=0
grep -q "opcache.validate_timestamps=0: Apache keeps serving" "$TMPROOT/release.log" || rc=1
report "$rc" "the release warned that a frozen opcache needs an Apache restart after a release in place"

secret_md5="$(docker exec "$CID" php -r 'echo md5_file($argv[1]);' "/run/secrets/$SECRET_NAME")"
rc=0
docker exec -i -u citadel \
    -e "CONTRACT_SECRET_NAME=$SECRET_NAME" \
    -e "CONTRACT_SECRET_MD5=$secret_md5" \
    -e "CONTRACT_APP_DIR=/citadel/src" \
    "$CID" php <"$REPO/scripts/contract-image-checks.php" >"$TMPROOT/checks.log" 2>&1 || rc=$?
cat "$TMPROOT/checks.log"
FAILURES=$((FAILURES + $(grep -c '^FAIL: ' "$TMPROOT/checks.log" || true)))
if [ "$rc" != "0" ] && ! grep -q '^FAIL: ' "$TMPROOT/checks.log"; then
    report 1 "contract-image-checks.php ran to the end (exit $rc)"
fi

if [ "$FAILURES" != "0" ]; then
    printf 'contract-image: %s assertion(s) failed\n' "$FAILURES" >&2
    exit 1
fi
printf 'contract-image: all assertions passed\n'
