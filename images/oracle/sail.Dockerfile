# Squire's Oracle-ready Laravel Sail runtime.
#
# Layers the shared Oracle Instant Client and oci8 install (install-oracle.sh)
# onto Sail's own runtime image, which scripts/oracle-image-build.sh builds
# first from vendor/laravel/sail/runtimes/<php>. The base image's contract is
# kept exactly as Sail wrote it: containers start as root, SUPERVISOR_PHP_USER
# is already set to sail, and start-container drops each process to the sail
# user, remapping its UID when WWWUSER is set.

ARG SAIL_BASE=sail-base
FROM ${SAIL_BASE}

# BuildKit's predefined argument: the architecture being built (amd64 or
# arm64), which the install script uses to pick the client version.
ARG TARGETARCH

USER root

COPY images/oracle/install-oracle.sh /tmp/install-oracle.sh
RUN sh /tmp/install-oracle.sh && rm -f /tmp/install-oracle.sh

# Library resolution matches Citadel's PHP images: the install script
# registered the client folder with ldconfig; ORACLE_HOME names it and
# LD_LIBRARY_PATH is the fallback. TNS_ADMIN and NLS_LANG stay unset.
ENV ORACLE_HOME=/opt/oracle/instantclient \
    LD_LIBRARY_PATH=/opt/oracle/instantclient

LABEL org.opencontainers.image.description="Laravel Sail runtime with Oracle Instant Client (linux/amd64: 21.7.0.0.0, linux/arm64: 19.32.0.0.0) and the oci8 PHP extension 3.4.1"

# The base image ends as root with ENTRYPOINT ["start-container"]; say so
# explicitly rather than relying on inheritance. start-container needs root
# to run usermod when WWWUSER remaps the sail UID.
ENTRYPOINT ["start-container"]
