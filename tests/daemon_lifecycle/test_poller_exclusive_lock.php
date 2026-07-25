<?php
/**
 * Tests for gnmi_with_poller_exclusive_lock() — advisory non-blocking flock serialization.
 *
 * Run from full Cacti install (needs include/cli_check.php):
 *   php plugins/gnmi/tests/daemon_lifecycle/test_poller_exclusive_lock.php
 */

$no_http_headers = true;
chdir(__DIR__ . '/../../../../');
include_once('./include/cli_check.php');
include_once($config['base_path'] . '/plugins/gnmi/include/functions.php');

$tests_run     = 0;
$tests_passed  = 0;
$tests_failed  = 0;
$tests_skipped = 0;

function assert_true($cond, $message) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ($cond) {
		$tests_passed++;
		echo "PASS: $message\n";
	} else {
		$tests_failed++;
		echo "FAIL: $message\n";
	}
}

function assert_false($cond, $message) {
	assert_true(!$cond, $message);
}

function remove_test_lock($path) {
	if (is_file($path)) {
		unlink($path);
	}
}

// --- Test 1: callable runs and lock releases (same process can re-acquire) ---
$lock1 = sys_get_temp_dir() . '/gnmi_poller_lock_test_' . mt_rand(10000, 99999) . '.lock';
remove_test_lock($lock1);

$executed = 0;
$ok = gnmi_with_poller_exclusive_lock(function () use (&$executed) {
	$executed++;
}, $lock1);

assert_true($ok, 'lock: first acquisition returns true');
assert_true($executed === 1, 'lock: callback ran once');

$executed2 = 0;
$ok2 = gnmi_with_poller_exclusive_lock(function () use (&$executed2) {
	$executed2++;
}, $lock1);

assert_true($ok2, 'lock: second acquisition after release returns true');
assert_true($executed2 === 1, 'lock: second callback ran once');
remove_test_lock($lock1);

// --- Test 2: subprocess holds lock — parent gets busy (non-blocking) ---
$lock2 = sys_get_temp_dir() . '/gnmi_poller_lock_test_busy_' . mt_rand(10000, 99999) . '.lock';
remove_test_lock($lock2);

$php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';

$inline = '$f = fopen(' . var_export($lock2, true) . ', "c+");'
	. 'if (!$f) exit(2);'
	. 'if (!flock($f, LOCK_EX)) exit(3);'
	. 'echo "locked\n";'
	. 'fflush(STDOUT);'
	. 'sleep(3);';

$descriptorspec = array(
	0 => array('pipe', 'r'),
	1 => array('pipe', 'w'),
	2 => array('pipe', 'w'),
);

$cmd = escapeshellarg($php) . ' -r ' . escapeshellarg($inline);
$proc = proc_open($cmd, $descriptorspec, $pipes);

$child_ready = false;
if (is_resource($proc)) {
	stream_set_blocking($pipes[1], false);
	$start = time();
	while ((time() - $start) < 5) {
		$line = fgets($pipes[1], 128);
		if ($line !== false && strpos($line, 'locked') !== false) {
			$child_ready = true;
			break;
		}
		usleep(50000);
	}

	if ($child_ready) {
		$ran_while_busy = 0;
		$busy_ok = gnmi_with_poller_exclusive_lock(function () use (&$ran_while_busy) {
			$ran_while_busy++;
		}, $lock2);

		assert_false($busy_ok, 'lock: while child holds lock, returns false (non-blocking)');
		assert_true($ran_while_busy === 0, 'lock: callback must not run when busy');

		$ran_after_wait = 0;
		$blocking_ok = gnmi_with_poller_exclusive_lock(function () use (&$ran_after_wait) {
			$ran_after_wait++;
		}, $lock2, true);

		assert_true($blocking_ok, 'lock: blocking acquisition waits for the current holder');
		assert_true($ran_after_wait === 1, 'lock: blocking callback runs after the holder releases');
	} else {
		$tests_skipped++;
		echo "SKIP: child did not signal lock held in time\n";
	}

	fclose($pipes[0]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($proc);
} else {
	$tests_skipped++;
	echo "SKIP: could not start subprocess for busy lock test\n";
}

remove_test_lock($lock2);

// --- Test 3: exception in callback still releases lock (finally block) ---
$lock3 = sys_get_temp_dir() . '/gnmi_poller_lock_test_exc_' . mt_rand(10000, 99999) . '.lock';
remove_test_lock($lock3);

$threw = false;
try {
	gnmi_with_poller_exclusive_lock(function () {
		throw new \RuntimeException('deliberate test exception');
	}, $lock3);
} catch (\RuntimeException $e) {
	$threw = true;
}

assert_true($threw, 'exception: callback exception propagated to caller');

// Lock must be released by finally even though callback threw
$reacquired = false;
$ok3 = gnmi_with_poller_exclusive_lock(function () use (&$reacquired) {
	$reacquired = true;
}, $lock3);

assert_true($ok3,        'exception: lock re-acquired by same process after throw');
assert_true($reacquired, 'exception: re-acquire callback ran after throw');
remove_test_lock($lock3);

// --- Summary ---
echo "\nResults: $tests_passed / $tests_run passed";
if ($tests_skipped > 0) {
	echo ", $tests_skipped skipped";
}
if ($tests_failed > 0) {
	echo ", $tests_failed FAILED\n";
	exit(1);
}
echo "\n";
