function previewImage(input, previewId) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function (e) {
      document.getElementById(previewId).src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function addOption(containerId) {
  const container = document.getElementById(containerId);
  const newItem = document.createElement("div");
  newItem.className = "optionItem";
  newItem.innerHTML = `
                <input type="text" value="">
                <button type="button" class="btnRemove" onclick="removeOption(this)"><i class="fas fa-trash"></i></button>
            `;
  container.appendChild(newItem);
}

function removeOption(button) {
  button.parentElement.remove();
}

function saveSiteContent() {
  const channels = Array.from(
    document.querySelectorAll("#paymentChannelsList input"),
  )
    .map((i) => i.value)
    .filter((v) => v.trim() !== "");
  const purposes = Array.from(
    document.querySelectorAll("#paymentPurposesList input"),
  )
    .map((i) => i.value)
    .filter((v) => v.trim() !== "");

  const formData = {
    deptTitle: document.getElementById("deptTitle").value,
    cemeteryTitle: document.getElementById("cemeteryTitle").value,
    cemeteryAddress: document.getElementById("cemeteryAddress").value,
    officeAddress: document.getElementById("officeAddress").value,
    mapEmbedUrl: document.getElementById("mapEmbedUrl").value,
    contactPhone: document.getElementById("contactPhone").value,
    contactEmail: document.getElementById("contactEmail").value,
    paymentChannels: channels,
    paymentPurposes: purposes,
  };

  console.log("Saved Content:", formData);
  alert("Site Content and Payment Dropdown settings saved successfully!");
}
