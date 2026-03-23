import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:url_launcher/url_launcher.dart';

/// Pantalla de recibo/factura de un viaje completado.
/// Recibe los datos del viaje vía arguments de Navigator.
///
/// Args esperados (Map<String, dynamic>):
///   viaje_id, origen, destino, distancia_km, duracion_min, precio,
///   conductor_nombre, conductor_auto, conductor_placa, fecha,
///   descuento, codigo_descuento, tarifa_base, precio_km, precio_minuto,
///   categoria_nombre, metodo_pago ('Efectivo' | 'PayPhone'), client_transaction_id
class ReceiptScreen extends StatelessWidget {
  static const String route = '/receipt';

  const ReceiptScreen({super.key});

  // ─── Texto del recibo para compartir ────────────────────────────────────
  String _buildReceiptText(Map<String, dynamic> args) {
    final double precio     = (args['precio'] as num?)?.toDouble() ?? 0;
    final double distancia  = (args['distancia_km'] as num?)?.toDouble() ?? 0;
    final int    duracion   = (args['duracion_min'] as num?)?.toInt() ?? 0;
    final double descuento  = (args['descuento'] as num?)?.toDouble() ?? 0;
    final String conductor  = args['conductor_nombre'] as String? ?? '-';
    final String auto       = args['conductor_auto']   as String? ?? '-';
    final String placa      = args['conductor_placa']  as String? ?? '-';
    final String metodo     = args['metodo_pago']      as String? ?? 'Efectivo';
    final String txnId      = args['client_transaction_id'] as String? ?? '-';

    return '''
🚗 *Recibo de Viaje GeoMove*
────────────────────────
📍 Origen: ${args['origen'] ?? ''}
📍 Destino: ${args['destino'] ?? ''}

📏 Distancia: ${distancia.toStringAsFixed(1)} km
⏱ Duración: $duracion min
👨‍✈️ Conductor: $conductor
🚘 Vehículo: $auto · $placa

────────────────────────
💵 Total pagado: \$${precio.toStringAsFixed(2)}
${descuento > 0 ? '🎟 Descuento aplicado: -\$${descuento.toStringAsFixed(2)}\n' : ''}💳 Método de pago: $metodo
🔑 ID: $txnId
────────────────────────
Gracias por viajar con GeoMove 🙌
    '''.trim();
  }

