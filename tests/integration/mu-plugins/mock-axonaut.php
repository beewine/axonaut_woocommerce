<?php
// Simule l'API Axonaut : journalise les requêtes et répond avec un petit état en mémoire (option).
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'https://axonaut.com') !== 0) { return $pre; }
    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    $method = $args['method'] ?? 'GET';
    $body = isset($args['body']) ? json_decode($args['body'], true) : null;
    $log = get_option('axo_mock_log', array());
    $log[] = array('m' => $method, 'p' => $path, 'q' => $query, 'b' => $body);
    update_option('axo_mock_log', $log, false);
    $db = get_option('axo_mock_products', array());   // id => produit
    $reply = function ($data, $code = 200) { return array('headers' => array(), 'body' => json_encode($data), 'response' => array('code' => $code, 'message' => ''), 'cookies' => array(), 'filename' => null); };
    parse_str((string) $query, $q);

    if ($path === '/api/v2/products' && $method === 'GET') {
        $out = array();
        foreach ($db as $p) {
            if (isset($q['internal_id']) && (string) $p['internal_id'] !== (string) $q['internal_id']) { continue; }
            if (isset($q['product_code']) && stripos($p['product_code'], $q['product_code']) === false) { continue; }
            $out[] = $p;
        }
        $page = (int) ($args['headers']['page'] ?? 1);
        return $reply($page > 1 ? array() : $out);
    }
    if ($path === '/api/v2/products' && $method === 'POST') {
        $id = count($db) + 100;
        $db[$id] = array('id' => $id, 'internal_id' => $body['internal_id'] ?? '', 'product_code' => $body['product_code'] ?? '', 'name' => $body['name'] ?? '', 'description' => $body['description'] ?? '', 'price' => $body['price'] ?? 0, 'stock' => $body['stock'] ?? null);
        update_option('axo_mock_products', $db, false);
        return $reply($db[$id]);
    }
    if (preg_match('#^/api/v2/products/(\d+)/stock$#', $path, $m) && $method === 'PATCH') {
        $db[(int) $m[1]]['stock'] = $body['stock']; update_option('axo_mock_products', $db, false); return $reply(array());
    }
    if (preg_match('#^/api/v2/products/(\d+)$#', $path, $m) && $method === 'PATCH') {
        $db[(int) $m[1]] = array_merge($db[(int) $m[1]], $body); update_option('axo_mock_products', $db, false); return $reply($db[(int) $m[1]]);
    }
    if ($path === '/api/v2/invoices' && $method === 'GET') { return $reply(array()); }
    if ($path === '/api/v2/invoices' && $method === 'POST') {
        $total = 0; foreach ($body['products'] as $l) { $total += $l['price'] * $l['quantity'] * (1 + $l['tax_rate'] / 100); }
        return $reply(array('id' => 9000 + count($log), 'total' => round($total, 2), 'tax_amount' => 0, 'company' => array('id' => 55), 'paid_date' => null));
    }
    if (preg_match('#^/api/v2/invoices/(\d+)$#', $path)) { return $reply(array('id' => 9001, 'total' => 12, 'paid_date' => null)); }
    if ($path === '/api/v2/payments') { return $reply(array('id' => 1)); }
    return $reply(array('error' => array('status_code' => 404)), 404);
}, 10, 3);
