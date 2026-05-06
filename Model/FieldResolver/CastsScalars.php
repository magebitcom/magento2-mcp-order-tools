<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver;

/**
 * Null-preserving scalar casts. Returns null for non-scalar input rather
 * than risk a TypeError on Magento getters that nominally return `mixed`.
 */
trait CastsScalars
{
    /**
     * @param mixed $value
     * @return int|null
     */
    private function asInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || is_bool($value)
            || (is_string($value) && is_numeric($value))
        ) {
            return (int) $value;
        }
        return null;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * @param mixed $value
     * @return float|null
     */
    private function asFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value) || is_bool($value)
            || (is_string($value) && is_numeric($value))
        ) {
            return (float) $value;
        }
        return null;
    }
}
