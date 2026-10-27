<?php

/**
 * Dernière valeur synchronisée de chaque champ d'un produit.
 *
 * Sert de point de comparaison à trois voies : savoir si WooCommerce, Axonaut ou les deux
 * ont changé depuis la dernière synchronisation.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

class Axonaut_Snapshot
{
    const META = '_axonaut_snapshot';

    public static function get($product)
    {
        $raw = $product->get_meta(self::META, true);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : array();
    }

    /** Fusionne $values dans l'instantané et l'enregistre. */
    public static function update($product, array $values)
    {
        $snapshot = array_merge(self::get($product), $values);
        $product->update_meta_data(self::META, wp_json_encode($snapshot));
        $product->save_meta_data();
    }

    /** Normalise une valeur pour comparer WooCommerce et Axonaut sans faux écarts. */
    public static function normalize($field, $value)
    {
        switch ($field) {
            case 'price':
                return number_format((float) $value, 2, '.', '');
            case 'stock':
                return (string) (float) $value;
            case 'description':
                return Axonaut_Product_Mapper::clean_text($value);
            default:
                return trim((string) $value);
        }
    }
}
