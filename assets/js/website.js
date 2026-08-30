// ============================================================
//  CEMETERY MANAGEMENT – PUBLIC FRONTEND SCRIPT
// ============================================================

// ---------- Constants & Mappings ----------
const TEXT_SETTINGS_MAP = {
  main_title: ["heading_one", "heading_one1"],
  cemetery_name: [
    "cemetery_name",
    "cemetery_name1",
    "cemetery_name2",
    "cemetery_name3",
  ],
  cemetery_address: ["cemetery_address", "cemetery_address1"],
  office_address: ["cemetery_office_location"],
  office_hours: ["cemetery_office_hours"],
  contact_phone: ["cemetery_phone_number"],
  contact_email: ["cemetery_email"],
  // Special keys (burial_requirements, map_embed_url) handled separately
};

// ---------- Helpers ----------
// Populate a <select> with options from an array
function populateSelect(selectId, items, placeholderText = "Select an option") {
  const select = document.getElementById(selectId);
  if (!select) {
    console.warn(`Select #${selectId} not found.`);
    return;
  }
  select.innerHTML = "";
  const placeholder = document.createElement("option");
  placeholder.value = "";
  placeholder.disabled = true;
  placeholder.selected = true;
  placeholder.textContent = placeholderText;
  select.appendChild(placeholder);

  if (!Array.isArray(items)) return;
  for (const item of items) {
    const option = document.createElement("option");
    if (typeof item === "object" && item !== null && item.value !== undefined) {
      option.value = item.value;
      option.textContent = item.label || item.value;
    } else {
      option.value = item;
      option.textContent = item;
    }
    select.appendChild(option);
  }
}

// Safely format a date string
function formatDate(dateString) {
  if (!dateString) return "N/A";
  const date = new Date(dateString);
  return isNaN(date.getTime())
    ? dateString
    : date.toLocaleDateString("en-US", {
        month: "long",
        day: "numeric",
        year: "numeric",
      });
}

// ---------- Load settings from API ----------
function loadSiteContent() {
  fetch("/api/settings", { cache: "no-store" })
    .then((response) => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return response.json();
    })
    .then((result) => {
      if (!result?.data) {
        console.error("Invalid settings response:", result);
        showAlertTOP("Failed to load settings: invalid response", "error");
        return;
      }

      const processedKeys = new Set();

      for (const setting of result.data) {
        const key = setting.setting_key;
        const val = setting.setting_value;

        if (processedKeys.has(key)) continue; // skip duplicates
        processedKeys.add(key);

        // ---------- SPECIAL CASES ----------
        // 1. Burial Requirements (Markdown → sanitised HTML)
        if (key === "requirements_for_burial") {
          const el = document.getElementById("burialRequirements");
          if (el) {
            const rawHtml = marked.parse(val);
            const cleanHtml = DOMPurify.sanitize(rawHtml);
            el.innerHTML = cleanHtml;
            const what = document.getElementById("whatburialrequirements");
            if (what) what.style.display = "none";
          } else {
            console.warn("Element #burialRequirements not found");
          }
          continue;
        }

        // 2. Google Map embed (secure)
        if (key === "map_embed_url") {
          const el = document.getElementById("cemetery_google_map");
          if (!el) {
            console.warn("Element #cemetery_google_map not found");
            continue;
          }
          let url;
          try {
            url = new URL(val);
            if (!["http:", "https:"].includes(url.protocol)) {
              throw new Error("Only HTTP/HTTPS allowed");
            }
          } catch {
            console.warn("Invalid map URL:", val);
            el.innerHTML = '<p style="color:red;">Invalid map URL</p>';
            continue;
          }
          const iframeHtml = `<iframe
            src="${url.href}"
            allowfullscreen=""
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
          ></iframe>`;
          const cleanIframe = DOMPurify.sanitize(iframeHtml, {
            ADD_TAGS: ["iframe"],
            ADD_ATTR: ["src", "allowfullscreen", "loading", "referrerpolicy"],
          });
          el.innerHTML = cleanIframe;
          continue;
        }

        // ---------- REGULAR TEXT FIELDS ----------
        const targetIds = TEXT_SETTINGS_MAP[key];
        if (targetIds) {
          for (const id of targetIds) {
            const el = document.getElementById(id);
            if (!el) {
              console.warn(`Element #${id} not found for key "${key}"`);
              continue;
            }
            if (["INPUT", "TEXTAREA", "SELECT"].includes(el.tagName)) {
              el.value = val;
            } else {
              el.textContent = val; // safe
            }
          }
        }

        // ---------- DROPDOWN LISTS ----------
        try {
          if (key === "payment_channels" && val) {
            populateSelect(
              "payChannel",
              JSON.parse(val),
              "Select payment method",
            );
          }
          if (key === "permit_types" && val) {
            populateSelect(
              "payPurpose",
              JSON.parse(val),
              "Select payment purpose",
            );
          }
        } catch {
          // ignore JSON parse errors
        }
      }
    })
    .catch((error) => {
      console.error("Error loading settings:", error);
      // Optionally show a generic alert
    });
}

