<?php
$awb = isset($_GET["awb"]) ? preg_replace("/[^A-Za-z0-9\-]/", "", $_GET["awb"]) : "";
$json = null;
$error = "";
if ($awb !== "") {
  $url = "http://127.0.0.1/courier-dashboard/wp-json/courier/v1/track/" . urlencode($awb);
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_HTTPHEADER => ["Accept: application/json"]]);
  $body = curl_exec($ch);
  curl_close($ch);
  if ($body) {
    $parsed = json_decode($body, true);
    if ($parsed && !empty($parsed["success"])) {
      $json = $parsed;
    } else {
      $error = $parsed["message"] ?? "Unable to retrieve tracking information.";
    }
  } else {
    $error = "Could not connect to tracking service.";
  }
}
$d = $json["data"] ?? [];
$pkg = $d["package"] ?? [];
$con = $d["consignee"] ?? [];
$ship = $d["shipper"] ?? [];
$scans = $json["scans"] ?? [];
$status = $json["status"] ?? "";
$courierName = $json["courier"] ?? "";
$courierChips = ["Delhivery" => ["bg" => "#eef4ff", "fg" => "#1a56db"], "DTDC" => ["bg" => "#fff7ed", "fg" => "#c2410c"]];
$courierChip = $courierChips[$courierName] ?? ["bg" => "#f3f4f6", "fg" => "#374151"];
$sc = ["label" => "#1e40af", "bg" => "#dbeafe", "dot" => "#3b82f6"];
foreach (["Delivered" => ["label" => "#065f46", "bg" => "#d1fae5", "dot" => "#10b981"], "Out For Delivery" => ["label" => "#92400e", "bg" => "#fef3c7", "dot" => "#f59e0b"], "In Transit" => ["label" => "#1e40af", "bg" => "#dbeafe", "dot" => "#3b82f6"], "Manifested" => ["label" => "#5b21b6", "bg" => "#ede9fe", "dot" => "#8b5cf6"], "RTO" => ["label" => "#991b1b", "bg" => "#fee2e2", "dot" => "#ef4444"], "Cancelled" => ["label" => "#374151", "bg" => "#f3f4f6", "dot" => "#9ca3af"]] as $k => $v) {
  if ($status && stripos($status, $k) !== false) {
    $sc = $v;
    break;
  }
}
$steps = [["label" => "Ready to Ship", "sub" => "Order manifested", "key" => "manifested"], ["label" => "Scheduled for Pickup", "sub" => "Pickup request raised", "key" => "pickup"], ["label" => "In Transit", "sub" => "Package en route", "key" => "transit"], ["label" => "Out for Delivery", "sub" => "With delivery agent", "key" => "ofd"], ["label" => "Delivered", "sub" => "Package delivered", "key" => "delivered"]];
$step_scans = [];
foreach (array_reverse($scans) as $scan) {
  $sl = strtolower($scan["status"] ?? "");
  if (preg_match("/manifest|ready/i", $sl))
    $step_scans["manifested"] ??= $scan;
  elseif (preg_match("/pick.?up|picked/i", $sl))
    $step_scans["pickup"] ??= $scan;
  elseif (preg_match("/out.?for|ofd/i", $sl))
    $step_scans["ofd"] ??= $scan;
  elseif (preg_match("/deliver/i", $sl))
    $step_scans["delivered"] ??= $scan;
  elseif (preg_match("/transit/i", $sl))
    $step_scans["transit"] ??= $scan;
}
$done_keys = [];
$sl2 = strtolower($status);
if (preg_match("/deliver/i", $sl2))
  $done_keys = ["manifested", "pickup", "transit", "ofd", "delivered"];
elseif (preg_match("/out.?for|ofd/i", $sl2))
  $done_keys = ["manifested", "pickup", "transit", "ofd"];
elseif (preg_match("/transit/i", $sl2))
  $done_keys = ["manifested", "pickup", "transit"];
