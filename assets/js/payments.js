document.addEventListener("DOMContentLoaded", () => {
  const tableBody = document.getElementById("paymentsTableBody");

  if (!tableBody) return;

  tableBody.addEventListener("click", (e) => {
    const target = e.target.closest(".btnAction");
    if (!target) return;

    const row = target.closest("tr");
    const step = parseInt(target.dataset.step, 10);
    const statusTd = row.querySelector(".colStatus");
    const actionTd = row.querySelector(".colAction");

    if (step === 1) {
      statusTd.innerHTML = `<span class="statusBadge statusOrange">Pending Grounds Verification</span>`;
      actionTd.innerHTML = `<button class="btnAction" data-step="2">Awaiting Grounds</button>`;
    } else if (step === 2) {
      statusTd.innerHTML = `<span class="statusBadge statusGreen">Completed</span>`;
      actionTd.innerHTML = `<span class="actionText textComplete"><i class="fas fa-check-circle"></i> Verified</span>`;
    }
  });
});
