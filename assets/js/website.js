document.addEventListener("DOMContentLoaded", () => {
  initNavigation();
  initHomeView();
  initSearchView();
  initPaymentView();
  //   initHoursModal();
  initRecordModal();
});

function showView(viewId) {
  const views = document.querySelectorAll(".mView");
  views.forEach((view) => view.classList.remove("active"));

  const targetView = document.getElementById(viewId);
  if (targetView) {
    targetView.classList.add("active");
  }

  const navLinks = document.querySelectorAll("nav .navLink");
  navLinks.forEach((link) => link.classList.remove("active"));

  if (viewId === "searchView") {
    document.getElementById("navSearchView")?.classList.add("active");
  } else if (viewId === "mapView") {
    document.getElementById("navMapView")?.classList.add("active");
  } else if (viewId === "paymentView") {
    document.getElementById("navPaymentView")?.classList.add("active");
  } else if (viewId === "homeView") {
    document.querySelector('nav a[href="#home"]')?.classList.add("active");
  }
}

function initNavigation() {
  const navLinks = document.querySelectorAll("nav .navLink");

  navLinks.forEach((link) => {
    link.addEventListener("click", (e) => {
      const href = link.getAttribute("href");

      if (href === "#home") {
        e.preventDefault();
        showView("homeView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (href === "#search") {
        e.preventDefault();
        showView("searchView");
      } else if (href === "#map") {
        e.preventDefault();
        showView("mapView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (href === "#payment") {
        e.preventDefault();
        showView("paymentView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    });
  });
}

function initHomeView() {
  const findLovedOneBtn = document.querySelector(".findLovedOneBtn");
  if (findLovedOneBtn) {
    findLovedOneBtn.addEventListener("click", () => showView("searchView"));
  }

  const cemeteryMapBtns = document.querySelectorAll(".cemeteryMapBtn");
  cemeteryMapBtns.forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      showView("mapView");
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  });

  const cards = document.querySelectorAll(".cardGrid .card");
  cards.forEach((card) => {
    card.addEventListener("click", () => {
      const title = card.querySelector("h4")?.textContent.trim();

      if (title === "Search") {
        showView("searchView");
      } else if (title === "Cemetery Map") {
        showView("mapView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (title === "Payments") {
        showView("paymentView");
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (title === "Burial Requirements") {
        showView("homeView");
        const section = document.getElementById("burialRequirements");
        if (section) {
          section.scrollIntoView({ behavior: "smooth", block: "start" });
        }
      }
    });
  });
}
function initSearchView() {
  const searchForm = document.querySelector("#searchView form");
  const recordsGrid = document.querySelector(".recordsCardGrid");
  const searchInput = searchForm?.querySelector("input");

  if (!searchForm || !recordsGrid) return;

  recordsGrid.innerHTML = "";

  searchForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    // No need to lowercase here, let the database handle the case-insensitive search
    const query = searchInput.value.trim();

    if (!query) {
      recordsGrid.innerHTML = "";
      return;
    }

    // Show a loading state while waiting for the database
    recordsGrid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 2rem;">Searching records...</p>`;

    try {
      // Assuming your REST-ish setup routes 'api/search' to 'search.php'
      const response = await fetch(`api/search?q=${encodeURIComponent(query)}`);

      if (!response.ok) {
        if (response.status === 404) {
          recordsGrid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; padding: 2rem;">Record not found. Perhaps try to be very specific. Contact the office if certain that the records are accurate</p>`;
          return;
        }
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const results = await response.json();

      // Pass the fetched JSON directly to your existing render function
      renderRecords(results.data, recordsGrid);
    } catch (error) {
      console.error("Error fetching records:", error);
      recordsGrid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #ef4444; padding: 2rem;">Failed to connect to the database. Please try again.</p>`;
    }
  });
}

function formatDate(dateString) {
  if (!dateString) return "N/A";
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return dateString;

  return date.toLocaleDateString("en-US", {
    month: "long",
    day: "numeric",
    year: "numeric",
  });
}

function renderRecords(records, container) {
  container.innerHTML = "";

  if (records.length === 0) {
    container.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 2rem;">No matching records found.</p>`;
    return;
  }

  records.forEach((record) => {
    const card = document.createElement("div");
    card.className = "card";

    card.innerHTML = `
            <h4>${record.name}</h4>
            <p><strong>Born:</strong> ${formatDate(record.dateOfBirth)}</p>
            <p><strong>Died:</strong> ${formatDate(record.dateOfDeath)}</p>
        `;

    card.addEventListener("click", () => openRecordModal(record));
    container.appendChild(card);
  });
}

function toggleOtherPurpose(selectElement) {
  const otherWrapper = document.getElementById("otherPurposeWrapper");
  const otherInput = document.getElementById("payOtherPurpose");

  if (!otherWrapper || !otherInput) return;

  if (selectElement.value === "Others") {
    otherWrapper.classList.remove("hidden");
    otherInput.setAttribute("required", "true");
  } else {
    otherWrapper.classList.add("hidden");
    otherInput.removeAttribute("required");
    otherInput.value = "";
  }
}

function initPaymentView() {
  const paymentForm = document.getElementById("paymentForm");
  const otherWrapper = document.getElementById("otherPurposeWrapper");

  if (paymentForm) {
    paymentForm.addEventListener("submit", (e) => {
      e.preventDefault();

      const formData = new FormData(paymentForm);
      const data = Object.fromEntries(formData.entries());

      console.log("Payment Record Submitted:", data);
      alert(
        "Thank you! Your payment details have been successfully submitted for verification.",
      );

      paymentForm.reset();
      if (otherWrapper) {
        otherWrapper.classList.add("hidden");
      }
    });
  }
}

// function closeHoursModal() {
//   const hoursModal = document.getElementById("hoursModal");
//   if (hoursModal) {
//     hoursModal.classList.add("hidden");
//   }
// }

// function closeHoursModalOnOverlay(e) {
//   const hoursModal = document.getElementById("hoursModal");
//   if (e.target === hoursModal) {
//     closeHoursModal();
//   }
// }

// function initHoursModal() {
//   const hoursModal = document.getElementById("hoursModal");
//   const triggers = document.querySelectorAll(".officeHoursTrigger");

//   updateCurrentDayHighlight();

//   triggers.forEach((trigger) => {
//     trigger.addEventListener("click", () => {
//       if (hoursModal) {
//         hoursModal.classList.remove("hidden");
//         updateCurrentDayHighlight();
//       }
//     });
//   });
// }

// function updateCurrentDayHighlight() {
//   const now = new Date();
//   const day = now.getDay();
//   const currentHour = now.getHours();

//   document
//     .querySelectorAll(".hoursItem")
//     .forEach((item) => item.classList.remove("activeDay"));

//   const dayElement = document.getElementById(`day_${day}`);
//   if (dayElement) {
//     dayElement.classList.add("activeDay");
//   }

//   const isOpen = day >= 1 && day <= 6 && currentHour >= 8 && currentHour < 17;
//   const statusTextElements = document.querySelectorAll(".modalStatusText");

//   statusTextElements.forEach((el) => {
//     if (isOpen) {
//       el.textContent = "Open Now";
//       el.classList.remove("closed");
//       el.classList.add("open");
//     } else {
//       el.textContent = "Closed Now";
//       el.classList.remove("open");
//       el.classList.add("closed");
//     }
//   });
// }

function initRecordModal() {
  const recordModal = document.getElementById("recordModal");
  const closeBtn = document.getElementById("closeModalBtn");

  if (!recordModal || !closeBtn) return;

  closeBtn.addEventListener("click", closeRecordModal);
  recordModal.addEventListener("click", (e) => {
    if (e.target === recordModal) closeRecordModal();
  });
}

function openRecordModal(record) {
  const recordModal = document.getElementById("recordModal");
  if (!recordModal) return;

  document.getElementById("modalName").textContent = record.name;
  document.getElementById("modalBorn").textContent = formatDate(
    record.dateOfBirth,
  );
  document.getElementById("modalDied").textContent = formatDate(
    record.dateOfDeath,
  );

  const locationStr = `Block ${record.block}, Row ${record.row}, Column ${record.column}`;

  document.getElementById("modalLocation").textContent = locationStr;
  document.getElementById("modalGraveType").textContent =
    record.graveType || "N/A";

  recordModal.classList.add("open");
}

function closeRecordModal() {
  const recordModal = document.getElementById("recordModal");
  if (recordModal) {
    recordModal.classList.remove("open");
  }
}
