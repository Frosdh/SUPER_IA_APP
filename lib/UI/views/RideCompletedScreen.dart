import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/TransactionModel.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/Preferences/RideHistoryService.dart';
import 'package:fu_uber/Core/Preferences/TransactionHistoryService.dart';
import 'package:fu_uber/Core/Services/PayPhoneService.dart';
import 'package:fu_uber/UI/views/PayPhoneWebViewScreen.dart';

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

  // ─── Estado del pago ────────────────────────────────────────────────────
  // 'pending' | 'cash' | 'payphone_approved' | 'payphone_error'
  String _paymentStatus = 'pending';
  bool _procesandoPago = false;
  String? _payphoneError;
  TransactionModel? _transaccionGuardada;

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

  bool get _pagoConcluido =>
      _paymentStatus == 'cash' || _paymentStatus == 'payphone_approved';

  // ─── PAGO EN EFECTIVO ────────────────────────────────────────────────────
  Future<void> _elegirEfectivo(Map<String, dynamic> args) async {
    final double precio = (args['precio'] as num?)?.toDouble() ?? 0.0;
    final int? viajeId = (args['viaje_id'] as num?)?.toInt();

    final clientTxnId =
        TransactionHistoryService.generarClientTransactionId(viajeId);

    final txn = PayPhoneService.buildCashTransaction(
      clientTransactionId: clientTxnId,
      amountUSD: precio,
      referencia: 'Viaje GeoMove - ${args['destino'] ?? ''}',
      viajeId: viajeId,
    );

    final phone = await AuthPrefs.getUserPhone();
    await TransactionHistoryService.guardarTransaccion(
        userPhone: phone, transaccion: txn);

    if (mounted) {
      setState(() {
        _paymentStatus = 'cash';
        _transaccionGuardada = txn;
        _payphoneError = null;
      });
    }
  }

  // ─── PAGO CON PAYPHONE ───────────────────────────────────────────────────
  Future<void> _iniciarPayPhone(Map<String, dynamic> args) async {
    if (_procesandoPago) return;
    setState(() {
      _procesandoPago = true;
      _payphoneError = null;
    });

    final double precio = (args['precio'] as num?)?.toDouble() ?? 0.0;
    final int? viajeId = (args['viaje_id'] as num?)?.toInt();
    final String destino = args['destino'] as String? ?? 'destino';
    final clientTxnId =
        TransactionHistoryService.generarClientTransactionId(viajeId);

    // 1. Crear pago en PayPhone API
    final createResult = await PayPhoneService.crearPago(
      amountUSD: precio,
      clientTransactionId: clientTxnId,
      referencia: 'Viaje GeoMove - $destino',
    );

    if (!createResult.success || createResult.redirectUrl == null) {
      if (mounted) {
        setState(() {
          _procesandoPago = false;
          _payphoneError =
              createResult.errorMessage ?? 'No se pudo conectar con PayPhone';
        });
      }
      return;
    }

    setState(() => _procesandoPago = false);

    // 2. Abrir WebView de PayPhone
    if (!mounted) return;
    final result = await Navigator.pushNamed(
      context,
      PayPhoneWebViewScreen.route,
      arguments: {
        'redirectUrl': createResult.redirectUrl!,
        'paymentId': createResult.paymentId!,
        'clientTransactionId': clientTxnId,
        'amount': precio,
      },
    );

    if (!mounted) return;

    // 3. Procesar resultado del WebView
    if (result is PayPhoneWebViewResult) {
      final phone = await AuthPrefs.getUserPhone();

      if (result.approved) {
        final txn = TransactionModel(
          clientTransactionId: clientTxnId,
          payPhoneTransactionId: result.payPhoneTransactionId,
          amount: precio,
          method: 'payphone',
          status: 'approved',
          reference: 'Viaje GeoMove - $destino',
          fecha: DateTime.now().toIso8601String(),
          viajeId: viajeId,
        );
        await TransactionHistoryService.guardarTransaccion(
            userPhone: phone, transaccion: txn);

        setState(() {
          _paymentStatus = 'payphone_approved';
          _transaccionGuardada = txn;
          _payphoneError = null;
        });
      } else {
        // Pago cancelado → guardar como cancelado y volver a 'pending'
        final txn = TransactionModel(
          clientTransactionId: clientTxnId,
          amount: precio,
          method: 'payphone',
          status: 'cancelled',
          reference: 'Viaje GeoMove - $destino',
          fecha: DateTime.now().toIso8601String(),
          viajeId: viajeId,
        );
        await TransactionHistoryService.guardarTransaccion(
            userPhone: phone, transaccion: txn);

        setState(() {
          _paymentStatus = 'pending';
          _payphoneError =
              result.status == 'cancelled' ? 'Pago cancelado' : result.errorMessage;
        });
      }
    } else {
      // Cerró el WebView sin resultado
      setState(() {
        _paymentStatus = 'pending';
        _payphoneError = 'Pago no completado';
      });
    }
  }

  // ─── FINALIZAR VIAJE ─────────────────────────────────────────────────────
  Future<void> _finalizar(Map<String, dynamic> args) async {
    if (_guardando) return;
    if (!_pagoConcluido) {
      _mostrarDialogoSinPago();
      return;
    }
    setState(() => _guardando = true);

    try {
      final conductor = args['conductor'] as Map<String, dynamic>?;
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

  void _mostrarDialogoSinPago() {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: ConstantColors.backgroundCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text('Selecciona método de pago',
            style: TextStyle(
                color: ConstantColors.textWhite, fontWeight: FontWeight.w700)),
        content: Text(
          'Por favor elige cómo vas a pagar antes de continuar.',
          style: TextStyle(color: ConstantColors.textGrey),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text('Entendido',
                style: TextStyle(color: ConstantColors.primaryViolet)),
          ),
        ],
      ),
    );
  }

  // ─── BUILD ───────────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    final args =
        (ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?) ??
            {};

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
    final double descuento       = (args['descuento'] as num?)?.toDouble() ?? 0.0;
    final String codigoDescuento = args['codigo_descuento'] as String? ?? '';
    final double costoBase = tarifaBase;
    final double costoKm   = precioKm  * distancia;
    final double costoMin  = precioMin * duracion;

    return WillPopScope(
      onWillPop: () async {
        if (_pagoConcluido) {
          await _finalizar(args);
        }
        return false;
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

                  // ── Ícono de éxito animado ────────────────────────────
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

                  // ── Resumen del viaje ─────────────────────────────────
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
                        Text('Resumen del viaje',
                            style: TextStyle(
                              color: ConstantColors.textGrey,
                              fontSize: 12,
                              fontWeight: FontWeight.w600,
                              letterSpacing: 0.5,
                            )),
                        SizedBox(height: 16),
                        Row(
                          children: [
                            Column(
                              children: [
                                Icon(Icons.circle,
                                    size: 10,
                                    color: ConstantColors.primaryBlue),
                                Container(
                                    width: 2,
                                    height: 28,
                                    color: ConstantColors.borderColor),
                                Icon(Icons.location_on,
                                    size: 16,
                                    color: ConstantColors.primaryViolet),
                              ],
                            ),
                            SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(origen,
                                      style: TextStyle(
                                          color: ConstantColors.textGrey,
                                          fontSize: 13),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis),
                                  SizedBox(height: 14),
                                  Text(destino,
                                      style: TextStyle(
                                          color: ConstantColors.textWhite,
                                          fontSize: 14,
                                          fontWeight: FontWeight.w600),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis),
                                ],
                              ),
                            ),
                          ],
                        ),
                        SizedBox(height: 20),
                        Divider(color: ConstantColors.dividerColor),
                        SizedBox(height: 16),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceAround,
                          children: [
                            _buildStat(Icons.straighten_rounded,
                                '${distancia.toStringAsFixed(1)} km', 'Distancia'),
                            _buildDividerVertical(),
                            _buildStat(Icons.access_time_rounded,
                                '$duracion min', 'Duración'),
                            _buildDividerVertical(),
                            _buildStat(Icons.attach_money_rounded,
                                '\$${precio.toStringAsFixed(2)}', 'Total',
                                highlight: true),
                          ],
                        ),
                      ],
                    ),
                  ),

                  SizedBox(height: 16),

                  // ── Desglose de precio ────────────────────────────────
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
                            padding: EdgeInsets.symmetric(
                                horizontal: 10, vertical: 4),
                            decoration: BoxDecoration(
                              color: ConstantColors.primaryViolet
                                  .withOpacity(0.15),
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
                            '${distancia.toStringAsFixed(1)} km × \$$precioKm',
                            costoKm),
                        _buildDesglose('$duracion min × \$$precioMin', costoMin),
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
                                        fontWeight: FontWeight.w600),
                                  ),
                                ],
                              ),
                              Text(
                                '-\$${descuento.toStringAsFixed(2)}',
                                style: TextStyle(
                                    color: Colors.green,
                                    fontSize: 13,
                                    fontWeight: FontWeight.w600),
                              ),
                            ],
                          ),
                        ],
                        Divider(color: ConstantColors.dividerColor, height: 20),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text('Total a pagar',
                                style: TextStyle(
                                    color: ConstantColors.textWhite,
                                    fontSize: 15,
                                    fontWeight: FontWeight.w700)),
                            Text('\$${precio.toStringAsFixed(2)}',
                                style: TextStyle(
                                    color: ConstantColors.primaryViolet,
                                    fontSize: 18,
                                    fontWeight: FontWeight.w800)),
                          ],
                        ),
                      ],
                    ),
                  ),

                  SizedBox(height: 16),

                  // ── ✨ MÓDULO DE PAGO (NUEVO) ─────────────────────────
                  _buildPaymentCard(args),

                  SizedBox(height: 16),

                  // ── Calificación (solo visible si ya pagó) ────────────
                  if (_pagoConcluido) ...[
                    Container(
                      padding: EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: ConstantColors.backgroundCard,
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: ConstantColors.borderColor),
                      ),
                      child: Column(
                        children: [
                          if (conductor != null) ...[
                            Row(
                              children: [
                                CircleAvatar(
                                  radius: 26,
                                  backgroundColor: ConstantColors.primaryViolet
                                      .withOpacity(0.2),
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
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(conductor['nombre'] ?? '',
                                          style: TextStyle(
                                              color: ConstantColors.textWhite,
                                              fontSize: 15,
                                              fontWeight: FontWeight.w700)),
                                      SizedBox(height: 3),
                                      Text(
                                          '${conductor['auto']}  ·  ${conductor['placa']}',
                                          style: TextStyle(
                                              color: ConstantColors.textGrey,
                                              fontSize: 12)),
                                    ],
                                  ),
                                ),
                                Row(
                                  children: [
                                    Icon(Icons.star_rounded,
                                        color: Colors.amber, size: 16),
                                    SizedBox(width: 3),
                                    Text('${conductor['calificacion']}',
                                        style: TextStyle(
                                            color: ConstantColors.textGrey,
                                            fontSize: 13)),
                                  ],
                                ),
                              ],
                            ),
                          ] else
                            SizedBox(),

                          SizedBox(height: 20),
                          Divider(color: ConstantColors.dividerColor),
                          SizedBox(height: 16),

                          Text('¿Cómo fue tu experiencia?',
                              style: TextStyle(
                                  color: ConstantColors.textWhite,
                                  fontSize: 15,
                                  fontWeight: FontWeight.w600)),

                          SizedBox(height: 16),

                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: List.generate(5, (index) {
                              final estrella = index + 1;
                              return GestureDetector(
                                onTap: () =>
                                    setState(() => _calificacion = estrella),
                                child: AnimatedContainer(
                                  duration: Duration(milliseconds: 150),
                                  padding:
                                      EdgeInsets.symmetric(horizontal: 6),
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
                            Text(_textoCalificacion(_calificacion),
                                style: TextStyle(
                                    color: Colors.amber,
                                    fontSize: 13,
                                    fontWeight: FontWeight.w600)),
                            SizedBox(height: 16),
                            TextField(
                              controller: _comentarioController,
                              maxLines: 3,
                              maxLength: 200,
                              style: TextStyle(
                                  color: ConstantColors.textWhite,
                                  fontSize: 14),
                              decoration: InputDecoration(
                                hintText:
                                    'Deja un comentario sobre tu viaje (opcional)...',
                                hintStyle: TextStyle(
                                    color: ConstantColors.textSubtle,
                                    fontSize: 13),
                                filled: true,
                                fillColor: ConstantColors.backgroundDark,
                                counterStyle: TextStyle(
                                    color: ConstantColors.textSubtle),
                                border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(14),
                                    borderSide: BorderSide(
                                        color: ConstantColors.borderColor)),
                                enabledBorder: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(14),
                                    borderSide: BorderSide(
                                        color: ConstantColors.borderColor)),
                                focusedBorder: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(14),
                                    borderSide: BorderSide(
                                        color: ConstantColors.primaryViolet)),
                                contentPadding: EdgeInsets.all(14),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    SizedBox(height: 32),
                  ],

                  // ── Botón finalizar ───────────────────────────────────
                  GestureDetector(
                    onTap: _guardando || !_pagoConcluido
                        ? null
                        : () => _finalizar(args),
                    child: AnimatedContainer(
                      duration: Duration(milliseconds: 200),
                      width: double.infinity,
                      height: 56,
                      decoration: BoxDecoration(
                        gradient: _pagoConcluido
                            ? LinearGradient(
                                colors: [
                                  ConstantColors.primaryViolet,
                                  ConstantColors.primaryBlue,
                                ],
                                begin: Alignment.centerLeft,
                                end: Alignment.centerRight,
                              )
                            : LinearGradient(
                                colors: [Color(0xFF2D2D5E), Color(0xFF2D2D5E)],
                                begin: Alignment.centerLeft,
                                end: Alignment.centerRight,
                              ),
                        borderRadius: BorderRadius.circular(16),
                        boxShadow: _pagoConcluido
                            ? [
                                BoxShadow(
                                  color: ConstantColors.primaryViolet
                                      .withOpacity(0.4),
                                  blurRadius: 20,
                                  offset: Offset(0, 8),
                                )
                              ]
                            : [],
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
                                _pagoConcluido
                                    ? (_calificacion > 0
                                        ? 'Enviar calificación'
                                        : 'Finalizar')
                                    : 'Selecciona método de pago',
                                style: TextStyle(
                                  color: _pagoConcluido
                                      ? Colors.white
                                      : ConstantColors.textSubtle,
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
      ),
    );
  }

  // ─── CARD DE PAGO ────────────────────────────────────────────────────────
  Widget _buildPaymentCard(Map<String, dynamic> args) {
    return Container(
      padding: EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(
          color: _pagoConcluido
              ? ConstantColors.success.withOpacity(0.4)
              : ConstantColors.borderColor,
          width: _pagoConcluido ? 1.5 : 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header
          Row(
            children: [
              Icon(
                _pagoConcluido
                    ? Icons.check_circle_rounded
                    : Icons.payment_rounded,
                color: _pagoConcluido
                    ? ConstantColors.success
                    : ConstantColors.primaryViolet,
                size: 20,
              ),
              SizedBox(width: 8),
              Text(
                '¿Cómo deseas pagar?',
                style: TextStyle(
                  color: ConstantColors.textWhite,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
              Spacer(),
              // Badge de monto
              Container(
                padding: EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: ConstantColors.primaryViolet.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  '\$${(args['precio'] as num?)?.toStringAsFixed(2) ?? '0.00'}',
                  style: TextStyle(
                    color: ConstantColors.primaryViolet,
                    fontSize: 13,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),

          SizedBox(height: 16),

          // ── Si ya pagó: mostrar confirmación ──────────────────────────
          if (_pagoConcluido) ...[
            Container(
              padding: EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: ConstantColors.success.withOpacity(0.1),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                    color: ConstantColors.success.withOpacity(0.3)),
              ),
              child: Row(
                children: [
                  Icon(Icons.check_circle_rounded,
                      color: ConstantColors.success, size: 22),
                  SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _paymentStatus == 'cash'
                              ? 'Pago en efectivo registrado'
                              : '¡Pago con PayPhone aprobado!',
                          style: TextStyle(
                            color: ConstantColors.success,
                            fontSize: 14,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        if (_transaccionGuardada != null)
                          Text(
                            'ID: ${_transaccionGuardada!.clientTransactionId.split('-').last}',
                            style: TextStyle(
                                color: ConstantColors.textSubtle,
                                fontSize: 11),
                          ),
                      ],
                    ),
                  ),
                  // Ícono del método
                  if (_paymentStatus == 'payphone_approved')
                    Container(
                      width: 32,
                      height: 32,
                      decoration: BoxDecoration(
                        color: Color(0xFF00A6B4).withOpacity(0.2),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Center(
                        child: Text('P',
                            style: TextStyle(
                                color: Color(0xFF00A6B4),
                                fontWeight: FontWeight.w900,
                                fontSize: 16)),
                      ),
                    )
                  else
                    Icon(Icons.payments_rounded,
                        color: ConstantColors.warning, size: 26),
                ],
              ),
            ),
          ]

          // ── Si no ha pagado: mostrar opciones ─────────────────────────
          else ...[
            // Error de PayPhone (si lo hubo)
            if (_payphoneError != null) ...[
              Container(
                padding: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                margin: EdgeInsets.only(bottom: 12),
                decoration: BoxDecoration(
                  color: ConstantColors.error.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(
                      color: ConstantColors.error.withOpacity(0.3)),
                ),
                child: Row(
                  children: [
                    Icon(Icons.error_outline_rounded,
                        color: ConstantColors.error, size: 16),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _payphoneError!,
                        style: TextStyle(
                            color: ConstantColors.error, fontSize: 12),
                      ),
                    ),
                  ],
                ),
              ),
            ],

            // Botón EFECTIVO
            _buildPaymentButton(
              icon: Icons.payments_rounded,
              color: ConstantColors.warning,
              title: 'Pagar en efectivo',
              subtitle: 'Entrega el dinero directamente al conductor',
              onTap: () => _elegirEfectivo(args),
            ),

            SizedBox(height: 10),

            // Botón PAYPHONE
            _buildPaymentButton(
              icon: null,
              color: Color(0xFF00A6B4),
              title: 'Pagar con PayPhone',
              subtitle: 'Tarjeta de crédito / débito de forma segura',
              payPhoneLogo: true,
              isLoading: _procesandoPago,
              onTap: _procesandoPago ? null : () => _iniciarPayPhone(args),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildPaymentButton({
    required IconData? icon,
    required Color color,
    required String title,
    required String subtitle,
    bool payPhoneLogo = false,
    bool isLoading = false,
    VoidCallback? onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: Duration(milliseconds: 150),
        padding: EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: color.withOpacity(0.08),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: color.withOpacity(0.3)),
        ),
        child: Row(
          children: [
            // Ícono o logo PayPhone
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                color: color.withOpacity(0.15),
                borderRadius: BorderRadius.circular(10),
              ),
              child: isLoading
                  ? Center(
                      child: SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          valueColor:
                              AlwaysStoppedAnimation<Color>(color),
                        ),
                      ),
                    )
                  : payPhoneLogo
                      ? Center(
                          child: Text('P',
                              style: TextStyle(
                                color: color,
                                fontWeight: FontWeight.w900,
                                fontSize: 22,
                              )),
                        )
                      : Icon(icon!, color: color, size: 22),
            ),
            SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 14,
                          fontWeight: FontWeight.w700)),
                  SizedBox(height: 3),
                  Text(subtitle,
                      style: TextStyle(
                          color: ConstantColors.textGrey, fontSize: 12)),
                ],
              ),
            ),
            Icon(Icons.chevron_right_rounded,
                color: color.withOpacity(0.7), size: 20),
          ],
        ),
      ),
    );
  }

  // ─── Helpers de UI ───────────────────────────────────────────────────────
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
              style: TextStyle(
                  color: ConstantColors.textGrey, fontSize: 13)),
          Text('\$${monto.toStringAsFixed(2)}',
              style: TextStyle(
                  color: ConstantColors.textWhite, fontSize: 13)),
        ],
      ),
    );
  }

  Widget _buildStat(IconData icon, String value, String label,
      {bool highlight = false}) {
    return Column(
      children: [
        Icon(icon,
            color: highlight
                ? ConstantColors.primaryViolet
                : ConstantColors.textGrey,
            size: 20),
        SizedBox(height: 6),
        Text(value,
            style: TextStyle(
              color: highlight
                  ? ConstantColors.primaryViolet
                  : ConstantColors.textWhite,
              fontSize: 16,
              fontWeight: FontWeight.w700,
            )),
        SizedBox(height: 2),
        Text(label,
            style: TextStyle(
                color: ConstantColors.textSubtle, fontSize: 11)),
      ],
    );
  }

  Widget _buildDividerVertical() {
    return Container(
        width: 1, height: 40, color: ConstantColors.dividerColor);
  }
}
