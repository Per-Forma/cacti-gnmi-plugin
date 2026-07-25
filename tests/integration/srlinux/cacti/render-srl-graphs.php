<?php

declare(strict_types=1);

chdir('/var/www/html/cacti');
require '/var/www/html/cacti/include/cli_check.php';
require_once '/var/www/html/cacti/lib/rrd.php';

$output_dir = getenv('GNMI_GRAPH_OUTPUT_DIR') ?: '/tmp/gnmi-srl-graphs';
if (!is_dir($output_dir) && !mkdir($output_dir, 0750, true)) {
	fwrite(STDERR, "Unable to create graph output directory: {$output_dir}\n");
	exit(1);
}

$host_id = (int)db_fetch_cell_prepared(
	'SELECT id FROM host WHERE external_id = ? LIMIT 1',
	['gnmi-srl-beta-srl1']
);
if ($host_id === 0) {
	fwrite(STDERR, "SR Linux beta Cacti host not found.\n");
	exit(1);
}

$graphs = db_fetch_assoc_prepared(
	'SELECT DISTINCT graph_local_id FROM plugin_gnmi_metrics
	 WHERE graph_local_id IS NOT NULL
	   AND subscription_id IN (
	       SELECT s.id FROM plugin_gnmi_subscriptions s
	       JOIN plugin_gnmi_devices d ON d.id = s.device_id
	       WHERE d.host_id = ?
	   )
	 ORDER BY graph_local_id',
	[$host_id]
);

$rendered = [];
foreach ($graphs as $graph) {
	$graph_id = (int)$graph['graph_local_id'];
	$meta = [];
	$image = rrdtool_function_graph(
		$graph_id,
		null,
		[
			'image_format' => 'png',
			'graph_start' => time() - 900,
			'graph_end' => time(),
			'graph_width' => 900,
			'graph_height' => 240,
			'disable_cache' => true,
		],
		'',
		$meta,
		1
	);
	if ($image === false || $image === '') {
		fwrite(STDERR, "Cacti could not render graph {$graph_id}.\n");
		exit(1);
	}

	$file = "{$output_dir}/graph_{$graph_id}.png";
	if (file_put_contents($file, $image) === false) {
		fwrite(STDERR, "Unable to write {$file}.\n");
		exit(1);
	}
	$rendered[] = ['graph_local_id' => $graph_id, 'file' => $file, 'bytes' => filesize($file)];
}

fwrite(STDOUT, json_encode(['host_id' => $host_id, 'graphs' => $rendered], JSON_PRETTY_PRINT) . "\n");
