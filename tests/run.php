<?php
require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__);
require $root . '/includes/utils.php';
require $root . '/includes/class-axonaut-api.php';
require $root . '/includes/class-axonaut-webhooks.php';
require_once $root . '/includes/class-axonaut-product-mapper.php';
require_once $root . '/includes/class-axonaut-product-resolver.php';
require_once $root . '/includes/class-axonaut-sync-settings.php';
require_once $root . '/includes/class-axonaut-snapshot.php';
require_once $root . '/includes/class-axonaut-merge.php';
require_once $root . '/includes/class-axonaut-queue.php';
require_once $root . '/includes/class-axonaut-stock-sync.php';
require_once $root . '/includes/class-axonaut-product-import.php';
require_once $root . '/includes/class-axonaut-rounding.php';
require_once $root . '/includes/class-axonaut-tax.php';
require_once $root . '/includes/axonaut-sell.php';
require_once $root . '/includes/class-axonaut-order-sync.php';

echo "Axonaut_Api\n";
$GLOBALS['axo_http_queue'][] = array('code' => 200, 'body' => '[{"id":7}]');
$r = Axonaut_Api::get('/api/v2/products', array('internal_id' => 'REF#1'), array('page' => 1));
$req = $GLOBALS['axo_http'][0];
check('GET ok et données décodées', $r['ok'] && $r['data'][0]['id'] === 7);
check('paramètres encodés dans l\'URL', strpos($req['url'], 'internal_id=REF%231') !== false);
check('clé API dans les en-têtes', $req['args']['headers']['userApiKey'] === 'KEY');
check('pas de sslverify désactivé', !array_key_exists('sslverify', $req['args']));
$GLOBALS['axo_http_queue'][] = array('code' => 404, 'body' => '{"error":{"status_code":404}}');
$r = Axonaut_Api::get('/api/v2/products');
check('404 => ok=false, status=404', !$r['ok'] && $r['status'] === 404);

echo "AxonautStockLog\n";
$good = '{"topic":"stockLog.created","data":{"product_id":3,"current_stock":12,"product":{"internal_id":"45","product_code":"SKU-1"}}}';
$log = AxonautStockLog::from_request(new WP_REST_Request($good));
check('message valide', $log instanceof AxonautStockLog && $log->internal_id === '45' && $log->current_stock == 12);
check('topic invalide refusé', is_wp_error(AxonautStockLog::from_request(new WP_REST_Request(str_replace('stockLog.created', 'x', $good)))));
check('internal_id et product_code manquants : refusé', is_wp_error(AxonautStockLog::from_request(new WP_REST_Request('{"topic":"stockLog.created","data":{"current_stock":1,"product":{}}}'))));
$byCode = AxonautStockLog::from_request(new WP_REST_Request('{"topic":"stockLog.created","data":{"current_stock":1,"product":{"product_code":"A"}}}'));
check('product_code seul : accepté (rapprochement par UGS)', $byCode instanceof AxonautStockLog && $byCode->product_code === 'A' && $byCode->internal_id === '');
check('corps invalide refusé', is_wp_error(AxonautStockLog::from_request(new WP_REST_Request('nope'))));

echo "Axonaut_Webhooks::authorize\n";
check('secret correct accepté', Axonaut_Webhooks::authorize(new WP_REST_Request('', array('x_axonaut_secret' => 'S3CRET'))) === true);
check('secret incorrect refusé', is_wp_error(Axonaut_Webhooks::authorize(new WP_REST_Request('', array('x_axonaut_secret' => 'bad')))));
check('sans en-tête refusé', is_wp_error(Axonaut_Webhooks::authorize(new WP_REST_Request(''))));
$GLOBALS['axo_can'] = true;
check('utilisateur authentifié par clé WC accepté', Axonaut_Webhooks::authorize(new WP_REST_Request('')) === true);

