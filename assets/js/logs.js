document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("searchInput");
  const filterSelect = document.getElementById("filterSelect");
  const datePicker = document.getElementById("datePicker");
  const tableBody = document.getElementById("logTableBody");
  const paginationText = document.getElementById("paginationText");
  const paginationBtnsContainer = document.getElementById("paginationBtns");

  let allLogs = [];
  const rowsPerPage = 10;
  let currentPage = 1;

  // 1. Fetch data on load
  fetchLogs();

  async function fetchLogs() {
    // Skeleton loader for table
    tableBody.innerHTML = Array(5)
      .fill(
        `
            <tr>
                <td colspan="4" style="text-align: center; color: #94a3b8;">
                    <i class="fas fa-spinner fa-spin"></i> Loading logs...
                </td>
            </tr>
        `,
      )
      .join("");

    try {
      const response = await fetch("api/viewlogs");
      const result = await response.json();

      if (result.success && result.data) {
        processLogs(result.data);
      } else {
        showEmptyState("No logs available.");
      }
    } catch (error) {
      console.error("Failed to fetch logs:", error);
      showEmptyState("Error fetching logs. Please try again later.");
    }
  }

  // 2. Parse raw strings into workable objects
  function processLogs(rawLogs) {
    allLogs = [];

    rawLogs.forEach((logString) => {
      // REGEX: Looks for [Date] Action (User ID: X)
      const match = logString.match(/^\[(.*?)\] (.*?) \(User ID: (.*?)\)$/);

      if (match) {
        const timestamp = match[1];
        const action = match[2];
        const userId = match[3].trim();

        // Auto-detect event type category for filtering
        let category = "User Actions";
        const lowerAction = action.toLowerCase();
        if (lowerAction.includes("log") || lowerAction.includes("auth"))
          category = "Auth";
        if (lowerAction.includes("setting") || lowerAction.includes("system"))
          category = "System";

        allLogs.push({
          timestamp,
          action,
          userId,
          category,
          originalString: logString,
        });
      } else {
        // Fallback for weirdly formatted logs
        allLogs.push({
          timestamp: "-",
          action: logString,
          userId: "-",
          category: "System",
          originalString: logString,
        });
      }
    });

    setTimeout(() => {
      renderTable();
    }, 1000);
  }

  // 3. Filter and Pagination Logic
  function getFilteredLogs() {
    const searchTerm = searchInput.value.toLowerCase().trim();
    const selectedCategory = filterSelect.value.toLowerCase().trim();
    const selectedDate = datePicker.value;

    return allLogs.filter((log) => {
      const matchesSearch = log.originalString
        .toLowerCase()
        .includes(searchTerm);
      const matchesCategory =
        !selectedCategory ||
        log.category.toLowerCase().includes(selectedCategory);
      const matchesDate =
        !selectedDate || log.timestamp.startsWith(selectedDate);

      return matchesSearch && matchesCategory && matchesDate;
    });
  }

  function renderTable() {
    const filteredLogs = getFilteredLogs();
    const totalRows = filteredLogs.length;
    const totalPages = Math.ceil(totalRows / rowsPerPage) || 1;

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    tableBody.innerHTML = "";

    if (totalRows === 0) {
      showEmptyState("No logs found matching your criteria.");
      paginationText.textContent = `Showing 0 to 0 of 0 logs`;
      paginationBtnsContainer.innerHTML = "";
      return;
    }

    const startIndex = (currentPage - 1) * rowsPerPage;
    const endIndex = startIndex + rowsPerPage;
    const pageLogs = filteredLogs.slice(startIndex, endIndex);

    // Build Table Rows
    pageLogs.forEach((log) => {
      const tr = document.createElement("tr");

      // Color code actions
      let actionStyle = "";
      if (
        log.action.toLowerCase().includes("failed") ||
        log.action.toLowerCase().includes("error")
      ) {
        actionStyle = "color: #dc2626; font-weight: 500;"; // Red
      }

      // Highlighting Search Text dynamically
      let displayAction = log.action;
      if (searchInput.value.trim()) {
        const searchRegex = new RegExp(`(${searchInput.value.trim()})`, "gi");
        displayAction = log.action.replace(
          searchRegex,
          `<mark class="search-highlight">$1</mark>`,
        );
      }

      tr.innerHTML = `
                <td class="timestamp">${log.timestamp}</td>
                <td><span class="idTag">${log.userId !== "-" ? `User ID: ${log.userId}` : "System"}</span></td>
                <td><span class="badge">${log.category}</span></td>
                <td style="${actionStyle}">${displayAction}</td>
            `;
      tableBody.appendChild(tr);
    });

    // Update pagination text
    const startDisplay = startIndex + 1;
    const endDisplay = Math.min(endIndex, totalRows);
    paginationText.textContent = `Showing ${startDisplay} to ${endDisplay} of ${totalRows} logs`;

    renderPaginationControls(totalPages);
  }

  function renderPaginationControls(totalPages) {
    paginationBtnsContainer.innerHTML = "";

    const prevBtn = document.createElement("button");
    prevBtn.className = "btnPage";
    prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
    prevBtn.disabled = currentPage === 1;
    prevBtn.addEventListener("click", () => {
      if (currentPage > 1) {
        currentPage--;
        renderTable();
      }
    });
    paginationBtnsContainer.appendChild(prevBtn);

    for (let i = 1; i <= totalPages; i++) {
      // To prevent massive pagination UI, only show a few buttons
      if (
        i === 1 ||
        i === totalPages ||
        (i >= currentPage - 1 && i <= currentPage + 1)
      ) {
        const pageBtn = document.createElement("button");
        pageBtn.className = `btnPage ${i === currentPage ? "active" : ""}`;
        pageBtn.textContent = i;
        pageBtn.addEventListener("click", () => {
          currentPage = i;
          renderTable();
        });
        paginationBtnsContainer.appendChild(pageBtn);
      } else if (i === currentPage - 2 || i === currentPage + 2) {
        const dots = document.createElement("span");
        dots.textContent = "...";
        dots.style.padding = "0 8px";
        paginationBtnsContainer.appendChild(dots);
      }
    }

    const nextBtn = document.createElement("button");
    nextBtn.className = "btnPage";
    nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
    nextBtn.disabled = currentPage === totalPages || totalPages === 0;
    nextBtn.addEventListener("click", () => {
      if (currentPage < totalPages) {
        currentPage++;
        renderTable();
      }
    });
    paginationBtnsContainer.appendChild(nextBtn);
  }

  function showEmptyState(message) {
    const textColor = message.toLowerCase().includes("error")
      ? "#dc2626"
      : "#6b7280";
    tableBody.innerHTML = `
            <tr>
                <td colspan="4" style="text-align: center; color: ${textColor}; padding: 30px;">
                    ${message}
                </td>
            </tr>
        `;
  }

  // Event Listeners for Live Filtering
  searchInput.addEventListener("input", () => {
    currentPage = 1;
    renderTable();
  });
  filterSelect.addEventListener("change", () => {
    currentPage = 1;
    renderTable();
  });
  datePicker.addEventListener("change", () => {
    currentPage = 1;
    renderTable();
  });
});
