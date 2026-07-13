<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\Search;

use Magebit\Mcp\Model\Util\WebsiteStoreResolver;
use Magebit\McpOrderTools\Api\OrderItemFilterTranslatorInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory;
use Zend_Db_Expr;

/**
 * Queries `sales_order_item` joined to `sales_order` for the MCP
 * `sales.order.item.list` tool — item-level filters that order-level
 * searchCriteria cannot express, plus an optional GROUP BY aggregate mode.
 * Unrecognised filter keys are dispatched to the injected
 * {@see OrderItemFilterTranslatorInterface[]} array, then rejected.
 */
class OrderItemQuery
{
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 25;

    /** @var array<int, string> */
    public const SORTABLE_FIELDS = ['created_at', 'item_id', 'sku', 'qty_ordered', 'row_total'];

    /** @var array<int, string> */
    public const AGGREGATE_SORTABLE_FIELDS = ['qty_ordered', 'row_total', 'order_count', 'line_count'];

    /** @var array<int, string> */
    public const GROUP_BY_FIELDS = ['sku', 'product_type', 'store_id', 'order_status'];

    /** @var array<string, string> */
    private const SORT_COLUMNS = [
        'created_at' => 'so.created_at',
        'item_id' => 'main_table.item_id',
        'sku' => 'main_table.sku',
        'qty_ordered' => 'main_table.qty_ordered',
        'row_total' => 'main_table.row_total',
    ];

    /** @var array<string, string> */
    private const GROUP_COLUMNS = [
        'sku' => 'main_table.sku',
        'product_type' => 'main_table.product_type',
        'store_id' => 'main_table.store_id',
        'order_status' => 'so.status',
    ];

