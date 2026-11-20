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
		# Detached from the step's output, or the runner would hold the step open until the command ends.
		# The exit file is renamed into place, so `wait` never reads it half written.
		(
			set +e
			"$@" > "$dir/$name.log" 2>&1
			echo $? > "$dir/$name.exit.tmp"
			mv "$dir/$name.exit.tmp" "$dir/$name.exit"
		) < /dev/null > /dev/null 2>&1 &
		;;

	wait)
		# Bounded by the step's or the job's `timeout-minutes`.
		until [ -f "$dir/$name.exit" ]; do
			sleep 1
		done

		cat "$dir/$name.log"
		exit "$( cat "$dir/$name.exit" )"
		;;

	*)
		echo "Unknown action: $action" >&2
		exit 1
		;;
esac
