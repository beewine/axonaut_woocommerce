<?php

/**
 * Taux de TVA exact d'une ligne de commande WooCommerce.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

class Axonaut_Tax
{
    /**
     * @param WC_Order_Item $item ligne produit, port ou frais (commande ou remboursement)
     * @return float taux en %
     */
    public static function item_rate($item)
    {
        $taxes = $item->get_taxes();
        $rateIds = array();
        if (!empty($taxes['total'])) {
            foreach ($taxes['total'] as $rateId => $amount) {
                if ((float) $amount != 0.0) {
                    $rateIds[] = $rateId;
                }
            }
        }

        // Un seul taux appliqué : on lit le taux configuré, sans passer par un ratio arrondi.
        if (count($rateIds) === 1) {
            $rate = WC_Tax::_get_tax_rate($rateIds[0]);
            if (!empty($rate['tax_rate'])) {
                return (float) $rate['tax_rate'];
            }
        }

        return self::ratio((float) $item->get_total(), (float) $item->get_total_tax());
    }

    /** Taux déduit des montants (taxes composées, taux supprimé) : 2 décimales, 1 si inférieur à 10. */
    public static function ratio($total, $tax)
    {
        if ($total == 0 || $tax == 0) {
            return 0.0;
        }
        $rate = abs($tax / $total) * 100;
        return $rate >= 10 ? round($rate) : round($rate, 1);
    }
}
