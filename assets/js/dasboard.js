document.addEventListener("DOMContentLoaded", () => {

    // AUTO CURRENT YEAR
    const yearText = document.getElementById("currentYear");
    if (yearText) {
        const currentYear = new Date().getFullYear();
        yearText.textContent = "Year: " + currentYear;
    }

    // PIE CHART
    const pieCanvas = document.getElementById('pieChart');

    if (pieCanvas) {
        new Chart(pieCanvas, {
            type: 'pie',
            data: {
                labels: ['Available', 'Expiring', 'Occupied'],
                datasets: [{
                    data: [30, 60, 10],
                    backgroundColor: [
                        '#a8c29a',
                        '#3366cc',
                        '#ef4444' 
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,

                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'center',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 15,
                            boxWidth: 10,
                            boxHeight: 10,
                            font: {
                                size: 13
                            }
                        }
                    }
                }
            }
        });
    }

    // BAR GRAPH
    const barCanvas = document.getElementById('barGraph');

    if (barCanvas) {
        new Chart(barCanvas, {
            type: 'bar',
            data: {
                labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                datasets: [{
                    data: [12, 18, 35, 16, 22, 30, 25, 17, 40, 28, 33, 45],
                    backgroundColor: '#ef4444', 
                    borderRadius: 5,
                    barPercentage: 0.8,
                    categoryPercentage: 0.9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,

                plugins: {
                    legend: {
                        display: false
                    }
                },

                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#64748b'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e2e8f0'
                        },
                        ticks: {
                            stepSize: 5,
                            color: '#64748b'
                        }
                    }
                }
            }
        });
    }
});