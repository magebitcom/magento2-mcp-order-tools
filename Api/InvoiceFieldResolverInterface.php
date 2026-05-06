<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Api;

use Magebit\Mcp\Api\FieldResolverInterface;
use Magento\Sales\Api\Data\InvoiceInterface;

interface InvoiceFieldResolverInterface extends FieldResolverInterface
{
    /**
     * @param InvoiceInterface $invoice
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return array<int|string, mixed>
     */
    public function resolve(InvoiceInterface $invoice, array $args): array;
}
