#!/usr/bin/env php
<?php
/**
 * gNMI RRD path and recovery regression tests.
 *
 * Run all tests:
 *   php plugins/gnmi/tests/phase_3_3/test_rrd_file_recovery.php
 *
 * Run one test:
 *   php plugins/gnmi/tests/phase_3_3/test_rrd_file_recovery.php testZeroByteRrdIsRecovered
 */

require_once('/var/www/html/cacti/include/global.php');
require_once('/var/www/html/cacti/lib/database.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/functions.php');
require_once('/var/www/html/cacti/plugins/gnmi/include/subscription_functions.php');

class TestRrdFileRecovery {

    private $device_id = null;
    private $subscription_id = null;
    private $metric_id = null;
    private $local_data_id = null;
    private $rrd_path = null;
    private $template_data_id = null;
    private $template_path_original = null;
    private $test_hostname = null;

    public function setUp() {
        $this->test_hostname = 'rrd-recovery-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.example.com';

        db_execute_prepared(
            "INSERT INTO plugin_gnmi_devices
                (host_id, enabled, hostname, port, username, password, use_tls, collection_interval, encoding)
             VALUES (1, 1, ?, 9339, 'u', 'p', 1, 10, 'JSON_IETF')",
            array($this->test_hostname)
        );
        $this->device_id = (int)db_fetch_insert_id();
        if (!$this->device_id) {
            throw new Exception('Failed to create test device');
        }

        $this->subscription_id = gnmi_create_subscription(
            $this->device_id,
            'test/rrd/recovery',
            'test-interface',
            array('auto_create_datasources' => 0)
        );
        if (!$this->subscription_id) {
            throw new Exception('Failed to create test subscription');
        }

        db_execute_prepared(
            'UPDATE plugin_gnmi_subscriptions
             SET auto_create_datasources = 0, auto_create_graphs = 0
             WHERE id = ?',
            array($this->subscription_id)
        );

        $this->metric_id = gnmi_add_metric_to_subscription(
            $this->subscription_id,
            'test-counter',
            'COUNTER'
        );
        if (!$this->metric_id) {
            throw new Exception('Failed to create test metric');
        }
    }

    public function tearDown() {
        if ($this->template_data_id && $this->template_path_original !== null) {
            db_execute_prepared(
                'UPDATE data_template_data SET data_source_path = ? WHERE id = ?',
                array($this->template_path_original, $this->template_data_id)
            );
        }

        $this->removeRrdArtifacts();

        if ($this->local_data_id) {
            db_execute_prepared(
                'DELETE FROM poller_item WHERE local_data_id = ?',
                array($this->local_data_id)
            );
            db_execute_prepared(
                'DELETE FROM data_template_rrd WHERE local_data_id = ?',
                array($this->local_data_id)
            );
            db_execute_prepared(
                'DELETE FROM data_input_data
                 WHERE data_template_data_id IN (
                     SELECT id FROM data_template_data WHERE local_data_id = ?
                 )',
                array($this->local_data_id)
            );
            db_execute_prepared(
                'DELETE FROM data_template_data WHERE local_data_id = ?',
                array($this->local_data_id)
            );
            db_execute_prepared(
                'DELETE FROM data_local WHERE id = ?',
                array($this->local_data_id)
            );
        }

        if ($this->metric_id) {
            db_execute_prepared(
                'DELETE FROM plugin_gnmi_metrics WHERE id = ?',
                array($this->metric_id)
            );
        }
        if ($this->subscription_id) {
            db_execute_prepared(
                'DELETE FROM plugin_gnmi_subscriptions WHERE id = ?',
                array($this->subscription_id)
            );
        }
        if ($this->device_id) {
            db_execute_prepared(
                'DELETE FROM plugin_gnmi_devices WHERE id = ?',
                array($this->device_id)
            );
        }

        $this->device_id = null;
        $this->subscription_id = null;
        $this->metric_id = null;
        $this->local_data_id = null;
        $this->rrd_path = null;
        $this->template_data_id = null;
        $this->template_path_original = null;
        $this->test_hostname = null;
    }

    private function assertTrue($condition, $message) {
        if (!$condition) {
            throw new Exception($message);
        }
    }

