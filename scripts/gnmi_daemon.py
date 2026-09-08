#!/usr/bin/env python3
"""
gNMI Daemon - Maintains persistent gNMI subscriptions for Cacti plugin.

This daemon:
- Maintains a persistent gNMI subscription to a single device
- Samples telemetry every 5 seconds internally
- Buffers samples and writes to JSON storage every 10 seconds
- Provides health check mechanism for Cacti poller hook
- Handles reconnection with exponential backoff
- Polls database for configuration changes
- Gracefully shuts down on SIGTERM/SIGINT

Usage:
    gnmi_daemon.py --device-id <id> [--config-file <path>]
    gnmi_daemon.py --device-id <id> --check-health

Architecture:
    gNMI Device (5s) → Daemon (buffer) → JSON (10s) → Poller Bridge → Cacti → RRD
"""

import argparse
import fcntl
import json
import logging
import os
import re
import signal
import sys
import tempfile
import time
import math
from collections import deque
from datetime import datetime, timezone
from pathlib import Path
from queue import Empty, Queue
from threading import Event, Thread
from typing import Dict, Optional, Any, Deque, List, Set

# Add parent directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from gnmi_runtime import chmod_private_file, ensure_private_dir, storage_dir as default_storage_dir
from gnmi_tls import (
    apply_tls_cipher_policy,
    tls_cipher_policy_label,
    validate_tls_cipher_policy,
)

# gRPC reads GRPC_SSL_CIPHER_SUITES while its native runtime initializes.  Keep
# these imports lazy so direct gnmi_daemon.py invocation can apply the selected
# per-device policy before importing pygnmi/grpc.  gnmi_daemon_ctl.py also sets
# the child environment before exec, providing the same guarantee normally.
gNMIclient = None
telemetryParser = None
patch_pygnmi_for_ciena = None

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger('gnmi_daemon')


def initialize_gnmi_runtime(config: Dict[str, Any]) -> str:
    """Apply TLS policy before the first pygnmi/grpc import in this process."""
    global gNMIclient, telemetryParser, patch_pygnmi_for_ciena

    policy = apply_tls_cipher_policy(
        os.environ,
        config.get('tls_cipher_policy', 'default'),
        use_tls=bool(config.get('use_tls', not config.get('insecure', False))),
    )

    if gNMIclient is None:
        from gnmi_collector.pygnmi_patch import patch_pygnmi_for_ciena as ciena_patch
        from pygnmi.client import gNMIclient as client_class, telemetryParser as parser

        gNMIclient = client_class
        telemetryParser = parser
        patch_pygnmi_for_ciena = ciena_patch

    return policy


def _telemetry_leaf_name(path: Any) -> str:
    """Return an unqualified leaf name from a pygnmi path or JSON_IETF key."""
    leaf = str(path or '').rsplit('/', 1)[-1]
    return leaf.rsplit(':', 1)[-1]


def _flatten_telemetry_update(path: str, value: Any) -> Dict[str, Any]:
    """Flatten scalar or container-valued pygnmi updates to leaf/value pairs.

    Some targets send one update per leaf. SR Linux instead returns the
    subscribed ``statistics`` container as a JSON_IETF object in a single
    update. The daemon's configured metric filter operates on leaf names, so
    normalize both response shapes before applying that filter.
    """
    if not isinstance(value, dict):
        return {_telemetry_leaf_name(path): value}

    flattened: Dict[str, Any] = {}
    for child_path, child_value in value.items():
        if isinstance(child_value, dict):
            flattened.update(_flatten_telemetry_update(str(child_path), child_value))
        else:
            flattened[_telemetry_leaf_name(child_path)] = child_value
    return flattened


def compute_buffer_max_samples(poller_interval: int, sample_interval: int = 5) -> int:
    """
    Compute the maximum sample buffer size for a given poller interval.

    Formula: ceil((poller_interval * 1.5) / sample_interval)
    Clamped to [4, 120] to prevent degenerate buffer sizes.

    The 1.5× factor provides a safety margin so the buffer always covers
    more than one complete poller cycle, even under minor timing variance.

    Args:
        poller_interval: Cacti poller interval in seconds (e.g. 10, 60, 300).
        sample_interval: gNMI sample rate in seconds (default 5, fixed by daemon).

    Returns:
        Integer max_samples value, clamped to [4, 120].
    """
    raw = math.ceil((poller_interval * 1.5) / sample_interval)
    return max(4, min(120, raw))


def load_config(config_json: str) -> Dict[str, Any]:
    """
    Load daemon configuration from JSON.

    Args:
        config_json: JSON string containing daemon configuration

    Returns:
        Dict containing validated configuration

    Raises:
        ValueError: If configuration is invalid or missing required fields
    """
    try:
        config = json.loads(config_json)
    except json.JSONDecodeError as e:
        raise ValueError(f"Invalid JSON configuration: {e}")

    # Validate required fields
    required_fields = ['device_id', 'hostname', 'port', 'username', 'password', 'subscriptions']
    for field in required_fields:
        if field not in config:
            raise ValueError(f"Missing required config field: {field}")

    compatibility_mode = config.get('compatibility_mode', 'standard')
    if compatibility_mode not in ('standard', 'ciena_saos10'):
        raise ValueError(f"Invalid compatibility_mode: {compatibility_mode}")

    validate_tls_cipher_policy(config.get('tls_cipher_policy', 'default'))

    # Validate subscriptions is non-empty array
    if not isinstance(config['subscriptions'], list) or len(config['subscriptions']) == 0:
        raise ValueError("Config must include at least one subscription")

    # Validate each subscription has required fields
    for i, subscription in enumerate(config['subscriptions']):
        required_sub_fields = ['path', 'instance', 'metrics', 'field_mapping']
        for field in required_sub_fields:
            if field not in subscription:
                raise ValueError(f"Subscription {i} missing required field: {field}")

        # Validate metrics is non-empty list
        if not isinstance(subscription['metrics'], list) or len(subscription['metrics']) == 0:
            raise ValueError(f"Subscription {i} must include at least one metric")

        # Validate field_mapping is dict
        if not isinstance(subscription['field_mapping'], dict):
            raise ValueError(f"Subscription {i} field_mapping must be a dictionary")

    return config


