const API_ENDPOINT = "api/reports.php";
let currentReportScope = "interment";

// Open/Close Custom Dropdown Panel
function toggleDropdown() {
  document.getElementById("customDropdown").classList.toggle("open");
}

window.addEventListener("click", function (e) {
  const dropdownEl = document.getElementById("customDropdown");
  if (dropdownEl && !dropdownEl.contains(e.target)) {
    dropdownEl.classList.remove("open");
  }
});

// Select Option Logic
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

function switchReport(reportId) {
  currentReportScope = reportId;
  const reports = document.querySelectorAll(".reportCard");
  reports.forEach((report) => report.classList.remove("active"));

  const activeReport = document.getElementById("report-" + reportId);
  if (activeReport) {
    activeReport.classList.add("active");

    document.querySelectorAll(".filterEngineContainer").forEach((panel) => {
      panel.classList.remove("visible");
    });

    const externalFilterPanel = document.getElementById(
      "filter-panel-" + reportId,
    );
    if (externalFilterPanel) {
      externalFilterPanel.classList.add("visible");
    }

    resetFilterEngine(reportId);
  }
}

function resetFilterEngine(reportScope) {
  const filterPanel = document.getElementById("filter-panel-" + reportScope);
  if (!filterPanel) {
    loadReportData(reportScope, "none", "all");
    return;
  }
  const firstPill = filterPanel.querySelector(".filterPill");
  if (firstPill) {
    applyFilterType(reportScope, "none");
  }
}

function applyFilterType(reportScope, filterType) {
  const filterPanel = document.getElementById("filter-panel-" + reportScope);
  const pills = filterPanel.querySelectorAll(".filterPill");
  pills.forEach((pill) => pill.classList.remove("active"));

  const targetedPill = filterPanel.querySelector(
    `.filterPill[data-filter="${filterType}"]`,
  );
  if (targetedPill) targetedPill.classList.add("active");

  const subSelectors = filterPanel.querySelectorAll(".secondaryParamSelector");
  subSelectors.forEach((sel) => sel.classList.remove("visible"));

  if (filterType === "none") {
    loadReportData(reportScope, "none", "all");
  } else {
    const targetedSelectBox = document.getElementById(
      `${reportScope}-sec-${filterType}`,
    );
    if (targetedSelectBox) {
      targetedSelectBox.classList.add("visible");
      const selectDropdown = targetedSelectBox.querySelector("select");
      selectDropdown.value = "all";
      loadReportData(reportScope, filterType, "all");
    }
  }
}

function runDataFilterQuery(reportScope, attributeType, filterTargetValue) {
  loadReportData(reportScope, attributeType, filterTargetValue);
}

// ==================== FETCH API & RENDER ENGINE ====================

async function updateGenerationDate(
  reportScope,
  cemetery_name = "Mandaue City Public Cemetery",
) {
  // Load branding if cemetery_title is not in settingsMap
  if (!settingsMap["cemetery_title"]) {
    await loadReportBranding(); // Wait for it to complete
    cemetery_name = settingsMap["cemetery_title"] || cemetery_name;
  } else {
    cemetery_name = settingsMap["cemetery_title"];
  }

  const dateStr = new Date().toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });

  const meta = document.querySelector(`#report-${reportScope} .docMetaSummary`);
  if (meta) {
    meta.innerHTML = `${cemetery_name} &bull; Generated on: ${dateStr}`;
  }
}

async function loadReportData(reportScope, filterType, filterValue) {
  const container = document.getElementById(`${reportScope}-content`);
  if (!container) return;

  const fallbackId = `${reportScope}-fallback`;
  container.innerHTML =
    '<div style="text-align:center; padding: 40px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading report data...</div>';
  updateGenerationDate(reportScope);

  try {
    const res = await fetch(
      `${API_ENDPOINT}?report=${reportScope}&filter=${filterType}&value=${filterValue}`,
    );
    if (!res.ok) throw new Error("Failed Network Response");

    const json = await res.json();

    if (json.status === 200) {
      if (reportScope === "capacity") renderCapacity(json.data, container);
      else if (reportScope === "expirations")
        renderExpirations(json.data, container);
      else if (reportScope === "interment")
        renderInterment(json.data, container, fallbackId);
      else if (reportScope === "allgraves")
        renderAllGraves(json.data, container, fallbackId);
    } else {
      container.innerHTML = `<div id="${fallbackId}" class="noDataFallbackMessage" style="display:block;">${json.message || "No data available."}</div>`;
    }
  } catch (err) {
    console.error("Error fetching report data:", err);
    container.innerHTML = `<div id="${fallbackId}" class="noDataFallbackMessage" style="display:block;">Connection error. Please try again later.</div>`;
  }
}

