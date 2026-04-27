<?php
/**
 * Clase TOTPComponent
 * Implementa la generación y validación de contraseñas de un solo uso basadas en el tiempo (TOTP).
 * Sigue las especificaciones RFC 6238 (TOTP) y RFC 4226 (HOTP - Truncamiento dinámico).
 */
class TOTPComponent {
    // Diccionario de caracteres válidos para la codificación Base32 (RFC 4648).
    private $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Genera una clave secreta aleatoria codificada en Base32.
     * * @param int $length Longitud de la cadena resultante (por defecto 16 caracteres).
     * @return string Clave secreta generada en Base32.
     */
    public function generateSecret($length = 16) {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            // random_int() garantiza que la selección sea criptográficamente segura,
            // proveyendo suficiente entropía para el secreto del token.
            $secret .= $this->base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Construye la URL de un código QR para facilitar la configuración en aplicaciones de autenticación (ej. Google Authenticator, Authy).
     * * @param string $issuer Nombre de la entidad o sistema que emite el token.
     * @param string $accountName Identificador del usuario (ej. nombre de usuario o correo).
     * @param string $secret La clave secreta generada para el usuario.
     * @return string URL de la API que retorna la imagen del código QR.
     */
    public function getQRCodeUrl($issuer, $accountName, $secret) {
        // Se formatea el URI estándar "otpauth" requerido por las aplicaciones de autenticación.
        // Se utiliza rawurlencode para asegurar que los espacios y caracteres especiales en los nombres no rompan la sintaxis.
        $uri = sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer)
        );
        // Delega la generación visual del QR a un servicio de terceros (QRServer) mediante GET.
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($uri);
    }

    /**
     * Valida si el código numérico ingresado por el usuario es correcto en el margen de tiempo actual.
     * * @param string $secret La clave secreta almacenada del usuario.
     * @param string $code El código de 6 dígitos ingresado por el usuario.
     * @param int $discrepancy Margen de error tolerado en "ventanas" de 30 segundos para compensar la desincronización de relojes entre el cliente y el servidor (1 = +/- 30 segundos).
     * @return bool True si el código es válido, False en caso contrario.
     */
    public function verifyCode($secret, $code, $discrepancy = 1) {
        // Calcula la ventana de tiempo actual (UNIX timestamp dividido por el intervalo estándar de 30 segundos).
        $currentTimeSlice = floor(time() / 30);

        // Itera sobre el margen de tolerancia permitido (pasado, presente y futuro inmediato).
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            // Genera el código esperado para esa porción de tiempo.
            $calculatedCode = $this->calculateCode($secret, $currentTimeSlice + $i);
            
            // hash_equals se usa para comparar las cadenas en tiempo constante,
            // previniendo vulnerabilidades de ataques de temporización (timing attacks).
            if (hash_equals((string)$calculatedCode, (string)$code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ejecuta el algoritmo núcleo matemático/criptográfico para calcular el token esperado.
     * * @param string $secret Clave secreta en Base32.
     * @param int $timeSlice Ventana de tiempo actual.
     * @return string Código numérico final formateado a 6 dígitos.
     */
    private function calculateCode($secret, $timeSlice) {
        // Transforma la clave Base32 al array de bytes original.
        $secretKey = $this->base32Decode($secret);
        
        // Empaqueta el identificador de tiempo en formato binario de 8 bytes (64 bits, big-endian)
        // según lo exige el estándar RFC 4226.
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        
        // Genera el hash HMAC utilizando SHA-1 (algoritmo estándar para TOTP), 
        // pasándole los bytes del tiempo y la clave secreta decodificada.
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        
        // --- Proceso de Truncamiento Dinámico (Dynamic Truncation - RFC 4226) ---
        // 1. Extrae el offset a partir del último nibble (4 bits) del hash resultante.
        $offset = ord(substr($hash, -1)) & 0x0F;
        
        // 2. Extrae 4 bytes del hash comenzando desde el byte indicado por el offset.
        $value = unpack('N', substr($hash, $offset, 4));
        
        // 3. Aplica una máscara a nivel de bits (0x7FFFFFFF) para ignorar el bit de signo 
        // y asegurar un entero positivo estandarizado sin importar la arquitectura del procesador.
        $value = $value[1] & 0x7FFFFFFF;

        // Se reduce el valor resultante a 6 dígitos utilizando la operación módulo 10^6.
        $modulo = pow(10, 6);
        // Si el resultado tiene menos de 6 dígitos, se rellena con ceros a la izquierda (str_pad).
        return str_pad($value % $modulo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Decodifica una cadena en Base32 devolviendo sus datos binarios.
     * Es necesario porque HMAC-SHA1 debe operar sobre los bytes crudos y no sobre el texto de representación.
     * * @param string $secret Cadena en Base32 a decodificar.
     * @return string Representación binaria del secreto.
     */
    private function base32Decode($secret) {
        // Asegura que la entrada esté en mayúsculas para coincidir con el diccionario.
        $secret = strtoupper($secret);
        $decoded = '';
        $buffer = 0;
        $bufferSize = 0;

        // Itera carácter por carácter.
        foreach (str_split($secret) as $char) {
            $val = strpos($this->base32Chars, $char);
            if ($val === false) continue; // Ignora caracteres inválidos o de relleno (padding).

            // Desplaza el buffer temporal 5 bits a la izquierda (Base32 utiliza 5 bits por carácter) 
            // e inserta el valor del carácter actual.
            $buffer = ($buffer << 5) | $val;
            $bufferSize += 5;

            // Cuando acumulamos 8 bits (1 byte) o más en el buffer, extraemos ese byte completo
            // y lo añadimos a la cadena binaria final.
            if ($bufferSize >= 8) {
                $bufferSize -= 8;
                $decoded .= chr(($buffer >> $bufferSize) & 0xFF);
            }
        }
        return $decoded;
    }
}
?>
