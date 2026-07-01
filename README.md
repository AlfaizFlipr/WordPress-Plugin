# Courier Connector — WooCommerce + Delhivery

A working, Shiprocket-style courier management system delivered as **two WordPress plugins**:

| Plugin | Folder | Install on | Role |
|--------|--------|-----------|------|
| **Courier Connector** (central) | `courier-connector/` | Your dashboard WP site | The aggregation dashboard. Talks to Delhivery, stores orders, creates shipments, generates AWB, tracks, pushes AWB/tracking back to client stores. |
| **Courier Connector — Client** | `courier-connector-client/` | Each client WooCommerce store | Connects to the dashboard, auto-syncs orders, receives AWB + tracking back. |

> Everything runs **inside WordPress plugins** — the dashboard is a native wp-admin
> area under the **Courier** menu. No separate server required. (If you later want a
> standalone multi-tenant SaaS UI, the central plugin's REST API is already the
> integration contract for it.)

---

## What works end-to-end

✅ Client WooCommerce stores connect via a single "Connect Store" action
✅ Orders auto-sync to the central dashboard (real-time WooCommerce hooks + bulk sync)
✅ "Push to Delhivery" creates the shipment and generates an AWB
✅ AWB & tracking sync back to the client store automatically
✅ Clients/admin manage shipments from the dashboard (filters, detail, label, track)
✅ Stat cards: Total, Pending, Booked, In-Transit, Delivered
✅ 15-minute cron polls Delhivery and updates statuses + reverse-syncs

---

## Install

1. Zip each folder (`courier-connector` and `courier-connector-client`) — or copy
   them into `wp-content/plugins/`.
2. **Dashboard site:** activate **Courier Connector**. Requires WooCommerce.
3. **Each client store:** activate **Courier Connector — Client**. Requires WooCommerce.

### Configure the dashboard
`WP Admin → Courier → Settings`:
- Delhivery **API Token** (from the Delhivery seller panel)
- **Pickup address**, then click **Register / Update Warehouse** (the pickup *name*
  must match a Delhivery-registered warehouse)
- Default package weight/dimensions

### Connect a client store
On the client store: `WooCommerce → Courier Connector` → enter the dashboard URL →
**Connect Store**. The connector auto-registers and pulls its API key. Use
**Sync recent orders** to backfill existing orders.

---

## Order flow

```
Customer places order on client store
        │  woocommerce_new_order
        ▼
Client plugin POSTs order → /wp-json/courier/v1/orders   (X-CC-Api-Key)
        ▼
Dashboard stores order (tagged with source store)
        ▼
Admin clicks "Push to Delhivery"
        ▼
Dashboard → Delhivery CMU create  → AWB generated
        ▼
Dashboard POSTs AWB back → client /wp-json/courier/v1/update-awb
        ▼
Client order shows AWB + Track button
        ▼
Cron (15 min) polls Delhivery → status changes
        ▼
Dashboard updates + POSTs → client /wp-json/courier/v1/update-tracking
```

---

## REST API (central plugin) — `courier/v1`

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| POST | `/connect-store` | open | Register a store, returns `api_key` |
| GET  | `/ping` | `X-CC-Api-Key` | Verify connection |
| POST | `/orders` | `X-CC-Api-Key` | Import an order |
| PUT  | `/orders/{external_id}` | `X-CC-Api-Key` | Update order status |

Reverse callbacks the dashboard sends to client stores (`courier/v1` on the client):
`/update-awb` and `/update-tracking`.

---

## Delhivery endpoints used (`includes/class-cc-delhivery-api.php`)

- `GET /waybill/api/bulk/json/` — pre-allocate AWB
- `POST /api/backend/clientwarehouse/create/` — register pickup
- `POST /api/cmu/create.json` — create shipment (form-encoded `format=json&data=…`)
- `GET /api/v1/packages/json/?waybill=` — track
- `GET /api/p/packing_slip` — label / packing slip
- `POST /api/p/edit` — cancel

Switch **Production/Staging** under Settings (staging base:
`https://staging-express.delhivery.com`).

---

## Notes & next steps

- HPOS (High-Performance Order Storage) compatible.
- All API calls are logged under **Courier → Logs** for debugging.
- Phase 2 ideas already scaffolded by the architecture: wallet/billing, pickup
  requests (`create_pickup()` is implemented), rate calculator, and additional
  couriers (Xpressbees/DTDC/Blue Dart) by adding sibling API client classes and a
  courier-selection layer.
- Field names in `CC_Order::to_delhivery_shipment()` map to Delhivery's CMU schema;
  confirm exact keys against your account's API doc version before going live.
