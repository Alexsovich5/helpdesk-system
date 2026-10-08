#!/bin/sh
# Loads seed.ldif into the directory the first time the container starts,
# then runs slapd in the foreground.
set -e

MARKER=/var/lib/ldap/.seeded

if [ ! -f "$MARKER" ]; then
	slapadd -n 1 -l /etc/ldap/seed.ldif
	chown -R openldap:openldap /var/lib/ldap
	touch "$MARKER"
fi

exec slapd -h 'ldap:///' -u openldap -g openldap -d 0
