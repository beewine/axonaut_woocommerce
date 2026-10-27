<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Axonaut
 * @subpackage Axonaut/admin
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-display.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/log.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";

class Axonaut_Admin
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
     * @param      string $plugin_name The name of this plugin.
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
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/axonaut-admin.css', array(), $this->version, 'all');
    }

    /**
     * Register the script for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/axonaut-admin.js', array('jquery'), $this->version, 'all');
    }

    /**
     * Display settings component of the module
     */
    public function axonaut_options_page()
    {
        ?>
        <div class="wrap">
            <div id="icon-options-general" class="icon32"></div>
            <h1><?php __("Configuration du module Axonaut", "axonaut")?></h1>
            <?php
            if (isset($_GET['settings-updated'])) { // add settings saved message with the class of "updated"
                add_settings_error('wporg_messages', 'wporg_message', __('Paramètres sauvegardés', 'axonaut'), 'updated');
            }
            settings_errors('wporg_messages');
            $active_tab = "configuration";
            if (isset($_GET["tab"])) {
                $active_tab = sanitize_key($_GET["tab"]);
            }
            ?>
            <!-- wordpress provides the styling for tabs. -->
            <h2 class="nav-tab-wrapper">
                <!-- when tab buttons are clicked we jump back to the same page but with a new parameter that represents the clicked tab. accordingly we make it active -->
                <a href="?page=axonaut_settings&tab=configuration" class="nav-tab <?php if ($active_tab == 'configuration') {
                       echo 'nav-tab-active';
                   } ?> "><?php _e('Configuration', 'axonaut'); ?></a>
                <a href="?page=axonaut_settings&tab=synchro" class="nav-tab <?php if ($active_tab == 'synchro') {
                    echo 'nav-tab-active';
                } ?>"><?php _e('Synchronisation', 'axonaut'); ?></a>
                <a href="?page=axonaut_settings&tab=utils" class="nav-tab <?php if ($active_tab == 'utils') {
                    echo 'nav-tab-active';
                } ?>"><?php _e('Utilitaires', 'axonaut'); ?></a>
            </h2>

            <form method="post" action="<?php echo esc_url($active_tab == 'synchro' ? admin_url('admin-ajax.php') : 'options.php'); ?>" <?php if ($active_tab == "synchro") { ?>name="sync"<?php } ?>>
            <?php if ($active_tab == "synchro") { ?>
                <input type="hidden" name="action" value="axonaut_sync"/>
                <input type="hidden" name="_axonaut_nonce" value="<?php echo esc_attr(wp_create_nonce('axonaut_sync')); ?>"/>
            <?php } ?>
            <?php

            settings_fields($active_tab . "_section");
            do_settings_sections("axonaut_settings");
            if ($active_tab == "synchro") {
                if (get_option("axonaut_last_sync_date") == ""){
                    submit_button(__("Synchroniser l'ensemble de mes données", 'axonaut'), "primary", "axonaut_submit_synchro");
                }
                else if ((get_option("axonaut_last_sync_date")->diff(new DateTime())->format("%i") != "0")) {
                    submit_button(__("Synchroniser l'ensemble de mes données", 'axonaut'), "primary", "axonaut_submit_synchro");
                }
                else {
                    echo __("Veuillez attendre au moins 1 minute depuis la dernière synchronisation avant d'en faire une nouvelle.", 'axonaut');
                }
                echo '<img id="loading" src="' . plugin_dir_url(__FILE__) . '/resources/Gear.gif" alt="loading" style="display:none">';
            } else if ($active_tab == "configuration")
                submit_button(__("Enregistrer les changements", 'axonaut'), "primary", "axonaut_submit_config");
            ?>
            </form>
            <hr>
            <?php echo __("Version du module Axonaut : ", 'axonaut') . getVersionModule(); ?>
        </div>
        <?php

    }

    /**
     * Add the admin menu to Woocommerce
     */
    public function axonaut_add_admin_menu()
    {

        add_menu_page('Axonaut', 'Axonaut', 'manage_options', 'axonaut_settings', [$this, 'axonaut_options_page'],
            plugin_dir_url(__FILE__) . "/logo.png", 54);

    }


    /**
     * Initialized defaults settings.
     */
    public function axonaut_settings_init()
    {
        $this->enqueue_styles();
        $this->enqueue_scripts();

        $tab = isset($_GET["tab"]) ? sanitize_key($_GET["tab"]) : "configuration";

        if ($tab == "configuration") {
            // Enregistrement des configurations
            add_settings_section("configuration_section", __("Paramètres du module Axonaut", "axonaut"), [$this, "axonaut_display_header_options_content"], "axonaut_settings");
            add_settings_field("axonaut_api_key", __('Clé d\'API', "axonaut"), [$this, "axonaut_display_api_key"], "axonaut_settings", "configuration_section");
            add_settings_field("axonaut_sync_products", __('Synchronisation des produits', "axonaut"), [$this, "axonaut_display_sync_products"], "axonaut_settings", "configuration_section");
            add_settings_field("axonaut_product_match", __('Rapprochement des produits', "axonaut"), [$this, "axonaut_display_product_match"], "axonaut_settings", "configuration_section");
            add_settings_field("axonaut_description_source", __('Descriptif envoyé à Axonaut', "axonaut"), [$this, "axonaut_display_description_source"], "axonaut_settings", "configuration_section");
            add_settings_field("axonaut_sync_existing_customers", __('Synchronisation des clients existants', "axonaut"), [$this, "axonaut_display_sync_existing_customers"], "axonaut_settings", "configuration_section");
            add_settings_field("axonaut_sync_new_customers", __('Synchronisation des nouveaux clients', "axonaut"), [$this, "axonaut_display_sync_new_customers"], "axonaut_settings", "configuration_section");
            add_settings_field("axonaut_sync_sells", __('Synchronisation des ventes', "axonaut"), [$this, "axonaut_display_sync_sells"], "axonaut_settings", "configuration_section");
            add_settings_field("axonaut_sync_stocks", __('Synchronisation des stocks depuis Axonaut', "axonaut"), [$this, "axonaut_display_sync_stocks"], "axonaut_settings", "configuration_section");
            register_setting("configuration_section", "axonaut_api_key");
            register_setting("configuration_section", "axonaut_product_match", array('sanitize_callback' => function ($v) {
                return $v === 'id_only' ? 'id_only' : 'sku_then_id';
            }));
            register_setting("configuration_section", "axonaut_description_source", array('sanitize_callback' => function ($v) {
                return in_array($v, array('short', 'long', 'short_then_long'), true) ? $v : 'short';
            }));
            register_setting("configuration_section", "axonaut_sync_existing_customers");
            register_setting("configuration_section", "axonaut_sync_products");
            register_setting("configuration_section", "axonaut_sync_new_customers");
            register_setting("configuration_section", "axonaut_sync_sells");
            register_setting("configuration_section", "axonaut_sync_stocks");
        } else if ($tab == "synchro") {
            add_settings_section("axonaut_synchro_section", __("Synchronisation des données WooCommerce > Axonaut", "axonaut"), [$this, "axonaut_display_axo_synchro_content"], "axonaut_settings");
            add_settings_field("axonaut_last_sync_date", __("Dernière synchronisation", "axonaut"), [$this, "axonaut_display_last_sync_date"], "axonaut_settings", "axonaut_synchro_section");
            add_option("axonaut_last_sync_date", new DateTime('2000-01-01'));
            register_setting("axonaut_synchro_section", "axonaut_last_sync_date");
        } else if ($tab == 'utils') {
            add_settings_section("axonaut_infos", __("Informations utiles", "axonaut"), [$this, "axonaut_display_infos"], "axonaut_settings");
            add_settings_section("axonaut_logs", __("Télécharger le fichier log.txt", "axonaut"), [$this, "axonaut_display_download_logs"], "axonaut_settings");
            add_settings_section("axonaut_contact", __("Contacter Axonaut", "axonaut"), [$this, "axonaut_display_contact"], "axonaut_settings");
        }
    }

    /**
     * Display the API key
     */
    public function axonaut_display_api_key()
    {
        ?>
        <input style="width: 380px;" type="text" name="axonaut_api_key" id="axonaut_api_key"
               value="<?php echo esc_attr(get_option('axonaut_api_key')); ?>"/>
        <?php
    }

    /**
     * Mode de rapprochement entre produits WooCommerce et produits Axonaut
     */
    public function axonaut_display_product_match()
    {
        $value = get_option('axonaut_product_match', 'sku_then_id');
        echo "<select name='axonaut_product_match' id='axonaut_product_match'>";
        echo "<option value='sku_then_id'" . selected($value, 'sku_then_id', false) . ">" . esc_html__("Référence (UGS) d'abord, puis ID WooCommerce", "axonaut") . "</option>";
        echo "<option value='id_only'" . selected($value, 'id_only', false) . ">" . esc_html__("ID WooCommerce uniquement (historique)", "axonaut") . "</option>";
        echo "</select>";
        echo "<p class='description'>" . esc_html__("La référence WooCommerce (UGS) est comparée à la référence du produit Axonaut. Le produit Axonaut retrouvé reçoit l'ID WooCommerce, ce qui sert ensuite pour le stock et les factures.", "axonaut") . "</p>";
    }

    /**
     * Source du descriptif envoyé à Axonaut
     */
    public function axonaut_display_description_source()
    {
        $value = get_option('axonaut_description_source', 'short');
        echo "<select name='axonaut_description_source' id='axonaut_description_source'>";
        echo "<option value='short'" . selected($value, 'short', false) . ">" . esc_html__("Description courte", "axonaut") . "</option>";
        echo "<option value='long'" . selected($value, 'long', false) . ">" . esc_html__("Description longue", "axonaut") . "</option>";
        echo "<option value='short_then_long'" . selected($value, 'short_then_long', false) . ">" . esc_html__("Description courte, à défaut la longue", "axonaut") . "</option>";
        echo "</select>";
    }

    /**
     * Display stocks switch 
     */
    public function axonaut_display_sync_stocks()
    {
        echo AxonautDisplay::create_switch("axonaut_sync_stocks");
        echo "<input id='axonaut_sync_stocks_url' type='text' style='margin-left: 20px; margin-right: 20px; width: 380px;' readonly='readonly' value='".get_bloginfo('url')."/wp-json/axonaut/v3/stocklog-created'/>";
        echo "<div id='axonaut_sync_stocks_url_cpy_button' style='border: 1px solid #000; padding: 3px; display: inline-block;'><i class='dashicons dashicons-editor-paste-text'></i></div>";
        echo "<br/><br/>" . __("Secret du webhook (à ajouter dans Axonaut, en-tête <code>X-Axonaut-Secret</code>) :", "axonaut") . "<br/>";
        echo "<input type='text' style='width: 380px;' readonly='readonly' value='" . esc_attr(axonaut_get_webhook_secret()) . "'/>";
        echo __("<br/><br/><b>Important</b> : les stocks sont synchronisés depuis Axonaut, Axonaut est maître des informations de stock.<br/>", "axonaut");
        echo "<script>
        document.getElementById('axonaut_sync_stocks_url_cpy_button').addEventListener('click', () => {
                navigator.clipboard.writeText(document.getElementById('axonaut_sync_stocks_url').value).then(() => {
                window.alert('Lien copié');
            });
        });
        </script>";
    }

    /**
     * Display sells switch
     */
    public function axonaut_display_sync_sells()
    {
        echo AxonautDisplay::create_switch("axonaut_sync_sells");
    }

    /**
     * GUI switch, display existing customer switch
     */
    public function axonaut_display_sync_existing_customers()
    {
        echo AxonautDisplay::create_switch("axonaut_sync_existing_customers");
    }

    /**
     * GUI switch to allow new customers synchronize
     */
    public function axonaut_display_sync_new_customers()
    {
        echo AxonautDisplay::create_switch("axonaut_sync_new_customers");
    }

    /**
     * GUI switch to allow product synchronize
     */
    public function axonaut_display_sync_products()
    {
        echo AxonautDisplay::create_switch("axonaut_sync_products");
    }

    /**
     * GUI label for guide the user
     */
    public function axonaut_display_header_options_content()
    {
        echo __("<b>Configuration du module :</b>
        <br/>- La <b>clé API</b> se trouve sur <a href='https://axonaut.com/utilities/api' target='_blank'>votre section API sur Axonaut</a>. <b>Attention</b> : assurez vous que l'utilisateur correspondant à cette clé possède le rôle <b>\"Administrateur\"</b> sur Axonaut !
        <br/>- <b>Synchronisation des produits</b> : vos produits seront mis à jour dans Axonaut (nom, prix, taxe, description, quantité, ...).
        <br/>- <b>Synchronisation des nouveaux clients</b> : les utilisateurs qui s'inscrivent à votre boutique et/ou passent une commande seront créés dans Axonaut et identifiés grâce à leur adresse email.
        <br/>- <b>Synchronisation des ventes</b> : les commandes passées à \"En cours\" généreront des factures, et les commandes passées à \"Terminée\" généreront le paiement correspondant dans Axonaut.");
    }

    /**
     * GUI label for guide user
     */
    public function axonaut_display_axo_synchro_content()
    {
        echo __("La synchronisation suivante s'appliquera sur l'ensemble de vos <b>clients et produits <u>existants</u></b> dans WooCommerce avec Axonaut en un clic.
        <br/>Pour réaliser cette synchronisation, veuillez cliquer sur le bouton ci-dessous.
        <br/><br/>", "axonaut");
    }

    /**
     * GUI label for information
     */
    public function axonaut_display_sku_content()
    {
        echo "Créez des références rapidement en un clic";
    }

    /**
     * GUI settings for utils
     */
    public function axonaut_display_download_logs()
    {
        echo "
        <p>
            <a href='" . esc_url(wp_nonce_url(admin_url('admin-post.php?action=axonaut_download_log'), 'axonaut_download_log')) . "'>" . __("Cliquez ici pour télécharger le fichier log.txt", "axonaut") . "</a>
        </p>
        ";
    }

    public function axonaut_display_last_sync_date()
    {
        ?>
        <input style="width: 380px;" type="text" name="last_sync_date" id="last_sync_date"
               value="<?php
               if (get_option("axonaut_last_sync_date") == "") {
                   echo "*";
               } else {
                   echo (get_option("axonaut_last_sync_date"))->format("d/m/Y \à H:i:s");
               } ?>" disabled="disabled"/>
        <?php
    }

    public function axonaut_display_infos()
    {
        echo "
        <p>Version du module Axonaut : " . getVersionModule() . "
            <br/>
            <li>L'adresse de livraison est désormais différenciée de l'adresse de facturation lors de la génération de la facture dans Axonaut.</li>
            <br/><br/>" .
            __("Nous travaillons en permanence sur le module afin de l'améliorer. Veillez à bien mettre à jour votre module à chaque fois que l'option vous est proposée.", "axonaut") .
        "</p>";
    }

	public function axonaut_display_contact()
	{
		echo
        "<p>" . __("En cas de problème, n'hésitez pas à", "axonaut") . "<a href=\"http://www.axonaut.com\" target=\"_blank\">" . __("nous contacter directement sur Axonaut", "axonaut") . "</a>" .
        "</p>";
	}

//	public function axonaut_add_custom_product_id() {
//		$args = array(
//			'label' => __('Axonaut Product ID', 'woocommerce'),
//			'placeholder' => __('Enter Axonaut Product ID here', 'woocommerce'),
//			'id' => 'axonaut_product_id',
//			'desc_tip' => true,
//			'description' => __('This ID is for internal use only.', 'woocommerce'),
//		);
////		woocommerce_wp_text_input($args);
//		woocommerce_wp_hidden_input($args);
//	}

    /**
     * Synchronisation complète lancée depuis l'onglet « Synchronisation » (AJAX).
     */
    public function ajax_sync()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce(isset($_POST['_axonaut_nonce']) ? $_POST['_axonaut_nonce'] : '', 'axonaut_sync')) {
            wp_die(esc_html__("Accès refusé", "axonaut"), '', array('response' => 403));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . "includes/customer-manager.php";
        require_once plugin_dir_path(dirname(__FILE__)) . "includes/product-manager.php";

        axonaut_write_log("Synchronization...");
        if (get_option('axonaut_api_key') == "") {
            wp_die(esc_html__('Aucune clé API n\'a été trouvée. Veuillez vérifier que la clé a bien été renseignée dans l\'onglet "Configuration"', 'axonaut'));
        }

        update_option("axonaut_last_sync_date", new \DateTime());
        if (get_option('axonaut_sync_existing_customers') == "on") {
            $customer_manager = new CustomerManager();
            $customer_manager->synchronize();
            $customer_manager->dump();
        }
        if (get_option('axonaut_sync_products') == "on") {
            $product_manager = new ProductManager();
            $product_manager->synchronize();
            if (get_option('axonaut_sync_existing_customers') == "on") {
                echo " ";
            }
            $product_manager->dump();
        }
        wp_die();
    }

    /**
     * Téléchargement du journal (admin-post).
     */
    public function download_log()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__("Accès refusé", "axonaut"), '', array('response' => 403));
        }
        check_admin_referer('axonaut_download_log');

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="axonaut-log.txt"');
        header('Cache-Control: no-store');
        echo read_log();
        exit;
    }
}
