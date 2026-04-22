// ==========================================
// 1. SUCCESS / ERROR / WARNING MODAL
// ==========================================
function showModal({
  type = "success", // success | warning | error
  title = "Notification",
  message = "",
  actionText = "",
  actionLink = "/",
  allowOutsideClick = true,
}) {
  // Remove existing modal if any
  const existing = document.querySelector("#custom-modal-overlay");
  if (existing) existing.remove();

  // Inject styles (only once)
  if (!document.querySelector("#custom-modal-styles")) {
    const style = document.createElement("style");
    style.id = "custom-modal-styles";
    style.textContent = `
      #custom-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        backdrop-filter: blur(6px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        animation: fadeIn 0.2s ease;
      }

      #custom-modal-box {
        width: 100%;
        max-width: 380px;
        background: #ffffff;
        border-radius: 16px;
        padding: 24px 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        text-align: center;
        position: relative;
        animation: scaleIn 0.25s ease;
        font-family: system-ui, -apple-system, sans-serif;
      }

      .modal-icon {
        width: 60px;
        height: 60px;
        margin: 0 auto 12px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        font-weight: bold;
        color: white;
      }

      .success { background: #22c55e; }
      .warning { background: #f59e0b; }
      .error { background: #ef4444; }

      .modal-title {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 8px;
      }

      .modal-message {
        font-size: 14px;
        color: #555;
        margin-bottom: 18px;
        line-height: 1.4;
      }

      .modal-btn {
        padding: 10px 16px;
        border: none;
        border-radius: 10px;
        font-size: 14px;
        cursor: pointer;
        transition: 0.2s;
        font-weight: 500;
      }

      .btn-success { background: #22c55e; color: white; }
      .btn-warning { background: #f59e0b; color: white; }
      .btn-error { background: #ef4444; color: white; }

      .modal-btn:hover {
        transform: translateY(-1px);
        opacity: 0.9;
      }

      .close-btn {
        position: absolute;
        top: 10px;
        right: 12px;
        font-size: 20px;
        border: none;
        background: transparent;
        cursor: pointer;
        color: #888;
      }

      .close-btn:hover { color: #000; }

      @keyframes fadeIn {
        from { opacity: 0 }
        to { opacity: 1 }
      }

      @keyframes scaleIn {
        from { transform: scale(0.9); opacity: 0 }
        to { transform: scale(1); opacity: 1 }
      }

      @keyframes fadeOut {
        to { opacity: 0 }
      }

      @keyframes scaleOut {
        to { transform: scale(0.95); opacity: 0 }
      }
    `;
    document.head.appendChild(style);
  }

  // Icons
  const icons = {
    success: "✓",
    warning: "!",
    error: "✕",
  };

  const overlay = document.createElement("div");
  overlay.id = "custom-modal-overlay";

  const modal = document.createElement("div");
  modal.id = "custom-modal-box";

  modal.innerHTML = `
    ${allowOutsideClick ? `<button class="close-btn">&times;</button>` : ""}
    <div class="modal-icon ${type}">${icons[type]}</div>
    <div class="modal-title">${title}</div>
    <div class="modal-message">${message}</div>
    ${actionText ? `<button class="modal-btn btn-${type}">${actionText}</button>` : ""}
  `;

  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  const closeModal = () => {
    overlay.style.animation = "fadeOut 0.2s forwards";
    modal.style.animation = "scaleOut 0.2s forwards";
    setTimeout(() => overlay.remove(), 200);
  };

  // Outside click
  if (allowOutsideClick) {
    overlay.addEventListener("click", closeModal);
  }

  // Prevent closing when clicking inside
  modal.addEventListener("click", (e) => e.stopPropagation());

  // Close button
  const closeBtn = modal.querySelector(".close-btn");
  if (closeBtn) closeBtn.onclick = closeModal;

  // Action button
  if (actionText) {
    modal.querySelector(".modal-btn").onclick = () => {
      if (actionLink === "/") {
        closeModal();
      } else {
        window.location.href = actionLink;
      }
    };
  }

  // ESC key support
  document.addEventListener("keydown", function escHandler(e) {
    if (e.key === "Escape" && allowOutsideClick) {
      closeModal();
      document.removeEventListener("keydown", escHandler);
    }
  });
}

// ==========================================
// 2. FULLSCREEN LOADING SPINNER
// ==========================================
function showLoading(message = "Processing...") {
  const existing = document.querySelector("#custom-loading-overlay");
  if (existing) existing.remove();

  if (!document.querySelector("#custom-loading-styles")) {
    const style = document.createElement("style");
    style.id = "custom-loading-styles";
    style.textContent = `
      #custom-loading-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        backdrop-filter: blur(6px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10005; /* Always stays above the normal modal */
        animation: fadeIn 0.2s ease;
      }

      #custom-loading-box {
        width: 100%;
        max-width: 320px;
        background: #ffffff;
        border-radius: 16px;
        padding: 30px 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        position: relative;
        animation: scaleIn 0.25s ease;
        font-family: system-ui, -apple-system, sans-serif;
      }

      .loading-spinner {
        width: 50px;
        height: 50px;
        border: 4px solid rgba(155, 93, 229, 0.2);
        border-top: 4px solid #9b5de5; 
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-bottom: 16px;
      }

      .loading-message {
        font-size: 16px;
        font-weight: 600;
        color: #1e293b;
        letter-spacing: 0.5px;
      }

      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }

      @keyframes fadeOutLoader {
        to { opacity: 0 }
      }

      @keyframes scaleOutLoader {
        to { transform: scale(0.95); opacity: 0 }
      }
    `;
    document.head.appendChild(style);
  }

  const overlay = document.createElement("div");
  overlay.id = "custom-loading-overlay";

  const loaderBox = document.createElement("div");
  loaderBox.id = "custom-loading-box";

  loaderBox.innerHTML = `
    <div class="loading-spinner"></div>
    <div class="loading-message" id="custom-loading-text">${message}</div>
  `;

  overlay.appendChild(loaderBox);

  // Prevent closing when clicking - forces the user to wait for the API
  overlay.addEventListener("click", (e) => e.stopPropagation());
  loaderBox.addEventListener("click", (e) => e.stopPropagation());

  document.body.appendChild(overlay);
}

// ==========================================
// 3. HIDE LOADING SPINNER
// ==========================================
function hideLoading() {
  const overlay = document.querySelector("#custom-loading-overlay");
  const loaderBox = document.querySelector("#custom-loading-box");

  if (overlay && loaderBox) {
    overlay.style.animation = "fadeOutLoader 0.2s forwards";
    loaderBox.style.animation = "scaleOutLoader 0.2s forwards";
    setTimeout(() => overlay.remove(), 200);
  }
}
