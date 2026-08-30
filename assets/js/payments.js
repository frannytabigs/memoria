setTimeout(() => {
  const tableBody = document.getElementById("paymentsTableBody");

  // Grab the user role defined in your HTML script
  const userRole = localStorage.getItem("memoria_role");

  if (!tableBody) return;

  // Helper to create a table cell with safe text
  function createCell(text) {
    const td = document.createElement("td");
    td.textContent = text ?? "-";
    return td;
  }

  // Fetch payments from the backend API
  async function fetchPayments() {
    try {
      const response = await fetch("api/payments");
      const result = await response.json();

      if (result.status === 200) {
        renderTable(result.data.payments);
      } else {
        console.error("Failed to load payments:", result.message);
        tableBody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red;">Error loading payments.</td></tr>`;
      }
    } catch (error) {
      console.error("Error fetching payments:", error);
    }
  }

  // Dynamically build the table rows
  function renderTable(payments) {
    tableBody.innerHTML = "";

    if (!payments || payments.length === 0) {
      tableBody.innerHTML = `<tr><td colspan="9" style="text-align:center;">No payment records found.</td></tr>`;
      return;
    }

    payments.forEach((payment) => {
      const tr = document.createElement("tr");

      // Determine badge color based on overall_status
      let badgeClass = "statusRed";
      if (payment.overall_status === "Pending Grounds")
        badgeClass = "statusOrange";
      if (payment.overall_status === "Completed") badgeClass = "statusGreen";

      // Determine action buttons based on status and user role
      let actionElement;

      if (payment.overall_status === "Pending Office") {
        if (userRole === "Administrator" || userRole === "Office Staff") {
          const btn = document.createElement("button");
          btn.className = "btnAction";
          btn.dataset.id = payment.payment_id;
          btn.dataset.action = "confirm_office";
          btn.textContent = "Verify";
          actionElement = btn;
        } else {
          const span = document.createElement("span");
          span.className = "actionText";
          span.textContent = "Awaiting Office";
          actionElement = span;
        }
      } else if (payment.overall_status === "Pending Grounds") {
        if (userRole === "Administrator" || userRole === "Grounds Staff") {
          const btn = document.createElement("button");
          btn.className = "btnAction";
          btn.dataset.id = payment.payment_id;
          btn.dataset.action = "confirm_grounds";
          btn.textContent = "Verify";
          actionElement = btn;
        } else {
          const span = document.createElement("span");
          span.className = "actionText";
          span.textContent = "Awaiting Grounds";
          actionElement = span;
        }
      } else if (payment.overall_status === "Completed") {
        const span = document.createElement("span");
        span.className = "actionText textComplete";
        span.innerHTML = '<i class="fas fa-check-circle"></i> Verified';
        actionElement = span;
      }

      // Format currency
      const formattedAmount = parseFloat(
        payment.details?.amount ?? 0,
      ).toLocaleString("en-US", { minimumFractionDigits: 2 });

      // Row cells
      tr.appendChild(createCell(payment.deceased_name));
      tr.appendChild(createCell(payment.payers_name));
      tr.appendChild(createCell(payment.details?.channel));
      tr.appendChild(createCell(payment.reference_number));

      const amountTd = document.createElement("td");
      amountTd.textContent = `₱ ${formattedAmount}`;
      tr.appendChild(amountTd);

      // Create the SMS button cell
      const smsTd = document.createElement("td");
      const smsBtn = document.createElement("button");
      smsBtn.type = "button";
      smsBtn.style.display = "inline-flex";
      smsBtn.style.alignItems = "center";
      smsBtn.style.gap = "6px";
      smsBtn.style.padding = "6px 10px";
      smsBtn.style.border = "1px solid #ccc";
      smsBtn.style.borderRadius = "4px";
      smsBtn.style.background = "#f9f9f9";
      smsBtn.style.color = "#333";
      smsBtn.style.fontSize = "13px";
      smsBtn.style.cursor = "pointer";
      smsBtn.onclick = function () {
        makeSmsHandler(
          payment.details.phone_number,
          `Hello ${payment.payers_name}, your payment with the reference number: ${payment.reference_number} is now verified.`,
        );
      };

      const iconsms = document.createElement("i");
      iconsms.className = "fas fa-comment-sms";
      smsBtn.appendChild(iconsms);

      const phoneText = document.createTextNode(payment.details.phone_number);
      smsBtn.appendChild(phoneText);
      smsTd.appendChild(smsBtn);
      tr.appendChild(smsTd);

      tr.appendChild(createCell(payment.details.email));
      tr.appendChild(createCell(payment.details?.purpose));

      // Image button
      const imageTd = document.createElement("td");
      const viewBtn = document.createElement("button");
      viewBtn.className = "btnView";
      viewBtn.style.textDecoration = "none";
      viewBtn.style.display = "inline-block";

      const icon = document.createElement("i");
      icon.className = "fas fa-image";
      viewBtn.appendChild(icon);

      const labelText = document.createTextNode(" View");
      viewBtn.appendChild(labelText);

      viewBtn.dataset.imageLink = payment.details?.image_link ?? "";
      viewBtn.addEventListener("click", () => {
        showpreviewimage(viewBtn.dataset.imageLink);
      });

      imageTd.appendChild(viewBtn);
      tr.appendChild(imageTd);

      tr.appendChild(createCell(payment.details?.remarks_payer));

      // Status badge
      const statusTd = document.createElement("td");
      statusTd.className = "colStatus";
      const badge = document.createElement("span");
      badge.className = `statusBadge ${badgeClass}`;
      badge.textContent = payment.overall_status ?? "-";
      statusTd.appendChild(badge);
      tr.appendChild(statusTd);

      // -------------------------------------------------------------
      // ACTION CELL: Wrapper for both Verify and Delete buttons
      // -------------------------------------------------------------
      const actionTd = document.createElement("td");
      actionTd.className = "colAction";

      const actionWrapper = document.createElement("div");
      actionWrapper.style.display = "flex";
      actionWrapper.style.alignItems = "center";
      actionWrapper.style.gap = "8px";

      // Append the existing verify logic element
      if (actionElement) actionWrapper.appendChild(actionElement);

      // Create Delete Button
      const deleteBtn = document.createElement("button");
      deleteBtn.type = "button";
      deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
      deleteBtn.style.padding = "6px 12px";
      deleteBtn.style.border = "none";
      deleteBtn.style.borderRadius = "4px";
      deleteBtn.style.background = "#ef4444";
      deleteBtn.style.color = "#fff";
      deleteBtn.style.cursor = "pointer";
      deleteBtn.style.fontSize = "13px";
      deleteBtn.style.display = "inline-flex";
      deleteBtn.style.alignItems = "center";
      deleteBtn.style.gap = "6px";
      deleteBtn.style.transition = "background 0.2s";

      // Hover effect for inline styling
      deleteBtn.onmouseover = () => (deleteBtn.style.background = "#dc2626");
      deleteBtn.onmouseout = () => (deleteBtn.style.background = "#ef4444");

      // Trigger the independent modal, passing fetchPayments to refresh the table
      deleteBtn.onclick = () =>
        showDeleteConfirmModal(payment.payment_id, fetchPayments);

      actionWrapper.appendChild(deleteBtn);
      actionTd.appendChild(actionWrapper);
      tr.appendChild(actionTd);
      // -------------------------------------------------------------

      tableBody.appendChild(tr);
    });
  }

  // Handle PUT requests for Action Buttons
  tableBody.addEventListener("click", async (e) => {
    const target = e.target.closest(".btnAction");
    if (!target) return;

    // Prevent double-clicks
    target.disabled = true;
    target.textContent = "Processing...";

    const paymentId = target.dataset.id;
    const actionPayload = target.dataset.action;

    try {
      const response = await fetch(`api/payments/${paymentId}`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ action: actionPayload }),
      });

      const result = await response.json();

      if (result.status === 200) {
        fetchPayments(); // Refresh the table
      } else {
        if (typeof showAlertTOP === "function") {
          showAlertTOP(
            "Cannot process the action. This failed: " + result.message,
            "error",
          );
        } else {
          alert("Cannot process the action. This failed: " + result.message);
        }
        target.disabled = false;
        target.textContent = "Retry";
      }
    } catch (error) {
      console.error("Error updating payment:", error);
      if (typeof showAlertTOP === "function") {
        showAlertTOP("A network error occurred while verifying.", "error");
      } else {
        alert("A network error occurred while verifying.");
      }
      target.disabled = false;
      target.textContent = "Retry";
    }
  });

  // Initialize the table on load
  fetchPayments();
}, 1000);

