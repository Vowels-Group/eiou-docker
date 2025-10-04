# Makefile for eIOU Application
# Usage: make [target]

.PHONY: help
help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-20s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

.PHONY: build
build: ## Build Docker containers
	docker-compose build

.PHONY: up
up: ## Start all containers
	docker-compose up -d

.PHONY: down
down: ## Stop all containers
	docker-compose down

.PHONY: restart
restart: down up ## Restart all containers

.PHONY: logs
logs: ## View container logs
	docker-compose logs -f

.PHONY: shell
shell: ## Access application shell
	docker-compose exec app bash

.PHONY: test
test: ## Run all tests
	docker-compose run --rm test-runner

.PHONY: test-unit
test-unit: ## Run unit tests only
	docker-compose exec app php tests/Unit/*.php

.PHONY: test-integration
test-integration: ## Run integration tests only
	docker-compose exec app php tests/Integration/*.php

.PHONY: test-security
test-security: ## Run security tests only
	docker-compose exec app php tests/Security/*.php

.PHONY: lint
lint: ## Run code linting
	docker-compose exec app phpcs --standard=PSR12 src/

.PHONY: fix
fix: ## Fix code style issues
	docker-compose exec app phpcbf --standard=PSR12 src/

.PHONY: analyze
analyze: ## Run static analysis
	docker-compose exec app phpstan analyze src/ --level=5

.PHONY: clean
clean: ## Clean up containers and volumes
	docker-compose down -v
	docker system prune -f

.PHONY: backup
backup: ## Backup database
	@mkdir -p backups
	docker-compose exec app sqlite3 /var/www/html/data/database.sqlite ".backup /var/www/html/backups/backup-$$(date +%Y%m%d-%H%M%S).sqlite"
	@echo "Backup created in backups/"

.PHONY: restore
restore: ## Restore database from latest backup
	@latest=$$(ls -t backups/*.sqlite | head -1); \
	if [ -n "$$latest" ]; then \
		docker-compose exec app sqlite3 /var/www/html/data/database.sqlite ".restore $$latest"; \
		echo "Restored from $$latest"; \
	else \
		echo "No backup found"; \
	fi

.PHONY: migrate
migrate: ## Run database migrations
	docker-compose exec app php artisan migrate

.PHONY: seed
seed: ## Seed the database
	docker-compose exec app php artisan db:seed

.PHONY: fresh
fresh: ## Fresh install with migrations and seeds
	docker-compose exec app php artisan migrate:fresh --seed

.PHONY: monitor
monitor: ## Start monitoring stack
	docker-compose --profile monitoring up -d

.PHONY: status
status: ## Check container status
	docker-compose ps
	@echo ""
	@echo "Health status:"
	@curl -s http://localhost:8080/health || echo "Application not responding"

.PHONY: deploy
deploy: ## Deploy to production
	./scripts/deploy.sh production latest

.PHONY: deploy-staging
deploy-staging: ## Deploy to staging
	./scripts/deploy.sh staging latest

.PHONY: security-scan
security-scan: ## Run security scan
	docker run --rm -v "$$(pwd)":/src aquasec/trivy fs /src

.PHONY: performance
performance: ## Run performance tests
	docker-compose exec app ab -n 1000 -c 10 http://localhost/

.PHONY: install
install: ## Initial installation
	@echo "Installing eIOU application..."
	@cp .env.example .env
	@make build
	@make up
	@make migrate
	@echo "Installation complete! Access at http://localhost:8080"

.PHONY: update
update: ## Update application
	@echo "Updating eIOU application..."
	@git pull
	@make build
	@make migrate
	@make restart
	@echo "Update complete!"

.PHONY: dev
dev: ## Start development environment
	docker-compose -f docker-compose.yml up --build

.PHONY: prod
prod: ## Build for production
	docker build --target production -t eiou:latest .

.PHONY: validate
validate: ## Validate configuration files
	@echo "Validating Docker configuration..."
	@docker-compose config -q && echo "✓ docker-compose.yml is valid" || echo "✗ docker-compose.yml has errors"
	@echo "Validating Dockerfile..."
	@docker build --no-cache -t test-build . > /dev/null 2>&1 && echo "✓ Dockerfile is valid" || echo "✗ Dockerfile has errors"