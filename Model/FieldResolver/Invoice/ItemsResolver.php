<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Invoice;

use Magebit\McpOrderTools\Api\InvoiceFieldResolverInterface;
use Magento\Sales\Api\Data\InvoiceInterface;

/**
 * Lines invoiced — qty + prices per SKU.
 */
class ItemsResolver implements InvoiceFieldResolverInterface
{
    public const KEY = 'items';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 40;
    }

    /** @inheritDoc */
    public function resolve(InvoiceInterface $invoice, array $args): array
    {
        $rows = [];
        foreach ($invoice->getItems() as $item) {
            $rows[] = [
                'entity_id' => (int) $item->getEntityId(),
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'qty' => (float) $item->getQty(),
                'price' => (float) $item->getPrice(),
                'row_total' => (float) $item->getRowTotal(),
                'tax_amount' => (float) $item->getTaxAmount(),
                'discount_amount' => (float) $item->getDiscountAmount(),
            ];
        }
        return $rows;
    }
}
