<?php
/**
 * gNMI Plugin Phase 3.3 - Subscription Management UI Functions
 *
 * Rendering functions for subscription and metric management interface.
 * Integrates with Cacti device edit form.
 */

/**
 * Render the complete subscription management section
 *
 * @param int $device_id gNMI device ID
 * @param int $host_id Cacti host ID
 * @return bool Success status
 */
function gnmi_render_subscription_section($device_id, $host_id) {
    if (empty($device_id) || !is_numeric($device_id)) {
        cacti_log('gNMI: Invalid device_id for subscription section', false, 'PLUGIN');
        return false;
    }

    // Display any messages (if function exists)
    if (function_exists('display_messages')) {
        display_messages();
    }

    echo '<div class="gnmi-subscription-section">';
    echo '<h3>gNMI Subscriptions</h3>';

    // Render subscription table
    $table_result = gnmi_render_subscription_table($device_id);
    if ($table_result === false) {
        echo '<div class="error">Failed to load subscriptions</div>';
        return false;
    }

    // Render add subscription form (hidden by default)
    gnmi_render_add_subscription_form($device_id);

    // Render edit subscription form (hidden by default; single global form populated by JS)
    gnmi_render_edit_subscription_form();

    // Render edit metric form (hidden by default; single global form populated by JS)
    gnmi_render_edit_metric_form();

    // Render JavaScript for UI interactions
    gnmi_render_subscription_javascript();

    echo '</div>';

    return true;
}

/**
 * Render subscription table with add button
 *
 * @param int $device_id gNMI device ID
 * @return bool Success status
 */
function gnmi_render_subscription_table($device_id) {
    if (empty($device_id) || !is_numeric($device_id)) {
        cacti_log('gNMI: Invalid device_id for subscription table', false, 'PLUGIN');
        return false;
    }

    // Get subscriptions for device
    $subscriptions = gnmi_get_device_subscriptions($device_id);

    echo '<table class="cactiTable" style="width:100%">';
    echo '<thead>';
    echo '<tr class="tableHeader">';
    echo '<th class="tableSubHeaderColumn" style="width:40%">Path</th>';
    echo '<th class="tableSubHeaderColumn" style="width:15%">Instance</th>';
    echo '<th class="tableSubHeaderColumn" style="width:10%">Metrics</th>';
    echo '<th class="tableSubHeaderColumn" style="width:10%">Enabled</th>';
    echo '<th class="tableSubHeaderColumn" style="width:25%">Actions</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    if (empty($subscriptions)) {
        echo '<tr class="odd"><td colspan="5"><em>No subscriptions configured. Click "Add" below to get started.</em></td></tr>';
    } else {
        $row_class = 'odd';
        foreach ($subscriptions as $subscription) {
            gnmi_render_subscription_row($subscription, $row_class);
            $row_class = ($row_class === 'odd') ? 'even' : 'odd';
        }
    }

    echo '</tbody>';
    echo '<tfoot>';
    echo '<tr>';
    echo '<td colspan="5">';
    echo '<table style="width:100%"><tr>';
    echo '<td>Add Subscription</td>';
    echo '<td style="text-align:right;">';
    echo '<input type="button" value="Add" onclick="gnmi_show_add_subscription_form()" class="ui-button ui-corner-all ui-widget">';
    echo '</td>';
    echo '</tr></table>';
    echo '</td>';
    echo '</tr>';
    echo '</tfoot>';
    echo '</table>';

    return true;
}

/**
 * Render a single subscription row
 *
 * @param array $subscription Subscription data
 * @return bool Success status
 */
