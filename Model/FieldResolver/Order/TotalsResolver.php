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
 * Money totals plus invoiced / refunded progress counters.
 */
class TotalsResolver implements OrderFieldResolverInterface
{
    use CastsScalars;

    public const KEY = 'totals';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 60;
    }

    /** @inheritDoc */
    public function resolve(OrderInterface $order, array $args): array
    {
        return [
            'currency_code' => (string) $order->getOrderCurrencyCode(),
            'base_currency_code' => $this->asString($order->getBaseCurrencyCode()),
            'subtotal' => (float) $order->getSubtotal(),
            'subtotal_incl_tax' => (float) $order->getSubtotalInclTax(),
            'tax_amount' => (float) $order->getTaxAmount(),
            'shipping_amount' => (float) $order->getShippingAmount(),
            'shipping_incl_tax' => (float) $order->getShippingInclTax(),
            'discount_amount' => (float) $order->getDiscountAmount(),
            'grand_total' => (float) $order->getGrandTotal(),
            'total_paid' => (float) $order->getTotalPaid(),
            'total_refunded' => (float) $order->getTotalRefunded(),
            'total_invoiced' => (float) $order->getTotalInvoiced(),
            'total_due' => (float) $order->getTotalDue(),
        ];
    }
}
