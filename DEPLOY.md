# Deploying Choose'Tounsi (Ubuntu VPS, 4–8 GB RAM)

One server runs everything. Only nginx is public; every other process listens on 127.0.0.1.

| Component | Repo / folder | Runs as | Listens on | Start / restart |
|---|---|---|---|---|
| nginx (TLS, reverse proxy) | – | `nginx` | :80, :443 | `sudo systemctl restart nginx` |
| MySQL 8 | – | `mysql` | 127.0.0.1:3306 | `sudo systemctl restart mysql` |
| Laravel API (backend) | `/var/www/choosetounsi-backend` | PHP-FPM 8.2 | unix socket | `sudo systemctl restart php8.2-fpm` |
| Queue worker | backend | `choosetounsi-queue` (systemd) | – | `sudo systemctl restart choosetounsi-queue` |
| Scheduler | backend | cron, every minute | – | `crontab -u www-data -l` |
| Storefront + seller dashboard (Next.js, same app) | `/var/www/choosetounsi-frontend` | `choosetounsi-frontend` (systemd) | 127.0.0.1:3000 | `sudo systemctl restart choosetounsi-frontend` |
| Admin panel (Next.js) | `/var/www/admin-panel` | `choosetounsi-admin` (systemd) | 127.0.0.1:3001 | `sudo systemctl restart choosetounsi-admin` |
| AI service (photo search, CLIP) | `/opt/choosetounsi-ai-service` | `choosetounsi-ai` (systemd) | 127.0.0.1:8001 | `sudo systemctl restart choosetounsi-ai` |

Domains used below: `choosetounsi.tn` (storefront), `api.choosetounsi.tn` (Laravel),
`admin.choosetounsi.tn` (admin). Replace them with yours.

The unit files are in `deploy/` (this repo) and `choosetounsi-ai-service/deploy/`.

---

## 1. Server basics

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server git unzip curl ufw software-properties-common python3-venv python3-pip
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs

sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable   # 3000/3001/3306/8001 stay closed
```

### Swap (4 GB servers: do it; 8 GB: 2 GB is enough)

```bash
sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swap.conf && sudo sysctl --system
```

RAM budget (4 GB): MySQL ~1 GB (`innodb_buffer_pool_size = 768M` in
`/etc/mysql/mysql.conf.d/mysqld.cnf`), PHP-FPM ~0.6 GB (`pm.max_children = 10`), the two
Next.js apps ~0.5 GB, AI service ~0.2 GB, queue worker ~0.1 GB, the rest for the OS.

## 2. MySQL

```bash
sudo mysql_secure_installation
sudo mysql -e "CREATE DATABASE choosetounsi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'choosetounsi'@'localhost' IDENTIFIED BY 'CHANGE-ME';
  GRANT ALL ON choosetounsi.* TO 'choosetounsi'@'localhost'; FLUSH PRIVILEGES;"
# Import a dump from development if you have one:
#   mysql -u choosetounsi -p choosetounsi < choosetounsi.sql
```

Daily backup (cron as root): `0 3 * * * mysqldump --single-transaction choosetounsi | gzip > /var/backups/choosetounsi-$(date +\%F).sql.gz`

## 3. Laravel backend

```bash
sudo mkdir -p /var/www && sudo chown $USER /var/www && cd /var/www
git clone <backend-repo-url> choosetounsi-backend && cd choosetounsi-backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

`.env` (production values):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.choosetounsi.tn
DB_DATABASE=choosetounsi
DB_USERNAME=choosetounsi
DB_PASSWORD=CHANGE-ME
QUEUE_CONNECTION=database
CACHE_DRIVER=file
# Photo search: the AI service on this machine (move it later: change the URL only)
AI_SERVICE_URL=http://127.0.0.1:8001
AI_SERVICE_TOKEN=<long random string, same as the AI service .env>   # openssl rand -hex 32
```

plus the mail (Brevo), Groq, Pusher, payment keys you use in development.

```bash
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

### Queue worker (systemd)

```bash
sudo cp deploy/choosetounsi-queue.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now choosetounsi-queue
journalctl -u choosetounsi-queue -f
```

(Supervisor works too: see docs/QUEUE_AND_SCHEDULER.md. Use one of the two, not both.)

### Scheduler (cron, every minute)

```bash
sudo crontab -u www-data -e
# add:
* * * * * cd /var/www/choosetounsi-backend && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` shows the jobs (nightly `search:build-index` 02:15,
`image-search:rebuild` 02:45, ads, promotions, forecasts…).

## 4. AI service (photo search)

```bash
sudo git clone <ai-service-repo-url> /opt/choosetounsi-ai-service
sudo chown -R www-data:www-data /opt/choosetounsi-ai-service
cd /opt/choosetounsi-ai-service
sudo -u www-data python3 -m venv .venv
sudo -u www-data .venv/bin/pip install -r requirements.txt
sudo -u www-data cp .env.example .env     # set AI_SERVICE_TOKEN = backend's AI_SERVICE_TOKEN
```

The model is not in git: copy it from your PC (one file, 89 MB):

