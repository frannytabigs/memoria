document.addEventListener("DOMContentLoaded", function () {
  const togglePasswordBtn = document.getElementById("togglePassword");
  const passwordInput = document.getElementById("password");

  if (togglePasswordBtn && passwordInput) {
    // 1. Hide the button initially if the password field is empty
    togglePasswordBtn.style.display =
      passwordInput.value.length > 0 ? "flex" : "none";

    // 2. Listen for user typing in the password field
    passwordInput.addEventListener("input", function () {
      if (this.value.length > 0) {
        togglePasswordBtn.style.display = "flex"; // Show icon
      } else {
        togglePasswordBtn.style.display = "none"; // Hide icon

        // Optional: If they delete all text, revert back to bullet points (password mode)
        passwordInput.setAttribute("type", "password");
        togglePasswordBtn.querySelector(".eye-open").style.display = "block";
        togglePasswordBtn.querySelector(".eye-closed").style.display = "none";
      }
    });

    // 3. The clicking logic (same as before)
    togglePasswordBtn.addEventListener("click", function () {
      const isPassword = passwordInput.getAttribute("type") === "password";

      // Toggle the type attribute
      passwordInput.setAttribute("type", isPassword ? "text" : "password");

      // Toggle icons visibility
      const eyeOpen = this.querySelector(".eye-open");
      const eyeClosed = this.querySelector(".eye-closed");

      if (isPassword) {
        eyeOpen.style.display = "none";
        eyeClosed.style.display = "block";
      } else {
        eyeOpen.style.display = "block";
        eyeClosed.style.display = "none";
      }
    });
  }
});
