<?php

/**
 * The Customer class
 *
 * This is used to represent a customer
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-api.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";

class AxonautCustomer
{
	public $wcCustomer;

	public $internalId;
	public $companyName;
	public $companyAddressContactName;
	public $companyAddressStreet;
	public $companyAddressZipCode;
	public $companyAddressCity;
	public $companyAddressCountry;
	public $companyCategories;

	public $employeeFirstname;
	public $employeeLastname;
	public $employeeEmail;
	public $employeePhoneNumber;

	/**
	 * AxonautCustomer constructor
	 *
	 * @param $wcCustomer
	 */
	public function __construct($wcCustomer, $order = null, $isRegister = false)
    {
        // $wcCustomer est un WC_Customer (commande) ou un WP_User (inscription).
        $userId = method_exists($wcCustomer, 'get_id') ? (int) $wcCustomer->get_id() : (int) $wcCustomer->ID;
        $userEmail = method_exists($wcCustomer, 'get_email') ? $wcCustomer->get_email() : (isset($wcCustomer->data->user_email) ? $wcCustomer->data->user_email : '');

        if ($userId != 0) {
            $this->wcCustomer = $wcCustomer;
            $d = get_user_meta($userId);
            $meta = function ($key) use ($d) {
                return isset($d[$key][0]) ? $d[$key][0] : '';
            };
            $this->internalId = $userId;

            if ($isRegister) {
                $this->companyName = $meta('nickname');
                $this->employeeFirstname = $meta('first_name');
                $this->employeeLastname = $meta('last_name');
                $this->employeeEmail = $userEmail;
                return;
            }

            $billingEmail = $meta('billing_email') !== '' ? $meta('billing_email') : $userEmail;

            $isFirstnameAndLastname = false;
            if ($meta('billing_company') !== '') {
                $companyName = $meta('billing_company');
            } else if ($meta('billing_first_name') !== '' || $meta('billing_last_name') !== '') {
                $companyName = $meta('billing_first_name') . " " . $meta('billing_last_name');
                $isFirstnameAndLastname = true;
            } else {
                $companyName = $meta('nickname');
            }

            $this->companyName = $companyName;
            $this->companyAddressContactName = !$isFirstnameAndLastname ? $meta('first_name') . " " . $meta('last_name') : "";
            $this->companyAddressStreet = $meta('billing_address_1');
            $this->companyAddressZipCode = $meta('billing_postcode');
            $this->companyAddressCity = $meta('billing_city');
            $this->companyAddressCountry = $meta('billing_country');
            $this->companyCategories = array();
            $this->employeeFirstname = $meta('first_name');
            $this->employeeLastname = $meta('last_name');
            $this->employeeEmail = $billingEmail;
            $this->employeePhoneNumber = $meta('billing_phone');
        } else if (!$isRegister && $order != null) {
            // Utilisateur pas inscrit à la boutique, a fait une commande
            $this->internalId = $order->get_billing_email();
            $this->companyName = !empty($order->get_billing_company()) ? $order->get_billing_company() : $order->get_billing_first_name() . " " . $order->get_billing_last_name();
            $this->companyAddressContactName = !empty($order->get_billing_company()) ? $order->get_billing_first_name() . " " . $order->get_billing_last_name() : "";
            $this->companyAddressStreet = $order->get_billing_address_1() . " " . $order->get_billing_address_2();
            $this->companyAddressZipCode = $order->get_billing_postcode();
            $this->companyAddressCity = $order->get_billing_city();
            $this->companyAddressCountry = $order->get_billing_country();
            $this->companyCategories = array("Client");
            $this->employeeFirstname = $order->get_billing_first_name();
            $this->employeeLastname = $order->get_billing_last_name();
            $this->employeeEmail = $order->get_billing_email();
            $this->employeePhoneNumber = $order->get_billing_phone();
        }
    }

    /**
     * Send request to Axonaut API in order to synchronize the customer
     * @return bool
     */
    public function send()
    {
        axonaut_write_log("[ Customer : " . $this->internalId . " ] Sending company from WooCommerce to Axonaut...");

	    $body = array(
	        // Company data
		    "company_name" => $this->companyName,
            "internal_id" => $this->internalId,
		    "address_contact_name" => $this->companyAddressContactName,
		    "address_street" => $this->companyAddressStreet,
		    "address_zip_code" => $this->companyAddressZipCode,
		    "address_city" => $this->companyAddressCity,
		    "address_country" => $this->companyAddressCountry,
		    "categories" => $this->companyCategories,
            // Employee data
            "employee_email" => $this->employeeEmail,
            "employee_firstname" => $this->employeeFirstname,
            "employee_lastname" => $this->employeeLastname,
            "employee_cellphone" => $this->employeePhoneNumber,
            "employee_phone" => "",
	    );

        $result = Axonaut_Api::post('/api/v2/private/company-and-employee', $body);
        if (!$result['ok']) {
            return null;
        }

        axonaut_write_log("[ Customer : " . $this->internalId . " ] Synchronization of the company has been successful (" . $result['data']["company_id"] . ").");

        return true;
    }
}
