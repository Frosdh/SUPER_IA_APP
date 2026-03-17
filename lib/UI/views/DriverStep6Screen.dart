import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/DriverRegistrationData.dart';
import 'package:fu_uber/Core/Networking/ApiProvider.dart';
import 'package:fu_uber/UI/views/DriverWaitingScreen.dart';

class DriverStep6Screen extends StatefulWidget {
  final DriverRegistrationData data;
  const DriverStep6Screen({Key? key, required this.data}) : super(key: key);

  @override
  _DriverStep6ScreenState createState() => _DriverStep6ScreenState();
}

class _DriverStep6ScreenState extends State<DriverStep6Screen> {
  final _api = ApiProvider();
  bool _acepta    = false;
  bool _loading   = false;
  String _etapa   = '';

  void _showMsg(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
  }

  Future<String?> _fileToBase64(File? f) async {
    if (f == null) return null;
    final bytes = await f.readAsBytes();
    return base64Encode(bytes);
  }

  Future<void> _submit() async {
    if (!_acepta) {
      _showMsg('Debes aceptar la verificación de antecedentes para continuar');
      return;
    }

    setState(() { _loading = true; _etapa = 'Registrando datos personales…'; });

    // ── 1) Registrar conductor ────────────────────────────────
    final d = widget.data;
    final regResp = await _api.registerDriver(
      nombre:      d.nombre,
      email:       d.email,
      telefono:    d.telefono,
      cedula:      d.cedula,
      password:    d.password,
      ciudad:      d.ciudad,
      marca:       d.marca,
      modelo:      d.modelo,
      placa:       d.placa,
      color:       d.color,
      anio:        d.anio,
      categoriaId: d.categoriaId,
    );

    if (regResp['status'] != 'success') {
      setState(() { _loading = false; _etapa = ''; });
      _showMsg(regResp['message']?.toString() ?? 'Error al registrar');
      return;
    }

    final conductorId = regResp['conductor_id'] as int;

    // ── 2) Subir documentos ───────────────────────────────────
    final docs = <String, File?>{
      'licencia_frente':  d.licenciaFrente,
      'licencia_reverso': d.licenciaReverso,
      'cedula':           d.fotoCedula,
      'soat':             d.fotoSoat,
      'matricula':        d.fotoMatricula,
    };

    for (final entry in docs.entries) {
      if (entry.value == null) continue;
      setState(() => _etapa = 'Subiendo ${_nombreDoc(entry.key)}…');
      final b64 = await _fileToBase64(entry.value);
      if (b64 == null) continue;
      await _api.uploadDocumentoConductor(
        conductorId:   conductorId,
        tipo:          entry.key,
        imagenBase64:  b64,
      );
    }

    // ── 3) Subir foto de perfil ───────────────────────────────
    if (d.fotoPerfil != null) {
      setState(() => _etapa = 'Subiendo foto de perfil…');
      final b64 = await _fileToBase64(d.fotoPerfil);
      if (b64 != null) {
        await _api.uploadDocumentoConductor(
          conductorId:  conductorId,
          tipo:         'foto_perfil',
          imagenBase64: b64,
        );
      }
    }

    setState(() { _loading = false; _etapa = ''; });

    // ── 4) Ir a pantalla de espera ────────────────────────────
    Navigator.pushAndRemoveUntil(
      context,
      MaterialPageRoute(builder: (_) => DriverWaitingScreen(conductorId: conductorId)),
      (_) => false,
    );
  }

