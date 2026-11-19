(function () {
  var el = document.getElementById("sarala-data");
  if (!el) return;
  var d;
  try { d = JSON.parse(el.textContent); } catch (e) { return; }
  var KEY = "sarala_guest";

  // Send customers to their page right after signing in.
  try {
    if (d.guest) {
      sessionStorage.setItem(KEY, "1");
    } else if (sessionStorage.getItem(KEY)) {
      sessionStorage.removeItem(KEY);
      var tags = (d.tags || []).map(function (t) { return String(t).toLowerCase(); });
      var rules = d.redirects || [];
      for (var i = 0; i < rules.length; i++) {
        var r = rules[i];
        if (!r.tag || tags.indexOf(String(r.tag).toLowerCase()) !== -1) {
          if (r.url && r.url !== d.path) window.location.assign(r.url);
          break;
        }
      }
    }
  } catch (e) { /* storage blocked */ }

  // Sign-in bar.
  var bar = document.querySelector(".sarala-prompt");
  if (bar) {
    var closed = false;
    try { closed = sessionStorage.getItem("sarala_prompt_closed") === "1"; } catch (e) {}
    if (!closed) {
      document.body.insertBefore(bar, document.body.firstChild);
      bar.hidden = false;
    }
    var x = bar.querySelector(".sarala-prompt__close");
    if (x) x.addEventListener("click", function () {
      bar.hidden = true;
      try { sessionStorage.setItem("sarala_prompt_closed", "1"); } catch (e) {}
    });
  }

  // "Sign in to see prices" in place of hidden prices.
  if (d.hidePrices && d.priceSelectors) {
    var mark = function () {
      var nodes;
      try { nodes = document.querySelectorAll(d.priceSelectors); } catch (e) { return; }
      for (var j = 0; j < nodes.length; j++) {
        var n = nodes[j];
        if (n.getAttribute("data-sarala") || (n.parentElement && n.parentElement.closest("[data-sarala]"))) continue;
        n.setAttribute("data-sarala", "1");
        var a = document.createElement("a");
        a.className = "sarala-price-link";
        a.href = d.loginUrl;
        a.textContent = d.priceText;
        n.parentNode.insertBefore(a, n);
      }
    };
    mark();
    // Themes load more products (filters, infinite scroll) without a page load.
    new MutationObserver(function () { mark(); }).observe(document.body, { childList: true, subtree: true });
  }
})();