// ------------------------------------------------------------------
// Delete Confirmation Modal Logic (Completely Independent)
// ------------------------------------------------------------------
function showDeleteConfirmModal(paymentId, refreshCallback) {
  injectDeleteStyles();

  // Create overlay
  const overlay = document.createElement("div");
  overlay.id = "deleteConfirmOverlay";
  overlay.className = "memoria-delete-overlay";

  // Create modal box
  const box = document.createElement("div");
  box.className = "memoria-delete-box";
  box.innerHTML = `
    <div style="font-weight: 600; font-size: 1.25rem; margin-bottom: 8px; color: #1e293b;">Delete Payment</div>
    <div style="color: #64748b; font-size: 0.95rem; margin-bottom: 24px;">Are you sure you want to delete this payment record? This action cannot be undone.</div>
    <div class="memoria-delete-actions">
      <button class="memoria-btn-cancel" id="btnCancelDelete">Cancel</button>
      <button class="memoria-btn-confirm" id="btnConfirmDelete">Delete</button>
    </div>
  `;

  overlay.appendChild(box);
  document.body.appendChild(overlay);

  // Close function
  const closeModal = () => {
    overlay.style.animation = "fadeOut 0.2s ease forwards";
    setTimeout(() => overlay.remove(), 200);
  };

  document.getElementById("btnCancelDelete").onclick = closeModal;

  // Close if clicking outside the box
  overlay.onclick = (e) => {
    if (e.target === overlay) closeModal();
  };

  // Confirm function
  document.getElementById("btnConfirmDelete").onclick = async (e) => {
    const btn = e.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

    try {
      const response = await fetch(`api/payments/${paymentId}`, {
        method: "DELETE",
        headers: { Accept: "application/json" },
      });
      const result = await response.json();

      if (result.status === 200) {
        closeModal();
        if (refreshCallback) refreshCallback(); // refresh table
      } else {
        if (typeof showAlertTOP === "function") {
          showAlertTOP("Failed to delete: " + result.message, "error");
        } else {
          alert("Failed to delete: " + result.message);
        }
        btn.disabled = false;
        btn.textContent = "Delete";
      }
    } catch (error) {
      console.error("Error deleting payment:", error);
      if (typeof showAlertTOP === "function") {
        showAlertTOP("Network error during deletion.", "error");
      } else {
        alert("Network error during deletion.");
      }
      btn.disabled = false;
      btn.textContent = "Delete";
    }
  };
}

