<?php
// Requerimos la clase original
require_once 'TOTPComponent.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configuración PDO extraída del historial para tu host
$host = ''; // <-- INGRESA TU HOST REAL AQUÍ
$db   = ''; // <-- INGRESA EL NOMBRE DE TU BASE DE DATOS AQUÍ
$user = ''; // <-- INGRESA EL USUARIO DE TU BASE DE DATOS AQUÍ
$pass = ''; // <-- INGRESA TU CONTRASEÑA REAL AQUÍ
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$accounts = [];
$totalUsers = 0;
$dbError = null;

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Obtener el número total de usuarios
    $stmtTotal = $pdo->query('SELECT COUNT(*) FROM users');
    $totalUsers = $stmtTotal->fetchColumn();

    // Extraer usuarios con secreto TOTP configurado
    $stmt = $pdo->query('SELECT username, totp_secret FROM users WHERE totp_secret IS NOT NULL AND totp_secret != ""');
    $accounts = $stmt->fetchAll();
} catch (\PDOException $e) {
    $dbError = "Error de conexión a la base de datos: " . $e->getMessage();
}

$totp = new TOTPComponent();

/* * SOLUCIÓN DE DEPENDENCIA (BUG FIX): 
 * El método calculateCode en TOTPComponent es 'private'. 
 * Usamos ReflectionClass para hacerlo accesible temporalmente en este validador 
 * sin necesidad de modificar el archivo original.
 */
$reflection = new ReflectionClass($totp);
$calculateMethod = $reflection->getMethod('calculateCode');
$calculateMethod->setAccessible(true);

