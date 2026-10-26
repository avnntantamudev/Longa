import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'requests_repository.dart';

final _catalogProvider = FutureProvider.autoDispose<({List<Skill> skills, List<Zone> zones, List<String> provinces})>((ref) async {
  final repo = ref.watch(requestsRepositoryProvider);
  final results = await Future.wait([repo.skills(), repo.zones(), repo.provinces()]);
  return (skills: results[0] as List<Skill>, zones: results[1] as List<Zone>, provinces: results[2] as List<String>);
});

/// Pedir aula: área, tema, valor, data, duração, pagamento, modalidade e local no mapa.
class CreateRequestScreen extends ConsumerStatefulWidget {
  const CreateRequestScreen({super.key});

  @override
  ConsumerState<CreateRequestScreen> createState() => _CreateRequestScreenState();
}

class _CreateRequestScreenState extends ConsumerState<CreateRequestScreen> {
  static const _luanda = LatLng(-8.8383, 13.2344);

  final _title = TextEditingController();
  final _description = TextEditingController();
  final _amount = TextEditingController();
  final _municipality = TextEditingController();
  final _neighborhood = TextEditingController();
  final _map = MapController();

  Skill? _skill;
  DateTime _when = DateTime.now().add(const Duration(hours: 2));
  int _duration = 60;
  String _payment = 'electronic';
  String _modality = 'in_person';
  String _province = 'Luanda';
  LatLng? _point;
  Map<String, String> _errors = const {};
  String? _error;
  bool _saving = false;

