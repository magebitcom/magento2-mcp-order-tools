<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Order;

use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magebit\McpOrderTools\Model\FieldResolver\CastsScalars;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Customer slice. PII fields are HMAC-fingerprinted in the audit log by the
 * base module's PiiRedactor; the MCP response itself is clear-text per spec.
 */
class CustomerResolver implements OrderFieldResolverInterface
{
    use CastsScalars;

    public const KEY = 'customer';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 30;
    }

    /** @inheritDoc */
    public function resolve(OrderInterface $order, array $args): array
    {
        return [
            'customer_id' => $this->asInt($order->getCustomerId()),
            'customer_email' => (string) $order->getCustomerEmail(),
            'customer_firstname' => $this->asString($order->getCustomerFirstname()),
            'customer_lastname' => $this->asString($order->getCustomerLastname()),
            'customer_group_id' => $this->asInt($order->getCustomerGroupId()),
            'is_guest' => (bool) $order->getCustomerIsGuest(),
        ];
    }
}
