document.addEventListener("DOMContentLoaded", async () => {
  try {
    const response = await fetch("api/auth.php");
    const contentType = response.headers.get("content-type");

    // Only redirect if the response is successful AND it's actually JSON data
    if (
      response.ok &&
      contentType &&
      contentType.includes("application/json")
    ) {
      window.location.href = "dashboard.html";
    } else if (response.ok) {
      console.warn(
        "API returned raw PHP instead of JSON. Please run this project on a PHP server.",
      );
    }
  } catch (error) {
    console.error("Error checking login status:", error);
  }
});
