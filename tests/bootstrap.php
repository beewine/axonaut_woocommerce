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
    function set_name($v) { $this->d['name'] = $v; }
    function set_description($v) { $this->d[$this->d['type'] === 'variation' ? 'desc' : 'long'] = $v; }
    function set_short_description($v) { $this->d['short'] = $v; }
    function is_on_sale() { return !empty($this->d['on_sale']); }
    function set_regular_price($v) { $this->d['price'] = (float) $v; }
    function get_tax_class() { return ''; }
    function get_tax_status() { return 'none'; }
}
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function wp_kses_post($s) { return $s; }
function wc_format_decimal($v) { return $v; }
function wc_get_price_excluding_tax($p) { return isset($p->d['price']) ? $p->d['price'] : 0; }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }

// Action Scheduler simulé
$GLOBALS['axo_as'] = array();
function as_has_scheduled_action($hook, $args, $group) { return isset($GLOBALS['axo_as'][md5($hook . json_encode($args))]); }
function as_schedule_single_action($when, $hook, $args, $group) { $GLOBALS['axo_as'][md5($hook . json_encode($args))] = $when; return 1; }

// ---- Commandes, remboursements, TVA
class WC_Tax {
    static $rates = array();
    static function _get_tax_rate($id) { return isset(self::$rates[$id]) ? array('tax_rate' => self::$rates[$id]) : false; }
}
class FakeItem {
    public $d;
    function __construct($d) { $this->d = $d + array('qty' => 1, 'total' => 0, 'tax' => 0, 'taxes' => array('total' => array()), 'product' => null, 'status' => 'taxable', 'id' => 1); }
    function get_id() { return $this->d['id']; }
    function get_quantity() { return $this->d['qty']; }
    function get_total() { return $this->d['total']; }
    function get_total_tax() { return $this->d['tax']; }
    function get_taxes() { return $this->d['taxes']; }
    function get_product() { return $this->d['product'] ?: false; }
    function get_tax_status() { return $this->d['status']; }
}
class FakeOrder {
    public $d;
    function __construct($d) { $this->d = $d + array('items' => array(), 'shipping' => array(), 'fee' => array(), 'total' => 0, 'refunded' => 0, 'meta' => array(), 'notes' => array(), 'email' => 'client@example.com'); }
    function get_id() { return $this->d['id']; }
    function get_items($type = 'line_item') { return $type === 'shipping' ? $this->d['shipping'] : ($type === 'fee' ? $this->d['fee'] : $this->d['items']); }
    function get_total() { return $this->d['total']; }
    function get_total_refunded() { return $this->d['refunded']; }
    function get_total_tax() { return 0; }
    function get_total_tax_refunded() { return 0; }
    function get_meta($k) { return isset($this->d['meta'][$k]) ? $this->d['meta'][$k] : ''; }
    function update_meta_data($k, $v) { $this->d['meta'][$k] = $v; }
    function delete_meta_data($k) { unset($this->d['meta'][$k]); }
    function save_meta_data() {}
    function add_order_note($n) { $this->d['notes'][] = $n; }
    function get_billing_email() { return $this->d['email']; }
    function get_shipping_address_1() { return ''; }
    function get_customer_id() { return 0; }
    function get_payment_method() { return 'stripe'; }
    // remboursement
    function get_amount() { return isset($this->d['amount']) ? $this->d['amount'] : 0; }
    function get_reason() { return isset($this->d['reason']) ? $this->d['reason'] : ''; }
}
$GLOBALS['axo_orders'] = array();
function wc_get_order($id) { return isset($GLOBALS['axo_orders'][$id]) ? $GLOBALS['axo_orders'][$id] : false; }
function add_option($k, $v = '', $d = '', $a = 'yes') { if (array_key_exists($k, $GLOBALS['axo_options'])) { return false; } $GLOBALS['axo_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['axo_options'][$k]); return true; }
