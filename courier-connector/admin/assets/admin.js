/* Courier Connector — admin dashboard JS */
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
      '<div id="cc-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px"></div>'
    );
    var $box = $(
      '<div style="background:#fff;border-radius:8px;width:100%;max-width:660px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.25)"></div>'
    );
    var $hdr = $(
      '<div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e5e7eb"></div>'
    );
    $hdr.append('<h3 style="margin:0;font-size:15px">' + title + "</h3>");
    var $close = $(
      '<button style="background:none;border:none;font-size:22px;cursor:pointer;line-height:1;color:#6b7280">&times;</button>'
    );
    $hdr.append($close);
    $box.append($hdr);
    $box.append(
      '<div style="padding:16px 20px;overflow-y:auto">' + html + "</div>"
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
    // Push to Delhivery.
    $(document).on("click", ".cc-push", function () {
      var $btn = $(this),
        id = $btn.data("order");
      busy($btn, true);
      post({ action: "cc_push_shipment", order_id: id }, function (d) {
        toast(d.message || "Shipment created.", "ok");
        setTimeout(function () {
          location.reload();
        }, 900);
      });
      // post() handles failure; re-enable via timeout fallback.
      setTimeout(function () {
        busy($btn, false);
      }, 6000);
    });

    // Cancel shipment.
    $(document).on("click", ".cc-cancel", function () {
      if (!confirm("Cancel this shipment with Delhivery?")) {
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

    // Track — shows modal with full scan timeline.
    $(document).on("click", ".cc-track", function () {
      var $btn = $(this),
        id = $btn.data("order");
      busy($btn, true);
      post({ action: "cc_track", order_id: id }, function (d) {
        var html =
          '<p style="margin:0 0 12px"><span style="font-weight:600">' +
          (d.status || "Unknown") +
          "</span>";
        if (d.location)
          html += ' &mdash; <span style="color:#6b7280">' + d.location + "</span>";
        html += "</p>";
        if (d.awb)
          html +=
            '<p style="margin:0 0 12px;font-size:12px;color:#6b7280">AWB: <code>' +
            d.awb +
            "</code></p>";
        if (d.scans && d.scans.length) {
          html +=
            '<table style="width:100%;border-collapse:collapse;font-size:13px">';
          html +=
            '<thead><tr style="background:#f3f4f6"><th style="text-align:left;padding:6px 10px">Date / Time</th><th style="text-align:left;padding:6px 10px">Status</th><th style="text-align:left;padding:6px 10px">Location</th></tr></thead><tbody>';
          d.scans.forEach(function (s) {
            html +=
              "<tr><td style='padding:6px 10px;border-bottom:1px solid #f0f0f0'>" +
              (s.time || "") +
              "</td><td style='padding:6px 10px;border-bottom:1px solid #f0f0f0'>" +
              (s.status || "") +
              "</td><td style='padding:6px 10px;border-bottom:1px solid #f0f0f0'>" +
              (s.location || "") +
              "</td></tr>";
          });
          html += "</tbody></table>";
        } else {
          html += '<p style="color:#6b7280;font-size:13px">No scan events yet.</p>';
        }
        ccModal("Shipment Tracking", html);
      });
      setTimeout(function () {
        busy($btn, false);
      }, 8000);
    });

    // Label.
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

    // Select all.
    $("#cc-check-all").on("change", function () {
      $(".cc-row-check").prop("checked", this.checked);
    });

    // Bulk push.
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
      if (!confirm("Push " + ids.length + " order(s) to Delhivery?")) {
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
