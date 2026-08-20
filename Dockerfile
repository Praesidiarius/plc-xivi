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
		bcmath \
		gd \
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
	# **What lets this container deploy** (XIV-61, §4.8). Deployer drives
	# `docker compose` on the target over SSH, from an operator's machine rather
	# than from a hosted runner, and this project has no PHP on the host: the
	# operator's machine *is* this container. Without an ssh client `dep` fails
	# on its first connection with nothing useful to say.
	#
	# Dev image only. The production images have no business holding an ssh
	# client, and the deploy is driven at them rather than from them.
	apt-get update
	apt-get install -y --no-install-recommends openssh-client
	rm -rf /var/lib/apt/lists/*
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
	#
	# `/app/var/attachments` is on that list for exactly the caddy/ reason, one
	# feature along ([XIV-115]): `compose.yaml` mounts a named volume there, and a
	# named volume takes its ownership from the image's own directory when it is
	# created. Without this line the volume arrives owned by root, the dev
	# container cannot write into it, and the failure surfaces as a customer being
	# told their upload could not be saved.
	mkdir -p /app/var/attachments /data/caddy /config/caddy
	chmod 1777 /app/var /app/var/attachments /data /data/caddy /config /config/caddy
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

# Builder for the customer-facing image
FROM frankenphp_prod_builder AS frankenphp_public_builder

# **The half of XIV-96 that a route cannot give you** (docs/architecture/deployment.md §4.4).
#
# Everything above this line built one image containing the whole repository,
# administration surface included. This stage takes the administration surface
# back out, and the result is `frankenphp_public` at the bottom of this file: the
# image a customer's hostname is served from, which does not contain
# `packages/control-plane` at all.
#
# **Two build targets rather than one image with the operator routes switched
# off**, and the argument is short. "Not routed" and "not present" are different
# guarantees, and only the second survives somebody's mistake — a wrong
# environment variable, a merge that reinstates a listener, a compiler pass that
# stops being registered. [XIV-56] is the live precedent for the difference:
# something shipped inside the production image that was never meant to be
# there, inert on the day and discovered long afterwards, and no amount of
# configuration would have kept it out. The cost of this decision is one more
# build in CI, paid once per run and measured in the build cache rather than in
# the whole of `composer install`, because this stage starts from the finished
# one above.
#
# **A separate repository was the third option and was rejected.** Real
# isolation, real drift, and a shared control-plane schema owned by two
# repositories with no single migration history. Not worth it for one operator.
#
# The removal itself is `frankenphp/omit-control-plane.php`, bind-mounted rather
# than copied so that the script which strips the package is not itself in the
# image that had it stripped. Read it for why Composer's installed-package record
# is edited instead of `composer.json` — the short version is that both images
# are built from **one lock file**, which is what stops the two from drifting,
# and that is a property worth more than the tidiness of a second manifest.
#
# The cache is thrown away and rebuilt because it must be. `composer install`
# above has already compiled a service container that knows about operator
# commands, a routing table that includes the signup loader, and a Twig cache
# holding the operator templates. Serving a customer out of that would be
# serving them out of the administration surface's compiled remains.
RUN --mount=type=bind,source=frankenphp/omit-control-plane.php,target=/tmp/omit-control-plane.php <<-EOF
	php /tmp/omit-control-plane.php
	rm -rf packages/control-plane vendor/xivi/control-plane
	composer dump-autoload --classmap-authoritative --no-dev
	rm -rf var/cache/* var/log/*
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	chmod -R g=u var
	sync
EOF

# **Proof, inside the build, that the thing is actually gone.**
#
# The acceptance criterion XIV-96 was written with is "verified by looking inside
# the image rather than by checking that a route 404s", and a check that only
# ever runs when somebody remembers to run it is a check that stops being run.
# So the build itself refuses to produce an image that still has any of it.
#
# Three questions, because each of them has failed independently somewhere in
# this ticket's history: the sources, the autoloader's opinion of them, and the
# compiled container. The last is the one that would otherwise be missed —
# deleting the files while a stale `var/cache/prod` still holds a service
# definition naming them produces an image that boots, serves, and fatals the
# first time that service is instantiated.
RUN <<-'EOF'
	if [ -e packages/control-plane ] || [ -e vendor/xivi/control-plane ]; then
		echo 'The control-plane package is still on disk in the customer-facing build.' >&2
		exit 1
	fi
	# Extended regular expressions, and `\\+` rather than `\\`, because the two
	# files being searched escape the namespace separator differently: PHP source
	# writes 'Xivi\\ControlPlane\\…' with the backslash doubled, YAML and the
	# container's own dumps write it once. One or more backslashes matches both,
	# and matching only one would have quietly passed on the classmap — which is
	# the file that decides whether the classes are loadable at all.
	if grep -rqIE 'Xivi\\+ControlPlane' vendor/composer/; then
		echo 'The autoloader still knows about Xivi\ControlPlane classes.' >&2
		exit 1
	fi
	if grep -rqIE 'Xivi\\+ControlPlane' var/cache/; then
		echo 'The compiled container still names Xivi\ControlPlane.' >&2
		exit 1
	fi
	# **And then: is a copy of it anywhere under /app at all?**
	#
	# The three checks above all passed on an image that contained thirty-three
	# complete copies of the package, under
	# `.claude/worktrees/<agent>/packages/control-plane`. The build context is not
	# the working tree, `.gitignore` is not `.dockerignore`, and a checkout nested
	# one directory deeper matches none of the paths named above. The image was
	# 7.3 GB. `.dockerignore` now excludes those directories, which is the fix;
	# this is what would have caught it, and what will catch the next copy that
	# arrives somewhere nobody has thought of yet.
	#
	# **A path and not the namespace string**, which was tried first and is wrong:
	# plenty of files here legitimately *name* `Xivi\ControlPlane` without being
	# it — the three guarded seams §4.4 lists, `composer.lock`, and comments in
	# `deptrac.yaml`, `bin/ci` and half a dozen classes explaining why the package
	# is absent. A check that fires on a comment is a check somebody deletes. What
	# must not be here is the *code*, and the code lives in a directory whose name
	# says so.
	found=$(find . -ipath '*control-plane*' -print | head -20)
	if [ -n "$found" ]; then
		echo 'A copy of the control-plane package is somewhere in the customer-facing build:' >&2
		echo "$found" >&2
		exit 1
	fi
	echo 'No control-plane package anywhere under /app, and nothing loadable naming it.'
EOF


# The runtime both production images are assembled on
#
# Split out of `frankenphp_prod` by XIV-96 so that the customer-facing image is
# the same runtime as the internal one and differs **only** in which `/app` is
# copied into it. Two nearly identical final stages would have been the other
# way to write this, and the way they fail is that a `COPY` added to one and not
# the other is invisible until something is missing from an image nobody boots
# in CI.
FROM debian:13-slim AS frankenphp_runtime

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

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
# **What compose.prod.yaml's healthcheck runs** (XIV-61, §4.8). This stage is
# `debian:13-slim` and carries no curl, wget, nc or busybox, so a healthcheck
# written the usual way fails with `executable file not found` and the container
# never leaves `health: starting`. PHP is the only thing here that can open a
# socket, and the script says why it asks what it asks.
COPY --link --chmod=755 frankenphp/healthcheck.php /usr/local/bin/xivi-healthcheck

USER www-data

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]


# **The internal image: everything, administration surface included** (XIV-96).
#
# This is the image `bin/deploy` is run out of, and the one served on the
# control-plane hostname. It is also the only one of the two that owns the
# control-plane schema: its entrypoint runs the migrations, because the instance
# that has the administration surface is the instance that is allowed to write
# to the administration database (§4.4, and `deploy:registry-grants`).
#
# The name is unchanged from before this ticket, deliberately. It is what
# `bin/ci` builds, what compose refers to and what any existing deployment
# names, and renaming the complete image to make room for the reduced one would
# have made every one of those quietly build the wrong thing.
FROM frankenphp_runtime AS frankenphp_prod

COPY --link --exclude=var --from=frankenphp_prod_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
USER root
RUN chmod g=u /app/var
USER www-data


# **The customer-facing image: the same application, without the administration
# surface** (XIV-96, §4.4).
#
# Identical to the stage above in every respect except which builder it takes
# `/app` from, which is the whole design: one repository, one lock file, one
# runtime, and a single stage in the middle whose only job is removal. Anything
# that is true of the internal image's runtime is true of this one by
# construction rather than by two lists being kept in step.
#
# **What it does contain, said plainly**, because "does not contain the
# administration surface" is a claim and claims are worth bounding:
#
#   * `migrations/control/` — including the migrations that create `operator`
#     and `signup_request`. Those are the application's and must not move
#     (§3.1): the namespace is recorded in `doctrine_migration_versions` and no
#     table moved when the classes did. They are DDL rather than administration
#     logic, and the image cannot run them anyway — the entrypoint does not, and
#     the database user this instance connects as has no privilege to.
#   * Two `access_control` rules mentioning `^/control`, in
#     `config/packages/security.yaml`, which Symfony requires to be declared in
#     one place. They guard paths this image has no routes for.
#
# Everything else — the operator entity and its firewall, provisioning, the
# tenant list, usage collection, the signup intake and its landing page, every
# `control:*` command — is absent from the filesystem, from the autoloader and
# from the compiled container, and the stage that removed it refuses to finish
# if any of the three still mentions it.
FROM frankenphp_runtime AS frankenphp_public

COPY --link --exclude=var --from=frankenphp_public_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_public_builder /app/var /app/var
USER root
RUN chmod g=u /app/var
USER www-data
