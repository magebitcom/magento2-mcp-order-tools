<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Api\Security;

use Magebit\Mcp\Test\Api\McpTestCase;
use Magebit\Mcp\Test\Api\Security\InjectionPayload;
use Magebit\Mcp\Test\Api\Security\ToolPayload;

/**
 * A read tool returns stored prompt injection text as plain data; the server does not act on it.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 * @magentoApiDataFixture Magebit_McpOrderTools::Test/Api/_files/mcpsec_injection_order.php
 */
class InjectionReadBackTest extends McpTestCase
{
    /**
     * @return void
     */
    public function testReadToolReturnsInjectionTextAsData(): void
    {
        $response = $this->toolsCall('sales.order.comments', ['order_increment_id' => InjectionPayload::ORDER_INCREMENT_ID]);

        $this->assertJsonRpcSuccess($response);
        self::assertTrue(
            ToolPayload::contains(ToolPayload::decode($response), InjectionPayload::TEXT),
            'Expected the stored injection text in the tool output.'
        );
    }
}
