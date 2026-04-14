import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Networking/ApiProvider.dart';
import 'package:super_ia/Core/Preferences/DriverPrefs.dart';
import 'package:super_ia/UI/views/DriverEarningsScreen.dart';

class DriverProfileScreen extends StatefulWidget {
  static const String route = '/driver_profile';

  const DriverProfileScreen({Key? key}) : super(key: key);

  @override
  State<DriverProfileScreen> createState() => _DriverProfileScreenState();
}

class _DriverProfileScreenState extends State<DriverProfileScreen> {
  final ApiProvider _api = ApiProvider();

  bool _loading = true;
  String? _error;

  Map<String, dynamic>? _conductor;
  Map<String, dynamic>? _vehiculo;
  List<dynamic> _documentos = [];
  Map<String, dynamic>? _stats;

  // Nombres legibles para cada tipo de documento
  static const Map<String, String> _docLabels = {
    'licencia_frente':  'Licencia de conducir (frente)',
    'licencia_reverso': 'Licencia de conducir (reverso)',
    'cedula':           'Cédula de identidad',
    'soat':             'SOAT',
    'matricula':        'Matrícula vehicular',
  };

  static const Map<String, IconData> _docIcons = {
    'licencia_frente':  Icons.credit_card_rounded,
    'licencia_reverso': Icons.credit_card_rounded,
    'cedula':           Icons.badge_rounded,
    'soat':             Icons.health_and_safety_rounded,
    'matricula':        Icons.directions_car_rounded,
  };

  @override
  void initState() {
    super.initState();
    _loadProfile();
  }