  @override
  void dispose() {
    for (final c in [_title, _description, _amount, _municipality, _neighborhood]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickDateTime() async {
    final date = await showDatePicker(context: context, initialDate: _when, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 60)));
    if (date == null || !mounted) return;
    final time = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_when));
    if (time == null) return;
    setState(() => _when = DateTime(date.year, date.month, date.day, time.hour, time.minute));
  }

  Future<void> _useMyLocation(List<Zone> zones) async {
    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        setState(() => _error = 'Sem permissão de localização. Toque no mapa para marcar o local.');
        return;
      }
      final pos = await Geolocator.getCurrentPosition();
      final point = LatLng(pos.latitude, pos.longitude);
      setState(() => _point = point);
      _map.move(point, 15);
      // Bairro mais próximo das zonas conhecidas, se ainda não estiver escrito
      if (_neighborhood.text.isEmpty && zones.isNotEmpty) {
        zones.sort((a, b) => _dist(a, point).compareTo(_dist(b, point)));
        _neighborhood.text = zones.first.neighborhood;
        if (_municipality.text.isEmpty) _municipality.text = zones.first.municipality;
      }
    } catch (_) {
      setState(() => _error = 'Não foi possível obter a localização. Toque no mapa para marcar o local.');
    }
  }

  double _dist(Zone z, LatLng p) => (z.lat - p.latitude).abs() + (z.lng - p.longitude).abs();

  Future<void> _submit() async {
    final amount = int.tryParse(_amount.text.replaceAll('.', '').trim());
    if (_skill == null || amount == null || _point == null) {
      setState(() => _error = 'Escolha a área, indique o valor e marque o local no mapa.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
      _errors = const {};
    });
    try {
      final created = await ref.read(requestsRepositoryProvider).create(NewRequest(
            skillId: _skill!.id,
            title: _title.text.trim().isEmpty ? 'Aula de ${_skill!.name.split(' — ').first}' : _title.text.trim(),
            description: _description.text.trim(),
            amount: amount,
            scheduledAt: _when,
            durationMinutes: _duration,
            paymentMethod: _payment,
            modality: _modality,
            province: _province,
            municipality: _municipality.text.trim(),
            neighborhood: _neighborhood.text.trim(),
            latitude: _point!.latitude,
            longitude: _point!.longitude,
          ));
      if (mounted) context.push('/pedidos/${created.id}');
    } catch (e) {
      setState(() {
        _error = errorText(e);
      });
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final catalog = ref.watch(_catalogProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Pedir aula')),
      body: catalog.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => LoadError(e, onRetry: () => ref.invalidate(_catalogProvider)),
        data: (c) => ListView(padding: const EdgeInsets.all(16), children: [
          const Text('Enviamos o pedido aos formadores verificados perto de si. O primeiro que aceitar fica com a aula.', style: TextStyle(color: LongaColors.inkMuted)),
          const SizedBox(height: 16),
          DropdownButtonFormField<Skill>(
            value: _skill,
            isExpanded: true,
            decoration: InputDecoration(labelText: 'O que quer aprender?', errorText: _errors['skill_id']),
            items: [for (final s in c.skills) DropdownMenuItem(value: s, child: Text(s.name, overflow: TextOverflow.ellipsis))],
            onChanged: (s) => setState(() => _skill = s),
          ),
          const SizedBox(height: 12),
          TextField(controller: _title, maxLength: 120, decoration: const InputDecoration(labelText: 'Tema (opcional)', counterText: '')),
          const SizedBox(height: 12),
          TextField(controller: _description, maxLines: 3, maxLength: 1000, decoration: const InputDecoration(labelText: 'Detalhes (opcional)')),
          const SizedBox(height: 4),
          TextField(controller: _amount, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Valor (Kz)', hintText: '5000')),
          const SizedBox(height: 12),
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.event),
            title: Text(when(_when)),
            subtitle: const Text('Toque para mudar a data e a hora'),
            onTap: _pickDateTime,
          ),
          DropdownButtonFormField<int>(
            value: _duration,
            decoration: const InputDecoration(labelText: 'Duração'),
            items: [for (final m in const [30, 45, 60, 90, 120, 180, 240]) DropdownMenuItem(value: m, child: Text(m < 60 ? '$m min' : '${(m / 60).toStringAsFixed(m % 60 == 0 ? 0 : 1).replaceAll('.', ',')} h'))],
            onChanged: (v) => setState(() => _duration = v ?? 60),
          ),
          const SizedBox(height: 16),
          const Text('Pagamento', style: TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          SegmentedButton<String>(
            segments: const [ButtonSegment(value: 'electronic', label: Text('Pela LONGA')), ButtonSegment(value: 'cash', label: Text('Em mão'))],
            selected: {_payment},
            onSelectionChanged: (v) => setState(() => _payment = v.first),
          ),
          const SizedBox(height: 4),
          Text(
            _payment == 'electronic'
                ? 'Paga quando o formador aceitar. O valor fica retido e só é libertado depois de confirmar a aula com o QR.'
                : 'Paga ao formador no dia da aula. Confirma na app depois da aula.',
            style: const TextStyle(color: LongaColors.inkMuted, fontSize: 13),
          ),
          const SizedBox(height: 16),
          const Text('Modalidade', style: TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          SegmentedButton<String>(
            segments: const [ButtonSegment(value: 'in_person', label: Text('Presencial')), ButtonSegment(value: 'online', label: Text('Online'))],
            selected: {_modality},
            onSelectionChanged: (v) => setState(() => _modality = v.first),
          ),
          const SizedBox(height: 16),
          const Text('Onde vai ser a aula?', style: TextStyle(fontWeight: FontWeight.w600)),
          const Text('Os formadores só veem o bairro e a distância aproximada até aceitarem.', style: TextStyle(color: LongaColors.inkMuted, fontSize: 13)),
          const SizedBox(height: 8),
          OutlinedButton.icon(onPressed: () => _useMyLocation([...c.zones]), icon: const Icon(Icons.my_location), label: const Text('Usar a minha localização')),
          const SizedBox(height: 8),
          SizedBox(
            height: 240,
            child: ClipRRect(
              borderRadius: BorderRadius.circular(16),
              child: FlutterMap(
                mapController: _map,
                options: MapOptions(initialCenter: _point ?? _luanda, initialZoom: _point == null ? 12 : 15, onTap: (_, p) => setState(() => _point = p)),
                children: [
                  // Tiles OSM só para desenvolvimento; em produção usar um fornecedor de mapas
                  TileLayer(urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', userAgentPackageName: 'ao.longa.app'),
                  if (_point != null)
                    MarkerLayer(markers: [Marker(point: _point!, width: 40, height: 40, child: const Icon(Icons.location_on, color: LongaColors.orange500, size: 40))]),
                ],
              ),
            ),
          ),
          Text(_point == null ? 'Toque no mapa para marcar o local.' : 'Local marcado.', style: TextStyle(color: _point == null ? LongaColors.inkMuted : LongaColors.success, fontSize: 13)),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: c.provinces.contains(_province) ? _province : null,
            decoration: const InputDecoration(labelText: 'Província'),
            items: [for (final p in c.provinces) DropdownMenuItem(value: p, child: Text(p))],
            onChanged: (v) => setState(() => _province = v ?? _province),
          ),
          const SizedBox(height: 12),
          TextField(controller: _municipality, decoration: const InputDecoration(labelText: 'Município')),
          const SizedBox(height: 12),
          TextField(controller: _neighborhood, decoration: const InputDecoration(labelText: 'Bairro')),
          const SizedBox(height: 16),
          if (_error != null) ...[Notice(_error!), const SizedBox(height: 12)],
          FilledButton(onPressed: _saving ? null : _submit, child: Text(_saving ? 'A publicar…' : 'Procurar formador')),
        ]),
      ),
    );
  }
}
