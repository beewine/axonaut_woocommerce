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

class Axonaut_Product_Sync
{
    /**
     * Tâche planifiée : synchronise un produit.
     * @param int $productId
     */
    public static function run($productId)
    {
        if (get_option('axonaut_sync_products') !== 'on' || get_option('axonaut_api_key') === '') {
            return;
        }
        $product = wc_get_product($productId);
        if ($product) {
            self::push($product);
        }
    }

    /**
     * Crée ou met à jour le produit dans Axonaut.
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
        $body = Axonaut_Product_Mapper::payload($product);
        $remote = Axonaut_Product_Resolver::find_remote($product, false);

        if ($remote !== null) {
            $result = Axonaut_Api::patch('/api/v2/products/' . $remote['id'], $body);
            $action = 'updated';
        } else {
            // La création accepte le stock initial ; la mise à jour passe par /stock.
            if ($product->managing_stock() && $product->get_stock_quantity() !== null) {
                $body['stock'] = (float) $product->get_stock_quantity();
            }
            $result = Axonaut_Api::post('/api/v2/products', $body);
            $action = 'created';
        }

        if (!$result['ok']) {
            return false;
        }

        axonaut_write_log("[ Product : $wcId ] Axonaut product $action (SKU '" . $body['product_code'] . "').");
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
