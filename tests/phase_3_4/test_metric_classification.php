#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.4 - Metric Classification Tests
 *
 * Tests for gnmi_classify_metric() pure function.
 * No database required - pure input/output function tests.
 *
 * Run: php plugins/gnmi/tests/phase_3_4/test_metric_classification.php
 */

// Load just the functions file - classification is a pure function, no Cacti DB needed.
// We stub the Cacti global.php include path for standalone execution.
if (file_exists('/var/www/html/cacti/include/global.php')) {
    require_once('/var/www/html/cacti/include/global.php');
    require_once('/var/www/html/cacti/lib/database.php');
    require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');
} else {
    // Standalone: define a minimal stub so functions.php can be parsed without Cacti
    // gnmi_classify_metric() has no external dependencies.
    define('POLLER_VERBOSITY_LOW', 1);
    define('POLLER_VERBOSITY_MEDIUM', 2);
    define('POLLER_VERBOSITY_DEBUG', 5);
    if (!function_exists('cacti_log')) {
        function cacti_log($msg, $output = false, $facility = 'POLLER', $level = 1) {}
    }
    // Load only the classification function from functions.php
    // by requiring the full file (other functions will fail gracefully if DB not present)
    $functions_path = dirname(dirname(dirname(__FILE__))) . '/include/functions.php';
    if (file_exists($functions_path)) {
        // Suppress errors from undefined Cacti functions during include
        $prev = error_reporting(0);
        // Define stubs for symbols referenced at file-level in functions.php
        if (!defined('GNMI_DATA_INPUT_NAME')) {
            require_once($functions_path);
        }
        error_reporting($prev);
    }
}

class TestMetricClassification {

    public function setUp() {
        // Pure function tests — no DB setup needed
    }

    public function tearDown() {
        // Nothing to clean up
    }

    private function assertClassification($metric_name, $group, $direction, $graph_key) {
        $result = gnmi_classify_metric($metric_name);
        if ($result['group'] !== $group) {
            throw new Exception("Expected group='$group' for '$metric_name', got '{$result['group']}'");
        }
        if ($result['direction'] !== $direction) {
            throw new Exception("Expected direction='$direction' for '$metric_name', got '{$result['direction']}'");
        }
        $actual_key = array_key_exists('graph_key', $result) ? $result['graph_key'] : null;
        if ($actual_key !== $graph_key) {
            throw new Exception("Expected graph_key=" . var_export($graph_key, true) . " for '$metric_name', got " . var_export($actual_key, true));
        }
    }

    // -------------------------------------------------------------------------
    // Traffic: inbound
    // -------------------------------------------------------------------------