    private function assertSame($expected, $actual, $message) {
        if ($expected !== $actual) {
            throw new Exception(
                $message . '; expected ' . var_export($expected, true) .
                ', got ' . var_export($actual, true)
            );
        }
    }

    private function createDataSource() {
        $this->local_data_id = (int)gnmi_create_data_source_for_metric($this->metric_id);
        if (!$this->local_data_id) {
            throw new Exception('Failed to create test data source');
        }

        $this->rrd_path = get_data_source_path($this->local_data_id, true);
        if (empty($this->rrd_path)) {
            throw new Exception('Failed to resolve test RRD path');
        }

        $rrd_dir = dirname($this->rrd_path);
        if (!is_dir($rrd_dir) && !mkdir($rrd_dir, 0775, true) && !is_dir($rrd_dir)) {
            throw new Exception("Failed to create test RRD directory $rrd_dir");
        }

        return $this->local_data_id;
    }

    private function removeRrdArtifacts() {
        if (!$this->rrd_path) {
            return;
        }

        foreach (array(
            $this->rrd_path,
            $this->rrd_path . '.invalid',
            $this->rrd_path . '.recovery-pending'
        ) as $path) {
            if (file_exists($path) || is_link($path)) {
                @unlink($path);
            }
        }

        foreach (glob($this->rrd_path . '.replacement.*') ?: array() as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
    }

    private function writeFile($path, $content) {
        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            throw new Exception("Failed to write test file $path");
        }
        clearstatcache(true, $path);
    }

    private function buildUpdateCommand($epoch, $value) {
        return 'rrdtool update --skip-past-updates ' .
            escapeshellarg($this->rrd_path) . ' ' .
            escapeshellarg($epoch . ':' . $value);
    }

    public function testPerMetricDatasourcePersistsExactPath() {
        $this->createDataSource();

        $stored_path = db_fetch_cell_prepared(
            'SELECT data_source_path FROM data_template_data WHERE local_data_id = ?',
            array($this->local_data_id)
        );
        $expected_path = sprintf('<path_rra>/%d/%d.rrd', 1, $this->local_data_id);

        $this->assertSame(
            $expected_path,
            $stored_path,
            'Per-metric datasource did not persist the exact tokenized RRD path'
        );
        $this->assertTrue(
            strpos($stored_path, '|host_id|') === false &&
            strpos($stored_path, '|local_data_id|') === false,
            'Per-metric datasource path contains unresolved template placeholders'
        );

        return true;
    }

    public function testTemplatePathPatternIsReconciled() {
        $template_id = gnmi_get_passthrough_data_template_id();
        $this->assertTrue((int)$template_id > 0, 'Passthrough data template was not available');

        $row = db_fetch_row_prepared(
            'SELECT id, data_source_path
             FROM data_template_data
             WHERE data_template_id = ? AND local_data_id = 0
             LIMIT 1',
            array($template_id)
        );
        $this->assertTrue(!empty($row), 'Passthrough template data row was not found');

        $this->template_data_id = (int)$row['id'];
        $this->template_path_original = $row['data_source_path'];

        db_execute_prepared(
            'UPDATE data_template_data SET data_source_path = ? WHERE id = ?',
            array('<path_rra>|host_id|/|local_data_id|.rrd', $this->template_data_id)
        );

        // Exercise the cached-ID path as well as the existing-row path.
        gnmi_get_passthrough_data_template_id();
        $actual_path = db_fetch_cell_prepared(
            'SELECT data_source_path FROM data_template_data WHERE id = ?',
            array($this->template_data_id)
        );

        $this->assertSame(
            '<path_rra>/|host_id|/|local_data_id|.rrd',
            $actual_path,
            'Passthrough template path was not reconciled'
        );

        return true;
    }

