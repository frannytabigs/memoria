/**
 * RESERVE — Reservation catalog + Burial Clearance intake
 * ---------------------------------------------------------------------------
 * GET  api/reserve        -> catalog: expired / expiring / vacant graves
 * POST api/reserve        -> stages a NEW "Pending" interment on a grave
 * PUT  api/reserve/{id}   -> corrects an existing "Pending" interment
 *
 * A grave vanishes from the catalog the moment it gets staged, so the Pending
 * rows are pulled from api/interments and merged into the same table. That way
 * the "+" button (intake) and the pencil button (correction) both have rows to
 * work on. Everything here is JS-only: reserve.html is left untouched, so the
 * few fields the paper form is missing (old occupant transfer, grave picker)
 * are injected into the modal at runtime.
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

  // reserve.php only lets Admin / Office Staff POST or PUT.
  const canModify = ["Administrator", "Office Staff"].includes(
    localStorage.getItem("memoria_role"),
  );

  // =========================================================
  // STATE
  // =========================================================
  let catalogRows = []; // exactly what the table renders
  let pendingRows = []; // staged reservations (status = Pending)
  let pagination = null;
  let breakdown = null;

  const graveIndex = new Map(); // GRAVE CODE -> { grave_id, status, ... }
  const takenControlNumbers = new Set();

  let activeRow = null; // row currently open in the modal
  let mode = "create"; // "create" (POST) | "edit" (PUT)
  let expirationTouched = false; // stop auto-filling once staff edits it
  let isSaving = false;
  let currentPage = 1;

  // =========================================================
  // SMALL UTILITIES
  // =========================================================
  const escapeHTML = (str) => {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  };

  const text = (value) =>
    value === null || value === undefined ? "" : String(value).trim();

  // Empty strings must become NULL or MySQL rejects them on DATE columns.
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

  // The paper form spells the choices out; the DB column is an ENUM.
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

  // "Niche Wall" (form) vs "Niche" (DB), "Lawn / Grounds" vs "Lawn/Grounds"...
  const normalizeBlockType = (value) =>
    text(value).toLowerCase().replace(/[\s/]/g, "");

  const blockTypeMatches = (formValue, dbValue) => {
    const a = normalizeBlockType(formValue);
    const b = normalizeBlockType(dbValue);
    if (!a || !b) return true;
    return a.startsWith(b) || b.startsWith(a);
  };

  // The DB holds values the static <select>s never listed (sex "Unknown",
  // block types like "Mausoleum"). Add them instead of silently dropping data.
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
   * reserve.php stores the old-occupant transfer plan by wrapping the remarks
   * in a JSON envelope. Unwrap it so the table shows the human note and the
   * modal can restore the plan.
   */
  const parseRemarks = (raw) => {
    const value = text(raw);
    if (!value.startsWith("{")) return { note: value, destination: null };
    try {
      const parsed = JSON.parse(value);
      if (parsed && parsed._workflow === "pending_transition") {
        return {
          note: text(parsed.remarks),
          destination: parsed.old_occupant_destination || null,
        };
      }
    } catch (error) {
      // A plain remark that just happens to start with a brace.
    }
    return { note: value, destination: null };
  };

  // =========================================================
  // INJECTED STYLES
  // =========================================================
  (function injectStyles() {
    if (document.getElementById("reserveInjectedStyles")) return;

    const style = document.createElement("style");
    style.id = "reserveInjectedStyles";
    style.textContent = `
      .rowTag {
        display: inline-block;
        margin-left: 8px;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        vertical-align: middle;
      }
      .rowTag.pending  { background: #ede9fe; color: #5b21b6; }
      .rowTag.expired  { background: #fee2e2; color: #b91c1c; }
      .rowTag.expiring { background: #fef3c7; color: #b45309; }
      .rowTag.vacant   { background: #dcfce7; color: #15803d; }

      .graveCode {
        display: inline-block;
        margin-left: 6px;
        padding: 2px 6px;
        border-radius: 4px;
        background: #f1f5f9;
        color: #475569;
        font-size: 11px;
        font-weight: 600;
      }

      .dayHint { display: block; font-size: 11px; font-weight: 600; margin-top: 2px; }
      .dayHint.overdue { color: #dc2626; }
      .dayHint.soon { color: #b45309; }

      tbody tr.clickableRow { cursor: pointer; }
      tbody tr.clickableRow:hover { background: #f8fafc; }

      .actions button:disabled { opacity: 0.4; cursor: not-allowed; }
      .actions button:disabled:hover { transform: none; box-shadow: none; }

      button.smsBtn:disabled { cursor: not-allowed !important; }
      button.smsBtn:disabled:hover { transform: none; box-shadow: none; }

      .skeletonBox {
        height: 12px;
        border-radius: 6px;
        background: linear-gradient(90deg, #eef2f7 25%, #e2e8f0 37%, #eef2f7 63%);
        background-size: 400% 100%;
        animation: reserveShimmer 1.4s ease infinite;
      }
      @keyframes reserveShimmer {
        0% { background-position: 100% 50%; }
        100% { background-position: 0 50%; }
      }

      .stateCell {
        text-align: center !important;
        padding: 46px 20px !important;
        color: #94a3b8;
        font-size: 14px;
        font-weight: 600;
        white-space: normal !important;
      }
      .stateCell.isError { color: #dc2626; }

      .reservePagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        padding: 16px 20px;
        border-top: 1px solid #f1f5f9;
        background: #fcfdff;
      }
      .reserveSummary { font-size: 12px; color: #64748b; font-weight: 600; }
      .reservePages { display: flex; gap: 6px; flex-wrap: wrap; }
      .reservePages button {
        min-width: 34px;
        padding: 6px 10px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        background: #ffffff;
        color: #475569;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
      }
      .reservePages button:hover:not(:disabled) { background: #f1f5f9; }
      .reservePages button.active { background: #1e3a8a; border-color: #1e3a8a; color: #ffffff; }
      .reservePages button:disabled { opacity: 0.45; cursor: not-allowed; }

      .occupantNote {
        margin: -6px 0 14px 0;
        padding: 10px 12px;
        border-left: 3px solid #f59e0b;
        background: #fffbeb;
        color: #92400e;
        font-size: 12px;
        font-weight: 600;
        border-radius: 4px;
      }

      .fieldHint { font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 4px; }

      .paperForm.isBusy { opacity: 0.65; pointer-events: none; }

      .paperForm input[readonly] { background: #f8fafc; color: #64748b; cursor: not-allowed; }
    `;
    document.head.appendChild(style);
  })();

  // =========================================================
  // INJECTED MARKUP (grave pickers + old occupant transfer)
  // =========================================================
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

    // Drop it just above REMARKS so the paper form still reads top to bottom.
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

  (function injectPaginationBar() {
    const container = document.querySelector(".tableContainer");
    if (!container || document.getElementById("reservePagination")) return;

    const bar = document.createElement("div");
    bar.className = "reservePagination";
    bar.id = "reservePagination";
    bar.innerHTML = `
      <span class="reserveSummary" id="reserveSummary"></span>
      <span class="reservePages" id="reservePages"></span>
    `;
    container.appendChild(bar);
  })();

  // The paper form ships these two as readonly. Staff asked to be able to
  // override both, so unlock them and only auto-fill what is untouched.
  [controlNoInput, expirationDateInput].forEach((input) => {
    if (!input) return;
    input.removeAttribute("readonly");
    input.style.backgroundColor = "#ffffff";
  });

  // =========================================================
  // DATA LOADING
  // =========================================================
  const readJSON = async (response) => {
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.includes("application/json")) {
      throw new Error("STATIC_SERVER");
    }
    return response.json();
  };

  const fetchCatalog = async () => {
    const query = new URLSearchParams({
      page: currentPage,
      limit: CATALOG_LIMIT,
    });
    const response = await fetch(`api/reserve?${query.toString()}`);
    const result = await readJSON(response);
    if (!result.success) throw new Error(result.message || "Catalog failed");
    return result.data || {};
  };

  const fetchPending = async () => {
    try {
      const response = await fetch("api/interments?search=Pending&limit=45");
      const result = await readJSON(response);
      if (!result.success) return [];
      return (result.data?.interments || []).filter(
        (item) => item.status === "Pending",
      );
    } catch (error) {
      console.error("Failed to load pending reservations:", error);
      return [];
    }
  };

  const fetchRecord = async (resource, id) => {
    if (!id) return null;
    try {
      const response = await fetch(`api/${resource}/${id}`);
      const result = await readJSON(response);
      return result.success ? result.data : null;
    } catch (error) {
      console.error(`Failed to load ${resource} ${id}:`, error);
      return null;
    }
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
    const remarks = parseRemarks(item.remarks);
    const grave = item.grave || {};
    const block = item.block || {};
    const deceased = item.deceased || {};
    const contact = item.contact || {};

    if (grave.grave_code) {
      rememberGrave({
        grave_id: grave.grave_id,
        grave_code: grave.grave_code,
        block_name: block.block_name,
        // A live lease means the slot is physically occupied. A pending one is
        // already locked by reserve.php, so it is not selectable either way.
        status: kind === "pending" ? "Staged" : "Occupied",
      });
    }
    if (item.control_number) takenControlNumbers.add(item.control_number);

    return {
      kind,
      interment_id: item.interment_id || null,
      grave_id: grave.grave_id || null,
      grave_code: grave.grave_code || "",
      block_name: block.block_name || "",
      block_type: "",
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
        date_of_death: deceased.date_of_death || "",
        death_certificate: deceased.death_certificate || "",
      },
      contact: {
        contact_id: contact.contact_id || null,
        name: contact.name || "",
        phone_number: contact.phone_number || "",
      },
      note: remarks.note,
      destination: remarks.destination,
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
      deceased: { deceased_id: null, name: "", sex: "" },
      contact: { contact_id: null, name: "", phone_number: "" },
      note: "",
      destination: null,
    };
  };

  // =========================================================
  // TABLE RENDERING
  // =========================================================
  const TAGS = {
    pending: "Staged",
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
                `<td><div class="skeletonBox" style="width:${Math.floor(
                  Math.random() * 40 + 50,
                )}%;"></div></td>`,
            )
            .join("")}
        </tr>`,
      )
      .join("");
  };

  const renderState = (message, isError = false) => {
    tableBody.innerHTML = `
      <tr>
        <td colspan="${COLSPAN}" class="stateCell ${isError ? "isError" : ""}">
          ${escapeHTML(message)}
        </td>
      </tr>`;
  };

  const renderTable = () => {
    if (!catalogRows.length) {
      renderState(
        "Nothing to reserve right now — no vacant, expiring or expired graves on this page.",
      );
      return;
    }

    tableBody.innerHTML = catalogRows
      .map((row, index) => {
        const isPending = row.kind === "pending";
        const addStyle = isPending ? ' style="display:none"' : "";
        const editStyle = isPending ? "" : ' style="display:none"';
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
          <td>
            ${
              row.contact.phone_number
                ? `
                  <button
                    class="smsBtn"${lockedAttrs}
                    style="
                      background: #f3f4f6;
                      border: 1px solid #d1d5db;
                      border-radius: 6px;
                      cursor: pointer;
                      padding: 5px 8px;
                      display: inline-flex;
                      align-items: center;
                      gap: 6px;
                    "
                  >
                    <span>📩</span>
                    <span>${escapeHTML(row.contact.phone_number)}</span>
                  </button>
                `
                : "&mdash;"
            }
          </td>        
          <td>${dash(row.note)}</td>
          <td class="actions">
            <button class="addBtn"${addStyle}${lockedAttrs} title="Create Burial Clearance">
              <i class="fas fa-plus"></i>
            </button>
            <button class="editBtn"${editStyle}${lockedAttrs} title="Edit Reservation / Burial Clearance">
              <i class="fas fa-edit"></i>
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

    if (breakdown) {
      summary.textContent =
        `${pendingRows.length} staged · ${breakdown.expired_count} expired · ` +
        `${breakdown.expiring_count} expiring soon · ${breakdown.vacant_count} vacant`;
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
      else
        button.addEventListener("click", () => {
          currentPage = page;
          loadAll();
        });
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
      `<option value="${escapeHTML(entry.grave_code)}">${escapeHTML(
        [entry.block_name, entry.status].filter(Boolean).join(" · "),
      )}</option>`;

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

    const pendingRequest = fetchPending();

    try {
      const [catalog, pending] = await Promise.all([
        fetchCatalog(),
        pendingRequest,
      ]);

      pagination = catalog.pagination || null;
      breakdown = pagination?.breakdown || null;
      pendingRows = pending.map((item) => fromInterment(item, "pending"));

      const expired = (catalog.expired || []).map((item) =>
        fromInterment(item, "expired"),
      );
      const expiring = (catalog.expiring || []).map((item) =>
        fromInterment(item, "expiring"),
      );
      const vacant = (catalog.vacant || []).map(fromVacantGrave);

      // Staged reservations lead the list, but only on the first page —
      // reserve.php does not paginate them.
      catalogRows = [
        ...(currentPage === 1 ? pendingRows : []),
        ...vacant,
        ...expired,
        ...expiring,
      ];

      renderTable();
      renderPagination();
      refreshGraveOptions();
    } catch (error) {
      console.error("Failed to load the reservation catalog:", error);
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

  const toggleOccupantGrave = () => {
    if (!occupantGraveCol || !occupantType) return;
    occupantGraveCol.style.visibility =
      occupantType.value === "specific_grave" ? "visible" : "hidden";
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
    mode = "create";
    expirationTouched = false;
  };

  /** Fresh intake on a catalog grave -> POST api/reserve */
  const openCreate = (row) => {
    mode = "create";
    controlNoInput.value = generateControlNumber();
    clearanceDateInput.value = todayValue();

    if (graveCodeInput) graveCodeInput.value = row.grave_code || "";
    setSelectValue(burialTypeSelect, row.block_type || burialTypeSelect.value);
    setSelectValue(assistanceSelect, ASSISTANCE_LABELS.Burial);

    // Expired / expiring rows sit on a grave that still holds someone.
    toggleOccupantSection(row.kind !== "vacant", row.deceased.name);
    toggleOccupantGrave();
    refreshGraveOptions();
  };

  /** Correction on a staged reservation -> PUT api/reserve/{id} */
  const openEdit = async (row) => {
    mode = "edit";

    controlNoInput.value = row.control_number;
    clearanceDateInput.value = toInputDate(row.clearance_date);
    dateIntermentInput.value = toInputDate(row.date_buried);
    expirationDateInput.value = toInputDate(row.lease_expiration_date);
    expirationTouched = true; // never silently recompute a saved lease

    setSelectValue(
      assistanceSelect,
      ASSISTANCE_LABELS[row.assistance_type] || ASSISTANCE_LABELS.Other,
    );

    field("permitBurial").value = row.burial_permit_number;
    field("permitExhumation").value = row.exhumation_permit_number;
    field("permitTransfer").value = row.transfer_permit_number;
    field("deceasedRemarks").value = row.note;

    field("deceasedName").value = row.deceased.name;
    setSelectValue(field("deceasedSex"), row.deceased.sex);
    field("deceasedDod").value = toInputDate(row.deceased.date_of_death);
    field("deceasedCert").value = row.deceased.death_certificate;

    field("reqName").value = row.contact.name;
    field("reqPhone").value = row.contact.phone_number;

    // reserve.php's PUT cannot move a staged reservation to another grave.
    if (graveCodeInput) {
      graveCodeInput.value = row.grave_code;
      graveCodeInput.setAttribute("readonly", "readonly");
      graveCodeInput.title =
        "A staged reservation stays on its grave. Cancel it in Records to pick a different one.";
    }

    toggleOccupantSection(Boolean(row.destination), row.deceased.name);
    if (row.destination) {
      setSelectValue(occupantType, row.destination.type);
      occupantNotes.value = text(row.destination.notes);
      const target = Array.from(graveIndex.values()).find(
        (entry) => entry.grave_id === row.destination.grave_id,
      );
      occupantGrave.value = target ? target.grave_code : "";
    }
    toggleOccupantGrave();
    refreshGraveOptions();

    // The list endpoints omit a few columns, so pull the full records before
    // saving — otherwise the PUT would blank the address / barangay / DOB.
    setFormBusy(true);
    const [deceased, contact] = await Promise.all([
      fetchRecord("deceased", row.deceased.deceased_id),
      fetchRecord("contacts", row.contact.contact_id),
    ]);
    setFormBusy(false);

    if (activeRow !== row) return; // staff already moved on

    if (deceased) {
      row.fullDeceased = deceased;
      field("deceasedDob").value = toInputDate(deceased.date_of_birth);
      field("deceasedAddress").value = text(deceased.last_known_address);
      if (text(deceased.name)) field("deceasedName").value = deceased.name;
      setSelectValue(field("deceasedSex"), deceased.sex);
    }

    if (contact) {
      row.fullContact = contact;
      field("reqStreet").value = text(contact.address);
      setSelectValue(field("requesting_barangay"), contact.barangay);
      if (text(contact.phone_number)) {
        field("reqPhone").value = contact.phone_number;
      }
    }
  };

  const openModal = async (row) => {
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

    burialModal.style.display = "block";
    document.body.style.overflow = "hidden";

    if (row.kind === "pending") await openEdit(row);
    else openCreate(row);
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
        block_type: "",
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
  // SAVE
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

    let url = "api/reserve";
    let method = "POST";
    let payload = {};

    if (mode === "edit") {
      const row = activeRow;
      url = `api/reserve/${row.interment_id}`;
      method = "PUT";

      payload = {
        interment_id: row.interment_id,
        control_number: controlNumber,
        ...collectShared(),
        burial_permit_number: text(field("permitBurial").value),
        exhumation_permit_number: text(field("permitExhumation").value),
        transfer_permit_number: text(field("permitTransfer").value),
        deceased: {
          name: text(field("deceasedName").value),
          sex: text(field("deceasedSex").value),
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
          // Not on the paper form — echo it back so the PUT cannot wipe it.
          email_address: text(row.fullContact?.email_address),
        },
      };

      if (row.destination) {
        const destination = await buildDestination(row.grave_id);
        if (!destination) return;
        payload.old_occupant_destination = destination;
      }
    } else {
      // --- POST: resolve which grave is actually being staged ---
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
      if (entry.status === "Staged") {
        notify(
          `Grave ${entry.grave_code} already has a staged reservation. Edit that one instead.`,
          "error",
        );
        shake(["graveCode"]);
        return;
      }

      payload = {
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

      // reserve.php only demands a transfer plan when the slot is occupied.
      if (entry.status !== "Vacant") {
        toggleOccupantSection(true, activeRow.deceased.name);
        const destination = await buildDestination(entry.grave_id);
        if (!destination) return;
        payload.old_occupant_destination = destination;
      }
    }

    isSaving = true;
    setFormBusy(true);
    const originalLabel = btnSave.textContent;
    btnSave.textContent = "Saving...";

    try {
      const response = await fetch(url, {
        method,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const result = await readJSON(response);

      if (!result.success) {
        // reserve.php refuses the update when a grave already flagged for
        // exhumation has no transfer plan on file. Reveal the fields so staff
        // can restate it instead of hitting a dead end.
        if (/destination/i.test(result.message || "") && activeRow) {
          activeRow.destination = activeRow.destination || {
            type: occupantType.value,
            notes: "",
          };
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
        result.message ||
          (mode === "edit"
            ? "Reservation updated."
            : "Burial clearance staged successfully."),
        "success",
      );

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
      const tr = event.target.closest("tr[data-index]");
      if (!tr) return;

      const button = event.target.closest("button");
      // IF THEY DID NOT CLICK A BUTTON, DO NOTHING
      if (!button || button.disabled) return;

      const row = catalogRows[Number(tr.dataset.index)];
      if (!row) return;

      // Handle the new SMS button
      if (button.classList.contains("smsBtn")) {
        openSmsModal(row);
        return;
      }

      // Otherwise, it was the Add or Edit button
      openModal(row);
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

  if (burialTypeSelect) {
    burialTypeSelect.addEventListener("change", refreshGraveOptions);
  }

  if (occupantType) {
    occupantType.addEventListener("change", toggleOccupantGrave);
  }

  // Retargeting the clearance to another grave flips the transfer requirement.
  if (graveCodeInput) {
    graveCodeInput.addEventListener("change", () => {
      if (mode !== "create") return;
      const entry = graveIndex.get(normalizeCode(graveCodeInput.value));
      if (!entry) return;
      toggleOccupantSection(
        entry.status !== "Vacant",
        entry.grave_id === activeRow?.grave_id ? activeRow.deceased.name : "",
      );
      toggleOccupantGrave();
    });
  }

  // Phone formatter handling both 09XX and +63 formats
  const phoneInput = field("reqPhone");
  if (phoneInput) {
    phoneInput.addEventListener("input", (event) => {
      // 1. Keep digits and the plus sign (escaped as \+ for maximum safety)
      let value = event.target.value.replace(/[^\d\+]/g, "");
      value = value.replace(/(?!^)\+/g, ""); // Strip any '+' that isn't the first character

      let formatted = "";

      // 2. If they start with a '+', instantly switch to international format
      if (value.startsWith("+")) {
        // Format: +63 928 124 8905 (Max length: 13 characters)
        if (value.length > 13) value = value.substring(0, 13);

        formatted = value.substring(0, 3);
        if (value.length > 3) formatted += " " + value.substring(3, 6);
        if (value.length > 6) formatted += " " + value.substring(6, 9);
        if (value.length > 9) formatted += " " + value.substring(9, 13);
      } else {
        // Format: 0928 589 3458 (Max length: 11 characters)
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
    if (event.key === "Escape" && burialModal.style.display === "block") {
      closeModal();
    }
  });

  // =========================================================
  // BOOT
  // =========================================================
  loadAll();

  // --- SMS MODAL ELEMENTS ---
  const smsModal = document.getElementById("smsModalOverlay");
  const smsForm = document.getElementById("smsForm");
  const smsPhoneInput = document.getElementById("smsPhone");
  const smsMessageInput = document.getElementById("smsMessage");
  const smsIncludeCheckbox = document.getElementById("smsIncludeCemeteryName");
  const btnSmsCancel = document.getElementById("btnSmsCancel");
  const btnSmsSend = document.getElementById("btnSmsSend");

  let currentSmsRow = null;

  const closeSmsModal = () => {
    smsModal.style.display = "none";
    document.body.style.overflow = "auto";
    currentSmsRow = null;
    smsForm.reset();
  };

  const openSmsModal = (row) => {
    currentSmsRow = row;

    // Format the expiration date for human reading
    const expDate = formatDate(row.lease_expiration_date);

    // Populate the default template
    const contactName = text(row.contact.name) || "Family Member";
    const deceasedName = text(row.deceased.name) || "your beloved";

    smsPhoneInput.value = text(row.contact.phone_number);
    smsMessageInput.value = `Dear ${contactName}, this is an official notice. The lease for the grave of ${deceasedName} ends on ${expDate}. Please visit the cemetery office as soon as possible to arrange the necessary transfer of remains. Thank you.`;

    smsIncludeCheckbox.checked = true;

    smsModal.style.display = "block";
    document.body.style.overflow = "hidden";
  };

  // --- SMS EVENT LISTENERS ---
  if (btnSmsCancel) btnSmsCancel.addEventListener("click", closeSmsModal);

  if (smsForm) {
    smsForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!currentSmsRow) return;

      const phone = smsPhoneInput.value;
      const message = smsMessageInput.value;
      const includeName = smsIncludeCheckbox.checked;

      if (!phone) {
        notify("No valid phone number to send to.", "error");
        return;
      }

      // UX: Prevent double-clicking
      const originalText = btnSmsSend.textContent;
      btnSmsSend.textContent = "Sending...";
      btnSmsSend.disabled = true;

      try {
        // Call the async function you already built
        await sendSms(phone, message, includeName);
        closeSmsModal();
      } catch (error) {
        console.error(error);
        notify("An error occurred while sending the SMS.", "error");
      } finally {
        btnSmsSend.textContent = originalText;
        btnSmsSend.disabled = false;
      }
    });
  }

  async function sendSms(phoneNumber, message, include_cemetery_name = true) {
    try {
      const response = await fetch("api/sendsms", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          phone_number: phoneNumber,
          message: message,
          include_cemetery_name: include_cemetery_name,
        }),
      });
      const result = await response.json();
      if (result.success) notify("SMS sent successfully!", "success");
      else notify(result.message, "error");
    } catch (error) {
      notify("Failed to send SMS.", "error");
    }
  }
});
