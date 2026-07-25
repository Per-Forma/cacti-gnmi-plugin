"""
Monkey patch for pygnmi to handle Ciena device compatibility issues.

Ciena 10.8 devices have two issues:
1. Do not respond to the Capabilities RPC, causing connection to hang
2. Can return None responses during subscription, causing TypeError

This patch addresses both issues.
"""

import logging

logger = logging.getLogger(__name__)


def patch_pygnmi_for_ciena():
    """
    Patch pygnmi for Ciena device compatibility.

    Patches:
    1. gNMIclient.capabilities() - Skip Capabilities RPC call to prevent hang
    2. StreamSubscriber._merge_updates() - Handle None responses gracefully

    This should be called before creating any gNMIclient instances.
    """
    try:
        from pygnmi.client import gNMIclient, StreamSubscriber

        # A daemon reconnects repeatedly in the same process. Avoid wrapping
        # the same class methods again on every connection attempt.
        if getattr(gNMIclient, '_gnmi_ciena_saos10_patched', False):
            logger.debug("Ciena SAOS 10.x pygnmi compatibility patch already active")
            return

        # ==========================================
        # Patch 1: Capabilities RPC (PREVENTS HANG)
        # ==========================================
        # Save the original capabilities method
        original_capabilities = gNMIclient.capabilities

        def patched_capabilities(self):
            """
            Patched capabilities method that returns immediately without
            making the gNMI Capabilities RPC call.

            Ciena 10.8 devices don't respond to Capabilities RPC, causing
            connect() to hang indefinitely. This bypasses that call.
            """
            logger.warning("capabilities() patched - skipping RPC call (Ciena workaround)")

            # Return a minimal valid response
            return {
                "supported_encodings": ["json", "json_ietf"],
                "gNMI_version": "0.7.0",
                "supported_models": []
            }

        # Replace the capabilities method
        gNMIclient.capabilities = patched_capabilities
        gNMIclient._gnmi_ciena_saos10_patched = True
        logger.info("Patched gNMIclient.capabilities() to skip RPC call")

        # ==========================================
        # Patch 2: Handle None Responses (PREVENTS TypeError)
        # ==========================================
        # Patch StreamSubscriber._merge_updates() to handle None responses
        try:
            logger.info("Attempting to patch StreamSubscriber._merge_updates()...")

            # Get the original _merge_updates method
            if not hasattr(StreamSubscriber, '_merge_updates'):
                logger.error("StreamSubscriber does not have _merge_updates method")
                # Continue without this patch - capabilities patch is the critical one
                logger.info("pygnmi patched (capabilities only) - None response handling skipped")
                return

            original_merge_updates = StreamSubscriber._merge_updates

            def patched_merge_updates(self, resp, new_resp):
                """
                Patched _merge_updates method that handles None responses from Ciena devices.

                Ciena devices can return None responses during subscription, which causes
                TypeError when pygnmi tries to check "update" in new_resp (where new_resp is None).
                This patch checks for None before any operations.

                Args:
                    resp: Accumulated response dictionary
                    new_resp: New response from device (can be None)
                """
                # Handle None response gracefully
                if new_resp is None:
                    logger.debug("Received None response from device in _merge_updates(), skipping merge (this is normal for Ciena devices)")
                    # Don't return - just skip the merge, but don't break the loop
                    # The caller (_get_updates_till_sync) will continue waiting for next update
                    return

                # Call original method if response is valid
                try:
                    return original_merge_updates(self, resp, new_resp)
                except TypeError as e:
                    # Catch any remaining TypeError issues (defensive)
                    if "NoneType" in str(e) and ("not iterable" in str(e) or "argument of type" in str(e)):
                        logger.debug(f"Caught TypeError in _merge_updates (likely None-related): {e}, skipping merge")
                        return
                    raise

            # Replace the _merge_updates method
            StreamSubscriber._merge_updates = patched_merge_updates
            logger.info("Successfully patched StreamSubscriber._merge_updates() to handle None responses")

            # Also patch _get_updates_till_sync to handle None responses properly
            if hasattr(StreamSubscriber, '_get_updates_till_sync'):
                original_get_updates_till_sync = StreamSubscriber._get_updates_till_sync

                def patched_get_updates_till_sync(self, timeout=None):
                    """
                    Patched _get_updates_till_sync that handles None responses.

                    When None responses are received, continue the loop instead of
                    waiting forever for sync_response.
                    """
                    resp = {"update": {}}
                    none_count = 0
                    max_none_before_warning = 10

                    while not "sync_response" in resp:
                        new_resp = self._get_one_update(timeout=timeout)

                        # Handle None responses - continue loop
                        if new_resp is None:
                            none_count += 1
                            if none_count <= max_none_before_warning:
                                logger.debug(f"Received None in _get_updates_till_sync (count: {none_count}), continuing...")
                            elif none_count == max_none_before_warning + 1:
                                logger.warning(f"Received {none_count} None responses, continuing to wait for valid data...")
                            # Continue loop - don't merge None, but keep waiting
                            continue

                        # Reset None count on valid response
                        none_count = 0

                        # Merge valid response
                        self._merge_updates(resp, new_resp)

                    return resp

                StreamSubscriber._get_updates_till_sync = patched_get_updates_till_sync
                logger.info("Successfully patched StreamSubscriber._get_updates_till_sync() to handle None responses")

        except Exception as e:
            logger.error(f"Failed to patch _merge_updates method: {e}")
            import traceback
            logger.error(f"Traceback: {traceback.format_exc()}")
            # Continue without this patch - capabilities patch is the critical one
            logger.warning("Continuing without None response patch - capabilities patch is critical")

        logger.info("pygnmi patched successfully for Ciena compatibility (capabilities + None handling)")

    except ImportError as e:
        logger.error(f"Failed to import pygnmi modules: {e}")
        logger.error("Make sure pygnmi is installed in the virtual environment")
        raise
    except Exception as e:
        logger.error(f"Failed to patch pygnmi: {e}")
        import traceback
        logger.error(f"Traceback: {traceback.format_exc()}")
        raise
