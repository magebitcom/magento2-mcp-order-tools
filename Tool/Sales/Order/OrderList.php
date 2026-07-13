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
use Magebit\Mcp\Model\Tool\Schema\Preset\FieldSelection;
use Magebit\Mcp\Model\Tool\Schema\Preset\Filters;
use Magebit\Mcp\Model\Tool\Schema\Preset\Pagination;
use Magebit\Mcp\Model\Tool\Schema\Preset\Sort;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magebit\McpOrderTools\Model\FieldResolver\Order\OnDemandResolver;
use Magebit\McpOrderTools\Model\Search\OrderSearchCriteriaBuilder;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * MCP tool `sales.order.list` — filtered + paged list of orders.
 */
class OrderList implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.list';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_list';

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSearchCriteriaBuilder $searchBuilder
     * @param ResolverPipeline $pipeline
     * @param OrderFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSearchCriteriaBuilder $searchBuilder,
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
        return 'List Orders';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Search sales orders with optional filters (status, state, date '
            . 'range, grand-total range, customer email, increment id, store '
            . 'id, website id) and paging. Each result row is composed from '
            . 'the same field resolvers as `sales.order.get`; use '
            . '`fields`/`exclude` to narrow. Line items are available on '
            . 'request: include "items" in `fields` (kept out of default '
            . 'rows for payload size). For item-level filtering or '
            . 'aggregation prefer `sales.order.item.list`.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(Filters::describing(
                'Filter clauses. Built-in keys: status, '
                . 'state, created_at_from, created_at_to, '
                . 'grand_total_min, grand_total_max, customer_email, '
                . 'increment_id, store_id, website_id. Scalar or '
                . 'array values (array ⇒ IN). `website_id` is '
                . 'expanded to the matching store-view ids — orders '
                . 'carry no website column.'
            ))
            ->with(Sort::fields(OrderSearchCriteriaBuilder::SORTABLE_FIELDS))
            ->with(Pagination::maxPageSize(OrderSearchCriteriaBuilder::MAX_PAGE_SIZE))
            ->with(FieldSelection::default())
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
        $criteria = $this->searchBuilder->build($arguments);
        $result = $this->orderRepository->getList($criteria);

        $plan = $this->pipeline->plan($this->requestedResolvers($arguments), $arguments);

        $rows = [];
        foreach ($result->getItems() as $order) {
            $row = [];
            foreach ($plan as $resolver) {
                $row[$resolver->getKey()] = $resolver->resolve($order, $arguments);
            }
            $rows[] = $row;
        }

        $payload = [
            'items' => $rows,
            'total_count' => (int) $result->getTotalCount(),
            'page_size' => $criteria->getPageSize() ?? OrderSearchCriteriaBuilder::DEFAULT_PAGE_SIZE,
            'current_page' => $criteria->getCurrentPage() ?? 1,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode order list as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'row_count' => count($rows),
                'total_count' => (int) $result->getTotalCount(),
                'page' => $criteria->getCurrentPage(),
                'page_size' => $criteria->getPageSize(),
            ]
        );
    }

    /**
     * Drops opt-in resolvers whose key was not explicitly requested via
     * `fields`, so expensive slices don't run for default list calls.
     *
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return OrderFieldResolverInterface[]
     */
    private function requestedResolvers(array $arguments): array
    {
        $resolvers = [];
        foreach ($this->fieldResolvers as $resolver) {
            if ($resolver instanceof OnDemandResolver && !$resolver->isRequested($arguments)) {
                continue;
            }
            $resolvers[] = $resolver;
        }

        return $resolvers;
    }
}
