import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';

class AgendaRecuperacionScreen extends StatefulWidget {
  const AgendaRecuperacionScreen({super.key});

  @override
  State<AgendaRecuperacionScreen> createState() =>
      _AgendaRecuperacionScreenState();
}

class _AgendaRecuperacionScreenState extends State<AgendaRecuperacionScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _tareas = const [];
  final Map<String, bool> _confirmado = {};

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      // Reutiliza el endpoint existente filtrando por tipo=recuperacion
      final url = Uri.parse(
          '${Constants.apiBaseUrl}/obtener_tareas_pendientes_asesor.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'desde': DateTime.now().toIso8601String().substring(0, 10),
        'tipo': 'recuperacion',
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) throw Exception('Respuesta invalida');

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        throw Exception(
            decoded['message']?.toString() ?? 'No se pudo cargar');
      }

      final tareas = decoded['tareas'];
      if (tareas is List) {
        _tareas =
            tareas.map((e) => Map<String, dynamic>.from(e as Map)).toList();
      } else {
        _tareas = [];
      }

      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.toString();
      });
    }
  }

  Future<void> _seleccionarHoy(String tareaId,
      {required bool seleccionar}) async {
    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();
      if (usuarioId.isEmpty) throw Exception('Sesión no encontrada');

      final url =
          Uri.parse('${Constants.apiBaseUrl}/seleccionar_tarea_hoy.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'tarea_id': tareaId,
        'accion': seleccionar ? 'seleccionar' : 'deseleccionar',
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) throw Exception('Respuesta invalida');

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        final msg =
            decoded['message']?.toString() ?? 'No se pudo actualizar';
        if (!mounted) return;
        _showSnack(msg, error: true);
        return;
      }

      await _cargar();
    } catch (e) {
      if (!mounted) return;
      _showSnack(e.toString().replaceFirst('Exception: ', ''), error: true);
    }
  }

  Future<void> _posponerTarea(String tareaId, DateTime nuevaFecha) async {
    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();
      if (usuarioId.isEmpty) throw Exception('Sesión no encontrada');

      final fechaStr = nuevaFecha.toIso8601String().substring(0, 10);

      final url = Uri.parse('${Constants.apiBaseUrl}/posponer_tarea.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'tarea_id': tareaId,
        'nueva_fecha': fechaStr,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) throw Exception('Respuesta invalida');

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        final msg = decoded['message']?.toString() ?? 'No se pudo posponer';
        if (!mounted) return;
        _showSnack(msg, error: true);
        return;
      }

      _confirmado.remove(tareaId);
      await _cargar();
    } catch (e) {
      if (!mounted) return;
      _showSnack(e.toString().replaceFirst('Exception: ', ''), error: true);
    }
  }

  Future<void> _finalizarTarea(String tareaId) async {
    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();
      if (usuarioId.isEmpty) throw Exception('Sesión no encontrada');

      final url = Uri.parse('${Constants.apiBaseUrl}/finalizar_tarea.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'tarea_id': tareaId,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) throw Exception('Respuesta invalida');

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        final msg = decoded['message']?.toString() ?? 'No se pudo finalizar';
        if (!mounted) return;
        _showSnack(msg, error: true);
        return;
      }

      _confirmado.remove(tareaId);
      _showSnack('Tarea de recuperación completada ✓', error: false);
      await _cargar();
    } catch (e) {
      if (!mounted) return;
      _showSnack(e.toString().replaceFirst('Exception: ', ''), error: true);
    }
  }

  void _showSnack(String msg, {required bool error}) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor:
          error ? ConstantColors.warning : Colors.green.shade700,
    ));
  }

  @override
  Widget build(BuildContext context) {
    final hoy = DateTime.now().toIso8601String().substring(0, 10);

    return DefaultTabController(
      length: 2,
      initialIndex: 0,
      child: Scaffold(
        backgroundColor: ConstantColors.backgroundDark,
        appBar: AppBar(
          backgroundColor: ConstantColors.backgroundDark,
          foregroundColor: ConstantColors.textWhite,
          elevation: 0,
          title: Row(
            children: const [
              Icon(Icons.loop_rounded, color: Color(0xFFFFDD00), size: 20),
              SizedBox(width: 8),
              Text(
                'Recuperación',
                style: TextStyle(fontWeight: FontWeight.w800),
              ),
            ],
          ),
          actions: [
            IconButton(
              onPressed: _cargar,
              icon: const Icon(Icons.refresh_rounded),
            ),
          ],
          bottom: const TabBar(
            labelColor: ConstantColors.textWhite,
            unselectedLabelColor: ConstantColors.textGrey,
            indicatorColor: Color(0xFFFFDD00),
            tabs: [
              Tab(text: 'Hoy'),
              Tab(text: 'Lista'),
            ],
          ),
        ),
        body: TabBarView(
          children: [
            RefreshIndicator(
              onRefresh: _cargar,
              child: _buildContent(soloHoy: true, hoy: hoy),
            ),
            RefreshIndicator(
              onRefresh: _cargar,
              child: _buildContent(soloHoy: false, hoy: hoy),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildContent({required bool soloHoy, required String hoy}) {
    if (_loading) {
      return ListView(
        padding: const EdgeInsets.all(16),
        children: const [
          SizedBox(height: 120),
          Center(child: CircularProgressIndicator()),
        ],
      );
    }

    if (_error != null) {
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.red.shade50,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Colors.red.shade200),
            ),
            child: Text(
              'No se pudo cargar.\n$_error',
              style: TextStyle(color: Colors.red.shade800),
            ),
          ),
        ],
      );
    }

    if (_tareas.isEmpty) {
      return ListView(
        padding: const EdgeInsets.all(16),
        children: const [
          SizedBox(height: 40),
          Center(
            child: Text(
              'No hay tareas de recuperación.',
              style: TextStyle(
                color: ConstantColors.textGrey,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      );
    }

    // Clasificar tareas — ENUM: programada | en_proceso | completada | postergada
    final tareasHoy = _tareas.where((t) {
      final estado = t['estado']?.toString() ?? '';
      final selDia = t['seleccionada_dia']?.toString() ?? '';
      return estado == 'en_proceso' && (selDia.isEmpty || selDia == hoy);
    }).toList();

    final completadasHoy = _tareas.where((t) {
      final estado = t['estado']?.toString() ?? '';
      final fr = t['fecha_realizada']?.toString() ?? '';
      return estado == 'completada' && fr == hoy;
    }).toList();

    final otras = _tareas.where((t) {
      final estado = t['estado']?.toString() ?? '';
      final selDia = t['seleccionada_dia']?.toString() ?? '';
      if (estado == 'completada') return false;
      if (estado == 'en_proceso') {
        return selDia.isNotEmpty && selDia != hoy;
      }
      // programada y postergada siempre van en lista
      return true;
    }).toList();

    final poolTareas =
        otras.where((t) => (t['es_pool']?.toString() ?? '0') == '1').toList();
    final propiasTareas =
        otras.where((t) => (t['es_pool']?.toString() ?? '0') != '1').toList();

    final widgets = <Widget>[];

    if (soloHoy) {
      // ── Tab Hoy ──
      if (tareasHoy.isNotEmpty) {
        widgets.add(_sectionTitle('Tareas de recuperación de hoy'));
        for (final t in tareasHoy) {
          widgets.add(_card(t, hoy));
          widgets.add(const SizedBox(height: 10));
        }
      }

      if (completadasHoy.isNotEmpty) {
        if (widgets.isNotEmpty) widgets.add(const SizedBox(height: 6));
        widgets.add(_sectionTitle('Completadas hoy'));
        for (final t in completadasHoy) {
          widgets.add(_card(t, hoy));
          widgets.add(const SizedBox(height: 10));
        }
      }

      if (tareasHoy.isEmpty && completadasHoy.isEmpty) {
        widgets.add(const SizedBox(height: 40));
        widgets.add(
          const Center(
            child: Text(
              'No hay tareas de recuperación para hoy.\nSelecciona una desde la Lista.',
              textAlign: TextAlign.center,
              style: TextStyle(
                color: ConstantColors.textGrey,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        );
      }
    } else {
      // ── Tab Lista ──
      if (poolTareas.isNotEmpty) {
        widgets.add(
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              children: [
                Icon(Icons.inbox_rounded, color: Colors.teal.shade300, size: 16),
                const SizedBox(width: 6),
                Text(
                  'Disponibles (para cualquier asesor)',
                  style: TextStyle(
                    color: Colors.teal.shade200,
                    fontWeight: FontWeight.w800,
                    fontSize: 14,
                  ),
                ),
              ],
            ),
          ),
        );
        for (final t in poolTareas) {
          widgets.add(_card(t, hoy));
          widgets.add(const SizedBox(height: 10));
        }
        widgets.add(const SizedBox(height: 6));
      }

      if (propiasTareas.isNotEmpty) {
        widgets.add(_sectionTitle('Mis tareas pendientes'));
        for (final t in propiasTareas) {
          widgets.add(_card(t, hoy));
          widgets.add(const SizedBox(height: 10));
        }
      }

      if (poolTareas.isEmpty && propiasTareas.isEmpty) {
        widgets.add(const SizedBox(height: 40));
        widgets.add(
          const Center(
            child: Text(
              'No hay tareas de recuperación pendientes.',
              style: TextStyle(
                color: ConstantColors.textGrey,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        );
      }
    }

    if (widgets.isNotEmpty && widgets.last is SizedBox) {
      widgets.removeLast();
    }

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      children: widgets,
    );
  }

  Widget _sectionTitle(String title) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Text(
        title,
        style: const TextStyle(
          color: ConstantColors.textWhite,
          fontWeight: FontWeight.w800,
          fontSize: 14,
        ),
      ),
    );
  }

  double? _toDouble(dynamic v) {
    if (v == null) return null;
    if (v is num) return v.toDouble();
    return double.tryParse(v.toString());
  }

  /// Extrae el número de meses en mora del campo observaciones.
  int? _parseMeses(String obs) {
    final m = RegExp(r'[Mm]eses\s+en\s+mora\s*[:\-]\s*(\d+)').firstMatch(obs);
    if (m != null) return int.tryParse(m.group(1) ?? '');
    // Formato alternativo: "— Meses en mora: 2"
    final m2 = RegExp(r'(\d+)\s+[Mm]eses?\s+en\s+mora').firstMatch(obs);
    if (m2 != null) return int.tryParse(m2.group(1) ?? '');
    return null;
  }

  /// Limpia el texto de observaciones para mostrar al usuario.
  String _cleanObs(String obs) {
    var clean = obs;
    // Quitar "(credito_ref:UUID)"
    clean = clean.replaceAll(RegExp(r'\s*\(credito_ref:[^)]*\)'), '');
    // Quitar "Meses en mora: N. " al inicio
    clean = clean.replaceAll(RegExp(r'^[Mm]eses\s+en\s+mora\s*:\s*\d+\.?\s*'), '');
    // Quitar " — Meses en mora: N" al final o medio
    clean = clean.replaceAll(RegExp(r'\s*[—\-]+\s*[Mm]eses\s+en\s+mora\s*:\s*\d+'), '');
    // Quitar "Meses en mora: N" sin guión
    clean = clean.replaceAll(RegExp(r'\s*[Mm]eses\s+en\s+mora\s*:\s*\d+'), '');
    return clean.trim();
  }

  /// Formatea una fecha YYYY-MM-DD a DD/MM/YYYY
  String _fmtFecha(String raw) {
    if (raw.isEmpty || raw == 'null') return '';
    final parts = raw.split('-');
    if (parts.length == 3) return '${parts[2]}/${parts[1]}/${parts[0]}';
    return raw;
  }

  Widget _card(Map<String, dynamic> t, String hoy) {
    final estado = t['estado']?.toString() ?? '';
    final tareaId = t['id']?.toString() ?? '';
    final cliente = t['cliente_nombre']?.toString() ?? 'Cliente';
    final cedula = t['cliente_cedula']?.toString() ?? '';
    final telefono = t['cliente_telefono']?.toString() ?? '';
    final ciudad = t['cliente_ciudad']?.toString() ?? '';
    final observaciones = t['observaciones']?.toString() ?? '';
    final fechaProg = t['fecha_programada']?.toString() ?? '';
    final fechaReal = t['fecha_realizada']?.toString() ?? '';
    final fechaCredito = t['fecha_credito']?.toString() ?? '';
    final selDia = t['seleccionada_dia']?.toString() ?? '';
    final esPool = (t['es_pool']?.toString() ?? '0') == '1';
    final confirmado = _confirmado[tareaId] ?? false;
    final lat = _toDouble(t['cliente_latitud']);
    final lng = _toDouble(t['cliente_longitud']);
    final hasCoord = lat != null && lng != null && (lat != 0.0 || lng != 0.0);

    // Estado ENUM real: programada | en_proceso | completada | postergada
    final esPospuesta = estado == 'postergada' ||
        (estado == 'en_proceso' && selDia.isNotEmpty && selDia != hoy);
    final isDone = estado == 'completada';
    final isProc = estado == 'en_proceso' && !esPospuesta;

    // Meses en mora
    final meses = _parseMeses(observaciones);
    final cleanObs = _cleanObs(observaciones);

    // Status badge colors
    Color badgeBg, badgeBorder, badgeText;
    String badgeLabel;
    if (isDone) {
      badgeBg = Colors.green.withOpacity(0.15);
      badgeBorder = Colors.green.shade600;
      badgeText = Colors.green.shade300;
      badgeLabel = 'Completada';
    } else if (isProc) {
      badgeBg = Colors.purple.withOpacity(0.15);
      badgeBorder = Colors.purple.shade400;
      badgeText = Colors.purple.shade200;
      badgeLabel = 'En proceso';
    } else if (esPospuesta) {
      badgeBg = Colors.orange.withOpacity(0.15);
      badgeBorder = Colors.orange.shade600;
      badgeText = Colors.orange.shade300;
      badgeLabel = 'Pospuesta';
    } else {
      badgeBg = Colors.blue.withOpacity(0.12);
      badgeBorder = Colors.blue.shade600;
      badgeText = Colors.blue.shade300;
      badgeLabel = 'Programada';
    }

    // Mora color: rojo fuerte si ≥3, naranja si <3
    final moraColor = (meses != null && meses >= 3)
        ? Colors.red.shade600
        : Colors.orange.shade600;

    final fechaMostrar = isDone ? fechaReal : fechaProg;

    return Container(
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Encabezado de la tarjeta ──
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.04),
              borderRadius:
                  const BorderRadius.vertical(top: Radius.circular(16)),
            ),
            child: Row(
              children: [
                const Icon(Icons.loop_rounded,
                    color: Color(0xFFFFDD00), size: 15),
                const SizedBox(width: 6),
                const Expanded(
                  child: Text(
                    'Recuperación de cartera',
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 12,
                      color: ConstantColors.textGrey,
                      letterSpacing: 0.3,
                    ),
                  ),
                ),
                if (esPool) ...[
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 7, vertical: 3),
                    decoration: BoxDecoration(
                      color: Colors.teal.shade700.withOpacity(0.2),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: Colors.teal.shade500),
                    ),
                    child: Text(
                      'Disponible',
                      style: TextStyle(
                          fontWeight: FontWeight.w700,
                          fontSize: 10,
                          color: Colors.teal.shade200),
                    ),
                  ),
                  const SizedBox(width: 6),
                ],
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                  decoration: BoxDecoration(
                    color: badgeBg,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: badgeBorder),
                  ),
                  child: Text(
                    badgeLabel,
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 11,
                      color: badgeText,
                    ),
                  ),
                ),
              ],
            ),
          ),

          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // ── Nombre del cliente ──
                Text(
                  cliente,
                  style: const TextStyle(
                    color: ConstantColors.textWhite,
                    fontWeight: FontWeight.w900,
                    fontSize: 16,
                  ),
                ),
                const SizedBox(height: 6),

                // ── Info líneas: cédula, teléfono, ciudad ──
                Wrap(
                  spacing: 12,
                  runSpacing: 4,
                  children: [
                    if (cedula.isNotEmpty)
                      _infoChip(Icons.badge_outlined, 'C.I. $cedula'),
                    if (telefono.isNotEmpty)
                      _infoChip(Icons.phone_rounded, telefono),
                    if (ciudad.isNotEmpty)
                      _infoChip(Icons.location_on_rounded, ciudad),
                  ],
                ),

                const SizedBox(height: 10),

                // ── Badge meses de atraso ──
                if (meses != null)
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 14, vertical: 7),
                    decoration: BoxDecoration(
                      color: moraColor.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: moraColor.withOpacity(0.6)),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.warning_rounded,
                            color: moraColor, size: 16),
                        const SizedBox(width: 7),
                        Text(
                          '$meses ${meses == 1 ? 'mes' : 'meses'} de atraso',
                          style: TextStyle(
                            color: moraColor,
                            fontWeight: FontWeight.w900,
                            fontSize: 14,
                          ),
                        ),
                      ],
                    ),
                  ),

                // ── Fechas ──
                const SizedBox(height: 8),
                Row(
                  children: [
                    if (fechaCredito.isNotEmpty &&
                        fechaCredito != 'null') ...[
                      Expanded(
                        child: _fechaInfo(
                          Icons.credit_card_rounded,
                          'Aprobación crédito',
                          _fmtFecha(fechaCredito),
                          Colors.blue.shade300,
                        ),
                      ),
                      const SizedBox(width: 10),
                    ],
                    if (fechaMostrar.isNotEmpty)
                      Expanded(
                        child: _fechaInfo(
                          isDone
                              ? Icons.check_circle_rounded
                              : Icons.calendar_today_rounded,
                          isDone ? 'Completada' : 'Fecha gestión',
                          _fmtFecha(fechaMostrar),
                          isDone
                              ? Colors.green.shade300
                              : ConstantColors.textGrey,
                        ),
                      ),
                  ],
                ),

                // ── Observaciones limpias ──
                if (cleanObs.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.notes_rounded,
                          size: 13, color: ConstantColors.textGrey),
                      const SizedBox(width: 5),
                      Expanded(
                        child: Text(
                          cleanObs,
                          style: const TextStyle(
                            color: ConstantColors.textGrey,
                            fontSize: 12,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],

                const SizedBox(height: 12),

                // ── Acciones ──
                if (isDone)
                  _doneRow()
                else if (esPospuesta)
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: tareaId.isEmpty
                          ? null
                          : () => _seleccionarHoy(tareaId, seleccionar: true),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: ConstantColors.warning,
                        foregroundColor: Colors.black,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12)),
                      ),
                      icon: const Icon(Icons.play_arrow_rounded, size: 18),
                      label: const Text('Seleccionar para hoy',
                          style: TextStyle(
                              fontWeight: FontWeight.w800, fontSize: 13)),
                    ),
                  )
                else if (isProc)
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // ── Botón Ruta (solo si tiene coordenadas) ──
                      if (hasCoord) ...[
                        ElevatedButton.icon(
                          onPressed: () {
                            Navigator.of(context).pop({
                              'destino_lat': lat,
                              'destino_lng': lng,
                              'destino_nombre': cliente,
                              'tarea_id': tareaId,
                            });
                          },
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF1565C0),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12)),
                          ),
                          icon: const Icon(Icons.route_rounded, size: 17),
                          label: const Text(
                            'Ver ruta al cliente',
                            style: TextStyle(
                                fontWeight: FontWeight.w800, fontSize: 13),
                          ),
                        ),
                        const SizedBox(height: 8),
                      ],
                      // ── Checkbox + Posponer + Finalizar ──
                      Row(
                    children: [
                      Expanded(
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 8),
                          decoration: BoxDecoration(
                            color: Colors.white.withOpacity(0.06),
                            borderRadius: BorderRadius.circular(12),
                            border:
                                Border.all(color: ConstantColors.borderColor),
                          ),
                          child: Row(
                            children: [
                              Checkbox(
                                value: confirmado,
                                onChanged: tareaId.isEmpty
                                    ? null
                                    : (v) => setState(() =>
                                        _confirmado[tareaId] = v ?? false),
                                visualDensity: VisualDensity.compact,
                                materialTapTargetSize:
                                    MaterialTapTargetSize.shrinkWrap,
                                side: BorderSide(
                                    color: Colors.white.withOpacity(0.35)),
                                activeColor: Colors.green.shade700,
                                checkColor: Colors.white,
                              ),
                              const SizedBox(width: 4),
                              const Expanded(
                                child: Text(
                                  'Completado',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                    color: ConstantColors.textWhite,
                                    fontWeight: FontWeight.w800,
                                    fontSize: 12,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      OutlinedButton.icon(
                        onPressed: tareaId.isEmpty
                            ? null
                            : () async {
                                final picked = await showDatePicker(
                                  context: context,
                                  initialDate: DateTime.now()
                                      .add(const Duration(days: 1)),
                                  firstDate: DateTime.now(),
                                  lastDate: DateTime.now()
                                      .add(const Duration(days: 365)),
                                );
                                if (picked == null) return;
                                await _posponerTarea(tareaId, picked);
                              },
                        style: OutlinedButton.styleFrom(
                          foregroundColor: Colors.white,
                          side: BorderSide(
                              color: Colors.white.withOpacity(0.25)),
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 12),
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12)),
                        ),
                        icon:
                            const Icon(Icons.event_repeat_rounded, size: 15),
                        label: const Text(
                          'Posponer',
                          style: TextStyle(
                              fontWeight: FontWeight.w800, fontSize: 12),
                        ),
                      ),
                      const SizedBox(width: 8),
                      ElevatedButton.icon(
                        onPressed: (tareaId.isEmpty || !confirmado)
                            ? null
                            : () => _finalizarTarea(tareaId),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.green.shade700,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 12),
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12)),
                        ),
                        icon:
                            const Icon(Icons.check_circle_rounded, size: 15),
                        label: const Text(
                          'Finalizar',
                          style: TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 12),
                        ),
                      ),
                    ],
                  ),    // cierra Row acciones
                    ],  // cierra children del Column
                  )     // cierra Column isProc
                else
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: tareaId.isEmpty
                          ? null
                          : () => _seleccionarHoy(tareaId, seleccionar: true),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: esPool
                            ? Colors.teal.shade700
                            : ConstantColors.warning,
                        foregroundColor:
                            esPool ? Colors.white : Colors.black,
                        padding: const EdgeInsets.symmetric(vertical: 13),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12)),
                      ),
                      icon: Icon(
                          esPool
                              ? Icons.assignment_ind_rounded
                              : Icons.play_arrow_rounded,
                          size: 18),
                      label: Text(
                        esPool ? 'Tomar tarea' : 'Iniciar hoy',
                        style: const TextStyle(
                            fontWeight: FontWeight.w800, fontSize: 14),
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

  /// Pequeño chip de información (ícono + texto)
  Widget _infoChip(IconData icon, String label) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 13, color: ConstantColors.textGrey),
        const SizedBox(width: 3),
        Text(
          label,
          style:
              const TextStyle(color: ConstantColors.textGrey, fontSize: 12),
        ),
      ],
    );
  }

  /// Bloque de fecha con ícono y etiqueta
  Widget _fechaInfo(
      IconData icon, String label, String value, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: color.withOpacity(0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withOpacity(0.25)),
      ),
      child: Row(
        children: [
          Icon(icon, size: 13, color: color),
          const SizedBox(width: 5),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    color: color.withOpacity(0.8),
                    fontSize: 10,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                Text(
                  value,
                  style: TextStyle(
                    color: color,
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _doneRow() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.06),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Row(
        children: const [
          Icon(Icons.check_circle_rounded,
              size: 16, color: Colors.greenAccent),
          SizedBox(width: 8),
          Expanded(
            child: Text(
              'Finalizada',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: ConstantColors.textWhite,
                fontWeight: FontWeight.w800,
                fontSize: 12,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _cancelRow() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.06),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Row(
        children: const [
          Icon(Icons.cancel_rounded, size: 16, color: Colors.redAccent),
          SizedBox(width: 8),
          Expanded(
            child: Text(
              'Cancelada',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: ConstantColors.textWhite,
                fontWeight: FontWeight.w800,
                fontSize: 12,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