function renderCapacity(data, container) {
  if (!data || !data.summary) return;
  const sum = data.summary;

  const tot = sum.total_graves || 0;
  const occPct = tot ? ((sum.occupied / tot) * 100).toFixed(1) : 0;
  const vacPct = tot ? ((sum.vacant / tot) * 100).toFixed(1) : 0;
  const resPct = tot ? ((sum.reserved / tot) * 100).toFixed(1) : 0;
  const pendPct = tot ? ((sum.pending_exhumation / tot) * 100).toFixed(1) : 0;

  let html = `
    <div class="docBlockHeader">Overall Cemetery Status</div>
    <table class="cleanTable">
      <thead>
        <tr>
          <th>Metric Indicator</th>
          <th>Count Value</th>
          <th>Percentage Allocation</th>
        </tr>
      </thead>
      <tbody>
        <tr><td><strong>Total Graves Registry Count</strong></td><td>${tot}</td><td>100%</td></tr>
        <tr><td><strong>Occupied Sites</strong></td><td>${sum.occupied || 0}</td><td>${occPct}%</td></tr>
        <tr><td><strong>Vacant Units Available</strong></td><td>${sum.vacant || 0}</td><td>${vacPct}%</td></tr>
        <tr><td><strong>Reserved Spaces</strong></td><td>${sum.reserved || 0}</td><td>${resPct}%</td></tr>
        <tr><td><strong>Pending Exhumation</strong></td><td>${sum.pending_exhumation || 0}</td><td>${pendPct}%</td></tr>
      </tbody>
    </table>

    <div class="docBlockHeader">Breakdown by Blocks</div>
    <table class="cleanTable">
      <thead>
        <tr>
          <th>Block</th>
          <th>Structural Profile Type</th>
          <th>Total Cap</th>
          <th>Occupied</th>
          <th>Vacant</th>
          <th>Reserved</th>
        </tr>
      </thead>
      <tbody>`;

  if (data.by_block && data.by_block.length > 0) {
    data.by_block.forEach((b) => {
      html += `<tr>
        <td><strong>${b.block_name || "N/A"}</strong></td>
        <td>${b.block_type || "N/A"}</td>
        <td>${b.total_graves || 0}</td>
        <td>${b.occupied || 0}</td>
        <td>${b.vacant || 0}</td>
        <td>${b.reserved || 0}</td>
      </tr>`;
    });
  } else {
    html += `<tr><td colspan="6" style="text-align:center;">No block data available</td></tr>`;
  }

  html += `</tbody></table>`;
  container.innerHTML = html;
}

function renderExpirations(data, container) {
  let html = "";
  if (data.expired && data.expired.length > 0) {
    html += `<div class="docBlockHeader" style="color: #dc3545; border-bottom-color: #f8d7da">ALREADY EXPIRED (Action Required)</div>`;
    data.expired.forEach((item) => {
      html += `<div class="bulletItem">
        <strong>Grave ${item.grave_code || "Unknown"}</strong>
        <div class="itemDetailBlock">
          <strong>Deceased:</strong> ${item.deceased_name || "N/A"} &nbsp;|&nbsp;
          <strong>Expired On:</strong> ${item.lease_expiration_date || "Unknown"}<br />
          <strong>Contact:</strong> ${item.contact_name || "Unknown"} (${item.phone_number || "N/A"})<br />
          <strong>Status Notes:</strong> <span style="color: #dc3545; font-weight: 600">${item.remarks || "No remarks provided"}</span>
        </div>
      </div>`;
    });
  }

  if (data.expiring && data.expiring.length > 0) {
    html += `<div class="docBlockHeader" style="color: #b45309; border-bottom-color: #fef3c7">EXPIRING SOON (Next 30 Days)</div>`;
    data.expiring.forEach((item) => {
      html += `<div class="bulletItem">
        <strong>Grave ${item.grave_code || "Unknown"}</strong>
        <div class="itemDetailBlock">
          <strong>Deceased:</strong> ${item.deceased_name || "N/A"} &nbsp;|&nbsp;
          <strong>Expires On:</strong> ${item.lease_expiration_date || "Unknown"}<br />
          <strong>Contact:</strong> ${item.contact_name || "Unknown"} (${item.phone_number || "N/A"})<br />
          <strong>Status Notes:</strong> <span style="color: #b45309; font-weight: 600">${item.remarks || "No remarks provided"}</span>
        </div>
      </div>`;
    });
  }

  if (!html)
    html = `<div class="noDataFallbackMessage" style="display:block;">No expired or expiring leases found.</div>`;
  container.innerHTML = html;
}

