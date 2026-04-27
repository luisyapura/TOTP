<?php
// Requerimos el archivo que contiene la clase original para no duplicar código lógico.
require_once 'TOTPComponent.php';

// Habilitar la visualización de errores de PHP.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==========================================
// CONFIGURACIÓN DE BASE DE DATOS (PDO)
// ==========================================
$host = 'localhost'; // Host de conexion de la base de datos
$db   = ''; // Nombre de la base de datos
$user = ''; // Usuario de la base de datos
$pass = ''; // Contraseña asignada para la auditoría
$charset = 'utf8mb4'; // Codificación segura para caracteres especiales
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
    // Intentamos establecer la conexión con la base de datos.
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Obtener la cantidad absoluta de usuarios registrados en la tabla 'users'.
    $stmtTotal = $pdo->query('SELECT COUNT(*) FROM users');
    $totalUsers = $stmtTotal->fetchColumn();

    // Extraer únicamente los usuarios que han configurado su secreto de autenticación (2FA).
    $stmt = $pdo->query('SELECT username, totp_secret FROM users WHERE totp_secret IS NOT NULL AND totp_secret != ""');
    $accounts = $stmt->fetchAll();
} catch (\PDOException $e) {
    $dbError = "Error de conexión a la base de datos: " . $e->getMessage();
}

$totp = new TOTPComponent();

// ==========================================
// SOLUCIÓN DE DEPENDENCIA MEDIANTE REFLEXIÓN (REFLECTION API)
// ==========================================
$reflection = new ReflectionClass($totp);
$calculateMethod = $reflection->getMethod('calculateCode');
$calculateMethod->setAccessible(true); // Rompemos la encapsulación privada.

// Cálculo del bloque de tiempo actual
$timeSlice = floor(time() / 30);
$timeRemaining = 30 - (time() % 30);

