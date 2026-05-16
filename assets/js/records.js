/* --- TAB SWITCHING --- */
function showTab(tabName, event) {
    document.querySelectorAll('.subNavItem').forEach(btn => btn.classList.remove('active'));
    event.currentTarget.classList.add('active');

    document.querySelectorAll('.tableWrapper').forEach(tab => tab.classList.remove('active'));

    if (tabName === 'burial') {
        document.getElementById('burialRecords').classList.add('active');
    } else {
        document.getElementById('blockRecords').classList.add('active');
    }
    
    // CLEAR SEARCH IG TAB SWITCHING
    document.getElementById("recordSearch").value = "";
    filterTable();
}

/* --- SEARCH & FILTER LOGIC --- */
function filterTable() {
    const input = document.getElementById("recordSearch");
    const filter = input.value.toLowerCase();
    const activeWrapper = document.querySelector('.tableWrapper.active');
    const activeTable = activeWrapper.querySelector('table');

    if (!activeTable) return;

    const rows = activeTable.getElementsByTagName("tr");
    let hasVisibleRow = false;

    for (let i = 1; i < rows.length; i++) {
        let show = false;
        const cells = rows[i].getElementsByTagName("td");

        for (let j = 0; j < cells.length; j++) {
            if (cells[j].innerText.toLowerCase().includes(filter)) {
                show = true;
                break;
            }
        }

        rows[i].style.display = show ? "" : "none";
        if (show) hasVisibleRow = true;
    }

    // NO DATA BASTA IG SEARCH NYA WALA
    const noDataText = activeWrapper.querySelector(".noData");
    if (noDataText) {
        noDataText.style.display = hasVisibleRow ? "none" : "block";
    }
}

function checkEmptyTables() {
    document.querySelectorAll('.tableWrapper').forEach(wrapper => {
        const tbody = wrapper.querySelector('tbody');
        const noDataMsg = wrapper.querySelector('.noData');
        
        if (!tbody || tbody.rows.length === 0) {
            noDataMsg.style.display = 'block';
        } else {
            noDataMsg.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const recordModal = document.getElementById('recordModal');
    const recordForm = document.getElementById('recordForm');
    const dateBuriedInput = document.getElementById('dateBuried');
    const expirationInput = document.getElementById('expiration');
    const burialTableBody = document.querySelector('#burialRecords tbody');
    const blockTableBody = document.querySelector('#blockRecords tbody');

    checkEmptyTables();

    document.getElementById("recordSearch").addEventListener("keyup", filterTable); 
    document.getElementById("searchBtn").addEventListener("click", filterTable);

    window.openModal = function() {
        recordModal.style.display = 'flex';
    };

    window.closeModal = function() {
        recordModal.style.display = 'none';
        recordForm.reset();
        expirationInput.value = "";
        delete expirationInput.dataset.rawDate;
    };

    window.onclick = (e) => {
        if (e.target === recordModal) closeModal();
    };

    // EXPIRATION CALCULATION (5 YEARS NI SYA)
    dateBuriedInput.addEventListener('change', function() {
        if (this.value) {
            const burialDate = new Date(this.value);
            burialDate.setFullYear(burialDate.getFullYear() + 5);
            
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            expirationInput.value = burialDate.toLocaleDateString('en-US', options);
            expirationInput.dataset.rawDate = burialDate.toISOString();
        } else {
            expirationInput.value = "";
        }
    });

    // STATUS
    function getStatus(expirationDateStr) {
        const now = new Date();
        const expirationDate = new Date(expirationDateStr);
        const diffInDays = Math.ceil((expirationDate - now) / (1000 * 3600 * 24));

        if (diffInDays < 0) {
            return { text: 'Expired', class: 'status-expired' };
        } else if (diffInDays <= 30) {
            return { text: 'Expiring', class: 'status-expiring' };
        } else {
            return { text: 'Occupied', class: 'status-occupied' };
        }
    }

    // FORM SUBMISSION
    recordForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const rawExpDate = expirationInput.dataset.rawDate;
        const status = getStatus(rawExpDate);

        const formData = {
            controlNo: document.getElementById('controlNo').value,
            firstName: document.getElementById('firstName').value,
            middleName: document.getElementById('middleName').value,
            lastName: document.getElementById('lastName').value,
            dateBuried: new Date(dateBuriedInput.value).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
            address: document.getElementById('deceasedAddress').value,
            block: document.getElementById('blockNo').value,
            floor: document.getElementById('floorLevel').value || '1',
            expiration: expirationInput.value,
            contactPerson: document.getElementById('contactPerson').value,
            contactNo: document.getElementById('contactNumber').value,
            contactAddr: document.getElementById('contactAddress').value,
            remarks: document.getElementById('remarks').value || '-'
        };

        const fullName = `${formData.firstName} ${formData.middleName ? formData.middleName + ' ' : ''}${formData.lastName}`;

        const burialRow = `
            <tr>
                <td>${formData.controlNo}</td>
                <td>${fullName}</td>
                <td>${formData.address}</td>
                <td>${formData.dateBuried}</td>
                <td>${formData.block}</td>
                <td>${formData.expiration}</td>
                <td>${formData.contactPerson}</td>
                <td>${formData.contactNo}</td>
                <td>${formData.contactAddr}</td>
                <td>${formData.remarks}</td>
                <td class="actions">
                    <button class="printBtn"><i class="fas fa-print"></i></button>
                    <button class="editBtn"><i class="fas fa-edit"></i></button>
                    <button class="deleteBtn"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;

        const blockRow = `
            <tr>
                <td>${formData.block}</td>
                <td><span class="status-badge ${status.class}">${status.text}</span></td>
                <td>${fullName}</td>
                <td>${formData.floor}</td>
                <td>${formData.remarks}</td>
                <td class="actions">
                    <button class="printBtn"><i class="fas fa-print"></i></button>
                    <button class="editBtn"><i class="fas fa-edit"></i></button>
                    <button class="deleteBtn"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;

        burialTableBody.insertAdjacentHTML('afterbegin', burialRow);
        blockTableBody.insertAdjacentHTML('afterbegin', blockRow);

        checkEmptyTables();

        alert(`Record Saved. Status: ${status.text}`);
        closeModal();
    });
});