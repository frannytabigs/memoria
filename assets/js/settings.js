function togglePass(inputId, btn) {
  const input = document.getElementById(inputId);
  const icon = btn.querySelector("i");
  if (!input) return;

  if (input.type === "password") {
    input.type = "text";
    icon.classList.replace("fa-eye", "fa-eye-slash");
  } else {
    input.type = "password";
    icon.classList.replace("fa-eye-slash", "fa-eye");
  }
}

function previewLogo(input) {
  const preview = document.getElementById("logoPreview");
  if (!preview || !input.files || !input.files[0]) return;

  const reader = new FileReader();
  reader.onload = (e) => {
    preview.innerHTML = `<img src="${e.target.result}" alt="Logo Preview" />`;
  };
  reader.readAsDataURL(input.files[0]);
}

function deleteLogo() {
  const preview = document.getElementById("logoPreview");
  const input = document.getElementById("logoUpload");
  if (preview) preview.innerHTML = `<i class="fas fa-image"></i>`;
  if (input) input.value = "";
  showToast("Logo removed.", "info");
}

function previewIcon(input, previewId) {
  const preview = document.getElementById(previewId);
  if (!preview || !input.files || !input.files[0]) return;

  const reader = new FileReader();
  reader.onload = (e) => {
    preview.innerHTML = `<img src="${e.target.result}" alt="Icon Preview" style="width:100%;height:100%;object-fit:contain;" />`;
  };
  reader.readAsDataURL(input.files[0]);
}

function deleteIcon(previewId, inputId) {
  const preview = document.getElementById(previewId);
  const input = document.getElementById(inputId);
  if (preview) preview.innerHTML = `<i class="fas fa-image"></i>`;
  if (input) input.value = "";
  showToast("Icon removed.", "info");
}

function previewReport(input, previewId) {
  const preview = document.getElementById(previewId);
  if (!preview || !input.files || !input.files[0]) return;

  const reader = new FileReader();
  reader.onload = (e) => {
    preview.innerHTML = `<img src="${e.target.result}" alt="Preview" />`;
  };
  reader.readAsDataURL(input.files[0]);
}

function deleteReport(previewId, inputId) {
  const preview = document.getElementById(previewId);
  const input = document.getElementById(inputId);
  const labels = {
    reportHeaderPreview: ["fa-file-image", "No header uploaded"],
    reportFooterPreview: ["fa-file-image", "No footer uploaded"],
    signatureImagePreview1: ["fa-signature", "No signature uploaded"],
    signatureImagePreview2: ["fa-signature", "No signature uploaded"],
    signatureImagePreview3: ["fa-signature", "No signature uploaded"],
    signatureImagePreview4: ["fa-signature", "No signature uploaded"],
  };

  const [icon, text] = labels[previewId] || [
    "fa-file-image",
    "No file uploaded",
  ];

  if (preview)
    preview.innerHTML = `<i class="fas ${icon}"></i><span>${text}</span>`;
  if (input) input.value = "";
  showToast("File removed.", "info");
}

function updateColorHex(input) {
  const hexLabel = document.getElementById("uiColorHex");
  if (hexLabel) hexLabel.textContent = input.value;
}

function applyUIColor() {
  const input = document.getElementById("uiColor");
  if (!input) return;

  const root = document.documentElement;

  input.addEventListener("input", () => {
    const color = input.value;

    // Main color
    root.style.setProperty("--mainColor", color);

    // Convert HEX to RGB
    const rgb = color.match(/\w\w/g).map((x) => parseInt(x, 16));

    // Detect brightness
    const brightness = (rgb[0] * 299 + rgb[1] * 587 + rgb[2] * 114) / 1000;

    // Auto text color
    const textColor = brightness > 128 ? "#0f172a" : "#ffffff";

    root.style.setProperty("--sidebarText", textColor);

    // Dynamic colors
    root.style.setProperty(
      "--sidebar-hover",
      `color-mix(in srgb, ${color}, white 12%)`,
    );

    root.style.setProperty(
      "--sidebar-border",
      `color-mix(in srgb, ${color}, white 18%)`,
    );

    root.style.setProperty(
      "--sidebar-accent",
      `color-mix(in srgb, ${color}, white 40%)`,
    );

    root.style.setProperty(
      "--sidebarText-muted",
      `color-mix(in srgb, ${textColor}, transparent 30%)`,
    );
  });
  showToast(`UI color applied: ${input.value}`, "success");
}

