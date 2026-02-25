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
      //   if (data.message == "invalid credentials") {
      //     showErrorPopup("Incorrect Phone Number or Password");
      //     console.log(data);
      //     return;
      //   }
      //   showSuccessPopup(
      //     `Hello ${escapeHtml(data.user.firstName)}. You are now logged in`,
      //     "userprofile.html?phoneNumber=" +
      //       data.user.phoneNumber.replace("+", "%2B"),
      //   );
      //   console.log(data);
      //   sessionStorage.setItem("user_data", JSON.stringify
      // (data.user));
      //   sessionStorage.setItem("is_logged_in", "true");
      console.log(data);
    })
    .catch((error) => {
      console.error("Error:", error);
    });
});