echo "Axonaut_Product_Mapper::description\n";
$GLOBALS['axo_options']['axonaut_description_source'] = 'short';
$p = new FakeProduct(array('id' => 10, 'short' => '<p>Court; ok</p><script>x</script>', 'long' => 'Long'));
check('description courte nettoyée (; et balises)', Axonaut_Product_Mapper::description($p) === '<p>Court  ok</p>x');
$GLOBALS['axo_options']['axonaut_description_source'] = 'long';
check('source longue', Axonaut_Product_Mapper::description($p) === 'Long');
$GLOBALS['axo_options']['axonaut_description_source'] = 'short_then_long';
$noShort = new FakeProduct(array('id' => 11, 'short' => '  ', 'long' => 'Long'));
check('courte vide => longue', Axonaut_Product_Mapper::description($noShort) === 'Long');
$GLOBALS['axo_options']['axonaut_description_source'] = 'short';
$parent = new FakeProduct(array('id' => 20, 'type' => 'variable', 'short' => 'Parent court'));
$GLOBALS['axo_wc_products'][20] = $parent;
$var = new FakeProduct(array('id' => 21, 'type' => 'variation', 'parent' => 20, 'desc' => 'Variation desc'));
$var2 = new FakeProduct(array('id' => 22, 'type' => 'variation', 'parent' => 20, 'desc' => ''));
check('variation : sa description prime', Axonaut_Product_Mapper::description($var) === 'Variation desc');
check('variation sans description : celle du parent', Axonaut_Product_Mapper::description($var2) === 'Parent court');

echo "Axonaut_Product_Resolver\n";
Axonaut_Product_Resolver::reset_cache();
$GLOBALS['axo_http'] = array();
$list = json_encode(array(
    array('id' => 1, 'product_code' => 'ABC-10', 'internal_id' => '999'),   // lié à un autre produit WooCommerce
    array('id' => 2, 'product_code' => 'abc-1', 'internal_id' => ''),       // même référence, sans identifiant
    array('id' => 3, 'product_code' => 'ABC-1', 'internal_id' => '', 'disabled' => true),
));
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => $list), array('code' => 200, 'body' => '[]'));
$r = Axonaut_Product_Resolver::find_by_sku('ABC-1', 50);
check('référence exacte (insensible à la casse), pas « contient »', $r !== null && $r['id'] === 2);
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => json_encode(array(
    array('id' => 8, 'product_code' => 'ABC-1', 'internal_id' => '999'),
))), array('code' => 200, 'body' => '[]'));
check('référence déjà liée à un autre produit : non reprise', Axonaut_Product_Resolver::find_by_sku('ABC-1', 50) === null);
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => json_encode(array(
    array('id' => 8, 'product_code' => 'ABC-1', 'internal_id' => '999'),
    array('id' => 9, 'product_code' => 'ABC-1', 'internal_id' => '50'),
))));
$r = Axonaut_Product_Resolver::find_by_sku('ABC-1', 50);
check('préfère le produit déjà lié à cet ID', $r !== null && $r['id'] === 9);

// find_remote : lien automatique par PATCH de l'internal_id
Axonaut_Product_Resolver::reset_cache();
$GLOBALS['axo_http'] = array();
$GLOBALS['axo_options']['axonaut_product_match'] = 'sku_then_id';
$wc = new FakeProduct(array('id' => 77, 'sku' => 'ZZ-9'));
$GLOBALS['axo_http_queue'] = array(
    array('code' => 200, 'body' => json_encode(array(array('id' => 5, 'product_code' => 'ZZ-9', 'internal_id' => '')))),
    array('code' => 200, 'body' => '{}'),
);
$remote = Axonaut_Product_Resolver::find_remote($wc);
$patch = end($GLOBALS['axo_http']);
check('produit retrouvé par référence', $remote !== null && $remote['id'] === 5);
check('PATCH inscrit internal_id = ID WooCommerce', $patch['args']['method'] === 'PATCH' && strpos($patch['url'], '/products/5') !== false && strpos($patch['args']['body'], '"internal_id":"77"') !== false);

