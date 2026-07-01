# Fresh Setup on XAMPP (Clean Start)

This wipes your old WordPress installs and builds two clearly-named sites:

| Folder / URL | Role | Plugin to install |
|---|---|---|
| `C:\xampp\htdocs\panel` → `http://localhost/panel` | **Central dashboard** | Naya Setu Courier — **Dashboard** |
| `C:\xampp\htdocs\store1` → `http://localhost/store1` | **A WooCommerce store** | Naya Setu Courier — **Store Connector** |

> Why rename? Your old `wordpress` / `wordpress-client` looked alike and the wrong
> plugin ended up on the wrong site. `panel` and `store1` make it obvious.
> (Don't name it `dashboard` — XAMPP already uses `C:\xampp\htdocs\dashboard` for
> its own welcome page.)

---

## STEP 1 — Start XAMPP
Open **XAMPP Control Panel** → **Start** both **Apache** and **MySQL**.

---

## STEP 2 — Delete the OLD installs

### 2a. Note the old database names first (so you delete the right ones)
Open each old `wp-config.php` and read the `DB_NAME` value:
- `C:\xampp\htdocs\wordpress\wp-config.php`
- `C:\xampp\htdocs\wordpress-client\wp-config.php`

### 2b. Delete the old files
Delete these folders:
- `C:\xampp\htdocs\wordpress`
- `C:\xampp\htdocs\wordpress-client`

### 2c. Drop the old databases
Open **http://localhost/phpmyadmin** → click each old DB (from 2a) → **Operations**
tab → **Drop the database (DROP)**. (Or check the box next to it on the left and
choose Drop.)

✅ Old sites are gone.

---

## STEP 3 — Get fresh WordPress files

1. Download WordPress: **https://wordpress.org/latest.zip**
2. Extract it. You get a folder named `wordpress`.
3. Copy it into `C:\xampp\htdocs\` **twice**, renaming each:
   - `C:\xampp\htdocs\panel`   (do NOT use the name `dashboard` — XAMPP owns that)
   - `C:\xampp\htdocs\store1`

---

## STEP 4 — Create two fresh databases

Open **http://localhost/phpmyadmin** → **New** (left sidebar) → create these two
(Collation `utf8mb4_general_ci`):
- `ns_dashboard`
- `ns_store1`

---

## STEP 5 — Install WordPress on each site

### Dashboard
1. Go to **http://localhost/panel**
2. Run the installer. When asked for database details (XAMPP defaults):
   - Database Name: `ns_dashboard`
   - Username: `root`
   - Password: *(leave blank)*
   - Database Host: `localhost`
   - Table Prefix: `wp_`
3. Set Site Title = "Naya Setu Dashboard", create your **admin username + password**,
   finish, and log in.

### Store
Repeat at **http://localhost/store1** using database `ns_store1`.
Site Title = "Store 1".

---

## STEP 6 — Set permalinks (BOTH sites)

On each site: **Settings → Permalinks → select "Post name" → Save Changes.**
(Required so the REST API works cleanly.)

---

## STEP 7 — Install WooCommerce (BOTH sites)

On each site: **Plugins → Add New** → search **WooCommerce** → **Install** →
**Activate**. You can skip/close the WooCommerce setup wizard.

---

## STEP 8 — Install the Naya Setu plugins (CORRECT one on each)

### On the DASHBOARD site (`http://localhost/panel`)
**Plugins → Add New → Upload Plugin** → choose
`dist/naya-setu-courier-dashboard.zip` → **Install Now → Activate**.
- ✅ You should now see a **"Naya Setu"** menu in the sidebar.

### On the STORE site (`http://localhost/store1`)
**Plugins → Add New → Upload Plugin** → choose
`dist/naya-setu-courier-connector.zip` → **Install Now → Activate**.
- ✅ You should see **WooCommerce → Courier Connector**.

> ❗ Do NOT swap these. Dashboard zip → dashboard site only. Connector zip → store
> site only.

---

## STEP 9 — Verify the dashboard exposes the right routes

Open in your browser:
```
http://localhost/panel/wp-json/courier/v1
```
You MUST see **`connect-store`**, **`orders`**, **`ping`** in the list.
- ✅ See `connect-store` → perfect, continue.
- ❌ See `update-awb` instead → you put the connector on the dashboard. Fix Step 8.

---

## STEP 10 — Connect the store

On `http://localhost/store1/wp-admin` → **WooCommerce → Courier Connector**:
- Dashboard URL: **`http://localhost/panel`**
- Click **Connect Store** → expect **"Store connected successfully!"**

Verify on the dashboard:
`http://localhost/panel/wp-admin/admin.php?page=cc-websites` → Store 1 appears,
status **Active**.

---

## STEP 11 — Test an order end-to-end

1. On `store1`: **WooCommerce → Orders → Add Order** → fill name, phone, address,
   **city / state / pincode**, add a product → **Create**.
2. On the dashboard:
   `http://localhost/panel/wp-admin/admin.php?page=cc-orders` → the order appears
   (tagged "Store 1", status *Pending*). ✅ Sync works.
3. (Needs Delhivery token) **Naya Setu → Settings** → add token + pickup →
   open the order → **Push to Delhivery** → AWB is created and flows back to the store.

---

## Your bookmarks (so you never mix sites again)

**Dashboard (central panel):**
- Courier dashboard: `http://localhost/panel/wp-admin/admin.php?page=cc-dashboard`
- Orders: `http://localhost/panel/wp-admin/admin.php?page=cc-orders`
- Connected Stores: `http://localhost/panel/wp-admin/admin.php?page=cc-websites`
- Settings: `http://localhost/panel/wp-admin/admin.php?page=cc-settings`
- Route check: `http://localhost/panel/wp-json/courier/v1`

**Store:**
- Connect page: `http://localhost/store1/wp-admin/admin.php?page=ccc-settings`

> Ignore `page=wc-admin` — that's WooCommerce's own Analytics, not this plugin.
