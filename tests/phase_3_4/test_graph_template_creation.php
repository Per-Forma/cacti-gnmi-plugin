#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.4 - Graph Template Creation Tests
 *
 * Tests for:
 *   - gnmi_ensure_cdef_bytes_to_bits()
 *   - gnmi_get_color_id()
 *   - gnmi_get_passthrough_graph_template_id()
 *   - gnmi_get_traffic_graph_template_id()
 *   - gnmi_get_packets_graph_template_id()
 *   - gnmi_get_integrity_graph_template_id()
 *
 * Run: php plugins/gnmi/tests/phase_3_4/test_graph_template_creation.php
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');

class TestGraphTemplateCreation {

    // IDs collected during tests for teardown
    private $created_graph_template_ids = array();
    private $created_cdef_ids = array();

    public function setUp() {
        // Clear cached template IDs so each test starts clean
        db_execute("DELETE FROM settings WHERE name IN (
            'gnmi_passthrough_graph_template_id',
            'gnmi_traffic_graph_template_id',
            'gnmi_packets_graph_template_id',
            'gnmi_integrity_packets_graph_template_id',
            'gnmi_integrity_octets_graph_template_id',
            'gnmi_cdef_bytes_to_bits_id'
        )");
        // Remove any gNMI graph templates that may have been created
        $ids = db_fetch_assoc("SELECT id FROM graph_templates WHERE name IN (
            'gNMI - Passthrough',
            'gNMI - Interface Traffic',
            'gNMI - Interface Packets',
            'gNMI - Interface Integrity Packets Events',
            'gNMI - Interface Integrity Octets'
        )");
        foreach ($ids as $row) {
            $this->_delete_graph_template($row['id']);
        }
        // Remove gNMI CDEFs
        db_execute("DELETE FROM cdef WHERE name = 'Turn Bytes into Bits (gNMI)'");
    }

    public function tearDown() {
        // Clear cached IDs
        db_execute("DELETE FROM settings WHERE name IN (
            'gnmi_passthrough_graph_template_id',
            'gnmi_traffic_graph_template_id',
            'gnmi_packets_graph_template_id',
            'gnmi_integrity_packets_graph_template_id',
            'gnmi_integrity_octets_graph_template_id',
            'gnmi_cdef_bytes_to_bits_id'
        )");
        // Remove any gNMI graph templates
        $ids = db_fetch_assoc("SELECT id FROM graph_templates WHERE name IN (
            'gNMI - Passthrough',
            'gNMI - Interface Traffic',
            'gNMI - Interface Packets',
            'gNMI - Interface Integrity Packets Events',
            'gNMI - Interface Integrity Octets'
        )");
        foreach ($ids as $row) {
            $this->_delete_graph_template($row['id']);
        }
        db_execute("DELETE FROM cdef WHERE name = 'Turn Bytes into Bits (gNMI)'");
    }

    private function _delete_graph_template($id) {
        db_execute_prepared('DELETE FROM graph_templates_item WHERE graph_template_id = ?', array($id));
        db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_input_id IN (
            SELECT id FROM graph_template_input WHERE graph_template_id = ?)', array($id));
        db_execute_prepared('DELETE FROM graph_template_input WHERE graph_template_id = ?', array($id));
        db_execute_prepared('DELETE FROM graph_templates_graph WHERE graph_template_id = ?', array($id));
        db_execute_prepared('DELETE FROM graph_templates WHERE id = ?', array($id));
    }

    // -------------------------------------------------------------------------
    // CDEF
    // -------------------------------------------------------------------------

    /**
     * Test 1: gnmi_ensure_cdef_bytes_to_bits() returns an integer ID
     */
    public function testCdefBytesToBitsReturnsId() {
        $id = gnmi_ensure_cdef_bytes_to_bits();
        if (!is_int($id) || $id <= 0) {
            throw new Exception("Expected int > 0 from gnmi_ensure_cdef_bytes_to_bits(), got: " . var_export($id, true));
        }
        return true;
    }

    /**
     * Test 2: CDEF row exists in cdef table with correct name
     */
    public function testCdefRowExists() {
        $id = gnmi_ensure_cdef_bytes_to_bits();
        $row = db_fetch_row_prepared('SELECT * FROM cdef WHERE id = ?', array($id));
        if (!$row) {
            throw new Exception("CDEF row not found for id=$id");
        }
        if (strpos($row['name'], 'Bytes into Bits') === false) {
            throw new Exception("Expected 'Bytes into Bits' in CDEF name, got: {$row['name']}");
        }
        return true;
    }

