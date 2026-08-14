#!/usr/bin/env python3
"""
gNMI Connection Test — three-stage connectivity probe.
Reads config from JSON file (path in sys.argv[1]).
Outputs NDJSON: one JSON object per line, flushed immediately on completion.
Stops at the first failed stage.

Stages:
  1. TCP  — raw socket connect to hostname:port
  2. Transport — gNMI client connect() (standard Capabilities behavior by default)
  3. gNMI — Subscribe probe on /system/state; any gRPC response = connectivity confirmed
"""

import json, os, signal, socket, sys, time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))  # mirrors gnmi_daemon.py line 38

import logging
logging.basicConfig(level=logging.WARNING)  # suppress pygnmi INFO chatter on stderr

from gnmi_tls import apply_tls_cipher_policy, tls_cipher_policy_label


def emit(stage: str, success: bool, message: str, duration_ms: int) -> None:
    print(json.dumps({
        'stage': stage,
        'success': success,
        'message': message,
        'duration_ms': duration_ms,
    }), flush=True)


# ─── Stage 1: TCP ─────────────────────────────────────────────────────────────

def stage_tcp(hostname: str, port: int) -> bool:
    t0 = time.monotonic()
    try:
        conn = socket.create_connection((hostname, port), timeout=5)
        conn.close()
        ms = int((time.monotonic() - t0) * 1000)
        emit('tcp', True, f'Connected to {hostname}:{port} in {ms}ms', ms)
        return True
    except OSError as e:
        ms = int((time.monotonic() - t0) * 1000)
        emit('tcp', False, f'TCP connection failed: {e}', ms)
        return False


# ─── Stage 2: TLS / gNMI connect ─────────────────────────────────────────────

def stage_tls(config: dict):
    """Returns open gNMIclient on success, None on failure."""
    try:
        tls_cipher_policy = apply_tls_cipher_policy(
            os.environ,
            config.get('tls_cipher_policy', 'default'),
            use_tls=config.get('use_tls', True),
        )
    except ValueError as e:
        emit('tls', False, str(e), 0)
        return None

    if config.get('compatibility_mode', 'standard') == 'ciena_saos10':
        from gnmi_collector.pygnmi_patch import patch_pygnmi_for_ciena
        patch_pygnmi_for_ciena()
    from pygnmi.client import gNMIclient

    kwargs: dict = dict(
        target=(config['hostname'], config['port']),
        username=config.get('username', ''),
        password=config.get('password', ''),
        insecure=not config.get('use_tls', True),
        skip_verify=config.get('skip_verify', False),
    )
    # Only pass cert paths when non-empty (mirrors gnmi_daemon.py lines 606-613)
    if config.get('ca_cert_path'):     kwargs['path_root'] = config['ca_cert_path']
    if config.get('client_key_path'):  kwargs['path_key']  = config['client_key_path']
    if config.get('client_cert_path'): kwargs['path_cert'] = config['client_cert_path']
    if config.get('tls_override'):     kwargs['override']  = config['tls_override']

    t0 = time.monotonic()

    def _connect_alarm(signum, frame):
        raise TimeoutError()

    previous_alarm = signal.signal(signal.SIGALRM, _connect_alarm)
    signal.alarm(12)
    try:
        gc = gNMIclient(**kwargs)
        gc.connect()
        signal.alarm(0)
        ms = int((time.monotonic() - t0) * 1000)
        transport = 'TLS handshake' if config.get('use_tls', True) else 'Insecure gNMI transport'
        policy_label = tls_cipher_policy_label(tls_cipher_policy)
        emit('tls', True, f'{transport} OK using {policy_label} ({ms}ms)', ms)
        return gc
    except TimeoutError:
        signal.alarm(0)
        ms = int((time.monotonic() - t0) * 1000)
        emit(
            'tls',
            False,
            'TLS/gNMI setup timed out after 12s — TCP is reachable, but the '
            'gRPC channel or Capabilities RPC did not complete. Check TLS cipher '
            'policy and protocol compatibility mode.',
            ms,
        )
        return None
    except Exception as e:
        signal.alarm(0)
        ms = int((time.monotonic() - t0) * 1000)
        err = str(e)
        if 'UNAUTHENTICATED' in err or 'code: 16' in err:
            emit('tls', False, 'Authentication failed — check username/password', ms)
        elif config.get('use_tls', True) and (
            'certificate' in err.lower() or 'ssl' in err.lower() or
            'handshake' in err.lower() or 'UNAVAILABLE' in err
        ):
            emit('tls', False, 'TLS certificate validation failed — check CA cert path and TLS hostname override', ms)
        elif config.get('use_tls', True) and (
            not err or type(e).__name__ == 'FutureTimeoutError'
        ):
            emit(
                'tls',
                False,
                'Secure gRPC TLS negotiation failed. Check certificates and server '
                'name; if the target only offers older TLS 1.2 ciphers, select '
                'Legacy TLS compatibility and retest.',
                ms,
            )
        else:
            emit('tls', False, f'TLS connection failed: {err[:200]}', ms)
        return None
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous_alarm)


# ─── Stage 3: gNMI probe ──────────────────────────────────────────────────────

