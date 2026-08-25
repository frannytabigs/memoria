let burialData = [];
let currentEditId = null;
let currentPage = 1;
const rowsPerPage = 10;

document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("recordSearch");
  const searchBtn = document.getElementById("searchBtn");
  const intermentInput = document.getElementById("dateInterment");
  const form = document.getElementById("burialClearanceForm");
  const prevBtn = document.getElementById("prevPageBtn");
  const nextBtn = document.getElementById("nextPageBtn");

  if (searchInput) {
    searchInput.addEventListener("keyup", (e) => {
      if (e.key === "Enter") filterTable();
    });
  }

  if (searchBtn) searchBtn.addEventListener("click", filterTable);
  if (intermentInput)
    intermentInput.addEventListener("input", autoCalculateExpirationDate);

  if (form) {
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      submitBurialClearanceForm();
    });
  }

  if (prevBtn) {
    prevBtn.addEventListener("click", () => {
      if (currentPage > 1) {
        currentPage--;
        renderBurialTable();
      }
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener("click", () => {
      const totalPages = Math.ceil(burialData.length / rowsPerPage);
      if (currentPage < totalPages) {
        currentPage++;
        renderBurialTable();
      }
    });
  }

  setupPhoneFormatter("reqPhone");
  checkEmptyTables();
});

function openSeamlessModal() {
  const modal = document.getElementById("burialModalOverlay");
  if (!modal) return;

  setFormFieldsDisabled(false);

  modal.style.display = "block";
  const controlNoField = document.getElementById("controlNo");
  if (!currentEditId && controlNoField) {
    controlNoField.value = generateControlNumber();
  }
}

function openModal() {
  openSeamlessModal();
}

function closeSeamlessModal() {
  currentEditId = null;
  const modal = document.getElementById("burialModalOverlay");
  const form = document.getElementById("burialClearanceForm");

  if (modal) {
    modal.style.display = "none";
    modal.classList.remove("view-mode");
  }
  if (form) form.reset();

  setFormFieldsDisabled(false);
}

window.onclick = function (event) {
  const burialOverlay = document.getElementById("burialModalOverlay");
  if (event.target === burialOverlay) closeSeamlessModal();
};

function setFormFieldsDisabled(status) {
  const modal = document.getElementById("burialModalOverlay");
  if (modal) {
    if (status) {
      modal.classList.add("view-mode");
    } else {
      modal.classList.remove("view-mode");
    }

    modal.querySelectorAll("input, select, .btnPaperSave").forEach((field) => {
      field.disabled = status;
    });
  }
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

function submitBurialClearanceForm() {
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
  closeSeamlessModal();
}

function renderBurialTable() {
  const tbody = document.getElementById("burialTableBody");
  if (!tbody) return;

  tbody.innerHTML = "";

  const start = (currentPage - 1) * rowsPerPage;
  const end = start + rowsPerPage;
  const paginatedData = burialData.slice(start, end);

  paginatedData.forEach((item) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
            <td>${item.controlNo}</td>
            <td>${item.name}</td>
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
                    <button class="viewBtn" onclick="viewBurialDetails(${item.id})" title="View Details"><i class="fas fa-eye"></i></button>
                    <button class="editBtn" onclick="editBurialRecord(${item.id})" title="Edit Record"><i class="fas fa-edit"></i></button>
                    <button class="deleteBtn" onclick="deleteBurialRecord(${item.id})" title="Delete Record"><i class="fas fa-trash-alt"></i></button>
                </div>
            </td>`;
    tbody.appendChild(tr);
  });

  updatePagination();
  checkEmptyTables();
}

function updatePagination() {
  const prevBtn = document.getElementById("prevPageBtn");
  const nextBtn = document.getElementById("nextPageBtn");
  const numbersContainer = document.getElementById("paginationNumbers");

  const totalPages = Math.max(1, Math.ceil(burialData.length / rowsPerPage));

  if (prevBtn) prevBtn.disabled = currentPage <= 1;
  if (nextBtn) nextBtn.disabled = currentPage >= totalPages;

  if (numbersContainer) {
    numbersContainer.innerHTML = "";
    for (let i = 1; i <= totalPages; i++) {
      const btn = document.createElement("button");
      btn.className = `pageNumber ${i === currentPage ? "active" : ""}`;
      btn.textContent = i;
      btn.onclick = () => {
        currentPage = i;
        renderBurialTable();
      };
      numbersContainer.appendChild(btn);
    }
  }
}

function viewBurialDetails(id) {
  editBurialRecord(id);
  setFormFieldsDisabled(true);
}

function editBurialRecord(id) {
  const item = burialData.find((b) => b.id === id);
  const modal = document.getElementById("burialModalOverlay");
  if (!item || !modal) return;

  currentEditId = id;
  setFormFieldsDisabled(false);
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

function deleteBurialRecord(id) {
  if (confirm("Delete this burial record?")) {
    burialData = burialData.filter((b) => b.id !== id);
    if (
      (currentPage - 1) * rowsPerPage >= burialData.length &&
      currentPage > 1
    ) {
      currentPage--;
    }
    renderBurialTable();
  }
}

function filterTable() {
  const input = document.getElementById("recordSearch");
  if (!input) return;

  const filter = input.value.toLowerCase();
  const activeWrapper = document.querySelector(".tableWrapper.active");
  if (!activeWrapper) return;

  const tbody = activeWrapper.querySelector("tbody");
  if (!tbody) return;

  const rows = tbody.getElementsByTagName("tr");
  let hasVisible = false;

  for (let i = 0; i < rows.length; i++) {
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
  if (noData)
    noData.style.display = hasVisible || rows.length === 0 ? "none" : "block";
}

function checkEmptyTables() {
  document.querySelectorAll(".tableWrapper").forEach((wrapper) => {
    const tbody = wrapper.querySelector("tbody");
    const noData = wrapper.querySelector(".noData");
    if (!tbody || !noData) return;
    noData.style.display = tbody.rows.length === 0 ? "block" : "none";
  });
}

function setupPhoneFormatter(elementId) {
  const input = document.getElementById(elementId);
  if (!input) return;

  input.addEventListener("input", function (e) {
    let value = e.target.value.replace(/\D/g, "");
    if (value.length > 11) value = value.substring(0, 11);

    let formatted = "";
    if (value.length > 0) {
      formatted = value.substring(0, 4);
      if (value.length > 4) formatted += " " + value.substring(4, 7);
      if (value.length > 7) formatted += " " + value.substring(7, 11);
    }
    e.target.value = formatted;
  });
}
