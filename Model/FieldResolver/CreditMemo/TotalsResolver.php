<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\CreditMemo;

use Magebit\McpOrderTools\Api\CreditMemoFieldResolverInterface;
use Magebit\McpOrderTools\Model\FieldResolver\CastsScalars;
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Credit memo totals — subtotal, adjustments, shipping, grand total.
 */
class TotalsResolver implements CreditMemoFieldResolverInterface
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
        return 20;
    }

    /** @inheritDoc */
    public function resolve(CreditmemoInterface $creditMemo, array $args): array
    {
        return [
            'currency_code' => (string) $creditMemo->getOrderCurrencyCode(),
            'base_currency_code' => $this->asString($creditMemo->getBaseCurrencyCode()),
            'subtotal' => (float) $creditMemo->getSubtotal(),
            'tax_amount' => (float) $creditMemo->getTaxAmount(),
            'shipping_amount' => (float) $creditMemo->getShippingAmount(),
            'discount_amount' => (float) $creditMemo->getDiscountAmount(),
            'adjustment' => (float) $creditMemo->getAdjustment(),
            'adjustment_positive' => (float) $creditMemo->getAdjustmentPositive(),
            'adjustment_negative' => (float) $creditMemo->getAdjustmentNegative(),
            'grand_total' => (float) $creditMemo->getGrandTotal(),
        ];
    }
}
