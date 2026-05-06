<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Api;

use Magebit\Mcp\Api\FieldResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Contributor to `sales.order.list` / `sales.order.get` output. Each resolver
 * owns one named slice of the response. See `docs/EXTENDING.md`.
 */
interface OrderFieldResolverInterface extends FieldResolverInterface
{
    /**
     * @param OrderInterface $order
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return array<int|string, mixed>
     */
    public function resolve(OrderInterface $order, array $args): array;
}
