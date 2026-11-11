# Production setup: supplier synchronization

This guide installs the supplier mapping and automatic import feature on an existing OAPTEKA production deployment using `compose.production.yaml`. For a new server, complete the base infrastructure and administrator setup in [README.md](README.md) first.

Run commands from the production checkout. The examples use `.env.production`, the existing `oapteka-op` Compose project, and the existing persistent volumes. Deploy a reviewed release containing all new application files and the migration, not just changes to existing files.

## 1. Prepare the release

- Record the current Git revision and `RELEASE_TAG`. Back up PostgreSQL, private uploads, and the protected environment configuration using the existing backup procedure.
- Preserve the production `APP_KEY`: it encrypts Gmail credentials and external service sessions. Do not generate a new key for this update.
- Confirm the server can make outbound HTTPS requests to `icorp.tech` and `pricelist-tj.com`. Existing Gmail imports also require their current Google API connectivity.
- Keep the existing shared database cache and private storage. All application and worker instances must share the same cache locks, database, and private session/import files. The supplied Compose configuration provides these volumes for a single deployment host; separate hosts need shared storage as well.
- This feature adds no dependencies. Existing compatible production images can be reused. If the runtime image is incompatible with `composer.lock`, follow the image rebuild procedure in the README before starting the release.

New supplier configurations have automatic fetching disabled. Discovery and confirmed mappings alone do not enable automatic external imports. Existing mailbox behavior continues until the supplier's synchronization settings are explicitly saved.

## 2. Stop services and configure credentials

Check the current production environment and stop the web application, worker, and scheduler before changing mounted source or environment configuration. PostgreSQL remains running.

```sh
make ENV_FILE=.env.production check
make ENV_FILE=.env.production stop
```

Take a consistent backup while application writers are stopped, then deploy the reviewed source revision using your normal release procedure.

Add the following variables to the existing protected `.env.production`. Replace the example values with the service account credentials; do not copy these placeholders literally.

```dotenv
ICORP_PHONE="992XXXXXXXXX"
ICORP_PASSWORD="replace-with-service-account-password"
ICORP_FIRM_ID="replace-with-numeric-workspace-id"

PRICELIST_TJ_PHONE="992XXXXXXXXX"
PRICELIST_TJ_PASSWORD="replace-with-service-account-password"
```

`ICORP_FIRM_ID` is the numeric iCorp workspace/company ID available to the account. It is separate from the external partner ID and catalog ID discovered for each supplier.

Keep these existing production settings:

```dotenv
APP_ENV=production
APP_DEBUG=false
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=150
PRICE_LIST_QUEUE=price-list-imports
PRICE_LIST_DISK=local
GMAIL_IMPORTS_QUEUE=gmail-imports
```

Keep the existing `APP_KEY`, database credentials, application URL, source path, and compatible runtime image tag. Restrict the environment file:

```sh
chmod 600 .env.production
```

There is no global external-import enable switch in `.env`; activation is configured separately for each supplier in the administrator panel. The daily directory task attempts both external services, so an unconfigured service can report an authentication failure even when its supplier sources are disabled.

If mailbox imports already work, retain their current settings and connection. If enabling mailbox intake for the first time, follow the **Admin Gmail inbox** section in the README, connect the designated administrator mailbox, and set:

```dotenv
GMAIL_IMPORTS_ENABLED=true
GMAIL_IMPORTS_CONNECTION_ID="replace-with-existing-gmail-connection-id"
GMAIL_IMPORTS_MAILBOX="replace-with-connected-mailbox-address"
```

## 3. Install the schema and start the release

The current Makefile does **not** build frontend assets or run migrations inside `make apply`. Run these steps explicitly, in order, while application services remain stopped:

1. Build the release's frontend assets on the build machine with the locked dependencies:

   ```sh
   npm ci
   npm run build
   ```

   Transfer the resulting `public/build` directory with the release. Ensure production has no development `public/hot` file. Alternatively, on the build machine using the production Compose configuration, use `make ENV_FILE=.env.production LOCAL_BUILD=1 assets`; the Node image must already be available. Do not rebuild assets while the serving application is reading the same directory.

