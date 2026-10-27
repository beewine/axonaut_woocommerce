<?php
// Mini-bootstrap sans WordPress : stubs suffisants pour tester la logique pure du module.
// Usage : php tests/run.php

define('ABSPATH', __DIR__ . '/');
$GLOBALS['axo_options'] = array('axonaut_api_key' => 'KEY', 'axonaut_webhook_secret' => 'S3CRET');
$GLOBALS['axo_http'] = array();      // requêtes capturées
$GLOBALS['axo_http_queue'] = array(); // réponses simulées

function plugin_dir_path($file) { return rtrim(dirname($file), '/') . '/'; }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['axo_options']) ? $GLOBALS['axo_options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['axo_options'][$k] = $v; return true; }
function wp_json_encode($v) { return json_encode($v); }
// Comme WordPress (build_query sans urlencode) : les valeurs doivent déjà être encodées.
function add_query_arg($args, $url) { $q = array(); foreach ($args as $k => $v) { $q[] = $k . '=' . $v; } return $url . (strpos($url, '?') === false ? '?' : '&') . implode('&', $q); }
function __($s, $d = null) { return $s; }
function wp_upload_dir() { return array('basedir' => sys_get_temp_dir(), 'error' => false); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_generate_password($n = 12, $x = true) { return str_repeat('a', $n); }
function is_wp_error($t) { return $t instanceof WP_Error; }
function current_user_can($c) { return !empty($GLOBALS['axo_can']); }
function wp_remote_request($url, $args) {
    $GLOBALS['axo_http'][] = array('url' => $url, 'args' => $args);
    return array_shift($GLOBALS['axo_http_queue']) ?: array('code' => 200, 'body' => '{}');
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

class WP_Error {
    public $code, $message, $data;
    function __construct($c = '', $m = '', $d = '') { $this->code = $c; $this->message = $m; $this->data = $d; }
    function get_error_message() { return $this->message; }
}
class WP_REST_Request {
    private $body, $headers;
    function __construct($body = '', $headers = array()) { $this->body = $body; $this->headers = $headers; }
    function get_body() { return $this->body; }
    function get_header($k) { return isset($this->headers[$k]) ? $this->headers[$k] : null; }
}

$GLOBALS['axo_fail'] = 0;
function check($label, $cond) {
    echo ($cond ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$cond) { $GLOBALS['axo_fail']++; }
}

function wp_remote_retrieve_header($r, $h) { return isset($r['headers'][$h]) ? $r['headers'][$h] : ''; }
function wp_strip_all_tags($s) { return trim(strip_tags($s)); }
function wc_get_product($id) { return isset($GLOBALS['axo_wc_products'][$id]) ? $GLOBALS['axo_wc_products'][$id] : false; }
function wc_get_product_id_by_sku($sku) { foreach ($GLOBALS['axo_wc_products'] as $id => $p) { if ($p->get_sku() === $sku) { return $id; } } return 0; }
$GLOBALS['axo_wc_products'] = array();

class FakeProduct {
    public $d;
    function __construct($d) { $this->d = $d + array('type' => 'simple', 'sku' => '', 'short' => '', 'long' => '', 'desc' => '', 'name' => 'Produit', 'parent' => 0, 'status' => 'publish'); }
    function get_id() { return $this->d['id']; }
    function get_sku() { return $this->d['sku']; }
    function get_name() { return $this->d['name']; }
    function get_status() { return $this->d['status']; }
    function get_parent_id() { return $this->d['parent']; }
    function is_type($t) { return $this->d['type'] === $t; }
    // Sur une variation, get_description() est la description de la variation ; sinon la description longue.
    function get_description() { return $this->d['type'] === 'variation' ? $this->d['desc'] : $this->d['long']; }
    function get_short_description() { return $this->d['short']; }
    function managing_stock() { return !empty($this->d['manage']); }
    function get_stock_quantity() { return array_key_exists('qty', $this->d) ? $this->d['qty'] : null; }
    function get_meta($k, $single = true) { return isset($this->d['meta'][$k]) ? $this->d['meta'][$k] : ''; }
    function update_meta_data($k, $v) { $this->d['meta'][$k] = $v; }
    function save_meta_data() {}
    function set_manage_stock($v) { $this->d['manage'] = $v; }
    function set_stock_quantity($v) { $this->d['qty'] = $v; }
    function set_stock_status($v) { $this->d['stock_status'] = $v; }
    function save() { $this->d['saved'] = true; }
}

// Action Scheduler simulé
$GLOBALS['axo_as'] = array();
function as_has_scheduled_action($hook, $args, $group) { return isset($GLOBALS['axo_as'][md5($hook . json_encode($args))]); }
function as_schedule_single_action($when, $hook, $args, $group) { $GLOBALS['axo_as'][md5($hook . json_encode($args))] = $when; return 1; }
