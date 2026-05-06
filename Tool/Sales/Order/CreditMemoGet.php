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
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpOrderTools\Api\CreditMemoFieldResolverInterface;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `sales.order.credit_memo.get` — fetch one credit memo.
 */
class CreditMemoGet implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.credit_memo.get';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_credit_memo_get';

    /**
     * @param EntityFinder $entityFinder
     * @param ResolverPipeline $pipeline
     * @param CreditMemoFieldResolverInterface[] $fieldResolvers
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
        return 'Get Credit Memo';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Fetch a single credit memo by its numeric id or increment id.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('credit_memo_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('credit_memo_increment_id', fn (StringBuilder $s) => $s->minLength(1))
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
        $memo = $this->entityFinder->creditMemoFrom($arguments);

        $response = [];
        foreach ($this->pipeline->plan($this->fieldResolvers, $arguments) as $resolver) {
            $response[$resolver->getKey()] = $resolver->resolve($memo, $arguments);
        }

        $json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode credit memo as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => (int) $memo->getEntityId(),
                'increment_id' => (string) $memo->getIncrementId(),
                'order_id' => (int) $memo->getOrderId(),
            ]
        );
    }
}
