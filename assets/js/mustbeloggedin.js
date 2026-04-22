document.addEventListener("DOMContentLoaded", async () => {
  try {
    const response = await fetch("api/auth.php");
    if (!response.ok) {
      window.location.href = "index.html";
    } else {
      try {
        const data = await response.json();
        document.getElementById("name").textContent = data.data.user.name;
        document.getElementById("userprofile").textContent =
          data.data.user.username.substring(0, 2).toUpperCase();
        document.getElementById("username_role").textContent =
          `${data.data.user.username} | ${data.data.user.role}`;
      } catch (err) {
        console.error("Error parsing user data:", err);
      }
    }
  } catch (error) {
    alert(
      `An error occurred while checking login status. Please try again. Error: ${error.message}`,
    );
    console.error("Error checking login status:", error);
    // window.location.href = "index.html";
  }
});