2. On the production server, prepare storage and dependencies, then run pending migrations:

   ```sh
   make ENV_FILE=.env.production check
   make ENV_FILE=.env.production images
   make ENV_FILE=.env.production init
   make ENV_FILE=.env.production db-up
   make ENV_FILE=.env.production dependencies
   make ENV_FILE=.env.production migrate
   make ENV_FILE=.env.production artisan COMMAND='migrate:status'
   ```

   Confirm `2026_11_11_122607_create_supplier_synchronization_tables` has run. This additive migration creates the partner directory, supplier settings, synchronization runs/attempts, and import source metadata. Do not run `migrate:fresh` or production seeders.

3. Refresh serving caches and recreate the application services:

   ```sh
   make ENV_FILE=.env.production start
   make ENV_FILE=.env.production ps
   make ENV_FILE=.env.production artisan COMMAND='route:list --name=admin.supplier-sync'
   make ENV_FILE=.env.production artisan COMMAND='schedule:list'
   ```

   `make start` refreshes configuration, route, and view caches, then recreates PHP-FPM, the queue worker, the scheduler, and Nginx. Use this workflow after credential changes as well; a one-off `config:cache` command does not refresh the serving containers' configuration.

If a preparation step fails, keep application services stopped, fix the cause, and repeat the failed preparation steps before `make start`.

## 4. Verify the worker and scheduler

The existing `queue` container consumes `gmail-imports`, `price-list-imports`, and `default` using the configured queue names. External discovery, supplier synchronization, and import processing use `PRICE_LIST_QUEUE`.

The worker timeout is 120 seconds and the database queue reservation is 150 seconds. Keep the reservation longer than the worker timeout. The supplied containers already use these values.

Keep exactly one `scheduler` container. It runs `schedule:work`; no additional host cron is needed for this Compose deployment. Confirm these tasks appear in `schedule:list`:

| Task | Frequency |
| --- | --- |
| `suppliers:sync` | Every minute; dispatches due suppliers and recovers unfinished work |
| `suppliers:sync --directories` | Daily; refreshes both external directories |
| `gmail-imports:sync` | Every five minutes; retains existing mailbox discovery/recovery |

Each supplier defaults to a 15-minute polling interval and a 24-hour freshness window. The one-minute dispatcher respects the supplier's next-check time. Application dates use `Asia/Dushanbe`.

Inspect logs and failed jobs:

```sh
make ENV_FILE=.env.production logs SERVICE=queue
make ENV_FILE=.env.production logs SERVICE=scheduler
make ENV_FILE=.env.production artisan COMMAND='queue:failed'
```

Each log command follows its service; exit it with Ctrl+C before running the next command. A successful Artisan dispatch only means work was queued. Confirm completion in the panel and worker logs.

## 5. Connect services and confirm supplier mappings

1. Sign in as an administrator and open **Операционный центр → Синхронизация поставщиков**. The panel's relative path is `/admin/supplier-sync`.
2. Select **Повторить подключение** for each service. Normal iCorp connection never forces another device out.
3. If iCorp reports that the account is occupied, coordinate with the account owner. Only then open **Принудительный вход iCorp**, explicitly confirm the other device's logout, and select **Войти принудительно**. This administrator action is audited.
4. Select **Обновить справочник** for each service, wait for its queued job to finish, and reload the panel. Failed discovery preserves the existing directory and confirmed mappings.
5. Use supplier search and the unmapped/ambiguous filters. Check every suggested name and phone match. Suggestions never merge suppliers automatically.
6. Confirm the correct existing internal supplier, or create an active supplier using the mapping form. One internal supplier can have one mapping per external service. Resolve conflicts rather than creating duplicate suppliers.

For initial discovery from the command line, the following queues both directories:

```sh
make ENV_FILE=.env.production artisan COMMAND='suppliers:sync --directories'
```

Use the panel to confirm mappings and perform audited connection/takeover actions. The older `external-prices:fetch` command is a private extraction/staging tool, not the new automatic activation workflow.

## 6. Configure and enable one supplier at a time

Saving synchronization settings opts the supplier into the shared source-selection policy, including mailbox activation. With automatic loading disabled, new configured mailbox imports can remain in preview. For a supplier already using mailbox imports, prepare its source profiles first and save the final enabled settings during the controlled cutover.

