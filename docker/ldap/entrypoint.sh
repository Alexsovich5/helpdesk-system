#!/bin/sh
# Loads seed.ldif into the directory, then runs slapd in the foreground.
#
# The directory content is defined by seed.ldif alone. The marker file
# holds the SHA256 of the seed that was loaded; when seed.ldif changes (a
# rebuilt image), the database is reset to the empty one the slapd package
# created and the new seed is loaded, so an existing ldap-data volume never
# serves stale test users or groups.
set -e

MARKER=/var/lib/ldap/.seeded
SEED=/etc/ldap/seed.ldif
PRISTINE=/usr/local/share/ldap-pristine

want=$(sha256sum "$SEED" | cut -d' ' -f1)
have=$(cat "$MARKER" 2>/dev/null || true)

if [ "$want" != "$have" ]; then
	# A marker means an earlier seed is loaded: start again from the
	# package's empty database.
	if [ -e "$MARKER" ]; then
		find /var/lib/ldap -mindepth 1 -delete
		cp -a "$PRISTINE"/. /var/lib/ldap/
	fi
	slapadd -n 1 -l "$SEED"
	chown -R openldap:openldap /var/lib/ldap
	echo "$want" > "$MARKER"
fi

exec slapd -h 'ldap:///' -u openldap -g openldap -d 0
