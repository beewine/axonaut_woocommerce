<?php
require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__);
require $root . '/includes/utils.php';
require $root . '/includes/class-axonaut-api.php';
require $root . '/includes/class-axonaut-webhooks.php';
require_once $root . '/includes/class-axonaut-product-mapper.php';
require_once $root . '/includes/class-axonaut-product-resolver.php';

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

echo $GLOBALS['axo_fail'] ? "\n{$GLOBALS['axo_fail']} échec(s)\n" : "\nTout est vert\n";
exit($GLOBALS['axo_fail'] ? 1 : 0);
