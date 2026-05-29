.PHONY: up down logs db restart clean test-unit package help

PLUGIN := MagicLinkLogin
DIST   := dist

help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-14s\033[0m %s\n", $$1, $$2}'

up: ## Start all containers (Piwigo + DB + Mailpit)
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

test-unit: ## Run PHPUnit unit tests (in dedicated php-cli container)
	docker compose run --rm phpunit vendor/bin/phpunit --testdox

package: ## Build a clean distributable zip (runtime files only, no dev artifacts)
	@rm -rf $(DIST)/$(PLUGIN) $(DIST)/$(PLUGIN).zip
	@mkdir -p $(DIST)/$(PLUGIN)
	@cp -R \
		plugins/$(PLUGIN)/main.inc.php \
		plugins/$(PLUGIN)/maintain.class.php \
		plugins/$(PLUGIN)/magic_link_handler.php \
		plugins/$(PLUGIN)/verify.php \
		plugins/$(PLUGIN)/include \
		plugins/$(PLUGIN)/template \
		plugins/$(PLUGIN)/language \
		plugins/$(PLUGIN)/LICENSE \
		plugins/$(PLUGIN)/README.md \
		$(DIST)/$(PLUGIN)/
	@find $(DIST)/$(PLUGIN) -name '.DS_Store' -delete
	@cd $(DIST) && zip -rq $(PLUGIN).zip $(PLUGIN)
	@rm -rf $(DIST)/$(PLUGIN)
	@echo "Built $(DIST)/$(PLUGIN).zip"
	@unzip -l $(DIST)/$(PLUGIN).zip
