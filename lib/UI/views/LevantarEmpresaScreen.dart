// ============================================================
// LevantarEmpresaScreen.dart
// Pantalla para buscar un prospecto por nombre de empresa
// y luego lanzar el levantamiento completo de la empresa.
// ============================================================

import 'dart:async';
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
  Timer? _debounce;

  // ── Filtros ──────────────────────────────────────────────────
  /// 'todos' | 'pendiente' | 'completado'
  String _filtroEstado = 'todos';
  String? _filtroCiudad;

  List<String> get _ciudadesDisponibles {
    final ciudades = _resultados
        .map((e) => (e['ciudad'] ?? '').toString().trim())
        .where((c) => c.isNotEmpty)
        .toSet()
        .toList()
      ..sort();
    return ciudades;
  }

  List<Map<String, dynamic>> get _resultadosFiltrados {
    return _resultados.where((c) {
      final tieneEnc = c['encuesta_negocio'] != null;
      if (_filtroEstado == 'pendiente'  && tieneEnc)  return false;
      if (_filtroEstado == 'completado' && !tieneEnc) return false;
      if (_filtroCiudad != null) {
        final ciudad = (c['ciudad'] ?? '').toString().trim();
        if (ciudad != _filtroCiudad) return false;
      }
      return true;
    }).toList();
  }

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
    setState(() {
      _cargando     = true;
      _error        = null;
      _resultados   = [];
      _buscado      = false;
      _filtroCiudad = null;
    });

    try {
      final resp = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/buscar_cliente_por_empresa.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {'nombre_empresa': texto, 'limit': '50'},
      ).timeout(const Duration(seconds: 12));

      final data = json.decode(resp.body) as Map<String, dynamic>;
      if (data['status'] == 'success') {
        final items = (data['items'] as List?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList() ?? [];
        setState(() { _resultados = items; _buscado = true; });
      } else {
        setState(() {
          _error   = data['message']?.toString() ?? 'Error al buscar';
          _buscado = true;
        });
      }
    } catch (e) {
      setState(() {
        _error   = 'No se pudo conectar al servidor. ($e)';
        _buscado = true;
      });
    } finally {
      setState(() => _cargando = false);
    }
  }

  void _onSearchChanged(String v) {
    // Debounce para evitar llamadas excesivas al servidor mientras escribe
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () {
      if (mounted) _buscar();
    });
  }

  // ── Abrir levantamiento del prospecto seleccionado ──────────
  Future<void> _abrirLevantamiento(Map<String, dynamic> cliente) async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => NuevaEncuestaScreen(
          tipoTarea:      'levantamiento',
          incluirEmpresa: true,
          initialData: {
            'id':               cliente['id']            ?? '',
            'nombre':          cliente['nombre']         ?? '',
            'cedula':          cliente['cedula']         ?? '',
            'telefono':        cliente['telefono']       ?? '',
            'celular':         cliente['celular']        ?? '',
            'email':           cliente['email']          ?? '',
            'direccion':       cliente['direccion']      ?? '',
            'ciudad':          cliente['ciudad']         ?? '',
            'nombre_empresa':  cliente['nombre_empresa'] ?? '',
            'es_cliente':      '1',
          },
        ),
      ),
    );
    // Al volver, recargar para reflejar cambio de estado
    if (mounted && _buscarCtrl.text.trim().isNotEmpty) _buscar();
  }

  // ── UI ────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        flexibleSpace: Container(
          decoration: const BoxDecoration(
            gradient: ConstantColors.blueYellowGradient,
          ),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text(
          'Levantar Empresa',
          style: TextStyle(
              color: Colors.white, fontWeight: FontWeight.w700, fontSize: 18),
        ),
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: Column(
        children: [
          // ── Cabecera de búsqueda ──────────────────────────────
          Container(
            decoration: const BoxDecoration(
              gradient: ConstantColors.blueYellowGradient,
              borderRadius: BorderRadius.only(
                bottomLeft:  Radius.circular(24),
                bottomRight: Radius.circular(24),
              ),
            ),
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 22),
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
                        controller:      _buscarCtrl,
                        focusNode:       _buscarFocus,
                        textInputAction: TextInputAction.search,
                        onSubmitted:     (_) => _buscar(),
                        onChanged:       _onSearchChanged,
                        style: const TextStyle(color: Colors.white, fontSize: 15),
                        decoration: InputDecoration(
                          hintText:  'Nombre de empresa…',
                          hintStyle: const TextStyle(color: Colors.white54),
                          filled:    true,
                          fillColor: Colors.white.withOpacity(0.12),
                          prefixIcon: const Icon(Icons.store_rounded,
                              color: Colors.white70),
                          contentPadding: const EdgeInsets.symmetric(
                              vertical: 14, horizontal: 14),
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: BorderSide.none,
                          ),
                          enabledBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: BorderSide(
                                color: Colors.white.withOpacity(0.2)),
                          ),
                          focusedBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: const BorderSide(color: Colors.white),
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Material(
                      color: Colors.white.withOpacity(0.18),
                      borderRadius: BorderRadius.circular(12),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(12),
                        onTap: _cargando ? null : _buscar,
                        child: Container(
                          padding: const EdgeInsets.all(14),
                          child: _cargando
                              ? const SizedBox(
                                  width:  22,
                                  height: 22,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2.5,
                                    color: Colors.white,
                                  ),
                                )
                              : const Icon(Icons.search_rounded,
                                  color: Colors.white, size: 26),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),

          // ── Filtros (solo si hay resultados) ──────────────────
          if (_buscado && _resultados.isNotEmpty) _buildFiltros(),

          // ── Cuerpo: lista de resultados ───────────────────────
          Expanded(child: _buildCuerpo()),
        ],
      ),
    );
  }

  // ── Barra de filtros ─────────────────────────────────────────
  Widget _buildFiltros() {
    final ciudades = _ciudadesDisponibles;
    return Container(
      color: ConstantColors.backgroundDark,
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                _chip(
                  label:    'Todos',
                  selected: _filtroEstado == 'todos',
                  onTap: () => setState(() => _filtroEstado = 'todos'),
                ),
                const SizedBox(width: 8),
                _chip(
                  label:         '⚠ Pendiente',
                  selected:      _filtroEstado == 'pendiente',
                  onTap: () => setState(() => _filtroEstado = 'pendiente'),
                  selectedColor: ConstantColors.warning,
                ),
                const SizedBox(width: 8),
                _chip(
                  label:         '✓ Completado',
                  selected:      _filtroEstado == 'completado',
                  onTap: () => setState(() => _filtroEstado = 'completado'),
                  selectedColor: ConstantColors.success,
                ),
                if (ciudades.length > 1) ...[
                  const SizedBox(width: 14),
                  Container(
                      width: 1, height: 24, color: ConstantColors.borderColor),
                  const SizedBox(width: 14),
                  _chip(
                    label:    _filtroCiudad ?? 'Ciudad',
                    selected: _filtroCiudad != null,
                    icon:     Icons.location_on_rounded,
                    onTap: () => _mostrarSelectorCiudad(ciudades),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 6),
          Text(
            '${_resultadosFiltrados.length} de ${_resultados.length} resultado${_resultados.length == 1 ? '' : 's'}',
            style: TextStyle(color: ConstantColors.textSubtle, fontSize: 11),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  Widget _chip({
    required String   label,
    required bool     selected,
    required VoidCallback onTap,
    Color?   selectedColor,
    IconData? icon,
  }) {
    final color = selectedColor ?? ConstantColors.primaryViolet;
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: selected
              ? color.withOpacity(0.18)
              : ConstantColors.backgroundCard,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(
            color: selected ? color : ConstantColors.borderColor,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (icon != null) ...[
              Icon(icon, size: 13,
                  color: selected ? color : ConstantColors.textGrey),
              const SizedBox(width: 4),
            ],
            Text(
              label,
              style: TextStyle(
                fontSize:   12,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                color:      selected ? color : ConstantColors.textGrey,
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _mostrarSelectorCiudad(List<String> ciudades) {
    showModalBottomSheet(
      context: context,
      backgroundColor: ConstantColors.backgroundCard,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(height: 8),
          Container(
            width: 40, height: 4,
            decoration: BoxDecoration(
              color: ConstantColors.borderColor,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(height: 16),
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 20),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                'Filtrar por ciudad',
                style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                    fontSize: 16),
              ),
            ),
          ),
          const SizedBox(height: 8),
          ListTile(
            leading: Icon(Icons.public_rounded,
                color: _filtroCiudad == null
                    ? ConstantColors.primaryViolet
                    : ConstantColors.textGrey),
            title: Text(
              'Todas las ciudades',
              style: TextStyle(
                color: _filtroCiudad == null
                    ? ConstantColors.primaryViolet
                    : Colors.white,
                fontWeight: _filtroCiudad == null
                    ? FontWeight.w700
                    : FontWeight.w400,
              ),
            ),
            onTap: () {
              setState(() => _filtroCiudad = null);
              Navigator.pop(context);
            },
          ),
          ...ciudades.map((c) => ListTile(
                leading: Icon(Icons.location_on_rounded,
                    size: 20,
                    color: _filtroCiudad == c
                        ? ConstantColors.primaryViolet
                        : ConstantColors.textGrey),
                title: Text(
                  c,
                  style: TextStyle(
                    color: _filtroCiudad == c
                        ? ConstantColors.primaryViolet
                        : Colors.white,
                    fontWeight: _filtroCiudad == c
                        ? FontWeight.w700
                        : FontWeight.w400,
                  ),
                ),
                onTap: () {
                  setState(() => _filtroCiudad = c);
                  Navigator.pop(context);
                },
              )),
          const SizedBox(height: 20),
        ],
      ),
    );
  }

  // ── Cuerpo principal ─────────────────────────────────────────
  Widget _buildCuerpo() {
    if (_cargando) {
      return Center(
        child: CircularProgressIndicator(color: ConstantColors.primaryViolet),
      );
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.error_outline_rounded,
                  color: ConstantColors.error, size: 48),
              const SizedBox(height: 12),
              Text(_error!,
                  textAlign: TextAlign.center,
                  style:
                      TextStyle(color: ConstantColors.error, fontSize: 14)),
              const SizedBox(height: 16),
              ElevatedButton.icon(
                onPressed: _buscar,
                icon: const Icon(Icons.refresh_rounded),
                label: const Text('Reintentar'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: ConstantColors.primaryViolet,
                  foregroundColor: Colors.white,
                ),
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
              ShaderMask(
                shaderCallback: (b) =>
                    ConstantColors.primaryGradient.createShader(b),
                child: const Icon(
                  Icons.business_center_rounded,
                  size: 80,
                  color: Colors.white,
                ),
              ),
              const SizedBox(height: 20),
              Text(
                'Ingresa el nombre de la empresa\ny presiona buscar.',
                textAlign: TextAlign.center,
                style: TextStyle(
                    color: ConstantColors.textGrey, fontSize: 15, height: 1.5),
              ),
            ],
          ),
        ),
      );
    }

    final lista = _resultadosFiltrados;

    if (lista.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(40),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.search_off_rounded,
                  size: 64, color: ConstantColors.textSubtle),
              const SizedBox(height: 16),
              Text(
                _resultados.isEmpty
                    ? 'No se encontraron prospectos\ncon esa empresa.'
                    : 'Ningún resultado coincide\ncon los filtros aplicados.',
                textAlign: TextAlign.center,
                style:
                    TextStyle(color: ConstantColors.textGrey, fontSize: 15),
              ),
              if (_resultados.isNotEmpty) ...[
                const SizedBox(height: 16),
                TextButton.icon(
                  onPressed: () => setState(() {
                    _filtroEstado = 'todos';
                    _filtroCiudad = null;
                  }),
                  icon: const Icon(Icons.filter_alt_off_rounded),
                  label: const Text('Limpiar filtros'),
                  style: TextButton.styleFrom(
                      foregroundColor: ConstantColors.primaryViolet),
                ),
              ],
            ],
          ),
        ),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      itemCount: lista.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, i) => _buildTarjeta(lista[i]),
    );
  }

  // ── Tarjeta de prospecto ──────────────────────────────────────
  Widget _buildTarjeta(Map<String, dynamic> c) {
    final nombre   = (c['nombre']         ?? '').toString();
    final cedula   = (c['cedula']         ?? '').toString();
    final empresa  = (c['nombre_empresa'] ?? '').toString();
    final ciudad   = (c['ciudad']         ?? '').toString();
    final tieneEnc = c['encuesta_negocio'] != null;

    return Material(
      color: ConstantColors.backgroundCard,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => _abrirLevantamiento(c),
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: ConstantColors.borderColor),
          ),
          padding:
              const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              // ─ Icono empresa ─
              Container(
                width:  50,
                height: 50,
                decoration: BoxDecoration(
                  gradient: ConstantColors.primaryGradient,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: const Icon(Icons.store_rounded,
                    color: Colors.white, size: 26),
              ),
              const SizedBox(width: 14),

              // ─ Datos ─
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      empresa.isNotEmpty
                          ? empresa
                          : '(Sin nombre de empresa)',
                      style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w700,
                          fontSize: 15),
                    ),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        Icon(Icons.person_rounded,
                            size: 13, color: ConstantColors.textGrey),
                        const SizedBox(width: 4),
                        Expanded(
                          child: Text(
                            nombre,
                            style: TextStyle(
                                color: ConstantColors.textGrey,
                                fontSize: 13),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                    if (cedula.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          Icon(Icons.badge_rounded,
                              size: 13, color: ConstantColors.textSubtle),
                          const SizedBox(width: 4),
                          Text(cedula,
                              style: TextStyle(
                                  color: ConstantColors.textSubtle,
                                  fontSize: 12)),
                        ],
                      ),
                    ],
                    if (ciudad.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          Icon(Icons.location_on_rounded,
                              size: 13, color: ConstantColors.textSubtle),
                          const SizedBox(width: 4),
                          Text(ciudad,
                              style: TextStyle(
                                  color: ConstantColors.textSubtle,
                                  fontSize: 12)),
                        ],
                      ),
                    ],
                    const SizedBox(height: 8),
                    // ─ Badge estado ─
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: tieneEnc
                            ? ConstantColors.success.withOpacity(0.15)
                            : ConstantColors.warning.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(
                          color: tieneEnc
                              ? ConstantColors.success.withOpacity(0.6)
                              : ConstantColors.warning.withOpacity(0.6),
                        ),
                      ),
                      child: Text(
                        tieneEnc
                            ? '✓ Levantamiento completado'
                            : '⚠ Pendiente de levantamiento',
                        style: TextStyle(
                          fontSize:   11,
                          fontWeight: FontWeight.w600,
                          color: tieneEnc
                              ? ConstantColors.success
                              : ConstantColors.warning,
                        ),
                      ),
                    ),
                  ],
                ),
              ),

              // ─ Flecha ─
              const SizedBox(width: 8),
              Icon(Icons.chevron_right_rounded,
                  color: ConstantColors.primaryViolet, size: 26),
            ],
          ),
        ),
      ),
    );
  }
}
