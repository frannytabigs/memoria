document.addEventListener("DOMContentLoaded", async () => {
  try {
    const response = await fetch("api/auth.php");
    const contentType = response.headers.get("content-type");

    // Only redirect if the response is successful AND it is actual JSON data
    if (
      response.ok &&
      contentType &&
      contentType.includes("application/json")
    ) {
      window.location.href = "dashboard.html";
    } else if (response.status === 429) {
      console.warn("Too many request.");
    } else {
      // 1. Map API setting keys → array of HTML element IDs
      const TEXT_SETTINGS_MAP = {
        main_title: ["heading_one"],
        cemetery_title: ["cemetery_name"],
        cemetery_address: ["cemetery_address"],
      };
      fetch("/api/settings", { cache: "no-store" })
        .then(function (response) {
          return response.json();
        })
        .then(function (result) {
          if (!result || !result.data) {
            console.error("Unexpected response structure:", result);
            // showAlertTOP(
            //   "Failed to load settings: Invalid response format",
            //   "error",
            // );
            return;
          }

          var processedKeys = new Set();
          // console.log("--- Starting to load settings ---");

          result.data.forEach(function (setting) {
            var key = setting.setting_key;
            var val = setting.setting_value;

            if (processedKeys.has(key)) {
              // console.log("Skipping duplicate (older):", key);
              return;
            }
            processedKeys.add(key);
            // console.log("Processing key:", key, "→ value:", val);

            // ----- REGULAR TEXT FIELDS (using the map) -----
            var targetIds = TEXT_SETTINGS_MAP[key];
            if (targetIds) {
              targetIds.forEach(function (id) {
                var el = document.getElementById(id);
                if (!el) {
                  console.warn(
                    "  ✗ Element #" + id + " not found for key: " + key,
                  );
                  return;
                }
                if (
                  el.tagName === "INPUT" ||
                  el.tagName === "TEXTAREA" ||
                  el.tagName === "SELECT"
                ) {
                  el.value = val;
                } else {
                  el.textContent = val;
                }
                // console.log("  ✓ Updated #" + id + " → " + val);
              });
            } else {
              // console.log("  (No mapping for key:", key, ")");
            }

            // console.log(
            //   "--- Finished loading settings. Processed keys:",
            //   Array.from(processedKeys),
            // );
          });
        })
        .catch(function (error) {
          console.error(error);
          //showAlertTOP("Too many requests. Please try again later.", "error");
        });

      if (response.status === 401) return;

      console.warn(
        "Static server detected or API failed. Staying on the page.",
      );
    }
  } catch (error) {
    console.error("Error checking login status:", error);
  }
});
