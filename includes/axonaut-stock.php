<?php

/**
 * The StockLog  class
 *
 * This is used to manage stockLog events
 *
 * @since      3.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

use WpOrg\Requests\Exception;

 //ck_f672b1fa40ce408f9e22227460efb1411f221e13

require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-product.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";

class AxonautStockLog
{
    public $id;
    public $timestamp;
    public $date;
    public $topic = "stockLog.created";

    /**
     * Product data
     * @var AxonautStockLogProduct
     */
    public $data;

    /**
     * AxonautStockLog constructor.
     */
    public function __construct($request)
    {
        $body = json_decode($request->get_body());
        $this->validate($body);
        $this->id = $body->id;
        $this->timestamp = $body->timestamp;
        $this->date = $body->date;
        $this->data = new AxonautStockLogProduct($body->data);
    }

    private function validate(stdClass $body)
    {
        if($body->topic != $this->topic) throw new Exception( 'bad_topic', __( "Bad topic sent", "axonaut" ) );
        if(!isset($body->data->product->internal_id) || ($body->data->product->internal_id === ""))  throw new Exception( 'internal_id_missing', __( "internal_id missing", "axonaut" ) );
        if(!isset($body->data->current_stock) || ($body->data->current_stock === ""))  throw new Exception( 'current_stock_missing', __( "current_stock missing", "axonaut" ) );
    }
}

class AxonautStockLogProduct
{
    public $product_id;
    public $current_stock;
    public $weighted_average_cost;
    public $raw_material = [];
    public $product;

    /**
     * AxonautStockLogProduct constructor.
     */
    public function __construct($product)
    {
        $this->product_id = $product->product_id;
        $this->current_stock = $product->current_stock;
        $this->weighted_average_cost = $product->weighted_average_cost;
        $this->raw_material = $product->raw_material;
        $this->product = new AxonautStockLogProductInfo($product->product);
    }
}


class AxonautStockLogProductInfo
{
    public $internal_id;
    public $product_code;

    /**
     * AxonautStockLogProductInfo constructor.
     */
    public function __construct($product)
    {
        $this->internal_id = $product->internal_id;
        $this->product_code = $product->product_code;
    }
}