# shellcheck shell=bash
#
# Which stack this checkout owns (XIV-51, extracted here by XIV-55).
#
# Source this, do not run it. It exports the variables that decide *which*
# containers, ports and tenant databases a command acts on, all derived from the
# directory the checkout sits in:
#
#   XIVI_CHECKOUT          the directory name, unsanitised — for lock files and messages
#   COMPOSE_PROJECT_NAME   the compose project
#   APP_ROOT               what the php container bind-mounts
#   APP_UID / APP_GID      who it runs as
#   TEST_RUN               the tenant-database prefix
#   *_PORT                 the published ports, for any checkout but the main one
#
# **Why this is a file of its own.** XIV-51 put this block in `bin/ci`, so only
# `bin/ci` knew it. Every other command — `docker compose exec php …`, `logs`,
# `down` — got the *main* checkout's project and ports instead, with no warning
# and no error: the right containers for the wrong checkout. A worktree could not
# open a shell in its own stack without replicating the block by hand, and found
# out it had not by colliding on a published port (XIV-55).
#
# So: one definition, two readers — `bin/ci` and `bin/compose`. Anything else
# that grows a need for it sources this rather than copying it.
#
# Every assignment keeps the `${VAR:-…}` form the CI script used, so an
# explicitly exported value still wins over the derived one. That is the escape
# hatch for the case nobody has thought of yet.

# The root of *this* checkout, from this file's own location rather than from the
# working directory — a wrapper may be invoked from a subdirectory, and a
# worktree's `bin/` is its own.
XIVI_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# The dev container bind-mounts this working tree and writes into it — vendor/,
# caches, generated config — so it has to run as whoever owns these files.
# Exported here because compose.override.yaml cannot work it out for itself:
# $UID is a shell variable that is never exported, so Compose only ever saw the
# fallback. On a machine where that fallback is wrong, the first thing to fail is
# "/app/vendor does not exist and could not be created", and the container is
# reported unhealthy rather than as a permissions problem.
export APP_UID="${APP_UID:-$(id -u)}"
export APP_GID="${APP_GID:-$(id -g)}"

# The checkout whose stack keeps the ordinary names and the ordinary ports. Any
# other one gets its own, so the two never meet.
XIVI_MAIN_CHECKOUT='plc-xivi'

# **Which checkout this is** (XIV-51).
#
# Everything here assumed one working copy and one stack, which is true until
# somebody wants two branches at once — a second clone, a git worktree, an agent.
# Three things then collide, and all three are named after this:
#
#   * the compose project, or the second `up` adopts the first's containers
#     rather than making its own
#   * the published ports, since two web servers cannot have port 443
#   * the tenant databases, which are namespaced per paratest worker and would
#     otherwise be namespaced per worker *only* — the same eight numbers in both
#     runs, fighting over one set of databases (XIV-9's failure, one layer up)
#
# Derived from the directory rather than configured, so it is right without
# anybody setting anything, and stable so a second run in the same checkout finds
# what the first left behind.
XIVI_CHECKOUT="$(basename "$XIVI_ROOT")"

# **Two sanitisings, because the two names allow different characters.** Compose
# takes a hyphen and Postgres does not, and collapsing them to one rule renames
# the main checkout's project — which then starts a *second* set of containers
# beside the running ones and fails on the first published port. Found exactly
# that way.
XIVI_PROJECT="$(printf '%s' "$XIVI_CHECKOUT" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9_-' '-')"

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-$XIVI_PROJECT}"

# What the php container serves. Compose resolves a relative volume path against
# the compose file's directory, which is the *main* checkout even when the script
# is a worktree's — so a worktree has to say where it is, absolutely.
export APP_ROOT="${APP_ROOT:-$XIVI_ROOT}"

# Empty for the main checkout, so its databases, containers and ports keep the
# names they have always had and nothing about a single-checkout run changes.
if [ "$XIVI_CHECKOUT" = "$XIVI_MAIN_CHECKOUT" ]; then
	export TEST_RUN=""
else
	# A database identifier: letters, digits and underscores only.
	export TEST_RUN="_$(printf '%s' "$XIVI_CHECKOUT" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '_')"

	# A second stack cannot publish the first's ports. Offsets rather than
	# random numbers, so the address of a given checkout is predictable and can
	# be bookmarked.
	XIVI_PORT_OFFSET=$((10#$(printf '%s' "$XIVI_PROJECT" | cksum | cut -d' ' -f1) % 100 + 1))
	export HTTP_PORT="${HTTP_PORT:-$((8000 + XIVI_PORT_OFFSET))}"
	export HTTPS_PORT="${HTTPS_PORT:-$((8400 + XIVI_PORT_OFFSET))}"
	export HTTP3_PORT="${HTTP3_PORT:-$((8400 + XIVI_PORT_OFFSET))}"
	export DATABASE_PORT="${DATABASE_PORT:-$((5500 + XIVI_PORT_OFFSET))}"
	export ADMINER_PORT="${ADMINER_PORT:-$((8100 + XIVI_PORT_OFFSET))}"
	# The mail catcher's web UI (XIV-41). Its own band, 8201–8300, so it cannot
	# land on Adminer's — each of these owns a hundred, which is what the offset
	# can be.
	export MAILPIT_PORT="${MAILPIT_PORT:-$((8200 + XIVI_PORT_OFFSET))}"
fi

# Where this checkout's stack answers, for anything that wants to tell somebody.
# The unset ports are the main checkout's, where the compose files' own defaults
# apply and nothing above has been exported — so the fallbacks here have to agree
# with compose.yaml and compose.override.yaml.
xivi_stack_summary() {
	if [ "$XIVI_CHECKOUT" = "$XIVI_MAIN_CHECKOUT" ]; then
		printf 'checkout   %s (the main one)\n' "$XIVI_CHECKOUT"
	else
		printf 'checkout   %s\n' "$XIVI_CHECKOUT"
	fi
	printf 'project    %s\n' "$COMPOSE_PROJECT_NAME"
	printf 'root       %s\n' "$APP_ROOT"
	printf 'app        https://localhost:%s\n' "${HTTPS_PORT:-443}"
	printf 'database   127.0.0.1:%s\n' "${DATABASE_PORT:-5432}"
	printf 'adminer    http://127.0.0.1:%s   (--profile tools)\n' "${ADMINER_PORT:-8080}"
	printf 'mail       http://127.0.0.1:%s\n' "${MAILPIT_PORT:-8025}"
	# What the suite's own tenants are called: config/services.yaml builds
	# `tenant<TEST_RUN><TEST_TOKEN>_`, the token being paratest's worker number.
	# Dev tenants are unaffected — they are `tenant_<slug>` whatever the checkout.
	printf 'test dbs   tenant%s<worker>_\n' "${TEST_RUN}"
}
