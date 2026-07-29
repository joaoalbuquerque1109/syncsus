.PHONY: setup up down migrate seed test quality build logs

setup:
	composer install
	php artisan key:generate
	npm install
	npm run build
	docker compose up -d --build
	docker compose exec app php artisan migrate --seed

up:
	docker compose up -d

down:
	docker compose down

migrate:
	docker compose exec app php artisan migrate

seed:
	docker compose exec app php artisan db:seed

test:
	composer test

quality:
	composer quality
	npm run lint
	npm run format:check
	npm run build

build:
	docker compose build

logs:
	docker compose logs -f app nginx queue-worker scheduler
