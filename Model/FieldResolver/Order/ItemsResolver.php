<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Order;

use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Parent order lines only — bundle / configurable children are dropped to
 * avoid the parent + child duplication callers usually have to handle.
 */
class ItemsResolver implements OrderFieldResolverInterface
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
        return 50;
    }

    /** @inheritDoc */
    public function resolve(OrderInterface $order, array $args): array
    {
        $rows = [];
        foreach ($order->getItems() as $item) {
            if ($item->getParentItem() !== null) {
                continue;
            }
            $rows[] = [
                'item_id' => (int) $item->getItemId(),
                'sku' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'product_type' => (string) $item->getProductType(),
                'qty_ordered' => (float) $item->getQtyOrdered(),
                'qty_invoiced' => (float) $item->getQtyInvoiced(),
                'qty_shipped' => (float) $item->getQtyShipped(),
                'qty_refunded' => (float) $item->getQtyRefunded(),
                'qty_canceled' => (float) $item->getQtyCanceled(),
                'price' => (float) $item->getPrice(),
                'row_total' => (float) $item->getRowTotal(),
                'row_total_incl_tax' => (float) $item->getRowTotalInclTax(),
                'tax_amount' => (float) $item->getTaxAmount(),
                'discount_amount' => (float) $item->getDiscountAmount(),
            ];
        }
        return $rows;
    }
}
