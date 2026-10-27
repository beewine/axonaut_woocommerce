<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */
class Axonaut
{
    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      Axonaut_Loader $loader Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string $plugin_name The string used to uniquely identify this plugin.
     */
    protected $plugin_name;

    /**
     * The current version of the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string $version The current version of the plugin.
     */
    protected $version;

    /**
     * Define the core functionality of the plugin.
     *
     * Set the plugin name and the plugin version that can be used throughout the plugin.
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function __construct()
    {
        if (defined('PLUGIN_NAME_VERSION')) {
            $this->version = PLUGIN_NAME_VERSION;
        } else {
            if(!empty(file_get_contents(plugin_dir_path(dirname(__FILE__)) . "/version.txt"))){
                $this->version = file_get_contents(plugin_dir_path(dirname(__FILE__)) . "/version.txt");
            }
            else {
                $this->version = '1.0.0';
            }
        }
        $this->plugin_name = 'Axonaut';
        $this->load_dependencies();
        $this->set_locale();
        $this->define_public_hooks();
        $this->define_admin_hooks();
        $this->define_api_endpoints();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - Axonaut_Loader. Orchestrates the hooks of the plugin.
     * - Axonaut_i18n. Defines internationalization functionality.
     * - Axonaut_Admin. Defines all hooks for the admin area.
     * - Axonaut_Public. Defines all hooks for the public side of the site.
     *
     * Create an instance of the loader which will be used to register the hooks
     * with WordPress.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies()
    {
        /**
         * The class responsible for orchestrating the actions and filters of the
         * core plugin.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-axonaut-loader.php';

        /**
         * The class responsible for defining internationalization functionality
         * of the plugin.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-axonaut-i18n.php';

        /**
         * The class responsible for defining all actions that occur in the admin area.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-axonaut-admin.php';

        /**
         * The class responsible for defining all actions that occur in the public-facing
         * side of the site.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-axonaut-public.php';

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-axonaut-product-sync.php';

        $this->loader = new Axonaut_Loader();

    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Uses the Axonaut_i18n class in order to set the domain and to register the hook
     * with WordPress.
     *
     * @since    1.0.0
     * @access   private
     */
    private function set_locale()
    {
        $plugin_i18n = new Axonaut_i18n();

        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks()
    {
        $plugin_admin = new Axonaut_Admin($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('admin_menu', $plugin_admin, 'axonaut_add_admin_menu');
        $this->loader->add_action('admin_init', $plugin_admin, 'axonaut_settings_init');
        $this->loader->add_action('wp_enqueue_styles', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('wp_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        $this->loader->add_action('wp_ajax_axonaut_sync', $plugin_admin, 'ajax_sync');
        $this->loader->add_action('admin_post_axonaut_download_log', $plugin_admin, 'download_log');
//        $this->loader->add_action('woocommerce_product_options_sku', $plugin_admin, 'axonaut_add_custom_product_id');
//        $this->loader->add_action('woocommerce_process_product_meta', $plugin_admin, 'axonaut_save_custom_product_id');
    }

    /**
     * Register all of the endpoints answering to axonaut webhooks
     * of the plugin.
     *
     * @since    3.0.0
     * @access   private
     */
    private function define_api_endpoints()
    {
        $plugin_admin = new Axonaut_Public($this->get_plugin_name(), $this->get_version());

        $this->loader->add_endpoint('rest_api_init', $plugin_admin, 'axonaut_register_webhooks_endpoints');
        $this->loader->add_filter('woocommerce_rest_is_request_to_rest_api', 'Axonaut_Webhooks', 'allow_woocommerce_key_auth');
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_public_hooks()
    {
        $plugin_public = new Axonaut_Public($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('wp_enqueue_styles', $plugin_public, 'enqueue_styles');
        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');
        $this->loader->add_action('woocommerce_order_status_completed', $plugin_public, 'hook_sell_pay');
        $this->loader->add_action('woocommerce_order_status_processing', $plugin_public, 'hook_sell');
        $this->loader->add_action('woocommerce_order_status_cancelled', $plugin_public, 'hook_cancel_order');
        $this->loader->add_action('user_register', $plugin_public, 'hook_register');
        $this->loader->add_action(Axonaut_Queue::HOOK_PRODUCT, 'Axonaut_Product_Sync', 'run');
        $this->loader->add_action(Axonaut_Queue::HOOK_STOCK, 'Axonaut_Stock_Sync', 'run');
        // Temps réel : sauvegarde d'un produit ou d'une variation, changement de stock.
        $this->loader->add_action('woocommerce_new_product', 'Axonaut_Product_Sync', 'on_product_saved');
        $this->loader->add_action('woocommerce_update_product', 'Axonaut_Product_Sync', 'on_product_saved');
        $this->loader->add_action('woocommerce_new_product_variation', 'Axonaut_Product_Sync', 'on_product_saved');
        $this->loader->add_action('woocommerce_update_product_variation', 'Axonaut_Product_Sync', 'on_product_saved');
        $this->loader->add_action('woocommerce_product_set_stock', 'Axonaut_Stock_Sync', 'on_stock_changed');
        $this->loader->add_action('woocommerce_variation_set_stock', 'Axonaut_Stock_Sync', 'on_stock_changed');
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run()
    {
        $this->loader->run();
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @since     1.0.0
     * @return    string    The name of the plugin.
     */
    public function get_plugin_name()
    {
        return $this->plugin_name;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     * @since     1.0.0
     * @return    Axonaut_Loader    Orchestrates the hooks of the plugin.
     */
    public function get_loader()
    {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @since     1.0.0
     * @return    string    The version number of the plugin.
     */
    public function get_version()
    {
        return $this->version;
    }
}
