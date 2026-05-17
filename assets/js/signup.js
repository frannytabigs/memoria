const form = document.getElementById("signupform");

form.addEventListener("submit", function (e) {
  e.preventDefault();

  const formData = new FormData(form);

  const signupButton = document.getElementById("signupbutton");
  signupButton.disabled = true;
  signupButton.textContent = "Signing up...";
  signupButton.style.cursor = "not-allowed";

  const inputs = form.querySelectorAll("input");
  inputs.forEach((input) => {
    input.disabled = true;
  });

  setTimeout(() => {
    fetch("api/users.php", {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showModal({
            type: "success",
            title: "Signup Successful",
            message: `Hello ${data.data?.first_name ?? "User"}! Your account has been created. Please wait for admin verfication to log in to your account.`,
            actionText: "Proceed to login",
            actionLink: "index.html",
            allowOutsideClick: false,
          });
        } else {
          // showModal({
          //   type: "warning",
          //   title: data.message,
          //   actionText: "",
          // });
          showAlertTOP(data.message, "warning", 10000);
          const errorMessage = data.message.toLowerCase();

          // 1. Create a dictionary mapping what the server says (left) to your HTML IDs (right)
          const fieldMap = {
            "phone number": "phone_number",
            phone: "phone_number", // A fallback just in case the server just says "phone"
            email: "email",
            username: "username",
            password: "password",
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
        // Re-enable the signup button and inputs
        signupButton.disabled = false;
        signupButton.textContent = "Sign Up";
        signupButton.style.cursor = "pointer";

        inputs.forEach((input) => {
          input.disabled = false;
        });
      });
  }, 500); // Simulate a 1-second delay for better UX
});
