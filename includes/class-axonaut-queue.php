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
    const HOOK_STOCK = 'axonaut_sync_stock';

    public static function enqueue_product($productId, $delay = 0)
    {
        return self::enqueue(self::HOOK_PRODUCT, array((int) $productId), $delay);
    }

    public static function enqueue_stock($productId, $delay = 0)
    {
        return self::enqueue(self::HOOK_STOCK, array((int) $productId), $delay);
    }

    /**
     * Planifie une tâche (sans doublon déjà en attente).
     *
     * @param string $hook
     * @param array  $args
     * @param int    $delay secondes avant exécution
     * @return bool planifiée ou déjà planifiée
     */
    public static function enqueue($hook, array $args, $delay = 0)
    {
        $when = time() + max(0, (int) $delay);

        if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {
            if (as_has_scheduled_action($hook, $args, self::GROUP)) {
                return true;
            }
            return as_schedule_single_action($when, $hook, $args, self::GROUP) > 0;
        }

        if (wp_next_scheduled($hook, $args)) {
            return true;
        }
        return false !== wp_schedule_single_event($when + 1, $hook, $args);
    }
}