function gnmi_render_subscription_row($subscription, $row_class = 'odd') {
    if (empty($subscription) || !is_array($subscription)) {
        cacti_log('gNMI: Invalid subscription data for row rendering', false, 'PLUGIN');
        return false;
    }

    $subscription_id = $subscription['id'];
    $path = html_escape($subscription['subscription_path']);
    $instance = html_escape($subscription['instance_identifier']);
    $enabled = $subscription['enabled'] ? 'Yes' : 'No';

    // Get metric count
    $metrics = gnmi_get_subscription_metrics($subscription_id);
    $metric_count = count($metrics);

    echo '<tr class="' . $row_class . '"'
        . ' data-subscription-id="' . $subscription_id . '"'
        . ' data-path="' . $path . '"'
        . ' data-instance="' . $instance . '"'
        . ' data-enabled="' . ($subscription['enabled'] ? '1' : '0') . '"'
        . ' data-notes="' . html_escape($subscription['notes']) . '"'
        . '>';
    echo '<td><span title="' . $path . '">' .
         (strlen($path) > 50 ? substr($path, 0, 50) . '...' : $path) .
         '</span></td>';
    echo '<td>' . $instance . '</td>';
    echo '<td>' . $metric_count . ' <a href="javascript:void(0)" onclick="gnmi_toggle_metrics(' . $subscription_id . ')" class="linkEditMain">[View]</a></td>';
    echo '<td>' . $enabled . '</td>';
    echo '<td>';
    echo '<a href="javascript:void(0)" onclick="gnmi_edit_subscription(' . $subscription_id . ')" class="linkEditMain">[Edit]</a> ';
    echo '<a href="javascript:void(0)" onclick="if(confirm(\'Delete this subscription?\')) gnmi_delete_subscription(' . $subscription_id . ')" class="linkDeleteMain">[Delete]</a>';
    echo '</td>';
    echo '</tr>';

    // Render metrics section (hidden by default, spans full width)
    echo '<tr id="metrics-' . $subscription_id . '" style="display: none;">';
    echo '<td colspan="5" style="padding: 5px 20px;">';
    gnmi_render_metric_table($subscription_id);
    echo '</td>';
    echo '</tr>';

    return true;
}

/**
 * Render metric table for a subscription
 *
 * @param int $subscription_id Subscription ID
 * @return bool Success status
 */
function gnmi_render_metric_table($subscription_id) {
    if (empty($subscription_id) || !is_numeric($subscription_id)) {
        cacti_log('gNMI: Invalid subscription_id for metric table', false, 'PLUGIN');
        return false;
    }

    // Get metrics for subscription
    $metrics = gnmi_get_subscription_metrics($subscription_id);

    echo '<table class="cactiTable" style="width:100%">';
    echo '<thead>';
    echo '<tr class="tableHeader">';
    echo '<th class="tableSubHeaderColumn">Metric Name</th>';
    echo '<th class="tableSubHeaderColumn">RRD Type</th>';
    echo '<th class="tableSubHeaderColumn">Cacti Field</th>';
    echo '<th class="tableSubHeaderColumn">Data Source</th>';
    echo '<th class="tableSubHeaderColumn">Graph</th>';
    echo '<th class="tableSubHeaderColumn">Enabled</th>';
    echo '<th class="tableSubHeaderColumn">Actions</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    if (empty($metrics)) {
        echo '<tr class="odd"><td colspan="7"><em>No metrics configured. Click "Add" below to get started.</em></td></tr>';
    } else {
        $row_class = 'odd';
        foreach ($metrics as $metric) {
            gnmi_render_metric_row($metric, $row_class);
            $row_class = ($row_class === 'odd') ? 'even' : 'odd';
        }
    }

    echo '</tbody>';
    echo '<tfoot>';
    echo '<tr>';
    echo '<td colspan="7">';
    echo '<table style="width:100%"><tr>';
    echo '<td>Add Metric</td>';
    echo '<td style="text-align:right;">';
    echo '<input type="button" value="Add" onclick="gnmi_show_add_metric_form(' . $subscription_id . ')" class="ui-button ui-corner-all ui-widget">';
    echo '</td>';
    echo '</tr></table>';
    echo '</td>';
    echo '</tr>';
    echo '</tfoot>';
    echo '</table>';

    // Render add metric form (hidden by default)
    gnmi_render_add_metric_form($subscription_id);

    return true;
}

/**
 * Render a single metric row
 *
 * @param array $metric Metric data
 * @return bool Success status
 */
