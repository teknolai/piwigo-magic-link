.PHONY: up down logs db restart clean test-unit test-e2e help

help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-14s\033[0m %s\n", $$1, $$2}'

up: ## Start all containers (Piwigo + DB + Mailpit + Selenium)
	docker compose up -d
	@echo ""
	@echo "  Piwigo:  http://localhost"
	@echo "  Mailpit: http://localhost:8025"
	@echo ""

down: ## Stop all containers
	docker compose down

logs: ## Tail Piwigo logs
	docker compose logs -f piwigo

db: ## Open a MySQL shell
	docker compose exec db mysql -u piwigo -ppiwigo piwigo

restart: ## Restart Piwigo only (pick up PHP changes without full restart)
	docker compose restart piwigo

clean: ## Destroy everything including DB data — full fresh start
	docker compose down -v
	@echo "All containers and volumes removed."

test-unit: ## Run PHPUnit unit tests (no Docker needed)
	vendor/bin/phpunit tests/unit --testdox

test-e2e: ## Run Codeception browser E2E tests (Docker must be running)
	docker compose exec piwigo php /config/www/plugins/MagicLinkLogin/vendor/bin/codecept run e2e --html
	@echo "Report: tests/_output/report.html"
