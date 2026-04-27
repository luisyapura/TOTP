<?php
// Requerimos el archivo que contiene la clase original para no duplicar código lógico.
require_once 'TOTPComponent.php';

// Habilitar la visualización de errores de PHP.
// Esto es crucial en entornos de auditoría/desarrollo para ver fallos en pantalla en lugar de páginas en blanco.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==========================================
// CONFIGURACIÓN DE BASE DE DATOS (PDO)
// ==========================================
// Credenciales explícitas del host y la base de datos de la prueba de concepto (PoC).
$host = 'localhost'; // Host de conexion de la base de datos
$db   = ''; // Nombre de la base de datos
$user = ''; // Usuario de la base de datos
$pass = ''; // Contraseña asignada para la auditoría
$charset = 'utf8mb4'; // Codificación segura para caracteres especiales

// Data Source Name (DSN) define el driver de base de datos (mysql) y los parámetros del host.
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Opciones de seguridad y optimización para la instancia PDO:
// - ATTR_ERRMODE: Lanza excepciones en caso de fallo (para atraparlas en un bloque try/catch).
// - ATTR_DEFAULT_FETCH_MODE: Devuelve los datos de la base de datos como un array asociativo (clave/valor).
// - ATTR_EMULATE_PREPARES: Desactiva la emulación para aprovechar las sentencias preparadas nativas de MySQL (mitiga Inyección SQL).
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Inicialización de variables para la renderización de la interfaz.
$accounts = [];     // Almacenará la lista de usuarios.
$totalUsers = 0;    // Contador total de registros.
$dbError = null;    // Almacenará el mensaje de error si falla la conexión.

try {
    // Intentamos establecer la conexión con la base de datos.
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Consulta 1: Obtener la cantidad absoluta de usuarios registrados en la tabla 'users'.
    // Esto sirve para propósitos de auditoría (saber si hay registros que no tienen TOTP).
    $stmtTotal = $pdo->query('SELECT COUNT(*) FROM users');
    $totalUsers = $stmtTotal->fetchColumn();

    // Consulta 2: Extraer únicamente los usuarios que han configurado su secreto de autenticación (2FA).
    // Filtramos los que tengan 'totp_secret' nulo o vacío.
    $stmt = $pdo->query('SELECT username, totp_secret FROM users WHERE totp_secret IS NOT NULL AND totp_secret != ""');
    $accounts = $stmt->fetchAll();
} catch (\PDOException $e) {
    // Si ocurre cualquier error en las consultas o en la conexión, lo guardamos para mostrarlo amigablemente.
    $dbError = "Error de conexión a la base de datos: " . $e->getMessage();
}

// Instanciamos el componente principal de criptografía que importamos al inicio.
$totp = new TOTPComponent();

// ==========================================
// SOLUCIÓN DE DEPENDENCIA MEDIANTE REFLEXIÓN (REFLECTION API)
// ==========================================
/* * PROBLEMA: El método 'calculateCode' dentro de 'TOTPComponent.php' fue declarado como 'private',
 * lo que significa que solo puede ser ejecutado desde dentro de esa misma clase, causando un "Fatal Error" aquí.
 * SOLUCIÓN: Usamos la clase 'ReflectionClass' de PHP. Esto nos permite inspeccionar el componente
 * y cambiar la accesibilidad de su método privado a público temporalmente solo para este archivo,
 * evitando tener que modificar el archivo original del sistema y arriesgar otras funcionalidades.
 */
$reflection = new ReflectionClass($totp);
$calculateMethod = $reflection->getMethod('calculateCode');
$calculateMethod->setAccessible(true); // Rompemos la encapsulación privada.

// ==========================================
// LÓGICA DE TIEMPO TOTP (RFC 6238)
// ==========================================
// Los tokens TOTP cambian cada 30 segundos basados en el Epoch Unix (1 de enero de 1970).
// Floor(time() / 30) calcula en qué "bloque" exacto de 30 segundos nos encontramos actualmente.
$timeSlice = floor(time() / 30);