function injectDeleteStyles() {
  if (document.getElementById("memoriaDeleteStyles")) return;
  const style = document.createElement("style");
  style.id = "memoriaDeleteStyles";
  style.textContent = `
    .memoria-delete-overlay {
      position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
      background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
      display: flex; align-items: center; justify-content: center;
      z-index: 10000; animation: fadeIn 0.2s ease forwards;
      font-family: system-ui, -apple-system, sans-serif;
    }
    .memoria-delete-box {
      background: #ffffff; padding: 24px; border-radius: 12px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
      max-width: 400px; width: 90%; text-align: center;
    }
    .memoria-delete-actions {
      display: flex; gap: 12px; justify-content: center;
    }
    .memoria-btn-cancel {
      padding: 8px 16px; border-radius: 6px; border: 1px solid #e2e8f0;
      background: #f8fafc; color: #475569; cursor: pointer; font-weight: 500; font-size: 14px;
      transition: all 0.2s;
    }
    .memoria-btn-cancel:hover { background: #f1f5f9; }
    .memoria-btn-confirm {
      padding: 8px 16px; border-radius: 6px; border: none; font-size: 14px;
      background: #ef4444; color: white; cursor: pointer; font-weight: 500;
      transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
    }
    .memoria-btn-confirm:hover { background: #dc2626; }
    .memoria-btn-confirm:disabled { opacity: 0.7; cursor: not-allowed; }
  `;
  document.head.appendChild(style);
}

// ------------------------------------------------------------------
// (Your existing preview image code remains below)
// ------------------------------------------------------------------
function showpreviewimage(link) {
  if (!link || typeof link !== "string") {
    showErrorPopup("No image link provided.");
    return;
  }

  // 1. Create overlay immediately to show a loading state
  const overlay = createBaseOverlay();
  showLoading(overlay);

  // 2. Build URLs
  let originalUrl;
  try {
    originalUrl = new URL(link, window.location.origin).href;
  } catch (_) {
    showErrorPopup("Invalid image link.", overlay);
    return;
  }

  // 3. Fallback URL logic
  // If it's already an absolute URL or API path, try it. If it fails, fallback to your API structure.
  let fallbackUrl = link.startsWith("http")
    ? link
    : `${window.location.origin}/api/images/${link.replace(/^\//, "")}`;

  // 4. Try loading images
  tryLoadImage(originalUrl)
    .then(() => {
      renderImage(originalUrl, overlay);
    })
    .catch(() => {
      // Original failed – try fallback
      return tryLoadImage(fallbackUrl)
        .then(() => renderImage(fallbackUrl, overlay))
        .catch(() => {
          // Both failed – show graceful error
          showErrorPopup(
            "Looks like the image is not found. It may have been moved or deleted.",
            overlay,
          );
        });
    });
}

// ------------------------------------------------------------------
// Core Loading Logic
// ------------------------------------------------------------------
function tryLoadImage(url) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    let resolved = false;

    const timeout = setTimeout(() => {
      if (!resolved) {
        resolved = true;
        reject(new Error("Timeout loading image"));
      }
    }, 10000);

    img.onload = () => {
      if (!resolved) {
        resolved = true;
        clearTimeout(timeout);
        resolve();
      }
    };

    img.onerror = () => {
      if (!resolved) {
        resolved = true;
        clearTimeout(timeout);
        reject(new Error("Failed to load image"));
      }
    };

    img.src = url;
  });
}