elseif (preg_match("/pick.?up|picked/i", $sl2))
  $done_keys = ["manifested", "pickup"];
elseif ($status)
  $done_keys = ["manifested"];
function fdate($dt)
{
  if (!$dt)
    return "";
  try {
    return (new DateTime($dt))->format("d M Y, h:i a");
  } catch (Exception $e) {
    return $dt;
  }
}
function dval($v, $fb = "—")
{
  return htmlspecialchars(trim((string) $v) ?: $fb);
}
$mdate = fdate($d["pickup_date"] ?? "");
$total = $d["total"] ?? 0;
$pmode = $d["payment_mode"] ?? ($json ? (($d["cod_amount"] ?? 0) > 0 ? "COD" : "Pre-Paid") : "");
$dot = $sc["dot"];
$pkgName = $pkg["name"] ?? "";
$pkgQty = (int) ($pkg["qty"] ?? 0);
$pkgWt = (float) ($pkg["weight"] ?? 0);
$pkgPrice = (float) ($pkg["price"] ?? 0);
$shipName = $ship["name"] ?? "";
$shipCity = $ship["city"] ?? $d["origin"] ?? "";
$shipState = $ship["state"] ?? "";
$shipPin = $ship["pincode"] ?? "";
$conName = $con["name"] ?? "";
$conAddr = $con["address"] ?? "";
$conCity = $con["city"] ?? $d["destination"] ?? "";
$conState = $con["state"] ?? "";
$conPin = $con["pincode"] ?? "";
?><!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Shipment Details<?php if ($awb): ?> — <?php echo htmlspecialchars($awb); ?><?php endif; ?></title>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    body {
      font-family: "IBM Plex Sans", sans-serif;
      font-size: 13px;
      color: #111827;
      background: #f3f4f6;
      min-height: 100vh
    }

    .layout {
      display: flex;
      flex-direction: column;
      min-height: 100vh
    }

    .hdr {
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      height: 52px;
      position: sticky;
      top: 0;
      z-index: 10
    }

    .hdr-inner {
      max-width: 1280px;
      margin: 0 auto;
      height: 100%;
      display: flex;
      align-items: center;
      padding: 0 40px;
      gap: 10px
    }

    .bc {
      display: flex;
      align-items: center;
      flex: 1;
      min-width: 0;
      font-size: 13px
    }

    .bc a {
      color: #6b7280;
      text-decoration: none
    }

    .bc a:hover {
      color: #111827
    }

    .bc-sep {
      color: #d1d5db;
      margin: 0 5px
    }

    .bc-cur {
      color: #111827;
      font-weight: 500
    }

    .hs {
      display: flex;
      align-items: center;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      overflow: hidden;
      height: 32px;
      flex-shrink: 0
    }

    .hs-type {
      background: #f9fafb;
      border-right: 1px solid #e5e7eb;
      padding: 0 10px;
      font-size: 12px;
      color: #374151;
      font-weight: 500;
      height: 100%;
      display: flex;
      align-items: center;
      gap: 4px;
      white-space: nowrap
    }

    .hs input {
      border: none;
      outline: none;
      padding: 0 10px;
      font-size: 12px;
      color: #374151;
      background: transparent;
      width: 160px;
      font-family: inherit
    }

    .hs input::placeholder {
      color: #9ca3af
    }

    .hs button {
      background: #ed4136;
      color: #fff;
      border: none;
      height: 100%;
      padding: 0 12px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      white-space: nowrap
    }

    .hs button:hover {
      background: #c53030
    }

    .content {
      flex: 1;
      padding: 20px 40px;
      max-width: 1280px;
      width: 100%;
      margin: 0 auto;
      align-self: center
    }

    .awb-card {
      background: #fff;
      border-radius: 8px;
      padding: 16px 20px;
      margin-bottom: 14px;
      border: 1px solid #f3f4f6;
      box-shadow: 0 1px 2px rgba(0, 0, 0, .05)
    }

    .awb-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 12px
    }

    .awb-left {
      display: flex;
      align-items: center;
      gap: 12px
    }

    .awb-ico {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      background: #f3f4f6;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0
    }

    .awb-ico svg {
      width: 20px;
      height: 20px;
      fill: none;
      stroke: #6b7280;
      stroke-width: 1.5;
      stroke-linecap: round;
      stroke-linejoin: round
    }

    .awb-n {
      font-size: 16px;
      font-weight: 600;
      color: #111827;
      line-height: 1.3
    }

    .awb-a {
      font-size: 12px;
      color: #9ca3af;
      margin-top: 2px
    }

    .awb-acts {
      display: flex;
      gap: 7px;
      flex-wrap: wrap;
      align-items: center
    }

    .abtn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 6px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 500;
      cursor: pointer;
      background: #fff;
      color: #374151;
      font-family: inherit
    }

    .abtn:hover {
      background: #f9fafb
    }

    .awb-meta {
      display: flex;
      align-items: center;
      gap: 7px;
      flex-wrap: wrap
    }

    .sp {
      display: inline-flex;
      align-items: center;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 600
    }

    .mp {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 9px;
      border: 1px solid #e5e7eb;
      border-radius: 20px;
      font-size: 11px;
      color: #6b7280
    }

    .mp svg {
      width: 11px;
      height: 11px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round
    }

    .cp {
      display: inline-flex;
      align-items: center;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700
    }

    .abtn.copied {
      background: #ecfdf5;
      border-color: #a7f3d0;
      color: #065f46
    }

    .g2 {
      display: grid;
      grid-template-columns: 1fr 330px;
      gap: 12px;
      align-items: start
    }

    .card {
      background: #fff;
      border-radius: 8px;
      border: 1px solid #f3f4f6;
      box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
      margin-bottom: 10px;
      overflow: hidden
    }

    .card:last-child {
      margin-bottom: 0
    }

    .ch {
      padding: 11px 18px;
      border-bottom: 1px solid #f3f4f6;
      display: flex;
      align-items: center;
      gap: 7px
    }

    .ch svg {
      width: 14px;
      height: 14px;
      fill: none;
      stroke: #9ca3af;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round
    }

    .cht {
      font-size: 12px;
      font-weight: 600;
      color: #374151
    }

    .cb {
      padding: 14px 18px
    }

    .row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 5px 0;
      font-size: 12px;
      border-bottom: 1px solid #f9fafb
    }

    .row:last-child {
      border-bottom: none
    }

    .rl {
      color: #6b7280
    }

    .rv {
      font-weight: 500;
      color: #111827;
      text-align: right
    }

    .pkr {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      padding: 4px 0
    }

    .pk-n {
      font-weight: 600;
      color: #111827;
      font-size: 13px;
      flex: 1;
      min-width: 120px
    }

    .pk-s {
      font-size: 12px;
      color: #6b7280;
      white-space: nowrap
    }

    .pk-t {
      font-size: 13px;
      font-weight: 700;
      color: #111827;
      white-space: nowrap
    }

    .pk-sub {
      margin-top: 8px;
      font-size: 11px;
      color: #9ca3af;
      display: flex;
      gap: 12px;
      flex-wrap: wrap
    }

    .dl-block {
      margin-bottom: 14px
    }

    .dl-block:last-child {
      margin-bottom: 0
    }

    .dl-hd {
      display: flex;
      align-items: center;
      gap: 7px;
      margin-bottom: 6px
    }

    .dl-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      flex-shrink: 0
    }

    .dl-dot-o {
      border: 2px solid #d1d5db;
      background: #fff
    }

    .dl-dot-f {
      background: #ed4136
    }

    .dl-lbl {
      font-size: 10px;
      font-weight: 700;
      color: #9ca3af;
      text-transform: uppercase;
      letter-spacing: .6px
    }

    .dl-name {
      font-size: 13px;
      font-weight: 600;
      color: #111827;
      margin-bottom: 2px;
      padding-left: 17px
    }

    .dl-addr {
      font-size: 12px;
      color: #6b7280;
      line-height: 1.5;
      padding-left: 17px
    }

    .dl-conn {
      margin-left: 4px;
      width: 2px;
      height: 16px;
      background: #e5e7eb;
      margin-bottom: 10px;
      margin-top: 2px
    }

    .sbox {
      margin-top: 8px;
      margin-left: 17px;
      padding: 6px 10px;
      background: #f9fafb;
      border-radius: 6px;
      border: 1px solid #f3f4f6;
      display: inline-block
    }

    .sbox-l {
      font-size: 9px;
      font-weight: 700;
      color: #9ca3af;
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-bottom: 2px
    }

    .sbox-n {
      font-size: 12px;
      font-weight: 500;
      color: #374151
    }

    .pbadge {
      display: inline-flex;
      align-items: center;
      padding: 2px 8px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 600
    }

    .p-pre {
      background: #d1fae5;
      color: #065f46
    }

    .p-cod {
      background: #fee2e2;
      color: #991b1b
    }

    .pt {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-top: 9px;
      margin-top: 6px;
      border-top: 1px solid #f3f4f6;
      font-weight: 700;
      font-size: 13px;
      color: #111827
    }

    .trm {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      padding: 11px 18px;
      border-bottom: 1px solid #f3f4f6
    }

    .trl {
      font-size: 10px;
      font-weight: 600;
      color: #9ca3af;
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 3px
    }

    .trv {
      font-size: 12px;
      font-weight: 500;
      color: #111827
    }

    .tl {
      padding: 16px 18px
    }

    .tlr {
      display: flex;
      gap: 10px;
      padding-bottom: 18px
    }

    .tlr:last-child {
      padding-bottom: 0
    }

    .tll {
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 20px;
      flex-shrink: 0
    }

    .tld {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid #e5e7eb;
      background: #fff;
      flex-shrink: 0;
      z-index: 1
    }

    .tld-i {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #d1d5db
    }

    .tln {
      flex: 1;
      width: 2px;
      background: #e5e7eb;
      margin-top: 2px
    }

    .tlc {
      flex: 1;
      padding-top: 1px
    }

    .tl-lb {
      font-size: 12px;
      font-weight: 500;
      color: #9ca3af;
      line-height: 1.3
    }

    .tl-lb.done {
      font-weight: 600;
      color: #111827
    }

    .tl-ti {
      font-size: 10px;
      color: #9ca3af;
      margin-top: 2px
    }

    .tl-su {
      font-size: 11px;
      color: #9ca3af;
      margin-top: 2px
    }

    .tl-de {
      font-size: 11px;
      color: #6b7280;
      margin-top: 2px;
      line-height: 1.4
    }

    .sw {
      padding: 0 18px 14px
    }

    details summary {
      font-size: 11px;
      font-weight: 600;
      color: #2563eb;
      cursor: pointer;
      list-style: none;
      display: flex;
      align-items: center;
      gap: 4px;
      padding: 6px 0;
      user-select: none
    }

    details summary::-webkit-details-marker {
      display: none
    }

    .st {
      width: 100%;
      border-collapse: collapse;
      font-size: 11px;
      margin-top: 6px
    }

    .st th {
      text-align: left;
      padding: 5px 7px;
      color: #9ca3af;
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .4px;
      border-bottom: 1px solid #f3f4f6;
      background: #fafafa
    }

    .st td {
      padding: 5px 7px;
      border-bottom: 1px solid #f9fafb;
      color: #374151;
      vertical-align: top
    }

    .st tr:first-child td {
      font-weight: 600;
      color: #111827;
      background: #f9fafb
    }

    .sf {
      background: #fff;
      border-radius: 8px;
      padding: 20px 24px;
      max-width: 460px;
      margin: 40px auto;
      border: 1px solid #f3f4f6;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .06)
    }

    .sf h3 {
      font-size: 14px;
      font-weight: 600;
      color: #111827;
      margin-bottom: 12px
    }

    .sfr {
      display: flex;
      gap: 8px
    }

    .sfr input {
      flex: 1;
      padding: 9px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 13px;
      outline: none;
      font-family: inherit
    }

    .sfr input:focus {
      border-color: #ed4136;
      box-shadow: 0 0 0 2px rgba(237, 65, 54, .1)
    }

    .sfr button {
      padding: 9px 18px;
      background: #ed4136;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit
    }

    .sfr button:hover {
      background: #c53030
    }

    .al {
      background: #fff7ed;
      border: 1px solid #fed7aa;
      border-radius: 8px;
      padding: 11px 16px;
      color: #92400e;
      font-size: 12px;
      margin-bottom: 14px
    }

    @media(max-width:860px) {
      .g2 {
        grid-template-columns: 1fr
      }
    }

    @media(max-width:540px) {
      .hdr-inner {
        padding: 0 16px
      }

      .hs input {
        width: 110px
      }

      .content {
        padding: 12px 16px
      }
    }
  </style>
