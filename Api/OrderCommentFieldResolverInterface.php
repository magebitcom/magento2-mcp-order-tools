<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Api;

use Magebit\Mcp\Api\FieldResolverInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;

interface OrderCommentFieldResolverInterface extends FieldResolverInterface
{
    /**
     * @param OrderStatusHistoryInterface $comment
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return array<int|string, mixed>
     */
    public function resolve(OrderStatusHistoryInterface $comment, array $args): array;
}
