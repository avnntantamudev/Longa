/// Erro no formato único da API: {error: {code, message, details}, request_id}.
class ApiError implements Exception {
  ApiError(this.status, this.code, this.message, [this.details = const {}]);

  /// Sem resposta do servidor (sem rede, tempo esgotado).
  factory ApiError.network() => ApiError(0, 'network', 'Sem ligação. Verifique a internet e tente de novo.');

  factory ApiError.fromBody(int status, Object? body) {
    if (body is Map && body['error'] is Map) {
      final error = body['error'] as Map;
      final details = error['details'];
      return ApiError(
        status,
        (error['code'] ?? 'http_error').toString(),
        (error['message'] ?? 'Não foi possível concluir o pedido.').toString(),
        details is Map ? details.map((k, v) => MapEntry(k.toString(), v)) : const {},
      );
    }
    return ApiError(status, 'http_error', 'Não foi possível concluir o pedido.');
  }

  final int status;
  final String code;
  final String message;
  final Map<String, Object?> details;

  bool get isNetwork => status == 0;

  /// Erros de validação por campo: {campo: primeira mensagem}.
  Map<String, String> get fieldErrors {
    if (code != 'validation_failed') return const {};
    return details.map((field, messages) => MapEntry(field, messages is List && messages.isNotEmpty ? messages.first.toString() : messages.toString()));
  }

  /// Primeira mensagem útil para mostrar ao utilizador.
  String get userMessage => fieldErrors.values.isNotEmpty ? fieldErrors.values.first : message;

  @override
  String toString() => 'ApiError($status, $code): $message';
}
