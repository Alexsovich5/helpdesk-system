#!/bin/sh
# Downloads the .debs named in a manifest (app.list, ldap.list) from
# snapshot.debian.org and verifies them before any image installs them:
#
#   1. Release and Release.gpg are fetched over HTTPS only, redirects
#      included, and the signature is checked with gpgv against the Debian
#      archive keyrings, including the removed-keys keyring that holds the
#      stretch and wheezy keys. An expired key does not skip the check: gpgv
#      has to report a VALIDSIG and no bad, unverifiable, missing-key or
#      revoked signature. A copy of Release with one byte added must fail
#      the same check, which shows gpgv is really checking.
#   2. The Packages index must match the SHA256 and size listed in Release.
#   3. Each .deb must match the SHA256 and size in that Packages index.
#
# The verified files end up in OUTDIR with a SHA256SUMS list that keeps the
# manifest order, which is the order dpkg installs them in.
#
# Usage: fetch_verified_debs.sh MANIFEST OUTDIR
set -eu

ARCH=amd64
KEYRING_ARGS="--keyring /usr/share/keyrings/debian-archive-keyring.gpg
              --keyring /usr/share/keyrings/debian-archive-removed-keys.gpg"

die() {
    echo "fetch_verified_debs: $*" >&2
    exit 1
}

[ $# -eq 2 ] || die "usage: fetch_verified_debs.sh MANIFEST OUTDIR"
MANIFEST=$1
OUT=$2
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$OUT"
: >"$OUT/SHA256SUMS"

# fetch URL DEST: HTTPS only, up to three attempts
fetch() {
    case $1 in
        https://*) ;;
        *) die "refusing a URL that is not HTTPS: $1" ;;
    esac
    attempt=1
    until curl -fsSL --proto '=https' --proto-redir '=https' --tlsv1.2 \
            --connect-timeout 30 --max-time 900 -o "$2" "$1"; do
        [ "$attempt" -lt 3 ] || die "download failed: $1"
        attempt=$((attempt + 1))
        sleep 10
    done
}

# check_file FILE SHA256 SIZE LABEL
check_file() {
    actual=$(sha256sum "$1" | cut -d' ' -f1)
    [ "$actual" = "$2" ] || die "$4: SHA256 is $actual, expected $2"
    size=$(wc -c <"$1" | tr -d ' ')
    [ "$size" = "$3" ] || die "$4: size is $size, expected $3"
}

# signature_ok RELEASE SIGNATURE STATUSFILE: gpgv verdict as exit status
signature_ok() {
    # shellcheck disable=SC2086
    gpgv --status-fd 3 $KEYRING_ARGS "$2" "$1" 3>"$3" >"$3.log" 2>&1 \
        && grep -q '^\[GNUPG:\] VALIDSIG ' "$3" \
        && ! grep -Eq '^\[GNUPG:\] (BADSIG|ERRSIG|NO_PUBKEY|REVKEYSIG) ' "$3"
}

# release_entry RELEASE PATH: print "SHA256 SIZE" from the SHA256 section
release_entry() {
    awk -v path="$2" '
        /^SHA256:/ { in_sha = 1; next }
        /^[^ ]/ { in_sha = 0 }
        in_sha && $3 == path { print $1, $2; found = 1; exit }
        END { if (!found) exit 1 }' "$1"
}

# fetch_repo NAME BASE_URL DIST COMPONENT
fetch_repo() {
    dir=$WORK/$1
    mkdir -p "$dir"
    url=$2/dists/$3
    fetch "$url/Release" "$dir/Release"
    fetch "$url/Release.gpg" "$dir/Release.gpg"

    if ! signature_ok "$dir/Release" "$dir/Release.gpg" "$dir/status"; then
        cat "$dir/status.log" "$dir/status" >&2
        die "$1: Release signature does not verify"
    fi
    cp "$dir/Release" "$dir/Release.modified"
    echo >>"$dir/Release.modified"
    if signature_ok "$dir/Release.modified" "$dir/Release.gpg" "$dir/status.modified"; then
        die "$1: gpgv accepted a modified Release"
    fi
    grep -qx "Codename: ${3%%/*}" "$dir/Release" \
        || die "$1: Release is not for ${3%%/*}"

    index=$4/binary-$ARCH/Packages
    entry=$(release_entry "$dir/Release" "$index.gz") \
        || die "$1: $index.gz is not listed in Release"
    fetch "$url/$index.gz" "$dir/Packages.gz"
    # shellcheck disable=SC2086
    check_file "$dir/Packages.gz" $entry "$1 $index.gz"
    gzip -dc "$dir/Packages.gz" >"$dir/Packages"
    entry=$(release_entry "$dir/Release" "$index") \
        || die "$1: $index is not listed in Release"
    # shellcheck disable=SC2086
    check_file "$dir/Packages" $entry "$1 $index"
    echo "$2" >"$dir/base"
    echo "verified $1: $url ($index)"
}

# fetch_pkg NAME VERSION REPO
fetch_pkg() {
    dir=$WORK/$3
    [ -f "$dir/Packages" ] || die "$1: repo $3 is not declared before it"
    found=$(awk -v pkg="$1" -v ver="$2" '
        BEGIN { RS = "" }
        {
            p = v = f = h = z = ""
            n = split($0, lines, "\n")
            for (i = 1; i <= n; i++) {
                if (lines[i] ~ /^Package: /) p = substr(lines[i], 10)
                else if (lines[i] ~ /^Version: /) v = substr(lines[i], 10)
                else if (lines[i] ~ /^Filename: /) f = substr(lines[i], 11)
                else if (lines[i] ~ /^SHA256: /) h = substr(lines[i], 9)
                else if (lines[i] ~ /^Size: /) z = substr(lines[i], 7)
            }
            if (p == pkg && v == ver) { print f, h, z; count++ }
        }
        END { if (count != 1) exit 1 }' "$dir/Packages") \
        || die "$1 $2: not exactly one entry in the $3 Packages index"
    # shellcheck disable=SC2086
    set -- "$1" "$2" "$3" $found
    [ $# -eq 6 ] || die "$1 $2: incomplete Packages entry"
    case $4 in
        *..* | *[!A-Za-z0-9+.~_/-]*) die "$1 $2: unsafe Filename $4" ;;
        pool/*.deb) ;;
        *) die "$1 $2: unexpected Filename $4" ;;
    esac
    deb=$OUT/${4##*/}
    fetch "$(cat "$dir/base")/$4" "$deb"
    check_file "$deb" "$5" "$6" "$1 $2"
    (cd "$OUT" && sha256sum "${4##*/}") >>"$OUT/SHA256SUMS"
    echo "verified $1 $2"
}

exec 4<"$MANIFEST"
while read -r kind a b c d rest <&4; do
    case $kind in
        '' | '#'*) continue ;;
        repo) fetch_repo "$a" "$b" "$c" "$d" ;;
        pkg) fetch_pkg "$a" "$b" "$c" ;;
        *) die "unknown manifest line: $kind $a $b $c $d $rest" ;;
    esac
done
exec 4<&-
[ -s "$OUT/SHA256SUMS" ] || die "manifest lists no packages"
