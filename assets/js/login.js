const form = document.getElementById("loginform");

form.addEventListener("submit", function (e) {
  e.preventDefault();

  const formData = new FormData(form);
  const username = document.getElementById("username");
  const password = document.getElementById("password");
  const loginButton = document.getElementById("loginbutton");

  loginButton.disabled = true;
  username.disabled = true;
  password.disabled = true;
  loginButton.textContent = "Logging in...";
  loginButton.style.cursor = "not-allowed";

  setTimeout(() => {
    fetch("api/auth.php", {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          //console.log("Login successful:", data);
          //console.log("User data:", data.data.users[0]);
          showAlertTOP(
            "Login successful! Hello " +
              data.data.name +
              "! Redirecting to dashboard...",
            "success",
          );

          // ❗ DO NOT re-enable anything here

          setTimeout(() => {
            window.location.href = "dashboard.html";
          }, 2555);
        } else {
          showAlertTOP(data.message, "warning");

          username.style.transition = "0.3s";
          password.style.transition = "0.3s";

          username.style.borderColor = "red";
          password.style.borderColor = "red";

          username.style.boxShadow = "0 0 8px red";
          password.style.boxShadow = "0 0 8px red";

          username.animate(
            [
              { transform: "translateX(0)" },
              { transform: "translateX(-5px)" },
              { transform: "translateX(5px)" },
              { transform: "translateX(-5px)" },
              { transform: "translateX(0)" },
            ],
            { duration: 300 },
          );

          password.animate(
            [
              { transform: "translateX(0)" },
              { transform: "translateX(-5px)" },
              { transform: "translateX(5px)" },
              { transform: "translateX(-5px)" },
              { transform: "translateX(0)" },
            ],
            { duration: 300 },
          );

          setTimeout(() => {
            username.style.borderColor = "";
            password.style.borderColor = "";
            username.style.boxShadow = "";
            password.style.boxShadow = "";
          }, 1950);

          // ✅ ONLY re-enable on failure
          username.disabled = false;
          password.disabled = false;
          loginButton.disabled = false;
          loginButton.textContent = "Log In";
          loginButton.style.cursor = "pointer";
        }
      })
      .catch((error) => {
        showAlertTOP(
          "An error occurred while processing your request. Please try again later.",
          "error",
        );
        console.error("Error:", error);

        // ✅ re-enable only on error
        username.disabled = false;
        password.disabled = false;
        loginButton.disabled = false;
        loginButton.textContent = "Log In";
        loginButton.style.cursor = "pointer";
      });
  }, 666);
});
