"""
Unit tests for gnmi_collector.transforms module

Tests data transformation functions for converting gNMI telemetry values.
These tests cover collector metric transformations.
"""

import pytest
from scripts.gnmi_collector.transforms import (
    octets_to_bits,
    apply_transform,
    apply_transforms
)


class TestOctetsToBits:
    """Test octet-to-bit conversion for interface counters."""

    @pytest.mark.unit
    def test_basic_conversion(self):
        """Test that octets are correctly converted to bits (multiply by 8)."""
        assert octets_to_bits(1000) == 8000
        assert octets_to_bits(0) == 0
        assert octets_to_bits(1) == 8

    @pytest.mark.unit
    def test_large_values(self):
        """Test conversion with large counter values (64-bit counters)."""
        # Typical interface counter values
        assert octets_to_bits(1234567890) == 9876543120
        # Near 64-bit max
        large_value = 2**63 - 1
        assert octets_to_bits(large_value) == large_value * 8

    @pytest.mark.unit
    def test_float_values(self):
        """Test conversion with float values (should work for gauge types)."""
        assert octets_to_bits(100.5) == 804.0
        assert octets_to_bits(0.125) == 1.0

    @pytest.mark.unit
    def test_invalid_input(self):
        """Test error handling for invalid input types."""
        with pytest.raises(TypeError):
            octets_to_bits("1000")
        with pytest.raises(TypeError):
            octets_to_bits(None)
        with pytest.raises(TypeError):
            octets_to_bits([1000])

    @pytest.mark.unit
    def test_negative_values(self):
        """Test that negative values raise an error (counters can't be negative)."""
        with pytest.raises(ValueError):
            octets_to_bits(-100)


class TestApplyTransform:
    """Test generic transform application function."""

    @pytest.mark.unit
    def test_multiply_operation(self):
        """Test multiply transform operation."""
        result = apply_transform(100, operation="multiply", operand=8)
        assert result == 800

    @pytest.mark.unit
    def test_multiply_by_zero(self):
        """Test multiply by zero."""
        result = apply_transform(100, operation="multiply", operand=0)
        assert result == 0

    @pytest.mark.unit
    def test_multiply_by_fraction(self):
        """Test multiply by fractional value."""
        result = apply_transform(100, operation="multiply", operand=0.5)
        assert result == 50.0

    @pytest.mark.unit
    def test_divide_operation(self):
        """Test divide transform operation."""
        result = apply_transform(800, operation="divide", operand=8)
        assert result == 100.0

    @pytest.mark.unit
    def test_divide_by_zero(self):
        """Test that divide by zero raises an error."""
        with pytest.raises(ZeroDivisionError):
            apply_transform(100, operation="divide", operand=0)

    @pytest.mark.unit
    def test_add_operation(self):
        """Test addition transform operation."""
        result = apply_transform(100, operation="add", operand=50)
        assert result == 150

    @pytest.mark.unit
    def test_subtract_operation(self):
        """Test subtraction transform operation."""
        result = apply_transform(100, operation="subtract", operand=50)
        assert result == 50

    @pytest.mark.unit
    def test_unsupported_operation(self):
        """Test that unsupported operations raise an error."""
        with pytest.raises(ValueError):
            apply_transform(100, operation="modulo", operand=3)

    @pytest.mark.unit
    def test_invalid_value_type(self):
        """Test that invalid operand types raise an error."""
        with pytest.raises(TypeError):
            apply_transform(100, operation="multiply", operand="8")


class TestApplyTransforms:
    """Test applying multiple transforms to telemetry data."""

    @pytest.mark.unit
    def test_single_transform_to_results(self):
        """Test applying single transform to POC-style results list."""
        # POC format: list of dicts with 'path' and 'val' keys
        results = [
            {'path': 'out-octets', 'val': 1000},
            {'path': 'in-octets', 'val': 2000}
        ]
        transforms = [{"operation": "multiply", "value": 8}]

        apply_transforms(results, transforms)

        assert results[0]['val'] == 8000
        assert results[1]['val'] == 16000

    @pytest.mark.unit
    def test_multiple_transforms_sequential(self):
        """Test applying multiple transforms in sequence."""
        results = [{'path': 'metric1', 'val': 100}]
        transforms = [
            {"operation": "multiply", "value": 2},
            {"operation": "add", "value": 50}
        ]

        apply_transforms(results, transforms)

        # (100 * 2) + 50 = 250
        assert results[0]['val'] == 250

    @pytest.mark.unit
    def test_empty_transforms(self):
        """Test that empty transforms list doesn't modify data."""
        results = [{'path': 'metric1', 'val': 100}]
        transforms = []

        apply_transforms(results, transforms)

        assert results[0]['val'] == 100

    @pytest.mark.unit
    def test_none_transforms(self):
        """Test that None transforms doesn't modify data."""
        results = [{'path': 'metric1', 'val': 100}]

        apply_transforms(results, None)

        assert results[0]['val'] == 100

    @pytest.mark.unit
    def test_transform_with_none_result(self):
        """Test that None results are skipped gracefully."""
        results = [
            {'path': 'metric1', 'val': 100},
            None,
            {'path': 'metric2', 'val': 200}
        ]
        transforms = [{"operation": "multiply", "value": 2}]

        apply_transforms(results, transforms)

        assert results[0]['val'] == 200
        assert results[1] is None
        assert results[2]['val'] == 400

    @pytest.mark.unit
    def test_transform_with_missing_val_key(self):
        """Test handling of results missing 'val' key."""
        results = [
            {'path': 'metric1', 'val': 100},
            {'path': 'metric2'}  # Missing 'val' key
        ]
        transforms = [{"operation": "multiply", "value": 2}]

        # Should skip items without 'val' key
        apply_transforms(results, transforms)

        assert results[0]['val'] == 200
        assert 'val' not in results[1]

    @pytest.mark.unit
    def test_octets_to_bits_transform(self):
        """Test the common octets-to-bits transform from POC."""
        results = [
            {'path': 'out-octets', 'val': 12500000},  # 12.5 MB
            {'path': 'in-octets', 'val': 8750000}     # 8.75 MB
        ]
        transforms = [{"operation": "multiply", "value": 8}]

        apply_transforms(results, transforms)

        # Converted to bits
        assert results[0]['val'] == 100000000  # 100 Mbps
        assert results[1]['val'] == 70000000   # 70 Mbps


class TestTransformEdgeCases:
    """Test edge cases and error conditions."""

    @pytest.mark.unit
    def test_very_large_values(self):
        """Test transforms with very large values."""
        # Near 64-bit counter max
        large_val = 2**63 - 1
        result = apply_transform(large_val, operation="multiply", operand=1)
        assert result == large_val

    @pytest.mark.unit
    def test_precision_with_floats(self):
        """Test that float precision is maintained."""
        result = apply_transform(0.123456789, operation="multiply", operand=1000)
        assert abs(result - 123.456789) < 0.0001

    @pytest.mark.unit
    def test_transform_preserves_original_dict_structure(self):
        """Test that transforms only modify 'val' and preserve other keys."""
        results = [
            {
                'path': 'interface/counters/out-octets',
                'val': 1000,
                'timestamp': 1234567890,
                'metadata': {'unit': 'octets'}
            }
        ]
        transforms = [{"operation": "multiply", "value": 8}]

        apply_transforms(results, transforms)

        assert results[0]['val'] == 8000
        assert results[0]['path'] == 'interface/counters/out-octets'
        assert results[0]['timestamp'] == 1234567890
        assert results[0]['metadata'] == {'unit': 'octets'}
