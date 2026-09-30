# Private database transport acceptance

`accept_transport.sh DISPOSABLE_CACTI_CONTAINER` requires a labeled compatibility
installation that has already passed archive acceptance. It copies only that
installation's packaged scripts/includes and container-built virtual environment
into a fresh native Docker volume, protects code as root, and tests as `www-data`.

It creates private MariaDB 10.6.21 instances on port 4406 and with networking
disabled, private socket volumes, and ephemeral synthetic TLS certificates.
Tests compare actual selected PDO exports and the standalone PHP resolver with
PyMySQL 1.2.0, asserting the exact database marker and negotiated session TLS.
Cases include exact quoted/Unicode and empty passwords, nondefault localhost,
verified/encryption-only TLS, trust/expiry/hostname failures, mutual TLS identities,
explicit TLS disablement, sockets/local-only grants, relative certificates,
unreadable material and encrypted keys. Socket TLS and encryption-only PDO
limitations are recorded explicitly. The Python unit test also observes that a
server without TLS receives no authentication packet when TLS is required.

The native container then installs Cacti and the plugin using its existing
protected venv, and runs the full 12-source collector/graph/deadline fixture
against both the verified mutual-TLS endpoint (with a quoted Unicode password)
and the socket-only database. Code, script directories and venv are confirmed
unwritable by the service account; only runtime and Cacti-owned paths are writable.

Output contains case names and booleans/categories or nonsecret timing counts. The EXIT trap removes
all newly created containers, volumes, synthetic keys and scratch files. No
existing Cacti deployment or service is modified. CI runs this against the
accepted archive on Cacti 1.2.25 and 1.2.31.
