import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Networking/ApiProvider.dart';
import 'package:fu_uber/Core/Preferences/DriverPrefs.dart';

/// Pantalla de ganancias y comisiones del conductor.
/// Muestra el desglose de: tarifa total → comisión plataforma → ganancia neta.
class DriverEarningsScreen extends StatefulWidget {
  static const String route = '/driver_earnings';

  const DriverEarningsScreen({super.key});

  @override
  State<DriverEarningsScreen> createState() => _DriverEarningsScreenState();
}

class _DriverEarningsScreenState extends State<DriverEarningsScreen>
    with SingleTickerProviderStateMixin {
  final ApiProvider _api = ApiProvider();
  late TabController _tabController;

  List<Map<String, dynamic>> _viajes = [];
  bool _loading = true;
  String? _error;

  // Comisión de la plataforma (ajustable desde Constants / servidor)
  static const double _comisionPct = 0.15; // 15%

  // Período seleccionado: 0=Hoy, 1=Semana, 2=Mes, 3=Total
  int _periodo = 3;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this, initialIndex: 3);
    _tabController.addListener(() {
      if (!_tabController.indexIsChanging) {
        setState(() => _periodo = _tabController.index);
      }
    });
    _cargarHistorial();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _cargarHistorial() async {
    setState(() { _loading = true; _error = null; });
    final driverId = await DriverPrefs.getDriverId();
    final response = await _api.getDriverTripHistory(
        conductorId: driverId ?? 0, page: 1);

    if (!mounted) return;
    if (response['status'] == 'success') {
      final list = (response['viajes'] as List?)
              ?.map((e) => (e as Map).cast<String, dynamic>())
              .toList() ??
          [];
      setState(() { _viajes = list; _loading = false; });
    } else {
      setState(() {
        _loading = false;
        _error = 'No se pudieron cargar los datos';
      });
    }
  }

  // ─── Filtrar viajes por período ──────────────────────────────────────────
  List<Map<String, dynamic>> get _viajesFiltrados {
    if (_viajes.isEmpty) return [];
    final now = DateTime.now();
    return _viajes.where((v) {
      final fechaStr =
          v['fecha_fin']?.toString() ?? v['fecha_pedido']?.toString() ?? '';
      if (fechaStr.isEmpty) return _periodo == 3;
      try {
        final fecha = DateTime.parse(fechaStr);
        switch (_periodo) {
          case 0: // Hoy
            return fecha.year == now.year &&
                fecha.month == now.month &&
                fecha.day == now.day;
          case 1: // Semana
            return now.difference(fecha).inDays <= 7;
          case 2: // Mes
            return fecha.year == now.year && fecha.month == now.month;
          case 3: // Total
            return true;
        }
      } catch (_) {}
      return _periodo == 3;
    }).toList();
  }

  double get _tarifaTotal =>
      _viajesFiltrados.fold(0.0,
          (s, v) => s + ((v['tarifa_total'] as num?)?.toDouble() ?? 0));

  double get _comisionTotal => _tarifaTotal * _comisionPct;
  double get _gananciaNetaTotal => _tarifaTotal - _comisionTotal;

  String _formatFecha(String raw) {
    try {
      final dt = DateTime.parse(raw);
      final m = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
      return '${dt.day} ${m[dt.month-1]}  ${dt.hour.toString().padLeft(2,'0')}:${dt.minute.toString().padLeft(2,'0')}';
    } catch (_) {
      return raw;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: ConstantColors.backgroundCard,
        elevation: 0,
        leading: IconButton(
          icon: Icon(Icons.arrow_back_ios_new_rounded,
              color: ConstantColors.textWhite, size: 20),
          onPressed: () => Navigator.pop(context),
        ),
        title: Text('Mis ganancias',
            style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 17,
                fontWeight: FontWeight.w700)),
        actions: [
          IconButton(
            icon: Icon(Icons.refresh_rounded, color: ConstantColors.textGrey),
            onPressed: _cargarHistorial,
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: ConstantColors.primaryViolet,
          labelColor: ConstantColors.primaryViolet,
          unselectedLabelColor: ConstantColors.textGrey,
          labelStyle: TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
          tabs: const [
            Tab(text: 'Hoy'),
            Tab(text: 'Semana'),
            Tab(text: 'Mes'),
            Tab(text: 'Total'),
          ],
        ),
      ),
      body: _loading
          ? Center(
              child: CircularProgressIndicator(
                  valueColor: AlwaysStoppedAnimation<Color>(
                      ConstantColors.primaryViolet)))
          : _error != null
              ? _buildError()
              : _buildBody(),
    );
  }

  Widget _buildBody() {
    final filtrados = _viajesFiltrados;

    return RefreshIndicator(
      onRefresh: _cargarHistorial,
      color: ConstantColors.primaryViolet,
      child: SingleChildScrollView(
        physics: AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            // ── Resumen financiero ───────────────────────────────────────
            _buildResumenCard(),
            SizedBox(height: 20),

            // ── Desglose de comisión ─────────────────────────────────────
            _buildComisionCard(),
            SizedBox(height: 20),

            // ── Lista de viajes del período ──────────────────────────────
            if (filtrados.isEmpty)
              _buildEmpty()
            else ...[
              Row(children: [
                Icon(Icons.history_rounded,
                    color: ConstantColors.textGrey, size: 16),
                SizedBox(width: 8),
                Text('${filtrados.length} viajes en este período',
                    style: TextStyle(
                        color: ConstantColors.textGrey, fontSize: 13)),
              ]),
              SizedBox(height: 12),
              ...filtrados.map((v) => _buildViajeCard(v)).toList(),
            ],
            SizedBox(height: 40),
          ],
        ),
      ),
    );
  }

  // ─── Tarjeta de resumen ───────────────────────────────────────────────────
  Widget _buildResumenCard() {
    final filtrados = _viajesFiltrados;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            ConstantColors.primaryViolet.withOpacity(0.85),
            ConstantColors.primaryBlue.withOpacity(0.75),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: ConstantColors.primaryViolet.withOpacity(0.3),
            blurRadius: 20,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Ganancia neta',
              style: TextStyle(color: Colors.white60, fontSize: 13)),
          SizedBox(height: 4),
          Text(
            '\$${_gananciaNetaTotal.toStringAsFixed(2)}',
            style: TextStyle(
              color: Colors.white,
              fontSize: 44,
              fontWeight: FontWeight.w900,
              letterSpacing: -1.5,
            ),
          ),
          Text('USD · ${filtrados.length} viajes',
              style: TextStyle(color: Colors.white54, fontSize: 12)),
          SizedBox(height: 20),
          Row(
            children: [
              _buildResumenStat(
                  'Total bruto', '\$${_tarifaTotal.toStringAsFixed(2)}'),
              Container(
                  width: 1,
                  height: 30,
                  color: Colors.white24,
                  margin: EdgeInsets.symmetric(horizontal: 16)),
              _buildResumenStat(
                  'Comisión (${(_comisionPct * 100).toInt()}%)',
                  '-\$${_comisionTotal.toStringAsFixed(2)}'),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildResumenStat(String label, String value) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(label, style: TextStyle(color: Colors.white54, fontSize: 11)),
      Text(value,
          style: TextStyle(
              color: Colors.white,
              fontSize: 16,
              fontWeight: FontWeight.w700)),
    ]);
  }

  // ─── Desglose de comisión ─────────────────────────────────────────────────
  Widget _buildComisionCard() {
    return Container(
      padding: EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(Icons.pie_chart_rounded,
                color: ConstantColors.primaryViolet, size: 18),
            SizedBox(width: 8),
            Text('Desglose de comisión',
                style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 14,
                    fontWeight: FontWeight.w700)),
          ]),
          SizedBox(height: 16),

          // Barra visual del desglose
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: Row(children: [
              Expanded(
                flex: (100 - _comisionPct * 100).round(),
                child: Container(
                  height: 10,
                  color: ConstantColors.success,
                ),
              ),
              Expanded(
                flex: (_comisionPct * 100).round(),
                child: Container(
                  height: 10,
                  color: ConstantColors.error.withOpacity(0.7),
                ),
              ),
            ]),
          ),
          SizedBox(height: 12),

          _comisionRow('Tu ganancia (${(100 - _comisionPct * 100).round()}%)',
              _gananciaNetaTotal, ConstantColors.success),
          SizedBox(height: 8),
          _comisionRow(
              'Plataforma GeoMove (${(_comisionPct * 100).round()}%)',
              _comisionTotal,
              ConstantColors.error),
          Divider(color: ConstantColors.dividerColor, height: 20),
          _comisionRow('Total cobrado al pasajero', _tarifaTotal,
              ConstantColors.textWhite),
        ],
      ),
    );
  }

  Widget _comisionRow(String label, double monto, Color color) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Row(children: [
          Container(
              width: 10,
              height: 10,
              decoration:
                  BoxDecoration(color: color, shape: BoxShape.circle)),
          SizedBox(width: 8),
          Text(label,
              style: TextStyle(
                  color: ConstantColors.textGrey, fontSize: 13)),
        ]),
        Text('\$${monto.toStringAsFixed(2)}',
            style: TextStyle(
                color: color, fontSize: 14, fontWeight: FontWeight.w700)),
      ],
    );
  }

  // ─── Card de viaje individual ─────────────────────────────────────────────
  Widget _buildViajeCard(Map<String, dynamic> v) {
    final tarifa =
        (v['tarifa_total'] as num?)?.toDouble() ?? 0;
    final comision   = tarifa * _comisionPct;
    final neta       = tarifa - comision;
    final pasajero   = v['pasajero_nombre']?.toString() ?? 'Pasajero';
    final origen     = v['origen_texto']?.toString() ?? '';
    final destino    = v['destino_texto']?.toString() ?? '';
    final distancia  = (v['distancia_km'] as num?)?.toDouble() ?? 0;
    final duracion   = (v['duracion_min'] as num?)?.toDouble() ?? 0;
    final fechaStr   = v['fecha_fin']?.toString() ??
        v['fecha_pedido']?.toString() ?? '';
    final fecha      = fechaStr.isNotEmpty ? _formatFecha(fechaStr) : '';

    return Container(
      margin: EdgeInsets.only(bottom: 12),
      padding: EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        // Header: pasajero + ganancia neta
        Row(children: [
          CircleAvatar(
            radius: 16,
            backgroundColor: ConstantColors.primaryBlue.withOpacity(0.15),
            child: Icon(Icons.person, color: ConstantColors.primaryBlue, size: 16),
          ),
          SizedBox(width: 10),
          Expanded(
            child: Text(pasajero,
                style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontWeight: FontWeight.w700,
                    fontSize: 14),
                maxLines: 1,
                overflow: TextOverflow.ellipsis),
          ),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('\$${neta.toStringAsFixed(2)}',
                style: TextStyle(
                    color: ConstantColors.success,
                    fontWeight: FontWeight.w800,
                    fontSize: 15)),
            Text('de \$${tarifa.toStringAsFixed(2)}',
                style: TextStyle(
                    color: ConstantColors.textSubtle, fontSize: 10)),
          ]),
        ]),

        SizedBox(height: 12),

        // Ruta abreviada
        Text('$origen → $destino',
            style: TextStyle(
                color: ConstantColors.textGrey, fontSize: 12),
            maxLines: 1,
            overflow: TextOverflow.ellipsis),

        SizedBox(height: 10),

        // Stats y comisión
        Row(children: [
          _miniStat(Icons.straighten_rounded,
              '${distancia.toStringAsFixed(1)} km'),
          SizedBox(width: 14),
          _miniStat(Icons.access_time_rounded,
              '${duracion.toStringAsFixed(0)} min'),
          Spacer(),
          Container(
            padding: EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: ConstantColors.error.withOpacity(0.1),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(
              '-\$${comision.toStringAsFixed(2)} comisión',
              style: TextStyle(
                  color: ConstantColors.error.withOpacity(0.8),
                  fontSize: 10,
                  fontWeight: FontWeight.w600),
            ),
          ),
        ]),

        if (fecha.isNotEmpty) ...[
          SizedBox(height: 8),
          Text(fecha,
              style: TextStyle(
                  color: ConstantColors.textSubtle, fontSize: 11)),
        ],
      ]),
    );
  }

  Widget _miniStat(IconData icon, String value) {
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, color: ConstantColors.textGrey, size: 13),
      SizedBox(width: 4),
      Text(value,
          style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
    ]);
  }

  Widget _buildEmpty() {
    return Center(
      child: Padding(
        padding: EdgeInsets.all(32),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.bar_chart_rounded,
              color: ConstantColors.textSubtle, size: 56),
          SizedBox(height: 16),
          Text('Sin viajes en este período',
              style: TextStyle(
                  color: ConstantColors.textGrey,
                  fontSize: 15,
                  fontWeight: FontWeight.w600)),
          SizedBox(height: 8),
          Text('Aquí aparecerán tus ganancias\ncuando tengas viajes',
              style: TextStyle(
                  color: ConstantColors.textSubtle, fontSize: 13),
              textAlign: TextAlign.center),
        ]),
      ),
    );
  }

  Widget _buildError() {
    return Center(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Icon(Icons.cloud_off_rounded,
            color: ConstantColors.warning, size: 52),
        SizedBox(height: 16),
        Text(_error ?? 'Error',
            style: TextStyle(color: ConstantColors.textGrey, fontSize: 14)),
        SizedBox(height: 16),
        ElevatedButton.icon(
          style: ElevatedButton.styleFrom(
              backgroundColor: ConstantColors.primaryViolet,
              foregroundColor: Colors.white),
          onPressed: _cargarHistorial,
          icon: Icon(Icons.refresh_rounded),
          label: Text('Reintentar'),
        ),
      ]),
    );
  }
}
