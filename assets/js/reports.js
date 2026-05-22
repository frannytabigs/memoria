// Open/Close Custom Dropdown Panel
function toggleDropdown() {
  document.getElementById("customDropdown").classList.toggle("open");
}

// Action when a master report type option row is clicked
function selectOption(element) {
  const chosenValue = element.getAttribute("data-value");

  const optionRows = document.querySelectorAll(".customOptionRow");
  optionRows.forEach((row) => row.classList.remove("active"));

  element.classList.add("active");

  const optionHTML = element.querySelector(".optionContent").innerHTML;
  document.getElementById("selectedOptionTarget").innerHTML = optionHTML;

  switchReport(chosenValue);
  document.getElementById("customDropdown").classList.remove("open");
}

// Show/Hide Report Page Cards Setup
function switchReport(reportId) {
  const reports = document.querySelectorAll(".reportCard");
  reports.forEach((report) => {
    report.classList.remove("active");
  });

  const activeReport = document.getElementById("report-" + reportId);
  if (activeReport) {
    activeReport.classList.add("active");

    // Hide all external dashboard filter toolbars first
    document.querySelectorAll(".filterEngineContainer").forEach((panel) => {
      panel.classList.remove("visible");
    });

    // Display target report container panel externally if valid matches exist
    const externalFilterPanel = document.getElementById(
      "filter-panel-" + reportId,
    );
    if (externalFilterPanel) {
      externalFilterPanel.classList.add("visible");
    }

    resetFilterEngine(reportId);
  }
}

// Dropdown Click-Out Close Handler rules
window.addEventListener("click", function (e) {
  const dropdownEl = document.getElementById("customDropdown");
  if (!dropdownEl.contains(e.target)) {
    dropdownEl.classList.remove("open");
  }
});

// ==================== LIVE DATA FILTER ENGINE OPERATIONS ====================
function applyFilterType(reportScope, filterType) {
  const filterPanel = document.getElementById("filter-panel-" + reportScope);

  // Toggle clicked active design state class on the selector buttons
  const pills = filterPanel.querySelectorAll(".filterPill");
  pills.forEach((pill) => pill.classList.remove("active"));
  filterPanel
    .querySelector(`.filterPill[data-filter="${filterType}"]`)
    .classList.add("active");

  // Hide all sub-parameter select lists initially
  const subSelectors = filterPanel.querySelectorAll(".secondaryParamSelector");
  subSelectors.forEach((sel) => sel.classList.remove("visible"));

  if (filterType === "none") {
    runDataFilterQuery(reportScope, "none", "all");
  } else {
    const targetedSelectBox = document.getElementById(
      `${reportScope}-sec-${filterType}`,
    );
    if (targetedSelectBox) {
      targetedSelectBox.classList.add("visible");
      const selectDropdown = targetedSelectBox.querySelector("select");
      selectDropdown.value = "all";
      runDataFilterQuery(reportScope, filterType, "all");
    }
  }
}

function runDataFilterQuery(reportScope, attributeType, filterTargetValue) {
  const reportCard = document.getElementById("report-" + reportScope);
  const records = reportCard.querySelectorAll(".bulletItem");
  let visibleItemCount = 0;

  records.forEach((item) => {
    if (filterTargetValue === "all" || attributeType === "none") {
      item.classList.remove("hiddenRow");
      visibleItemCount++;
    } else {
      const dataAttributeValue = item.getAttribute("data-" + attributeType);

      if (dataAttributeValue === filterTargetValue) {
        item.classList.remove("hiddenRow");
        visibleItemCount++;
      } else {
        item.classList.add("hiddenRow");
      }
    }
  });

  const fallbackMsg = document.getElementById(`${reportScope}-fallback`);
  if (fallbackMsg) {
    fallbackMsg.style.display = visibleItemCount === 0 ? "block" : "none";
  }
}

function resetFilterEngine(reportScope) {
  const filterPanel = document.getElementById("filter-panel-" + reportScope);
  if (!filterPanel) return;

  const firstPill = filterPanel.querySelector(".filterPill");
  if (firstPill) {
    applyFilterType(reportScope, "none");
  }
}

// Initialize state view rules upon load execution loop
document.addEventListener("DOMContentLoaded", function () {
  switchReport("interment");
});
