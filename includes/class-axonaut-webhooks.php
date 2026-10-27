<?php

/**
 * Fired during plugin activation
 *
 * @since      1.0.0
 *
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      3.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */
require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-stock.php";

class Axonaut_Webhooks
{
    /**
     * Receive stocklog.created events from Axonaut
     *
     * @since    3.0.0
     */
    public static function axonaut_handle_webhook_stocklog_created($request) {
        if(!get_option('axonaut_sync_stocks')) return new WP_REST_Response("sync_disabled", 403);

        try{
            $stockLog = new AxonautStockLog($request);
        }catch(Exception $e){

            return new WP_REST_Response($e->getMessage(), 400);
        }

        self::update_stock($stockLog->data->product->internal_id, $stockLog->data->current_stock);

        return new WP_REST_Response([], 200);
    }

    private static function update_stock($productId, $stock){
        $product = wc_get_product( $productId );
        if($product){
            $product->set_stock_quantity($stock);
            if($stock > 0){
                $product->set_stock_status('instock');
            }else{
                $product->set_stock_status('outofstock');
            }
            $product->save();
        }
    }

}
