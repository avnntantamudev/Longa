import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:longa/shared/pin_pad.dart';

void main() {
  testWidgets('o teclado preenche 6 dígitos, apaga e devolve o PIN', (tester) async {
    String? completed;
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: SingleChildScrollView(child: PinPad(onComplete: (p) => completed = p)))));

    for (final d in ['4', '8', '2']) {
      await tester.tap(find.text(d));
    }
    await tester.tap(find.text('⌫'));
    for (final d in ['2', '9', '1', '6']) {
      await tester.tap(find.text(d));
    }
    await tester.pump();

    expect(completed, '482916');
  });

  testWidgets('desativado não aceita dígitos e mostra o erro', (tester) async {
    String? completed;
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: SingleChildScrollView(child: PinPad(onComplete: (p) => completed = p, enabled: false, error: 'PIN errado.')))));

    for (var i = 0; i < 6; i++) {
      await tester.tap(find.text('1'));
    }
    await tester.pump();

    expect(completed, isNull);
    expect(find.text('PIN errado.'), findsOneWidget);
  });
}
