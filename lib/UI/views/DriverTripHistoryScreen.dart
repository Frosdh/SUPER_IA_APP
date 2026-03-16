import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Networking/ApiProvider.dart';

class DriverTripHistoryScreen extends StatefulWidget {
  static const String route = '/driver_trip_history';

  final int driverId;
  const DriverTripHistoryScreen({Key? key, required this.driverId}) : super(key: key);

  @override
  _DriverTripHistoryScreenState createState() => _DriverTripHistoryScreenState();
}

class _DriverTripHistoryScreenState extends State<DriverTripHistoryScreen> {
  final ApiProvider _api = ApiProvider();
  final ScrollController _scrollController = ScrollController();

  List<Map<String, dynamic>> _viajes = [];
  double _gananciaTotal = 0;
  int _total = 0;
  int _page = 1;
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _fetchHistory();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >= _scrollController.position.maxScrollExtent - 200) {
      if (_hasMore && !_loadingMore) _fetchMore();
    }
  }

  Future<void> _fetchHistory({bool reset = false}) async {
    if (reset) {
      setState(() {
        _page = 1;
        _viajes = [];
        _hasMore = true;
        _loading = true;
        _error = null;
      });
    }
    final response = await _api.getDriverTripHistory(conductorId: widget.driverId, page: _page);
    if (!mounted) return;
    if (response['status'] == 'success') {
      final list = (response['viajes'] as List?)
          ?.map((e) => (e as Map).cast<String, dynamic>())
          .toList() ?? [];
      setState(() {
        _viajes = list;
        _total = response['total'] as int? ?? 0;
        _gananciaTotal = (response['ganancia_total'] as num?)?.toDouble() ?? 0;
        _hasMore = list.length >= 20;
        _loading = false;
        _error = null;
      });
    } else {
      final debug = response['debug']?.toString() ?? response['message']?.toString() ?? 'Sin detalle';
      setState(() {
        _loading = false;
        _error = 'Error del servidor:\n$debug';
      });
    }
  }

  Future<void> _fetchMore() async {
    if (_loadingMore || !_hasMore) return;
    setState(() { _loadingMore = true; _page++; });
    final response = await _api.getDriverTripHistory(conductorId: widget.driverId, page: _page);
    if (!mounted) return;
    if (response['status'] == 'success') {
      final list = (response['viajes'] as List?)
          ?.map((e) => (e as Map).cast<String, dynamic>())
          .toList() ?? [];
      setState(() {
        _viajes.addAll(list);
        _hasMore = list.length >= 20;
        _loadingMore = false;
      });
    } else {
      setState(() { _loadingMore = false; });
    }
  }

  String _formatFecha(String raw) {
    try {
      final dt = DateTime.parse(raw);
      final months = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
      return '${dt.day} ${months[dt.month - 1]} ${dt.year}  ${dt.hour.toString().padLeft(2,'0')}:${dt.minute.toString().padLeft(2,'0')}';
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
        title: const Text('Historial de viajes', style: TextStyle(fontWeight: FontWeight.w700)),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(72),
          child: Container(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
            color: ConstantColors.backgroundCard,
            child: Row(children: [
              _statChip(Icons.check_circle_rounded, '$_total viajes', ConstantColors.primaryBlue),
              const SizedBox(width: 12),
              _statChip(Icons.attach_money_rounded, '\$${_gananciaTotal.toStringAsFixed(2)}', ConstantColors.success),
            ]),
          ),
        ),
      ),
      body: _loading
          ? Center(child: CircularProgressIndicator(valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryBlue)))
          : _error != null
              ? _buildError()
              : _viajes.isEmpty
                  ? _buildEmpty()
                  : RefreshIndicator(
                      onRefresh: () => _fetchHistory(reset: true),
                      color: ConstantColors.primaryBlue,
                      child: ListView.builder(
                        controller: _scrollController,
                        padding: const EdgeInsets.all(16),
                        itemCount: _viajes.length + (_loadingMore ? 1 : 0),
                        itemBuilder: (context, index) {
                          if (index == _viajes.length) {
                            return Center(
                              child: Padding(
                                padding: const EdgeInsets.all(16),
                                child: CircularProgressIndicator(
                                  valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryBlue),
                                ),
                              ),
                            );
                          }
                          return _buildTripCard(_viajes[index]);
                        },
                      ),
                    ),
    );
  }

  Widget _statChip(IconData icon, String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(
        color: color.withOpacity(0.12),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: color.withOpacity(0.3)),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, color: color, size: 16),
        const SizedBox(width: 6),
        Text(text, style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 13)),
      ]),
    );
  }

  Widget _buildTripCard(Map<String, dynamic> viaje) {
    final pasajero = viaje['pasajero_nombre']?.toString() ?? 'Pasajero';
    final origen   = viaje['origen_texto']?.toString()   ?? '';
    final destino  = viaje['destino_texto']?.toString()  ?? '';
    final tarifa   = (viaje['tarifa_total']  as num?)?.toDouble() ?? 0;
    final distancia = (viaje['distancia_km'] as num?)?.toDouble() ?? 0;
    final duracion  = (viaje['duracion_min'] as num?)?.toDouble() ?? 0;
    final fecha    = _formatFecha(viaje['fecha_fin']?.toString() ?? viaje['fecha_pedido']?.toString() ?? '');

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        // Header: pasajero + tarifa
        Row(children: [
          CircleAvatar(
            radius: 18,
            backgroundColor: ConstantColors.primaryBlue.withOpacity(0.15),
            child: Icon(Icons.person, color: ConstantColors.primaryBlue, size: 20),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(pasajero, style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700, fontSize: 15)),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: ConstantColors.success.withOpacity(0.13),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Text('\$${tarifa.toStringAsFixed(2)}', style: TextStyle(color: ConstantColors.success, fontWeight: FontWeight.w800, fontSize: 14)),
          ),
        ]),
        const SizedBox(height: 12),

        // Ruta
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Column(children: [
            Container(width: 10, height: 10, decoration: BoxDecoration(color: Colors.green.shade500, shape: BoxShape.circle)),
            Container(width: 2, height: 24, color: ConstantColors.borderColor),
            Container(width: 10, height: 10, decoration: BoxDecoration(color: ConstantColors.primaryViolet, shape: BoxShape.circle)),
          ]),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(origen, style: TextStyle(color: ConstantColors.textWhite, fontSize: 13), maxLines: 1, overflow: TextOverflow.ellipsis),
            const SizedBox(height: 14),
            Text(destino, style: TextStyle(color: ConstantColors.textWhite, fontSize: 13), maxLines: 1, overflow: TextOverflow.ellipsis),
          ])),
        ]),
        const SizedBox(height: 12),

        // Métricas
        Row(children: [
          _miniStat(Icons.straighten_rounded, '${distancia.toStringAsFixed(1)} km'),
          const SizedBox(width: 16),
          _miniStat(Icons.access_time_rounded, '${duracion.toStringAsFixed(0)} min'),
          const Spacer(),
          Text(fecha, style: TextStyle(color: ConstantColors.textGrey, fontSize: 11)),
        ]),
      ]),
    );
  }

  Widget _miniStat(IconData icon, String value) {
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, color: ConstantColors.textGrey, size: 14),
      const SizedBox(width: 4),
      Text(value, style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
    ]);
  }

  Widget _buildEmpty() {
    return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
      Icon(Icons.history_rounded, color: ConstantColors.textGrey, size: 64),
      const SizedBox(height: 16),
      Text('Aún no tienes viajes completados', style: TextStyle(color: ConstantColors.textGrey, fontSize: 16)),
    ]));
  }

  Widget _buildError() {
    return Center(child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
        Icon(Icons.cloud_off_rounded, color: ConstantColors.warning, size: 56),
        const SizedBox(height: 16),
        Text(_error ?? 'Error', style: TextStyle(color: ConstantColors.textWhite, fontSize: 15), textAlign: TextAlign.center),
        const SizedBox(height: 20),
        ElevatedButton.icon(
          style: ElevatedButton.styleFrom(backgroundColor: ConstantColors.primaryBlue, foregroundColor: Colors.white),
          onPressed: () => _fetchHistory(reset: true),
          icon: const Icon(Icons.refresh_rounded),
          label: const Text('Reintentar'),
        ),
      ]),
    ));
  }
}
