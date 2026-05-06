<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Invoice;

use Magebit\McpOrderTools\Api\InvoiceFieldResolverInterface;
use Magebit\McpOrderTools\Model\FieldResolver\CastsScalars;
use Magento\Sales\Api\Data\InvoiceInterface;

/**
 * Invoice totals — subtotal, grand total, tax, shipping, currency.
 */
class TotalsResolver implements InvoiceFieldResolverInterface
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
        return 30;
    }

    /** @inheritDoc */
    public function resolve(InvoiceInterface $invoice, array $args): array
    {
        return [
            'currency_code' => (string) $invoice->getOrderCurrencyCode(),
            'base_currency_code' => $this->asString($invoice->getBaseCurrencyCode()),
            'subtotal' => (float) $invoice->getSubtotal(),
            'tax_amount' => (float) $invoice->getTaxAmount(),
            'shipping_amount' => (float) $invoice->getShippingAmount(),
            'discount_amount' => (float) $invoice->getDiscountAmount(),
            'grand_total' => (float) $invoice->getGrandTotal(),
        ];
    }
}
