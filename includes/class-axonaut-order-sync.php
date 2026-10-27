<?php

/**
 * Synchronisation des commandes en arrière-plan, avec verrou et nouvelles tentatives.
 *
 * Une commande n'est plus perdue si Axonaut est momentanément indisponible, et deux changements
 * de statut rapprochés (En cours puis Terminée) ne créent pas deux factures.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-queue.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-sell.php";

class Axonaut_Order_Sync
{
    const HOOK = 'axonaut_sync_order';
    const MAX_ATTEMPTS = 4;
    const LOCK_TTL = 120;

    /** Délais avant les tentatives 2, 3 et 4 (secondes). */
    const RETRY_DELAYS = array(60, 300, 1800);

    /**
     * @param int    $orderId
     * @param string $action invoice | pay | cancel | refund
     * @param int    $extra  identifiant du remboursement pour « refund »
     */
    public static function schedule($orderId, $action, $extra = 0, $attempt = 1, $delay = 0)
    {
        return Axonaut_Queue::enqueue(self::HOOK, array((int) $orderId, (string) $action, (int) $extra, (int) $attempt), $delay);
    }

    public static function on_processing($orderId)
    {
        if (self::enabled()) {
            self::schedule($orderId, 'invoice');
        }
    }

    public static function on_completed($orderId)
    {
        if (self::enabled()) {
            self::schedule($orderId, 'pay');
        }
    }

    public static function on_cancelled($orderId)
    {
        if (self::enabled()) {
            self::schedule($orderId, 'cancel');
        }
    }

    public static function on_refunded($orderId, $refundId)
    {
        if (self::enabled() && get_option('axonaut_sync_credit_notes') === 'on') {
            self::schedule($orderId, 'refund', $refundId);
        }
    }

    private static function enabled()
    {
        return get_option('axonaut_sync_sells') === 'on';
    }

    /** Tâche planifiée. */
    public static function run($orderId, $action, $extra = 0, $attempt = 1)
    {
        if (!self::enabled()) {
            return;
        }
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $lock = 'axonaut_lock_' . (int) $orderId;
        if (!self::acquire($lock)) {
            // Une autre tâche traite cette commande : on repasse juste après.
            self::schedule($orderId, $action, $extra, $attempt, 15);
            return;
        }

        try {
            $ok = self::execute(static::make_sell($order), $order, $action, (int) $extra);
        } finally {
            delete_option($lock);
        }

        if ($ok !== false) {
            return;
        }
        if ($attempt < self::MAX_ATTEMPTS) {
            self::schedule($orderId, $action, $extra, $attempt + 1, self::RETRY_DELAYS[$attempt - 1]);
            return;
        }

        $order->add_order_note(sprintf(
            /* translators: %s: action name */
            __("Axonaut : la synchronisation (%s) a échoué après plusieurs tentatives. Consultez le journal du module.", "axonaut"),
            $action
        ));
    }

    /** Instancie la classe de vente (point d'extension pour les tests). */
    protected static function make_sell($order)
    {
        return new AxonautSell($order);
    }

    /** @return mixed false en cas d'échec à retenter, autre valeur sinon */
    private static function execute($sell, $order, $action, $extra)
    {
        switch ($action) {
            case 'invoice':
                return $sell->send();
            case 'pay':
                $result = $sell->send_pay();
                // « false » signifie aussi « déjà payée » : seul un échec d'envoi est à retenter.
                return $result === false && empty($order->get_meta(AxonautSell::META_INVOICE_ID)) ? false : true;
            case 'cancel':
                $ok = $sell->cancel_order();
                if (get_option('axonaut_sync_credit_notes') === 'on') {
                    $credit = $sell->credit_cancelled_order();
                    if ($credit === false) {
                        return false;
                    }
                }
                return $ok;
            case 'refund':
                $refund = wc_get_order($extra);
                return $refund ? $sell->send_credit_note($refund) : null;
        }
        return null;
    }

    /** Verrou atomique : add_option échoue si l'option existe déjà. Expiré après LOCK_TTL. */
    private static function acquire($name)
    {
        if (add_option($name, time(), '', 'no')) {
            return true;
        }
        $since = (int) get_option($name);
        if ($since && time() - $since > self::LOCK_TTL) {
            update_option($name, time());
            return true;
        }
        return false;
    }
}
