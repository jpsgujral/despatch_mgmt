# Local Temporary Master Recovery Plan

Use this plan when the web server is down and the local XAMPP server must temporarily run the business.

## Normal State

```text
Web server = active master
Local server = standby / read-only
Users use web domain
```

Daily or scheduled sync:

```bash
cd "/d/OneDrive/Documents/despatch_mgmt - Copy"
./backup/restore_latest.bat --db-only
```

Do not run this after users start entering records on local, because it overwrites local data.

## Web Server Outage

1. Confirm the web server is really down.
2. Stop users from trying both web and local at the same time.
3. Promote local MySQL to temporary master:

```bash
cd "/d/OneDrive/Documents/despatch_mgmt - Copy"
./backup/promote_local_master.bat
```

4. Send users to:

```text
http://192.168.1.10/despatch_mgmt/
```

5. Record outage start time.

## While Local Is Master

Only local should be used for new challans, despatches, invoices, payments, receipts, and stock changes.

Do not restore backup from web to local during this period.

## Web Server Comes Back

Do not immediately send users back to web. First rebuild web from local:

1. Stop users for a few minutes.
2. On local, create and upload backup to R2:

```bash
cd "/d/OneDrive/Documents/despatch_mgmt - Copy"
./backup/push_local_backup.bat
```

3. On the web server Backup & Restore page, restore the newest backup from local.
4. Check web server:

```text
https://your-web-domain/health.php
```

5. Test login, latest challan/despatch, and latest payment from the outage period.
6. Move users back to web.
7. Return local to standby/read-only:

```bash
cd "/d/OneDrive/Documents/despatch_mgmt - Copy"
./backup/return_local_standby.bat
```

8. Rebuild local standby from web again:

```bash
./backup/restore_latest.bat --db-only
```

## Cloudflare Role

Cloudflare should handle traffic routing and secure local access, not data conflict resolution.

Recommended Cloudflare setup:

- Primary origin: web server.
- Secondary origin: local XAMPP through Cloudflare Tunnel.
- Health monitor: `/health.php`.
- Failover: automatic only if you are comfortable with local promotion steps, otherwise manual switch is safer.

## Do Not Use Two Masters

Avoid this state:

```text
Web server = writable
Local server = writable
Both accept users
```

That can create duplicate document numbers and conflicting stock/payment records.
