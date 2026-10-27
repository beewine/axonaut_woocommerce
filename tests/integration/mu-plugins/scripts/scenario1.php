<?php
// Scénario d'intégration : produit -> file d'attente -> API simulée, commande, remboursement.
function t($label, $ok) { echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n"; if (!$ok) { $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1; } }
function mock_log() { return get_option('axo_mock_log', array()); }
function clear_pending() { foreach (array('axonaut_sync_product', 'axonaut_sync_stock', 'axonaut_sync_order', 'axonaut_pull_page') as $h) { as_unschedule_all_actions($h); } }
function pending($hook) { return as_get_scheduled_actions(array('hook' => $hook, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50), 'ids'); }

delete_option('axo_mock_log'); delete_option('axo_mock_products');
foreach (wc_get_products(array('limit' => -1, 'status' => 'any')) as $old) { $old->delete(true); }
foreach (wc_get_orders(array('limit' => -1)) as $old) { $old->delete(true); }
clear_pending();
update_option('axonaut_api_key', 'KEY');
update_option('axonaut_sync_sells', 'on');
update_option('axonaut_sync_credit_notes', 'on');
update_option('axonaut_product_match', 'sku_then_id');
update_option('axonaut_dir_stock', 'both');
foreach (array('name', 'description', 'price') as $f) { update_option("axonaut_dir_$f", 'to_axonaut'); }
update_option('woocommerce_calc_taxes', 'yes');
WC_Tax::_insert_tax_rate(array('tax_rate_country' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'TVA', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => ''));

echo "Produit\n";
$p = new WC_Product_Simple();
$p->set_name('Vin test'); $p->set_sku('TEST-1'); $p->set_regular_price('12.00');
$p->set_short_description('<p>Bouteille; 75cl</p>'); $p->set_description('Description longue');
$p->set_manage_stock(true); $p->set_stock_quantity(10); $p->set_status('publish');
$pid = $p->save();
t('synchro produit planifiée dans Action Scheduler', count(pending('axonaut_sync_product')) === 1);
do_action('axonaut_sync_product', $pid);
$log = mock_log();
$post = null; foreach ($log as $e) { if ($e['m'] === 'POST' && $e['p'] === '/api/v2/products') { $post = $e['b']; } }
t('produit créé dans Axonaut avec référence et ID WooCommerce', $post && $post['product_code'] === 'TEST-1' && $post['internal_id'] === (string) $pid);
t('descriptif nettoyé (point-virgule retiré)', $post && strpos($post['description'], ';') === false && strpos($post['description'], 'Bouteille') !== false);
t('stock initial envoyé à la création', $post && $post['stock'] == 10);
t('instantané enregistré sur le produit', strlen((string) wc_get_product($pid)->get_meta('_axonaut_snapshot')) > 10);

echo "Stock WooCommerce -> Axonaut\n";
update_option('axo_mock_log', array(), false);
$p = wc_get_product($pid); $p->set_stock_quantity(8); $p->save();
t('changement de stock planifié (30 s)', count(pending('axonaut_sync_stock')) >= 1);
do_action('axonaut_sync_stock', $pid);
$stockPatch = null; foreach (mock_log() as $e) { if ($e['m'] === 'PATCH' && preg_match('#/stock$#', $e['p'])) { $stockPatch = $e; } }
t('PATCH /stock avec la valeur absolue', $stockPatch && $stockPatch['b']['stock'] == 8);

echo "Commande\n";
$order = wc_create_order();
$order->add_product(wc_get_product($pid), 2);
$order->set_billing_email('client@example.test');
$order->calculate_totals();
$order->set_status('processing'); $order->save();
t('facture planifiée à la mise « En cours »', count(pending('axonaut_sync_order')) >= 1);
update_option('axo_mock_log', array(), false);
do_action('axonaut_sync_order', $order->get_id(), 'invoice', 0, 1);
do_action('axonaut_sync_order', $order->get_id(), 'invoice', 0, 1);
$invoices = array_values(array_filter(mock_log(), function ($e) { return $e['m'] === 'POST' && $e['p'] === '/api/v2/invoices'; }));
t('une seule facture créée malgré deux exécutions', count($invoices) === 1);
$line = $invoices[0]['b']['products'][0] ?? array();
t('ligne : id Axonaut du produit retrouvé par référence', isset($line['id']) && $line['quantity'] == 2);
t('ligne : taux de TVA exact (20)', isset($line['tax_rate']) && (float) $line['tax_rate'] === 20.0);
$o = wc_get_order($order->get_id());
t('id de facture mémorisé sur la commande', (int) $o->get_meta('_axonaut_invoice_id') > 0);

echo "Remboursement -> avoir\n";
update_option('axo_mock_log', array(), false);
$refund = wc_create_refund(array('amount' => 14.40, 'order_id' => $order->get_id(), 'line_items' => array(
    key($order->get_items()) => array('qty' => 1, 'refund_total' => 12.00, 'refund_tax' => array(1 => 2.40)),
)));
t('remboursement créé', !is_wp_error($refund));
$refundId = is_wp_error($refund) ? 0 : $refund->get_id();
t('avoir planifié', count(pending('axonaut_sync_order')) >= 1);
do_action('axonaut_sync_order', $order->get_id(), 'refund', $refundId, 1);
$credits = array_values(array_filter(mock_log(), function ($e) { return $e['m'] === 'POST' && $e['p'] === '/api/v2/invoices'; }));
t('avoir envoyé avec origin_invoice_id et quantité négative', count($credits) === 1 && !empty($credits[0]['b']['origin_invoice_id']) && $credits[0]['b']['products'][0]['quantity'] < 0);

echo "Webhook de stock (REST)\n";
$secret = axonaut_get_webhook_secret();
$call = function ($headers, $body) {
    $req = new WP_REST_Request('POST', '/axonaut/v3/stocklog-created');
    foreach ($headers as $k => $v) { $req->set_header($k, $v); }
    $req->set_body(json_encode($body));
    return rest_do_request($req)->get_status();
};
wp_set_current_user(0);
$payload = array('topic' => 'stockLog.created', 'data' => array('current_stock' => 3, 'product' => array('internal_id' => (string) $pid, 'product_code' => 'TEST-1')));
t('sans secret : 401', $call(array(), $payload) === 401);
t('mauvais secret : 401', $call(array('x-axonaut-secret' => 'nope'), $payload) === 401);
update_option('axo_mock_log', array(), false);
clear_pending();
t('bon secret : 200', $call(array('x-axonaut-secret' => $secret), $payload) === 200);
t('stock mis à jour dans WooCommerce', (int) wc_get_product($pid)->get_stock_quantity() === 3);
t('anti-écho : aucun renvoi planifié vers Axonaut', count(pending('axonaut_sync_stock')) === 0 && count(pending('axonaut_sync_product')) === 0);
$byCode = array('topic' => 'stockLog.created', 'data' => array('current_stock' => 5, 'product' => array('internal_id' => '', 'product_code' => 'TEST-1')));
t('rapprochement par UGS seul : 200', $call(array('x-axonaut-secret' => $secret), $byCode) === 200 && (int) wc_get_product($pid)->get_stock_quantity() === 5);
t('produit inconnu : 404', $call(array('x-axonaut-secret' => $secret), array('topic' => 'stockLog.created', 'data' => array('current_stock' => 1, 'product' => array('internal_id' => '999999', 'product_code' => 'ZZZ')))) === 404);
t('topic invalide : 400', $call(array('x-axonaut-secret' => $secret), array('topic' => 'autre', 'data' => array())) === 400);
update_option('axonaut_dir_stock', 'to_axonaut');
t('sens « vers Axonaut » : le webhook est refusé (403)', $call(array('x-axonaut-secret' => $secret), $payload) === 403);
update_option('axonaut_dir_stock', 'both');

echo "Client inscrit\n";
$uid = wp_insert_user(array('user_login' => 'jean', 'user_pass' => 'x', 'user_email' => 'jean@example.test', 'first_name' => 'Jean', 'last_name' => 'Dupont', 'role' => 'customer'));
update_user_meta($uid, 'billing_first_name', 'Jean'); update_user_meta($uid, 'billing_last_name', 'Dupont');
update_user_meta($uid, 'billing_address_1', '1 rue du Vin'); update_user_meta($uid, 'billing_city', 'Nîmes'); update_user_meta($uid, 'billing_postcode', '30000'); update_user_meta($uid, 'billing_country', 'FR');
$o2 = wc_create_order(array('customer_id' => $uid));
$o2->add_product(wc_get_product($pid), 1); $o2->calculate_totals(); $o2->save();
update_option('axo_mock_log', array(), false);
do_action('axonaut_sync_order', $o2->get_id(), 'invoice', 0, 1);
$cust = null; foreach (mock_log() as $e) { if ($e['p'] === '/api/v2/private/company-and-employee') { $cust = $e['b']; } }
t('client envoyé avec identifiant et adresse', $cust && (string) $cust['internal_id'] === (string) $uid && $cust['company_name'] === 'Jean Dupont' && $cust['address_city'] === 'Nîmes' && $cust['employee_email'] === 'jean@example.test');

echo "Journal\n";
$path = axonaut_log_path();
t('journal écrit dans uploads/axonaut-logs', $path && file_exists($path) && strpos($path, 'axonaut-logs') !== false && filesize($path) > 50);
echo "\n" . (empty($GLOBALS['fails']) ? "Scénario OK" : $GLOBALS['fails'] . " échec(s)") . "\n";
