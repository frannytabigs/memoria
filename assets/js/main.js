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
setInterval(updateSidebarTime, 1000);

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
      window.location.href = "/logout.php";
    }
  });
}

function adminOnly() {
  try {
    fetch("api/auth.php")
      .then(function (response) {
        if (!response.ok) {
          throw new Error("Request failed");
        }
        return response.json();
      })
      .then(function (responseData) {
        if (responseData.role !== "Administrator") {
          document.getElementById("system").style.display = "none";
          document.getElementById("accounts").style.display = "none";
        }
      })
      .catch(function (error) {
        console.error("Error checking login status:", error);
      });
  } catch (error) {
    console.error("Error checking login status:", error);
  }
}

adminOnly();
