<?php
class TOTPComponent {
    private $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // Genera un secreto aleatorio de 16 caracteres en Base32
    public function generateSecret($length = 16) {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $this->base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    // Genera la URL del codigo QR utilizando un servicio externo (QRServer)
    public function getQRCodeUrl($issuer, $accountName, $secret) {
        $uri = sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer)
        );
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($uri);
    }

    // Valida el c贸digo TOTP introducido contra el secreto
    public function verifyCode($secret, $code, $discrepancy = 1) {
        $currentTimeSlice = floor(time() / 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = $this->calculateCode($secret, $currentTimeSlice + $i);
            if (hash_equals((string)$calculatedCode, (string)$code)) {
                return true;
            }
        }
        return false;
    }

    private function calculateCode($secret, $timeSlice) {
        $secretKey = $this->base32Decode($secret);
        // Empaquetar el tiempo en 8 bytes (64 bits)
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        
        // Truncamiento din谩mico (RFC 4226)
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4));
        $value = $value[1] & 0x7FFFFFFF;

        $modulo = pow(10, 6);
        return str_pad($value % $modulo, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode($secret) {
        $secret = strtoupper($secret);
        $decoded = '';
        $buffer = 0;
        $bufferSize = 0;

        foreach (str_split($secret) as $char) {
            $val = strpos($this->base32Chars, $char);
            if ($val === false) continue;

            $buffer = ($buffer << 5) | $val;
            $bufferSize += 5;

            if ($bufferSize >= 8) {
                $bufferSize -= 8;
                $decoded .= chr(($buffer >> $bufferSize) & 0xFF);
            }
        }
        return $decoded;
    }
}
?>
