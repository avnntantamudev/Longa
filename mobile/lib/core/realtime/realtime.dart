import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../providers.dart';

/// Evento recebido de um canal (ex.: session.confirmed, wallet.updated).
class RealtimeEvent {
  RealtimeEvent(this.channel, this.name, this.data);

  final String channel;
  final String name;
  final Map<String, dynamic> data;
}

typedef SocketFactory = WebSocketChannel Function(Uri uri);

final realtimeProvider = Provider<RealtimeClient>((ref) {
  final client = RealtimeClient(ref.watch(apiClientProvider));
  ref.onDispose(client.dispose);
  return client;
});

/// Cliente mínimo do protocolo Pusher (usado pelo Laravel Reverb) para canais privados.
///
/// Liga-se só quando algum ecrã subscreve um canal; autoriza cada canal em /broadcasting/auth com
/// o token da sessão; volta a ligar com espera crescente. Se o tempo real falhar, os ecrãs continuam
/// a funcionar com a atualização periódica.
class RealtimeClient {
  RealtimeClient(this.api, {SocketFactory? socketFactory}) : _socketFactory = socketFactory ?? WebSocketChannel.connect;

  final ApiClient api;
  final SocketFactory _socketFactory;

  WebSocketChannel? _socket;
  StreamSubscription<dynamic>? _subscription;
  String? _socketId;
  int _retry = 0;
  Timer? _reconnect;
  bool _disposed = false;

  final Map<String, StreamController<RealtimeEvent>> _channels = {};

  Uri get _uri => Uri(
        scheme: AppConfig.reverbTls ? 'wss' : 'ws',
        host: AppConfig.reverbHost,
        port: AppConfig.reverbPort,
        path: '/app/${AppConfig.reverbKey}',
        queryParameters: {'protocol': '7', 'client': 'longa-flutter', 'version': '1.0'},
      );

  /// Eventos de um canal privado (sem o prefixo "private-"), ex.: "session.12".
  Stream<RealtimeEvent> privateChannel(String name) {
    final channel = 'private-$name';
    final controller = _channels.putIfAbsent(
      channel,
      () => StreamController<RealtimeEvent>.broadcast(
        onListen: () => _socketId == null ? _connect() : _subscribe(channel),
        onCancel: () => _unsubscribe(channel),
      ),
    );
    return controller.stream;
  }

  void _connect() {
    if (_socket != null || _disposed) return;
    try {
      final socket = _socketFactory(_uri);
      _socket = socket;
      _subscription = socket.stream.listen(_onMessage, onDone: _onClosed, onError: (Object _) => _onClosed());
    } catch (_) {
      _onClosed();
    }
  }

  void _onMessage(dynamic raw) {
    final message = jsonDecode(raw as String) as Map<String, dynamic>;
    final event = message['event'] as String? ?? '';
    final data = _decode(message['data']);

    switch (event) {
      case 'pusher:connection_established':
        _socketId = data['socket_id'] as String?;
        _retry = 0;
        for (final channel in _channels.keys) {
          if (_channels[channel]!.hasListener) _subscribe(channel);
        }
      case 'pusher:ping':
        _send({'event': 'pusher:pong', 'data': <String, dynamic>{}});
      case 'pusher:error' || 'pusher_internal:subscription_succeeded':
        break;
      default:
        final channel = message['channel'] as String?;
        final controller = channel == null ? null : _channels[channel];
        if (controller != null && !controller.isClosed) {
          // broadcastAs() no servidor: o nome chega sem o ponto inicial
          controller.add(RealtimeEvent(channel!, event.startsWith('.') ? event.substring(1) : event, data));
        }
    }
  }

  Future<void> _subscribe(String channel) async {
    final socketId = _socketId;
    if (socketId == null) return;
    try {
      final auth = await api.post('/broadcasting/auth', data: {'socket_id': socketId, 'channel_name': channel});
      _send({'event': 'pusher:subscribe', 'data': {'auth': auth['auth'], 'channel': channel}});
    } catch (_) {
      // Sem autorização (ex.: sessão expirada): fica sem tempo real neste canal
    }
  }

  void _unsubscribe(String channel) {
    _send({'event': 'pusher:unsubscribe', 'data': {'channel': channel}});
    _channels.remove(channel)?.close();
  }

  void _send(Map<String, dynamic> message) {
    try {
      _socket?.sink.add(jsonEncode(message));
    } catch (_) {}
  }

  void _onClosed() {
    _subscription?.cancel();
    _socket = null;
    _socketId = null;
    if (_disposed || _channels.isEmpty) return;
    final delay = Duration(seconds: [1, 2, 5, 10, 30][_retry.clamp(0, 4)]);
    _retry++;
    _reconnect?.cancel();
    _reconnect = Timer(delay, _connect);
  }

  static Map<String, dynamic> _decode(Object? data) {
    if (data is Map<String, dynamic>) return data;
    if (data is String && data.isNotEmpty) {
      try {
        final decoded = jsonDecode(data);
        if (decoded is Map<String, dynamic>) return decoded;
      } catch (_) {}
    }
    return <String, dynamic>{};
  }

  void dispose() {
    _disposed = true;
    _reconnect?.cancel();
    _subscription?.cancel();
    _socket?.sink.close();
    for (final c in _channels.values) {
      c.close();
    }
    _channels.clear();
  }
}
