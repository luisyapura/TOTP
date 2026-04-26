<?php
// requiere la inclusión de la clase previa:
// require_once 'TOTPComponent.php';
require_once 'TOTPComponent.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Configuración PDO
$dsn = 'mysql:host=localhost;dbname=icsaqyyw_auth_poc;charset=utf8mb4';
$dbUser = 'icsaqyyw_poc'; // Ajustar credenciales
$dbPass = '155007287+James$';

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

$totp = new TOTPComponent();
$action = $_GET['action'] ?? 'login';

$message = '';
$qrUrl = '';
$secret = '';

if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $secret = $totp->generateSecret();

    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, totp_secret) VALUES (?, ?, ?)");
    try {
        $stmt->execute([$username, $password, $secret]);
        $qrUrl = $totp->getQRCodeUrl('POC_Sistema', $username, $secret);
        $action = 'registered';
    } catch (PDOException $e) {
        $message = "Error: Usuario duplicado o fallo en DB.";
    }
} elseif ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT id, password_hash, totp_secret FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['pending_user_id'] = $user['id'];
        $_SESSION['pending_totp_secret'] = $user['totp_secret'];
        header('Location: ?action=verify_totp');
        exit;
    } else {
        $message = "Credenciales incorrectas.";
    }
} elseif ($action === 'verify_totp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['totp_code'];
    $temp_secret = $_SESSION['pending_totp_secret'];

    if ($totp->verifyCode($temp_secret, $code)) {
        $_SESSION['user_id'] = $_SESSION['pending_user_id'];
        unset($_SESSION['pending_user_id'], $_SESSION['pending_totp_secret']);
        $action = 'success';
    } else {
        $message = "Código TOTP inválido.";
    }
} elseif ($action === 'logout') {
    session_destroy();
    header('Location: ?action=login');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOTP Access Terminal - CyberSecurity Analyst</title>
    
    <script src="/js/mainesp.js"></script>
    <script src="/js/cv-access.js"></script>
    
    <link rel="stylesheet" href="/css/main.css">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Estilos de inputs alineados con main.css */
        .cyber-input {
            background-color: rgba(0, 0, 0, 0.5);
            border: 1px solid #00ff41;
            color: #00ff41;
            width: 100%;
            padding: 0.75rem;
            margin-bottom: 1.5rem;
            border-radius: 0.25rem;
            font-family: 'Share Tech Mono', monospace;
            transition: all 0.3s ease;
        }
        .cyber-input:focus {
            outline: none;
            box-shadow: 0 0 10px rgba(0, 255, 65, 0.5);
            background-color: rgba(0, 255, 65, 0.1);
        }
        .cyber-input::placeholder {
            color: rgba(0, 255, 65, 0.5);
        }
    </style>
</head>
<body class="bg-gray-950 text-green-400">

    <div id="crt-startup-overlay">
        <div id="startup-msg-box">
            <span id="startup-typing-text" class="startup-terminal-text"></span>
            <span id="startup-cursor" class="startup-cursor"></span>
        </div>
        <div class="crt-line"></div>
    </div>

    <canvas id="matrix-canvas"></canvas>

    <div id="main-header"></div>

    <div class="relative z-10">
        <main class="container mx-auto px-2 py-4 md:py-0">
            <section id="hero" class="min-h-[85vh] flex flex-col items-center justify-center text-center overflow-x-hidden">
                
                <div class="bg-gray-900/50 backdrop-blur-md border border-[#0f0] rounded-lg p-8 shadow-[0_0_20px_#0f0] w-full max-w-md text-center">
                    
                    <?php if ($message): ?>
                        <div class="border border-red-500 text-red-500 p-2 mb-4 rounded bg-black/50 font-bold text-sm">
                            > SYSTEM_ERROR: <?= htmlspecialchars($message) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($action === 'login'): ?>
                        <h2 class="font-orbitron text-3xl text-green-500 mb-6 glitch-text" data-text="SYS_LOGIN">SYS_LOGIN</h2>
                        <form method="POST" action="?action=login">
                            <input type="text" name="username" class="cyber-input" placeholder="> USERNAME" required autofocus>
                            <input type="password" name="password" class="cyber-input" placeholder="> PASSWORD" required>
                            <button type="submit" class="matrix-button w-full mb-4">INICIAR CONEXIÓN</button>
                        </form>
                        <a href="?action=register" class="text-green-400 hover:text-white transition-colors text-sm">> Solicitar acceso (Registro)</a>

                    <?php elseif ($action === 'register'): ?>
                        <h2 class="font-orbitron text-3xl text-green-500 mb-6 glitch-text" data-text="REGISTRATION">REGISTRATION</h2>
                        <form method="POST" action="?action=register">
                            <input type="text" name="username" class="cyber-input" placeholder="> NEW_USERNAME" required autofocus>
                            <input type="password" name="password" class="cyber-input" placeholder="> NEW_PASSWORD" required>
                            <button type="submit" class="matrix-button w-full mb-4">GENERAR CREDENCIALES</button>
                        </form>
                        <a href="?action=login" class="text-green-400 hover:text-white transition-colors text-sm">> Volver al Login</a>

                    <?php elseif ($action === 'registered'): ?>
                        <h2 class="font-orbitron text-2xl text-green-500 mb-4">MFA_SETUP_REQUIRED</h2>
                        <p class="text-sm mb-4 text-gray-300">Escanea el código con Google Authenticator para inicializar tu token.</p>
                        
                        <div class="flex justify-center mb-6">
                            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Code" class="matrix-image-frame bg-white p-2">
                        </div>
                        
                        <p class="text-xs text-green-400 mb-6">SECRET_KEY: <?= htmlspecialchars($secret) ?></p>
                        <a href="?action=login" class="matrix-button w-full block">PROCEDER AL LOGIN</a>

                    <?php elseif ($action === 'verify_totp'): ?>
                        <h2 class="font-orbitron text-3xl text-green-500 mb-6 glitch-text" data-text="2FA_REQUIRED">2FA_REQUIRED</h2>
                        <p class="text-sm mb-6 text-gray-300">Introduce el token temporal generado por tu dispositivo.</p>
                        <form method="POST" action="?action=verify_totp">
                            <input type="text" name="totp_code" class="cyber-input text-center text-2xl tracking-widest" placeholder="000000" maxlength="6" required autofocus autocomplete="off">
                            <button type="submit" class="matrix-button w-full mb-4">AUTENTICAR TOKEN</button>
                        </form>
                        <a href="?action=login" class="text-red-400 hover:text-red-300 transition-colors text-sm">> Abortar secuencia</a>

                    <?php elseif ($action === 'success'): ?>
                        <h2 class="font-orbitron text-3xl text-green-500 mb-6 glitch-text" data-text="ACCESS_GRANTED">ACCESS_GRANTED</h2>
                        <div class="text-green-400 mb-8 border border-green-500 p-4 bg-green-500/10 rounded">
                            > Conexión segura establecida.<br>
                            > Bienvenido al sistema central.
                        </div>
                        <a href="?action=logout" class="futuristic-btn w-full block py-2 rounded text-center">DESCONECTAR</a>
                    
                    <?php else: ?>
                        <?php header('Location: ?action=login'); exit; ?>
                    <?php endif; ?>
                </div>

            </section>
        </main>
    </div>

    <div id="main-footer"></div>

</body>
</html>
