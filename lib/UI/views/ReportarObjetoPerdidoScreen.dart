import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';

class ReportarObjetoPerdidoScreen extends StatefulWidget {
  static const String route = '/reportar_objeto_perdido';

  final int viajeId;
  final String origen;
  final String destino;
  final String conductorNombre;
  final String conductorPlaca;

  const ReportarObjetoPerdidoScreen({
    Key? key,
    required this.viajeId,
    required this.origen,
    required this.destino,
    required this.conductorNombre,
    required this.conductorPlaca,
  }) : super(key: key);

  @override
  State<ReportarObjetoPerdidoScreen> createState() =>
      _ReportarObjetoPerdidoScreenState();
}

class _ReportarObjetoPerdidoScreenState
    extends State<ReportarObjetoPerdidoScreen> {
  final _formKey = GlobalKey<FormState>();
  final _descripcionCtrl = TextEditingController();
  bool _enviando = false;
  bool _enviado = false;
  int? _ticketId;

  @override
  void dispose() {
    _descripcionCtrl.dispose();
    super.dispose();
  }

  Future<void> _enviarReporte() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _enviando = true);

    try {
      final telefono = await AuthPrefs.getUserPhone();
      final uri = Uri.parse(
          '${Constants.apiBaseUrl}/reportar_objeto_perdido.php');

      final response = await http.post(uri, body: {
        'telefono': telefono,
        'viaje_id': widget.viajeId.toString(),
        'descripcion': _descripcionCtrl.text.trim(),
      });

      final data = jsonDecode(response.body);

      if (data['status'] == 'success') {
        setState(() {
          _enviado = true;
          _ticketId = data['ticket_id'];
        });
      } else {
        _mostrarError(data['message'] ?? 'Error al enviar el reporte');
      }
    } catch (e) {
      _mostrarError('Error de conexión. Intenta de nuevo.');
    } finally {
      if (mounted) setState(() => _enviando = false);
    }
  }

  void _mostrarError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: Colors.redAccent,
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: Colors.white),
          onPressed: () => Navigator.pop(context),
        ),
        title: const Text(
          'Objeto perdido',
          style: TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      body: _enviado ? _buildExito() : _buildFormulario(),
    );
  }

  // ── Pantalla de éxito ──────────────────────────────────────────────
  Widget _buildExito() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 100,
              height: 100,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.green.withOpacity(0.15),
              ),
              child: const Icon(
                Icons.check_circle_rounded,
                color: Colors.greenAccent,
                size: 54,
              ),
            ),
            const SizedBox(height: 24),
            const Text(
              '¡Reporte enviado!',
              style: TextStyle(
                color: Colors.white,
                fontSize: 22,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 12),
            Text(
              _ticketId != null
                  ? 'Número de reporte: #$_ticketId\n\nRevisaremos tu caso y te contactaremos pronto. También recibirás un email de confirmación.'
                  : 'Revisaremos tu caso y te contactaremos pronto.',
              style: TextStyle(
                color: ConstantColors.textGrey,
                fontSize: 15,
                height: 1.6,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 36),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: () => Navigator.pop(context),
                style: ElevatedButton.styleFrom(
                  backgroundColor: ConstantColors.primaryViolet,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                ),
                child: const Text(
                  'Volver al historial',
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                    fontSize: 15,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Formulario ────────────────────────────────────────────────────
  Widget _buildFormulario() {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Info del viaje
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: ConstantColors.backgroundCard,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: ConstantColors.borderColor),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Viaje #${widget.viajeId}',
                    style: TextStyle(
                      color: ConstantColors.textGrey,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 12),
                  _buildRutaRow(
                    Icons.circle,
                    ConstantColors.primaryBlue,
                    widget.origen,
                  ),
                  Padding(
                    padding: const EdgeInsets.only(left: 4),
                    child: Container(
                      width: 1,
                      height: 16,
                      color: ConstantColors.borderColor,
                    ),
                  ),
                  _buildRutaRow(
                    Icons.location_on_rounded,
                    ConstantColors.primaryViolet,
                    widget.destino,
                  ),
                  const SizedBox(height: 12),
                  Divider(color: ConstantColors.dividerColor),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Icon(Icons.person_rounded,
                          size: 14, color: ConstantColors.textGrey),
                      const SizedBox(width: 6),
                      Text(
                        widget.conductorNombre,
                        style: TextStyle(
                          color: ConstantColors.textGrey,
                          fontSize: 13,
                        ),
                      ),
                      const SizedBox(width: 14),
                      Icon(Icons.directions_car_rounded,
                          size: 14, color: ConstantColors.textGrey),
                      const SizedBox(width: 6),
                      Text(
                        widget.conductorPlaca,
                        style: TextStyle(
                          color: ConstantColors.textGrey,
                          fontSize: 13,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            const SizedBox(height: 28),

            // Instrucciones
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.amber.withOpacity(0.08),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.amber.withOpacity(0.25)),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.info_outline_rounded,
                      color: Colors.amber, size: 20),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Describe el objeto que olvidaste con el mayor detalle posible (color, marca, tipo). Recibirás confirmación por email y el equipo de soporte se contactará contigo.',
                      style: TextStyle(
                        color: Colors.amber.shade200,
                        fontSize: 13,
                        height: 1.5,
                      ),
                    ),
                  ),
                ],
              ),
            ),

            const SizedBox(height: 24),

            // Campo descripción
            Text(
              'Descripción del objeto',
              style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 15,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 10),
            TextFormField(
              controller: _descripcionCtrl,
              maxLines: 5,
              maxLength: 500,
              style: const TextStyle(color: Colors.white),
              decoration: InputDecoration(
                hintText:
                    'Ej: Mochila negra con laptop adentro, mochila marca Totto...',
                hintStyle: TextStyle(
                  color: ConstantColors.textSubtle,
                  fontSize: 14,
                ),
                filled: true,
                fillColor: ConstantColors.backgroundCard,
                counterStyle:
                    TextStyle(color: ConstantColors.textGrey),
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
                  borderSide: BorderSide(
                    color: ConstantColors.primaryViolet,
                    width: 1.5,
                  ),
                ),
                contentPadding: const EdgeInsets.all(16),
              ),
              validator: (v) {
                if (v == null || v.trim().isEmpty) {
                  return 'Por favor describe el objeto perdido';
                }
                if (v.trim().length < 10) {
                  return 'Describe con más detalle (mínimo 10 caracteres)';
                }
                return null;
              },
            ),

            const SizedBox(height: 32),

            // Botón enviar
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: _enviando ? null : _enviarReporte,
                style: ElevatedButton.styleFrom(
                  backgroundColor: ConstantColors.primaryViolet,
                  disabledBackgroundColor:
                      ConstantColors.primaryViolet.withOpacity(0.5),
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                ),
                child: _enviando
                    ? const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(
                          color: Colors.white,
                          strokeWidth: 2.5,
                        ),
                      )
                    : const Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.search_rounded,
                              color: Colors.white, size: 20),
                          SizedBox(width: 8),
                          Text(
                            'Enviar reporte',
                            style: TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w700,
                              fontSize: 15,
                            ),
                          ),
                        ],
                      ),
              ),
            ),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }

  Widget _buildRutaRow(IconData icon, Color color, String texto) {
    return Row(
      children: [
        Icon(icon, size: 10, color: color),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            texto,
            style: TextStyle(
              color: ConstantColors.textWhite,
              fontSize: 13,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }
}