  Future<void> _loadProfile() async {
    if (!mounted) return;
    setState(() { _loading = true; _error = null; });

    final conductorId = await DriverPrefs.getDriverId();
    if (conductorId <= 0) {
      setState(() { _loading = false; _error = 'Sesión no válida.'; });
      return;
    }

    final resp = await _api.obtenerPerfilConductor(conductorId);
    if (!mounted) return;

    if (resp['status'] != 'success') {
      setState(() {
        _loading = false;
        _error = resp['message']?.toString() ?? 'Error al cargar perfil';
      });
      return;
    }

    setState(() {
      _loading  = false;
      _conductor = resp['conductor'] as Map<String, dynamic>?;
      _vehiculo  = resp['vehiculo']  as Map<String, dynamic>?;
      _documentos= (resp['documentos'] as List?) ?? [];
      _stats     = resp['stats']     as Map<String, dynamic>?;
    });
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  Color _estadoColor(String estado) {
    switch (estado) {
      case 'aprobado':   return const Color(0xFF22c55e);
      case 'rechazado':  return const Color(0xFFef4444);
      default:           return const Color(0xFFf59e0b);
    }
  }

  String _estadoLabel(String estado) {
    switch (estado) {
      case 'aprobado':  return 'Aprobado';
      case 'rechazado': return 'Rechazado';
      default:          return 'Pendiente';
    }
  }

  IconData _estadoIcon(String estado) {
    switch (estado) {
      case 'aprobado':  return Icons.check_circle_rounded;
      case 'rechazado': return Icons.cancel_rounded;
      default:          return Icons.hourglass_top_rounded;
    }
  }

  String _cuentaEstadoLabel() {
    final v = _conductor?['verificado'] ?? 0;
    final e = _conductor?['estado']?.toString() ?? '';
    if (v == 1 || v == '1') return 'Activo';
    if (e == 'rechazado')   return 'Rechazado';
    return 'Pendiente de aprobación';
  }

  Color _cuentaEstadoColor() {
    final v = _conductor?['verificado'] ?? 0;
    final e = _conductor?['estado']?.toString() ?? '';
    if (v == 1 || v == '1') return const Color(0xFF22c55e);
    if (e == 'rechazado')   return const Color(0xFFef4444);
    return const Color(0xFFf59e0b);
  }

  // ── Build ─────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _buildError()
              : _buildContent(),
    );
  }

  Widget _buildError() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.error_outline_rounded, color: Colors.red.shade400, size: 56),
          const SizedBox(height: 16),
          Text(_error!, style: TextStyle(color: ConstantColors.textGrey), textAlign: TextAlign.center),
          const SizedBox(height: 20),
          ElevatedButton.icon(
            onPressed: _loadProfile,
            icon: const Icon(Icons.refresh_rounded),
            label: const Text('Reintentar'),
            style: ElevatedButton.styleFrom(backgroundColor: ConstantColors.primaryBlue),
          ),
        ]),
      ),
    );
  }

  Widget _buildContent() {
    return RefreshIndicator(
      onRefresh: _loadProfile,
      color: ConstantColors.primaryBlue,
      child: CustomScrollView(
        slivers: [
          _buildAppBar(),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _buildEstadoCuenta(),
                  const SizedBox(height: 20),
                  _buildStatsRow(),
                  const SizedBox(height: 16),
                  _buildGananciasButton(),
                  const SizedBox(height: 24),
                  _buildSectionTitle('Datos personales', Icons.person_outline_rounded),
                  const SizedBox(height: 12),
                  _buildDatosPersonales(),
                  const SizedBox(height: 24),
                  if (_vehiculo != null) ...[
                    _buildSectionTitle('Vehículo', Icons.directions_car_rounded),
                    const SizedBox(height: 12),
                    _buildVehiculo(),
                    const SizedBox(height: 24),
                  ],
                  _buildSectionTitle('Documentos', Icons.folder_open_rounded),
                  const SizedBox(height: 12),
                  _buildDocumentos(),
                  const SizedBox(height: 32),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ── SliverAppBar con foto de perfil ───────────────────────────────────────

  Widget _buildAppBar() {
    final nombre = _conductor?['nombre']?.toString() ?? '';
    final categoria = _conductor?['categoria']?.toString() ?? '';
    final fotoBase64 = _conductor?['foto_perfil']?.toString() ?? '';

    return SliverAppBar(
      expandedHeight: 220,
      pinned: true,
      backgroundColor: ConstantColors.backgroundCard,
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white),
        onPressed: () => Navigator.pop(context),
      ),
      flexibleSpace: FlexibleSpaceBar(
        background: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                ConstantColors.primaryBlue.withOpacity(0.9),
                ConstantColors.primaryViolet,
              ],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const SizedBox(height: 40),
              // Foto de perfil
              Container(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white, width: 3),
                  boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 12)],
                ),
                child: CircleAvatar(
                  radius: 48,
                  backgroundColor: ConstantColors.backgroundCard,
                  backgroundImage: fotoBase64.isNotEmpty
                      ? MemoryImage(base64Decode(fotoBase64)) as ImageProvider
                      : null,
                  child: fotoBase64.isEmpty
                      ? Icon(Icons.person_rounded, size: 48, color: ConstantColors.primaryBlue)
                      : null,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                nombre,
                style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800),
              ),
              if (categoria.isNotEmpty)
                Text(
                  categoria,
                  style: TextStyle(color: Colors.white.withOpacity(0.8), fontSize: 13),
                ),
            ],
          ),
        ),
      ),
    );
  }

  // ── Badge de estado de cuenta ──────────────────────────────────────────────

  Widget _buildEstadoCuenta() {
    final color = _cuentaEstadoColor();
    final label = _cuentaEstadoLabel();
    return Center(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
        decoration: BoxDecoration(
          color: color.withOpacity(0.15),
          borderRadius: BorderRadius.circular(30),
          border: Border.all(color: color.withOpacity(0.4)),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(
            label == 'Activo' ? Icons.verified_rounded : Icons.pending_rounded,
            color: color,
            size: 18,
          ),
          const SizedBox(width: 8),
          Text(
            label,
            style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 14),
          ),
        ]),
      ),
    );
  }

  // ── Fila de estadísticas ───────────────────────────────────────────────────

  Widget _buildStatsRow() {
    final totalViajes  = _stats?['total_viajes'] ?? 0;
    final promedio     = (_stats?['promedio_calificacion'] ?? 0.0).toStringAsFixed(1);
    final calificacion = double.tryParse(promedio) ?? 0.0;

    return Row(children: [
      Expanded(child: _statCard(
        icon: Icons.route_rounded,
        label: 'Viajes realizados',
        value: totalViajes.toString(),
        color: ConstantColors.primaryBlue,
      )),
      const SizedBox(width: 12),
      Expanded(child: _statCard(
        icon: Icons.star_rounded,
        label: 'Calificación',
        value: calificacion > 0 ? '★ $promedio' : 'Sin calif.',
        color: const Color(0xFFf59e0b),
      )),
    ]);
  }

  Widget _buildGananciasButton() {
    return GestureDetector(
      onTap: () => Navigator.pushNamed(context, DriverEarningsScreen.route),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 20),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [
              const Color(0xFF22c55e).withOpacity(0.15),
              const Color(0xFF16a34a).withOpacity(0.08),
            ],
            begin: Alignment.centerLeft,
            end: Alignment.centerRight,
          ),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: const Color(0xFF22c55e).withOpacity(0.4)),
        ),
        child: Row(children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: const Color(0xFF22c55e).withOpacity(0.18),
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Icon(Icons.trending_up_rounded,
                color: Color(0xFF22c55e), size: 24),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Mis ganancias',
                  style: TextStyle(
                      color: ConstantColors.textWhite,
                      fontSize: 15,
                      fontWeight: FontWeight.w700)),
              const SizedBox(height: 2),
              Text('Ver comisiones, tarifa neta y historial',
                  style: TextStyle(
                      color: ConstantColors.textGrey, fontSize: 11)),
            ]),
          ),
          Icon(Icons.chevron_right_rounded,
              color: const Color(0xFF22c55e), size: 22),
        ]),
      ),
    );
  }

  Widget _statCard({required IconData icon, required String label, required String value, required Color color}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Row(children: [
        Container(
          width: 40, height: 40,
          decoration: BoxDecoration(
            color: color.withOpacity(0.15),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(icon, color: color, size: 22),
        ),
        const SizedBox(width: 12),
        Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: TextStyle(color: ConstantColors.textGrey, fontSize: 11)),
          const SizedBox(height: 2),
          Text(value, style: TextStyle(color: ConstantColors.textWhite, fontSize: 18, fontWeight: FontWeight.w800)),
        ]),
      ]),
    );
  }

  // ── Título de sección ──────────────────────────────────────────────────────

  Widget _buildSectionTitle(String title, IconData icon) {
    return Row(children: [
      Icon(icon, color: ConstantColors.primaryBlue, size: 20),
      const SizedBox(width: 8),
      Text(title, style: TextStyle(color: ConstantColors.textWhite, fontSize: 16, fontWeight: FontWeight.w700)),
    ]);
  }

  // ── Datos personales ───────────────────────────────────────────────────────

  Widget _buildDatosPersonales() {
    final c = _conductor!;
    return Container(
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Column(children: [
        _infoRow(Icons.person_rounded,       'Nombre',    c['nombre']?.toString()   ?? '—'),
        _divider(),
        _infoRow(Icons.email_rounded,        'Email',     c['email']?.toString()    ?? '—'),
        _divider(),
        _infoRow(Icons.phone_rounded,        'Teléfono',  c['telefono']?.toString() ?? '—'),
        _divider(),
        _infoRow(Icons.badge_rounded,        'Cédula',    c['cedula']?.toString()   ?? '—'),
        _divider(),
        _infoRow(Icons.location_city_rounded,'Ciudad',    c['ciudad']?.toString()   ?? '—'),
        _divider(),
        _infoRow(Icons.calendar_today_rounded,'Registro',
          _formatDate(c['creado_en']?.toString() ?? '')),
      ]),
    );
  }

  Widget _buildVehiculo() {
    final v = _vehiculo!;
    final placa = v['placa']?.toString() ?? '—';
    final marca = v['marca']?.toString() ?? '';
    final modelo = v['modelo']?.toString() ?? '';
    final color  = v['color']?.toString() ?? '';
    final anio   = v['anio']?.toString() ?? '';

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Row(children: [
        Container(
          width: 52, height: 52,
          decoration: BoxDecoration(
            color: ConstantColors.primaryBlue.withOpacity(0.15),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Icon(Icons.directions_car_rounded, color: ConstantColors.primaryBlue, size: 28),
        ),
        const SizedBox(width: 16),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(
            '$marca $modelo',
            style: TextStyle(color: ConstantColors.textWhite, fontSize: 16, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 4),
          Row(children: [
            _chip(placa, ConstantColors.primaryBlue),
            const SizedBox(width: 8),
            if (color.isNotEmpty) _chip(color, Colors.grey.shade600),
            const SizedBox(width: 8),
            if (anio.isNotEmpty) _chip(anio, Colors.grey.shade700),
          ]),
        ])),
      ]),
    );
  }

  Widget _chip(String label, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withOpacity(0.2),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withOpacity(0.4)),
      ),
      child: Text(label, style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }

  // ── Documentos ────────────────────────────────────────────────────────────

  Widget _buildDocumentos() {
    final tiposEsperados = ['licencia_frente', 'licencia_reverso', 'cedula', 'soat', 'matricula'];

    // Indexar los documentos recibidos por tipo
    final Map<String, Map<String, dynamic>> docMap = {};
    for (final d in _documentos) {
      final tipo = d['tipo']?.toString() ?? '';
      docMap[tipo] = d as Map<String, dynamic>;
    }

    return Container(
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Column(
        children: List.generate(tiposEsperados.length, (i) {
          final tipo   = tiposEsperados[i];
          final doc    = docMap[tipo];
          final estado = doc?['estado']?.toString() ?? 'pendiente';
          final notas  = doc?['notas']?.toString() ?? '';
          final label  = _docLabels[tipo] ?? tipo;
          final icon   = _docIcons[tipo]  ?? Icons.insert_drive_file_rounded;
          final color  = _estadoColor(estado);

          return Column(children: [
            ListTile(
              leading: Container(
                width: 40, height: 40,
                decoration: BoxDecoration(
                  color: color.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(icon, color: color, size: 20),
              ),
              title: Text(label, style: TextStyle(color: ConstantColors.textWhite, fontSize: 13, fontWeight: FontWeight.w600)),
              subtitle: notas.isNotEmpty
                  ? Text(notas, style: TextStyle(color: ConstantColors.textGrey, fontSize: 11))
                  : null,
              trailing: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: color.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: color.withOpacity(0.4)),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(_estadoIcon(estado), color: color, size: 13),
                  const SizedBox(width: 4),
                  Text(
                    _estadoLabel(estado),
                    style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700),
                  ),
                ]),
              ),
            ),
            if (i < tiposEsperados.length - 1) _divider(),
          ]);
        }),
      ),
    );
  }

  // ── Widgets auxiliares ────────────────────────────────────────────────────

  Widget _infoRow(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
      child: Row(children: [
        Icon(icon, color: ConstantColors.primaryBlue, size: 20),
        const SizedBox(width: 14),
        Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: TextStyle(color: ConstantColors.textGrey, fontSize: 11)),
          const SizedBox(height: 2),
          Text(value, style: TextStyle(color: ConstantColors.textWhite, fontSize: 14, fontWeight: FontWeight.w600)),
        ]),
      ]),
    );
  }

  Widget _divider() => Divider(height: 1, color: ConstantColors.borderColor, indent: 16, endIndent: 16);

  String _formatDate(String raw) {
    try {
      final dt = DateTime.parse(raw);
      return '${dt.day.toString().padLeft(2,'0')}/${dt.month.toString().padLeft(2,'0')}/${dt.year}';
    } catch (_) {
      return raw;
    }
  }
}
