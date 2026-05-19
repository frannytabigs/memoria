document.addEventListener("DOMContentLoaded", () => {
  // 1. Fetch the data when the page loads
  fetchLogs();

  async function fetchLogs() {
    try {
      // Call your API
      const response = await fetch("api/viewlogs");
      const result = await response.json();

      if (result.success && result.data && result.data.logs) {
        renderLogs(result.data.logs);
      } else {
        renderLogs(["No logs available."]); // Render an empty state if no logs are returned
      }
    } catch (error) {
      console.error("Failed to fetch logs:", error);
      renderLogs(["Error fetching logs. Please try again later."]); // Show an error message in the UI
    }
  }

  // 2. Render the data to the screen
  function renderLogs(logsArray) {
    const listElement = document.getElementById("logList");
    listElement.innerHTML = ""; // Clear out any loading text

    // --- NEW: Safer Check for Empty States & Dummy Inputs ---
    // We explicitly check if the single string matches our custom error messages
    const isDummyMessage =
      logsArray.length === 1 &&
      (logsArray[0] === "No logs available." ||
        logsArray[0].includes("Error fetching logs"));

    if (!logsArray || logsArray.length === 0 || isDummyMessage) {
      // If the message contains "Error", make it red. Otherwise, make it a soft gray.
      const message =
        logsArray.length === 1 ? logsArray[0] : "No logs available.";
      const textColor = message.toLowerCase().includes("error")
        ? "#dc2626"
        : "#6b7280";

      listElement.innerHTML = `
        <li class="log-item" style="justify-content: center; color: ${textColor}; font-weight: 500; font-style: italic; background-color: transparent;">
            ${message}
        </li>
      `;
      return; // Stop the function here
    }

    logsArray.forEach((logString) => {
      // REGEX: Looks for [Date] Action (User ID: X)
      const match = logString.match(/^\[(.*?)\] (.*?) \(User ID: (.*?)\)$/);

      const li = document.createElement("li");
      li.className = "log-item";

      if (match) {
        // If the string is formatted correctly, extract the pieces
        const timestamp = match[1];
        const action = match[2];
        const rawUserId = match[3].trim();

        // Handle 'unsure' or empty User IDs
        let displayUser, userBadgeClass;
        if (!rawUserId) {
          displayUser = "Unauthenticated";
          userBadgeClass = "empty";
        } else if (rawUserId.toLowerCase() === "system") {
          displayUser = "System Event";
          userBadgeClass = "system";
        } else {
          displayUser = `User ID: ${rawUserId}`;
          userBadgeClass = "normal";
        }

        // Color-code the action text based on keywords
        let actionTextClass = "";
        const lowerAction = action.toLowerCase();
        if (lowerAction.includes("failed") || lowerAction.includes("error")) {
          actionTextClass = "text-error";
        } else if (lowerAction.includes("registered")) {
          actionTextClass = "text-success";
        }

        // Build the normal row
        li.innerHTML = `
            <span class="log-time">${timestamp}</span>
            <span class="log-action ${actionTextClass}">${action}</span>
            <span class="log-user ${userBadgeClass}">${displayUser}</span>
        `;
      } else {
        // FALLBACK: If a log string is totally unformatted (no date, no user ID).
        // It skips the 3-column layout and simply prints the raw sentence nicely.
        li.innerHTML = `
            <span class="log-action text-warning" style="padding-left: 0; flex-grow: 1;">${logString}</span>
        `;
      }

      listElement.appendChild(li);
    });

    // 3. Initialize the Search functionality AFTER logs are rendered
    initSearch();
  }

  // 4. The Search Highlight, Filter & Clear Logic
  function initSearch() {
    const searchInput = document.querySelector(".search-input");
    const clearIcon = document.querySelector(".clear-icon");
    const logItems = document.querySelectorAll(".log-item");

    // Save original HTML
    logItems.forEach((item) => {
      item.dataset.originalHtml = item.innerHTML;
    });

    searchInput.addEventListener("input", (e) => {
      const searchTerm = e.target.value.trim();

      // Show the "X" if there is text, hide it if empty
      clearIcon.style.display = searchTerm ? "block" : "none";

      // Reset rows
      logItems.forEach((item) => {
        item.innerHTML = item.dataset.originalHtml;
        item.style.display = "";
      });

      if (!searchTerm) return;

      const searchRegex = new RegExp(`(${searchTerm})`, "gi");

      logItems.forEach((item) => {
        if (item.textContent.toLowerCase().includes(searchTerm.toLowerCase())) {
          const walker = document.createTreeWalker(
            item,
            NodeFilter.SHOW_TEXT,
            null,
            false,
          );
          const nodesToReplace = [];
          let node;

          while ((node = walker.nextNode())) {
            if (searchRegex.test(node.nodeValue)) nodesToReplace.push(node);
          }

          nodesToReplace.forEach((textNode) => {
            const fragment = document.createDocumentFragment();
            const parts = textNode.nodeValue.split(searchRegex);

            parts.forEach((part) => {
              if (searchRegex.test(part)) {
                const mark = document.createElement("mark");
                mark.className = "search-highlight";
                mark.textContent = part;
                fragment.appendChild(mark);
              } else {
                fragment.appendChild(document.createTextNode(part));
              }
            });
            textNode.parentNode.replaceChild(fragment, textNode);
          });
        } else {
          item.style.display = "none";
        }
      });
    });

    // Click event for the "X" button
    clearIcon.addEventListener("click", () => {
      searchInput.value = "";
      clearIcon.style.display = "none";
      searchInput.dispatchEvent(new Event("input"));
      searchInput.focus();
    });
  }
});
