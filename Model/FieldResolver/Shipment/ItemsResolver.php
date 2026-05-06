<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Shipment;

use Magebit\McpOrderTools\Api\ShipmentFieldResolverInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentItemInterface;

/**
 * Lines shipped — qty + SKU + weight.
 */
class ItemsResolver implements ShipmentFieldResolverInterface
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
        return 20;
    }

    /** @inheritDoc */
    public function resolve(ShipmentInterface $shipment, array $args): array
    {
        $rows = [];
        foreach ($shipment->getItems() as $item) {
            if (!$item instanceof ShipmentItemInterface) {
                continue;
            }
            $rows[] = [
                'entity_id' => (int) $item->getEntityId(),
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'qty' => (float) $item->getQty(),
                'price' => (float) $item->getPrice(),
                'row_total' => (float) $item->getRowTotal(),
                'weight' => (float) $item->getWeight(),
            ];
        }
        return $rows;
    }
}
