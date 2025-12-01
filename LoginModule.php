<?php
session_start();
if (!empty($_SESSION['role'])) {
  if ($_SESSION['role'] === 'admin') {
    header('Location: admin_listings.php');
    exit;
  }
  if ($_SESSION['role'] === 'unit_owner') {
    header('Location: DashboardUO.php');
    exit;
  }
  header('Location: DashboardT.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>HanapBahay Login</title>
  <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="styles_login.css" />
  <link rel="stylesheet" href="darkmode.css" />
  <script>
    (function() {
      try {
        const savedTheme = localStorage.getItem('hb-theme');
        if (savedTheme) {
          document.documentElement.setAttribute('data-theme', savedTheme);
        }
      } catch (err) {
        // Ignore access errors (e.g., disabled cookies)
      }
    })();
  </script>
</head>
<body class="bg-soft">
  <header class="site-header">
    <nav>
      <a href="/" class="brand">
        <img src="Assets/Logo1.png" alt="HanapBahay logo">
        <span>HanapBahay</span>
      </a>
      <div class="nav-links">
        <a class="nav-link" href="index.php">Home</a>
        <a class="nav-link" href="browse_listings.php">Browse Listings</a>
      </div>
    </nav>
  </header>

  <main class="login-shell">
    <div class="login-container">
      <img src="PROTOTYPE1.png" alt="HanapBahay Logo" class="logo mb-2">
      <h1 class="brand-name">HANAPBAHAY</h1>
      <p class="tagline">"Finding your way home"</p>

      <?php if (isset($_SESSION['login_error'])): ?>
        <div class="alert alert-danger text-center">
          <?= htmlspecialchars($_SESSION['login_error']) ?>
          <?php if ($_SESSION['login_error'] === 'Please verify your email before logging in.'): ?>
            <br><small>Didn't receive the email? <a href="resend_verification.php">Resend verification</a></small>
          <?php endif; ?>
        </div>
        <?php unset($_SESSION['login_error']); ?>
      <?php endif; ?>

      <?php if (isset($_SESSION['success_flash'])): ?>
        <div class="alert alert-success text-center">
          <?= htmlspecialchars($_SESSION['success_flash']); ?>
        </div>
        <?php unset($_SESSION['success_flash']); ?>
      <?php endif; ?>

      <?php if (isset($_SESSION['reg_errors'])): ?>
        <div class="alert alert-danger text-start">
          <ul class="mb-0">
            <?php foreach ($_SESSION['reg_errors'] as $error): ?>
              <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php unset($_SESSION['reg_errors']); ?>
      <?php endif; ?>

      <div class="toggle-btns">
        <button id="showLogin" class="toggle-btn active" type="button">Login</button>
        <button id="showRegister" class="toggle-btn" type="button">Register</button>
      </div>

      <div class="forms">
        <form id="loginForm" class="form active" method="post" action="login_process.php" novalidate>
          <input type="email" class="form-control" name="email" placeholder="Email" required>
          <div class="password-wrapper">
            <input type="password" class="form-control" name="password" id="loginPassword" placeholder="Password" required>
            <span class="toggle-icon" data-toggle-target="loginPassword">Show</span>
          </div>
          <div class="text-center mb-2">
            <a href="forgot_password.php" class="text-decoration-none forgot-password-link">Forgot Password?</a>
          </div>
          <button type="submit" class="btn">Login</button>

          <div class="text-center mt-3">
            <a href="index.php" class="homepage-link text-decoration-none fw-semibold">Return to Homepage</a>
          </div>
        </form>

        <form id="registerForm" class="form" method="post" action="register_process.php" novalidate>
          <input type="text" class="form-control" name="first_name" placeholder="First Name" required>
          <input type="text" class="form-control" name="last_name" placeholder="Last Name" required>
          <input type="email" class="form-control" name="email" placeholder="Email" required>

          <div class="password-wrapper">
            <input type="password" class="form-control" name="password" id="regPassword" placeholder="Password" required>
            <span class="toggle-icon" data-toggle-target="regPassword">Show</span>
          </div>

          <div class="password-hint" id="passwordHint">
            <strong class="password-hint-title">Password must include:</strong>
            <ul id="passwordHintList" class="password-hint-list" aria-live="polite">
              <li data-rule="length" class="missing">At least 8 characters</li>
              <li data-rule="upper" class="missing">An uppercase letter</li>
              <li data-rule="lower" class="missing">A lowercase letter</li>
              <li data-rule="number" class="missing">A number</li>
              <li data-rule="special" class="missing">A special character (!@#$%)</li>
            </ul>
          </div>

          <div class="password-wrapper">
            <input type="password" class="form-control" name="confirm_password" id="confirmPassword" placeholder="Confirm Password" required>
            <span class="toggle-icon" data-toggle-target="confirmPassword">Show</span>
          </div>
          <div id="confirmFeedback" class="text-danger small text-start mb-2" hidden>Passwords do not match.</div>

          <select class="form-control" name="role" required>
            <option value="">Select Role</option>
            <option value="tenant">Tenant</option>
            <option value="unit_owner">Unit Owner</option>
          </select>

          <button type="submit" class="btn mt-4">Register</button>
        </form>
      </div>
    </div>
  </main>

  <footer class="site-footer">
    <div class="footer-inner">
      <span>&copy; <?= date('Y'); ?> HanapBahay. All rights reserved.</span>
    </div>
  </footer>

  <script>
    const showLogin = document.getElementById('showLogin');
    const showRegister = document.getElementById('showRegister');
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');

    showLogin.addEventListener('click', () => {
      loginForm.classList.add('active');
      registerForm.classList.remove('active');
      showLogin.classList.add('active');
      showRegister.classList.remove('active');
    });

    showRegister.addEventListener('click', () => {
      registerForm.classList.add('active');
      loginForm.classList.remove('active');
      showRegister.classList.add('active');
      showLogin.classList.remove('active');
    });

    document.querySelectorAll('.toggle-icon').forEach(btn => {
      btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-toggle-target');
        const input = document.getElementById(targetId);
        if (!input) {
          return;
        }
        const isVisible = input.type === 'text';
        input.type = isVisible ? 'password' : 'text';
        btn.textContent = isVisible ? 'Show' : 'Hide';
      });
    });

    const regPassword = document.getElementById('regPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    const passwordHint = document.getElementById('passwordHint');
    const passwordHintList = document.getElementById('passwordHintList');
    const confirmFeedback = document.getElementById('confirmFeedback');
    const passwordRulesList = passwordHintList ? passwordHintList.querySelectorAll('li') : [];

    const passwordPatterns = {
      length: /.{8,}/,
      upper: /[A-Z]/,
      lower: /[a-z]/,
      number: /\d/,
      special: /[^A-Za-z0-9]/
    };

    function showPasswordHint() {
      if (passwordHint) {
        passwordHint.classList.add('visible');
      }
    }

    function hidePasswordHint() {
      if (passwordHint) {
        passwordHint.classList.remove('visible');
      }
    }

    function evaluatePassword(value) {
      const results = {
        length: passwordPatterns.length.test(value),
        upper: passwordPatterns.upper.test(value),
        lower: passwordPatterns.lower.test(value),
        number: passwordPatterns.number.test(value),
        special: passwordPatterns.special.test(value)
      };
      results.allMet = Object.values(results).every(Boolean);
      return results;
    }

    function updatePasswordHint() {
      if (!regPassword || !passwordRulesList.length) {
        return { allMet: false };
      }
      const value = regPassword.value || '';
      const results = evaluatePassword(value);
      passwordRulesList.forEach(item => {
        const rule = item.getAttribute('data-rule');
        if (results[rule]) {
          item.classList.add('met');
          item.classList.remove('missing');
        } else {
          item.classList.add('missing');
          item.classList.remove('met');
        }
      });
      regPassword.classList.toggle('is-valid', results.allMet);
      regPassword.classList.toggle('is-invalid', !results.allMet && value.length > 0);
      return results;
    }

    function validateConfirmPassword() {
      if (!regPassword || !confirmPassword) {
        return false;
      }
      const matches = regPassword.value === confirmPassword.value && confirmPassword.value.length > 0;
      if (confirmFeedback) {
        if (matches) {
          confirmFeedback.hidden = true;
          confirmPassword.classList.add('is-valid');
          confirmPassword.classList.remove('is-invalid');
        } else if (confirmPassword.value.length > 0) {
          confirmFeedback.hidden = false;
          confirmPassword.classList.add('is-invalid');
          confirmPassword.classList.remove('is-valid');
        } else {
          confirmFeedback.hidden = true;
          confirmPassword.classList.remove('is-valid', 'is-invalid');
        }
      }
      return matches;
    }

    if (regPassword) {
      regPassword.addEventListener('focus', () => {
        showPasswordHint();
        updatePasswordHint();
      });
      regPassword.addEventListener('blur', () => {
        hidePasswordHint();
      });
      regPassword.addEventListener('input', () => {
        updatePasswordHint();
        validateConfirmPassword();
      });
    }

    if (confirmPassword) {
      confirmPassword.addEventListener('input', validateConfirmPassword);
    }

    registerForm.addEventListener('submit', (event) => {
      const passwordResults = updatePasswordHint();
      const passwordsMatch = validateConfirmPassword();
      if (!passwordResults.allMet) {
        event.preventDefault();
        regPassword.focus();
        showPasswordHint();
        return;
      }
      if (!passwordsMatch) {
        event.preventDefault();
        confirmPassword.focus();
      }
    });

    if (window.location.search.includes('register=1')) {
      showRegister.click();
    }
  </script>
</body>
</html>
