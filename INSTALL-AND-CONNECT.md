# Naya Setu Courier — Install & Connect Guide

You have **two ready-to-install ZIP files** in the `dist/` folder. You never copy
code folders by hand — you upload these ZIPs through the WordPress admin.

| File | Install on | What it is |
|------|-----------|------------|
| `dist/naya-setu-courier-dashboard.zip` | Your **central dashboard** WordPress site | The control panel: orders, shipments, Delhivery, AWB, tracking, stores, settings + landing page shortcode. |
| `dist/naya-setu-courier-connector.zip` | **Each client** WooCommerce store | The connector that syncs orders to the dashboard and receives AWB/tracking back. |

> Requirement on every site: **WordPress + WooCommerce active**, PHP 7.4+.
> (Install PHP/WordPress first — e.g. LocalWP, XAMPP, or any host.)

---

## PART A — Set up the central dashboard (do this once)

1. Log in to your dashboard site → **WP Admin**.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Choose `dist/naya-setu-courier-dashboard.zip` → **Install Now** → **Activate**.
4. A new menu **“Naya Setu”** appears in the left sidebar.
5. Open **Naya Setu → Settings** and fill in:
   - **Delhivery API Token** (from your Delhivery seller panel)
   - **Environment**: Production (or Staging to test)
   - **Pickup Address** (name, phone, address, city, state, pincode)
   - **Default package** weight/dimensions
   - Click **Save Settings**.
6. Click **Register / Update Warehouse** — this registers your pickup with Delhivery.
   (The pickup *name* must match a warehouse Delhivery recognizes.)

✅ Your dashboard is now live. The **Dashboard** page shows the connect-store URL
you’ll need in Part B (also shown under **Connected Stores**).

---

## PART B — Connect a client WooCommerce store (repeat per store)

1. Log in to the **client store** → **WP Admin**.
2. **Plugins → Add New → Upload Plugin**.
3. Choose `dist/naya-setu-courier-connector.zip` → **Install Now** → **Activate**.
4. Go to **WooCommerce → Courier Connector**.
5. Enter your **Dashboard URL** (e.g. `https://dashboard.yoursite.com`) and click
   **Connect Store**.
   - The connector calls your dashboard, registers itself, and stores the API key
     automatically. You do **not** copy keys by hand.
6. Click **Sync recent orders** to import existing orders (optional backfill).

✅ Done. From now on, every new order on this store syncs to your dashboard
automatically.

---

## PART C — Daily workflow (on the dashboard)

1. **Naya Setu → Orders** — see every order from every connected store.
2. Filter by status, store, payment, date, or search.
3. Click **Push** (or select many → **Push selected to Delhivery**) to create the
   shipment. An **AWB** is generated and synced back to the store automatically.
4. Use **Track** and **Label** on booked orders.
5. Tracking refreshes every 15 minutes automatically (and updates the store).

---

## PART D — The marketing landing page

Two options:

- **Static (any host):** open/upload `landing/index.html` anywhere (even without
  WordPress). Edit the `Connect Store` / `Login` links if needed.
- **Inside WordPress:** create a Page and add the shortcode:
  ```
  [naya_setu_landing]
  ```
  Optional custom links:
  ```
  [naya_setu_landing connect_url="https://yoursite.com/connect" login_url="https://yoursite.com/wp-admin"]
  ```

---

## PART E — Client login & Client Portal

So each client can log into **your** dashboard and manage **only their own** orders:

1. On the dashboard site, create a new **Page** (Pages → Add New), title it e.g.
   "Client Login", and add the shortcode:
   ```
   [naya_setu_client_portal]
   ```
   Publish it. This page is the client login + dashboard.
2. Go to **Naya Setu → Connected Stores**. For each store, enter the owner's
   **client email** (in the add form, or the "Link" box in the Client Login column).
   - This creates a `Naya Setu Client` user account and links it to that store.
   - WordPress emails them a set-password link.
3. The client visits the portal page, signs in, and sees stat cards + an orders
   table for **their store only** — with Push to Delhivery, Track, Label and order
   detail. They cannot see other clients' orders.

Roles:
- **Administrator** → full dashboard (`Naya Setu` admin menu) + sees all stores in the portal.
- **Naya Setu Client** → only the front-end portal, scoped to their linked store(s).

## Re-packaging after edits

If you change plugin code and want fresh ZIPs, run in PowerShell from the project root:

```powershell
Compress-Archive -Path .\courier-connector -DestinationPath .\dist\naya-setu-courier-dashboard.zip -Force
Compress-Archive -Path .\courier-connector-client -DestinationPath .\dist\naya-setu-courier-connector.zip -Force
```

---

## Troubleshooting

- **“Connect” fails:** confirm the dashboard URL is correct, the dashboard plugin
  is active, and the site is reachable over HTTPS.
- **Shipment fails / no AWB:** open **Naya Setu → Logs** — every Delhivery API call
  and its raw response is logged there. Most issues are a wrong token, an
  unregistered pickup name, or a Delhivery field-name mismatch for your account.
- **No tracking updates:** WordPress cron runs on traffic; on low-traffic sites set
  a real server cron to hit `wp-cron.php`, or trigger **Track** manually.
