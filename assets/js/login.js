document.addEventListener("DOMContentLoaded", async () => {
  const queryString = window.location.search;
  const urlParams = new URLSearchParams(queryString);

  if (urlParams.get("logout") == "true") {
    fetch("api/auth.php", {
      method: "DELETE",
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showModal({
            type: "success",
            title: "Logout Successful",
            message: `You have been logged out successfully`,
            allowOutsideClick: false,
            actionText: "Return to login",
            actionLink: "login.html",
          });
        }
      })
      .catch((error) => {
        console.error("Error:", error);
      });
    return;
  }

  try {
    const response = await fetch("api/auth.php");

    if (response.ok) {
      const data = await response.json();
      //console.log("User is already logged in", data);
      //console.log(document.cookie);
      window.location.href = "dashboard.html";
    }
  } catch (error) {
    //console.error("Auth check failed:", error);
  }
});

const form = document.getElementById("loginform");

form.addEventListener("submit", function (e) {
  e.preventDefault();

  const formData = new FormData(form);

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
