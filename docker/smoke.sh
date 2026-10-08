#!/bin/sh
# HTTP smoke test against the running app container. Signs in as the
# directory user "bob" through the LDAP simulator and opens the ticket list
# and the reports page. Exits non-zero on the first unexpected response.
#
# Usage: smoke.sh [base-url]   (default http://app, the compose service)

set -eu

BASE=${1:-http://app}
WORK=$(mktemp -d)
JAR="$WORK/cookies"
BODY="$WORK/body"
trap 'rm -rf "$WORK"' EXIT

fail() {
	echo "FAIL: $*" >&2
	exit 1
}

# request METHOD PATH EXPECTED_STATUS [curl args...]
request() {
	method=$1 path=$2 expected=$3
	shift 3
	status=$(curl -sS -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" -X "$method" "$@" "$BASE$path") \
		|| fail "$method $path: curl error"
	[ "$status" = "$expected" ] || fail "$method $path returned $status, expected $expected"
	echo "ok  $method $path -> $status"
}

request GET /login 200
token=$(sed -n 's/.*name="_token" type="hidden" value="\([^"]*\)".*/\1/p' "$BODY" | head -n 1)
[ -n "$token" ] || token=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$BODY" | head -n 1)
[ -n "$token" ] || fail "no CSRF token on the login page"

request POST /login 302 --data-urlencode "_token=$token" \
	--data-urlencode "username=bob" --data-urlencode "password=password"
if grep -q 'url=[^"]*/login' "$BODY"; then fail "POST /login redirected back to the login page"; fi

request GET /tickets 200
grep -q 'HD-' "$BODY" || fail "GET /tickets lists no ticket numbers"

request GET /reports 200

echo "smoke test passed"
