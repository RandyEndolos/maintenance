<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ERS | Welcome</title>
<style>
  :root {
    --maroon-700: #5a0f1b;
    --maroon-600: #7a1b2a;
    --maroon-500: #8b1e33;
    --maroon-400: #a42b43;
    --maroon-300: #c7475e;
    --white: #ffffff;
    --offwhite: #f9f6f7;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
    background: linear-gradient(160deg, var(--maroon-700), var(--maroon-500));
    color: var(--white);
    min-height: 100vh;
    display: grid;
    place-items: center;
  }

  .card {
    background: var(--offwhite);
    color: #2a2a2a;
    width: min(92vw, 720px);
    padding: 32px 28px;
    border-radius: 16px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
    border: 1px solid rgba(255,255,255,0.25);
  }

  .header {
    text-align: center;
    margin-bottom: 20px;
  }

  .title {
    margin: 0 0 8px 0;
    font-size: 32px;
    color: var(--maroon-700);
    letter-spacing: 0.3px;
  }

  .subtitle {
    margin: 0;
    font-size: 14px;
    color: #5b5b5b;
  }

  .buttons {
    margin-top: 20px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }

  .btn {
    appearance: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 14px 16px;
    font-weight: 600;
    font-size: 15px;
    border-radius: 10px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: transform 120ms ease, background-color 160ms ease, color 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
    text-decoration: none;
  }

  .btn:active {
    transform: translateY(1px);
  }

  .btn-primary {
    background: var(--maroon-600);
    color: var(--white);
    box-shadow: 0 6px 16px rgba(122, 27, 42, 0.35);
  }

  .btn-primary:hover {
    background: var(--maroon-400);
  }

  .btn-outline {
    background: var(--white);
    color: var(--maroon-700);
    border-color: var(--maroon-400);
  }

  .btn-outline:hover {
    background: #fff7f8;
    border-color: var(--maroon-600);
    color: var(--maroon-600);
  }

  .footer-note {
    margin-top: 18px;
    text-align: center;
    font-size: 12px;
    color: #6b6b6b;
  }

  /* Modal styles */
  .modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    z-index: 1000;
  }

  .modal-overlay[aria-hidden="false"] { display: flex; }

  .modal {
    width: min(92vw, 480px);
    background: var(--white);
    color: #2a2a2a;
    border-radius: 14px;
    box-shadow: 0 16px 36px rgba(0,0,0,0.35);
    border: 1px solid rgba(0,0,0,0.06);
  }

  .modal header {
    padding: 18px 20px 6px 20px;
  }

  .modal header { text-align: center; }
  .modal .modal-icon { display:flex; justify-content:center; margin-bottom:8px; }
  .modal .modal-icon img { width:64px; height:64px; object-fit:contain; border-radius:8px; }

  /* Site logo above main card */
  .site-logo { display: block; margin: 18px auto 6px auto; width: 220px; max-width: 92%; height: auto; }

  /* RCC small logo fixed to top-right (non-interactive) */
  .rcc-topright { position: fixed; top: 12px; right: 12px; width: 88px; height: auto; z-index: 1200; pointer-events: none; }

  .modal h2 {
    margin: 0;
    font-size: 20px;
    color: var(--maroon-700);
  }

  .modal .modal-body { padding: 8px 20px 20px 20px; }
  .modal form { display: grid; gap: 12px; }

  .modal label { font-size: 14px; color: #4a4a4a; }
  .modal input[type="text"],
  .modal input[type="email"],
  .modal input[type="password"] {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #d0d0d0;
    background: #fff;
    font-size: 14px;
  }

  .modal .actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }
  .modal .btn-cancel { background: #efefef; color: #222; }
  .modal .btn-submit { background: var(--maroon-600); color: var(--white); border-color: var(--maroon-600); }
  .modal .btn-submit:hover { background: var(--maroon-400); border-color: var(--maroon-400); }

  .error-text { color: #b42318; background: #fee4e2; border: 1px solid #f3b4ad; padding: 8px 10px; border-radius: 8px; font-size: 13px; }

  @media (max-width: 520px) {
    .buttons {
      grid-template-columns: 1fr;
    }
    .title { font-size: 26px; }
    .site-logo { width: 160px; }
    .rcc-topright { width: 64px; }
  }
  
  /* Bottom corner actions (about/manual) */
  .bottom-action {
    position: fixed;
    bottom: 14px;
    width: 64px;
    height: 64px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent; /* make background transparent to match image */
    border-radius: 10px;
    box-shadow: none; /* remove shadow so the image appears clean */
    z-index: 900;
    text-decoration: none;
    overflow: visible;
  }

  .bottom-action img { width: 48px; height: 48px; object-fit: contain; }
  .bottom-action.bottom-left { left: 14px; }
  .bottom-action.bottom-right { right: 14px; }

  @media (max-width: 520px) {
    .bottom-action { width: 56px; height: 56px; }
    .bottom-action img { width: 40px; height: 40px; }
  }
</style>
</head>
<body>
  <img src="../img/logo.png" alt="RCC" class="rcc-topright">
  <img src="../img/rcc.png" alt="ERS Logo" class="site-logo">
  <main class="card" role="main" aria-labelledby="welcome-title">
    <header class="header">
      <h1 id="welcome-title" class="title">Welcome to RCC Maintenance Request System</h1>
      <p class="subtitle">Please choose your portal to continue</p>
    </header>

     <section class="buttons" aria-label="Portal selection">
       <a class="btn btn-primary" href="#" data-open-modal="requester" role="button" aria-label="Requester">Requester</a>
       <a class="btn btn-primary" href="#" data-open-modal="staff" role="button" aria-label="Staff">Staff</a>
       <a class="btn btn-primary" href="#" data-open-modal="admin" role="button" aria-label="Admin">Admin</a>
       <a class="btn btn-outline" href="requestAccount.php" role="button" aria-label="Request for an Account">Request for an Account</a>
     </section>

  </main>

  <!-- Requester Login Modal (email + password) -->
  <div class="modal-overlay" id="modal-requester" aria-hidden="true" aria-labelledby="modal-requester-title" role="dialog">
    <div class="modal" role="document">
      <header>
        <div class="modal-icon"><img src="../img/requester.png" alt="Requester"></div>
        <h2 id="modal-requester-title">Requester Login</h2>
      </header>
      <div class="modal-body">
        <?php if (!empty($_GET['error']) && ($_GET['role'] ?? '') === 'requester'): ?>
          <div class="error-text" role="alert"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        <form method="post" action="../login.php">
          <input type="hidden" name="role" value="requester">
          <div>
            <label for="requester-email">Email</label>
            <input id="requester-email" name="email" type="email" autocomplete="email" required>
          </div>
          <div>
            <label for="requester-password">Password</label>
            <input id="requester-password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <div class="actions">
            <button type="button" class="btn btn-outline btn-cancel" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary btn-submit">Login</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Staff Login Modal (name + password) -->
  <div class="modal-overlay" id="modal-staff" aria-hidden="true" aria-labelledby="modal-staff-title" role="dialog">
    <div class="modal" role="document">
      <header>
        <div class="modal-icon"><img src="../img/staff.png" alt="Staff"></div>
        <h2 id="modal-staff-title">Staff Login</h2>
      </header>
      <div class="modal-body">
        <?php if (!empty($_GET['error']) && ($_GET['role'] ?? '') === 'staff'): ?>
          <div class="error-text" role="alert"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        <form method="post" action="../login.php">
          <input type="hidden" name="role" value="staff">
          <div>
            <label for="staff-name">Name</label>
            <input id="staff-name" name="name" type="text" autocomplete="username" required>
          </div>
          <div>
            <label for="staff-password">Password</label>
            <input id="staff-password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <div class="actions">
            <button type="button" class="btn btn-outline btn-cancel" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary btn-submit">Login</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Admin Login Modal (email + password) -->
  <div class="modal-overlay" id="modal-admin" aria-hidden="true" aria-labelledby="modal-admin-title" role="dialog">
    <div class="modal" role="document">
      <header>
        <div class="modal-icon"><img src="../img/admin.png" alt="Admin"></div>
        <h2 id="modal-admin-title">Admin Login</h2>
      </header>
      <div class="modal-body">
        <?php if (!empty($_GET['error']) && ($_GET['role'] ?? '') === 'admin'): ?>
          <div class="error-text" role="alert"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        <form method="post" action="../login.php">
          <input type="hidden" name="role" value="admin">
          <div>
            <label for="admin-email">Email</label>
            <input id="admin-email" name="email" type="email" autocomplete="email" required>
          </div>
          <div>
            <label for="admin-password">Password</label>
            <input id="admin-password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <div class="actions">
            <button type="button" class="btn btn-outline btn-cancel" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary btn-submit">Login</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    (function() {
      function openModal(role) {
        var overlay = document.getElementById('modal-' + role);
        if (!overlay) return;
        overlay.setAttribute('aria-hidden', 'false');
        // Focus first input
        var input = overlay.querySelector('input[type="email"], input[type="text"]');
        if (input) setTimeout(function(){ input.focus(); }, 50);
      }

      function closeModal(overlay) {
        overlay.setAttribute('aria-hidden', 'true');
      }

      // Open buttons
      document.querySelectorAll('[data-open-modal]').forEach(function(btn){
        btn.addEventListener('click', function(e){
          e.preventDefault();
          openModal(btn.getAttribute('data-open-modal'));
        });
      });

      // Close buttons
      document.querySelectorAll('[data-close-modal]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var overlay = btn.closest('.modal-overlay');
          if (overlay) closeModal(overlay);
        });
      });

      // Click outside to close
      document.querySelectorAll('.modal-overlay').forEach(function(overlay){
        overlay.addEventListener('click', function(e){
          if (e.target === overlay) closeModal(overlay);
        });
      });

      // Esc to close
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
          document.querySelectorAll('.modal-overlay[aria-hidden="false"]').forEach(function(overlay){
            closeModal(overlay);
          });
        }
      });

      // If server sent an error with role, open that modal automatically
      var params = new URLSearchParams(window.location.search);
      var err = params.get('error');
      var role = params.get('role');
      if (err && role) {
        openModal(role);
      }
    })();
    // Placeholder click handlers for bottom-action buttons
    document.querySelectorAll('.bottom-action').forEach(function(btn){
      btn.addEventListener('click', function(e){
        e.preventDefault();
        var action = btn.getAttribute('data-action');
        // Future: call the action handler for this button
        console.log('Bottom action clicked:', action);
      });
    });
  </script>
  
  <!-- About and Manual quick-action buttons (placeholders for future functions) -->
  <a href="#" role="button" class="bottom-action bottom-left" data-action="about" aria-label="About">
    <img src="../img/about.png" alt="About">
  </a>
  <a href="#" role="button" class="bottom-action bottom-right" data-action="manual" aria-label="Manual">
    <img src="../img/manual.png" alt="Manual">
  </a>
</body>
</html>

