document.addEventListener("DOMContentLoaded", async () => {
  try {
    const response = await fetch("api/auth.php");
    const contentType = response.headers.get("content-type");

    // Only redirect if the response is successful AND it is actual JSON data
    if (
      response.ok &&
      contentType &&
      contentType.includes("application/json")
    ) {
      window.location.href = "dashboard.html";
    } else {
      console.warn(
        "Static server detected or API failed. Staying on the page.",
      );
    }
  } catch (error) {
    console.error("Error checking login status:", error);
  }
});
