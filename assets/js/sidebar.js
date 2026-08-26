function updateSidebarTime() {
  const now = new Date();
  const dateOptions = { month: "long", day: "numeric", year: "numeric" };
  const dateString = now.toLocaleDateString("en-US", dateOptions);
  const timeString = now.toLocaleTimeString("en-US", {
    hour: "2-digit",
    minute: "2-digit",
  });

  const displayElement = document.getElementById("dateTimeDisplay");
  if (displayElement) {
    displayElement.textContent = `${dateString} | ${timeString}`;
  }
}

document.addEventListener("DOMContentLoaded", () => {
  updateSidebarTime();
  setInterval(updateSidebarTime, 1000);

  const logoToggle = document.getElementById("logoToggle");
  const sidebar = document.querySelector(".sidebar");

  if (logoToggle && sidebar) {
    logoToggle.addEventListener("click", () => {
      sidebar.classList.toggle("collapsed");
      setTimeout(() => {
        window.dispatchEvent(new Event("resize"));
      }, 300);
    });
  }
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
      fetch("api/auth.php", { method: "DELETE" });

      // ADD THESE TWO LINES: Clear the memory cache!
      localStorage.removeItem("memoria_role");
      localStorage.removeItem("memoria_username");

      setTimeout(() => {
        window.location.href = "login.html";
      }, 150);
    } catch (err) {
      console.error("Logout failed:", err);
    }
  });
}
//LOG OUT ---^

/**
 * Initializes User Auth, sets up the UI, and restricts access based on roles.
 * @param {Array} allowedRoles - Array of roles allowed on this page.
 *                               Leave empty [] to allow ANY logged-in user.
 *                               Example: ['Administrator', 'Office Staff']
 */
function initAuth(allowedRoles = []) {
  const cachedRole = localStorage.getItem("memoria_role");
  const cachedUsername = localStorage.getItem("memoria_username");

  // 1. INSTANT CACHE CHECK (Zero Flicker Kick)
  // If this page requires specific roles, and the cached role isn't one of them, kick them.
  if (
    allowedRoles.length > 0 &&
    cachedRole &&
    !allowedRoles.includes(cachedRole)
  ) {
    window.location.href = "dashboard.html";
    return; // Stop execution immediately
  }

  // Helper function to update the DOM and assign role-based CSS classes
  function updateUI(username, role) {
    const userLabel = document.getElementById("usernameLabel");
    const userLogo = document.getElementById("usernameLogo");
    const roleLabel = document.getElementById("roleLabel");

    if (userLabel) userLabel.textContent = username;
    if (userLogo) userLogo.textContent = username.toUpperCase().substring(0, 2);
    if (roleLabel) roleLabel.textContent = role;

    // Remove any existing role classes to prevent stale data
    document.documentElement.classList.remove(
      "role-admin",
      "role-office",
      "role-grounds",
    );

    // Add the specific role class to the <html> tag for CSS targeting
    if (role === "Administrator") {
      document.documentElement.classList.add("role-admin");
    } else if (role === "Office Staff") {
      document.documentElement.classList.add("role-office");
    } else if (role === "Grounds Staff") {
      document.documentElement.classList.add("role-grounds");
    }
  }

  // 2. INSTANT UI UPDATE (From Cache)
  if (cachedUsername && cachedRole) {
    updateUI(cachedUsername, cachedRole);
  }

  // 3. SINGLE BACKGROUND VERIFICATION
  fetch("api/auth.php")
    .then(function (response) {
      const contentType = response.headers.get("content-type");
      if (!contentType || !contentType.includes("application/json")) {
        throw new Error("STATIC_SERVER");
      }
      if (response.status === 429) {
        throw new Error("RATE_LIMITED");
      }
      if (!response.ok) {
        throw new Error("AUTH_FAILED");
      }
      return response.json();
    })
    .then(function (responseData) {
      const user = responseData.data.user;

      // Double check role requirement against the TRUE server response
      if (allowedRoles.length > 0 && !allowedRoles.includes(user.role)) {
        // They shouldn't be here (either lied in cache or roles changed)
        window.location.href = "dashboard.html";
        return;
      }

      // Update cache and UI securely with fresh data
      localStorage.setItem("memoria_role", user.role);
      localStorage.setItem("memoria_username", user.username);
      updateUI(user.username, user.role);
    })
    .catch(function (error) {
      console.error("Auth check failed:", error.message);

      if (error.message === "RATE_LIMITED") {
        console.warn("WAIT A MINUTE, TOO MANY REQUESTS");
        if (typeof showAlertTOP === "function")
          showAlertTOP("Too many requests. Please try again later.", "warning");
        return;
      }

      if (error.message === "AUTH_FAILED") {
        // True failure (Not logged in)
        localStorage.removeItem("memoria_role");
        localStorage.removeItem("memoria_username");

        // Strip roles on logout
        document.documentElement.classList.remove(
          "role-admin",
          "role-office",
          "role-grounds",
        );
        window.location.href = "login.html";
      } else {
        // DEV MODE FALLBACK (STATIC_SERVER / LIVE SERVER)
        console.warn("Running locally without backend. Bypassing login kick.");

        const devUsername = "DevMode_Admin";
        const devRole = "Administrator"; // Change this locally to test different roles

        localStorage.setItem("memoria_role", devRole);
        localStorage.setItem("memoria_username", devUsername);

        updateUI(devUsername, devRole);

        if (typeof showAlertTOP === "function") {
          showAlertTOP(
            "Running in Dev Mode: Auth checks are bypassed!",
            "warning",
            5000,
          );
        }
      }
    });
}
