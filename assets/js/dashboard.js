window.graveStatusChart = null;
window.monthlyExpirationChart = null;

window.initDashboardCharts = function (pieDataValues, barDataValues) {
  const pieCanvas = document.getElementById("graveStatusChart");
  if (pieCanvas) {
    const ctxPie = pieCanvas.getContext("2d");
    window.graveStatusChart = new Chart(ctxPie, {
      type: "pie",
      data: {
        labels: ["Vacant", "Occupied", "Expiring", "Expired", "Reserved"],
        datasets: [
          {
            data: pieDataValues,
            backgroundColor: [
              "#10B981",
              "#3B82F6",
              "#F59E0B",
              "#EF4444",
              "#8B5CF6",
            ],
            borderWidth: 1,
            hoverOffset: 15,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: 20 },
        // Correct v3+ animation syntax
        animation: {
          duration: 1500,
          easing: "easeOutQuart",
        },
        plugins: {
          legend: {
            position: "bottom",
            labels: { padding: 16, usePointStyle: true, pointStyle: "circle" },
          },
        },
      },
    });
  }

  const barCanvas = document.getElementById("monthlyExpirationChart");
  if (barCanvas) {
    const ctxBar = barCanvas.getContext("2d");
    const barGradient = ctxBar.createLinearGradient(0, 0, 0, 320);
    barGradient.addColorStop(0, "#6366F1");
    barGradient.addColorStop(1, "#A5B4FC");

    window.monthlyExpirationChart = new Chart(ctxBar, {
      type: "bar",
      data: {
        labels: [
          "Jan",
          "Feb",
          "Mar",
          "Apr",
          "May",
          "Jun",
          "Jul",
          "Aug",
          "Sep",
          "Oct",
          "Nov",
          "Dec",
        ],
        datasets: [
          {
            label: "Expirations",
            data: barDataValues,
            backgroundColor: barGradient,
            borderRadius: 6,
            borderSkipped: false,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        // Correct v3+ animation syntax
        animation: {
          duration: 1200,
          easing: "easeOutQuart",
        },
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 12 } } },
          y: {
            grid: { color: "#F3F4F6" },
            beginAtZero: true,
            ticks: { stepSize: 5 },
          },
        },
      },
    });
  }
};

// Helper function to smoothly hide the entire .statCard
function hideStatCard(cardId) {
  const element = document.getElementById(cardId);
  if (element) {
    const statCard = element.closest(".statCard");

    // Only animate if it hasn't been hidden yet
    if (statCard && statCard.style.display !== "none") {
      // 1. Add smooth transition properties
      statCard.style.transition = "all 0.4s ease-out";
      statCard.style.overflow = "hidden"; // Prevents content from spilling during shrink

      // 2. Fade out and scale down slightly
      statCard.style.opacity = "0";
      statCard.style.transform = "scale(0.9)";

      // 3. Wait for the fade, then collapse the physical space smoothly
      setTimeout(() => {
        statCard.style.width = "0px";
        statCard.style.margin = "0px";
        statCard.style.padding = "0px";
        statCard.style.border = "none";

        // 4. Finally, remove it from the document flow completely
        setTimeout(() => {
          statCard.style.display = "none";
        }, 400);
      }, 300); // Starts collapsing just before the fade is completely done
    }
  }
}

async function loadDashboardData() {
  try {
    const response = await fetch("api/dashboard");
    const result = await response.json();

    if (response.ok) {
      const data = result.data;

      const statCards = [
        { id: "stat-total-interments", key: "total_interment_records" },
        { id: "stat-available-graves", key: "available_graves" },
        { id: "stat-expiring-leases", key: "expiring_leases_count" },
        { id: "stat-unverified-accounts", key: "unverified_accounts" },
        { id: "stat-pending-clearances", key: "payments" },
      ];

      statCards.forEach(({ id, key }) => {
        const element = document.getElementById(id);
        if (!element) return;

        const value = data[key];

        if (value !== undefined && value !== null) {
          // Data exists: Remove skeleton, add fade-in, and set the text
          element.classList.remove("skeleton");
          element.classList.add("fade-in");

          if (
            key === "payments" &&
            value.pending_office !== undefined &&
            value.pending_grounds !== undefined
          ) {
            const totalPendingTasks =
              value.pending_office + value.pending_grounds;
            element.textContent = totalPendingTasks;
          } else if (key !== "payments") {
            element.textContent = value;
          } else {
            // Data format is unexpected, hide the card smoothly
            hideStatCard(id);
          }
        } else {
          // Data is completely missing, hide the card smoothly
          hideStatCard(id);
        }
      });

      const dist = data.grave_status_distribution || {};
      const pieData = [
        dist["Vacant"] || 0,
        dist["Occupied"] || 0,
        dist["Expiring"] || 0,
        dist["Expired"] || 0,
        dist["Reserved"] || 0,
      ];

      const monthlyExp = data.monthly_lease_expiration || {};
      const barData = Object.values(monthlyExp);

      window.initDashboardCharts(pieData, barData);
    } else {
      console.error("Dashboard API Error:", result.message);
    }
  } catch (error) {
    console.error("Failed to load dashboard data:", error);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  loadDashboardData();
});
