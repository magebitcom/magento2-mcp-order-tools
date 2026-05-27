<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Unit\Model\Payment;

use Magebit\McpOrderTools\Model\Payment\AdditionalInformationFilter;
use PHPUnit\Framework\TestCase;

class AdditionalInformationFilterTest extends TestCase
{
    public function testEmptyAllowlistDropsEverything(): void
    {
        $filter = new AdditionalInformationFilter();

        $this->assertSame([], $filter->filter([
            'method_title' => 'Check / Money order',
            'SECRET_PSP_TOKEN' => 'pi_should_never_leak',
            'pspReference' => 'tok_should_never_leak',
        ]));
    }

    public function testOnlyAllowlistedKeysSurvive(): void
    {
        $filter = new AdditionalInformationFilter(['method_title']);

        $this->assertSame(
            ['method_title' => 'Check / Money order'],
            $filter->filter([
                'method_title' => 'Check / Money order',
                'SECRET_PSP_TOKEN' => 'pi_should_never_leak',
            ])
        );
    }

    public function testMissingAllowlistedKeyIsOmittedNotNull(): void
    {
        $filter = new AdditionalInformationFilter(['method_title', 'not_present']);

        $this->assertSame(
            ['method_title' => 'Check / Money order'],
            $filter->filter(['method_title' => 'Check / Money order'])
        );
    }
}
