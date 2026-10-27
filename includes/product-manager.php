<?php

/**
 * Synchronisation complète du catalogue (bouton de l'onglet « Synchronisation »).
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-product-sync.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";

class ProductManager
{
    private $planned = 0;

    /**
     * Planifie la synchronisation de tous les produits WooCommerce vers Axonaut.
     */
    public function synchronize()
    {
        axonaut_write_log("############### Synchronizing products ###############");
        $this->planned = Axonaut_Product_Sync::enqueue_all();
        axonaut_write_log("Scheduled " . $this->planned . " products for synchronization.");
    }

    public function dump()
    {
        axonaut_write_log("===== Products synchronization has been scheduled =====");
        echo sprintf(
            /* translators: %d: number of products */
            __("%d produit(s) planifié(s) pour la synchronisation. Elle se déroule en arrière-plan.", "axonaut"),
            $this->planned
        );
    }
}
