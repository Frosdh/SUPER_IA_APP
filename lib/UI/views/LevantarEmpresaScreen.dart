// ============================================================
// LevantarEmpresaScreen.dart
// Pantalla para buscar un prospecto por nombre de empresa
// y luego lanzar el levantamiento completo de la empresa.
// ============================================================

import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/UI/views/NuevaEncuestaScreen.dart';

class LevantarEmpresaScreen extends StatefulWidget {
  static const String route = '/levantar-empresa';

  const LevantarEmpresaScreen({Key? key}) : super(key: key);

  @override
  State<LevantarEmpresaScreen> createState() => _LevantarEmpresaScreenState();
}

class _LevantarEmpresaScreenState extends State<LevantarEmpresaScreen> {
  final TextEditingController _buscarCtrl = TextEditingController();
  final FocusNode _buscarFocus = FocusNode();

  bool _cargando = false;
  String? _error;
  List<Map<String, dynamic>> _resultados = [];
  bool _buscado = false;

  @override
  void dispose() {
    _buscarCtrl.dispose();
    _buscarFocus.dispose();
    super.dispose();
  }

  // ── Buscar por nombre de empresa ─────────────────────────────
  Future<void> _buscar() async {
    final texto = _buscarCtrl.text.trim();
    if (texto.isEmpty) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _cargando = true;
      _error    = null;
      _resultados = [];
      _buscado  = false;
    });

    try {
      final resp = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/buscar_cliente_por_empresa.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {'nombre_empresa': texto, 'limit': '20'},
      ).timeout(const Duration(seconds: 12));

      final data = json.decode(resp.body) as Map<String, dynamic>;
      if (data['status'] == 'success') {
        final items = (data['items'] as List?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList() ?? [];
        setState(() { _resultados = items; _buscado = true; });
      } else {
        setState(() { _error = data['message']?.toString() ?? 'Error al buscar'; _buscado = true; });
      }
    } catch (e) {
      setState(() { _error = 'No se pudo conectar al servidor. ($e)'; _buscado = true; });
    } finally {
      setState(() => _cargando = false);
    }
  }

  // ── Abrir levantamiento del prospecto seleccionado ──────────
  Future<void> _abrirLevantamiento(Map<String, dynamic> cliente) async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => NuevaEncuestaScreen(
          tipoTarea: 'levantamiento',
          incluirEmpresa: true,
          initialData: {
            'nombre':        cliente['nombre'] ?? '',
            'cedula':        cliente['cedula'] ?? '',
            'telefono':      cliente['telefono'] ?? '',
            'celular':       cliente['celular'] ?? '',
            'email':         cliente['email'] ?? '',
            'direccion':     cliente['direccion'] ?? '',
            'ciudad':        cliente['ciudad'] ?? '',
            'nombre_empresa': cliente['nombre_empresa'] ?? '',
            'es_cliente':    '1',
          },
        ),
      ),
    );
    // Al volver, recargar la búsqueda para reflejar si ya tiene encuesta
    if (mounted && _buscarCtrl.text.trim().isNotEmpty) _buscar();
  }

  // ── UI ────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.grey100,
      appBar: AppBar(
        backgroundColor: ConstantColors.primaryBlue,
        elevation: 0,
        title: const Text(
          'Levantar Empresa',
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 18),
        ),
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: Column(
        children: [
          // ── Cabecera de búsqueda ──────────────────────────────
          Container(
            color: ConstantColors.primaryBlue,
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Busca al prospecto por el nombre de su empresa para completar el levantamiento.',
                  style: TextStyle(color: Colors.white70, fontSize: 13),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _buscarCtrl,
                        focusNode: _buscarFocus,
                        textInputAction: TextInputAction.search,
                        onSubmitted: (_) => _buscar(),
                        style: const TextStyle(color: Colors.black87, fontSize: 15),
                        decoration: InputDecoration(
                          hintText: 'Nombre de empresa…',
                          hintStyle: TextStyle(color: Colors.grey.shade500),
                          filled: true,
                          fillColor: Colors.white,
                          prefixIcon: const Icon(Icons.store_rounded, color: Colors.blueGrey),
                          contentPadding: const EdgeInsets.symmetric(vertical: 14, horizontal: 14),
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: BorderSide.none,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Material(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(12),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(12),
                        onTap: _cargando ? null : _buscar,
                        child: Container(
                          padding: const EdgeInsets.all(14),
                          child: _cargando
                              ? SizedBox(
                                  width: 22,
                                  height: 22,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2.5,
                                    color: ConstantColors.primaryBlue,
                                  ),
                                )
                              : Icon(Icons.search_rounded,
                                  color: ConstantColors.primaryBlue, size: 26),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),

          // ── Cuerpo: lista de resultados ───────────────────────
          Expanded(
            child: _buildCuerpo(),
          ),
        ],
      ),
    );
  }

  Widget _buildCuerpo() {
    if (_cargando) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.error_outline_rounded, color: Colors.red.shade400, size: 48),
              const SizedBox(height: 12),
              Text(_error!, textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.red.shade700, fontSize: 14)),
              const SizedBox(height: 16),
              ElevatedButton.icon(
                onPressed: _buscar,
                icon: const Icon(Icons.refresh_rounded),
                label: const Text('Reintentar'),
              ),
            ],
          ),
        ),
      );
    }

    if (!_buscado) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(40),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.business_center_rounded,
                  size: 72, color: ConstantColors.primaryBlue.withOpacity(0.25)),
              const SizedBox(height: 20),
              Text(
                'Ingresa el nombre de la empresa\ny presiona buscar.',
                textAlign: TextAlign.center,
                style: TextStyle(
                    color: ConstantColors.textDarkGrey, fontSize: 15, height: 1.5),
              ),
            ],
          ),
        ),
      );
    }

    if (_resultados.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(40),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.search_off_rounded, size: 64, color: Colors.grey.shade400),
              const SizedBox(height: 16),
              Text(
                'No se encontraron prospectos\ncon esa empresa.',
                textAlign: TextAlign.center,
                style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 15),
              ),
            ],
          ),
        ),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      itemCount: _resultados.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, i) => _buildTarjeta(_resultados[i]),
    );
  }

  Widget _buildTarjeta(Map<String, dynamic> c) {
    final nombre   = (c['nombre'] ?? '').toString();
    final cedula   = (c['cedula'] ?? '').toString();
    final empresa  = (c['nombre_empresa'] ?? '').toString();
    final ciudad   = (c['ciudad'] ?? '').toString();
    final tieneEnc = c['encuesta_negocio'] != null;

    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      elevation: 1.5,
      shadowColor: Colors.black12,
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => _abrirLevantamiento(c),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              // ─ Icono empresa ─
              Container(
                width: 50,
                height: 50,
                decoration: BoxDecoration(
                  color: ConstantColors.primaryBlue.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(Icons.store_rounded,
                    color: ConstantColors.primaryBlue, size: 26),
              ),
              const SizedBox(width: 14),

              // ─ Datos ─
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      empresa.isNotEmpty ? empresa : '(Sin nombre de empresa)',
                      style: const TextStyle(
                          fontWeight: FontWeight.w700, fontSize: 15),
                    ),
                    const SizedBox(height: 3),
                    Row(
                      children: [
                        Icon(Icons.person_rounded, size: 14,
                            color: ConstantColors.textDarkGrey),
                        const SizedBox(width: 4),
                        Expanded(
                          child: Text(
                            nombre,
                            style: TextStyle(
                                color: ConstantColors.textDarkGrey, fontSize: 13),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                    if (cedula.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          Icon(Icons.badge_rounded, size: 14,
                              color: ConstantColors.textDarkGrey),
                          const SizedBox(width: 4),
                          Text(cedula,
                              style: TextStyle(
                                  color: ConstantColors.textDarkGrey, fontSize: 13)),
                        ],
                      ),
                    ],
                    if (ciudad.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          Icon(Icons.location_on_rounded, size: 14,
                              color: ConstantColors.textDarkGrey),
                          const SizedBox(width: 4),
                          Text(ciudad,
                              style: TextStyle(
                                  color: ConstantColors.textDarkGrey, fontSize: 12)),
                        ],
                      ),
                    ],
                    const SizedBox(height: 6),
                    // ─ Badge estado ─
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: tieneEnc
                            ? Colors.green.shade50
                            : Colors.orange.shade50,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(
                          color: tieneEnc
                              ? Colors.green.shade300
                              : Colors.orange.shade300,
                        ),
                      ),
                      child: Text(
                        tieneEnc
                            ? '✓ Levantamiento en proceso'
                            : '⚠ Pendiente de levantamiento',
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                          color: tieneEnc
                              ? Colors.green.shade800
                              : Colors.orange.shade800,
                        ),
                      ),
                    ),
                  ],
                ),
              ),

              // ─ Flecha ─
              Icon(Icons.chevron_right_rounded,
                  color: ConstantColors.primaryBlue, size: 26),
            ],
          ),
        ),
      ),
    );
  }
}
