<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Unit\Model\Search;

use Magebit\Mcp\Model\Util\WebsiteStoreResolver;
use Magebit\McpOrderTools\Api\OrderItemFilterTranslatorInterface;
use Magebit\McpOrderTools\Model\Search\OrderItemQuery;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderItemQueryTest extends TestCase
{
    /**
     * @var Collection&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Collection&MockObject $collection;

    /**
     * @var Select&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Select&MockObject $select;

    /**
     * @var AdapterInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdapterInterface&MockObject $connection;

    /**
     * @var WebsiteStoreResolver&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private WebsiteStoreResolver&MockObject $websiteStoreResolver;

    /**
     * @var array<int, array{0: string, 1: mixed}>
     */
    private array $filterCalls = [];

    /**
     * @var array<int, string>
     */
    private array $orderCalls = [];

    protected function setUp(): void
    {
        $this->filterCalls = [];
        $this->orderCalls = [];

        $this->select = $this->createMock(Select::class);
        $this->select->method('join')->willReturnSelf();
        $this->select->method('reset')->willReturnSelf();
        $this->select->method('columns')->willReturnSelf();
        $this->select->method('group')->willReturnSelf();
        $this->select->method('limitPage')->willReturnSelf();
        $this->select->method('order')
            ->willReturnCallback(function (mixed $spec) {
                $this->orderCalls[] = is_string($spec) ? $spec : '';
                return $this->select;
            });

        $this->connection = $this->createMock(AdapterInterface::class);

        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('getSelect')->willReturn($this->select);
        $this->collection->method('getTable')->willReturn('sales_order');
        $this->collection->method('getConnection')->willReturn($this->connection);
        $this->collection->method('getItems')->willReturn([]);
        $this->collection->method('getSize')->willReturn(0);
        $this->collection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $condition) {
                $this->filterCalls[] = [$field, $condition];
                return $this->collection;
            });

        $this->websiteStoreResolver = $this->createMock(WebsiteStoreResolver::class);
    }

    public function testDefaultRestrictsToParentItems(): void
    {
        $this->query()->list([]);

        $this->assertContains(
            ['main_table.parent_item_id', ['null' => true]],
            $this->filterCalls,
            'Default must drop bundle/configurable child rows (parent_item_id IS NULL).'
        );
    }

    public function testParentItemsOnlyFalseKeepsChildRows(): void
    {
        $this->query()->list(['filters' => ['parent_items_only' => false]]);

        foreach ($this->filterCalls as $call) {
            $this->assertNotSame('main_table.parent_item_id', $call[0]);
        }
    }

    public function testOrderStatusScalarFiltersJoinedColumn(): void
    {
        $this->query()->list(['filters' => ['order_status' => 'complete']]);

        $this->assertContains(['so.status', ['eq' => 'complete']], $this->filterCalls);
    }

    public function testOrderStatusArrayBecomesInFilter(): void
    {
        $this->query()->list(['filters' => ['order_status' => ['complete', 'processing']]]);

        $this->assertContains(['so.status', ['in' => ['complete', 'processing']]], $this->filterCalls);
    }

    public function testOrderStateFiltersJoinedColumn(): void
    {
        $this->query()->list(['filters' => ['order_state' => 'processing']]);

        $this->assertContains(['so.state', ['eq' => 'processing']], $this->filterCalls);
    }

    public function testCreatedAtRangeFiltersOrderCreatedAt(): void
    {
        $this->query()->list([
            'filters' => [
                'created_at_from' => '2025-01-01',
                'created_at_to' => '2025-12-31',
            ],
        ]);

        $this->assertContains(['so.created_at', ['gteq' => '2025-01-01']], $this->filterCalls);
        $this->assertContains(['so.created_at', ['lteq' => '2025-12-31']], $this->filterCalls);
    }

    public function testSkuExactMatch(): void
    {
        $this->query()->list(['filters' => ['sku' => 'envelope-regular']]);

        $this->assertContains(['main_table.sku', ['eq' => 'envelope-regular']], $this->filterCalls);
    }

    public function testSkuGlobBecomesLike(): void
    {
        $this->query()->list(['filters' => ['sku' => '*location-bright*']]);

        $this->assertContains(['main_table.sku', ['like' => '%location-bright%']], $this->filterCalls);
    }

    public function testSkuGlobEscapesSqlWildcards(): void
    {
        $this->query()->list(['filters' => ['sku' => '*a_b%c*']]);

        $this->assertContains(['main_table.sku', ['like' => '%a\_b\%c%']], $this->filterCalls);
    }

    public function testSkuArrayBecomesInFilter(): void
    {
        $this->query()->list(['filters' => ['sku' => ['a', 'b']]]);

        $this->assertContains(['main_table.sku', ['in' => ['a', 'b']]], $this->filterCalls);
    }

    public function testProductTypeFilter(): void
    {
        $this->query()->list(['filters' => ['product_type' => 'configurable']]);

        $this->assertContains(['main_table.product_type', ['eq' => 'configurable']], $this->filterCalls);
    }

    public function testStoreIdFilterIsTableQualified(): void
    {
        $this->query()->list(['filters' => ['store_id' => 2]]);

        $this->assertContains(['main_table.store_id', ['eq' => 2]], $this->filterCalls);
    }

    public function testWebsiteIdExpandsToStoreIds(): void
    {
        $this->websiteStoreResolver->method('resolveStoreIds')->willReturn([1, 3]);

        $this->query()->list(['filters' => ['website_id' => 1]]);

        $this->assertContains(['main_table.store_id', ['in' => [1, 3]]], $this->filterCalls);
    }

    public function testWebsiteWithoutStoresForcesEmptyResult(): void
    {
        $this->websiteStoreResolver->method('resolveStoreIds')->willReturn([]);

        $this->query()->list(['filters' => ['website_id' => 9]]);

        $this->assertContains(['main_table.store_id', ['eq' => 0]], $this->filterCalls);
    }

    public function testUnknownFilterKeyThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('warehouse_id');

        $this->query()->list(['filters' => ['warehouse_id' => 5]]);
    }

    public function testTranslatorClaimsUnknownFilterKey(): void
    {
        $translator = $this->createMock(OrderItemFilterTranslatorInterface::class);
        $translator->method('supports')->willReturnCallback(
            static fn (string $key): bool => $key === 'warehouse_id'
        );
        $translator->expects($this->once())
            ->method('translate')
            ->with('warehouse_id', 5, $this->collection);

        $this->query([$translator])->list(['filters' => ['warehouse_id' => 5]]);
    }

    public function testPageSizeIsClamped(): void
    {
        $this->collection->expects($this->once())
            ->method('setPageSize')
            ->with(OrderItemQuery::MAX_PAGE_SIZE);

        $this->query()->list(['page_size' => 500]);
    }

    public function testDefaultSortIsOrderCreatedAtDescWithItemIdTiebreak(): void
    {
        $this->query()->list([]);

        $this->assertSame(['so.created_at DESC', 'main_table.item_id DESC'], $this->orderCalls);
    }

    public function testSortByQtyOrderedAsc(): void
    {
        $this->query()->list(['sort_by' => 'qty_ordered', 'sort_dir' => 'asc']);

        $this->assertSame(['main_table.qty_ordered ASC', 'main_table.item_id ASC'], $this->orderCalls);
    }

    public function testUnknownSortFieldThrows(): void
    {
        $this->expectException(LocalizedException::class);

        $this->query()->list(['sort_by' => 'name']);
    }

    public function testListReturnsTotalCountAndPaging(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('getSelect')->willReturn($this->select);
        $this->collection->method('getTable')->willReturn('sales_order');
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getSize')->willReturn(642);
        $this->collection->method('getItems')->willReturn([$this->itemMock()]);

        $result = $this->query()->list(['page' => 2, 'page_size' => 50]);

        $this->assertSame(642, $result['total_count']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(50, $result['page_size']);
        $this->assertSame(
            [
                'item_id' => 7,
                'order_id' => 3,
                'order_increment_id' => '100000003',
                'order_status' => 'complete',
                'order_created_at' => '2025-06-01 10:00:00',
                'parent_item_id' => null,
                'store_id' => 1,
                'sku' => 'envelope-regular-location-very_bright',
                'name' => 'Envelope',
                'product_type' => 'simple',
                'qty_ordered' => 2.0,
                'qty_invoiced' => 2.0,
                'qty_shipped' => 1.0,
                'qty_refunded' => 0.0,
                'qty_canceled' => 0.0,
                'price' => 10.0,
                'row_total' => 20.0,
                'row_total_incl_tax' => 24.2,
                'tax_amount' => 4.2,
                'discount_amount' => 0.0,
            ],
            $result['rows'][0]
        );
    }

    public function testAggregateGroupsAndCastsMeasures(): void
    {
        $capturedColumns = null;
        $this->select->method('columns')
            ->willReturnCallback(function (mixed $columns) use (&$capturedColumns) {
                $capturedColumns = $columns;
                return $this->select;
            });
        $this->select->expects($this->once())
            ->method('group')
            ->with(['main_table.sku']);
        $this->connection->method('fetchAll')->willReturn([
            [
                'sku' => 'envelope-regular-location-bright',
                'qty_ordered' => '5.0000',
                'row_total' => '100.5000',
                'order_count' => '3',
                'line_count' => '4',
            ],
        ]);

        $result = $this->query()->aggregate(['group_by' => ['sku']]);

        $this->assertIsArray($capturedColumns);
        $this->assertSame(
            ['sku', 'qty_ordered', 'row_total', 'order_count', 'line_count'],
            array_keys($capturedColumns)
        );
        $this->assertSame('SUM(main_table.qty_ordered)', (string) $capturedColumns['qty_ordered']);
        $this->assertSame('COUNT(DISTINCT main_table.order_id)', (string) $capturedColumns['order_count']);

        $this->assertSame(['sku'], $result['group_by']);
        $this->assertSame(
            [
                'sku' => 'envelope-regular-location-bright',
                'qty_ordered' => 5.0,
                'row_total' => 100.5,
                'order_count' => 3,
                'line_count' => 4,
            ],
            $result['rows'][0]
        );
    }

    public function testAggregateDefaultSortIsQtyOrderedDesc(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->query()->aggregate(['group_by' => ['sku']]);

        $this->assertSame(['qty_ordered DESC'], $this->orderCalls);
    }

    public function testAggregateSortByMeasure(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->query()->aggregate([
            'group_by' => ['sku'],
            'sort_by' => 'row_total',
            'sort_dir' => 'asc',
        ]);

        $this->assertSame(['row_total ASC'], $this->orderCalls);
    }

    public function testAggregateRejectsListOnlySortField(): void
    {
        $this->expectException(LocalizedException::class);

        $this->query()->aggregate(['group_by' => ['sku'], 'sort_by' => 'created_at']);
    }

    public function testAggregateRejectsUnknownGroupKey(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('customer_email');

        $this->query()->aggregate(['group_by' => ['customer_email']]);
    }

    public function testAggregateRequiresAtLeastOneGroupKey(): void
    {
        $this->expectException(LocalizedException::class);

        $this->query()->aggregate(['group_by' => []]);
    }

    /**
     * @param array<int, OrderItemFilterTranslatorInterface> $translators
     * @return OrderItemQuery
     */
    private function query(array $translators = []): OrderItemQuery
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Collection => $this->collection);

        return new OrderItemQuery($factory, $this->websiteStoreResolver, $translators);
    }

    /**
     * @return Item&MockObject
     */
    private function itemMock(): Item&MockObject
    {
        $item = $this->createMock(Item::class);
        $item->method('getItemId')->willReturn(7);
        $item->method('getOrderId')->willReturn(3);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getStoreId')->willReturn(1);
        $item->method('getSku')->willReturn('envelope-regular-location-very_bright');
        $item->method('getName')->willReturn('Envelope');
        $item->method('getProductType')->willReturn('simple');
        $item->method('getQtyOrdered')->willReturn(2.0);
        $item->method('getQtyInvoiced')->willReturn(2.0);
        $item->method('getQtyShipped')->willReturn(1.0);
        $item->method('getQtyRefunded')->willReturn(0.0);
        $item->method('getQtyCanceled')->willReturn(0.0);
        $item->method('getPrice')->willReturn(10.0);
        $item->method('getRowTotal')->willReturn(20.0);
        $item->method('getRowTotalInclTax')->willReturn(24.2);
        $item->method('getTaxAmount')->willReturn(4.2);
        $item->method('getDiscountAmount')->willReturn(0.0);
        $item->method('getData')->willReturnCallback(static fn (string $key): mixed => [
            'order_increment_id' => '100000003',
            'order_status' => 'complete',
            'order_created_at' => '2025-06-01 10:00:00',
        ][$key] ?? null);

        return $item;
    }
}
