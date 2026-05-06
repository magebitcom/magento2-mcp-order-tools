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
 * created_at / updated_at as stored in the database (typically UTC).
 */
class TimestampsResolver implements OrderFieldResolverInterface
{
    public const KEY = 'timestamps';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 80;
    }

    /** @inheritDoc */
    public function resolve(OrderInterface $order, array $args): array
    {
        return [
            'created_at' => (string) $order->getCreatedAt(),
            'updated_at' => (string) $order->getUpdatedAt(),
        ];
    }
}