  String _nombreDoc(String tipo) {
    const map = {
      'licencia_frente':  'licencia (frente)',
      'licencia_reverso': 'licencia (reverso)',
      'cedula':           'cédula',
      'soat':             'SOAT',
      'matricula':        'matrícula',
    };
    return map[tipo] ?? tipo;
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
        children: [
          Positioned(top: 0, left: 0, right: 0,
            child: Container(height: size.height * 0.28,
              decoration: const BoxDecoration(
                gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight,
                  colors: [Color(0xFF0F0C29), Color(0xFF302B63), Color(0xFF24243E)]),
              ),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 4, 16, 0),
                  child: Row(children: [
                    IconButton(icon: const Icon(Icons.arrow_back, color: Colors.white),
                      onPressed: _loading ? null : () => Navigator.pop(context)),
                    const Text('Registro de Conductor',
                      style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700)),
                  ]),
                ),
                _buildProgress(paso: 6, total: 6, label: 'Verificación final'),
                Expanded(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(24, 8, 24, 32),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const SizedBox(height: 12),

                        // Ícono central
                        Center(
                          child: Container(
                            width: 90, height: 90,
                            decoration: BoxDecoration(
                              gradient: ConstantColors.buttonGradient,
                              shape: BoxShape.circle,
                              boxShadow: [BoxShadow(
                                color: ConstantColors.primaryViolet.withOpacity(0.35),
                                blurRadius: 28, offset: const Offset(0, 10),
                              )],
                            ),
                            child: const Icon(Icons.policy_outlined, color: Colors.white, size: 42),
                          ),
                        ),
                        const SizedBox(height: 20),

                        const Text('Verificación de antecedentes',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 8),
                        Text(
                          'Para garantizar la seguridad de todos los usuarios, realizamos una verificación de antecedentes a todos los conductores.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white.withOpacity(0.6), fontSize: 14, height: 1.5),
                        ),

                        const SizedBox(height: 24),

                        // Qué incluye
                        Container(
                          padding: const EdgeInsets.all(20),
                          decoration: BoxDecoration(
                            color: Colors.black.withOpacity(0.22),
                            borderRadius: BorderRadius.circular(20),
                            border: Border.all(color: ConstantColors.borderColor.withOpacity(0.7)),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text('¿Qué incluye la verificación?',
                                style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700)),
                              const SizedBox(height: 14),
                              _checkItem('Historial judicial y penal'),
                              _checkItem('Validez de la licencia de conducir'),
                              _checkItem('Autenticidad de los documentos enviados'),
                              _checkItem('Registro vehicular y estado del SOAT'),
                            ],
                          ),
                        ),

                        const SizedBox(height: 20),

                        // Resumen de datos
                        Container(
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            color: ConstantColors.primaryBlue.withOpacity(0.08),
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(color: ConstantColors.primaryBlue.withOpacity(0.25)),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(children: [
                                Icon(Icons.summarize_outlined, color: ConstantColors.primaryBlue, size: 18),
                                const SizedBox(width: 8),
                                const Text('Resumen de tu registro',
                                  style: TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w700)),
                              ]),
                              const SizedBox(height: 10),
                              _resumenItem('Conductor', widget.data.nombre),
                              _resumenItem('Ciudad', widget.data.ciudad),
                              _resumenItem('Vehículo',
                                '${widget.data.marca} ${widget.data.modelo} — ${widget.data.placa}'),
                              _resumenItem('Documentos', '${widget.data.documentosCompletos ? "5/5 ✓" : "Incompletos"}'),
                            ],
                          ),
                        ),

                        const SizedBox(height: 20),

                        // Checkbox aceptar
                        GestureDetector(
                          onTap: () => setState(() => _acepta = !_acepta),
                          child: Container(
                            padding: const EdgeInsets.all(16),
                            decoration: BoxDecoration(
                              color: _acepta
                                  ? Colors.greenAccent.withOpacity(0.07)
                                  : Colors.black.withOpacity(0.22),
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(
                                color: _acepta
                                    ? Colors.greenAccent.withOpacity(0.4)
                                    : ConstantColors.borderColor.withOpacity(0.7),
                              ),
                            ),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                AnimatedContainer(
                                  duration: const Duration(milliseconds: 200),
                                  width: 24, height: 24,
                                  decoration: BoxDecoration(
                                    gradient: _acepta ? ConstantColors.buttonGradient : null,
                                    color: _acepta ? null : Colors.transparent,
                                    borderRadius: BorderRadius.circular(6),
                                    border: Border.all(
                                      color: _acepta ? Colors.transparent : ConstantColors.textGrey,
                                      width: 2,
                                    ),
                                  ),
                                  child: _acepta
                                      ? const Icon(Icons.check, color: Colors.white, size: 16)
                                      : null,
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Text(
                                    'Acepto que GeoMove realice una verificación de mis antecedentes penales, judiciales y documentales para activar mi cuenta.',
                                    style: TextStyle(
                                      color: Colors.white.withOpacity(0.85),
                                      fontSize: 13, height: 1.45,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),

                        const SizedBox(height: 28),

                        // Botón enviar
                        if (_loading) ...[
                          Container(
                            padding: const EdgeInsets.all(20),
                            decoration: BoxDecoration(
                              color: Colors.black.withOpacity(0.22),
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(color: ConstantColors.borderColor.withOpacity(0.5)),
                            ),
                            child: Column(children: [
                              const CircularProgressIndicator(
                                strokeWidth: 3,
                                valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                              ),
                              const SizedBox(height: 14),
                              Text(_etapa,
                                textAlign: TextAlign.center,
                                style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w600)),
                              const SizedBox(height: 4),
                              Text('Por favor no cierres la app',
                                style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
                            ]),
                          ),
                        ] else
                          SizedBox(
                            width: double.infinity, height: 54,
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                gradient: _acepta ? ConstantColors.buttonGradient : null,
                                color: _acepta ? null : Colors.white.withOpacity(0.08),
                                borderRadius: BorderRadius.circular(16),
                                boxShadow: _acepta ? [BoxShadow(
                                  color: ConstantColors.primaryViolet.withOpacity(0.28),
                                  blurRadius: 20, offset: const Offset(0, 8),
                                )] : [],
                              ),
                              child: ElevatedButton(
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: Colors.transparent, shadowColor: Colors.transparent,
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                                ),
                                onPressed: _acepta ? _submit : null,
                                child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                                  Icon(Icons.check_circle_outline, color: _acepta ? Colors.white : ConstantColors.textGrey, size: 18),
                                  const SizedBox(width: 8),
                                  Text('Finalizar Registro',
                                    style: TextStyle(
                                      color: _acepta ? Colors.white : ConstantColors.textGrey,
                                      fontSize: 15, fontWeight: FontWeight.w700,
                                    )),
                                ]),
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _checkItem(String texto) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Row(children: [
      Icon(Icons.check_circle_outline, color: ConstantColors.primaryBlue, size: 18),
      const SizedBox(width: 10),
      Text(texto, style: TextStyle(color: Colors.white.withOpacity(0.85), fontSize: 13)),
    ]),
  );

  Widget _resumenItem(String label, String valor) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Row(children: [
      Text('$label: ', style: const TextStyle(color: ConstantColors.textGrey, fontSize: 13)),
      Expanded(child: Text(valor,
        style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600))),
    ]),
  );
}

Widget _buildProgress({required int paso, required int total, required String label}) {
  return Padding(
    padding: const EdgeInsets.fromLTRB(24, 12, 24, 12),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text('Paso $paso de $total — $label',
          style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
        Text('${((paso / total) * 100).round()}%',
          style: TextStyle(color: ConstantColors.primaryBlue, fontSize: 13, fontWeight: FontWeight.w700)),
      ]),
      const SizedBox(height: 8),
      ClipRRect(
        borderRadius: BorderRadius.circular(6),
        child: LinearProgressIndicator(
          value: paso / total, minHeight: 6,
          backgroundColor: Colors.white.withOpacity(0.12),
          valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryBlue),
        ),
      ),
    ]),
  );
}
