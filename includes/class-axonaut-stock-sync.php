<?php

/**
 * Stock WooCommerce vers Axonaut.
 *
 * Le stock est envoyé en valeur absolue (PATCH /products/{id}/stock), avec un délai de 30 s :
 * si Axonaut décrémente lui-même son stock à la création de la facture, la valeur envoyée
 * (celle de WooCommerce après la commande) l'emporte sans double décompte.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-api.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-sync-settings.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-snapshot.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-product-resolver.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-queue.php";

class Axonaut_Stock_Sync
{
    const DELAY = 30;

    /**
     * Hook woocommerce_product_set_stock / woocommerce_variation_set_stock.
     * @param WC_Product $product
     */
    public static function on_stock_changed($product)
    {
        if (Axonaut_Sync_Settings::is_inbound() || !Axonaut_Sync_Settings::pushes('stock') || get_option('axonaut_api_key') === '') {
            return;
        }
        Axonaut_Queue::enqueue_stock($product->get_id(), self::DELAY);
    }

    /** Tâche planifiée. */
    public static function run($productId)
    {
        $product = wc_get_product($productId);
        if ($product) {
            self::push($product);
        }
    }

    /**
     * Envoie le stock du produit s'il a changé depuis la dernière synchronisation.
     *
     * @param WC_Product $product
     * @param array|null $remote produit Axonaut déjà retrouvé
     * @return bool
     */
    public static function push($product, $remote = null)
    {
        if (!Axonaut_Sync_Settings::pushes('stock') || !$product->managing_stock() || $product->get_stock_quantity() === null) {
            return true;
        }

        $quantity = (float) $product->get_stock_quantity();
        $snapshot = Axonaut_Snapshot::get($product);
        if (isset($snapshot['stock']) && $snapshot['stock'] === Axonaut_Snapshot::normalize('stock', $quantity)) {
            return true;
        }

        if ($remote === null) {
            $remote = Axonaut_Product_Resolver::find_remote($product, false);
        }
        if ($remote === null) {
            // Produit inconnu d'Axonaut : sa création envoie aussi le stock.
            require_once plugin_dir_path(__FILE__) . "class-axonaut-product-sync.php";
            return Axonaut_Product_Sync::push($product);
        }

        $result = Axonaut_Api::patch('/api/v2/products/' . $remote['id'] . '/stock', array(
            'stock' => $quantity,
            'update_reason' => 'woocommerce',
        ));
        if (!$result['ok']) {
            return false;
        }

        Axonaut_Snapshot::update($product, array('stock' => Axonaut_Snapshot::normalize('stock', $quantity)));
        axonaut_write_log("[ Product : " . $product->get_id() . " ] Stock sent to Axonaut ($quantity).");
        return true;
    }

    /**
     * Applique un stock reçu d'Axonaut au produit WooCommerce.
     *
     * @param WC_Product $product
     * @param float|int|string $stock
     */
    public static function apply_inbound($product, $stock)
    {
        Axonaut_Sync_Settings::as_inbound(function () use ($product, $stock) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
            $product->save();
        });
        Axonaut_Snapshot::update($product, array('stock' => Axonaut_Snapshot::normalize('stock', $stock)));
    }
}
