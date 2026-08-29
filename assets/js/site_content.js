// Map specific HTML element IDs to database setting_keys
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

// 1. Fetch Settings on Page Load
document.addEventListener("DOMContentLoaded", loadSiteContent);

function loadSiteContent() {
  fetch("/api/settings", { cache: "no-store" })
    .then(function (response) {
      return response.json();
    })
    .then(function (result) {
      // Check if result has the expected structure
      if (!result || !result.data) {
        console.error("Unexpected response structure:", result);
        showAlertTOP(
          "Failed to load settings: Invalid response format",
          "error",
        );
        return;
      }

      // Create a Set to track which keys we've already processed
      var processedKeys = new Set();

      result.data.forEach(function (setting) {
        var key = setting.setting_key;

        // Because PHP returns newest first, if we've already seen this key,
        // the current row is an old duplicate. Skip it!
        if (processedKeys.has(key)) return;
        processedKeys.add(key);

        var val = setting.setting_value;
        //console.log(key);
        if (key.startsWith("requirements_for_burial")) {
          // 5. Convert the Markdown to raw HTML
          const rawHtml = marked.parse(val);

          // 6. Sanitize the HTML to remove any potential malicious scripts
          const cleanHtml = DOMPurify.sanitize(rawHtml);

          // 7. Inject the clean HTML into your webpage container
          document.getElementById("burialRequirements").innerHTML = cleanHtml;
          // console.log(cleanHtml);
        }

        // Populate Text Fields & Textareas
        if (
          TEXT_SETTINGS_MAP[key] &&
          document.getElementById(TEXT_SETTINGS_MAP[key])
        ) {
          var el = document.getElementById(TEXT_SETTINGS_MAP[key]);

          // Use .value for inputs/textareas/selects, otherwise .textContent
          if (
            el.tagName === "INPUT" ||
            el.tagName === "TEXTAREA" ||
            el.tagName === "SELECT"
          ) {
            el.value = val;
          } else {
            el.textContent = val;
          }
        }
        // Populate Image Previews
        if (
          IMAGE_SETTINGS_MAP[key] &&
          document.getElementById(IMAGE_SETTINGS_MAP[key].previewId)
        ) {
          if (val) {
            var imgElement = document.getElementById(
              IMAGE_SETTINGS_MAP[key].previewId,
            );

            var timestamp = new Date().getTime();
            imgElement.src = "/api/images/" + val + "?t=" + timestamp;
            imgElement.dataset.existingFilename = val;
          }
        }

        // Populate Arrays (Dropdowns)
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
        } catch (jsonError) {
          console.warn("Could not parse JSON for " + key + ":", val);
          // This prevents the page from breaking if one DB row is formatted incorrectly!
        }
      });
    })
    .catch(function (error) {
      console.error(error);
      showAlertTOP("Too many requests. Please try again later.", "error");
    });
}

// Helper: Rebuild HTML lists from JSON arrays
function rebuildDropdownList(containerId, itemsArray, inputClass) {
  var container = document.getElementById(containerId);
  if (!container) return;

  container.innerHTML = "";

  itemsArray.forEach(function (item) {
    var newItem = document.createElement("div");
    newItem.className = "optionItem";
    newItem.innerHTML = `
            <input type="text" class="${inputClass}" value="${item}">
            <button type="button" class="btnRemove" onclick="removeOption(this)"><i class="fas fa-trash"></i></button>
        `;
    container.appendChild(newItem);
  });
}

