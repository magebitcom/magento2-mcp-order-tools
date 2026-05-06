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
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpOrderTools\Api\OrderCommentFieldResolverInterface;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;

/**
 * MCP tool `sales.order.comments` — list status-history comments on an order.
 */
class CommentList implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.comments';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_comments';
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 50;

    /**
     * @param EntityFinder $entityFinder
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ResolverPipeline $pipeline
     * @param OrderCommentFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
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
        return 'List Order Comments';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'List status-history comments on an order. Optional filter '
            . '`is_visible_on_front` narrows to customer-visible entries.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('order_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('order_increment_id', fn (StringBuilder $s) => $s->minLength(1))
            ->boolean('is_visible_on_front', fn (BooleanBuilder $b) => $b)
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(self::MAX_PAGE_SIZE))
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
        $order = $this->entityFinder->orderFrom($arguments);

        $pageSize = isset($arguments['page_size']) && is_numeric($arguments['page_size'])
            ? min((int) $arguments['page_size'], self::MAX_PAGE_SIZE)
            : self::DEFAULT_PAGE_SIZE;
        $page = isset($arguments['page']) && is_numeric($arguments['page'])
            ? max(1, (int) $arguments['page'])
            : 1;

        $this->searchCriteriaBuilder->addFilter(
            OrderStatusHistoryInterface::PARENT_ID,
            (int) $order->getEntityId()
        );
        if (array_key_exists('is_visible_on_front', $arguments)) {
            $this->searchCriteriaBuilder->addFilter(
                OrderStatusHistoryInterface::IS_VISIBLE_ON_FRONT,
                $arguments['is_visible_on_front'] ? 1 : 0
            );
        }
        $criteria = $this->searchCriteriaBuilder
            ->setPageSize($pageSize)
            ->setCurrentPage($page)
            ->create();

        $result = $this->historyRepository->getList($criteria);
        $plan = $this->pipeline->plan($this->fieldResolvers, $arguments);

        $rows = [];
        foreach ($result->getItems() as $history) {
            $row = [];
            foreach ($plan as $resolver) {
                $row[$resolver->getKey()] = $resolver->resolve($history, $arguments);
            }
            $rows[] = $row;
        }

        $payload = [
            'items' => $rows,
            'total_count' => (int) $result->getTotalCount(),
            'page_size' => $pageSize,
            'current_page' => $page,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode comment list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'order_id' => (int) $order->getEntityId(),
                'row_count' => count($rows),
                'total_count' => (int) $result->getTotalCount(),
            ]
        );
    }
}