    public function testZeroByteRrdIsRecovered() {
        $this->createDataSource();
        $this->writeFile($this->rrd_path, '');

        $result = gnmi_prepare_rrd_file(
            $this->rrd_path,
            $this->local_data_id,
            'zero-byte test',
            time()
        );

        $this->assertTrue($result === true, 'Zero-byte RRD recovery returned false');
        $this->assertTrue(
            is_file($this->rrd_path . '.invalid'),
            'Zero-byte RRD was not preserved as the bounded invalid backup'
        );
        $this->assertTrue(
            filesize($this->rrd_path . '.invalid') === 0,
            'Zero-byte invalid backup unexpectedly contains data'
        );
        $this->assertTrue(
            gnmi_rrd_is_valid($this->rrd_path),
            'Replacement RRD is not structurally valid'
        );
        $this->assertTrue(
            !file_exists($this->rrd_path . '.recovery-pending'),
            'Recovery marker remained after successful recovery'
        );

        return true;
    }

    public function testNonzeroCorruptRrdRecoversAfterUpdateFailure() {
        $this->createDataSource();
        $this->writeFile($this->rrd_path, 'not an rrd');
        $epoch = time();

        $result = gnmi_update_rrd_with_recovery(
            $this->buildUpdateCommand($epoch, 12345),
            $this->rrd_path,
            $this->local_data_id,
            'corrupt update test',
            $epoch
        );

        $this->assertTrue($result === true, 'Corrupt RRD was not recreated and retried');
        $this->assertTrue(
            file_get_contents($this->rrd_path . '.invalid') === 'not an rrd',
            'Original corrupt RRD was not preserved'
        );
        $this->assertTrue(
            gnmi_rrd_is_valid($this->rrd_path),
            'Recovered RRD is not structurally valid'
        );

        return true;
    }

    public function testValidRrdIsNotReplacedForDataError() {
        $this->createDataSource();
        $this->assertTrue(
            gnmi_create_rrd_file(
                $this->rrd_path,
                $this->local_data_id,
                'valid update error test',
                time()
            ),
            'Failed to create valid test RRD'
        );

        clearstatcache(true, $this->rrd_path);
        $inode_before = fileinode($this->rrd_path);
        $hash_before = hash_file('sha256', $this->rrd_path);
        $invalid_command = 'rrdtool update ' . escapeshellarg($this->rrd_path) .
            ' ' . escapeshellarg(time() . ':1:2');

        $result = gnmi_update_rrd_with_recovery(
            $invalid_command,
            $this->rrd_path,
            $this->local_data_id,
            'valid data error test',
            time()
        );

        clearstatcache(true, $this->rrd_path);
        $this->assertTrue($result === false, 'Invalid update unexpectedly succeeded');
        $this->assertSame(
            $inode_before,
            fileinode($this->rrd_path),
            'Valid RRD inode changed after a data error'
        );
        $this->assertSame(
            $hash_before,
            hash_file('sha256', $this->rrd_path),
            'Valid RRD contents changed after a data error'
        );
        $this->assertTrue(
            !file_exists($this->rrd_path . '.invalid'),
            'Valid RRD was incorrectly quarantined'
        );

        return true;
    }

    public function testRecoveryFailureIsThrottledAndBackupIsBounded() {
        $this->createDataSource();
        db_execute_prepared(
            'UPDATE data_template_rrd SET rrd_heartbeat = 0 WHERE local_data_id = ?',
            array($this->local_data_id)
        );
        $this->writeFile($this->rrd_path, 'persistently corrupt');
        $epoch = time();
        $update_command = $this->buildUpdateCommand($epoch, 1);

        $first_result = gnmi_update_rrd_with_recovery(
            $update_command,
            $this->rrd_path,
            $this->local_data_id,
            'throttle test',
            $epoch
        );
        $this->assertTrue($first_result === false, 'Invalid metadata recovery unexpectedly succeeded');
        $this->assertTrue(
            is_file($this->rrd_path . '.invalid'),
            'Initial corrupt file was not preserved'
        );
        $this->assertTrue(
            is_file($this->rrd_path . '.recovery-pending'),
            'Failed recovery did not leave a cooldown marker'
        );
        $marker_mtime = filemtime($this->rrd_path . '.recovery-pending');
        $backup_hash = hash_file('sha256', $this->rrd_path . '.invalid');

        $second_result = gnmi_update_rrd_with_recovery(
            $update_command,
            $this->rrd_path,
            $this->local_data_id,
            'throttle test',
            $epoch
        );

        clearstatcache(true, $this->rrd_path . '.recovery-pending');
        $this->assertTrue($second_result === false, 'Throttled recovery unexpectedly succeeded');
        $this->assertSame(
            $marker_mtime,
            filemtime($this->rrd_path . '.recovery-pending'),
            'Cooldown marker changed during a throttled retry'
        );
        $this->assertSame(
            $backup_hash,
            hash_file('sha256', $this->rrd_path . '.invalid'),
            'Bounded invalid backup was replaced during a throttled retry'
        );
        $this->assertSame(
            1,
            count(glob($this->rrd_path . '.invalid*')),
            'More than one invalid backup was retained'
        );

        return true;
    }

