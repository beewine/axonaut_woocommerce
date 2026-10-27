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
        if (!$isRegister) {
            if ($wcCustomer->get_id() != 0) {
                $this->wcCustomer = $wcCustomer;
                $d = get_user_meta($wcCustomer->ID);
                if (!empty(f($d["billing_email"][0]))) {
                    $billingEmail = f($d["billing_email"][0]);
                } else {
                    $billingEmail = $wcCustomer->data->user_email;
                }

                $isFirstnameAndLastname = false;
                if (isset($d['billing_company'][0]) && !empty($d['billing_company'][0])) {
                    $companyName = $d['billing_company'][0];
                } else if (!empty($d['billing_first_name'][0]) || !empty($d['billing_last_name'][0])) {
                    $companyName = $d['billing_first_name'][0] . " " . $d['billing_last_name'][0];
                    $isFirstnameAndLastname = true;
                } else {
                    $companyName = $d['nicename'][0];
                }

                $this->internalId = $wcCustomer->ID;
                $this->companyName = $companyName;
                $this->companyAddressContactName = !$isFirstnameAndLastname ? f($d['first_name'][0]) . " " . f($d['last_name'][0]) : "";
                $this->companyAddressStreet = f($d['billing_address_1'][0]);
                $this->companyAddressZipCode = f($d['billing_postcode'][0]);
                $this->companyAddressCity = f($d['billing_city'][0]);
                $this->companyAddressCountry = f($d['billing_country'][0]);
                $this->companyCategories = array();
                $this->employeeFirstname = f($d['first_name'][0]);
                $this->employeeLastname = f($d['last_name'][0]);
                $this->employeeEmail = $billingEmail;
                $this->employeePhoneNumber = f($d['billing_phone'][0]);
            } else {
                // Utilisateur pas inscrit à la boutique, a fait une commande
                if ($order != null) {
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
        } else {
            if ($wcCustomer->ID != 0) {
                $this->wcCustomer = $wcCustomer;
                $d = get_user_meta($wcCustomer->ID);
                $this->internalId = $wcCustomer->ID;
                $this->companyName = $d["nickname"][0];
                $this->employeeFirstname = $d['first_name'][0];
                $this->employeeLastname = $d['last_name'][0];
                $this->employeeEmail = $wcCustomer->data->user_email;
            }
        }
    }

    /**
     * Send request to Axonaut API in order to synchronize the customer
     * @return bool
     */
    public function send()
    {
        axonaut_write_log("[ Customer : " . $this->wcCustomer->ID . " ] Sending company from WooCommerce to Axonaut...");

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

        axonaut_write_log("[ Customer : " . $this->wcCustomer->ID . " ] Synchronization of the company has been successful (" . $result['data']["company_id"] . ").");

        return true;
    }
}
