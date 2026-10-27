<?php

/**
 * Contrôle et correction des écarts d'arrondi entre le total WooCommerce et le total
 * qu'Axonaut calculera à partir des lignes envoyées.
 *
 * Modèle d'Axonaut retenu : HT de chaque ligne arrondi au centime, TVA calculée sur le HT
 * cumulé de chaque taux et arrondie au centime. La correction est expérimentale et désactivée
 * par défaut (réglage « Arrondis »).
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

class Axonaut_Rounding
{
    const MAX_ADJUSTMENT = 0.05;

    /**
     * Totaux attendus d'Axonaut pour des lignes.
     *
     * @param array[] $lines price (HT unitaire), quantity, tax_rate
     * @return array{ht:float,tax:float,total:float,groups:array}
     */
    public static function expected(array $lines)
    {
        $groups = array();
        foreach ($lines as $line) {
            $key = number_format((float) $line['tax_rate'], 4, '.', '');
            $groups[$key] = (isset($groups[$key]) ? $groups[$key] : 0) + round((float) $line['price'] * (float) $line['quantity'], 2);
        }

        $ht = 0.0;
        $tax = 0.0;
        foreach ($groups as $rate => $groupHt) {
            $groupHt = round($groupHt, 2);
            $groups[$rate] = $groupHt;
            $ht += $groupHt;
            $tax += round($groupHt * (float) $rate / 100, 2);
        }

        return array(
            'ht' => round($ht, 2),
            'tax' => round($tax, 2),
            'total' => round($ht + $tax, 2),
            'groups' => $groups,
        );
    }

    /**
     * Ligne d'ajustement à ajouter pour que le total d'Axonaut égale celui de WooCommerce.
     *
     * @param array[] $lines
     * @param float   $wcTotal total TTC WooCommerce
     * @return array|null null si aucun écart, ou écart trop grand pour être un simple arrondi
     */
    public static function adjustment(array $lines, $wcTotal)
    {
        $expected = self::expected($lines);
        $delta = round((float) $wcTotal - $expected['total'], 2);
        if ($delta == 0 || abs($delta) > self::MAX_ADJUSTMENT || empty($expected['groups'])) {
            return null;
        }

        // Taux dominant (plus gros HT), puis taux nul en dernier recours.
        $groups = $expected['groups'];
        arsort($groups);
        $candidates = array((float) key($groups), 0.0);

        foreach ($candidates as $rate) {
            $base = round(isset($groups[number_format($rate, 4, '.', '')]) ? $groups[number_format($rate, 4, '.', '')] : 0, 2);
            $guess = round($delta / (1 + $rate / 100), 2);
            foreach (array($guess, round($guess + 0.01, 2), round($guess - 0.01, 2)) as $amount) {
                $newHt = round($base + $amount, 2);
                $total = $expected['total'] - $base - round($base * $rate / 100, 2) + $newHt + round($newHt * $rate / 100, 2);
                if (round($total, 2) == round((float) $wcTotal, 2)) {
                    return array('price' => $amount, 'quantity' => 1, 'tax_rate' => $rate);
                }
            }
        }

        return null;
    }

    /**
     * Compare le total WooCommerce à ce que renvoie Axonaut à la création de la facture.
     * Le sens exact des champs « total » et « tax_amount » n'étant pas documenté, les deux
     * lectures possibles (TTC, ou HT + TVA) sont acceptées.
     *
     * @return bool true si cohérent
     */
    public static function matches_invoice(array $invoice, $wcTotal)
    {
        $total = isset($invoice['total']) ? (float) $invoice['total'] : null;
        if ($total === null) {
            return true; // rien à comparer
        }
        $tax = isset($invoice['tax_amount']) ? (float) $invoice['tax_amount'] : 0.0;
        $wc = round((float) $wcTotal, 2);
        return abs($total - $wc) < 0.005 || abs($total + $tax - $wc) < 0.005;
    }
}
