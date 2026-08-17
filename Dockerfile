#syntax=docker/dockerfile:1

# Adapted from dunglas/symfony-docker (MIT), Copyright (c) Kévin Dunglas.
# https://github.com/dunglas/symfony-docker — see THIRD-PARTY-NOTICES.md.

# Versions
FROM dunglas/frankenphp:1-php8.5 AS frankenphp_upstream

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/build/building/multi-stage/#stop-at-a-specific-build-stage
# https://docs.docker.com/reference/compose-file/build/#target


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# persistent deps
# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		pdo_pgsql \
		zip
	rm -rf /var/lib/apt/lists/*
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

###> recipes ###
###> symfony/panther ###
# Chromium and ChromeDriver
ENV PANTHER_NO_SANDBOX=1
# Not mandatory, but recommended
ENV PANTHER_CHROME_ARGUMENTS='--disable-dev-shm-usage'
# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends chromium chromium-driver && rm -rf /var/lib/apt/lists/*

# Firefox and geckodriver
#ARG GECKODRIVER_VERSION=0.34.0
# hadolint ignore=DL3008
#RUN apt-get update && apt-get install -y --no-install-recommends firefox && rm -rf /var/lib/apt/lists/*
#RUN wget -q https://github.com/mozilla/geckodriver/releases/download/v$GECKODRIVER_VERSION/geckodriver-v$GECKODRIVER_VERSION-aarch64.tar.gz; \
#	tar -zxf geckodriver-v$GECKODRIVER_VERSION-aarch64.tar.gz -C /usr/bin; \
#	rm geckodriver-v$GECKODRIVER_VERSION-aarch64.tar.gz
###< symfony/panther ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

# dev dependencies
RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	useradd -m -s /bin/bash nonroot
	git config --system --add safe.directory /app
	# The dev container runs as the host user (see compose.override.yaml), whose
	# uid is not known at build time. These are the paths it must be able to
	# write: var/ is an anonymous volume that inherits these permissions when it
	# is created, and Caddy stores its local TLS certificate under /data.
	#
	# The caddy/ subdirectories matter as much as their parents. They arrive from
	# the upstream image owned by root, so making only /data and /config writable
	# leaves Caddy unable to create /data/caddy/pki and the container never
	# becomes healthy. A named volume takes its permissions from here, so a
	# machine that already has one will not show this — only a fresh one will.
	mkdir -p /app/var /data/caddy /config/caddy
	chmod 1777 /app/var /data /data/caddy /config /config/caddy
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Builder for the prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod_builder

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
# The monorepo's own packages are Composer path repositories, so they have to be
# present before install can resolve them (docs/architecture.md §3).
COPY --link packages packages/
# **A cache mount rather than `--no-cache`** (XIV-99). The recipe this line came
# from passes `--no-cache` so that nothing is left inside the image, which is
# right — a layer full of zip archives is weight nobody wants. But it also means
# every dependency's dist is fetched from codeload on *every* build, and after a
# day of merges GitHub starts answering `HTTP/2 429` and the build fails for a
# reason that has nothing to do with what is being built.
#
# A BuildKit cache mount keeps both properties: the archives live outside the
# image, so no layer grows, and they survive between builds, so a rebuild
# downloads only what actually changed. `COMPOSER_CACHE_DIR` is set explicitly
# rather than relying on `$HOME`, because the mount target has to be a path this
# file names and not one the base image happens to choose.
# **And an optional credential, mounted rather than copied** (XIV-99). Every
# package here is public, so this buys no access — it raises the *API* allowance
# from 60 requests an hour to 5,000, which composer spends on metadata.
#
# **It does not lift the limit that actually broke a build**, and that is worth
# writing down because it is the obvious wrong guess. The failure was
# `HTTP/2 429` from `codeload.github.com` while fetching dist archives, and that
# limit is per address: measured on the same URL, anonymous and authenticated
# both answered 429. A token is not the fix for it. **The cache mount above is**,
# because the archives it stops re-fetching are the requests being counted.
#
# `type=secret` and not an `ARG` or a `COPY`: a build argument is recorded in the
# image's own history and a copied file is a layer, and a token in either is a
# token that has leaked. A secret mount exists only while this one command runs.
#
# `required=false` on purpose — a checkout with no token still builds, just
# against the anonymous limit. That keeps `git clone && bin/ci` working for
# somebody who has never heard of this line.
# **The mount target is outside `/app`**, so that nothing which copies `/app` can
# pick it up, and composer is pointed at it through `COMPOSER_AUTH` — expanded by
# the shell inside this one command, so the token is in no layer and the image
# history records only the `$(cat …)` that produced it.
#
# **That was not what leaked, and the real cause is worth writing down.** A token
# did reach a production image during this work, and the mount target was a red
# herring: the file was in the **build context**. `.gitignore` covered it, Docker
# does not read `.gitignore`, and `COPY --link --exclude=frankenphp/ . ./` below
# copies the context wholesale. `.dockerignore` is the list that decides what
# reaches an image, and it now excludes `auth.json`.
#
# It survived at mode 600 owned by root, so the obvious check — grep inside the
# image — found nothing, because the image runs as an unprivileged user. **Verify
# as root or the check is worse than none**, because it produces confidence.
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    --mount=type=secret,id=composer_auth,target=/run/secrets/composer_auth,required=false \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_AUTH="$(cat /run/secrets/composer_auth 2>/dev/null || echo '{}')" \
    composer install --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	mkdir -p var/cache var/log var/share
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	chmod +x bin/console
	chmod -R g=u var
	sync
EOF

# Collect shared libraries needed by FrankenPHP and PHP extensions
# hadolint ignore=DL3008,SC3054,DL4006
RUN <<-'EOF'
	apt-get update
	apt-get install -y --no-install-recommends libtree
	mkdir -p /tmp/libs
	BINARIES=(frankenphp php file)
	for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			[ -f "$lib" ] && cp -n "$lib" /tmp/libs/
		done
	done
	rm -rf /var/lib/apt/lists/*
EOF

# Prod FrankenPHP image
FROM debian:13-slim AS frankenphp_prod

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

COPY --from=frankenphp_prod_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp_prod_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=frankenphp_prod_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=frankenphp_prod_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=frankenphp_prod_builder /tmp/libs /usr/lib

COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# CA certificates for TLS, file/libmagic for Symfony MIME type detection
COPY --from=frankenphp_prod_builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=frankenphp_prod_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=frankenphp_prod_builder /usr/bin/file /usr/bin/file
COPY --from=frankenphp_prod_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

ENV  OPENSSL_CONF=/etc/ssl/openssl.cnf XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt

RUN <<-EOF
	mkdir -p /data/caddy /config/caddy
	chown -R www-data:www-data /data /config
	# Remove setuid/setgid bits
	find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

COPY --link --exclude=var --from=frankenphp_prod_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
RUN chmod g=u /app/var

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

USER www-data

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
