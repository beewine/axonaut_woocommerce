<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @since      1.0.0
 *
 * @package    Axonaut
 * @subpackage Axonaut/public
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-customer.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-order-sync.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-webhooks.php";

class Axonaut_Public
{
    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string $plugin_name The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string $version The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string $plugin_name The name of the plugin.
     * @param      string $version The version of this plugin.
     */
    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_styles()
    {
        /**
         * This function is provided for demonstration purposes only.
         *
         * An instance of this class should be passed to the run() function
         * defined in Axonaut_Loader as all of the hooks are defined
         * in that particular class.
         *
         * The Axonaut_Loader will then create the relationship
         * between the defined hooks and the functions defined in this
         * class.
         */

        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/axonaut-public.css', array(), $this->version, 'all');
    }

    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts()
    {
        /**
         * This function is provided for demonstration purposes only.
         *
         * An instance of this class should be passed to the run() function
         * defined in Axonaut_Loader as all of the hooks are defined
         * in that particular class.
         *
         * The Axonaut_Loader will then create the relationship
         * between the defined hooks and the functions defined in this
         * class.
         */

        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/axonaut-public.js', array('jquery'), $this->version, false);
    }

    // Les hooks de commande (statuts En cours, Terminée, Annulée, remboursement) sont traités
    // en arrière-plan par Axonaut_Order_Sync, avec verrou et nouvelles tentatives.

    /**
     * Hook when a visitor create an account on Woocommerce.
     * @param $userId : id of the new user.
     */
    public function hook_register($userId)
    {
        axonaut_write_log("Creating a new prospect in hook_register.");
        if (get_option('axonaut_sync_new_customers') != "on") {
            return;
        }

        $user = new WP_User($userId);
        $customer = new AxonautCustomer($user, null, true);
        $customer->send();
    }

    
    /**
     * Register webhooks
     */
    public function axonaut_register_webhooks_endpoints()
    {
        register_rest_route('axonaut/v3', 'stocklog-created', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [new Axonaut_Webhooks, 'axonaut_handle_webhook_stocklog_created'],
            'permission_callback' => [Axonaut_Webhooks::class, 'authorize'],
        ));
    }

//    public function hook_update_product($productId)
//    {
//        write_log("Updating a product in hook_update_product.");
//
//        if (strcmp(get_option('axonaut_sync_products'), "on") !== 0) {
//            return;
//        }
//
//        $product = new WC_Product($productId);
//        $axonautProduct = new AxonautProduct($product);
//        $axonautProduct->send();
//    }

}
