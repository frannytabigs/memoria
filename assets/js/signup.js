const form = document.getElementById("signupform");

form.addEventListener("submit", function (e) {
  e.preventDefault();

  const formData = new FormData(form);

  fetch("api/users.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showModal({
          type: "success",
          title: "Signup Successful",
          message: `Hello ${data.data?.first_name ?? "User"}! Your account has been created. Please wait for admin verfication to log in to your account.`,
          actionText: "Proceed to dashboard",
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
