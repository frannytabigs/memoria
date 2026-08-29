document.addEventListener("DOMContentLoaded", () => {
  initNavigation();
  initHomeView();
  initSearchView();
  initPaymentView();
  //   initHoursModal();
  initRecordModal();
});

function showView(viewId) {
  const views = document.querySelectorAll(".mView");
  views.forEach((view) => view.classList.remove("active"));

  const targetView = document.getElementById(viewId);
  if (targetView) {
    targetView.classList.add("active");
  }

  const navLinks = document.querySelectorAll("nav .navLink");
  navLinks.forEach((link) => link.classList.remove("active"));

  if (viewId === "searchView") {
    document.getElementById("navSearchView")?.classList.add("active");
  } else if (viewId === "mapView") {
    document.getElementById("navMapView")?.classList.add("active");
  } else if (viewId === "paymentView") {
    document.getElementById("navPaymentView")?.classList.add("active");
  } else if (viewId === "homeView") {
    document.querySelector('nav a[href="#home"]')?.classList.add("active");
  }
}

function initNavigation() {
  const navLinks = document.querySelectorAll("nav .navLink");

  navLinks.forEach((link) => {
    link.addEventListener("click", (e) => {
      const href = link.getAttribute("href");

      if (href === "#home") {
        e.preventDefault();
        showView("homeView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (href === "#search") {
        e.preventDefault();
        showView("searchView");
      } else if (href === "#map") {
        e.preventDefault();
        showView("mapView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (href === "#payment") {
        e.preventDefault();
        showView("paymentView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    });
  });
}

function initHomeView() {
  const findLovedOneBtn = document.querySelector(".findLovedOneBtn");
  if (findLovedOneBtn) {
    findLovedOneBtn.addEventListener("click", () => showView("searchView"));
  }

  const cemeteryMapBtns = document.querySelectorAll(".cemeteryMapBtn");
  cemeteryMapBtns.forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      showView("mapView");
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  });

  const cards = document.querySelectorAll(".cardGrid .card");
  cards.forEach((card) => {
    card.addEventListener("click", () => {
      const title = card.querySelector("h4")?.textContent.trim();

      if (title === "Search") {
        showView("searchView");
      } else if (title === "Cemetery Map") {
        showView("mapView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (title === "Payments") {
        showView("paymentView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (title === "Burial Requirements") {
        showView("homeView");
        const section = document.getElementById("burialRequirements");
        if (section) {
          section.scrollIntoView({ behavior: "smooth", block: "start" });
        }
      }
    });
  });
}
function initSearchView() {
  const searchForm = document.querySelector("#searchView form");
  const recordsGrid = document.querySelector(".recordsCardGrid");
  const searchInput = searchForm?.querySelector("input");

  if (!searchForm || !recordsGrid) return;

  recordsGrid.innerHTML = "";

  searchForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    // No need to lowercase here, let the database handle the case-insensitive search
    const query = searchInput.value.trim();

    if (!query) {
      recordsGrid.innerHTML = "";
      return;
    }

    // Show a loading state while waiting for the database
    recordsGrid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 2rem;">Searching records...</p>`;

    try {
      // Assuming your REST-ish setup routes 'api/search' to 'search.php'
      const response = await fetch(`api/search?q=${encodeURIComponent(query)}`);

      if (!response.ok) {
        if (response.status === 404) {
          recordsGrid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; padding: 2rem;">Record not found. Perhaps try to be very specific. Contact the office if certain that the records are accurate</p>`;
          return;
        }
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const results = await response.json();

      // Pass the fetched JSON directly to your existing render function
      renderRecords(results.data, recordsGrid);
    } catch (error) {
      console.error("Error fetching records:", error);
      recordsGrid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #ef4444; padding: 2rem;">Failed to connect to the database. Please try again.</p>`;
    }
  });
}

function formatDate(dateString) {
  if (!dateString) return "N/A";
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return dateString;

  return date.toLocaleDateString("en-US", {
    month: "long",
    day: "numeric",
    year: "numeric",
  });
}

function renderRecords(records, container) {
  container.innerHTML = "";

  if (records.length === 0) {
    container.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 2rem;">No matching records found.</p>`;
    return;
  }

  records.forEach((record) => {
    const card = document.createElement("div");
    card.className = "card";

    card.innerHTML = `
            <h4>${record.name}</h4>
            <p><strong>Born:</strong> ${formatDate(record.dateOfBirth)}</p>
            <p><strong>Died:</strong> ${formatDate(record.dateOfDeath)}</p>
        `;

    card.addEventListener("click", () => openRecordModal(record));
    container.appendChild(card);
  });
}

function toggleOtherPurpose(selectElement) {
  const otherWrapper = document.getElementById("otherPurposeWrapper");
  const otherInput = document.getElementById("payOtherPurpose");

  if (!otherWrapper || !otherInput) return;

  if (selectElement.value === "Others") {
    // otherWrapper.classList.remove("hidden");
    // otherInput.setAttribute("required", "true");
  } else {
    otherWrapper.classList.add("hidden");
    otherInput.removeAttribute("required");
    otherInput.value = "";
  }
}

function initPaymentView() {
  const paymentForm = document.getElementById("paymentForm");
  const otherWrapper = document.getElementById("otherPurposeWrapper");
  const otherInput = document.getElementById("payOtherPurpose");

  if (!paymentForm) return;

  paymentForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    // ----- 1. Collect and validate data -----
    const reference = document.getElementById("payReference").value.trim();
    const channel = document.getElementById("payChannel").value;
    const amount = parseFloat(document.getElementById("payAmount").value);
    const purposeSelect = document.getElementById("payPurpose");
    let purpose = purposeSelect.value;
    const remarks = document.getElementById("payRemarks").value.trim();
    const imageFile = document.getElementById("payImage").files[0];

    // Extra fields (not in DB – we'll add them to remarks)
    const fullName = document.getElementById("payName").value.trim();
    const deceasedName = document
      .getElementById("payDeceasedName")
      .value.trim();
    const contact = document.getElementById("payContact").value.trim();
    const email = document.getElementById("payEmail").value.trim();

    // Basic validation
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

    // // Handle "Others" purpose
    // if (purpose === "Others") {
    //   const customPurpose = otherInput ? otherInput.value.trim() : "";
    //   if (!customPurpose) {
    //     return showModal({
    //       type: "error",
    //       title: "Missing Purpose",
    //       message: "Please specify the purpose.",
    //     });
    //   }
    //   purpose = customPurpose; // overwrite with custom text
    // }

    // Build a descriptive remarks string that includes extra info
    let remarksFull = remarks;
    const extraInfo = [];
    if (fullName) extraInfo.push(`Name: ${fullName}`);
    if (deceasedName) extraInfo.push(`Deceased: ${deceasedName}`);
    if (contact) extraInfo.push(`Contact: ${contact}`);
    if (email) extraInfo.push(`Email: ${email}`);
    if (extraInfo.length) {
      remarksFull =
        (remarksFull ? remarksFull + "\n" : "") + extraInfo.join(" | ");
    }

    // ----- 2. Prepare FormData -----
    const formData = new FormData();
    formData.append("reference_number", reference);
    formData.append("payment_channel", channel);
    formData.append("amount", amount);
    formData.append("purpose", purpose);
    formData.append("image", imageFile);
    formData.append("deceased_name", deceasedName);
    formData.append("remarks_payer", remarksFull); // combine everything

    // If you want to send the original purpose (e.g., "Others") for reference, add another field
    // but the backend only uses `purpose`, so we don't.

    // ----- 3. Send request -----
    try {
      // Show a loading state (optional)
      const submitBtn = paymentForm.querySelector('button[type="submit"]');
      const originalText = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Submitting...";

      const response = await fetch("api/payments", {
        method: "POST",
        body: formData,
        // No Content-Type header – browser sets it with boundary for FormData
      });

      const result = await response.json();

      // Reset button state
      submitBtn.disabled = false;
      submitBtn.textContent = originalText;

      if (!response.ok) {
        // Show error from server
        const errorMsg =
          result.message ||
          result.error ||
          "Submission failed. Please try again.";
        showModal({
          type: "error",
          title: "Submission Failed",
          message: errorMsg,
        });
        return;
      }

      // Success
      showModal({
        type: "success",
        title: "Payment For Verification",
        message:
          result.message ||
          "Thank you! Your payment details have been successfully submitted for verification. We will contact you soon!",
        actionText: "Noted",
        allowOutsideClick: false,
      });

      // Reset form and hide "Other" field
      paymentForm.reset();
      if (otherWrapper) otherWrapper.classList.add("hidden");
      if (otherInput) otherInput.value = "";
    } catch (error) {
      console.error("Payment submission error:", error);
      showModal({
        type: "error",
        title: "Network Error",
        message:
          "Unable to reach the server. Please check your internet connection and try again.",
      });
    }
  });
}

// function closeHoursModal() {
//   const hoursModal = document.getElementById("hoursModal");
//   if (hoursModal) {
//     hoursModal.classList.add("hidden");
//   }
// }

// function closeHoursModalOnOverlay(e) {
//   const hoursModal = document.getElementById("hoursModal");
//   if (e.target === hoursModal) {
//     closeHoursModal();
//   }
// }

// function initHoursModal() {
//   const hoursModal = document.getElementById("hoursModal");
//   const triggers = document.querySelectorAll(".officeHoursTrigger");

//   updateCurrentDayHighlight();

//   triggers.forEach((trigger) => {
//     trigger.addEventListener("click", () => {
//       if (hoursModal) {
//         hoursModal.classList.remove("hidden");
//         updateCurrentDayHighlight();
//       }
//     });
//   });
// }

// function updateCurrentDayHighlight() {
//   const now = new Date();
//   const day = now.getDay();
//   const currentHour = now.getHours();

//   document
//     .querySelectorAll(".hoursItem")
//     .forEach((item) => item.classList.remove("activeDay"));

//   const dayElement = document.getElementById(`day_${day}`);
//   if (dayElement) {
//     dayElement.classList.add("activeDay");
//   }

//   const isOpen = day >= 1 && day <= 6 && currentHour >= 8 && currentHour < 17;
//   const statusTextElements = document.querySelectorAll(".modalStatusText");

//   statusTextElements.forEach((el) => {
//     if (isOpen) {
//       el.textContent = "Open Now";
//       el.classList.remove("closed");
//       el.classList.add("open");
//     } else {
//       el.textContent = "Closed Now";
//       el.classList.remove("open");
//       el.classList.add("closed");
//     }
//   });
// }

function initRecordModal() {
  const recordModal = document.getElementById("recordModal");
  const closeBtn = document.getElementById("closeModalBtn");

  if (!recordModal || !closeBtn) return;

  closeBtn.addEventListener("click", closeRecordModal);
  recordModal.addEventListener("click", (e) => {
    if (e.target === recordModal) closeRecordModal();
  });
}

function openRecordModal(record) {
  const recordModal = document.getElementById("recordModal");
  if (!recordModal) return;

  document.getElementById("modalName").textContent = record.name;
  document.getElementById("modalBorn").textContent = formatDate(
    record.dateOfBirth,
  );
  document.getElementById("modalDied").textContent = formatDate(
    record.dateOfDeath,
  );

  const locationStr = `Block ${record.block}, Row ${record.row}, Column ${record.column}`;

  document.getElementById("modalLocation").textContent = locationStr;
  document.getElementById("modalGraveType").textContent =
    record.graveType || "N/A";

  recordModal.classList.add("open");
}

function closeRecordModal() {
  const recordModal = document.getElementById("recordModal");
  if (recordModal) {
    recordModal.classList.remove("open");
  }
}

// 1. Map API setting keys → array of HTML element IDs
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
  // Special keys are handled separately (not in this map)
};

// Helper to populate a <select> from an array
function populateSelect(selectId, items, placeholderText) {
  var select = document.getElementById(selectId);
  if (!select) {
    console.warn("Select element #" + selectId + " not found.");
    return;
  }
  select.innerHTML = "";
  var placeholder = document.createElement("option");
  placeholder.value = "";
  placeholder.disabled = true;
  placeholder.selected = true;
  placeholder.textContent = placeholderText || "Select an option";
  select.appendChild(placeholder);
  if (!Array.isArray(items)) return;
  items.forEach(function (item) {
    var option = document.createElement("option");
    if (typeof item === "object" && item !== null && item.value !== undefined) {
      option.value = item.value;
      option.textContent = item.label || item.value;
    } else {
      option.value = item;
      option.textContent = item;
    }
    select.appendChild(option);
  });
}

// 2. Fetch and populate
function loadSiteContent() {
  fetch("/api/settings", { cache: "no-store" })
    .then(function (response) {
      return response.json();
    })
    .then(function (result) {
      if (!result || !result.data) {
        console.error("Unexpected response structure:", result);
        showAlertTOP(
          "Failed to load settings: Invalid response format",
          "error",
        );
        return;
      }

      var processedKeys = new Set();
      // // // // // console.log("--- Starting to load settings ---");

      result.data.forEach(function (setting) {
        var key = setting.setting_key;
        var val = setting.setting_value;

        if (processedKeys.has(key)) {
          // // // // // console.log("Skipping duplicate (older):", key);
          return;
        }
        processedKeys.add(key);
        // // // // // console.log("Processing key:", key, "→ value:", val);

        // ----- SPECIAL CASES -----

        // 1. Burial Requirements (Markdown → sanitised HTML)
        if (key === "requirements_for_burial") {
          var el = document.getElementById("burialRequirements");
          if (el) {
            var rawHtml = marked.parse(val);
            var cleanHtml = DOMPurify.sanitize(rawHtml);
            el.innerHTML = cleanHtml;
            // // // // // console.log("  ✓ Set burial requirements for #burialRequirements");
            document.getElementById("whatburialrequirements").style.display =
              "none";
          } else {
            console.warn("  ✗ Element #burialRequirements not found");
          }
          return;
        }

        // 2. Google Map embed (secure)
        if (key === "map_embed_url") {
          var el = document.getElementById("cemetery_google_map");
          if (el) {
            var url;
            try {
              url = new URL(val);
              if (url.protocol !== "http:" && url.protocol !== "https:") {
                throw new Error("Only http/https URLs allowed");
              }
            } catch (e) {
              console.warn("  ✗ Invalid map URL:", val);
              el.innerHTML = '<p style="color:red;">Invalid map URL</p>';
              return;
            }
            var iframeHtml = `<iframe
                src="${url.href}"
                allowfullscreen=""
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
              ></iframe>`;
            var cleanIframe = DOMPurify.sanitize(iframeHtml, {
              ADD_TAGS: ["iframe"],
              ADD_ATTR: ["src", "allowfullscreen", "loading", "referrerpolicy"],
            });
            el.innerHTML = cleanIframe;
            // // // // console.log("  ✓ Set map iframe for #cemetery_google_map");
          } else {
            console.warn("  ✗ Element #cemetery_google_map not found");
          }
          return;
        }

        // ----- REGULAR TEXT FIELDS (using the map) -----
        var targetIds = TEXT_SETTINGS_MAP[key];
        if (targetIds) {
          targetIds.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) {
              console.warn("  ✗ Element #" + id + " not found for key: " + key);
              return;
            }
            if (
              el.tagName === "INPUT" ||
              el.tagName === "TEXTAREA" ||
              el.tagName === "SELECT"
            ) {
              el.value = val;
            } else {
              el.textContent = val;
            }
            // // // // console.log("  ✓ Updated #" + id + " → " + val);
          });
        } else {
          // // // // console.log("  (No mapping for key:", key, ")");
        }

        // ----- DROPDOWN LISTS (payment_channels, permit_types) -----
        try {
          if (key === "payment_channels" && val) {
            populateSelect(
              "payChannel",
              JSON.parse(val),
              "Select payment method",
            );
            // // // // console.log("  ✓ Populated payment channels dropdown");
          }
          if (key === "permit_types" && val) {
            populateSelect(
              "payPurpose",
              JSON.parse(val),
              "Select payment purpose",
            );
            // // // // console.log("  ✓ Populated permit types dropdown");
          }
        } catch (jsonError) {
          //console.warn("  ✗ Could not parse JSON for " + key + ":", val);
        }
      });

      // // // // // // console.log(
      //   "--- Finished loading settings. Processed keys:",
      //   Array.from(processedKeys),
      // );
    })
    .catch(function (error) {
      console.error(error);
      //showAlertTOP("Too many requests. Please try again later.", "error");
    });
}

// 3. Run it
loadSiteContent();
