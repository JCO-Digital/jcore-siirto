# Thin wrapper over the pnpm scripts. `make ci` is the entry point the shared
# publish workflow in jcore-update calls; the rest are local shortcuts.

.PHONY: all ci install build i18n check format start playground clean

all: install build i18n

ci: install build i18n

install:
	pnpm install
	composer install --no-dev --no-interaction --prefer-dist

build:
	pnpm build

i18n:
	pnpm i18n

check:
	pnpm check

format:
	pnpm format

start:
	pnpm start

playground:
	pnpm playground

clean:
	rm -rf build node_modules release vendor
