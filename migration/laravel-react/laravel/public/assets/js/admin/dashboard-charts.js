document.addEventListener('DOMContentLoaded', () => {
  // Barangay Chart
  const barangayCtx = document.getElementById('barangayChart');
  if (barangayCtx) {
    new Chart(barangayCtx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: window.barangayLabels,
        datasets: [
          {
            label: 'Verified',
            data: window.barangayVerified,
            backgroundColor: 'rgba(25, 135, 84, 0.7)',
            borderColor: 'rgba(25, 135, 84, 1)',
            borderWidth: 1
          },
          {
            label: 'Pending',
            data: window.barangayApplicant,
            backgroundColor: 'rgba(255, 193, 7, 0.7)',
            borderColor: 'rgba(255, 193, 7, 1)',
            borderWidth: 1
          }
        ]
      },
      options: {
        responsive: true,
        scales: {
          x: { beginAtZero: true },
          y: { beginAtZero: true }
        }
      }
    });
  }

  // Gender Chart
  const genderCtx = document.getElementById('genderChart');
  if (genderCtx) {
    new Chart(genderCtx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: window.genderLabels,
        datasets: [
          {
            label: 'Verified',
            data: window.genderVerified,
            backgroundColor: 'rgba(25, 135, 84, 0.7)',
            borderColor: 'rgba(25, 135, 84, 1)',
            borderWidth: 1
          },
          {
            label: 'Applicant',
            data: window.genderApplicant,
            backgroundColor: 'rgba(255, 193, 7, 0.7)',
            borderColor: 'rgba(255, 193, 7, 1)',
            borderWidth: 1
          }
        ]
      },
      options: {
        responsive: true,
        scales: {
          x: { beginAtZero: true },
          y: { beginAtZero: true }
        }
      }
    });
  }
});