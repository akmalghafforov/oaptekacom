COMPOSE ?= docker compose
COMPOSE_PROJECT ?= oaptekacom
COMMAND ?=
ENV_FILE ?= $(if $(wildcard .env.production),.env.production,.env)

# Read only APP_ENV; never execute an environment file as shell code.
DETECTED_ENVIRONMENT := $(shell python3 -c 'import pathlib,re,sys; p=pathlib.Path(sys.argv[1]); match=re.search(r"(?m)^\s*(?:export\s+)?APP_ENV\s*=\s*([^\r\n]*)", p.read_text()) if p.is_file() else None; value=match.group(1).split(" #",1)[0].strip().strip(chr(34)+chr(39)) if match else ("production" if p.name == ".env.production" else "local"); print("local" if value in ("local", "development", "testing") else value)' "$(ENV_FILE)")
ENVIRONMENT ?= $(DETECTED_ENVIRONMENT)

ifneq ($(ENVIRONMENT),$(DETECTED_ENVIRONMENT))
$(error ENVIRONMENT conflicts with APP_ENV in $(ENV_FILE); select the appropriate ENV_FILE instead)
endif

ifeq ($(ENVIRONMENT),production)
PRODUCTION_ENV_FILE := $(abspath $(ENV_FILE))
export PRODUCTION_ENV_FILE
# Pin the file and project even if local Compose settings are inherited.
override COMPOSE_COMMAND := docker compose --project-name oapteka-op --env-file "$(PRODUCTION_ENV_FILE)" -f "$(CURDIR)/compose.production.yaml"
else ifeq ($(ENVIRONMENT),local)
override COMPOSE_COMMAND := $(COMPOSE) --project-name $(COMPOSE_PROJECT) --env-file "$(ENV_FILE)" -f "$(CURDIR)/docker-compose.yml"
else
$(error Unsupported APP_ENV in $(ENV_FILE); expected production, local, development, or testing)
endif

.DEFAULT_GOAL := help
.NOTPARALLEL: up apply restart cache-clear
.PHONY: help check images dependencies start network up apply down stop restart build assets init db-up watch ps logs shell artisan composer test migrate migrate-fresh cache-clear queue-restart db

help: ## Show available commands and selected environment.
	@printf 'Environment: %s (%s)\n' "$(ENVIRONMENT)" "$(ENV_FILE)"
	@awk 'BEGIN {FS = ":.*##"}; /^[a-zA-Z_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

check: ## Validate the selected Compose configuration without printing secrets.
	$(COMPOSE_COMMAND) config --quiet

network: check ## Inspect the production proxy network, or create it locally if missing.
ifeq ($(ENVIRONMENT),production)
	@docker network inspect "$$( $(COMPOSE_COMMAND) config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["networks"]["traefik-net"]["name"])' )" >/dev/null
else
	@docker network inspect traefik-net >/dev/null 2>&1 || docker network create traefik-net
endif

images: check ## Check required production images before stopping services.
ifeq ($(ENVIRONMENT),production)
	@set -eu; images="$$( $(COMPOSE_COMMAND) --profile release config --images )"; \
	for image in $$images; do \
		docker image inspect "$$image" >/dev/null 2>&1 || { \
			printf 'Required image %s is missing; run make build for application images, or docker pull %s for external images.\n' "$$image" "$$image" >&2; exit 1; }; \
	done
endif

dependencies: ## Install locked production dependencies into the shared volume.
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) run --rm --no-deps --pull never dependencies
endif

start: ## Refresh production caches and recreate application services.
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) up --no-build --pull never --force-recreate --no-deps --exit-code-from app-init app-init
	$(COMPOSE_COMMAND) up --no-build --pull never --detach --force-recreate --no-deps app queue scheduler nginx
endif

up: ## Start application services; production installs dependencies and refreshes caches.
ifeq ($(ENVIRONMENT),production)
up: images
	$(MAKE) stop
	$(MAKE) init
	$(MAKE) db-up
	$(MAKE) dependencies
	$(MAKE) start
else
up: network
	$(COMPOSE_COMMAND) up --build --detach
endif

