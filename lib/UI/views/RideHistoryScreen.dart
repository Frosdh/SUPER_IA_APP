import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/RideHistoryService.dart';
import 'package:fu_uber/UI/views/OsmMapScreen.dart';
import 'package:fu_uber/UI/views/ReportarObjetoPerdidoScreen.dart';
import 'package:fu_uber/UI/views/ReceiptScreen.dart';
import 'package:fu_uber/UI/views/DisputeScreen.dart';

class RideHistoryScreen extends StatefulWidget {
  static const String route = '/ride_history';

  @override
  _RideHistoryScreenState createState() => _RideHistoryScreenState();
}

class _RideHistoryScreenState extends State<RideHistoryScreen> {
  List<Map<String, dynamic>> _viajes = [];
  bool _cargando = true;

  @override
  void initState() {
    super.initState();
    _cargarHistorial();
  }

  Future<void> _cargarHistorial() async {
    final lista = await RideHistoryService.obtenerViajes();
    if (mounted) {
      setState(() {
        _viajes = lista;
        _cargando = false;
      });
    }
  }

  String _formatearFecha(String iso) {
    try {
      final fecha = DateTime.parse(iso).toLocal();
      final meses = [
        '', 'ene', 'feb', 'mar', 'abr', 'may', 'jun',
        'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
      ];
      final hora = fecha.hour.toString().padLeft(2, '0');
      final min  = fecha.minute.toString().padLeft(2, '0');
      return '${fecha.day} ${meses[fecha.month]} ${fecha.year}  $hora:$min';
    } catch (_) {
      return '';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: Icon(Icons.arrow_back, color: Colors.white),
          onPressed: () => Navigator.of(context).pop(),
        ),
        title: Text(
          'Mis viajes',
          style: TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      body: _cargando
          ? Center(
              child: CircularProgressIndicator(
                valueColor: AlwaysStoppedAnimation<Color>(
                  ConstantColors.primaryViolet,
                ),
              ),
            )
          : _viajes.isEmpty
              ? _buildEstadoVacio()
              : _buildListaViajes(),
    );
  }

