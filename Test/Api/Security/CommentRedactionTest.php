<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Api\Security;

use Magebit\Mcp\Test\Api\McpTestCase;
use Magebit\Mcp\Test\Api\Security\AuditLogReader;
use Magebit\Mcp\Test\Api\Security\ConfigState;
use Magebit\Mcp\Test\Api\Security\InjectionPayload;
use Magebit\Mcp\Test\Api\Security\ToolPayload;

/**
 * An email inside free comment text is masked in the audit log, while the store keeps the real comment.
 *
 * @magentoApiDataFixture Magento/User/_files/user_with_role.php
 * @magentoApiDataFixture Magebit_McpOrderTools::Test/Api/_files/mcpsec_injection_order.php
 */
class CommentRedactionTest extends McpTestCase
{
    private const EMAIL = 'jane.doe@example.com';

    protected ?bool $allowWrites = true;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        ConfigState::set('magebit_mcp/general/allow_writes', '1');
        parent::setUp();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ConfigState::restoreAll();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testCommentTextWithEmailIsFingerprintedInAuditArguments(): void
    {
        $added = $this->toolsCall('sales.order.comment.add', [
            'order_increment_id' => InjectionPayload::ORDER_INCREMENT_ID,
            'text' => 'Customer wrote from ' . self::EMAIL . ' about delivery',
            'is_visible_on_front' => true,
        ]);
        $this->assertJsonRpcSuccess($added);

        $row = AuditLogReader::latest('sales.order.comment.add', $this->tokenRowId);
        self::assertNotNull($row, 'Expected an audit row for the comment.');
        $arguments = $row['arguments_json'] ?? null;
        self::assertIsString($arguments);
        self::assertStringNotContainsString(self::EMAIL, $arguments);
        self::assertMatchesRegularExpression('/\[EMAIL:[0-9a-f]{16}\]/', $arguments);

        $comments = $this->toolsCall('sales.order.comments', [
            'order_increment_id' => InjectionPayload::ORDER_INCREMENT_ID,
        ]);
        self::assertTrue(
            ToolPayload::contains(ToolPayload::decode($comments), self::EMAIL),
            'The stored comment should keep the real email; only the audit copy is masked.'
        );
    }
}
