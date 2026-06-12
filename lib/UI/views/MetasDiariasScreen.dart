// ============================================================
// MetasDiariasScreen.dart
// Muestra al asesor sus metas, en dos pestañas:
//   - "Metas del día": metas diarias asignadas por el supervisor.
//   - "Metas del mes": suma de metas del mes y % de cumplimiento.
// Estados posibles: pendiente / completado / no_cumplido
// El backend actualiza el estado automáticamente si ya son
// las 18:00 (día) o si el mes ya cerró (mes).
// ============================================================
import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';

class MetasDiariasScreen extends StatefulWidget {
  const MetasDiariasScreen({super.key});

  @override
  State<MetasDiariasScreen> createState() => _MetasDiariasScreenState();
}

class _MetasDiariasScreenState extends State<MetasDiariasScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  // ── Estado: metas del día ──────────────────────────────────
  bool _loading = true;
  String? _error;
  bool _tieneMeta = false;
  String _mensajeUI = '';
  Map<String, dynamic>? _meta;

  // ── Estado: metas del mes ──────────────────────────────────
  bool _loadingMes = true;
  String? _errorMes;
  bool _tieneMetaMes = false;
  String _mensajeUIMes = '';
  Map<String, dynamic>? _metaMes;

  Timer? _autoRefresh;

  static const _meses = [
    '', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _cargar();
    _cargarMes();
    // Refresca cada 60s para ir actualizando el avance
    _autoRefresh = Timer.periodic(const Duration(seconds: 60), (_) {
      _cargar();
      _cargarMes();
    });
  }

  @override
  void dispose() {
    _autoRefresh?.cancel();
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _cargar() async {
    if (!mounted) return;
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final asesorId = await AuthPrefs.getAsesorId();
      final usuarioId = await AuthPrefs.getUsuarioId();
      final hoy = DateTime.now().toIso8601String().substring(0, 10);

      final url = Uri.parse('${Constants.apiBaseUrl}/obtener_metas_asesor.php');
      final resp = await http.post(url, body: {
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        if (usuarioId.isNotEmpty) 'usuario_id': usuarioId,
        'fecha': hoy,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) throw Exception('Respuesta inválida');
      if (decoded['status'] != 'success') {
        throw Exception(decoded['message']?.toString() ?? 'Error');
      }

      if (!mounted) return;
      setState(() {
        _tieneMeta = decoded['tiene_meta'] == true;
        _mensajeUI = decoded['mensaje_ui']?.toString() ?? '';
        _meta = _tieneMeta
            ? Map<String, dynamic>.from(decoded['meta'] as Map)
            : null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _cargarMes() async {
    if (!mounted) return;
    setState(() {
      _loadingMes = true;
      _errorMes = null;
    });

    try {
      final asesorId = await AuthPrefs.getAsesorId();
      final usuarioId = await AuthPrefs.getUsuarioId();
      final mesActual = DateTime.now().toIso8601String().substring(0, 7); // YYYY-MM

      final url = Uri.parse('${Constants.apiBaseUrl}/obtener_metas_mes_asesor.php');
      final resp = await http.post(url, body: {
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        if (usuarioId.isNotEmpty) 'usuario_id': usuarioId,
        'mes': mesActual,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) throw Exception('Respuesta inválida');
      if (decoded['status'] != 'success') {
        throw Exception(decoded['message']?.toString() ?? 'Error');
      }

      if (!mounted) return;
      setState(() {
        _tieneMetaMes = decoded['tiene_meta'] == true;
        _mensajeUIMes = decoded['mensaje_ui']?.toString() ?? '';
        _metaMes = _tieneMetaMes
            ? Map<String, dynamic>.from(decoded['meta'] as Map)
            : null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _errorMes = e.toString());
    } finally {
      if (mounted) setState(() => _loadingMes = false);
    }
  }

  IconData _iconFor(String key) {
    switch (key) {
      case 'poll':             return Icons.poll_outlined;
      case 'user-plus':        return Icons.person_add_alt_1_outlined;
      case 'hand-holding-usd': return Icons.volunteer_activism_outlined;
      case 'piggy-bank':       return Icons.savings_outlined;
      case 'wallet':           return Icons.account_balance_wallet_outlined;
      case 'chart-line':       return Icons.show_chart_outlined;
      case 'walking':          return Icons.directions_walk_outlined;
      case 'university':       return Icons.account_balance_outlined;
      case 'coins':            return Icons.monetization_on_outlined;
      case 'chart-pie':        return Icons.pie_chart_outline;
      case 'file-invoice-dollar': return Icons.receipt_long_outlined;
      case 'dollar-sign':      return Icons.attach_money;
      default:                 return Icons.flag_outlined;
    }
  }

  Color _estadoColor(String estado) {
    switch (estado) {
      case 'completado':  return const Color(0xFF10B981);
      case 'no_cumplido': return const Color(0xFFEF4444);
      default:            return const Color(0xFFF59E0B);
    }
  }

  String _estadoLabel(String estado) {
    switch (estado) {
      case 'completado':  return 'COMPLETADO';
      case 'no_cumplido': return 'NO CUMPLIDO';
      default:            return 'PENDIENTE';
    }
  }

  IconData _estadoIcon(String estado) {
    switch (estado) {
      case 'completado':  return Icons.check_circle;
      case 'no_cumplido': return Icons.cancel;
      default:            return Icons.access_time_filled;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF4F6F9),
      appBar: AppBar(
        title: const Text('Mis Metas'),
        backgroundColor: const Color(0xFF0A2748),
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: (_loading || _loadingMes)
                ? null
                : () {
                    _cargar();
                    _cargarMes();
                  },
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: const Color(0xFFFFDD00),
          indicatorWeight: 3,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white60,
          labelStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13),
          tabs: const [
            Tab(text: 'METAS DEL DÍA'),
            Tab(text: 'METAS DEL MES'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          // ── Pestaña: Metas del día ─────────────────────────
          RefreshIndicator(
            onRefresh: _cargar,
            child: _loading && _meta == null
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                    ? _buildError(_error!, _cargar)
                    : !_tieneMeta
                        ? _buildNoMeta(
                            _mensajeUI,
                            'El supervisor aún no te asignó metas para hoy.',
                          )
                        : _buildMeta(),
          ),
          // ── Pestaña: Metas del mes ──────────────────────────
          RefreshIndicator(
            onRefresh: _cargarMes,
            child: _loadingMes && _metaMes == null
                ? const Center(child: CircularProgressIndicator())
                : _errorMes != null
                    ? _buildError(_errorMes!, _cargarMes)
                    : !_tieneMetaMes
                        ? _buildNoMeta(
                            _mensajeUIMes,
                            'El supervisor aún no te asignó metas para este mes.',
                          )
                        : _buildMetaMes(),
          ),
        ],
      ),
    );
  }

  Widget _buildError(String mensaje, Future<void> Function() onRetry) => ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 80),
          const Icon(Icons.error_outline, size: 48, color: Colors.redAccent),
          const SizedBox(height: 12),
          Text(mensaje, textAlign: TextAlign.center),
          const SizedBox(height: 16),
          Center(
            child: ElevatedButton(onPressed: onRetry, child: const Text('Reintentar')),
          ),
        ],
      );

  Widget _buildNoMeta(String mensajeUI, String fallback) => ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 80),
          const Icon(Icons.inbox_outlined, size: 56, color: Colors.grey),
          const SizedBox(height: 12),
          Text(
            mensajeUI.isNotEmpty ? mensajeUI : fallback,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 15, color: Color(0xFF6B7280)),
          ),
        ],
      );

  Widget _buildMeta() {
    final meta = _meta!;
    final estado = meta['estado']?.toString() ?? 'pendiente';
    final pctTotal = (meta['pct_total'] as num?)?.toInt() ?? 0;
    final items = (meta['items'] as List?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList() ??
        [];
    final obs = meta['observaciones']?.toString() ?? '';
    final color = _estadoColor(estado);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _buildResumenCard(
          estado: estado,
          pctTotal: pctTotal,
          color: color,
          descripcion: estado == 'pendiente'
              ? 'Tienes hasta las 6:00 pm para cumplir las metas.'
              : estado == 'completado'
                  ? '¡Felicitaciones! Cumpliste todas las metas del día.'
                  : 'El día cerró sin completar todas las metas.',
          obs: obs,
        ),
        const SizedBox(height: 18),
        ...items.map(_buildItemMeta).toList(),
        const SizedBox(height: 24),
      ],
    );
  }

  Widget _buildMetaMes() {
    final meta = _metaMes!;
    final estado = meta['estado']?.toString() ?? 'pendiente';
    final pctTotal = (meta['pct_total'] as num?)?.toInt() ?? 0;
    final items = (meta['items'] as List?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList() ??
        [];
    final obs = meta['observaciones']?.toString() ?? '';
    final color = _estadoColor(estado);
    final diasAsignados = (meta['dias_asignados'] as num?)?.toInt() ?? 0;

    String mesLabel = '';
    final mesStr = meta['mes']?.toString() ?? '';
    if (mesStr.contains('-')) {
      final partes = mesStr.split('-');
      final mNum = int.tryParse(partes[1]) ?? 0;
      if (mNum >= 1 && mNum <= 12) {
        mesLabel = '${_meses[mNum]} ${partes[0]}';
      }
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _buildResumenCard(
          estado: estado,
          pctTotal: pctTotal,
          color: color,
          titulo: mesLabel.isNotEmpty ? 'Meta de $mesLabel' : null,
          descripcion: estado == 'completado'
              ? '¡Felicitaciones! Cumpliste todas las metas del mes.'
              : estado == 'no_cumplido'
                  ? 'El mes cerró sin completar todas las metas.'
                  : 'Progreso acumulado en los $diasAsignados día(s) con meta asignada este mes.',
          obs: obs,
        ),
        const SizedBox(height: 18),
        ...items.map(_buildItemMeta).toList(),
        const SizedBox(height: 24),
      ],
    );
  }

  Widget _buildResumenCard({
    required String estado,
    required int pctTotal,
    required Color color,
    required String descripcion,
    String? titulo,
    String obs = '',
  }) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF0A2748), Color(0xFF123A6D)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(18),
        boxShadow: const [
          BoxShadow(color: Color(0x22000000), blurRadius: 14, offset: Offset(0, 6)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (titulo != null) ...[
            Text(
              titulo,
              style: const TextStyle(
                color: Colors.white70,
                fontWeight: FontWeight.w700,
                fontSize: 12,
                letterSpacing: .8,
              ),
            ),
            const SizedBox(height: 6),
          ],
          Row(
            children: [
              Icon(_estadoIcon(estado), color: color, size: 30),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  _estadoLabel(estado),
                  style: TextStyle(
                    color: color,
                    fontWeight: FontWeight.w900,
                    fontSize: 18,
                    letterSpacing: .6,
                  ),
                ),
              ),
              Text('$pctTotal%',
                  style: const TextStyle(
                      color: Color(0xFFFFDD00),
                      fontWeight: FontWeight.w900,
                      fontSize: 28)),
            ],
          ),
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(
              value: pctTotal / 100,
              minHeight: 10,
              backgroundColor: Colors.white24,
              color: const Color(0xFFFFDD00),
            ),
          ),
          const SizedBox(height: 10),
          Text(
            descripcion,
            style: const TextStyle(color: Colors.white70, fontSize: 13),
          ),
          if (obs.isNotEmpty) ...[
            const Divider(color: Colors.white24, height: 20),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.sticky_note_2_outlined,
                    color: Colors.white70, size: 18),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(obs,
                      style: const TextStyle(color: Colors.white, fontSize: 13)),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildItemMeta(Map<String, dynamic> item) {
    final int meta = (item['meta'] as num?)?.toInt() ?? 0;
    final int avance = (item['avance'] as num?)?.toInt() ?? 0;
    final bool cumplido = item['cumplido'] == true;
    final bool esMonto = item['es_monto'] == true;
    final int pct = (item['pct'] as num?)?.toInt() ?? 0;

    if (meta == 0) return const SizedBox.shrink(); // no mostrar metas no asignadas

    final Color color = cumplido ? const Color(0xFF10B981) : const Color(0xFFF59E0B);

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE5E7EB)),
        boxShadow: const [
          BoxShadow(color: Color(0x11000000), blurRadius: 8, offset: Offset(0, 3)),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 44, height: 44,
            decoration: BoxDecoration(
              color: color.withOpacity(.12),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(_iconFor(item['icon']?.toString() ?? ''), color: color),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item['label']?.toString() ?? '',
                    style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 14.5,
                        color: Color(0xFF0A2748))),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Text(
                        esMonto
                            ? '\$$avance / \$$meta'
                            : '$avance / $meta',
                        style: TextStyle(
                            color: color,
                            fontWeight: FontWeight.w700,
                            fontSize: 13)),
                    const SizedBox(width: 10),
                    if (cumplido)
                      const Icon(Icons.check_circle,
                          color: Color(0xFF10B981), size: 18),
                  ],
                ),
                const SizedBox(height: 6),
                ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: pct / 100,
                    minHeight: 6,
                    backgroundColor: const Color(0xFFF1F5F9),
                    color: color,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text('$pct%',
              style: TextStyle(
                  fontWeight: FontWeight.w900,
                  color: color,
                  fontSize: 16)),
        ],
      ),
    );
  }
}
