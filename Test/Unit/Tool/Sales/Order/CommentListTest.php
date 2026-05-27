<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Unit\Tool\Sales\Order;

use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magebit\McpOrderTools\Tool\Sales\Order\CommentList;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\Data\OrderStatusHistorySearchResultInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CommentListTest extends TestCase
{
    /**
     * @var SearchCriteriaBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private SearchCriteriaBuilder&MockObject $criteriaBuilder;

    /**
     * @var array<int, array{0: string, 1: mixed}>
     */
    private array $filterCalls = [];

    protected function setUp(): void
    {
        $this->filterCalls = [];
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->criteriaBuilder->method('addFilter')
            ->willReturnCallback(function (string $field, mixed $value) {
                $this->filterCalls[] = [$field, $value];
                return $this->criteriaBuilder;
            });
        $this->criteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->criteriaBuilder->method('setCurrentPage')->willReturnSelf();
        $this->criteriaBuilder->method('create')
            ->willReturn($this->createStub(SearchCriteriaInterface::class));
    }

    public function testDefaultRestrictsToCustomerVisible(): void
    {
        $this->execute([]);

        $this->assertContains(
            [OrderStatusHistoryInterface::IS_VISIBLE_ON_FRONT, 1],
            $this->filterCalls,
            'Default must filter to is_visible_on_front = 1 (customer-visible only).'
        );
    }

    public function testExplicitInternalRequestReturnsHiddenNotes(): void
    {
        $this->execute(['is_visible_on_front' => false]);

        $this->assertContains(
            [OrderStatusHistoryInterface::IS_VISIBLE_ON_FRONT, 0],
            $this->filterCalls
        );
    }

    public function testExplicitVisibleRequestStillRestricts(): void
    {
        $this->execute(['is_visible_on_front' => true]);

        $this->assertContains(
            [OrderStatusHistoryInterface::IS_VISIBLE_ON_FRONT, 1],
            $this->filterCalls
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return void
     */
    private function execute(array $arguments): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(119);

        $entityFinder = $this->createMock(EntityFinder::class);
        $entityFinder->method('orderFrom')->willReturn($order);

        $searchResult = $this->createMock(OrderStatusHistorySearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([]);
        $searchResult->method('getTotalCount')->willReturn(0);

        $historyRepository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $historyRepository->method('getList')->willReturn($searchResult);

        $pipeline = $this->createMock(ResolverPipeline::class);
        $pipeline->method('plan')->willReturn([]);

        $tool = new CommentList(
            $entityFinder,
            $historyRepository,
            $this->criteriaBuilder,
            $pipeline,
            []
        );

        $tool->execute($arguments);
    }
}