    /**
     * Test 3: CDEF has three items (CURRENT_DATA_SOURCE, literal '8', multiply operator)
     */
    public function testCdefHasTwoItems() {
        $id = gnmi_ensure_cdef_bytes_to_bits();
        $items = db_fetch_assoc_prepared('SELECT * FROM cdef_items WHERE cdef_id = ? ORDER BY sequence', array($id));
        if (count($items) < 3) {
            throw new Exception("Expected 3 CDEF items, got " . count($items));
        }
        // type 4 = CURRENT_DATA_SOURCE, type 6 = custom string, type 2 = operator
        $types = array_column($items, 'type');
        if (!in_array(4, $types)) {
            throw new Exception("Missing CURRENT_DATA_SOURCE (type=4) CDEF item");
        }
        $values = array_column($items, 'value');
        if (!in_array('8', $values)) {
            throw new Exception("Missing multiplier '8' CDEF item value");
        }
        // Multiply operator: type=2, value='3' (3=*)
        $has_multiply = false;
        foreach ($items as $item) {
            if ((int)$item['type'] === 2 && $item['value'] === '3') { $has_multiply = true; break; }
        }
        if (!$has_multiply) {
            throw new Exception("Missing multiply operator (type=2, value='3') CDEF item");
        }
        return true;
    }

