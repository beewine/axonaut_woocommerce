<?php

/**
 * Synchronisation d'un produit WooCommerce vers Axonaut.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-api.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-product-mapper.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-product-resolver.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-queue.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-sync-settings.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-snapshot.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-stock-sync.php";

class Axonaut_Product_Sync
{
    /**
     * Hook woocommerce_new_product / update_product (et leurs variantes pour les variations).
     * @param int $productId
     */
    public static function on_product_saved($productId)
    {
        if (Axonaut_Sync_Settings::is_inbound() || empty(Axonaut_Sync_Settings::pushed_fields()) || get_option('axonaut_api_key') === '') {
            return;
        }
        Axonaut_Queue::enqueue_product($productId, 10);
    }

    /**
     * Tâche planifiée : synchronise un produit.
     * @param int $productId
     */
    public static function run($productId)
    {
        if (empty(Axonaut_Sync_Settings::pushed_fields()) || get_option('axonaut_api_key') === '') {
            return;
        }
        $product = wc_get_product($productId);
        if ($product) {
            self::push($product);
        }
    }

    /**
     * Crée ou met à jour le produit dans Axonaut, pour les champs envoyés vers Axonaut.
     * Un produit variable n'est pas envoyé : ce sont ses variations qui le sont.
     *
     * @param WC_Product $product
     * @return bool
     */
    public static function push($product)
    {
        if ($product->is_type('variable')) {
            return true;
        }
        if (!in_array($product->get_status(), array('publish', 'private'), true)) {
            return true;
        }

        $wcId = $product->get_id();
        $fields = Axonaut_Sync_Settings::pushed_fields();
        $remote = Axonaut_Product_Resolver::find_remote($product, false);

        if ($remote !== null) {
            $body = Axonaut_Product_Mapper::payload($product, $fields);
            $result = Axonaut_Api::patch('/api/v2/products/' . $remote['id'], $body);
            $action = 'updated';
        } else {
            // Création : tous les champs, et le stock initial si WooCommerce le gère.
            $body = Axonaut_Product_Mapper::payload($product);
            if ($product->managing_stock() && $product->get_stock_quantity() !== null) {
                $body['stock'] = (float) $product->get_stock_quantity();
            }
            $result = Axonaut_Api::post('/api/v2/products', $body);
            $action = 'created';
            $fields = array_merge($fields, array('name', 'description', 'price'));
            if (isset($body['stock'])) {
                $fields[] = 'stock';
            }
        }

        if (!$result['ok']) {
            return false;
        }
        if ($action === 'created') {
            Axonaut_Product_Resolver::remember($product, $result['data']);
        }

        $values = Axonaut_Product_Mapper::values($product);
        if (isset($body['stock'])) {
            $values['stock'] = Axonaut_Snapshot::normalize('stock', $body['stock']);
        }
        Axonaut_Snapshot::update($product, array_intersect_key($values, array_flip($fields)));
        axonaut_write_log("[ Product : $wcId ] Axonaut product $action (SKU '" . $body['product_code'] . "').");

        if ($action === 'updated') {
            Axonaut_Stock_Sync::push($product, $remote);
        }
        return true;
    }

    /**
     * Planifie la synchronisation de tout le catalogue, à raison d'un produit toutes
     * les 2 secondes (limite de débit de l'API).
     *
     * @return int nombre de produits planifiés
     */
    public static function enqueue_all()
    {
        $ids = wc_get_products(array(
            'limit' => -1,
            'return' => 'ids',
            'status' => array('publish', 'private'),
        ));

        $count = 0;
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }
            $targets = $product->is_type('variable') ? $product->get_children() : array($id);
            foreach ($targets as $targetId) {
                Axonaut_Queue::enqueue_product($targetId, $count * 2);
                $count++;
            }
        }

        return $count;
    }
}
