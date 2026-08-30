(function () {
  "use strict";

  // ---------- DOM references ----------
  const $ = (id) => document.getElementById(id);

  // ---------- State ----------
  let settings = {}; // setting_key → setting_value
  let idMap = {}; // setting_key → setting_id
  let settingsLoaded = false;
  let loadSettingsPromise = null; // for waiting if save is called early

  // ---------- TEXT mapping (element ID → setting key) ----------
  const TEXT_SETTINGS_MAP = {
    textbee_device_id: "textbeeDevice",
    textbee_api_key: "textbeeapiKey",
    main_color: "uiColor",
    people_1_name: "name1",
    people_1_title: "title1",
    people_2_name: "name2",
    people_2_title: "title2",
    people_3_name: "name3",
    people_3_title: "title3",
    people_4_name: "name4",
    people_4_title: "title4",
  };

  // ---------- IMAGE mapping ----------
  const IMAGE_SETTINGS_MAP = {
    header: {
      previewId: "reportHeaderPreview", // fixed: separate preview
      inputId: "reportHeaderUpload",
      desc: "Header Reports Document",
    },
    footer: {
      previewId: "reportFooterPreview",
      inputId: "reportFooterUpload",
      desc: "Footer Reports Document",
    },
  };

  // ---------- Load settings from API ----------
  async function loadSettings() {
    try {
      const response = await fetch("/api/settings");
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();

      if (!data.data || !Array.isArray(data.data)) {
        throw new Error("Invalid response format");
      }

      const newSettings = {};
      const newIdMap = {};
      data.data.forEach((item) => {
        if (item.setting_key) {
          newSettings[item.setting_key] = item.setting_value || "";
          if (item.setting_id) {
            newIdMap[item.setting_key] = item.setting_id;
          }
        }
      });

      settings = newSettings;
      idMap = newIdMap;
      settingsLoaded = true;

      // Populate UI
      populateTextFields();
      populateColorDisplay();
      // (Image previews are handled by file inputs, no initial load needed)
    } catch (error) {
      console.error("Failed to load settings:", error);
      showAlertTOP(
        "Failed to load settings. Please refresh the page.",
        "error",
      );
      throw error; // re-throw so callers know
    }
  }

  // ---------- Populate text inputs from settings ----------
  function populateTextFields() {
    for (const [key, elementId] of Object.entries(TEXT_SETTINGS_MAP)) {
      const el = $(elementId);
      if (!el) {
        console.warn(`Element #${elementId} not found for key "${key}"`);
        continue;
      }
      el.value = settings[key] || "";
    }
  }

  function populateColorDisplay() {
    const color = settings.main_color || "";
    const hexLabel = $("uiColorHex");
    if (hexLabel) {
      hexLabel.textContent = color; // safe (not innerHTML)
    }
  }

  // ---------- Wait for settings to be loaded before saving ----------
  async function ensureSettingsLoaded() {
    if (settingsLoaded) return;
    if (loadSettingsPromise) {
      await loadSettingsPromise;
      return;
    }
    // Start loading if not already
    loadSettingsPromise = loadSettings();
    await loadSettingsPromise;
  }

  // ---------- SAVE (main function) ----------
  async function saveSiteContent() {
    // Wait for settings to be loaded (so idMap is populated)
    await ensureSettingsLoaded();

    const formData = new FormData();
    let index = 0;
    const deletionPromises = [];

    // Helper to append a setting to the POST payload
    function appendToForm(key, value, desc, fileInputId) {
      formData.append(`bulk_settings[${index}][setting_key]`, key);
      formData.append(`bulk_settings[${index}][setting_value]`, value);
      formData.append(`bulk_settings[${index}][description]`, desc);

      if (fileInputId) {
        const fileInput = $(fileInputId);
        if (fileInput && fileInput.files.length > 0) {
          const file = fileInput.files[0];
          if (file.type !== "image/png") {
            showAlertTOP(
              `The image for "${desc}" must be a PNG file.`,
              "error",
            );
            throw new Error(`Invalid file type for ${desc}`);
          }
          formData.append(`bulk_images[${index}]`, file);
        }
      }
      index++;
    }

    // ---------- 1. Process text fields ----------
    for (const [key, elementId] of Object.entries(TEXT_SETTINGS_MAP)) {
      const el = $(elementId);
      if (!el) continue;

      const value = el.value.trim();

      // If empty, delete from DB (if it exists)
      if (value === "") {
        const existingId = idMap[key];
        if (existingId) {
          deletionPromises.push(
            fetch(`/api/settings/${existingId}`, { method: "DELETE" })
              .then((res) => {
                if (!res.ok) throw new Error(`DELETE failed (${res.status})`);
                delete idMap[key]; // prevent double deletion
              })
              .catch((err) => {
                console.error(`Delete error for ${key}:`, err);
                showAlertTOP(`Error deleting "${key}"`, "error");
              }),
          );
        }
        // If no ID, it's not in DB – skip saving.
        continue;
      }

      // Non‑empty: prepare to save (update or insert)
      let desc = `System content for ${key}`;
      if (key.startsWith("textbee")) desc += " (sensitive)";
      appendToForm(key, value, desc);
    }

    // ---------- 2. Process image fields ----------
    for (const [imageKey, config] of Object.entries(IMAGE_SETTINGS_MAP)) {
      const imgElement = $(config.previewId);
      // Use existing filename from data attribute, else default
      const existingVal =
        imgElement?.dataset.existingFilename || `${imageKey}.png`;
      appendToForm(imageKey, existingVal, config.desc, config.inputId);
    }

    // ---------- 3. Wait for deletions, then POST ----------
    try {
      await Promise.all(deletionPromises);

      const response = await fetch("/api/settings", {
        // same endpoint as GET
        method: "POST",
        body: formData,
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const result = await response.json();
      if (result && result.status === 200) {
        showAlertTOP("Site content saved successfully!", "success");
        // Optionally reload settings to refresh idMap for new inserts
        await loadSettings(); // refresh state
      } else {
        const msg = result?.message || "Failed to save site content.";
        showAlertTOP(msg, "error");
      }
    } catch (error) {
      console.error("Save error:", error);
      showAlertTOP(
        "Too many requests or server error. Please try again later.",
        "error",
      );
    }
  }

  // ---------- UI helper functions (unchanged, with minor tweaks) ----------
  window.togglePass = function (inputId, btn) {
    const input = $(inputId);
    if (!input) return;
    const icon = btn.querySelector("i");
    if (!icon) return;
    if (input.type === "password") {
      input.type = "text";
      icon.classList.replace("fa-eye", "fa-eye-slash");
    } else {
      input.type = "password";
      icon.classList.replace("fa-eye-slash", "fa-eye");
    }
  };

  window.previewLogo = function (input) {
    const preview = $("logoPreview");
    if (!preview || !input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = (e) => {
      preview.innerHTML = `<img src="${e.target.result}" alt="Logo Preview" />`;
    };
    reader.readAsDataURL(input.files[0]);
  };

  window.previewIcon = function (input, previewId) {
    const preview = $(previewId);
    if (!preview || !input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = (e) => {
      preview.innerHTML = `<img src="${e.target.result}" alt="Icon Preview" style="width:100%;height:100%;object-fit:contain;" />`;
    };
    reader.readAsDataURL(input.files[0]);
  };

  window.previewReport = function (input, previewId) {
    const preview = $(previewId);
    if (!preview || !input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = (e) => {
      preview.innerHTML = `<img src="${e.target.result}" alt="Preview" />`;
    };
    reader.readAsDataURL(input.files[0]);
  };

  window.updateColorHex = function (input) {
    const hexLabel = $("uiColorHex");
    if (hexLabel) hexLabel.textContent = input.value;
  };

  window.applyUIColor = function () {
    const input = $("uiColor");
    if (!input) return;
    const root = document.documentElement;
    input.addEventListener("input", () => {
      const color = input.value;
      root.style.setProperty("--mainColor", color);
      const rgb = color.match(/\w\w/g)?.map((x) => parseInt(x, 16));
      if (!rgb) return;
      const brightness = (rgb[0] * 299 + rgb[1] * 587 + rgb[2] * 114) / 1000;
      const textColor = brightness > 128 ? "#0f172a" : "#ffffff";
      root.style.setProperty("--sidebarText", textColor);
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
  };

  // ---------- DOMContentLoaded: initialisation ----------
  document.addEventListener("DOMContentLoaded", async () => {
    // 1. Load settings
    try {
      await loadSettings();
    } catch {
      // Already handled inside loadSettings
    }

    // 2. Load user profile
    try {
      const resp = await fetch("/api/auth");
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();
      if (data.success && data.data) {
        $("profileName").value = data.data.name || "";
        $("profileUsername").value = data.data.username || "";
        $("profileEmail").value = data.data.email || "";
        $("profilePhone").value = data.data.phone_number || "";
        $("userid").value = data.data.user_id || "";

        // Hide admin sections if not Administrator
        if (data.data.role !== "Administrator") {
          const hideAdmin = $("hideadmin");
          const hideAdmin1 = $("hideadmin1");
          if (hideAdmin) {
            hideAdmin.style.display = "none";
            hideAdmin.style.opacity = "0";
          }
          if (hideAdmin1) {
            hideAdmin1.style.display = "none";
            hideAdmin1.style.opacity = "0";
          }
        }
      }
    } catch (error) {
      console.error("Profile load error:", error);
      showAlertTOP("Failed to load profile. Please refresh.", "error");
    }

    // 3. Profile form submission
    const profileForm = $("profileForm");
    if (profileForm) {
      profileForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        const password = $("profilePassword")?.value?.trim() || "";
        const confirm = $("profileConfirm")?.value?.trim() || "";
        if (password && password !== confirm) {
          showAlertTOP("Passwords do not match!", "error");
          return;
        }
        const payload = {
          name: $("profileName")?.value?.trim() || "",
          username: $("profileUsername")?.value?.trim() || "",
          email: $("profileEmail")?.value?.trim() || "",
          phone_number: $("profilePhone")?.value?.trim() || "",
          password: password || null,
        };
        const userid = $("userid")?.value;
        if (!userid) {
          showAlertTOP("User ID missing. Please log in again.", "error");
          return;
        }
        try {
          const resp = await fetch(`/api/users.php/${userid}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
          });
          const data = await resp.json();
          if (data?.message) {
            showAlertTOP(data.message, data.success ? "success" : "error");
          } else {
            showAlertTOP("Profile updated successfully.", "success");
          }
          if (data?.success && password) {
            showModal({
              type: "success",
              title: "Password Changed Successfully",
              message: "Your account has been updated. Please log in again.",
              actionText: "Proceed to login",
              actionLink: "login.html",
              allowOutsideClick: false,
            });
          }
        } catch (error) {
          console.error("Profile update error:", error);
          showAlertTOP("Failed to update profile. Please try again.", "error");
        }
      });
    }

    // 4. System configuration form submission
    const systemForm = $("systemForm");
    if (systemForm) {
      systemForm.addEventListener("submit", (e) => {
        e.preventDefault();
        saveSiteContent(); // async, but we don't await here (it handles itself)
      });
    }
  });

  // Expose save function globally (if needed)
  window.saveSiteContent = saveSiteContent;
})();
