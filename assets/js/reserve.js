document.addEventListener("DOMContentLoaded", function () {
  const burialModal = document.getElementById("burialModalOverlay");
  const burialForm = document.getElementById("burialClearanceForm");
  const addButtons = document.querySelectorAll(".addBtn");
  const dateIntermentInput = document.getElementById("dateInterment");
  const expirationDateInput = document.getElementById("expirationDate");
  const dateTimeDisplay = document.getElementById("dateTimeDisplay");

  function updateSystemTime() {
    const now = new Date();
    const options = {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric",
    };
    if (dateTimeDisplay) {
      dateTimeDisplay.textContent = now.toLocaleDateString("en-US", options);
    }
  }

  updateSystemTime();

  function generateControlNumber() {
    const first = Math.floor(1000 + Math.random() * 9000);
    const second = Math.floor(1000 + Math.random() * 9000);
    document.getElementById("controlNo").value = `${first}-${second}`;
  }

  function openBurialModal() {
    if (!burialModal) return;
    burialModal.style.display = "block";
    document.body.style.overflow = "hidden";
    document.getElementById("clearanceDate").valueAsDate = new Date();
    generateControlNumber();
  }

  function closeModal() {
    if (!burialModal) return;
    burialModal.style.display = "none";
    document.body.style.overflow = "auto";
    burialForm.reset();
  }

  addButtons.forEach((button) => {
    button.addEventListener("click", function (e) {
      e.preventDefault();
      openBurialModal();
    });
  });

  window.addEventListener("click", function (event) {
    if (event.target === burialModal) {
      closeModal();
    }
  });

  window.autoCalculateExpirationDate = function () {
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
  };

  window.closeSeamlessModal = function () {
    closeModal();
  };

  window.submitBurialClearanceForm = function () {
    if (!burialForm.checkValidity()) {
      burialForm.reportValidity();
      return;
    }

    alert("Burial Clearance Saved Successfully!");
    closeModal();
  };
});
