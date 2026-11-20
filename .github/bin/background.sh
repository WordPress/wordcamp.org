#!/usr/bin/env bash
#
# Runs a setup command in the background, so the steps after it don't wait for it.
#
#   background.sh start <name> <command...>
#   background.sh wait <name>
#
# `wait` replays the command's output and exits with its exit code.

set -euo pipefail

dir="${RUNNER_TEMP:-/tmp}/background"
mkdir -p "$dir"

action=$1
name=$2
shift 2

case "$action" in
	start)
		: > "$dir/$name.log"

		# Detached from the step's output, or the runner would hold the step open until the command ends.
		(
			set +e
			"$@" > "$dir/$name.log" 2>&1
			echo $? > "$dir/$name.exit"
		) < /dev/null > /dev/null 2>&1 &
		echo $! > "$dir/$name.pid"
		;;

	wait)
		# Streams the output until the command ends, so a step that times out still shows where it got stuck.
		tail -n +1 -f --pid="$( cat "$dir/$name.pid" )" "$dir/$name.log"
		exit "$( cat "$dir/$name.exit" )"
		;;

	*)
		echo "Unknown action: $action" >&2
		exit 1
		;;
esac
