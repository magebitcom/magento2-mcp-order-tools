# Extending `Magebit_McpOrderTools`

Every read tool in this module composes its response from a DI-injected array
of **field resolvers**. Each resolver owns one named slice of the output; 3rd
parties add, replace, or remove slices from their own `etc/di.xml` without
touching this module.

The same pattern applies verbatim to invoices, shipments, credit memos, and
order-status-history comments — each with its own entity-scoped interface.

## Add a new field to `sales.order.get` / `sales.order.list`

### 1. Implement `OrderFieldResolverInterface`

```php
<?php
declare(strict_types=1);

namespace Vendor\GiftWrap\Mcp\Resolver;

use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;

final class GiftWrapResolver implements OrderFieldResolverInterface
{
    public function getKey(): string
    {
        return 'gift_wrap';
    }

    public function getSortOrder(): int
    {
        // 100 = default; 50 renders earlier than built-ins, 150 later.
        return 120;
    }

    public function resolve(OrderInterface $order, array $args): array
    {
        return [
            'is_gift_wrapped' => (bool) $order->getData('gw_id'),
            'wrap_price' => (float) $order->getData('gw_price'),
        ];
    }
}
```

### 2. Register the resolver in `etc/di.xml`

```xml
<type name="Magebit\McpOrderTools\Tool\Sales\Order\OrderGet">
    <arguments>
        <argument name="fieldResolvers" xsi:type="array">
            <item name="gift_wrap" xsi:type="object">
                Vendor\GiftWrap\Mcp\Resolver\GiftWrapResolver
            </item>
        </argument>
    </arguments>
</type>

<!-- Optional: also surface it on list rows. -->
<type name="Magebit\McpOrderTools\Tool\Sales\Order\OrderList">
    <arguments>
        <argument name="fieldResolvers" xsi:type="array">
            <item name="gift_wrap" xsi:type="object">
                Vendor\GiftWrap\Mcp\Resolver\GiftWrapResolver
            </item>
        </argument>
    </arguments>
</type>
```

DI-array items are merged across modules, so Magebit-shipped resolvers stay
in place — you only pay for what you add.

### 3. Run `bin/magento setup:upgrade && setup:di:compile`

No other changes needed. The next `sales.order.get` call will include a new
`gift_wrap` key; MCP clients that support `tools/list` introspection see the
key materialize on next refresh.

### Opt-out from a caller

The two arguments every read tool accepts:

- `fields: ["identity", "totals"]` — only render those keys.
- `exclude: ["items"]` — everything except those keys.

Useful when an LLM over-fetches or when a specific slice is expensive.

## Add a new filter to `sales.order.list`

Built-in filters cover every `OrderInterface` column. For custom attributes
or cross-table joins, implement `OrderFilterTranslatorInterface` and
register it:

```php
<?php
declare(strict_types=1);

namespace Vendor\Loyalty\Mcp\Filter;

use Magebit\McpOrderTools\Api\OrderFilterTranslatorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

final class LoyaltyMinPointsTranslator implements OrderFilterTranslatorInterface
{
    public function supports(string $key): bool
    {
        return $key === 'loyalty_points_min';
    }

    public function translate(string $key, mixed $value, SearchCriteriaBuilder $builder): void
    {
        // Magento\Framework\Api\SearchCriteriaBuilder supports arbitrary
        // column filters; a join would be added via a plugin on the
        // SearchResult collection or an extension attribute.
        $builder->addFilter('loyalty_points', (int) $value, 'gteq');
    }
}
```

```xml
<type name="Magebit\McpOrderTools\Model\Search\OrderSearchCriteriaBuilder">
    <arguments>
        <argument name="filterTranslators" xsi:type="array">
            <item name="loyalty_points_min" xsi:type="object">
                Vendor\Loyalty\Mcp\Filter\LoyaltyMinPointsTranslator
            </item>
        </argument>
    </arguments>
</type>
```

Unsupported filter keys fail fast with `INVALID_PARAMS` rather than silently
ignoring — your translator must claim the key via `supports()` before the
built-in dispatch falls through.

## Per-entity resolver interfaces

Everything above applies symmetrically to each entity with its own
interface:

| Entity | Interface | Built-in tool uses it |
|---|---|---|
| Order | `OrderFieldResolverInterface` | `sales.order.get`, `sales.order.list` |
| Invoice | `InvoiceFieldResolverInterface` | `sales.order.invoice.get`, `sales.order.invoices` |
| Shipment | `ShipmentFieldResolverInterface` | `sales.order.shipment.get`, `sales.order.shipments` |
| Credit memo | `CreditMemoFieldResolverInterface` | `sales.order.credit_memo.get`, `sales.order.credit_memos` |
| Comment | `OrderCommentFieldResolverInterface` | `sales.order.comments` |

All five extend the base module's `Magebit\Mcp\Api\FieldResolverInterface`
marker, so the same `ResolverPipeline` in `Magebit_Mcp` sorts + filters them
for every tool.

## Write-tool ACL layering

Every write tool in this module implements both `ToolInterface` and
`Magebit\Mcp\Api\UnderlyingAclAwareInterface`. The handler enforces two ACL
checks per call:

1. The tool's own MCP-scoped resource — e.g. `Magebit_McpOrderTools::tool_sales_order_invoice_create`.
2. The underlying Magento resource returned by `getUnderlyingAclResource()` — e.g. `Magento_Sales::invoice`.

Both must pass. This preserves "MCP cannot do what the admin UI cannot" — an
MCP-only role denied `Magento_Sales::invoice` cannot invoice via MCP, even
if you granted the MCP-specific resource by accident.

Custom write tools SHOULD implement `UnderlyingAclAwareInterface` any time
they delegate to a Magento service contract that has an established admin
ACL.

## Redaction of PII in audit logs

Argument and result payloads flow through `Magebit_Mcp`'s PII redactor
before being written to the audit log. By default the redactor fingerprints
any field whose name matches `email|telephone|phone|password|street|postcode|card|token|authorization|cookie|ssn|tax_id` (case-insensitive substring).

For PSP-specific payment info (e.g. Stripe `pi_*` references, Adyen PSP
tokens), add the field to the redactor's allowlist:

```xml
<type name="Magebit\Mcp\Model\AuditLog\PiiRedactor">
    <arguments>
        <argument name="additionalSensitiveKeys" xsi:type="array">
            <item name="stripe_payment_intent" xsi:type="string">payment_intent</item>
            <item name="adyen_psp" xsi:type="string">pspReference</item>
        </argument>
    </arguments>
</type>
```