Axonaut_Product_Resolver::reset_cache();
$GLOBALS['axo_http'] = array();
$GLOBALS['axo_options']['axonaut_product_match'] = 'id_only';
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => '[]'));
Axonaut_Product_Resolver::find_remote($wc);
check('mode id_only : aucune recherche par référence', count($GLOBALS['axo_http']) === 1 && strpos($GLOBALS['axo_http'][0]['url'], 'internal_id=77') !== false);
$GLOBALS['axo_options']['axonaut_product_match'] = 'sku_then_id';

echo "find_wc_product (webhook de stock)\n";
$GLOBALS['axo_wc_products'][300] = new FakeProduct(array('id' => 300, 'sku' => 'SKU-300'));
check('par ID WooCommerce', Axonaut_Product_Resolver::find_wc_product('300', '')->get_id() === 300);
check('par UGS quand internal_id absent', Axonaut_Product_Resolver::find_wc_product('', 'SKU-300')->get_id() === 300);
check('internal_id non numérique : repli sur l\'UGS', Axonaut_Product_Resolver::find_wc_product('REF#9', 'SKU-300')->get_id() === 300);
check('inconnu => null', Axonaut_Product_Resolver::find_wc_product('4040', 'NOPE') === null);

echo "Axonaut_Sync_Settings\n";
$GLOBALS['axo_options'] = array('axonaut_api_key' => 'KEY');
check('défaut historique : rien d\'activé => none', Axonaut_Sync_Settings::direction('stock') === 'none' && Axonaut_Sync_Settings::direction('name') === 'none');
$GLOBALS['axo_options']['axonaut_sync_stocks'] = 'on';
$GLOBALS['axo_options']['axonaut_sync_products'] = 'on';
check('défaut historique : stock depuis Axonaut', Axonaut_Sync_Settings::direction('stock') === 'from_axonaut' && Axonaut_Sync_Settings::pulls('stock') && !Axonaut_Sync_Settings::pushes('stock'));
check('défaut historique : produits vers Axonaut', Axonaut_Sync_Settings::direction('price') === 'to_axonaut');
$GLOBALS['axo_options']['axonaut_dir_stock'] = 'both';
check('réglage enregistré prioritaire', Axonaut_Sync_Settings::pushes('stock') && Axonaut_Sync_Settings::pulls('stock'));
$GLOBALS['axo_options']['axonaut_dir_name'] = 'valeur-invalide';
check('valeur invalide ignorée', Axonaut_Sync_Settings::direction('name') === 'to_axonaut');
check('garde anti-écho', !Axonaut_Sync_Settings::is_inbound() && Axonaut_Sync_Settings::as_inbound(function () { return Axonaut_Sync_Settings::is_inbound(); }) === true && !Axonaut_Sync_Settings::is_inbound());

echo "Axonaut_Merge (comparaison à trois voies)\n";
$m = function ($dir, $wc, $remote, $snap, $win = 'woocommerce') { return Axonaut_Merge::decide($dir, $wc, $remote, $snap, $win); };
check('valeurs égales => rien', $m('both', 'a', 'a', 'x') === 'none');
check('none => rien', $m('none', 'a', 'b', null) === 'none');
check('to_axonaut => envoie', $m('to_axonaut', 'a', 'b', 'b') === 'to_axonaut');
check('from_axonaut => importe', $m('from_axonaut', 'a', 'b', 'a') === 'to_woocommerce');
check('both : seul WooCommerce a changé', $m('both', 'new', 'old', 'old') === 'to_axonaut');
check('both : seul Axonaut a changé', $m('both', 'old', 'new', 'old') === 'to_woocommerce');
check('both : conflit, WooCommerce l\'emporte', $m('both', 'wc', 'ax', 'old', 'woocommerce') === 'to_axonaut');
check('both : conflit, Axonaut l\'emporte', $m('both', 'wc', 'ax', 'old', 'axonaut') === 'to_woocommerce');
check('both : pas d\'instantané => arbitrage par le gagnant', $m('both', 'wc', 'ax', null, 'axonaut') === 'to_woocommerce');

