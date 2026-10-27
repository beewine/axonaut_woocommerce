<?php

/**
 * Import périodique des produits Axonaut vers WooCommerce (titre, descriptif, prix, stock).
 *
 * Axonaut ne notifie que les mouvements de stock : les autres champs sont donc relus par
 * page (une tâche par page, pour ne jamais bloquer ni dépasser la limite de débit).
 * Chaque champ est décidé par comparaison à trois voies (Axonaut_Merge) selon son sens
 * de synchronisation. Les produits WooCommerce ne sont jamais créés ni supprimés ici.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-api.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-merge.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-queue.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-snapshot.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-sync-settings.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-product-mapper.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-product-resolver.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-stock-sync.php";

class Axonaut_Product_Import
{
    const HOOK_START = 'axonaut_pull_start';
    const HOOK_PAGE = 'axonaut_pull_page';
    const MAX_PAGES = 500;

    /** Champs relus depuis Axonaut. */
    public static function fields()
    {
        return array_values(array_filter(Axonaut_Sync_Settings::FIELDS, array('Axonaut_Sync_Settings', 'pulls')));
    }

    /**
     * Planifie (ou retire) l'import horaire selon les réglages.
     * Appelé depuis l'administration : les fonctions Action Scheduler y sont prêtes.
     */
    public static function ensure_schedule()
    {
        if (!function_exists('as_next_scheduled_action')) {
            return;
        }
        $wanted = !empty(self::fields()) && get_option('axonaut_api_key') !== '';
        $scheduled = as_next_scheduled_action(self::HOOK_START, array(), Axonaut_Queue::GROUP);

        if ($wanted && !$scheduled) {
            as_schedule_recurring_action(time() + 60, HOUR_IN_SECONDS, self::HOOK_START, array(), Axonaut_Queue::GROUP);
        } elseif (!$wanted && $scheduled) {
            as_unschedule_all_actions(self::HOOK_START, array(), Axonaut_Queue::GROUP);
        }
    }

    /** Lance un import complet (première page, les suivantes s'enchaînent). */
    public static function start()
    {
        if (empty(self::fields())) {
            return false;
        }
        return Axonaut_Queue::enqueue(self::HOOK_PAGE, array(1), 0);
    }

    /** Tâche planifiée : traite une page puis planifie la suivante. */
    public static function run_page($page)
    {
        $page = (int) $page;
        if (empty(self::fields()) || get_option('axonaut_api_key') === '' || $page > self::MAX_PAGES) {
            return;
        }

        $result = Axonaut_Api::get('/api/v2/products', array(), array('page' => $page, 'timeout' => 30));
        if (!$result['ok'] || empty($result['data'])) {
            return;
        }

        foreach ($result['data'] as $remote) {
            self::import_one($remote);
        }

        Axonaut_Queue::enqueue(self::HOOK_PAGE, array($page + 1), 5);
    }

    /**
     * Applique à WooCommerce les changements d'un produit Axonaut.
     *
     * @param array $remote produit Axonaut
     * @return string[] champs mis à jour dans WooCommerce
     */
    public static function import_one(array $remote)
    {
        $product = self::find_wc_product($remote);
        if (!$product || $product->is_type('variable')) {
            return array();
        }

        $remoteValues = self::remote_values($remote);
        $snapshot = Axonaut_Snapshot::get($product);
        $wcValues = self::wc_values($product);
        $winner = Axonaut_Sync_Settings::conflict_winner();

        $toWoo = array();
        $toAxonaut = false;

        foreach (self::fields() as $field) {
            if (!isset($remoteValues[$field]) || !isset($wcValues[$field])) {
                continue;
            }
            // Le nom d'une variation est généré par WooCommerce à partir de ses attributs.
            if ($field === 'name' && $product->is_type('variation')) {
                continue;
            }
            // Un prix promotionnel actif n'est pas comparable au prix HT d'Axonaut.
            if ($field === 'price' && $product->is_on_sale()) {
                continue;
            }

            $decision = Axonaut_Merge::decide(
                Axonaut_Sync_Settings::direction($field),
                $wcValues[$field],
                $remoteValues[$field],
                isset($snapshot[$field]) ? $snapshot[$field] : null,
                $winner
            );

            if ($decision === Axonaut_Merge::TO_WOOCOMMERCE) {
                $toWoo[$field] = $remote[$field] ?? null;
            } elseif ($decision === Axonaut_Merge::TO_AXONAUT) {
                $toAxonaut = true;
            } elseif (!isset($snapshot[$field])) {
                // Déjà identiques et jamais synchronisés : on mémorise l'état commun.
                Axonaut_Snapshot::update($product, array($field => $wcValues[$field]));
            }
        }

        if (!empty($toWoo)) {
            self::apply($product, $toWoo);
            $after = self::wc_values($product);
            $update = array();
            foreach (array_keys($toWoo) as $field) {
                $update[$field] = $field === 'price' ? $after['price'] : $remoteValues[$field];
            }
            Axonaut_Snapshot::update($product, $update);
            axonaut_write_log("[ Product : " . $product->get_id() . " ] Updated from Axonaut : " . implode(', ', array_keys($toWoo)) . ".");
        }

        if ($toAxonaut) {
            Axonaut_Queue::enqueue_product($product->get_id(), 10);
        }

        return array_keys($toWoo);
    }

    /** Produit WooCommerce lié au produit Axonaut (ID, sinon référence ; lie alors les deux). */
    public static function find_wc_product(array $remote)
    {
        $product = Axonaut_Product_Resolver::find_wc_product(
            isset($remote['internal_id']) ? $remote['internal_id'] : '',
            isset($remote['product_code']) ? $remote['product_code'] : ''
        );

        if ($product && (!isset($remote['internal_id']) || (string) $remote['internal_id'] === '') && isset($remote['id'])) {
            $link = Axonaut_Api::patch('/api/v2/products/' . $remote['id'], array('internal_id' => (string) $product->get_id()));
            if ($link['ok']) {
                axonaut_write_log("[ Product : " . $product->get_id() . " ] Linked to Axonaut product " . $remote['id'] . " by reference.");
            }
        }

        return $product;
    }

    public static function remote_values(array $remote)
    {
        $values = array();
        if (isset($remote['name'])) {
            $values['name'] = Axonaut_Snapshot::normalize('name', $remote['name']);
        }
        if (array_key_exists('description', $remote)) {
            $values['description'] = Axonaut_Snapshot::normalize('description', $remote['description']);
        }
        if (isset($remote['price'])) {
            $values['price'] = Axonaut_Snapshot::normalize('price', $remote['price']);
        }
        if (isset($remote['stock']) && $remote['stock'] !== '') {
            $values['stock'] = Axonaut_Snapshot::normalize('stock', $remote['stock']);
        }
        return $values;
    }

    public static function wc_values($product)
    {
        $values = Axonaut_Product_Mapper::values($product);
        if ($product->managing_stock() && $product->get_stock_quantity() !== null) {
            $values['stock'] = Axonaut_Snapshot::normalize('stock', $product->get_stock_quantity());
        } else {
            unset($values['stock']);
        }
        return $values;
    }

    /** Écrit dans WooCommerce les valeurs Axonaut retenues. */
    private static function apply($product, array $values)
    {
        Axonaut_Sync_Settings::as_inbound(function () use ($product, $values) {
            if (isset($values['name'])) {
                $product->set_name(sanitize_text_field($values['name']));
            }
            if (array_key_exists('description', $values)) {
                $text = wp_kses_post((string) $values['description']);
                if ($product->is_type('variation') || Axonaut_Product_Mapper::description_source() === 'long') {
                    $product->set_description($text);
                } else {
                    $product->set_short_description($text);
                }
            }
            if (isset($values['price'])) {
                $product->set_regular_price(wc_format_decimal(self::price_for_storage($product, (float) $values['price'])));
            }
            if (isset($values['stock'])) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity((float) $values['stock']);
                $product->set_stock_status((float) $values['stock'] > 0 ? 'instock' : 'outofstock');
            }
            $product->save();
        });
    }

    /** Prix HT d'Axonaut converti dans la convention de saisie de la boutique (HT ou TTC). */
    public static function price_for_storage($product, $priceExclTax)
    {
        if (get_option('woocommerce_prices_include_tax') === 'yes') {
            $taxes = WC_Tax::calc_exclusive_tax($priceExclTax, WC_Tax::get_rates($product->get_tax_class()));
            return $priceExclTax + array_sum($taxes);
        }
        return $priceExclTax;
    }
}
