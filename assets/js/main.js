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

// =============================
// LOGOUT (Clean Independent Modal)
// =============================

const logoutBtn = document.querySelector(".logout");

// Ensure styles are injected only once
(function injectLogoutModalStyles() {
  if (document.getElementById("lt_logout_modal_styles")) return;

  const style = document.createElement("style");
  style.id = "lt_logout_modal_styles";
  style.textContent = `
    .lt_logout_overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.55);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 99999;
      animation: lt_fadeIn 0.2s ease-out;
      font-family: Arial, sans-serif;
    }

    .lt_logout_modal {
      width: 320px;
      background: #ffffff;
      border-radius: 14px;
      padding: 20px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.2);
      transform: scale(0.95);
      animation: lt_popIn 0.2s ease-out forwards;
    }

    .lt_logout_title {
      font-size: 18px;
      font-weight: 600;
      margin-bottom: 8px;
      color: #222;
    }

    .lt_logout_text {
      font-size: 14px;
      color: #555;
      margin-bottom: 18px;
    }

    .lt_logout_actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .lt_btn {
      border: none;
      padding: 8px 14px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 13px;
      transition: 0.2s ease;
    }

    .lt_btn_cancel {
      background: #eee;
      color: #333;
    }

    .lt_btn_cancel:hover {
      background: #ddd;
    }

    .lt_btn_logout {
      background: #e74c3c;
      color: #fff;
    }

    .lt_btn_logout:hover {
      background: #c0392b;
    }

    @keyframes lt_fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes lt_popIn {
      to { transform: scale(1); }
    }
  `;
  document.head.appendChild(style);
})();

// Create modal (returns a promise)
function showLogoutModal() {
  return new Promise((resolve) => {
    const overlay = document.createElement("div");
    overlay.className = "lt_logout_overlay";

    overlay.innerHTML = `
      <div class="lt_logout_modal">
        <div class="lt_logout_title">Confirm Logout</div>
        <div class="lt_logout_text">Are you sure you want to log out?</div>

        <div class="lt_logout_actions">
          <button class="lt_btn lt_btn_cancel">Cancel</button>
          <button class="lt_btn lt_btn_logout">Logout</button>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);

    const cancelBtn = overlay.querySelector(".lt_btn_cancel");
    const logoutConfirmBtn = overlay.querySelector(".lt_btn_logout");

    const close = (result) => {
      overlay.remove();
      resolve(result);
    };

    cancelBtn.addEventListener("click", () => close(false));
    logoutConfirmBtn.addEventListener("click", () => close(true));

    // click outside closes
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) close(false);
    });

    // ESC key support
    const escHandler = (e) => {
      if (e.key === "Escape") {
        document.removeEventListener("keydown", escHandler);
        close(false);
      }
    };
    document.addEventListener("keydown", escHandler);
  });
}

// Main logout logic
if (logoutBtn) {
  logoutBtn.addEventListener("click", async () => {
    const confirmed = await showLogoutModal();

    if (!confirmed) return;

    try {
      // optional: you can await this if backend matters
      fetch("api/auth.php", { method: "DELETE" });

      // small delay makes UX feel smoother
      setTimeout(() => {
        window.location.href = "index.html";
      }, 150);
    } catch (err) {
      console.error("Logout failed:", err);
    }
  });
}

function adminOnly() {
  fetch("api/auth.php")
    .then(function (response) {
      if (!response.ok) {
        throw new Error("Request failed");
      }
      // Stop execution if the server spits out raw PHP text
      if (!contentType || !contentType.includes("application/json")) {
        throw new Error("Invalid server environment: API did not return JSON.");
      }

      return response.json();
    })
    .then(function (responseData) {
      // console.log(responseData.data.user.role);
      if (responseData.data.user.role == "Administrator") {
        document.querySelectorAll(".adminOnly").forEach(function (element) {
          element.style.display = "block";
        });
      }
      document.getElementById("usernameLabel").textContent =
        responseData.data.user.username;
      document.getElementById("usernameLogo").textContent =
        responseData.data.user.username.toUpperCase().substring(0, 2); // Get first 2 letters of username for logo
      document.getElementById("roleLabel").textContent =
        responseData.data.user.role;
    })
    .catch(function (error) {
      console.error("Error checking login status:", error);
      window.location.href = "index.html";
    });
}

adminOnly();
