<?php
/**
 * Script principal de autenticación.
 * Gestiona el ciclo de vida del usuario en el sistema: registro, inicio de sesión (fase 1),
 * validación multifactor TOTP (fase 2) y cierre de sesión.
 */

// Requiere la inclusión de la clase que maneja la lógica de validación y generación TOTP (Time-based One-Time Password).
require_once 'TOTPComponent.php';

// Configuración de reporte de errores para entorno de depuración. 
// Expone todos los errores, advertencias y avisos generados por PHP.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Inicializa o reanuda la sesión actual de PHP para persistir el estado del usuario.
session_start();

// Configuración PDO unificada con validar.php
// Parámetros estáticos para la conexión a la base de datos MySQL.
$host = 'localhost'; // Host de conexion de la base de datos
$db   = ''; // Nombre de la base de datos
$user = ''; // Usuario de la base de datos
$pass = ''; // Contraseña asignada para la auditoría
$charset = 'utf8mb4'; // Codificación segura para caracteres especiales

// Construcción del Data Source Name (DSN) requerido por PDO.
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

try {
    // Instanciación del objeto PDO para interactuar con la base de datos.
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        // Configura el manejo de errores: PDO lanzará una excepción (PDOException) en caso de fallos.
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Define el modo de obtención de datos por defecto como array asociativo.
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    // Interrumpe la ejecución del script y expone el mensaje de error si falla la conexión.
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Instancia el componente que expone las funciones TOTP.
$totp = new TOTPComponent();

// Determina el bloque lógico a procesar utilizando el parámetro GET 'action'. Si no existe, define 'login' por defecto.
$action = $_GET['action'] ?? 'login';

// Variables de estado inicializadas vacías para ser utilizadas posteriormente en la vista (interfaz).
$message = ''; // Mensaje de error/estado para el frontend.
$qrUrl = '';   // URL que contiene la imagen del código QR a inyectar en la vista.
$secret = '';  // La clave secreta TOTP generada.

// BLOQUE LÓGICO: REGISTRO DE USUARIO
// Ejecutado cuando se envía el formulario de registro vía POST.
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    
    // Cifra la contraseña del usuario empleando el algoritmo de hash por defecto de PHP (actualmente bcrypt).
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Genera una clave secreta base32 aleatoria única para la generación de tokens TOTP de este usuario.
    $secret = $totp->generateSecret();

    // Prepara una sentencia SQL de inserción para evitar vulnerabilidades de inyección SQL (SQLi).
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, totp_secret) VALUES (?, ?, ?)");
    try {
        // Ejecuta la inserción con los parámetros recolectados.
        $stmt->execute([$username, $password, $secret]);
        
        // Solicita al componente la URL de una API o servicio que provea la imagen QR para configuración del 2FA.
        $qrUrl = $totp->getQRCodeUrl('POC_Sistema', $username, $secret);
        
        // Actualiza la variable de acción para cambiar el estado de la vista al éxito del registro (donde se expone el QR).
        $action = 'registered';
    } catch (PDOException $e) {
        // Captura excepciones de la base de datos, principalmente aplicable para violaciones de integridad referencial (UNIQUE Constraint en el username).
        $message = "Error: Usuario duplicado o fallo en DB.";
    }
} 
// BLOQUE LÓGICO: INICIO DE SESIÓN (FASE 1 - CREDENCIALES)
// Ejecutado cuando se envía el formulario de login vía POST.
elseif ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Consulta el hash y la semilla secreta del usuario ingresado. Se utiliza bind parameters para seguridad.
    $stmt = $pdo->prepare("SELECT id, password_hash, totp_secret FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Verifica que exista un registro del usuario y valida matemáticamente el hash de la contraseña plana contra la almacenada.
    if ($user && password_verify($password, $user['password_hash'])) {
        // Autorización parcial: Establece variables de sesión "pendientes". 
        // El usuario está identificado, pero el acceso no se consolida hasta completar la fase 2.
        $_SESSION['pending_user_id'] = $user['id'];
        $_SESSION['pending_totp_secret'] = $user['totp_secret'];
        
        // Fuerza una redirección HTTP 302 a la vista de ingreso del Token.
        header('Location: ?action=verify_totp');
        exit;
    } else {
        // Respuesta genérica de fallo. Evita la enumeración de usuarios informando si falló el correo o la contraseña específicamente.
        $message = "Credenciales incorrectas.";
    }
} 
// BLOQUE LÓGICO: VALIDACIÓN DE DOBLE FACTOR (FASE 2 - TOTP)
// Ejecutado cuando el usuario envía el código generado por la app.
elseif ($action === 'verify_totp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['totp_code'];
    $temp_secret = $_SESSION['pending_totp_secret'];

    // Llama al método del componente para contrastar el código temporal ingresado contra la clave secreta almacenada en sesión.
    if ($totp->verifyCode($temp_secret, $code)) {
        // Si el token es válido (basado en el tiempo actual), se consolida la autorización.
        // Se define el user_id permanente en la sesión, garantizando el acceso general.
        $_SESSION['user_id'] = $_SESSION['pending_user_id'];
        
        // Destruye las variables pendientes para prevenir reúso y limpiar la memoria de sesión temporal.
        unset($_SESSION['pending_user_id'], $_SESSION['pending_totp_secret']);
        
        // Define el estado para que la UI muestre acceso garantizado.
        $action = 'success';
    } else {
        // Rechazo del token por expiración temporal de la ventana del TOTP o ingreso erróneo.
        $message = "Código TOTP inválido.";
    }
} 
// BLOQUE LÓGICO: CIERRE DE SESIÓN
// Ejecutado cuando el usuario hace clic en "DESCONECTAR" o se realiza un GET con "logout".
elseif ($action === 'logout') {
    // Vacía todos los datos asociados a la cookie de sesión en el lado del servidor.
    session_destroy();
    // Redirige al estado inicial de autenticación.
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
    
    <!-- Carga de scripts locales de efectos visuales -->
    <script src="/js/mainesp.js"></script>
    <script src="/js/cv-access.js"></script>
    
    <link rel="stylesheet" href="/css/main.css">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Soporte Tailwind CDN para layout de utilidades CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
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
<body class="bg-gray-950 text-green-400">

    <!-- Efecto Overlay tipo Terminal CRT inyectado mediante CSS/JS de los archivos cargados -->
    <div id="crt-startup-overlay">
        <div id="startup-msg-box">
            <span id="startup-typing-text" class="startup-terminal-text"></span>
            <span id="startup-cursor" class="startup-cursor"></span>
        </div>
        <div class="crt-line"></div>
    </div>

    <!-- Canvas para el efecto matrix en el fondo de la pantalla -->
    <canvas id="matrix-canvas" class="fixed top-0 left-0 w-full h-full -z-10 opacity-30"></canvas>

    <div id="main-header"></div>

    <div class="relative z-10">
        <main class="container mx-auto px-2 py-4 md:py-0">
            <section id="hero" class="min-h-[85vh] flex flex-col items-center justify-center text-center overflow-x-hidden">
                
                <!-- Panel Principal de Autenticación -->
                <div class="bg-gray-900/50 backdrop-blur-md border border-[#0f0] rounded-lg p-8 shadow-[0_0_20px_#0f0] w-full max-w-md text-center">
                    
                    <!-- Condicional para renderizar mensajes de error procedentes del backend -->
                    <?php if ($message): ?>
                        <div class="border border-red-500 text-red-500 p-2 mb-4 rounded bg-black/50 font-bold text-sm">
                            > SYSTEM_ERROR: <?= htmlspecialchars($message) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Módulo Lógico de Renderizado: Formulario de INICIO DE SESIÓN -->
                    <?php if ($action === 'login'): ?>
                        <h2 class="font-orbitron text-3xl text-green-500 mb-6 glitch-text" data-text="SYS_LOGIN">SYS_LOGIN</h2>
                        <form method="POST" action="?action=login">
                            <input type="text" name="username" class="cyber-input" placeholder="> USERNAME" required autofocus>
                            <input type="password" name="password" class="cyber-input" placeholder="> PASSWORD" required>
                            <button type="submit" class="matrix-button w-full mb-2">INICIAR CONEXIÓN</button>
                        </form>

                    <!-- Módulo Lógico de Renderizado: Formulario de REGISTRO -->
                    <?php elseif ($action === 'register'): ?>
                        <h2 class="font-orbitron text-3xl text-green-500 mb-6 glitch-text" data-text="REGISTRATION">REGISTRAR NUEVO USUARIO</h2>
                        <form method="POST" action="?action=register">
                            <input type="text" name="username" class="cyber-input" placeholder="> Nuevo Usuario" required autofocus>
                            <input type="password" name="password" class="cyber-input" placeholder="> Nueva Contraseña" required>
                            <button type="submit" class="matrix-button w-full mb-2">GENERAR CREDENCIALES</button>
                        </form>

                    <!-- Módulo Lógico de Renderizado: Feedback de CREACIÓN DE CUENTA EXITOSA y provisión de QR -->
                    <?php elseif ($action === 'registered'): ?>
                        <h2 class="font-orbitron text-2xl text-green-500 mb-4">MFA_SETUP_REQUIRED</h2>
                        <p class="text-sm mb-4 text-gray-300">Escanea el código con tu Bóveda MFA (QRSCAN) para inicializar tu token.</p>
                        
                        <!-- Presentación del código QR y semilla en texto plano para el setup del cliente -->
                        <div class="flex justify-center mb-6">
                            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Code" class="matrix-image-frame bg-white p-2">
                        </div>
                        
                        <p class="text-xs text-green-400 mb-6">SECRET_KEY: <?= htmlspecialchars($secret) ?></p>
                        <a href="?action=login" class="matrix-button w-full block">PROCEDER AL LOGIN</a>

                    <!-- Módulo Lógico de Renderizado: Prompt para validación TOTP (Post fase 1 login) -->
                    <?php elseif ($action === 'verify_totp'): ?>
                        <h2 class="font-orbitron text-3xl text-green-500 mb-6 glitch-text" data-text="2FA_REQUIRED">2FA_REQUIRED</h2>
                        <p class="text-sm mb-6 text-gray-300">Introduce el token temporal generado por tu dispositivo.</p>
                        <form method="POST" action="?action=verify_totp">
                            <input type="text" name="totp_code" class="cyber-input text-center text-2xl tracking-widest" placeholder="000000" maxlength="6" required autofocus autocomplete="off">
                            <button type="submit" class="matrix-button w-full mb-4">AUTENTICAR TOKEN</button>
                        </form>
                        <!-- Opción para regresar y cancelar el estado pendiente -->
                        <a href="?action=login" class="text-red-400 hover:text-red-300 transition-colors text-sm">> Abortar secuencia</a>

                    <!-- Módulo Lógico de Renderizado: Logueo y autenticación completamente aprobados -->
                    <?php elseif ($action === 'success'): ?>
                        <h2 class="font-orbitron text-3xl text-green-500 mb-6 glitch-text" data-text="ACCESS_GRANTED">ACCESS_GRANTED</h2>
                        <div class="text-green-400 mb-8 border border-green-500 p-4 bg-green-500/10 rounded">
                            > Conexión segura establecida.<br>
                            > Bienvenido al sistema central.
                        </div>
                        <a href="?action=logout" class="futuristic-btn w-full block py-2 rounded text-center">DESCONECTAR</a>
                    
                    <!-- Fallback default de seguridad. Destino final de acciones erróneas por GET. -->
                    <?php else: ?>
                        <?php header('Location: ?action=login'); exit; ?>
                    <?php endif; ?>
                </div>

                <!-- SECCIÓN SEPARADA: Enlaces Rápidos (Visible solo en Login y Registro) -->
                <?php if ($action === 'login' || $action === 'register'): ?>
                <div class="mt-6 w-full max-w-md bg-gray-950/80 backdrop-blur-sm border border-green-900/60 p-5 rounded-lg shadow-[0_0_15px_rgba(0,255,65,0.05)]">
                    <div class="text-xs text-green-600 mb-4 text-center tracking-widest font-bold">> ENLACES_DEL_SISTEMA</div>
                    
                    <div class="flex flex-col sm:flex-row gap-4">
                        
                        <!-- Botón Dinámico: Intercambia la vista en función del estado de $action -->
                        <?php if ($action !== 'register'): ?>
                        <a href="?action=register" class="system-link-btn flex-1 py-3 px-4 text-center text-sm font-bold flex items-center justify-center rounded">
                            <i class="fas fa-user-plus mr-2"></i> REGISTRAR
                        </a>
                        <?php else: ?>
                        <a href="?action=login" class="system-link-btn flex-1 py-3 px-4 text-center text-sm font-bold flex items-center justify-center rounded">
                            <i class="fas fa-sign-in-alt mr-2"></i> LOGIN
                        </a>
                        <?php endif; ?>

                        <!-- Botón fijo hacia el Validador Master-View -->
                        <a href="validador.php" class="system-link-btn system-link-btn-alt flex-1 py-3 px-4 text-center text-sm font-bold flex items-center justify-center rounded">
                            <i class="fas fa-shield-alt mr-2"></i> VALIDADOR
                        </a>

                    </div>
                </div>
                <?php endif; ?>

            </section>
        </main>
    </div>

    <div id="main-footer"></div>

</body>
</html>
