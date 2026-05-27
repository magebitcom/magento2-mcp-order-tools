<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\Payment;

/**
 * Allowlist filter for a payment record's `additional_information`, which PSP
 * modules fill with gateway secrets. Empty by default so nothing reaches the MCP
 * client; the base-module PII redactor covers only the audit row, not this path.
 */
class AdditionalInformationFilter
{
    /**
     * @param string[] $allowlist Keys permitted to appear in the tool response.
     * @phpstan-param array<int, string> $allowlist
     */
    public function __construct(
        private readonly array $allowlist = []
    ) {
    }

    /**
     * @param array $raw
     * @phpstan-param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function filter(array $raw): array
    {
        $filtered = [];
        foreach ($this->allowlist as $key) {
            if (array_key_exists($key, $raw)) {
                $filtered[$key] = $raw[$key];
            }
        }
        return $filtered;
    }
}
