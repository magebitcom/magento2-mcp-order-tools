<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\Search;

use Magebit\Mcp\Model\Util\WebsiteStoreResolver;
use Magebit\McpOrderTools\Api\OrderFilterTranslatorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Translates the MCP `sales.order.list` filter schema into a
 * {@see SearchCriteriaInterface}. Built-in filter keys are handled inline;
 * unrecognised keys are dispatched to the injected
 * {@see OrderFilterTranslatorInterface[]} array, then rejected with
 * {@see LocalizedException} if no translator claims them. Paging is clamped
 * at {@see self::MAX_PAGE_SIZE} and sort fields are restricted to the
 * {@see self::SORTABLE_FIELDS} whitelist.
 */
class OrderSearchCriteriaBuilder
{
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 25;

    /** @var array<int, string> Ordered for default sort preference. */
    public const SORTABLE_FIELDS = [
        OrderInterface::CREATED_AT,
        OrderInterface::UPDATED_AT,
        OrderInterface::ENTITY_ID,
        OrderInterface::INCREMENT_ID,
        OrderInterface::GRAND_TOTAL,
        OrderInterface::STATUS,
    ];

    /**
     * @param SearchCriteriaBuilder $criteriaBuilder
     * @param SortOrderBuilder $sortBuilder
     * @param WebsiteStoreResolver $websiteStoreResolver
     * @param OrderFilterTranslatorInterface[] $filterTranslators
     */
    public function __construct(
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly SortOrderBuilder $sortBuilder,
        private readonly WebsiteStoreResolver $websiteStoreResolver,
        private readonly array $filterTranslators = []
    ) {
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return SearchCriteriaInterface
     * @throws LocalizedException
     */
    public function build(array $args): SearchCriteriaInterface
    {
        $filtersRaw = $args['filters'] ?? [];
        if (!is_array($filtersRaw)) {
            throw new LocalizedException(__('Filter payload must be an object.'));
        }

        foreach ($filtersRaw as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new LocalizedException(__('Filter keys must be non-empty strings.'));
            }
            $this->applyFilter($key, $value);
        }

        $this->applySort($args);
        $this->applyPaging($args);

        return $this->criteriaBuilder->create();
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function applyFilter(string $key, mixed $value): void
    {
        switch ($key) {
            case 'status':
            case 'state':
            case OrderInterface::INCREMENT_ID:
            case OrderInterface::STORE_ID:
                $this->addEqualsOrIn($key, $value);
                return;

            case 'website_id':
                $storeIds = $this->websiteStoreResolver->resolveStoreIds($value);
                if ($storeIds === []) {
                    // No stores under that website — force a zero-row result.
                    $this->criteriaBuilder->addFilter(OrderInterface::STORE_ID, 0);
                    return;
                }
                $this->criteriaBuilder->addFilter(OrderInterface::STORE_ID, $storeIds, 'in');
                return;

            case OrderInterface::CUSTOMER_EMAIL:
                $this->addEqualsOrIn(OrderInterface::CUSTOMER_EMAIL, $value);
                return;

            case 'created_at_from':
                $this->addRangeBoundary(OrderInterface::CREATED_AT, 'gteq', $value);
                return;
            case 'created_at_to':
                $this->addRangeBoundary(OrderInterface::CREATED_AT, 'lteq', $value);
                return;

            case 'grand_total_min':
                $this->addRangeBoundary(OrderInterface::GRAND_TOTAL, 'gteq', $value);
                return;
            case 'grand_total_max':
                $this->addRangeBoundary(OrderInterface::GRAND_TOTAL, 'lteq', $value);
                return;
        }

        foreach ($this->filterTranslators as $translator) {
            if ($translator->supports($key)) {
                $translator->translate($key, $value, $this->criteriaBuilder);
                return;
            }
        }

        throw new LocalizedException(__('Unknown order filter: "%1".', $key));
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addEqualsOrIn(string $field, mixed $value): void
    {
        if (is_array($value)) {
            $list = array_values(array_filter(
                $value,
                static fn($v): bool => is_scalar($v) && (string) $v !== ''
            ));
            if ($list === []) {
                return;
            }
            $this->criteriaBuilder->addFilter($field, $list, 'in');
            return;
        }
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Filter "%1" requires a non-empty value.', $field));
        }
        $this->criteriaBuilder->addFilter($field, $value);
    }

    /**
     * @param string $field
     * @param string $condition
     * @param mixed $value
     * @return void
     * @throws LocalizedException
     */
    private function addRangeBoundary(string $field, string $condition, mixed $value): void
    {
        if (!is_scalar($value) || (string) $value === '') {
            throw new LocalizedException(__('Range boundary for "%1" must be scalar.', $field));
        }
        $this->criteriaBuilder->addFilter($field, (string) $value, $condition);
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return void
     * @throws LocalizedException
     */
    private function applySort(array $args): void
    {
        $sortBy = $args['sort_by'] ?? OrderInterface::CREATED_AT;
        if (!is_string($sortBy) || $sortBy === '') {
            throw new LocalizedException(__('"sort_by" must be a non-empty string.'));
        }
        if (!in_array($sortBy, self::SORTABLE_FIELDS, true)) {
            throw new LocalizedException(__(
                '"sort_by" must be one of: %1.',
                implode(', ', self::SORTABLE_FIELDS)
            ));
        }

        $dirRaw = $args['sort_dir'] ?? 'desc';
        $dir = is_string($dirRaw) ? strtolower($dirRaw) : 'desc';
        if ($dir !== 'asc' && $dir !== 'desc') {
            throw new LocalizedException(__('"sort_dir" must be "asc" or "desc".'));
        }

        $this->sortBuilder->setField($sortBy);
        $this->sortBuilder->setDirection($dir === 'asc' ? SortOrder::SORT_ASC : SortOrder::SORT_DESC);
        $this->criteriaBuilder->addSortOrder($this->sortBuilder->create());
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return void
     * @throws LocalizedException
     */
    private function applyPaging(array $args): void
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

        $this->criteriaBuilder->setCurrentPage($page);
        $this->criteriaBuilder->setPageSize($size);
    }
}
