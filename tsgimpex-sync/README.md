# Simple Live To Local Pull

Goal: copy live database data into local XAMPP/phpMyAdmin database.

Database name on both sides:

```text
tsgimpex_despatch_mgmt
```

## Live Server

Upload this folder to live:

```text
despatch_mgmt/tsgimpex-sync/
```

Edit `sync_config.php` on live:

```php
define('THIS_SITE', 'live');
define('SYNC_SECRET', 'same-secret-on-live-and-local');
define('DB_HOST', 'localhost');
define('DB_USER', 'live_db_user');
define('DB_PASS', 'live_db_password');
```

The live file `sync_api.php` is read-only. It only does `SHOW TABLES`, `SHOW CREATE TABLE`, and `SELECT`.

## Local XAMPP

Keep this folder on local machine.

Edit `sync_config.php` on local:

```php
define('THIS_SITE', 'local');
define('SYNC_SECRET', 'same-secret-on-live-and-local');
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('LIVE_SYNC_API_URL', 'https://tsgimpex.com/despatch_mgmt/tsgimpex-sync/sync_api.php');
```

Run:

```bat
pull_from_live.bat
```

Or:

```bat
D:\xampp\php\php.exe "D:\OneDrive\Documents\despatch_mgmt - Copy\tsgimpex-sync\pull_from_live.php"
```

## Important

This replaces local tables with live tables. It does not send local data back to live.