def stage_gnmi(gc) -> bool:
    """
    Subscribe to /system/state with encoding='json'.

    Success conditions:
      - sync_response or update received (OpenConfig-compliant device)
      - gRPC code 3 INVALID_ARGUMENT "not supported" (Ciena — path rejected but device responded)
      - gRPC code 12 UNIMPLEMENTED (device responded but operation not supported)

    Failure conditions:
      - gRPC code 16 UNAUTHENTICATED
      - gRPC code 14 UNAVAILABLE / Socket closed (connection dropped)
      - 10-second SIGALRM timeout with no response
    """
    # on_change mode triggers an immediate response (data or rejection) from any gNMI device.
    # sample mode delays the first response by sample_interval — unusable for a quick probe.
    req = {
        'subscription': [{'path': '/system/state', 'mode': 'on_change'}],
        'mode': 'stream',
        'encoding': 'json',
    }
    t0 = time.monotonic()

    def _alarm(signum, frame):
        raise TimeoutError()

    signal.signal(signal.SIGALRM, _alarm)
    signal.alarm(10)

    try:
        gen = gc.subscribe(req)
        for resp in gen:
            ms = int((time.monotonic() - t0) * 1000)
            resp_str = str(resp)
            signal.alarm(0)
            if 'sync_response' in resp_str or 'update' in resp_str:
                emit('gnmi', True, f'gNMI responsive — data/sync received ({ms}ms)', ms)
                try: gc.close()
                except: pass
                return True
            # Ciena returns error responses as stream entries rather than exceptions
            if 'code: 3' in resp_str or 'INVALID_ARGUMENT' in resp_str or 'not supported' in resp_str.lower():
                emit('gnmi', True,
                     f'gNMI responsive — device replied: path not supported '
                     f'(connectivity confirmed, {ms}ms)', ms)
                try: gc.close()
                except: pass
                return True
            if 'code: 12' in resp_str or 'UNIMPLEMENTED' in resp_str:
                emit('gnmi', True,
                     f'gNMI responsive — unimplemented response received '
                     f'(connectivity confirmed, {ms}ms)', ms)
                try: gc.close()
                except: pass
                return True
            if 'code: 16' in resp_str or 'UNAUTHENTICATED' in resp_str:
                emit('gnmi', False, 'Authentication failed — check credentials', ms)
                try: gc.close()
                except: pass
                return False
            if 'code: 14' in resp_str or 'UNAVAILABLE' in resp_str:
                emit('gnmi', False, f'gNMI connection dropped (UNAVAILABLE)', ms)
                try: gc.close()
                except: pass
                return False
            # Unknown entry type — re-arm alarm and keep reading
            signal.alarm(10)
    except TimeoutError:
        ms = int((time.monotonic() - t0) * 1000)
        emit('gnmi', False, 'gNMI probe timed out after 10s — device not responding', ms)
        try: gc.close()
        except: pass
        return False
    except Exception as e:
        signal.alarm(0)
        ms = int((time.monotonic() - t0) * 1000)
        err = str(e)
        if 'UNAUTHENTICATED' in err or 'code: 16' in err:
            emit('gnmi', False, 'Authentication failed — check credentials', ms)
            try: gc.close()
            except: pass
            return False
        elif 'code: 3' in err or 'INVALID_ARGUMENT' in err or 'not supported' in err.lower():
            # Path not supported is a valid gRPC reply — proves full connectivity
            emit('gnmi', True,
                 f'gNMI responsive — device replied: path not supported '
                 f'(connectivity confirmed, {ms}ms)', ms)
            try: gc.close()
            except: pass
            return True
        elif 'code: 12' in err or 'UNIMPLEMENTED' in err:
            emit('gnmi', True,
                 f'gNMI responsive — unimplemented response received '
                 f'(connectivity confirmed, {ms}ms)', ms)
            try: gc.close()
            except: pass
            return True
        elif 'UNAVAILABLE' in err or 'code: 14' in err or 'Socket closed' in err:
            # Device dropped the connection — typically wrong encoding or TLS mismatch
            emit('gnmi', False, f'gNMI connection dropped (UNAVAILABLE): {err[:150]}', ms)
            try: gc.close()
            except: pass
            return False
        else:
            emit('gnmi', False, f'gNMI error: {err[:200]}', ms)
            try: gc.close()
            except: pass
            return False

    signal.alarm(0)
    ms = int((time.monotonic() - t0) * 1000)
    emit('gnmi', False, 'gNMI subscription ended without recognisable response', ms)
    try: gc.close()
    except: pass
    return False


# ─── Entry point ──────────────────────────────────────────────────────────────

def main() -> None:
    if len(sys.argv) < 2:
        emit('error', False, 'Usage: gnmi_connection_test.py <config_json_path>', 0)
        sys.exit(1)

    try:
        with open(sys.argv[1]) as f:
            config = json.load(f)
    except Exception as e:
        emit('error', False, f'Cannot read config file: {e}', 0)
        sys.exit(1)

    hostname = config.get('hostname', '')
    port = int(config.get('port', 9339))

    if not stage_tcp(hostname, port):
        sys.exit(1)

    gc = stage_tls(config)
    if gc is None:
        sys.exit(1)

    if not stage_gnmi(gc):
        sys.exit(1)


if __name__ == '__main__':
    main()
