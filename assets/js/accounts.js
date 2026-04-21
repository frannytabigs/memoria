function saveaccount() {}

function editaccount(btn) {
  const row = btn.closest("tr");
  const editBtn = row.querySelector(".editBtn");
  const saveBtn = row.querySelector(".saveBtn");
  const roleSelect = row.querySelector(".roleSelect");
  const statusSelect = row.querySelector(".statusSelect");
  const role = row.querySelector(".role");
  const status = row.querySelector(".status");

  role.classList.add("hidden");
  document.getElementById("status").classList.add("hidden");
}
