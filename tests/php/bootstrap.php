<?php
/**
 * Loads the plugin outside WordPress for tests: the handful of WordPress functions it calls are
 * replaced by simple in-memory versions (options, transients, cron, filters, mail). No network,
 * no database. Used by tests/php/run_tests.php and tests/php/parity.php.
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

$GLOBALS['wp_options'] = [];
$GLOBALS['wp_filters'] = [];
$GLOBALS['wp_scheduled'] = [];
$GLOBALS['wp_mails'] = [];

function get_option($k, $default = false) { return array_key_exists($k, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$k] : $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['wp_options'][$k] = $v; return true; }
function add_option($k, $v = '', $dep = '', $autoload = 'yes') {
    if (array_key_exists($k, $GLOBALS['wp_options'])) {
        return false;
    }
    $GLOBALS['wp_options'][$k] = $v;
    return true;
}
function delete_option($k) { unset($GLOBALS['wp_options'][$k]); return true; }
function get_transient($k) { return get_option("_transient_$k"); }
function set_transient($k, $v, $ttl = 0) { return update_option("_transient_$k", $v); }
function delete_transient($k) { return delete_option("_transient_$k"); }

function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['wp_filters'][$hook][] = $cb; return true; }
function add_action($hook, $cb, $prio = 10, $args = 1) { return add_filter($hook, $cb, $prio, $args); }
function apply_filters($hook, $value, ...$args) {
    foreach ($GLOBALS['wp_filters'][$hook] ?? [] as $cb) {
        $value = $cb($value, ...$args);
    }
    return $value;
}
function add_shortcode($tag, $cb) {}
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}

function wp_next_scheduled($hook) { return $GLOBALS['wp_scheduled'][$hook] ?? false; }
function wp_schedule_single_event($t, $hook, $args = []) { $GLOBALS['wp_scheduled'][$hook] = $t; return true; }
function wp_schedule_event($t, $r, $hook, $args = []) { return wp_schedule_single_event($t, $hook); }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['wp_scheduled'][$hook]); }

function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_mail($to, $subject, $body) { $GLOBALS['wp_mails'][] = compact('to', 'subject', 'body'); return true; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function home_url($p = '') { return 'https://example.test' . $p; }
function plugin_dir_url($f) { return 'https://example.test/wp-content/plugins/mta-ridership/'; }
function rest_url($p = '') { return 'https://example.test/wp-json/' . $p; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function get_page_by_path($p, $o = 'OBJECT', $t = 'page') { return null; }
define('OBJECT', 'OBJECT');

function reset_wp() {
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_scheduled'] = [];
    $GLOBALS['wp_mails'] = [];
}

ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'); // keep the plugin's log lines out of test output
require dirname(__DIR__, 2) . '/wordpress-plugin/mta-ridership/mta-ridership.php';