// ------------------------------------------------------------------
// UI Elements (Glassmorphism & Dark Theme)
// ------------------------------------------------------------------
function createBaseOverlay() {
  removeExistingModal();
  injectStyles();

  const overlay = document.createElement("div");
  overlay.id = "imagePreviewOverlay";
  overlay.className = "memoria-glass-overlay";

  // Click overlay to dismiss
  overlay.onclick = removeExistingModal;
  document.body.appendChild(overlay);

  return overlay;
}

function showLoading(overlay) {
  overlay.innerHTML = '<div class="memoria-spinner"></div>';
}

function renderImage(imageUrl, overlay) {
  overlay.innerHTML = ""; // Clear loading spinner

  const closeBtn = document.createElement("button");
  closeBtn.innerHTML = `
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="18" y1="6" x2="6" y2="18"></line>
      <line x1="6" y1="6" x2="18" y2="18"></line>
    </svg>
  `;
  closeBtn.className = "memoria-close-btn";
  closeBtn.onclick = (e) => {
    e.stopPropagation();
    removeExistingModal();
  };

  const img = document.createElement("img");
  img.src = imageUrl;
  img.className = "memoria-preview-img";
  img.onclick = (e) => e.stopPropagation(); // Prevent closing when clicking image

  overlay.appendChild(closeBtn);
  overlay.appendChild(img);
}

function showErrorPopup(message, existingOverlay = null) {
  const overlay = existingOverlay || createBaseOverlay();
  overlay.innerHTML = ""; // Clear spinner or old content

  const closeBtn = document.createElement("button");
  closeBtn.innerHTML = `
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="18" y1="6" x2="6" y2="18"></line>
      <line x1="6" y1="6" x2="18" y2="18"></line>
    </svg>
  `;
  closeBtn.className = "memoria-close-btn";

  const box = document.createElement("div");
  box.className = "memoria-error-box";
  box.innerHTML = `
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px;">
      <circle cx="12" cy="12" r="10"></circle>
      <line x1="15" y1="9" x2="9" y2="15"></line>
      <line x1="9" y1="9" x2="15" y2="15"></line>
    </svg>
    <div style="font-weight: 600; font-size: 1.1rem; margin-bottom: 4px; color: #f8fafc;">Image Unavailable</div>
    <div style="color: #94a3b8; font-size: 0.9rem;">${message}</div>
  `;

  box.onclick = (e) => e.stopPropagation();

  overlay.appendChild(closeBtn);
  overlay.appendChild(box);
}

function removeExistingModal() {
  const existing = document.getElementById("imagePreviewOverlay");
  if (existing) {
    // Add fade out animation before removing
    existing.style.animation = "fadeOut 0.2s ease forwards";
    setTimeout(() => existing.remove(), 200);
  }
}

// ------------------------------------------------------------------
// Injected CSS Styles
// ------------------------------------------------------------------
function injectStyles() {
  if (document.getElementById("memoriaPreviewStyles")) return;

  const style = document.createElement("style");
  style.id = "memoriaPreviewStyles";
  style.textContent = `
    .memoria-glass-overlay {
      position: fixed;
      top: 0; left: 0;
      width: 100vw; height: 100vh;
      background: rgba(15, 23, 42, 0.7);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      cursor: pointer;
      animation: fadeIn 0.3s ease forwards;
      font-family: system-ui, -apple-system, sans-serif;
    }

    .memoria-close-btn {
      position: absolute;
      top: 24px; right: 24px;
      width: 44px; height: 44px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s ease;
      z-index: 10000;
    }
    
    .memoria-close-btn:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: scale(1.05);
    }

    .memoria-preview-img {
      max-width: 90vw;
      max-height: 90vh;
      border-radius: 12px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
      object-fit: contain;
      cursor: default;
      border: 1px solid rgba(255, 255, 255, 0.1);
      background: rgba(0, 0, 0, 0.2);
    }

    .memoria-error-box {
      background: rgba(30, 41, 59, 0.8);
      border: 1px solid rgba(255, 255, 255, 0.1);
      padding: 32px 24px;
      border-radius: 16px;
      text-align: center;
      box-shadow: 0 20px 40px rgba(0,0,0,0.4);
      max-width: 360px;
      cursor: default;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .memoria-spinner {
      width: 40px;
      height: 40px;
      border: 3px solid rgba(255, 255, 255, 0.1);
      border-radius: 50%;
      border-top-color: #3b82f6;
      animation: spin 1s ease-in-out infinite;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    @keyframes fadeOut {
      from { opacity: 1; }
      to { opacity: 0; }
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  `;
  document.head.appendChild(style);
}