    public function testCreateUsesFallbackStartWithoutOldestEpoch() {
        $this->createDataSource();
        $this->removeRrdArtifacts();
        $before = time();

        $result = gnmi_create_rrd_file(
            $this->rrd_path,
            $this->local_data_id,
            'fallback start test',
            null
        );

        $after = time();
        $last_update = (int)trim(shell_exec(
            'rrdtool last ' . escapeshellarg($this->rrd_path)
        ));

        $this->assertTrue($result === true, 'RRD creation with a null oldest epoch failed');
        $this->assertTrue(
            gnmi_rrd_is_valid($this->rrd_path),
            'Fallback-start RRD is not structurally valid'
        );
        $this->assertTrue(
            $last_update >= ($before - 11) && $last_update <= $after,
            "Fallback start time $last_update was outside the expected range"
        );

        return true;
    }

    public function testCreateRefusesExistingRrdPath() {
        $this->createDataSource();
        $this->assertTrue(
            gnmi_create_rrd_file(
                $this->rrd_path,
                $this->local_data_id,
                'existing target test',
                time()
            ),
            'Failed to create initial valid RRD'
        );

        clearstatcache(true, $this->rrd_path);
        $inode_before = fileinode($this->rrd_path);
        $hash_before = hash_file('sha256', $this->rrd_path);

        $result = gnmi_create_rrd_file(
            $this->rrd_path,
            $this->local_data_id,
            'existing target test',
            time()
        );

        clearstatcache(true, $this->rrd_path);
        $this->assertTrue($result === false, 'Creation unexpectedly replaced an existing RRD');
        $this->assertSame(
            $inode_before,
            fileinode($this->rrd_path),
            'Existing RRD inode changed during refused creation'
        );
        $this->assertSame(
            $hash_before,
            hash_file('sha256', $this->rrd_path),
            'Existing RRD contents changed during refused creation'
        );

        return true;
    }

    public function testRepeatedCorruptionFailsClosedWithoutDeletingEitherFile() {
        $this->createDataSource();
        $invalid_path = $this->rrd_path . '.invalid';
        $first_corruption = 'first corrupted database';
        $second_corruption = 'second corrupted database';

        $this->writeFile($invalid_path, $first_corruption);
        $this->writeFile($this->rrd_path, $second_corruption);

        clearstatcache(true, $invalid_path);
        clearstatcache(true, $this->rrd_path);
        $invalid_inode_before = fileinode($invalid_path);
        $invalid_hash_before = hash_file('sha256', $invalid_path);
        $current_inode_before = fileinode($this->rrd_path);
        $current_hash_before = hash_file('sha256', $this->rrd_path);

        $result = gnmi_update_rrd_with_recovery(
            $this->buildUpdateCommand(time(), 1),
            $this->rrd_path,
            $this->local_data_id,
            'repeated corruption test',
            time()
        );

        clearstatcache(true, $invalid_path);
        clearstatcache(true, $this->rrd_path);
        $this->assertTrue($result === false, 'Repeated corruption did not fail closed');
        $this->assertTrue(is_file($invalid_path), 'First corrupted RRD was deleted');
        $this->assertTrue(is_file($this->rrd_path), 'Second corrupted RRD was deleted');
        $this->assertSame(
            $invalid_inode_before,
            fileinode($invalid_path),
            'First corrupted RRD inode changed'
        );
        $this->assertSame(
            $invalid_hash_before,
            hash_file('sha256', $invalid_path),
            'First corrupted RRD contents changed'
        );
        $this->assertSame(
            $current_inode_before,
            fileinode($this->rrd_path),
            'Second corrupted RRD inode changed'
        );
        $this->assertSame(
            $current_hash_before,
            hash_file('sha256', $this->rrd_path),
            'Second corrupted RRD contents changed'
        );
        $this->assertTrue(
            is_file($this->rrd_path . '.recovery-pending'),
            'Repeated corruption did not leave a cooldown marker'
        );
        $this->assertSame(
            0,
            count(glob($this->rrd_path . '.replacement.*') ?: array()),
            'Repeated corruption left a replacement candidate'
        );

        return true;
    }

