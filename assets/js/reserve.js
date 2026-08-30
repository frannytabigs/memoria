/**
 * RESERVE — Reservation catalog + Burial Clearance intake
 * ---------------------------------------------------------------------------
 * GET  api/reserve        -> catalog: vacant / expired / expiring graves
 * POST api/reserve        -> stages a NEW "Pending" interment on a grave
 *
 * STRICT BOUNDARY: This module is for INTAKE only.
 * Edits, finalizations, and cancellations happen in Monitor.html.
 */
document.addEventListener("DOMContentLoaded", () => {
  // =========================================================
  // ELEMENTS
  // =========================================================
  const tableBody = document.getElementById("reservation");
  const burialModal = document.getElementById("burialModalOverlay");
  const burialForm = document.getElementById("burialClearanceForm");
  const btnCancel = document.getElementById("btnCancel");
  const btnSave = document.getElementById("btnSave");

  const field = (id) => document.getElementById(id);

  const controlNoInput = field("controlNo");
  const clearanceDateInput = field("clearanceDate");
  const dateIntermentInput = field("dateInterment");
  const expirationDateInput = field("expirationDate");
  const graveCodeInput = field("graveCode");
  const burialTypeSelect = field("burialBlock");
  const assistanceSelect = field("reqAssistance");

  const COLSPAN = 7;
  const LEASE_YEARS = 5;
  const CATALOG_LIMIT = 50;

  // reserve.php only lets Admin / Office Staff POST.
  const canModify = ["Administrator", "Office Staff"].includes(
    localStorage.getItem("memoria_role"),
  );

  // =========================================================
  // STATE & CACHE
  // =========================================================
  let catalogRows = [];
  let pagination = null;
  let breakdown = null;

  const graveIndex = new Map();
  const takenControlNumbers = new Set();

  let activeRow = null;
  let expirationTouched = false;
  let isSaving = false;

  // Search & Pagination State
  let currentPage = 1;
  let searchQuery = "";
  let searchTimeout = null;
  let defaultDataCache = {}; // Caches standard pages so clearing search is instantaneous

  // =========================================================
  // SMALL UTILITIES
  // =========================================================
  const escapeHTML = (str) => {
    if (str === null || str === undefined) return "";
    return String(str).replace(
      /[&<>"']/g,
      (m) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[m],
    );
  };

  const text = (value) =>
    value === null || value === undefined ? "" : String(value).trim();
  const orNull = (value) => (text(value) === "" ? null : text(value));
  const dash = (value) =>
    text(value) === "" ? "&mdash;" : escapeHTML(text(value));
  const normalizeCode = (value) => text(value).toUpperCase();
  const toInputDate = (value) => (value ? String(value).slice(0, 10) : "");

  const formatDate = (value) => {
    if (!value) return "&mdash;";
    const parsed = new Date(`${toInputDate(value)}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return escapeHTML(value);
    return parsed.toLocaleDateString("en-US", {
      month: "long",
      day: "2-digit",
      year: "numeric",
    });
  };

  const addYears = (value, years) => {
    if (!value) return "";
    const parsed = new Date(`${toInputDate(value)}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return "";
    parsed.setFullYear(parsed.getFullYear() + years);
    const month = String(parsed.getMonth() + 1).padStart(2, "0");
    const day = String(parsed.getDate()).padStart(2, "0");
    return `${parsed.getFullYear()}-${month}-${day}`;
  };

  const todayValue = () => {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const day = String(now.getDate()).padStart(2, "0");
    return `${now.getFullYear()}-${month}-${day}`;
  };

  const notify = (message, type = "info") => {
    if (typeof showAlertTOP === "function") showAlertTOP(message, type);
    else console.log(`[${type}] ${message}`);
  };

  const shake = (ids) => {
    if (typeof animateInputsOnError === "function") animateInputsOnError(ids);
  };

  const ASSISTANCE_LABELS = {
    Burial: "Burial of the late...",
    Transfer: "Transfer the remains of the late...",
    Other: "Other...",
  };

  const toAssistanceEnum = (label) => {
    const value = text(label).toLowerCase();
    if (value.startsWith("burial")) return "Burial";
    if (value.startsWith("transfer")) return "Transfer";
    return "Other";
  };

  const normalizeBlockType = (value) =>
    text(value).toLowerCase().replace(/[\s/]/g, "");

  const blockTypeMatches = (formValue, dbValue) => {
    const a = normalizeBlockType(formValue);
    const b = normalizeBlockType(dbValue);
    if (!a || !b) return true;
    return a.startsWith(b) || b.startsWith(a);
  };

  const ensureOption = (select, value) => {
    if (!select || text(value) === "") return;
    const exists = Array.from(select.options).some(
      (option) => option.value === value,
    );
    if (exists) return;
    const option = document.createElement("option");
    option.value = value;
    option.textContent = value;
    select.appendChild(option);
  };

  const setSelectValue = (select, value) => {
    if (!select) return;
    ensureOption(select, text(value));
    select.value = text(value);
  };

  /**
   * blocks.block_type is a DB enum ('Niche', 'Lawn/Grounds', …) while the form
   * offers friendlier labels ('Niche Wall', 'Lawn / Grounds'). Match an existing
   * option through the same fuzzy comparison the datalist filter uses instead of
   * appending the raw enum, which slowly filled the dropdown with near-duplicates.
   */
  const selectBlockType = (select, dbValue) => {
    if (!select || text(dbValue) === "") return;
    const match = Array.from(select.options).find((option) =>
      blockTypeMatches(option.value || option.textContent, dbValue),
    );
    if (match) select.value = match.value;
    else setSelectValue(select, dbValue);
  };

  // =========================================================
  // INJECTED STYLES & DOM
  // =========================================================
  (function injectStyles() {
    if (document.getElementById("reserveInjectedStyles")) return;
    const style = document.createElement("style");
    style.id = "reserveInjectedStyles";
    style.textContent = `
      /* UI/UX Fixed Table Layout */
      .tableContainer table { table-layout: fixed; width: 100%; }
      .tableContainer td, .tableContainer th { overflow: hidden; text-overflow: ellipsis; }

      /* Unified Top Control Bar */
      .reserveTopBar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
      
      /* Search Box */
      .searchBox { position: relative; width: 100%; max-width: 340px; display: flex; align-items: center; }
      .searchBox .searchIcon { position: absolute; left: 14px; color: #94a3b8; font-size: 14px; pointer-events: none; transition: color 0.2s; }
      .searchBox input { width: 100%; padding: 10px 38px 10px 40px; border: 1px solid #e2e8f0; border-radius: 99px; font-size: 14px; color: #1e293b; background: #f8fafc; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); box-sizing: border-box; outline: none; }
      .searchBox input::placeholder { color: #94a3b8; }
      .searchBox input:focus { border-color: #3b82f6; background: #ffffff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
      .searchBox input:focus + .searchIcon { color: #3b82f6; }
      .searchBox .clearBtn { position: absolute; right: 12px; background: transparent; border: none; color: #94a3b8; cursor: pointer; padding: 4px; border-radius: 50%; display: none; align-items: center; justify-content: center; transition: all 0.2s; font-size: 12px; width: 22px; height: 22px; }
      .searchBox .clearBtn:hover { color: #1e293b; background: #e2e8f0; }
      .searchBox.hasText .clearBtn { display: flex; }

      /* Pagination inside Top Bar */
      .reservePagination { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
      .reserveSummary { font-size: 13px; color: #64748b; font-weight: 600; }
      .reservePages { display: flex; gap: 6px; flex-wrap: wrap; }
      .reservePages button { min-width: 34px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; background: #ffffff; color: #475569; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
      .reservePages button:hover:not(:disabled):not(.active) { background: #f1f5f9; }
      .reservePages button.active { background: #1e3a8a; border-color: #1e3a8a; color: #ffffff; cursor: default; }
      .reservePages button:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }

      /* Table Badges & Tags */
      .rowTag { display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; vertical-align: middle; }
      .rowTag.expired  { background: #fee2e2; color: #b91c1c; }
      .rowTag.expiring { background: #fef3c7; color: #b45309; }
      .rowTag.vacant   { background: #dcfce7; color: #15803d; }
      .graveCode { display: inline-block; margin-left: 6px; padding: 2px 6px; border-radius: 4px; background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 600; }
      .dayHint { display: block; font-size: 11px; font-weight: 600; margin-top: 2px; }
      .dayHint.overdue { color: #dc2626; }
      .dayHint.soon { color: #b45309; }
      
      tbody tr.clickableRow { cursor: pointer; }
      tbody tr.clickableRow:hover { background: #f8fafc; }
      .actions button:disabled { opacity: 0.4; cursor: not-allowed; }
      
      /* Loaders & Errors */
      .skeletonBox { height: 12px; border-radius: 6px; background: linear-gradient(90deg, #eef2f7 25%, #e2e8f0 37%, #eef2f7 63%); background-size: 400% 100%; animation: reserveShimmer 1.4s ease infinite; }
      @keyframes reserveShimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
      .stateCell { text-align: center !important; padding: 46px 20px !important; color: #94a3b8; font-size: 14px; font-weight: 600; white-space: normal !important; }
      .stateCell.isError { color: #dc2626; }
      
      /* Modal elements */
      .occupantNote { margin: -6px 0 14px 0; padding: 10px 12px; border-left: 3px solid #f59e0b; background: #fffbeb; color: #92400e; font-size: 12px; font-weight: 600; border-radius: 4px; }
      .fieldHint { font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 4px; }
      .paperForm.isBusy { opacity: 0.65; pointer-events: none; }
      .paperForm input[readonly] { background: #f8fafc; color: #64748b; cursor: not-allowed; }
    `;
    document.head.appendChild(style);
  })();

  (function injectTopControls() {
    const container = document.querySelector(".tableContainer");
    if (!container || document.getElementById("reserveTopBar")) return;

    const topBar = document.createElement("div");
    topBar.className = "reserveTopBar";
    topBar.id = "reserveTopBar";
    topBar.innerHTML = `
      <div class="searchBox" id="reserveSearchBox">
        <input type="text" id="reserveSearchInput" placeholder="Search code, block, or name..." autocomplete="off" />
        <i class="fas fa-search searchIcon"></i>
        <button class="clearBtn" id="reserveSearchClear" title="Clear search"><i class="fas fa-times"></i></button>
      </div>
      <div class="reservePagination" id="reservePagination">
        <span class="reserveSummary" id="reserveSummary"></span>
        <span class="reservePages" id="reservePages"></span>
      </div>
    `;

    // Injects the unified control bar right above the main table container
    container.insertAdjacentElement("beforebegin", topBar);
  })();

  // NOTE: there is deliberately no SMS modal built here. assets/js/sendsms.js
  // owns the composer and the per-situation templates; this page only tags its
  // phone buttons with data-* and lets one delegated binding open it.

  const graveListId = "reserveGraveOptions";
  const vacantListId = "reserveVacantOptions";

  (function injectDatalists() {
    [graveListId, vacantListId].forEach((id) => {
      if (document.getElementById(id)) return;
      const list = document.createElement("datalist");
      list.id = id;
      document.body.appendChild(list);
    });
    if (graveCodeInput) graveCodeInput.setAttribute("list", graveListId);
  })();

  (function injectOccupantSection() {
    if (!burialForm || document.getElementById("occupantWrap")) return;
    const wrap = document.createElement("div");
    wrap.id = "occupantWrap";
    wrap.style.display = "none";
    wrap.innerHTML = `
      <div class="sectionTitle">OLD OCCUPANT TRANSFER</div>
      <div class="occupantNote" id="occupantNote"></div>
      <div class="row">
        <div class="col">
          <label>Where do the current remains go?</label>
          <select id="occupantType">
            <option value="common_bone_chamber">Common Bone Chamber</option>
            <option value="specific_grave">Transfer to a specific grave</option>
            <option value="family_custody">Released to family custody</option>
            <option value="other">Other (state below)</option>
          </select>
        </div>
        <div class="col" id="occupantGraveCol">
          <label>Destination Grave Code:</label>
          <input type="text" id="occupantGrave" list="${vacantListId}" placeholder="Vacant grave code" />
          <div class="fieldHint">Must be a different, vacant grave.</div>
        </div>
      </div>
      <div class="row">
        <div class="col">
          <label>Transfer Notes:</label>
          <input type="text" id="occupantNotes" placeholder="Required when choosing Other" />
        </div>
      </div>
    `;
    const remarksTitle = Array.from(
      burialForm.querySelectorAll(".sectionTitle"),
    ).find((node) => node.textContent.trim().toUpperCase() === "REMARKS");
    if (remarksTitle) remarksTitle.insertAdjacentElement("beforebegin", wrap);
    else burialForm.querySelector(".paperFormActions")?.before(wrap);
  })();

  const occupantWrap = field("occupantWrap");
  const occupantNote = field("occupantNote");
  const occupantType = field("occupantType");
  const occupantGraveCol = field("occupantGraveCol");
  const occupantGrave = field("occupantGrave");
  const occupantNotes = field("occupantNotes");

  [controlNoInput, expirationDateInput].forEach((input) => {
    if (!input) return;
    input.removeAttribute("readonly");
    input.style.backgroundColor = "#ffffff";
  });

  // =========================================================
  // DATA LOADING & CACHING
  // =========================================================
  const readJSON = async (response) => {
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.includes("application/json"))
      throw new Error("STATIC_SERVER");
    return response.json();
  };

  const fetchCatalog = async () => {
    // 1. If not searching and we already have this page in cache, load it instantly!
    if (!searchQuery && defaultDataCache[currentPage]) {
      return { fromCache: true, data: defaultDataCache[currentPage] };
    }

    // 2. Otherwise construct query and fetch
    const query = new URLSearchParams({
      page: currentPage,
      limit: CATALOG_LIMIT,
    });
    if (searchQuery) query.append("search", searchQuery);

    const response = await fetch(`api/reserve?${query.toString()}`);
    const result = await readJSON(response);

    if (!result.success) throw new Error(result.message || "Catalog failed");

    const data = result.data || {};

    // 3. Store standard pages in cache for instantaneous return later
    if (!searchQuery) {
      defaultDataCache[currentPage] = data;
    }

    return { fromCache: false, data: data };
  };

  // =========================================================
  // ROW MODEL
  // =========================================================
  const rememberGrave = (entry) => {
    const key = normalizeCode(entry.grave_code);
    if (!key) return;
    graveIndex.set(key, { ...graveIndex.get(key), ...entry });
  };

  const fromInterment = (item, kind) => {
    const grave = item.grave || {};
    const block = item.block || {};
    const deceased = item.deceased || {};
    const contact = item.contact || {};

    if (grave.grave_code) {
      rememberGrave({
        grave_id: grave.grave_id,
        grave_code: grave.grave_code,
        block_name: block.block_name,
        block_type: block.block_type,
        // Deliberately NOT grave.grave_status: this row exists because a live
        // interment sits in that grave, and graves.status has drifted badly in
        // the seed data. A body we can see beats a label we cannot trust, and
        // it keeps an occupied grave out of the "vacant destination" datalist.
        status: "Occupied",
      });
    }
    if (item.control_number) takenControlNumbers.add(item.control_number);

    return {
      kind,
      interment_id: item.interment_id || null,
      grave_id: grave.grave_id || null,
      grave_code: grave.grave_code || "",
      block_name: block.block_name || "",
      block_type: block.block_type || "",
      control_number: item.control_number || "",
      assistance_type: item.assistance_type || "Burial",
      clearance_date: item.clearance_date || "",
      date_buried: item.date_buried || "",
      lease_expiration_date: item.lease_expiration_date || "",
      burial_permit_number: item.burial_permit_number || "",
      exhumation_permit_number: item.exhumation_permit_number || "",
      transfer_permit_number: item.transfer_permit_number || "",
      days_remaining: item.days_remaining || 0,
      days_overdue: item.days_overdue || 0,
      deceased: {
        deceased_id: deceased.deceased_id || null,
        name: deceased.name || "",
        sex: deceased.sex || "",
        date_of_birth: deceased.date_of_birth || "",
        date_of_death: deceased.date_of_death || "",
        death_certificate: deceased.death_certificate || "",
        last_known_address: deceased.last_known_address || "",
      },
      contact: {
        contact_id: contact.contact_id || null,
        name: contact.name || "",
        phone_number: contact.phone_number || "",
        address: contact.address || "",
        barangay: contact.barangay || "",
      },
      note: text(item.remarks),
    };
  };

  const fromVacantGrave = (grave) => {
    rememberGrave({
      grave_id: grave.grave_id,
      grave_code: grave.grave_code,
      block_name: grave.block_name,
      block_type: grave.block_type,
      status: "Vacant",
    });

    return {
      kind: "vacant",
      interment_id: null,
      grave_id: grave.grave_id || null,
      grave_code: grave.grave_code || "",
      block_name: grave.block_name || "",
      block_type: grave.block_type || "",
      control_number: "",
      assistance_type: "Burial",
      clearance_date: "",
      date_buried: "",
      lease_expiration_date: "",
      burial_permit_number: "",
      exhumation_permit_number: "",
      transfer_permit_number: "",
      days_remaining: 0,
      days_overdue: 0,
      deceased: {
        deceased_id: null,
        name: "",
        sex: "",
        date_of_birth: "",
        date_of_death: "",
        death_certificate: "",
        last_known_address: "",
      },
      contact: {
        contact_id: null,
        name: "",
        phone_number: "",
        address: "",
        barangay: "",
      },
      note: "",
    };
  };

  /** api/reserve's unified `catalog` array mixes both shapes; dispatch on the tag. */
  const fromCatalogEntry = (item) =>
    item.availability === "vacant"
      ? fromVacantGrave(item)
      : fromInterment(
          item,
          item.availability === "expired" ? "expired" : "expiring",
        );

  // =========================================================
  // TABLE RENDERING
  // =========================================================
  const TAGS = {
    expired: "Lease expired",
    expiring: "Expiring soon",
    vacant: "Vacant",
  };

  const renderSkeleton = () => {
    tableBody.innerHTML = Array(5)
      .fill(0)
      .map(
        () => `
        <tr style="pointer-events:none;">
          ${Array(COLSPAN)
            .fill(0)
            .map(
              () =>
                `<td><div class="skeletonBox" style="width:${Math.floor(Math.random() * 40 + 50)}%;"></div></td>`,
            )
            .join("")}
        </tr>`,
      )
      .join("");
  };

  const renderState = (message, isError = false) => {
    tableBody.innerHTML = `<tr><td colspan="${COLSPAN}" class="stateCell ${isError ? "isError" : ""}">${escapeHTML(message)}</td></tr>`;
  };

  const renderTable = () => {
    if (!catalogRows.length) {
      renderState(
        searchQuery
          ? `No graves or records matched "${searchQuery}".`
          : "Nothing to reserve right now — no vacant, expiring or expired graves on this page.",
      );
      return;
    }

    // A vacant grave has no family to notify. Of the two lease states, only the
    // wording differs, so the row picks the template and sendsms.js writes it.
    const SMS_TEMPLATE = {
      expiring: "lease_expiring",
      expired: "lease_expired",
    };

    const smsCell = (row) => {
      const phone = text(row.contact.phone_number);
      if (!phone) return "&mdash;";

      const template = SMS_TEMPLATE[row.kind];
      if (!template) return dash(phone);

      const data = {
        contact_name: text(row.contact.name),
        deceased_name: text(row.deceased.name),
        grave_code: text(row.grave_code),
        block_name: text(row.block_name),
        lease_expiration_date: text(row.lease_expiration_date),
        date_buried: text(row.date_buried),
        control_number: text(row.control_number),
      };

      return `<button type="button" class="smsBtn" data-sms
        data-phone="${escapeHTML(phone)}"
        data-sms-template="${escapeHTML(template)}"
        data-sms-data="${escapeHTML(JSON.stringify(data))}"
        onclick="makeSmsHandler('${escapeHTML(phone)}','Hello ${data.contact_name}, this is an official notice. The lease for ${data.deceased_name} expires on ${data.lease_expiration_date}')"
        ${canModify ? `title="Notify ${escapeHTML(data.contact_name || "this contact")} about the lease"` : 'disabled title="Your role cannot send notifications"'}
      ><i class="fas fa-comment-sms"></i> <span>${escapeHTML(phone)}</span></button>`;
    };

    tableBody.innerHTML = catalogRows
      .map((row, index) => {
        const lockedAttrs = canModify ? "" : ' disabled title="View only"';

        let expirationCell = formatDate(row.lease_expiration_date);
        if (row.kind === "expired" && row.days_overdue > 0) {
          expirationCell += `<span class="dayHint overdue">${row.days_overdue} day(s) overdue</span>`;
        } else if (row.kind === "expiring" && row.days_remaining >= 0) {
          expirationCell += `<span class="dayHint soon">${row.days_remaining} day(s) left</span>`;
        }

        return `
        <tr data-index="${index}" class="${canModify ? "clickableRow" : ""}">
          <td>
            ${dash(row.deceased.name)}
            <span class="rowTag ${row.kind}">${TAGS[row.kind]}</span>
          </td>
          <td>
            ${dash(row.block_name)}
            ${row.grave_code ? `<span class="graveCode">${escapeHTML(row.grave_code)}</span>` : ""}
          </td>
          <td>${expirationCell}</td>
          <td>${dash(row.contact.name)}</td>
          <td>${smsCell(row)}</td>
          <td>${dash(row.note)}</td>
          <td class="actions">
            <button class="addBtn"${lockedAttrs} title="Create Burial Clearance (Intake)">
              <i class="fas fa-plus"></i>
            </button>
          </td>
        </tr>`;
      })
      .join("");
  };

  const renderPagination = () => {
    const summary = field("reserveSummary");
    const pages = field("reservePages");
    if (!summary || !pages) return;

    if (breakdown && !searchQuery) {
      summary.textContent = `${breakdown.vacant_count} vacant · ${breakdown.expired_count} expired · ${breakdown.expiring_count} expiring soon`;
    } else if (pagination) {
      const total = pagination.total_records ?? 0;
      summary.textContent = `Page ${currentPage} of ${Math.max(1, pagination.total_pages)} · ${total} match${total === 1 ? "" : "es"}`;
    } else {
      summary.textContent = "";
    }

    const totalPages = Math.max(1, pagination?.total_pages || 1);
    pages.innerHTML = "";
    if (totalPages <= 1) return;

    const addButton = (label, page, options = {}) => {
      const button = document.createElement("button");
      button.innerHTML = label;

      if (options.active) button.classList.add("active");
      if (options.disabled) button.disabled = true;
      else {
        button.addEventListener("click", (e) => {
          if (button.classList.contains("active")) return;

          // UI UX UPDATE: Apply visual feedback instantly upon click!
          document
            .querySelectorAll(".reservePages button")
            .forEach((b) => b.classList.remove("active"));
          e.currentTarget.classList.add("active");

          currentPage = page;
          loadAll();
        });
      }
      pages.appendChild(button);
    };

    addButton('<i class="fas fa-chevron-left"></i>', currentPage - 1, {
      disabled: currentPage <= 1,
    });

    for (let page = 1; page <= totalPages; page++) {
      const nearby = Math.abs(page - currentPage) <= 1;
      if (page === 1 || page === totalPages || nearby) {
        addButton(String(page), page, { active: page === currentPage });
      } else if (page === currentPage - 2 || page === currentPage + 2) {
        const dots = document.createElement("span");
        dots.textContent = "…";
        dots.style.padding = "6px 4px";
        dots.style.color = "#94a3b8";
        pages.appendChild(dots);
      }
    }

    addButton('<i class="fas fa-chevron-right"></i>', currentPage + 1, {
      disabled: currentPage >= totalPages,
    });
  };

  const refreshGraveOptions = () => {
    const graveList = document.getElementById(graveListId);
    const vacantList = document.getElementById(vacantListId);
    if (!graveList || !vacantList) return;

    const selectedType = burialTypeSelect ? burialTypeSelect.value : "";
    const entries = Array.from(graveIndex.values());

    const option = (entry) =>
      `<option value="${escapeHTML(entry.grave_code)}">${escapeHTML([entry.block_name, entry.status].filter(Boolean).join(" · "))}</option>`;

    graveList.innerHTML = entries
      .filter((entry) => entry.status !== "Staged")
      .filter((entry) => blockTypeMatches(selectedType, entry.block_type))
      .map(option)
      .join("");

    vacantList.innerHTML = entries
      .filter((entry) => entry.status === "Vacant")
      .map(option)
      .join("");
  };

  // =========================================================
  // LOAD
  // =========================================================
  const loadAll = async () => {
    renderSkeleton();
    const fetchStartTime = Date.now();

    try {
      const result = await fetchCatalog();
      const catalog = result.data;

      // Intelligent Delay: Only show the loader if we actually hit the network.
      // If the data came instantly from our cache, we skip the artificial delay
      // so it snaps onto the screen instantly.
      if (!result.fromCache) {
        const elapsed = Date.now() - fetchStartTime;
        const minimumLoaderTime = 800; // professional delay length
        if (elapsed < minimumLoaderTime) {
          await new Promise((resolve) =>
            setTimeout(resolve, minimumLoaderTime - elapsed),
          );
        }
      }

      pagination = catalog.pagination || null;
      breakdown = pagination?.breakdown || null;

      // The API clamps `page` to the real last page. Without adopting the value
      // back, a search that shrinks the result set left the footer claiming
      // "page 4 of 1" while the rows on screen were page 1's.
      if (pagination?.current_page) currentPage = pagination.current_page;

      // One list, already interleaved by the union in the order we render:
      // vacant, then overdue leases, then the ones about to lapse. Rebuilding
      // from the three legacy arrays re-sorted the page and broke the mapping
      // between a row's index and its position on the server's page.
      const entries = Array.isArray(catalog.catalog)
        ? catalog.catalog
        : [
            ...(catalog.vacant || []).map((g) => ({
              ...g,
              availability: "vacant",
            })),
            ...(catalog.expired || []).map((i) => ({
              ...i,
              availability: "expired",
            })),
            ...(catalog.expiring || []).map((i) => ({
              ...i,
              availability: "expiring",
            })),
          ];

      catalogRows = entries.map(fromCatalogEntry);

      renderTable();
      renderPagination();
      refreshGraveOptions();
    } catch (error) {
      console.error("Failed to load the reservation catalog:", error);
      catalogRows = [];
      pagination = null;
      breakdown = null;
      if (error.message === "STATIC_SERVER") {
        renderState(
          "The backend is not responding. Run the site through Laragon / Apache so api/reserve.php can load.",
          true,
        );
      } else {
        renderState(
          error.message || "Error loading the catalog. Please try again later.",
          true,
        );
      }
      renderPagination();
    }
  };

  // =========================================================
  // SEARCH BINDINGS
  // =========================================================
  const searchInputEl = document.getElementById("reserveSearchInput");
  const searchClearBtn = document.getElementById("reserveSearchClear");
  const searchWrapEl = document.getElementById("reserveSearchBox");

  if (searchInputEl) {
    searchInputEl.addEventListener("input", (e) => {
      const val = e.target.value;

      // Toggle the appearance of the "X" clear button
      if (searchWrapEl)
        searchWrapEl.classList.toggle("hasText", val.length > 0);

      // Debounce logic: don't bombard the server on every keystroke
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        searchQuery = val.trim();
        currentPage = 1;
        loadAll();
      }, 400);
    });
  }

  if (searchClearBtn) {
    searchClearBtn.addEventListener("click", () => {
      searchInputEl.value = "";
      if (searchWrapEl) searchWrapEl.classList.remove("hasText");

      searchQuery = "";
      currentPage = 1;

      // Because we cached it, loadAll will bypass the API and render instantly
      loadAll();
      searchInputEl.focus(); // Good UX: Return focus after clearing
    });
  }

  // =========================================================
  // MODAL
  // =========================================================
  const setFormBusy = (busy) => {
    const paper = burialForm?.querySelector(".paperForm");
    if (paper) paper.classList.toggle("isBusy", busy);
    if (btnSave) btnSave.disabled = busy;
  };

  const toggleOccupantSection = (show, occupantName = "") => {
    if (!occupantWrap) return;
    occupantWrap.style.display = show ? "block" : "none";
    if (show && occupantNote) {
      occupantNote.textContent = occupantName
        ? `This grave still holds ${occupantName}. Record where those remains will go before staging the new interment.`
        : "This grave is still occupied. Record where the current remains will go before staging the new interment.";
    }
  };

  // `visibility: hidden` left a dead gap in the row; collapse the column instead.
  const toggleOccupantGrave = () => {
    if (!occupantGraveCol || !occupantType) return;
    occupantGraveCol.style.display =
      occupantType.value === "specific_grave" ? "" : "none";
  };

  const generateControlNumber = () => {
    for (let attempt = 0; attempt < 50; attempt++) {
      const first = Math.floor(1000 + Math.random() * 9000);
      const second = Math.floor(1000 + Math.random() * 9000);
      const candidate = `${first}-${second}`;
      if (!takenControlNumbers.has(candidate)) return candidate;
    }
    return `${Date.now()}`.slice(-9);
  };

  const closeModal = () => {
    if (isSaving) return;
    burialModal.style.display = "none";
    document.body.style.overflow = "auto";
    burialForm.reset();
    setFormBusy(false);
    toggleOccupantSection(false);
    if (graveCodeInput) {
      graveCodeInput.removeAttribute("readonly");
      graveCodeInput.removeAttribute("title");
    }
    activeRow = null;
    expirationTouched = false;
  };

  /** Fresh intake on a catalog grave -> POST api/reserve */
  const openCreate = (row) => {
    if (!canModify) {
      notify(
        "Your role can view the catalog but not process reservations.",
        "warning",
      );
      return;
    }

    burialForm.reset();
    setFormBusy(false);
    expirationTouched = false;
    activeRow = row;

    controlNoInput.value = generateControlNumber();
    clearanceDateInput.value = todayValue();

    if (graveCodeInput) graveCodeInput.value = row.grave_code || "";
    selectBlockType(burialTypeSelect, row.block_type);
    setSelectValue(assistanceSelect, ASSISTANCE_LABELS.Burial);

    // Expired / expiring rows sit on a grave that still holds someone.
    toggleOccupantSection(row.kind !== "vacant", row.deceased.name);
    toggleOccupantGrave();
    refreshGraveOptions();

    burialModal.style.display = "block";
    document.body.style.overflow = "hidden";
  };

  // =========================================================
  // GRAVE RESOLUTION
  // =========================================================
  const lookupGrave = async (code) => {
    const key = normalizeCode(code);
    if (!key) return null;
    if (graveIndex.has(key)) return graveIndex.get(key);

    try {
      const response = await fetch(
        `api/graves?search=${encodeURIComponent(text(code))}`,
      );
      const result = await readJSON(response);
      if (!result.success) return null;

      const match = (result.data?.graves || []).find(
        (grave) => normalizeCode(grave.grave_code) === key,
      );
      if (!match) return null;

      const entry = {
        grave_id: match.grave_id,
        grave_code: match.grave_code,
        block_name: match.block_name,
        block_type: match.block_type || "",
        status: match.status,
      };
      rememberGrave(entry);
      return entry;
    } catch (error) {
      console.error("Grave lookup failed:", error);
      return null;
    }
  };

  // =========================================================
  // SAVE (POST ONLY)
  // =========================================================
  const buildDestination = async (targetGraveId) => {
    const type = occupantType.value;
    const destination = { type, notes: text(occupantNotes.value) };

    if (type === "other" && destination.notes === "") {
      notify("Transfer notes are required when choosing 'Other'.", "error");
      shake(["occupantNotes"]);
      return null;
    }

    if (type === "specific_grave") {
      const code = text(occupantGrave.value);
      if (!code) {
        notify("Enter the destination grave code for the transfer.", "error");
        shake(["occupantGrave"]);
        return null;
      }

      const entry = await lookupGrave(code);
      if (!entry) {
        notify(`Grave "${code}" was not found.`, "error");
        shake(["occupantGrave"]);
        return null;
      }
      if (entry.grave_id === targetGraveId) {
        notify("The destination must be a different grave.", "error");
        shake(["occupantGrave"]);
        return null;
      }
      if (entry.status !== "Vacant") {
        notify(
          `Grave ${entry.grave_code} is ${entry.status}, not vacant.`,
          "error",
        );
        shake(["occupantGrave"]);
        return null;
      }
      destination.grave_id = entry.grave_id;
    }
    return destination;
  };

  const collectShared = () => ({
    assistance_type: toAssistanceEnum(assistanceSelect.value),
    clearance_date: orNull(clearanceDateInput.value),
    date_buried: orNull(dateIntermentInput.value),
    lease_expiration_date: orNull(expirationDateInput.value),
    remarks: text(field("deceasedRemarks").value),
  });

  const save = async () => {
    if (isSaving || !activeRow) return;

    if (!canModify) {
      notify(
        "Only Office Staff and Administrators can process reservations.",
        "error",
      );
      return;
    }

    if (!burialForm.checkValidity()) {
      burialForm.reportValidity();
      return;
    }

    const controlNumber = text(controlNoInput.value);
    if (!controlNumber) {
      notify("A control number is required.", "error");
      shake(["controlNo"]);
      return;
    }

    const typedCode = text(graveCodeInput.value);
    let entry = null;

    if (
      typedCode &&
      normalizeCode(typedCode) !== normalizeCode(activeRow.grave_code)
    ) {
      entry = await lookupGrave(typedCode);
      if (!entry) {
        notify(`Grave "${typedCode}" was not found.`, "error");
        shake(["graveCode"]);
        return;
      }
    } else {
      entry = graveIndex.get(normalizeCode(activeRow.grave_code)) || {
        grave_id: activeRow.grave_id,
        grave_code: activeRow.grave_code,
        status: activeRow.kind === "vacant" ? "Vacant" : "Occupied",
      };
    }

    if (!entry.grave_id) {
      notify("Pick a grave before saving this clearance.", "error");
      shake(["graveCode"]);
      return;
    }
    if (["Reserved", "Pending Exhumation", "Staged"].includes(entry.status)) {
      notify(
        `Grave ${entry.grave_code} already has an active staging process. Go to the Monitor module.`,
        "error",
      );
      shake(["graveCode"]);
      return;
    }

    let payload = {
      grave_id: entry.grave_id,
      control_number: controlNumber,
      ...collectShared(),
      burial_permit_number: orNull(field("permitBurial").value),
      exhumation_permit_number: orNull(field("permitExhumation").value),
      transfer_permit_number: orNull(field("permitTransfer").value),
      deceased: {
        name: text(field("deceasedName").value),
        sex: text(field("deceasedSex").value) || "Unknown",
        date_of_birth: orNull(field("deceasedDob").value),
        date_of_death: orNull(field("deceasedDod").value),
        death_certificate: orNull(field("deceasedCert").value),
        last_known_address: text(field("deceasedAddress").value),
      },
      contact: {
        name: text(field("reqName").value),
        address: text(field("reqStreet").value),
        barangay: text(field("requesting_barangay").value),
        phone_number: text(field("reqPhone").value),
      },
    };

    if (entry.status !== "Vacant") {
      toggleOccupantSection(true, activeRow.deceased.name);
      const destination = await buildDestination(entry.grave_id);
      if (!destination) return;
      payload.old_occupant_destination = destination;
    }

    isSaving = true;
    setFormBusy(true);
    const originalLabel = btnSave.textContent;
    btnSave.textContent = "Saving...";

    try {
      const response = await fetch("api/reserve", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const result = await readJSON(response);

      if (!result.success) {
        if (/destination/i.test(result.message || "") && activeRow) {
          toggleOccupantSection(true, activeRow.deceased.name);
          toggleOccupantGrave();
        }
        notify(
          result.message || "The reservation could not be saved.",
          "error",
        );
        return;
      }

      takenControlNumbers.add(controlNumber);
      notify(
        result.message || "Burial clearance staged successfully.",
        "success",
      );

      // Invalidate cache to show the new record missing/updated
      defaultDataCache = {};

      isSaving = false;
      closeModal();
      await loadAll();
    } catch (error) {
      console.error("Failed to save the reservation:", error);
      notify(
        error.message === "STATIC_SERVER"
          ? "The backend is not responding. Start Laragon / Apache and try again."
          : "A network or server error occurred.",
        "error",
      );
    } finally {
      isSaving = false;
      setFormBusy(false);
      btnSave.textContent = originalLabel;
    }
  };

  // =========================================================
  // EVENTS
  // =========================================================
  if (tableBody) {
    tableBody.addEventListener("click", (event) => {
      // Phone buttons belong to sendsms.js's own delegated binding below.
      if (event.target.closest("[data-sms]")) return;

      const tr = event.target.closest("tr[data-index]");
      if (!tr) return;

      const button = event.target.closest("button");
      if (!button || button.disabled) return;

      const row = catalogRows[Number(tr.dataset.index)];
      if (!row) return;

      openCreate(row);
    });
  }

  if (dateIntermentInput) {
    const recalc = () => {
      if (expirationTouched) return;
      expirationDateInput.value = addYears(
        dateIntermentInput.value,
        LEASE_YEARS,
      );
    };
    dateIntermentInput.addEventListener("change", recalc);
    dateIntermentInput.addEventListener("input", recalc);
  }

  if (expirationDateInput) {
    expirationDateInput.addEventListener("input", () => {
      expirationTouched = true;
    });
  }

  if (burialTypeSelect)
    burialTypeSelect.addEventListener("change", refreshGraveOptions);
  if (occupantType)
    occupantType.addEventListener("change", toggleOccupantGrave);

  if (graveCodeInput) {
    graveCodeInput.addEventListener("change", () => {
      const entry = graveIndex.get(normalizeCode(graveCodeInput.value));
      if (!entry) return;
      toggleOccupantSection(
        entry.status !== "Vacant",
        entry.grave_id === activeRow?.grave_id ? activeRow.deceased.name : "",
      );
      toggleOccupantGrave();
    });
  }

  // Phone formatter
  const phoneInput = field("reqPhone");
  if (phoneInput) {
    phoneInput.addEventListener("input", (event) => {
      let value = event.target.value.replace(/[^\d\+]/g, "");
      value = value.replace(/(?!^)\+/g, "");
      let formatted = "";

      if (value.startsWith("+")) {
        if (value.length > 13) value = value.substring(0, 13);
        formatted = value.substring(0, 3);
        if (value.length > 3) formatted += " " + value.substring(3, 6);
        if (value.length > 6) formatted += " " + value.substring(6, 9);
        if (value.length > 9) formatted += " " + value.substring(9, 13);
      } else {
        if (value.length > 11) value = value.substring(0, 11);
        formatted = value.substring(0, 4);
        if (value.length > 4) formatted += " " + value.substring(4, 7);
        if (value.length > 7) formatted += " " + value.substring(7, 11);
      }
      event.target.value = formatted;
    });
  }

  if (btnCancel) btnCancel.addEventListener("click", closeModal);
  if (btnSave) btnSave.addEventListener("click", save);

  if (burialForm) {
    burialForm.addEventListener("submit", (event) => {
      event.preventDefault();
      save();
    });
  }

  if (burialModal) {
    burialModal.addEventListener("click", (event) => {
      if (event.target === burialModal) closeModal();
    });
  }

  document.addEventListener("keydown", (event) => {
    // sendsms.js handles Escape for its own composer.
    if (event.key === "Escape" && burialModal.style.display === "block") {
      closeModal();
    }
  });

  // =========================================================
  // BOOT
  // =========================================================
  loadAll();
});