// Calcula cuántos segundos restan para pasar al siguiente bloque (30, 29, 28... 0).
$timeRemaining = 30 - (time() % 30);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOTP Master View - CyberSecurity Analyst</title>
    
    <!-- Dependencias de Scripts y Estilos (Idénticas a register.php para consistencia visual) -->
    <script src="/js/mainesp.js"></script>
    <script src="/js/cv-access.js"></script>
    <link rel="stylesheet" href="/css/main.css">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <!-- FontAwesome para los íconos de los botones de navegación -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tailwind CSS cargado vía CDN para utilizar clases utilitarias de flexbox, grids, paddings y colores -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        /* ========================================== */
        /* HOJA DE ESTILOS PERSONALIZADOS (CYBERPUNK) */
        /* ========================================== */
        
        /* Estilo base para los paneles oscuros translúcidos */
        .cyber-panel {
            background-color: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid #00ff41; /* Borde verde característico */
            box-shadow: 0 0 20px rgba(0, 255, 65, 0.2);
        }
        
        /* Estilo de la fuente tipográfica que muestra el código TOTP numérico */
        .token-display {
            font-family: 'Share Tech Mono', 'Courier New', monospace;
            font-size: 3rem;
            letter-spacing: 0.25em; /* Separación amplia para facilitar la lectura de los 6 dígitos */
            text-shadow: 0 0 10px rgba(0, 255, 65, 0.8);
        }

        /* Estilo de alerta rojo si el cálculo criptográfico falla (ej: Secreto Base32 inválido en la BD) */
        .token-error {
            color: #ef4444; 
            text-shadow: 0 0 10px rgba(239, 68, 68, 0.8);
            font-size: 1.5rem;
            letter-spacing: normal;
        }

        /* ========================================== */
        /* ANIMACIÓN DEL RELOJ CIRCULAR SVG           */
        /* ========================================== */
        .circular-chart {
            display: block;
            width: 60px;
            height: 60px;
        }
        /* Círculo de fondo oscuro/apagado */
        .circle-bg {
            fill: none;
            stroke: rgba(0, 255, 65, 0.1);
            stroke-width: 3;
        }
        /* Círculo dinámico de progreso color verde */
        .circle {
            fill: none;
            stroke: #00ff41;
            stroke-width: 3;
            stroke-linecap: square;
            /* La transición permite que la reducción del círculo sea suave y no a saltos */
            transition: stroke-dasharray 1s linear, stroke 0.3s ease;
            filter: drop-shadow(0 0 3px #00ff41);
        }
        /* Clase CSS que inyectaremos vía JavaScript cuando falten 5 segundos o menos */
        .circle.danger {
            stroke: #ef4444; /* Rojo alerta */
            filter: drop-shadow(0 0 3px #ef4444);
        }

        /* ========================================== */
        /* BOTONES DE NAVEGACIÓN LATERAL              */
        /* ========================================== */
        .system-link-btn {
            border: 1px solid #00ff41;
            color: #00ff41;
            transition: all 0.3s ease;
            text-transform: uppercase;
            font-family: 'Share Tech Mono', monospace;
        }
        .system-link-btn:hover {
            background-color: #00ff41; /* Inversión de colores al pasar el ratón */
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

    <!-- Efecto visual de superposición que simula una pantalla de tubo de rayos catódicos (CRT) -->
    <div id="crt-startup-overlay">
        <div id="startup-msg-box">
            <span id="startup-typing-text" class="startup-terminal-text"></span>
            <span id="startup-cursor" class="startup-cursor"></span>
        </div>
        <div class="crt-line"></div>
    </div>

    <!-- Lienzo HTML5 para la animación de lluvia digital estilo Matrix (Z-index negativo para el fondo) -->
    <canvas id="matrix-canvas" class="fixed top-0 left-0 w-full h-full -z-10 opacity-30"></canvas>

    <div id="main-header"></div>

    <!-- ========================================== -->
    <!-- ESTRUCTURA PRINCIPAL DIVIDIDA EN COLUMNAS  -->
    <!-- ========================================== -->
    <!-- Flex-col en móviles, Flex-row en pantallas anchas (xl). Divide la pantalla en Izquierda (Grilla) y Derecha (Menú) -->
    <div class="relative z-10 min-h-screen flex flex-col xl:flex-row items-start justify-center py-10 px-4 gap-8 max-w-[1400px] mx-auto">
        
        <!-- [COLUMNA IZQUIERDA]: Panel de Cabecera y Grilla de Usuarios. Ocupa todo el espacio restante (flex-1). -->
        <div class="flex-1 w-full max-w-5xl flex flex-col items-center">
            
            <!-- Panel superior con información técnica del entorno y estadísticas -->
            <div class="cyber-panel rounded-lg p-6 w-full text-center mb-8">
                <h1 class="font-orbitron text-4xl text-green-500 mb-2 glitch-text" data-text="TOTP_MASTER_VIEW">TOTP_MASTER_VIEW</h1>
                <p class="text-sm text-gray-400 mb-4">> AUDITORÍA EN TIEMPO REAL: <?= htmlspecialchars($db) ?></p>
                
                <div class="flex flex-col md:flex-row justify-center items-center gap-6 border-t border-green-900 pt-4 mt-2">
                    <!-- Estadísticas de PDO -->
                    <div class="text-xs text-green-300 bg-green-900/30 px-4 py-2 rounded border border-green-800">
                        REGISTROS TOTALES: <span class="font-bold text-white"><?= $totalUsers ?></span> | 
                        MFA ACTIVO: <span class="font-bold text-white"><?= count($accounts) ?></span>
                    </div>
                    
                    <!-- Reloj global (Se oculta si falla la base de datos) -->
                    <?php if (!$dbError): ?>
                    <div class="flex items-center gap-4 bg-black/50 px-6 py-2 rounded-full border border-green-500/30">
                        <span class="text-lg font-bold">ROTACIÓN EN: <span id="countdown-text" class="text-white"><?= $timeRemaining ?>s</span></span>
                        
                        <!-- El SVG dibuja el círculo. Los cálculos de porcentaje se inyectan dinámicamente en 'stroke-dasharray' desde JS -->
                        <svg class="circular-chart" viewBox="0 0 36 36">
                            <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="circle" id="circle-path" stroke-dasharray="100, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bloque de impresión de errores SQL capturados -->
            <?php if ($dbError): ?>
                <div class="border border-red-500 text-red-500 p-4 rounded bg-black/80 font-bold w-full text-center mb-6">
                    > SYSTEM_ERROR: <?= htmlspecialchars($dbError) ?>
                </div>
            <?php endif; ?>

            <!-- Grilla Responsiva: 1 columna en móviles, 2 en tabletas, 3 en computadoras -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 w-full">
                <?php 
                // Iteramos a través del array multidimensional devuelto por PDO
                foreach ($accounts as $acc): 
                    // Limpieza preventiva: Convertir a mayúsculas y borrar espacios laterales (Requisito de Base32)
                    $cleanSecret = strtoupper(trim($acc['totp_secret']));
                    $currentCode = '';
                    $hasError = false;

                    try {
                        // AQUÍ EJECUTAMOS LA LÓGICA CRIPTOGRÁFICA. 
                        // Usamos el 'invoke' sobre la clase '$totp' pasándole el secreto y el bloque de tiempo.
                        $currentCode = $calculateMethod->invoke($totp, $cleanSecret, $timeSlice);
                    } catch (\Throwable $th) {
                        // Si el secreto no es compatible con el estándar Base32, se captura el error y se bandera.
                        $currentCode = 'ERROR_SYNC';
                        $hasError = true;
                    }
                ?>
                    <!-- Tarjeta individual de usuario (Cambia a bordes rojos si $hasError es verdadero) -->
                    <div class="cyber-panel p-6 rounded-lg text-center flex flex-col justify-between transition-transform duration-300 hover:-translate-y-1 <?= $hasError ? 'border-red-500 shadow-[0_0_15px_rgba(239,68,68,0.2)]' : '' ?>">
                        <div class="mb-4 pb-4 border-b border-green-900/50">
                            <!-- Nombre de usuario y visualización del secreto Base32 extraído de MySQL -->
                            <div class="text-xl text-white font-orbitron">> USER: <?= htmlspecialchars($acc['username']) ?></div>
                            <div class="text-xs text-gray-500 mt-2 break-all">SECRET: <?= htmlspecialchars($cleanSecret) ?></div>
                        </div>
                        
                        <div class="py-2">
                            <!-- Impresión del código numérico final de 6 dígitos -->
                            <div class="<?= $hasError ? 'token-error' : 'token-display text-green-400' ?>">
                                <?= htmlspecialchars($currentCode) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- Mensaje alternativo si la tabla fue leída pero la columna totp_secret está vacía -->
                <?php if (empty($accounts) && !$dbError): ?>
                    <div class="col-span-full cyber-panel p-8 text-center text-yellow-500 border-yellow-500">
                        > NO SE DETECTARON CREDENCIALES TOTP ACTIVAS EN LA BASE DE DATOS.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- [COLUMNA DERECHA]: PANEL DE NAVEGACIÓN     -->
        <!-- ========================================== -->
        <!-- Ancho fijo (w-80) en escritorio. 'sticky top-10' hace que el panel se quede anclado al hacer scroll vertical hacia abajo -->
        <div class="w-full xl:w-80 flex flex-col gap-4 sticky top-10 mt-8 xl:mt-0">
            <div class="bg-gray-950/80 backdrop-blur-sm border border-green-900/60 p-6 rounded-lg shadow-[0_0_15px_rgba(0,255,65,0.05)]">
                <div class="text-xs text-green-600 mb-6 text-center tracking-widest font-bold border-b border-green-900/50 pb-4">
                    > NAVEGACIÓN_DEL_SISTEMA
                </div>
                
                <div class="flex flex-col gap-4">
                    <!-- Botón 1: Apunta al action 'login' del archivo register.php -->
                    <a href="register.php?action=login" class="system-link-btn py-4 px-4 text-center text-sm font-bold flex items-center justify-center rounded">
                        <i class="fas fa-sign-in-alt mr-2"></i> INICIAR SESIÓN
                    </a>
                    
                    <!-- Botón 2: Apunta al action 'register' del archivo register.php -->
                    <a href="register.php?action=register" class="system-link-btn system-link-btn-alt py-4 px-4 text-center text-sm font-bold flex items-center justify-center rounded">
                        <i class="fas fa-user-plus mr-2"></i> REGISTRAR NUEVO USUARIO
                    </a>
                </div>
            </div>
        </div>

    </div>

    <div id="main-footer"></div>

    <!-- ========================================== -->
    <!-- SCRIPT DE SINCRONIZACIÓN Y ANIMACIÓN SVG   -->
    <!-- ========================================== -->
    <?php if (!$dbError): ?>
    <script>
        // Variables inicializadas desde PHP en el momento del renderizado
        var timeLeft = <?= $timeRemaining ?>; 
        var totalTime = 30; // La ventana temporal universal para el estándar TOTP
        
        // Elementos del DOM a manipular
        var countdownTxt = document.getElementById('countdown-text');
        var circlePath = document.getElementById('circle-path');
        
        // Función responsable de modificar los textos y atributos del círculo SVG
        function updateUI() {
            if (countdownTxt) countdownTxt.innerText = timeLeft + 's';
            
            if (circlePath) {
                // Matemáticas SVG: Calculamos qué porcentaje de la circunferencia debe dibujarse
                var percentage = (timeLeft / totalTime) * 100;
                circlePath.setAttribute('stroke-dasharray', percentage + ', 100');

                // Lógica de alerta visual: Cuando restan 5 segundos o menos
                if (timeLeft <= 5) {
                    circlePath.classList.add('danger'); // Agrega bordes rojos
                    if (countdownTxt) countdownTxt.classList.add('text-red-500');
                    if (countdownTxt) countdownTxt.classList.remove('text-white');
                } else {
                    circlePath.classList.remove('danger'); // Retorna al verde normal
                    if (countdownTxt) countdownTxt.classList.remove('text-red-500');
                    if (countdownTxt) countdownTxt.classList.add('text-white');
                }
            }
        }

        // Se ejecuta una vez inmediatamente para no esperar 1 segundo antes de mostrar datos
        updateUI(); 

        // Bucle que se ejecuta exactamente cada 1000 milisegundos (1 segundo)
        var timerInterval = setInterval(function() {
            timeLeft--; // Descontar el tiempo
            
            if (timeLeft <= 0) {
                clearInterval(timerInterval); // Detenemos este temporizador
                
                // CRÍTICO: Recargamos toda la página ignorando la caché del navegador.
                // Esto fuerza a PHP a volver a consultar la clase y generar el nuevo hash de los siguientes 30 segundos.
                window.location.reload(true); 
            } else {
                updateUI(); // Si aún hay tiempo, seguimos actualizando el DOM
            }
        }, 1000);
    </script>
    <?php endif; ?>
</body>
</html>