function gnmi_render_metric_row($metric, $row_class = 'odd') {
    if (empty($metric) || !is_array($metric)) {
        cacti_log('gNMI: Invalid metric data for row rendering', false, 'PLUGIN');
        return false;
    }

    $metric_id = $metric['id'];
    $metric_name = html_escape($metric['metric_name']);
    $cacti_field = html_escape($metric['cacti_field_name']);
    $rrd_type = html_escape($metric['rrd_type']);
    $enabled = $metric['enabled'] ? 'Yes' : 'No';

    // Data source column
    if ($metric['datasource_created'] && $metric['local_data_id']) {
        $data_source_display = '<a href="data_sources.php?action=edit&local_data_id=' . $metric['local_data_id'] . '" class="linkEditMain">#' . $metric['local_data_id'] . '</a>';
    } else {
        $data_source_display = '<a href="javascript:void(0)" onclick="gnmi_create_datasource(' . $metric_id . ')" class="linkEditMain">[Create]</a>';
    }

    // Graph column
    if (!empty($metric['graph_local_id'])) {
        $graph_display = '<a href="graph.php?local_graph_id=' . (int)$metric['graph_local_id'] . '" class="linkEditMain" target="_blank">[View #' . (int)$metric['graph_local_id'] . ']</a>';
    } elseif ($metric['datasource_created'] && $metric['local_data_id']) {
        $graph_display = '<a href="javascript:void(0)" onclick="gnmi_create_graph(' . $metric_id . ')" class="linkEditMain">[Create]</a>';
    } else {
        $graph_display = '<span style="color:#999">Needs DS</span>';
    }

    echo '<tr class="' . $row_class . '"'
        . ' data-metric-id="' . $metric_id . '"'
        . ' data-metric-name="' . $metric_name . '"'
        . ' data-rrd-type="' . $rrd_type . '"'
        . ' data-enabled="' . ($metric['enabled'] ? '1' : '0') . '"'
        . ' data-rrd-heartbeat="' . (int)$metric['rrd_heartbeat'] . '"'
        . ' data-rrd-min="' . html_escape($metric['rrd_min']) . '"'
        . ' data-rrd-max="' . html_escape($metric['rrd_max']) . '"'
        . '>';
    echo '<td>' . $metric_name . '</td>';
    echo '<td>' . $rrd_type . '</td>';
    echo '<td>' . $cacti_field . '</td>';
    echo '<td>' . $data_source_display . '</td>';
    echo '<td>' . $graph_display . '</td>';
    echo '<td>' . $enabled . '</td>';
    echo '<td>';
    echo '<a href="javascript:void(0)" onclick="gnmi_edit_metric(' . $metric_id . ')" class="linkEditMain">[Edit]</a> ';
    echo '<a href="javascript:void(0)" onclick="gnmi_delete_metric(' . $metric_id . ')" class="linkDeleteMain">[Delete]</a>';
    echo '</td>';
    echo '</tr>';

    return true;
}

/**
 * Render add subscription form
 *
 * @param int $device_id gNMI device ID
 * @return bool Success status
 */
function gnmi_render_add_subscription_form($device_id) {
    if (empty($device_id) || !is_numeric($device_id)) {
        cacti_log('gNMI: Invalid device_id for add subscription form', false, 'PLUGIN');
        return false;
    }

    echo '<div class="add-subscription-form" id="add-subscription-form" style="display: none;">';
    echo '<div class="formContainer">';
    echo '<div id="subscription-form-fields">';
    echo '<input type="hidden" data-gnmi-name="action" value="add_subscription">';
    echo '<input type="hidden" data-gnmi-name="device_id" value="' . $device_id . '">';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Subscription Path:</div>';
    echo '<div class="formField">';
    echo '<input type="text" data-gnmi-name="subscription_path" id="gnmi_sub_path" size="80" required>';
    echo '<div class="formDescription">Full gNMI path (e.g., Ciena:cn-if:interface-telemetry-state/...)</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Instance Identifier:</div>';
    echo '<div class="formField">';
    echo '<input type="text" data-gnmi-name="instance_identifier" id="gnmi_sub_instance" size="30" required>';
    echo '<div class="formDescription">Unique name (e.g., ettp-40, GigabitEthernet0/0/0)</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Enabled:</div>';
    echo '<div class="formField">';
    echo '<input type="checkbox" data-gnmi-name="enabled" id="gnmi_sub_enabled" checked>';
    echo '<div class="formDescription">Enable this subscription</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Notes:</div>';
    echo '<div class="formField">';
    echo '<textarea data-gnmi-name="notes" id="gnmi_sub_notes" rows="3" cols="60"></textarea>';
    echo '<div class="formDescription">Optional description</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel"></div>';
    echo '<div class="formField">';
    echo '<input type="button" value="Save Subscription" onclick="gnmi_save_subscription()" class="ui-button ui-corner-all ui-widget">';
    echo ' <input type="button" value="Cancel" onclick="gnmi_hide_add_subscription_form()" class="ui-button ui-corner-all ui-widget">';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
    echo '</div>';

    return true;
}

/**
 * Render edit subscription form (single global hidden form; JS populates and shows it)
 *
 * @return bool Success status
 */
