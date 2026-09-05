#!/usr/bin/env bash
#
# End-to-end test of the email body parsing (quoted reply handling).
#
# Generates reply emails for an existing issue, injects them through the real
# EmailReporting pipeline inside the Mantis container, then reads back the
# created notes and checks them.
#
# Usage:
#   plugins/EmailReporting/tests/run_e2e.sh --issue=1443 --from=you@imatic.cz
#
# Options (defaults in brackets):
#   --issue=<id>        target issue, notes are added to it (required)
#   --from=<address>    sender, must not be a disposable domain [tester@imatic.cz]
#   --container=<name>  Mantis web container [mantis-web]
#   --db=<name>         Postgres container [mantis-postgres]
#   --db-name=<name>    database [bugtracker]
#   --db-user=<name>    database user [postgres]
#   --label=<text>      text before the issue id in the subject [Mantis]
#   --dry-run           parse and route only, create no notes

set -euo pipefail

ISSUE=""
FROM="tester@imatic.cz"
CONTAINER="mantis-web"
DB_CONTAINER="mantis-postgres"
DB_NAME="bugtracker"
DB_USER="postgres"
LABEL="Mantis"
DRY_RUN=""

for arg in "$@"; do
	case "$arg" in
		--issue=*)     ISSUE="${arg#*=}" ;;
		--from=*)      FROM="${arg#*=}" ;;
		--container=*) CONTAINER="${arg#*=}" ;;
		--db=*)        DB_CONTAINER="${arg#*=}" ;;
		--db-name=*)   DB_NAME="${arg#*=}" ;;
		--db-user=*)   DB_USER="${arg#*=}" ;;
		--label=*)     LABEL="${arg#*=}" ;;
		--dry-run)     DRY_RUN="--dry-run" ;;
		*) echo "Unknown option: $arg" >&2; exit 1 ;;
	esac
done

if [ -z "$ISSUE" ]; then
	echo "Usage: $0 --issue=<id> [--from=<address>] [--dry-run]" >&2
	exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EML_DIR="$(mktemp -d)"
trap 'rm -rf "$EML_DIR"' EXIT

echo "Issue:     #$ISSUE"
echo "Sender:    $FROM"
echo "Container: $CONTAINER"
echo

php "$SCRIPT_DIR/gen_eml.php" --issue="$ISSUE" --out="$EML_DIR" --label="$LABEL" --from="$FROM" >/dev/null
docker cp "$EML_DIR/." "$CONTAINER:/tmp/erp_eml_test" >/dev/null

INJECTOR="plugins/ImaticEmailReporting/tests/inject_email.php"
if ! docker exec "$CONTAINER" test -f "/var/www/html/$INJECTOR"; then
	echo "Injector not found in the container: $INJECTOR" >&2
	exit 1
fi

# Notes already present, so only the new ones are inspected afterwards.
note_count() {
	docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -At \
		-c "SELECT count(*) FROM mantis_bugnote_table WHERE bug_id = $ISSUE;"
}

note_text() {
	docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -At \
		-c "SELECT t.note FROM mantis_bugnote_table b
		    JOIN mantis_bugnote_text_table t ON t.id = b.bugnote_text_id
		    WHERE b.bug_id = $ISSUE ORDER BY b.id DESC LIMIT 1;"
}

# name | texts that must be present (";" separated) | text that must be absent
CASES=(
	"q1_trailing_quote|nasadit v piatok|POVODNA SPRAVA"
	"q2_inline_quote|Kedy bude hotovy deploy?;A co migracie DB?;Diky|"
	"q3_inline_plus_trailing|Kedy bude deploy?;A co DB?|CELA POVODNA SPRAVA"
	"q4_html_blockquote|Kedy bude hotovy deploy?|"
)

FAILED=0

for entry in "${CASES[@]}"; do
	IFS='|' read -r NAME EXPECT_PRESENT EXPECT_ABSENT <<<"$entry"

	echo "===== $NAME ====="

	BEFORE=$(note_count)

	docker exec -w /var/www/html "$CONTAINER" \
		php "$INJECTOR" --eml="/tmp/erp_eml_test/$NAME.eml" $DRY_RUN 2>&1 \
		| grep -E "Message:|ERROR|rejected" || true

	if [ -n "$DRY_RUN" ]; then
		echo "(dry run, no note created)"
		echo
		continue
	fi

	AFTER=$(note_count)
	if [ "$AFTER" = "$BEFORE" ]; then
		echo "FAIL: no note was created"
		FAILED=$((FAILED + 1))
		echo
		continue
	fi

	NOTE="$(note_text)"
	echo "--- note ---"
	echo "$NOTE"
	echo "------------"

	OK=1
	if [ -n "$EXPECT_PRESENT" ]; then
		IFS=';' read -ra NEEDLES <<<"$EXPECT_PRESENT"
		for needle in "${NEEDLES[@]}"; do
			if ! grep -qF "$needle" <<<"$NOTE"; then
				echo "FAIL: expected text missing: $needle"
				OK=0
			fi
		done
	fi
	if [ -n "$EXPECT_ABSENT" ] && grep -qF "$EXPECT_ABSENT" <<<"$NOTE"; then
		echo "FAIL: text that should have been stripped is present: $EXPECT_ABSENT"
		OK=0
	fi

	if [ "$NAME" = "q4_html_blockquote" ]; then
		# HTML is only converted to markdown when MantisCoreFormatting is
		# loaded (mail_api.php). Without it the raw HTML ends up in the note
		# and the quote logic never sees a "> " line.
		if grep -qF "<blockquote>" <<<"$NOTE"; then
			echo "INFO: raw HTML in the note - MantisCoreFormatting is not loaded, HTML quotes are not processed"
			OK=1
		fi
	fi

	[ "$OK" = 1 ] && echo "PASS" || FAILED=$((FAILED + 1))
	echo
done

docker exec "$CONTAINER" rm -rf /tmp/erp_eml_test

if [ -n "$DRY_RUN" ]; then
	echo "Dry run finished."
	exit 0
fi

echo "Notes were added to issue #$ISSUE - review and delete them when done."
[ "$FAILED" = 0 ] && echo "All e2e checks passed" || echo "$FAILED e2e check(s) failed"
exit "$FAILED"
