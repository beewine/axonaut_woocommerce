<?php
function t($label, $ok) { echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n"; if (!$ok) { $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1; } }
function pending($hook) { return as_get_scheduled_actions(array('hook' => $hook, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50), 'ids'); }

echo "Administration\n";
wp_set_current_user(1);
$admin = new Axonaut_Admin('Axonaut', '2.5.0');
$admin->axonaut_settings_init();
ob_start(); $admin->axonaut_options_page(); $html = ob_get_clean();
t('page de configuration rendue sans erreur', strlen($html) > 2000);
t('champs de sens de synchronisation présents', strpos($html, 'axonaut_dir_stock') !== false && strpos($html, 'axonaut_dir_description') !== false && strpos($html, 'axonaut_conflict_winner') !== false);
t('rapprochement, avoirs et arrondis présents', strpos($html, 'axonaut_product_match') !== false && strpos($html, 'axonaut_sync_credit_notes') !== false && strpos($html, 'axonaut_rounding_mode') !== false);
t('secret du webhook affiché', strpos($html, axonaut_get_webhook_secret()) !== false);
t('URL du webhook via rest_url', strpos($html, 'axonaut/v3/stocklog-created') !== false);
t('anciens interrupteurs produits/stocks retirés', strpos($html, 'name="axonaut_sync_products"') === false && strpos($html, 'name="axonaut_sync_stocks"') === false);

echo "Téléchargement du journal et AJAX : contrôle des droits\n";
wp_set_current_user(0);
$denied = false;
add_filter('wp_die_handler', function () { return function ($m) { throw new Exception('die'); }; });
try { $admin->ajax_sync(); } catch (Exception $e) { $denied = true; }
t('synchro AJAX refusée sans droits ni nonce', $denied);
$denied = false;
try { $admin->download_log(); } catch (Exception $e) { $denied = true; }
t('téléchargement du journal refusé sans droits', $denied);

echo "Authentification par clé API WooCommerce (Basic)\n";
global $wpdb;
$ck = 'ck_' . wc_rand_hash(); $cs = 'cs_' . wc_rand_hash();
$wpdb->insert($wpdb->prefix . 'woocommerce_api_keys', array('user_id' => 1, 'description' => 'stock-axonaut', 'permissions' => 'read_write', 'consumer_key' => wc_api_hash($ck), 'consumer_secret' => $cs, 'truncated_key' => substr($ck, -7)));
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/wp-json/axonaut/v3/stocklog-created';
$_SERVER['PHP_AUTH_USER'] = $ck; $_SERVER['PHP_AUTH_PW'] = $cs;
wp_set_current_user(0);
$uid = apply_filters('determine_current_user', false);
t('la clé WooCommerce authentifie la route du module', (int) $uid === 1);
unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTPS']);
$_SERVER['REQUEST_URI'] = '/wp-json/autre/v1/x';
t('pas d\'authentification hors de nos routes', !apply_filters('determine_current_user', false));

echo "Import périodique depuis Axonaut\n";
update_option('axonaut_dir_name', 'from_axonaut'); update_option('axonaut_dir_description', 'from_axonaut'); update_option('axonaut_dir_price', 'from_axonaut'); update_option('axonaut_dir_stock', 'none');
$pid = wc_get_product_id_by_sku('TEST-1');
$db = get_option('axo_mock_products');
foreach ($db as $k => $row) { if ($row['internal_id'] == $pid) { $db[$k]['name'] = 'Vin test renommé dans Axonaut'; $db[$k]['description'] = 'Nouveau descriptif Axonaut'; $db[$k]['price'] = 15.0; } }
update_option('axo_mock_products', $db, false);
foreach (array('axonaut_sync_product', 'axonaut_sync_stock', 'axonaut_pull_page') as $h) { as_unschedule_all_actions($h); }
Axonaut_Product_Import::run_page(1);
$p = wc_get_product($pid);
t('titre repris dans WooCommerce', $p->get_name() === 'Vin test renommé dans Axonaut');
t('descriptif court repris', $p->get_short_description() === 'Nouveau descriptif Axonaut');
t('prix repris', (float) $p->get_regular_price() === 15.0);
t('aucun renvoi vers Axonaut (anti-écho)', count(pending('axonaut_sync_product')) === 0);
t('page suivante planifiée', count(pending('axonaut_pull_page')) === 1);
Axonaut_Product_Import::ensure_schedule();
t('import horaire planifié', (bool) as_next_scheduled_action('axonaut_pull_start', array(), 'axonaut'));
update_option('axonaut_dir_name', 'none'); update_option('axonaut_dir_description', 'none'); update_option('axonaut_dir_price', 'none');
Axonaut_Product_Import::ensure_schedule();
t('import désactivé quand aucun champ n\'est importé', !as_next_scheduled_action('axonaut_pull_start', array(), 'axonaut'));

echo "\n" . (empty($GLOBALS['fails']) ? "Scénario 2 OK" : $GLOBALS['fails'] . " échec(s)") . "\n";