function gnmi_render_edit_subscription_form() {
    echo '<div class="edit-subscription-form" id="edit-subscription-form" style="display: none;">';
    echo '<div class="formContainer">';
    echo '<h4 style="margin-top:0;">Edit Subscription</h4>';
    echo '<div id="edit-subscription-form-fields">';
    echo '<input type="hidden" data-gnmi-name="action" value="update_subscription">';
    echo '<input type="hidden" data-gnmi-name="subscription_id" value="">';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Subscription Path:</div>';
    echo '<div class="formField">';
    echo '<input type="text" data-gnmi-name="subscription_path" id="gnmi_edit_sub_path" size="80">';
    echo '<div class="formDescription">Full gNMI path (e.g., Ciena:cn-if:interface-telemetry-state/...)</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Instance Identifier:</div>';
    echo '<div class="formField">';
    echo '<input type="text" data-gnmi-name="instance_identifier" id="gnmi_edit_sub_instance" size="30">';
    echo '<div class="formDescription">Unique name (e.g., ettp-40, GigabitEthernet0/0/0)</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Enabled:</div>';
    echo '<div class="formField">';
    echo '<input type="checkbox" data-gnmi-name="enabled" id="gnmi_edit_sub_enabled" name="gnmi_edit_sub_enabled">';
    echo '<div class="formDescription">Enable this subscription</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Notes:</div>';
    echo '<div class="formField">';
    echo '<textarea data-gnmi-name="notes" id="gnmi_edit_sub_notes" rows="3" cols="60"></textarea>';
    echo '<div class="formDescription">Optional description</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel"></div>';
    echo '<div class="formField">';
    echo '<input type="button" value="Save Changes" onclick="gnmi_submit_subscription_edit()" class="ui-button ui-corner-all ui-widget">';
    echo ' <input type="button" value="Cancel" onclick="gnmi_hide_edit_subscription_form()" class="ui-button ui-corner-all ui-widget">';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
    echo '</div>';

    return true;
}

/**
 * Render edit metric form (single global hidden form; JS populates and shows it)
 *
 * @return bool Success status
 */
function gnmi_render_edit_metric_form() {
    echo '<div class="edit-metric-form" id="edit-metric-form" style="display: none;">';
    echo '<div class="formContainer">';
    echo '<h4 style="margin-top:0;">Edit Metric</h4>';
    echo '<div id="edit-metric-form-fields">';
    echo '<input type="hidden" data-gnmi-name="action" value="update_metric">';
    echo '<input type="hidden" data-gnmi-name="metric_id" value="">';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Metric Name:</div>';
    echo '<div class="formField">';
    echo '<input type="text" data-gnmi-name="metric_name" id="gnmi_edit_metric_name" size="30">';
    echo '<div class="formDescription">Exact name as reported by the device (e.g., in-octets). Changing this does not rename the RRD data source.</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">RRD Type:</div>';
    echo '<div class="formField">';
    echo '<select data-gnmi-name="rrd_type" id="gnmi_edit_rrd_type">';
    echo '<option value="COUNTER">COUNTER (cumulative values)</option>';
    echo '<option value="GAUGE">GAUGE (point-in-time values)</option>';
    echo '<option value="DERIVE">DERIVE (rate of change)</option>';
    echo '<option value="ABSOLUTE">ABSOLUTE (absolute values)</option>';
    echo '</select>';
    echo '<div class="formDescription">Data source type for RRD</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Enabled:</div>';
    echo '<div class="formField">';
    echo '<input type="checkbox" data-gnmi-name="enabled" id="gnmi_edit_metric_enabled" name="gnmi_edit_metric_enabled">';
    echo '<div class="formDescription">Enable this metric</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Heartbeat (s):</div>';
    echo '<div class="formField">';
    echo '<input type="number" data-gnmi-name="rrd_heartbeat" id="gnmi_edit_rrd_heartbeat" size="8" min="1">';
    echo '<div class="formDescription">Maximum seconds between updates before value is marked unknown</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">RRD Min:</div>';
    echo '<div class="formField">';
    echo '<input type="text" data-gnmi-name="rrd_min" id="gnmi_edit_rrd_min" size="10">';
    echo '<div class="formDescription">Minimum value (use U for unlimited)</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">RRD Max:</div>';
    echo '<div class="formField">';
    echo '<input type="text" data-gnmi-name="rrd_max" id="gnmi_edit_rrd_max" size="10">';
    echo '<div class="formDescription">Maximum value (use U for unlimited)</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel"></div>';
    echo '<div class="formField">';
    echo '<input type="button" value="Save Changes" onclick="gnmi_submit_metric_edit()" class="ui-button ui-corner-all ui-widget">';
    echo ' <input type="button" value="Cancel" onclick="gnmi_hide_edit_metric_form()" class="ui-button ui-corner-all ui-widget">';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
    echo '</div>';

    return true;
}