    /**
     * @param CollectionFactory $collectionFactory
     * @param WebsiteStoreResolver $websiteStoreResolver
     * @param OrderItemFilterTranslatorInterface[] $filterTranslators
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly WebsiteStoreResolver $websiteStoreResolver,
        private readonly array $filterTranslators = []
    ) {
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return array{rows: list<array<string, mixed>>, total_count: int, page: int, page_size: int}
     * @throws LocalizedException
     */
    public function list(array $args): array
    {
        $collection = $this->buildFilteredCollection($args);

        [$sortBy, $direction] = $this->sortSpec($args, self::SORTABLE_FIELDS, 'created_at');
        $select = $collection->getSelect();
        $select->order(self::SORT_COLUMNS[$sortBy] . ' ' . $direction);
        if ($sortBy !== 'item_id') {
            // Deterministic paging when the primary sort column has duplicates.
            $select->order('main_table.item_id ' . $direction);
        }

        [$page, $pageSize] = $this->paging($args);
        $collection->setCurPage($page);
        $collection->setPageSize($pageSize);

        $rows = [];
        foreach ($collection->getItems() as $item) {
            if (!$item instanceof Item) {
                continue;
            }
            $rows[] = $this->mapItem($item);
        }

        return [
            'rows' => $rows,
            'total_count' => (int) $collection->getSize(),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return array{rows: list<array<string, mixed>>, group_by: list<string>, page: int, page_size: int}
     * @throws LocalizedException
     */
    public function aggregate(array $args): array
    {
        $groupBy = $this->groupKeys($args);
        $collection = $this->buildFilteredCollection($args);

        $select = $collection->getSelect();
        $select->reset(Select::COLUMNS);

        $columns = [];
        foreach ($groupBy as $key) {
            $columns[$key] = self::GROUP_COLUMNS[$key];
        }
        $columns['qty_ordered'] = new Zend_Db_Expr('SUM(main_table.qty_ordered)');
        $columns['row_total'] = new Zend_Db_Expr('SUM(main_table.row_total)');
        $columns['order_count'] = new Zend_Db_Expr('COUNT(DISTINCT main_table.order_id)');
        $columns['line_count'] = new Zend_Db_Expr('COUNT(*)');
        $select->columns($columns);
        $select->group(array_map(
            static fn (string $key): string => self::GROUP_COLUMNS[$key],
            $groupBy
        ));

        [$sortBy, $direction] = $this->sortSpec($args, self::AGGREGATE_SORTABLE_FIELDS, 'qty_ordered', 'desc');
        $select->reset(Select::ORDER);
        $select->order($sortBy . ' ' . $direction);

        [$page, $pageSize] = $this->paging($args);
        $select->limitPage($page, $pageSize);

        $rows = [];
        foreach ($collection->getConnection()->fetchAll($select) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $rows[] = $this->mapAggregateRow($raw, $groupBy);
        }

        return [
            'rows' => $rows,
            'group_by' => $groupBy,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return Collection
     * @throws LocalizedException
     */
    private function buildFilteredCollection(array $args): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->getSelect()->join(
            ['so' => $collection->getTable('sales_order')],
            'so.entity_id = main_table.order_id',
            [
                'order_increment_id' => 'so.increment_id',
                'order_status' => 'so.status',
                'order_created_at' => 'so.created_at',
            ]
        );

        $filtersRaw = $args['filters'] ?? [];
        if (!is_array($filtersRaw)) {
            throw new LocalizedException(__('Filter payload must be an object.'));
        }

        $parentItemsOnly = true;
        foreach ($filtersRaw as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new LocalizedException(__('Filter keys must be non-empty strings.'));
            }
            if ($key === 'parent_items_only') {
                $parentItemsOnly = (bool) $value;
                continue;
            }
            $this->applyFilter($collection, $key, $value);
        }
        if ($parentItemsOnly) {
            $collection->addFieldToFilter('main_table.parent_item_id', ['null' => true]);
        }

        return $collection;
    }

    /**
     * @param Collection $collection
     * @param string $key
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function applyFilter(Collection $collection, string $key, mixed $value): void
    {
        switch ($key) {
            case 'order_status':
                $this->addEqualsOrIn($collection, 'so.status', $key, $value);
                return;
            case 'order_state':
                $this->addEqualsOrIn($collection, 'so.state', $key, $value);
                return;

            case 'created_at_from':
                $this->addRangeBoundary($collection, 'so.created_at', 'gteq', $value);
                return;
            case 'created_at_to':
                $this->addRangeBoundary($collection, 'so.created_at', 'lteq', $value);
                return;

            case 'sku':
                $this->addSkuFilter($collection, $value);
                return;

            case 'product_type':
                $this->addEqualsOrIn($collection, 'main_table.product_type', $key, $value);
                return;

            case 'store_id':
                $this->addEqualsOrIn($collection, 'main_table.store_id', $key, $value);
                return;

            case 'website_id':
                $storeIds = $this->websiteStoreResolver->resolveStoreIds($value);
                if ($storeIds === []) {
                    // No stores under that website — force a zero-row result.
                    $collection->addFieldToFilter('main_table.store_id', ['eq' => 0]);
                    return;
                }
                $collection->addFieldToFilter('main_table.store_id', ['in' => $storeIds]);
                return;
        }

        foreach ($this->filterTranslators as $translator) {
            if ($translator->supports($key)) {
                $translator->translate($key, $value, $collection);
                return;
            }
        }

        throw new LocalizedException(__('Unknown order item filter: "%1".', $key));
    }

    /**
     * @param Collection $collection
     * @param string $column
     * @param string $key
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addEqualsOrIn(Collection $collection, string $column, string $key, mixed $value): void
    {
        if (is_array($value)) {
            $list = array_values(array_filter(
                $value,
                static fn($v): bool => is_scalar($v) && (string) $v !== ''
            ));
            if ($list === []) {
                return;
            }
            $collection->addFieldToFilter($column, ['in' => $list]);
            return;
        }
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Filter "%1" requires a non-empty value.', $key));
        }
        $collection->addFieldToFilter($column, ['eq' => $value]);
    }

    /**
     * @param Collection $collection
     * @param string $column
     * @param string $condition
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addRangeBoundary(Collection $collection, string $column, string $condition, mixed $value): void
    {
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Range boundary for "%1" must be scalar.', $column));
        }
        $collection->addFieldToFilter($column, [$condition => (string) $value]);
    }

    /**
     * Scalar values containing `*` are treated as glob patterns and translated
     * to SQL LIKE; `%` / `_` in the input are escaped as literals.
     *
     * @param Collection $collection
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addSkuFilter(Collection $collection, mixed $value): void
    {
        if (is_array($value)) {
            $this->addEqualsOrIn($collection, 'main_table.sku', 'sku', $value);
            return;
        }
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Filter "sku" requires a non-empty value.'));
        }
        $sku = (string) $value;
        if (str_contains($sku, '*')) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $sku);
            $collection->addFieldToFilter('main_table.sku', ['like' => str_replace('*', '%', $escaped)]);
            return;
        }
        $collection->addFieldToFilter('main_table.sku', ['eq' => $sku]);
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @param array $allowed
     * @phpstan-param array<int, string> $allowed
     * @param string $defaultField
     * @param string $defaultDirection
     * @return array{0: string, 1: string}
     * @throws LocalizedException
     */
    private function sortSpec(
        array $args,
        array $allowed,
        string $defaultField,
        string $defaultDirection = 'desc'
    ): array {
        $sortBy = $args['sort_by'] ?? $defaultField;
        if (!is_string($sortBy) || !in_array($sortBy, $allowed, true)) {
            throw new LocalizedException(__(
                '"sort_by" must be one of: %1.',
                implode(', ', $allowed)
            ));
        }

        $dirRaw = $args['sort_dir'] ?? $defaultDirection;
        $direction = is_string($dirRaw) ? strtolower($dirRaw) : '';
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new LocalizedException(__('"sort_dir" must be "asc" or "desc".'));
        }

        return [$sortBy, strtoupper($direction)];
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return array{0: int, 1: int}
     * @throws LocalizedException
     */
    private function paging(array $args): array
    {
        $pageRaw = $args['page'] ?? 1;
        $page = is_numeric($pageRaw) ? max(1, (int) $pageRaw) : 1;

        $sizeRaw = $args['page_size'] ?? self::DEFAULT_PAGE_SIZE;
        if (!is_numeric($sizeRaw)) {
            throw new LocalizedException(__('"page_size" must be numeric.'));
        }
        $size = max(1, (int) $sizeRaw);
        if ($size > self::MAX_PAGE_SIZE) {
            $size = self::MAX_PAGE_SIZE;
        }

        return [$page, $size];
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return list<string>
     * @throws LocalizedException
     */
    private function groupKeys(array $args): array
    {
        $raw = $args['group_by'] ?? [];
        if (!is_array($raw)) {
            throw new LocalizedException(__('"group_by" must be an array of strings.'));
        }

        $keys = [];
        foreach ($raw as $key) {
            if (!is_string($key) || !in_array($key, self::GROUP_BY_FIELDS, true)) {
                throw new LocalizedException(__(
                    'Unknown "group_by" key: "%1". Allowed: %2.',
                    is_scalar($key) ? (string) $key : 'non-string',
                    implode(', ', self::GROUP_BY_FIELDS)
                ));
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            throw new LocalizedException(__('"group_by" requires at least one key.'));
        }

        return $keys;
    }

    /**
     * @param Item $item
     * @return array<string, mixed>
     */
    private function mapItem(Item $item): array
    {
        $parentItemId = $item->getParentItemId();

        return [
            'item_id' => (int) $item->getItemId(),
            'order_id' => (int) $item->getOrderId(),
            'order_increment_id' => $this->stringFrom($item->getData('order_increment_id')),
            'order_status' => $this->stringFrom($item->getData('order_status')),
            'order_created_at' => $this->stringFrom($item->getData('order_created_at')),
            'parent_item_id' => $parentItemId === null ? null : (int) $parentItemId,
            'store_id' => (int) $item->getStoreId(),
            'sku' => (string) $item->getSku(),
            'name' => (string) $item->getName(),
            'product_type' => (string) $item->getProductType(),
            'qty_ordered' => (float) $item->getQtyOrdered(),
            'qty_invoiced' => (float) $item->getQtyInvoiced(),
            'qty_shipped' => (float) $item->getQtyShipped(),
            'qty_refunded' => (float) $item->getQtyRefunded(),
            'qty_canceled' => (float) $item->getQtyCanceled(),
            'price' => (float) $item->getPrice(),
            'row_total' => (float) $item->getRowTotal(),
            'row_total_incl_tax' => (float) $item->getRowTotalInclTax(),
            'tax_amount' => (float) $item->getTaxAmount(),
            'discount_amount' => (float) $item->getDiscountAmount(),
        ];
    }

    /**
     * @param array $raw
     * @phpstan-param array<int|string, mixed> $raw
     * @param array $groupBy
     * @phpstan-param list<string> $groupBy
     * @return array<string, mixed>
     */
    private function mapAggregateRow(array $raw, array $groupBy): array
    {
        $row = [];
        foreach ($groupBy as $key) {
            $row[$key] = $key === 'store_id'
                ? $this->intFrom($raw[$key] ?? null)
                : $this->stringFrom($raw[$key] ?? null);
        }
        $row['qty_ordered'] = $this->floatFrom($raw['qty_ordered'] ?? null);
        $row['row_total'] = $this->floatFrom($raw['row_total'] ?? null);
        $row['order_count'] = $this->intFrom($raw['order_count'] ?? null);
        $row['line_count'] = $this->intFrom($raw['line_count'] ?? null);

        return $row;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function stringFrom(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function intFrom(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param mixed $value
     * @return float
     */
    private function floatFrom(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
