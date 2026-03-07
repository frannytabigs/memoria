document.addEventListener("DOMContentLoaded", async () => {
  try {
    const response = await fetch("api/auth.php");
    if (!response.ok) {
      window.location.href = "login.html";
    }
  } catch (error) {
    window.location.href = "login.html";
  }
});
