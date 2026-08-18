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
	#
	# **One hundred buckets, and nothing used to notice a second tenant in one**
	# (XIV-86). `cksum` is a checksum rather than a hash — it was picked for
	# being in every POSIX box, not for spread — and a hundred buckets is a
	# birthday problem the moment more than a couple of checkouts exist: seven
	# collide about one time in five, twelve better than even. Parallel agents in
	# worktrees made that the ordinary workload rather than the exotic one, and
	# seven live worktrees on this machine did in fact land two on offset 52.
	#
	# The derivation is deliberately left alone. What a wider space would buy is
	# a smaller probability of a thing that is still possible, at the cost of
	# restructuring bands that each own exactly a hundred numbers; what the
	# offset buys — an address you can bookmark, the same one every time — is
	# worth more than the rarity. So the answer is `xivi_assert_ports_free`
	# below: notice the collision, name the other checkout, and refuse. See
	# docs/architecture.md §9.2.
	XIVI_PORT_OFFSET=$((10#$(printf '%s' "$XIVI_PROJECT" | cksum | cut -d' ' -f1) % 100 + 1))

	# **The bands, as a table rather than as six `export` lines** (XIV-86).
	#
	# It reads a little less directly than the six assignments it replaces, and
	# that is bought for one reason: the guard below has to know the same six
	# variable names and the same six bands in order to work out which *other*
	# offset is free and print the exports that move this checkout onto it. A
	# second copy of that list is a copy that goes wrong on the day somebody
	# publishes a seventh port — which is XIV-55's lesson (a derivation known to
	# one reader is only true where that reader runs) applied inside one file.
	#
	# Each band owns a hundred numbers, which is what fixes the offset at 1–100:
	# http 8001–8100, adminer 8101–8200, the mail catcher's web UI 8201–8300
	# (XIV-41), https 8401–8500, the database 5501–5600. https and http3 share a
	# number on purpose — it is one address, spoken over tcp and over udp.
	XIVI_PORT_BANDS='HTTP_PORT 8000
HTTPS_PORT 8400
HTTP3_PORT 8400
DATABASE_PORT 5500
ADMINER_PORT 8100
MAILPIT_PORT 8200'

	# **Which of them this file actually chose**, as opposed to which arrived
	# already exported. The distinction only matters to the guard, and it matters
	# a lot: an explicitly exported port is somebody who has already been through
	# this and decided, so it is honoured and never questioned. Refusing it would
	# be refusing the escape hatch at exactly the moment it is being used — the
	# `${VAR:-…}` form at the top of this file promises that an explicit value
	# wins, and a check that overrode it would quietly retract the promise.
	XIVI_DERIVED_PORTS=''
	while read -r xivi_port_name xivi_port_band; do
		if [ -z "${!xivi_port_name:-}" ]; then
			printf -v "$xivi_port_name" '%s' "$((xivi_port_band + XIVI_PORT_OFFSET))"
			XIVI_DERIVED_PORTS="${XIVI_DERIVED_PORTS}${xivi_port_name} "
		fi
		# shellcheck disable=SC2163  # the name is from the table above, not from input
		export "${xivi_port_name?}"
	done <<< "$XIVI_PORT_BANDS"
	unset xivi_port_name xivi_port_band
fi

# **Whether the address this checkout claims is actually its own** (XIV-86).
#
# Call this before anything binds a port; it prints a diagnosis on stderr and
# returns non-zero when another compose project is already publishing one of the
# ports derived above. It is a *function* rather than something this file does
# while being sourced, because sourcing happens on every single `bin/compose`
# invocation — `exec`, `logs`, `ps`, `down` — and none of those bind anything.
# The callers decide, and they decide narrowly: `bin/compose` runs it only for
# the subcommands that create containers, `bin/ci` runs it once, immediately
# before its `up`. An ordinary `bin/compose exec php …` pays nothing at all,
# which is not a nicety — a check that costs a tenth of a second on a command
# people type forty times an hour is a check people find a way around.
#
# **Why this is worth doing at all, given Docker already refuses the bind.**
# Because Docker's refusal is about one arbitrary port and says nothing about
# why: "Bind for 0.0.0.0:5552 failed: port is already allocated" names neither
# the checkout that holds it nor the fact that every address this stack derived
# belongs to that same checkout. Worse, the failure is partial. `up` starts
# services
# in dependency order and stops where it first cannot bind, so what you are left
# with is some containers of yours running, some of the neighbour's ports
# unavailable, and an offset that will keep doing this until somebody works out
# that the number came from a checksum of the directory name.
#
# **And `DATABASE_PORT` is the one that can be quiet.** Everything above prints
# `database 127.0.0.1:5552` in `xivi_stack_summary`, which is the address people
# paste into PhpStorm's database panel or into `psql`. When the offset collides,
# that address answers — it is the *other* checkout's Postgres, healthy, with a
# full set of tenant databases in it, and nothing about the connection suggests
# it is the wrong machine. AGENTS.md warns that a bare `docker compose` in a
# worktree runs the suite against the main checkout's tenants; this is the same
# hazard arriving through a different door, for somebody who did the right thing
# and used `bin/compose`. That is what turns "a confusing error" into "worth
# refusing over".
#
# **Detect and refuse, not detect and step.** Walking to the next free offset
# would be more convenient and would give up the property the comment above
# argues for: a checkout's URL would then depend on what else happened to be
# running the moment it started, so the bookmark stops being a bookmark. A
# collision is genuinely exceptional. The right response to an exceptional thing
# is a good error, and the error below is graded on whether it can be acted on
# without thinking — it names the offset, the project holding it, that project's
# directory, and the six exports that move this checkout somewhere free.
xivi_assert_ports_free() {
	# Nothing derived, nothing to check, and both cases are common enough to be
	# worth returning before the `docker ps` below. The main checkout keeps 80,
	# 443, 5432, 8080 and 8025, which no offset in 1–100 can ever produce; and a
	# checkout whose ports all arrived by explicit export has already been
	# decided by a person.
	[ -n "${XIVI_DERIVED_PORTS:-}" ] || return 0

	# One round trip, everything in it. `docker ps` reports only *running*
	# containers, which is exactly the set that holds host ports, and the two
	# compose labels turn a port number into the name of a checkout — the answer
	# the operator actually needs. About 140 ms with a hundred and thirty
	# containers on the daemon, which is the whole reason this is not on the hot
	# path.
	#
	# A failure here is never this function's to report: no Docker, no daemon, an
	# old client without the label formatter. The command about to run will say
	# so far better than a guard can, so this gets out of the way. A guard that
	# can block work it was only ever meant to explain is worse than no guard.
	local snapshot
	snapshot="$(docker ps --format '{{.Label "com.docker.compose.project"}}|{{.Label "com.docker.compose.project.working_dir"}}|{{.Ports}}' 2>/dev/null)" || return 0
	[ -n "$snapshot" ] || return 0

	# One pass over the snapshot, collecting two things.
	#
	# `xivi_busy` is every host port Docker has published, whoever owns it,
	# including our own — used only to find an offset to *suggest*, where our own
	# ports being listed is harmless because our own offset is excluded anyway.
	#
	# `xivi_taken` is the interesting one: a record per port of ours that a
	# *different* compose project is publishing. Docker renders a publication as
	# `0.0.0.0:8452->443/tcp`, so anchoring the match on the colon before and the
	# arrow after cannot match a container-side port (which the arrow precedes)
	# and cannot match a longer number that merely ends in the same digits —
	# `:18452->` does not contain `:8452->`.
	local xivi_busy=' ' xivi_taken='' xivi_project xivi_dir xivi_ports xivi_name xivi_port xivi_word
	while IFS='|' read -r xivi_project xivi_dir xivi_ports; do
		for xivi_word in $xivi_ports; do
			# The pattern is quoted because an unquoted `>` inside a `case` arm is
			# read as a redirection and the script does not parse at all.
			case "$xivi_word" in
				*":"*"->"*)
					xivi_word="${xivi_word%%->*}"
					xivi_busy="${xivi_busy}${xivi_word##*:} "
					;;
			esac
		done

		[ -n "$xivi_project" ] || continue
		[ "$xivi_project" != "$COMPOSE_PROJECT_NAME" ] || continue

		for xivi_name in $XIVI_DERIVED_PORTS; do
			xivi_port="${!xivi_name}"
			case "$xivi_ports" in
				*":$xivi_port->"*) xivi_taken="${xivi_taken}${xivi_name}|${xivi_port}|${xivi_project}|${xivi_dir}"$'\n' ;;
			esac
		done
	done <<< "$snapshot"

	# Spelled out rather than written as `[ -n … ] && report`, which would return
	# the failed test's own status and turn "no collision" into a refusal under
	# `set -e`. The clear case has to return zero, out loud.
	if [ -n "$xivi_taken" ]; then
		xivi_report_port_collision "$xivi_taken" "$xivi_busy"
		return 1
	fi

	return 0
}

