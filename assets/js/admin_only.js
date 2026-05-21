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
        console.warn(
          "Auth check failed due to static server. Allowing access for testing purposes.",
        );
        console.error(
          "Looks like you are running this page without the backend server???? THIS WILL BE BUGGY WTF NO GOODBYE",
        );
        localStorage.setItem("memoria_role", "Administrator");
        localStorage.setItem(
          "memoria_username",
          "BUGGY_DevModeOKAYYYY??/LIVESERVER?????????WTF",
        );
        return; // Let them stay for Live Server testing
      }
      window.location.href = "index.html";
    });
}

kickStaff();
