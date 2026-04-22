<?php
// composer install --optimize-autoloader --no-dev
require_once __DIR__ . '/vendor/autoload.php';
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Memoria | Log In</title>

    <!-- <link rel="icon" href="assets/images/Mlogo.png" type="image/png" /> -->

    <link rel="stylesheet" href="assets/css/auth.css" />
    <link
      href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap"
      rel="stylesheet"
    />
    <link
      href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css"
      rel="stylesheet"
    />
  </head>
  <body>
    <header class="navBar">
      <div class="navContainer">
        <div class="navLogoSection">
          <img
            src="assets/images/MandaueLogo.png"
            alt="Mandaue City Logo"
            class="navLogo"
          />
          <img
            src="assets/images/DGSLogo.png"
            alt="Department of General Services logo"
            class="navLogo"
          />

          <div class="navText" id="navText">
            <h1>DEPARTMENT OF GENERAL SERVICES</h1>
            <h2 id="cemetery_address">MANDAUE CITY</h2>
          </div>
        </div>

        <div class="navLink">
          <a href="signup.html" class="btnNav">Sign Up</a>
        </div>
      </div>
    </header>

    <div class="wrapper">
      <form action="#" id="loginform">
        <h1 class="memoriaLogo">
          <img
            src="assets/images/MemoriaLogo.png"
            alt="Memoria"
            class="mLogo"
            id="cemetery_logo"
          />
        </h1>
        <p class="subtitle" id="cemetery_name">
          Mandaue City Municipal Cemetery
        </p>

        <div class="inputBox">
          <input
            type="text"
            placeholder="Username"
            name="username"
            id="username"
            required
          />
          <i class="bx bxs-user-circle"></i>
        </div>

        <div class="inputBox">
          <input
            type="password"
            placeholder="Password"
            name="password"
            id="password"
            required
          />
          <i class="bx bxs-lock-alt"></i>
        </div>

        <div class="forgotPassword">
          <a href="reset.html">Forgot Password?</a>
        </div>

        <button type="submit" class="btn">LOGIN</button>

        <div class="note">
          <p>System access is restricted to authorized personnel only.</p>
          <a href="#"> Contact Your Administrator</a>
        </div>
      </form>
    </div>
    <script src="assets/js/modal.js"></script>
    <script src="assets/js/loggedinalready.js"></script>
    <script src="assets/js/login.js"></script>
  </body>
</html>
