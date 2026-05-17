document.addEventListener("DOMContentLoaded", () => {
  const tableBody = document.querySelector("tbody");
  const filterBtn = document.getElementById("filterBtn");
  const searchInput = document.getElementById("userSearch");
  const statusFilter = document.getElementById("statusFilter");
  const roleFilter = document.getElementById("roleFilter");
  const tableContainer = document.querySelector(".table");
  const clearSearchBtn = document.getElementById("clearSearchBtn");

  // State Management
  let currentPage = 1;
  let currentSearch = "";

  // SECURITY: XSS Prevention Utility
  const escapeHTML = (str) => {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  };

  const injectUIComponents = () => {
    const uiContainer = document.createElement("div");

    uiContainer.innerHTML = `
      <!-- Confirm Modal -->
      <div id="confirmModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; z-index:10000;">
          <div style="background:#fff; padding:32px; border-radius:16px; width:90%; max-width:400px; text-align:center;">
              <div id="modalIcon" style="font-size:32px; margin-bottom:14px;"></div>
              <h3 id="modalTitle" style="font-size:22px; color:#1e293b; margin-bottom:8px;"></h3>
              <p id="modalDesc" style="font-size:14px; color:#64748b; margin-bottom:22px;"></p>
              <div style="display:flex; gap:12px;">
                  <button id="cancelAction" style="flex:1; border:none; padding:12px; border-radius:8px; background:#f1f5f9; cursor:pointer; color:#1e293b; font-weight:500;">Cancel</button>
                  <button id="confirmAction" style="flex:1; border:none; padding:12px; border-radius:8px; color:#fff; cursor:pointer; font-weight:500;"></button>
              </div>
          </div>
      </div>

      <!-- Success / Error / Warning Alert -->
      <div id="successAlert" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); backdrop-filter:blur(3px); align-items:center; justify-content:center; z-index:10001;">
          <div style="background:#fff; padding:38px 30px; border-radius:18px; width:90%; max-width:380px; text-align:center;">
              <div id="alertIconContainer" style="font-size:34px; margin-bottom:18px;"></div>
              <h3 id="alertTitle" style="font-size:22px; color:#1e293b; margin-bottom:10px;">Success</h3>
              <p id="successMsg" style="font-size:14px; color:#64748b;"></p>
          </div>
      </div>
      
      <!-- Global Loading Overlay -->
      <div id="globalLoader" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.7); backdrop-filter:blur(3px); align-items:center; justify-content:center; z-index:10005; flex-direction:column; color:#fff;">
          <i class="fas fa-spinner fa-spin" style="font-size:42px; margin-bottom:16px;"></i>
          <h3 id="loaderTitle" style="font-size:20px; font-weight:600; margin:0;">Processing...</h3>
          <p style="font-size:14px; color:#cbd5e1; margin-top:8px;">Please wait, this might take a moment.</p>
      </div>

      <div id="paginationContainer" style="display:none; justify-content:center; gap:8px; margin-top:20px; padding-bottom: 20px;"></div>
    `;

    document.body.appendChild(uiContainer);
    tableContainer.appendChild(document.getElementById("paginationContainer"));
  };

  injectUIComponents();

  // --- LOADER UTILITY --- //
  const toggleLoader = (show, title = "Processing...") => {
    const loader = document.getElementById("globalLoader");
    const titleEl = document.getElementById("loaderTitle");
    if (show) {
      titleEl.textContent = title;
      loader.style.display = "flex";
    } else {
      loader.style.display = "none";
    }
  };

  // Cancel edit if clicking outside the editing row or modal
  document.addEventListener("click", (e) => {
    const editingRow = document.querySelector('tr[data-state="edit"]');
    const modal = document.getElementById("confirmModal");
    const loader = document.getElementById("globalLoader");

    if (editingRow && loader.style.display === "none") {
      const isClickInsideRow = editingRow.contains(e.target);
      const isClickInsideModal = modal.contains(e.target);

      if (!isClickInsideRow && !isClickInsideModal) {
        editingRow.dataset.state = "view";

        const currentRole = editingRow
          .querySelector(".roleCell .viewMode")
          .textContent.trim();
        const currentStatus = editingRow
          .querySelector(".statusCell .viewMode")
          .textContent.trim();

        editingRow.querySelector(".roleSelect").value = currentRole;
        editingRow.querySelector(".statusSelect").value = currentStatus;
      }
    }
  });

  const showConfirmModal = (type) =>
    new Promise((resolve) => {
      const modal = document.getElementById("confirmModal");
      const icon = document.getElementById("modalIcon");
      const title = document.getElementById("modalTitle");
      const desc = document.getElementById("modalDesc");
      const confirmBtn = document.getElementById("confirmAction");
      const cancelBtn = document.getElementById("cancelAction");

      const config = {
        save: {
          icon: `<i class="fas fa-question-circle" style="color:#3b82f6;"></i>`,
          title: "Save Changes?",
          desc: "Click save to apply the changes made.",
          text: "Save",
          color: "#3b82f6",
        },
        delete: {
          icon: `<i class="fas fa-trash-alt" style="color:#e11d48;"></i>`,
          title: "Delete Account?",
          desc: "This cannot be undone.",
          text: "Delete",
          color: "#e11d48",
        },
      }[type];

      icon.innerHTML = config.icon;
      title.textContent = config.title;
      desc.textContent = config.desc;
      confirmBtn.textContent = config.text;
      confirmBtn.style.background = config.color;

      modal.style.display = "flex";

      confirmBtn.onclick = () => {
        modal.style.display = "none";
        resolve(true);
      };
      cancelBtn.onclick = () => {
        modal.style.display = "none";
        resolve(false);
      };
    });

  const showAlert = (message, type) => {
    const alert = document.getElementById("successAlert");
    const msg = document.getElementById("successMsg");
    const icon = document.getElementById("alertIconContainer");
    const title = document.getElementById("alertTitle");

    msg.textContent = message;

    if (type === "error") {
      title.textContent = "Error";
      title.style.color = "#e11d48"; // Red
      icon.innerHTML = `<i class="fas fa-exclamation-circle" style="color:#e11d48;"></i>`;
    } else if (type === "warning") {
      title.textContent = "Partial Success";
      title.style.color = "#d97706"; // Amber/Orange
      icon.innerHTML = `<i class="fas fa-exclamation-triangle" style="color:#d97706;"></i>`;
    } else if (type === "delete") {
      title.textContent = "Deleted";
      title.style.color = "#1e293b";
      icon.innerHTML = `<i class="fas fa-trash-alt" style="color:#e11d48;"></i>`;
    } else {
      title.textContent = "Success";
      title.style.color = "#1e293b";
      icon.innerHTML = `<i class="fas fa-check-circle" style="color:#3b82f6;"></i>`;
    }

    alert.style.display = "flex";

    // Give more reading time for errors and warnings
    setTimeout(
      () => {
        alert.style.display = "none";
      },
      type === "error" || type === "warning" ? 3500 : 1500,
    );
  };

  // --- LOCAL FILTERING LOGIC --- //
  const applyLocalFilters = () => {
    const selectedStatus = statusFilter.value.toLowerCase();
    const selectedRole = roleFilter.value.toLowerCase();

    const rows = tableBody.querySelectorAll("tr:not(#noDataRow)");
    let visibleCount = 0;

    rows.forEach((row) => {
      if (row.id === "localNoDataRow") {
        row.remove();
        return;
      }

      const roleSpan = row
        .querySelector(".roleCell .viewMode")
        .textContent.toLowerCase();
      const statusSpan = row
        .querySelector(".statusCell .viewMode")
        .textContent.toLowerCase();

      const roleMatches = selectedRole === "all" || roleSpan === selectedRole;
      const statusMatches =
        selectedStatus === "all" || statusSpan === selectedStatus;

      if (roleMatches && statusMatches) {
        row.style.display = "";
        visibleCount++;
      } else {
        row.style.display = "none";
      }
    });

    if (visibleCount === 0 && rows.length > 0) {
      const tr = document.createElement("tr");
      tr.id = "localNoDataRow";
      tr.innerHTML = `<td colspan="8" style="text-align:center; padding:40px; color:#94a3b8; font-size:14px; font-weight:600;">No matching records found for this filter</td>`;
      tableBody.appendChild(tr);
    }
  };

  statusFilter.addEventListener("change", applyLocalFilters);
  roleFilter.addEventListener("change", applyLocalFilters);

  // --- API LOGIC --- //
  const loadUsers = async () => {
    try {
      const queryParams = new URLSearchParams();
      if (currentPage > 1) queryParams.append("page", currentPage);
      if (currentSearch) queryParams.append("search", currentSearch);

      const queryString = queryParams.toString()
        ? `?${queryParams.toString()}`
        : "";

      const response = await fetch(`api/users${queryString}`);
      const result = await response.json();

      if (result.success) {
        renderTable(result.data.users);
        renderPagination(result.data.pagination);
        applyLocalFilters();
      } else {
        if (typeof showAlertTOP === "function")
          showAlertTOP("No users found in that criteria.", "error");
        renderTable([]);
        renderPagination(null);
      }
    } catch (error) {
      console.error("Error fetching users:", error);
      tableBody.innerHTML = `<tr id="noDataRow"><td colspan="8" style="text-align:center; padding:40px; color:#e11d48;">Error loading data. Check console.</td></tr>`;
    }
  };

  const renderTable = (users) => {
    tableBody.innerHTML = "";

    if (!users || users.length === 0) {
      tableBody.innerHTML = `<tr id="noDataRow"><td colspan="8" style="text-align:center; padding:40px; color:#94a3b8; font-size:14px; font-weight:600;">No records found</td></tr>`;
      return;
    }

    users.forEach((user) => {
      const row = document.createElement("tr");
      row.dataset.state = "view";
      row.dataset.id = escapeHTML(user.user_id);

      const safeName = escapeHTML(user.name);
      const safeUsername = escapeHTML(user.username);
      const safeEmail = escapeHTML(user.email);
      const safePhone = escapeHTML(user.phone_number);
      const safeUserId = escapeHTML(user.user_id);

      const roleLower = escapeHTML(user.role.toLowerCase());
      const statusLower = escapeHTML(user.status.toLowerCase());

      const displayRole = escapeHTML(
        user.role.charAt(0).toUpperCase() + user.role.slice(1).toLowerCase(),
      );
      const displayStatus = escapeHTML(
        user.status.charAt(0).toUpperCase() +
          user.status.slice(1).toLowerCase(),
      );

      row.innerHTML = `
        <td>${safeName}</td>
        <td>${safeUsername}</td>
        <td>${safeEmail}</td>
        <td>${safePhone}</td>
        <td class="roleCell">
          <span class="viewMode">${displayRole}</span>
          <select class="editMode roleSelect">
            <option value="Staff" ${roleLower === "staff" ? "selected" : ""}>Staff</option>
            <option value="Administrator" ${roleLower === "administrator" ? "selected" : ""}>Administrator</option>
          </select>
        </td>
        <td class="statusCell">
          <span class="viewMode status ${statusLower}">${displayStatus}</span>
          <select class="editMode statusSelect">
            <option value="Unverified" ${statusLower === "unverified" ? "selected" : ""}>Unverified</option>
            <option value="Verified" ${statusLower === "verified" ? "selected" : ""}>Verified</option>
          </select>
        </td>
        <td>${safeUserId}</td>
        <td class="actionCell">
          <button class="viewMode editBtn" title="edit">
            <i class="fas fa-edit"></i>
          </button>
          <button class="editMode saveBtn" title="save">
            <i class="fas fa-check"></i>
          </button>
          <button class="deleteBtn" title="delete">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      `;
      tableBody.appendChild(row);
    });
  };

  const renderPagination = (paginationData) => {
    const container = document.getElementById("paginationContainer");
    container.innerHTML = "";

    if (!paginationData || paginationData.total_pages <= 1) {
      container.style.display = "none";
      return;
    }

    container.style.display = "flex";

    for (let i = 1; i <= paginationData.total_pages; i++) {
      const btn = document.createElement("button");
      btn.textContent = i;
      btn.style.padding = "6px 14px";
      btn.style.border = "none";
      btn.style.borderRadius = "6px";
      btn.style.cursor = "pointer";
      btn.style.fontWeight = "500";
      btn.style.background =
        i === paginationData.current_page ? "#2f3136" : "#e2e8f0";
      btn.style.color = i === paginationData.current_page ? "#fff" : "#475569";
      btn.style.transition = "background 0.2s ease";

      btn.addEventListener("mouseover", () => {
        if (i !== paginationData.current_page) btn.style.background = "#cbd5e1";
      });
      btn.addEventListener("mouseout", () => {
        if (i !== paginationData.current_page) btn.style.background = "#e2e8f0";
      });

      btn.addEventListener("click", () => {
        currentPage = i;
        loadUsers();
      });
      container.appendChild(btn);
    }
  };

  // --- ACTION EVENT LISTENERS --- //
  tableBody.addEventListener("click", async (e) => {
    const row = e.target.closest("tr");
    if (!row || row.id === "noDataRow" || row.id === "localNoDataRow") return;
    const userId = row.dataset.id;

    const editBtn = e.target.closest(".editBtn");
    const saveBtn = e.target.closest(".saveBtn");
    const deleteBtn = e.target.closest(".deleteBtn");

    if (editBtn) {
      e.stopPropagation();
      document
        .querySelectorAll('tr[data-state="edit"]')
        .forEach((r) => (r.dataset.state = "view"));
      row.dataset.state = "edit";
    }

    if (saveBtn) {
      e.stopPropagation();
      const ok = await showConfirmModal("save");
      if (!ok) return;

      const roleSelect = row.querySelector(".roleSelect");
      const statusSelect = row.querySelector(".statusSelect");

      const roleValue = roleSelect.value;
      const statusValue = statusSelect.value;

      // If the target state is Verified, we assume an SMS attempt will happen
      // (whether it's the first time, or retrying a failed attempt).
      const isTargetVerified = statusValue.toLowerCase() === "verified";

      // 1. Disable Selects so they can't be changed during request
      roleSelect.disabled = true;
      statusSelect.disabled = true;

      // 2. Trigger the waiting overlay with accurate message
      const loaderMsg = isTargetVerified
        ? "Saving & Sending SMS..."
        : "Saving Changes...";
      toggleLoader(true, loaderMsg);

      try {
        const response = await fetch(`api/users/${userId}`, {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            role: roleValue,
            status: statusValue,
          }),
        });

        const result = await response.json();

        // Check if database save was successful
        if (result.success) {
          row.querySelector(".roleCell .viewMode").textContent = roleValue;
          const statusSpan = row.querySelector(".statusCell .viewMode");
          statusSpan.textContent = statusValue;
          statusSpan.className = `viewMode status ${statusValue.toLowerCase()}`;
          row.dataset.state = "view";

          applyLocalFilters();

          // If the backend signals that the SMS specifically failed despite the save
          if (
            result.sms_failed ||
            (result.message &&
              result.message.toLowerCase().includes("failed to send"))
          ) {
            showAlertTOP(
              result.message || "Saved changes, but the SMS failed to send.",
              "warning",
              6666,
            );
          } else {
            showAlert("Account updated successfully.", "save");
          }
        } else {
          const errorMsg = result.message || "Failed to update user.";
          showAlertTOP(errorMsg, "error");
        }
      } catch (error) {
        console.error("Update failed", error);
        showAlertTOP("A network or server error occurred.", "error");
      } finally {
        // 3. Unlock Selects and remove waiting overlay
        roleSelect.disabled = false;
        statusSelect.disabled = false;
        toggleLoader(false);
      }
    }

    if (deleteBtn) {
      e.stopPropagation();
      const ok = await showConfirmModal("delete");
      if (!ok) return;

      // Trigger the waiting overlay for deleting
      toggleLoader(true, "Deleting user...");

      try {
        const response = await fetch(`api/users/${userId}`, {
          method: "DELETE",
        });

        const result = await response.json();

        if (result.success) {
          showAlert("Account deleted successfully.", "delete");
          if (
            tableBody.querySelectorAll("tr:not(#localNoDataRow)").length ===
              1 &&
            currentPage > 1
          ) {
            currentPage--;
          }
          loadUsers();
        } else {
          const errorMsg = result.message || "Failed to delete user.";
          showAlertTOP(errorMsg, "error");
        }
      } catch (error) {
        console.error("Delete failed", error);
        showAlertTOP("A network or server error occurred.", "error");
      } finally {
        // Remove waiting overlay
        toggleLoader(false);
      }
    }
  });

  // --- SEARCH & FILTER EXECUTION --- //
  const executeMasterUpdate = () => {
    currentSearch = searchInput.value.trim();
    currentPage = 1;
    loadUsers();

    if (clearSearchBtn) {
      clearSearchBtn.style.display =
        currentSearch.length > 0 ? "block" : "none";
    }
  };

  searchInput.addEventListener("input", () => {
    clearSearchBtn.style.display =
      searchInput.value.length > 0 ? "block" : "none";
  });

  if (clearSearchBtn) {
    clearSearchBtn.addEventListener("click", () => {
      searchInput.value = "";
      clearSearchBtn.style.display = "none";
      roleFilter.value = "all";
      statusFilter.value = "all";
      executeMasterUpdate();
    });
  }

  filterBtn.addEventListener("click", executeMasterUpdate);

  searchInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      executeMasterUpdate();
    }
  });

  const searchIcon = document.querySelector(".searchBox .fa-search");

  if (searchIcon) {
    searchIcon.style.cursor = "pointer";
    searchIcon.addEventListener("click", executeMasterUpdate);
  }

  // Initial Data Load
  loadUsers();
});