  Widget _buildEstadoVacio() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 90,
            height: 90,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: ConstantColors.primaryViolet.withOpacity(0.1),
            ),
            child: Icon(
              Icons.directions_car_outlined,
              color: ConstantColors.primaryViolet,
              size: 44,
            ),
          ),
          SizedBox(height: 20),
          Text(
            'Aún no tienes viajes',
            style: TextStyle(
              color: ConstantColors.textWhite,
              fontSize: 18,
              fontWeight: FontWeight.w700,
            ),
          ),
          SizedBox(height: 8),
          Text(
            'Tus viajes completados\naparecerán aquí',
            style: TextStyle(
              color: ConstantColors.textGrey,
              fontSize: 14,
              height: 1.5,
            ),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }

  Widget _buildListaViajes() {
    return ListView.builder(
      padding: EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      itemCount: _viajes.length,
      itemBuilder: (context, index) {
        final viaje = _viajes[index];
        return _buildTarjetaViaje(viaje, index);
      },
    );
  }

  Widget _buildTarjetaViaje(Map<String, dynamic> viaje, int index) {
    final double precio    = (viaje['precio'] as num)?.toDouble() ?? 0.0;
    final double distancia = (viaje['distancia_km'] as num)?.toDouble() ?? 0.0;
    final int    duracion  = (viaje['duracion_min'] as num)?.toInt() ?? 0;
    final int    calif     = (viaje['calificacion'] as num)?.toInt() ?? 0;
    final String fecha     = _formatearFecha(viaje['fecha'] ?? '');

    return Container(
      margin: EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Padding(
        padding: EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Encabezado: fecha + precio
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  fecha,
                  style: TextStyle(
                    color: ConstantColors.textGrey,
                    fontSize: 12,
                  ),
                ),
                Container(
                  padding: EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: ConstantColors.primaryViolet.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    '\$${precio.toStringAsFixed(2)}',
                    style: TextStyle(
                      color: ConstantColors.primaryViolet,
                      fontWeight: FontWeight.w700,
                      fontSize: 14,
                    ),
                  ),
                ),
              ],
            ),

            SizedBox(height: 14),

            // Ruta origen → destino
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Column(
                  children: [
                    Icon(Icons.circle,
                        size: 9, color: ConstantColors.primaryBlue),
                    Container(
                      width: 2, height: 22,
                      color: ConstantColors.borderColor,
                    ),
                    Icon(Icons.location_on,
                        size: 14, color: ConstantColors.primaryViolet),
                  ],
                ),
                SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        viaje['origen'] ?? '',
                        style: TextStyle(
                          color: ConstantColors.textGrey,
                          fontSize: 13,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      SizedBox(height: 12),
                      Text(
                        viaje['destino'] ?? '',
                        style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
              ],
            ),

            SizedBox(height: 14),
            Divider(color: ConstantColors.dividerColor, height: 1),
            SizedBox(height: 12),

            // Stats: distancia, duración, calificación
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildMiniStat(
                  Icons.straighten_rounded,
                  '${distancia.toStringAsFixed(1)} km',
                ),
                _buildSeparador(),
                _buildMiniStat(
                  Icons.access_time_rounded,
                  '$duracion min',
                ),
                _buildSeparador(),
                Row(
                  children: List.generate(5, (i) {
                    return Icon(
                      i < calif
                          ? Icons.star_rounded
                          : Icons.star_outline_rounded,
                      color: i < calif ? Colors.amber : ConstantColors.textSubtle,
                      size: 14,
                    );
                  }),
                ),
              ],
            ),

            // Conductor (si hay info)
            if ((viaje['conductor_nombre'] ?? '').isNotEmpty) ...[
              SizedBox(height: 12),
              Divider(color: ConstantColors.dividerColor, height: 1),
              SizedBox(height: 10),
              Row(
                children: [
                  Icon(Icons.person_rounded,
                      size: 14, color: ConstantColors.textGrey),
                  SizedBox(width: 6),
                  Text(
                    viaje['conductor_nombre'],
                    style: TextStyle(
                      color: ConstantColors.textGrey,
                      fontSize: 12,
                    ),
                  ),
                  SizedBox(width: 10),
                  Icon(Icons.directions_car_rounded,
                      size: 14, color: ConstantColors.textGrey),
                  SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      '${viaje['conductor_auto']}  ·  ${viaje['conductor_placa']}',
                      style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 12,
                      ),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ],
            SizedBox(height: 14),
            // ── Botones de acción fila 1 ─────────────────────────────
            Row(
              children: [
                // Repetir viaje
                Expanded(
                  child: GestureDetector(
                    onTap: () {
                      Navigator.pushNamed(
                        context,
                        OsmMapScreen.route,
                        arguments: {
                          'repeat_trip': true,
                          'origen': viaje['origen'] ?? '',
                          'destino': viaje['destino'] ?? '',
                          'origen_lat': viaje['origen_lat'],
                          'origen_lng': viaje['origen_lng'],
                          'destino_lat': viaje['destino_lat'],
                          'destino_lng': viaje['destino_lng'],
                        },
                      );
                    },
                    child: Container(
                      padding: EdgeInsets.symmetric(vertical: 12),
                      decoration: BoxDecoration(
                        color: ConstantColors.primaryViolet.withOpacity(0.12),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                          color: ConstantColors.primaryViolet.withOpacity(0.35),
                        ),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.replay_rounded,
                              color: ConstantColors.primaryViolet, size: 16),
                          SizedBox(width: 6),
                          Text(
                            'Repetir viaje',
                            style: TextStyle(
                              color: ConstantColors.primaryViolet,
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),

                // Botón objeto perdido (solo viajes terminados con conductor)
                if ((viaje['estado'] ?? '') == 'terminado' &&
                    (viaje['conductor_nombre'] ?? '').isNotEmpty) ...[
                  SizedBox(width: 10),
                  Expanded(
                    child: GestureDetector(
                      onTap: () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => ReportarObjetoPerdidoScreen(
                              viajeId: viaje['id'] ?? 0,
                              origen: viaje['origen'] ?? '',
                              destino: viaje['destino'] ?? '',
                              conductorNombre: viaje['conductor_nombre'] ?? '',
                              conductorPlaca: viaje['conductor_placa'] ?? '',
                            ),
                          ),
                        );
                      },
                      child: Container(
                        padding: EdgeInsets.symmetric(vertical: 12),
                        decoration: BoxDecoration(
                          color: Colors.orange.withOpacity(0.10),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(
                            color: Colors.orange.withOpacity(0.35),
                          ),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.search_rounded,
                                color: Colors.orange, size: 16),
                            SizedBox(width: 6),
                            Text(
                              'Objeto perdido',
                              style: TextStyle(
                                color: Colors.orange,
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ],
            ),

            // ── Botones de acción fila 2: Recibo + Disputar ─────────
            SizedBox(height: 10),
            Row(
              children: [
                // Ver recibo
                Expanded(
                  child: GestureDetector(
                    onTap: () {
                      Navigator.pushNamed(
                        context,
                        ReceiptScreen.route,
                        arguments: {
                          'viaje_id':         viaje['id'] ?? 0,
                          'origen':           viaje['origen'] ?? '',
                          'destino':          viaje['destino'] ?? '',
                          'distancia_km':     viaje['distancia_km'] ?? 0,
                          'duracion_min':     viaje['duracion_min'] ?? 0,
                          'precio':           viaje['precio'] ?? 0,
                          'conductor_nombre': viaje['conductor_nombre'] ?? '',
                          'conductor_auto':   viaje['conductor_auto'] ?? '',
                          'conductor_placa':  viaje['conductor_placa'] ?? '',
                          'fecha':            viaje['fecha'] ?? '',
                          'descuento':        viaje['descuento'] ?? 0,
                          'tarifa_base':      viaje['tarifa_base'] ?? 0,
                          'precio_km':        viaje['precio_km'] ?? 0,
                          'precio_minuto':    viaje['precio_minuto'] ?? 0,
                          'categoria_nombre': viaje['categoria_nombre'] ?? '',
                          'metodo_pago':      viaje['metodo_pago'] ?? 'cash',
                        },
                      );
                    },
                    child: Container(
                      padding: EdgeInsets.symmetric(vertical: 12),
                      decoration: BoxDecoration(
                        color: ConstantColors.primaryBlue.withOpacity(0.10),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                          color: ConstantColors.primaryBlue.withOpacity(0.35),
                        ),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.receipt_rounded,
                              color: ConstantColors.primaryBlue, size: 16),
                          SizedBox(width: 6),
                          Text(
                            'Ver recibo',
                            style: TextStyle(
                              color: ConstantColors.primaryBlue,
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),

                SizedBox(width: 10),

                // Disputar cobro
                Expanded(
                  child: GestureDetector(
                    onTap: () {
                      Navigator.pushNamed(
                        context,
                        DisputeScreen.route,
                        arguments: {
                          'viaje_id':         viaje['id'] ?? 0,
                          'origen':           viaje['origen'] ?? '',
                          'destino':          viaje['destino'] ?? '',
                          'precio':           viaje['precio'] ?? 0,
                          'fecha':            viaje['fecha'] ?? '',
                          'conductor_nombre': viaje['conductor_nombre'] ?? '',
                          'metodo_pago':      viaje['metodo_pago'] ?? 'cash',
                        },
                      );
                    },
                    child: Container(
                      padding: EdgeInsets.symmetric(vertical: 12),
                      decoration: BoxDecoration(
                        color: ConstantColors.error.withOpacity(0.08),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                          color: ConstantColors.error.withOpacity(0.3),
                        ),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.flag_rounded,
                              color: ConstantColors.error, size: 16),
                          SizedBox(width: 6),
                          Text(
                            'Disputar cobro',
                            style: TextStyle(
                              color: ConstantColors.error,
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildMiniStat(IconData icon, String valor) {
    return Row(
      children: [
        Icon(icon, size: 14, color: ConstantColors.textGrey),
        SizedBox(width: 4),
        Text(
          valor,
          style: TextStyle(
            color: ConstantColors.textWhite,
            fontSize: 13,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }

  Widget _buildSeparador() {
    return Container(
      width: 1, height: 16,
      color: ConstantColors.dividerColor,
    );
  }
}
