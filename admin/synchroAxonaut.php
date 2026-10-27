<?php

/**
 * File execute by AJAX to process to synchronization when user press the button
 *
 * @package    Axonaut
 * @subpackage Axonaut/admin
 */

require('../../../../wp-load.php');
require_once plugin_dir_path(dirname(__FILE__)) . "includes/customer-manager.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/product-manager.php";


axonaut_write_log("Synchronization...");
if (get_option('axonaut_api_key') != "") {
    update_option("axonaut_last_sync_date", new \DateTime());
    if (get_option('axonaut_sync_existing_customers') == "on") {
        $customer_manager = new CustomerManager();
        $customer_manager->synchronize();
        $customer_manager->dump();
    }
    if (get_option('axonaut_sync_products') == "on") {
        $product_manager = new ProductManager();
        $product_manager->synchronize();
        if (get_option('axonaut_sync_existing_customers') == "on")
            echo " ";
        $product_manager->dump();
    }
} else
    echo 'Aucune clé API n\'a été trouvée. Veuillez vérifier que la clé a bien été renseignée dans l\'onglet "Configuration"';
?>