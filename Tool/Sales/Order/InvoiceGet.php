<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Tool\Sales\Order;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Api\InvoiceFieldResolverInterface;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `sales.order.invoice.get` — fetch one invoice by id or increment id.
 */
class InvoiceGet implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.invoice.get';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_invoice_get';

    /**
     * @param EntityFinder $entityFinder
     * @param ResolverPipeline $pipeline
     * @param InvoiceFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ResolverPipeline $pipeline,
        private readonly array $fieldResolvers = []
    ) {
    }

    /** @inheritDoc */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /** @inheritDoc */
    public function getTitle(): string
    {
        return 'Get Invoice';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Fetch a single invoice by its numeric id or increment id.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('invoice_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Numeric sales_invoice.entity_id.')
            )
            ->string('invoice_increment_id', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Invoice increment id.')
            )
            ->array('fields', fn (ArrayBuilder $a) => $a->ofStrings())
            ->array('exclude', fn (ArrayBuilder $a) => $a->ofStrings())
            ->toArray();
    }

    /** @inheritDoc */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /** @inheritDoc */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /** @inheritDoc */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function execute(array $arguments): ToolResultInterface
    {
        $invoice = $this->entityFinder->invoiceFrom($arguments);

        $response = [];
        foreach ($this->pipeline->plan($this->fieldResolvers, $arguments) as $resolver) {
            $response[$resolver->getKey()] = $resolver->resolve($invoice, $arguments);
        }

        $json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode invoice as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => (int) $invoice->getEntityId(),
                'increment_id' => (string) $invoice->getIncrementId(),
                'order_id' => (int) $invoice->getOrderId(),
            ]
        );
    }
}
