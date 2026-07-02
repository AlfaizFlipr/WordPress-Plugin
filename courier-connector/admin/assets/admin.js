(function ($) {
  "use strict";

  function toast(msg, type) {
    var $box = $("#cc-toast");
    if (!$box.length) {
      $box = $('<div id="cc-toast"></div>').appendTo("body");
    }
    var $t = $('<div class="cc-toast"></div>')
      .addClass("cc-toast-" + (type || "ok"))
      .text(msg);
    $box.append($t);
    setTimeout(function () {
      $t.fadeOut(250, function () {
        $t.remove();
      });
    }, 4500);
  }

  function ccModal(title, html) {
    $("#cc-modal").remove();
    var $overlay = $(
      '<div id="cc-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px"></div>',
    );
    var $box = $(
      '<div style="background:#fff;border-radius:8px;width:100%;max-width:660px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.25)"></div>',
    );
    var $hdr = $(
      '<div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e5e7eb"></div>',
    );
    $hdr.append('<h3 style="margin:0;font-size:15px">' + title + "</h3>");
    var $close = $(
      '<button style="background:none;border:none;font-size:22px;cursor:pointer;line-height:1;color:#6b7280">&times;</button>',
    );
    $hdr.append($close);
    $box.append($hdr);
    $box.append(
      '<div style="padding:16px 20px;overflow-y:auto">' + html + "</div>",
    );
    $overlay.append($box);
    $("body").append($overlay);
    $close.on("click", function () {
      $overlay.remove();
    });
    $overlay.on("click", function (e) {
      if (e.target === $overlay[0]) $overlay.remove();
    });
  }

  function post(data, done) {
    data.nonce = CC.nonce;
    $.post(CC.ajax, data)
      .done(function (res) {
        if (res && res.success) {
          done(res.data || {});
        } else {
          toast(
            (res && res.data && res.data.message) || "Action failed.",
            "err",
          );
        }
      })
      .fail(function () {
        toast("Network error.", "err");
      });
  }

  function busy($btn, on) {
    if (on) {
      $btn.data("label", $btn.text()).prop("disabled", true).text("…");
    } else {
      $btn.prop("disabled", false).text($btn.data("label"));
    }
  }

  $(document).ready(function () {
    $(document).on("click", ".cc-push", function () {
      var $btn = $(this),
        id = $btn.data("order"),
        courier = $btn
          .closest(".cc-push-group")
          .find(".cc-courier-select")
          .val();
      busy($btn, true);
      var payload = { action: "cc_push_shipment", order_id: id };
      if (courier) payload.courier = courier;
      post(payload, function (d) {
        toast(d.message || "Shipment created.", "ok");
        setTimeout(function () {
          location.reload();
        }, 900);
      });
      setTimeout(function () {
        busy($btn, false);
      }, 6000);
    });

    $(document).on("click", ".cc-cancel", function () {
      if (!confirm("Cancel this shipment?")) {
        return;
      }
      var $btn = $(this),
        id = $btn.data("order");
      busy($btn, true);
      post({ action: "cc_cancel_shipment", order_id: id }, function (d) {
        toast(d.message || "Cancelled.", "ok");
        setTimeout(function () {
          location.reload();
        }, 900);
      });
      setTimeout(function () {
        busy($btn, false);
      }, 6000);
    });

    $(document).on("click", ".cc-track", function () {
      var $btn = $(this),
        id = $btn.data("order");
      busy($btn, true);
      post({ action: "cc_track", order_id: id }, function (d) {
        var info = d.data || {};
        var pkg = info.package || {};
        var con = info.consignee || {};
        var ship = info.shipper || {};
        var scans = d.scans || [];
        var status = d.status || "Unknown";

        var sc = "#2563eb";
        if (/deliver/i.test(status)) sc = "#16a34a";
        else if (/out for/i.test(status)) sc = "#d97706";
        else if (/rto|cancel/i.test(status)) sc = "#dc2626";
        else if (/manifest/i.test(status)) sc = "#7c3aed";

        var h =
          '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">';

        h +=
          '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #f1f5f9">';
        h += "<div>";
        h +=
          '<div style="font-size:15px;font-weight:700;color:#1e293b">AWB# ' +
          (info.awb || d.awb || "") +
          "</div>";
        if (info.total)
          h +=
            '<div style="font-size:12px;color:#94a3b8;margin-top:2px">₹' +
            parseFloat(info.total).toFixed(2) +
            "</div>";
        h += "</div>";
        h +=
          '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' +
          sc +
          "22;color:" +
          sc +
          '">' +
          status +
          "</span>";
        h += "</div>";

        h +=
          '<div style="display:grid;grid-template-columns:1fr 220px;gap:16px;align-items:start">';

        h += "<div>";

        if (pkg.name || pkg.qty || pkg.weight) {
          h += '<div style="margin-bottom:14px">';
          h +=
            '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Package</div>';
          h +=
            '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:13px">';
          if (pkg.name)
            h +=
              '<span style="font-weight:500;color:#1e293b">' +
              pkg.name +
              "</span>";
          if (pkg.qty)
            h += '<span style="color:#6b7280">Qty: ' + pkg.qty + "</span>";
          if (pkg.weight)
            h +=
              '<span style="color:#94a3b8">' +
              Math.round(pkg.weight) +
              " gm</span>";
          if (pkg.price)
            h +=
              '<span style="font-weight:600">₹' +
              parseFloat(pkg.price).toFixed(2) +
              "</span>";
          h += "</div>";
          if (pkg.length && pkg.width && pkg.height) {
            h +=
              '<div style="font-size:11px;color:#94a3b8;margin-top:4px">' +
              pkg.length +
              " × " +
              pkg.width +
              " × " +
              pkg.height +
              " cm</div>";
          }
          h += "</div>";
        }

        if (ship.name || ship.city) {
          h += '<div style="margin-bottom:12px">';
          h +=
            '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">From</div>';
          if (ship.name)
            h +=
              '<div style="font-size:13px;font-weight:500;color:#1e293b">' +
              ship.name +
              "</div>";
          h +=
            '<div style="font-size:12px;color:#6b7280">' +
            [ship.city, ship.state, ship.pincode].filter(Boolean).join(", ") +
            "</div>";
          h += "</div>";
        }

        if (con.name || con.city) {
          h += '<div style="margin-bottom:12px">';
          h +=
            '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">To</div>';
          if (con.name)
            h +=
              '<div style="font-size:13px;font-weight:500;color:#1e293b">' +
              con.name +
              "</div>";
          if (con.address)
            h +=
              '<div style="font-size:12px;color:#6b7280">' +
              con.address +
              "</div>";
          h +=
            '<div style="font-size:12px;color:#6b7280">' +
            [con.city, con.state, con.pincode].filter(Boolean).join(", ") +
            "</div>";
          h += "</div>";
        }

        if (info.payment_mode || info.total) {
          h +=
            '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;font-size:13px">';
          h +=
            '<span style="color:#6b7280">' +
            (info.payment_mode || "Pre-Paid") +
            "</span>";
          if (info.total)
            h +=
              '<span style="font-weight:700">₹' +
              parseFloat(info.total).toFixed(2) +
              "</span>";
          h += "</div>";
        }

        h += "</div>";

        h += '<div style="border-left:1px solid #f1f5f9;padding-left:16px">';
        h +=
          '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Scan Timeline</div>';

        if (scans.length) {
          scans.forEach(function (s, i) {
            var isFirst = i === 0;
            h += '<div style="display:flex;gap:8px;margin-bottom:10px">';
            h += '<div style="flex-shrink:0;margin-top:2px">';
            h +=
              '<div style="width:10px;height:10px;border-radius:50%;background:' +
              (isFirst ? sc : "#d1d5db") +
              ';margin-top:2px"></div>';
            h += "</div>";
            h += '<div style="flex:1">';
            h +=
              '<div style="font-size:12px;font-weight:' +
              (isFirst ? "600" : "400") +
              ";color:" +
              (isFirst ? "#1e293b" : "#6b7280") +
              '">' +
              (s.status || "") +
              "</div>";
            if (s.location)
              h +=
                '<div style="font-size:11px;color:#94a3b8">' +
                s.location +
                "</div>";
            if (s.time)
              h +=
                '<div style="font-size:10px;color:#cbd5e1;margin-top:1px">' +
                s.time +
                "</div>";
            h += "</div>";
            h += "</div>";
          });
        } else {
          h +=
            '<p style="color:#94a3b8;font-size:12px;margin:0">Tracking activates after first scan (30–60 min after pickup).</p>';
        }

        h += "</div>";
        h += "</div>";
        h += "</div>";

        ccModal("Shipment Tracking — " + (d.awb || id), h);
      });
      setTimeout(function () {
        busy($btn, false);
      }, 8000);
    });

    $(document).on("click", ".cc-label", function () {
      var id = $(this).data("order");
      post({ action: "cc_label", order_id: id }, function (d) {
        if (d.label_url) {
          window.open(d.label_url, "_blank");
        } else {
          toast("Label not available yet. Try again shortly.", "err");
        }
      });
    });

    $("#cc-check-all").on("change", function () {
      $(".cc-row-check").prop("checked", this.checked);
    });

    $(document).on("click", ".cc-tab-btn", function () {
      var tab = $(this).data("tab");
      $(".cc-tab-btn").removeClass("active");
      $(this).addClass("active");
      $('[data-tab-panel]').removeClass("active");
      $('[data-tab-panel="' + tab + '"]').addClass("active");
    });

    $("#cc-bulk-push").on("click", function () {
      var ids = $(".cc-row-check:checked")
        .map(function () {
          return this.value;
        })
        .get();
      if (!ids.length) {
        toast("Select at least one order.", "err");
        return;
      }
      if (!confirm("Push " + ids.length + " order(s) to their courier?")) {
        return;
      }
      var $btn = $(this);
      busy($btn, true);
      post({ action: "cc_bulk_push", order_ids: ids }, function (d) {
        toast(
          "Done: " + d.ok + " booked, " + d.failed + " failed.",
          d.failed ? "err" : "ok",
        );
        setTimeout(function () {
          location.reload();
        }, 1200);
      });
      setTimeout(function () {
        busy($btn, false);
      }, 15000);
    });
  });
})(jQuery);
