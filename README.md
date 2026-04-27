<p align="center">
  <img src="/IMG/portada.png" width="1200" height="400" alt="portada">
</p>

# 🔐 Sistema MFA basado en TOTP (POC)

Este repositorio contiene una Prueba de Concepto (POC) completa para un sistema de Autenticación de Múltiples Factores (MFA) basado en algoritmos TOTP (Time-Based One-Time Password). El proyecto incluye un backend estructurado en PHP, una interfaz gráfica y un cliente móvil autónomo desarrollado en Flutter ("QRSCAN").

---

## 📑 Tabla de Contenidos

* 🧮 1. Teoría y Fundamento Matemático (RFC 6238)
* 🖥️ 2. Arquitectura del Backend (PHP)
* 📱 3. Cliente Móvil: QRSCAN (Flutter)
* 🔄 4. Diagrama de Flujo del Sistema

---

## 🧮 1. Teoría y Fundamento Matemático (RFC 6238)

El sistema implementa el estándar abierto de la IETF definido en el RFC 6238 (TOTP), el cual es una extensión directa del RFC 4226 (HOTP - HMAC-Based One-Time Password). A diferencia de HOTP, que utiliza un contador de eventos iterativo, TOTP utiliza una marca de tiempo estandarizada como base para el cálculo.

### 📐 Formulación Matemática Base

La ecuación que rige la generación del token es:

$$TOTP = Truncate(HMAC_SHA1(K, T))$$

### 🔍 Variables de la Ecuación

* **$K$ (Clave Secreta Compartida):** Una cadena binaria estática generada aleatoriamente por el servidor durante el registro. Para facilitar su intercambio en formatos como códigos QR, se codifica en Base32.

* **$T$ (Ventana de Tiempo):** Un valor entero que representa la cantidad de intervalos transcurridos desde el tiempo de inicio ($T_0$). Se calcula de la siguiente manera:

$$T = \lfloor \frac{CurrentTime - T_0}{X} \rfloor$$

### 📝 Nota sobre las variables:

* **$CurrentTime$:** Tiempo actual en formato Epoch Unix (segundos transcurridos desde el 1 de enero de 1970).
* **$T_0$:** Tiempo de inicio (por defecto 0).
* **$X$:** Intervalo de la ventana (estándar de la industria: 30 segundos).

### ⚙️ Proceso de Truncamiento Dinámico (Dynamic Truncation)

El resultado de la función HMAC-SHA-1 es un hash de 20 bytes (160 bits). Dado que un usuario no puede digitar 160 bits, se aplica un truncamiento:

1. Se extrae el último byte del hash (byte 19).
2. Se aplica una máscara bit a bit `& 0x0F` (los 4 bits menos significativos) para obtener un offset (índice entre 0 y 15).
3. Utilizando este offset, se extraen 4 bytes (32 bits) consecutivos del hash.
4. Se descarta el bit más significativo aplicando una máscara lógica `& 0x7FFFFFFF`, dejando 31 bits utilizables.
5. Para obtener un número de $d$ dígitos (usualmente $6$), se aplica un módulo matemático: $Valor_{binario} \pmod{10^d}$.

---

## 🛠️ 2. Preparación del Entorno y Base de Datos

Para ejecutar esta Prueba de Concepto, es necesario configurar la persistencia de datos y la estructura de archivos en el servidor web.

🗄️ Estructura de la Base de Datos (MySQL/MariaDB)

Se requiere una base de datos y una tabla para almacenar las credenciales de los usuarios y su respectiva semilla TOTP. Ejecutar la siguiente consulta en el motor de base de datos antes de iniciar el sistema:



📁 Estructura de Archivos del Proyecto

El sistema debe alojarse en el directorio raíz del servidor web (ej. htdocs, www o public_html) siguiendo esta jerarquía:

* [BASE DE DATOS](/maindb.sql)
* [TOTPCOMPONENT](/TOTP/TOTPComponent.php)
* [REGISTER](/TOTP/register.php)
  
```
/
├── TOTPComponent.php    # Contiene la clase matemática pura (Fase 1 de creación).
└── register.php         # Archivo principal (UI + Controladores de Sesión y PDO) (Fase 2 de creación).

```