</head>

<body>
  <div class="layout">
    <div class="hdr">
      <div class="hdr-inner">
        <div class="bc"><span style="color:#6b7280">Shipments</span><span class="bc-sep">&#8250;</span><span
            style="color:#6b7280">Forward</span><span class="bc-sep">&#8250;</span><span class="bc-cur">Shipment
            Details</span></div>
        <form method="get" class="hs">
          <div class="hs-type">AWB <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
              stroke-width="2">
              <path d="M19 9l-7 7-7-7" />
            </svg></div>
          <input type="text" name="awb" value="<?php echo htmlspecialchars($awb); ?>" placeholder="Search AWB..."
            autocomplete="off">
          <button type="submit">Track</button>
        </form>
      </div>
    </div>
    <div class="content">
      <?php if ($awb && $error): ?>
        <div class="al">&#9888; <?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if (!$awb): ?>
        <div class="sf">
          <h3>Track Shipment</h3>
          <form method="get" class="sfr">
            <input type="text" name="awb" placeholder="Enter AWB number..." autocomplete="off">
            <button type="submit">Track</button>
          </form>
        </div>
      <?php endif; ?>
      <?php if ($json): ?>
        <div class="awb-card">
          <div class="awb-top">
            <div class="awb-left">
              <div class="awb-ico"><svg viewBox="0 0 24 24">
                  <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg></div>
              <div>
                <div class="awb-n"><?php echo dval($d["awb"] ?? $awb, $awb); ?></div>
                <?php if ($total > 0): ?>
                  <div class="awb-a">&#8377;<?php echo number_format((float) $total, 2); ?></div><?php endif; ?>
              </div>
            </div>
            <div class="awb-acts">
              <button class="abtn" id="copyAwbBtn" onclick="copyAwb(this)"
                data-awb="<?php echo htmlspecialchars($d["awb"] ?? $awb); ?>">
                <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" stroke-width="2" fill="none">
                  <rect x="9" y="9" width="13" height="13" rx="2" />
                  <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" />
                </svg>
                Copy AWB
              </button>
              <button class="abtn" onclick="window.print()">
                <svg viewBox="0 0 24 24" width="12" height="12" stroke="currentColor" stroke-width="2" fill="none">
                  <path
                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm1-4h4v5H9v-5z" />
                </svg>
                Print POD
              </button>
            </div>
          </div>
          <div class="awb-meta">
            <span class="sp"
              style="background:<?php echo $sc["bg"]; ?>;color:<?php echo $sc["label"]; ?>"><?php echo htmlspecialchars($status); ?></span>
            <?php if ($courierName): ?><span class="cp"
                style="background:<?php echo $courierChip["bg"]; ?>;color:<?php echo $courierChip["fg"]; ?>">via
                <?php echo htmlspecialchars($courierName); ?></span><?php endif; ?>
            <?php if ($mdate): ?><span class="mp"><svg viewBox="0 0 24 24">
                  <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                  <line x1="16" y1="2" x2="16" y2="6" />
                  <line x1="8" y1="2" x2="8" y2="6" />
                  <line x1="3" y1="10" x2="21" y2="10" />
                </svg><?php echo $mdate; ?></span><?php endif; ?>
            <?php if ($pmode): ?><span class="mp"><?php echo htmlspecialchars($pmode); ?></span><?php endif; ?>
          </div>
        </div>
        <div class="g2">
          <div>
            <div class="card">
              <div class="ch"><svg viewBox="0 0 24 24">
                  <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg><span class="cht">Package Details</span></div>
              <div class="cb">
                <div class="pkr">
                  <div class="pk-n"><?php echo dval($pkgName, "Package"); ?></div>
                  <?php if ($pkgQty > 0): ?>
                    <div class="pk-s">Qty: <?php echo $pkgQty; ?></div><?php endif; ?>
                  <?php if ($pkgPrice > 0): ?>
                    <div class="pk-t">&#8377;<?php echo number_format($pkgPrice, 2); ?></div><?php endif; ?>
                </div>
                <div class="pk-sub">
                  <span>Weight: <?php echo $pkgWt > 0 ? round($pkgWt) . " gm" : "—"; ?></span>
                  <?php if (($pkg["length"] ?? 0) > 0): ?>
                    <span>Dims: <?php echo $pkg["length"] . "&times;" . $pkg["width"] . "&times;" . $pkg["height"]; ?> cm</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="card">
              <div class="ch"><svg viewBox="0 0 24 24">
                  <path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  <path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg><span class="cht">Delivery Details</span></div>
              <div class="cb">
                <div class="dl-block">
                  <div class="dl-hd">
                    <div class="dl-dot dl-dot-o"></div>
                    <div class="dl-lbl">Pickup</div>
                  </div>
                  <div class="dl-name"><?php echo dval($shipName); ?></div>
                  <div class="dl-addr"><?php
                  $parts = array_filter([$shipCity, $shipState, $shipPin]);
                  echo $parts ? htmlspecialchars(implode(", ", $parts)) : "—";
                  ?></div>
                </div>
                <div class="dl-conn"></div>
                <div class="dl-block">
                  <div class="dl-hd">
                    <div class="dl-dot dl-dot-f"></div>
                    <div class="dl-lbl">Delivery</div>
                  </div>
                  <div class="dl-name"><?php echo dval($conName); ?></div>
                  <div class="dl-addr"><?php
                  if ($conAddr)
                    echo htmlspecialchars($conAddr) . "<br>";
                  $parts2 = array_filter([$conCity, $conState, $conPin]);
                  echo $parts2 ? htmlspecialchars(implode(", ", $parts2)) : "—";
                  ?></div>
                  <?php if ($shipName): ?>
                    <div class="sbox">
                      <div class="sbox-l">Seller / Shipper</div>
                      <div class="sbox-n"><?php echo dval($shipName); ?></div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="card">
              <div class="ch"><svg viewBox="0 0 24 24">
                  <path d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg><span class="cht">Payment Details</span></div>
              <div class="cb">
                <div class="row"><span class="rl">Mode</span><span class="rv"><span
                      class="pbadge <?php echo (stripos($pmode ?: "", "cod") !== false) ? "p-cod" : "p-pre"; ?>"><?php echo dval($pmode, "Pre-Paid"); ?></span></span>
                </div>
                <?php if (($d["cod_amount"] ?? 0) > 0): ?>
                  <div class="row"><span class="rl">COD Amount</span><span
                      class="rv">&#8377;<?php echo number_format((float) $d["cod_amount"], 2); ?></span></div><?php endif; ?>
                <div class="row"><span class="rl">Invoice Value</span><span
                    class="rv">&#8377;<?php echo number_format((float) ($pkgPrice ?: $total ?: 0), 2); ?></span></div>
                <div class="pt"><span>Total</span><span>&#8377;<?php echo number_format((float) ($total ?? 0), 2); ?></span>
                </div>
              </div>
            </div>
          </div>
          <div style="position:sticky;top:60px">
            <div class="card">
              <div class="ch"><svg viewBox="0 0 24 24">
                  <circle cx="12" cy="12" r="10" />
                  <polyline points="12 6 12 12 16 14" />
                </svg><span class="cht">Shipment Tracker</span></div>
              <div class="trm">
                <div>
                  <div class="trl">AWB</div>
                  <div class="trv"><?php echo dval($d["awb"] ?? $awb, $awb); ?></div>
                </div>
                <div>
                  <div class="trl">Courier</div>
                  <div class="trv"><?php if ($courierName): ?><span class="cp"
                        style="background:<?php echo $courierChip["bg"]; ?>;color:<?php echo $courierChip["fg"]; ?>"><?php echo htmlspecialchars($courierName); ?></span><?php else: ?>—<?php endif; ?>
                  </div>
                </div>
                <div>
                  <div class="trl">Weight</div>
                  <div class="trv"><?php echo $pkgWt > 0 ? round($pkgWt) . " gm" : "—"; ?></div>
                </div>
                <div>
                  <div class="trl">Dimensions</div>
                  <div class="trv">
                    <?php echo (($pkg["length"] ?? 0) > 0) ? $pkg["length"] . "&times;" . $pkg["width"] . "&times;" . $pkg["height"] . " cm" : "—"; ?>
                  </div>
                </div>
                <div>
                  <div class="trl">Origin</div>
                  <div class="trv"><?php echo dval($d["origin"] ?? $shipCity); ?></div>
                </div>
              </div>
              <div class="tl">
                <?php foreach ($steps as $i => $step):
                  $done = in_array($step["key"], $done_keys);
                  $scan = $step_scans[$step["key"]] ?? null;
                  $isLast = ($i === count($steps) - 1);
                  ?>
                  <div class="tlr">
                    <div class="tll">
                      <div class="tld" style="<?php echo $done ? "background:{$dot};border-color:{$dot}" : ""; ?>">
                        <?php if ($done): ?>
                          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"
                            stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                          </svg>
                        <?php else: ?>
                          <div class="tld-i"></div><?php endif; ?>
                      </div>
                      <?php if (!$isLast): ?>
                        <div class="tln" style="<?php echo $done ? "background:{$dot}" : ""; ?>"></div><?php endif; ?>
                    </div>
                    <div class="tlc">
                      <div class="tl-lb<?php echo $done ? " done" : ""; ?>"><?php echo $step["label"]; ?></div>
                      <?php if ($scan): ?>
                        <div class="tl-ti"><?php echo fdate($scan["time"] ?? $scan["ScanDateTime"] ?? ""); ?></div>
                        <div class="tl-de"><?php echo dval($scan["location"] ?? $scan["ScannedLocation"] ?? ""); ?></div>
                      <?php else: ?>
                        <div class="tl-su"><?php echo $step["sub"]; ?></div><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (count($scans) > 0): ?>
                <div class="sw">
                  <details>
                    <summary>&#9660; View all scans (<?php echo count($scans); ?>)</summary>
                    <table class="st">
                      <thead>
                        <tr>
                          <th>Status</th>
                          <th>Location</th>
                          <th>Date &amp; Time</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($scans as $sc2): ?>
                          <tr>
                            <td><?php echo dval($sc2["status"] ?? "", " "); ?></td>
                            <td><?php echo dval($sc2["location"] ?? "", " "); ?></td>
                            <td><?php echo fdate($sc2["time"] ?? ""); ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </details>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    function copyAwb(btn) {
      var awb = btn.getAttribute("data-awb");
      if (!awb) return;
      navigator.clipboard.writeText(awb).then(function () {
        var label = btn.innerHTML;
        btn.classList.add("copied");
        btn.lastChild.textContent = " Copied!";
        setTimeout(function () { btn.classList.remove("copied"); btn.innerHTML = label; }, 1600);
      });
    }
  </script>
</body>

</html>