<?php

/**
 * Transforme un produit WooCommerce en données Axonaut (nom, descriptif, prix, TVA, référence).
 *
 * Utilisé à la fois par la synchronisation du catalogue et par les lignes de facture, pour
 * qu'un même produit porte toujours le même nom et le même descriptif des deux côtés.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

class Axonaut_Product_Mapper
{
    /** Source du descriptif : short, long ou short_then_long. */
    public static function description_source()
    {
        $source = get_option('axonaut_description_source', 'short');
        return in_array($source, array('short', 'long', 'short_then_long'), true) ? $source : 'short';
    }

    /**
     * Nom du produit tel qu'il apparaît dans Axonaut.
     * Pour une variation : nom généré par WooCommerce ("Produit - Rouge, XL").
     */
    public static function name($product)
    {
        return $product->get_name();
    }

    /**
     * Descriptif du produit, nettoyé (pas de point-virgule, HTML limité).
     * Pour une variation, sa propre description prime, puis celle du produit parent.
     */
    public static function description($product)
    {
        $candidates = array();
        $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : null;

        if ($product->is_type('variation')) {
            $candidates['variation'] = $product->get_description();
        }

        $holder = $parent ? $parent : $product;
        $short = $holder->get_short_description();
        $long = $holder->get_description();

        switch (self::description_source()) {
            case 'long':
                $candidates['main'] = $long;
                break;
            case 'short_then_long':
                $candidates['main'] = !empty(trim(wp_strip_all_tags($short))) ? $short : $long;
                break;
            default:
                $candidates['main'] = $short;
        }

        foreach ($candidates as $text) {
            $clean = self::clean_text($text);
            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }

    public static function clean_text($text)
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return '';
        }
        $text = str_replace(';', ' ', strip_tags(htmlspecialchars_decode($text), '<p><strong><del><em>'));
        return trim($text);
    }

    public static function sku($product)
    {
        return trim((string) $product->get_sku());
    }

    /**
     * Taux de TVA de base du produit (en %).
     */
    public static function tax_rate($product)
    {
        if ($product->get_tax_status() !== 'taxable') {
            return 0;
        }
        $rates = WC_Tax::get_base_tax_rates($product->get_tax_class());
        if (empty($rates)) {
            return 0;
        }
        $rate = reset($rates);
        return !empty($rate['rate']) ? (float) $rate['rate'] : 0;
    }

    /**
     * Prix unitaire HT, quel que soit le réglage « prix saisis TTC ou HT ».
     */
    public static function price_excl_tax($product)
    {
        if (get_option('woocommerce_prices_include_tax') === 'yes') {
            $price_incl = wc_get_price_including_tax($product);
            $taxes = WC_Tax::calc_tax($price_incl, WC_Tax::get_rates($product->get_tax_class()), true);
            return $price_incl - array_sum($taxes);
        }
        return wc_get_price_excluding_tax($product);
    }

    /**
     * Données envoyées à Axonaut pour créer ou mettre à jour le produit.
     * Seuls les champs documentés de l'API produit sont envoyés.
     */
    public static function payload($product)
    {
        return array(
            'name' => self::name($product),
            'description' => self::description($product),
            'internal_id' => (string) $product->get_id(),
            'product_code' => self::sku($product),
            'price' => round((float) self::price_excl_tax($product), 4),
            'tax_rate' => self::tax_rate($product),
        );
    }
}