apply: ## Apply production code, dependencies, assets, migrations and caches using existing images.
ifeq ($(ENVIRONMENT),production)
apply: images
	$(MAKE) stop
	$(MAKE) init
	$(MAKE) db-up
	$(MAKE) dependencies
	$(MAKE) assets
	$(MAKE) migrate
	$(MAKE) start
else
	@printf 'make apply is production-only; select a production ENV_FILE. Use make up locally.\n' >&2
	@exit 1
endif

down: ## Stop/remove every service, all profiles and project orphans (keeps volumes).
	$(COMPOSE_COMMAND) --profile "*" down --remove-orphans

stop: ## Stop web services and workers, leaving PostgreSQL running.
	$(COMPOSE_COMMAND) stop nginx app queue scheduler

restart: ## Stop and restart application services with fresh production caches.
	$(MAKE) stop
	$(MAKE) up

build: check ## Build service images.
	$(COMPOSE_COMMAND) build

assets: check ## Install frontend dependencies and build assets in a temporary container.
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) run --rm --no-deps --pull never assets
else
	$(COMPOSE_COMMAND) run --rm --no-deps vite sh -c 'npm ci && npm run build && rm -f public/hot'
endif

init: check ## Initialize writable storage ownership (application services must be stopped).
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) run --rm --no-deps --pull never storage-init
else
	$(COMPOSE_COMMAND) run --rm --no-deps --user root app sh -c 'mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache'
endif

db-up: check ## Start PostgreSQL and wait for health.
	$(COMPOSE_COMMAND) up $(if $(filter production,$(ENVIRONMENT)),--no-build --pull never --no-recreate )--detach --wait postgres

watch: ## Synchronize local code and run Vite (requires development dependencies).
ifeq ($(ENVIRONMENT),production)
	$(error Live source synchronization is unavailable in production; use make stop, assets, and up)
else
	$(COMPOSE_COMMAND) watch
endif

ps: ## Show service status, including initialization containers.
	$(COMPOSE_COMMAND) ps --all

logs: ## Follow logs; pass SERVICE=name to filter.
	$(COMPOSE_COMMAND) logs --follow $(SERVICE)

shell: ## Open a shell in the app container.
	$(COMPOSE_COMMAND) exec app sh

artisan: ## Run Artisan; pass COMMAND='route:list' (for example).
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) run --rm --no-deps --pull never release release $(COMMAND) --no-interaction
else
	$(COMPOSE_COMMAND) exec app php artisan $(COMMAND)
endif

composer: ## Run Composer locally; production installs locked dependencies during up/apply.
ifeq ($(ENVIRONMENT),production)
	$(error Production installs reviewed locked dependencies with make apply; update Composer manifests locally)
else
	$(COMPOSE_COMMAND) exec app composer $(COMMAND)
endif

test: ## Run the Laravel test suite (requires development dependencies).
ifeq ($(ENVIRONMENT),production)
	$(error The production image excludes test dependencies; select a local ENV_FILE to run tests)
else
	$(COMPOSE_COMMAND) exec app php artisan test --compact
endif

migrate: check ## Run pending database migrations without seeding.
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) run --rm --no-deps --pull never release release migrate --force --no-interaction
else
	$(COMPOSE_COMMAND) exec app php artisan migrate --force --no-interaction
endif

migrate-fresh: ## Drop all tables and rerun migrations (destructive, local only).
ifeq ($(ENVIRONMENT),production)
	$(error Production databases cannot be reset with migrate-fresh; use make migrate)
else
	$(COMPOSE_COMMAND) exec app php artisan migrate:fresh --force --no-interaction
endif

cache-clear: ## Clear application caches and refresh/restart production services.
ifeq ($(ENVIRONMENT),production)
	$(MAKE) stop
	$(COMPOSE_COMMAND) run --rm --no-deps --pull never release release optimize:clear --no-interaction
	$(MAKE) up
else
	$(COMPOSE_COMMAND) exec app php artisan optimize:clear --no-interaction
endif

queue-restart: ## Signal queue workers to restart after the current job.
	$(COMPOSE_COMMAND) exec app php artisan queue:restart --no-interaction

db: ## Open a PostgreSQL console.
	$(COMPOSE_COMMAND) exec postgres sh -lc 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'