// 2. Save Settings to Database
function saveSiteContent() {
  var formData = new FormData();
  var index = 0;

  // Helper to structure bulk data for PHP
  var appendToForm = function (key, value, desc, fileInputId) {
    formData.append("bulk_settings[" + index + "][setting_key]", key);
    formData.append("bulk_settings[" + index + "][setting_value]", value);
    formData.append("bulk_settings[" + index + "][description]", desc);

    if (fileInputId) {
      var fileInput = document.getElementById(fileInputId);
      if (fileInput && fileInput.files.length > 0) {
        var file = fileInput.files[0];

        // Strict Frontend PNG Check
        if (file.type !== "image/png") {
          showAlertTOP("Image file should be a PNG", "error");
          throw new Error("The image for " + desc + " MUST be a PNG file.");
        }

        formData.append("bulk_images[" + index + "]", file);
      }
    }
    index++;
  };

  // Append Text Fields
  for (var textKey in TEXT_SETTINGS_MAP) {
    if (TEXT_SETTINGS_MAP.hasOwnProperty(textKey)) {
      var elementId = TEXT_SETTINGS_MAP[textKey];
      var el = document.getElementById(elementId);
      if (el) {
        // Check for defaultValue (textareas) OR placeholder (inputs)
        var valToSave = el.value.trim();
        if (!valToSave) {
          valToSave = el.defaultValue
            ? el.defaultValue.trim()
            : el.placeholder
              ? el.placeholder.trim()
              : "";
        }

        appendToForm(textKey, valToSave, "System content for " + textKey);
      }
    }
  }

  // Append Image Fields
  for (var imageKey in IMAGE_SETTINGS_MAP) {
    if (IMAGE_SETTINGS_MAP.hasOwnProperty(imageKey)) {
      var config = IMAGE_SETTINGS_MAP[imageKey];
      var imgElement = document.getElementById(config.previewId);

      // If no file is saved yet (empty DB), force the standard PNG name
      var existingVal =
        imgElement && imgElement.dataset.existingFilename
          ? imgElement.dataset.existingFilename
          : imageKey + ".png";

      appendToForm(imageKey, existingVal, config.desc, config.inputId);
    }
  }

  // Append Arrays (Dropdowns)
  var channels = [];
  var channelInputs = document.querySelectorAll("#paymentChannelsList input");
  for (var i = 0; i < channelInputs.length; i++) {
    var val = channelInputs[i].value;
    if (val.trim() !== "") {
      channels.push(val);
    }
  }

  var purposes = [];
  var purposeInputs = document.querySelectorAll("#paymentPurposesList input");
  for (var j = 0; j < purposeInputs.length; j++) {
    var val2 = purposeInputs[j].value;
    if (val2.trim() !== "") {
      purposes.push(val2);
    }
  }

  // Convert arrays to JSON strings before appending
  appendToForm(
    "payment_channels",
    JSON.stringify(channels),
    "Dropdown list for notification types",
  );
  appendToForm(
    "permit_types",
    JSON.stringify(purposes),
    "Dropdown list for permit classifications",
  );

  // Transmit to API
  fetch("/api/settings.php", {
    method: "POST",
    body: formData,
  })
    .then(function (response) {
      return response.json();
    })
    .then(function (result) {
      // Check if result has status property
      if (result && result.status === 200) {
        showAlertTOP("Site content saved successfully!", "success");
        setTimeout(() => location.reload(true), 2000);
      } else {
        var errorMsg =
          result && result.message
            ? result.message
            : "Failed to save site content.";
        showAlertTOP(errorMsg, "error");
      }
    })
    .catch(function (error) {
      console.error(error);
      showAlertTOP("Too many requests. Please try again later.", "error");
    });
}

// 3. Existing UI Helpers
function previewImage(input, previewId) {
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function (e) {
      document.getElementById(previewId).src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function addOption(containerId) {
  var container = document.getElementById(containerId);
  var inputClass =
    containerId === "paymentChannelsList" ? "channelInput" : "purposeInput";
  var newItem = document.createElement("div");
  newItem.className = "optionItem";
  newItem.innerHTML = `
        <input type="text" class="${inputClass}" value="">
        <button type="button" class="btnRemove" onclick="removeOption(this)"><i class="fas fa-trash"></i></button>
    `;
  container.appendChild(newItem);
}

function removeOption(button) {
  button.parentElement.remove();
}
