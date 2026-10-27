<?php

/**
 * Axonaut Product
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-api.php";
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
	 * @return array|null
	 */
	public function get_axonaut_product()
	{
		$wcId = $this->wcProduct->get_id();

		$result = Axonaut_Api::get('/api/v2/products', array('internal_id' => $wcId), array('page' => 1));
		if (!$result['ok'] || empty($result['data'])) {
			return null;
		}

		return $result['data'][0];
	}

    /**
     * Send request to synchronize one product.
     * @return bool|null
     */
    public function send()
    {
        $axonautProduct = $this->get_axonaut_product();

        axonaut_write_log("[ Product : ".$this->internalId." ] Sending product from WooCommerce to Axonaut...");

	    $body = array(
		    "name" => $this->name,
		    "price" => $this->price,
		    "tax_rate" => $this->taxRate,
		    "description" => $this->description,
		    "internal_id" => (string) $this->internalId,
		    "product_code" => $this->productCode,
		    "weight" => $this->weight,
		    "location" => $this->location,
		    "product_type" => $this->productType,
		    "stock" => $this->stock,
	    );

        if (!empty($axonautProduct)) {
            $result = Axonaut_Api::patch('/api/v2/products/' . $axonautProduct["id"], $body);
        } else {
            $result = Axonaut_Api::post('/api/v2/products', $body);
        }

        if (!$result['ok']) {
            return null;
        }

        axonaut_write_log("[ Product : " . $this->internalId . " ] Product synchronized (Axonaut ID : " . $result['data']["id"] . ")");

        return true;
    }
}
