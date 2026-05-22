let blockData = [];
let burialData = [];
let currentEditId = null;

document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("recordSearch");
  const searchBtn = document.getElementById("searchBtn");

  if (searchInput) {
    searchInput.addEventListener("keyup", function (event) {
      if (event.key === "Enter") {
        filterTable();
      }
    });
  }

  if (searchBtn) {
    searchBtn.addEventListener("click", filterTable);
  }

  const intermentInput = document.getElementById("dateInterment");
  if (intermentInput) {
    intermentInput.addEventListener("input", autoCalculateExpirationDate);
  }

  // Phone formatters initialized
  setupPhoneFormatter("ownerPhone");
  setupPhoneFormatter("reqPhone");

  checkEmptyTables();
});

/** PHONE FORMATTING UTILITY **/
function setupPhoneFormatter(elementId) {
  const input = document.getElementById(elementId);
  if (!input) return;

  input.addEventListener("input", function (e) {
    // Remove non-numeric characters
    let value = e.target.value.replace(/\D/g, "");

    // Limit to 11 digits
    if (value.length > 11) value = value.substring(0, 11);

    // Apply format: 09XX XXX XXXX
    let formatted = "";
    if (value.length > 0) {
      formatted = value.substring(0, 4); // 09XX
      if (value.length > 4) formatted += " " + value.substring(4, 7); // XXX
      if (value.length > 7) formatted += " " + value.substring(7, 11); // XXXX
    }
    e.target.value = formatted;
  });
}

function generateControlNumber() {
  let controlNumber;
  let exists = true;
  while (exists) {
    const firstPart = Math.floor(1000 + Math.random() * 9000);
    const secondPart = Math.floor(1000 + Math.random() * 9000);
    controlNumber = `${firstPart}-${secondPart}`;
    exists = burialData.some((item) => item.controlNo === controlNumber);
  }
  return controlNumber;
}

function showTab(tabName, event) {
  document.querySelectorAll(".subNavItem").forEach((btn) => {
    btn.classList.remove("active");
  });
  event.currentTarget.classList.add("active");
  document.querySelectorAll(".tableWrapper").forEach((tab) => {
    tab.classList.remove("active");
  });
  if (tabName === "burial") {
    document.getElementById("burialRecords").classList.add("active");
  } else {
    document.getElementById("blockRecords").classList.add("active");
  }
  const searchInput = document.getElementById("recordSearch");
  if (searchInput) searchInput.value = "";
  filterTable();
}

function openSeamlessModal() {
  const burialRecords = document.getElementById("burialRecords");
  const isBurialTabActive =
    burialRecords && burialRecords.classList.contains("active");
  if (isBurialTabActive) {
    const modal = document.getElementById("burialModalOverlay");
    if (modal) {
      modal.style.display = "block";
      const controlNoField = document.getElementById("controlNo");
      if (!currentEditId && controlNoField && !controlNoField.value) {
        controlNoField.value = generateControlNumber();
      }
    }
  } else {
    const modal = document.getElementById("blockModalOverlay");
    if (modal) {
      modal.style.display = "block";
      toggleOwnerFieldsDisplay();
    }
  }
}

function openModal() {
  openSeamlessModal();
}

function closeSeamlessModal(type) {
  currentEditId = null;
  if (type === "burial" || type === "deceased") {
    const modal = document.getElementById("burialModalOverlay");
    const form = document.getElementById("burialClearanceForm");
    if (modal) modal.style.display = "none";
    if (form) form.reset();
  } else if (type === "block") {
    const modal = document.getElementById("blockModalOverlay");
    const form = document.getElementById("blockForm");
    if (modal) modal.style.display = "none";
    if (form) form.reset();
    toggleOwnerFieldsDisplay();
  }
}

window.onclick = function (event) {
  const burialOverlay = document.getElementById("burialModalOverlay");
  const blockOverlay = document.getElementById("blockModalOverlay");
  const ownerOverlay = document.getElementById("ownerDetailsModalOverlay");
  if (event.target === burialOverlay) closeSeamlessModal("burial");
  if (event.target === blockOverlay) closeSeamlessModal("block");
  if (event.target === ownerOverlay) ownerOverlay.style.display = "none";
};

function toggleOwnerFieldsDisplay() {
  const blockType = document.getElementById("blockType");
  const ownerSection = document.getElementById("ownerSectionContainer");

  if (!blockType || !ownerSection) return;

  const isPrivate = blockType.value === "Private / Owned";

  ownerSection.style.display = isPrivate ? "block" : "none";

  ownerSection.querySelectorAll("input, select").forEach((field) => {
    field.required = isPrivate;
  });
}

