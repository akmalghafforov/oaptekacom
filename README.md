# OAPTEKA

Server-rendered Laravel MVP for pharmacy and wholesaler ordering.

## Docker development

1. Create the shared proxy network once (it is already used by `mrstairs-backend`): `docker network create traefik-net`.
2. Copy `.env.docker.example` to `.env`, generate an `APP_KEY` with `docker compose run --rm app php artisan key:generate`, and set a strong database password.
3. Start the stack: `docker compose up --build`.

Nginx registers itself on the external `traefik-net` as `oapteka`; Traefik routes `${TRAEFIK_HOST:-oapteka.test}` to port 80. PostgreSQL and the queue worker remain on the private `internal` network.

The application stores uploaded receipts under Laravel's local/private disk. The Compose `app` service installs dependencies and runs migrations before PHP-FPM starts.

## Production deployment

Use a separate OApteka checkout on the server, never another application's directory.
Requirements: Docker with Compose v2, Make, Python 3 (for the network check), and access
to the existing Traefik setup. Point the chosen domain's DNS at the server. Inspect
`docker network ls` and `docker network inspect traefik-net` (or your existing proxy
network), and verify its `web`/`websecure` entrypoints, certificate resolver and file
middlewares. Confirm the application and database subnets do not overlap **any**
Docker network, host route, or VPN; choose an unused address inside the application
subnet for Nginx. Do not change shared Traefik or other applications.

Copy `.env.production.example` to `.env.production` and run `chmod 600 .env.production`.
Fill every required value, especially the persistent `APP_KEY`, a strong `DB_PASSWORD`,
Oson SMS credentials, the absolute `APP_SOURCE_PATH`, and an immutable `RELEASE_TAG`
(for example, the commit used to build the runtime). This tag identifies images, not
the currently deployed Git revision. Generate the key once with
`php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'` on a trusted PHP
installation. Keep it across updates and restores. `APP_URL` must use HTTPS and match
`TRAEFIK_HOST`. Verify the Traefik and subnet values before proceeding. Never commit
secrets; keep password files outside the checkout, with mode 600.

From that checkout, run these steps in order:

```sh
make check
make network
make build
docker pull node:24-alpine
docker pull postgres:16-alpine
make apply
```

The same Make commands work in both environments. Make prefers `.env.production`
when it exists, otherwise `.env`, and reads `APP_ENV` without executing the file.
`APP_ENV=production` selects `compose.production.yaml` and the `oapteka-op` project;
`local`, `development`, or `testing` selects `docker-compose.yml`. Unknown environments
and conflicting `ENVIRONMENT` overrides fail. Use `make help` to see the selected
file and environment, or `ENV_FILE=/absolute/path` on each invocation to choose a
specific file (for example, `make ENV_FILE=.env up` for local development).

`make check` validates without printing secrets. Production routing stays pinned
regardless of `COMPOSE`, `COMPOSE_COMMAND`, `COMPOSE_FILE`, or `COMPOSE_PROJECT_NAME`
overrides. Use `DOCKER_CONTEXT` to select a Docker context if needed. `make network`
only inspects the existing proxy network in production. `make up` stops production
application services, initializes writable volumes, ensures PostgreSQL is healthy,
installs locked production dependencies, refreshes caches and recreates application
services. Use `make apply` for updates that also need assets and migrations. `make restart` stops services and runs
that startup sequence. Local dependency mutation, tests, source watching and database
resets are unavailable in the production image; their commands fail clearly instead
of falling back to the local stack. `make down` stops and removes every service in
the detected project, including PostgreSQL, all profiles, and orphaned service
containers, while keeping persistent volumes and the external Traefik network.
Use `make stop` when PostgreSQL should keep running. Never use `down --volumes` in
production.

PHP services share a production dependency volume mounted read-only at runtime.
The ownership initializer and Composer installer have write access. Installation runs
as `www-data`, skips scripts and development packages, optimizes autoloading, and
checks platform requirements; package discovery runs during cache initialization. Application directories are mounted
read-only from `APP_SOURCE_PATH`; Nginx mounts only `public/`. The temporary Node 24
service builds `public/build` with its own dependency volume. Remove `public/hot` and
any development `public/storage` symlink before deployment. Ensure checkout directories
and public assets are readable/traversable by container users. There are no host ports;
only Nginx joins the shared proxy network. Database data and private uploads retain
`oapteka-op_postgres-data` and `oapteka-op_private-uploads`. Runtime storage and
`bootstrap/cache` have separate persistent volumes; private uploads stay at their
existing path and are never served directly.

