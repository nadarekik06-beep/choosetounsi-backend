# Queue worker and scheduler

The app uses the **database queue** (`QUEUE_CONNECTION=database`, tables `jobs` / `failed_jobs`) and Laravel's scheduler. Two processes must run next to the web server:

| Process | What depends on it |
|---|---|
| `php artisan queue:work` | e-mails (marketing digests, campaign notifications, seller-application mails), AI ad copy, photo search fingerprints (see SEARCH.md) |
| `php artisan schedule:run` (every minute) | `ads:*` (complete-ended, reset-daily, stock-watch, optimize, reconcile-stats, grant-monthly-credit, send-digest, send-interest-emails), `search:build-index`, `image-search:rebuild`, subscriptions, promotions, recommendations |

Check what is scheduled: `php artisan schedule:list`. Ad jobs run in Africa/Tunis time (`config/ads.php` → `timezone`).

## Windows (XAMPP, development or a small server)

**Scheduler** — Task Scheduler → *Create Task*:
- General: *Run whether user is logged on or not*.
- Triggers: *Daily*, repeat every **1 minute** for a duration of **Indefinitely**.
- Actions: Program `C:\xampp\php\php.exe`, arguments `artisan schedule:run`, start in `C:\xampp\htdocs\choosetounsi-backend`.

Or from an elevated PowerShell:

```powershell
schtasks /Create /TN "ChooseTounsi scheduler" /SC MINUTE /MO 1 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\choosetounsi-backend\artisan schedule:run" /RU SYSTEM
```

**Queue worker** — keep one worker running and restart it if it stops:
- Task Scheduler → *Create Task* → Trigger *At startup*; Action `C:\xampp\php\php.exe` with arguments `artisan queue:work --tries=3 --max-time=3600` (start in the backend folder); Settings: *If the task fails, restart every 1 minute*, and uncheck *Stop the task if it runs longer than…*. `--max-time` makes the worker exit hourly so Task Scheduler restarts it with fresh code.
- For development, simply keep `php artisan queue:work` open in a terminal.

After deploying new code, run `php artisan queue:restart` so workers reload.

## Production (Linux)

**Cron** (as the web user, e.g. `crontab -u www-data -e`):

```
* * * * * cd /var/www/choosetounsi-backend && php artisan schedule:run >> /dev/null 2>&1
```

**Supervisor** — `/etc/supervisor/conf.d/choosetounsi-worker.conf`:

```ini
[program:choosetounsi-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/choosetounsi-backend/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/choosetounsi-backend/storage/logs/worker.log
stopwaitsecs=3600
```

Then `supervisorctl reread && supervisorctl update && supervisorctl start choosetounsi-worker:*`. Run `php artisan queue:restart` on every deploy.

## Failed jobs

- List: `php artisan queue:failed` · retry: `php artisan queue:retry all` · clear: `php artisan queue:flush`.
- Marketing e-mails are sent through Brevo SMTP (`MAIL_*` in `.env`); a bad SMTP password shows up here as failed jobs.