function autoCalculateExpirationDate() {
  const interment = document.getElementById("dateInterment");
  const expiration = document.getElementById("expirationDate");
  if (!interment || !expiration) return;
  if (!interment.value) {
    expiration.value = "";
    return;
  }
  const date = new Date(interment.value);
  date.setFullYear(date.getFullYear() + 5);
  expiration.value = date.toISOString().split("T")[0];
}

function submitBlockRegistrationForm() {
  const form = document.getElementById("blockForm");
  if (!form || !form.checkValidity()) {
    form.reportValidity();
    return;
  }
  const newBlock = {
    id: currentEditId || Date.now(),
    name: document.getElementById("blockName").value,
    type: document.getElementById("blockType").value,
    capacity: document.getElementById("blockCapacity").value,
    area: document.getElementById("blockArea").value,
    remarks: document.getElementById("blockRemarks").value,
    owner: {
      name: document.getElementById("ownerName").value,
      phone: document.getElementById("ownerPhone").value,
      purok: document.getElementById("ownerPurok").value,
      barangay: document.getElementById("ownerBarangay").value,
      email: document.getElementById("ownerEmail").value,
    },
  };
  if (currentEditId) {
    blockData = blockData.map((b) => (b.id === currentEditId ? newBlock : b));
  } else {
    blockData.push(newBlock);
  }
  renderBlockTable();
  closeSeamlessModal("block");
}

function submitBurialClearanceForm() {
  const form = document.getElementById("burialClearanceForm");
  if (!form || !form.checkValidity()) {
    form.reportValidity();
    return;
  }
  const reqStreet = document.getElementById("reqStreet").value;
  const reqBrgy = document.getElementById("requesting_barangay").value;
  const newBurial = {
    id: currentEditId || Date.now(),
    controlNo:
      document.getElementById("controlNo").value || generateControlNumber(),
    name: document.getElementById("deceasedName").value,
    sex: document.getElementById("deceasedSex").value,
    dob: document.getElementById("deceasedDob").value,
    address: document.getElementById("deceasedAddress").value,
    dateInterment: document.getElementById("dateInterment").value,
    expiration: document.getElementById("expirationDate").value,
    block: document.getElementById("burialBlock").value,
    contactName: document.getElementById("reqName").value,
    contactPhone: document.getElementById("reqPhone").value,
    contactAddress: `${reqStreet}${reqStreet && reqBrgy ? ", " : ""}${reqBrgy}`,
    remarks: document.getElementById("deceasedRemarks").value,
  };
  if (currentEditId) {
    burialData = burialData.map((b) =>
      b.id === currentEditId ? newBurial : b,
    );
  } else {
    burialData.push(newBurial);
  }
  renderBurialTable();
  closeSeamlessModal("deceased");
}

function renderBlockTable() {
  const tbody = document.getElementById("blockTableBody");
  if (!tbody) return;
  tbody.innerHTML = "";
  blockData.forEach((item) => {
    const tr = document.createElement("tr");
    const typeDisplay =
      item.type === "Private / Owned"
        ? `<a href="#" style="color:#2e7d32;font-weight:600;text-decoration:underline;" onclick="viewOwnerDetails(${item.id});event.preventDefault();">${item.type}</a>`
        : item.type;
    tr.innerHTML = `
            <td>${item.name}</td>
            <td>${typeDisplay}</td>
            <td>${item.capacity || "-"}</td>
            <td>${item.area || "-"}</td>
            <td>${item.remarks || "-"}</td>
            <td>
                <div class="actions">
                    <button class="editBtn" onclick="editBlockRecord(${item.id})"><i class="fas fa-edit"></i></button>
                    <button class="deleteBtn" onclick="deleteBlockRecord(${item.id})"><i class="fas fa-trash-alt"></i></button>
                </div>
            </td>`;
    tbody.appendChild(tr);
  });
  checkEmptyTables();
}

