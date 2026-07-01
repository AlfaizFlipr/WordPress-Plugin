# Naya Setu Courier — Testing Checklist (Your Side)

Everything **you** need to do to test the full system end-to-end. Follow top to
bottom. Boxes `[ ]` are things to tick off as you go.

---

## 0. What you are testing

Two WordPress sites talking to each other + Delhivery:

```
[ Client store site ]  --orders-->  [ Dashboard site ]  --shipment-->  [ Delhivery ]
        (Store Connector plugin)        (Dashboard plugin)                (API)
            ^                                  |
            +---------- AWB / tracking --------+
```

You need **2 WordPress sites**. The easiest way on Windows is **LocalWP** (free) —
it can run both sites in a few clicks.

---

## 1. Prerequisites to install

- [ ] **LocalWP** — https://localwp.com (recommended). *Or* XAMPP/Laragon if you
      prefer manual setup. Requirement: **PHP 7.4+**, **MySQL**, **WordPress 6.x**.
- [ ] A **Delhivery account** with **API access**:
  - Production token from the Delhivery seller panel (Settings → API), **or**
  - A **staging/UAT token** from your Delhivery account manager (best for testing —
    no real shipments get created).
- [ ] The two ZIP files from this project's `dist/` folder:
  - `naya-setu-courier-dashboard.zip`
  - `naya-setu-courier-connector.zip`

> ⚠️ Without a Delhivery token you can still test **everything except** actual AWB
> creation/tracking. Order sync, dashboard, filters, and the client portal all work
> without it.

---

## 2. Create the two sites (LocalWP)

- [ ] Open LocalWP → **Create a new site** → name it **`dashboard`** → finish setup
      (PHP 8.x, default DB). Note its URL, e.g. `http://dashboard.local`.
- [ ] Create a second site → name it **`store1`** → finish. URL e.g.
      `http://store1.local`.
- [ ] On **both** sites: log into `wp-admin` → **Plugins → Add New → search
      "WooCommerce" → Install → Activate**. Skip the WooCommerce setup wizard
      (click "Skip" / "Not right now").

---

## 3. Install the Naya Setu plugins

### Dashboard site (`dashboard.local`)
- [ ] **Plugins → Add New → Upload Plugin** → choose
      `naya-setu-courier-dashboard.zip` → **Install Now → Activate**.
- [ ] Confirm a **"Naya Setu"** menu appears in the left sidebar.

### Store site (`store1.local`)
- [ ] **Plugins → Add New → Upload Plugin** → choose
      `naya-setu-courier-connector.zip` → **Install Now → Activate**.
- [ ] Confirm **WooCommerce → Courier Connector** exists.

---

## 4. Configure the dashboard (Delhivery + pickup)

On `dashboard.local` → **Naya Setu → Settings**:

- [ ] Paste your **Delhivery API Token**.
- [ ] Set **Environment** = **Staging** (for safe testing) or Production.
- [ ] Fill **Pickup Address** (name, phone, full address, city, state, pincode).
      The **Pickup Name** must be a warehouse Delhivery will accept.
- [ ] Set default **weight/dimensions** (e.g. 0.5 kg, 10×10×10).
- [ ] Click **Save Settings**.
- [ ] Click **Register / Update Warehouse**.
  - ✅ Expected: green "Pickup location registered" notice.
  - ❌ If it fails: open **Naya Setu → Logs** and read the Delhivery response.

---

## 5. Connect the store to the dashboard

On `store1.local` → **WooCommerce → Courier Connector**:

- [ ] Enter **Dashboard URL** = `http://dashboard.local` → click **Connect Store**.
  - ✅ Expected: "Store connected successfully!" and the page now shows Store ID +
    a masked API key.
  - ❌ If it fails: make sure both sites are running and the URL is exactly right
    (no trailing slash needed; LocalWP URLs are reachable from each other).
- [ ] On `dashboard.local` → **Naya Setu → Connected Stores** → confirm `store1`
      appears with status **Active**.

---

## 6. Create test orders & verify auto-sync

On `store1.local`:

- [ ] Add a quick product: **Products → Add New** → name "Test Tee", set a price →
      **Publish**.
- [ ] Create an order manually: **WooCommerce → Orders → Add Order** →
  - Set **Billing**: name, phone, email, address, **city, state, pincode** (Indian
    pincode, important for Delhivery), and set payment to COD or leave prepaid.
  - **Add item** → Test Tee → **Create / Save**.
- [ ] (Optional) Place a real order through the storefront checkout instead.

