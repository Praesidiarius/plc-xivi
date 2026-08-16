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
#   IMAGES_PREFIX          what this checkout's own images are called
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

# **And the image it runs** (XIV-71).
#
# XIV-51 made the project, the ports, the bind mount and the tenant prefix
# per-checkout, and stopped one line short: `compose.override.yaml` names the dev
# image `${IMAGES_PREFIX:-}xivi-php-dev`, which was one fixed name for every
# checkout on the machine. A branch that touches the `Dockerfile` — or anything it
# copies in, and `frankenphp/docker-entrypoint.sh` is copied in — therefore
# rebuilt the image every *other* checkout was already running.
#
# That is not a hypothetical. XIV-63 changed the entrypoint to call
# `bin/reconcile` and rebuilt; the worktree next door, on a branch predating that
# script, was handed an entrypoint calling a file its tree did not contain and its
# php container crash-looped under `set -e`. It worked around it with a temporary
# shim, which is the right instinct and should not have been necessary.
#
# **The crash was the lucky outcome.** The same mechanism can hand a worktree an
# image that differs *quietly* — an extension one branch added, a changed
# `php.ini`, a different base tag — and everything comes up healthy while `bin/ci`
# is green or red for reasons that have nothing to do with the branch. An artifact
# that does not match the tree being checked is XIV-63's complaint one layer down,
# and it also breaks the property that made parallel checkouts worth having: two
# of them are supposed to be unable to disturb each other, and neither could tell.
#
# The marginal disk cost is what makes this affordable, and it was measured rather
# than assumed: a worktree whose build inputs match the main checkout's produces
# byte-for-byte the same layers, so Docker resolves it to the *same image ID* with
# a second tag on it — 0 B. A worktree pays only for what it actually changed, and
# only from the changed layer down.
#
# Empty for the main checkout, so its databases, containers, ports and images keep
# the names they have always had and nothing about a single-checkout run changes.
if [ "$XIVI_CHECKOUT" = "$XIVI_MAIN_CHECKOUT" ]; then
	export TEST_RUN=""
	export IMAGES_PREFIX=""
else
	# A database identifier: letters, digits and underscores only.
	export TEST_RUN="_$(printf '%s' "$XIVI_CHECKOUT" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '_')"

	# **A third sanitising**, for the reason there are already two: a Docker
	# reference is neither a compose project name nor a database identifier. It
	# forbids a leading or trailing separator, and forbids mixing them — `foo-_bar`
	# is a legal project name and an illegal image name — so this reduces to the
	# part that is legal everywhere, letters, digits and single hyphens, and then
	# puts one hyphen back on the end because this is a *prefix* that gets
	# `xivi-php-dev` concatenated onto it.
	XIVI_IMAGE_SLUG="$(printf '%s' "$XIVI_CHECKOUT" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-')"
	XIVI_IMAGE_SLUG="${XIVI_IMAGE_SLUG#-}"
	XIVI_IMAGE_SLUG="${XIVI_IMAGE_SLUG%-}"
	export IMAGES_PREFIX="${IMAGES_PREFIX:-${XIVI_IMAGE_SLUG}-}"

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
# with compose.yaml and compose.override.yaml. The same caveat covers the image:
# the stem `xivi-php-dev` is written out in compose.override.yaml, and repeating
# it here is the price of being able to print the whole name.
#
# The image is printed rather than left implicit because it is the one thing in
# this list that outlives the checkout. `git worktree remove` takes the directory,
# the stack goes with `bin/compose down`, and the image stays — so the name a
# reader needs in order to `docker image rm` it has to be obtainable *before* the
# directory that derives it is gone.
xivi_stack_summary() {
	if [ "$XIVI_CHECKOUT" = "$XIVI_MAIN_CHECKOUT" ]; then
		printf 'checkout   %s (the main one)\n' "$XIVI_CHECKOUT"
	else
		printf 'checkout   %s\n' "$XIVI_CHECKOUT"
	fi
	printf 'project    %s\n' "$COMPOSE_PROJECT_NAME"
	printf 'root       %s\n' "$APP_ROOT"
	printf 'image      %sxivi-php-dev\n' "$IMAGES_PREFIX"
	printf 'app        https://localhost:%s\n' "${HTTPS_PORT:-443}"
	printf 'database   127.0.0.1:%s\n' "${DATABASE_PORT:-5432}"
	printf 'adminer    http://127.0.0.1:%s   (--profile tools)\n' "${ADMINER_PORT:-8080}"
	printf 'mail       http://127.0.0.1:%s\n' "${MAILPIT_PORT:-8025}"
	# What the suite's own tenants are called: config/services.yaml builds
	# `tenant<TEST_RUN><TEST_TOKEN>_`, the token being paratest's worker number.
	# Dev tenants are unaffected — they are `tenant_<slug>` whatever the checkout.
	printf 'test dbs   tenant%s<worker>_\n' "${TEST_RUN}"
}
