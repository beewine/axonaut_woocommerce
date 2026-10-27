<?php

/**
 * Factures, paiements et avoirs d'une commande WooCommerce.
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-api.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-product-resolver.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-tax.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/class-axonaut-rounding.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/axonaut-customer.php";
require_once plugin_dir_path(dirname(__FILE__)) . "includes/utils.php";

define("SHIPPING_UNIQUE_ID", "111999");
define("FEE_UNIQUE_ID", "211999");
define("ROUNDING_UNIQUE_ID", "311999");
define("REFUND_UNIQUE_ID", "411999");

class AxonautSell
{
    const META_INVOICE_ID = '_axonaut_invoice_id';
    const META_COMPANY_ID = '_axonaut_company_id';
    const META_CREDIT_ID = '_axonaut_credit_note_id';

    private $orderNumber;
    private $order;

    /**
     * AxonautSell constructor.
     * @param WC_Order $order
     */
    public function __construct($order)
    {
        axonaut_write_log("[ Invoices management ] Initialized.");
        $this->order = $order;
        $this->orderNumber = $order->get_id();
    }

    // ======================================================================
    // Lignes
    // ======================================================================

    /**
     * Ligne de facture d'un produit de commande, ou null si elle doit être ignorée.
     *
     * @param WC_Order_Item_Product $item
     * @param bool $refund true : ligne d'avoir (quantité négative)
     * @return array|null
     */
    private function product_line($item, $refund)
    {
        $product = $item->get_product();
        if (is_bool($product) || !$product) {
            axonaut_write_log("[ Order : " . $this->orderNumber . " ] Could not get product from order item, skipping order item " . $item->get_id() . ".", AXONAUT_LOG_WARNING);
            return null;
        }

        $quantity = abs((float) $item->get_quantity());
        $total = abs((float) $item->get_total());
        if ($quantity == 0) {
            // Remboursement d'un montant sans quantité : une unité au montant remboursé.
            if (!$refund || $total == 0) {
                return null;
            }
            $quantity = 1;
        }

        $taxRate = $item->get_tax_status() !== 'taxable' ? 0 : Axonaut_Tax::item_rate($item);
        $sku = Axonaut_Product_Mapper::sku($product);

        $line = array(
            "name" => Axonaut_Product_Mapper::name($product),
            "price" => round($total / $quantity, 6),
            "tax_rate" => $taxRate,
            "quantity" => $refund ? -$quantity : $quantity,
            "description" => Axonaut_Product_Mapper::description($product),
            "chapter" => "Commande internet n°" . $this->orderNumber,
        );

        // Un produit déjà connu d'Axonaut (par référence ou par ID) est désigné par son id Axonaut.
        // Sinon, Axonaut le crée à partir de l'ID WooCommerce et de la référence.
        $remote = Axonaut_Product_Resolver::match_mode() === 'sku_then_id' ? Axonaut_Product_Resolver::find_remote($product) : null;
        if ($remote !== null) {
            $line["id"] = (int) $remote["id"];
        } else {
            $line["internal_id"] = (string) $product->get_id();
            if ($sku !== '') {
                $line["product_code"] = $sku;
            }
        }

        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Adding product of id " . $product->get_id() . " to the document.");
        return $line;
    }

    /**
     * Lignes de port et de frais, avec le taux exact de chaque ligne.
     *
     * @param WC_Order|WC_Order_Refund $source
     * @param bool $refund
     * @return array[]
     */
    private function extra_lines($source, $refund)
    {
        $lines = array();
        $chapter = "Commande internet n°" . $this->orderNumber;

        foreach ($source->get_items('shipping') as $item) {
            $total = abs((float) $item->get_total());
            if ($total == 0) {
                continue;
            }
            $lines[] = array(
                "name" => "Frais de port",
                "internal_id" => SHIPPING_UNIQUE_ID,
                "price" => $total,
                "quantity" => $refund ? -1 : 1,
                "description" => "",
                "tax_rate" => Axonaut_Tax::item_rate($item),
                "chapter" => $chapter,
            );
        }

        foreach ($source->get_items('fee') as $item) {
            $total = abs((float) $item->get_total());
            if ($total == 0) {
                continue;
            }
            // Une remise commerciale (frais négatif) reste une remise sur la facture.
            $negative = (float) $item->get_total() < 0;
            $lines[] = array(
                "name" => "Frais",
                "internal_id" => FEE_UNIQUE_ID,
                "price" => $negative ? -$total : $total,
                "quantity" => $refund ? -1 : 1,
                "description" => "",
                "tax_rate" => Axonaut_Tax::item_rate($item),
                "chapter" => $chapter,
            );
        }

        return $lines;
    }

    /**
     * Lignes de la facture : produits, port, frais, et ajustement d'arrondi si activé.
     *
     * @return array[]
     */
    public function get_products_informations()
    {
        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Getting products data.");

        $products = array();
        foreach ($this->order->get_items() as $item) {
            $line = $this->product_line($item, false);
            if ($line !== null) {
                $products[] = $line;
            }
        }
        $products = array_merge($products, $this->extra_lines($this->order, false));

        if (get_option('axonaut_rounding_mode', 'off') === 'adjust') {
            $adjustment = Axonaut_Rounding::adjustment($products, (float) $this->order->get_total());
            if ($adjustment !== null) {
                $products[] = array_merge($adjustment, array(
                    "name" => "Arrondi",
                    "internal_id" => ROUNDING_UNIQUE_ID,
                    "description" => "",
                    "chapter" => "Commande internet n°" . $this->orderNumber,
                ));
                axonaut_write_log("[ Order : " . $this->orderNumber . " ] Rounding adjustment of " . $adjustment['price'] . " added.");
            }
        }

        return $products;
    }

    // ======================================================================
    // Facture
    // ======================================================================

    /** Corps commun des factures et avoirs : client, adresse de livraison. */
    private function document_body($lines, $orderNumber)
    {
        $body = array(
            "products" => $lines,
            "order_number" => $orderNumber,
            "allow_gocardless_payment" => false,
        );

        $companyId = $this->order->get_meta(self::META_COMPANY_ID);
        if (!empty($companyId)) {
            $body["company_id"] = (int) $companyId;
        } else {
            $body["employee_email"] = $this->order->get_billing_email();
        }

        return $body;
    }

    /**
     * Facture Axonaut de la commande : identifiant mémorisé, sinon recherche par référence.
     *
     * @return array|null facture (champs de GET /invoices)
     */
    private function fetch_invoice()
    {
        $id = $this->order->get_meta(self::META_INVOICE_ID);
        if (!empty($id)) {
            $result = Axonaut_Api::get('/api/v2/invoices/' . (int) $id);
            if ($result['ok'] && !empty($result['data'])) {
                return $result['data'];
            }
            if ($result['status'] === 404) {
                $this->order->delete_meta_data(self::META_INVOICE_ID);
                $this->order->save_meta_data();
            } else {
                return null;
            }
        }

        $result = Axonaut_Api::get('/api/v2/invoices', array('internal_ref' => $this->orderNumber), array('page' => 1));
        if (!$result['ok'] || empty($result['data'])) {
            return null;
        }
        // La recherche peut aussi remonter des avoirs de la commande : on ne garde qu'une facture positive.
        foreach ($result['data'] as $invoice) {
            if (!isset($invoice['total']) || (float) $invoice['total'] > 0) {
                $this->remember_invoice($invoice);
                return $invoice;
            }
        }
        return null;
    }

    private function remember_invoice($invoice)
    {
        if (!empty($invoice['id'])) {
            $this->order->update_meta_data(self::META_INVOICE_ID, (int) $invoice['id']);
        }
        if (!empty($invoice['company']['id'])) {
            $this->order->update_meta_data(self::META_COMPANY_ID, (int) $invoice['company']['id']);
        }
        $this->order->save_meta_data();
    }

    /** Crée ou met à jour le client dans Axonaut avant la facture. */
    protected function sync_customer()
    {
        $wcCustomer = new WC_Customer($this->order->get_customer_id());
        $axonautCustomer = new AxonautCustomer($wcCustomer, $this->order);
        $axonautCustomer->send();
    }

    /**
     * Crée la facture (une seule par commande).
     * @return int|false identifiant de la facture Axonaut
     */
    public function send()
    {
        $existing = $this->fetch_invoice();
        if ($existing !== null) {
            axonaut_write_log("[ Order : " . $this->orderNumber . " ] Invoice already exists in Axonaut (" . $existing["id"] . ").");
            return $existing["id"];
        }

        $this->sync_customer();

        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Creating the invoice from WooCommerce to Axonaut...");

        $body = $this->document_body($this->get_products_informations(), $this->orderNumber);

        if (!empty($this->order->get_shipping_address_1())) {
            $contactName = $this->order->get_shipping_first_name() . " " . $this->order->get_shipping_last_name();
            if (!empty($this->order->get_billing_email())) {
                $contactName .= " - " . $this->order->get_billing_email();
            }
            if (!empty($this->order->get_shipping_phone())) {
                $contactName .= " - " . $this->order->get_shipping_phone();
            }
            $body["delivery_address"] = array(
                "company_name" => $this->order->get_shipping_company(),
                "contact_name" => $contactName,
                "street" => $this->order->get_shipping_address_1(),
                "zip_code" => $this->order->get_shipping_postcode(),
                "city" => $this->order->get_shipping_city(),
                "country" => $this->order->get_shipping_country(),
            );
        }

        $result = Axonaut_Api::post('/api/v2/invoices', $body);
        if (!$result['ok']) {
            return false;
        }

        $invoice = $result['data'];
        $this->remember_invoice($invoice);
        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Invoice created (" . $invoice["id"] . ").");

        if (!Axonaut_Rounding::matches_invoice($invoice, $this->order->get_total())) {
            $note = sprintf(
                /* translators: 1: WooCommerce total, 2: Axonaut total, 3: Axonaut tax amount */
                __("Axonaut : écart de total sur la facture. WooCommerce %1\$s, Axonaut total %2\$s (TVA %3\$s).", "axonaut"),
                $this->order->get_total(),
                isset($invoice['total']) ? $invoice['total'] : '?',
                isset($invoice['tax_amount']) ? $invoice['tax_amount'] : '?'
            );
            $this->order->add_order_note($note);
            axonaut_write_log("[ Order : " . $this->orderNumber . " ] " . $note, AXONAUT_LOG_WARNING);
        }

        return $invoice["id"];
    }

    /**
     * Paiement de la facture (commande « Terminée »).
     * @return bool
     */
    public function send_pay()
    {
        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Sending payment to Axonaut...");

        $invoice = $this->fetch_invoice();
        if ($invoice !== null) {
            $axonautInvoiceId = $invoice["id"];
            if (!empty($invoice["paid_date"])) {
                // La facture a déjà été payée : ne pas continuer
                axonaut_write_log("[ Order : " . $this->orderNumber . " ] This invoice has already been paid (" . $axonautInvoiceId . " - " . $invoice["paid_date"] . ").");
                return false;
            }
        } else {
            axonaut_write_log("[ Order : " . $this->orderNumber . " ] Invoice does not exist in Axonaut - Create the invoice.");
            $axonautInvoiceId = $this->send();
        }

        if (empty($axonautInvoiceId)) {
            axonaut_write_log("[ Order : " . $this->orderNumber . " ] Could not retrieve Axonaut invoice id.");
            return false;
        }

        // 1 = Prélèvement ; 2 = Virement ; 3 = Chèque ; 4 = CB ; 5 = Espèce
        $woo_payment = $this->order->get_payment_method();
        if (strpos($woo_payment, "bacs") !== false) {
            $paymentMethod = 2; // Transfère bancaire
        } else if (strpos($woo_payment, "cheque") !== false) {
            $paymentMethod = 3; // Chèque
        } else if (strpos($woo_payment, "cod") !== false) {
            $paymentMethod = 5; // Cash on delivery
        } else {
            $paymentMethod = 4; // Par défaut, CB
        }

        $result = Axonaut_Api::post('/api/v2/payments', array(
            // Avec les avoirs synchronisés, la facture est réduite des remboursements déjà passés.
            "amount" => get_option('axonaut_sync_credit_notes') === 'on' ? $this->order->get_total() - $this->order->get_total_refunded() : $this->order->get_total(),
            "nature" => $paymentMethod,
            "date_ts" => (new DateTime())->getTimestamp(),
            "invoice_id" => $axonautInvoiceId,
        ));
        if (!$result['ok']) {
            return false;
        }

        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Payment request has been successful.");
        return true;
    }

    /**
     * Annule les paiements de la commande dans Axonaut.
     * @return bool
     */
    public function cancel_order()
    {
        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Cancelling order payments...");

        $result = Axonaut_Api::delete('/api/v2/private/order-payments/' . $this->orderNumber);
        if (!$result['ok']) {
            return false;
        }

        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Payments successfully deleted.");
        return true;
    }

    // ======================================================================
    // Avoirs
    // ======================================================================

    /**
     * Avoir correspondant à un remboursement WooCommerce.
     *
     * @param WC_Order_Refund $refund
     * @return int|false|null id de l'avoir ; null si rien à faire (facture absente ou déjà fait)
     */
    public function send_credit_note($refund)
    {
        $done = $refund->get_meta(self::META_CREDIT_ID);
        if (!empty($done)) {
            return null;
        }

        $invoice = $this->fetch_invoice();
        if ($invoice === null) {
            axonaut_write_log("[ Order : " . $this->orderNumber . " ] Refund " . $refund->get_id() . " ignored : no Axonaut invoice for this order.");
            return null;
        }

        $lines = array();
        foreach ($refund->get_items() as $item) {
            $line = $this->product_line($item, true);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        $lines = array_merge($lines, $this->extra_lines($refund, true));

        if (empty($lines)) {
            // Remboursement d'un montant libre, sans lignes : une ligne au taux moyen de la commande.
            $amount = abs((float) $refund->get_amount());
            $tax = abs((float) $refund->get_total_tax());
            if ($amount == 0) {
                return null;
            }
            $lines[] = array(
                "name" => "Remboursement" . ($refund->get_reason() ? " : " . $refund->get_reason() : ""),
                "internal_id" => REFUND_UNIQUE_ID,
                "price" => round($amount - $tax, 6),
                "quantity" => -1,
                "description" => "",
                "tax_rate" => Axonaut_Tax::ratio($amount - $tax, $tax),
                "chapter" => "Commande internet n°" . $this->orderNumber,
            );
        }

        $body = $this->document_body($lines, "AV-" . $refund->get_id());
        $body["origin_invoice_id"] = (int) $invoice["id"];

        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Creating the credit note for refund " . $refund->get_id() . "...");
        $result = Axonaut_Api::post('/api/v2/invoices', $body);
        if (!$result['ok']) {
            return false;
        }

        $creditId = (int) $result['data']["id"];
        $refund->update_meta_data(self::META_CREDIT_ID, $creditId);
        $refund->save_meta_data();
        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Credit note created (" . $creditId . ").");
        return $creditId;
    }

    /**
     * Avoir pour le solde non remboursé d'une commande annulée après facturation.
     *
     * @return int|false|null
     */
    public function credit_cancelled_order()
    {
        if (!empty($this->order->get_meta(self::META_CREDIT_ID))) {
            return null;
        }
        $invoice = $this->fetch_invoice();
        if ($invoice === null) {
            return null;
        }

        $remaining = round((float) $this->order->get_total() - (float) $this->order->get_total_refunded(), 2);
        if ($remaining <= 0) {
            return null; // déjà intégralement remboursée (avoirs créés remboursement par remboursement)
        }

        if ((float) $this->order->get_total_refunded() == 0) {
            // Aucun remboursement : l'avoir reprend les lignes de la facture.
            $lines = array();
            foreach ($this->get_products_informations() as $line) {
                $line["quantity"] = -abs($line["quantity"]);
                $lines[] = $line;
            }
        } else {
            $tax = (float) $this->order->get_total_tax() - (float) $this->order->get_total_tax_refunded();
            $lines = array(array(
                "name" => "Annulation de la commande",
                "internal_id" => REFUND_UNIQUE_ID,
                "price" => round($remaining - $tax, 6),
                "quantity" => -1,
                "description" => "",
                "tax_rate" => Axonaut_Tax::ratio($remaining - $tax, $tax),
                "chapter" => "Commande internet n°" . $this->orderNumber,
            ));
        }

        $body = $this->document_body($lines, "AV-" . $this->orderNumber);
        $body["origin_invoice_id"] = (int) $invoice["id"];

        axonaut_write_log("[ Order : " . $this->orderNumber . " ] Creating the credit note for the cancelled order...");
        $result = Axonaut_Api::post('/api/v2/invoices', $body);
        if (!$result['ok']) {
            return false;
        }

        $this->order->update_meta_data(self::META_CREDIT_ID, (int) $result['data']["id"]);
        $this->order->save_meta_data();
        return (int) $result['data']["id"];
    }
}