⚙️ Secuencia de Despliegue
Crear TOTPComponent.php para definir la lógica criptográfica.
Crear register.php (o integrarlo como index.php), incluyendo require_once 'TOTPComponent.php' en la primera línea.
Desplegar los recursos estáticos en las carpetas css y js.

---

## 🖥️ 3. Arquitectura del Backend (PHP)

El backend opera sin frameworks externos para demostrar la implementación en crudo del algoritmo. Se divide en dos partes fundamentales: el almacenamiento (PDO MySQL) y la lógica matemática (TOTPComponent).

### 🧩 Clase TOTPComponent

#### 🔐 generateSecret($length = 16)

* **Función:** Crea la entropía inicial.
* **Lógica:** Itera 16 veces seleccionando caracteres aleatorios de un diccionario Base32 (RFC 4648). Utiliza `random_int()` para asegurar imprevisibilidad criptográfica.

#### 🔄 base32Decode($secret)

* **Función:** Convierte el texto legible en bytes puros.
* **Lógica:** Efectúa desplazamientos de bits (`<< 5` y `>>`) para empaquetar grupos de 5 bits en bytes de 8 bits requeridos por HMAC.
  * "<<" (Desplazamiento a la izquierda o Left Shift): La expresión << 5 significa que el programa toma los bits (ceros y unos) de una variable y los mueve exactamente 5 posiciones hacia la izquierda. Es el equivalente binario a multiplicar un número.
  * ">>" (Desplazamiento a la derecha o Right Shift): Mueve los bits hacia la derecha, lo cual es el equivalente binario a dividir un número.

#### ⚙️ calculateCode($secret, $timeSlice)

* **Función:** Motor de generación del RFC 6238.
* **Lógica:** Transforma el `timeSlice` en binario Big-Endian de 64 bits usando `pack('N*', 0) . pack('N*', $timeSlice)`. Luego aplica `hash_hmac('sha1', ...)` y ejecuta truncamiento dinámico.

#### 🛡️ verifyCode($secret, $code, $discrepancy = 1)

* **Función:** Validación con tolerancia a desincronización.
* **Lógica:** Genera tokens para $T$, $T-1$ y $T+1`. Usa `hash_equals()` para mitigar ataques de timing.

---

## 📱 3. Cliente Móvil: QRSCAN (Flutter)

La aplicación cliente procesa el QR y calcula tokens localmente (offline).

### 🧠 Arquitectura de _TOTPScannerScreenState

#### 📷 Motor de Captura (mobile_scanner)

* Activa la cámara y detecta QR en tiempo real mediante Google ML Kit.
* Busca la URI `otpauth://totp/` y detiene el escaneo al encontrarla.

#### 🔗 Decodificador de URI

* Extrae parámetros como `secret` e `issuer`.

#### ⏱️ Bucle de Sincronización Temporal

* `Timer.periodic(1000 ms)`
* Obtiene tiempo Unix local (`DateTime.now().millisecondsSinceEpoch`).
* Ejecuta TOTP mediante librería `otp`.
* Actualiza UI (`setState`) mostrando código de 6 dígitos.

---

## 🔄 4. Diagrama de Flujo del Sistema

### 🧾 Fase 1: Aprovisionamiento (Registro)

* 🧑‍💻 Usuario → Solicita nueva cuenta
* 🖥️ Backend → Genera $K$, almacena en BD y genera QR

### 🔁 Fase 2: Sincronización

* 📱 App QRSCAN → Escanea QR y guarda $K$
* 📱 App → Ejecuta $TOTP(K, TiempoActual)$

### 🔐 Fase 3: Validación (Login)

* 🧑‍💻 Usuario → Ingresa credenciales
* 🖥️ Backend → Solicita segundo factor
* 🧑‍💻 Usuario → Introduce código TOTP
* 🖥️ Backend → Valida $TOTP(K_{servidor}, TiempoServidor \pm 1)$

### ✅ Resultado

* 🔓 Acceso concedido si los códigos coinciden

---

## ⚠️ Disclaimer

Este proyecto es únicamente con fines educativos y de investigación. No debe ser utilizado en entornos de producción sin auditoría de seguridad adecuada.
