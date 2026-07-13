<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Unit\Model\FieldResolver\Order;

use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magebit\McpOrderTools\Model\FieldResolver\Order\OnDemandResolver;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OnDemandResolverTest extends TestCase
{
    /**
     * @var OrderFieldResolverInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private OrderFieldResolverInterface&MockObject $inner;

    private OnDemandResolver $resolver;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(OrderFieldResolverInterface::class);
        $this->inner->method('getKey')->willReturn('items');
        $this->inner->method('getSortOrder')->willReturn(50);
        $this->resolver = new OnDemandResolver($this->inner);
    }

    public function testDelegatesKeyAndSortOrder(): void
    {
        $this->assertSame('items', $this->resolver->getKey());
        $this->assertSame(50, $this->resolver->getSortOrder());
    }

    public function testDelegatesResolve(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $rows = [['sku' => 'foo']];
        $this->inner->expects($this->once())
            ->method('resolve')
            ->with($order, ['fields' => ['items']])
            ->willReturn($rows);

        $this->assertSame($rows, $this->resolver->resolve($order, ['fields' => ['items']]));
    }

    public function testNotRequestedWithoutFields(): void
    {
        $this->assertFalse($this->resolver->isRequested([]));
    }

    public function testNotRequestedWhenFieldsOmitKey(): void
    {
        $this->assertFalse($this->resolver->isRequested(['fields' => ['identity', 'totals']]));
    }

    public function testRequestedWhenFieldsContainKey(): void
    {
        $this->assertTrue($this->resolver->isRequested(['fields' => ['identity', 'items']]));
    }

    public function testRequestedWithScalarFieldsValue(): void
    {
        $this->assertTrue($this->resolver->isRequested(['fields' => 'items']));
    }

    public function testNotRequestedWithNonStringFieldsValue(): void
    {
        $this->assertFalse($this->resolver->isRequested(['fields' => 42]));
    }
}
