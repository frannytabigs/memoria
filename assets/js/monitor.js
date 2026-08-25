document.addEventListener("DOMContentLoaded", () => {
  const tableBody = document.getElementById("reservation");
  const burialModal = document.getElementById("burialModalOverlay");
  const burialForm = document.getElementById("burialClearanceForm");
  const btnCancel = document.getElementById("btnCancel");
  const btnSave = document.getElementById("btnSave");
  const controlNoInput = document.getElementById("controlNo");

  function generateControlNo() {
    const part1 = Math.floor(1000 + Math.random() * 9000);
    const part2 = Math.floor(1000 + Math.random() * 9000);
    return `${part1}-${part2}`;
  }

  if (tableBody) {
    tableBody.addEventListener("click", (e) => {
      const target = e.target.closest("button");
      if (!target) return;

      const row = target.closest("tr");

      if (target.classList.contains("checkBtn")) {
        if (
          confirm("Mark this record as completed and remove it from the list?")
        ) {
          row.remove();
        }
      }

      if (target.classList.contains("editBtn")) {
        burialForm.reset();
        if (controlNoInput) {
          controlNoInput.value = generateControlNo();
        }
        burialModal.style.display = "flex";
      }

      if (target.classList.contains("deleteBtn")) {
        if (confirm("Are you sure you want to delete this record?")) {
          row.remove();
        }
      }
    });
  }

  if (btnCancel) {
    btnCancel.addEventListener("click", () => {
      burialModal.style.display = "none";
    });
  }

  if (btnSave) {
    btnSave.addEventListener("click", () => {
      alert("Burial Clearance saved successfully.");
      burialModal.style.display = "none";
    });
  }

  if (burialModal) {
    burialModal.addEventListener("click", (e) => {
      if (e.target === burialModal) {
        burialModal.style.display = "none";
      }
    });
  }
});
