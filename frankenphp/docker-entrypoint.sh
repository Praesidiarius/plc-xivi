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
	# (XIV-61, docs/architecture.md §4.2).
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
		# (XIV-61, docs/architecture.md §4.2).
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
		if find ./migrations -iname '*.php' -print -quit | grep --quiet .; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
