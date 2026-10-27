<?php

/**
 * The Sell  class
 *
 * This is used to manage sells
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-product.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/product-manager.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";

define("SHIPPING_UNIQUE_ID", "111999");
define("FEE_UNIQUE_ID", "211999");

class AxonautSell
{
    private $orderNumber;
    private $order;

//    private $baseUrl = "http://localhost/axonauts4/public/index.php";
    private $baseUrl = "https://axonaut.com";

    /**
     * AxonautSell constructor.
     * @var WC_Order $order
     */
    public function __construct($order)
    {
        axonaut_write_log("[ Invoices management ] Initialized.");
        $this->order = $order;
        $this->orderNumber = $order->get_id();
    }

    /**
     * Get products informations
     * @return array
     */
    public function get_products_informations()
    {
        axonaut_write_log("[ Order : ".$this->orderNumber." ] Getting products data.");

        // Initialize products array
	    $products = array();

        foreach ($this->order->get_items() as $item) {
            $itemData = $item->get_data();
        	$oneProduct = array();

        	$product = $item->get_product();

            if (is_bool($product)) {
                axonaut_write_log("[ Order : ".$this->orderNumber." ] Could not get product from order item, skipping order item:", AXONAUT_LOG_WARNING);
                axonaut_write_log("[ Order : ".$this->orderNumber." ]\t\t".json_encode($itemData));

                break;
            }

        	// ======== Tax calculation ========

            $taxRate = 0;
	        if($item->get_tax_status() != "taxable"){
		        $taxRate = 0;
	        }
	        else {
	            axonaut_write_log(json_encode($itemData));
                if ($itemData["total"] != 0) {
                    foreach (array_values($itemData["taxes"]["total"]) as $taxAmount) {
                        if (!empty($taxAmount)) {
                            $taxRate = round(($taxAmount / $itemData["total"]) * 100, 1);
                        }
                    }
                }
	        }

	        // ========== Description ==========

            $description = "";
            $name = $product->get_title();
            if ($product->is_type("variation")) {
                $productVariation = new WC_Product_Variation($product->get_id());
                if (!is_null($productVariation)) {
                    $description = $productVariation->get_description();
                    $variationValues = "";
                    foreach ($productVariation->get_attributes() as $key => $value) {
                        $myVariation = $productVariation->get_attribute($key);
                        $variationValues .= " " . $myVariation;
                    }
                    $name .= $variationValues;
                }
            }
            if (empty($description)) {
                $description = $product->get_short_description();
                if (!empty($description)) {
                    $description = str_replace(";", " ", strip_tags(htmlspecialchars_decode($description), '<p><strong><del><em>'));
                }

                // tckt374237
//                else {
//                    $description = str_replace(";", " ", strip_tags(htmlspecialchars_decode($product->get_title()), '<p><strong><del><em>'));
//                }
            }

	        $oneProduct["name"] = $name;
	        $oneProduct["internal_id"] = $product->get_id();
	        $oneProduct["price"] = $item->get_total() / $item->get_quantity(); // total price/quantity to get unit price
	        $oneProduct["tax_rate"] = $taxRate;
	        $oneProduct["quantity"] = $item->get_quantity();
	        $oneProduct["description"] = $description;
	        $oneProduct["chapter"] = "Commande internet n°" . $this->orderNumber;

            axonaut_write_log("[ Order : ".$this->orderNumber." ] Adding product of id " . $product->get_id() . " (".$product->get_title().") to the invoice.");

            array_push($products, $oneProduct);
        }

        axonaut_write_log(json_encode($this->order));

        // Add shipping
        if ($this->order->get_shipping_total() > 0) {
        	$shippingProduct = array();

	        // Shipping tax
	        $shippingTax = $this->order->get_shipping_total() == 0 ? 0 : round((($this->order->get_shipping_tax() / $this->order->get_shipping_total()) * 100), 2, PHP_ROUND_HALF_UP);

            // TODO : find a way to get exact tax rate.
            // Problem : WooCommerce does not give tax rate but only shipping_total & shipping_tax.
            // Example :
            // 0.88/4.02 = 21.89 = 22% => need to round
            // 0.22/4.02 = 5.47 = 5.5% => need to round with 1 decimal
            $shippingTax = $shippingTax >= 10 ? round($shippingTax) : round($shippingTax, 1);

            axonaut_write_log($this->order->get_shipping_total() . " // " . $this->order->get_shipping_tax());

            $shippingProduct["name"] = "Frais de port";
        	$shippingProduct["internal_id"] = SHIPPING_UNIQUE_ID;
        	$shippingProduct["price"] = $this->order->get_shipping_total();
        	$shippingProduct["quantity"] = 1;
        	$shippingProduct["description"] = "";
        	$shippingProduct["tax_rate"] = $shippingTax;
            $shippingProduct["chapter"] = "Commande internet n°" . $this->orderNumber;

        	array_push($products, $shippingProduct);
        }

        foreach ($this->order->get_fees() as $feeId => $fee) {
//            if ($fee->get_amount() > 0) {
                $fee = new WC_Order_Item_Fee($feeId);

                $feeProduct["name"] = "Frais";
                $feeProduct["internal_id"] = FEE_UNIQUE_ID;
                $feeProduct["price"] = $fee->get_total();
                $feeProduct["quantity"] = 1;
                $feeProduct["description"] = "";
                $feeProduct["tax_rate"] = round((($fee->get_total_tax() / $fee->get_total()) * 100), 1, PHP_ROUND_HALF_UP);
                $feeProduct["chapter"] = "Commande internet n°" . $this->orderNumber;

                array_push($products, $feeProduct);
//            }
        }

        return $products;
    }

	/**
	 * Create body of the request to synchronized sells with Axonaut
	 * @return bool: success of the task
	 * @throws Exception
	 */
    public function send()
    {
        // Update or Create Customer
	    $wcCustomer = new WC_Customer($this->order->get_customer_id());
        $axonautCustomer = new AxonautCustomer($wcCustomer, $this->order);
	    $axonautCustomer->send();

        axonaut_write_log("[ Order : ".$this->orderNumber." ] Creating the invoice from WooCommerce to Axonaut...");

        $body = array(
            "employee_email" => $this->order->get_billing_email(),
            "products" => $this->get_products_informations(),
            "order_number" => $this->orderNumber,
            "allow_gocardless_payment" => false,
        );

        if (!empty($this->order->get_shipping_address_1())) {
            $contactName = $this->order->get_shipping_first_name() . " " . $this->order->get_shipping_last_name();
            if (!empty($this->order->get_billing_email())) {
                $contactName .= " - " . $this->order->get_billing_email();
            }
            if (!empty($this->order->get_shipping_phone())) {
                $contactName .= " - " . $this->order->get_shipping_phone();
            }
            $body["delivery_address"] = array(
                "company_name" => $this->order->get_shipping_company(),
                "contact_name" => $contactName,
                "street" => $this->order->get_shipping_address_1(),
                "zip_code" => $this->order->get_shipping_postcode(),
                "city" => $this->order->get_shipping_city(),
                "country" => $this->order->get_shipping_country(),
            );
        }

        axonaut_write_log(json_encode($body));

        $request = wp_remote_post($this->baseUrl . '/api/v2/invoices', array(
        	'headers' => array(
		        'userApiKey' => get_option('axonaut_api_key'),
		        'Content-Type' => 'application/json',
                'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
	        ),
	        'body' => json_encode($body),
            'sslverify' => false,
        ));

        if (is_wp_error($request)) {
            $error = $request->get_error_message();
            axonaut_write_log("Something went wrong in axonaut-sell::send() (1) : $error", AXONAUT_LOG_ERROR);
            return false;
        } else {
	        // No WordPress error
	        $response = json_decode($request["body"], true);
	        if (!empty($response["error"])) {
		        // Axonaut error
                axonaut_write_log("[ Order : " . $this->orderNumber . " ] Error when trying to post the invoice : " . json_encode($response["error"]), AXONAUT_LOG_ERROR);
		        return false;
	        }

            axonaut_write_log( "[ Order : $this->orderNumber ] Invoice created (" . $response["id"] . ")." );

	        return $response["id"];
        }
    }

    /**
     * Create body of the request to mark an order as pay sells with Axonaut
     * @return bool: success of the task
     * @throws Exception
     */
    public function send_pay()
    {
        axonaut_write_log("[ Order : ".$this->orderNumber." ] Sending payment to Axonaut...");

        $request = wp_remote_get($this->baseUrl . '/api/v2/invoices?internal_ref='.$this->orderNumber, array(
        	'headers' => array(
        		'userApiKey' => get_option('axonaut_api_key'),
		        'Content-Type' => 'application/json',
		        'page' => 1,
                'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
	        ),
            'sslverify' => false,
        ));

        if (is_wp_error($request)) {
            $error = $request->get_error_message();
            axonaut_write_log("Something went wrong in axonaut-sell::send_pay() (1) : $error");
            return false;
        } else {
	        // No WordPress error
	        $response = json_decode($request["body"], true);
            if (!empty($response["error"])) {
            	if ($response["error"]["status_code"] == "404") {
		            // Aucune facture avec ce numéro de commande n'a été trouvée. Créer la facture.
                    axonaut_write_log( "[ Order : " . $this->orderNumber . " ] Invoice does not exist in Axonaut - Create the invoice." );
		            $axonautInvoiceId = $this->send();
	            } else {
            		// Autre erreur. Log.
                    axonaut_write_log("[ Order : " . $this->orderNumber . " ] Error when trying to retrieve the invoice : " . json_encode($response["error"]));
		            return false;
	            }
            } else {
                $axonautInvoice = $response[0];
                $axonautInvoiceId = $axonautInvoice["id"];
                if(!is_null($axonautInvoice["paid_date"])) {
					// La facture a déjà été payée
					// Ne pas continuer
                    axonaut_write_log( "[ Order : " . $this->orderNumber . " ] This invoice has already been paid (" . $axonautInvoiceId . " - " .$axonautInvoice["paid_date"] . ")." );

					return false;
				}
            }
        }

        // ======================= Nouveau paiement =======================

        if (!is_null($axonautInvoiceId)) {
            // 1 = Prélèvement ; 2 = Virement ; 3 = Chèque ; 4 = CB ; 5 = Espèce
            $woo_payment = $this->order->get_payment_method();
            if (strpos($woo_payment, "bacs") !== false) {
                $paymentMethod = 2; // Transfère bancaire
            } else if (strpos($woo_payment, "cheque") !== false) {
                $paymentMethod = 3; // Chèque
            } else if (strpos($woo_payment, "cod") !== false) {
                $paymentMethod = 5; // Cash on delivery
            } else {
                $paymentMethod = 4; // Par défaut, CB
            }

            $body = array(
                "amount" => $this->order->get_total(),
                "nature" => $paymentMethod,
                "date_ts" => (new DateTime())->getTimestamp(),
                "invoice_id" => $axonautInvoiceId,
            );

            $request = wp_remote_post($this->baseUrl . '/api/v2/payments', array(
                'headers' => array(
                    'userApiKey' => get_option('axonaut_api_key'),
                    'Content-Type' => 'application/json',
                    'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
                ),
                'body' => json_encode($body),
                'sslverify' => false,
            ));

            if (is_wp_error($request)) {
                $error = $request->get_error_message();
                axonaut_write_log("Something went wrong in axonaut-sell::send_pay() (2) : $error", AXONAUT_LOG_ERROR);

                return false;
            } else {
                // No WordPress error
                $response = json_decode($request["body"], true);
                if (!empty($response["error"])) {
                    // Axonaut error
                    axonaut_write_log("[ Order : " . $this->orderNumber . " ] Error when trying to post a payment : " . json_encode($response["error"]));

                    return false;
                }
                axonaut_write_log("[ Order : " . $this->orderNumber . " ] Payment request has been successful.");

                return true;
            }
        }

        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Could not retrieve Axonaut invoice id.");
        return false;
    }

    /**
     * Cancel order
     *
     * @return bool: success of the task
     * @throws Exception
     */
    public function cancel_order()
    {
        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Cancelling order payments...");
        
        $request = wp_remote_request($this->baseUrl . '/api/v2/private/order-payments/'.$this->orderNumber, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'userApiKey' => get_option('axonaut_api_key'),
                'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
            ),
            'method' => "DELETE",
            'sslverify' => false,
        ));

        if (is_wp_error($request)) {
            $error = $request->get_error_message();
            axonaut_write_log("Something went wrong in axonaut-sell::cancel_order() (1) : $error", AXONAUT_LOG_ERROR);
            return false;
        } else {
            // No WordPress error
            $response = json_decode($request["body"], true);
            if (!empty($response["error"])) {
                // Axonaut error
                axonaut_write_log("[ Order : " . $this->orderNumber . " ] Error when trying to delete the payment : " . json_encode($response["error"]), AXONAUT_LOG_ERROR);
                return false;
            }

            axonaut_write_log("[ Order : $this->orderNumber ] Payments successfully deleted.");

            return true;
        }
    }
}