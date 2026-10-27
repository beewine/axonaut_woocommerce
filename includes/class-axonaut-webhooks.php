<?php

/**
 * Webhooks reçus d'Axonaut.
 *
 * @since      3.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */
require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-stock.php";

class Axonaut_Webhooks
{
    /**
     * Contrôle d'accès de la route : secret partagé (en-tête X-Axonaut-Secret)
     * ou clé API WooCommerce en Basic (méthode historique du module).
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function authorize($request)
    {
        $secret = get_option('axonaut_webhook_secret');
        $provided = $request->get_header('x_axonaut_secret');
        if (!empty($secret) && !empty($provided) && hash_equals($secret, $provided)) {
            return true;
        }

        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        return new WP_Error('axonaut_forbidden', __("Accès refusé", "axonaut"), array('status' => 401));
    }

    /**
     * Fait reconnaître à WooCommerce nos routes comme éligibles à l'authentification
     * par clé API (Basic), afin que la configuration décrite dans la documentation
     * du module (clé « stock-axonaut ») soit réellement vérifiée.
     *
     * @param bool $is_request_to_rest_api
     * @return bool
     */
    public static function allow_woocommerce_key_auth($is_request_to_rest_api)
    {
        if ($is_request_to_rest_api || empty($_SERVER['REQUEST_URI'])) {
            return $is_request_to_rest_api;
        }
        $uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        return false !== strpos($uri, trailingslashit(rest_get_url_prefix()) . 'axonaut/');
    }

    /**
     * Receive stocklog.created events from Axonaut
     *
     * @since    3.0.0
     */
    public static function axonaut_handle_webhook_stocklog_created($request)
    {
        if (!get_option('axonaut_sync_stocks')) {
            return new WP_REST_Response("sync_disabled", 403);
        }

        $stockLog = AxonautStockLog::from_request($request);
        if (is_wp_error($stockLog)) {
            return new WP_REST_Response($stockLog->get_error_message(), 400);
        }

        self::update_stock($stockLog->internal_id, $stockLog->current_stock);

        return new WP_REST_Response([], 200);
    }

    private static function update_stock($productId, $stock)
    {
        $product = wc_get_product($productId);
        if ($product) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
            $product->save();
        }
    }
}
