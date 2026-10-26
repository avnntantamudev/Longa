import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Teclado de PIN de 6 dígitos com botões grandes. Chama [onComplete] com os 6 dígitos.
class PinPad extends StatefulWidget {
  const PinPad({super.key, required this.onComplete, this.error, this.enabled = true});

  final void Function(String pin) onComplete;
  final String? error;
  final bool enabled;

  @override
  State<PinPad> createState() => PinPadState();
}

class PinPadState extends State<PinPad> {
  String _value = '';

  /// Limpa o que foi escrito (ex.: depois de um PIN errado).
  void clear() => setState(() => _value = '');

  void _press(String digit) {
    if (!widget.enabled || _value.length >= 6) return;
    setState(() => _value += digit);
    if (_value.length == 6) {
      final pin = _value;
      widget.onComplete(pin);
    }
  }

  void _back() {
    if (widget.enabled && _value.isNotEmpty) setState(() => _value = _value.substring(0, _value.length - 1));
  }

  Widget _key(String label, {VoidCallback? onTap, String? semantic}) => Padding(
        padding: const EdgeInsets.all(6),
        child: Semantics(
          button: true,
          label: semantic ?? label,
          child: Material(
            color: onTap == null ? Colors.transparent : LongaColors.navy50,
            shape: const CircleBorder(),
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: widget.enabled ? onTap : null,
              child: SizedBox(width: 72, height: 72, child: Center(child: Text(label, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w600)))),
            ),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Semantics(
        label: '${_value.length} de 6 dígitos',
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(
            6,
            (i) => Container(
              key: ValueKey('pin-dot-$i'),
              margin: const EdgeInsets.symmetric(horizontal: 6),
              width: 16,
              height: 16,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: i < _value.length ? LongaColors.orange500 : Colors.transparent,
                border: Border.all(color: i < _value.length ? LongaColors.orange500 : LongaColors.navy200, width: 2),
              ),
            ),
          ),
        ),
      ),
      if (widget.error != null) ...[
        const SizedBox(height: 12),
        Text(widget.error!, textAlign: TextAlign.center, style: const TextStyle(color: LongaColors.danger, fontWeight: FontWeight.w600)),
      ],
      const SizedBox(height: 16),
      for (final row in const [['1', '2', '3'], ['4', '5', '6'], ['7', '8', '9']])
        Row(mainAxisAlignment: MainAxisAlignment.center, children: [for (final d in row) _key(d, onTap: () => _press(d))]),
      Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        _key(''),
        _key('0', onTap: () => _press('0')),
        _key('⌫', onTap: _back, semantic: 'Apagar'),
      ]),
    ]);
  }
}
