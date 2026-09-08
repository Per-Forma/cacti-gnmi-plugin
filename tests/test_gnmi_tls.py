"""Tests for vendor-neutral, process-scoped gRPC TLS cipher policies."""

import pytest

from scripts.gnmi_tls import (
    LEGACY_COMPATIBILITY_CIPHER_SUITES,
    apply_tls_cipher_policy,
    tls_cipher_policy_label,
    validate_tls_cipher_policy,
)


def test_default_policy_removes_inherited_override():
    environment = {"GRPC_SSL_CIPHER_SUITES": "inherited", "PATH": "/bin"}

    assert apply_tls_cipher_policy(environment, "default") == "default"
    assert "GRPC_SSL_CIPHER_SUITES" not in environment
    assert environment["PATH"] == "/bin"


def test_legacy_policy_sets_allowlisted_modern_first_cipher_list():
    environment = {}

    assert (
        apply_tls_cipher_policy(environment, "legacy_compatibility")
        == "legacy_compatibility"
    )
    assert environment["GRPC_SSL_CIPHER_SUITES"] == LEGACY_COMPATIBILITY_CIPHER_SUITES
    assert LEGACY_COMPATIBILITY_CIPHER_SUITES.startswith(
        "ECDHE-RSA-AES256-GCM-SHA384:"
    )
    assert LEGACY_COMPATIBILITY_CIPHER_SUITES.endswith("ECDHE-RSA-AES128-SHA")


def test_plaintext_transport_never_retains_cipher_override():
    environment = {"GRPC_SSL_CIPHER_SUITES": "inherited"}

    apply_tls_cipher_policy(
        environment,
        "legacy_compatibility",
        use_tls=False,
    )

    assert "GRPC_SSL_CIPHER_SUITES" not in environment


def test_unknown_policy_fails_closed():
    with pytest.raises(ValueError, match="Invalid tls_cipher_policy"):
        validate_tls_cipher_policy("arbitrary-cipher-string")
    with pytest.raises(ValueError, match="Invalid tls_cipher_policy"):
        apply_tls_cipher_policy({}, None)


def test_policy_labels_are_user_facing():
    assert tls_cipher_policy_label("default") == "gRPC defaults"
    assert (
        tls_cipher_policy_label("legacy_compatibility")
        == "Legacy TLS compatibility"
    )