    public function testNoClobberPublishRefusesExistingDestination() {
        $this->createDataSource();
        $source = $this->rrd_path . '.replacement.source';
        $destination = $this->rrd_path . '.replacement.destination';

        $this->assertTrue(
            gnmi_create_rrd_file(
                $source,
                $this->local_data_id,
                'no-clobber source test',
                time()
            ),
            'Failed to create valid replacement source'
        );
        $this->writeFile($destination, 'existing destination');

        clearstatcache(true, $destination);
        $destination_inode_before = fileinode($destination);
        $destination_hash_before = hash_file('sha256', $destination);
        $error = null;

        $result = gnmi_link_file_no_clobber($source, $destination, $error);

        clearstatcache(true, $destination);
        $this->assertTrue($result === false, 'No-clobber publication overwrote a destination');
        $this->assertSame(
            $destination_inode_before,
            fileinode($destination),
            'Existing destination inode changed'
        );
        $this->assertSame(
            $destination_hash_before,
            hash_file('sha256', $destination),
            'Existing destination contents changed'
        );
        $this->assertTrue(is_string($error) && $error !== '', 'No-clobber refusal did not return an error');

        return true;
    }

    public function testSuccessfulRecoveryUsesValidatedCandidate() {
        $this->createDataSource();
        $this->writeFile($this->rrd_path, 'candidate recovery source');
        $epoch = time();

        $result = gnmi_update_rrd_with_recovery(
            $this->buildUpdateCommand($epoch, 99),
            $this->rrd_path,
            $this->local_data_id,
            'candidate recovery test',
            $epoch
        );

        $this->assertTrue($result === true, 'First-time corruption recovery failed');
        $this->assertSame(
            'candidate recovery source',
            file_get_contents($this->rrd_path . '.invalid'),
            'Original corrupted RRD was not preserved'
        );
        $this->assertTrue(
            gnmi_rrd_is_valid($this->rrd_path),
            'Published replacement RRD is not valid'
        );
        $this->assertSame(
            0,
            count(glob($this->rrd_path . '.replacement.*') ?: array()),
            'Successful recovery left a replacement candidate'
        );

        return true;
    }
}

$test = new TestRrdFileRecovery();
$reflection = new ReflectionClass($test);
$requested_method = $argv[1] ?? null;
$methods = array();

if ($requested_method !== null) {
    if (!$reflection->hasMethod($requested_method) || strpos($requested_method, 'test') !== 0) {
        fwrite(STDERR, "Unknown test method: $requested_method\n");
        exit(2);
    }
    $methods[] = $reflection->getMethod($requested_method);
} else {
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (strpos($method->getName(), 'test') === 0) {
            $methods[] = $method;
        }
    }
}

echo "Running gNMI RRD File Recovery Tests...\n";
echo "=======================================\n";

$passed = 0;
$failed = 0;

foreach ($methods as $method) {
    echo "Running {$method->getName()}... ";
    try {
        $test->setUp();
        $result = $method->invoke($test);
        if ($result !== true) {
            throw new Exception('Unexpected test result: ' . var_export($result, true));
        }
        echo "PASS\n";
        $passed++;
    } catch (Throwable $e) {
        echo "FAIL: {$e->getMessage()}\n";
        $failed++;
    } finally {
        try {
            $test->tearDown();
        } catch (Throwable $cleanup_error) {
            echo "CLEANUP FAIL: {$cleanup_error->getMessage()}\n";
            $failed++;
        }
    }
}

echo "\nTest Results:\n";
echo "=============\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit($failed > 0 ? 1 : 0);
