// ============================================================
//  ADMIN SETTINGS – MANAGE SITE CONTENT & IMAGES
// ============================================================

// ---------- Mappings ----------
// Database key → HTML element ID (for text inputs, textareas, selects)
const TEXT_SETTINGS_MAP = {
  main_title: "deptTitle",
  cemetery_title: "cemeteryTitle",
  cemetery_address: "cemeteryAddress",
  office_address: "officeAddress",
  office_hours: "officeHours",
  map_embed_url: "cemeteryGoogleMaps",
  contact_phone: "contactPhone",
  contact_email: "contactEmail",
  requirements_for_burial: "requirements_for_burial",
};

// Database key → configuration for image inputs/previews
const IMAGE_SETTINGS_MAP = {
  logo1: { previewId: "logo1", inputId: "uploadlogo1", desc: "Header Logo 1" },
  logo2: { previewId: "logo2", inputId: "uploadlogo2", desc: "Header Logo 2" },
  cemetery_logo: {
    previewId: "cemetery_logo",
    inputId: "uploadcemetery_logo",
    desc: "Main Cemetery Logo",
  },
  cemetery_logo_small: {
    previewId: "cemetery_logo_small",
    inputId: "uploadcemetery_logo_small",
    desc: "Small Cemetery Logo",
  },
  cemetery_background: {
    previewId: "cemetery_background",
    inputId: "uploadcemetery_background",
    desc: "Cemetery Background Logo",
  },
};

// ---------- Helpers ----------
// Create a DOM element from a string, but safer than innerHTML
function createElementFromHTML(html) {
  const template = document.createElement("template");
  template.innerHTML = html.trim();
  return template.content.firstElementChild;
}

// Rebuild a list of option items from an array (for payment channels / permit types)
function rebuildDropdownList(containerId, itemsArray, inputClass) {
  const container = document.getElementById(containerId);
  if (!container) return;

  container.innerHTML = ""; // Clear existing

  for (const item of itemsArray) {
    const div = document.createElement("div");
    div.className = "optionItem";

    const input = document.createElement("input");
    input.type = "text";
    input.className = inputClass;
    input.value = item; // safe – no need to escape for attribute when using .value

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btnRemove";
    btn.innerHTML = '<i class="fas fa-trash"></i>';
    btn.addEventListener("click", () => {
      div.remove(); // modern removal
    });

    div.appendChild(input);
    div.appendChild(btn);
    container.appendChild(div);
  }
}

// ---------- Load Settings ----------
function loadSiteContent() {
  fetch("/api/settings", { cache: "no-store" })
    .then((response) => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return response.json();
    })
    .then((result) => {
      if (!result?.data) {
        console.error("Invalid settings response:", result);
        showAlertTOP("Failed to load settings: invalid response", "error");
        return;
      }

      const processedKeys = new Set();

      for (const setting of result.data) {
        const key = setting.setting_key;
        if (processedKeys.has(key)) continue; // skip duplicates (newest first)
        processedKeys.add(key);

        const val = setting.setting_value;

        // ---- Special case: Burial Requirements (Markdown → sanitised HTML) ----
        if (key === "requirements_for_burial") {
          const el = document.getElementById("burialRequirements");
          if (el) {
            const rawHtml = marked.parse(val || "");
            const cleanHtml = DOMPurify.sanitize(rawHtml);
            el.innerHTML = cleanHtml;
          }
          continue;
        }

        // ---- Text fields (including map_embed_url, contact, etc.) ----
        const targetId = TEXT_SETTINGS_MAP[key];
        if (targetId) {
          const el = document.getElementById(targetId);
          if (el) {
            if (["INPUT", "TEXTAREA", "SELECT"].includes(el.tagName)) {
              el.value = val;
            } else {
              el.textContent = val; // safe
            }
          }
        }

        // ---- Image previews ----
        const imageConfig = IMAGE_SETTINGS_MAP[key];
        if (imageConfig && val) {
          const img = document.getElementById(imageConfig.previewId);
          if (img) {
            // Add cache‑busting timestamp
            img.src = `/api/images/${val}?t=${Date.now()}`;
            img.dataset.existingFilename = val;
          }
        }

        // ---- Dropdown lists (JSON arrays) ----
        try {
          if (key === "payment_channels" && val) {
            rebuildDropdownList(
              "paymentChannelsList",
              JSON.parse(val),
              "channelInput",
            );
          }
          if (key === "permit_types" && val) {
            rebuildDropdownList(
              "paymentPurposesList",
              JSON.parse(val),
              "purposeInput",
            );
          }
        } catch {
          // Silently ignore invalid JSON – won't break the page
        }
      }
    })
    .catch((error) => {
      console.error("Error loading settings:", error);
      showAlertTOP("Failed to load settings. Please refresh.", "error");
    });
}

