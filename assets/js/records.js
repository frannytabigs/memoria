/**
 * RECORDS — the master interment ledger (digital Burial Clearance forms)
 *
 * GET    api/interments?page=&limit=     paginated ledger
 * GET    api/interments?search=          server-side search (backend caps it at 45)
 * GET    api/interments?control_number=  uniqueness probe for generated control numbers
 * POST   api/interments                  new record (resolves the nested deceased + contact)
 * PUT    api/interments/{id}             control no., permits, dates, status, remarks, plus nested deceased/contact updates
 * DELETE api/interments/{id}             soft delete, and it frees the grave
 * GET    api/graves?search=CODE          resolve a grave: its status and who is already in it
 *
 * MERGE = co-interment: several records sharing one grave (bones moved into a bone
 * chamber, a family stacking a niche). POST api/interments refuses an occupied
 * grave unless the block is a chamber/mass grave/cluster, the request is a
 * Transfer, or is_co_interment is set — so the form asks for that confirmation
 * out loud. DELETE always frees the grave to Vacant, so after deleting one of a
 * merged set we flag the grave back to Occupied.
 */
document.addEventListener("DOMContentLoaded", () => {
  const ROWS_PER_PAGE = 250;
  const COLSPAN = 13;
  const LEASE_YEARS = 5;

  // ==========================================
  // ELEMENTS
  // ==========================================
  const field = (id) => document.getElementById(id);

  const tableBody = field("burialTableBody");
  const noData = field("burialNoData");
  const container = document.querySelector(".tableContainer");
  const searchInput = field("recordSearch");
  const searchBtn = field("searchBtn");
  const prevBtn = field("prevPageBtn");
  const nextBtn = field("nextPageBtn");
  const pageNumbers = field("paginationNumbers");
  const addBtn = document.querySelector(".addRecordBtn");

  const overlay = field("burialModalOverlay");
  const form = field("burialClearanceForm");
  const paper = overlay ? overlay.querySelector(".paperForm") : null;
  const saveBtn = overlay ? overlay.querySelector(".btnPaperSave") : null;
  const cancelBtn = overlay ? overlay.querySelector(".btnPaperCancel") : null;

  if (!tableBody || !overlay || !form) return;

  // ==========================================
  // STATE
  // ==========================================
  let records = [];
  let pagination = null;
  let currentPage = 1;
  let searchTerm = "";
  let loadToken = 0;
  let isLoading = false;
  let isSaving = false;

  let mode = "create"; // create | merge | edit | view
  let activeRecord = null;
  let mergeSource = null;
  let resolvedGrave = null;
  let expirationTouched = false;

  const graveCache = new Map();
  let searchTimer = null;
  let graveTimer = null;

  // The page is already gated to Administrator / Office Staff by initAuth(). A
  // missing cached role only means auth has not answered yet, so do not lock the
  // buttons on a fresh login — just keep Grounds Staff read-only.
  const canModify = () =>
    localStorage.getItem("memoria_role") !== "Grounds Staff";

  // ==========================================
  // SMALL HELPERS
  // ==========================================
  const text = (value) =>
    value === null || value === undefined ? "" : String(value).trim();

  // Empty strings must become NULL or MySQL rejects them on DATE columns.
  const orNull = (value) => (text(value) === "" ? null : text(value));

  const normalizeCode = (value) => text(value).toUpperCase();
  const toInputDate = (value) => (value ? String(value).slice(0, 10) : "");

  const escapeHtml = (value) =>
    text(value).replace(
      /[&<>"']/g,
      (character) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        })[character],
    );

  const cell = (value) => {
    if (text(value) !== "") return escapeHtml(value);
    return '<span class="cellMuted">—</span>';
  };

  const notify = (message, type = "info", duration = 4000) => {
    if (typeof showAlertTOP === "function")
      showAlertTOP(message, type, duration);
    else console.log(`[${type}] ${message}`);
  };

  const shake = (ids) => {
    if (typeof animateInputsOnError === "function") animateInputsOnError(ids);
  };

  const todayISO = () => {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 10);
  };

  const addYears = (value, years) => {
    const iso = toInputDate(value);
    const [year, month, day] = iso.split("-").map(Number);
    if (!year || !month || !day) return "";
    return new Date(Date.UTC(year + years, month - 1, day))
      .toISOString()
      .slice(0, 10);
  };

  const slug = (value) =>
    text(value)
      .toLowerCase()
      .replace(/[^a-z]+/g, "");

  // The DB holds values the static <select>s never listed (sex "Unknown", the
  // barangay "Tawason", "Paknaan" vs the form's "Pakna-an"). Append rather than
  // silently dropping the stored value on save.
  const ensureOption = (select, value) => {
    if (!select || text(value) === "") return;
    const exists = Array.from(select.options).some(
      (option) => option.value === text(value),
    );
    if (exists) return;
    const option = document.createElement("option");
    option.value = text(value);
    option.textContent = text(value);
    select.appendChild(option);
  };

  const setSelectValue = (select, value) => {
    if (!select) return;
    ensureOption(select, value);
    select.value = text(value);
  };

  // #reqAssistance ships without value attributes, so the label text would land
  // straight in ENUM('Burial','Transfer','Other').
  const ASSISTANCE_LABELS = {
    Burial: "Burial of the late",
    Transfer: "Transfer the remains of the late to the bone chamber",
    Other: "Other...",
  };

  const toAssistanceEnum = (label) => {
    const value = text(label).toLowerCase();
    if (value.startsWith("transfer")) return "Transfer";
    if (value.startsWith("burial")) return "Burial";
    return "Other";
  };

  // Burial Type is form-speak ("Niche Wall"); blocks are named "Niche Wall A",
  // "Lawn Area D". Infer the type from the block name so the form stays coherent.
  const guessBurialType = (blockName) => {
    const name = text(blockName).toLowerCase();
    if (name.includes("bone")) return "Bone Chamber";
    if (name.includes("niche")) return "Niche Wall";
    if (name.includes("lawn") || name.includes("ground"))
      return "Lawn / Grounds";
    if (name.includes("private") || name.includes("mausoleum"))
      return "Private / Owned";
    if (name.includes("unmapped")) return "Unmapped Area";
    return "";
  };

  // ==========================================
  // INJECTED STYLES
  // ==========================================
  (function injectStyles() {
    if (field("recordsInjectedStyles")) return;
    const style = document.createElement("style");
    style.id = "recordsInjectedStyles";
    style.textContent = `
      .recordsMeta {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        padding: 10px 16px;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
        font-size: 12px;
        color: #64748b;
      }
      .recordsMeta strong { color: #1e293b; font-weight: 600; }
      .searchChip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 6px 3px 10px;
        border-radius: 999px;
        background: #e0f2fe;
        color: #0369a1;
        font-weight: 600;
      }
      .searchChip button {
        border: none;
        background: transparent;
        color: inherit;
        cursor: pointer;
        font-size: 14px;
        line-height: 1;
        padding: 0 2px;
      }
      .cellMuted { color: #cbd5e1; }
      .blockCell { display: flex; align-items: center; gap: 8px; }
      .graveChip {
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        color: #334155;
        border-radius: 6px;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.4px;
        cursor: pointer;
        font-family: inherit;
      }
      .graveChip:hover { background: #e0f2fe; border-color: #7dd3fc; color: #0369a1; }
      .sharedBadge {
        background: #ede9fe;
        color: #6d28d9;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }
      .rowChip {
        display: inline-block;
        margin-left: 8px;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }
      .rowChip.active { background: #dcfce7; color: #15803d; }
      .rowChip.expired { background: #fee2e2; color: #b91c1c; }
      .rowChip.exhumed { background: #e2e8f0; color: #475569; }
      .rowChip.movedtofamily { background: #fef3c7; color: #92400e; }
      .rowChip.pending { background: #e0e7ff; color: #4338ca; }
      .dayHint { display: block; font-size: 10px; color: #b91c1c; margin-top: 2px; }
      .actions .mergeBtn { background: #ede9fe; color: #6d28d9; }
      .actions button:disabled { opacity: 0.4; cursor: not-allowed; }
      tbody tr.clickableRow { cursor: pointer; }
      .stateCell { text-align: center; padding: 34px 16px !important; color: #94a3b8; }
      .skeletonBox {
        display: block;
        height: 11px;
        border-radius: 4px;
        background: linear-gradient(90deg, #eef2f7 25%, #e2e8f0 37%, #eef2f7 63%);
        background-size: 400% 100%;
        animation: recordsShimmer 1.3s ease-in-out infinite;
      }
      @keyframes recordsShimmer {
        0% { background-position: 100% 50%; }
        100% { background-position: 0 50%; }
      }
      .pageGap { padding: 6px 4px; color: #94a3b8; font-size: 13px; }
      .formMode {
        text-align: center;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        color: #64748b;
        margin: -22px 0 26px 0;
      }
      .fieldHint { font-size: 11px; color: #64748b; margin-top: 5px; line-height: 1.45; }
      .coBox {
        border: 1px solid #ddd6fe;
        background: #f5f3ff;
        border-radius: 8px;
        padding: 14px 16px;
        margin-bottom: 15px;
      }
      .coBox.isHidden { display: none; }
      .coTitle { font-size: 12px; font-weight: 700; color: #5b21b6; margin-bottom: 6px; }
      .coNote { font-size: 12px; color: #4c1d95; line-height: 1.5; }
      .coOccupants { margin: 10px 0 0 0; padding: 0; list-style: none; }
      .coOccupants li {
        font-size: 12px;
        color: #3730a3;
        padding: 5px 0;
        border-top: 1px dashed #ddd6fe;
      }
      .coCheck {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        margin-top: 12px;
        font-size: 12px;
        font-weight: 700;
        color: #4c1d95;
        cursor: pointer;
      }
      .coCheck input { width: 16px; height: 16px; margin: 1px 0 0 0; cursor: pointer; }
      .paperForm.isBusy { opacity: 0.6; pointer-events: none; }
      .rec_overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 99998;
        font-family: Inter, Arial, sans-serif;
      }
      .rec_dialog {
        width: 380px;
        background: #fff;
        border-radius: 14px;
        padding: 22px;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.25);
      }
      .rec_title { font-size: 17px; font-weight: 700; color: #1e293b; margin-bottom: 8px; }
      .rec_text { font-size: 13px; color: #475569; line-height: 1.55; margin-bottom: 18px; }
      .rec_actions { display: flex; justify-content: flex-end; gap: 10px; }
      .rec_actions button {
        border: none;
        padding: 9px 16px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        font-family: inherit;
      }
      .rec_cancel { background: #eef2f7; color: #334155; }
      .rec_confirm { background: #dc2626; color: #fff; }
    `;
    document.head.appendChild(style);
  })();

  // ==========================================
  // INJECTED DOM
  // ==========================================
  const graveListId = "recordsGraveOptions";
  const graveList = document.createElement("datalist");
  graveList.id = graveListId;
  document.body.appendChild(graveList);

  const metaBar = document.createElement("div");
  metaBar.className = "recordsMeta";
  metaBar.innerHTML = '<span id="recordsMetaText">Loading records…</span>';
  if (container) container.prepend(metaBar);
  const metaText = field("recordsMetaText");

  const formTitle = paper ? paper.querySelector(".formTitle") : null;
  const modeBanner = document.createElement("div");
  modeBanner.className = "formMode";
  modeBanner.id = "formMode";
  if (formTitle) formTitle.insertAdjacentElement("afterend", modeBanner);

  const controlNoInput = field("controlNo");
  const expirationInput = field("expirationDate");
  const graveInput = field("graveCode");
  const assistanceSelect = field("reqAssistance");
  const blockSelect = field("burialBlock");

  // The paper form ships these two as readonly. Staff need to be able to type a
  // control number straight off the paper copy and to override a lease that was
  // not a plain five years, so unlock both and only auto-fill what is untouched.
  [controlNoInput, expirationInput].forEach((input) => {
    if (!input) return;
    input.removeAttribute("readonly");
    input.style.backgroundColor = "#ffffff";
  });

  // The grave code is not optional — POST api/interments rejects a record
  // without one — so relabel it and wire up a live picker.
  if (graveInput) {
    graveInput.setAttribute("list", graveListId);
    graveInput.setAttribute("placeholder", "Start typing a code, e.g. E-J1");
    graveInput.setAttribute("autocomplete", "off");
    const graveLabel = graveInput.closest(".col")?.querySelector("label");
    if (graveLabel) {
      graveLabel.textContent = "Grave Code:";
      graveLabel.classList.add("required");
    }
    graveInput.required = true;
    const hint = document.createElement("div");
    hint.className = "fieldHint";
    hint.id = "graveHint";
    graveInput.insertAdjacentElement("afterend", hint);
  }
  const graveHint = field("graveHint");

  // One reusable line under the requesting party, for records that have no
  // contact linked at all.
  const contactHint = document.createElement("div");
  contactHint.className = "fieldHint";
  contactHint.id = "contactHint";
  if (field("reqName"))
    field("reqName").insertAdjacentElement("afterend", contactHint);

  // Record status has no field on the paper form, but PUT accepts it and staff
  // need it to mark a body Exhumed or Moved to Family.
  const expirationRow = expirationInput
    ? expirationInput.closest(".row")
    : null;
  const statusCol = document.createElement("div");
  statusCol.className = "col";
  statusCol.id = "statusCol";
  statusCol.innerHTML = `
    <label>Record Status:</label>
    <select id="intermentStatus">
      <option value="Active">Active</option>
      <option value="Expired">Expired</option>
      <option value="Exhumed">Exhumed</option>
      <option value="Moved to Family">Moved to Family</option>
      <option value="Pending">Pending</option>
    </select>
    <div class="fieldHint">New records always start as Active.</div>`;
  if (expirationRow) expirationRow.appendChild(statusCol);
  const statusSelect = field("intermentStatus");

  // Co-interment ("merge") panel, right under the location assignment.
  const coBox = document.createElement("div");
  coBox.className = "coBox isHidden";
  coBox.innerHTML = `
    <div class="coTitle">THIS GRAVE IS ALREADY IN USE</div>
    <div class="coNote" id="coNote"></div>
    <ul class="coOccupants" id="coOccupants"></ul>
    <label class="coCheck" id="coCheckWrap">
      <input type="checkbox" id="coEnable" />
      <span
        >Merge — put this record in the same grave (co-interment). Bone chambers,
        mass graves and clusters accept this normally; a niche or lawn plot needs
        this ticked on purpose.</span
      >
    </label>`;
  if (expirationRow) expirationRow.insertAdjacentElement("beforebegin", coBox);
  const coNote = field("coNote");
  const coOccupants = field("coOccupants");
  const coEnable = field("coEnable");
  const coCheckWrap = field("coCheckWrap");

  // ==========================================
  // FETCH LAYER
  // ==========================================
  const readJSON = async (response) => {
    const contentType = response.headers.get("content-type") || "";
    // A static file server (Live Server, python -m http.server) hands back the
    // raw PHP; treat that as "backend is not running", not as a real error.
    if (!contentType.includes("application/json"))
      throw new Error("STATIC_SERVER");
    return response.json();
  };

  const apiGet = async (url) => readJSON(await fetch(url));

  const apiSend = async (url, method, payload) =>
    readJSON(
      await fetch(url, {
        method,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload || {}),
      }),
    );

  const failureText = (error) =>
    error && error.message === "STATIC_SERVER"
      ? "the backend is not running"
      : "the request could not be completed";

  const resolveGrave = async (code, force) => {
    const key = normalizeCode(code);
    if (!key) return null;
    if (!force && graveCache.has(key)) return graveCache.get(key);
    try {
      const result = await apiGet(
        `api/graves?search=${encodeURIComponent(key)}`,
      );
      const graves = result.success ? result.data?.graves || [] : [];
      graves.forEach((grave) =>
        graveCache.set(normalizeCode(grave.grave_code), grave),
      );
      const match =
        graves.find((grave) => normalizeCode(grave.grave_code) === key) || null;
      graveCache.set(key, match);
      return match;
    } catch (error) {
      return null;
    }
  };

  const occupantsOf = (grave) =>
    grave && Array.isArray(grave.interments) ? grave.interments : [];

  const graveIsTaken = (grave) =>
    Boolean(grave) &&
    (grave.status === "Occupied" || occupantsOf(grave).length > 0);

  // ==========================================
  // ROW MODEL
  // ==========================================
  const fromInterment = (item) => ({
    interment_id: Number(item.interment_id) || 0,
    control_number: text(item.control_number),
    assistance_type: text(item.assistance_type) || "Burial",
    burial_permit_number: text(item.burial_permit_number),
    transfer_permit_number: text(item.transfer_permit_number),
    exhumation_permit_number: text(item.exhumation_permit_number),
    date_buried: toInputDate(item.date_buried),
    clearance_date: toInputDate(item.clearance_date),
    lease_expiration_date: toInputDate(item.lease_expiration_date),
    status: text(item.status) || "Active",
    remarks: text(item.remarks),
    grave: {
      // A NULL grave comes back as 0 from the endpoint's (int) cast.
      grave_id: Number(item.grave?.grave_id) || 0,
      grave_code: text(item.grave?.grave_code),
    },
    block: {
      block_id: Number(item.block?.block_id) || 0,
      block_name: text(item.block?.block_name),
    },
    deceased: {
      deceased_id: Number(item.deceased?.deceased_id) || 0,
      name: text(item.deceased?.name),
      sex: text(item.deceased?.sex),
      date_of_birth: toInputDate(item.deceased?.date_of_birth),
      date_of_death: toInputDate(item.deceased?.date_of_death),
      death_certificate: text(item.deceased?.death_certificate),
      last_known_address: text(item.deceased?.last_known_address),
      is_archived: Boolean(item.deceased?.is_archived),
    },
    contact: {
      contact_id: Number(item.contact?.contact_id) || 0,
      name: text(item.contact?.name),
      address: text(item.contact?.address),
      barangay: text(item.contact?.barangay),
      phone_number: text(item.contact?.phone_number),
      is_archived: Boolean(item.contact?.is_archived),
    },
  });

  // ==========================================
  // LOADING
  // ==========================================
  const setState = (html) => {
    tableBody.innerHTML = `<tr><td colspan="${COLSPAN}" class="stateCell">${html}</td></tr>`;
    if (noData) noData.style.display = "none";
  };

  const showSkeleton = () => {
    const row = `<tr>${Array.from(
      { length: COLSPAN },
      () => '<td><span class="skeletonBox"></span></td>',
    ).join("")}</tr>`;
    tableBody.innerHTML = row.repeat(6);
    if (noData) noData.style.display = "none";
  };

  const loadRecords = async () => {
    const token = ++loadToken;
    isLoading = true;
    showSkeleton();
    renderPagination(); // Disables buttons while loading

    const params = new URLSearchParams({
      page: String(currentPage),
      limit: String(ROWS_PER_PAGE),
    });
    if (searchTerm) params.set("search", searchTerm);

    try {
      const result = await apiGet(`api/interments?${params.toString()}`);
      if (token !== loadToken) return;

      if (!result.success) {
        // An empty ledger answers 404 rather than an empty list.
        records = [];
        pagination = null;
        setState(
          searchTerm
            ? `Nothing matches <strong>${escapeHtml(searchTerm)}</strong>.`
            : "No Records Found",
        );
        renderMeta();
        return;
      }

      pagination = result.data?.pagination || null;
      records = (result.data?.interments || []).map(fromInterment);
      renderTable();
      renderMeta();
    } catch (error) {
      if (token !== loadToken) return;
      records = [];
      pagination = null;
      console.error("Records failed to load:", error.message);
      setState(
        error.message === "STATIC_SERVER"
          ? "The PHP backend is not running, so the ledger is empty.<br />Open this page through Laragon/Apache."
          : "The ledger could not be loaded. Please try again.",
      );
      renderMeta();
    } finally {
      if (token === loadToken) {
        isLoading = false; // Turn off loading state
        renderPagination(); // NOW draw the buttons (they will unlock!)
      }
    }
  };

  const goToPage = (page) => {
    const target = Math.max(1, Number(page) || 1);
    if (target === currentPage) return;
    currentPage = target;
    loadRecords();
  };

  const reload = () => loadRecords();

  // ==========================================
  // RENDER: META + PAGINATION
  // ==========================================
  const renderMeta = () => {
    if (!metaText) return;
    const total = Number(pagination?.total_records) || 0;
    const page = Number(pagination?.current_page) || currentPage;
    const pages = Math.max(1, Number(pagination?.total_pages) || 1);
    const parts = [];

    if (total > 0) {
      parts.push(
        `<strong>${total}</strong> record${total === 1 ? "" : "s"} · page <strong>${page}</strong> of ${pages}`,
      );
    } else {
      parts.push("No records to show");
    }
    if (searchTerm) {
      parts.push(
        `<span class="searchChip">“${escapeHtml(searchTerm)}” <button type="button" id="clearSearch" title="Clear search">&times;</button></span>`,
      );
      // The interments endpoint hard-caps a search at 45 rows.
      if (total >= 45) parts.push("search results are capped at 45");
    }
    metaText.innerHTML = parts.join(" &nbsp;·&nbsp; ");

    const clear = field("clearSearch");
    if (clear) {
      clear.addEventListener("click", () => {
        if (searchInput) searchInput.value = "";
        searchTerm = "";
        currentPage = 1;
        loadRecords();
      });
    }
  };

  const pageWindow = (page, total) => {
    if (total <= 7)
      return Array.from({ length: total }, (_, index) => index + 1);
    const wanted = new Set([1, total, page, page - 1, page + 1]);
    if (page <= 3) [2, 3, 4].forEach((entry) => wanted.add(entry));
    if (page >= total - 2)
      [total - 1, total - 2, total - 3].forEach((entry) => wanted.add(entry));
    const sorted = [...wanted]
      .filter((entry) => entry >= 1 && entry <= total)
      .sort((a, b) => a - b);
    const out = [];
    sorted.forEach((entry, index) => {
      if (index && entry - sorted[index - 1] > 1) out.push("gap");
      out.push(entry);
    });
    return out;
  };

  const renderPagination = () => {
    const total = Math.max(1, Number(pagination?.total_pages) || 1);

    // Trust the currentPage state (which goToPage just updated),
    // but ensure it doesn't exceed the total pages.
    currentPage = Math.min(Math.max(1, currentPage), total);

    if (prevBtn) prevBtn.disabled = currentPage <= 1 || isLoading;
    if (nextBtn) nextBtn.disabled = currentPage >= total || isLoading;
    if (!pageNumbers) return;

    pageNumbers.innerHTML = "";
    pageWindow(currentPage, total).forEach((entry) => {
      if (entry === "gap") {
        const gap = document.createElement("span");
        gap.className = "pageGap";
        gap.textContent = "…";
        pageNumbers.appendChild(gap);
        return;
      }
      const button = document.createElement("button");
      button.type = "button";
      button.className = `pageNumber ${entry === currentPage ? "active" : ""}`;
      button.textContent = String(entry);
      button.disabled = isLoading;
      button.addEventListener("click", () => goToPage(entry));
      pageNumbers.appendChild(button);
    });
  };

  // ==========================================
  // RENDER: TABLE
  // ==========================================
  const renderTable = () => {
    if (!records.length) {
      setState(
        searchTerm ? "Nothing matches this search." : "No Records Found",
      );
      return;
    }
    if (noData) noData.style.display = "none";

    // Only the rows we hold can be compared, so this flags a shared grave that
    // happens to land on the same page. The chip below searches for the exact
    // total, which is the reliable answer.
    const sharing = new Map();
    records.forEach((record) => {
      const code = record.grave.grave_code;
      if (code) sharing.set(code, (sharing.get(code) || 0) + 1);
    });

    const writable = canModify();
    const today = todayISO();

    tableBody.innerHTML = records
      .map((record, index) => {
        const contactAddress = [record.contact.address, record.contact.barangay]
          .filter(Boolean)
          .join(", ");

        const code = record.grave.grave_code;
        const shared = code ? sharing.get(code) || 0 : 0;
        const overdue =
          record.lease_expiration_date &&
          record.lease_expiration_date < today &&
          record.status === "Active";

        const blockCell = `
          <div class="blockCell">
            <span>${cell(record.block.block_name)}</span>
            ${
              code
                ? `<button type="button" class="graveChip" data-action="grave" title="Show every record in grave ${escapeHtml(code)}">${escapeHtml(code)}</button>`
                : ""
            }
            ${
              shared > 1
                ? `<span class="sharedBadge" title="Another record on this page shares this grave">merged ×${shared}</span>`
                : ""
            }
          </div>`;

        const expiryCell = `${cell(record.lease_expiration_date)}
          <span class="rowChip ${slug(record.status)}">${escapeHtml(record.status)}</span>
          ${overdue ? '<span class="dayHint">lease already lapsed</span>' : ""}`;

        const lock = writable ? "" : 'disabled title="View only"';

        return `
          <tr data-index="${index}">
            <td>${cell(record.control_number)}</td>
            <td>${cell(record.deceased.name)}</td>
            <td>${cell(record.deceased.sex)}</td>
            <td>${cell(record.deceased.date_of_birth)}</td>
            <td>${cell(record.deceased.last_known_address)}</td>
            <td>${cell(record.date_buried)}</td>
            <td>${blockCell}</td>
            <td>${expiryCell}</td>
            <td>${cell(record.contact.name)}</td>
            <td>${cell(record.contact.phone_number)}</td>
            <td>${cell(contactAddress)}</td>
            <td>${cell(record.remarks)}</td>
            <td>
              <div class="actions">
                <button class="viewBtn" data-action="view" title="View details"><i class="fas fa-eye"></i></button>
                <button class="editBtn" data-action="edit" title="Edit record" ${lock}><i class="fas fa-edit"></i></button>
                <button class="mergeBtn" data-action="merge" title="Add another record to this same grave (merge)" ${
                  code ? lock : 'disabled title="No grave on this record"'
                }><i class="fas fa-layer-group"></i></button>
                <button class="deleteBtn" data-action="delete" title="Delete record" ${lock}><i class="fas fa-trash-alt"></i></button>
              </div>
            </td>
          </tr>`;
      })
      .join("");
  };

  // ==========================================
  // CONFIRM DIALOG
  // ==========================================
  const askConfirm = (title, message, confirmLabel) =>
    new Promise((resolve) => {
      const dialog = document.createElement("div");
      dialog.className = "rec_overlay";
      dialog.innerHTML = `
        <div class="rec_dialog">
          <div class="rec_title">${escapeHtml(title)}</div>
          <div class="rec_text">${message}</div>
          <div class="rec_actions">
            <button type="button" class="rec_cancel">Cancel</button>
            <button type="button" class="rec_confirm">${escapeHtml(confirmLabel)}</button>
          </div>
        </div>`;
      document.body.appendChild(dialog);

      const close = (answer) => {
        document.removeEventListener("keydown", onKey);
        dialog.remove();
        resolve(answer);
      };
      const onKey = (event) => {
        if (event.key === "Escape") {
          event.stopPropagation();
          close(false);
        }
      };

      dialog
        .querySelector(".rec_cancel")
        .addEventListener("click", () => close(false));
      dialog
        .querySelector(".rec_confirm")
        .addEventListener("click", () => close(true));
      dialog.addEventListener("click", (event) => {
        if (event.target === dialog) close(false);
      });
      document.addEventListener("keydown", onKey);
      dialog.querySelector(".rec_confirm").focus();
    });

  // ==========================================
  // MODAL PLUMBING
  // ==========================================
  const setBusy = (busy) => {
    if (paper) paper.classList.toggle("isBusy", Boolean(busy));
    if (saveBtn) saveBtn.disabled = Boolean(busy);
  };

  // Kept for the inline onclick in records.html, and reused for view mode.
  const setFormFieldsDisabled = (status) => {
    overlay.classList.toggle("view-mode", Boolean(status));
    overlay
      .querySelectorAll("input, select, .btnPaperSave")
      .forEach((element) => {
        element.disabled = Boolean(status);
      });
  };

  const lockGraveCode = (locked, reason) => {
    if (!graveInput) return;
    if (locked) graveInput.setAttribute("readonly", "readonly");
    else graveInput.removeAttribute("readonly");
    graveInput.style.backgroundColor = locked ? "#f8fafc" : "#ffffff";
    graveInput.title = locked ? reason || "" : "";
    if (graveHint) graveHint.textContent = locked ? reason || "" : "";
  };

  const MODE_LABEL = {
    create: "New record",
    merge: "Merging into an existing grave",
    edit: "Editing record",
    view: "Viewing record — read only",
  };

  const applyMode = () => {
    modeBanner.textContent = MODE_LABEL[mode] || "";
    if (statusCol)
      // "" restores whatever records.css gives a .col — do not force flex.
      statusCol.style.display =
        mode === "create" || mode === "merge" ? "none" : "";
    if (cancelBtn) cancelBtn.textContent = mode === "view" ? "Close" : "Cancel";
    if (saveBtn)
      saveBtn.textContent =
        mode === "edit"
          ? "Save Changes"
          : mode === "merge"
            ? "Save Merged Record"
            : "Save";
  };

  const renderGraveNotice = () => {
    if (!coBox) return;
    // Everyone in the grave except the record being edited.
    const occupants = occupantsOf(resolvedGrave).filter(
      (item) =>
        !activeRecord ||
        Number(item.interment_id) !== activeRecord.interment_id,
    );
    const isNew = mode === "create" || mode === "merge";

    // Nothing to say: no grave, a free grave, or an edit where this record is
    // the only body in it.
    if (
      !resolvedGrave ||
      !graveIsTaken(resolvedGrave) ||
      (!isNew && !occupants.length)
    ) {
      coBox.classList.add("isHidden");
      return;
    }

    coBox.classList.remove("isHidden");
    // A grave can read Occupied with nothing active filed against it (the last
    // body was exhumed). POST still refuses it, so cover both wordings.
    coNote.innerHTML = `Grave <strong>${escapeHtml(resolvedGrave.grave_code)}</strong>${
      resolvedGrave.block_name
        ? ` in ${escapeHtml(resolvedGrave.block_name)}`
        : ""
    } is marked <strong>${escapeHtml(resolvedGrave.status)}</strong>${
      occupants.length
        ? ` and already holds ${occupants.length} record${occupants.length === 1 ? "" : "s"}.`
        : ", though no active record is filed against it."
    }`;

    coOccupants.innerHTML = occupants
      .map(
        (item) =>
          `<li>${escapeHtml(item.deceased?.name || "Unnamed")} — ${escapeHtml(
            item.control_number || "no control no.",
          )}${item.lease_expiration_date ? ` · lease to ${escapeHtml(item.lease_expiration_date)}` : ""}</li>`,
      )
      .join("");

    coCheckWrap.style.display = isNew ? "flex" : "none";
  };

  const updateGraveNotice = async (force) => {
    const code = normalizeCode(graveInput ? graveInput.value : "");
    if (!code) {
      resolvedGrave = null;
      coBox.classList.add("isHidden");
      if (graveHint && mode !== "edit" && mode !== "view")
        graveHint.textContent = "Required — the record has to sit in a grave.";
      return;
    }
    if (
      resolvedGrave &&
      normalizeCode(resolvedGrave.grave_code) === code &&
      !force
    ) {
      renderGraveNotice();
      return;
    }
    resolvedGrave = await resolveGrave(code, force);
    if (normalizeCode(graveInput.value) !== code) return; // staff kept typing

    if (!resolvedGrave) {
      coBox.classList.add("isHidden");
      if (graveHint && mode !== "edit" && mode !== "view")
        graveHint.textContent = `No grave is coded “${code}”.`;
      return;
    }
    if (graveHint && mode !== "edit" && mode !== "view") {
      graveHint.textContent = `${resolvedGrave.block_name || "Unknown block"} · ${resolvedGrave.status}`;
    }
    // Keep Burial Type in step with the block the grave actually belongs to.
    const guessed = guessBurialType(resolvedGrave.block_name);
    if (guessed) setSelectValue(blockSelect, guessed);
    renderGraveNotice();
  };

  const openModal = () => {
    // display:block, not flex — .paperForm centres itself with margin: 0 auto.
    overlay.style.display = "block";
    document.body.style.overflow = "hidden";
  };

  const resetForm = () => {
    form.reset();
    setFormFieldsDisabled(false);
    setBusy(false);
    activeRecord = null;
    mergeSource = null;
    resolvedGrave = null;
    expirationTouched = false;
    if (coEnable) coEnable.checked = false;
    if (coBox) coBox.classList.add("isHidden");
    lockGraveCode(false);
    if (graveHint) graveHint.textContent = "";
    contactHint.textContent = "";
    graveList.innerHTML = "";
  };

  const closeModal = () => {
    overlay.style.display = "none";
    overlay.classList.remove("view-mode");
    document.body.style.overflow = "";
    resetForm();
    mode = "create";
  };

  // ==========================================
  // CONTROL NUMBERS
  // ==========================================
  const generateControlNumber = async () => {
    const year = new Date().getFullYear();
    for (let attempt = 0; attempt < 5; attempt += 1) {
      const candidate = `MEM-${year}-${String(Math.floor(Math.random() * 10000)).padStart(4, "0")}`;
      try {
        const result = await apiGet(
          `api/interments?control_number=${encodeURIComponent(candidate)}`,
        );
        if (!result.success) return candidate; // 404 means nothing is using it
      } catch (error) {
        return candidate; // offline: let the POST be the judge
      }
    }
    return `MEM-${year}-${String(Date.now()).slice(-5)}`;
  };

  // ==========================================
  // OPEN: CREATE / MERGE
  // ==========================================
  const openCreate = async (source) => {
    resetForm();
    mode = source ? "merge" : "create";
    mergeSource = source || null;
    applyMode();

    field("clearanceDate").value = todayISO();
    openModal();

    generateControlNumber().then((number) => {
      if (mode !== "create" && mode !== "merge") return;
      if (!text(controlNoInput.value)) controlNoInput.value = number;
    });

    if (!mergeSource) {
      if (graveHint)
        graveHint.textContent = "Required — the record has to sit in a grave.";
      return;
    }

    // Merging: pin the grave, pre-tick co-interment, and carry the family over.
    graveInput.value = mergeSource.grave.grave_code;
    lockGraveCode(
      true,
      `Merging into ${mergeSource.grave.grave_code}. Cancel and use + to file into a different grave.`,
    );
    if (coEnable) coEnable.checked = true;
    setSelectValue(assistanceSelect, ASSISTANCE_LABELS.Transfer);
    setSelectValue(
      blockSelect,
      guessBurialType(mergeSource.block.block_name) || blockSelect.value,
    );

    field("reqName").value = mergeSource.contact.name;
    field("reqPhone").value = mergeSource.contact.phone_number;
    field("reqStreet").value = mergeSource.contact.address;
    setSelectValue(field("requesting_barangay"), mergeSource.contact.barangay);

    if (graveHint) {
      graveHint.textContent = `Merging into ${mergeSource.grave.grave_code}. The requesting party was copied from ${
        mergeSource.deceased.name || "the existing record"
      } — change it if a different family is asking.`;
    }

    // Still fetch true grave occupancy notice (api/graves)
    setBusy(true);
    await updateGraveNotice(true);
    setBusy(false);
  };

  // ==========================================
  // OPEN: EDIT / VIEW
  // ==========================================
  const openEdit = async (record, readOnly) => {
    resetForm();
    mode = readOnly ? "view" : "edit";
    activeRecord = record;
    applyMode();

    // Map Interments Fields
    controlNoInput.value = record.control_number;
    field("clearanceDate").value = record.clearance_date;
    setSelectValue(
      assistanceSelect,
      ASSISTANCE_LABELS[record.assistance_type] || ASSISTANCE_LABELS.Other,
    );
    field("permitBurial").value = record.burial_permit_number;
    field("permitExhumation").value = record.exhumation_permit_number;
    field("permitTransfer").value = record.transfer_permit_number;
    field("dateInterment").value = record.date_buried;
    expirationInput.value = record.lease_expiration_date;
    expirationTouched = true; // never silently recompute a stored lease
    field("deceasedRemarks").value = record.remarks;

    // Map Grave/Block Status Fields
    graveInput.value = record.grave.grave_code;
    setSelectValue(
      blockSelect,
      guessBurialType(record.block.block_name) || blockSelect.value,
    );
    setSelectValue(statusSelect, record.status);

    // Map Deceased Details
    field("deceasedName").value = record.deceased.name;
    setSelectValue(field("deceasedSex"), record.deceased.sex);
    field("deceasedDob").value = record.deceased.date_of_birth;
    field("deceasedDod").value = record.deceased.date_of_death;
    field("deceasedCert").value = record.deceased.death_certificate;
    field("deceasedAddress").value = record.deceased.last_known_address;

    // Map Contact Details
    if (record.contact.contact_id) {
      field("reqName").value = record.contact.name;
      field("reqPhone").value = record.contact.phone_number;
      field("reqStreet").value = record.contact.address;
      setSelectValue(field("requesting_barangay"), record.contact.barangay);
    } else {
      contactHint.textContent =
        "This record has no linked requesting party, so contact details cannot be saved from here.";
    }

    // PUT api/interments never writes grave_id, so a record cannot change grave
    // from here. Say so instead of quietly dropping the edit.
    lockGraveCode(
      true,
      "A record cannot be moved to another grave from this form. Delete it and file it again, or use the merge button to add someone to this grave.",
    );

    openModal();
    if (mode === "view") setFormFieldsDisabled(true);

    // True occupant list for the grave (api/graves), so a merged set is visible while editing.
    setBusy(true);
    await updateGraveNotice(true);
    setBusy(false);
  };

  // ==========================================
  // SAVE
  // ==========================================
  const collectDeceased = () => ({
    name: text(field("deceasedName").value),
    // '' would break the ENUM insert.
    sex: text(field("deceasedSex").value) || "Unknown",
    date_of_birth: text(field("deceasedDob").value),
    date_of_death: text(field("deceasedDod").value),
    death_certificate: text(field("deceasedCert").value),
    last_known_address: text(field("deceasedAddress").value),
  });

  const collectContact = () => ({
    name: text(field("reqName").value),
    address: text(field("reqStreet").value),
    barangay: text(field("requesting_barangay").value),
    phone_number: text(field("reqPhone").value),
  });

  const createRecord = async () => {
    const code = normalizeCode(graveInput.value);
    let grave = resolvedGrave;
    if (!grave || normalizeCode(grave.grave_code) !== code) {
      grave = await resolveGrave(code);
      resolvedGrave = grave;
    }
    if (!grave) {
      notify(`No grave is coded “${code}”. Pick one from the list.`, "error");
      shake(["graveCode"]);
      return false;
    }
    if (graveIsTaken(grave) && !(coEnable && coEnable.checked)) {
      renderGraveNotice();
      notify(
        `Grave ${grave.grave_code} is already in use. Tick “Merge” to place this record alongside the existing one, or choose another grave.`,
        "warning",
        7000,
      );
      shake(["graveCode"]);
      coBox.scrollIntoView({ behavior: "smooth", block: "center" });
      return false;
    }

    const build = (controlNumber) => ({
      control_number: controlNumber,
      grave_code: grave.grave_code,
      grave_id: grave.grave_id,
      assistance_type: toAssistanceEnum(assistanceSelect.value),
      burial_permit_number: text(field("permitBurial").value),
      transfer_permit_number: text(field("permitTransfer").value),
      exhumation_permit_number: text(field("permitExhumation").value),
      date_buried: orNull(field("dateInterment").value),
      clearance_date: orNull(field("clearanceDate").value),
      lease_expiration_date: orNull(expirationInput.value),
      remarks: text(field("deceasedRemarks").value),
      is_co_interment: Boolean(coEnable && coEnable.checked),
      deceased: collectDeceased(),
      contact: collectContact(),
    });

    let controlNumber =
      text(controlNoInput.value) || (await generateControlNumber());
    let result = await apiSend("api/interments", "POST", build(controlNumber));

    // Two clerks can land on the same random number; take one more shot.
    if (
      !result.success &&
      /control number already exists/i.test(result.message || "")
    ) {
      controlNumber = await generateControlNumber();
      controlNoInput.value = controlNumber;
      result = await apiSend("api/interments", "POST", build(controlNumber));
    }

    if (!result.success) {
      notify(result.message || "The record could not be saved.", "error", 7000);
      return false;
    }

    graveCache.delete(normalizeCode(grave.grave_code));
    notify(
      `Record ${controlNumber} filed in grave ${grave.grave_code}.`,
      "success",
    );
    return true;
  };

  const updateRecord = async (record) => {
    const payload = {
      control_number: orNull(controlNoInput.value),
      assistance_type: toAssistanceEnum(assistanceSelect.value),
      burial_permit_number: text(field("permitBurial").value),
      transfer_permit_number: text(field("permitTransfer").value),
      exhumation_permit_number: text(field("permitExhumation").value),
      date_buried: orNull(field("dateInterment").value),
      clearance_date: orNull(field("clearanceDate").value),
      lease_expiration_date: orNull(expirationInput.value),
      status: text(statusSelect.value) || record.status,
      remarks: text(field("deceasedRemarks").value),
      deceased: collectDeceased(),
      contact: collectContact(),
    };

    const result = await apiSend(
      `api/interments/${record.interment_id}`,
      "PUT",
      payload,
    );

    if (!result.success) {
      notify(result.message || "Failed to update record", "error", 8000);
      return false;
    }

    notify("Record updated.", "success");
    return true;
  };

  const save = async () => {
    if (isSaving || mode === "view") return;
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    isSaving = true;
    setBusy(true);
    try {
      const saved =
        mode === "edit" && activeRecord
          ? await updateRecord(activeRecord)
          : await createRecord();
      if (!saved) return;
      const wasNew = mode !== "edit";
      closeModal();
      if (wasNew) currentPage = 1; // new rows sort to the top
      reload();
    } catch (error) {
      console.error("Save failed:", error.message);
      notify(
        `The record was not saved — ${failureText(error)}.`,
        "error",
        7000,
      );
    } finally {
      isSaving = false;
      setBusy(false);
    }
  };

  // ==========================================
  // DELETE
  // ==========================================
  const removeRecord = async (record) => {
    const confirmed = await askConfirm(
      "Delete this record?",
      `<strong>${escapeHtml(record.deceased.name || "This record")}</strong>
       (${escapeHtml(record.control_number || "no control no.")}) will be removed from the
       ledger and grave ${escapeHtml(record.grave.grave_code || "—")} will be freed.
       The deceased and contact entries are kept.`,
      "Delete record",
    );
    if (!confirmed) return;

    // Ask before deleting: the endpoint frees the grave whatever else is in it.
    const grave = await resolveGrave(record.grave.grave_code, true);
    const siblings = occupantsOf(grave).filter(
      (item) => Number(item.interment_id) !== record.interment_id,
    ).length;

    try {
      const result = await apiSend(
        `api/interments/${record.interment_id}`,
        "DELETE",
      );
      if (!result.success) {
        notify(
          result.message || "The record could not be deleted.",
          "error",
          7000,
        );
        return;
      }

      // DELETE always drops the grave to Vacant. If a merged record is still in
      // there, put the status back so the grave does not read as free.
      if (siblings > 0 && record.grave.grave_id) {
        const repair = await apiSend(
          `api/graves/${record.grave.grave_id}`,
          "PUT",
          {
            status: "Occupied",
          },
        ).catch(() => null);
        const fixed =
          repair?.success || /no changes detected/i.test(repair?.message || "");
        if (!fixed) {
          notify(
            `Deleted, but grave ${record.grave.grave_code} still holds ${siblings} record(s) and is now flagged Vacant — set it back to Occupied on the map.`,
            "warning",
            9000,
          );
        }
      }

      graveCache.delete(normalizeCode(record.grave.grave_code));
      notify("Record deleted.", "success");
      reload();
    } catch (error) {
      console.error("Delete failed:", error.message);
      notify(
        `The record was not deleted — ${failureText(error)}.`,
        "error",
        7000,
      );
    }
  };

  // ==========================================
  // EVENTS
  // ==========================================
  tableBody.addEventListener("click", (event) => {
    const row = event.target.closest("tr[data-index]");
    if (!row) return;
    const record = records[Number(row.dataset.index)];
    if (!record) return;

    // Look for a button click. If a button wasn't clicked, stop here.
    const button = event.target.closest("button[data-action]");
    if (!button || button.disabled) return;

    const action = button.dataset.action;

    if (action === "grave") {
      // The exact answer to "who else is in this grave?"
      if (searchInput) searchInput.value = record.grave.grave_code;
      searchTerm = record.grave.grave_code;
      currentPage = 1;
      loadRecords();
      return;
    }
    if (action === "delete") {
      removeRecord(record);
      return;
    }
    if (action === "merge") {
      openCreate(record);
      return;
    }
    if (action === "view") {
      openEdit(record, true);
      return;
    }

    // If the action is "edit", it falls through to here
    openEdit(record, !canModify());
  });

  const runSearch = () => {
    const value = text(searchInput ? searchInput.value : "");
    if (value === searchTerm) return;
    searchTerm = value;
    currentPage = 1;
    loadRecords();
  };

  if (searchInput) {
    searchInput.addEventListener("keydown", (event) => {
      if (event.key !== "Enter") return;
      event.preventDefault();
      clearTimeout(searchTimer);
      runSearch();
    });
    searchInput.addEventListener("input", () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(runSearch, 450);
    });
  }
  if (searchBtn) searchBtn.addEventListener("click", runSearch);

  if (prevBtn)
    prevBtn.addEventListener("click", () => goToPage(currentPage - 1));
  if (nextBtn)
    nextBtn.addEventListener("click", () => goToPage(currentPage + 1));

  const intermentDate = field("dateInterment");
  if (intermentDate) {
    const autoExpire = () => {
      if (expirationTouched) return;
      expirationInput.value = intermentDate.value
        ? addYears(intermentDate.value, LEASE_YEARS)
        : "";
    };
    intermentDate.addEventListener("input", autoExpire);
    intermentDate.addEventListener("change", autoExpire);
  }
  if (expirationInput) {
    expirationInput.addEventListener("input", () => {
      expirationTouched = true;
    });
  }

  if (graveInput) {
    // Live picker: api/graves?search= matches the code and returns the status
    // plus everyone already interred there.
    graveInput.addEventListener("input", () => {
      clearTimeout(graveTimer);
      const value = normalizeCode(graveInput.value);
      if (value.length < 2) {
        graveList.innerHTML = "";
        return;
      }
      graveTimer = setTimeout(async () => {
        try {
          const result = await apiGet(
            `api/graves?search=${encodeURIComponent(value)}`,
          );
          const graves = result.success ? result.data?.graves || [] : [];
          graves.forEach((grave) =>
            graveCache.set(normalizeCode(grave.grave_code), grave),
          );
          graveList.innerHTML = graves
            .map((grave) => {
              const held = occupantsOf(grave).length;
              const label = `${grave.block_name || "?"} · ${grave.status}${
                held ? ` · ${held} record${held === 1 ? "" : "s"}` : ""
              }`;
              return `<option value="${escapeHtml(grave.grave_code)}" label="${escapeHtml(label)}"></option>`;
            })
            .join("");
        } catch (error) {
          graveList.innerHTML = "";
        }
      }, 350);
    });
    graveInput.addEventListener("change", () => updateGraveNotice());
    graveInput.addEventListener("blur", () => updateGraveNotice());
  }

  if (coEnable) coEnable.addEventListener("change", renderGraveNotice);

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    save();
  });

  overlay.addEventListener("click", (event) => {
    if (event.target === overlay) closeModal();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    if (document.querySelector(".rec_overlay")) return; // the confirm owns Escape
    if (overlay.style.display === "block") closeModal();
  });

  if (addBtn && !canModify()) {
    addBtn.disabled = true;
    addBtn.title = "View only";
  }

  // Reuse the existing phone formatter from the original page.
  const phoneInput = field("reqPhone");
  if (phoneInput) {
    phoneInput.addEventListener("input", (event) => {
      let value = event.target.value.replace(/\D/g, "");
      if (value.length > 11) value = value.substring(0, 11);
      let formatted = "";
      if (value.length > 0) {
        formatted = value.substring(0, 4);
        if (value.length > 4) formatted += " " + value.substring(4, 7);
        if (value.length > 7) formatted += " " + value.substring(7, 11);
      }
      event.target.value = formatted;
    });
  }

  // ==========================================
  // GLOBALS THE HTML STILL CALLS
  // ==========================================
  window.openSeamlessModal = () => {
    if (!canModify()) {
      notify("Your role can view records but not add them.", "warning");
      return;
    }
    openCreate(null);
  };
  window.openModal = window.openSeamlessModal;
  window.closeSeamlessModal = closeModal;
  window.setFormFieldsDisabled = setFormFieldsDisabled;

  applyMode();
  loadRecords();
});
