<?php

/**
 * The Customer Manager class
 *
 * This is used to manage customers
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-customer.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";


class CustomerManager
{
    private $wordpress_customers;

//    private $baseUrl = "http://localhost/axonauts4/public/index.php";
    private $baseUrl = "https://axonaut.com";

    /**
     * CustomerManager constructor.
     */
    public function __construct()
    {
        $this->get_wordpress_users();
    }

    public function get_wordpress_users()
    {
	    $query = new WP_User_Query(array(
		    'number' => 1,
		    'order' => 'DESC',
	    ));
	    $last_user = $query->get_results();

	    $args = array(
//		    'blog_id' => '',
//		    'role' => '',
//		    'role__in' => array(),
//		    'role__not_in' => array(),
//		    'meta_key' => '',
//		    'meta_value' => '',
//		    'meta_compare' => '',
//		    'meta_query' => array(),
//		    'date_query' => array(),
//		    'include' => range(0, $last_user[0]->ID),
//		    'exclude' => array(),
//		    'orderby' => 'login',
//		    'order' => 'ASC',
//		    'offset' => '',
//		    'search' => '',
//		    'number' => '',
//		    'count_total' => false,
//		    'fields' => 'all',
//		    'who' => '',
	    );

	    $this->wordpress_customers = get_users($args);
    }

    /**
     * Synchronize customers to Axonaut
     */
    public function synchronize() {
        axonaut_write_log("############### Synchronizing customers ###############");

        $customers = array();

        foreach ($this->wordpress_customers as $customer) {
            $d = get_user_meta($customer->ID);
            if (isset($d["billing_email"]) && !empty(f($d["billing_email"][0]))) {
                $billingEmail = f($d["billing_email"][0]);
            } else {
                $billingEmail = $customer->data->user_email;
            }

            $internalId            = $customer->ID;

            if (isset($d['billing_company']) && !empty($d['billing_company'][0])) {
                $companyName = $d['billing_company'][0];
            } else if (
                (isset($d['billing_first_name']) && !empty($d['billing_first_name'][0]))
                || (isset($d['billing_last_name']) && !empty($d['billing_last_name'][0]))
            ) {
                $companyName = $d['billing_first_name'][0] . " " . $d['billing_last_name'][0];
            } else {
                $companyName = $d['nickname'][0];
            }

            $firstname = '';
            if (isset($d['billing_first_name']) && !empty($d['billing_first_name'][0])) {
                $firstname = $d['billing_first_name'][0];
            } else if (isset($d['first_name']) && !empty($d['first_name'][0])) {
                $firstname = $d['first_name'][0];
            }

            $lastname = '';
            if (isset($d['billing_last_name']) && !empty($d['billing_last_name'][0])) {
                $lastname = $d['billing_last_name'][0];
            } else if (isset($d['last_name']) && !empty($d['last_name'][0])) {
                $lastname = $d['last_name'][0];
            }

            $companyAddressContactName  = $firstname . " " . $lastname;
            $companyAddressStreet  = isset($d['billing_address_1']) && !empty(f($d['billing_address_1'][0])) ? f($d['billing_address_1'][0]) : "";
            $companyAddressZipCode = isset($d['billing_postcode']) && !empty(f($d['billing_postcode'][0])) ? f($d['billing_postcode'][0]) : "";
            $companyAddressCity    = isset($d['billing_city']) && !empty(f($d['billing_city'][0])) ? f($d['billing_city'][0]) : "";
            $companyAddressCountry = isset($d['billing_country']) && !empty(f($d['billing_country'][0])) ? f($d['billing_country'][0]) : "";
            $companyCategories     = array();
            $employeeFirstname     = isset($d['first_name']) && !empty(f($d['first_name'][0])) ? f($d['first_name'][0]) : "";
            $employeeLastname      = isset($d['last_name']) && !empty(f($d['last_name'][0])) ? f($d['last_name'][0]) : "";
            $employeeEmail         = $billingEmail;
            $employeePhoneNumber   = isset($d['billing_phone']) && !empty(f($d['billing_phone'][0])) ? f($d['billing_phone'][0]) : "";

            $customers[] = array(
                // Company data
                "company_name" => $companyName,
                "internal_id" => $internalId,
                "address_contact_name" => $companyAddressContactName,
                "address_street" => $companyAddressStreet,
                "address_zip_code" => $companyAddressZipCode,
                "address_city" => $companyAddressCity,
                "address_country" => $companyAddressCountry,
                "categories" => $companyCategories,
                // Employee data
                "employee_email" => $employeeEmail,
                "employee_firstname" => $employeeFirstname,
                "employee_lastname" => $employeeLastname,
                "employee_cellphone" => $employeePhoneNumber,
                "employee_phone" => "",
            );
        }

        axonaut_write_log("Sending " . sizeof($customers) ." companies to Axonaut.");

        try {
            $request = wp_remote_post($this->baseUrl . '/api/v2/private/companies-and-employees', array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'userApiKey' => get_option('axonaut_api_key'),
                    'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
                ),
                'body' => json_encode(array("companies_and_employees" => $customers)),
                'sslverify' => false,
            ));
        } catch (Exception $e) {
            axonaut_write_log("[ Customers ] " . $e->getMessage());
        }
//
//        if (is_wp_error($request)) {
//            $error = $request->get_error_message();
//            write_log("Something went wrong in customer-manager::synchronize() (1) : $error");
//
//            return null;
//        } else {
//            // No WordPress error
//            $response = json_decode($request["body"], true);
//            if (!empty($response["error"])) {
//                // Axonaut error
//                write_log("[ Customers ] Error when trying to synchronize customers : " . json_encode($response["error"]));
//
//                return null;
//            }
//            write_log("[ Customers ] Number of companies successfully synchronized : " . $response["count_companies"]);
//            return $response["count_companies"];
//        }
    }

    /**
     * Show how many customers have been synchronized
     */
    public function dump()
    {
        axonaut_write_log("===== Customers synchronization has ended =====");
        echo "Customers synchronization request has been sent.";
    }

}