# The message, kept apart from the detection so that each is one job. Argument
# one is the newline-separated `NAME|PORT|PROJECT|DIR` list of collisions,
# argument two the space-delimited set of host ports Docker currently publishes.
# Always returns non-zero: it is only ever called when there is something to
# refuse, and the caller propagates that.
xivi_report_port_collision() {
	local taken="$1" busy="$2"
	local name port project dir suggestion=0 candidate free xivi_name xivi_band

	printf '\n' >&2
	printf 'This checkout cannot have the ports it derived: another compose\n' >&2
	printf 'project is already publishing them (XIV-86).\n\n' >&2
	printf '  checkout   %s\n' "$XIVI_CHECKOUT" >&2
	printf '  project    %s\n' "$COMPOSE_PROJECT_NAME" >&2
	printf '  offset     %s, from a checksum of the project name — one of a hundred\n\n' "$XIVI_PORT_OFFSET" >&2

	# The ports first, one line each, then the holders once with the directory
	# that identifies them. The directory is the thing an operator can act on —
	# it is where `bin/compose down` has to be typed — but repeating it beside
	# every port would bury the list it belongs to, and the same project holds
	# all six whenever the cause is two checkouts sharing an offset.
	local holders=''
	while IFS='|' read -r name port project dir; do
		[ -n "$name" ] || continue
		printf '  %-14s %-6s held by %s\n' "$name" "$port" "$project" >&2
		case "$holders" in
			*"[$project]"*) ;;
			*) holders="${holders}[$project]|$dir"$'\n' ;;
		esac
	done <<< "$taken"

	printf '\n' >&2
	while IFS='|' read -r project dir; do
		[ -n "$project" ] || continue
		printf '  that checkout is %s\n' "$dir" >&2
	done <<< "$holders"

	printf '\n' >&2
	printf 'Two checkouts landed on the same offset, which nothing used to notice.\n' >&2
	printf 'Starting anyway is not merely a failed bind: DATABASE_PORT would point\n' >&2
	printf 'at the other checkout, so psql, PhpStorm or anything else reading the\n' >&2
	printf 'address this script prints would be working on its tenant databases.\n' >&2

	# An offset to suggest, from the snapshot already in hand rather than from
	# another round trip. It walks upward from the derived one and wraps, so the
	# answer is deterministic and near the address this checkout has been using —
	# and it is only ever *advice*. The stepping happens in the operator's shell,
	# by an explicit export, which is what keeps the address a bookmark: it moved
	# because somebody moved it, not because of what was running that morning.
	for candidate in $(seq $((XIVI_PORT_OFFSET + 1)) 100) $(seq 1 $((XIVI_PORT_OFFSET - 1))); do
		free=true
		while read -r xivi_name xivi_band; do
			case "$busy" in
				*" $((xivi_band + candidate)) "*) free=false ;;
			esac
		done <<< "$XIVI_PORT_BANDS"

		if [ "$free" = true ]; then
			suggestion=$candidate
			break
		fi
	done

	printf '\n' >&2
	if [ "$suggestion" -eq 0 ]; then
		# A hundred offsets all in use means a hundred stacks, which has never
		# happened and would be its own problem. Say so plainly rather than
		# printing a suggestion that is known to be wrong.
		printf 'Every offset from 1 to 100 has something on it, so there is no free\n' >&2
		printf 'one to suggest. Stop a stack you are finished with, or pick ports by\n' >&2
		printf 'hand and export them.\n' >&2
	else
		printf 'Every port above keeps the `${VAR:-…}` form, so an explicit export wins\n' >&2
		printf 'over the derived value — and is honoured by this check rather than\n' >&2
		printf 'refused, because somebody exporting these has already resolved it.\n' >&2
		printf 'Offset %s is free as far as Docker is concerned; export it and run\n' "$suggestion" >&2
		printf 'again, in the shell you will also run `bin/ci` from:\n\n' >&2
		printf '  export' >&2
		while read -r xivi_name xivi_band; do
			printf ' %s=%s' "$xivi_name" "$((xivi_band + suggestion))" >&2
		done <<< "$XIVI_PORT_BANDS"
		printf '\n' >&2
	fi

	printf '\n' >&2
	printf 'Or stop the other stack, from its own directory:  bin/compose down\n\n' >&2

	return 1
}

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