echo "Axonaut_Stock_Sync\n";
Axonaut_Product_Resolver::reset_cache();
$GLOBALS['axo_options'] = array('axonaut_api_key' => 'KEY', 'axonaut_dir_stock' => 'both', 'axonaut_product_match' => 'id_only');
$GLOBALS['axo_http'] = array();
$sp = new FakeProduct(array('id' => 500, 'sku' => 'S-500', 'manage' => true, 'qty' => 7));
$GLOBALS['axo_http_queue'] = array(
    array('code' => 200, 'body' => json_encode(array(array('id' => 61, 'internal_id' => '500', 'product_code' => 'S-500')))),
    array('code' => 200, 'body' => '{}'),
);
check('push stock : succès', Axonaut_Stock_Sync::push($sp) === true);
$last = end($GLOBALS['axo_http']);
check('PATCH /products/{id}/stock avec la valeur absolue', $last['args']['method'] === 'PATCH' && substr($last['url'], -18) === '/products/61/stock' && strpos($last['args']['body'], '"stock":7') !== false);
$n = count($GLOBALS['axo_http']);
Axonaut_Stock_Sync::push($sp);
check('stock inchangé depuis l\'instantané : aucun appel', count($GLOBALS['axo_http']) === $n);
$sp->d['qty'] = 5;
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => '{}'));
Axonaut_Product_Resolver::reset_cache();
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => json_encode(array(array('id' => 61, 'internal_id' => '500')))), array('code' => 200, 'body' => '{}'));
Axonaut_Stock_Sync::push($sp);
check('stock modifié : nouvel envoi', count($GLOBALS['axo_http']) === $n + 2);
Axonaut_Stock_Sync::apply_inbound($sp, 12);
check('entrant : stock appliqué et instantané mis à jour', $sp->d['qty'] === 12 && Axonaut_Snapshot::get($sp)['stock'] === '12');
$n = count($GLOBALS['axo_http']);
Axonaut_Stock_Sync::push($sp);
check('anti-écho : le stock reçu n\'est pas renvoyé', count($GLOBALS['axo_http']) === $n);

echo "Axonaut_Queue\n";
Axonaut_Queue::enqueue_stock(500, 30);
Axonaut_Queue::enqueue_stock(500, 30);
check('une seule tâche en attente par produit', count($GLOBALS['axo_as']) === 1);

echo "Axonaut_Product_Import\n";
$GLOBALS['axo_options'] = array('axonaut_api_key' => 'KEY', 'axonaut_dir_name' => 'from_axonaut', 'axonaut_dir_description' => 'both', 'axonaut_dir_price' => 'from_axonaut', 'axonaut_dir_stock' => 'none', 'axonaut_conflict_winner' => 'woocommerce');
$GLOBALS['axo_http'] = array();
$GLOBALS['axo_as'] = array();
$ip = new FakeProduct(array('id' => 700, 'name' => 'Ancien nom', 'short' => 'Ancien descriptif', 'price' => 10.0));
$GLOBALS['axo_wc_products'][700] = $ip;
Axonaut_Snapshot::update($ip, array('name' => 'Ancien nom', 'description' => 'Ancien descriptif', 'price' => '10.00'));
$updated = Axonaut_Product_Import::import_one(array('id' => 90, 'internal_id' => '700', 'product_code' => '', 'name' => 'Nouveau nom', 'description' => 'Ancien descriptif', 'price' => 12.5));
check('titre et prix importés, descriptif inchangé', $updated === array('name', 'price') && $ip->get_name() === 'Nouveau nom' && $ip->d['price'] === 12.5);
check('instantané mis à jour', Axonaut_Snapshot::get($ip)['name'] === 'Nouveau nom' && Axonaut_Snapshot::get($ip)['price'] === '12.50');
check('aucun produit à renvoyer vers Axonaut', count($GLOBALS['axo_as']) === 0);

// descriptif dans les deux sens : modifié seulement côté WooCommerce => renvoi vers Axonaut, pas d'écrasement
$ip->d['short'] = 'Descriptif WooCommerce';
$updated = Axonaut_Product_Import::import_one(array('id' => 90, 'internal_id' => '700', 'name' => 'Nouveau nom', 'description' => 'Ancien descriptif', 'price' => 12.5));
check('modifié côté WooCommerce seulement : conservé', $ip->d['short'] === 'Descriptif WooCommerce' && !in_array('description', $updated, true));
check('...et planifié vers Axonaut', count($GLOBALS['axo_as']) === 1);

