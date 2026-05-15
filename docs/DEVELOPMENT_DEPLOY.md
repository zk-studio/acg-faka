# 二开、部署与自动更新

## 项目结构

这是 PHP 8 + MySQL + Smarty 模板项目，不是 npm/Vite/React 这类独立前端工程。

- 前台模板：`app/View/User/Theme/Cartoon`
- 前台样式：`assets/user/css`
- 前台交互：`assets/user/controller`、`assets/user/js`
- 后台模板：`app/View/Admin`
- 后台样式和交互：`assets/admin`
- 入口文件：`index.php`
- 伪静态入口参数：`index.php?s=/user/index/index`

前端改动通常直接改 `.html`、`.css`、`.js`，不需要打包构建。

## 本地二开建议

1. 基于当前代码创建自己的仓库，不要直接依赖原作者仓库作为唯一远端。
2. 建议只改主题目录和 `assets/user`，业务接口改动再进入 `app/Controller`、`app/Service`。
3. 如果浏览器缓存了 CSS/JS，可以临时把 `index.php` 里的 `DEBUG` 改成 `true` 调试，提交前改回 `false`。
4. 线上不要使用后台自带的云更新覆盖二开代码，后续以自己的 Git 仓库作为发布来源。

## 服务器环境

最低要求：

- PHP `>= 8.0`
- MySQL `>= 5.6`，建议 5.7 或 8.0
- PHP 扩展：`gd`、`curl`、`PDO`、`pdo_mysql`、`json`、`session`、`zip`、`openssl`
- Composer
- Nginx 或 Apache

## 推荐 Docker 部署

本仓库已提供 Docker Compose 配置，推荐用于本地调试和服务器部署：

- `nginx`：站点入口、伪静态、gzip、静态资源缓存
- `php`：PHP-FPM 8.2、Composer、OPcache、Redis 扩展
- `mysql`：MySQL 8.0，启用 utf8mb4 和基础性能参数
- `redis`：用于 PHP session，减少 session 文件锁导致的卡顿

首次启动前复制环境变量：

```bash
cp .env.example .env
```

然后修改 `.env` 里的数据库密码。启动：

```bash
docker-compose up -d --build
```

本地默认访问：

```text
http://127.0.0.1:18080
```

安装向导里的数据库配置建议：

```text
数据库地址：mysql
数据库名：acg_faka
数据库账号：acg_faka
数据库密码：填写 .env 里的 MYSQL_PASSWORD
表前缀：acg_
```

Docker 方案已经内置官方文档提到的 Redis session 优化：

```ini
session.save_handler = redis
session.save_path = "tcp://redis:6379"
```

同时启用了 PHP OPcache、realpath cache、Nginx gzip、静态资源 30 天浏览器缓存、PHP-FPM 进程池参数和 MySQL 基础缓冲参数。

Nginx 伪静态：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^(.*)$ /index.php?s=$1 last;
        break;
    }
}
```

## 首次部署

服务器上执行，路径按你的实际站点目录替换：

```bash
cd /www/wwwroot
git clone 你的仓库地址 acg-faka
cd acg-faka
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
mkdir -p runtime assets/cache
chmod -R 775 runtime assets/cache kernel/Install config
```

然后访问域名，按安装页面填写数据库信息。安装完成后，在服务器仓库目录执行：

```bash
git update-index --skip-worktree config/database.php
git update-index --skip-worktree config/store.php 2>/dev/null || true
chmod +x scripts/server-update.sh
```

`config/database.php` 会被安装程序写入真实数据库账号，所以不要让后续 Git 更新覆盖它。

## 自动更新方式一：GitHub Actions 推送后更新服务器

已提供 `.github/workflows/deploy.yml`。你需要在 GitHub 仓库的 `Settings -> Secrets and variables -> Actions` 里配置：

- `SERVER_HOST`：服务器 IP 或域名
- `SERVER_PORT`：SSH 端口，通常是 `22`
- `SERVER_USER`：SSH 用户
- `SERVER_SSH_KEY`：私钥内容
- `APP_DIR`：服务器项目目录，例如 `/www/wwwroot/acg-faka`

服务器需要能在 `APP_DIR` 里执行 `git pull`。如果你的仓库是私有仓库，需要给服务器配置 deploy key 或访问令牌。

配置完成后，本地提交并推送到 `main` 分支，GitHub Actions 会 SSH 到服务器执行：

```bash
APP_DIR=/www/wwwroot/acg-faka BRANCH=main bash scripts/server-update.sh
```

## 自动更新方式二：服务器定时拉取

如果不想配置 GitHub Actions，可以用 crontab 定时执行：

```cron
*/2 * * * * cd /www/wwwroot/acg-faka && APP_DIR=/www/wwwroot/acg-faka BRANCH=main bash scripts/server-update.sh >> runtime/deploy.log 2>&1
```

这种方式简单，但不是实时更新，最多会有几分钟延迟。

## 更新脚本做了什么

`scripts/server-update.sh` 会：

- 加锁，避免多个更新同时执行
- 保护 `config/database.php` 和 `config/store.php`
- 拉取指定分支最新代码
- Docker 部署时执行 `docker-compose up -d --build`
- Docker 部署时在 PHP 容器内执行 `composer install --no-dev`
- 非 Docker 部署时执行宿主机 `composer install --no-dev`
- 创建运行目录 `runtime`、`assets/cache`
- 清理模板缓存 `runtime/view`
- 修正运行目录权限
- Docker 部署时重启 `php` 和 `nginx` 容器
- 非 Docker 部署时尝试 reload PHP-FPM 或重置 OPcache

常用执行方式：

```bash
APP_DIR=/www/wwwroot/acg-faka BRANCH=main WEB_USER=www PHP_FPM_SERVICE=php-fpm bash scripts/server-update.sh
```

Docker 部署常用执行方式：

```bash
APP_DIR=/www/wwwroot/acg-faka BRANCH=main USE_DOCKER=1 bash scripts/server-update.sh
```
