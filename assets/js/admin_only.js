function kickStaff() {
  fetch("api/auth.php")
    .then(function (response) {
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
      console.error("Error checking login status:", error);
      window.location.href = "index.html";
    });
}

kickStaff();
