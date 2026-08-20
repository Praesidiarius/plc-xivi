#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

	# Make what is on disk match the tree this container is about to serve
	# (XIV-63). This used to be `composer install` when vendor/ was empty, which
	# is right exactly once — on the first start of a fresh checkout. After that
	# the test never fires again, so restarting the container after a merge that
	# changed composer.lock installs nothing, which is both the obvious first
	# guess and the wrong one; only deleting vendor/ worked.
	#
	# The script is the same one `bin/ci` runs, so a stack started by hand is in
	# the state the suite would have put it in, and it is a no-op in a production
	# image — read it for what it does and does not touch.
	bin/reconcile

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	# **Refuse to start on a secret anybody can read out of the repository**
	# (XIV-61, docs/architecture/deployment.md §4.2).
	#
	# `.env` is committed and public, and the image build compiles it into
	# `.env.local.php` with `composer dump-env prod` — so a freshly built image
	# contains a working `APP_SECRET` and a working `TENANT_SECRET_KEYS`, and a
	# deployment that forgets to supply its own runs on them **while looking
	# perfectly healthy**. No error, no warning, no degraded behaviour: cookies
	# are signed, invitation links verify, tenant passwords decrypt. The way it
	# surfaces is somebody forging one.
	#
	# This is where the guarantee belongs rather than in `bin/deploy`, which runs
	# it too: a deploy can be skipped, replayed from an older revision or walked
	# past by restarting a container by hand, and the container that comes back
	# from any of those is the one serving customers. `set -e` at the top of this
	# file means a refusal here never reaches `frankenphp run`, so the failure
	# presents as a container that will not come up — which is loud — rather than
	# as a service that is fine.
	#
	# It runs before the database wait on purpose. Nothing below is worth doing
	# for an instance that must not serve, and the reason is more readable in a
	# log that does not have sixty seconds of connection attempts after it.
	#
	# **It stands down entirely outside `APP_ENV=prod`.** `bin/ci`, the test suite
	# and `bin/compose up` all run on those placeholders deliberately — that is
	# what lets a fresh checkout start with nothing configured — so refusing there
	# would be refusing the ordinary case.
	php bin/console deploy:check-secrets

	# **Rebuild the routing cache against this deployment's environment**
	# (XIV-61, docs/architecture/deployment.md §4.8).
	#
	# The image ships a warmed `var/cache/prod`, built from the committed `.env`,
	# and most of what is in there is env-independent because `%env()%` is
	# resolved when a parameter is read rather than when the container is
	# compiled. Routing is the exception, and it is not a small one.
	#
	# `SignupRouteLoader` decides **whether routes exist at all** from
	# `SIGNUP_HOST` (§8.13), and that decision is written into the compiled URL
	# matcher at warmup. Warmup happened at build time, where `SIGNUP_HOST` is the
	# empty value `.env` commits, so an instance that sets it got a matcher with
	# no signup routes in it and served its landing page from the dashboard route
	# instead. That is a 500 on a host which deliberately resolves no tenant, and
	# nothing anywhere says the cause: `debug:router` lists the routes correctly,
	# because it re-reads the loader rather than the matcher, so the one command
	# somebody would run to check agrees that everything is fine.
	#
	# One image is deployed to instances with different hostnames, so anything
	# structural taken from the environment has to be recomputed here. It costs
	# under a second and it runs before the database wait, so it is not on the
	# path of anything slow.
	#
	# **`cache:clear` and not `cache:warmup`**, which was measured rather than
	# assumed: warmup leaves an existing `url_matching_routes.php` exactly as it
	# found it, so on this image it is a no-op and the stale matcher survives it.
	# Clearing builds the cache directory afresh and the matcher comes back with
	# the deployment's hostnames in it.
	php bin/console cache:clear

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		# **The control plane's migrations, and deliberately not the tenants'**
		# (XIV-61, docs/architecture/deployment.md §4.2).
		#
		# This is one database, one transaction, and the container cannot serve a
		# single request without it, so running it on every start is both cheap
		# and the thing that keeps a container from ever serving against a
		# control-plane schema older than itself. It is idempotent, so a start
		# after `bin/deploy` has already run costs a version query.
		#
		# `bin/console tenant:migrate` is **not** here, and that is the decision
		# rather than an omission. The tenant set is one database per customer,
		# and this script runs on every container start — an OOM restart, a
		# health-check flap, a node draining, somebody typing
		# `docker compose restart`. Putting the loop here would make every one of
		# those an operation over fifty customers' databases, taken at a moment
		# nobody planned, with the container not serving for its duration; and
		# two containers starting at once would compute the same plan for the
		# same fifty databases and both begin applying it, which `all_or_nothing`
		# does not protect against because it protects a run from itself rather
		# than from another run.
		#
		# So the tenant half is a one-shot step a deploy calls: `bin/deploy`,
		# which ships in this image and runs secrets, control plane and tenants
		# in that order. Read it for the full argument and for the migration
		# window the ordering depends on.
		#
		# **And only out of the image that has the administration surface**
		# (XIV-96, §4.4). There are two production images now: the internal one,
		# which contains `packages/control-plane`, and the customer-facing one,
		# which does not. They share one control-plane database, and the
		# customer-facing instance connects to it as a role that holds `SELECT`
		# on the registry tables and nothing else — no writes, no DDL, so this
		# command could not succeed there even if it were run (see
		# `bin/console deploy:registry-grants`).
		#
		# The test is the package's presence rather than an environment
		# variable, and that is the same choice `config/bundles.php` makes one
		# level up: a flag says what somebody configured, a directory says what
		# is in the image. The two builds cannot disagree with it, and neither
		# can a deployment that copies the wrong `.env`.
		#
		# So the schema has exactly one owner, which is the property worth
		# having: `bin/deploy` runs out of the internal image, moves the control
		# plane and every tenant, and only then are the serving containers
		# replaced (§4.2).
		if find ./migrations -iname '*.php' -print -quit | grep --quiet .; then
			if [ -d packages/control-plane ]; then
				php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
			else
				# **The customer-facing image asks instead of writing, and a
				# refusal here stops the container.**
				#
				# The guarantee the line above buys the internal image is that a
				# container never serves against a control-plane schema older
				# than itself. That guarantee is worth just as much over here and
				# cannot be bought the same way, so it is bought by checking:
				# `up-to-date` is a `SELECT` on `doctrine_migration_versions`,
				# which is the one administration table this instance is granted.
				#
				# Fatal rather than advisory, which puts it beside
				# `deploy:check-secrets` rather than beside `deploy:check-hosts`
				# — and the asymmetry is the same one those two already draw. A
				# schema behind the code is a property of the *instance*: every
				# customer this container would serve is served by code expecting
				# columns that are not there. Refusing to start denies exactly
				# the thing that must not run, and it is loud, which an
				# intermittent query failure in production is not.
				#
				# It is not a race with the deploy. `bin/deploy` migrates before
				# the containers are replaced, so by the time this runs the
				# answer is already yes; and a rollback is fine too, because
				# `--fail-on-unregistered` is deliberately not passed — a schema
				# *ahead* of this image is what going backwards looks like and is
				# explicitly allowed by §4.2's additive-only rule.
				echo 'No administration surface in this image, so the control-plane schema is not this container'"'"'s to move.'
				echo 'Checking that somebody else has already moved it...'
				php bin/console doctrine:migrations:up-to-date
			fi
		fi

		# **Say which hostnames this instance answers to, on every start**
		# (XIV-93, docs/architecture/deployment.md §4.3).
		#
		# `framework.trusted_hosts` refuses a `Host` outside XIVI_TRUSTED_DOMAINS
		# with a bare 400 — no page, no header named, nothing in the body — and
		# the person who finds out is the customer whose installation just went
		# dark. This prints the pattern in force and names every tenant in the
		# registry that would be refused by it, so that the log of a container
		# that is serving already contains the answer to the question somebody
		# will ask about it later.
		#
		# **`|| true`, and the reason is the opposite of the secret check's.** A
		# published secret is a property of the instance, so refusing to start
		# denies exactly the thing that must not run. A hostname outside the
		# pattern is a property of *one customer*, who is already dark — and
		# exiting non-zero here would take every other customer dark to protect
		# them, on every restart, for as long as the mistake stood. The gate is
		# `bin/deploy`, which runs this before traffic moves and where stopping
		# costs a one-shot container.
		#
		# It stands down in the sense that matters outside production too: an
		# installation that sets nothing prints one line saying it answers to
		# everything, which is what development is and what this application did
		# before XIV-93.
		php bin/console deploy:check-hosts || true
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
