function showModal({
  type = "success", // 'success', 'warning', 'error'
  title = "Notification",
  message = "",
  actionText = "",
  actionLink = "/",
  allowOutsideClick = true,
}) {
  //forceAction is true when allowOutsideClick is false, and vice versa
  const forceAction = !allowOutsideClick;

  //Create Overlay
  const overlay = document.createElement("div");
  overlay.className = "custom-modal-overlay";

  //Determine Icons and Button Colors
  let iconHTML = "";
  let btnClass = `btn-${type}`;
  if (type === "success") iconHTML = "✓";
  if (type === "warning") iconHTML = "!";
  if (type === "error") iconHTML = "✕";

  //Create Modal HTML
  const modalBox = document.createElement("div");
  modalBox.className = "custom-modal-box";

  // Stop clicks inside the modal from bubbling to the overlay
  modalBox.onclick = (e) => e.stopPropagation();

  // Conditionally build the button HTML
  const actionButtonHTML = actionText
    ? `<button class="custom-modal-action-btn ${btnClass}">${actionText}</button>`
    : "";

  const messageStyle = !actionText ? 'style="margin-bottom: 0;"' : "";

  modalBox.innerHTML = `
        ${!forceAction ? '<button class="custom-modal-close-btn">&times;</button>' : ""}
        <div class="custom-modal-content">
            <div class="custom-modal-icon icon-${type}">${iconHTML}</div>
            <h2 class="custom-modal-title">${title}</h2>
            <p class="custom-modal-message" ${messageStyle}>${message}</p>
            ${actionButtonHTML}
        </div>
    `;

  overlay.appendChild(modalBox);
  document.body.appendChild(overlay);

  // Closing Logic with Animation
  const closeModal = () => {
    const fadeOut = overlay.animate([{ opacity: 1 }, { opacity: 0 }], {
      duration: 200,
      easing: "ease",
      fill: "forwards",
    });

    modalBox.animate(
      [
        { transform: "scale(1) translateY(0)", opacity: 1 },
        { transform: "scale(0.95) translateY(10px)", opacity: 0 },
      ],
      {
        duration: 200,
        easing: "cubic-bezier(0.4, 0, 0.2, 1)",
        fill: "forwards",
      },
    );

    fadeOut.onfinish = () => overlay.remove();
  };

  // Event Listeners
  if (allowOutsideClick) {
    overlay.onclick = closeModal;
  }

  if (!forceAction) {
    const closeBtn = modalBox.querySelector(".custom-modal-close-btn");
    if (closeBtn) closeBtn.onclick = closeModal;
  }

  // Only attach the button listener if the button was created
  if (actionText) {
    const actionBtn = modalBox.querySelector(".custom-modal-action-btn");
    actionBtn.onclick = () => {
      if (actionLink === "/") {
        closeModal();
      } else {
        window.location.href = actionLink;
      }
    };
  }
}