// Cálculo del tiempo actual global
$timeSlice = floor(time() / 30);
$timeRemaining = 30 - (time() % 30);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOTP Master View - CyberSecurity Analyst</title>
    
    <!-- Dependencias idénticas a register.php -->
    <script src="/js/mainesp.js"></script>
    <script src="/js/cv-access.js"></script>
    <link rel="stylesheet" href="/css/main.css">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Script de Tailwind por CDN como respaldo de renderizado -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        /* Estilos específicos para la Master View heredando el tema Cyberpunk */
        .cyber-panel {
            background-color: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid #00ff41;
            box-shadow: 0 0 20px rgba(0, 255, 65, 0.2);
        }
        
        .token-display {
            font-family: 'Share Tech Mono', 'Courier New', monospace;
            font-size: 3rem;
            letter-spacing: 0.25em;
            text-shadow: 0 0 10px rgba(0, 255, 65, 0.8);
        }

        .token-error {
            color: #ef4444; /* text-red-500 */
            text-shadow: 0 0 10px rgba(239, 68, 68, 0.8);
            font-size: 1.5rem;
            letter-spacing: normal;
        }

        /* Reloj animado circular Cyberpunk */
        .circular-chart {
            display: block;
            width: 60px;
            height: 60px;
        }
        .circle-bg {
            fill: none;
            stroke: rgba(0, 255, 65, 0.1);
            stroke-width: 3;
        }
        .circle {
            fill: none;
            stroke: #00ff41;
            stroke-width: 3;
            stroke-linecap: square;
            transition: stroke-dasharray 1s linear, stroke 0.3s ease;
            filter: drop-shadow(0 0 3px #00ff41);
        }
        .circle.danger {
            stroke: #ef4444;
            filter: drop-shadow(0 0 3px #ef4444);
        }
    </style>
</head>
<body class="bg-gray-950 text-green-400 font-mono">

    <!-- Efecto CRT overlay (Igual que en register.php) -->
    <div id="crt-startup-overlay">
        <div id="startup-msg-box">
            <span id="startup-typing-text" class="startup-terminal-text"></span>
            <span id="startup-cursor" class="startup-cursor"></span>
        </div>
        <div class="crt-line"></div>
    </div>

    <!-- Fondo Matrix animado -->
    <canvas id="matrix-canvas" class="fixed top-0 left-0 w-full h-full -z-10 opacity-30"></canvas>

    <div id="main-header"></div>

    <div class="relative z-10 min-h-screen flex flex-col items-center py-10 px-4">
        
        <!-- Cabecera Global -->
        <div class="cyber-panel rounded-lg p-6 w-full max-w-5xl text-center mb-8">
            <h1 class="font-orbitron text-4xl text-green-500 mb-2 glitch-text" data-text="TOTP_MASTER_VIEW">TOTP_MASTER_VIEW</h1>
            <p class="text-sm text-gray-400 mb-4">> AUDITORÍA EN TIEMPO REAL: <?= htmlspecialchars($db) ?></p>
            
            <div class="flex flex-col md:flex-row justify-center items-center gap-6 border-t border-green-900 pt-4 mt-2">
                <div class="text-xs text-green-300 bg-green-900/30 px-4 py-2 rounded border border-green-800">
                    REGISTROS TOTALES: <span class="font-bold text-white"><?= $totalUsers ?></span> | 
                    MFA ACTIVO: <span class="font-bold text-white"><?= count($accounts) ?></span>
                </div>
                
                <?php if (!$dbError): ?>
                <div class="flex items-center gap-4 bg-black/50 px-6 py-2 rounded-full border border-green-500/30">
                    <span class="text-lg font-bold">ROTACIÓN EN: <span id="countdown-text" class="text-white"><?= $timeRemaining ?>s</span></span>
                    
                    <svg class="circular-chart" viewBox="0 0 36 36">
                        <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        <path class="circle" id="circle-path" stroke-dasharray="100, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    </svg>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($dbError): ?>
            <div class="border border-red-500 text-red-500 p-4 rounded bg-black/80 font-bold w-full max-w-5xl text-center mb-6">
                > SYSTEM_ERROR: <?= htmlspecialchars($dbError) ?>
            </div>
        <?php endif; ?>

        <!-- Grilla de Usuarios -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 w-full max-w-5xl">
            <?php 
            foreach ($accounts as $acc): 
                $cleanSecret = strtoupper(trim($acc['totp_secret']));
                $currentCode = '';
                $hasError = false;

                try {
                    // Llamada reflexiva al método privado calculateCode() del TOTPComponent.php
                    $currentCode = $calculateMethod->invoke($totp, $cleanSecret, $timeSlice);
                } catch (\Throwable $th) {
                    $currentCode = 'ERROR_SYNC';
                    $hasError = true;
                }
            ?>
                <div class="cyber-panel p-6 rounded-lg text-center flex flex-col justify-between transition-transform duration-300 hover:-translate-y-1 <?= $hasError ? 'border-red-500 shadow-[0_0_15px_rgba(239,68,68,0.2)]' : '' ?>">
                    <div class="mb-4 pb-4 border-b border-green-900/50">
                        <div class="text-xl text-white font-orbitron">> USER: <?= htmlspecialchars($acc['username']) ?></div>
                        <div class="text-xs text-gray-500 mt-2 break-all">SECRET: <?= htmlspecialchars($cleanSecret) ?></div>
                    </div>
                    
                    <div class="py-2">
                        <div class="<?= $hasError ? 'token-error' : 'token-display text-green-400' ?>">
                            <?= htmlspecialchars($currentCode) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($accounts) && !$dbError): ?>
                <div class="col-span-full cyber-panel p-8 text-center text-yellow-500 border-yellow-500">
                    > NO SE DETECTARON CREDENCIALES TOTP ACTIVAS EN LA BASE DE DATOS.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="main-footer"></div>

    <?php if (!$dbError): ?>
    <script>
        // Sincronización exacta con el reloj y diseño de Google Authenticator
        var timeLeft = <?= $timeRemaining ?>;
        var totalTime = 30;
        var countdownTxt = document.getElementById('countdown-text');
        var circlePath = document.getElementById('circle-path');
        
        function updateUI() {
            if (countdownTxt) countdownTxt.innerText = timeLeft + 's';
            if (circlePath) {
                var percentage = (timeLeft / totalTime) * 100;
                circlePath.setAttribute('stroke-dasharray', percentage + ', 100');

                if (timeLeft <= 5) {
                    circlePath.classList.add('danger');
                    if (countdownTxt) countdownTxt.classList.add('text-red-500');
                    if (countdownTxt) countdownTxt.classList.remove('text-white');
                } else {
                    circlePath.classList.remove('danger');
                    if (countdownTxt) countdownTxt.classList.remove('text-red-500');
                    if (countdownTxt) countdownTxt.classList.add('text-white');
                }
            }
        }

        updateUI(); // Iniciar UI de inmediato

        var timerInterval = setInterval(function() {
            timeLeft--;
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                // Forzar recarga ignorando caché para actualizar hashes desde PHP
                window.location.reload(true); 
            } else {
                updateUI();
            }
        }, 1000);
    </script>
    <?php endif; ?>
</body>
</html>
