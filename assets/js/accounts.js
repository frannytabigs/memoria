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
            <div id="loadingOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; z-index:10005; flex-direction: column;">
                <div style="border: 4px solid rgba(216, 180, 226, 0.2); border-top: 4px solid #9b5de5; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom: 15px;"></div>
                <h3 id="loadingText" style="color:#ffffff; font-size:16px; letter-spacing: 1px;">Processing...</h3>
            </div>

            <div id="confirmModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; z-index:10000;">
                <div style="background:white; padding:32px; border-radius:16px; width:90%; max-width:400px; text-align:center; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                    <div id="modalIcon" style="font-size:32px; margin-bottom:15px;"></div>
                    <h3 id="modalTitle" style="color:#1e293b; font-size:20px; margin-bottom:10px;"></h3>
                    <p id="modalDesc" style="color:#64748b; font-size:14px; margin-bottom:25px;"></p>
                    <div style="display:flex; gap:12px;">
                        <button id="cancelAction" style="flex:1; padding:12px; border:none; border-radius:8px; background:#f1f5f9; color:#475569; font-weight:600; cursor:pointer;">Cancel</button>
                        <button id="confirmAction" style="flex:1; padding:12px; border:none; border-radius:8px; color:white; font-weight:600; cursor:pointer;"></button>
                    </div>
                </div>
            </div>

            <div id="successAlert" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.4); backdrop-filter:blur(3px); align-items:center; justify-content:center; z-index:10001; cursor: pointer;">
                <div style="background:white; padding:40px 32px; border-radius:20px; width:90%; max-width:400px; text-align:center; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); cursor: default;">
                    <div id="alertIconContainer" style="font-size:32px; margin-bottom:20px;"></div>
                    <h3 style="color:#1e293b; font-size:22px; margin-bottom:10px;">Success</h3>
                    <p id="successMsg" style="color:#64748b; font-size:15px; margin-bottom: 0;"></p>
                    <p style="color:#94a3b8; font-size:11px; margin-top: 25px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Click anywhere to dismiss</p>
                </div>
            </div>
        `;
    document.body.appendChild(uiContainer);
  };
  injectUIComponents();

  // --- 2. API FETCHING & RENDERING ---
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
        tableBody.innerHTML = `<tr><td colspan="6" style="text-align:center">User not found</td></tr>`;
        return;
      }
      if (!response.ok) {
        throw new Error(`Network response was not ok (${response.status})`);
      }

      const users = await response.json();
      renderTable(users.data?.users || []);
    } catch (error) {
      console.error("Error fetching users:", error);
      tableBody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:#e11d48;">Failed to load data. Please try again later and check your connection.</td></tr>`;
    }
  };

  const renderTable = (users) => {
    tableBody.innerHTML = ""; // Clear the loading state or old data

    if (users.length === 0) {
      tableBody.innerHTML = `<tr><td colspan="6" style="text-align:center;">No users found.</td></tr>`;
      return;
    }

    users.forEach((user) => {
      const tr = document.createElement("tr");
      tr.setAttribute("data-state", "view");
      tr.setAttribute("data-id", user.user_id); // Add ID to the row so you know who to update in the DB later
      const escapeHTML = (str) =>
        str.replace(
          /[&<>'"]/g,
          (tag) =>
            ({
              "&": "&amp;",
              "<": "&lt;",
              ">": "&gt;",
              "'": "&#39;",
              '"': "&quot;",
            })[tag],
        );
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
  };

  // Call the fetch function as soon as the DOM is ready
  fetchUsers();

  // --- 3. MODALS & ALERTS LOGIC ---
  const showConfirmModal = (type) => {
    return new Promise((resolve) => {
      const modal = document.getElementById("confirmModal");
      const icon = document.getElementById("modalIcon");
      const title = document.getElementById("modalTitle");
      const desc = document.getElementById("modalDesc");
      const btn = document.getElementById("confirmAction");

      if (type === "save") {
        icon.innerHTML =
          '<i class="fas fa-question-circle" style="color:#3b82f6;"></i>';
        title.innerText = "Save Changes?";
        desc.innerText = "Are you sure you want to apply these changes?";
        btn.innerText = "Save Changes";
        btn.style.background = "#3b82f6";
      } else {
        icon.innerHTML =
          '<i class="fas fa-exclamation-circle" style="color:#e11d48;"></i>';
        title.innerText = "Delete Account?";
        desc.innerText = "This action is permanent and cannot be undone.";
        btn.innerText = "Delete";
        btn.style.background = "#e11d48";
      }

      modal.style.display = "flex";

      document.getElementById("confirmAction").onclick = () => {
        modal.style.display = "none";
        resolve(true);
      };
      document.getElementById("cancelAction").onclick = () => {
        modal.style.display = "none";
        resolve(false);
      };
    });
  };

  const triggerSuccessAlert = (message, iconType) => {
    const alert = document.getElementById("successAlert");
    const msgContainer = document.getElementById("successMsg");
    const iconContainer = document.getElementById("alertIconContainer");

    msgContainer.innerText = message;

    if (iconType === "delete") {
      iconContainer.innerHTML =
        '<i class="fas fa-trash-alt" style="color:#e11d48;"></i>';
    } else {
      iconContainer.innerHTML =
        '<i class="fas fa-check-circle" style="color:#3b82f6;"></i>';
    }

    alert.style.display = "flex";

    const closeAlert = () => {
      alert.style.display = "none";
      alert.removeEventListener("click", closeAlert);
    };
    setTimeout(() => alert.addEventListener("click", closeAlert), 10);
  };

  // --- 4. TABLE EVENT LISTENERS ---
  tableBody.addEventListener("click", async (e) => {
    const row = e.target.closest("tr");
    if (!row) return;

    const loadingOverlay = document.getElementById("loadingOverlay");
    const loadingText = document.getElementById("loadingText");

    // Handle Edit Mode UI
    if (e.target.closest(".editBtn")) {
      row.setAttribute("data-state", "edit");
      row
        .querySelectorAll(".viewMode")
        .forEach((el) => (el.style.display = "none"));
      row
        .querySelectorAll(".editMode")
        .forEach((el) => (el.style.display = "inline-block"));
    }

    // Handle Save (PUT Request)
    if (e.target.closest(".saveBtn")) {
      const confirmed = await showConfirmModal("save");
      if (confirmed) {
        const userId = row.getAttribute("data-id");
        const newRole = row.querySelector(".roleSelect").value;
        const newStatus = row.querySelector(".statusSelect").value;

        // SHOW LOADER
        loadingText.innerText = "Saving User Data...";
        loadingOverlay.style.display = "flex";

        try {
          const response = await fetch(`api/users/${userId}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              user_id: userId,
              role: newRole,
              status: newStatus,
            }),
          });

          if (!response.ok) throw new Error("Network response was not ok");
          const result = await response.json();

          if (result.success) {
            row.querySelector(".roleCell .viewMode").textContent = newRole;
            const statusSpan = row.querySelector(".statusCell .viewMode");
            statusSpan.textContent = newStatus;
            statusSpan.className = `viewMode status ${newStatus.toLowerCase()}`;

            row.setAttribute("data-state", "view");
            row
              .querySelectorAll(".viewMode")
              .forEach((el) => (el.style.display = ""));
            row
              .querySelectorAll(".editMode")
              .forEach((el) => (el.style.display = "none"));

            triggerSuccessAlert(
              "Account has been updated successfully.",
              "save",
            );
          } else {
            alert(
              "Failed to update user: " + (result.message || "Unknown error"),
            );
          }
        } catch (error) {
          console.error("Error updating user:", error);
          alert("An error occurred while communicating with the server.");
        } finally {
          // HIDE LOADER ALWAYS
          loadingOverlay.style.display = "none";
        }
      }
    }

    // Handle Delete (DELETE Request)
    if (e.target.closest(".deleteBtn")) {
      const confirmed = await showConfirmModal("delete");
      if (confirmed) {
        const userId = row.getAttribute("data-id");

        // SHOW LOADER
        loadingText.innerText = "Deleting Account...";
        loadingOverlay.style.display = "flex";

        try {
          const response = await fetch(`api/users/${userId}`, {
            method: "DELETE",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ user_id: userId }),
          });

          if (!response.ok) throw new Error("Network response was not ok");
          const result = await response.json();

          if (result.success) {
            row.remove();
            triggerSuccessAlert(
              "Account has been deleted successfully.",
              "delete",
            );
          } else {
            alert(
              "Failed to delete user: " + (result.message || "Unknown error"),
            );
          }
        } catch (error) {
          console.error("Error deleting user:", error);
          alert("An error occurred while communicating with the server.");
        } finally {
          // HIDE LOADER ALWAYS
          loadingOverlay.style.display = "none";
        }
      }
    }
  });

  // --- 5. FILTERING ---
  filterBtn.addEventListener("click", () => {
    const searchTerm = searchInput.value.toLowerCase();
    const selectedStatus = statusFilter.value;
    const selectedRole = roleFilter.value;
    const rows = tableBody.querySelectorAll("tr");

    rows.forEach((row) => {
      const name = row.cells[0].textContent.toLowerCase();
      const username = row.cells[1].textContent.toLowerCase();
      const email = row.cells[2].textContent.toLowerCase();
      const role = row.querySelector(".roleCell .viewMode").textContent;
      const status = row.querySelector(".statusCell .viewMode").textContent;

      const matchesSearch =
        name.includes(searchTerm) ||
        username.includes(searchTerm) ||
        email.includes(searchTerm);

      const matchesStatus =
        selectedStatus === "all" || status === selectedStatus;
      const matchesRole = selectedRole === "all" || role === selectedRole;

      row.style.display =
        matchesSearch && matchesStatus && matchesRole ? "" : "none";
    });
  });
});
