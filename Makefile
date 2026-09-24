COMPOSE ?= docker compose
COMMAND ?=

.DEFAULT_GOAL := help

.PHONY: help network up down restart build watch ps logs shell artisan composer test migrate migrate-fresh cache-clear queue-restart db

help: ## Show available commands.
	@awk 'BEGIN {FS = ":.*##"}; /^[a-zA-Z_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

network: ## Create the shared Traefik network if it does not already exist.
	@docker network inspect traefik-net >/dev/null 2>&1 || docker network create traefik-net

up: network ## Build images and start all services in the background.
	$(COMPOSE) up --build --detach

down: ## Stop and remove containers and project networks (keeps volumes).
	$(COMPOSE) down

restart: ## Restart all services.
	$(COMPOSE) restart

build: ## Build service images.
	$(COMPOSE) build

watch: ## Start the stack, synchronize code, and run Vite inside Docker.
	$(COMPOSE) watch

ps: ## Show service status.
	$(COMPOSE) ps

logs: ## Follow all logs; pass SERVICE=name to filter (for example: make logs SERVICE=queue).
	$(COMPOSE) logs --follow $(SERVICE)

shell: ## Open a shell in the app container.
	$(COMPOSE) exec app sh

artisan: ## Run Artisan; pass COMMAND='route:list' (for example).
	$(COMPOSE) exec app php artisan $(COMMAND)

composer: ## Run Composer; pass COMMAND='require vendor/package' (for example).
	$(COMPOSE) exec app composer $(COMMAND)

test: ## Run the Laravel test suite inside the app container.
	$(COMPOSE) exec app php artisan test --compact

migrate: ## Run pending database migrations.
	$(COMPOSE) exec app php artisan migrate --force

migrate-fresh: ## Drop all tables and rerun migrations (destructive).
	$(COMPOSE) exec app php artisan migrate:fresh --force

cache-clear: ## Clear Laravel's application caches.
	$(COMPOSE) exec app php artisan optimize:clear

queue-restart: ## Signal queue workers to restart after the current job.
	$(COMPOSE) exec app php artisan queue:restart

db: ## Open a PostgreSQL console.
	$(COMPOSE) exec postgres sh -lc 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'
