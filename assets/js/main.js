// SIDEBAR ACTIVE STATE
document.querySelectorAll(".menu").forEach((menu) => {
  menu.addEventListener("click", () => {
    document.querySelectorAll(".menu").forEach((item) => {
      item.classList.remove("active");
    });

    menu.classList.add("active");
  });
});

// LOGOUT BUTTON
const logoutBtn = document.querySelector(".logout");

if (logoutBtn) {
  logoutBtn.addEventListener("click", () => {
    if (confirm("Are you sure you want to logout?")) {
      window.location.href = "/logout.php";
    }
  });
}
