/**
 * EtchFacets — Etch Builder Settings Bar Control
 *
 * Adds a custom button to the Etch builder Settings Bar that opens
 * an EtchFacets modal. See: https://docs.etchwp.com/integrations/controls
 *
 * @package EtchFacets
 */
(function () {
  "use strict";

  console.log("EtchFacets builder script loaded");

  window.addEventListener("load", () => {
    if (
      !window.etchControls ||
      !window.etchControls.builder ||
      !window.etchControls.builder.settingsBar ||
      !window.etchControls.builder.settingsBar.top ||
      typeof window.etchControls.builder.settingsBar.top.addAfter !== "function"
    ) {
      // Not in the Etch builder — nothing to do.
      return;
    }

    window.etchControls.builder.settingsBar.top.addAfter({
      id: "etchfacets-control",
      icon: "ci:filter",
      tooltip: "EtchFacets",
      callback: openEtchFacetsModal,
    });
  });

  function openEtchFacetsModal() {
    // Avoid duplicates if the user clicks repeatedly.
    const existing = document.getElementById("etchfacets-modal");
    if (existing) {
      return;
    }

    const overlay = document.createElement("div");
    overlay.id = "etchfacets-modal";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.setAttribute("aria-labelledby", "etchfacets-modal-title");
    overlay.style.cssText = [
      "position:fixed",
      "inset:0",
      "background:rgba(0,0,0,0.5)",
      "display:flex",
      "align-items:center",
      "justify-content:center",
      "z-index:2147483647",
    ].join(";");

    const dialog = document.createElement("div");
    dialog.style.cssText = [
      "background:#1e1e1e",
      "color:#fff",
      "min-width:320px",
      "max-width:90vw",
      "padding:24px 28px",
      "border-radius:8px",
      "box-shadow:0 10px 40px rgba(0,0,0,0.5)",
      "font-family:system-ui,-apple-system,sans-serif",
    ].join(";");

    const title = document.createElement("h2");
    title.id = "etchfacets-modal-title";
    title.textContent = "EtchFacets";
    title.style.cssText = "margin:0 0 12px;font-size:18px;font-weight:600;";

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.textContent = "Close";
    closeBtn.style.cssText = [
      "margin-top:16px",
      "padding:6px 14px",
      "background:#3858e9",
      "color:#fff",
      "border:0",
      "border-radius:4px",
      "cursor:pointer",
      "font-size:13px",
    ].join(";");

    const close = () => overlay.remove();
    closeBtn.addEventListener("click", close);
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) close();
    });
    document.addEventListener("keydown", function onKey(e) {
      if (e.key === "Escape") {
        document.removeEventListener("keydown", onKey);
        close();
      }
    });

    dialog.appendChild(title);
    dialog.appendChild(closeBtn);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
  }
})();
