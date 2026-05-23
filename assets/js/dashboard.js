document.addEventListener("DOMContentLoaded", () => {
  //dashboard.html
  fetch("api/dashboard.php")
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        document.getElementById("unverifiedaccounts").textContent =
          data.data.unverifiedCount;
      }
    })
    .catch((error) => console.error("Error fetching dashboard data:", error));

  // AUTO CURRENT YEAR
  const yearText = document.getElementById("currentYear");
  if (yearText) {
    const currentYear = new Date().getFullYear();
    yearText.textContent = "Year: " + currentYear;
  }

  // PIE CHART
  const pieCanvas = document.getElementById("pieChart");

  if (pieCanvas) {
    new Chart(pieCanvas, {
      type: "pie",
      data: {
        labels: ["Available", "Expiring", "Occupied"],
        datasets: [
          {
            data: [30, 60, 10],
            backgroundColor: ["#a8c29a", "#3366cc", "#ef4444"],
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,

        plugins: {
          legend: {
            position: "bottom",
            align: "center",
            labels: {
              usePointStyle: true,
              pointStyle: "circle",
              padding: 15,
              boxWidth: 10,
              boxHeight: 10,
              font: {
                size: 13,
              },
            },
          },
        },
      },
    });
  }

  // BAR GRAPH
  const barCanvas = document.getElementById("barGraph");

  if (barCanvas) {
    new Chart(barCanvas, {
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
            data: [12, 18, 35, 16, 22, 30, 25, 17, 40, 28, 33, 45],
            backgroundColor: "#ef4444",
            borderRadius: 5,
            barPercentage: 0.8,
            categoryPercentage: 0.9,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,

        plugins: {
          legend: {
            display: false,
          },
        },

        scales: {
          x: {
            grid: {
              display: false,
            },
            ticks: {
              color: "#64748b",
            },
          },
          y: {
            beginAtZero: true,
            grid: {
              color: "#e2e8f0",
            },
            ticks: {
              stepSize: 5,
              color: "#64748b",
            },
          },
        },
      },
    });
  }

  // NOTIFICATION
  const tableBody = document.querySelector("table tbody");
  if (tableBody) {
    tableBody.addEventListener("click", (e) => {
      const cell = e.target.closest(".contactNum");
      if (!cell) return;

      const isNotified = cell.getAttribute("data-notified") === "true";
      const phoneNumber = cell.querySelector(".phoneLink").textContent;

      if (!isNotified) {
        if (confirm(`Send expiration notice to ${phoneNumber}?`)) {
          cell.setAttribute("data-notified", "true");
          alert("Notification Sent Successfully!");
        }
      } else {
        if (confirm("Reset notification status for this contact?")) {
          cell.setAttribute("data-notified", "false");
        }
      }
    });
  }
});
