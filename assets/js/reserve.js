document.addEventListener("DOMContentLoaded", function () {
  const burialModal = document.getElementById("burialModalOverlay");
  const burialForm = document.getElementById("burialClearanceForm");
  const addButtons = document.querySelectorAll(".addBtn");
  const editButtons = document.querySelectorAll(".editBtn");
  const dateIntermentInput = document.getElementById("dateInterment");
  const expirationDateInput = document.getElementById("expirationDate");
  const dateTimeDisplay = document.getElementById("dateTimeDisplay");
  const btnCancel = document.getElementById("btnCancel");
  const btnSave = document.getElementById("btnSave");

  function updateSystemTime() {
    if (!dateTimeDisplay) return;
    const now = new Date();
    const options = {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric",
    };
    dateTimeDisplay.textContent = now.toLocaleDateString("en-US", options);
  }
  updateSystemTime();

  function generateControlNumber() {
    const first = Math.floor(1000 + Math.random() * 9000);
    const second = Math.floor(1000 + Math.random() * 9000);
    document.getElementById("controlNo").value = `${first}-${second}`;
  }

  function openModal() {
    if (!burialModal) return;
    burialModal.style.display = "block";
    document.body.style.overflow = "hidden";
  }

  function closeModal() {
    if (!burialModal) return;
    burialModal.style.display = "none";
    document.body.style.overflow = "auto";
    burialForm.reset();
  }

  function autoCalculateExpirationDate() {
    if (!dateIntermentInput.value) {
      expirationDateInput.value = "";
      return;
    }

    let intermentDate = new Date(dateIntermentInput.value);
    intermentDate.setFullYear(intermentDate.getFullYear() + 5);

    const year = intermentDate.getFullYear();
    const month = String(intermentDate.getMonth() + 1).padStart(2, "0");
    const day = String(intermentDate.getDate()).padStart(2, "0");

    expirationDateInput.value = `${year}-${month}-${day}`;
  }

  addButtons.forEach((button) => {
    button.addEventListener("click", function (e) {
      e.preventDefault();
      burialForm.reset();
      document.getElementById("clearanceDate").valueAsDate = new Date();
      generateControlNumber();
      openModal();
    });
  });

  editButtons.forEach((button) => {
    button.addEventListener("click", function (e) {
      e.preventDefault();
      const tr = this.closest("tr");

      burialForm.reset();
      document.getElementById("clearanceDate").valueAsDate = new Date();
      generateControlNumber();

      // Populate form from table data attributes
      document.getElementById("deceasedName").value = tr.dataset.deceased || "";
      document.getElementById("graveCode").value = tr.dataset.block || "";
      document.getElementById("expirationDate").value =
        tr.dataset.expiration || "";
      document.getElementById("reqName").value = tr.dataset.contactPerson || "";
      document.getElementById("reqPhone").value = tr.dataset.contactNo || "";
      document.getElementById("deceasedRemarks").value =
        tr.dataset.remarks || "";

      openModal();
    });
  });

  if (dateIntermentInput) {
    dateIntermentInput.addEventListener("change", autoCalculateExpirationDate);
  }

  if (btnCancel) {
    btnCancel.addEventListener("click", closeModal);
  }

  if (btnSave) {
    btnSave.addEventListener("click", function () {
      if (!burialForm.checkValidity()) {
        burialForm.reportValidity();
        return;
      }
      alert("Burial Clearance Saved Successfully!");
      closeModal();
    });
  }

  window.addEventListener("click", function (event) {
    if (event.target === burialModal) {
      closeModal();
    }
  });
});
