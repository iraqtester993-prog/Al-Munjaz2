.PHONY: test build backend-test backend-build app-build dashboard-build

test: backend-test

build: backend-build app-build dashboard-build

backend-test:

	cd backend && php artisan test

backend-build:

	cd backend && npm run build

app-build:

	npm --prefix frontends/app-pwa run build

dashboard-build:

	npm --prefix frontends/dashboard-pwa run build
