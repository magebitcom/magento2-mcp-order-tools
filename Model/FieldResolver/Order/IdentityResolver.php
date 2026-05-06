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
 * Row identity slice — entity_id, increment_id, store_id.
 */
class IdentityResolver implements OrderFieldResolverInterface
{
    public const KEY = 'identity';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 10;
    }

    /** @inheritDoc */
    public function resolve(OrderInterface $order, array $args): array
    {
        return [
            'entity_id' => (int) $order->getEntityId(),
            'increment_id' => (string) $order->getIncrementId(),
            'store_id' => (int) $order->getStoreId(),
        ];
    }
}
