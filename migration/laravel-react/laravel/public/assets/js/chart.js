document.addEventListener('DOMContentLoaded', function () {
  function isDataEmpty(datasets) {
    return datasets.every(ds =>
      Array.isArray(ds.data) && ds.data.every(val => val === 0)
    );
  }

  function showNoDataMessage(canvasId, message = "No data available") {
    const container = document.getElementById(canvasId)?.parentElement;
    if (container) {
      const msg = document.createElement("p");
      msg.innerHTML = `<i class="bi bi-info-circle me-2"></i>${message}`;
      msg.className = "text-center text-muted mt-3";
      container.appendChild(msg);
    }
  }

  // ===== Barangay Chart =====
  const barangayChartEl = document.getElementById('barangayChart');
  if (barangayChartEl) {
    const barangayData = {
      labels: window.barangayLabels || [],
      datasets: [
        {
          label: 'Verified',
          data: window.barangayVerified || [],
          backgroundColor: '#3b8efc',
          borderRadius: 8,
          borderSkipped: false
        },
        {
          label: 'Applicant',
          data: window.barangayApplicant || [],
          backgroundColor: '#ff9800',
          borderRadius: 8,
          borderSkipped: false
        }
      ]
    };

    if (isDataEmpty(barangayData.datasets)) {
      showNoDataMessage('barangayChart');
    } else {
      new Chart(barangayChartEl.getContext('2d'), {
        type: 'bar',
        data: barangayData,
        options: {
          responsive: true,
          plugins: {
            legend: {
              position: 'top',
              labels: {
                boxWidth: 12,
                font: {
                  weight: '500'
                }
              }
            }
          },
          scales: {
            x: {
              grid: { color: '#f1f1f1' },
              stacked: false
            },
            y: {
              beginAtZero: true,
              grid: { color: '#f1f1f1' },
              stacked: false
            }
          },
          elements: {
            bar: {
              borderRadius: 8,
              barPercentage: 0.6,
              categoryPercentage: 0.5
            }
          }
        }
      });
    }
  }

  // ===== Gender Chart =====
  const genderChartEl = document.getElementById('genderChart');
  if (genderChartEl) {
    const genderData = {
      labels: window.genderLabels || [],
      datasets: [
        {
          label: 'Verified',
          data: window.genderVerified || [],
          backgroundColor: '#3b8efc',
          borderRadius: 8,
          borderSkipped: false
        },
        {
          label: 'Applicant',
          data: window.genderApplicant || [],
          backgroundColor: '#ff9800',
          borderRadius: 8,
          borderSkipped: false
        }
      ]
    };

    if (isDataEmpty(genderData.datasets)) {
      showNoDataMessage('genderChart');
    } else {
      new Chart(genderChartEl.getContext('2d'), {
        type: 'bar',
        data: genderData,
        options: {
          responsive: true,
          plugins: {
            legend: {
              position: 'top',
              labels: {
                boxWidth: 12,
                font: {
                  weight: '500'
                }
              }
            }
          },
          scales: {
            x: {
              grid: { color: '#f1f1f1' },
              stacked: false
            },
            y: {
              beginAtZero: true,
              grid: { color: '#f1f1f1' },
              stacked: false
            }
          },
          elements: {
            bar: {
              borderRadius: 8,
              barPercentage: 0.6,
              categoryPercentage: 0.5
            }
          }
        }
      });
    }
  }
});
