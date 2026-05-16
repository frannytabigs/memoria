const modal = document.getElementById('reserveModal');
const form = document.querySelector('.reserveForm');
const addButtons = document.querySelectorAll('.addBtn');

addButtons.forEach(button => {
    button.addEventListener('click', openReserveModal);
});

function openReserveModal() {
    modal.style.display = 'flex';
}

function closeReserveModal() {
    modal.style.display = 'none';
    form.reset();
}

window.onclick = function(event) {
    if (event.target == modal) {
        closeReserveModal();
    }
}

form.addEventListener('submit', function(e) {
    e.preventDefault();

    const firstName = document.getElementById('firstName').value;
    const middleName = document.getElementById('middleName').value;
    const lastName = document.getElementById('lastName').value;

    const fullName = `${firstName} ${middleName} ${lastName}`;

    alert(`Reservation processed for ${fullName}`);

    closeReserveModal();
});