1. Open the mapped supplier's synchronization page.
2. Configure an **active** profile for every external source you intend to enable. Confirm product matching and expiration-date formats. External workbooks use fixed columns; do not copy mailbox column mappings into them.
3. Start with strict validation thresholds: at least one valid row, zero error rows, and zero percent errors. Raise the minimum valid-row count to a realistic supplier-specific floor after inspecting a representative list.
4. If using email, configure the existing **Профиль почты и маршрутизация вложений**: active mailbox profile, sender addresses, filename routing, and inventory-date extraction.
5. Disable sources without usable credentials, a confirmed mapping, or an active profile. Set distinct priority positions. The default order is **Почта → iCorp → PriceList-TJ**.
6. Use a 15-minute fetch interval and 24-hour freshness window initially. For a new supplier, save with **Автоматическая загрузка: Выключена** while testing. For an existing mailbox supplier, coordinate the temporary preview behavior described above, or enable its prepared configuration at cutover.
7. Select **Загрузить сейчас**. Manual requests work while automatic fetching is disabled or paused, but retain all source, profile, priority, date, and validation checks. A valid fresh preferred-source list can block an external fallback; that is expected.
8. Inspect **История синхронизации**, the import rows, publication date/precision, displayed prices, and active catalog. Compare several items against the external service. External discounts are retained as metadata and are not applied a second time; existing pharmacy pricing rules still apply.
9. If a preview needs date review, obtain the actual publication date and record its evidence in **Подтвердить дату и проверить активацию**. Fetch time is not evidence of publication time. Confirmation reruns selection checks and does not guarantee activation. Reject an unsuitable preview with **Отклонить предпросмотр** so the run can continue.
10. Once the manual result is correct, set **Автоматическая загрузка: Включена**, **Без паузы**, and save. Every enabled source must have an active profile. Observe the next scheduled run before enabling another supplier.

For a representative supplier replacement, verify that an existing basket keeps its requested quantities and saved discounts, and that removed catalog items remain unavailable rather than disappearing from the basket.

## 7. Monitoring and recovery

Check supplier history, next-check time, and last successful activation. The successful synchronization timestamp changes only after catalog activation. Rechecking unchanged content does not renew its publication freshness.

| Result | Operator action |
| --- | --- |
| Authentication / permission error | Check credentials and workspace access, restart the serving release after environment changes, then retry connection in the panel. |
| Account occupied / takeover required | Coordinate with the iCorp account owner, then use the explicitly confirmed panel action. |
| Service busy / waiting for import or mailbox | Allow the current operation to finish; repeated requests coalesce. Check worker logs if progress stops. |
| Missing profile / mapping | Confirm the mapping and save an active source profile. |
| Missing date / insufficient precision / unknown active-catalog date | Review supplier evidence. Do not invent a timestamp or bypass the date policy. Reject a preview if it cannot be resolved. |
| Validation failure / empty or incomplete extraction | Inspect the preview or source service, correct the cause, and request another fetch. The previous active catalog remains available. |
| Fresh preferred source / unchanged content / stale candidate | Usually expected. Review source dates and priorities before changing settings. |
| Mapping, profile, or settings changed | Let the current run settle and request a new fetch using the current configuration. |

To dispatch due suppliers and recovery checks immediately:

```sh
make ENV_FILE=.env.production artisan COMMAND='suppliers:sync'
```

This is not a force-refresh of every supplier. It respects due times and eligibility; use **Загрузить сейчас** for an individual manual fetch. Abandoned supplier ownership and service-account locks can take up to two hours to expire. Do not clear shared cache locks while an extraction might still be running.

If a queue job exhausts retries, inspect `queue:failed` and logs, fix the cause, and retry only the relevant job using `make artisan COMMAND='queue:retry JOB_ID'`. Replace `JOB_ID` with the displayed identifier. Check the supplier history afterward.

To stop automatic activity for one configured supplier, set its pause state and save. Queued automatic runs recheck pause/configuration before fetching or activating; the active catalog remains available. Manual requests can still run while paused. For an emergency stop of all background processing, stop the `queue` and `scheduler` services using the existing production Compose configuration; this also pauses mailbox and other background work.

For code rollback, stop application services before changing mounted source, restore the recorded compatible release and runtime tag, and rebuild/transfer its assets before restarting. Retain the additive schema and history unless a separately reviewed restore requires the verified database backup. Do not blindly roll back migrations or delete volumes. Preserve the production `APP_KEY` and private uploads/session files.
