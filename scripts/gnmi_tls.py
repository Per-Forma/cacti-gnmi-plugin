"""Vendor-neutral, per-process gRPC TLS cipher policy helpers.

The gRPC C core reads ``GRPC_SSL_CIPHER_SUITES`` during initialization.  This
module deliberately imports neither grpc nor pygnmi so callers can configure a
fresh child process before either library is loaded.
"""

from collections.abc import MutableMapping


DEFAULT_TLS_CIPHER_POLICY = "default"
LEGACY_TLS_CIPHER_POLICY = "legacy_compatibility"
SUPPORTED_TLS_CIPHER_POLICIES = frozenset({
    DEFAULT_TLS_CIPHER_POLICY,
    LEGACY_TLS_CIPHER_POLICY,
})

# Prefer the modern suite accepted by the validated target and allow the
# legacy TLS 1.2 CBC/SHA-1 suite only for explicitly opted-in devices.
LEGACY_COMPATIBILITY_CIPHER_SUITES = (
    "ECDHE-RSA-AES256-GCM-SHA384:"
    "ECDHE-RSA-AES128-SHA"
)


def validate_tls_cipher_policy(value: object) -> str:
    """Return an allowlisted policy name or fail closed."""
    if value not in SUPPORTED_TLS_CIPHER_POLICIES:
        raise ValueError(f"Invalid tls_cipher_policy: {value}")
    return str(value)


def apply_tls_cipher_policy(
    environment: MutableMapping[str, str],
    policy: object = DEFAULT_TLS_CIPHER_POLICY,
    *,
    use_tls: bool = True,
) -> str:
    """Apply a deterministic cipher policy to a process environment.

    The default and plaintext modes explicitly remove any inherited global
    override so an operator's temporary container-wide workaround cannot
    silently weaken unrelated device daemons.
    """
    normalized = validate_tls_cipher_policy(policy)
    if use_tls and normalized == LEGACY_TLS_CIPHER_POLICY:
        environment["GRPC_SSL_CIPHER_SUITES"] = LEGACY_COMPATIBILITY_CIPHER_SUITES
    else:
        environment.pop("GRPC_SSL_CIPHER_SUITES", None)
    return normalized


def tls_cipher_policy_label(policy: object) -> str:
    """Return a user-facing label for logs and diagnostics."""
    normalized = validate_tls_cipher_policy(policy)
    if normalized == LEGACY_TLS_CIPHER_POLICY:
        return "Legacy TLS compatibility"
    return "gRPC defaults"
