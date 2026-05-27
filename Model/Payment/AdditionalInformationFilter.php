<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\Payment;

/**
 * Positive (allowlist) filter for a payment record's `additional_information`.
 *
 * PSP modules use `additional_information` as a free-form store for per-order
 * gateway state — tokenized card references, 3DS payloads, payer IP/email, raw
 * gateway responses. None of that should reach an MCP client by default, so the
 * tool response carries only the keys an operator has explicitly allowlisted.
 * The base-module PII redactor does NOT cover this path: it sanitizes the audit
 * row, never the response payload returned over the wire.
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
