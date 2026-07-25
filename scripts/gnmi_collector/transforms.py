"""
Data transformation utilities for gNMI telemetry values.

This module provides functions to transform raw gNMI telemetry data
into the format needed for storage in RRD files. Common transformations
include converting octet counters to bits, calculating rates, and
applying arbitrary mathematical operations.

Metric transformation helpers used by the collector.
"""

from typing import List, Dict, Any, Optional, Union


def octets_to_bits(octets: Union[int, float]) -> Union[int, float]:
    """
    Convert octet counter values to bits.

    This is the most common transformation for interface traffic counters,
    as network metrics are typically expressed in bits per second rather
    than octets (bytes) per second.

    Args:
        octets: Counter value in octets (bytes)

    Returns:
        Counter value in bits (octets * 8)

    Raises:
        TypeError: If octets is not a numeric type
        ValueError: If octets is negative (counters can't be negative)

    Examples:
        >>> octets_to_bits(1000)
        8000
        >>> octets_to_bits(12500000)  # 12.5 MB
        100000000  # 100 Mbps
    """
    if not isinstance(octets, (int, float)):
        raise TypeError(f"Expected int or float, got {type(octets).__name__}")

    if octets < 0:
        raise ValueError(f"Counter values cannot be negative: {octets}")

    return octets * 8


def apply_transform(
    value: Union[int, float],
    operation: str,
    operand: Union[int, float]
) -> Union[int, float]:
    """
    Apply a single mathematical transformation to a value.

    Args:
        value: The input value to transform
        operation: The operation to perform. Supported operations:
            - "multiply": Multiply by the specified operand
            - "divide": Divide by the specified operand
            - "add": Add the specified operand
            - "subtract": Subtract the specified operand
        operand: The operand for the transformation

    Returns:
        The transformed value

    Raises:
        ValueError: If operation is not supported
        TypeError: If value or operand are not numeric
        ZeroDivisionError: If operation is "divide" and operand is 0

    Examples:
        >>> apply_transform(100, "multiply", 8)
        800
        >>> apply_transform(1000, "divide", 10)
        100.0
        >>> apply_transform(50, "add", 25)
        75
    """
    if not isinstance(value, (int, float)):
        raise TypeError(f"Value must be int or float, got {type(value).__name__}")

    if not isinstance(operand, (int, float)):
        raise TypeError(f"Transform operand must be int or float, got {type(operand).__name__}")

    if operation == "multiply":
        return value * operand
    elif operation == "divide":
        if operand == 0:
            raise ZeroDivisionError("Cannot divide by zero")
        return value / operand
    elif operation == "add":
        return value + operand
    elif operation == "subtract":
        return value - operand
    else:
        raise ValueError(
            f"Unsupported operation: {operation}. "
            f"Supported operations: multiply, divide, add, subtract"
        )


def apply_transforms(
    results: List[Optional[Dict[str, Any]]],
    transforms: Optional[List[Dict[str, Any]]]
) -> None:
    """
    Apply a list of transforms to POC-format results in-place.

    This function modifies the results list in-place, applying each transform
    sequentially to the 'val' key of each result dictionary. This matches the
    behavior of the POC code.

    Args:
        results: List of result dictionaries with 'path' and 'val' keys.
                 None entries are skipped. Results missing 'val' key are skipped.
        transforms: List of transform specifications, each with:
                   - "operation": The operation name (multiply, divide, etc.)
                   - "value": The operand value
                   None or empty list means no transforms applied.

    Returns:
        None (modifies results in-place)

    Examples:
        >>> results = [
        ...     {'path': 'out-octets', 'val': 1000},
        ...     {'path': 'in-octets', 'val': 2000}
        ... ]
        >>> transforms = [{"operation": "multiply", "value": 8}]
        >>> apply_transforms(results, transforms)
        >>> results[0]['val']
        8000
        >>> results[1]['val']
        16000
    """
    # Handle None or empty transforms
    if not transforms:
        return

    # Apply each transform to each result
    for transform in transforms:
        for result in results:
            # Skip None results
            if result is None:
                continue

            # Skip results without 'val' key
            if 'val' not in result:
                continue

            # Apply the transform
            operation = transform.get('operation')
            transform_value = transform.get('value')

            if operation and transform_value is not None:
                result['val'] = apply_transform(
                    result['val'],
                    operation,
                    transform_value
                )