/**
 * Render add metric form
 *
 * @param int $subscription_id Subscription ID
 * @return bool Success status
 */
function gnmi_render_add_metric_form($subscription_id) {
    if (empty($subscription_id) || !is_numeric($subscription_id)) {
        cacti_log('gNMI: Invalid subscription_id for add metric form', false, 'PLUGIN');
        return false;
    }

    echo '<div class="add-metric-form" id="add-metric-form-' . $subscription_id . '" style="display: none;">';
    echo '<div class="formContainer">';
    echo '<div id="metric-form-fields-' . $subscription_id . '">';
    echo '<input type="hidden" data-gnmi-name="action" value="add_metric">';
    echo '<input type="hidden" data-gnmi-name="subscription_id" value="' . $subscription_id . '">';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Metric Name:</div>';
    echo '<div class="formField">';
    echo '<input type="text" data-gnmi-name="metric_name" id="gnmi_metric_name_' . $subscription_id . '" size="30">';
    echo '<div class="formDescription">Exact name from device (e.g., in-octets)</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">RRD Type:</div>';
    echo '<div class="formField">';
    echo '<select data-gnmi-name="rrd_type" id="gnmi_rrd_type_' . $subscription_id . '">';
    echo '<option value="COUNTER" selected>COUNTER (cumulative values)</option>';
    echo '<option value="GAUGE">GAUGE (point-in-time values)</option>';
    echo '<option value="DERIVE">DERIVE (rate of change)</option>';
    echo '<option value="ABSOLUTE">ABSOLUTE (absolute values)</option>';
    echo '</select>';
    echo '<div class="formDescription">Data source type for RRD</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel">Enabled:</div>';
    echo '<div class="formField">';
    echo '<input type="checkbox" data-gnmi-name="enabled" id="gnmi_metric_enabled_' . $subscription_id . '" checked>';
    echo '<div class="formDescription">Enable this metric</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="formRow">';
    echo '<div class="formLabel"></div>';
    echo '<div class="formField">';
    echo '<input type="button" value="Save Metric" onclick="gnmi_save_metric(' . $subscription_id . ')" class="ui-button ui-corner-all ui-widget">';
    echo ' <input type="button" value="Cancel" onclick="gnmi_hide_add_metric_form(' . $subscription_id . ')" class="ui-button ui-corner-all ui-widget">';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
    echo '</div>';

    return true;
}

/**
 * Render JavaScript for UI interactions
 *
 * @return bool Success status
 */
