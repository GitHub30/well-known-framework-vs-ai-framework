#!/usr/bin/env bash
set -euo pipefail

INFRA_DIR="$(cd "$(dirname "$0")" && pwd)"
RUN_DIR="/tmp/clouddrive-run"
mkdir -p "$RUN_DIR/nginx-body" "$RUN_DIR/nginx-proxy" "$RUN_DIR/nginx-fastcgi" "$RUN_DIR/nginx-uwsgi" "$RUN_DIR/nginx-scgi"
chmod 700 "$RUN_DIR"

# Generate and persist per-deployment 256-bit CSRF HMAC secrets (no hardcoded secrets!)
SECRET_FILE="${RUN_DIR}/secrets.env"
if [[ ! -f "$SECRET_FILE" ]]; then
  umask 077
  {
    echo "SECRET_AI=$(php -r 'echo bin2hex(random_bytes(32));')"
    echo "SECRET_LARAVEL=$(php -r 'echo bin2hex(random_bytes(32));')"
    echo "SECRET_PHALCON=$(php -r 'echo bin2hex(random_bytes(32));')"
    echo "SECRET_SLIM=$(php -r 'echo bin2hex(random_bytes(32));')"
  } > "$SECRET_FILE"
fi
# shellcheck disable=SC1090
source "$SECRET_FILE"

generate_fpm_conf() {
  local name="$1"
  local secret=""
  local app_url=""
  case "$name" in
    ai)      secret="$SECRET_AI";      app_url="http://localhost:8081" ;;
    laravel) secret="$SECRET_LARAVEL"; app_url="http://localhost:8082" ;;
    phalcon) secret="$SECRET_PHALCON"; app_url="http://localhost:8083" ;;
    slim)    secret="$SECRET_SLIM";    app_url="http://localhost:8084" ;;
  esac

  cat > "$RUN_DIR/fpm-${name}.conf" <<EOF
[global]
pid = ${RUN_DIR}/fpm-${name}.pid
error_log = ${RUN_DIR}/fpm-${name}.log
daemonize = yes

[${name}]
listen = ${RUN_DIR}/fpm-${name}.sock
listen.mode = 0660
listen.backlog = 1024
pm = static
pm.max_children = 4
pm.max_requests = 0
request_terminate_timeout = 30s
clear_env = yes
env[PATH] = /usr/local/bin:/usr/bin:/bin
env[APP_SECRET] = ${secret}
env[APP_URL] = ${app_url}
EOF
}

start_fpm() {
  local name="$1"
  local ini="${INFRA_DIR}/php-fpm-common.ini"
  if [[ "$name" == "phalcon" ]]; then
    ini="${INFRA_DIR}/php-fpm-phalcon.ini"
  fi
  stop_fpm "$name"
  generate_fpm_conf "$name"
  /usr/sbin/php-fpm8.3 -c "$ini" -y "${RUN_DIR}/fpm-${name}.conf"
}

stop_fpm() {
  local name="$1"
  local pidfile="${RUN_DIR}/fpm-${name}.pid"
  if [[ -f "$pidfile" ]]; then
    local pid
    pid="$(cat "$pidfile" 2>/dev/null || true)"
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
      for _ in $(seq 1 20); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.1
      done
    fi
    rm -f "$pidfile" "${RUN_DIR}/fpm-${name}.sock"
  fi
}

start_nginx() {
  stop_nginx
  /usr/sbin/nginx -c "${INFRA_DIR}/nginx.conf" -p "$RUN_DIR"
}

stop_nginx() {
  local pidfile="${RUN_DIR}/nginx.pid"
  if [[ -f "$pidfile" ]]; then
    local pid
    pid="$(cat "$pidfile" 2>/dev/null || true)"
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
      for _ in $(seq 1 20); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.1
      done
    fi
    rm -f "$pidfile"
  fi
}

cmd="${1:-start}"
target="${2:-all}"

case "$cmd" in
  start)
    if [[ "$target" == "all" ]]; then
      for p in ai laravel phalcon slim; do start_fpm "$p"; done
      start_nginx
    else
      start_fpm "$target"
      [[ -f "${RUN_DIR}/nginx.pid" ]] || start_nginx
    fi
    echo "Started servers (${target})."
    ;;
  stop)
    if [[ "$target" == "all" ]]; then
      stop_nginx
      for p in ai laravel phalcon slim; do stop_fpm "$p"; done
    else
      stop_fpm "$target"
    fi
    echo "Stopped servers (${target})."
    ;;
  restart)
    "$0" stop "$target"
    "$0" start "$target"
    ;;
  status)
    for name in nginx fpm-ai fpm-laravel fpm-phalcon fpm-slim; do
      pidfile="${RUN_DIR}/${name}.pid"
      if [[ -f "$pidfile" ]] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
        echo "${name}: running (pid $(cat "$pidfile"))"
      else
        echo "${name}: stopped"
      fi
    done
    ;;
  *)
    echo "Usage: $0 {start|stop|restart|status} [all|ai|laravel|phalcon|slim]"
    exit 1
    ;;
esac
