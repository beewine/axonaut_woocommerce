<?php

/**
 * Axonaut Product
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/log.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";


class AxonautProduct
{
	private $wcProduct;

    private $internalId;
    private $productCode;
    private $name;
    private $description;
    private $price;
    private $productNatureName;
    private $productType;
    private $taxRate;
    private $weight;
    private $location;
    private $jobCosting;
    private $stock;
    private $priceWithTax;

//    private $baseUrl = "http://localhost/axonauts4/public/index.php";
    private $baseUrl = "https://axonaut.com";

	/**
	 * AxonautProduct constructor.
	 *
	 * @param $wcProduct
	 */
    public function __construct($wcProduct)
    {
    	$this->wcProduct = $wcProduct;

	    $tax_rates = WC_Tax::get_base_tax_rates($wcProduct->get_tax_class());
	    if(!empty($tax_rates)){
		    $tax_rate = reset($tax_rates);
	    }

	    $description = $wcProduct->get_short_description();
	    if (!empty($description)) {
		    $description = str_replace(";", " ", strip_tags(htmlspecialchars_decode($description), '<p><strong><del><em>'));
	    }
	    else {
		    $description = str_replace(";", " ", strip_tags(htmlspecialchars_decode($wcProduct->get_title()), '<p><strong><del><em>'));
	    }

	    $weight = $wcProduct->get_weight() * 1000;
	    if (get_option('woocommerce_weight_unit') == "g"){
		    $weight = $wcProduct->get_weight();
	    }

        $this->productCode = $wcProduct->get_sku();
        $this->internalId = $wcProduct->get_id();
        $this->name = $wcProduct->get_title();
        $this->description = $description;
        $this->price = wc_get_price_excluding_tax($wcProduct);
        $this->productNatureName = "";
        $this->productType = "";
        $this->taxRate = !empty($tax_rate["rate"]) ? $tax_rate["rate"] : 0;
        $this->weight = $weight;
        $this->location = "";
        $this->jobCosting = "";
        $this->stock = $wcProduct->get_stock_quantity();;
        $this->priceWithTax = wc_get_price_including_tax($wcProduct);
    }

	/**
	 * Get the Axonaut product
	 *
	 * @return bool|null
	 */
	public function get_axonaut_product()
	{
		$wcId = $this->wcProduct->get_id();

        // GET /api/v2/products
        $request = wp_remote_get($this->baseUrl . '/api/v2/products?internal_id=' . $wcId, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'userApiKey' => get_option( 'axonaut_api_key' ),
                'page' => 1,
                'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
            ),
            'sslverify' => false,
        ));

        if (is_wp_error($request)) {
            $error = $request->get_error_message();
            axonaut_write_log( "Something went wrong in axonaut-product::get_axonaut_product() (1) : $error", AXONAUT_LOG_ERROR );

            return null;
        } else {
            // No WordPress error
            $response = json_decode($request["body"], true);
            if (!empty($response["error"])) {
                // Axonaut error
                if ($response["error"]["status_code"] == "404") {
                    axonaut_write_log("[ Product : " . $wcId . " ] Product not found : " . json_encode($response["error"]), AXONAUT_LOG_ERROR);
                } else {
                    axonaut_write_log("[ Product : " . $wcId . " ] Error when trying to retrieve the product : " . json_encode($response["error"]), AXONAUT_LOG_ERROR);
                }
                return null;
            } else {
                $products = $response;

                return $products[0];
            }
        }
	}

    /**
     * Send request to synchronize one product.
     * @return bool
     */
    public function send()
    {
    	$axonautProduct = $this->get_axonaut_product($this->wcProduct);

        axonaut_write_log("[ Product : ".$this->internalId." ] Sending product from WooCommerce to Axonaut...");

	    $body = array(
		    "name" => $this->name,
		    "price" => $this->price,
		    "tax_rate" => $this->taxRate,
		    "description" => $this->description,
		    "internal_id" => $this->internalId,
		    "product_code" => $this->productCode,
		    "weight" => $this->weight,
		    "location" => $this->location,
		    "product_type" => $this->productType,
		    "stock" => $this->stock,
	    );

        if (!empty($axonautProduct)) {
			// PATCH /api/v2/products
	        $method = "PATCH";
            axonaut_write_log("[ Product : " . $this->wcProduct->get_id() . " ] This product already exists. $method product. ");
	        $request = wp_remote_request($this->baseUrl . '/api/v2/products/'.$axonautProduct["id"], array(
		        'headers' => array(
			        'Content-Type' => 'application/json',
			        'userApiKey' => get_option('axonaut_api_key'),
			        'page' => 1,
                    'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
		        ),
		        'method' => "PATCH",
		        'body' => json_encode($body),
                'sslverify' => false,
	        ));
        } else {
	        // POST /api/v2/products
	        $method = "POST";
            axonaut_write_log("[ Product : " . $this->wcProduct->get_id() . " ] This product does not exist. $method product.");
	        $request = wp_remote_post($this->baseUrl . '/api/v2/products', array(
		        'headers' => array(
			        'Content-Type' => 'application/json',
			        'userApiKey' => get_option('axonaut_api_key'),
                    'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
		        ),
		        'body' => json_encode($body),
                'sslverify' => false,
	        ));
        }

        if (is_wp_error($request)) {
            $error = $request->get_error_message();
            axonaut_write_log("Something went wrong in axonaut-product::send() : $error", AXONAUT_LOG_ERROR);
            return null;
        } else {
	        // No WordPress error
	        $response = json_decode($request["body"], true);
	        if (!empty($response["error"])) {
		        // Axonaut error
                axonaut_write_log("[ Product : " . $this->wcProduct->get_id() . " ] Error when trying to $method the product : " . json_encode($response["error"]), AXONAUT_LOG_ERROR);

		        return null;
	        }

            axonaut_write_log("[ Product : " . $this->wcProduct->get_id() . " ] $method product OK (Axonaut ID : " . $response["id"] . ")");
        }

        return true;
    }
}