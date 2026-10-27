<?php

/**
 * The Product Manager class
 *
 * This is used to manage products
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-api.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-product.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";


class ProductManager
{
    private $wordpress_products = array();

    /**
     * ProductManager constructor.
     */
    public function __construct()
    {
	    $this->get_wordpress_products();
    }

	public function get_wordpress_products()
	{
        $query = new WC_Product_Query(array(
            'limit' => PHP_INT_MAX,
        ));
        $this->wordpress_products = $query->get_products();
	}

    /**
     * Synchronize all products between Woocommerce and Axonaut
     */
    public function synchronize()
    {
        axonaut_write_log("############### Synchronizing products ###############");

	    $catalog = array();

        foreach ($this->wordpress_products as $product) {
            if ($product->is_type("variable")) {
                $myVariableProduct = new WC_Product_Variable($product->get_id());
                $myProductVariations = $myVariableProduct->get_available_variations();
                foreach ($myProductVariations as $variation) {
                    $myProductVariation = wc_get_product($variation['variation_id']);
                    $variationValues = "";
                    foreach ($myProductVariation->get_attributes() as $key => $value) {
                        $myVariation     = $myProductVariation->get_attribute($key);
                        $variationValues .= " " . $myVariation;
                    }
                    $myName = $product->get_name() . $variationValues;

                    $tax_rates = WC_Tax::get_base_tax_rates($myProductVariation->get_tax_class());
                    if (!empty($tax_rates)) {
                        $tax_rate = reset($tax_rates);
                    }

                    $description = $product->get_short_description();
                    if (!empty($description)) {
                        $description = str_replace(";", " ", strip_tags(htmlspecialchars_decode($description), '<p><strong><del><em>'));
                    } else {
                        $description = str_replace(";", " ", strip_tags(htmlspecialchars_decode($product->get_title()), '<p><strong><del><em>'));
                    }

                    $weight = ((float) $product->get_weight()) * 1000;
                    if (get_option('woocommerce_weight_unit') == "g") {
                        $weight = $product->get_weight();
                    }

                    $productCode = $variation['sku'];
                    $internalId  = $variation['variation_id'];
                    $name        = $myName;
                    $taxRate     = !empty($tax_rate["rate"]) ? $tax_rate["rate"] : 0;
                    $stock       = $myProductVariation->get_stock_quantity();

                    // [MODIF] Calcul du prix HT fiable, indépendamment du réglage "prices_include_tax"
                    if (get_option('woocommerce_prices_include_tax') === 'yes') {
                        $price_incl = wc_get_price_including_tax($myProductVariation);
                        $tax_rates_calc = WC_Tax::get_rates($myProductVariation->get_tax_class());
                        $taxes  = WC_Tax::calc_tax($price_incl, $tax_rates_calc, true);
                        $price  = $price_incl - array_sum($taxes);
                    } else {
                        $price  = wc_get_price_excluding_tax($myProductVariation);
                    }

                    $catalog[] = array(
                        "name"         => $name,
                        "id"           => "",
                        "internal_id"  => $internalId,
                        "description"  => $description,
                        "price"        => $price,
                        "product_code" => $productCode,
                        "tax_rate"     => $taxRate,
                        "stock"        => $stock,
                        "weight"       => $weight,
                    );
                }
            } else {
                $myName = $product->get_title();

                $tax_rates = WC_Tax::get_base_tax_rates($product->get_tax_class());
                if (!empty($tax_rates)) {
                    $tax_rate = reset($tax_rates);
                }

                $description = $product->get_short_description();
                if (!empty($description)) {
                    $description = str_replace(";", " ", strip_tags(htmlspecialchars_decode($description), '<p><strong><del><em>'));
                } else {
                    $description = str_replace(";", " ", strip_tags(htmlspecialchars_decode($product->get_title()), '<p><strong><del><em>'));
                }

                $weight = ((float) $product->get_weight()) * 1000;
                if (get_option('woocommerce_weight_unit') == "g") {
                    $weight = $product->get_weight();
                }

                $productCode = $product->get_sku();
                $internalId  = $product->get_id();
                $name        = $myName;
                $taxRate     = !empty($tax_rate["rate"]) ? $tax_rate["rate"] : 0;
                $stock       = $product->get_stock_quantity();

                // [MODIF] Même logique que pour les variations
                if (get_option('woocommerce_prices_include_tax') === 'yes') {
                    $price_incl = wc_get_price_including_tax($product);
                    $tax_rates_calc = WC_Tax::get_rates($product->get_tax_class());
                    $taxes  = WC_Tax::calc_tax($price_incl, $tax_rates_calc, true);
                    $price  = $price_incl - array_sum($taxes);
                } else {
                    $price  = wc_get_price_excluding_tax($product);
                }

                $catalog[] = array(
                    "name"         => $name,
                    "id"           => "",
                    "internal_id"  => $internalId,
                    "description"  => $description,
                    "price"        => $price,
                    "product_code" => $productCode,
                    "tax_rate"     => $taxRate,
                    "stock"        => $stock,
                    "weight"       => $weight,
                );
            }
        }

        axonaut_write_log("Sending total of " . sizeof($catalog) ." products to Axonaut.");

        $catalog = array_chunk($catalog, 1000);

        foreach ($catalog as $catalogPart) {
            axonaut_write_log("Sending " . sizeof($catalogPart) ." products to Axonaut.");
            Axonaut_Api::post('/api/v2/private/products-catalog', array("catalog" => $catalogPart), array('timeout' => 60));
        }
    }

    /**
     * Show number of products synchronized
     */
    public function dump()
    {
        axonaut_write_log("===== Products synchronization has ended =====");
        echo "Products synchronization request has been sent.";
    }
}