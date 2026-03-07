new Chart(document.getElementById("modernPie"), {
  type: "pie",
  data: {
    labels: ["Occupied", "Available", "Expiring"],
    datasets: [
      {
        data: [830, 420, 36],
        backgroundColor: ["#2563eb", "#c7ddb5", "#ef4444"],
        borderWidth: 0,
        hoverOffset: 10,
      },
    ],
  },
  options: {
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: "bottom",
        labels: {
          usePointStyle: true,
          padding: 15,
          font: { size: 11 },
        },
      },
    },
  },
});

/* ==== 12-MONTH EXPIRATION FORECAST (CURRENT YEAR) ==== */
const currentDate = new Date();
const currentYear = currentDate.getFullYear();

const months = [
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
];

/* Simulated prediction logic */
function generateForecast() {
  let forecast = [];
  for (let i = 0; i < 12; i++) {
    forecast.push(Math.floor(Math.random() * 15) + 3);
  }
  return forecast;
}

const predictedData = generateForecast();

// Update Title Automatically
document
  .querySelector("#modernBar")
  .closest(".panel")
  .querySelector(".panel-header h3").textContent =
  `Monthly Expiration (${currentYear})`;

new Chart(document.getElementById("modernBar"), {
  type: "bar",
  data: {
    labels: months,
    datasets: [
      {
        label: "Predicted Expirations",
        data: predictedData,
        backgroundColor: "#ef4444", // RED
        borderRadius: 4,
        barThickness: 18,
      },
    ],
  },
  options: {
    maintainAspectRatio: false,
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: "#f1f5f9" },
        ticks: { font: { size: 10 } },
      },
      x: {
        grid: { display: false },
        ticks: { font: { size: 10 } },
      },
    },
    plugins: {
      legend: { display: false },
    },
  },
});
