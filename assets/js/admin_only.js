function kickStaff() {
  // 1. INSTANT CACHE CHECK: If cache exists and they aren't an admin, kick them immediately (Zero flicker!)
  const cachedRole = localStorage.getItem("memoria_role");
  if (cachedRole && cachedRole !== "Administrator") {
    window.location.href = "dashboard.html";
    return;
  }

  // 2. BACKGROUND VERIFICATION: Double check with the server just in case they hacked their local cache
  fetch("api/auth.php")
    .then(function (response) {
      const contentType = response.headers.get("content-type");
      if (!contentType || !contentType.includes("application/json")) {
        throw new Error("STATIC_SERVER");
      }
      if (!response.ok) {
        throw new Error("Request failed");
      }
      return response.json();
    })
    .then(function (responseData) {
      if (responseData.data.user.role !== "Administrator") {
        // They lied in their cache. Kick them!
        localStorage.removeItem("memoria_role");
        window.location.href = "dashboard.html";
      }
    })
    .catch(function (error) {
      if (error.message === "STATIC_SERVER") {
        localStorage.setItem("memoria_role", "Administrator");
        localStorage.setItem(
          "memoria_username",
          "DevModeOKAYYYY??/LIVESERVER?????????WTF",
        );
        return; // Let them stay for Live Server testing
      }
      window.location.href = "index.html";
    });
}

kickStaff();