Now verify the sync:

- [ ] On `dashboard.local` → **Naya Setu → Orders** → the order appears, tagged with
      store **store1**, shipment status **Pending**.
  - ✅ Auto-sync works.
- [ ] (Backfill test) On `store1.local` → **WooCommerce → Courier Connector** →
      **Sync recent orders** → confirm older orders also appear on the dashboard.

---

## 7. Create a shipment (Delhivery + AWB)

On `dashboard.local` → **Naya Setu → Orders**:

- [ ] Open the order → click **Push to Delhivery** (or **Push** in the list).
  - ✅ Expected: toast "Shipment created. AWB: …", status becomes **Booked**, AWB
    shows in the table.
  - ❌ If it fails: **Naya Setu → Logs** shows the exact Delhivery error. Common
    causes: wrong token, pickup name not registered, missing/invalid pincode, or a
    field-name mismatch for your account type.
- [ ] Check **reverse sync**: on `store1.local` open the same order →
      the AWB, courier, status and a **Track Shipment** button appear.
  - ✅ AWB synced back to the store.
- [ ] Click **Track** and **Label** on the dashboard order.
  - ✅ Track shows the latest status; Label opens the Delhivery packing slip
    (label availability can lag a minute on staging).

---

## 8. Test the Client Portal (client login)

On `dashboard.local`:

- [ ] **Pages → Add New** → title "Client Login" → in the content add the shortcode
      `[naya_setu_client_portal]` → **Publish**. Note the page URL.
- [ ] **Naya Setu → Connected Stores** → in the **Client Login** column for `store1`,
      enter a test email (e.g. `client1@example.com`) → **Link**.
  - This creates a **Naya Setu Client** user. (On Local, password-reset emails may
    not send — set the password manually next.)
- [ ] **Users → All Users** → find `client1@example.com` → **Edit** → set a password
      → **Update**.
- [ ] Open the **Client Login** page in a **private/incognito window** → sign in with
      that client.
  - ✅ Expected: branded portal, stat cards + orders for **store1 only**, with Push /
    Track / Label / View working.
- [ ] **Isolation test:** connect a second store (`store2.local`) with its own client,
      add an order there, and confirm **client1 cannot see store2's orders**.

---

## 9. Test automatic tracking sync (cron)

- [ ] Tracking refreshes every 15 min via WordPress cron. To test immediately,
      just click **Track** on a booked order — it pulls live status and updates.
- [ ] On low-traffic local sites, WP-cron only fires on page visits. To force it,
      visit `http://dashboard.local/wp-cron.php?doing_wp_cron` in the browser.
- [ ] When Delhivery reports **Delivered**, the order auto-completes and the status
      syncs back to the store.

---

## 10. Pass/fail summary to record

| # | Test | Pass? |
|---|------|-------|
| 1 | Both plugins activate without errors | [ ] |
| 2 | Delhivery settings save + warehouse registers | [ ] |
| 3 | Store connects to dashboard | [ ] |
| 4 | New order auto-syncs to dashboard | [ ] |
| 5 | Bulk "Sync recent orders" works | [ ] |
| 6 | Push to Delhivery returns an AWB | [ ] |
| 7 | AWB + tracking sync back to the store | [ ] |
| 8 | Track + Label actions work | [ ] |
| 9 | Client logs into portal, sees only their orders | [ ] |
| 10 | Client cannot see another store's orders | [ ] |
| 11 | Tracking/cron updates status | [ ] |

---

## 11. If something breaks

1. **First stop: Naya Setu → Logs** — every Delhivery API call + raw response is
   recorded there. 90% of issues are visible here.
2. **Enable WordPress debug** (in `wp-config.php`):
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   ```
   Errors then appear in `wp-content/debug.log`.
3. **"Connect" fails between sites:** confirm both LocalWP sites are running and the
   dashboard URL is correct and reachable.
4. **No AWB / Delhivery rejects:** usually token, unregistered pickup name, bad
   pincode, or a CMU field-name mismatch — adjust in
   `courier-connector/includes/class-cc-order.php → to_delhivery_shipment()` to match
   your account's API doc, then re-zip (see INSTALL-AND-CONNECT.md).
5. **Client portal blank / not styled:** make sure the page contains exactly
   `[naya_setu_client_portal]` and the dashboard plugin is active.

---

## What you need from me after testing

Send me whatever **fails** above with the matching **Logs** entry (or `debug.log`
line). The most likely thing needing a tweak is the Delhivery field mapping for your
specific account — that's a quick fix once I see the real API response.
