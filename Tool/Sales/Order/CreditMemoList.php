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
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * MCP tool `sales.order.credit_memos` — list credit memos for an order.
 */
class CreditMemoList implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.credit_memos';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_credit_memos';
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * @param EntityFinder $entityFinder
     * @param CreditmemoRepositoryInterface $creditMemoRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ResolverPipeline $pipeline
     * @param CreditMemoFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly CreditmemoRepositoryInterface $creditMemoRepository,
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
        return 'List Credit Memos for Order';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'List every credit memo attached to the given order (by entity '
            . 'or increment id). Paging supported; defaults to 25 rows per page.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('order_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('order_increment_id', fn (StringBuilder $s) => $s->minLength(1))
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

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(CreditmemoInterface::ORDER_ID, (int) $order->getEntityId())
            ->setPageSize($pageSize)
            ->setCurrentPage($page)
            ->create();

        $result = $this->creditMemoRepository->getList($criteria);
        $plan = $this->pipeline->plan($this->fieldResolvers, $arguments);

        $rows = [];
        foreach ($result->getItems() as $memo) {
            $row = [];
            foreach ($plan as $resolver) {
                $row[$resolver->getKey()] = $resolver->resolve($memo, $arguments);
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
            throw new LocalizedException(__('Failed to encode credit memo list as JSON.'));
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
