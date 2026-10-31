<?php
namespace Opencart\Admin\Model\Extension\SynraPay\Payment;

/**
 * Installs the table that links an OpenCart order to the SynraPay Direct API
 * order its card fields were opened on (hosted session: the Direct order is
 * created before the customer confirms, so the link cannot live in the order).
 */
class SynraPay extends \Opencart\System\Engine\Model
{
    public function install(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "synra_pay_order` (
            `order_id` INT(11) NOT NULL,
            `direct_order_id` VARCHAR(64) NOT NULL DEFAULT '',
            `merchant_order_id` VARCHAR(64) NOT NULL DEFAULT '',
            `amount` DECIMAL(15,4) NOT NULL DEFAULT '0.0000',
            `currency` VARCHAR(3) NOT NULL DEFAULT '',
            `fp` VARCHAR(32) NOT NULL DEFAULT '',
            `env` VARCHAR(16) NOT NULL DEFAULT '',
            `status` VARCHAR(32) NOT NULL DEFAULT 'created',
            `gateway_ref` VARCHAR(128) NOT NULL DEFAULT '',
            `history` TEXT,
            `attempts` INT(11) NOT NULL DEFAULT '0',
            `checks` INT(11) NOT NULL DEFAULT '0',
            `awaiting_since` DATETIME NULL DEFAULT NULL,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`order_id`),
            KEY `direct_order_id` (`direct_order_id`),
            KEY `merchant_order_id` (`merchant_order_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    public function uninstall(): void
    {
        // The link rows are kept: an uninstall must not erase which SynraPay
        // transaction paid which order. Drop the table by hand if you want it gone.
    }
}