    /**
     * Test 1: in_octets → traffic / inbound
     */
    public function testInOctetsIsTrafficInbound() {
        $result = gnmi_classify_metric('in_octets');
        if ($result['group'] !== 'traffic') {
            throw new Exception("Expected group='traffic' for 'in_octets', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'inbound') {
            throw new Exception("Expected direction='inbound' for 'in_octets', got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 2: in_bytes → traffic / inbound (alternate naming)
     */
    public function testInBytesIsTrafficInbound() {
        $result = gnmi_classify_metric('in_bytes');
        if ($result['group'] !== 'traffic') {
            throw new Exception("Expected group='traffic' for 'in_bytes', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'inbound') {
            throw new Exception("Expected direction='inbound' for 'in_bytes', got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 3: rx_octets → traffic / inbound (rx prefix variant)
     */
    public function testRxOctetsIsTrafficInbound() {
        $result = gnmi_classify_metric('rx_octets');
        if ($result['group'] !== 'traffic') {
            throw new Exception("Expected group='traffic' for 'rx_octets', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'inbound') {
            throw new Exception("Expected direction='inbound' for 'rx_octets', got '{$result['direction']}'");
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Traffic: outbound
    // -------------------------------------------------------------------------

    /**
     * Test 4: out_octets → traffic / outbound
     */
    public function testOutOctetsIsTrafficOutbound() {
        $result = gnmi_classify_metric('out_octets');
        if ($result['group'] !== 'traffic') {
            throw new Exception("Expected group='traffic' for 'out_octets', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'outbound') {
            throw new Exception("Expected direction='outbound' for 'out_octets', got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 5: tx_bytes → traffic / outbound (tx prefix variant)
     */
    public function testTxBytesIsTrafficOutbound() {
        $result = gnmi_classify_metric('tx_bytes');
        if ($result['group'] !== 'traffic') {
            throw new Exception("Expected group='traffic' for 'tx_bytes', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'outbound') {
            throw new Exception("Expected direction='outbound' for 'tx_bytes', got '{$result['direction']}'");
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Packets
    // -------------------------------------------------------------------------

    /**
     * Test 6: in_pkts → packets / inbound
     */
    public function testInPktsIsPacketsInbound() {
        $result = gnmi_classify_metric('in_pkts');
        if ($result['group'] !== 'packets') {
            throw new Exception("Expected group='packets' for 'in_pkts', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'inbound') {
            throw new Exception("Expected direction='inbound' for 'in_pkts', got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 7: out_packets → packets / outbound (full word variant)
     */
    public function testOutPacketsIsPacketsOutbound() {
        $result = gnmi_classify_metric('out_packets');
        if ($result['group'] !== 'packets') {
            throw new Exception("Expected group='packets' for 'out_packets', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'outbound') {
            throw new Exception("Expected direction='outbound' for 'out_packets', got '{$result['direction']}'");
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Errors
    // -------------------------------------------------------------------------

    /**
     * Test 8: out_errors → errors / outbound
     */
    public function testOutErrorsIsErrorsOutbound() {
        $result = gnmi_classify_metric('out_errors');
        if ($result['group'] !== 'errors') {
            throw new Exception("Expected group='errors' for 'out_errors', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'outbound') {
            throw new Exception("Expected direction='outbound' for 'out_errors', got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 9: in_err → errors / inbound (abbreviated variant)
     */
    public function testInErrIsErrorsInbound() {
        $result = gnmi_classify_metric('in_err');
        if ($result['group'] !== 'errors') {
            throw new Exception("Expected group='errors' for 'in_err', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'inbound') {
            throw new Exception("Expected direction='inbound' for 'in_err', got '{$result['direction']}'");
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Discards
    // -------------------------------------------------------------------------

    /**
     * Test 10: in_discards → discards / inbound
     */
    public function testInDiscardsIsDiscardsInbound() {
        $result = gnmi_classify_metric('in_discards');
        if ($result['group'] !== 'discards') {
            throw new Exception("Expected group='discards' for 'in_discards', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'inbound') {
            throw new Exception("Expected direction='inbound' for 'in_discards', got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 11: out_drops → discards / outbound (alternate word)
     */
    public function testOutDropsIsDiscardsOutbound() {
        $result = gnmi_classify_metric('out_drops');
        if ($result['group'] !== 'discards') {
            throw new Exception("Expected group='discards' for 'out_drops', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'outbound') {
            throw new Exception("Expected direction='outbound' for 'out_drops', got '{$result['direction']}'");
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Generic fallback
    // -------------------------------------------------------------------------

    /**
     * Test 12: cpu_util → generic / none
     */
    public function testCpuUtilIsGenericNone() {
        $result = gnmi_classify_metric('cpu_util');
        if ($result['group'] !== 'generic') {
            throw new Exception("Expected group='generic' for 'cpu_util', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'none') {
            throw new Exception("Expected direction='none' for 'cpu_util', got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 13: temperature → generic / none
     */
    public function testTemperatureIsGenericNone() {
        $result = gnmi_classify_metric('temperature');
        if ($result['group'] !== 'generic') {
            throw new Exception("Expected group='generic' for 'temperature', got '{$result['group']}'");
        }
        if ($result['direction'] !== 'none') {
            throw new Exception("Expected direction='none' for 'temperature', got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 14: empty string → generic / none
     */
    public function testEmptyStringIsGenericNone() {
        $result = gnmi_classify_metric('');
        if ($result['group'] !== 'generic') {
            throw new Exception("Expected group='generic' for empty string, got '{$result['group']}'");
        }
        if ($result['direction'] !== 'none') {
            throw new Exception("Expected direction='none' for empty string, got '{$result['direction']}'");
        }
        return true;
    }

    /**
     * Test 15: traffic metrics get a deterministic exact-pair graph key
     */
    public function testTrafficGraphKey() {
        $this->assertClassification('in-octets', 'traffic', 'inbound', 'octets');
        $this->assertClassification('out-octets', 'traffic', 'outbound', 'octets');
        return true;
    }

    /**
     * Test 16: normal packet families are exact-paired by base metric key
     */
    public function testBroadcastPacketsGraphKey() {
        $this->assertClassification('in-broadcast-pkts', 'packets', 'inbound', 'broadcast_pkts');
        $this->assertClassification('out-broadcast-pkts', 'packets', 'outbound', 'broadcast_pkts');
        return true;
    }

    /**
     * Test 17: packet-size buckets with octet in the name are packets, not traffic
     */
    public function testPacketSizeBucketsArePackets() {
        $this->assertClassification('in-1519-to-2047-octet-pkts', 'packets', 'inbound', '1519_to_2047_octet_pkts');
        $this->assertClassification('out-1519-to-2047-octet-pkts', 'packets', 'outbound', '1519_to_2047_octet_pkts');
        return true;
    }

    /**
     * Test 18: error/drop event counters share the integrity packet/event key
     */
    public function testIntegrityPacketsGraphKey() {
        $this->assertClassification('in-undersize-pkts', 'errors', 'inbound', 'integrity_packets');
        $this->assertClassification('in-jabber-pkts', 'errors', 'inbound', 'integrity_packets');
        $this->assertClassification('in-dropped-pkts', 'discards', 'inbound', 'integrity_packets');
        $this->assertClassification('out-errors', 'errors', 'outbound', 'integrity_packets');
        return true;
    }

    /**
     * Test 19: error/drop octet counters share the integrity octets key
     */
    public function testIntegrityOctetsGraphKey() {
        $this->assertClassification('in-undersize-octets', 'errors', 'inbound', 'integrity_octets');
        $this->assertClassification('in-jabber-octets', 'errors', 'inbound', 'integrity_octets');
        $this->assertClassification('in-dropped-octets', 'discards', 'inbound', 'integrity_octets');
        $this->assertClassification('out-discards-octets', 'discards', 'outbound', 'integrity_octets');
        return true;
    }
}

// Run tests if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $test = new TestMetricClassification();
    $reflection = new ReflectionClass($test);

    echo "Running Phase 3.4 Metric Classification Tests...\n";
    echo "=================================================\n";

    $passed = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($reflection->getMethods() as $method) {
        if (strpos($method->getName(), 'test') === 0) {
            echo "Running {$method->getName()}... ";
            try {
                $test->setUp();
                $result = $method->invoke($test);
                if ($result === true) {
                    echo "PASS\n";
                    $passed++;
                } elseif (is_string($result) && strpos($result, 'SKIP') === 0) {
                    echo "SKIP: $result\n";
                    $skipped++;
                } else {
                    echo "UNKNOWN\n";
                }
            } catch (Exception $e) {
                echo "FAIL: " . $e->getMessage() . "\n";
                $failed++;
            }
            $test->tearDown();
        }
    }

    echo "\nTest Results:\n";
    echo "=============\n";
    echo "Passed:  $passed\n";
    echo "Skipped: $skipped\n";
    echo "Failed:  $failed\n";
    if ($failed > 0) {
        exit(1);
    }
    echo "\nTest run complete.\n";
}
