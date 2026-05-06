<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Unit\Model\Search;

use Magebit\Mcp\Model\Util\WebsiteStoreResolver;
use Magebit\McpOrderTools\Api\OrderFilterTranslatorInterface;
use Magebit\McpOrderTools\Model\Search\OrderSearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderSearchCriteriaBuilderTest extends TestCase
{
    /**
     * @var SearchCriteriaBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private SearchCriteriaBuilder&MockObject $criteriaBuilder;

    /**
     * @var SortOrderBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private SortOrderBuilder&MockObject $sortBuilder;

    /**
     * @var SortOrder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private SortOrder&MockObject $sortOrder;

    /**
     * @var WebsiteStoreResolver&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private WebsiteStoreResolver&MockObject $websiteStoreResolver;

    protected function setUp(): void
    {
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sortBuilder = $this->createMock(SortOrderBuilder::class);
        $this->sortOrder = $this->createMock(SortOrder::class);
        $this->websiteStoreResolver = $this->createMock(WebsiteStoreResolver::class);
        $this->sortBuilder->method('setField')->willReturnSelf();
        $this->sortBuilder->method('setDirection')->willReturnSelf();
        $this->sortBuilder->method('create')->willReturn($this->sortOrder);

        $this->criteriaBuilder->method('addFilter')->willReturnSelf();
        $this->criteriaBuilder->method('addSortOrder')->willReturnSelf();
        $this->criteriaBuilder->method('setCurrentPage')->willReturnSelf();
        $this->criteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->criteriaBuilder->method('create')
            ->willReturn($this->createStub(SearchCriteriaInterface::class));
    }

    public function testDefaultSortIsCreatedAtDesc(): void
    {
        $this->sortBuilder->expects($this->once())->method('setField')->with('created_at');
        $this->sortBuilder->expects($this->once())->method('setDirection')->with(SortOrder::SORT_DESC);

        $this->builder()->build([]);
    }

    public function testExactStatusAddsEqualsFilter(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with($this->equalTo('status'), $this->equalTo('processing'));

        $this->builder()->build(['filters' => ['status' => 'processing']]);
    }

    public function testArrayStatusAddsInFilter(): void
    {
        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('status', $this->equalTo(['processing', 'complete']), 'in');

        $this->builder()->build(['filters' => ['status' => ['processing', 'complete']]]);
    }

    public function testCreatedAtRangeSplitsIntoTwoFilters(): void
    {
        $calls = [];
        $this->criteriaBuilder->expects($this->atLeast(2))
            ->method('addFilter')
            ->willReturnCallback(function (string $field, mixed $value, ?string $cond = null) use (&$calls) {
                $calls[] = [$field, $value, $cond];
                return $this->criteriaBuilder;
            });

        $this->builder()->build([
            'filters' => [
                'created_at_from' => '2025-01-01',
                'created_at_to' => '2025-12-31',
            ],
        ]);

        $this->assertContains(['created_at', '2025-01-01', 'gteq'], $calls);
        $this->assertContains(['created_at', '2025-12-31', 'lteq'], $calls);
    }

    public function testPageSizeCapsAtMax(): void
    {
        $this->criteriaBuilder->expects($this->once())
            ->method('setPageSize')
            ->with(OrderSearchCriteriaBuilder::MAX_PAGE_SIZE);

        $this->builder()->build(['page_size' => 500]);
    }

    public function testPageDefaultsToOne(): void
    {
        $this->criteriaBuilder->expects($this->once())
            ->method('setCurrentPage')
            ->with(1);

        $this->builder()->build([]);
    }

    public function testUnknownFilterThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Unknown order filter: "wat"/');

        $this->builder()->build(['filters' => ['wat' => 'lol']]);
    }

    public function testTranslatorClaimsCustomKey(): void
    {
        $translator = $this->createMock(OrderFilterTranslatorInterface::class);
        $translator->method('supports')->willReturnCallback(fn(string $k): bool => $k === 'custom_attr');
        $translator->expects($this->once())
            ->method('translate')
            ->with('custom_attr', 'val', $this->criteriaBuilder);

        $sut = new OrderSearchCriteriaBuilder(
            $this->criteriaBuilder,
            $this->sortBuilder,
            $this->websiteStoreResolver,
            [$translator]
        );

        $sut->build(['filters' => ['custom_attr' => 'val']]);
    }

    public function testWebsiteIdExpandsToStoreIdInFilter(): void
    {
        $this->websiteStoreResolver->expects($this->once())
            ->method('resolveStoreIds')
            ->with(1)
            ->willReturn([1, 2]);

        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('store_id', $this->equalTo([1, 2]), 'in');

        $this->builder()->build(['filters' => ['website_id' => 1]]);
    }

    public function testWebsiteIdWithNoStoresForcesZeroRows(): void
    {
        $this->websiteStoreResolver->method('resolveStoreIds')->willReturn([]);

        $this->criteriaBuilder->expects($this->atLeastOnce())
            ->method('addFilter')
            ->with('store_id', 0);

        $this->builder()->build(['filters' => ['website_id' => 42]]);
    }

    public function testInvalidSortFieldThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"sort_by" must be one of/');

        $this->builder()->build(['sort_by' => 'not_a_real_field']);
    }

    public function testInvalidSortDirThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"sort_dir" must be/');

        $this->builder()->build(['sort_dir' => 'sideways']);
    }

    public function testFiltersMustBeArray(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Filter payload must be an object/');

        $this->builder()->build(['filters' => 'status=complete']);
    }

    private function builder(): OrderSearchCriteriaBuilder
    {
        return new OrderSearchCriteriaBuilder(
            $this->criteriaBuilder,
            $this->sortBuilder,
            $this->websiteStoreResolver
        );
    }
}
