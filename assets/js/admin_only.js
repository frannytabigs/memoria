function kickStaff() {
  fetch("api/auth.php")
    .then(function (response) {
      const contentType = response.headers.get("content-type");

      // If the server doesn't return JSON, it means PHP isn't running correctly
      if (!contentType || !contentType.includes("application/json")) {
        throw new Error("STATIC_SERVER");
      }

      if (!response.ok) {
        throw new Error("Request failed");
      }
      return response.json();
    })
    .then(function (responseData) {
      // console.log(responseData.data.user.role);
      if (responseData.data.user.role != "Administrator") {
        document.querySelectorAll(".adminOnly").forEach(function (element) {
          element.style.display = "none";
        });
        window.location.href = "dashboard.html";
      }
    })
    .catch(function (error) {
      if (error.message === "STATIC_SERVER") {
        console.warn(
          "Static server detected. Admin-only features will be hidden, but no redirection will occur.",
        );
        return;
      }
      console.error("Error checking login status:", error);
      window.location.href = "index.html";
    });
}

kickStaff();
