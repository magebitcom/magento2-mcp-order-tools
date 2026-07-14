# Magento2 MCP - Order Tools

This is a sub-module for the [Magento2 MCP module](https://github.com/magebitcom/magento2-mcp-module)

----

Order-domain MCP tools for `Magebit_Mcp`. Reads and writes against sales
orders, invoices, shipments, credit memos, comments, and payment records.

Each tool is a thin wrapper over a Magento service contract
(`OrderRepositoryInterface`, `InvoiceOrderInterface`, etc.) and composes its
response from field resolvers that 3rd-party modules can extend.

## Install

```bash
composer require magebitcom/magento2-mcp-order-tools
bin/magento module:enable Magebit_McpOrderTools
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Ships with `Magebit_Mcp` as its only Magebit dependency. If you only want the
base MCP transport (no order tools), install `Magebit_Mcp` alone; this
module is designed to be optional.

## Tool catalog

### Read tools

| Tool | What it does |
|---|---|
| `sales.order.list` | Paginated order search; filter by status, state, date range, grand-total range, customer email, increment id, store id, website id. Line items are opt-in per row via `fields: ["items", ...]`. |
| `sales.order.get` | Single order by entity id or increment id with full detail (identity, state, customer, addresses, items, totals, payment, timestamps). |
| `sales.order.item.list` | Bulk order-line-item search across orders; filter by order status/state, date range, SKU (exact, `*glob*`, or array), product type, store id, website id. Optional `group_by` aggregate mode returns `SUM(qty_ordered)` / `SUM(row_total)` / order count per group — answers "units sold per SKU" questions in one call. |
| `sales.order.invoices` | Every invoice on an order. |
| `sales.order.invoice.get` | One invoice by id or increment id. |
| `sales.order.shipments` | Every shipment on an order. |
| `sales.order.shipment.get` | One shipment + its tracking records. |
| `sales.order.payment` | Payment record + transaction history for an order. |
| `sales.order.comments` | Status-history comments on an order, optionally narrowed to customer-visible entries. |
| `sales.order.credit_memos` | Every credit memo on an order. |
| `sales.order.credit_memo.get` | One credit memo by id or increment id. |

### Write tools

All writes require the global `magebit_mcp/general/allow_writes` flag **and**
the token's own `allow_writes` flag to be `1`. Destructive operations
additionally set the `requires_confirmation` hint so MCP clients (Claude
Desktop, etc.) prompt before firing.

| Tool | Confirm? | Delegates to |
|---|---|---|
| `sales.order.invoice.create` | yes | `InvoiceOrderInterface::execute()` |
| `sales.order.shipment.create` | yes | `ShipOrderInterface::execute()` |
| `sales.order.shipment.track.add` | no | `ShipmentTrackRepositoryInterface::save()` |
| `sales.order.credit_memo.create` | yes | `RefundOrderInterface::execute()` + optional online refund |
| `sales.order.cancel` | yes | `OrderManagementInterface::cancel()` |
| `sales.order.hold` | yes | `OrderManagementInterface::hold()` |
| `sales.order.unhold` | no | `OrderManagementInterface::unHold()` |
| `sales.order.comment.add` | no | `OrderManagementInterface::addComment()` |

Every write tool also implements `Magebit\Mcp\Api\UnderlyingAclAwareInterface`
so the handler blocks calls from admins who wouldn't be allowed to perform
the same action in the admin UI.

## Extending

See `docs/EXTENDING.md` for:
- adding a new field to any tool response via `*FieldResolverInterface`;
- adding a new filter to `sales.order.list` via `OrderFilterTranslatorInterface`;
- adding a new filter to `sales.order.item.list` via `OrderItemFilterTranslatorInterface`;
- the ACL layering rules for custom write tools;
- PII redactor configuration for PSP-specific payment fields.

## License

Released under the [MIT License](LICENSE).

---

[![Magebit - Full-service e-commerce agency](https://github.com/user-attachments/assets/cdc904ce-e839-40a0-a86f-792f7ab7961f)](https://magebit.com/)

*Have questions or need help? Contact us at info@magebit.com*

