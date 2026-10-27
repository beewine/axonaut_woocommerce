<?php
require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__);
require $root . '/includes/utils.php';
require $root . '/includes/class-axonaut-api.php';
require $root . '/includes/class-axonaut-webhooks.php';

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
check('internal_id manquant refusé', is_wp_error(AxonautStockLog::from_request(new WP_REST_Request('{"topic":"stockLog.created","data":{"current_stock":1,"product":{"product_code":"A"}}}'))));
check('corps invalide refusé', is_wp_error(AxonautStockLog::from_request(new WP_REST_Request('nope'))));

echo "Axonaut_Webhooks::authorize\n";
check('secret correct accepté', Axonaut_Webhooks::authorize(new WP_REST_Request('', array('x_axonaut_secret' => 'S3CRET'))) === true);
check('secret incorrect refusé', is_wp_error(Axonaut_Webhooks::authorize(new WP_REST_Request('', array('x_axonaut_secret' => 'bad')))));
check('sans en-tête refusé', is_wp_error(Axonaut_Webhooks::authorize(new WP_REST_Request(''))));
$GLOBALS['axo_can'] = true;
check('utilisateur authentifié par clé WC accepté', Axonaut_Webhooks::authorize(new WP_REST_Request('')) === true);

echo $GLOBALS['axo_fail'] ? "\n{$GLOBALS['axo_fail']} échec(s)\n" : "\nTout est vert\n";
exit($GLOBALS['axo_fail'] ? 1 : 0);