def build_gnmi_subscriptions(config: Dict[str, Any]) -> Dict[str, Any]:
    """
    Build pygnmi subscription request from config.

    Args:
        config: Daemon configuration dictionary

    Returns:
        Dict containing gNMI subscription request format
    """
    subscriptions = []

    for sub_config in config['subscriptions']:
        # Use sample_interval from config if available, otherwise use collection_interval
        # sample_interval is in seconds, convert to nanoseconds
        interval_seconds = config.get('sample_interval', config.get('collection_interval', 5))
        subscription = {
            'path': sub_config['path'],
            'mode': 'sample',
            'sample_interval': interval_seconds * 1000000000  # Convert to nanoseconds
        }
        subscriptions.append(subscription)
        logger.debug(f"Built subscription: path={sub_config['path']}, interval={interval_seconds}s ({interval_seconds * 1000000000}ns)")

    # pygnmi uses the protocol encoding names in lowercase. JSON_IETF is a
    # distinct encoding and must not be downgraded to legacy JSON.
    encoding = str(config.get('encoding', 'json')).lower()

    return {
        'subscription': subscriptions,
        'mode': 'stream',
        'encoding': encoding
    }


def _extract_instance_id(path_prefix: str) -> str:
    """
    Extract a canonical instance identifier from a gNMI path or prefix.

    Mirrors gnmi_poller_bridge.extract_instance_id so daemon and bridge agree
    on instance keys without a cross-module import (daemon must run standalone).

    Supports:
    - Ciena format: interface-counters[interface-type=ettp]/interfaces[if-name=40]
    - OpenConfig: /interface[name=eth0]
    - Returns "default" for non-keyed paths.
    """
    if not path_prefix:
        return "default"
    iface_type_match = re.search(r'interface-type=([^\]]+)', path_prefix)
    iface_name_match = re.search(r'if-name=([^\]]+)', path_prefix)
    if iface_type_match and iface_name_match:
        return f"{iface_type_match.group(1)}-{iface_name_match.group(1)}"
    name_match = re.search(r'\[name=([^\]]+)\]', path_prefix)
    if name_match:
        return name_match.group(1)
    return "default"


class ExponentialBackoff:
    """
    Implements exponential backoff for connection retries.

    Backoff sequence: 1s, 2s, 4s, 8s, 16s, 32s, 60s (max)
    """

    def __init__(self, initial_delay: float = 1.0, max_delay: float = 60.0, multiplier: float = 2.0):
        self.initial_delay = initial_delay
        self.max_delay = max_delay
        self.multiplier = multiplier
        self.current_delay = initial_delay
        self.retry_count = 0

    def get_delay(self) -> float:
        """Get current delay and increment for next time."""
        delay = self.current_delay
        self.retry_count += 1
        self.current_delay = min(self.current_delay * self.multiplier, self.max_delay)
        return delay

    def reset(self):
        """Reset backoff to initial state after successful connection."""
        self.current_delay = self.initial_delay
        self.retry_count = 0


class SampleBuffer:
    """
    Buffers gNMI samples for RRD streaming.

    Stores last N samples with timestamps. Daemon startup sizes this buffer from
    poller_interval and sample_interval so it covers at least one configured
    Cacti poller cycle. Uses deque maxlen for automatic rotation of oldest
    samples when full - no explicit clear() needed.
    """

    def __init__(self, max_samples: int = 15):
        self.max_samples = max_samples
        self.buffer: Deque[Dict[str, Any]] = deque(maxlen=max_samples)
        self.last_write_time = time.time()

    def add_sample(self, sample: Dict[str, Any]):
        """Add a sample to the buffer."""
        sample['timestamp'] = datetime.now(timezone.utc).isoformat()
        self.buffer.append(sample)

    def get_aggregated(self) -> Dict[str, Any]:
        """
        Get aggregated values from buffer.

        For now, returns the latest sample. Future enhancement:
        calculate average/min/max across buffered samples.
        """
        if not self.buffer:
            return {}

        # Return latest sample (most recent data)
        # TODO: Phase 3 - Add configurable aggregation strategies
        return self.buffer[-1]

    def get_all_samples_with_epoch(self) -> List[Dict[str, Any]]:
        """
        Return all buffered samples with Unix epoch timestamps.

        Converts ISO timestamps to Unix epoch for rrdtool compatibility.
        rrdtool requires timestamps in seconds since 1970, not ISO format.

        Returns:
            List of samples, each with an 'epoch' field (int, Unix timestamp)
        """
        samples = []
        for sample in self.buffer:
            sample_copy = sample.copy()
            # Convert ISO timestamp to Unix epoch for rrdtool
            iso_ts = sample_copy.get('timestamp', '')
            if iso_ts:
                try:
                    dt = datetime.fromisoformat(iso_ts.replace('Z', '+00:00'))
                    sample_copy['epoch'] = int(dt.timestamp())
                except (ValueError, AttributeError):
                    # Skip samples with invalid timestamps
                    continue
            samples.append(sample_copy)
        return samples

    def clear(self):
        """Clear the buffer after successful write. DEPRECATED - use mark_written() instead."""
        self.buffer.clear()
        self.last_write_time = time.time()

    def mark_written(self):
        """
        Mark that a write occurred without clearing the buffer.

        For RRD streaming, we keep samples in the buffer (let deque maxlen handle rotation)
        but still need to track write timing for the should_write() interval check.
        """
        self.last_write_time = time.time()

    def should_write(self, interval: float = 10.0) -> bool:
        """Check if it's time to write to storage."""
        return (time.time() - self.last_write_time) >= interval


