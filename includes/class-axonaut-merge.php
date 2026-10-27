<?php

/**
 * Décision de synchronisation d'un champ (comparaison à trois voies).
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

class Axonaut_Merge
{
    const NONE = 'none';
    const TO_AXONAUT = 'to_axonaut';
    const TO_WOOCOMMERCE = 'to_woocommerce';

    /**
     * @param string      $direction none | to_axonaut | from_axonaut | both
     * @param string      $wc        valeur WooCommerce (normalisée)
     * @param string      $remote    valeur Axonaut (normalisée)
     * @param string|null $snapshot  dernière valeur synchronisée (normalisée), null si inconnue
     * @param string      $winner    woocommerce | axonaut, en cas de modification des deux côtés
     * @return string self::NONE | self::TO_AXONAUT | self::TO_WOOCOMMERCE
     */
    public static function decide($direction, $wc, $remote, $snapshot, $winner)
    {
        if ($direction === 'none' || $wc === $remote) {
            return self::NONE;
        }
        if ($direction === 'to_axonaut') {
            return self::TO_AXONAUT;
        }
        if ($direction === 'from_axonaut') {
            return self::TO_WOOCOMMERCE;
        }

        // both
        if ($snapshot === null) {
            return $winner === 'axonaut' ? self::TO_WOOCOMMERCE : self::TO_AXONAUT;
        }
        $wcChanged = $wc !== $snapshot;
        $remoteChanged = $remote !== $snapshot;

        if ($wcChanged && !$remoteChanged) {
            return self::TO_AXONAUT;
        }
        if ($remoteChanged && !$wcChanged) {
            return self::TO_WOOCOMMERCE;
        }
        return $winner === 'axonaut' ? self::TO_WOOCOMMERCE : self::TO_AXONAUT;
    }
}
