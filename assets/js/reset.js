const form = document.getElementById("resetform");

form.addEventListener("submit", function (e) {
  e.preventDefault();

  const formData = new FormData(form);

  fetch("api/users/forgot-password", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showModal({
          type: "success",
          title: "Reset Password Successful",
          message: `Hello ${data.data?.first_name ?? "User"}! Your password has been reset. Please wait for admin verification to log in to your account.`,
          actionText: "Proceed to login",
          actionLink: "index.html",
          allowOutsideClick: false,
        });
      } else {
        showModal({
          type: "warning",
          title: data.message,
          actionText: "",
        });
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
    });
});