// modifié des deux côtés, WooCommerce l'emporte
$updated = Axonaut_Product_Import::import_one(array('id' => 90, 'internal_id' => '700', 'name' => 'Nouveau nom', 'description' => 'Descriptif Axonaut', 'price' => 12.5));
check('conflit : WooCommerce l\'emporte', $ip->d['short'] === 'Descriptif WooCommerce');
$GLOBALS['axo_options']['axonaut_conflict_winner'] = 'axonaut';
$updated = Axonaut_Product_Import::import_one(array('id' => 90, 'internal_id' => '700', 'name' => 'Nouveau nom', 'description' => 'Descriptif Axonaut', 'price' => 12.5));
check('conflit : Axonaut l\'emporte', $ip->d['short'] === 'Descriptif Axonaut' && in_array('description', $updated, true));

// prix promotionnel : jamais écrasé
$ip->d['on_sale'] = true;
$updated = Axonaut_Product_Import::import_one(array('id' => 90, 'internal_id' => '700', 'name' => 'Nouveau nom', 'description' => 'Descriptif Axonaut', 'price' => 99.0));
check('produit en promotion : prix ignoré', $ip->d['price'] === 12.5);

// produit inconnu de WooCommerce : ignoré
check('produit absent de WooCommerce : ignoré', Axonaut_Product_Import::import_one(array('id' => 91, 'internal_id' => '8888', 'product_code' => 'NOPE', 'name' => 'X')) === array());

// liaison par référence : PATCH de l'internal_id
$GLOBALS['axo_options']['axonaut_product_match'] = 'sku_then_id';
$GLOBALS['axo_wc_products'][701] = new FakeProduct(array('id' => 701, 'sku' => 'LINK-1', 'name' => 'Lié', 'price' => 5.0));
$GLOBALS['axo_http'] = array();
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => '{}'));
Axonaut_Product_Import::import_one(array('id' => 92, 'internal_id' => '', 'product_code' => 'LINK-1', 'name' => 'Lié', 'price' => 5.0));
check('produit Axonaut sans internal_id lié par sa référence', count($GLOBALS['axo_http']) === 1 && strpos($GLOBALS['axo_http'][0]['args']['body'], '"internal_id":"701"') !== false);

echo "Axonaut_Rounding\n";
$lines = array(array('price' => 3.3333, 'quantity' => 3, 'tax_rate' => 20), array('price' => 4.17, 'quantity' => 1, 'tax_rate' => 5.5));
$exp = Axonaut_Rounding::expected($lines);
check('totaux attendus par taux', $exp['ht'] === 14.17 && $exp['tax'] === round(10.00 * 0.2, 2) + round(4.17 * 0.055, 2) && $exp['total'] === round(14.17 + $exp['tax'], 2));
check('aucun écart => pas d\'ajustement', Axonaut_Rounding::adjustment($lines, $exp['total']) === null);
$adj = Axonaut_Rounding::adjustment($lines, $exp['total'] + 0.02);
$lines2 = array_merge($lines, array($adj));
check('écart de 2 centimes corrigé exactement', $adj !== null && Axonaut_Rounding::expected($lines2)['total'] === round($exp['total'] + 0.02, 2));
$adjNeg = Axonaut_Rounding::adjustment($lines, $exp['total'] - 0.01);
check('écart négatif corrigé', $adjNeg !== null && Axonaut_Rounding::expected(array_merge($lines, array($adjNeg)))['total'] === round($exp['total'] - 0.01, 2));
check('écart trop grand : pas un arrondi, pas d\'ajustement', Axonaut_Rounding::adjustment($lines, $exp['total'] + 1.5) === null);
check('cohérence facture : total TTC', Axonaut_Rounding::matches_invoice(array('total' => 100.0, 'tax_amount' => 16.67), 100.0));
check('cohérence facture : HT + TVA', Axonaut_Rounding::matches_invoice(array('total' => 83.33, 'tax_amount' => 16.67), 100.0));
check('incohérence détectée', !Axonaut_Rounding::matches_invoice(array('total' => 99.5, 'tax_amount' => 16.5), 100.0));

