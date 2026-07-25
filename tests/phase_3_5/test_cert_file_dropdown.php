#!/usr/bin/env php
<?php
/**
 * gNMI Plugin Phase 3.5 - Cert File Dropdown Tests
 *
 * Tests for gnmi_list_cert_files() pure function.
 * No database required — uses a temporary directory as the cert dir.
 *
 * Run: php plugins/gnmi/tests/phase_3_5/test_cert_file_dropdown.php
 */

// Stub Cacti globals so form_functions.php can be loaded standalone.
if (!defined('POLLER_VERBOSITY_LOW')) define('POLLER_VERBOSITY_LOW', 1);
if (!defined('POLLER_VERBOSITY_MEDIUM')) define('POLLER_VERBOSITY_MEDIUM', 2);
if (!defined('POLLER_VERBOSITY_DEBUG')) define('POLLER_VERBOSITY_DEBUG', 5);
if (!function_exists('cacti_log')) {
    function cacti_log($msg, $output = false, $facility = 'POLLER', $level = 1) {}
}
if (!function_exists('html_escape')) {
    function html_escape($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sanitize_search_string')) {
    function sanitize_search_string($str) { return $str; }
}
if (!function_exists('db_fetch_row_prepared')) {
    function db_fetch_row_prepared($sql, $args = []) { return []; }
}
if (!function_exists('db_fetch_cell_prepared')) {
    function db_fetch_cell_prepared($sql, $args = []) { return null; }
}
if (!function_exists('db_execute_prepared')) {
    function db_execute_prepared($sql, $args = []) { return true; }
}

// Set up $config with a temp base_path — tests will override runtime cert dir via $config
$tmp_base = sys_get_temp_dir() . '/gnmi_test_' . getmypid();
$GLOBALS['config'] = ['base_path' => $tmp_base];

// Load form_functions.php (suppress errors from missing Cacti symbols at parse time)
$form_functions_path = dirname(dirname(dirname(__FILE__))) . '/include/form_functions.php';
if (file_exists($form_functions_path)) {
    $prev = error_reporting(0);
    require_once($form_functions_path);
    error_reporting($prev);
}

class TestCertFileDropdown {

    private $cert_dir;

    public function setUp() {
        // Create a fresh temp cert directory for each test
        $this->cert_dir = sys_get_temp_dir() . '/gnmi_certs_' . getmypid() . '_' . mt_rand();
        mkdir($this->cert_dir, 0700, true);
        $GLOBALS['config']['base_path'] = dirname($this->cert_dir);
        // Rename the dir to match what gnmi_list_cert_files() expects:
        // base_path/plugins/gnmi/runtime/certs/
        // Instead, point base_path so that path resolves to our temp dir.
        $nested = $this->cert_dir . '/plugins/gnmi/runtime/certs';
        mkdir($nested, 0700, true);
        $this->cert_dir = $nested;
        $GLOBALS['config']['base_path'] = dirname(dirname(dirname(dirname($nested))));
    }

    public function tearDown() {
        // Remove temp tree
        $base = $GLOBALS['config']['base_path'];
        if (strpos($base, sys_get_temp_dir()) === 0) {
            $this->rrmdir($base);
        }
    }

    private function rrmdir($dir) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function touch_file($name) {
        file_put_contents($this->cert_dir . '/' . $name, '');
    }

    // -------------------------------------------------------------------------
    // Test 1: cert type returns .pem, .crt, .cer files; excludes .key
    // -------------------------------------------------------------------------

    public function testCertTypeReturnsPemCrtCerOnly() {
        $this->touch_file('ca.pem');
        $this->touch_file('client.crt');
        $this->touch_file('server.cer');
        $this->touch_file('client.key');    // must be excluded
        $this->touch_file('notes.txt');     // must be excluded

        $result = gnmi_list_cert_files('cert');

        if (in_array($this->cert_dir . '/client.key', $result)) {
            throw new Exception('.key file should NOT appear in cert-type results');
        }
        if (in_array($this->cert_dir . '/notes.txt', $result)) {
            throw new Exception('.txt file should NOT appear in cert-type results');
        }
        if (!in_array($this->cert_dir . '/ca.pem', $result)) {
            throw new Exception('.pem file should appear in cert-type results');
        }
        if (!in_array($this->cert_dir . '/client.crt', $result)) {
            throw new Exception('.crt file should appear in cert-type results');
        }
        if (!in_array($this->cert_dir . '/server.cer', $result)) {
            throw new Exception('.cer file should appear in cert-type results');
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Test 2: key type returns .pem and .key files; excludes .crt, .cer
    // -------------------------------------------------------------------------

    public function testKeyTypeReturnsPemKeyOnly() {
        $this->touch_file('client.key');
        $this->touch_file('server.pem');
        $this->touch_file('ca.crt');        // must be excluded
        $this->touch_file('server.cer');    // must be excluded

        $result = gnmi_list_cert_files('key');

        if (in_array($this->cert_dir . '/ca.crt', $result)) {
            throw new Exception('.crt file should NOT appear in key-type results');
        }
        if (in_array($this->cert_dir . '/server.cer', $result)) {
            throw new Exception('.cer file should NOT appear in key-type results');
        }
        if (!in_array($this->cert_dir . '/client.key', $result)) {
            throw new Exception('.key file should appear in key-type results');
        }
        if (!in_array($this->cert_dir . '/server.pem', $result)) {
            throw new Exception('.pem file should appear in key-type results');
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Test 3: nonexistent directory returns empty array
    // -------------------------------------------------------------------------

    public function testNonexistentDirReturnsEmptyArray() {
        // Point base_path at a location that has no certs/ subdir
        $GLOBALS['config']['base_path'] = '/nonexistent_path_that_cannot_exist_xyz';

        $result = gnmi_list_cert_files('cert');

        if (!is_array($result)) {
            throw new Exception('Expected an array, got ' . gettype($result));
        }
        if (count($result) !== 0) {
            throw new Exception('Expected empty array for nonexistent dir, got ' . count($result) . ' items');
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Test 4: returned values are full absolute paths
    // -------------------------------------------------------------------------

    public function testReturnsFullAbsolutePaths() {
        $this->touch_file('ca.pem');

        $result = gnmi_list_cert_files('cert');

        if (empty($result)) {
            throw new Exception('Expected at least one result but got none');
        }
        foreach ($result as $path) {
            if ($path[0] !== '/') {
                throw new Exception("Expected absolute path, got: $path");
            }
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Test 5: results are sorted and deduplicated
    // -------------------------------------------------------------------------

    public function testResultsAreSortedAndUnique() {
        $this->touch_file('z_last.pem');
        $this->touch_file('a_first.pem');
        $this->touch_file('m_middle.crt');

        $result = gnmi_list_cert_files('cert');

        if (count($result) !== count(array_unique($result))) {
            throw new Exception('Result contains duplicate entries');
        }

        $sorted = $result;
        sort($sorted);
        if ($result !== $sorted) {
            throw new Exception('Result is not sorted alphabetically');
        }

        // Verify a_first comes before z_last
        $pos_a = array_search($this->cert_dir . '/a_first.pem', $result);
        $pos_z = array_search($this->cert_dir . '/z_last.pem', $result);
        if ($pos_a === false || $pos_z === false || $pos_a >= $pos_z) {
            throw new Exception('Sort order incorrect: a_first.pem should come before z_last.pem');
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Test 6: empty directory returns empty array
    // -------------------------------------------------------------------------

    public function testEmptyDirReturnsEmptyArray() {
        // cert_dir exists but has no files
        $result = gnmi_list_cert_files('cert');

        if (!is_array($result)) {
            throw new Exception('Expected array, got ' . gettype($result));
        }
        if (count($result) !== 0) {
            throw new Exception('Expected empty array for empty dir, got ' . count($result) . ' items');
        }
        return true;
    }
}

// Run tests if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $test = new TestCertFileDropdown();
    $reflection = new ReflectionClass($test);

    echo "Running Phase 3.5 Cert File Dropdown Tests...\n";
    echo "==============================================\n";

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
