document.addEventListener("DOMContentLoaded", () => {
  const tableBody = document.querySelector("tbody");
  const filterBtn = document.getElementById("filterBtn");
  const searchInput = document.getElementById("userSearch");
  const statusFilter = document.getElementById("statusFilter");
  const roleFilter = document.getElementById("roleFilter");

  // --- 1. UI COMPONENTS INJECTION ---
  const injectUIComponents = () => {
    const uiContainer = document.createElement("div");

    uiContainer.innerHTML = `
      <style>
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
      </style>
      
      <!-- LOADING OVERLAY -->
      <div id="loadingOverlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; z-index:10005; flex-direction: column;">
        <div style="border: 4px solid rgba(216, 180, 226, 0.2); border-top: 4px solid #3b82f6; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom: 15px;"></div>
        <h3 id="loadingText" style="color:#ffffff; font-size:16px; letter-spacing: 1px;">Processing...</h3>
      </div>

      <!-- CONFIRMATION MODAL -->
      <div id="confirmModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; z-index:10000;">
        <div style="background:#fff; padding:32px; border-radius:16px; width:90%; max-width:400px; text-align:center; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
          <div id="modalIcon" style="font-size:32px; margin-bottom:14px;"></div>
          <h3 id="modalTitle" style="font-size:22px; color:#1e293b; margin-bottom:8px;"></h3>
          <p id="modalDesc" style="font-size:14px; color:#64748b; margin-bottom:22px;"></p>
          <div style="display:flex; gap:12px;">
            <button id="cancelAction" style="flex:1; border:none; padding:12px; border-radius:8px; background:#f1f5f9; color:#475569; font-weight:600; cursor:pointer;">Cancel</button>
            <button id="confirmAction" style="flex:1; border:none; padding:12px; border-radius:8px; color:#fff; font-weight:600; cursor:pointer;"></button>
          </div>
        </div>
      </div>

      <!-- SUCCESS ALERT -->
      <div id="successAlert" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); backdrop-filter:blur(3px); align-items:center; justify-content:center; z-index:10001; cursor: pointer;">
        <div style="background:#fff; padding:38px 30px; border-radius:18px; width:90%; max-width:380px; text-align:center; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); cursor: default;">
          <div id="alertIconContainer" style="font-size:34px; margin-bottom:18px;"></div>
          <h3 style="font-size:22px; color:#1e293b; margin-bottom:10px;">Success</h3>
          <p id="successMsg" style="font-size:15px; color:#64748b; margin-bottom:0;"></p>
        </div>
      </div>
    `;
    document.body.appendChild(uiContainer);
  };
  injectUIComponents();

  // --- 2. HELPER FUNCTIONS ---
  const getRows = () => [...tableBody.querySelectorAll("tr")].filter((r) => r.id !== "noDataRow");
  const normalize = (text) => text.toLowerCase().trim();

  const updateNoData = () => {
    document.getElementById("noDataRow")?.remove();
    const rows = getRows();
    const visibleRows = rows.filter((r) => r.style.display !== "none");

    if (rows.length === 0 || visibleRows.length === 0) {
      const row = document.createElement("tr");
      row.id = "noDataRow";
      row.innerHTML = `<td colspan="8" style="text-align:center; padding:40px; color:#94a3b8; font-size:14px; font-weight:600;">No data available</td>`;
      tableBody.appendChild(row);
    }
  };

  // --- 3. MODALS & ALERTS LOGIC ---
  const showConfirmModal = (type) => {
    return new Promise((resolve) => {
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
          desc: "Are you sure you want to apply these changes?",
          text: "Save",
          color: "#3b82f6",
        },
        delete: {
          icon: `<i class="fas fa-trash-alt" style="color:#e11d48;"></i>`,
          title: "Delete Account?",
          desc: "This action is permanent and cannot be undone.",
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
  };

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
    
    // Auto dismiss after 1.5 seconds, but also allow clicking to dismiss early
    const timer = setTimeout(() => { alert.style.display = "none"; }, 1500);
    alert.onclick = () => {
        clearTimeout(timer);
        alert.style.display = "none";
    };
  };

  // --- 4. API FETCHING & RENDERING (Restored from File 1) ---
  const fetchUsers = async () => {
    try {
      const params = new URLSearchParams(document.location.search);
      const searchQuery = params.get("search");
      const page = params.get("page") || 1;
      const requestUrl = searchQuery
        ? `api/users?search=${encodeURIComponent(searchQuery)}`
        : `api/users?page=${page}`;

      const response = await fetch(requestUrl);
      if (response.status === 404) {
        tableBody.innerHTML = "";
        updateNoData();
        return;
      }
      if (!response.ok) throw new Error(`Network response error (${response.status})`);

      const users = await response.json();
      renderTable(users.data?.users || []);
    } catch (error) {
      console.error("Error fetching users:", error);
      tableBody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:#e11d48;">Failed to load data. Check your connection.</td></tr>`;
    }
  };

  const renderTable = (users) => {
    tableBody.innerHTML = "";

    if (users.length === 0) {
      updateNoData();
      return;
    }

    // Strict XSS Protection
    const escapeHTML = (str) =>
      str.replace(/[&<>'"]/g, (tag) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;",
      })[tag]);

    users.forEach((user) => {
      const tr = document.createElement("tr");
      tr.setAttribute("data-state", "view");
      tr.setAttribute("data-id", user.user_id);
      
      tr.innerHTML = `
        <td>${escapeHTML(user.name)}</td>
        <td>${escapeHTML(user.username)}</td>
        <td>${escapeHTML(user.email)}</td>
        <td>${escapeHTML(user.phone_number)}</td>
        <td class="roleCell">
          <span class="viewMode">${escapeHTML(user.role)}</span>
          <select class="roleSelect editMode" style="display:none;">
            <option value="Administrator" ${user.role === "Administrator" ? "selected" : ""}>Administrator</option>
            <option value="Staff" ${user.role === "Staff" ? "selected" : ""}>Staff</option>
          </select>
        </td>
        <td class="statusCell">
          <span class="viewMode status ${user.status.toLowerCase()}">${escapeHTML(user.status)}</span>
          <select class="statusSelect editMode" style="display:none;">
            <option value="Verified" ${user.status === "Verified" ? "selected" : ""}>Verified</option>
            <option value="Unverified" ${user.status === "Unverified" ? "selected" : ""}>Unverified</option>
          </select>
        </td>
        <td>${escapeHTML(user.user_id)}</td>
        <td>
          <button class="editBtn viewMode">Edit</button>
          <button class="saveBtn editMode" style="display:none;">Save</button>
          <button class="deleteBtn viewMode">Delete</button>
        </td>
      `;
      tableBody.appendChild(tr);
    });
    updateNoData();
  };

  // Initial Data Load
  fetchUsers();

  // --- 5. EVENT LISTENERS ---

  // UX: Cancel Edit if user clicks anywhere outside the editing row (Restored from File 2)
  document.addEventListener("click", (e) => {
    const editingRow = document.querySelector('tr[data-state="edit"]');
    const modal = document.getElementById("confirmModal");
    
    if (editingRow) {
      const isClickInsideRow = editingRow.contains(e.target);
      const isClickInsideModal = modal.contains(e.target);

      if (!isClickInsideRow && !isClickInsideModal) {
        editingRow.dataset.state = "view";
        // Reset dropdowns to original view text if cancelled
        const currentRole = editingRow.querySelector(".roleCell .viewMode").textContent.trim();
        const currentStatus = editingRow.querySelector(".statusCell .viewMode").textContent.trim();
        editingRow.querySelector(".roleSelect").value = currentRole;
        editingRow.querySelector(".statusSelect").value = currentStatus;
        
        editingRow.querySelectorAll(".viewMode").forEach(el => el.style.display = "");
        editingRow.querySelectorAll(".editMode").forEach(el => el.style.display = "none");
      }
    }
  });

  // Table Row Actions (Merged UI handling + API logic)
  tableBody.addEventListener("click", async (e) => {
    const row = e.target.closest("tr");
    if (!row || row.id === "noDataRow") return;

    const loadingOverlay = document.getElementById("loadingOverlay");
    const loadingText = document.getElementById("loadingText");
    const userId = row.getAttribute("data-id");

    // EDIT
    if (e.target.closest(".editBtn")) {
      e.stopPropagation(); // Prevent document click logic from triggering immediately
      row.dataset.state = "edit";
      row.querySelectorAll(".viewMode").forEach((el) => (el.style.display = "none"));
      row.querySelectorAll(".editMode").forEach((el) => (el.style.display = "inline-block"));
    }

    // SAVE
    if (e.target.closest(".saveBtn")) {
      e.stopPropagation();
      const ok = await showConfirmModal("save");
      if (!ok) return;

      const newRole = row.querySelector(".roleSelect").value;
      const newStatus = row.querySelector(".statusSelect").value;

      loadingText.innerText = "Saving User Data...";
      loadingOverlay.style.display = "flex";

      try {
        const response = await fetch(`api/users/${userId}`, {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ user_id: userId, role: newRole, status: newStatus }),
        });

        if (!response.ok) throw new Error("Network response error");
        const result = await response.json();

        if (result.success) {
          row.querySelector(".roleCell .viewMode").textContent = newRole;
          const statusSpan = row.querySelector(".statusCell .viewMode");
          statusSpan.textContent = newStatus;
          statusSpan.className = `viewMode status ${newStatus.toLowerCase()}`;

          row.dataset.state = "view";
          row.querySelectorAll(".viewMode").forEach((el) => (el.style.display = ""));
          row.querySelectorAll(".editMode").forEach((el) => (el.style.display = "none"));

          showAlert("Account updated successfully.", "save");
        } else {
          alert("Failed to update user: " + (result.message || "Unknown error"));
        }
      } catch (error) {
        console.error("Error updating user:", error);
        alert("An error occurred while communicating with the server.");
      } finally {
        loadingOverlay.style.display = "none";
      }
    }

    // DELETE
    if (e.target.closest(".deleteBtn")) {
      e.stopPropagation();
      const ok = await showConfirmModal("delete");
      if (!ok) return;

      loadingText.innerText = "Deleting Account...";
      loadingOverlay.style.display = "flex";

      try {
        const response = await fetch(`api/users/${userId}`, {
          method: "DELETE",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ user_id: userId }),
        });

        if (!response.ok) throw new Error("Network response error");
        const result = await response.json();

        if (result.success) {
          row.remove();
          showAlert("Account deleted successfully.", "delete");
          updateNoData();
        } else {
          alert("Failed to delete user: " + (result.message || "Unknown error"));
        }
      } catch (error) {
        console.error("Error deleting user:", error);
        alert("An error occurred while communicating with the server.");
      } finally {
        loadingOverlay.style.display = "none";
      }
    }
  });

  // --- 6. FILTERING (Restored from File 2) ---
  
  // Search Bar (Enter Key) - Clears dropdown filters
  searchInput.addEventListener("keydown", (e) => {
    if (e.key !== "Enter") return;
    statusFilter.value = "all";
    roleFilter.value = "all";
    const term = normalize(searchInput.value);

    getRows().forEach((row) => {
      const name = normalize(row.cells[0].textContent);
      const username = normalize(row.cells[1].textContent);
      const email = normalize(row.cells[2].textContent);
      const match = name.includes(term) || username.includes(term) || email.includes(term);
      row.style.display = match ? "" : "none";
    });
    updateNoData();
  });

  // Dropdown Filter Button - Clears search bar
  filterBtn.addEventListener("click", () => {
    searchInput.value = "";
    const status = normalize(statusFilter.value);
    const role = normalize(roleFilter.value);

    getRows().forEach((row) => {
      const rowRole = normalize(row.querySelector(".roleCell .viewMode").textContent);
      const rowStatus = normalize(row.querySelector(".statusCell .viewMode").textContent);
      const match = (status === "all" || rowStatus === status) && (role === "all" || rowRole === role);
      row.style.display = match ? "" : "none";
    });
    updateNoData();
  });

});