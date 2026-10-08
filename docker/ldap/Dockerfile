# OpenLDAP directory standing in for Active Directory in integration tests
# and the demo stack. Seeded from seed.ldif on first start.
FROM debian:wheezy

# The wheezy image only carries archive keys that have expired, so the
# archive source is marked trusted (see SPEC section 7). The image ships
# packages from wheezy/updates (perl-base among them), so the security
# archive is needed too for slapd's perl dependency to resolve.
RUN echo 'deb [trusted=yes] http://archive.debian.org/debian wheezy main' > /etc/apt/sources.list \
 && echo 'deb [trusted=yes] http://archive.debian.org/debian-security wheezy/updates main' >> /etc/apt/sources.list \
 && apt-get -o Acquire::Check-Valid-Until=false update

RUN echo 'slapd slapd/no_configuration boolean false' | debconf-set-selections \
 && echo 'slapd slapd/domain string helpdesk.local' | debconf-set-selections \
 && echo 'slapd shared/organization string Helpdesk' | debconf-set-selections \
 && echo 'slapd slapd/password1 password admin' | debconf-set-selections \
 && echo 'slapd slapd/password2 password admin' | debconf-set-selections \
 && echo 'slapd slapd/backend select HDB' | debconf-set-selections \
 && echo 'slapd slapd/purge_database boolean true' | debconf-set-selections \
 && echo 'slapd slapd/move_old_database boolean true' | debconf-set-selections \
 && echo 'slapd slapd/allow_ldap_v2 boolean false' | debconf-set-selections \
 && DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Check-Valid-Until=false install -y --force-yes \
      --no-install-recommends slapd ldap-utils \
 && rm -rf /var/lib/apt/lists/*

COPY seed.ldif /etc/ldap/seed.ldif
COPY entrypoint.sh /usr/local/bin/ldap-entrypoint
RUN chmod +x /usr/local/bin/ldap-entrypoint

EXPOSE 389

ENTRYPOINT ["/usr/local/bin/ldap-entrypoint"]
