# OAPTEKA

Server-rendered Laravel MVP for pharmacy and wholesaler ordering.

## Docker development

1. Create the shared proxy network once (it is already used by `mrstairs-backend`): `docker network create traefik-net`.
2. Copy `.env.docker.example` to `.env`, generate an `APP_KEY` with `docker compose run --rm app php artisan key:generate`, and set a strong database password.
3. Start the stack: `docker compose up --build`.

Nginx registers itself on the external `traefik-net` as `oapteka`; Traefik routes `${TRAEFIK_HOST:-oapteka.test}` to port 80. PostgreSQL and the queue worker remain on the private `internal` network.

The application stores uploaded receipts under Laravel's local/private disk. The Compose `app` service installs dependencies and runs migrations before PHP-FPM starts.