echo "Axonaut_Tax\n";
WC_Tax::$rates = array(7 => '20.0000', 8 => '5.5000');
check('taux exact lu depuis WooCommerce', Axonaut_Tax::item_rate(new FakeItem(array('total' => 10, 'tax' => 0.55, 'taxes' => array('total' => array(8 => 0.55))))) === 5.5);
check('taux inconnu : repli sur le ratio', Axonaut_Tax::item_rate(new FakeItem(array('total' => 10, 'tax' => 2.0, 'taxes' => array('total' => array(99 => 2.0))))) === 20.0);
check('sans taxe : 0', Axonaut_Tax::item_rate(new FakeItem(array('total' => 10, 'tax' => 0))) === 0.0);

class TestSell extends AxonautSell { protected function sync_customer() {} }
class TestOrderSync extends Axonaut_Order_Sync { protected static function make_sell($order) { return new TestSell($order); } }
echo "AxonautSell : facture idempotente et avoirs\n";
$GLOBALS['axo_options'] = array('axonaut_api_key' => 'KEY', 'axonaut_product_match' => 'id_only', 'axonaut_sync_sells' => 'on', 'axonaut_sync_credit_notes' => 'on');
Axonaut_Product_Resolver::reset_cache();
$prod = new FakeProduct(array('id' => 31, 'sku' => 'VIN-31', 'name' => 'Vin rouge', 'short' => 'Bouteille'));
$order = new FakeOrder(array('id' => 900, 'total' => 12.0, 'items' => array(new FakeItem(array('qty' => 2, 'total' => 10.0, 'tax' => 2.0, 'taxes' => array('total' => array(7 => 2.0)), 'product' => $prod)))));
$GLOBALS['axo_orders'][900] = $order;
$sell = new TestSell($order);

$GLOBALS['axo_http'] = array();
$GLOBALS['axo_http_queue'] = array(
    array('code' => 200, 'body' => '[]'),                                                       // recherche internal_ref
    array('code' => 200, 'body' => json_encode(array('id' => 4001, 'total' => 12.0, 'tax_amount' => 2.0, 'company' => array('id' => 77)))),
);
$id = $sell->send();
$post = end($GLOBALS['axo_http']);
$sent = json_decode($post['args']['body'], true);
check('facture créée, id mémorisé sur la commande', $id === 4001 && $order->get_meta('_axonaut_invoice_id') === 4001 && $order->get_meta('_axonaut_company_id') === 77);
check('ligne : référence, taux exact, prix unitaire', $sent['products'][0]['product_code'] === 'VIN-31' && $sent['products'][0]['internal_id'] === '31' && $sent['products'][0]['tax_rate'] == 20 && $sent['products'][0]['price'] == 5 && $sent['products'][0]['quantity'] == 2);
check('totaux cohérents : pas de note', empty($order->d['notes']));

// 2e appel : aucune création (idempotence), la facture est relue par son id
$GLOBALS['axo_http'] = array();
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => json_encode(array('id' => 4001, 'total' => 12.0))));
check('deuxième envoi : même facture, aucune création', $sell->send() === 4001 && count($GLOBALS['axo_http']) === 1 && $GLOBALS['axo_http'][0]['args']['method'] === 'GET');

