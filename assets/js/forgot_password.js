const form = document.getElementById("resetform");

form.addEventListener("submit", function (e) {
  e.preventDefault();

  const formData = new FormData(form);

  const resetBtn = document.getElementById("resetBtn");
  resetBtn.disabled = true;
  resetBtn.textContent = "Processing...";

  let inputElements = form.querySelectorAll("input");
  inputElements.forEach((input) => {
    input.disabled = true;
  });

  setTimeout(() => {
    fetch("api/users/forgot-password", {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showModal({
            type: "success",
            title: "Reset Password Successful",
            message: `Hello ${data.data?.first_name ?? "User"}! Your password has been reset. Please wait for admin verification to log in to your account.`,
            actionText: "Proceed to login",
            actionLink: "index.html",
            allowOutsideClick: false,
          });
        } else {
          showAlertTOP(data.message, "warning", 10000);
          const errorMessage = data.message.toLowerCase();
          // 1. Create a dictionary mapping what the server says (left) to your HTML IDs (right)
          const fieldMap = {
            "phone number": "phone_number",
            phone: "phone_number", // A fallback just in case the server just says "phone"
            email: "email",
            username: "username",
            password: "password must be at least",
            "password must be at least": "password",
            name: "name",
          };

          let errorIds = [];

          // 2. Loop through our map and check the error message for each phrase
          for (const [searchPhrase, exactHtmlId] of Object.entries(fieldMap)) {
            if (errorMessage.includes(searchPhrase)) {
              // If we find a match, add the HTML ID to our array (avoiding duplicates)
              if (!errorIds.includes(exactHtmlId)) {
                errorIds.push(exactHtmlId);
              }
            }
          }

          // 3. Keep your specific rule for name vs username
          if (errorIds.includes("name") && errorIds.includes("username")) {
            errorIds = errorIds.filter((id) => id !== "name");
          }

          // 4. Trigger the animation using the exact HTML IDs
          if (errorIds.length > 0) {
            animateInputsOnError(errorIds);
          }
          if (errorMessage.includes("credentials")) {
            animateInputsOnError(["name", "username", "email", "phone_number"]);
          }
        }
        //console.log(data);
      })
      .catch((error) => {
        showAlertTOP(
          "An error occurred while processing your request. Please try again later.",
          "error",
        );
        console.error("Error:", error);
      })
      .finally(() => {
        resetBtn.disabled = false;
        resetBtn.textContent = "Reset Password";
        inputElements.forEach((input) => {
          input.disabled = false;
        });
      });
  }, 1000); //Simulate processing time with a 1 second delay for better UX
});