  Future<void> _compartirWhatsApp(String texto) async {
    final encoded = Uri.encodeComponent(texto);
    final url = 'https://wa.me/?text=$encoded';
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  Future<void> _copiarAlPortapapeles(BuildContext context, String texto) async {
    await Clipboard.setData(ClipboardData(text: texto));
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Recibo copiado al portapapeles',
              style: TextStyle(color: Colors.white)),
          backgroundColor: ConstantColors.success,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          duration: Duration(seconds: 2),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final args =
        (ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?) ?? {};

    final double precio        = (args['precio'] as num?)?.toDouble() ?? 0;
    final double distancia     = (args['distancia_km'] as num?)?.toDouble() ?? 0;
    final int    duracion      = (args['duracion_min'] as num?)?.toInt() ?? 0;
    final double descuento     = (args['descuento'] as num?)?.toDouble() ?? 0;
    final String codDescuento  = args['codigo_descuento'] as String? ?? '';
    final double tarifaBase    = (args['tarifa_base'] as num?)?.toDouble() ?? 0;
    final double precioKm      = (args['precio_km'] as num?)?.toDouble() ?? 0;
    final double precioMin     = (args['precio_minuto'] as num?)?.toDouble() ?? 0;
    final String categoria     = args['categoria_nombre'] as String? ?? 'GeoMove-X';
    final String metodo        = args['metodo_pago'] as String? ?? 'Efectivo';
    final String txnId         = args['client_transaction_id'] as String? ?? '-';
    final String conductor     = args['conductor_nombre'] as String? ?? '';
    final String auto          = args['conductor_auto'] as String? ?? '';
    final String placa         = args['conductor_placa'] as String? ?? '';
    final String fecha         = args['fecha'] as String? ?? '';
    final String origen        = args['origen'] as String? ?? '';
    final String destino       = args['destino'] as String? ?? '';

    final bool isPP = metodo.toLowerCase() == 'payphone';
    final receiptText = _buildReceiptText(args);

    String fechaFormateada = fecha;
    try {
      final dt = DateTime.parse(fecha).toLocal();
      final m = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
      fechaFormateada =
          '${dt.day} ${m[dt.month-1]} ${dt.year}  ${dt.hour.toString().padLeft(2,'0')}:${dt.minute.toString().padLeft(2,'0')}';
    } catch (_) {}

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
        title: Text('Recibo del viaje',
            style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 17,
                fontWeight: FontWeight.w700)),
        actions: [
          IconButton(
            icon: Icon(Icons.copy_rounded, color: ConstantColors.textGrey),
            onPressed: () => _copiarAlPortapapeles(context, receiptText),
            tooltip: 'Copiar recibo',
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            // ── Recibo visual ────────────────────────────────────────────
            Container(
              width: double.infinity,
              decoration: BoxDecoration(
                color: ConstantColors.backgroundCard,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: ConstantColors.borderColor),
                boxShadow: [
                  BoxShadow(
                    color: ConstantColors.primaryViolet.withOpacity(0.08),
                    blurRadius: 20,
                    offset: Offset(0, 8),
                  ),
                ],
              ),
              child: Column(
                children: [
                  // Header del recibo
                  Container(
                    padding: const EdgeInsets.all(24),
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: [
                          ConstantColors.primaryViolet.withOpacity(0.15),
                          ConstantColors.primaryBlue.withOpacity(0.10),
                        ],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                      borderRadius: BorderRadius.only(
                        topLeft: Radius.circular(20),
                        topRight: Radius.circular(20),
                      ),
                    ),
                    child: Column(
                      children: [
                        // Logo GeoMove
                        Row(
                          children: [
                            Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(
                                gradient: ConstantColors.buttonGradient,
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: Center(
                                child: Text('G',
                                    style: TextStyle(
                                        color: Colors.white,
                                        fontWeight: FontWeight.w900,
                                        fontSize: 18)),
                              ),
                            ),
                            SizedBox(width: 10),
                            Text('GeoMove',
                                style: TextStyle(
                                    color: ConstantColors.textWhite,
                                    fontSize: 16,
                                    fontWeight: FontWeight.w800)),
                            Spacer(),
                            Container(
                              padding: EdgeInsets.symmetric(
                                  horizontal: 10, vertical: 4),
                              decoration: BoxDecoration(
                                color: ConstantColors.success.withOpacity(0.15),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(
                                    color: ConstantColors.success
                                        .withOpacity(0.3)),
                              ),
                              child: Text('PAGADO',
                                  style: TextStyle(
                                      color: ConstantColors.success,
                                      fontSize: 11,
                                      fontWeight: FontWeight.w800,
                                      letterSpacing: 1)),
                            ),
                          ],
                        ),
                        SizedBox(height: 20),
                        // Monto grande
                        Text(
                          '\$${precio.toStringAsFixed(2)}',
                          style: TextStyle(
                            color: ConstantColors.textWhite,
                            fontSize: 48,
                            fontWeight: FontWeight.w900,
                            letterSpacing: -2,
                          ),
                        ),
                        Text(
                          'USD',
                          style: TextStyle(
                              color: ConstantColors.textGrey, fontSize: 14),
                        ),
                        SizedBox(height: 8),
                        Text(
                          fechaFormateada,
                          style: TextStyle(
                              color: ConstantColors.textSubtle, fontSize: 12),
                        ),
                      ],
                    ),
                  ),

                  // ── Línea punteada separadora (estilo recibo) ─────────
                  _buildDottedLine(),

                  // Cuerpo del recibo
                  Padding(
                    padding: const EdgeInsets.all(20),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        // Ruta
                        _buildSection('Ruta del viaje', []),
                        SizedBox(height: 12),
                        _buildRouteRow(origen, destino),
                        SizedBox(height: 20),

                        // Conductor
                        if (conductor.isNotEmpty) ...[
                          _buildSection('Conductor', []),
                          SizedBox(height: 10),
                          Row(children: [
                            CircleAvatar(
                              radius: 20,
                              backgroundColor:
                                  ConstantColors.primaryViolet.withOpacity(0.15),
                              child: Text(
                                conductor.isNotEmpty
                                    ? conductor[0].toUpperCase()
                                    : 'C',
                                style: TextStyle(
                                    color: ConstantColors.primaryViolet,
                                    fontWeight: FontWeight.w800,
                                    fontSize: 16),
                              ),
                            ),
                            SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(conductor,
                                      style: TextStyle(
                                          color: ConstantColors.textWhite,
                                          fontWeight: FontWeight.w700,
                                          fontSize: 14)),
                                  Text('$auto  ·  $placa',
                                      style: TextStyle(
                                          color: ConstantColors.textGrey,
                                          fontSize: 12)),
                                ],
                              ),
                            ),
                          ]),
                          SizedBox(height: 20),
                        ],

                        // Stats del viaje
                        Row(
                          children: [
                            _buildStatChip(
                                Icons.straighten_rounded,
                                '${distancia.toStringAsFixed(1)} km',
                                'Distancia',
                                ConstantColors.primaryBlue),
                            SizedBox(width: 10),
                            _buildStatChip(
                                Icons.access_time_rounded,
                                '$duracion min',
                                'Duración',
                                ConstantColors.primaryViolet),
                            SizedBox(width: 10),
                            _buildStatChip(
                                Icons.local_taxi_rounded,
                                categoria,
                                'Servicio',
                                ConstantColors.warning),
                          ],
                        ),

                        SizedBox(height: 20),
                        Divider(color: ConstantColors.dividerColor),
                        SizedBox(height: 16),

                        // Desglose de precio
                        _buildSection('Desglose del cobro', []),
                        SizedBox(height: 12),
                        _buildLineItem('Tarifa base', tarifaBase),
                        _buildLineItem(
                            '${distancia.toStringAsFixed(1)} km × \$$precioKm/km',
                            precioKm * distancia),
                        _buildLineItem(
                            '$duracion min × \$$precioMin/min',
                            precioMin * duracion),
                        if (descuento > 0) ...[
                          SizedBox(height: 4),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Row(children: [
                                Icon(Icons.local_offer_rounded,
                                    color: ConstantColors.success, size: 13),
                                SizedBox(width: 6),
                                Text(
                                  codDescuento.isNotEmpty
                                      ? 'Cupón $codDescuento'
                                      : 'Descuento',
                                  style: TextStyle(
                                      color: ConstantColors.success,
                                      fontSize: 13),
                                ),
                              ]),
                              Text('-\$${descuento.toStringAsFixed(2)}',
                                  style: TextStyle(
                                      color: ConstantColors.success,
                                      fontSize: 13,
                                      fontWeight: FontWeight.w700)),
                            ],
                          ),
                        ],
                        Divider(color: ConstantColors.dividerColor, height: 20),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text('Total',
                                style: TextStyle(
                                    color: ConstantColors.textWhite,
                                    fontSize: 16,
                                    fontWeight: FontWeight.w800)),
                            Text('\$${precio.toStringAsFixed(2)}',
                                style: TextStyle(
                                    color: ConstantColors.primaryViolet,
                                    fontSize: 20,
                                    fontWeight: FontWeight.w900)),
                          ],
                        ),

                        SizedBox(height: 20),
                        Divider(color: ConstantColors.dividerColor),
                        SizedBox(height: 16),

                        // Método de pago
                        _buildSection('Información de pago', []),
                        SizedBox(height: 12),
                        Row(children: [
                          Container(
                            width: 38,
                            height: 38,
                            decoration: BoxDecoration(
                              color: (isPP
                                      ? Color(0xFF00A6B4)
                                      : ConstantColors.warning)
                                  .withOpacity(0.15),
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Center(
                              child: isPP
                                  ? Text('P',
                                      style: TextStyle(
                                          color: Color(0xFF00A6B4),
                                          fontWeight: FontWeight.w900,
                                          fontSize: 18))
                                  : Icon(Icons.payments_rounded,
                                      color: ConstantColors.warning, size: 20),
                            ),
                          ),
                          SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(metodo,
                                    style: TextStyle(
                                        color: ConstantColors.textWhite,
                                        fontWeight: FontWeight.w700,
                                        fontSize: 14)),
                                if (txnId != '-')
                                  Text('ID: $txnId',
                                      style: TextStyle(
                                          color: ConstantColors.textSubtle,
                                          fontSize: 11)),
                              ],
                            ),
                          ),
                          Container(
                            padding: EdgeInsets.symmetric(
                                horizontal: 8, vertical: 4),
                            decoration: BoxDecoration(
                              color: ConstantColors.success.withOpacity(0.12),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text('Aprobado',
                                style: TextStyle(
                                    color: ConstantColors.success,
                                    fontSize: 11,
                                    fontWeight: FontWeight.w700)),
                          ),
                        ]),
                      ],
                    ),
                  ),

                  // ── Pie del recibo ────────────────────────────────────
                  _buildDottedLine(),
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      'Gracias por viajar con GeoMove · ${DateTime.now().year}',
                      style: TextStyle(
                          color: ConstantColors.textSubtle, fontSize: 12),
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
              ),
            ),

            SizedBox(height: 24),

            // ── Botones de acción ────────────────────────────────────────
            Row(
              children: [
                // Compartir por WhatsApp
                Expanded(
                  child: _buildActionButton(
                    icon: Icons.share_rounded,
                    label: 'Compartir',
                    color: ConstantColors.accentWhatsApp,
                    onTap: () => _compartirWhatsApp(receiptText),
                  ),
                ),
                SizedBox(width: 12),
                // Copiar texto
                Expanded(
                  child: _buildActionButton(
                    icon: Icons.copy_rounded,
                    label: 'Copiar',
                    color: ConstantColors.primaryViolet,
                    onTap: () => _copiarAlPortapapeles(context, receiptText),
                  ),
                ),
              ],
            ),

            SizedBox(height: 32),
          ],
        ),
      ),
    );
  }

  // ─── Widgets helpers ─────────────────────────────────────────────────────

  Widget _buildDottedLine() {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: List.generate(30, (_) => Expanded(
          child: Container(
            height: 1.5,
            color: ConstantColors.borderColor,
            margin: EdgeInsets.symmetric(horizontal: 2),
          ),
        )),
      ),
    );
  }

  Widget _buildSection(String title, List<Widget> children) {
    return Text(
      title.toUpperCase(),
      style: TextStyle(
        color: ConstantColors.textSubtle,
        fontSize: 10,
        fontWeight: FontWeight.w700,
        letterSpacing: 1.5,
      ),
    );
  }

  Widget _buildRouteRow(String origen, String destino) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Column(children: [
          Icon(Icons.circle, size: 10, color: ConstantColors.primaryBlue),
          Container(width: 2, height: 24, color: ConstantColors.borderColor),
          Icon(Icons.location_on, size: 14, color: ConstantColors.primaryViolet),
        ]),
        SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(origen,
                style:
                    TextStyle(color: ConstantColors.textGrey, fontSize: 13),
                maxLines: 1,
                overflow: TextOverflow.ellipsis),
            SizedBox(height: 12),
            Text(destino,
                style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 14,
                    fontWeight: FontWeight.w600),
                maxLines: 1,
                overflow: TextOverflow.ellipsis),
          ]),
        ),
      ],
    );
  }

  Widget _buildStatChip(IconData icon, String val, String lbl, Color color) {
    return Expanded(
      child: Container(
        padding: EdgeInsets.symmetric(vertical: 10, horizontal: 8),
        decoration: BoxDecoration(
          color: color.withOpacity(0.08),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: color.withOpacity(0.2)),
        ),
        child: Column(children: [
          Icon(icon, color: color, size: 16),
          SizedBox(height: 4),
          Text(val,
              style: TextStyle(
                  color: ConstantColors.textWhite,
                  fontWeight: FontWeight.w700,
                  fontSize: 12),
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis),
          Text(lbl,
              style:
                  TextStyle(color: ConstantColors.textSubtle, fontSize: 10),
              textAlign: TextAlign.center),
        ]),
      ),
    );
  }

  Widget _buildLineItem(String label, double amount) {
    return Padding(
      padding: EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style: TextStyle(color: ConstantColors.textGrey, fontSize: 13)),
          Text('\$${amount.toStringAsFixed(2)}',
              style:
                  TextStyle(color: ConstantColors.textWhite, fontSize: 13)),
        ],
      ),
    );
  }

  Widget _buildActionButton({
    required IconData icon,
    required String label,
    required Color color,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        height: 54,
        decoration: BoxDecoration(
          color: color.withOpacity(0.12),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: color.withOpacity(0.35)),
        ),
        child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(icon, color: color, size: 18),
          SizedBox(width: 8),
          Text(label,
              style: TextStyle(
                  color: color, fontSize: 14, fontWeight: FontWeight.w700)),
        ]),
      ),
    );
  }
}
