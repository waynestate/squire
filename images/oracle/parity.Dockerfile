# Squire's Oracle-ready parity image: a Citadel PHP app's extensions, plus
# Oracle, for local work.
#
# Built from the official php:<line>-cli-bookworm base (pinned per PHP line,
# 8.3 to 8.5, in images/oracle/php-images.txt), with the extensions Citadel apps use,
# Composer and git, then the shared Oracle Instant Client and oci8 install
# (install-oracle.sh) on top. WELD's compose.oracle.yaml points its services
# at this flavour; nothing here touches Citadel.
#
# Containers run as the non-root "app" user by default. Its UID and GID are
# build arguments (ADR-0010 wants an arbitrary non-root UID), and an
# orchestrator can still override them at run time with --user or compose
# `user:`. The image writes nothing outside /tmp and its bind mounts.

# The base is pinned by its multi-arch list digest. The build scripts pass the pin for the PHP line they
# build from images/oracle/php-images.txt; the default here is that file's 8.4 row, so a plain docker build gets it.
ARG PHP_IMAGE=docker.io/library/php:8.4.27-cli-bookworm@sha256:b3e907a1108629c562c4bb313ce3449e34d49991f6bb2487913f6146f47200fe
FROM ${PHP_IMAGE}

# BuildKit's predefined argument: the architecture being built (amd64 or
# arm64), which the install script uses to pick the client version.
ARG TARGETARCH

ENV ORACLE_HOME=/opt/oracle/instantclient \
    LD_LIBRARY_PATH=/opt/oracle/instantclient

LABEL org.opencontainers.image.description="PHP CLI with the extensions Citadel apps use, Oracle Instant Client (linux/amd64: 21.7.0.0.0, linux/arm64: 19.32.0.0.0) and the oci8 PHP extension 3.4.1"

# The extensions Citadel apps use, installed by the pinned mlocati installer
# (it also handles the pecl-based ones, redis and imagick). pcntl is not
# compiled into the official CLI image, so it goes in the list like the rest.
COPY --from=docker.io/mlocati/php-extension-installer:2.13.2@sha256:4fd45396c16685266d32bf147a24ecd5e81029d3820295895bf4a90131190ac9 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl ldap soap zip gd pdo_mysql mysqli bcmath pcntl exif redis imagick

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

COPY --from=docker.io/library/composer:2.11.2@sha256:5bcb28b8d0edc4091125f8218f9d088f998bdfc843f8fbb9a4beb4dcd8625684 /usr/bin/composer /usr/local/bin/composer

COPY images/oracle/install-oracle.sh /tmp/install-oracle.sh
RUN sh /tmp/install-oracle.sh && rm -f /tmp/install-oracle.sh

# The default runtime identity: non-root, re-pinnable at build time.
ARG APP_UID=1000
ARG APP_GID=1000
RUN groupadd --gid "$APP_GID" app \
    && useradd --uid "$APP_UID" --gid "$APP_GID" -m -s /usr/sbin/nologin app
USER app