// ---------- Save Settings ----------
function saveSiteContent() {
  const formData = new FormData();
  let index = 0;

  // Helper to append a setting entry
  const appendToForm = (key, value, desc, fileInputId) => {
    formData.append(`bulk_settings[${index}][setting_key]`, key);
    formData.append(`bulk_settings[${index}][setting_value]`, value);
    formData.append(`bulk_settings[${index}][description]`, desc);

    if (fileInputId) {
      const fileInput = document.getElementById(fileInputId);
      if (fileInput?.files?.length > 0) {
        const file = fileInput.files[0];
        if (file.type !== "image/png") {
          showAlertTOP("Image file must be a PNG", "error");
          throw new Error(`Invalid file type for ${desc}`);
        }
        formData.append(`bulk_images[${index}]`, file);
      }
    }
    index++;
  };

  // ---- 1. Text fields ----
  for (const [key, elementId] of Object.entries(TEXT_SETTINGS_MAP)) {
    const el = document.getElementById(elementId);
    if (!el) continue;

    // For inputs/textareas, use .value; fallback to placeholder if empty
    let value = el.value?.trim() || "";
    if (!value) {
      value = el.placeholder?.trim() || "";
    }
    appendToForm(key, value, `System content for ${key}`);
  }

  // ---- 2. Image fields ----
  for (const [key, config] of Object.entries(IMAGE_SETTINGS_MAP)) {
    const img = document.getElementById(config.previewId);
    const existingVal = img?.dataset?.existingFilename || `${key}.png`;
    appendToForm(key, existingVal, config.desc, config.inputId);
  }

  // ---- 3. Dropdown lists (collect from UI) ----
  const collectItems = (containerId) => {
    const inputs = document.querySelectorAll(`#${containerId} input`);
    const items = [];
    for (const inp of inputs) {
      const val = inp.value.trim();
      if (val) items.push(val);
    }
    return items;
  };

  const channels = collectItems("paymentChannelsList");
  const purposes = collectItems("paymentPurposesList");

  appendToForm(
    "payment_channels",
    JSON.stringify(channels),
    "Dropdown list for payment channels",
  );
  appendToForm(
    "permit_types",
    JSON.stringify(purposes),
    "Dropdown list for permit types",
  );

  // ---- 4. Send to server ----
  fetch("/api/settings.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return response.json();
    })
    .then((result) => {
      if (result?.status === 200) {
        showAlertTOP("Site content saved successfully!", "success");
        setTimeout(() => location.reload(true), 2000);
      } else {
        const msg = result?.message || "Failed to save site content.";
        showAlertTOP(msg, "error");
      }
    })
    .catch((error) => {
      console.error("Save error:", error);
      showAlertTOP(
        "Too many requests or server error. Please try again.",
        "error",
      );
    });
}

// ---------- UI Helpers (for add/remove options) ----------
function addOption(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const inputClass =
    containerId === "paymentChannelsList" ? "channelInput" : "purposeInput";
  const div = document.createElement("div");
  div.className = "optionItem";

  const input = document.createElement("input");
  input.type = "text";
  input.className = inputClass;
  input.value = "";

  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "btnRemove";
  btn.innerHTML = '<i class="fas fa-trash"></i>';
  btn.addEventListener("click", () => div.remove());

  div.appendChild(input);
  div.appendChild(btn);
  container.appendChild(div);
}

// (removeOption is no longer needed, as we use event listeners)

// ---------- Image Preview Helpers ----------
function previewImage(input, previewId) {
  if (!input?.files?.length) return;
  const reader = new FileReader();
  reader.onload = (e) => {
    const img = document.getElementById(previewId);
    if (img) img.src = e.target.result;
  };
  reader.readAsDataURL(input.files[0]);
}

// ---------- Initialisation ----------
document.addEventListener("DOMContentLoaded", loadSiteContent);
