/**
 * MONITOR — The Staging Area
 * ---------------------------------------------------------------------------
 * Every grave holding a live `grave_transitions` row (status 'Staged') appears
 * here as ONE table row with two sides:
 *
 *   left  (5 cols)  the INCOMING record — a 'Pending' interment filed from
 *                   Reserve, waiting to be finalized
 *   right (5 cols)  the CURRENT occupant(s), snapshotted when the grave was
 *                   staged, plus where their remains are going
 *
 * Action column:
 *   check   PUT    api/monitor  {action:'execute_transition', grave_id}
 *                  -> moves/exhumes the old remains, activates the new record
 *   edit    PUT    api/monitor/{interment_id} {action:'edit_pending', ...}
 *                  -> fix a typo on the pending record, or change where the
 *                     old remains are going
 *   delete  DELETE api/monitor/{interment_id}
 *                  -> cancel the reservation; the grave reverts to its prior
 *                     state (which is NOT always Vacant — the old occupants
 *                     are still in there)
 *
 * SMS: no composer lives in this file. assets/js/sendsms.js owns the modal and
 * the per-recipient templates. Rows only carry data-* attributes and one
 * delegated binding turns them into the right notice — the incoming family
 * gets `reservation_incoming`, the outgoing family gets `transfer_outgoing`.
 */