// ---------- Navigation ----------
function showView(viewId) {
  document
    .querySelectorAll(".mView")
    .forEach((view) => view.classList.remove("active"));
  const target = document.getElementById(viewId);
  if (target) target.classList.add("active");

  document
    .querySelectorAll("nav .navLink")
    .forEach((link) => link.classList.remove("active"));
  const navMap = {
    searchView: "navSearchView",
    mapView: "navMapView",
    paymentView: "navPaymentView",
    homeView: "navHomeView", // adjust if your HTML has an id for home nav
  };
  const navId = navMap[viewId];
  if (navId) {
    const navEl = document.getElementById(navId);
    if (navEl) navEl.classList.add("active");
  }
  // fallback for home if no specific id
  if (viewId === "homeView") {
    document.querySelector('nav a[href="#home"]')?.classList.add("active");
  }
}

function initNavigation() {
  document.querySelectorAll("nav .navLink").forEach((link) => {
    link.addEventListener("click", (e) => {
      e.preventDefault();
      const href = link.getAttribute("href");
      if (href === "#home") {
        showView("homeView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (href === "#search") {
        showView("searchView");
      } else if (href === "#map") {
        showView("mapView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (href === "#payment") {
        showView("paymentView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    });
  });
}

// ---------- Home View ----------
function initHomeView() {
  const findBtn = document.querySelector(".findLovedOneBtn");
  if (findBtn) {
    findBtn.addEventListener("click", () => showView("searchView"));
  }

  document.querySelectorAll(".cemeteryMapBtn").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      showView("mapView");
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  });

  document.querySelectorAll(".cardGrid .card").forEach((card) => {
    card.addEventListener("click", () => {
      const title = card.querySelector("h4")?.textContent?.trim() || "";
      if (title === "Search") showView("searchView");
      else if (title === "Cemetery Map") {
        showView("mapView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (title === "Payments") {
        showView("paymentView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (title === "Burial Requirements") {
        showView("homeView");
        const section = document.getElementById("burialRequirements");
        if (section)
          section.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  });
}

// ---------- Search View ----------
function renderRecords(records, container) {
  container.innerHTML = "";
  if (!records || records.length === 0) {
    container.innerHTML = `<p style="grid-column:1/-1;text-align:center;color:#64748b;padding:2rem;">No matching records found.</p>`;
    return;
  }
  for (const record of records) {
    const card = document.createElement("div");
    card.className = "card";
    card.innerHTML = `
      <h4>${DOMPurify.sanitize(record.name)}</h4>
      <p><strong>Born:</strong> ${formatDate(record.dateOfBirth)}</p>
      <p><strong>Died:</strong> ${formatDate(record.dateOfDeath)}</p>
    `;
    card.addEventListener("click", () => openRecordModal(record));
    container.appendChild(card);
  }
}

function initSearchView() {
  const searchForm = document.querySelector("#searchView form");
  const recordsGrid = document.querySelector(".recordsCardGrid");
  if (!searchForm || !recordsGrid) return;

  recordsGrid.innerHTML = "";
  const searchInput = searchForm.querySelector("input");
  if (!searchInput) return;

  searchForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const query = searchInput.value.trim();
    if (!query) {
      recordsGrid.innerHTML = "";
      return;
    }

    recordsGrid.innerHTML = `<p style="grid-column:1/-1;text-align:center;color:#64748b;padding:2rem;">Searching records...</p>`;

    try {
      const response = await fetch(`api/search?q=${encodeURIComponent(query)}`);
      if (!response.ok) {
        if (response.status === 404) {
          recordsGrid.innerHTML = `<p style="grid-column:1/-1;text-align:center;padding:2rem;">Record not found. Perhaps try to be very specific. Contact the office if certain that the records are accurate</p>`;
          return;
        }
        throw new Error(`HTTP ${response.status}`);
      }
      const data = await response.json();
      renderRecords(data.data, recordsGrid);
    } catch (error) {
      console.error("Search error:", error);
      recordsGrid.innerHTML = `<p style="grid-column:1/-1;text-align:center;color:#ef4444;padding:2rem;">Failed to connect to the database. Please try again.</p>`;
    }
  });
}

// ---------- Payment View ----------
function initPaymentView() {
  const paymentForm = document.getElementById("paymentForm");
  if (!paymentForm) return;

  const otherWrapper = document.getElementById("otherPurposeWrapper");
  const otherInput = document.getElementById("payOtherPurpose");

  paymentForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    // Collect fields
    const reference =
      document.getElementById("payReference")?.value?.trim() || "";
    const channel = document.getElementById("payChannel")?.value || "";
    const amount = parseFloat(document.getElementById("payAmount")?.value || 0);
    const purposeSelect = document.getElementById("payPurpose");
    let purpose = purposeSelect?.value || "";
    const remarks = document.getElementById("payRemarks")?.value?.trim() || "";
    const imageFile = document.getElementById("payImage")?.files?.[0] || null;

    const fullName = document.getElementById("payName")?.value?.trim() || "";
    const deceasedName =
      document.getElementById("payDeceasedName")?.value?.trim() || "";
    const contact = document.getElementById("payContact")?.value?.trim() || "";
    const email = document.getElementById("payEmail")?.value?.trim() || "";

    // Validation
    if (!reference)
      return showModal({
        type: "error",
        title: "Missing Reference",
        message: "Please enter a reference number.",
      });
    if (!channel)
      return showModal({
        type: "error",
        title: "Missing Channel",
        message: "Please select a payment channel.",
      });
    if (!amount || amount <= 0)
      return showModal({
        type: "error",
        title: "Invalid Amount",
        message: "Amount must be greater than zero.",
      });
    if (!purpose)
      return showModal({
        type: "error",
        title: "Missing Purpose",
        message: "Please select a payment purpose.",
      });
    if (!imageFile)
      return showModal({
        type: "error",
        title: "Missing Image",
        message: "Please upload a proof of payment image.",
      });

    // Combine extra info into remarks
    const extra = [];
    if (fullName) extra.push(`Name: ${fullName}`);
    if (deceasedName) extra.push(`Deceased: ${deceasedName}`);
    if (contact) extra.push(`Contact: ${contact}`);
    if (email) extra.push(`Email: ${email}`);
    const remarksFull = [remarks, ...extra].filter(Boolean).join(" | ");

    // Build FormData
    const formData = new FormData();
    formData.append("reference_number", reference);
    formData.append("payment_channel", channel);
    formData.append("amount", amount);
    formData.append("purpose", purpose);
    formData.append("image", imageFile);
    formData.append("deceased_name", deceasedName);
    formData.append("remarks_payer", remarksFull);

    // Submit
    try {
      const submitBtn = paymentForm.querySelector('button[type="submit"]');
      const originalText = submitBtn?.textContent || "Submit";
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Submitting...";
      }

      const response = await fetch("api/payments", {
        method: "POST",
        body: formData,
      });
      const result = await response.json();

      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      }

      if (!response.ok) {
        const errMsg =
          result?.message ||
          result?.error ||
          "Submission failed. Please try again.";
        return showModal({
          type: "error",
          title: "Submission Failed",
          message: errMsg,
        });
      }

      showModal({
        type: "success",
        title: "Payment For Verification",
        message:
          result?.message ||
          "Thank you! Your payment details have been successfully submitted for verification. We will contact you soon!",
        actionText: "Noted",
        allowOutsideClick: false,
      });

      paymentForm.reset();
      if (otherWrapper) otherWrapper.classList.add("hidden");
      if (otherInput) otherInput.value = "";
    } catch (error) {
      console.error("Payment error:", error);
      showModal({
        type: "error",
        title: "Network Error",
        message:
          "Unable to reach the server. Please check your internet connection and try again.",
      });
    }
  });
}

// ---------- Record Modal ----------
function initRecordModal() {
  const modal = document.getElementById("recordModal");
  const closeBtn = document.getElementById("closeModalBtn");
  if (!modal || !closeBtn) return;

  closeBtn.addEventListener("click", closeRecordModal);
  modal.addEventListener("click", (e) => {
    if (e.target === modal) closeRecordModal();
  });
}

function openRecordModal(record) {
  const modal = document.getElementById("recordModal");
  if (!modal) return;

  document.getElementById("modalName").textContent = record.name || "";
  document.getElementById("modalBorn").textContent = formatDate(
    record.dateOfBirth,
  );
  document.getElementById("modalDied").textContent = formatDate(
    record.dateOfDeath,
  );
  document.getElementById("modalLocation").textContent =
    `Block ${record.block || "?"}, Row ${record.row || "?"}, Column ${record.column || "?"}`;
  document.getElementById("modalGraveType").textContent =
    record.graveType || "N/A";

  modal.classList.add("open");
}

function closeRecordModal() {
  const modal = document.getElementById("recordModal");
  if (modal) modal.classList.remove("open");
}

// ---------- Initialisation on DOM ready ----------
document.addEventListener("DOMContentLoaded", () => {
  initNavigation();
  initHomeView();
  initSearchView();
  initPaymentView();
  initRecordModal();
  loadSiteContent();
});