Volume initialization assigns writable paths to `www-data`. Application initialization
refreshes package discovery, configuration, route and view caches once as `www-data`
before starting PHP-FPM, the worker and one scheduler. This follows
[Laravel's deployment guidance](https://laravel.com/framework/docs/13.x/deployment).
Production one-off Artisan commands use temporary cache paths so they read the current
environment, even when the serving release has cached configuration. Refresh serving
caches with `make up` after stopping services; do not use one-off
`config:cache` to refresh the serving application.

### Initial administrator and account modes

Create a password file outside the checkout using a secure editor; do not place the
password in command arguments or shell history. Set mode 600, then mount it read-only
for the existing bootstrap command (replace identity values and the absolute file path):

```sh
docker compose --env-file .env.production -f compose.production.yaml run --rm --no-deps \
  --user root --volume /secure/admin.password:/run/admin.password:ro \
  release release oapteka:bootstrap-admin --name='Administrator' \
  --email=admin@example.com --phone=992900000001 \
  --password-file=/run/admin.password --no-interaction
```

The command uses root only to read the protected host file; its ephemeral Laravel
cache paths are independent of the serving caches. Delete the password file after
successful bootstrap and configure administrator 2FA at first login. Do **not** run
the development seeder. Leave `ACCOUNT_MODES_ENABLED=false` until migrations and the
account-role backfill have succeeded:

```sh
make artisan COMMAND='oapteka:backfill-account-roles --dry-run'
make artisan COMMAND='oapteka:backfill-account-roles'
```

Review and resolve reported errors before enabling account modes on every application
node, then stop services and refresh caches/recreate them. Keep the existing feature
flags unchanged until explicitly enabling them; OTP bypass values remain empty.

### Updates and recovery

Back up PostgreSQL and private uploads, and verify that backups can be restored. Store
backups outside the checkout with restricted access. Record the current revision,
image tag and protected environment file. Stop OApteka's web and worker services **before**
changing mounted source, including checkout, asset builds, or cache refresh:

```sh
make stop
git pull --ff-only
make apply
```

Keep `RELEASE_TAG` unchanged when reusing images. `make apply` validates configuration
and required images before stopping application services, initializes volumes, ensures
PostgreSQL is healthy, installs locked Composer dependencies, builds frontend assets,
runs pending migrations, refreshes Laravel caches and recreates PHP-FPM, workers,
scheduler and Nginx. It never builds images or stops PostgreSQL. Missing application
images require `make build`; missing external images require `docker pull`.
Any failed preparation step stops the sequence and leaves application services stopped.
Fix the cause and rerun `make apply`; migrations are not automatically rolled back.
Local `make apply` is rejected. Git operations remain manual.

**One-time upgrade:** this dependency-volume setup changes the image-owned entrypoint.
After stopping services and pulling this change, choose a new runtime `RELEASE_TAG`,
run `make build`, pull the Node and PostgreSQL images if missing, then run `make apply`.

Rebuild with a new immutable runtime tag when the Dockerfile, PHP extensions/settings,
image-owned entrypoint, or other image contents change. Composer lockfile and frontend
changes normally use `make apply` without rebuilding; unavailable PHP extensions
require a runtime rebuild. Frontend compilation still runs in the Node container.
Do not overwrite old immutable image tags; retain compatible images for recovery.
A worker has 150 seconds to stop gracefully;
its 120-second timeout is below the fixed 150-second database queue reservation.
It processes the configured `PRICE_LIST_QUEUE` plus `default`. PostgreSQL continues
running during the application stop. Changing PostgreSQL environment credentials does
not change passwords in an existing database; plan database credential rotation separately.

Verify after each release:

```sh
make ps
make logs SERVICE=app
make logs SERVICE=queue
make logs SERVICE=scheduler
make artisan COMMAND='migrate:status'
make artisan COMMAND='queue:failed'
make artisan COMMAND='schedule:list'
```

Check the configured domain's `/up` endpoint over HTTPS, secure login/session behavior,
static assets, a real price-list import and its queue completion, and subscription
expiry logs. PHP permits 20 MB uploads, with a 25 MB request limit and 256 MB memory;
Nginx permits 25 MB request bodies. `schedule:list` should show subscription expiry
at midnight in `Asia/Dushanbe`. Keep exactly one scheduler container; no host cron
is needed. Compose startup health checks verify PostgreSQL, while `/up` checks Laravel
booting; also check actual database-backed workflows.

To restore a prior release, stop OApteka services, check out the recorded revision,
restore its protected environment settings and compatible image tag, rebuild that
revision's assets, initialize storage, and refresh caches/recreate services. If the
schema is incompatible, restore the verified database backup and matching uploads
before starting the old application. Checking out old source does not roll back data;
do not run blind migration rollbacks. Keep the persistent `APP_KEY` and existing
volume names. Network/subnet availability, image builds, Nginx validation, actual
volume permissions and end-to-end worker/scheduler behavior must be checked on the
server before routing production traffic.

## Admin Gmail inbox

Each admin can connect one private Gmail account from the admin Gmail page. This integration
requests only `https://www.googleapis.com/auth/gmail.readonly`. It reads INBOX pages and
message bodies without changing unread status. Only attachment names and sizes are shown;
downloading, importing price lists, background synchronization, and persistent message caches
are not included. Reading is bounded to 30 MIME nesting levels, eight attachment-backed
body fetches, 8 MiB per encoded text part, and 1 MiB of sanitized HTML; unsupported or malformed parts fall back
to plain text or a safe empty state.

1. In Google Cloud Console, enable the Gmail API for your project.
2. Configure the Google Auth Platform consent screen, audience, app details and the Gmail
   readonly scope. For an external app in Testing mode, add the intended Google accounts
   as test users.
3. Create an OAuth client of type **Web application**. Set `GOOGLE_CLIENT_ID` and
   `GOOGLE_CLIENT_SECRET` in your deployment environment. Set `GOOGLE_REDIRECT_URI` to
   your exact absolute HTTPS application URI ending in `/admin/gmail/callback` and register
   that identical URI as an authorized redirect URI in Google. Local development may use
   Google's permitted localhost HTTP redirect.
4. Install the locked dependencies (`composer install --no-interaction`), run
   `php artisan migrate --no-interaction`, then refresh configuration with
   `php artisan config:clear --no-interaction` and `php artisan config:cache --no-interaction`.
   Keep the same persistent `APP_KEY` across deployments and restores: it encrypts stored
   tokens. Rotate Google credentials through your secret management process.
5. Use server-side sessions (`SESSION_DRIVER=database` or Redis) and a shared atomic lock
   store (`SESSION_BLOCK_STORE=database` or Redis) on every application instance. Do not use
   cookie sessions or a process-local array cache. Setting `GOOGLE_CLIENT_ID` enables session
   blocking for all web requests so overlapping requests cannot restore consumed OAuth state.
   Session and credential locks last 300 seconds;
   keep the HTTP execution timeout below this value.

Docker development equivalents use `docker compose exec app` before the Artisan commands.
Production uses the existing `make migrate` workflow (with `--force`), then the application
container through `docker compose --env-file .env.production -f compose.production.yaml exec app`
for configuration commands; rebuild the release image/assets using the existing Make workflow.
Do not regenerate `APP_KEY` during an upgrade.

For external applications in Testing mode, refresh tokens for this Gmail scope expire after
seven days. Gmail readonly is a restricted scope. Production use may require OAuth restricted-scope
verification and, because this server handles restricted-scope data, a security assessment.
Google documents exceptions for eligible personal or internal use; admin-only access does **not**
itself qualify an application for the internal-use exception. Review
[Google OAuth guidance](https://developers.google.com/identity/protocols/oauth2) and
[restricted-scope verification requirements](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification)
for your audience and deployment.

Bundled Nginx access logs omit query strings and referrers, including callback codes and state.
Apply equivalent protection to Traefik, upstream proxies, WAFs, APM/error trackers and other
request logging: never capture callback query strings, token responses, or Google exception
bodies. Gmail responses use `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

Manual acceptance checklist with an authorized Google test account:

- Connect and consent; confirm the displayed account belongs to the signed-in admin.
- Browse First/Next pages, open plain and HTML messages, and inspect attachment names/sizes.
- Confirm unread status is unchanged and remote images/links cannot load in the message frame.
- Refresh after new mail arrives; verify another admin and non-admins cannot read this mailbox.
- Disconnect; confirm local connection removal even if Google revocation is unavailable.
- Reconnect the same account and a different account; test denied consent and revoked permissions.

Automated tests use mock Google HTTP responses; they do not demonstrate live Gmail connectivity.
