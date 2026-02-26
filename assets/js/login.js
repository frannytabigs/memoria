const form = document.getElementById("loginform");

form.addEventListener("submit", function (e) {
  e.preventDefault();

  const formData = new FormData(form);

  fetch("api/login.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showModal({
          type: "success",
          title: "Login Successful",
          message: `Welcome back ${data.data.fullName}!`,
          actionText: "Proceed to dashboard",
          actionLink: "/dashboard",
          allowOutsideClick: false,
        });
      } else {
        showModal({
          type: "warning",
          title: data.message,
          actionText: "",
        });
      }
      console.log(data);
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
    });
});

// add logic if user is already logged in, redirect to dashboard soafer hard!! soon
