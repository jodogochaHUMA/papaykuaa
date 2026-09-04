import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:http/http.dart' as http;

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Validador QR Papapykuaa',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(primarySwatch: Colors.red),
      home: const ScannerPage(),
    );
  }
}

class ScannerPage extends StatefulWidget {
  const ScannerPage({super.key});

  @override
  State<ScannerPage> createState() => _ScannerPageState();
}

class _ScannerPageState extends State<ScannerPage> {
  final MobileScannerController _controller = MobileScannerController();
  bool _isProcessing = false;

  final String verifyUrl = 'https://repem.net/admin/verify_qr.php';

  Future<void> _validarQR(String code) async {
    if (_isProcessing) return;
    setState(() => _isProcessing = true);

    try {
      final response = await http.post(
        Uri.parse(verifyUrl),
        body: {'code': code},
      );

      final data = jsonDecode(response.body);

      if (!mounted) return;

      if (data['ok'] == true) {
        final persona = data['data'] ?? {};
        final nombre = (persona['nombre'] ?? '').toString();
        final apellido = (persona['apellido'] ?? '').toString();
        final nombreCompleto = '$nombre $apellido'.trim();

        // Pausamos scanner para evitar lecturas múltiples
        await _controller.stop();

        // Navega a pantalla de éxito
        await Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => SuccessPage(nombreCompleto: nombreCompleto),
          ),
        );

        // Al volver, reanudamos scanner
        await _controller.start();
      } else {
        final msg = (data['message'] ?? 'QR inválido').toString();
        _mostrarError(msg);
      }
    } catch (e) {
      _mostrarError('Error de conexión: $e');
    } finally {
      if (mounted) setState(() => _isProcessing = false);
    }
  }

  void _mostrarError(String mensaje) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('❌ $mensaje'),
        backgroundColor: Colors.red,
        duration: const Duration(seconds: 2),
      ),
    );
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Validador QR - Papapykuaa'),
        centerTitle: true,
      ),
      body: Stack(
        children: [
          MobileScanner(
            controller: _controller,
            onDetect: (capture) {
              final barcodes = capture.barcodes;
              if (barcodes.isNotEmpty) {
                final raw = barcodes.first.rawValue;
                if (raw != null && raw.isNotEmpty) {
                  _validarQR(raw);
                }
              }
            },
          ),
          if (_isProcessing)
            Container(
              color: Colors.black45,
              child: const Center(
                child: CircularProgressIndicator(color: Colors.white),
              ),
            ),
        ],
      ),
    );
  }
}

class SuccessPage extends StatelessWidget {
  final String nombreCompleto;

  const SuccessPage({super.key, required this.nombreCompleto});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.green.shade50,
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Card(
              elevation: 6,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
              ),
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.check_circle, size: 72, color: Colors.green),
                    const SizedBox(height: 16),
                    const Text(
                      '¡Bienvenido/a!',
                      style: TextStyle(
                        fontSize: 30,
                        fontWeight: FontWeight.bold,
                        color: Colors.green,
                      ),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      nombreCompleto.isEmpty ? 'Participante' : nombreCompleto,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 28),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        onPressed: () => Navigator.pop(context),
                        icon: const Icon(Icons.qr_code_scanner),
                        label: const Text('Escanear otro QR'),
                        style: ElevatedButton.styleFrom(
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          textStyle: const TextStyle(fontSize: 18),
                        ),
                      ),
                    )
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}