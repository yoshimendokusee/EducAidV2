document.addEventListener('DOMContentLoaded', function() {
    // --------- Date Controls ---------
    const minTime = '06:00', maxTime = '17:00';
    const startDateInput = document.querySelector('input[name="start_date"]');
    const endDateInput = document.querySelector('input[name="end_date"]');
    if (startDateInput && endDateInput) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const minDate = tomorrow.toISOString().split('T')[0];
        startDateInput.setAttribute('min', minDate);
        endDateInput.setAttribute('min', minDate);

        startDateInput.addEventListener('change', function() {
            endDateInput.setAttribute('min', this.value);
            if (endDateInput.value < this.value) {
                endDateInput.value = this.value;
            }
        });
    }

    // --------- Time Inputs Controls ---------
    document.querySelectorAll('input[name="start_time[]"], input[name="end_time[]"]').forEach(input => {
        input.setAttribute('min', minTime);
        input.setAttribute('max', maxTime);
        input.setAttribute('step', '300');
        input.addEventListener('change', function() {
            if (!this.value) return;
            if (this.value < minTime) {
                alert('Time cannot be earlier than ' + minTime + '.');
                this.value = minTime;
            } else if (this.value > maxTime) {
                alert('Time cannot be later than ' + maxTime + '.');
                this.value = maxTime;
            }
            updateConfirmButtonState();
        });
    });

    // --------- Step Navigation ---------
    const step1 = document.getElementById('step-1');
    const step2 = document.getElementById('step-2');
    const payrollPopup = document.getElementById('payroll-popup');

    // "Next" button to go to Step 2
    const nextToStep2Btn = document.getElementById('next-to-step-2');
    if (nextToStep2Btn) {
        nextToStep2Btn.addEventListener('click', function() {
            const startDate = startDateInput ? startDateInput.value : '';
            const endDate = endDateInput ? endDateInput.value : '';
            if (!startDate || !endDate) {
                alert('Please select both start and end dates.');
                return;
            }
            if (new Date(endDate) < new Date(startDate)) {
                alert('End date cannot be earlier than start date.');
                return;
            }
            step1.classList.add('d-none');
            step2.classList.remove('d-none');
        });
    }

    // "Return to Dates" button in Step 2
    if (step2) {
        const returnToDatesButton = document.createElement('button');
        returnToDatesButton.textContent = 'Return to Dates';
        returnToDatesButton.classList.add('btn', 'btn-secondary', 'mb-3');
        returnToDatesButton.type = "button";
        returnToDatesButton.addEventListener('click', function() {
            step2.classList.add('d-none');
            step1.classList.remove('d-none');
        });
        step2.prepend(returnToDatesButton);
    }

    // --------- Confirm Button State ---------
    function updateConfirmButtonState() {
        const intervals = document.querySelectorAll('.time-interval');
        const confirmBtn = document.getElementById('confirm-intervals');
        if (!confirmBtn) return;
        for (const interval of intervals) {
            const start = interval.querySelector('input[name="start_time[]"]').value;
            const end = interval.querySelector('input[name="end_time[]"]').value;
            if (!start || !end) {
                confirmBtn.disabled = true;
                return;
            }
        }
        confirmBtn.disabled = false;
    }
    updateConfirmButtonState();
    document.querySelectorAll('input[name="start_time[]"], input[name="end_time[]"]').forEach(input => {
        input.addEventListener('change', updateConfirmButtonState);
    });

    // --------- Confirm Intervals Button (to payroll allocation) ---------
    const confirmIntervalsBtn = document.getElementById('confirm-intervals');
    if (confirmIntervalsBtn) {
        confirmIntervalsBtn.addEventListener('click', function() {
            const intervals = document.querySelectorAll('.time-interval');
            let prevEnd = null;
            for (const interval of intervals) {
                const start = interval.querySelector('input[name="start_time[]"]').value;
                const end = interval.querySelector('input[name="end_time[]"]').value;
                if (!start || !end) {
                    alert('Please complete all time intervals before confirming.');
                    return;
                }
                const startTime = new Date(`1970-01-01T${start}`);
                const endTime = new Date(`1970-01-01T${end}`);
                if (endTime <= startTime) {
                    alert('End time must be later than start time.');
                    return;
                }
                if (prevEnd && startTime < prevEnd) {
                    alert('Time intervals cannot overlap. Please adjust the intervals.');
                    return;
                }
                prevEnd = endTime;
            }
            // Show payroll allocation step
            step2.classList.add('d-none');
            payrollPopup.classList.remove('d-none');
            showPayrollAllocation();
        });
    }

    // "Return to Time Scheduling" in Payroll Allocation popup
    if (payrollPopup) {
        const returnToSchedulingButton = document.createElement('button');
        returnToSchedulingButton.textContent = 'Return to Time Scheduling';
        returnToSchedulingButton.classList.add('btn', 'btn-secondary', 'mb-3');
        returnToSchedulingButton.type = "button";
        returnToSchedulingButton.addEventListener('click', function() {
            payrollPopup.classList.add('d-none');
            step2.classList.remove('d-none');
        });
        payrollPopup.prepend(returnToSchedulingButton);
    }

    // --------- Payroll Allocation & Preview ---------
    function showPayrollAllocation() {
        const payrollContainer = document.getElementById('payroll-allocation-container');
        if (!payrollContainer) return;
        payrollContainer.innerHTML = `
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Batch 1 Capacity</label>
                    <input type="number" id="batch1-capacity" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Batch 2 Capacity</label>
                    <input type="number" id="batch2-capacity" class="form-control" required>
                </div>
            </div>
            <button type="button" id="generate-schedule" class="btn btn-secondary mb-3">Generate Schedule</button>
            <div id="schedule-preview"></div>
        `;
        // Generate preview on click
        const generateBtn = document.getElementById('generate-schedule');
        if (generateBtn) {
            generateBtn.addEventListener('click', function() {
                const b1 = parseInt(document.getElementById('batch1-capacity').value, 10);
                const b2 = parseInt(document.getElementById('batch2-capacity').value, 10);
                if (!b1 || !b2) {
                    alert('Please enter batch capacities.');
                    return;
                }
                const startDate = startDateInput ? startDateInput.value : '';
                const endDate = endDateInput ? endDateInput.value : '';
                const intervalsData = Array.from(document.querySelectorAll('.time-interval')).map(iv => ({
                    start: iv.querySelector('input[name="start_time[]"]').value,
                    end: iv.querySelector('input[name="end_time[]"]').value
                }));
                const preview = document.getElementById('schedule-preview');
                preview.innerHTML = `
                    <table class="table table-bordered">
                      <thead>
                        <tr>
                          <th>Date</th>
                          <th>Batch 1 (${intervalsData[0].start} - ${intervalsData[0].end})</th>
                          <th>Students</th>
                          <th>Batch 2 (${intervalsData[1].start} - ${intervalsData[1].end})</th>
                          <th>Students</th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
                `;
                const tbody = preview.querySelector('tbody');
                let counter = 1;
                let currentDate = new Date(startDate);
                const endDateObj = new Date(endDate);
                while (currentDate <= endDateObj) {
                    const dateLabel = currentDate.toISOString().split('T')[0];
                    const startNum1 = counter;
                    const endNum1 = counter + b1 - 1;
                    counter += b1;
                    const startNum2 = counter;
                    const endNum2 = counter + b2 - 1;
                    counter += b2;
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                      <td>${dateLabel}</td>
                      <td>${intervalsData[0].start} - ${intervalsData[0].end}</td>
                      <td>${startNum1} - ${endNum1}</td>
                      <td>${intervalsData[1].start} - ${intervalsData[1].end}</td>
                      <td>${startNum2} - ${endNum2}</td>
                    `;
                    tbody.appendChild(tr);
                    currentDate.setDate(currentDate.getDate() + 1);
                }
                // Add hidden inputs for form submission (once preview exists)
                const form = document.getElementById('payroll-allocation-form');
                const locationVal = document.querySelector('input[name="location"]').value;
                form.querySelectorAll('input[type="hidden"]').forEach(e => e.remove());
                form.insertAdjacentHTML('beforeend', `
                    <input type="hidden" name="start_date" value="${startDate}">
                    <input type="hidden" name="end_date" value="${endDate}">
                    <input type="hidden" name="start_time[]" value="${intervalsData[0].start}">
                    <input type="hidden" name="end_time[]" value="${intervalsData[0].end}">
                    <input type="hidden" name="start_time[]" value="${intervalsData[1].start}">
                    <input type="hidden" name="end_time[]" value="${intervalsData[1].end}">
                    <input type="hidden" name="batch1_capacity" value="${b1}">
                    <input type="hidden" name="batch2_capacity" value="${b2}">
                    <input type="hidden" name="location" value="${locationVal}">
                    <input type="hidden" name="confirm_save" value="1">
                `);
                // Show Save button
                const saveBtn = document.getElementById('save-schedule-btn');
                if (saveBtn) saveBtn.classList.remove('d-none');
            });
        }
    }

    // --------- Save/Publish/Unpublish/Modal Controls ---------
    const saveBtn = document.getElementById('confirm-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            document.getElementById('payroll-allocation-form').submit();
        });
    }
    const editBtn = document.getElementById('confirm-edit-btn');
    if (editBtn) {
        editBtn.addEventListener('click', function() {
            document.getElementById('edit-form').submit();
        });
    }
    const unpublishBtn = document.getElementById('confirm-unpublish-btn');
    if (unpublishBtn) {
        unpublishBtn.addEventListener('click', function() {
            document.getElementById('unpublish-form').submit();
        });
    }
});
