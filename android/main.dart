import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:otp/otp.dart';
import 'package:shared_preferences/shared_preferences.dart';

/**
 * Punto de entrada principal de la aplicación Flutter.
 */
void main() {
  runApp(const QRScanApp());
}

/**
 * Widget raíz de la aplicación.
 * Define la configuración global de la interfaz de usuario, incluyendo el tema
 * (colores, tipografía base) y la pantalla inicial.
 */
class QRScanApp extends StatelessWidget {
  const QRScanApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'QRSCAN POC',
      // Configuración de un tema oscuro personalizado con acentos en verde (estilo terminal/cyber).
      theme: ThemeData.dark().copyWith(
        scaffoldBackgroundColor: const Color(0xFF0D0D0D),
        primaryColor: const Color(0xFF00FF41),
      ),
      home: const TOTPListScreen(),
    );
  }
}

/**
 * Pantalla principal de la aplicación.
 * Utiliza un StatefulWidget ya que requiere mantener y mutar estado dinámico
 * (lista de cuentas, estado de la cámara y temporizador de actualización).
 */
class TOTPListScreen extends StatefulWidget {
  const TOTPListScreen({super.key});

  @override
  State<TOTPListScreen> createState() => _TOTPListScreenState();
}

class _TOTPListScreenState extends State<TOTPListScreen> {
  // Controlador del hardware de la cámara para la lectura de códigos de barras/QR.
  final MobileScannerController _scannerController = MobileScannerController();

  // Estructura de datos en memoria para almacenar las credenciales cargadas.
  List<Map<String, String>> _accounts = [];
  
  // Temporizador para forzar la reconstrucción de la UI y recalcular los tokens.
  Timer? _timer;
  
  // Bandera de estado para alternar entre la vista de lista y la vista de escáner.
  bool _isScanning = false;

  /**
   * Método del ciclo de vida que se ejecuta una única vez al insertar el widget en el árbol.
   */
  @override
  void initState() {
    super.initState();
    // Recupera las cuentas almacenadas en la memoria persistente del dispositivo.
    _loadAccounts();
    
    // Configura un temporizador periódico a 1 Hz (1 segundo).
    // Su función es invocar setState({}) cíclicamente para que el método build() se vuelva a ejecutar.
    // Esto es vital para recalcular los tokens TOTP en tiempo real según avanza el reloj del sistema.
    _timer = Timer.periodic(const Duration(seconds: 1), (_) => setState(() {}));
  }

  /**
   * Método del ciclo de vida que se ejecuta al destruir el widget.
   * Es crítico para liberar recursos de hardware e hilos en segundo plano, previniendo fugas de memoria.
   */
  @override
  void dispose() {
    _timer?.cancel();
    _scannerController.dispose();
    super.dispose();
  }

  /**
   * Lee la cadena JSON almacenada en el sistema de preferencias clave-valor (SharedPreferences).
   * Deserializa la información y actualiza el estado local _accounts.
   */
  Future<void> _loadAccounts() async {
    final prefs = await SharedPreferences.getInstance();
    final String? accountsJson = prefs.getString('totp_accounts');
    if (accountsJson != null) {
      final List<dynamic> decoded = jsonDecode(accountsJson);
      setState(() {
        // Mapea la lista dinámica devuelta por jsonDecode a un tipado estricto Map<String, String>.
        _accounts = decoded.map((e) => Map<String, String>.from(e)).toList();
      });
    }
  }

  /**
   * Agrega una nueva cuenta a la lista en memoria y persiste los cambios serializando a JSON.
   */
  Future<void> _saveAccount(String name, String secret) async {
    // Control de integridad: Evita insertar la misma clave secreta más de una vez.
    if (_accounts.any((acc) => acc['secret'] == secret)) {
      return;
    }
    
    // Agrega el nuevo registro al estado en memoria.
    _accounts.add({'accountName': name, 'secret': secret});
    
    // Serializa la lista completa y la guarda en disco de forma asíncrona.
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('totp_accounts', jsonEncode(_accounts));
    
    // Fuerza una actualización de la vista para mostrar el nuevo token.
    setState(() {});
  }

  /**
   * Elimina un registro específico basado en su índice dentro del array.
   * Actualiza el almacenamiento persistente inmediatamente después.
   */
  Future<void> _deleteAccount(int index) async {
    _accounts.removeAt(index);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('totp_accounts', jsonEncode(_accounts));
    setState(() {});
  }

