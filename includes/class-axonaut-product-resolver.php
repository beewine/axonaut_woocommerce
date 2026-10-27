<?php

/**
 * Fait le lien entre un produit WooCommerce et un produit Axonaut.
 *
 * Deux modes (réglage « Rapprochement des produits ») :
 *  - sku_then_id : référence (UGS WooCommerce = référence Axonaut) d'abord, puis ID WooCommerce.
 *  - id_only     : uniquement l'ID WooCommerce (comportement historique du module).
 *
 * Quand un produit Axonaut est retrouvé par sa référence mais n'a pas encore d'identifiant
 * interne, l'ID WooCommerce y est inscrit : les synchronisations suivantes (stock, factures)
 * se font alors directement par identifiant.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-api.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-product-mapper.php";

class Axonaut_Product_Resolver
{
    const MAX_PAGES = 5;

    private static $cache = array();

    public static function match_mode()
    {
        return get_option('axonaut_product_match', 'sku_then_id') === 'id_only' ? 'id_only' : 'sku_then_id';
    }

    public static function reset_cache()
    {
        self::$cache = array();
    }

    /**
     * Produit Axonaut correspondant au produit WooCommerce, ou null.
     *
     * @param WC_Product $product
     * @param bool       $link    inscrire l'ID WooCommerce dans le produit Axonaut retrouvé par sa référence
     * @return array|null
     */
    public static function find_remote($product, $link = true)
    {
        $wcId = $product->get_id();
        if (array_key_exists($wcId, self::$cache)) {
            return self::$cache[$wcId];
        }

        $remote = null;
        $sku = Axonaut_Product_Mapper::sku($product);

        if (self::match_mode() === 'sku_then_id' && $sku !== '') {
            $remote = self::find_by_sku($sku, $wcId);
        }
        if ($remote === null) {
            $remote = self::find_by_internal_id($wcId);
        }

        if ($remote !== null && $link && (!isset($remote['internal_id']) || (string) $remote['internal_id'] === '')) {
            $patch = Axonaut_Api::patch('/api/v2/products/' . $remote['id'], array('internal_id' => (string) $wcId));
            if ($patch['ok']) {
                $remote['internal_id'] = (string) $wcId;
                axonaut_write_log("[ Product : $wcId ] Linked to Axonaut product " . $remote['id'] . " by reference '$sku'.");
            }
        }

        return self::$cache[$wcId] = $remote;
    }

    public static function find_by_internal_id($wcId)
    {
        $result = Axonaut_Api::get('/api/v2/products', array('internal_id' => (string) $wcId), array('page' => 1));
        if (!$result['ok'] || empty($result['data'])) {
            return null;
        }
        return $result['data'][0];
    }

    /**
     * Recherche par référence : l'API filtre sur « contient », on garde la correspondance exacte.
     * Parmi les correspondances, on préfère celle déjà liée à ce produit, puis celle sans identifiant
     * interne. Une référence déjà liée à un autre produit WooCommerce n'est jamais reprise.
     */
    public static function find_by_sku($sku, $wcId)
    {
        $unlinked = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = Axonaut_Api::get('/api/v2/products', array('product_code' => $sku), array('page' => $page));
            if (!$result['ok'] || empty($result['data'])) {
                break;
            }

            foreach ($result['data'] as $candidate) {
                if (!self::same_code(isset($candidate['product_code']) ? $candidate['product_code'] : '', $sku)) {
                    continue;
                }
                if (!empty($candidate['disabled'])) {
                    continue;
                }
                $internal = isset($candidate['internal_id']) ? (string) $candidate['internal_id'] : '';
                if ($internal === (string) $wcId) {
                    return $candidate;
                }
                if ($internal === '' && $unlinked === null) {
                    $unlinked = $candidate;
                }
            }
        }

        return $unlinked;
    }

    public static function same_code($a, $b)
    {
        return strcasecmp(trim((string) $a), trim((string) $b)) === 0;
    }

    /**
     * Produit WooCommerce correspondant à un message d'Axonaut (webhook de stock).
     *
     * @param string $internalId
     * @param string $productCode
     * @return WC_Product|null
     */
    public static function find_wc_product($internalId, $productCode = '')
    {
        $internalId = trim((string) $internalId);
        if ($internalId !== '' && ctype_digit($internalId)) {
            $product = wc_get_product((int) $internalId);
            if ($product && $product->get_id()) {
                return $product;
            }
        }

        $productCode = trim((string) $productCode);
        if ($productCode !== '' && self::match_mode() === 'sku_then_id') {
            $id = wc_get_product_id_by_sku($productCode);
            if ($id) {
                return wc_get_product($id);
            }
        }

        return null;
    }
}
