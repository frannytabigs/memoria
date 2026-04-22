document.addEventListener("DOMContentLoaded", async () => {
  try {
    const response = await fetch("api/auth.php");
    if (!response.ok) {
      window.location.href = "index.html";
    }
  } catch (error) {
    alert(
      `An error occurred while checking login status. Please try again. Error: ${error.message}`,
    );
    console.error("Error checking login status:", error);
    // window.location.href = "index.html";
  }
});