document.addEventListener("DOMContentLoaded", () => {
  // =========================================================
  // ELEMENTS & STATE
  // =========================================================
  const tableBody = document.getElementById("reservation");
  if (!tableBody) return;

  const burialModal = document.getElementById("burialModalOverlay");
  const burialForm = document.getElementById("burialClearanceForm");
  const btnCancel = document.getElementById("btnCancel");
  const btnSave = document.getElementById("btnSave");

  const canModify = ["Administrator", "Office Staff"].includes(
    localStorage.getItem("memoria_role"),
  );

  const COLUMN_COUNT = 12; // must match monitor.html's <thead>

  let stagingList = [];
  let pagination = null;
  let activeRow = null;
  let isSaving = false;

  // Search & Pagination State
  let currentPage = 1;
  let searchQuery = "";
  let searchTimeout = null;
  let defaultDataCache = {}; // Caches standard pages so clearing search is instantaneous

  // =========================================================
  // UTILITIES
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
  const text = (val) =>
    val === null || val === undefined ? "" : String(val).trim();
  const dash = (val) => (text(val) === "" ? "&mdash;" : escapeHTML(text(val)));
  const orNull = (val) => (text(val) === "" ? null : text(val));
  const toInputDate = (val) => (val ? String(val).slice(0, 10) : "");

  const formatDate = (val) => {
    if (!val) return "&mdash;";
    const parsed = new Date(`${toInputDate(val)}T00:00:00`);
    return Number.isNaN(parsed.getTime())
      ? escapeHTML(val)
      : parsed.toLocaleDateString("en-US", {
          month: "long",
          day: "2-digit",
          year: "numeric",
        });
  };

  const notify = (msg, type = "info") => {
    if (typeof showAlertTOP === "function") showAlertTOP(msg, type);
    else alert(`[${type.toUpperCase()}] ${msg}`);
  };

  const setSelectValue = (select, value) => {
    if (!select) return;
    const exists = Array.from(select.options).some(
      (opt) => opt.value === text(value),
    );
    if (!exists && text(value) !== "") {
      const opt = document.createElement("option");
      opt.value = opt.textContent = text(value);
      select.appendChild(opt);
    }
    select.value = text(value);
  };

  // interments.assistance_type is ENUM('Burial','Transfer','Other') but the
  // clearance form shows prose ("Transfer the remains of the late..."). Map by
  // prefix in both directions so neither side has to match the wording exactly.
  const toAssistanceEnum = (label) => {
    const value = text(label).toLowerCase();
    if (value.startsWith("transfer")) return "Transfer";
    if (value.startsWith("burial")) return "Burial";
    return "Other";
  };

  const selectAssistance = (select, enumValue) => {
    if (!select) return;
    const wanted = text(enumValue) || "Burial";
    const match = Array.from(select.options).find(
      (opt) => toAssistanceEnum(opt.value || opt.textContent) === wanted,
    );
    if (match) select.value = match.value;
  };

  // Human labels for grave_transitions.outgoing_destination.
  const DESTINATION_LABELS = {
    specific_grave: "Move to a specific grave",
    common_bone_chamber: "Common bone chamber",
    family_custody: "Released to the family",
    other: "Other arrangement",
    none: "Nothing to move",
  };

  const destinationSummary = (dest) => {
    if (!dest || !text(dest.type) || dest.type === "none") return "";
    const label = DESTINATION_LABELS[dest.type] || text(dest.type);
    const code = text(dest.grave_code);
    return code ? `${label} (${code})` : label;
  };

  // =========================================================
  // INJECT CONTROLS & STYLES
  // =========================================================
  (function injectStyles() {
    if (document.getElementById("monitorInjectedStyles")) return;
    const style = document.createElement("style");
    style.id = "monitorInjectedStyles";
    style.textContent = `
      /* UI/UX Fixed Table Layout */
      .tableContainer table { table-layout: fixed; width: 150%; }
      .tableContainer td, .tableContainer th { overflow: hidden; text-overflow: ellipsis; }

      /* Unified Top Control Bar */
      .monitorTopBar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }

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
      .monitorPagination { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
      .monitorSummary { font-size: 13px; color: #64748b; font-weight: 600; }
      .monitorPages { display: flex; gap: 6px; flex-wrap: wrap; }
      .monitorPages button { min-width: 34px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; background: #ffffff; color: #475569; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
      .monitorPages button:hover:not(:disabled):not(.active) { background: #f1f5f9; }
      .monitorPages button.active { background: #1e3a8a; border-color: #1e3a8a; color: #ffffff; cursor: default; }
      .monitorPages button:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }

      .graveCode { display: inline-block; margin-left: 6px; padding: 2px 6px; border-radius: 4px; background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 600; }
      .actions button:disabled { opacity: 0.4; cursor: not-allowed; }
      .stateCell { text-align: center !important; padding: 46px 20px !important; color: #94a3b8; font-size: 14px; font-weight: 600; }
      .occupantNote { margin: -6px 0 14px 0; padding: 10px 12px; border-left: 3px solid #f59e0b; background: #fffbeb; color: #92400e; font-size: 12px; font-weight: 600; border-radius: 4px; }
      .emptyRight { background: #f8fafc; color: #cbd5e1 !important; text-align: center; font-style: italic; }

      /* One grave can hold several sets of remains — every snapshotted occupant
         gets its own line inside the same cell so the row stays aligned. */
      .occStack { display: flex; flex-direction: column; gap: 4px; }
      .occLine { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .occStack .occLine + .occLine { padding-top: 4px; border-top: 1px dashed #e2e8f0; }
      .outCount { display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 99px; background: #fef3c7; color: #92400e; font-size: 10px; font-weight: 700; }
      .destBadge { display: block; margin-top: 5px; font-size: 11px; font-weight: 600; color: #b45309; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .destBadge i { margin-right: 4px; }
      .rowWarn { display: block; font-size: 11px; font-weight: 700; color: #b91c1c; }

      .smsBtn { color: #1e3a8a; background: none; border: none; cursor: pointer; padding: 0; font-weight: 600; font-family: inherit; display: inline-flex; align-items: center; gap: 5px; max-width: 100%; }
      .smsBtn:hover { text-decoration: underline; color: #1d4ed8; }
      .smsBtn span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

      /* Loaders */
      .skeletonBox { height: 12px; border-radius: 6px; background: linear-gradient(90deg, #eef2f7 25%, #e2e8f0 37%, #eef2f7 63%); background-size: 400% 100%; animation: monitorShimmer 1.4s ease infinite; }
      @keyframes monitorShimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
    `;
    document.head.appendChild(style);
  })();

  (function injectTopControls() {
    const container = document.querySelector(".tableContainer");
    if (!container || document.getElementById("monitorTopBar")) return;

    const topBar = document.createElement("div");
    topBar.className = "monitorTopBar";
    topBar.id = "monitorTopBar";
    topBar.innerHTML = `
      <div class="searchBox" id="monitorSearchBox">
        <input type="text" id="monitorSearchInput" placeholder="Search name, code, or contact..." autocomplete="off" />
        <i class="fas fa-search searchIcon"></i>
        <button class="clearBtn" id="monitorSearchClear" title="Clear search"><i class="fas fa-times"></i></button>
      </div>
      <div class="monitorPagination" id="monitorPagination">
        <span class="monitorSummary" id="monitorSummary"></span>
        <span class="monitorPages" id="monitorPages"></span>
      </div>
    `;

    container.insertAdjacentElement("beforebegin", topBar);
  })();

  (function injectOccupantSection() {
    if (!burialForm || document.getElementById("occupantWrap")) return;
    const wrap = document.createElement("div");
    wrap.id = "occupantWrap";
    wrap.innerHTML = `
      <div class="sectionTitle">CURRENT OCCUPANT TRANSFER</div>
      <div class="occupantNote" id="occupantNote">Where are the current remains going?</div>
      <div class="row">
        <div class="col">
          <label>Transfer Type:</label>
          <select id="occupantType">
            <option value="common_bone_chamber">Common Bone Chamber</option>
            <option value="specific_grave">Specific Grave</option>
            <option value="family_custody">Family Custody</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="col" id="occupantGraveCol">
          <label>Destination Grave Code:</label>
          <input type="text" id="occupantGrave" placeholder="E.g., A-12" />
        </div>
      </div>
      <div class="row">
        <div class="col"><label>Notes:</label><input type="text" id="occupantNotes" /></div>
      </div>
    `;
    const remarksTitle = Array.from(
      burialForm.querySelectorAll(".sectionTitle"),
    ).find((n) => n.textContent.trim().toUpperCase() === "REMARKS");
    if (remarksTitle) remarksTitle.insertAdjacentElement("beforebegin", wrap);
  })();

  // `visibility: hidden` left a dead gap in the row; collapse the column instead.
  const syncDestinationField = () => {
    const type = document.getElementById("occupantType")?.value;
    const col = document.getElementById("occupantGraveCol");
    if (col) col.style.display = type === "specific_grave" ? "" : "none";
  };
  document
    .getElementById("occupantType")
    ?.addEventListener("change", syncDestinationField);

  // =========================================================
  // DATA LOADING, CACHING & RENDERING
  // =========================================================
  const renderSkeleton = () => {
    tableBody.innerHTML = Array(5)
      .fill(0)
      .map(
        () => `
        <tr style="pointer-events:none;">
          ${Array(COLUMN_COUNT)
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

  const fetchMonitor = async () => {
    // Return cached page instantly if we aren't searching
    if (!searchQuery && defaultDataCache[currentPage]) {
      return { fromCache: true, data: defaultDataCache[currentPage] };
    }

    const query = new URLSearchParams({ page: currentPage });
    if (searchQuery) query.append("search", searchQuery);

    const res = await fetch(`api/monitor?${query.toString()}`);
    const result = await res.json();

    if (!result.success) throw new Error(result.message);

    // Cache standard pages
    if (!searchQuery) defaultDataCache[currentPage] = result.data;

    return { fromCache: false, data: result.data };
  };

  const loadAll = async () => {
    renderSkeleton();
    const fetchStartTime = Date.now();

    try {
      const result = await fetchMonitor();

      // Delay loader slightly only if hitting the network, ensuring the skeleton is visible
      if (!result.fromCache) {
        const elapsed = Date.now() - fetchStartTime;
        if (elapsed < 800) {
          await new Promise((resolve) => setTimeout(resolve, 800 - elapsed));
        }
      }

      stagingList = result.data.staging_list || [];
      pagination = result.data.pagination || null;

      // The API clamps `page` to the real last page. Without adopting the value
      // back, a search that shrinks the result set left the footer claiming
      // "page 4 of 1" while showing page 1's rows.
      if (pagination?.current_page) currentPage = pagination.current_page;

      renderTable();
      renderPagination();
    } catch (err) {
      tableBody.innerHTML = `<tr><td colspan="${COLUMN_COUNT}" class="stateCell" style="color:#dc2626">${escapeHTML(err.message)}</td></tr>`;
      pagination = null;
      renderPagination();
    }
  };

  /**
   * Build one phone cell. The payload rides along as data-* attributes so
   * sendsms.js can pick the right template — no onclick="" interpolation, which
   * is what broke every name containing an apostrophe.
   */
  const smsCell = (record, template, extra = {}) => {
    const phone = text(record?.contact?.phone_number);
    if (!phone) return "&mdash;";

    const data = {
      contact_name: text(record.contact?.name),
      deceased_name: text(record.deceased?.name),
      grave_code: text(record.grave?.grave_code),
      block_name: text(record.block?.block_name),
      lease_expiration_date: text(record.lease_expiration_date),
      date_buried: text(record.date_buried),
      control_number: text(record.control_number),
      ...extra,
    };

    return `<button type="button" class="smsBtn" data-sms
      data-phone="${escapeHTML(phone)}"
      data-sms-template="${escapeHTML(template)}"
      data-sms-data="${escapeHTML(JSON.stringify(data))}"
      title="Compose an SMS to ${escapeHTML(data.contact_name || "this contact")}"
    ><i class="fas fa-comment-sms"></i> <span>${escapeHTML(phone)}</span></button>`;
  };

  /** "123 Street, Barangay" from whichever halves exist. */
  const joinAddress = (...parts) =>
    parts
      .map((p) => text(p))
      .filter((p) => p !== "")
      .join(", ");

  const renderTable = () => {
    if (!stagingList.length) {
      tableBody.innerHTML = `<tr><td colspan="${COLUMN_COUNT}" class="stateCell">${searchQuery ? `No records matched "${escapeHTML(searchQuery)}".` : "No graves are currently in transition."}</td></tr>`;
      return;
    }

    tableBody.innerHTML = stagingList
      .map((item, index) => {
        const inc = item.incoming_occupant;
        const trans = item.transition || {};
        const dest = trans.destination || inc?.old_occupant_destination || null;

        // `outgoing_occupants` is the real list; `outgoing_occupant` is only the
        // back-compat alias for the first one.
        const outs = Array.isArray(item.outgoing_occupants)
          ? item.outgoing_occupants
          : item.outgoing_occupant
            ? [item.outgoing_occupant]
            : [];

        const lockedAttrs = canModify ? "" : ' disabled title="View only"';
        const noIncoming = !inc;
        const incId = inc?.interment_id ?? "";

        const stack = (mapper) =>
          `<div class="occStack">${outs
            .map((o) => `<div class="occLine">${mapper(o)}</div>`)
            .join("")}</div>`;

        const countBadge =
          outs.length > 1
            ? `<span class="outCount">${outs.length} sets of remains</span>`
            : "";

        const destBadge = destinationSummary(dest)
          ? `<span class="destBadge" title="Destination for the current remains"><i class="fas fa-arrow-right-arrow-left"></i>${escapeHTML(destinationSummary(dest))}</span>`
          : `<span class="destBadge" style="color:#b91c1c" title="Finalizing will be refused until a destination is set"><i class="fas fa-triangle-exclamation"></i>No destination set</span>`;

        const rightSide = outs.length
          ? `
        <td class="buriedBg">${stack((o) => dash(o.deceased?.name))}${countBadge}${destBadge}</td>
        <td class="buriedBg">${stack((o) => dash(o.deceased?.last_known_address))}</td>
        <td class="buriedBg">${stack((o) => dash(o.contact?.name))}</td>
        <td class="buriedBg">${stack((o) =>
          smsCell(o, "transfer_outgoing", {
            destination_type: text(dest?.type),
            destination_grave_code: text(dest?.grave_code),
          }),
        )}</td>
        <td class="buriedBg">${stack((o) =>
          dash(joinAddress(o.contact?.address, o.contact?.barangay)),
        )}</td>
      `
          : `
        <td colspan="5" class="buriedBg emptyRight">Grave was vacant &mdash; nothing to move out</td>
      `;

        const stagedTip = [
          trans.transition_id ? `Transition #${trans.transition_id}` : "",
          trans.staged_by ? `staged by ${trans.staged_by}` : "",
          trans.staged_at ? `on ${toInputDate(trans.staged_at)}` : "",
          trans.prior_grave_status ? `(was ${trans.prior_grave_status})` : "",
        ]
          .filter(Boolean)
          .join(" · ");

        return `
        <tr data-index="${index}">
          <td>${dash(inc?.deceased?.name)}${noIncoming ? '<span class="rowWarn">Incoming record missing</span>' : ""}</td>
          <td class="block-text" title="${escapeHTML(stagedTip)}">${dash(item.grave.block_name)} <span class="graveCode">${escapeHTML(text(item.grave.grave_code))}</span></td>
          <td>${formatDate(inc?.lease_expiration_date)}</td>
          <td>${dash(inc?.contact?.name)}</td>
          <td>${inc ? smsCell(inc, "reservation_incoming") : "&mdash;"}</td>

          ${rightSide}

          <td>${dash(inc?.remarks)}</td>
          <td class="actions">
            <button class="checkBtn" data-act="finalize" data-grave-id="${item.grave.grave_id}"${lockedAttrs} title="Finalize: move the current remains out and activate the new record">
              <i class="fas fa-check"></i>
            </button>
            <button class="editBtn" data-act="edit" data-index="${index}"${noIncoming ? ' disabled title="Nothing to edit"' : lockedAttrs} title="Correct the incoming record or its destination">
              <i class="fas fa-edit"></i>
            </button>
            <button class="deleteBtn" data-act="cancel" data-interment-id="${incId}"${noIncoming ? ' disabled title="Nothing to cancel"' : lockedAttrs} title="Cancel this reservation">
              <i class="fas fa-trash"></i>
            </button>
          </td>
        </tr>
      `;
      })
      .join("");
  };

  const renderPagination = () => {
    const summary = document.getElementById("monitorSummary");
    const pages = document.getElementById("monitorPages");
    if (!summary || !pages) return;

    if (pagination) {
      const total = pagination.total_records ?? 0;
      summary.textContent = `Page ${currentPage} of ${Math.max(1, pagination.total_pages)} · ${total} grave${total === 1 ? "" : "s"} in transition`;
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

          // Instant visual feedback
          document
            .querySelectorAll(".monitorPages button")
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

  // =========================================================
  // SEARCH BINDINGS
  // =========================================================
  const searchInputEl = document.getElementById("monitorSearchInput");
  const searchClearBtn = document.getElementById("monitorSearchClear");
  const searchWrapEl = document.getElementById("monitorSearchBox");

  if (searchInputEl) {
    searchInputEl.addEventListener("input", (e) => {
      const val = e.target.value;
      if (searchWrapEl)
        searchWrapEl.classList.toggle("hasText", val.length > 0);

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

      loadAll(); // Bypasses API due to caching!
      searchInputEl.focus();
    });
  }

  // =========================================================
  // ACTIONS: FINALIZE & CANCEL
  // =========================================================
  const finalizeTransition = async (graveId, button) => {
    if (!canModify || !graveId) return;
    if (
      !confirm(
        "Finalize this transition?\n\nThe current remains will be moved to their recorded destination and the new record becomes the active occupant. This cannot be undone from here.",
      )
    )
      return;

    if (button) button.disabled = true;
    try {
      const res = await fetch("api/monitor", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "execute_transition",
          grave_id: graveId,
        }),
      });
      const result = await res.json();
      if (result.success) {
        const moved = result.data?.outgoing_updated;
        notify(
          `Transition complete. The grave is now ${result.data?.grave_status || "updated"}${moved ? `, ${moved} record(s) moved out` : ""}.`,
          "success",
        );
        defaultDataCache = {}; // Clear cache on change
        loadAll();
      } else {
        if (button) button.disabled = false;
        notify(result.message || "Failed to execute transition.", "error");
      }
    } catch (err) {
      if (button) button.disabled = false;
      notify("A network error occurred.", "error");
    }
  };

  const cancelTransition = async (intermentId, button) => {
    if (!canModify || !intermentId) return;
    if (
      !confirm(
        "Cancel this pending reservation?\n\nThe pending record is removed and the grave reverts to its previous state — if it still holds remains it goes back to Occupied, not Vacant.",
      )
    )
      return;

    if (button) button.disabled = true;
    try {
      const res = await fetch(`api/monitor/${intermentId}`, {
        method: "DELETE",
      });
      const result = await res.json();
      if (result.success) {
        notify(result.message || "Pending reservation cancelled.", "success");
        defaultDataCache = {}; // Clear cache on change
        loadAll();
      } else {
        if (button) button.disabled = false;
        notify(result.message || "Failed to cancel reservation.", "error");
      }
    } catch (err) {
      if (button) button.disabled = false;
      notify("A network error occurred.", "error");
    }
  };

  // =========================================================
  // ACTIONS: EDIT MODAL
  // =========================================================
  const field = (id) => document.getElementById(id);

  const openEditModal = (index) => {
    activeRow = stagingList[index];
    if (!activeRow) return;
    const inc = activeRow.incoming_occupant;
    if (!inc) {
      notify("This transition has no incoming record to edit.", "error");
      return;
    }

    const outs = Array.isArray(activeRow.outgoing_occupants)
      ? activeRow.outgoing_occupants
      : [];
    const dest =
      activeRow.transition?.destination || inc.old_occupant_destination || null;

    // ---- Clearance header -----------------------------------------
    field("controlNo").value = text(inc.control_number);
    field("clearanceDate").value = toInputDate(inc.clearance_date);

    // ---- Requesting party (contacts) ------------------------------
    field("reqName").value = text(inc.contact?.name);
    field("reqStreet").value = text(inc.contact?.address);
    setSelectValue(field("requesting_barangay"), inc.contact?.barangay);
    field("reqPhone").value = text(inc.contact?.phone_number);
    selectAssistance(field("reqAssistance"), inc.assistance_type);

    // ---- Deceased -------------------------------------------------
    field("deceasedName").value = text(inc.deceased?.name);
    setSelectValue(field("deceasedSex"), inc.deceased?.sex);
    field("deceasedDob").value = toInputDate(inc.deceased?.date_of_birth);
    field("deceasedAddress").value = text(inc.deceased?.last_known_address);
    field("deceasedDod").value = toInputDate(inc.deceased?.date_of_death);
    field("deceasedCert").value = text(inc.deceased?.death_certificate);

    // ---- Permits --------------------------------------------------
    field("permitBurial").value = text(inc.burial_permit_number);
    field("permitExhumation").value = text(inc.exhumation_permit_number);
    field("permitTransfer").value = text(inc.transfer_permit_number);

    // ---- Location (fixed by the transition, so read-only here) ----
    // Monitor may not move a staged record to a different grave: the staging
    // lock, the outgoing snapshot and the destination all hang off THIS grave.
    // Cancel and re-file from Reserve to change it.
    const blockSelect = field("burialBlock");
    setSelectValue(blockSelect, text(activeRow.grave.block_name) || "—");
    blockSelect.disabled = true;
    field("graveCode").value = text(activeRow.grave.grave_code);
    field("graveCode").readOnly = true;

    field("dateInterment").value = toInputDate(inc.date_buried);
    field("expirationDate").value = toInputDate(inc.lease_expiration_date);
    field("deceasedRemarks").value = text(inc.remarks);

    // ---- Current-occupant destination -----------------------------
    const wrap = field("occupantWrap");
    if (outs.length) {
      wrap.style.display = "block";
      const names = outs
        .map((o) => text(o.deceased?.name) || "an unnamed record")
        .join(", ");
      field("occupantNote").textContent =
        `${outs.length} set(s) of remains are still in ${text(activeRow.grave.grave_code)} (${names}). Say where they are going — finalizing is refused until this is set.`;
      setSelectValue(
        field("occupantType"),
        text(dest?.type) && dest.type !== "none"
          ? dest.type
          : "common_bone_chamber",
      );
      field("occupantGrave").value = text(dest?.grave_code);
      field("occupantNotes").value = text(dest?.notes);
      syncDestinationField();
    } else {
      wrap.style.display = "none";
    }

    burialModal.style.display = "block";
    document.body.style.overflow = "hidden";
  };

  const closeEditModal = () => {
    burialModal.style.display = "none";
    document.body.style.overflow = "auto";
    burialForm.reset();
    const blockSelect = field("burialBlock");
    if (blockSelect) blockSelect.disabled = false;
    activeRow = null;
  };

  btnCancel?.addEventListener("click", closeEditModal);

  btnSave?.addEventListener("click", async () => {
    if (isSaving || !activeRow) return;
    const inc = activeRow.incoming_occupant;
    if (!inc) return;

    if (text(field("deceasedName").value) === "") {
      notify("The name of the deceased is required.", "error");
      return;
    }
    if (text(field("reqName").value) === "") {
      notify("The requesting party's name is required.", "error");
      return;
    }

    // Every key present here is a key the API will write. Keys the modal does
    // not show (e.g. contact.email_address) are deliberately omitted so the
    // present-key semantics on the server leave them alone. control_number is
    // read-only and auto-generated, so it is never sent.
    const payload = {
      action: "edit_pending",
      interment_id: inc.interment_id,
      clearance_date: orNull(field("clearanceDate").value),
      date_buried: orNull(field("dateInterment").value),
      lease_expiration_date: orNull(field("expirationDate").value),
      assistance_type: toAssistanceEnum(field("reqAssistance").value),
      remarks: text(field("deceasedRemarks").value),
      burial_permit_number: text(field("permitBurial").value),
      exhumation_permit_number: text(field("permitExhumation").value),
      transfer_permit_number: text(field("permitTransfer").value),
      deceased: {
        name: text(field("deceasedName").value),
        sex: text(field("deceasedSex").value),
        date_of_birth: orNull(field("deceasedDob").value),
        date_of_death: orNull(field("deceasedDod").value),
        death_certificate: text(field("deceasedCert").value),
        last_known_address: text(field("deceasedAddress").value),
      },
      contact: {
        name: text(field("reqName").value),
        phone_number: text(field("reqPhone").value),
        address: text(field("reqStreet").value),
        barangay: text(field("requesting_barangay").value),
      },
    };

    const outs = Array.isArray(activeRow.outgoing_occupants)
      ? activeRow.outgoing_occupants
      : [];

    if (outs.length) {
      const type = field("occupantType").value;
      // specific_grave is editable now: the API resolves grave_code -> grave_id
      // and re-checks the destination is still able to take the remains.
      if (
        type === "specific_grave" &&
        text(field("occupantGrave").value) === ""
      ) {
        notify("Enter the destination grave code.", "error");
        return;
      }
      if (type === "other" && text(field("occupantNotes").value) === "") {
        notify("Notes are required when the destination is 'Other'.", "error");
        return;
      }
      payload.old_occupant_destination = {
        type,
        grave_code: text(field("occupantGrave").value),
        notes: text(field("occupantNotes").value),
      };
    }

    isSaving = true;
    btnSave.textContent = "Saving...";

    try {
      const res = await fetch(`api/monitor/${inc.interment_id}`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (data.success) {
        notify("Pending reservation updated.", "success");
        defaultDataCache = {}; // Clear cache on change
        closeEditModal();
        loadAll();
      } else {
        notify(data.message || "Failed to save.", "error");
      }
    } catch (err) {
      notify("Network error occurred.", "error");
    } finally {
      isSaving = false;
      btnSave.textContent = "Save";
    }
  });

  // =========================================================
  // DELEGATED ROW ACTIONS
  // =========================================================
  // One listener on the tbody. The old build wrote onclick="..." strings with
  // HTML-escaped names inside single quotes, so any apostrophe closed the JS
  // string and killed the handler.
  tableBody.addEventListener("click", (event) => {
    const button = event.target.closest("[data-act]");
    if (!button || button.disabled) return;

    if (button.dataset.act === "finalize") {
      finalizeTransition(Number(button.dataset.graveId), button);
    } else if (button.dataset.act === "edit") {
      openEditModal(Number(button.dataset.index));
    } else if (button.dataset.act === "cancel") {
      cancelTransition(Number(button.dataset.intermentId), button);
    }
  });

  // =========================================================
  // SMS  —  one delegated binding, templates live in sendsms.js
  // =========================================================
  if (typeof bindSmsButtons === "function") {
    bindSmsButtons(tableBody, (button) => {
      let data = {};
      try {
        data = JSON.parse(button.dataset.smsData || "{}");
      } catch (err) {
        data = {};
      }

      const template = button.dataset.smsTemplate || "custom";
      const context = [];
      if (data.deceased_name)
        context.push({ label: "Deceased", value: data.deceased_name });
      const place = [data.block_name, data.grave_code]
        .filter(Boolean)
        .join(" ");
      if (place) context.push({ label: "Grave", value: place });
      if (template === "reservation_incoming" && data.control_number)
        context.push({ label: "Control No.", value: data.control_number });
      if (template === "transfer_outgoing") {
        const summary = destinationSummary({
          type: data.destination_type,
          grave_code: data.destination_grave_code,
        });
        if (summary) context.push({ label: "Destination", value: summary });
      }

      return { phone: button.dataset.phone, template, data, context };
    });
  } else {
    console.error(
      "monitor.js: assets/js/sendsms.js is not loaded — phone numbers will not open the composer.",
    );
  }

  // =========================================================
  // BOOT
  // =========================================================
  loadAll();
});