  /**
   * Callback ejecutado por MobileScanner al detectar uno o más códigos QR en el fotograma de la cámara.
   */
  void _processQRCode(BarcodeCapture capture) {
    final List<Barcode> barcodes = capture.barcodes;
    for (final barcode in barcodes) {
      if (barcode.rawValue == null) continue;

      final String rawValue = barcode.rawValue!;
      
      // Valida si el string capturado cumple con el esquema estándar para tokens (RFC URI Scheme).
      if (rawValue.startsWith('otpauth://totp/')) {
        try {
          // Parsea el string en un objeto Uri para extracción estructurada de componentes.
          final Uri uri = Uri.parse(rawValue);
          
          // Verifica la existencia del parámetro obligatorio 'secret'.
          if (uri.queryParameters.containsKey('secret')) {
            final String secret = uri.queryParameters['secret']!;
            
            // Extrae el nombre de la cuenta (usualmente el path). Si no existe, asigna un valor genérico.
            final String accountName = uri.pathSegments.isNotEmpty ? uri.pathSegments.last : 'Usuario POC';

            // Invoca la persistencia de datos.
            _saveAccount(accountName, secret);

            // Cambia el estado para desmontar el widget de la cámara y volver a la lista.
            setState(() {
              _isScanning = false;
            });
            
            // Detiene explícitamente el flujo de la cámara para ahorrar batería y recursos.
            _scannerController.stop();
            return; // Termina la ejecución tras procesar el primer código válido.
          }
        } catch (e) {
          // Falla silenciosa en la UI, registro en consola para depuración de URIs malformados.
          debugPrint('Error parseando URI: $e');
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          // Modifica el título de la barra de navegación según el estado actual.
          _isScanning ? 'ESCANEANDO QR...' : 'QRSCAN - BÓVEDA MFA',
          style: const TextStyle(fontFamily: 'monospace', color: Color(0xFF00FF41)),
        ),
        backgroundColor: Colors.black,
        // Define un borde inferior estilizado (línea verde) para el AppBar.
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1.0),
          child: Container(
            color: const Color(0xFF00FF41),
            height: 1.0,
          ),
        ),
        actions: [
          // Lógica de renderizado condicional para mostrar el botón adecuado.
          if (!_isScanning)
            IconButton(
              icon: const Icon(Icons.add_a_photo, color: Color(0xFF00FF41)),
              onPressed: () {
                setState(() {
                  _isScanning = true;
                });
                // Inicia la captura de frames de la cámara.
                _scannerController.start();
              },
            ),
          if (_isScanning)
            IconButton(
              icon: const Icon(Icons.close, color: Colors.redAccent),
              onPressed: () {
                setState(() {
                  _isScanning = false;
                });
                // Detiene la cámara si el usuario cancela la operación.
                _scannerController.stop();
              },
            )
        ],
      ),
      // Alterna entre los dos widgets principales de cuerpo basado en la variable de estado _isScanning.
      body: _isScanning ? _buildScanner() : _buildAccountsList(),
    );
  }

  /**
   * Construye e inicializa el widget del escáner asociado a la cámara.
   */
  Widget _buildScanner() {
    return MobileScanner(
      controller: _scannerController,
      onDetect: _processQRCode, // Vincula el método de procesamiento al evento de detección.
    );
  }

  /**
   * Construye la lista desplazable de tokens TOTP.
   */
  Widget _buildAccountsList() {
    // Retorna una vista de retroalimentación vacía si no hay registros cargados.
    if (_accounts.isEmpty) {
      return const Center(
        child: Text(
          '> NO HAY SECRETOS ALMACENADOS\n> PRESIONA EL ICONO DE CÁMARA PARA AGREGAR',
          textAlign: TextAlign.center,
          style: TextStyle(color: Colors.grey, fontFamily: 'monospace'),
        ),
      );
    }

    // Obtiene el tiempo transcurrido desde Epoch (1 de enero de 1970) en milisegundos.
    // Esto es el input temporal base para calcular el token matemático correcto de la ventana actual.
    final int timestamp = DateTime.now().millisecondsSinceEpoch;

    // Genera la UI de forma eficiente solo para los elementos visibles en pantalla (ListView.builder).
    return ListView.builder(
      itemCount: _accounts.length,
      itemBuilder: (context, index) {
        final account = _accounts[index];
        final String secret = account['secret']!;
        final String name = account['accountName']!;

        // Llamada a la librería criptográfica para computar el TOTP actual.
        // Se ejecuta repetidamente gracias al Timer configurado en initState().
        final String code = OTP.generateTOTPCodeString(
          secret,
          timestamp,
          algorithm: Algorithm.SHA1, // Algoritmo hashing estándar.
          isGoogle: true,            // Bandera de compatibilidad para asegurar formato estándar (RFC 6238).
          length: 6,                 // Longitud de 6 dígitos.
          interval: 30,              // Ventana de validez de 30 segundos.
        );

        return Container(
          margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          decoration: BoxDecoration(
            border: Border.all(color: const Color(0xFF00FF41).withOpacity(0.3)),
            color: Colors.black,
          ),
          child: ListTile(
            title: Text(
              name,
              style: const TextStyle(color: Colors.white, fontFamily: 'monospace', fontSize: 14),
            ),
            subtitle: Text(
              code, // Se inyecta el token calculado para este frame.
              style: const TextStyle(
                color: Color(0xFF00FF41),
                fontFamily: 'monospace',
                fontSize: 32,
                fontWeight: FontWeight.bold,
                letterSpacing: 4,
              ),
            ),
            trailing: IconButton(
              icon: const Icon(Icons.delete_outline, color: Colors.redAccent),
              onPressed: () => _deleteAccount(index),
            ),
          ),
        );
      },
    );
  }
}