```powershell
scp C:\xampp\htdocs\choosetounsi-ai-service\models\clip-vision-int8.onnx user@SERVER:/tmp/
```
```bash
sudo -u www-data mkdir -p /opt/choosetounsi-ai-service/models
sudo mv /tmp/clip-vision-int8.onnx /opt/choosetounsi-ai-service/models/ && sudo chown www-data: /opt/choosetounsi-ai-service/models/*
```

Start on boot, restart on crash, 127.0.0.1 only:

```bash
sudo cp /opt/choosetounsi-ai-service/deploy/choosetounsi-ai.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now choosetounsi-ai
curl -s http://127.0.0.1:8001/health        # {"status":"ok","image_model":"clip-vision-int8.onnx@…"}
```

Then fingerprint the catalog photos (also runs nightly; safe to re-run):

```bash
cd /var/www/choosetounsi-backend
sudo -u www-data php artisan image-search:rebuild
# after replacing the model file: sudo -u www-data php artisan image-search:rebuild --fresh
```

If the AI service is stopped, the storefront's camera button shows "unavailable" and
everything else works; new photos are fingerprinted when it is back (job retries + nightly rebuild).

## 5. Storefront + seller dashboard, admin panel

```bash
cd /var/www && git clone <frontend-repo-url> choosetounsi-frontend && cd choosetounsi-frontend
cat > .env.production <<'EOF'
NEXT_PUBLIC_API_URL=https://api.choosetounsi.tn/api
NEXT_PUBLIC_SITE_URL=https://choosetounsi.tn
NEXT_PUBLIC_PUSHER_KEY=
NEXT_PUBLIC_PUSHER_CLUSTER=
EOF
npm ci && npm run build

cd /var/www && git clone <admin-repo-url> admin-panel && cd admin-panel
echo 'NEXT_PUBLIC_API_URL=https://api.choosetounsi.tn/api' > .env.production
npm ci && npm run build

sudo chown -R www-data:www-data /var/www/choosetounsi-frontend /var/www/admin-panel
sudo cp /var/www/choosetounsi-backend/deploy/choosetounsi-frontend.service /var/www/choosetounsi-backend/deploy/choosetounsi-admin.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now choosetounsi-frontend choosetounsi-admin
```

`NEXT_PUBLIC_*` values are baked in at build time: rebuild after changing them.

## 6. nginx

`/etc/nginx/sites-available/choosetounsi` (then `sudo ln -s` it into `sites-enabled`, remove `default`):

```nginx
server {
    server_name api.choosetounsi.tn;
    root /var/www/choosetounsi-backend/public;
    index index.php;
    client_max_body_size 20M;                     # product photo uploads

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}

server {
    server_name choosetounsi.tn www.choosetounsi.tn;
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

server {
    server_name admin.choosetounsi.tn;
    location / {
        proxy_pass http://127.0.0.1:3001;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

There is deliberately no block for port 8001: the AI service is never public.

## 7. HTTPS (Let's Encrypt)

Point the three DNS A records at the server first, then:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d choosetounsi.tn -d www.choosetounsi.tn -d api.choosetounsi.tn -d admin.choosetounsi.tn
sudo certbot renew --dry-run          # renewal is automatic (systemd timer)
```

## 8. Uptime monitoring (UptimeRobot, free)

Create a free account at uptimerobot.com and add:

| Monitor | Type | URL | Interval |
|---|---|---|---|
| Storefront | HTTP(s) | `https://choosetounsi.tn` | 5 min |
| API | HTTP(s) | `https://api.choosetounsi.tn/api/categories` | 5 min |
| Photo search | Keyword, keyword `"available":true` | `https://api.choosetounsi.tn/api/search/image/status` | 5 min |
| Admin | HTTP(s) | `https://admin.choosetounsi.tn` | 15 min |

The AI service's own `/health` is on 127.0.0.1 only. The photo search monitor above checks it
through Laravel (`available` is false when the AI service doesn't answer or nothing is
indexed), so nothing has to be opened to the internet. On the server:
`curl -s http://127.0.0.1:8001/health`.

## 9. Updating

```bash
# backend
cd /var/www/choosetounsi-backend && git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.2-fpm && sudo systemctl restart choosetounsi-queue

# storefront / admin
cd /var/www/choosetounsi-frontend && git pull && npm ci && npm run build && sudo systemctl restart choosetounsi-frontend
cd /var/www/admin-panel && git pull && npm ci && npm run build && sudo systemctl restart choosetounsi-admin

# AI service
cd /opt/choosetounsi-ai-service && sudo -u www-data git pull && sudo -u www-data .venv/bin/pip install -r requirements.txt
sudo systemctl restart choosetounsi-ai
```

## 10. Quick health check

```bash
systemctl --no-pager status nginx mysql php8.2-fpm choosetounsi-queue choosetounsi-frontend choosetounsi-admin choosetounsi-ai | grep -E '●|Active'
curl -s http://127.0.0.1:8001/health
curl -s https://api.choosetounsi.tn/api/search/image/status
php /var/www/choosetounsi-backend/artisan queue:failed | head
```