    /**
     * Test 4: gnmi_ensure_cdef_bytes_to_bits() is idempotent
     */
    public function testCdefIsIdempotent() {
        $id1 = gnmi_ensure_cdef_bytes_to_bits();
        $id2 = gnmi_ensure_cdef_bytes_to_bits();
        if ($id1 !== $id2) {
            throw new Exception("Expected same CDEF ID on repeated calls, got $id1 then $id2");
        }
        $count = db_fetch_cell("SELECT COUNT(*) FROM cdef WHERE name = 'Turn Bytes into Bits (gNMI)'");
        if ((int)$count !== 1) {
            throw new Exception("Expected exactly 1 CDEF row, got $count");
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Color helper
    // -------------------------------------------------------------------------

    /**
     * Test 5: gnmi_get_color_id() returns a valid ID for standard green
     */
    public function testColorIdGreenReturnsId() {
        $id = gnmi_get_color_id('00CF00');
        if (!is_int($id) || $id <= 0) {
            throw new Exception("Expected int > 0 for color 00CF00, got: " . var_export($id, true));
        }
        $row = db_fetch_row_prepared('SELECT hex FROM colors WHERE id = ?', array($id));
        if (!$row) {
            throw new Exception("Color row not found for id=$id");
        }
        if (strtoupper($row['hex']) !== '00CF00') {
            throw new Exception("Expected hex=00CF00, got {$row['hex']}");
        }
        return true;
    }

    /**
     * Test 6: gnmi_get_color_id() returns a valid ID for standard blue
     */
    public function testColorIdBlueReturnsId() {
        $id = gnmi_get_color_id('0000FF');
        if (!is_int($id) || $id <= 0) {
            throw new Exception("Expected int > 0 for color 0000FF, got: " . var_export($id, true));
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Passthrough graph template
    // -------------------------------------------------------------------------

    /**
     * Test 7: gnmi_get_passthrough_graph_template_id() returns int > 0
     */
    public function testPassthroughTemplateReturnsId() {
        $id = gnmi_get_passthrough_graph_template_id();
        if (!is_int($id) || $id <= 0) {
            throw new Exception("Expected int > 0 from gnmi_get_passthrough_graph_template_id(), got: " . var_export($id, true));
        }
        return true;
    }

    /**
     * Test 8: Passthrough template row exists in graph_templates
     */
    public function testPassthroughTemplateRowExists() {
        $id = gnmi_get_passthrough_graph_template_id();
        $row = db_fetch_row_prepared('SELECT * FROM graph_templates WHERE id = ?', array($id));
        if (!$row) {
            throw new Exception("graph_templates row not found for id=$id");
        }
        if ($row['name'] !== 'gNMI - Passthrough') {
            throw new Exception("Expected name='gNMI - Passthrough', got '{$row['name']}'");
        }
        return true;
    }

    /**
     * Test 9: Passthrough template has graph_templates_graph row (local_graph_id=0)
     */
    public function testPassthroughTemplateHasGraphRow() {
        $id = gnmi_get_passthrough_graph_template_id();
        $row = db_fetch_row_prepared(
            'SELECT * FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0',
            array($id)
        );
        if (!$row) {
            throw new Exception("graph_templates_graph template row not found for template $id");
        }
        return true;
    }

    /**
     * Test 10: Passthrough template has at least 2 items (LINE + GPRINT)
     */
    public function testPassthroughTemplateHasItems() {
        $id = gnmi_get_passthrough_graph_template_id();
        $items = db_fetch_assoc_prepared(
            'SELECT * FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0 ORDER BY sequence',
            array($id)
        );
        if (count($items) < 2) {
            throw new Exception("Expected >= 2 graph_templates_item rows, got " . count($items));
        }
        $types = array_column($items, 'graph_type_id');
        // Cacti 1.2.x: LINE1=4, LINE2=5, LINE3=6 (legacy: 2=HRULE, 3=VRULE)
        if (!in_array(4, $types) && !in_array(5, $types) && !in_array(6, $types)) {
            throw new Exception("Expected at least one LINE item (type 4/5/6) in passthrough template");
        }
        // GPRINT variants: 9=GPRINT, 11=GPRINT:LAST, 12=GPRINT:MAX, 13=GPRINT:MIN, 14=GPRINT:AVERAGE
        $has_gprint = false;
        foreach ($types as $t) {
            if (in_array($t, array(9, 11, 12, 13, 14))) { $has_gprint = true; break; }
        }
        if (!$has_gprint) {
            throw new Exception("Expected at least one GPRINT item in passthrough template");
        }
        return true;
    }

    /**
     * Test 11: Passthrough template is idempotent
     */
    public function testPassthroughTemplateIsIdempotent() {
        $id1 = gnmi_get_passthrough_graph_template_id();
        $id2 = gnmi_get_passthrough_graph_template_id();
        if ($id1 !== $id2) {
            throw new Exception("Expected same ID on repeated calls, got $id1 then $id2");
        }
        $count = db_fetch_cell("SELECT COUNT(*) FROM graph_templates WHERE name = 'gNMI - Passthrough'");
        if ((int)$count !== 1) {
            throw new Exception("Expected exactly 1 passthrough template, got $count");
        }
        return true;
    }

    /**
     * Test 12: Passthrough template ID is cached in settings
     */
    public function testPassthroughTemplateIdIsCached() {
        $id = gnmi_get_passthrough_graph_template_id();
        $cached = db_fetch_cell("SELECT value FROM settings WHERE name = 'gnmi_passthrough_graph_template_id'");
        if ((int)$cached !== $id) {
            throw new Exception("Settings cache mismatch: expected $id, got $cached");
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Traffic graph template
    // -------------------------------------------------------------------------

    /**
     * Test 13: gnmi_get_traffic_graph_template_id() returns int > 0
     */
    public function testTrafficTemplateReturnsId() {
        $id = gnmi_get_traffic_graph_template_id();
        if (!is_int($id) || $id <= 0) {
            throw new Exception("Expected int > 0 from gnmi_get_traffic_graph_template_id(), got: " . var_export($id, true));
        }
        return true;
    }

    /**
     * Test 14: Traffic template row exists with correct name
     */
    public function testTrafficTemplateRowExists() {
        $id = gnmi_get_traffic_graph_template_id();
        $row = db_fetch_row_prepared('SELECT * FROM graph_templates WHERE id = ?', array($id));
        if (!$row) {
            throw new Exception("graph_templates row not found for id=$id");
        }
        if ($row['name'] !== 'gNMI - Interface Traffic') {
            throw new Exception("Expected name='gNMI - Interface Traffic', got '{$row['name']}'");
        }
        return true;
    }

    /**
     * Test 15: Traffic template has 2 graph_template_input slots (inbound + outbound)
     */
    public function testTrafficTemplateHasTwoInputSlots() {
        $id = gnmi_get_traffic_graph_template_id();
        $inputs = db_fetch_assoc_prepared(
            'SELECT * FROM graph_template_input WHERE graph_template_id = ?',
            array($id)
        );
        if (count($inputs) !== 2) {
            throw new Exception("Expected 2 graph_template_input rows, got " . count($inputs));
        }
        $names = array_column($inputs, 'name');
        $has_inbound  = false;
        $has_outbound = false;
        foreach ($names as $n) {
            if (stripos($n, 'inbound') !== false || stripos($n, 'in') !== false) $has_inbound = true;
            if (stripos($n, 'outbound') !== false || stripos($n, 'out') !== false) $has_outbound = true;
        }
        if (!$has_inbound)  throw new Exception("Missing inbound input slot in traffic template");
        if (!$has_outbound) throw new Exception("Missing outbound input slot in traffic template");
        return true;
    }

    /**
     * Test 16: Traffic template has >= 8 items (LINE1 + AREA + 3xGPRINT + LINE1 + AREA + 3xGPRINT)
     */
    public function testTrafficTemplateHasSufficientItems() {
        $id = gnmi_get_traffic_graph_template_id();
        $items = db_fetch_assoc_prepared(
            'SELECT * FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0',
            array($id)
        );
        if (count($items) < 8) {
            throw new Exception("Expected >= 8 graph_templates_item rows, got " . count($items));
        }
        $types = array_column($items, 'graph_type_id');
        // Cacti 1.2.x: AREA=7
        if (!in_array(7, $types)) {
            throw new Exception("Expected at least one AREA item (type 7) in traffic template");
        }
        // LINE1=4 (thin line showing MAX value)
        if (!in_array(4, $types)) {
            throw new Exception("Expected at least one LINE1 item (type 4) in traffic template");
        }
        // AREA items must use 50% alpha (7F) for the min/max visualization
        $area_items = array_filter($items, function($i) { return (int)$i['graph_type_id'] === 7; });
        $has_transparent_area = false;
        foreach ($area_items as $ai) {
            if (strtoupper($ai['alpha']) === '7F') { $has_transparent_area = true; break; }
        }
        if (!$has_transparent_area) {
            throw new Exception("Expected at least one semi-transparent AREA item (alpha=7F) in traffic template");
        }
        // GPRINT variants: 9=GPRINT, 11=GPRINT:LAST, 12=GPRINT:MAX, 13=GPRINT:MIN, 14=GPRINT:AVERAGE
        $gprint_count = 0;
        foreach ($types as $t) {
            if (in_array($t, array(9, 11, 12, 13, 14))) $gprint_count++;
        }
        if ($gprint_count < 4) {
            throw new Exception("Expected at least 4 GPRINT items in traffic template, got $gprint_count");
        }
        return true;
    }

    /**
     * Test 17: Traffic template is idempotent
     */
    public function testTrafficTemplateIsIdempotent() {
        $id1 = gnmi_get_traffic_graph_template_id();
        $id2 = gnmi_get_traffic_graph_template_id();
        if ($id1 !== $id2) {
            throw new Exception("Expected same ID on repeated calls, got $id1 then $id2");
        }
        $count = db_fetch_cell("SELECT COUNT(*) FROM graph_templates WHERE name = 'gNMI - Interface Traffic'");
        if ((int)$count !== 1) {
            throw new Exception("Expected exactly 1 traffic template, got $count");
        }
        return true;
    }

    /**
     * Test 18: Packet pair template exists and does not use a CDEF
     */
    public function testPacketTemplateHasNoCdef() {
        $id = gnmi_get_packets_graph_template_id();
        if (!is_int($id) || $id <= 0) {
            throw new Exception("Expected packet template id > 0");
        }
        $row = db_fetch_row_prepared('SELECT name FROM graph_templates WHERE id = ?', array($id));
        if (!$row || $row['name'] !== 'gNMI - Interface Packets') {
            throw new Exception("Packet template row missing or has wrong name");
        }
        $cdef_count = db_fetch_cell_prepared(
            'SELECT COUNT(*) FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0 AND cdef_id > 0',
            array($id)
        );
        if ((int)$cdef_count !== 0) {
            throw new Exception("Packet template should not use a bytes-to-bits CDEF");
        }
        return true;
    }

    /**
     * Test 19: Integrity templates exist as dynamic graph shells
     */
    public function testIntegrityTemplatesReturnIds() {
        $packets_id = gnmi_get_integrity_graph_template_id('integrity_packets');
        $octets_id = gnmi_get_integrity_graph_template_id('integrity_octets');
        if (!is_int($packets_id) || $packets_id <= 0 || !is_int($octets_id) || $octets_id <= 0) {
            throw new Exception("Expected integrity template IDs > 0");
        }
        if ($packets_id === $octets_id) {
            throw new Exception("Integrity packet and octet templates should be separate");
        }
        foreach (array($packets_id, $octets_id) as $id) {
            $row = db_fetch_row_prepared(
                'SELECT * FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0',
                array($id)
            );
            if (!$row) {
                throw new Exception("Missing graph_templates_graph row for integrity template $id");
            }
        }
        return true;
    }
}

// Run tests if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $test = new TestGraphTemplateCreation();
    $reflection = new ReflectionClass($test);

    echo "Running Phase 3.4 Graph Template Creation Tests...\n";
    echo "===================================================\n";

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