function renderBurialTable() {
  const tbody = document.getElementById("burialTableBody");
  if (!tbody) return;
  tbody.innerHTML = "";
  burialData.forEach((item) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
            <td>${item.controlNo}</td>
            <td><strong>${item.name}</strong></td>
            <td>${item.sex || "-"}</td>
            <td>${item.dob || "-"}</td>
            <td>${item.address || "-"}</td>
            <td>${item.dateInterment || "-"}</td>
            <td>${item.block}</td>
            <td>${item.expiration}</td>
            <td>${item.contactName}</td>
            <td>${item.contactPhone || "-"}</td>
            <td>${item.contactAddress || "-"}</td>
            <td>${item.remarks || "-"}</td>
            <td>
                <div class="actions">
                    <button class="viewBtn" onclick="viewBurialDetails(${item.id})"><i class="fas fa-eye"></i></button>
                    <button class="editBtn" onclick="editBurialRecord(${item.id})"><i class="fas fa-edit"></i></button>
                    <button class="deleteBtn" onclick="deleteBurialRecord(${item.id})"><i class="fas fa-trash-alt"></i></button>
                </div>
            </td>`;
    tbody.appendChild(tr);
  });
  checkEmptyTables();
}

function viewOwnerDetails(id) {
  const item = blockData.find((b) => b.id === id);
  const modal = document.getElementById("ownerDetailsModalOverlay");
  const content = document.getElementById("ownerDetailsContent");
  if (!item || !modal || !content) return;
  content.innerHTML = `
        <p><strong>Full Contact Name:</strong> ${item.owner.name || "N/A"}</p>
        <p><strong>Contact Number:</strong> ${item.owner.phone || "N/A"}</p>
        <p><strong>Street/Zone:</strong> ${item.owner.purok || "N/A"}</p>
        <p><strong>Barangay:</strong> ${item.owner.barangay || "N/A"}</p>
        <p><strong>Email:</strong> ${item.owner.email || "N/A"}</p>`;
  modal.style.display = "block";
}

function editBlockRecord(id) {
  const item = blockData.find((b) => b.id === id);
  const modal = document.getElementById("blockModalOverlay");
  if (!item || !modal) return;
  currentEditId = id;
  modal.style.display = "block";
  document.getElementById("blockName").value = item.name || "";
  document.getElementById("blockType").value = item.type || "";
  document.getElementById("blockCapacity").value = item.capacity || "";
  document.getElementById("blockArea").value = item.area || "";
  document.getElementById("blockRemarks").value = item.remarks || "";
  document.getElementById("ownerName").value = item.owner?.name || "";
  document.getElementById("ownerPhone").value = item.owner?.phone || "";
  document.getElementById("ownerPurok").value = item.owner?.purok || "";
  document.getElementById("ownerBarangay").value = item.owner?.barangay || "";
  document.getElementById("ownerEmail").value = item.owner?.email || "";
  toggleOwnerFieldsDisplay();
}

function editBurialRecord(id) {
  const item = burialData.find((b) => b.id === id);
  const modal = document.getElementById("burialModalOverlay");
  if (!item || !modal) return;
  currentEditId = id;
  modal.style.display = "block";
  document.getElementById("controlNo").value = item.controlNo || "";
  document.getElementById("deceasedName").value = item.name || "";
  document.getElementById("deceasedSex").value = item.sex || "";
  document.getElementById("deceasedDob").value = item.dob || "";
  document.getElementById("deceasedAddress").value = item.address || "";
  document.getElementById("dateInterment").value = item.dateInterment || "";
  document.getElementById("expirationDate").value = item.expiration || "";
  document.getElementById("burialBlock").value = item.block || "";
  document.getElementById("reqName").value = item.contactName || "";
  document.getElementById("reqPhone").value = item.contactPhone || "";
  document.getElementById("deceasedRemarks").value = item.remarks || "";
  const parts = item.contactAddress ? item.contactAddress.split(", ") : [];
  document.getElementById("reqStreet").value = parts[0] || "";
  document.getElementById("requesting_barangay").value = parts[1] || "";
}

function deleteBlockRecord(id) {
  if (confirm("Delete this block record?")) {
    blockData = blockData.filter((b) => b.id !== id);
    renderBlockTable();
  }
}

function deleteBurialRecord(id) {
  if (confirm("Delete this burial record?")) {
    burialData = burialData.filter((b) => b.id !== id);
    renderBurialTable();
  }
}

function filterTable() {
  const input = document.getElementById("recordSearch");
  if (!input) return;
  const filter = input.value.toLowerCase();
  const activeWrapper = document.querySelector(".tableWrapper.active");
  if (!activeWrapper) return;
  const rows = activeWrapper.getElementsByTagName("tr");
  let hasVisible = false;
  for (let i = 1; i < rows.length; i++) {
    let show = false;
    const cells = rows[i].getElementsByTagName("td");
    for (let j = 0; j < cells.length; j++) {
      if (cells[j].innerText.toLowerCase().includes(filter)) {
        show = true;
        break;
      }
    }
    rows[i].style.display = show ? "" : "none";
    if (show) hasVisible = true;
  }
  const noData = activeWrapper.querySelector(".noData");
  if (noData) noData.style.display = hasVisible ? "none" : "block";
}

function checkEmptyTables() {
  document.querySelectorAll(".tableWrapper").forEach((wrapper) => {
    const tbody = wrapper.querySelector("tbody");
    const noData = wrapper.querySelector(".noData");
    if (!tbody || !noData) return;
    noData.style.display = tbody.rows.length === 0 ? "block" : "none";
  });
}
