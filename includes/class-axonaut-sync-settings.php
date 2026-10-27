<?php

/**
 * Réglages de sens de synchronisation, champ par champ, et garde anti-écho.
 *
 * Champs : stock, name (titre), description, price.
 * Sens   : none, to_axonaut, from_axonaut, both.
 *
 * Sans réglage enregistré, on reprend le comportement historique du module :
 *  - stock : « from_axonaut » si le webhook de stock était activé, sinon « none » ;
 *  - autres champs : « to_axonaut » si la synchronisation des produits était activée, sinon « none ».
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

class Axonaut_Sync_Settings
{
    const FIELDS = array('stock', 'name', 'description', 'price');
    const DIRECTIONS = array('none', 'to_axonaut', 'from_axonaut', 'both');

    private static $inbound = 0;

    public static function direction($field)
    {
        $value = get_option('axonaut_dir_' . $field, '');
        if (in_array($value, self::DIRECTIONS, true)) {
            return $value;
        }

        if ($field === 'stock') {
            return get_option('axonaut_sync_stocks') ? 'from_axonaut' : 'none';
        }
        return get_option('axonaut_sync_products') === 'on' ? 'to_axonaut' : 'none';
    }

    /** Ce champ part-il de WooCommerce vers Axonaut ? */
    public static function pushes($field)
    {
        return in_array(self::direction($field), array('to_axonaut', 'both'), true);
    }

    /** Ce champ vient-il d'Axonaut vers WooCommerce ? */
    public static function pulls($field)
    {
        return in_array(self::direction($field), array('from_axonaut', 'both'), true);
    }

    /** Champs envoyés vers Axonaut. */
    public static function pushed_fields()
    {
        return array_values(array_filter(self::FIELDS, array(__CLASS__, 'pushes')));
    }

    /** En cas de modification des deux côtés : « woocommerce » ou « axonaut » l'emporte. */
    public static function conflict_winner()
    {
        return get_option('axonaut_conflict_winner', 'woocommerce') === 'axonaut' ? 'axonaut' : 'woocommerce';
    }

    /**
     * Exécute un callback en marquant les écritures WooCommerce comme venant d'Axonaut,
     * pour que les hooks de sauvegarde ne les renvoient pas vers Axonaut.
     */
    public static function as_inbound($callback)
    {
        self::$inbound++;
        try {
            return $callback();
        } finally {
            self::$inbound--;
        }
    }

    public static function is_inbound()
    {
        return self::$inbound > 0;
    }
}
