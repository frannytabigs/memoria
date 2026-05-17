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
          showAlertTOP(
            "Login successful! Hello " +
              data.data.name +
              "! Redirecting to dashboard...",
            "success",
          );

          // Give the user time to actually SEE the success message before redirecting
          setTimeout(() => {
            window.location.href = "dashboard.html";
          }, 2555); // adjust this (1500–3000ms is usually good)
        } else {
          showAlertTOP(data.message, "warning");

          // Smooth animation
          username.style.transition = "0.3s";
          password.style.transition = "0.3s";

          // Change border
          username.style.borderColor = "red";
          password.style.borderColor = "red";

          // Optional glow effect
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
            {
              duration: 300,
            },
          );

          password.animate(
            [
              { transform: "translateX(0)" },
              { transform: "translateX(-5px)" },
              { transform: "translateX(5px)" },
              { transform: "translateX(-5px)" },
              { transform: "translateX(0)" },
            ],
            {
              duration: 300,
            },
          );

          setTimeout(() => {
            username.style.borderColor = "";
            password.style.borderColor = "";

            username.style.boxShadow = "";
            password.style.boxShadow = "";
          }, 1950);
        }

        //console.log(data);
      })
      .catch((error) => {
        showAlertTOP(
          "An error occurred while processing your request. Please try again later.",
          "error",
        );
        console.error("Error:", error);
      })
      .finally(() => {
        if (data.success) {
          return;
        } // Don't re-enable inputs if login was successful and we're redirecting
        username.disabled = false;
        password.disabled = false;
        loginButton.disabled = false;
        loginButton.textContent = "Log In";
        loginButton.style.cursor = "pointer";
      });
  }, 666); // Simulate a 1-second delay for better UX
});
