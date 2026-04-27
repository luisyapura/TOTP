import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:otp/otp.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  runApp(const QRScanApp());
}

class QRScanApp extends StatelessWidget {
  const QRScanApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'QRSCAN POC',
      theme: ThemeData.dark().copyWith(
        scaffoldBackgroundColor: const Color(0xFF0D0D0D),
        primaryColor: const Color(0xFF00FF41),
      ),
      home: const TOTPListScreen(),
    );
  }
}

class TOTPListScreen extends StatefulWidget {
  const TOTPListScreen({super.key});

  @override
  State<TOTPListScreen> createState() => _TOTPListScreenState();
}

class _TOTPListScreenState extends State<TOTPListScreen> {
  final MobileScannerController _scannerController = MobileScannerController();

  List<Map<String, String>> _accounts = [];
  Timer? _timer;
  bool _isScanning = false;

  @override
  void initState() {
    super.initState();
    _loadAccounts();
    // Sincronización continua de 1 Hz para la regeneración de todos los tokens en la UI
    _timer = Timer.periodic(const Duration(seconds: 1), (_) => setState(() {}));
  }

  @override
  void dispose() {
    _timer?.cancel();
    _scannerController.dispose();
    super.dispose();
  }

  // Carga el string JSON de la memoria persistente
  Future<void> _loadAccounts() async {
    final prefs = await SharedPreferences.getInstance();
    final String? accountsJson = prefs.getString('totp_accounts');
    if (accountsJson != null) {
      final List<dynamic> decoded = jsonDecode(accountsJson);
      setState(() {
        _accounts = decoded.map((e) => Map<String, String>.from(e)).toList();
      });
    }
  }

  // Guarda una nueva cuenta serializando a JSON
  Future<void> _saveAccount(String name, String secret) async {
    // Prevención de duplicados exactos
    if (_accounts.any((acc) => acc['secret'] == secret)) {
      return;
    }
    _accounts.add({'accountName': name, 'secret': secret});
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('totp_accounts', jsonEncode(_accounts));
    setState(() {});
  }

  // Elimina una cuenta específica
  Future<void> _deleteAccount(int index) async {
    _accounts.removeAt(index);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('totp_accounts', jsonEncode(_accounts));
    setState(() {});
  }

  // Procesamiento del payload QR
  void _processQRCode(BarcodeCapture capture) {
    final List<Barcode> barcodes = capture.barcodes;
    for (final barcode in barcodes) {
      if (barcode.rawValue == null) continue;

      final String rawValue = barcode.rawValue!;
      if (rawValue.startsWith('otpauth://totp/')) {
        try {
          final Uri uri = Uri.parse(rawValue);
          if (uri.queryParameters.containsKey('secret')) {
            final String secret = uri.queryParameters['secret']!;
            final String accountName = uri.pathSegments.isNotEmpty ? uri.pathSegments.last : 'Usuario POC';

            _saveAccount(accountName, secret);

            setState(() {
              _isScanning = false; // Retornar a la lista tras lectura exitosa
            });
            _scannerController.stop();
            return;
          }
        } catch (e) {
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
          _isScanning ? 'ESCANEANDO QR...' : 'QRSCAN - BÓVEDA MFA',
          style: const TextStyle(fontFamily: 'monospace', color: Color(0xFF00FF41)),
        ),
        backgroundColor: Colors.black,
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1.0),
          child: Container(
            color: const Color(0xFF00FF41),
            height: 1.0,
          ),
        ),
        actions: [
          if (!_isScanning)
            IconButton(
              icon: const Icon(Icons.add_a_photo, color: Color(0xFF00FF41)),
              onPressed: () {
                setState(() {
                  _isScanning = true;
                });
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
                _scannerController.stop();
              },
            )
        ],
      ),
      body: _isScanning ? _buildScanner() : _buildAccountsList(),
    );
  }

  Widget _buildScanner() {
    return MobileScanner(
      controller: _scannerController,
      onDetect: _processQRCode,
    );
  }

  Widget _buildAccountsList() {
    if (_accounts.isEmpty) {
      return const Center(
        child: Text(
          '> NO HAY SECRETOS ALMACENADOS\n> PRESIONA EL ICONO DE CÁMARA PARA AGREGAR',
          textAlign: TextAlign.center,
          style: TextStyle(color: Colors.grey, fontFamily: 'monospace'),
        ),
      );
    }

    final int timestamp = DateTime.now().millisecondsSinceEpoch;

    return ListView.builder(
      itemCount: _accounts.length,
      itemBuilder: (context, index) {
        final account = _accounts[index];
        final String secret = account['secret']!;
        final String name = account['accountName']!;

        // Generación dinámica en tiempo real para todos los items listados
        final String code = OTP.generateTOTPCodeString(
          secret,
          timestamp,
          algorithm: Algorithm.SHA1,
          isGoogle: true,
          length: 6,
          interval: 30,
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
              code,
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
