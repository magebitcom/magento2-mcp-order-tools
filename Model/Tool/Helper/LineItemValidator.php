<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\Tool\Helper;

use Magento\Framework\Exception\LocalizedException;

/**
 * Validator for the `items: [{item_id, qty}]` argument shared by the
 * invoice / shipment / credit-memo create tools.
 */
class LineItemValidator
{
    /**
     * @param mixed $raw
     * @return array<array{item_id: int, qty: float}>
     * @throws LocalizedException
     */
    public function validate(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new LocalizedException(__('Parameter "items" must be an array of objects.'));
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new LocalizedException(__('Each item must be an object.'));
            }
            $itemIdRaw = $row['item_id'] ?? null;
            $qtyRaw = $row['qty'] ?? null;
            if (!is_numeric($itemIdRaw) || (int) $itemIdRaw <= 0) {
                throw new LocalizedException(__('Each item requires a positive "item_id".'));
            }
            if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0) {
                throw new LocalizedException(__('Each item requires a positive "qty".'));
            }
            $out[] = ['item_id' => (int) $itemIdRaw, 'qty' => (float) $qtyRaw];
        }
        return $out;
    }
}
