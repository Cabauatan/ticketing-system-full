#!/usr/bin/env bash
set -euo pipefail

image="${1:-ticketing-runtime-smoke:local}"
healthy="ticketing-runtime-smoke-${GITHUB_RUN_ID:-local}-$$"
broken="${healthy}-missing-storage"
artifact_dir="${2:-reports/container-runtime}"
mkdir -p "$artifact_dir"

cleanup() {
    docker logs "$healthy" > "$artifact_dir/healthy.log" 2>&1 || true
    docker logs "$broken" > "$artifact_dir/missing-storage.log" 2>&1 || true
    docker rm -f "$healthy" "$broken" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Synthetic credentials only. No application .env, Aiven, or email is used.
common=(
    --cap-drop=KILL --memory=512m
    -e APP_ENV=production -e APP_DEBUG=false
    -e APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=
    -e APP_URL=http://localhost -e FRONTEND_URL=http://localhost
    -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory:
    -e CACHE_STORE=file -e SESSION_DRIVER=file
    -e SESSION_SECURE_COOKIE=false -e SESSION_SAME_SITE=lax
    -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=null
    -e RUN_MIGRATIONS=false -e RUN_QUEUE_WORKER=false
    -e REVERB_APP_ID=smoke -e REVERB_APP_KEY=smoke-key
    -e REVERB_APP_SECRET=smoke-secret -e REVERB_ALLOWED_ORIGINS=localhost
    -e REVERB_HOST=127.0.0.1 -e REVERB_PORT=8081 -e REVERB_SCHEME=http
    -e LOG_CHANNEL=stderr
)

docker run -d --name "$healthy" "${common[@]}" \
    -e RUN_REVERB=true -e RUN_SCHEDULER=true \
    -p 127.0.0.1::10000 "$image" >/dev/null
port="$(docker port "$healthy" 10000/tcp | awk -F: '{print $NF}')"
base="http://127.0.0.1:$port"
ready=false
for attempt in $(seq 1 40); do
    if curl --fail --silent --max-time 3 "$base/up" >/dev/null; then
        ready=true
        break
    fi
    [ "$(docker inspect --format '{{.State.Running}}' "$healthy")" = true ] || break
    sleep 2
done
[ "$ready" = true ] || { echo 'FAIL: container never became ready'; exit 1; }

docker exec "$healthy" sh -ec '
    runtime_uid=$(id -u www-data)
    [ "$(awk "/^Uid:/ {print \$2}" /proc/1/status)" = "$runtime_uid" ]
    for proc in /proc/[0-9]*; do
        [ -r "$proc/comm" ] || continue
        name=$(cat "$proc/comm")
        case "$name" in
            php|php-fpm*|nginx|supervisord)
                [ "$(awk "/^Uid:/ {print \$2}" "$proc/status")" = "$runtime_uid" ]
                ;;
        esac
    done
'
echo 'PASS: Supervisor and running application processes share www-data UID'

# Keep session tokens out of logs and artifacts.
curl --fail --silent --max-time 10 -H 'Accept: application/json' "$base/api/csrf-token" \
    | python3 -c 'import json,sys; token=json.load(sys.stdin).get("token"); assert isinstance(token,str) and token'
echo 'PASS: session bootstrap works under the runtime UID'

python3 - "$port" <<'PY'
import base64
import os
import socket
import sys
import time

for attempt in range(15):
    try:
        with socket.create_connection(("127.0.0.1", int(sys.argv[1])), timeout=3) as connection:
            key = base64.b64encode(os.urandom(16)).decode()
            request = (
                "GET /app/smoke-key?protocol=7&client=smoke&version=1 HTTP/1.1\r\n"
                "Host: localhost\r\nOrigin: http://localhost\r\n"
                "Upgrade: websocket\r\nConnection: Upgrade\r\n"
                f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n"
            )
            connection.sendall(request.encode())
            response = bytearray()
            while b"\r\n\r\n" not in response:
                chunk = connection.recv(4096)
                assert chunk, "WebSocket closed before handshake"
                response.extend(chunk)
            assert bytes(response).split(b"\r\n", 1)[0] == b"HTTP/1.1 101 Switching Protocols"
            frames = bytearray(bytes(response).split(b"\r\n\r\n", 1)[1])
            while b"pusher:connection_established" not in frames:
                assert b"pusher:error" not in frames, "Configured Reverb application was rejected"
                chunk = connection.recv(4096)
                assert chunk and len(frames) < 64000, "No Reverb connection event"
                frames.extend(chunk)
        break
    except (OSError, AssertionError):
        if attempt == 14:
            raise
        time.sleep(1)
print("PASS: configured Reverb application accepts the public connection")
PY

docker stop --time=25 "$healthy" >/dev/null
[ "$(docker inspect --format '{{.State.ExitCode}}' "$healthy")" = 0 ] \
    || { echo 'FAIL: runtime did not shut down gracefully'; exit 1; }
docker logs "$healthy" > "$artifact_dir/healthy.log" 2>&1
if grep -E 'unknown problem killing|PermissionError|Operation not permitted' "$artifact_dir/healthy.log"; then
    echo 'FAIL: Supervisor could not signal an application process'
    exit 1
fi
echo 'PASS: shutdown succeeds with CAP_KILL removed'

docker run -d --name "$broken" "${common[@]}" \
    -e SESSION_DRIVER=database -e CACHE_STORE=array \
    -e RUN_REVERB=true -e RUN_SCHEDULER=true "$image" >/dev/null
for attempt in $(seq 1 30); do
    [ "$(docker inspect --format '{{.State.Running}}' "$broken")" = true ] || break
    sleep 1
done
[ "$(docker inspect --format '{{.State.Running}}' "$broken")" = false ] \
    || { echo 'FAIL: missing storage did not fail startup promptly'; exit 1; }
[ "$(docker inspect --format '{{.State.ExitCode}}' "$broken")" != 0 ] \
    || { echo 'FAIL: missing storage reported success'; exit 1; }
docker logs "$broken" > "$artifact_dir/missing-storage.log" 2>&1
grep -q 'Application storage readiness failed' "$artifact_dir/missing-storage.log"
grep -q 'no such table: sessions' "$artifact_dir/missing-storage.log"
if grep -E 'spawned:|supervisord started' "$artifact_dir/missing-storage.log"; then
    echo 'FAIL: processes started despite failed storage readiness'
    exit 1
fi
echo 'PASS: missing session storage fails before processes start'
