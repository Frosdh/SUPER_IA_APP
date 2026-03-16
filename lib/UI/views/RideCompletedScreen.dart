import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/RideHistoryService.dart';

class RideCompletedScreen extends StatefulWidget {
  static const String route = '/ride_completed';

  @override
  _RideCompletedScreenState createState() => _RideCompletedScreenState();
}

class _RideCompletedScreenState extends State<RideCompletedScreen>
    with SingleTickerProviderStateMixin {
  int _calificacion = 0;
  bool _guardando = false;
  final TextEditingController _comentarioController = TextEditingController();
  late AnimationController _animController;
  late Animation<double> _scaleAnim;
  late Animation<double> _fadeAnim;

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: 700),
    );
    _scaleAnim = Tween<double>(begin: 0.5, end: 1.0).animate(
      CurvedAnimation(parent: _animController, curve: Curves.elasticOut),
    );
    _fadeAnim = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _animController, curve: Curves.easeOut),
    );
    _animController.forward();
  }

  @override
  void dispose() {
    _animController.dispose();
    _comentarioController.dispose();
    super.dispose();
  }

  Future<void> _finalizar(Map<String, dynamic> args) async {
    if (_guardando) return;
    setState(() => _guardando = true);

    try {
      final conductor = args['conductor'] as Map<String, dynamic>;
      // conductor_id puede ser null si es conductor simulado — se maneja con ?? 0
      final int conductorId =
          conductor != null && conductor['id'] != null
              ? (conductor['id'] as num).toInt()
              : 0;

      await RideHistoryService.guardarViaje(
        viajeId: (args['viaje_id'] as num?)?.toInt() ?? 0,
        calificacion: _calificacion,
        conductorId: conductorId,
        comentario: _comentarioController.text.trim(),
        origen: args['origen'] ?? '',
        destino: args['destino'] ?? '',
        distanciaKm: (args['distancia'] as num?)?.toDouble() ?? 0.0,
        duracionMin: (args['duracion'] as num?)?.toInt() ?? 0,
        precio: (args['precio'] as num?)?.toDouble() ?? 0.0,
        conductorNombre: conductor != null ? (conductor['nombre'] ?? '') : '',
        conductorAuto: conductor != null ? (conductor['auto'] ?? '') : '',
        conductorPlaca: conductor != null ? (conductor['placa'] ?? '') : '',
        origenLat: (args['origen_lat'] as num?)?.toDouble() ?? 0.0,
        origenLng: (args['origen_lng'] as num?)?.toDouble() ?? 0.0,
        destinoLat: (args['destino_lat'] as num?)?.toDouble() ?? 0.0,
        destinoLng: (args['destino_lng'] as num?)?.toDouble() ?? 0.0,
        descuento: (args['descuento'] as num?)?.toDouble() ?? 0.0,
        codigoDescuento: args['codigo_descuento'] as String? ?? '',
      );
      print('>>> [RideCompleted] Viaje guardado. viaje_id=${args['viaje_id']}, calificacion=$_calificacion');
    } catch (e) {
      print('>>> [RideCompleted] Error al guardar viaje: $e');
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
        if (Navigator.of(context).canPop()) {
          Navigator.of(context).pop();
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final args =
        (ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?) ?? {};

    final conductor = args['conductor'] as Map<String, dynamic>?;
    final String origen          = args['origen'] as String?   ?? '';
    final String destino         = args['destino'] as String?  ?? '';
    final double distancia       = (args['distancia'] as num?)?.toDouble() ?? 0.0;
    final int    duracion        = (args['duracion'] as num?)?.toInt()    ?? 0;
    final double precio          = (args['precio'] as num?)?.toDouble() ?? 0.0;
    final String categoriaNombre = args['categoria_nombre'] as String? ?? 'GeoMove-X';
    final double tarifaBase      = (args['tarifa_base'] as num?)?.toDouble() ?? 0.0;
    final double precioKm        = (args['precio_km'] as num?)?.toDouble() ?? 0.0;
    final double precioMin       = (args['precio_minuto'] as num?)?.toDouble() ?? 0.0;
    // Descuento aplicado (puede ser 0 si no hubo cupón)
    final double descuento       = (args['descuento'] as num?)?.toDouble() ?? 0.0;
    final String codigoDescuento = args['codigo_descuento'] as String? ?? '';
    // Componentes del precio
    final double costoBase = tarifaBase;
    final double costoKm   = precioKm  * distancia;
    final double costoMin  = precioMin * duracion;

    return WillPopScope(
      // Interceptar botón atrás de Android para guardar siempre
      onWillPop: () async {
        await _finalizar(args);
        return false; // La navegación la hace _finalizar()
      },
      child: Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: EdgeInsets.symmetric(horizontal: 24),
          child: FadeTransition(
            opacity: _fadeAnim,
            child: Column(
              children: [
                SizedBox(height: 40),

                // ── Ícono de éxito animado ─────────────────────────
                ScaleTransition(
                  scale: _scaleAnim,
                  child: Container(
                    width: 100,
                    height: 100,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: LinearGradient(
                        colors: [Color(0xFF00C853), Color(0xFF00E676)],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: Color(0xFF00C853).withOpacity(0.45),
                          blurRadius: 30,
                          spreadRadius: 4,
                        ),
                      ],
                    ),
                    child: Icon(
                      Icons.check_rounded,
                      color: Colors.white,
                      size: 54,
                    ),
                  ),
                ),

                SizedBox(height: 24),

                Text(
                  '¡Llegaste a tu destino!',
                  style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 24,
                    fontWeight: FontWeight.w800,
                  ),
                ),

                SizedBox(height: 8),

                Text(
                  'Esperamos que hayas disfrutado tu viaje',
                  style: TextStyle(
                    color: ConstantColors.textGrey,
                    fontSize: 14,
                  ),
                  textAlign: TextAlign.center,
                ),

                SizedBox(height: 32),

                // ── Resumen del viaje ──────────────────────────────
                Container(
                  padding: EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: ConstantColors.backgroundCard,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: ConstantColors.borderColor),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Resumen del viaje',
                        style: TextStyle(
                          color: ConstantColors.textGrey,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 0.5,
                        ),
                      ),
                      SizedBox(height: 16),

                      // Ruta
                      Row(
                        children: [
                          Column(
                            children: [
                              Icon(Icons.circle,
                                  size: 10, color: ConstantColors.primaryBlue),
                              Container(
                                width: 2, height: 28,
                                color: ConstantColors.borderColor,
                              ),
                              Icon(Icons.location_on,
                                  size: 16, color: ConstantColors.primaryViolet),
                            ],
                          ),
                          SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  origen,
                                  style: TextStyle(
                                    color: ConstantColors.textGrey,
                                    fontSize: 13,
                                  ),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                SizedBox(height: 14),
                                Text(
                                  destino,
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

                      SizedBox(height: 20),
                      Divider(color: ConstantColors.dividerColor),
                      SizedBox(height: 16),

                      // Stats: distancia, tiempo, precio
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceAround,
                        children: [
                          _buildStat(
                            Icons.straighten_rounded,
                            '${distancia.toStringAsFixed(1)} km',
                            'Distancia',
                          ),
                          _buildDividerVertical(),
                          _buildStat(
                            Icons.access_time_rounded,
                            '$duracion min',
                            'Duración',
                          ),
                          _buildDividerVertical(),
                          _buildStat(
                            Icons.attach_money_rounded,
                            '\$${precio.toStringAsFixed(2)}',
                            'Total',
                            highlight: true,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),

                SizedBox(height: 16),

                // ── Desglose de precio ─────────────────────────────
                Container(
                  padding: EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: ConstantColors.backgroundCard,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: ConstantColors.borderColor),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        Icon(Icons.receipt_long_rounded,
                            color: ConstantColors.primaryViolet, size: 18),
                        SizedBox(width: 8),
                        Text('Desglose del cobro',
                            style: TextStyle(
                              color: ConstantColors.textWhite,
                              fontSize: 14,
                              fontWeight: FontWeight.w700,
                            )),
                        Spacer(),
                        Container(
                          padding: EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(
                            color: ConstantColors.primaryViolet.withOpacity(0.15),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(categoriaNombre,
                              style: TextStyle(
                                color: ConstantColors.primaryViolet,
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                              )),
                        ),
                      ]),
                      SizedBox(height: 14),
                      _buildDesglose('Tarifa base', costoBase),
                      _buildDesglose(
                          '${distancia.toStringAsFixed(1)} km × \$$precioKm', costoKm),
                      _buildDesglose(
                          '$duracion min × \$$precioMin', costoMin),
                      // ── Línea de descuento (solo si se aplicó un cupón) ──
                      if (descuento > 0) ...[
                        SizedBox(height: 4),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Row(
                              children: [
                                Icon(Icons.local_offer_rounded,
                                    color: Colors.green, size: 14),
                                SizedBox(width: 6),
                                Text(
                                  codigoDescuento.isNotEmpty
                                      ? 'Cupón $codigoDescuento'
                                      : 'Descuento aplicado',
                                  style: TextStyle(
                                    color: Colors.green,
                                    fontSize: 13,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                            Text(
                              '-\$${descuento.toStringAsFixed(2)}',
                              style: TextStyle(
                                color: Colors.green,
                                fontSize: 13,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ],
                      Divider(color: ConstantColors.dividerColor, height: 20),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('Total pagado',
                              style: TextStyle(
                                color: ConstantColors.textWhite,
                                fontSize: 15,
                                fontWeight: FontWeight.w700,
                              )),
                          Text('\$${precio.toStringAsFixed(2)}',
                              style: TextStyle(
                                color: ConstantColors.primaryViolet,
                                fontSize: 18,
                                fontWeight: FontWeight.w800,
                              )),
                        ],
                      ),
                      SizedBox(height: 6),
                      Row(children: [
                        Icon(Icons.payments_rounded,
                            color: ConstantColors.success, size: 14),
                        SizedBox(width: 6),
                        Text('Pago en efectivo',
                            style: TextStyle(
                              color: ConstantColors.success,
                              fontSize: 12,
                            )),
                      ]),
                    ],
                  ),
                ),

                SizedBox(height: 16),

                // ── Calificación del conductor ─────────────────────
                Container(
                  padding: EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: ConstantColors.backgroundCard,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: ConstantColors.borderColor),
                  ),
                  child: Column(
                    children: [
                      // Info del conductor
                      if (conductor != null) ...[
                        Row(
                          children: [
                            CircleAvatar(
                              radius: 26,
                              backgroundColor:
                                  ConstantColors.primaryViolet.withOpacity(0.2),
                              child: Text(
                                conductor['inicial'] ?? 'C',
                                style: TextStyle(
                                  color: ConstantColors.primaryViolet,
                                  fontSize: 22,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ),
                            SizedBox(width: 14),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    conductor['nombre'] ?? '',
                                    style: TextStyle(
                                      color: ConstantColors.textWhite,
                                      fontSize: 15,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                  SizedBox(height: 3),
                                  Text(
                                    '${conductor['auto']}  ·  ${conductor['placa']}',
                                    style: TextStyle(
                                      color: ConstantColors.textGrey,
                                      fontSize: 12,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            Row(
                              children: [
                                Icon(Icons.star_rounded,
                                    color: Colors.amber, size: 16),
                                SizedBox(width: 3),
                                Text(
                                  '${conductor['calificacion']}',
                                  style: TextStyle(
                                    color: ConstantColors.textGrey,
                                    fontSize: 13,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ] else
                        SizedBox(),

                      SizedBox(height: 20),
                      Divider(color: ConstantColors.dividerColor),
                      SizedBox(height: 16),

                      Text(
                        '¿Cómo fue tu experiencia?',
                        style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                        ),
                      ),

                      SizedBox(height: 16),

                      // Estrellas
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: List.generate(5, (index) {
                          final estrella = index + 1;
                          return GestureDetector(
                            onTap: () =>
                                setState(() => _calificacion = estrella),
                            child: AnimatedContainer(
                              duration: Duration(milliseconds: 150),
                              padding: EdgeInsets.symmetric(horizontal: 6),
                              child: Icon(
                                estrella <= _calificacion
                                    ? Icons.star_rounded
                                    : Icons.star_outline_rounded,
                                color: estrella <= _calificacion
                                    ? Colors.amber
                                    : ConstantColors.textSubtle,
                                size: 44,
                              ),
                            ),
                          );
                        }),
                      ),

                      if (_calificacion > 0) ...[
                        SizedBox(height: 8),
                        Text(
                          _textoCalificacion(_calificacion),
                          style: TextStyle(
                            color: Colors.amber,
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        SizedBox(height: 16),
                        // Campo de reseña escrita
                        TextField(
                          controller: _comentarioController,
                          maxLines: 3,
                          maxLength: 200,
                          style: TextStyle(
                            color: ConstantColors.textWhite,
                            fontSize: 14,
                          ),
                          decoration: InputDecoration(
                            hintText: 'Deja un comentario sobre tu viaje (opcional)...',
                            hintStyle: TextStyle(
                              color: ConstantColors.textSubtle,
                              fontSize: 13,
                            ),
                            filled: true,
                            fillColor: ConstantColors.backgroundDark,
                            counterStyle: TextStyle(color: ConstantColors.textSubtle),
                            border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(14),
                              borderSide: BorderSide(color: ConstantColors.borderColor),
                            ),
                            enabledBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(14),
                              borderSide: BorderSide(color: ConstantColors.borderColor),
                            ),
                            focusedBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(14),
                              borderSide: BorderSide(color: ConstantColors.primaryViolet),
                            ),
                            contentPadding: EdgeInsets.all(14),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),

                SizedBox(height: 32),

                // ── Botón finalizar ────────────────────────────────
                GestureDetector(
                  onTap: _guardando ? null : () => _finalizar(args),
                  child: Container(
                    width: double.infinity,
                    height: 56,
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: [
                          ConstantColors.primaryViolet,
                          ConstantColors.primaryBlue,
                        ],
                        begin: Alignment.centerLeft,
                        end: Alignment.centerRight,
                      ),
                      borderRadius: BorderRadius.circular(16),
                      boxShadow: [
                        BoxShadow(
                          color: ConstantColors.primaryViolet.withOpacity(0.4),
                          blurRadius: 20,
                          offset: Offset(0, 8),
                        ),
                      ],
                    ),
                    child: Center(
                      child: _guardando
                          ? SizedBox(
                              width: 24,
                              height: 24,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                    Colors.white),
                              ),
                            )
                          : Text(
                              _calificacion > 0
                                  ? 'Enviar calificación'
                                  : 'Finalizar',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 16,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                    ),
                  ),
                ),

                SizedBox(height: 40),
              ],
            ),
          ),
        ),
      ),
    ), // Scaffold
    ); // WillPopScope
  }

  String _textoCalificacion(int estrellas) {
    switch (estrellas) {
      case 1: return 'Muy mala experiencia';
      case 2: return 'Podría mejorar';
      case 3: return 'Fue aceptable';
      case 4: return 'Buena experiencia';
      case 5: return '¡Excelente conductor!';
      default: return '';
    }
  }

  Widget _buildDesglose(String concepto, double monto) {
    return Padding(
      padding: EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(concepto,
              style: TextStyle(color: ConstantColors.textGrey, fontSize: 13)),
          Text('\$${monto.toStringAsFixed(2)}',
              style: TextStyle(color: ConstantColors.textWhite, fontSize: 13)),
        ],
      ),
    );
  }

  Widget _buildStat(IconData icon, String value, String label,
      {bool highlight = false}) {
    return Column(
      children: [
        Icon(
          icon,
          color: highlight
              ? ConstantColors.primaryViolet
              : ConstantColors.textGrey,
          size: 20,
        ),
        SizedBox(height: 6),
        Text(
          value,
          style: TextStyle(
            color: highlight
                ? ConstantColors.primaryViolet
                : ConstantColors.textWhite,
            fontSize: 16,
            fontWeight: FontWeight.w700,
          ),
        ),
        SizedBox(height: 2),
        Text(
          label,
          style: TextStyle(
            color: ConstantColors.textSubtle,
            fontSize: 11,
          ),
        ),
      ],
    );
  }

  Widget _buildDividerVertical() {
    return Container(
      width: 1,
      height: 40,
      color: ConstantColors.dividerColor,
    );
  }
}
