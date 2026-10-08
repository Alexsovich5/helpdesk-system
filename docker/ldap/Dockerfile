# OpenLDAP directory standing in for Active Directory in integration tests
# and the demo stack. Seeded from seed.ldif on first start. Built with the
# repository root as context (docker-compose.yml).

# Stage 1: download and verify slapd and its dependencies on a current
# Debian, over HTTPS from snapshot.debian.org (see
# docker/debs/fetch_verified_debs.sh).
FROM debian:bookworm-slim@sha256:7c7b2c966bc9ee8cedfeef67e0e279108992c77681fa595db4a9d65c06ccc587 AS debs
RUN ok=; for i in 1 2 3; do \
        if apt-get update \
           && apt-get install -y --no-install-recommends ca-certificates curl gpgv debian-archive-keyring; \
        then ok=1; break; fi; \
        sleep 10; \
    done; [ -n "$ok" ]; rm -rf /var/lib/apt/lists/*
COPY docker/debs/fetch_verified_debs.sh docker/debs/ldap.list /build/
RUN sh /build/fetch_verified_debs.sh /build/ldap.list /debs

# Stage 2: the directory image. dpkg installs only the verified files.
FROM debian:wheezy@sha256:2259b099d947443e44bbd1c94967c785361af8fd22df48a08a3942e2d5630849

COPY --from=debs /debs /tmp/debs
RUN echo 'slapd slapd/no_configuration boolean false' | debconf-set-selections \
 && echo 'slapd slapd/domain string helpdesk.local' | debconf-set-selections \
 && echo 'slapd shared/organization string Helpdesk' | debconf-set-selections \
 && echo 'slapd slapd/password1 password admin' | debconf-set-selections \
 && echo 'slapd slapd/password2 password admin' | debconf-set-selections \
 && echo 'slapd slapd/backend select HDB' | debconf-set-selections \
 && echo 'slapd slapd/purge_database boolean true' | debconf-set-selections \
 && echo 'slapd slapd/move_old_database boolean true' | debconf-set-selections \
 && echo 'slapd slapd/allow_ldap_v2 boolean false' | debconf-set-selections \
 && rm -f /etc/apt/sources.list /etc/apt/sources.list.d/* \
 && rm -rf /var/lib/apt/lists/* \
 && cd /tmp/debs \
 && sha256sum -c SHA256SUMS \
 && DEBIAN_FRONTEND=noninteractive dpkg -i $(awk '{ print $2 }' SHA256SUMS) \
 && cd / && rm -rf /tmp/debs \
 && cp -a /var/lib/ldap /usr/local/share/ldap-pristine

COPY docker/ldap/seed.ldif /etc/ldap/seed.ldif
COPY docker/ldap/entrypoint.sh /usr/local/bin/ldap-entrypoint
RUN chmod +x /usr/local/bin/ldap-entrypoint

EXPOSE 389

ENTRYPOINT ["/usr/local/bin/ldap-entrypoint"]
