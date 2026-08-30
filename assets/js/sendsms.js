function makeSmsHandler(phone_number, message) {
  // Generate a unique ID for this modal instance
  const instanceId =
    "sms_" + Date.now() + "_" + Math.random().toString(36).substr(2, 9);

  // HTML-escape the message to prevent XSS
  function htmlEscape(str) {
    if (!str) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  const modalId = instanceId + "_modal";

  // Create modal
  const modal = document.createElement("div");
  modal.id = modalId;
  modal.style.position = "fixed";
  modal.style.inset = "0";
  modal.style.background = "rgba(0,0,0,0.55)";
  modal.style.display = "none";
  modal.style.alignItems = "center";
  modal.style.justifyContent = "center";
  modal.style.zIndex = "9999";
  modal.style.fontFamily =
    'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

  modal.innerHTML = `
    <div class="sms-card" style="
      background: #ffffff;
      border-radius: 14px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.35);
      width: min(92vw, 420px);
      overflow: hidden;
      transform: translateY(8px);
      opacity: 0;
      transition: transform .25s ease, opacity .25s ease;
    ">
      <div style="
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        padding: 18px 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
        font-size: 16px;
        letter-spacing: .2px;
      ">
        <i class="fas fa-comment-sms" style="font-size: 18px;"></i>
        Send SMS Notification
      </div>

      <div style="padding: 18px 20px;">
        <div style="
          font-size: 13px;
          color: #6b7280;
          margin-bottom: 6px;
        ">Recipient Phone Number</div>
        <input
          id="${instanceId}_phone"
          type="text"
          readonly
          style="
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            color: #111827;
            font-size: 14px;
            outline: none;
          "
        />

        <div style="
          font-size: 13px;
          color: #6b7280;
          margin-top: 14px;
          margin-bottom: 6px;
        ">Message Content</div>
        <textarea
          id="${instanceId}_message"
          rows="4"
          style="
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            color: #111827;
            font-size: 14px;
            resize: vertical;
            outline: none;
            line-height: 1.4;
          "
        ></textarea>

        <div style="
          margin-top: 14px;
          display: flex;
          align-items: center;
          gap: 8px;
          font-size: 13px;
          color: #374151;
        ">
          <input
            id="${instanceId}_include"
            type="checkbox"
            checked
            style="accent-color: #6366f1;"
          />
          <label for="${instanceId}_include">
            Include cemetery name in the SMS
          </label>
        </div>

        <div style="
          margin-top: 18px;
          display: flex;
          gap: 10px;
          justify-content: flex-end;
        ">
          <button
            id="${instanceId}_cancel"
            type="button"
            style="
              padding: 8px 14px;
              border-radius: 10px;
              border: 1px solid #d1d5db;
              background: #ffffff;
              color: #374151;
              font-size: 13px;
              font-weight: 600;
              cursor: pointer;
              transition: background .15s ease, transform .05s ease;
            "
          >
            Cancel
          </button>
          <button
            id="${instanceId}_send"
            type="button"
            style="
              padding: 8px 16px;
              border-radius: 10px;
              border: none;
              background: linear-gradient(135deg, #6366f1, #8b5cf6);
              color: #ffffff;
              font-size: 13px;
              font-weight: 700;
              cursor: pointer;
              display: inline-flex;
              align-items: center;
              gap: 8px;
              transition: filter .15s ease, transform .05s ease;
            "
          >
            <i class="fas fa-paper-plane"></i>
            Send SMS
          </button>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  const smsModal = document.getElementById(modalId);
  const smsCard = smsModal.querySelector(".sms-card");
  const smsPhoneInput = document.getElementById(`${instanceId}_phone`);
  const smsMessageInput = document.getElementById(`${instanceId}_message`);
  const smsIncludeCheckbox = document.getElementById(`${instanceId}_include`);
  const btnSmsCancel = document.getElementById(`${instanceId}_cancel`);
  const btnSmsSend = document.getElementById(`${instanceId}_send`);

  // Set initial values (HTML-escaped message)
  smsPhoneInput.value = phone_number || "";
  smsMessageInput.value = htmlEscape(message);

  function openSmsModal() {
    smsModal.style.display = "flex";
    requestAnimationFrame(() => {
      smsCard.style.transform = "translateY(0)";
      smsCard.style.opacity = "1";
    });
    document.body.style.overflow = "hidden";
  }

  function closeSmsModal() {
    smsCard.style.transform = "translateY(8px)";
    smsCard.style.opacity = "0";
    setTimeout(() => {
      smsModal.style.display = "none";
      document.body.style.overflow = "";
      // Remove modal from DOM after closing
      setTimeout(() => {
        if (modal.parentNode) {
          modal.parentNode.removeChild(modal);
        }
      }, 250);
    }, 200);
  }

  // Clicking outside modal to close
  smsModal.addEventListener("click", (event) => {
    if (event.target === smsModal) closeSmsModal();
  });

  // Cancel button
  btnSmsCancel.addEventListener("click", closeSmsModal);

  // Send button
  btnSmsSend.addEventListener("click", async () => {
    const phone = smsPhoneInput.value.trim();
    const rawMessage = smsMessageInput.value.trim();
    const includeName = smsIncludeCheckbox.checked;

    if (!phone) {
      if (typeof showAlertTOP === "function") {
        showAlertTOP("No valid phone number to send to.", "error");
      } else {
        alert("No valid phone number to send to.");
      }
      return;
    }

    btnSmsSend.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    btnSmsSend.disabled = true;
    btnSmsSend.style.filter = "brightness(0.9)";
    btnSmsSend.style.cursor = "not-allowed";

    try {
      if (typeof sendSms === "function") {
        await sendSms(phone, rawMessage, includeName);
      } else {
        const response = await fetch("api/sendsms", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            phone_number: phone,
            message: rawMessage,
            include_cemetery_name: includeName,
          }),
        });
        const result = await response.json();
        if (result.success) {
          if (typeof showAlertTOP === "function") {
            showAlertTOP("SMS sent successfully!", "success");
          } else {
            alert("SMS sent successfully!");
          }
        } else {
          throw new Error(result.message || "Failed to send SMS.");
        }
      }
      closeSmsModal();
    } catch (error) {
      console.error(error);
      if (typeof showAlertTOP === "function") {
        showAlertTOP(
          error.message || "An error occurred while sending the SMS.",
          "error",
        );
      } else {
        alert(error.message || "An error occurred while sending the SMS.");
      }
    } finally {
      btnSmsSend.innerHTML = `<i class="fas fa-paper-plane"></i> Send SMS`;
      btnSmsSend.disabled = false;
      btnSmsSend.style.filter = "";
      btnSmsSend.style.cursor = "pointer";
    }
  });

  openSmsModal();
}
