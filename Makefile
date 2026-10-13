COMPOSE ?= docker compose
COMPOSE_PROJECT ?= oapteka-op
COMMAND ?=
ENVIRONMENT ?= local
PRODUCTION_ENV_FILE ?= .env.production

# A checkout with production configuration must select its environment explicitly.
ifeq ($(origin ENVIRONMENT),file)
ifneq ($(wildcard $(PRODUCTION_ENV_FILE)),)
ifneq ($(filter-out help,$(MAKECMDGOALS)),)
$(error Production configuration exists; select ENVIRONMENT=production or ENVIRONMENT=local explicitly)
endif
endif
endif

ifeq ($(ENVIRONMENT),production)
export PRODUCTION_ENV_FILE
# Pin the file and project even if COMPOSE_FILE / COMPOSE_PROJECT_NAME are inherited.
override COMPOSE_COMMAND := docker compose --project-name oapteka-op --env-file "$(PRODUCTION_ENV_FILE)" -f "$(CURDIR)/compose.production.yaml"
else ifeq ($(ENVIRONMENT),local)
COMPOSE_COMMAND = $(COMPOSE)
else
$(error ENVIRONMENT must be local or production)
endif

.DEFAULT_GOAL := help

.PHONY: help network up down restart build watch ps logs shell artisan composer test migrate migrate-fresh cache-clear queue-restart db

help: ## Show available commands.
	@awk 'BEGIN {FS = ":.*##"}; /^[a-zA-Z_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

network: ## Create the shared Traefik network if it does not already exist.
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) config --quiet
	@docker network inspect "$$( $(COMPOSE_COMMAND) config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["networks"]["traefik-net"]["name"])' )" >/dev/null
else
	@docker network inspect traefik-net >/dev/null 2>&1 || docker network create traefik-net
endif

ifeq ($(ENVIRONMENT),production)
up: production-application ## Start application services after the documented release steps.
else
up: network ## Build images and start all services in the background.
	$(COMPOSE_COMMAND) up --build --detach
endif

down: ## Stop and remove containers and project networks (keeps volumes).
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) down
else
	$(COMPOSE_COMMAND) --project-name $(COMPOSE_PROJECT) down
endif

ifneq ($(ENVIRONMENT),production)
restart: ## Restart all services.
	$(COMPOSE_COMMAND) restart
endif

build: ## Build service images.
	$(COMPOSE_COMMAND) build

ifneq ($(ENVIRONMENT),production)
watch: ## Start the stack, synchronize code, and run Vite inside Docker.
	$(COMPOSE_COMMAND) watch
endif

ps: ## Show service status.
	$(COMPOSE_COMMAND) ps

logs: ## Follow all logs; pass SERVICE=name to filter (for example: make logs SERVICE=queue).
	$(COMPOSE_COMMAND) logs --follow $(SERVICE)

shell: ## Open a shell in the app container.
	$(COMPOSE_COMMAND) exec app sh

artisan: ## Run Artisan; pass COMMAND='route:list' (for example).
ifeq ($(ENVIRONMENT),production)
	$(COMPOSE_COMMAND) run --rm --no-deps release release $(COMMAND) --no-interaction
else
	$(COMPOSE_COMMAND) exec app php artisan $(COMMAND)
endif

ifneq ($(ENVIRONMENT),production)
composer: ## Run Composer; pass COMMAND='require vendor/package' (for example).
	$(COMPOSE_COMMAND) exec app composer $(COMMAND)
endif

ifneq ($(ENVIRONMENT),production)
test: ## Run the Laravel test suite inside the app container.
	$(COMPOSE_COMMAND) exec app php artisan test --compact
endif

ifeq ($(ENVIRONMENT),production)
migrate: production-migrate ## Run pending production migrations using the current environment.
else
migrate: ## Run pending database migrations.
	$(COMPOSE_COMMAND) exec app php artisan migrate --force --no-interaction
endif

ifneq ($(ENVIRONMENT),production)
migrate-fresh: ## Drop all tables and rerun migrations (destructive).
	$(COMPOSE_COMMAND) exec app php artisan migrate:fresh --force
endif

ifneq ($(ENVIRONMENT),production)
cache-clear: ## Clear Laravel's application caches.
	$(COMPOSE_COMMAND) exec app php artisan optimize:clear
endif

queue-restart: ## Signal queue workers to restart after the current job.
	$(COMPOSE_COMMAND) exec app php artisan queue:restart

db: ## Open a PostgreSQL console.
	$(COMPOSE_COMMAND) exec postgres sh -lc 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'

# Explicit production targets require ENVIRONMENT=production; local stays the default.
.PHONY: require-production production-check production-assets production-init production-db production-application production-migrate production-stop production-status
require-production:
	@test "$(ENVIRONMENT)" = production || { echo 'Set ENVIRONMENT=production for production commands.' >&2; exit 1; }

production-check: require-production ## Validate production Compose without printing secrets.
	$(COMPOSE_COMMAND) config --quiet

production-assets: production-check ## Build frontend assets with temporary Node 24 service.
	$(COMPOSE_COMMAND) run --rm --no-deps assets

production-init: production-check ## Initialize writable volume ownership (services must be stopped).
	$(COMPOSE_COMMAND) run --rm --no-deps storage-init

production-db: production-check ## Start only PostgreSQL and wait for health.
	$(COMPOSE_COMMAND) up --detach --wait postgres

production-application: production-check ## Refresh caches, then recreate web, queue, and scheduler services.
	$(COMPOSE_COMMAND) up --force-recreate --no-deps --exit-code-from app-init app-init
	$(COMPOSE_COMMAND) up --detach --force-recreate --no-deps app queue scheduler nginx

production-migrate: production-check ## Apply migrations using current environment, without seeding.
	$(COMPOSE_COMMAND) run --rm --no-deps release release migrate --force --no-interaction

production-stop: require-production ## Stop OApteka web services and workers before changing mounted source.
	$(COMPOSE_COMMAND) stop nginx app queue scheduler

production-status: require-production ## Show production container status.
	$(COMPOSE_COMMAND) ps --all

ifeq ($(ENVIRONMENT),production)
# These development shortcuts are unsuitable for an image-owned vendor directory / live source mounts.
composer test migrate-fresh watch restart cache-clear:
	$(error Use the documented production release sequence; this shortcut is local-only)
endif
