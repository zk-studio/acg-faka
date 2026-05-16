#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/acg-faka}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
WEB_USER="${WEB_USER:-www}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-}"
USE_DOCKER="${USE_DOCKER:-auto}"
RUN_COMPOSER="${RUN_COMPOSER:-auto}"

cd "$APP_DIR"

mkdir -p runtime
exec 9>"$APP_DIR/runtime/deploy.lock"
if ! flock -n 9; then
  echo "Another deployment is running."
  exit 1
fi

if [ ! -d .git ]; then
  echo "$APP_DIR is not a git repository."
  exit 1
fi

# These files are created or changed by the installer/admin panel on the server.
git update-index --skip-worktree config/database.php 2>/dev/null || true
git update-index --skip-worktree config/store.php 2>/dev/null || true

git fetch --prune origin "$BRANCH"

current_branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$current_branch" != "$BRANCH" ]; then
  git checkout "$BRANCH"
fi

git reset --hard "origin/$BRANCH"

compose_cmd=""
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  compose_cmd="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  compose_cmd="docker-compose"
fi

if [ "$USE_DOCKER" = "auto" ] && [ -n "$compose_cmd" ] && [ -f docker-compose.yml ]; then
  USE_DOCKER=1
fi

if [ "$USE_DOCKER" = "1" ]; then
  if [ -z "$compose_cmd" ]; then
    echo "Docker Compose is not available."
    exit 1
  fi
  if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
    echo "Created .env from .env.example. Review database passwords for production."
  fi
  if ! grep -q '^COMPOSE_PROJECT_NAME=' .env 2>/dev/null; then
    project_name="$(basename "$APP_DIR" | tr -cd '[:alnum:]_-')"
    printf '\nCOMPOSE_PROJECT_NAME=%s\n' "$project_name" >> .env
  fi
  $compose_cmd up -d --build
  if [ "$RUN_COMPOSER" = "1" ] || { [ "$RUN_COMPOSER" = "auto" ] && [ ! -f vendor/autoload.php ]; }; then
    $compose_cmd exec -T php composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  fi
else
  if [ "$RUN_COMPOSER" = "0" ]; then
    echo "Composer installation skipped."
  elif command -v "$COMPOSER_BIN" >/dev/null 2>&1; then
    "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  else
    echo "Composer not found, skipped dependency installation."
  fi
fi

mkdir -p runtime runtime/view runtime/plugin assets/cache
mkdir -p app/Plugin app/Pay app/View/User/Theme kernel/Install/OS
rm -rf runtime/view/*

if [ "$USE_DOCKER" = "1" ] && [ "$(id -u)" = "0" ]; then
  # 让容器里的 www-data (uid 33) 能写：插件商店下载/解压、上传缓存、安装锁文件、配置
  chown -R 33:33 runtime assets/cache kernel/Install config \
                 app/Plugin app/Pay app/View/User/Theme
elif [ "$(id -u)" = "0" ] && id "$WEB_USER" >/dev/null 2>&1; then
  chown -R "$WEB_USER:$WEB_USER" runtime assets/cache kernel/Install config \
                                  app/Plugin app/Pay app/View/User/Theme
fi

find runtime assets/cache -type d -exec chmod 775 {} \; 2>/dev/null || true
find runtime assets/cache -type f -exec chmod 664 {} \; 2>/dev/null || true

if [ "$USE_DOCKER" = "1" ]; then
  $compose_cmd restart php nginx >/dev/null
elif [ -n "$PHP_FPM_SERVICE" ] && command -v systemctl >/dev/null 2>&1; then
  systemctl reload "$PHP_FPM_SERVICE"
elif command -v "$PHP_BIN" >/dev/null 2>&1; then
  "$PHP_BIN" -r 'function_exists("opcache_reset") && opcache_reset();' >/dev/null 2>&1 || true
fi

echo "Deployment completed: $(date '+%Y-%m-%d %H:%M:%S')"
