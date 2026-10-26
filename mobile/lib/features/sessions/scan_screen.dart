import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_error.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'session_models.dart';
import 'sessions_repository.dart';

/// Confirmar a aula (aluno): ler o QR do formador ou escrever o código de 6 dígitos.
/// A localização é opcional e serve só como sinal de risco no servidor.
class ScanScreen extends ConsumerStatefulWidget {
  const ScanScreen({super.key, required this.sessionId});

  final int sessionId;

  @override
  ConsumerState<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends ConsumerState<ScanScreen> {
  final _scanner = MobileScannerController(formats: const [BarcodeFormat.qrCode]);
  final _code = TextEditingController();
  String _key = ApiClient.newIdempotencyKey();
  String? _error;
  String? _done;
  bool _sending = false;
  Position? _position;

  @override
  void initState() {
    super.initState();
    _locate();
  }

  @override
  void dispose() {
    _scanner.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _locate() async {
    try {
      final permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) return;
      _position = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.medium, timeLimit: Duration(seconds: 8)));
    } catch (_) {
      _position = null;
    }
  }

  void _onDetect(BarcodeCapture capture) {
    if (_sending || _done != null) return;
    final raw = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
    if (raw == null) return;
    final parsed = parseQrPayload(raw);
    if (parsed == null) {
      setState(() => _error = 'Este QR não é de uma aula LONGA.');
    } else if (parsed.sessionId != widget.sessionId) {
      setState(() => _error = 'Este QR é de outra aula.');
    } else {
      _send(token: parsed.token);
    }
  }

  Future<void> _send({String? token, String? code}) async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final message = await ref.read(sessionsRepositoryProvider).confirm(
            widget.sessionId,
            token: token,
            code: code,
            latitude: _position?.latitude,
            longitude: _position?.longitude,
            idempotencyKey: _key,
          );
      await _scanner.stop();
      setState(() => _done = message);
    } on ApiError catch (e) {
      // Erro definitivo: chave nova; sem rede: repete com a mesma (não confirma duas vezes)
      if (!e.isNetwork) _key = ApiClient.newIdempotencyKey();
      setState(() => _error = e.userMessage);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_done != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Aula confirmada')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const CircleAvatar(radius: 48, backgroundColor: LongaColors.success, child: Icon(Icons.check, color: Colors.white, size: 56)),
              const SizedBox(height: 16),
              Text('Aula confirmada', style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 8),
              Text(_done!, textAlign: TextAlign.center),
              const SizedBox(height: 24),
              FilledButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Voltar à aula')),
            ]),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Escanear QR para confirmar')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        const Text('Aponte a câmara para o QR no telemóvel do formador.', style: TextStyle(color: LongaColors.inkMuted)),
        const SizedBox(height: 12),
        AspectRatio(
          aspectRatio: 1,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(24),
            child: Stack(fit: StackFit.expand, children: [
              MobileScanner(
                controller: _scanner,
                onDetect: _onDetect,
                errorBuilder: (_, __, ___) => const ColoredBox(
                  color: LongaColors.navy900,
                  child: Center(child: Padding(padding: EdgeInsets.all(24), child: Text('Não foi possível usar a câmara. Peça ao formador o código de 6 dígitos.', textAlign: TextAlign.center, style: TextStyle(color: Colors.white)))),
                ),
              ),
              IgnorePointer(child: Container(margin: const EdgeInsets.all(40), decoration: BoxDecoration(border: Border.all(color: LongaColors.orange500, width: 4), borderRadius: BorderRadius.circular(16)))),
              if (_sending) const ColoredBox(color: Colors.black38, child: Center(child: CircularProgressIndicator())),
            ]),
          ),
        ),
        const SizedBox(height: 12),
        if (_error != null) ...[Notice(_error!), const SizedBox(height: 12)],
        LongaCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('Ou escreva o código', style: TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              maxLength: 6,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 26, letterSpacing: 8, fontWeight: FontWeight.w700),
              decoration: const InputDecoration(counterText: '', hintText: '000000'),
            ),
            const SizedBox(height: 8),
            FilledButton(
              onPressed: _sending ? null : () => _code.text.length == 6 ? _send(code: _code.text) : setState(() => _error = 'O código tem 6 dígitos.'),
              child: const Text('Confirmar'),
            ),
          ]),
        ),
      ]),
    );
  }
}
