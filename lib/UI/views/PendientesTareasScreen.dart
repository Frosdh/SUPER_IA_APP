import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';

class PendientesTareasScreen extends StatefulWidget {
  const PendientesTareasScreen({super.key});

  @override
  State<PendientesTareasScreen> createState() => _PendientesTareasScreenState();
}

class _PendientesTareasScreenState extends State<PendientesTareasScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _tareas = const [];

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  double? _toDouble(dynamic v) {
    if (v == null) return null;
    if (v is num) return v.toDouble();
    return double.tryParse(v.toString());
  }

  String _tipoLabel(String tipo) {
    switch (tipo) {
      case 'nueva_cita_campo':
        return 'Nueva cita en campo';
      case 'nueva_cita_oficina':
        return 'Nueva cita en oficina';
      case 'documentos_pendientes':
        return 'Recolectar documentación';
      case 'levantamiento':
        return 'Levantamiento';
      default:
        return tipo.replaceAll('_', ' ');
    }
  }

  Future<void> _cargar() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      final url = Uri.parse('${Constants.apiBaseUrl}/obtener_tareas_pendientes_asesor.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'desde': DateTime.now().toIso8601String().substring(0, 10),
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) {
        throw Exception('Respuesta invalida');
      }

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        throw Exception(decoded['message']?.toString() ?? 'No se pudo cargar');
      }

      final tareas = decoded['tareas'];
      if (tareas is List) {
        _tareas = tareas.map((e) => Map<String, dynamic>.from(e as Map)).toList();
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

  Future<void> _setSeleccionHoy(String tareaId, {required bool seleccionar}) async {
    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      if (usuarioId.isEmpty) {
        throw Exception('Sesión no encontrada');
      }

      final url = Uri.parse('${Constants.apiBaseUrl}/seleccionar_tarea_hoy.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'tarea_id': tareaId,
        'accion': seleccionar ? 'seleccionar' : 'deseleccionar',
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) {
        throw Exception('Respuesta invalida');
      }

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        final msg = decoded['message']?.toString() ?? 'No se pudo actualizar';
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(msg),
            backgroundColor: ConstantColors.warning,
          ),
        );
        return;
      }

      await _cargar();
    } catch (e) {
      if (!mounted) return;
      final msg = e.toString().replaceFirst('Exception: ', '');
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(msg),
          backgroundColor: ConstantColors.warning,
        ),
      );
    }
  }

  Future<void> _fijarTareasHoy() async {
    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      if (usuarioId.isEmpty) {
        throw Exception('Sesión no encontrada');
      }

      final url = Uri.parse('${Constants.apiBaseUrl}/fijar_tareas_hoy.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) {
        throw Exception('Respuesta invalida');
      }

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        final msg = decoded['message']?.toString() ?? 'No se pudo fijar';
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(msg),
            backgroundColor: ConstantColors.warning,
          ),
        );
        return;
      }

      await _cargar();
    } catch (e) {
      if (!mounted) return;
      final msg = e.toString().replaceFirst('Exception: ', '');
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(msg),
          backgroundColor: ConstantColors.warning,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final hoy = DateTime.now().toIso8601String().substring(0, 10);

    Widget buildBaseList({required List<Widget> children}) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        children: children,
      );
    }

    Widget buildLoading() {
      return buildBaseList(children: const [
        SizedBox(height: 120),
        Center(child: CircularProgressIndicator()),
      ]);
    }

    Widget buildError() {
      return buildBaseList(children: [
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
      ]);
    }

    Widget buildEmpty(String text) {
      return buildBaseList(children: [
        const SizedBox(height: 30),
        Center(
          child: Text(
            text,
            style: const TextStyle(fontWeight: FontWeight.w700, color: ConstantColors.textWhite),
          ),
        ),
      ]);
    }

    Widget buildLista({required bool soloHoy}) {
      if (_loading) return buildLoading();
      if (_error != null) return buildError();
      if (_tareas.isEmpty) {
        return buildEmpty('No hay tareas pendientes.');
      }

      final seleccionadasHoy = _tareas.where((t) {
        final estado = t['estado']?.toString() ?? '';
        final selDia = t['seleccionada_dia']?.toString() ?? '';
        return estado == 'en_proceso' && (selDia.isEmpty || selDia == hoy);
      }).toList();

      final fijadasHoy = seleccionadasHoy.where((t) => (t['seleccion_fijada']?.toString() ?? '0') == '1').toList();
      final hoySinFijar = seleccionadasHoy.where((t) => (t['seleccion_fijada']?.toString() ?? '0') != '1').toList();

      final completadas = _tareas.where((t) {
        final estado = t['estado']?.toString() ?? '';
        return estado == 'completada';
      }).toList();

      final completadasHoy = completadas.where((t) {
        final fr = t['fecha_realizada']?.toString() ?? '';
        return fr == hoy;
      }).toList();

      final otras = _tareas.where((t) {
        final estado = t['estado']?.toString() ?? '';
        final selDia = t['seleccionada_dia']?.toString() ?? '';
        if (estado == 'completada' || estado == 'cancelada') return false;
        if (estado == 'en_proceso') {
          return selDia.isNotEmpty && selDia != hoy;
        }
        return true;
      }).toList();

      Widget card(Map<String, dynamic> t) {
        final tipo = t['tipo_tarea']?.toString() ?? '';
        final estado = t['estado']?.toString() ?? '';
        final fechaProg = t['fecha_programada']?.toString() ?? '';
        final horaProg = t['hora_programada']?.toString() ?? '';
        final fechaReal = t['fecha_realizada']?.toString() ?? '';
        final horaReal = t['hora_realizada']?.toString() ?? '';
        final cliente = t['cliente_nombre']?.toString() ?? 'Cliente';
        final ciudad = t['cliente_ciudad']?.toString() ?? '';
        final direccion = t['cliente_direccion']?.toString() ?? '';
        final tareaId = t['id']?.toString() ?? '';
        final fijada = (t['seleccion_fijada']?.toString() ?? '0') == '1';
        final selDia = t['seleccionada_dia']?.toString() ?? '';
        final esHoySeleccionada = estado == 'en_proceso' && (selDia.isEmpty || selDia == hoy);

        final fechaMostrar = estado == 'completada' ? fechaReal : fechaProg;
        final horaMostrar = estado == 'completada' ? horaReal : horaProg;

        final isProg = estado == 'programada';
        final isProc = estado == 'en_proceso';
        final isDone = estado == 'completada';
        final isCancel = estado == 'cancelada';

        final badgeBg = isDone
            ? Colors.green.shade50
            : (isProg ? Colors.blue.shade50 : (isProc ? Colors.purple.shade50 : (isCancel ? Colors.red.shade50 : Colors.orange.shade50)));
        final badgeBorder = isDone
            ? Colors.green.shade200
            : (isProg ? Colors.blue.shade200 : (isProc ? Colors.purple.shade200 : (isCancel ? Colors.red.shade200 : Colors.orange.shade200)));
        final badgeText = isDone
            ? Colors.green.shade800
            : (isProg ? Colors.blue.shade800 : (isProc ? Colors.purple.shade800 : (isCancel ? Colors.red.shade800 : Colors.orange.shade800)));

        final lat = _toDouble(t['cliente_latitud']);
        final lng = _toDouble(t['cliente_longitud']);
        final hasCoord = lat != null && lng != null && (lat != 0.0 || lng != 0.0);

        return Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: ConstantColors.backgroundCard,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: ConstantColors.borderColor),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      _tipoLabel(tipo),
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 14,
                        color: ConstantColors.textWhite,
                      ),
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: badgeBg,
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: badgeBorder),
                    ),
                    child: Text(
                      estado.isEmpty ? '—' : estado,
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 12,
                        color: badgeText,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                cliente,
                style: const TextStyle(
                  color: ConstantColors.textWhite,
                  fontWeight: FontWeight.w700,
                ),
              ),
              if (ciudad.trim().isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  ciudad,
                  style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12),
                ),
              ],
              if (direccion.trim().isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  direccion,
                  style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12),
                ),
              ],
              const SizedBox(height: 8),
              Row(
                children: [
                  const Icon(Icons.calendar_month_rounded, size: 16, color: ConstantColors.textGrey),
                  const SizedBox(width: 6),
                  Text(
                    [fechaMostrar, horaMostrar].where((e) => e.trim().isNotEmpty).join(' '),
                    style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: estado == 'completada'
                        ? Container(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                            decoration: BoxDecoration(
                              color: Colors.white.withOpacity(0.06),
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: ConstantColors.borderColor),
                            ),
                            child: Row(
                              children: const [
                                Icon(Icons.check_circle_rounded, size: 16, color: Colors.greenAccent),
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
                          )
                        : (estado == 'cancelada'
                            ? Container(
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
                              )
                            : (estado == 'en_proceso'
                                ? (fijada
                                    ? Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                                        decoration: BoxDecoration(
                                          color: Colors.white.withOpacity(0.06),
                                          borderRadius: BorderRadius.circular(12),
                                          border: Border.all(color: ConstantColors.borderColor),
                                        ),
                                        child: Row(
                                          children: const [
                                            Icon(Icons.lock_rounded, size: 16, color: ConstantColors.textWhite),
                                            SizedBox(width: 8),
                                            Expanded(
                                              child: Text(
                                                'Fijada (no se puede deseleccionar)',
                                                maxLines: 1,
                                                overflow: TextOverflow.ellipsis,
                                                style: TextStyle(
                                                  color: ConstantColors.textWhite,
                                                  fontWeight: FontWeight.w700,
                                                  fontSize: 12,
                                                ),
                                              ),
                                            ),
                                          ],
                                        ),
                                      )
                                    : (esHoySeleccionada
                                        ? OutlinedButton(
                                            onPressed: tareaId.isEmpty
                                                ? null
                                                : () => _setSeleccionHoy(tareaId, seleccionar: false),
                                            style: OutlinedButton.styleFrom(
                                              foregroundColor: Colors.red.shade200,
                                              side: BorderSide(color: Colors.red.shade200.withOpacity(0.7)),
                                              padding: const EdgeInsets.symmetric(vertical: 12),
                                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                            ),
                                            child: const Text(
                                              'Quitar de hoy',
                                              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12),
                                            ),
                                          )
                                        : Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                                            decoration: BoxDecoration(
                                              color: Colors.white.withOpacity(0.06),
                                              borderRadius: BorderRadius.circular(12),
                                              border: Border.all(color: ConstantColors.borderColor),
                                            ),
                                            child: Text(
                                              selDia.trim().isEmpty ? 'En proceso' : 'En proceso (seleccionada: $selDia)',
                                              maxLines: 1,
                                              overflow: TextOverflow.ellipsis,
                                              style: const TextStyle(
                                                color: ConstantColors.textWhite,
                                                fontWeight: FontWeight.w700,
                                                fontSize: 12,
                                              ),
                                            ),
                                          )))
                                : ElevatedButton(
                                    onPressed: tareaId.isEmpty
                                        ? null
                                        : () => _setSeleccionHoy(tareaId, seleccionar: true),
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: ConstantColors.warning,
                                      foregroundColor: Colors.white,
                                      padding: const EdgeInsets.symmetric(vertical: 12),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                    ),
                                    child: const Text(
                                      'Seleccionar hoy',
                                      style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12),
                                    ),
                                  ))),
                  ),
                  if (hasCoord) ...[
                    const SizedBox(width: 10),
                    OutlinedButton.icon(
                      onPressed: () {
                        Navigator.of(context).pop({
                          'destino_lat': lat,
                          'destino_lng': lng,
                          'destino_nombre': cliente,
                          'tarea_id': tareaId,
                        });
                      },
                      style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.white,
                        side: BorderSide(color: Colors.white.withOpacity(0.25)),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      icon: const Icon(Icons.route_rounded, size: 16),
                      label: const Text(
                        'Ruta',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12),
                      ),
                    ),
                  ],
                ],
              ),
            ],
          ),
        );
      }

      final widgets = <Widget>[];

      if (soloHoy) {
        if (fijadasHoy.isNotEmpty) {
          widgets.add(
            const Padding(
              padding: EdgeInsets.only(bottom: 10),
              child: Text(
                'Tareas fijadas de hoy',
                style: TextStyle(
                  color: ConstantColors.textWhite,
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              ),
            ),
          );
          for (final t in fijadasHoy) {
            widgets.add(card(t));
            widgets.add(const SizedBox(height: 10));
          }
          widgets.add(const SizedBox(height: 6));
        }

        if (hoySinFijar.isNotEmpty) {
          widgets.add(
            const Padding(
              padding: EdgeInsets.only(bottom: 10),
              child: Text(
                'Tareas de hoy',
                style: TextStyle(
                  color: ConstantColors.textWhite,
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              ),
            ),
          );
          for (final t in hoySinFijar) {
            widgets.add(card(t));
            widgets.add(const SizedBox(height: 10));
          }
          widgets.add(const SizedBox(height: 10));

          widgets.add(
            ElevatedButton.icon(
              onPressed: _fijarTareasHoy,
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.green.shade700,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              icon: const Icon(Icons.lock_rounded, size: 18),
              label: const Text(
                'Fijar tareas',
                style: TextStyle(fontWeight: FontWeight.w900),
              ),
            ),
          );
          widgets.add(const SizedBox(height: 6));
          widgets.add(
            Text(
              'Después de fijar, ya no se puede deseleccionar.',
              textAlign: TextAlign.center,
              style: TextStyle(color: ConstantColors.textGrey.withOpacity(0.9), fontSize: 12),
            ),
          );
        }

        if (completadasHoy.isNotEmpty) {
          widgets.add(const SizedBox(height: 14));
          widgets.add(
            const Padding(
              padding: EdgeInsets.only(bottom: 10),
              child: Text(
                'Finalizadas hoy',
                style: TextStyle(
                  color: ConstantColors.textWhite,
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              ),
            ),
          );
          for (final t in completadasHoy) {
            widgets.add(card(t));
            widgets.add(const SizedBox(height: 10));
          }
          widgets.add(const SizedBox(height: 6));
        }

        if (fijadasHoy.isEmpty && hoySinFijar.isEmpty && completadasHoy.isEmpty) {
          return buildEmpty('No hay tareas seleccionadas para hoy.');
        }
      } else {
        if (otras.isEmpty && completadas.isEmpty) {
          return buildEmpty('No hay tareas para mostrar.');
        }

        widgets.add(
          const Padding(
            padding: EdgeInsets.only(bottom: 10),
            child: Text(
              'Tareas programadas',
              style: TextStyle(
                color: ConstantColors.textWhite,
                fontWeight: FontWeight.w800,
                fontSize: 14,
              ),
            ),
          ),
        );

        for (final t in otras) {
          widgets.add(card(t));
          widgets.add(const SizedBox(height: 10));
        }

        if (completadas.isNotEmpty) {
          widgets.add(const SizedBox(height: 14));
          widgets.add(
            const Padding(
              padding: EdgeInsets.only(bottom: 10),
              child: Text(
                'Finalizadas',
                style: TextStyle(
                  color: ConstantColors.textWhite,
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              ),
            ),
          );
          for (final t in completadas) {
            widgets.add(card(t));
            widgets.add(const SizedBox(height: 10));
          }
        }
      }

      if (widgets.isNotEmpty) {
        if (widgets.last is SizedBox) widgets.removeLast();
      }

      return buildBaseList(children: widgets);
    }

    return DefaultTabController(
      length: 2,
      initialIndex: 1,
      child: Scaffold(
        backgroundColor: ConstantColors.backgroundDark,
        appBar: AppBar(
          backgroundColor: ConstantColors.backgroundDark,
          foregroundColor: ConstantColors.textWhite,
          elevation: 0,
          title: const Text(
            'Lista tareas',
            style: TextStyle(fontWeight: FontWeight.w800),
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
            indicatorColor: ConstantColors.warning,
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
              child: buildLista(soloHoy: true),
            ),
            RefreshIndicator(
              onRefresh: _cargar,
              child: buildLista(soloHoy: false),
            ),
          ],
        ),
      ),
    );
  }
}
