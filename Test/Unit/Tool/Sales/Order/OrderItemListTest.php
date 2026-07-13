<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Unit\Tool\Sales\Order;

use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Model\Search\OrderItemQuery;
use Magebit\McpOrderTools\Tool\Sales\Order\OrderItemList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderItemListTest extends TestCase
{
    /**
     * @var OrderItemQuery&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private OrderItemQuery&MockObject $query;

    private OrderItemList $tool;

    protected function setUp(): void
    {
        $this->query = $this->createMock(OrderItemQuery::class);
        $this->tool = new OrderItemList($this->query);
    }

    public function testMetadata(): void
    {
        $this->assertSame('sales.order.item.list', $this->tool->getName());
        $this->assertSame(
            'Magebit_McpOrderTools::tool_sales_order_item_list',
            $this->tool->getAclResource()
        );
        $this->assertSame(WriteMode::READ, $this->tool->getWriteMode());
        $this->assertFalse($this->tool->getConfirmationRequired());
    }

    public function testSchemaExposesFiltersGroupBySortAndPaging(): void
    {
        $schema = $this->tool->getInputSchema();

        $this->assertIsArray($schema['properties']);
        foreach (['filters', 'group_by', 'sort_by', 'sort_dir', 'page', 'page_size'] as $property) {
            $this->assertArrayHasKey($property, $schema['properties']);
        }
    }

    public function testListModeReturnsItemRows(): void
    {
        $this->query->expects($this->once())
            ->method('list')
            ->with(['page' => 2])
            ->willReturn([
                'rows' => [['sku' => 'abc', 'qty_ordered' => 1.5]],
                'total_count' => 41,
                'page' => 2,
                'page_size' => 25,
            ]);
        $this->query->expects($this->never())->method('aggregate');

        $result = $this->tool->execute(['page' => 2]);
        $payload = $this->decode($result->getContent());

        $this->assertSame([['sku' => 'abc', 'qty_ordered' => 1.5]], $payload['items']);
        $this->assertSame(41, $payload['total_count']);
        $this->assertSame(25, $payload['page_size']);
        $this->assertSame(2, $payload['current_page']);

        $summary = $result->getAuditSummary();
        $this->assertSame('list', $summary['mode']);
        $this->assertSame(1, $summary['row_count']);
        $this->assertSame(41, $summary['total_count']);
    }

    public function testGroupByRoutesToAggregateMode(): void
    {
        $arguments = ['group_by' => ['sku'], 'filters' => ['order_status' => 'complete']];
        $this->query->expects($this->once())
            ->method('aggregate')
            ->with($arguments)
            ->willReturn([
                'rows' => [['sku' => 'abc', 'qty_ordered' => 5.5]],
                'group_by' => ['sku'],
                'page' => 1,
                'page_size' => 25,
            ]);
        $this->query->expects($this->never())->method('list');

        $result = $this->tool->execute($arguments);
        $payload = $this->decode($result->getContent());

        $this->assertSame([['sku' => 'abc', 'qty_ordered' => 5.5]], $payload['groups']);
        $this->assertSame(['sku'], $payload['group_by']);
        $this->assertArrayNotHasKey('total_count', $payload);

        $summary = $result->getAuditSummary();
        $this->assertSame('aggregate', $summary['mode']);
        $this->assertSame(['sku'], $summary['group_by']);
        $this->assertSame(1, $summary['row_count']);
    }

    public function testEmptyGroupByFallsBackToListMode(): void
    {
        $this->query->expects($this->once())
            ->method('list')
            ->willReturn(['rows' => [], 'total_count' => 0, 'page' => 1, 'page_size' => 25]);
        $this->query->expects($this->never())->method('aggregate');

        $this->tool->execute(['group_by' => []]);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @return array<string, mixed>
     */
    private function decode(array $content): array
    {
        $this->assertSame('text', $content[0]['type']);
        $this->assertIsString($content[0]['text']);
        $payload = json_decode($content[0]['text'], true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
