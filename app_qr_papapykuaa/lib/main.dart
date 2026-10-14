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

  // Ajusta la URL a tu entorno (producción / staging)
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

        final already = data['already_scanned'] == true;
        final asistioAt = (persona['asistio_at'] ?? data['data']?['asistio_at'])?.toString();

        // Pausamos scanner para evitar lecturas múltiples
        await _controller.stop();

        // Navega a pantalla de éxito mostrando si ya fue escaneado
        await Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => SuccessPage(
              nombreCompleto: nombreCompleto,
              alreadyScanned: already,
              asistioAt: asistioAt,
            ),
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
  final bool alreadyScanned;
  final String? asistioAt;

  const SuccessPage({
    super.key,
    required this.nombreCompleto,
    this.alreadyScanned = false,
    this.asistioAt,
  });

  @override
  Widget build(BuildContext context) {
    final title = alreadyScanned ? 'Asistencia ya registrada' : 'Asistencia registrada';
    final icon = alreadyScanned ? Icons.info_outline : Icons.check_circle_outline;
    final iconColor = alreadyScanned ? Colors.orange : Colors.green;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Resultado'),
        centerTitle: true,
        backgroundColor: iconColor,
      ),
      body: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          children: [
            const SizedBox(height: 18),
            Icon(icon, size: 88, color: iconColor),
            const SizedBox(height: 18),
            Text(
              title,
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: iconColor),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 14),
            Text(
              nombreCompleto.isNotEmpty ? nombreCompleto : 'Nombre desconocido',
              style: const TextStyle(fontSize: 18),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 12),
            if (asistioAt != null && asistioAt!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                alreadyScanned
                    ? 'Primera lectura: $asistioAt'
                    : 'Fecha y hora: $asistioAt',
                style: const TextStyle(fontSize: 14, color: Colors.black54),
                textAlign: TextAlign.center,
              ),
            ],
            const Spacer(),
            ElevatedButton.icon(
              onPressed: () => Navigator.pop(context),
              icon: const Icon(Icons.arrow_back),
              label: const Text('Aceptar'),
              style: ElevatedButton.styleFrom(
                backgroundColor: iconColor,
                minimumSize: const Size(double.infinity, 48),
              ),
            ),
            const SizedBox(height: 12),
            TextButton(
              onPressed: () {
                // Volver al scanner (pop)
                Navigator.pop(context);
              },
              child: const Text('Volver a escanear'),
            ),
          ],
        ),
      ),
    );
  }
}