// remboursement partiel de 1 unité
$refund = new FakeOrder(array('id' => 901, 'amount' => 6.0, 'items' => array(new FakeItem(array('qty' => -1, 'total' => -5.0, 'tax' => -1.0, 'taxes' => array('total' => array(7 => -1.0)), 'product' => $prod)))));
$GLOBALS['axo_http'] = array();
$GLOBALS['axo_http_queue'] = array(
    array('code' => 200, 'body' => json_encode(array('id' => 4001, 'total' => 12.0))),
    array('code' => 200, 'body' => json_encode(array('id' => 4002))),
);
$credit = $sell->send_credit_note($refund);
$sent = json_decode(end($GLOBALS['axo_http'])['args']['body'], true);
check('avoir créé', $credit === 4002 && $refund->get_meta('_axonaut_credit_note_id') === 4002);
check('avoir : quantité négative, prix positif, facture d\'origine', $sent['products'][0]['quantity'] == -1 && $sent['products'][0]['price'] == 5 && $sent['origin_invoice_id'] === 4001 && $sent['company_id'] === 77);
$GLOBALS['axo_http'] = array();
check('avoir déjà créé : ignoré', $sell->send_credit_note($refund) === null && count($GLOBALS['axo_http']) === 0);

// remboursement sans ligne (montant libre)
$free = new FakeOrder(array('id' => 902, 'amount' => 3.0, 'reason' => 'Geste commercial'));
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => json_encode(array('id' => 4001, 'total' => 12.0))), array('code' => 200, 'body' => json_encode(array('id' => 4003))));
$sell->send_credit_note($free);
$sent = json_decode(end($GLOBALS['axo_http'])['args']['body'], true);
check('montant libre : une ligne « Remboursement »', $sent['products'][0]['internal_id'] === '411999' && $sent['products'][0]['quantity'] == -1 && $sent['products'][0]['price'] == 3);

// écart de total signalé en note de commande
$order2 = new FakeOrder(array('id' => 910, 'total' => 12.0, 'items' => array(new FakeItem(array('qty' => 1, 'total' => 10.0, 'tax' => 2.0, 'taxes' => array('total' => array(7 => 2.0)), 'product' => $prod)))));
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => '[]'), array('code' => 200, 'body' => json_encode(array('id' => 5001, 'total' => 11.99, 'tax_amount' => 1.99))));
(new TestSell($order2))->send();
check('écart de total : note sur la commande', count($order2->d['notes']) === 1);

echo "Axonaut_Order_Sync (verrou et nouvelles tentatives)\n";
$GLOBALS['axo_as'] = array();
Axonaut_Order_Sync::on_processing(900);
Axonaut_Order_Sync::on_processing(900);
check('une seule tâche par commande et action', count($GLOBALS['axo_as']) === 1);
$GLOBALS['axo_as'] = array();
$GLOBALS['axo_http'] = array();
$GLOBALS['axo_http_queue'] = array(array('code' => 500, 'body' => '{"error":"x"}'));   // la facture échoue (aucune facture mémorisée)
$orderRetry = new FakeOrder(array('id' => 920, 'total' => 12.0, 'items' => array(new FakeItem(array('qty' => 1, 'total' => 10.0, 'tax' => 2.0, 'taxes' => array('total' => array(7 => 2.0)), 'product' => $prod)))));
$GLOBALS['axo_orders'][920] = $orderRetry;
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => '[]'), array('code' => 500, 'body' => '{"error":"down"}'));
TestOrderSync::run(920, 'invoice', 0, 1);
check('échec : nouvelle tentative planifiée', count($GLOBALS['axo_as']) === 1);
$GLOBALS['axo_as'] = array();
$GLOBALS['axo_http_queue'] = array(array('code' => 200, 'body' => '[]'), array('code' => 500, 'body' => '{"error":"down"}'));
TestOrderSync::run(920, 'invoice', 0, 4);
check('4e échec : note de commande, plus de tentative', count($GLOBALS['axo_as']) === 0 && count($orderRetry->d['notes']) === 1);
check('verrou libéré après exécution', !array_key_exists('axonaut_lock_920', $GLOBALS['axo_options']));
$GLOBALS['axo_options']['axonaut_lock_920'] = time();
TestOrderSync::run(920, 'invoice', 0, 1);
check('verrou actif : la tâche est replanifiée sans rien envoyer', count($GLOBALS['axo_as']) === 1);

echo $GLOBALS['axo_fail'] ? "\n{$GLOBALS['axo_fail']} échec(s)\n" : "\nTout est vert\n";
exit($GLOBALS['axo_fail'] ? 1 : 0);
