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
use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `sales.order.get` — fetch one order by entity or increment id.
 */
class OrderGet implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.get';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_get';

    /**
     * @param EntityFinder $entityFinder
     * @param ResolverPipeline $pipeline
     * @param OrderFieldResolverInterface[] $fieldResolvers
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
        return 'Get Order';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Fetch a single sales order by its numeric entity id or its '
            . 'admin-visible increment id. The response is composed from '
            . 'registered field resolvers — use the `fields` argument to '
            . 'narrow the payload or `exclude` to drop specific slices '
            . '(e.g. exclude the `items` slice on high-line-count orders).';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('entity_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Numeric sales_order.entity_id.')
            )
            ->string('increment_id', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Human-visible order increment id (e.g. "000000001").')
            )
            ->array('fields', fn (ArrayBuilder $a) => $a
                ->ofStrings()
                ->description('Whitelist of resolver keys to include. '
                    . 'Defaults to all registered resolvers.')
            )
            ->array('exclude', fn (ArrayBuilder $a) => $a
                ->ofStrings()
                ->description('Resolver keys to drop from the default payload.')
            )
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
        $order = $this->entityFinder->orderFrom($arguments);

        $response = [];
        foreach ($this->pipeline->plan($this->fieldResolvers, $arguments) as $resolver) {
            $response[$resolver->getKey()] = $resolver->resolve($order, $arguments);
        }

        $json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode order payload as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => (int) $order->getEntityId(),
                'increment_id' => (string) $order->getIncrementId(),
                'status' => (string) $order->getStatus(),
            ]
        );
    }
}
