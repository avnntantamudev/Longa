# LONGA — atalhos para o ambiente Docker
# Uso: make <alvo>    (make help para a lista)

DC  = docker compose
APP = $(DC) exec app

.PHONY: help env up down restart logs shell migrate fresh seed test coverage lint lint-fix analyse ci build-assets

help: ## Mostra esta ajuda
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

env: ## Cria o .env a partir do .env.example (se não existir)
	@test -f .env || cp .env.example .env

up: env ## Constrói e arranca todos os serviços
	$(DC) up -d --build
	@echo "App:      http://localhost:$${APP_PORT:-8080}"
	@echo "Mailpit:  http://localhost:8025"
	@echo "Horizon:  http://localhost:$${APP_PORT:-8080}/horizon"

down: ## Pára e remove os contentores
	$(DC) down

restart: down up ## Reinicia tudo

logs: ## Segue os logs de todos os serviços
	$(DC) logs -f --tail=100

shell: ## Abre uma shell no contentor app
	$(APP) bash

migrate: ## Corre as migrations
	$(APP) php artisan migrate

fresh: ## Recria a base de dados e corre os seeders
	$(APP) php artisan migrate:fresh --seed

seed: ## Corre os seeders
	$(APP) php artisan db:seed

test: ## Corre os testes (Pest)
	$(APP) composer test

coverage: ## Corre os testes com relatório de cobertura
	$(APP) composer test:coverage

lint: ## Verifica o estilo (Pint) sem alterar ficheiros
	$(APP) composer lint

lint-fix: ## Corrige o estilo automaticamente
	$(APP) composer lint:fix

analyse: ## Análise estática (Larastan)
	$(APP) composer analyse

ci: lint analyse test ## Corre a mesma verificação que o CI

build-assets: ## Recompila os assets do frontend
	$(DC) run --rm assets sh -c "npm ci && npm run build"