function renderInterment(data, container, fallbackId) {
  if (!data || !data.rows || data.rows.length === 0) {
    container.innerHTML = `<div id="${fallbackId}" class="noDataFallbackMessage" style="display:block;">No corresponding records found matching the configured filter setup parameters.</div>`;
    return;
  }

  const grouped = {};
  data.rows.forEach((r) => {
    let monthYear = "Unknown Date";
    const dateToUse = r.date_buried || r.date_of_death;
    if (dateToUse) {
      const d = new Date(dateToUse);
      if (!isNaN(d))
        monthYear = d.toLocaleDateString("en-US", {
          month: "long",
          year: "numeric",
        });
    }
    if (!grouped[monthYear]) grouped[monthYear] = [];
    grouped[monthYear].push(r);
  });

  let html = "";
  for (const [month, rows] of Object.entries(grouped)) {
    html += `<div class="docBlockHeader">${month}</div>`;
    rows.forEach((item) => {
      const remarkColor =
        item.family_status === "No Family"
          ? "color: #c01e2e"
          : "color: #64748b";
      const address =
        item.address && item.address !== "Unspecified"
          ? item.address
          : item.barangay || "Unspecified";

      html += `<div class="bulletItem">
        &bull; <strong>Name:</strong> ${item.name || "Unknown"} |
        <strong>DOD:</strong> ${item.date_of_death || "Unknown"} |
        <strong>Buried:</strong> ${item.date_buried || "Unknown"}
        <div class="itemDetailBlock">
          <strong>Location:</strong> ${item.location || "N/A"}<br />
          <strong>Contact Person:</strong> ${item.contact_person || "N/A"} <span style="${remarkColor}">(${item.contact_phone || item.family_status})</span>
          &nbsp;|&nbsp; <strong>Address:</strong> ${address} &nbsp;|&nbsp;
          <strong>Gender:</strong> ${item.gender || "Unknown"}
        </div>
      </div>`;
    });
  }
  container.innerHTML = html;
}

function renderAllGraves(data, container, fallbackId) {
  if (!data || !data.rows || data.rows.length === 0) {
    container.innerHTML = `<div id="${fallbackId}" class="noDataFallbackMessage" style="display:block;">No matching grave registry slots found.</div>`;
    return;
  }

  const colors = {
    Vacant: "#16a34a",
    Occupied: "#2563eb",
    Reserved: "#d97706",
    "Pending Exhumation": "#dc2626",
  };

  let html = `<div class="docBlockHeader">Grave Registry Grid Matrix</div>`;
  data.rows.forEach((item) => {
    const c = colors[item.display_status] || "#64748b";
    html += `<div class="bulletItem">
      <strong>Grave Code: ${item.grave_code || "N/A"}</strong>
      <div class="itemDetailBlock">
        &bull; <strong>Status Condition Profile:</strong>
        <span style="color: ${c}; font-weight: 600">${item.display_status || "Unknown"}</span><br />
        <strong>Remarks/Structural Notes:</strong> ${item.remarks || "None"}
        &nbsp;|&nbsp; <strong>Block Section:</strong> ${item.block_name || "N/A"} (${item.block_type || "N/A"})
      </div>
    </div>`;
  });
  container.innerHTML = html;
}

// ==================== SIGNATORIES ====================

function bindReportSignatories(settingsMap) {
  const fallbackNames = [
    { name: "", title: "" },
    { name: "", title: "" },
    { name: "", title: "" },
    { name: "", title: "" },
  ];

  const slots = document.querySelectorAll(".sigContainer");
  slots.forEach((slot, index) => {
    const sig = fallbackNames[index % 4];
    const cycleIndex = index % 4;

    const nameKey = `people_${cycleIndex + 1}_name`;
    const titleKey = `people_${cycleIndex + 1}_title`;

    const nextSig = {
      name: settingsMap[nameKey] || sig.name,
      title: settingsMap[titleKey] || sig.title,
    };

    const nameEl = slot.querySelector(".sigName");
    const titleEl = slot.querySelector(".sigTitle");

    if (nameEl) nameEl.textContent = nextSig.name;
    if (nextSig.name === "") nameEl.style.display = "none";
    if (titleEl) titleEl.textContent = nextSig.title;
  });
}
const settingsMap = {};

async function loadReportBranding() {
  try {
    const response = await fetch("api/settings.php", { cache: "no-store" });
    if (!response.ok) throw new Error("Settings failed to load");

    const result = await response.json();
    const settings = Array.isArray(result?.data) ? result.data : [];

    settings.forEach((setting) => {
      if (setting && setting.setting_key) {
        settingsMap[setting.setting_key] = String(
          setting.setting_value || "",
        ).trim();
      }
    });

    bindReportSignatories(settingsMap);
  } catch (error) {
    console.warn("Report branding unavailable, using empty fallbacks.");
    bindReportSignatories({});
  }
}

document.addEventListener("DOMContentLoaded", function () {
  loadReportBranding();
  switchReport("interment");
});
