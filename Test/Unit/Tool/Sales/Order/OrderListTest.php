<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Unit\Tool\Sales\Order;

use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magebit\McpOrderTools\Model\FieldResolver\Order\OnDemandResolver;
use Magebit\McpOrderTools\Model\Search\OrderSearchCriteriaBuilder;
use Magebit\McpOrderTools\Tool\Sales\Order\OrderList;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderListTest extends TestCase
{
    /**
     * @var ResolverPipeline&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ResolverPipeline&MockObject $pipeline;

    /**
     * @var OrderFieldResolverInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private OrderFieldResolverInterface&MockObject $identityResolver;

    private OnDemandResolver $onDemandItems;

    protected function setUp(): void
    {
        $this->pipeline = $this->createMock(ResolverPipeline::class);

        $this->identityResolver = $this->createMock(OrderFieldResolverInterface::class);
        $this->identityResolver->method('getKey')->willReturn('identity');

        $items = $this->createMock(OrderFieldResolverInterface::class);
        $items->method('getKey')->willReturn('items');
        $this->onDemandItems = new OnDemandResolver($items);
    }

    public function testSchemaDeclaresTypedFilterKeys(): void
    {
        $schema = $this->tool()->getInputSchema();
        $this->assertIsArray($schema['properties']);
        $filters = $schema['properties']['filters'];
        $this->assertIsArray($filters);
        $this->assertTrue($filters['additionalProperties']);
        $props = $filters['properties'];
        $this->assertIsArray($props);

        $expected = [
            'status' => ['string', 'array'],
            'state' => ['string', 'array'],
            'increment_id' => ['string', 'array'],
            'customer_email' => ['string', 'array'],
            'store_id' => ['integer', 'array'],
            'website_id' => ['integer', 'array'],
            'created_at_from' => 'string',
            'created_at_to' => 'string',
            'grand_total_min' => 'number',
            'grand_total_max' => 'number',
        ];
        $this->assertSame(array_keys($expected), array_keys($props));
        foreach ($expected as $key => $type) {
            $prop = $props[$key];
            $this->assertIsArray($prop);
            $this->assertSame($type, $prop['type'], $key);
            $this->assertNotEmpty($prop['description'], $key);
        }
    }

    public function testOnDemandResolverSkippedByDefault(): void
    {
        $this->pipeline->expects($this->once())
            ->method('plan')
            ->with([$this->identityResolver], [])
            ->willReturn([]);

        $this->tool()->execute([]);
    }

    public function testOnDemandResolverSkippedWhenFieldsOmitIt(): void
    {
        $arguments = ['fields' => ['identity']];
        $this->pipeline->expects($this->once())
            ->method('plan')
            ->with([$this->identityResolver], $arguments)
            ->willReturn([]);

        $this->tool()->execute($arguments);
    }

    public function testOnDemandResolverIncludedWhenExplicitlyRequested(): void
    {
        $arguments = ['fields' => ['identity', 'items']];
        $this->pipeline->expects($this->once())
            ->method('plan')
            ->with([$this->identityResolver, $this->onDemandItems], $arguments)
            ->willReturn([]);

        $this->tool()->execute($arguments);
    }

    /**
     * @return OrderList
     */
    private function tool(): OrderList
    {
        $criteria = $this->createStub(SearchCriteriaInterface::class);
        $criteria->method('getPageSize')->willReturn(25);
        $criteria->method('getCurrentPage')->willReturn(1);

        $searchBuilder = $this->createMock(OrderSearchCriteriaBuilder::class);
        $searchBuilder->method('build')->willReturn($criteria);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([]);
        $searchResult->method('getTotalCount')->willReturn(0);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($searchResult);

        return new OrderList(
            $orderRepository,
            $searchBuilder,
            $this->pipeline,
            [$this->identityResolver, $this->onDemandItems]
        );
    }
}
