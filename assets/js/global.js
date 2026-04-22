// TIME & DATE
function updateSidebarTime() {
  const now = new Date();
  const dateOptions = { month: "long", day: "numeric", year: "numeric" };
  const dateString = now.toLocaleDateString("en-US", dateOptions);
  const timeString = now.toLocaleTimeString("en-US", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: true,
  });

  const displayElement = document.getElementById("dateTimeDisplay");
  if (displayElement) {
    displayElement.textContent = `${dateString} | ${timeString}`;
  }
}
updateSidebarTime();
setInterval(updateSidebarTime, 60000);

// SIDEBAR ACTIVE
document.querySelectorAll(".menu").forEach((menu) => {
  menu.addEventListener("click", () => {
    document.querySelectorAll(".menu").forEach((item) => {
      item.classList.remove("active");
    });

    menu.classList.add("active");
  });
});

// LOGOUT
const logoutBtn = document.querySelector(".logout");

if (logoutBtn) {
  logoutBtn.addEventListener("click", () => {
    if (confirm("Are you sure you want to logout?")) {
      fetch("api/auth.php", {
        method: "DELETE",
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            window.location.href = "index.html";
          }
        })
        .catch((error) => {
          console.error("Error:", error);
        });
      window.location.href = "index.html";
    }
  });
}
