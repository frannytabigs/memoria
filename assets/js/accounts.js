document.addEventListener("DOMContentLoaded", () => {
  const tableBody = document.querySelector("tbody");
  const filterBtn = document.getElementById("filterBtn");
  const searchInput = document.getElementById("userSearch");
  const statusFilter = document.getElementById("statusFilter");
  const roleFilter = document.getElementById("roleFilter");
  const tableContainer = document.querySelector(".table");

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

      <div id="successAlert" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); backdrop-filter:blur(3px); align-items:center; justify-content:center; z-index:10001;">
          <div style="background:#fff; padding:38px 30px; border-radius:18px; width:90%; max-width:380px; text-align:center;">
              <div id="alertIconContainer" style="font-size:34px; margin-bottom:18px;"></div>
              <h3 style="font-size:22px; color:#1e293b; margin-bottom:10px;">Success</h3>
              <p id="successMsg" style="font-size:14px; color:#64748b;"></p>
          </div>
      </div>
      
      <div id="paginationContainer" style="display:none; justify-content:center; gap:8px; margin-top:20px; padding-bottom: 20px;"></div>
    `;

    document.body.appendChild(uiContainer);

    // Append pagination right inside the .table container below the actual <table>
    tableContainer.appendChild(document.getElementById("paginationContainer"));
  };

  injectUIComponents();

  // Cancel edit if clicking outside the editing row or modal
  document.addEventListener("click", (e) => {
    const editingRow = document.querySelector('tr[data-state="edit"]');
    const modal = document.getElementById("confirmModal");

    if (editingRow) {
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

    msg.textContent = message;
    icon.innerHTML =
      type === "delete"
        ? `<i class="fas fa-trash-alt" style="color:#e11d48;"></i>`
        : `<i class="fas fa-check-circle" style="color:#3b82f6;"></i>`;

    alert.style.display = "flex";
    setTimeout(() => {
      alert.style.display = "none";
    }, 1500);
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

  // Triggers automatically whenever the dropdown selection changes
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
        applyLocalFilters(); // Always re-apply dropdowns after loading new data
      } else {
        // FIX: Handle 404 / No users found
        // This triggers the "No records found" row and hides pagination
        alert("No users found in that criteria.");
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

        if (result.success) {
          row.querySelector(".roleCell .viewMode").textContent = roleValue;
          const statusSpan = row.querySelector(".statusCell .viewMode");
          statusSpan.textContent = statusValue;
          statusSpan.className = `viewMode status ${statusValue.toLowerCase()}`;
          row.dataset.state = "view";

          applyLocalFilters();
          showAlert("Account updated successfully.", "save");
        } else {
          alert("Failed to update user.");
        }
      } catch (error) {
        console.error("Update failed", error);
      }
    }

    if (deleteBtn) {
      e.stopPropagation();
      const ok = await showConfirmModal("delete");
      if (!ok) return;

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
          alert("Failed to delete user.");
        }
      } catch (error) {
        console.error("Delete failed", error);
      }
    }
  });

  // --- SEARCH & FILTER EXECUTION --- //

  // Make the Filter button, Search Input, and Search Icon do a master update
  const executeMasterUpdate = () => {
    currentSearch = searchInput.value.trim();
    currentPage = 1;
    loadUsers(); // Fetches from API, renders, then applies current dropdown filters
  };

  filterBtn.addEventListener("click", executeMasterUpdate);

  searchInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      executeMasterUpdate();
    }
  });

  const searchIcon = document.querySelector(".searchBox i");
  if (searchIcon) {
    searchIcon.style.cursor = "pointer";
    searchIcon.addEventListener("click", executeMasterUpdate);
  }

  // Initial Data Load
  loadUsers();
});
