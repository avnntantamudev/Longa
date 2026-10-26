import 'package:flutter/material.dart';

import '../core/api/api_error.dart';
import '../core/config.dart';
import '../core/theme.dart';

/// Foto de perfil ou, sem foto, as iniciais no círculo laranja do kit.
class UserAvatar extends StatelessWidget {
  const UserAvatar({super.key, this.name, this.photoPath, this.size = 40});

  final String? name;

  /// Caminho relativo devolvido pela API (ex.: /fotos/abc-128.webp).
  final String? photoPath;
  final double size;

  String get _initials {
    final parts = (name ?? '?').split(' ').where((p) => p.isNotEmpty).take(2);
    final value = parts.map((p) => p[0]).join().toUpperCase();
    return value.isEmpty ? '?' : value;
  }

  @override
  Widget build(BuildContext context) {
    final fallback = CircleAvatar(
      radius: size / 2,
      backgroundColor: LongaColors.orange500,
      child: Text(_initials, style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: size * 0.36)),
    );
    if (photoPath == null) return fallback;

    return ClipOval(
      child: Image.network(
        photoPath!.startsWith('http') ? photoPath! : '${AppConfig.apiUrl}$photoPath',
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => fallback,
      ),
    );
  }
}

/// Mensagem de erro ou de sucesso no topo de um formulário.
class Notice extends StatelessWidget {
  const Notice(this.message, {super.key, this.success = false});

  final String message;
  final bool success;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: success ? LongaColors.successSoft : LongaColors.dangerSoft, borderRadius: BorderRadius.circular(12)),
        child: Text(message, style: TextStyle(color: success ? LongaColors.success : LongaColors.danger, fontWeight: FontWeight.w600)),
      );
}

/// Cartão branco com margem, como `.card` na PWA.
class LongaCard extends StatelessWidget {
  const LongaCard({super.key, required this.child, this.onTap, this.padding = const EdgeInsets.all(16)});

  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) => Card(
        margin: EdgeInsets.zero,
        clipBehavior: Clip.antiAlias,
        child: InkWell(onTap: onTap, child: Padding(padding: padding, child: child)),
      );
}

/// Etiqueta (badge) de estado.
class StateBadge extends StatelessWidget {
  const StateBadge(this.label, {super.key, this.color = LongaColors.navy500, this.background = LongaColors.navy50});

  final String label;
  final Color color;
  final Color background;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(99)),
        child: Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
      );
}

/// Texto de erro amigável para qualquer exceção.
String errorText(Object error) => error is ApiError ? error.userMessage : 'Ocorreu um erro inesperado. Tente de novo.';

void showSnack(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message), backgroundColor: error ? LongaColors.danger : LongaColors.navy900));
}

/// Estado de carregamento/erro comum (com "Tentar de novo").
class LoadError extends StatelessWidget {
  const LoadError(this.error, {super.key, required this.onRetry});

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(errorText(error), textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: onRetry, child: const Text('Tentar de novo')),
          ]),
        ),
      );
}