// ==========================================
// INTERCEPTOR AJAX (ACTUALIZACIÓN SILENCIOSA)
// ==========================================
// Si la petición viene de nuestro script JS buscando nuevos tokens (fetch_tokens=1)
// procesamos matemáticamente los códigos y devolvemos JSON sin recargar el HTML.
if (isset($_GET['fetch_tokens']) && $_GET['fetch_tokens'] === '1') {
    header('Content-Type: application/json');
    $newTokens = [];
    
    foreach ($accounts as $acc) {
        $cleanSecret = strtoupper(trim($acc['totp_secret']));
        $userId = md5($acc['username']); // Identificador único para el div en HTML
        
        try {
            $code = $calculateMethod->invoke($totp, $cleanSecret, $timeSlice);
            $newTokens[$userId] = ['code' => $code, 'error' => false];
        } catch (\Throwable $th) {
            $newTokens[$userId] = ['code' => 'ERROR_SYNC', 'error' => true];
        }
    }
    
    // Devolvemos el array codificado y finalizamos el script abruptamente
    echo json_encode(['status' => 'ok', 'tokens' => $newTokens]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOTP Master View - CyberSecurity Analyst</title>
    
    <script src="/js/mainesp.js"></script>
    <script src="/js/cv-access.js"></script>
    <link rel="stylesheet" href="/css/main.css">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
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
            color: #ef4444; 
            text-shadow: 0 0 10px rgba(239, 68, 68, 0.8);
            font-size: 1.5rem;
            letter-spacing: normal;
        }

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

        .system-link-btn {
            border: 1px solid #00ff41;
            color: #00ff41;
            transition: all 0.3s ease;
            text-transform: uppercase;
            font-family: 'Share Tech Mono', monospace;
        }
        .system-link-btn:hover {
            background-color: #00ff41; 
            color: #000;
            box-shadow: 0 0 15px rgba(0, 255, 65, 0.6);
        }
        .system-link-btn-alt {
            border: 1px dashed #00ff41;
            background-color: rgba(0, 255, 65, 0.05);
        }
    </style>
</head>
<body class="bg-gray-950 text-green-400 font-mono">

    <div id="crt-startup-overlay">
        <div id="startup-msg-box">
            <span id="startup-typing-text" class="startup-terminal-text"></span>
            <span id="startup-cursor" class="startup-cursor"></span>
        </div>
        <div class="crt-line"></div>
    </div>

    <!-- Fondo Matrix animado (Ya no se interrumpirá al llegar el contador a 0) -->
    <canvas id="matrix-canvas" class="fixed top-0 left-0 w-full h-full -z-10 opacity-30"></canvas>

    <div id="main-header"></div>

    <div class="relative z-10 min-h-screen flex flex-col xl:flex-row items-start justify-center py-10 px-4 gap-8 max-w-[1400px] mx-auto">
        
        <!-- [COLUMNA IZQUIERDA] -->
        <div class="flex-1 w-full max-w-5xl flex flex-col items-center">
            
            <div class="cyber-panel rounded-lg p-6 w-full text-center mb-8">
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
                <div class="border border-red-500 text-red-500 p-4 rounded bg-black/80 font-bold w-full text-center mb-6">
                    > SYSTEM_ERROR: <?= htmlspecialchars($dbError) ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 w-full">
                <?php 
                foreach ($accounts as $acc): 
                    $cleanSecret = strtoupper(trim($acc['totp_secret']));
                    $currentCode = '';
                    $hasError = false;
                    
                    // Creamos un ID único usando el hash del usuario para identificar la tarjeta en JavaScript
                    $cardId = md5($acc['username']); 

                    try {
                        $currentCode = $calculateMethod->invoke($totp, $cleanSecret, $timeSlice);
                    } catch (\Throwable $th) {
                        $currentCode = 'ERROR_SYNC';
                        $hasError = true;
                    }
                ?>
                    <!-- Asignamos el ID único al panel y al código -->
                    <div id="card-<?= $cardId ?>" class="cyber-panel p-6 rounded-lg text-center flex flex-col justify-between transition-transform duration-300 hover:-translate-y-1 <?= $hasError ? 'border-red-500 shadow-[0_0_15px_rgba(239,68,68,0.2)]' : '' ?>">
                        <div class="mb-4 pb-4 border-b border-green-900/50">
                            <div class="text-xl text-white font-orbitron">> USER: <?= htmlspecialchars($acc['username']) ?></div>
                            <div class="text-xs text-gray-500 mt-2 break-all">SECRET: <?= htmlspecialchars($cleanSecret) ?></div>
                        </div>
                        
                        <div class="py-2">
                            <!-- El div que contiene el token ahora es identificable vía JS -->
                            <div id="token-<?= $cardId ?>" class="<?= $hasError ? 'token-error' : 'token-display text-green-400' ?>">
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

        <!-- [COLUMNA DERECHA]: PANEL DE NAVEGACIÓN -->
        <div class="w-full xl:w-80 flex flex-col gap-4 sticky top-10 mt-8 xl:mt-0">
            <div class="bg-gray-950/80 backdrop-blur-sm border border-green-900/60 p-6 rounded-lg shadow-[0_0_15px_rgba(0,255,65,0.05)]">
                <div class="text-xs text-green-600 mb-6 text-center tracking-widest font-bold border-b border-green-900/50 pb-4">
                    > NAVEGACIÓN_DEL_SISTEMA
                </div>
                
                <div class="flex flex-col gap-4">
                    <a href="register.php?action=login" class="system-link-btn py-4 px-4 text-center text-sm font-bold flex items-center justify-center rounded">
                        <i class="fas fa-sign-in-alt mr-2"></i> INICIAR SESIÓN
                    </a>
                    
                    <a href="register.php?action=register" class="system-link-btn system-link-btn-alt py-4 px-4 text-center text-sm font-bold flex items-center justify-center rounded">
                        <i class="fas fa-user-plus mr-2"></i> REGISTRAR NUEVO USUARIO
                    </a>
                </div>
            </div>
        </div>

    </div>

    <div id="main-footer"></div>

    <?php if (!$dbError): ?>
    <script>
        // 1. SINCRONIZACIÓN DE RELOJES (Offset Criptográfico)
        // Tomamos el tiempo exacto del servidor en milisegundos y lo comparamos con la máquina local
        var serverTime = <?= time() ?> * 1000;
        var localTimeInit = Date.now();
        var timeOffset = serverTime - localTimeInit; // Desfase entre el servidor y el cliente
        
        var totalTime = 30; 
        var countdownTxt = document.getElementById('countdown-text');
        var circlePath = document.getElementById('circle-path');
        
        // Calculamos en qué bloque exacto de 30 segundos del Epoch Unix estamos
        var currentSlice = Math.floor((Date.now() + timeOffset) / 1000 / 30);
        
        function updateUI() {
            // Obtenemos el tiempo actual exacto sincronizado con el servidor
            var nowSecs = Math.floor((Date.now() + timeOffset) / 1000);
            
            // Calculamos matemáticamente el tiempo restante (garantiza exactitud absoluta)
            var timeLeft = 30 - (nowSecs % 30);
            var newSlice = Math.floor(nowSecs / 30);
            
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

            // 2. DISPARADOR AJAX EXACTO
            // Si detectamos que entramos a un nuevo bloque de 30 segundos, solicitamos los nuevos códigos
            if (newSlice > currentSlice) {
                currentSlice = newSlice;
                
                fetch(window.location.pathname + '?fetch_tokens=1')
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'ok') {
                            // Inyectamos los nuevos códigos actualizados dinámicamente sin recargar la web
                            for (const [userId, tokenData] of Object.entries(data.tokens)) {
                                const tokenElement = document.getElementById('token-' + userId);
                                const cardElement = document.getElementById('card-' + userId);
                                
                                if (tokenElement && cardElement) {
                                    tokenElement.innerText = tokenData.code;
                                    
                                    if (tokenData.error) {
                                        tokenElement.className = 'token-error';
                                        cardElement.classList.add('border-red-500', 'shadow-[0_0_15px_rgba(239,68,68,0.2)]');
                                    } else {
                                        tokenElement.className = 'token-display text-green-400';
                                        cardElement.classList.remove('border-red-500', 'shadow-[0_0_15px_rgba(239,68,68,0.2)]');
                                    }
                                }
                            }
                        }
                    })
                    .catch(error => console.error('> ERROR DE RED OBTENIENDO TOKENS:', error));
            }
        }

        // Renderizado inmediato
        updateUI(); 

        // Aumentamos la tasa de refresco visual a 200ms para mayor fluidez.
        // Al usar matemáticas (Date.now) en lugar de restar variables, esto no impacta el rendimiento del CPU 
        // ni desincroniza el tiempo, previniendo congelamientos por inactividad del navegador.
        setInterval(updateUI, 200);
    </script>
    <?php endif; ?>
</body>
</html>
