# Local Secondary Server With Live Sync

This app can run as a warm standby on a local server. The recommended setup is:

1. Primary production web server remains the write target.
2. Local server runs Apache/Nginx, PHP 8.1+, and MySQL/MariaDB.
3. MySQL replication keeps the local database almost live.
4. App files are deployed from Git or restored from the existing R2 backup system.
5. `health.php` is used by uptime monitoring or DNS/load-balancer failover.

## Important Decision

Use the local server as read-only standby during normal operation. Only promote it to writable mode when the production server is down and users need to continue work locally. Running both servers as writable without conflict handling can create duplicate challan numbers, PO numbers, stock changes, and payment records.

## Primary Server Setup

Create a replication user on the production MySQL server:

```sql
CREATE USER 'dms_replica'@'LOCAL_SERVER_IP' IDENTIFIED BY 'CHANGE_THIS_STRONG_PASSWORD';
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'dms_replica'@'LOCAL_SERVER_IP';
FLUSH PRIVILEGES;
```

Enable binary logs in MySQL/MariaDB config:

```ini
[mysqld]
server-id=1
log_bin=mysql-bin
binlog_format=ROW
expire_logs_days=7
```

Restart MySQL after changing config.

Create a consistent initial dump:

```bash
mysqldump --single-transaction --routines --triggers --events --master-data=2 tsgimpex_despatch_mgmt > dms_initial.sql
```

Copy `dms_initial.sql` to the local server and import it.

## Local Server Setup

Install the same app files and configure the local database credentials in `config.php`.

Import the initial dump:

```bash
mysql tsgimpex_despatch_mgmt < dms_initial.sql
```

Configure MySQL/MariaDB on the local server:

```ini
[mysqld]
server-id=2
relay_log=relay-bin
read_only=ON
super_read_only=ON
```

Start replication using the binlog file and position from the `CHANGE MASTER TO` line inside `dms_initial.sql`:

```sql
CHANGE MASTER TO
  MASTER_HOST='PRIMARY_SERVER_IP',
  MASTER_USER='dms_replica',
  MASTER_PASSWORD='CHANGE_THIS_STRONG_PASSWORD',
  MASTER_LOG_FILE='mysql-bin.000001',
  MASTER_LOG_POS=12345;

START SLAVE;
SHOW SLAVE STATUS\G
```

For MySQL 8, `SOURCE_*` syntax may be used instead of `MASTER_*`.

## Health Check

Open this endpoint from monitoring:

```text
https://your-domain.example/health.php
```

Healthy response returns HTTP `200` with JSON. Database or filesystem failure returns HTTP `503`.

Example monitor rule:

- Check every 1 minute.
- Mark down after 3 failed checks.
- Alert admin immediately.
- Switch DNS or reverse proxy to local standby only after confirming outage.

## Near-Live Fallback From R2 Backups

The existing backup system stores DB and app backups in R2. This is not true live sync, but it is useful if MySQL replication is unavailable.

On the local server, test the latest backup selection:

```bash
php backup/restore_latest.php --dry-run
```

Restore only the database:

```bash
php backup/restore_latest.php --db-only
```

Restore app files and database:

```bash
php backup/restore_latest.php
```

Schedule this only if the local server is a standby and nobody is writing to it. Restoring overwrites local data.

## Promotion During Outage

When production is down:

1. Stop replication on the local server.
2. Disable read-only mode.
3. Point users to the local server.
4. Keep a note of the promotion time.

On the Windows/XAMPP local server, use:

```bash
cd "/d/OneDrive/Documents/despatch_mgmt - Copy"
./backup/promote_local_master.bat
```

```sql
STOP SLAVE;
SET GLOBAL read_only=OFF;
SET GLOBAL super_read_only=OFF;
```

After production is repaired, do not simply point users back. First reconcile data written locally during the outage, then rebuild replication in one direction.

For the exact operator checklist, see `docs/local-temp-master-recovery.md`.

## Files And Uploads

Most uploaded documents appear to be stored through Cloudflare R2 helper functions. If all active uploads use R2, no local file sync is required for those objects. If any module writes directly to `uploads/`, sync that directory separately with `rsync`, Syncthing, or a scheduled zip restore.

## Minimum Practical Setup

If you need a fast first version:

1. Install XAMPP/WAMP or Linux Apache/PHP/MySQL on the local server.
2. Copy this app to the local web root.
3. Import the latest DB backup.
4. Confirm `http://LOCAL_SERVER/health.php` returns healthy JSON.
5. Add MySQL replication when the production host allows remote MySQL access over VPN or a secure tunnel.