function resetForm(formId) {
  const form = document.getElementById(formId);
  if (!form) return;
  form.reset();

  if (formId === "systemForm") {
    const logoPreview = document.getElementById("logoPreview");
    if (logoPreview) logoPreview.innerHTML = `<i class="fas fa-image"></i>`;

    const hexLabel = document.getElementById("uiColorHex");
    if (hexLabel) hexLabel.textContent = "#4a7cfe";

    [1, 2, 3, 4].forEach((n) => {
      const sigPreview = document.getElementById(`signatureImagePreview${n}`);
      if (sigPreview)
        sigPreview.innerHTML = `<i class="fas fa-signature"></i><span>No signature uploaded</span>`;
    });
  }

  showToast("Form reset to original values.", "info");
}

document.addEventListener("DOMContentLoaded", () => {
  const profileForm = document.getElementById("profileForm");
  if (profileForm) {
    profileForm.addEventListener("submit", (e) => {
      e.preventDefault();

      const password = document.getElementById("profilePassword")?.value;
      const confirm = document.getElementById("profileConfirm")?.value;

      if (password && password !== confirm) {
        showToast("Passwords do not match!", "error");
        return;
      }

      const payload = {
        name: document.getElementById("profileName")?.value,
        username: document.getElementById("profileUsername")?.value,
        email: document.getElementById("profileEmail")?.value,
        phone: document.getElementById("profilePhone")?.value,
        password: password || null,
      };

      console.log("Profile payload:", payload);
      showToast("Profile updated successfully!", "success");
    });
  }

  const systemForm = document.getElementById("systemForm");
  if (systemForm) {
    systemForm.addEventListener("submit", (e) => {
      e.preventDefault();

      const payload = {
        cemeteryName: document.getElementById("cemeteryName")?.value,
        cemeteryAddress: document.getElementById("cemeteryAddress")?.value,
        textbeeKey: document.getElementById("textbeeKey")?.value,
        deviceId: document.getElementById("deviceId")?.value,
        uiColor: document.getElementById("uiColor")?.value,
        signatureName1: document.getElementById("signatureName1")?.value,
        signatureTitle1: document.getElementById("signatureTitle1")?.value,
        signatureName2: document.getElementById("signatureName2")?.value,
        signatureTitle2: document.getElementById("signatureTitle2")?.value,
        signatureName3: document.getElementById("signatureName3")?.value,
        signatureTitle3: document.getElementById("signatureTitle3")?.value,
        signatureName4: document.getElementById("signatureName4")?.value,
        signatureTitle4: document.getElementById("signatureTitle4")?.value,
      };

      console.log("System config payload:", payload);
      showToast("System configuration saved!", "success");
    });
  }
});

function showToast(message, type = "info") {
  const existing = document.getElementById("settingsToast");
  if (existing) existing.remove();

  const colors = {
    success: {
      bg: "#e6f9f0",
      border: "#27ae60",
      icon: "fa-check-circle",
      text: "#1a7a46",
    },
    error: {
      bg: "#fff0f0",
      border: "#e03e3e",
      icon: "fa-times-circle",
      text: "#b91c1c",
    },
    info: {
      bg: "#eef3ff",
      border: "#4a7cfe",
      icon: "fa-info-circle",
      text: "#2b55cc",
    },
  };

  const c = colors[type] || colors.info;

  const toast = document.createElement("div");
  toast.id = "settingsToast";
  toast.style.cssText = `
    position: fixed;
    bottom: 28px;
    right: 28px;
    background: ${c.bg};
    border: 1.5px solid ${c.border};
    color: ${c.text};
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    display: flex;
    align-items: center;
    gap: 9px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.10);
    z-index: 9999;
    animation: toastIn 0.25s ease;
  `;

  toast.innerHTML = `<i class="fas ${c.icon}"></i> ${message}`;
  document.body.appendChild(toast);

  if (!document.getElementById("toastStyle")) {
    const style = document.createElement("style");
    style.id = "toastStyle";
    style.textContent = `
      @keyframes toastIn {
        from { opacity: 0; transform: translateY(12px); }
        to { opacity: 1; transform: translateY(0); }
      }
    `;
    document.head.appendChild(style);
  }

  setTimeout(() => toast.remove(), 3500);
}