class MultiInstanceBuffer:
    """
    Buffers gNMI samples in per-instance SampleBuffers.

    Each subscription instance (e.g., "ettp-40", "ettp-34") gets its own
    SampleBuffer with independent retention. This prevents samples from one
    subscription starving another's history when multiple subscriptions share
    a device — the read-side guarantee the 60s Cacti poller depends on for
    RRD backfill across all data sources.

    Write cadence is shared (one storage file per device), but every read API
    returns instance-keyed structures matching the format documented in
    docs/daemon_storage_format.md.
    """

    def __init__(self, max_samples_per_instance: int = 15):
        self.max_samples = max_samples_per_instance
        self.buffers: Dict[str, SampleBuffer] = {}
        self.last_write_time = time.time()

    def add_sample(self, instance: str, sample: Dict[str, Any]):
        """Add a sample for the given instance. Lazy-creates the per-instance buffer."""
        if instance not in self.buffers:
            self.buffers[instance] = SampleBuffer(max_samples=self.max_samples)
        self.buffers[instance].add_sample(sample)

    def get_aggregated_by_instance(self) -> Dict[str, Dict[str, Any]]:
        """Return {instance: latest_sample} for every instance that has data."""
        result = {}
        for instance, buf in self.buffers.items():
            latest = buf.get_aggregated()
            if latest:
                result[instance] = latest
        return result

    def get_samples_history_by_instance(self) -> Dict[str, List[Dict[str, Any]]]:
        """Return {instance: [samples with epoch]} for every instance."""
        result = {}
        for instance, buf in self.buffers.items():
            samples = buf.get_all_samples_with_epoch()
            if samples:
                result[instance] = samples
        return result

    def evict_missing(self, active_instances):
        """Drop per-instance buffers whose instance is no longer in active_instances."""
        active = set(active_instances)
        for instance in list(self.buffers.keys()):
            if instance not in active:
                logger.info(f"Evicting buffer for removed instance: {instance}")
                del self.buffers[instance]

    def has_data(self) -> bool:
        """True if any instance has at least one buffered sample."""
        return any(len(b.buffer) > 0 for b in self.buffers.values())

    def should_write(self, interval: float = 10.0) -> bool:
        return (time.time() - self.last_write_time) >= interval

    def mark_written(self):
        self.last_write_time = time.time()


