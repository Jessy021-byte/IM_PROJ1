<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (isset($_SESSION['demo_signed_in'])) {
    header('Location: admin.php');
    exit;
}

$login_error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>JLP Inventory Desk | Sign in</title>
  <link rel="stylesheet" href="styles.css">
  <!-- Lucide Icons CDN -->
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-page">

  <!-- Animated Background Canvas -->
  <canvas id="bg-canvas"></canvas>

  <!-- Ambient Glowing Background Orbs -->
  <div class="ambient-glow glow-1"></div>
  <div class="ambient-glow glow-2"></div>

  <!-- Toast Notification Container -->
  <div id="toast-container" class="toast-container"></div>

  <!-- Sign-in Card Container -->
  <main class="auth-wrapper">
    <div class="auth-card">
      
      <!-- Card Header / Logo -->
      <div class="auth-header">
        <div class="logo-icon">
          <i data-lucide="shield-check"></i>
        </div>
        <h1 class="auth-title">Welcome</h1>
        <p class="auth-subtitle">Sign in to manage the JLP Inventory Desk</p>
        <?php if ($login_error !== null): ?>
          <p class="auth-error" role="alert"><?= escape_html($login_error) ?></p>
        <?php endif; ?>
      </div>

      <!-- Tab Switcher -->
      <div class="tab-box">
        <button id="tab-login" class="tab-btn active" type="button" aria-selected="true">
          <i data-lucide="log-in"></i> Sign In
        </button>
        <button id="tab-register" class="tab-btn" type="button" aria-selected="false">
          <i data-lucide="user-plus"></i> Register
        </button>
        <div class="tab-indicator" id="tab-indicator"></div>
      </div>

      <!-- Form Container -->
      <div class="form-container">
        
        <!-- SIGN IN FORM -->
        <form id="login-form" class="auth-form active" method="post" action="admin.php">
          <input type="hidden" name="action" value="demo_login">
          <input type="hidden" name="csrf_token" value="<?= escape_html($_SESSION['csrf_token']) ?>">
          <div class="input-group">
            <label for="login-email">Email Address</label>
            <div class="input-wrapper">
              <i data-lucide="mail" class="field-icon"></i>
              <input type="email" id="login-email" name="email" placeholder="name@example.com" required>
            </div>
          </div>

          <div class="input-group">
            <label for="login-password">Password</label>
            <div class="input-wrapper">
              <i data-lucide="lock" class="field-icon"></i>
              <input type="password" id="login-password" name="password" placeholder="••••••••" required>
              <button type="button" class="toggle-password" data-password-toggle="login-password" aria-label="Show password" aria-pressed="false">
                <i data-lucide="eye"></i>
              </button>
            </div>
          </div>

          <div class="form-options">
            <label class="checkbox-container">
              <input type="checkbox" id="remember-me">
              <span class="checkmark"></span>
              Remember me
            </label>
            <a href="#forgot-modal" class="forgot-link" id="forgot-link">Forgot password?</a>
          </div>

          <button type="submit" class="btn-submit">
            <span class="btn-text">Sign In</span>
            <i data-lucide="arrow-right" class="btn-icon"></i>
          </button>

          <div class="divider">
            <span>OR CONTINUE WITH</span>
          </div>

          <div class="social-group">
            <button type="button" class="btn-social" title="GitHub" data-social-provider="GitHub">
              <i data-lucide="github"></i>
            </button>
          </div>
        </form>

        <!-- REGISTER FORM -->
        <form id="register-form" class="auth-form">
          <div class="input-group">
            <label for="reg-name">Full Name</label>
            <div class="input-wrapper">
              <i data-lucide="user" class="field-icon"></i>
              <input type="text" id="reg-name" name="name" placeholder="Your name" required>
            </div>
          </div>

          <div class="input-group">
            <label for="reg-email">Email Address</label>
            <div class="input-wrapper">
              <i data-lucide="mail" class="field-icon"></i>
              <input type="email" id="reg-email" placeholder="name@example.com" required>
            </div>
          </div>

          <div class="input-group">
            <label for="reg-password">Password</label>
            <div class="input-wrapper">
              <i data-lucide="lock" class="field-icon"></i>
              <input type="password" id="reg-password" placeholder="••••••••" required>
              <button type="button" class="toggle-password" data-password-toggle="reg-password" aria-label="Show password" aria-pressed="false">
                <i data-lucide="eye"></i>
              </button>
            </div>
            
            <!-- Password -->
            <div class="strength-meter">
              <div class="strength-bar" id="strength-bar"></div>
            </div>
            <span class="strength-text" id="strength-text">Password strength</span>
          </div>

          <div class="input-group">
            <label for="reg-confirm-password">Confirm Password</label>
            <div class="input-wrapper">
              <i data-lucide="shield-check" class="field-icon"></i>
              <input type="password" id="reg-confirm-password" placeholder="••••••••" required>
            </div>
          </div>

          <div class="form-options">
            <label class="checkbox-container">
              <input type="checkbox" id="terms-check" required>
              <span class="checkmark"></span>
              I agree to the <a href="#" class="inline-link">Terms & Conditions</a>
            </label>
          </div>

          <button type="submit" class="btn-submit">
            <span class="btn-text">Create Account</span>
            <i data-lucide="user-check" class="btn-icon"></i>
          </button>

          <div class="divider">
            <span>OR REGISTER WITH</span>
          </div>

          <div class="social-group">
            <button type="button" class="btn-social" title="Google" data-social-provider="Google">
              <i data-lucide="chrome"></i>
            </button>
            <button type="button" class="btn-social" title="GitHub" data-social-provider="GitHub">
              <i data-lucide="github"></i>
            </button>
            <button type="button" class="btn-social" title="Apple" data-social-provider="Apple">
              <i data-lucide="apple"></i>
            </button>
          </div>
        </form>

      </div>
    </div>
  </main>

  <!-- Forgot Password -->
  <div id="forgot-modal" class="modal-backdrop">
    <div class="modal-card">
      <button class="modal-close" type="button" aria-label="Close password reset dialog">
        <i data-lucide="x"></i>
      </button>
      <div class="modal-header">
        <i data-lucide="key-round" class="modal-icon"></i>
        <h2>Reset Password</h2>
        <p>Enter your email address and we'll send you instructions to reset your password.</p>
      </div>
      <form id="reset-form">
        <div class="input-group">
          <label for="reset-email">Email Address</label>
          <div class="input-wrapper">
            <i data-lucide="mail" class="field-icon"></i>
            <input type="email" id="reset-email" placeholder="name@example.com" required>
          </div>
        </div>
        <button type="submit" class="btn-submit">
          <span>Send Reset Link</span>
          <i data-lucide="send"></i>
        </button>
      </form>
    </div>
  </div>

  <script src="script.js"></script>
</body>
</html>