function gnmi_render_subscription_javascript() {
    ?>
    <script type="text/javascript">
    // Detach modal-style sub-forms from the Cacti device-edit <form>.
    // Why: programmatically toggling checkboxes inside these forms (e.g. when
    // [Edit] is clicked on a subscription) flips a named checkbox's state,
    // which Cacti's checkFormStatus() correctly reports as "Unsaved Changes
    // Detected" on the host form. Moving them outside <form> decouples them
    // from host-form change detection. Submission is already AJAX-based.
    $(document).ready(function() {
        var container = document.getElementById('gnmi-detached-forms');
        if (!container) {
            container = document.createElement('div');
            container.id = 'gnmi-detached-forms';
            document.body.appendChild(container);
        }
        // Style each relocated form as a fixed-position floating panel so it
        // appears in the viewport (not at the bottom of <body> where the user
        // can't see it). Existing show/hide logic toggles inline display only.
        function styleAsFloatingPanel(el) {
            el.style.position    = 'fixed';
            el.style.top         = '80px';
            el.style.left        = '50%';
            el.style.transform   = 'translateX(-50%)';
            el.style.zIndex      = '9999';
            el.style.background  = '#fff';
            el.style.border      = '1px solid #ccc';
            el.style.borderRadius= '4px';
            el.style.boxShadow   = '0 4px 20px rgba(0,0,0,0.3)';
            el.style.padding     = '16px 20px';
            el.style.maxWidth    = '90vw';
            el.style.maxHeight   = '85vh';
            el.style.overflowY   = 'auto';
        }
        var selectors = ['#add-subscription-form', '#edit-subscription-form', '#edit-metric-form'];
        selectors.forEach(function(sel) {
            var el = document.querySelector(sel);
            if (el && el.parentNode !== container) {
                container.appendChild(el);
                styleAsFloatingPanel(el);
            }
        });
        document.querySelectorAll('[id^="add-metric-form-"]').forEach(function(el) {
            if (el.parentNode !== container) {
                container.appendChild(el);
                styleAsFloatingPanel(el);
            }
        });
    });

    function gnmi_show_add_subscription_form() {
        console.log('gNMI: Showing add subscription form');
        var form = document.getElementById("add-subscription-form");
        if (form) {
            form.style.display = "block";
            console.log('gNMI: Form displayed successfully');
        } else {
            console.error('gNMI: Add subscription form not found');
        }
    }

    // Ensure the function is available globally
    window.gnmi_show_add_subscription_form = gnmi_show_add_subscription_form;

    function gnmi_hide_add_subscription_form() {
        document.getElementById("add-subscription-form").style.display = "none";
    }

    function gnmi_show_add_metric_form(subscription_id) {
        document.getElementById("add-metric-form-" + subscription_id).style.display = "block";
    }

    function gnmi_hide_add_metric_form(subscription_id) {
        document.getElementById("add-metric-form-" + subscription_id).style.display = "none";
    }

    function gnmi_toggle_metrics(subscription_id) {
        var metricsRow = document.getElementById("metrics-" + subscription_id);
        if (metricsRow.style.display === "none") {
            metricsRow.style.display = "table-row";
        } else {
            metricsRow.style.display = "none";
        }
    }

    function gnmi_edit_subscription(subscription_id) {
        var row  = document.querySelector('tr[data-subscription-id="' + subscription_id + '"]');
        var form = document.getElementById('edit-subscription-form');
        if (!row || !form) {
            alert('Could not find subscription row or edit form.');
            return;
        }
        form.querySelector('[data-gnmi-name="subscription_id"]').value  = subscription_id;
        form.querySelector('[data-gnmi-name="subscription_path"]').value = row.dataset.path    || '';
        form.querySelector('[data-gnmi-name="instance_identifier"]').value = row.dataset.instance || '';
        form.querySelector('[data-gnmi-name="enabled"]').checked         = (row.dataset.enabled === '1');
        form.querySelector('[data-gnmi-name="notes"]').value             = row.dataset.notes   || '';
        form.style.display = 'block';
    }

    function gnmi_hide_edit_subscription_form() {
        var form = document.getElementById('edit-subscription-form');
        if (form) form.style.display = 'none';
    }

    function gnmi_submit_subscription_edit() {
        var form = document.getElementById('edit-subscription-form');
        var path     = form.querySelector('[data-gnmi-name="subscription_path"]').value.trim();
        var instance = form.querySelector('[data-gnmi-name="instance_identifier"]').value.trim();
        if (!path || !instance) {
            alert('Subscription Path and Instance Identifier are required.');
            return;
        }

        var formData = new FormData();
        formData.append('action',                'update_subscription');
        formData.append('subscription_id',       form.querySelector('[data-gnmi-name="subscription_id"]').value);
        formData.append('subscription_path',     path);
        formData.append('instance_identifier',   instance);
        formData.append('enabled',               form.querySelector('[data-gnmi-name="enabled"]').checked ? '1' : '0');
        formData.append('notes',                 form.querySelector('[data-gnmi-name="notes"]').value);

        var csrfToken = document.querySelector('input[name="__csrf_magic"]');
        if (csrfToken) formData.append('__csrf_magic', csrfToken.value);

        fetch('plugins/gnmi/ajax_handler.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Subscription updated successfully!');
                gnmi_hide_edit_subscription_form();
                location.reload();
            } else {
                alert('Error updating subscription: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('gNMI: update subscription error:', error);
            alert('Error updating subscription: ' + error.message);
        });
    }

    function gnmi_delete_subscription(subscription_id) {
        console.log('gNMI: Deleting subscription ' + subscription_id + ' via AJAX...');

        var formData = new FormData();
        formData.append('action', 'delete_subscription');
        formData.append('subscription_id', subscription_id);
        formData.append('confirm', '1');

        // Add CSRF token if available
        var csrfToken = document.querySelector('input[name="__csrf_magic"]');
        if (csrfToken) {
            formData.append('__csrf_magic', csrfToken.value);
        }

        fetch('plugins/gnmi/ajax_handler.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        })
        .then(response => response.json())
        .then(data => {
            console.log('gNMI: AJAX delete subscription response:', data);
            if (data.success) {
                alert('Subscription deleted successfully!');
                location.reload();
            } else {
                alert('Error deleting subscription: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('gNMI: AJAX delete subscription error:', error);
            alert('Error deleting subscription: ' + error.message);
        });
    }

    function gnmi_edit_metric(metric_id) {
        var row  = document.querySelector('tr[data-metric-id="' + metric_id + '"]');
        var form = document.getElementById('edit-metric-form');
        if (!row || !form) {
            alert('Could not find metric row or edit form.');
            return;
        }
        form.querySelector('[data-gnmi-name="metric_id"]').value    = metric_id;
        form.querySelector('[data-gnmi-name="metric_name"]').value  = row.dataset.metricName  || '';
        form.querySelector('[data-gnmi-name="enabled"]').checked    = (row.dataset.enabled === '1');
        form.querySelector('[data-gnmi-name="rrd_heartbeat"]').value = row.dataset.rrdHeartbeat || '';
        form.querySelector('[data-gnmi-name="rrd_min"]').value      = row.dataset.rrdMin       || '';
        form.querySelector('[data-gnmi-name="rrd_max"]').value      = row.dataset.rrdMax       || '';

        // Set the rrd_type select to match the current value
        var rrdTypeSelect = form.querySelector('[data-gnmi-name="rrd_type"]');
        var currentType   = row.dataset.rrdType || 'COUNTER';
        for (var i = 0; i < rrdTypeSelect.options.length; i++) {
            rrdTypeSelect.options[i].selected = (rrdTypeSelect.options[i].value === currentType);
        }

        form.style.display = 'block';
    }

    function gnmi_hide_edit_metric_form() {
        var form = document.getElementById('edit-metric-form');
        if (form) form.style.display = 'none';
    }

    function gnmi_submit_metric_edit() {
        var form = document.getElementById('edit-metric-form');
        var metricName = form.querySelector('[data-gnmi-name="metric_name"]').value.trim();
        if (!metricName) {
            alert('Metric Name is required.');
            return;
        }

        var formData = new FormData();
        formData.append('action',         'update_metric');
        formData.append('metric_id',      form.querySelector('[data-gnmi-name="metric_id"]').value);
        formData.append('metric_name',    metricName);
        formData.append('rrd_type',       form.querySelector('[data-gnmi-name="rrd_type"]').value);
        formData.append('enabled',        form.querySelector('[data-gnmi-name="enabled"]').checked ? '1' : '0');
        formData.append('rrd_heartbeat',  form.querySelector('[data-gnmi-name="rrd_heartbeat"]').value);
        formData.append('rrd_min',        form.querySelector('[data-gnmi-name="rrd_min"]').value);
        formData.append('rrd_max',        form.querySelector('[data-gnmi-name="rrd_max"]').value);

        var csrfToken = document.querySelector('input[name="__csrf_magic"]');
        if (csrfToken) formData.append('__csrf_magic', csrfToken.value);

        fetch('plugins/gnmi/ajax_handler.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Metric updated successfully!');
                gnmi_hide_edit_metric_form();
                location.reload();
            } else {
                alert('Error updating metric: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('gNMI: update metric error:', error);
            alert('Error updating metric: ' + error.message);
        });
    }

    function gnmi_delete_metric(metric_id) {
        if (confirm("Are you sure you want to delete this metric?")) {
            console.log('gNMI: Deleting metric ' + metric_id + ' via AJAX...');

            var formData = new FormData();
            formData.append('action', 'delete_metric');
            formData.append('metric_id', metric_id);

            // Add CSRF token if available
            var csrfToken = document.querySelector('input[name="__csrf_magic"]');
            if (csrfToken) {
                formData.append('__csrf_magic', csrfToken.value);
            }

            fetch('plugins/gnmi/ajax_handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                console.log('gNMI: AJAX delete metric response:', data);
                if (data.success) {
                    alert('Metric deleted successfully!');
                    location.reload();
                } else {
                    alert('Error deleting metric: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('gNMI: AJAX delete metric error:', error);
                alert('Error deleting metric: ' + error.message);
            });
        }
    }

    function gnmi_create_datasource(metric_id) {
        var formData = new FormData();
        formData.append('action', 'create_datasource');
        formData.append('metric_id', metric_id);
        var csrfToken = document.querySelector('input[name="__csrf_magic"]');
        if (csrfToken) formData.append('__csrf_magic', csrfToken.value);

        fetch('plugins/gnmi/ajax_handler.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to create data source: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('gNMI: create datasource error:', error);
                alert('Error creating data source: ' + error.message);
            });
    }

    function gnmi_create_graph(metric_id) {
        var formData = new FormData();
        formData.append('action', 'create_graph');
        formData.append('metric_id', metric_id);
        var csrfToken = document.querySelector('input[name="__csrf_magic"]');
        if (csrfToken) formData.append('__csrf_magic', csrfToken.value);

        fetch('plugins/gnmi/ajax_handler.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to create graph: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('gNMI: create graph error:', error);
                alert('Error creating graph: ' + error.message);
            });
    }

    function gnmi_save_subscription() {
        console.log('gNMI: Saving subscription via AJAX...');

        // Get form data from the subscription form fields (scoped to sub-form container)
        var subForm = document.getElementById('add-subscription-form');

        // Validate required fields
        var path = subForm.querySelector('[data-gnmi-name="subscription_path"]').value.trim();
        var instance = subForm.querySelector('[data-gnmi-name="instance_identifier"]').value.trim();
        if (!path || !instance) {
            alert('Subscription Path and Instance Identifier are required.');
            return;
        }
        var formData = new FormData();
        formData.append('action', 'add_subscription');
        formData.append('device_id', subForm.querySelector('[data-gnmi-name="device_id"]').value);
        formData.append('subscription_path', subForm.querySelector('[data-gnmi-name="subscription_path"]').value);
        formData.append('instance_identifier', subForm.querySelector('[data-gnmi-name="instance_identifier"]').value);
        formData.append('enabled', subForm.querySelector('[data-gnmi-name="enabled"]').checked ? '1' : '0');
        formData.append('notes', subForm.querySelector('[data-gnmi-name="notes"]').value);

        // Add CSRF token if available
        var csrfToken = document.querySelector('input[name="__csrf_magic"]');
        if (csrfToken) {
            formData.append('__csrf_magic', csrfToken.value);
        }

        // Debug: Log form data
        console.log('gNMI: AJAX form data:');
        for (var pair of formData.entries()) {
            console.log('  ' + pair[0] + ': ' + pair[1]);
        }

        // Submit via AJAX to our dedicated AJAX handler
        fetch('plugins/gnmi/ajax_handler.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        })
        .then(response => response.json())
        .then(data => {
            console.log('gNMI: AJAX response:', data);
            if (data.success) {
                alert('Subscription saved successfully!');
                gnmi_hide_add_subscription_form();
                // TODO: Refresh subscription list
                location.reload(); // Temporary - refresh page to show new subscription
            } else {
                alert('Error saving subscription: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('gNMI: AJAX error:', error);
            alert('Error saving subscription: ' + error.message);
        });
    }

    function gnmi_save_metric(subscription_id) {
        console.log('gNMI: Saving metric via AJAX for subscription ' + subscription_id + '...');

        // Get form data from the metric form fields (scoped to sub-form container)
        var metricForm = document.getElementById('add-metric-form-' + subscription_id);

        // Validate required fields
        var metricName = metricForm.querySelector('[data-gnmi-name="metric_name"]').value.trim();
        if (!metricName) {
            alert('Metric Name is required.');
            return;
        }
        var formData = new FormData();
        formData.append('action', 'add_metric');
        formData.append('subscription_id', subscription_id);
        formData.append('metric_name', metricForm.querySelector('[data-gnmi-name="metric_name"]').value);
        formData.append('rrd_type', metricForm.querySelector('[data-gnmi-name="rrd_type"]').value);
        formData.append('enabled', metricForm.querySelector('[data-gnmi-name="enabled"]').checked ? '1' : '0');

        // Add CSRF token if available
        var csrfToken = document.querySelector('input[name="__csrf_magic"]');
        if (csrfToken) {
            formData.append('__csrf_magic', csrfToken.value);
        }

        // Debug: Log form data
        console.log('gNMI: AJAX metric form data:');
        for (var pair of formData.entries()) {
            console.log('  ' + pair[0] + ': ' + pair[1]);
        }

        // Submit via AJAX to our dedicated AJAX handler
        fetch('plugins/gnmi/ajax_handler.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        })
        .then(response => response.json())
        .then(data => {
            console.log('gNMI: AJAX metric response:', data);
            if (data.success) {
                alert('Metric saved successfully!');
                gnmi_hide_add_metric_form(subscription_id);
                // TODO: Refresh metric list
                location.reload(); // Temporary - refresh page to show new metric
            } else {
                alert('Error saving metric: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('gNMI: AJAX metric error:', error);
            alert('Error saving metric: ' + error.message);
        });
    }
    </script>
    <?php

    return true;
}
