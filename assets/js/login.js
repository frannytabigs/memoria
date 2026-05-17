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
          // showModal({
          //   type: "success",
          //   title: "Login Successful",
          //   message: `Welcome back ${data.data?.first_name ?? "User"}!`,
          //   actionText: "Proceed to dashboard",
          //   actionLink: "/dashboard",
          //   allowOutsideClick: false,
          // });
          window.location.href = "dashboard.html";
        } else {
          // showModal({
          //   type: "warning",
          //   title: data.message,
          //   actionText: "",
          // });

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
        showModal({
          type: "error",
          title: "Error has occured",
          message:
            "An error occurred while processing your request. Please try again later",
          actionLink: "/",
          allowOutsideClick: true,
        });
        console.error("Error:", error);
      })
      .finally(() => {
        username.disabled = false;
        password.disabled = false;
        loginButton.disabled = false;
        loginButton.textContent = "Log In";
        loginButton.style.cursor = "pointer";
      });
  }, 666);
});
