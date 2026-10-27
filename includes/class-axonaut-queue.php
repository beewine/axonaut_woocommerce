<?php

/**
 * File d'attente des synchronisations : Action Scheduler (fourni avec WooCommerce),
 * ou WP-Cron à défaut. Évite de bloquer une requête de page et respecte la limite
 * de débit de l'API Axonaut.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

class Axonaut_Queue
{
    const GROUP = 'axonaut';
    const HOOK_PRODUCT = 'axonaut_sync_product';

    /**
     * Planifie la synchronisation d'un produit (sans doublon déjà en attente).
     *
     * @param int $productId
     * @param int $delay secondes avant exécution
     * @return bool planifié ou déjà planifié
     */
    public static function enqueue_product($productId, $delay = 0)
    {
        $args = array((int) $productId);
        $when = time() + max(0, (int) $delay);

        if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {
            if (as_has_scheduled_action(self::HOOK_PRODUCT, $args, self::GROUP)) {
                return true;
            }
            return as_schedule_single_action($when, self::HOOK_PRODUCT, $args, self::GROUP) > 0;
        }

        if (wp_next_scheduled(self::HOOK_PRODUCT, $args)) {
            return true;
        }
        return false !== wp_schedule_single_event($when + 1, self::HOOK_PRODUCT, $args);
    }
}
