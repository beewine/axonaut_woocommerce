<?php

/**
 * Message stockLog.created reçu d'Axonaut.
 *
 * @since      3.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

class AxonautStockLog
{
    public $id;
    public $timestamp;
    public $date;
    public $topic = "stockLog.created";

    public $product_id;
    public $current_stock;
    public $internal_id;
    public $product_code;

    /**
     * Construit le message à partir de la requête REST.
     *
     * @param WP_REST_Request $request
     * @return AxonautStockLog|WP_Error
     */
    public static function from_request($request)
    {
        $body = json_decode($request->get_body());
        if (!is_object($body) || !isset($body->data) || !is_object($body->data)) {
            return new WP_Error('bad_payload', __("Corps de requête invalide", "axonaut"), array('status' => 400));
        }
        if (!isset($body->topic) || $body->topic !== "stockLog.created") {
            return new WP_Error('bad_topic', __("Bad topic sent", "axonaut"), array('status' => 400));
        }
        if (!isset($body->data->current_stock) || $body->data->current_stock === "") {
            return new WP_Error('current_stock_missing', __("current_stock missing", "axonaut"), array('status' => 400));
        }

        $product = isset($body->data->product) && is_object($body->data->product) ? $body->data->product : new stdClass();

        $log = new self();
        $log->id = isset($body->id) ? $body->id : null;
        $log->timestamp = isset($body->timestamp) ? $body->timestamp : null;
        $log->date = isset($body->date) ? $body->date : null;
        $log->product_id = isset($body->data->product_id) ? $body->data->product_id : null;
        $log->current_stock = $body->data->current_stock;
        $log->internal_id = isset($product->internal_id) ? (string) $product->internal_id : "";
        $log->product_code = isset($product->product_code) ? (string) $product->product_code : "";

        if ($log->internal_id === "") {
            return new WP_Error('internal_id_missing', __("internal_id missing", "axonaut"), array('status' => 400));
        }

        return $log;
    }
}