class GNMIDaemon:
    """
    Main daemon class managing gNMI subscription and storage.
    """

    def __init__(self, device_id: int, config: Dict[str, Any], storage_dir: str = None):
        if storage_dir is None:
            storage_path = default_storage_dir()
            if not storage_path.is_dir():
                raise RuntimeError(
                    f"Runtime storage directory missing: {storage_path}. "
                    "Install/enable the gNMI plugin to initialize runtime directories."
                )
            self.storage_dir = storage_path
            chmod_private_file(self.storage_dir, 0o750)
        else:
            self.storage_dir = ensure_private_dir(Path(storage_dir))
        self.device_id = device_id
        self.config = config
        self.storage_file = self.storage_dir / f"device_{device_id}.json"
        self.pid_file = self.storage_dir / f"device_{device_id}.pid"
        self.pid_lock_file = self.storage_dir / f".device_{device_id}.pid.lock"
        self._pid_lock_handle = None
        self.subscription_probe_timeout = 10.0

        # State management
        self.running = False
        self.shutdown_event = Event()
        self._shutdown_complete = False
        self.gnmi_client: Optional[Any] = None
        self.backoff = ExponentialBackoff()
        # Compute per-instance buffer capacity from poller interval (falls back to 300s default).
        # Formula: ceil((poller_interval * 1.5) / sample_interval), clamped [4, 120].
        # Each subscription instance gets its OWN buffer with this capacity, so retention
        # scales with subscription count instead of being shared across all interfaces.
        _poller_interval = config.get('poller_interval', 300)
        _sample_interval = config.get('sample_interval', 5)
        _max_samples = compute_buffer_max_samples(_poller_interval, _sample_interval)
        logger.info(
            f"MultiInstanceBuffer: poller_interval={_poller_interval}s, "
            f"sample_interval={_sample_interval}s -> max_samples_per_instance={_max_samples}"
        )
        self.sample_buffer = MultiInstanceBuffer(max_samples_per_instance=_max_samples)

        # Subscription routing: map a configured subscription's canonical instance name
        # (sub['instance']) AND the instance derived from its path to the subscription dict.
        # Built in connect_and_subscribe() before the telemetry loop runs.
        self._subscription_by_instance: Dict[str, Dict[str, Any]] = {}
        self._instance_lookup: Dict[str, str] = {}  # extracted-id -> canonical instance
        self._rejected_subscription_paths: Set[str] = set()
        self.unmatched_instance_count = 0

        # Connection state
        self.connection_status = "disconnected"
        self.connection_start_time: Optional[float] = None
        self.error_count = 0
        self.last_error: Optional[str] = None

        # Statistics
        self.sample_count = 0
        self.write_count = 0
        self.start_time = time.time()
        self.last_sample_time: Optional[float] = None
        self.reconnection_count = 0

        # Configuration polling
        self.config_poll_interval = 60  # Poll database every 60 seconds
        self.last_config_poll = time.time()

        logger.info(f"Daemon initialized for device {device_id}")
        logger.info(f"Storage: {self.storage_file}")

    def write_pid_file(self):
        """Acquire the lifetime lock and write the PID file for health checks."""
        lock = None
        try:
            lock = open(self.pid_lock_file, 'a+')
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            chmod_private_file(self.pid_lock_file)
            fd = os.open(self.pid_file, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
            with os.fdopen(fd, 'w') as f:
                f.write(str(os.getpid()))
            chmod_private_file(self.pid_file)
            self._pid_lock_handle = lock
            logger.info(f"PID file written: {self.pid_file}")
            return True
        except BlockingIOError:
            logger.error(f"Another daemon already owns the device-{self.device_id} runtime lock")
        except Exception as e:
            logger.error(f"Failed to write PID file: {e}")
        if lock is not None:
            lock.close()
        return False

    def remove_pid_file(self):
        """Remove the owned PID file, then release the lifetime lock."""
        lock = self._pid_lock_handle
        acquired_here = lock is None
        try:
            if acquired_here:
                lock = open(self.pid_lock_file, 'a+')
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            chmod_private_file(self.pid_lock_file)
            if self.pid_file.exists():
                with open(self.pid_file, 'r') as f:
                    owner_pid = int(f.read().strip())
                if owner_pid == os.getpid():
                    self.pid_file.unlink()
                    logger.info("PID file removed")
                else:
                    logger.info(
                        f"PID file belongs to successor process {owner_pid}; leaving it in place"
                    )
        except BlockingIOError:
            logger.info("PID runtime lock belongs to another daemon; leaving PID file in place")
        except Exception as e:
            logger.error(f"Failed to remove PID file: {e}")
        finally:
            if lock is not None:
                try:
                    fcntl.flock(lock, fcntl.LOCK_UN)
                except Exception:
                    pass
                lock.close()
            self._pid_lock_handle = None

    def write_storage(self, metric_groups: Optional[Dict[str, Dict[str, Any]]] = None):
        """
        Atomically write daemon state and buffered samples to JSON storage.

        The metric_groups argument is the instance-keyed latest-sample snapshot
        (output of MultiInstanceBuffer.get_aggregated_by_instance()). Pass None
        or {} for error/disconnected writes that update status only.

        samples_history is sourced directly from the per-instance buffers in
        instance-keyed form so the bridge can do a dict lookup instead of
        scanning a mixed list.

        Uses temp file + rename for atomic write to prevent partial reads.
        """
        try:
            storage_data = {
                "device_id": self.device_id,
                "hostname": self.config.get('hostname', 'unknown'),
                "last_update": datetime.now(timezone.utc).isoformat(),
                "daemon_status": self.connection_status,
                "connection_uptime": time.time() - self.connection_start_time if self.connection_start_time else 0,
                "subscription_start": datetime.fromtimestamp(self.connection_start_time, timezone.utc).isoformat() if self.connection_start_time else None,
                "error_count": self.error_count,
                "last_error": self.last_error,
                "tls_cipher_policy": self.config.get('tls_cipher_policy', 'default'),
                "rejected_subscription_paths": sorted(self._rejected_subscription_paths),
                "unmatched_instance_count": self.unmatched_instance_count,
                "metric_groups": metric_groups if metric_groups else {},
                "samples_history": self.sample_buffer.get_samples_history_by_instance(),
            }

            # Atomic write: temp file + rename
            with tempfile.NamedTemporaryFile(
                mode='w',
                dir=self.storage_dir,
                delete=False,
                prefix=f'.tmp_device_{self.device_id}_',
                suffix='.json'
            ) as tmp:
                json.dump(storage_data, tmp, indent=2)
                tmp_path = tmp.name

            # Atomic rename
            os.rename(tmp_path, self.storage_file)
            chmod_private_file(self.storage_file)

            self.write_count += 1
            logger.debug(f"Storage written: {self.storage_file} (write #{self.write_count})")

        except Exception as e:
            logger.error(f"Failed to write storage: {e}")
            # Clean up temp file if it exists
            try:
                if 'tmp_path' in locals() and os.path.exists(tmp_path):
                    os.unlink(tmp_path)
            except:
                pass

    def _match_instance(self, prefix: str, update_list: List[Dict[str, Any]]) -> Optional[str]:
        """
        Resolve an incoming gNMI notification to its canonical subscription instance.

        Matching strategy (canonicalize-then-lookup, never substring):
          1. _extract_instance_id(prefix) — usual case, prefix carries the keyed portion
          2. _extract_instance_id(first leaf's full path) — fallback for devices that
             emit empty prefix and put the full path on each leaf

        Returns the canonical instance name (sub['instance']) from the daemon config,
        or None if no subscription matches. The canonical name is what gets used as a
        key in the per-instance buffer, metric_groups, and samples_history — keeping
        the daemon and bridge in lockstep with the DB's subscription instance_identifier.
        """
        candidates = []
        if prefix:
            candidates.append(prefix)
        for item in update_list:
            if isinstance(item, dict) and item.get('path'):
                candidates.append(item['path'])
                break  # one fallback path is enough

        for path in candidates:
            extracted = _extract_instance_id(path)
            canonical = self._instance_lookup.get(extracted)
            if canonical:
                return canonical
        return None

    def process_sample_with_config(self, instance: str, raw_metrics: Dict[str, Any]):
        """
        Process a gNMI telemetry update for a specific subscription instance.

        Applies the matched subscription's field_mapping (gNMI metric name → Cacti
        field name) and filters to only the metrics that subscription declared,
        then appends the cleaned sample to the per-instance buffer.

        Writes to storage every ~10 seconds when the shared write clock elapses.
        """
        try:
            current_time = time.time()
            self.sample_count += 1

            if self.last_sample_time:
                sample_interval = current_time - self.last_sample_time
                if sample_interval > 7:  # Expected ~5s; warn if much higher
                    logger.warning(
                        f"Sample interval high: {sample_interval:.1f}s (sample #{self.sample_count}, instance={instance})"
                    )
                else:
                    logger.debug(
                        f"Sample received: #{self.sample_count} instance={instance} (interval: {sample_interval:.1f}s)"
                    )
            else:
                logger.debug(f"First sample received: #{self.sample_count} instance={instance}")

            self.last_sample_time = current_time

            # Apply field mapping and metric filter from the matched subscription.
            # Storage uses Cacti field names (in_octets) so the bridge can resolve
            # them directly against data_template_rrd.data_source_name.
            sub = self._subscription_by_instance.get(instance, {})
            field_mapping = sub.get('field_mapping', {})
            allowed_metrics = set(sub.get('metrics', []))

            mapped_sample: Dict[str, Any] = {}
            for metric_name, value in raw_metrics.items():
                if allowed_metrics and metric_name not in allowed_metrics:
                    continue
                cacti_field = field_mapping.get(metric_name, metric_name)
                mapped_sample[cacti_field] = value

            if not mapped_sample:
                logger.debug(f"No matching metrics for instance={instance}; skipping sample")
                return

            self.sample_buffer.add_sample(instance, mapped_sample)

            if self.sample_buffer.should_write(interval=10.0):
                write_start = time.time()
                elapsed_since_last_write = current_time - self.sample_buffer.last_write_time

                aggregated = self.sample_buffer.get_aggregated_by_instance()
                if aggregated:
                    self.write_storage(aggregated)
                    write_duration = time.time() - write_start
                    self.sample_buffer.mark_written()
                    logger.info(
                        f"Storage updated (samples: {self.sample_count}, writes: {self.write_count}, "
                        f"instances: {len(aggregated)}, write_interval: {elapsed_since_last_write:.1f}s, "
                        f"write_time: {write_duration*1000:.1f}ms)"
                    )

        except Exception as e:
            logger.error(f"Error processing sample for instance={instance}: {e}", exc_info=True)

    @staticmethod
    def _grpc_status_name(error: Exception) -> str:
        """Return a stable gRPC status name without requiring grpc imports."""
        code_method = getattr(error, 'code', None)
        if not callable(code_method):
            return ''
        try:
            code = code_method()
        except Exception:
            return ''
        name = getattr(code, 'name', None)
        if name:
            return str(name).upper()
        return str(code).rsplit('.', 1)[-1].upper()

    @classmethod
    def _is_definitive_path_rejection(cls, error: Exception) -> bool:
        """Recognize direct gRPC statuses and pygnmi's status-less wrapper."""
        if cls._grpc_status_name(error) in {
            'INVALID_ARGUMENT', 'NOT_FOUND', 'UNIMPLEMENTED'
        }:
            return True
        if type(error).__name__ != 'gNMIException':
            return False
        message = str(error).lower()
        return any(marker in message for marker in (
            'failed to parse',
            'invalid path',
            'invalid value',
            'path not found',
            'unsupported path',
            'unimplemented',
        ))

    def _identify_rejected_subscriptions(
        self,
        subscriptions: List[Dict[str, Any]],
        encoding: str,
    ) -> Set[str]:
        """Probe paths after an aggregate INVALID_ARGUMENT response.

        The fallback runs only after the target rejects the combined Subscribe
        RPC. Each path is retried with the same STREAM/SAMPLE subscription
        parameters; an immediate definitive rejection identifies a path that
        can be excluded on reconnect. Other probe failures are treated as
        transient and never exclude data.
        """
        rejected: Set[str] = set()
        for subscription in subscriptions:
            path = subscription['path']
            probe_generator = None
            try:
                probe_config = dict(self.config, subscriptions=[subscription], encoding=encoding)
                probe_built = build_gnmi_subscriptions(probe_config)
                probe_generator = self.gnmi_client.subscribe({
                    'subscription': probe_built['subscription'],
                    'mode': probe_built['mode'],
                    'encoding': probe_built['encoding'],
                })
                probe_results = Queue(maxsize=1)

                def read_first_response(generator=probe_generator, results=probe_results):
                    try:
                        results.put((True, next(iter(generator))))
                    except Exception as response_error:
                        results.put((False, response_error))

                Thread(target=read_first_response, daemon=True).start()
                try:
                    response_ok, response = probe_results.get(
                        timeout=self.subscription_probe_timeout
                    )
                except Empty:
                    logger.warning(
                        f"Timed out classifying gNMI subscription path {path}; "
                        "leaving it enabled"
                    )
                    continue
                if not response_ok:
                    raise response
            except Exception as probe_error:
                if self._is_definitive_path_rejection(probe_error):
                    rejected.add(path)
                    logger.warning(
                        f"Rejecting unsupported gNMI subscription path {path}: "
                        f"{type(probe_error).__name__}: {probe_error}"
                    )
                else:
                    logger.warning(
                        f"Could not classify gNMI subscription path {path}; "
                        f"leaving it enabled: {type(probe_error).__name__}: {probe_error}"
                    )
            finally:
                cancel = getattr(probe_generator, 'cancel', None)
                if callable(cancel):
                    try:
                        cancel()
                    except Exception:
                        pass
        return rejected

    def connect_and_subscribe(self) -> bool:
        """
        Establish gNMI connection and start subscription.

        Returns:
            bool: True if connection successful, False otherwise
        """
        active_config_subscriptions: List[Dict[str, Any]] = []
        try:
            logger.info(f"Connecting to {self.config['hostname']}:{self.config['port']}")
            self.connection_status = "connecting"

            tls_cipher_policy = initialize_gnmi_runtime(self.config)
            logger.info(
                "TLS cipher policy: %s",
                tls_cipher_policy_label(tls_cipher_policy),
            )

            if self.config.get('compatibility_mode', 'standard') == 'ciena_saos10':
                patch_pygnmi_for_ciena()
                logger.info("Applied Ciena SAOS 10.x pygnmi compatibility patches")

            # Create gNMI client with TLS parameters
            client_args = {
                'target': (self.config['hostname'], self.config['port']),
                'username': self.config.get('username'),
                'password': self.config.get('password'),
                'timeout': self.config.get('timeout', 30),  # Connection timeout in seconds
                'insecure': self.config.get('insecure', not self.config.get('use_tls', True)),
                'skip_verify': self.config.get('skip_verify', False)
            }

            # Add optional TLS parameters for mTLS
            if self.config.get('ca_cert'):
                client_args['path_root'] = self.config['ca_cert']
            if self.config.get('client_key'):
                client_args['path_key'] = self.config['client_key']
            if self.config.get('client_cert'):
                client_args['path_cert'] = self.config['client_cert']
            if self.config.get('tls_override'):
                client_args['override'] = self.config['tls_override']
                logger.info(f"Using TLS override: {self.config['tls_override']}")
            if self.config.get('no_qos_marking'):
                client_args['no_qos_marking'] = self.config['no_qos_marking']

            # Log connection parameters for debugging
            logger.info(f"gNMI connection parameters: timeout={client_args['timeout']}s, "
                       f"use_tls={not client_args['insecure']}, skip_verify={client_args['skip_verify']}, "
                       f"tls_override={'set' if client_args.get('override') else 'not set'}")

            self.gnmi_client = gNMIclient(**client_args)

            # Connect to device
            self.gnmi_client.connect()
            logger.info("gNMI client connected successfully")

            # Phase 3.3: Use dynamic subscriptions from config
            active_config_subscriptions = [
                sub for sub in self.config['subscriptions']
                if sub['path'] not in self._rejected_subscription_paths
            ]
            if not active_config_subscriptions:
                raise RuntimeError("No supported gNMI subscription paths remain")
            active_config = dict(self.config, subscriptions=active_config_subscriptions)
            gnmi_subscriptions = build_gnmi_subscriptions(active_config)
            subscribe_paths = gnmi_subscriptions['subscription']

            # Build instance routing tables. Both the canonical name (sub['instance'])
            # and the path-derived extracted ID map to the subscription dict, so
            # _match_instance can resolve via either form without substring matching.
            self._subscription_by_instance = {}
            self._instance_lookup = {}
            for sub in active_config_subscriptions:
                canonical = sub['instance']
                self._subscription_by_instance[canonical] = sub
                self._instance_lookup[canonical] = canonical
                derived = _extract_instance_id(sub['path'])
                self._instance_lookup[derived] = canonical
            # Drop per-instance buffers whose subscriptions no longer exist.
            self.sample_buffer.evict_missing(self._subscription_by_instance.keys())

            logger.info(f"Using dynamic configuration with {len(subscribe_paths)} subscriptions")
            for i, sub in enumerate(active_config_subscriptions):
                logger.info(
                    f"  Subscription {i+1}: instance={sub['instance']} path={sub['path']}"
                )

            # Prepare subscription request
            subscribe_request = {
                'subscription': subscribe_paths,
                'mode': gnmi_subscriptions['mode'],
                'encoding': gnmi_subscriptions['encoding']
            }

            # Start subscription (returns generator)
            # NOTE: Use subscribe() not subscribe_stream() to match POC behavior
            logger.info(f"Starting subscription with {len(subscribe_paths)} paths")
            logger.info(f"DEBUG: Subscription request: {json.dumps(subscribe_request, indent=2)}")

            # Use subscribe() method like POC does (not subscribe_stream)
            subscription_generator = self.gnmi_client.subscribe(subscribe_request)
            logger.info(f"DEBUG: Got subscription generator: {type(subscription_generator)}")

            iteration_count = 0
            for telemetry_entry in subscription_generator:
                iteration_count += 1
                logger.info(f"DEBUG: Received telemetry_entry #{iteration_count}, type: {type(telemetry_entry)}")

                # Parse protobuf response to dictionary (like POC does)
                try:
                    raw_data = telemetryParser(telemetry_entry)
                    logger.debug(f"DEBUG: Parsed raw_data keys: {list(raw_data.keys()) if raw_data else 'None'}")

                    # Check if this is an update (not sync_response or error)
                    if raw_data and 'update' in raw_data.keys():
                        update_dict = raw_data['update']
                        update_list = update_dict.get('update', [])
                        timestamp = update_dict.get('timestamp')
                        # Prefix carries the keyed portion (interface-type=ettp/if-name=40)
                        # that identifies which subscription this notification belongs to.
                        # Dropping it is what caused samples from multiple interfaces to
                        # mingle in one shared buffer before the fix.
                        prefix = update_dict.get('prefix', '') or ''

                        logger.debug(
                            f"DEBUG: Update list length: {len(update_list)}, timestamp: {timestamp}, prefix={prefix!r}"
                        )

                        if update_list:
                            instance = self._match_instance(prefix, update_list)
                            if instance is None:
                                self.unmatched_instance_count += 1
                                # Log keys, not values — telemetry can be large
                                logger.warning(
                                    f"Unmatched gNMI notification (count={self.unmatched_instance_count}): "
                                    f"prefix={prefix!r}, first_leaf_path="
                                    f"{(update_list[0].get('path') if isinstance(update_list[0], dict) else None)!r}; "
                                    f"known instances={list(self._instance_lookup.keys())}"
                                )
                                continue

                            update_data = {}
                            for item in update_list:
                                if isinstance(item, dict) and 'path' in item:
                                    update_data.update(
                                        _flatten_telemetry_update(
                                            item.get('path', ''),
                                            item.get('val'),
                                        )
                                    )

                            logger.debug(
                                f"DEBUG: instance={instance} metrics={list(update_data.keys())}"
                            )
                            self.process_sample_with_config(instance, update_data)
                        else:
                            logger.debug("DEBUG: Update list is empty, skipping")
                    else:
                        logger.debug(f"DEBUG: Skipping non-update response: {list(raw_data.keys()) if raw_data else 'None'}")

                except Exception as e:
                    logger.error(f"Error parsing telemetry entry: {e}", exc_info=True)

                # Check for shutdown
                if self.shutdown_event.is_set():
                    logger.info("Shutdown requested, stopping subscription")
                    break

                # Update status on first successful sample
                if self.connection_status != "connected":
                    self.connection_status = "connected"
                    self.connection_start_time = time.time()
                    self.backoff.reset()  # Reset backoff on successful connection

                    # Track reconnections
                    if self.reconnection_count > 0:
                        logger.info(f"Subscription re-established (reconnection #{self.reconnection_count})")
                    else:
                        logger.info("Subscription established successfully")

                # Poll database for config changes (every 60 seconds)
                if time.time() - self.last_config_poll > self.config_poll_interval:
                    self.poll_config_changes()
                    self.last_config_poll = time.time()

            return True

        except KeyboardInterrupt:
            raise  # Let signal handler deal with this
        except Exception as e:
            if self.shutdown_event.is_set():
                logger.info("Subscription stopped during requested shutdown")
                return True

            # Log detailed error information
            error_msg = f"{type(e).__name__}: {str(e)}"
            self.error_count += 1
            logger.error(
                f"Connection/subscription failed (error #{self.error_count}): {error_msg}",
                exc_info=True
            )
            self.last_error = error_msg
            self.connection_status = "error"

            if (
                self._is_definitive_path_rejection(e)
                and len(active_config_subscriptions) > 1
                and self.gnmi_client is not None
            ):
                rejected = self._identify_rejected_subscriptions(
                    active_config_subscriptions,
                    str(self.config.get('encoding', 'json')).lower(),
                )
                new_rejections = rejected - self._rejected_subscription_paths
                if new_rejections:
                    self._rejected_subscription_paths.update(new_rejections)
                    logger.warning(
                        "Aggregate Subscribe rejected; reconnecting without "
                        f"unsupported path(s): {sorted(new_rejections)}"
                    )

            # Write error state to storage
            try:
                self.write_storage()  # Empty metric_groups; status/error fields still update
            except:
                pass  # Don't fail on storage write error during error handling

            return False
        finally:
            # Ensure client is cleaned up
            if self.gnmi_client:
                try:
                    self.gnmi_client.close()
                except:
                    pass
                self.gnmi_client = None

    def poll_config_changes(self):
        """
        Poll database for configuration changes.

        TODO: Phase 2.7 - Implement database polling
        Check for:
        - Device config changes (host, port, credentials)
        - Metric path changes (add/remove subscriptions)
        - Restart requested flag

        NOTE: Adding or removing subscriptions currently requires a daemon
        restart so the gNMI Subscribe RPC and the MultiInstanceBuffer routing
        tables (self._subscription_by_instance, self._instance_lookup) get
        rebuilt. When this method is implemented, it must also call
        self.sample_buffer.evict_missing(...) and rebuild the lookup tables
        after any subscription topology change.
        """
        logger.debug("Polling for config changes (not yet implemented)")
        pass

    def run(self):
        """
        Main daemon loop with exponential backoff reconnection.
        """
        if not self.write_pid_file():
            return
        self.running = True

        logger.info(f"Daemon starting for device {self.device_id}")

        try:
            while self.running and not self.shutdown_event.is_set():
                # Attempt connection
                success = self.connect_and_subscribe()

                if not success and self.running:
                    # Exponential backoff before retry
                    self.reconnection_count += 1
                    delay = self.backoff.get_delay()
                    logger.warning(
                        f"Reconnect attempt {self.backoff.retry_count} after {delay:.1f}s delay "
                        f"(total reconnections: {self.reconnection_count})"
                    )

                    # Wait with ability to interrupt for shutdown
                    if self.shutdown_event.wait(timeout=delay):
                        break  # Shutdown requested during backoff

        except KeyboardInterrupt:
            logger.info("Keyboard interrupt received")
        finally:
            self.shutdown()

    def shutdown(self):
        """
        Graceful shutdown: close connections, flush buffers, cleanup.
        """
        if self._shutdown_complete:
            return
        self._shutdown_complete = True
        logger.info("Shutting down daemon...")

        self.running = False
        self.shutdown_event.set()

        # Close gNMI connection
        if self.gnmi_client:
            try:
                logger.info("Closing gNMI connection")
                self.gnmi_client.close()
                self.gnmi_client = None
            except Exception as e:
                logger.error(f"Error closing connection: {e}")

        # Flush any remaining buffered samples
        try:
            aggregated_data = self.sample_buffer.get_aggregated_by_instance()
            if aggregated_data:
                logger.info("Flushing remaining buffered samples")
                self.write_storage(aggregated_data)
        except Exception as e:
            logger.error(f"Error flushing buffer: {e}")

        # Update storage with disconnected status
        try:
            self.connection_status = "disconnected"
            self.write_storage()  # Empty metric_groups; status fields still update
        except Exception as e:
            logger.error(f"Error updating final status: {e}")

        # Remove PID file
        self.remove_pid_file()

        logger.info("Daemon shutdown complete")

    def request_shutdown(self):
        """Request loop termination without writing final state in a signal handler."""
        if self.shutdown_event.is_set():
            return
        logger.info("Shutdown requested")
        self.running = False
        self.shutdown_event.set()
        if self.gnmi_client:
            try:
                self.gnmi_client.close()
            except Exception as e:
                logger.debug(f"Subscription close during shutdown request: {e}")

    @staticmethod
    def check_health(device_id: int, storage_dir: str = None,
                     staleness_threshold: int = 600) -> Dict[str, Any]:
        """
        Check daemon health for poller hook.

        Args:
            device_id: gNMI device ID.
            storage_dir: Directory containing PID and JSON files.
            staleness_threshold: Seconds after which data is considered stale.
                Defaults to 600 (= 300s poller * 2) for a safe fallback.
                PHP callers should pass gnmi_get_poller_interval() * 2.

        Returns:
            dict: Health status with keys:
                - running: bool
                - pid: int or None
                - last_update: timestamp or None
                - status: str
                - stale: bool
                - age_seconds: float (when storage file is present)
        """
        if storage_dir is None:
            storage_dir = str(default_storage_dir())
        storage_dir = Path(storage_dir)
        pid_file = storage_dir / f"device_{device_id}.pid"
        storage_file = storage_dir / f"device_{device_id}.json"

        health = {
            "running": False,
            "pid": None,
            "last_update": None,
            "status": "unknown",
            "stale": False
        }

        # Check PID file
        if pid_file.exists():
            try:
                with open(pid_file, 'r') as f:
                    pid = int(f.read().strip())
                if pid <= 1:
                    raise ValueError(f"Invalid daemon PID: {pid}")
                health["pid"] = pid

                # Check if process is actually running
                try:
                    os.kill(pid, 0)  # Signal 0 just checks if process exists
                    health["running"] = True
                except OSError:
                    health["running"] = False
                    health["status"] = "pid_exists_but_process_dead"
            except Exception as e:
                logger.error(f"Error reading PID file: {e}")

        # Check storage file freshness
        if storage_file.exists():
            try:
                with open(storage_file, 'r') as f:
                    data = json.load(f)

                health["last_update"] = data.get("last_update")
                health["status"] = data.get("daemon_status", "unknown")

                # Check staleness using the caller-supplied threshold
                # (staleness_threshold = poller_interval * 2 from PHP)
                if health["last_update"]:
                    last_update_dt = datetime.fromisoformat(health["last_update"].replace('Z', '+00:00'))
                    age = (datetime.now(timezone.utc) - last_update_dt).total_seconds()
                    health["stale"] = age > staleness_threshold
                    health["age_seconds"] = age

            except Exception as e:
                logger.error(f"Error reading storage file: {e}")

        return health


def signal_handler(signum, frame):
    """Signal handler for graceful shutdown."""
    logger.info(f"Received signal {signum}")
    # Daemon instance will check shutdown_event in its loop
    pass


def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(
        description='gNMI Daemon for Cacti Plugin',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )

    parser.add_argument(
        '--device-id',
        type=int,
        required=True,
        help='Cacti device ID'
    )

    parser.add_argument(
        '--config-file',
        type=str,
        help='JSON configuration file (alternative to database)'
    )

    parser.add_argument(
        '--storage-dir',
        type=str,
        default=None,
        help=f'Storage directory for JSON files (default: {default_storage_dir()})'
    )

    parser.add_argument(
        '--check-health',
        action='store_true',
        help='Check daemon health and exit'
    )

    parser.add_argument(
        '--debug',
        action='store_true',
        help='Enable debug logging'
    )

    args = parser.parse_args()

    # Set log level
    if args.debug:
        logger.setLevel(logging.DEBUG)

    # Health check mode
    if args.check_health:
        health = GNMIDaemon.check_health(args.device_id, args.storage_dir)
        print(json.dumps(health, indent=2))
        sys.exit(0 if health['running'] else 1)

    # Load configuration
    # TODO: Phase 2.7 - Load from database
    if args.config_file:
        with open(args.config_file, 'r') as f:
            config = json.load(f)
    else:
        # For now, require config file
        # Phase 2.7 will query database
        logger.error("--config-file required (database loading not yet implemented)")
        sys.exit(1)

    # Create daemon instance
    daemon = GNMIDaemon(
        device_id=args.device_id,
        config=config,
        storage_dir=args.storage_dir
    )

    # Register signal handlers
    signal.signal(signal.SIGTERM, lambda s, f: daemon.request_shutdown())
    signal.signal(signal.SIGINT, lambda s, f: daemon.request_shutdown())

    # Run daemon
    try:
        daemon.run()
    except Exception as e:
        logger.error(f"Daemon crashed: {e}", exc_info=True)
        daemon.shutdown()
        sys.exit(1)


if __name__ == '__main__':
    main()
