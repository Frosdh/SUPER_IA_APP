import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/Core/Services/TareasFijadasCacheService.dart';
import 'package:super_ia/UI/views/LevantarEmpresaScreen.dart';
import 'package:super_ia/UI/views/NuevaEncuestaScreen.dart';

class PendientesTareasScreen extends StatefulWidget {
  const PendientesTareasScreen({super.key});

  @override
  State<PendientesTareasScreen> createState() => _PendientesTareasScreenState();
}

class _PendientesTareasScreenState extends State<PendientesTareasScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _tareas = const [];
  final Map<String, bool> _buenVisto = {};

  /// true cuando lo que se está mostrando NO vino del servidor (sin
  /// internet) sino de la copia local de tareas ya fijadas (ver
  /// TareasFijadasCacheService). Se usa para mostrar el aviso
  /// correspondiente en vez del banner de error genérico.
  bool _mostrandoCacheOffline = false;

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
      case 'prospecto_nuevo':       return 'Prospecto nuevo';
      case 'visita_frio':           return 'Visita en frío';
      case 'evaluacion':            return 'Evaluación';
      case 'recuperacion':          return 'Recuperación';
      case 'post_venta':            return 'Post venta';
      case 'represtamo':            return 'Représ tamo';
      case 'nueva_cita_campo':      return 'Nueva cita en campo';
      case 'nueva_cita_oficina':    return 'Nueva cita en oficina';
      case 'nueva_cita_inversion':  return '💰 Nueva cita de inversión';
      case 'documentos_pendientes': return 'Recolectar documentación';
      case 'levantamiento':         return 'Levantamiento Empresa';
      case 'seguimiento':           return 'Seguimiento';
      default:                      return tipo.replaceAll('_', ' ');
    }
  }

  /// Del total de tareas, el subconjunto que el asesor ya "fijó" para hoy
  /// (mismo filtro que usa buildLista para la sección "Tareas fijadas de
  /// hoy"). Es lo único que se guarda en la copia local: ver
  /// TareasFijadasCacheService.
  List<Map<String, dynamic>> _calcularFijadasHoy(List<Map<String, dynamic>> tareas) {
    final hoy = DateTime.now().toIso8601String().substring(0, 10);
    return tareas.where((t) {
      final estado = t['estado']?.toString() ?? '';
      final selDia = t['seleccionada_dia']?.toString() ?? '';
      final fijada = (t['seleccion_fijada']?.toString() ?? '0') == '1';
      return estado == 'en_proceso' && (selDia.isEmpty || selDia == hoy) && fijada;
    }).toList();
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
        _tareas = tareas
            .map((e) => Map<String, dynamic>.from(e as Map))
            .where((t) => t['tipo_tarea']?.toString() != 'recuperacion')
            .toList();
      } else {
        _tareas = [];
      }

      // Se guarda una copia local de las tareas ya fijadas de hoy, para
      // poder mostrarlas (y usar su ruta) si más tarde el asesor se queda
      // sin internet en la calle. No bloquea la UI ni el resto de la
      // carga si falla por algún motivo.
      unawaited(TareasFijadasCacheService.saveFijadas(_calcularFijadasHoy(_tareas)));

      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = null;
        _mostrandoCacheOffline = false;
      });
    } catch (e) {
      // Sin internet (u otro error): en vez de dejar la pantalla en blanco
      // con un error, se muestran las tareas que el asesor ya había fijado
      // para hoy la última vez que tuvo conexión (ver
      // TareasFijadasCacheService). Así puede seguir viendo cliente,
      // dirección y usar el botón "Ruta" aunque no haya señal.
      List<Map<String, dynamic>> cache = [];
      try {
        cache = await TareasFijadasCacheService.getFijadas();
      } catch (_) {}

      if (!mounted) return;
      if (cache.isNotEmpty) {
        setState(() {
          _tareas = cache;
          _loading = false;
          _error = null;
          _mostrandoCacheOffline = true;
        });
      } else {
        setState(() {
          _loading = false;
          _error = e.toString();
          _mostrandoCacheOffline = false;
        });
      }
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

  /// Abre la pantalla [NuevaEncuestaScreen] en modo edición con los datos
  /// completos de la tarea finalizada. Al volver se recarga la lista por si
  /// cambió alguna observación, nombre u otro dato visible.
  Future<void> _abrirEdicionEncuesta(Map<String, dynamic> tarea) async {
    final tareaId = tarea['id']?.toString() ?? '';
    if (tareaId.isEmpty) return;

    final tipo = tarea['tipo_tarea']?.toString() ?? 'prospecto_nuevo';

    final modificado = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => NuevaEncuestaScreen(
          tipoTarea: tipo,
          tareaIdEdicion: tareaId,
          incluirEmpresa: false,
        ),
      ),
    );

    if (modificado == true) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('Datos de la encuesta actualizados.'),
          backgroundColor: Colors.green.shade700,
        ),
      );
      await _cargar();
    }
  }

  // ── Navegar directo a la actividad según el tipo ─────────────
  String _labelActividad(String tipo) {
    switch (tipo) {
      case 'levantamiento':         return 'Ir al levantamiento';
      case 'evaluacion':            return 'Ir a evaluación';
      case 'prospecto_nuevo':       return 'Llenar encuesta';
      case 'visita_frio':           return 'Iniciar visita';
      case 'nueva_cita_inversion':  return 'Ir a cita de inversión';
      case 'nueva_cita_campo':      return 'Ir a cita en campo';
      case 'nueva_cita_oficina':    return 'Ir a cita en oficina';
      case 'post_venta':            return 'Ir a post venta';
      case 'represtamo':            return 'Ir a représ tamo';
      case 'seguimiento':           return 'Ir a seguimiento';
      case 'documentos_pendientes': return 'Ver documentación';
      default:                      return 'Ir a la actividad';
    }
  }

  IconData _iconActividad(String tipo) {
    switch (tipo) {
      case 'levantamiento':         return Icons.business_rounded;
      case 'evaluacion':            return Icons.assessment_rounded;
      case 'prospecto_nuevo':       return Icons.person_add_rounded;
      case 'visita_frio':           return Icons.door_front_door_rounded;
      case 'nueva_cita_inversion':  return Icons.savings_rounded;
      case 'documentos_pendientes': return Icons.folder_open_rounded;
      default:                      return Icons.play_arrow_rounded;
    }
  }

  Future<void> _irAActividad(Map<String, dynamic> tarea) async {
    final tipo    = tarea['tipo_tarea']?.toString() ?? 'prospecto_nuevo';
    final tareaId = tarea['id']?.toString() ?? '';
    if (tareaId.isEmpty) return;

    // Para levantamiento sin tarea específica → pantalla de búsqueda de empresa
    // Con tarea específica → encuesta directa con sección de empresa
    final bool conEmpresa = tipo == 'levantamiento' || tipo == 'evaluacion';

    final result = await Navigator.of(context).push<bool?>(
      MaterialPageRoute(
        builder: (_) => NuevaEncuestaScreen(
          tipoTarea:      tipo,
          tareaIdEdicion: tareaId,
          incluirEmpresa: conEmpresa,
        ),
      ),
    );
    if (result == true && mounted) await _cargar();
  }

  Future<void> _finalizarTarea(String tareaId) async {
    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      if (usuarioId.isEmpty) {
        throw Exception('Sesión no encontrada');
      }

      final url = Uri.parse('${Constants.apiBaseUrl}/finalizar_tarea.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'tarea_id': tareaId,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) {
        throw Exception('Respuesta invalida');
      }

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        final msg = decoded['message']?.toString() ?? 'No se pudo finalizar';
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(msg),
            backgroundColor: ConstantColors.warning,
          ),
        );
        return;
      }

      _buenVisto.remove(tareaId);
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

  Future<void> _editarEncuesta(String tareaId) async {
    try {
      if (tareaId.isEmpty) return;
      final result = await Navigator.of(context).push<bool?>(
        MaterialPageRoute(
          builder: (_) => NuevaEncuestaScreen(tareaIdEdicion: tareaId, incluirEmpresa: false),
        ),
      );
      // Si se guardaron cambios, recargar la lista
      if (result == true) await _cargar();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('No se pudo abrir editor: $e'), backgroundColor: ConstantColors.error),
      );
    }
  }

  Future<void> _posponerTarea(String tareaId, DateTime nuevaFecha) async {
    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      if (usuarioId.isEmpty) {
        throw Exception('Sesión no encontrada');
      }

      final fechaStr = nuevaFecha.toIso8601String().substring(0, 10);

      final url = Uri.parse('${Constants.apiBaseUrl}/posponer_tarea.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'tarea_id': tareaId,
        'nueva_fecha': fechaStr,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) {
        throw Exception('Respuesta invalida');
      }

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        final msg = decoded['message']?.toString() ?? 'No se pudo posponer';
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(msg),
            backgroundColor: ConstantColors.warning,
          ),
        );
        return;
      }

      _buenVisto.remove(tareaId);

      final esIncumplida = decoded['incumplida'] == true;
      final advertencia = decoded['advertencia']?.toString() ?? '';
      final mensaje = decoded['message']?.toString() ?? '';

      if (!mounted) return;

      if (esIncumplida) {
        await showDialog<void>(
          context: context,
          barrierDismissible: false,
          builder: (ctx) => AlertDialog(
            backgroundColor: ConstantColors.backgroundCard,
            title: const Row(
              children: [
                Icon(Icons.block_rounded, color: Colors.redAccent),
                SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Tarea incumplida',
                    style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w800),
                  ),
                ),
              ],
            ),
            content: Text(
              mensaje.isNotEmpty
                  ? mensaje
                  : 'Ya pospusiste esta tarea 5 veces. Se marcó como incumplida; tu supervisor deberá reasignarla a otro asesor.',
              style: const TextStyle(color: ConstantColors.textGrey, fontSize: 13.5),
            ),
            actions: [
              ElevatedButton(
                onPressed: () => Navigator.of(ctx).pop(),
                style: ElevatedButton.styleFrom(backgroundColor: Colors.red.shade700, foregroundColor: Colors.white),
                child: const Text('Entendido'),
              ),
            ],
          ),
        );
      } else if (advertencia.isNotEmpty) {
        await showDialog<void>(
          context: context,
          builder: (ctx) => AlertDialog(
            backgroundColor: ConstantColors.backgroundCard,
            title: const Row(
              children: [
                Icon(Icons.warning_amber_rounded, color: ConstantColors.warning),
                SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Última posposición disponible',
                    style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w800),
                  ),
                ),
              ],
            ),
            content: Text(
              advertencia,
              style: const TextStyle(color: ConstantColors.textGrey, fontSize: 13.5),
            ),
            actions: [
              ElevatedButton(
                onPressed: () => Navigator.of(ctx).pop(),
                style: ElevatedButton.styleFrom(backgroundColor: ConstantColors.warning, foregroundColor: Colors.white),
                child: const Text('Entendido'),
              ),
            ],
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Tarea pospuesta'), backgroundColor: Colors.green),
        );
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

  // ── Descartar tarea: el cliente/prospecto ya no quiere continuar ──
  // (ej. el asesor va al domicilio y le dicen que ya no les interesa).
  // La tarea pasa a estado 'cancelada': desaparece de las listas activas
  // y nunca cuenta como completada.
  Future<void> _confirmarYDescartarTarea(String tareaId) async {
    if (tareaId.isEmpty) return;
    final motivoCtrl = TextEditingController();

    final confirmado = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ConstantColors.backgroundCard,
        title: const Text(
          '¿Descartar esta tarea?',
          style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w800),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Se marcará como descartada y ya no aparecerá en tus tareas activas. '
              'Esta acción no se puede deshacer desde la app.',
              style: TextStyle(color: ConstantColors.textGrey, fontSize: 13),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: motivoCtrl,
              maxLines: 2,
              style: const TextStyle(color: ConstantColors.textWhite),
              decoration: InputDecoration(
                hintText: 'Motivo (opcional): ej. "Cliente ya no quiere nada"',
                hintStyle: TextStyle(color: ConstantColors.textGrey.withOpacity(0.7), fontSize: 12),
                filled: true,
                fillColor: Colors.white.withOpacity(0.06),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: BorderSide.none,
                ),
                contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancelar'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red.shade700, foregroundColor: Colors.white),
            child: const Text('Descartar'),
          ),
        ],
      ),
    );

    if (confirmado != true) return;
    await _descartarTarea(tareaId, motivoCtrl.text.trim());
  }

  Future<void> _descartarTarea(String tareaId, String motivo) async {
    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      if (usuarioId.isEmpty) {
        throw Exception('Sesión no encontrada');
      }

      final url = Uri.parse('${Constants.apiBaseUrl}/descartar_tarea.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'tarea_id': tareaId,
        if (motivo.isNotEmpty) 'motivo': motivo,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) {
        throw Exception('Respuesta invalida');
      }

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        final msg = decoded['message']?.toString() ?? 'No se pudo descartar';
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(msg),
            backgroundColor: ConstantColors.warning,
          ),
        );
        return;
      }

      _buenVisto.remove(tareaId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Tarea descartada'), backgroundColor: Colors.redAccent),
      );
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
        final esPool = (t['es_pool']?.toString() ?? '0') == '1';
        final esHoySeleccionada = estado == 'en_proceso' && (selDia.isEmpty || selDia == hoy);
        final buenVisto = _buenVisto[tareaId] ?? false;

        final fechaMostrar = estado == 'completada' ? fechaReal : fechaProg;
        final horaMostrar = estado == 'completada' ? horaReal : horaProg;

        // Detectar estado "pospuesta": en_proceso con seleccionada_dia distinta a hoy
        final esPospuesta = estado == 'en_proceso' && selDia.isNotEmpty && selDia != hoy;

        final isProg = estado == 'programada' || estado == 'pendiente' || esPospuesta;
        final isProc = estado == 'en_proceso' && !esPospuesta;
        final isDone = estado == 'completada';
        final isCancel = estado == 'cancelada';
        final isPosp = esPospuesta;

        final badgeLabel = isPosp
            ? 'programada'
            : (estado.isEmpty ? '—' : estado);

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
                  // Badge "Disponible" para tareas del pool
                  if (esPool) ...[
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: Colors.teal.shade700.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: Colors.teal.shade300),
                      ),
                      child: Text(
                        'Disponible',
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          fontSize: 11,
                          color: Colors.teal.shade200,
                        ),
                      ),
                    ),
                    const SizedBox(width: 6),
                  ],
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: badgeBg,
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: badgeBorder),
                    ),
                    child: Text(
                      badgeLabel,
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
                            : (isPosp
                                ? ElevatedButton(
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
                                          backgroundColor: esPool
                                              ? Colors.teal.shade700
                                              : ConstantColors.warning,
                                          foregroundColor: Colors.white,
                                          padding: const EdgeInsets.symmetric(vertical: 12),
                                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                        ),
                                        child: Text(
                                          esPool ? 'Tomar tarea' : 'Seleccionar hoy',
                                          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12),
                                        ),
                                      )))),
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

              if (isDone) ...[
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: tareaId.isEmpty
                        ? null
                        : () => _abrirEdicionEncuesta(t),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: ConstantColors.warning,
                      side: BorderSide(
                          color: ConstantColors.warning.withOpacity(0.7)),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                    icon: const Icon(Icons.edit_note_rounded, size: 18),
                    label: const Text(
                      'Modificar datos',
                      style: TextStyle(
                          fontWeight: FontWeight.w800, fontSize: 12),
                    ),
                  ),
                ),
              ],

              // ── Botón "Ir a actividad": solo cuando la tarea ya fue
              // seleccionada para hoy (en_proceso). Antes de seleccionarla
              // (programada) no debe aparecer, solo "Seleccionar hoy".
              if (isProc) ...[
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: tareaId.isEmpty ? null : () => _irAActividad(t),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: isProc
                          ? ConstantColors.primaryBlue
                          : const Color(0xFF3B5BDB),
                      foregroundColor: Colors.white,
                      elevation: 0,
                      padding: const EdgeInsets.symmetric(vertical: 13),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                    icon: Icon(_iconActividad(tipo), size: 18),
                    label: Text(
                      _labelActividad(tipo),
                      style: const TextStyle(
                          fontWeight: FontWeight.w900, fontSize: 13),
                    ),
                  ),
                ),
              ],

              if (isProc) ...[
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(0.06),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: ConstantColors.borderColor),
                        ),
                        child: Row(
                          children: [
                            Checkbox(
                              value: buenVisto,
                              onChanged: tareaId.isEmpty
                                  ? null
                                  : (v) {
                                      setState(() {
                                        _buenVisto[tareaId] = v ?? false;
                                      });
                                    },
                              visualDensity: VisualDensity.compact,
                              materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                              side: BorderSide(color: Colors.white.withOpacity(0.35)),
                              activeColor: Colors.green.shade700,
                              checkColor: Colors.white,
                            ),
                            const SizedBox(width: 6),
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
                    const SizedBox(width: 10),
                    OutlinedButton.icon(
                      onPressed: tareaId.isEmpty
                          ? null
                          : () async {
                              final picked = await showDatePicker(
                                context: context,
                                initialDate: DateTime.now().add(const Duration(days: 1)),
                                firstDate: DateTime.now(),
                                lastDate: DateTime.now().add(const Duration(days: 365)),
                              );
                              if (picked == null) return;
                              await _posponerTarea(tareaId, picked);
                            },
                      style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.white,
                        side: BorderSide(color: Colors.white.withOpacity(0.25)),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      icon: const Icon(Icons.event_repeat_rounded, size: 16),
                      label: const Text(
                        'Posponer',
                        style: TextStyle(fontWeight: FontWeight.w900, fontSize: 12),
                      ),
                    ),
                    const SizedBox(width: 10),
                    ElevatedButton.icon(
                      onPressed: (tareaId.isEmpty || !buenVisto) ? null : () => _finalizarTarea(tareaId),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.green.shade700,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      icon: const Icon(Icons.check_circle_rounded, size: 16),
                      label: const Text(
                        'Finalizar',
                        style: TextStyle(fontWeight: FontWeight.w900, fontSize: 12),
                      ),
                    ),
                  ],
                ),
              ],

              // ── Descartar: el cliente/prospecto ya no quiere continuar.
              // Solo disponible cuando el asesor ya "hizo suya" la tarea
              // (la seleccionó y quedó en_proceso). Una tarea todavía
              // 'pendiente'/'programada' (sin seleccionar por nadie, p. ej.
              // las del pool o las vencidas sin tomar) no debe poder
              // descartarse desde acá: nadie la ha tomado todavía.
              if (isProc) ...[
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: tareaId.isEmpty
                        ? null
                        : () => _confirmarYDescartarTarea(tareaId),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: Colors.red.shade300,
                      side: BorderSide(color: Colors.red.shade300.withOpacity(0.6)),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    icon: const Icon(Icons.block_rounded, size: 16),
                    label: const Text(
                      'Descartado',
                      style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12),
                    ),
                  ),
                ),
              ],
            ],
          ),
        );
      }

      final widgets = <Widget>[];

      if (_mostrandoCacheOffline) {
        widgets.add(
          Container(
            margin: const EdgeInsets.only(bottom: 14),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: ConstantColors.warning.withOpacity(0.12),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: ConstantColors.warning.withOpacity(0.5)),
            ),
            child: Row(
              children: [
                Icon(Icons.cloud_off_rounded, size: 18, color: ConstantColors.warning),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Sin internet: mostrando solo las tareas que ya fijaste para hoy '
                    '(guardadas en el celular). El resto de la agenda se actualizará '
                    'al recuperar conexión.',
                    style: TextStyle(color: ConstantColors.warning, fontSize: 12, fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
          ),
        );
      }

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
        final now = DateTime.now();
        final hoyDate = DateTime(now.year, now.month, now.day);
        final finSemana = hoyDate.add(Duration(days: 7 - hoyDate.weekday));

        // Helpers de fecha
        DateTime? _parseDate(String? s) {
          if (s == null || s.isEmpty) return null;
          try { return DateTime.parse(s.split('T').first); } catch (_) { return null; }
        }

        final poolTareas     = otras.where((t) => (t['es_pool']?.toString() ?? '0') == '1').toList();
        final propiasTareas  = otras.where((t) => (t['es_pool']?.toString() ?? '0') != '1').toList();

        // Agrupar propias por período
        final vencidas   = propiasTareas.where((t) {
          final f = _parseDate(t['fecha_programada']?.toString());
          return f != null && f.isBefore(hoyDate);
        }).toList();

        final tareasHoy  = propiasTareas.where((t) {
          final f = _parseDate(t['fecha_programada']?.toString());
          return f != null && f.isAtSameMomentAs(hoyDate);
        }).toList();

        final estaSemana = propiasTareas.where((t) {
          final f = _parseDate(t['fecha_programada']?.toString());
          return f != null && f.isAfter(hoyDate) && !f.isAfter(finSemana);
        }).toList();

        final futuras    = propiasTareas.where((t) {
          final f = _parseDate(t['fecha_programada']?.toString());
          return f != null && f.isAfter(finSemana);
        }).toList();

        // Sin fecha programada → agrupar aparte
        final sinFecha   = propiasTareas.where((t) {
          final f = _parseDate(t['fecha_programada']?.toString());
          return f == null;
        }).toList();

        // Caso especial sin internet: como la copia local solo tiene las
        // tareas ya fijadas de hoy (ver TareasFijadasCacheService), acá
        // caerían todas dentro de "tareasHoy" salvo que falte
        // fecha_programada o esté vencida/futura por algún desfase de
        // reloj — en cualquier caso, si no cayeron en ningún grupo no hay
        // que mostrar "No hay tareas para mostrar" (sería engañoso, si
        // tiene tareas fijadas): se listan igual bajo su propia sección.
        if (poolTareas.isEmpty && propiasTareas.isEmpty) {
          if (_mostrandoCacheOffline && _tareas.isNotEmpty) {
            widgets.add(
              Padding(
                padding: const EdgeInsets.only(top: 6, bottom: 10),
                child: Row(
                  children: [
                    Icon(Icons.lock_rounded, size: 16, color: ConstantColors.warning),
                    const SizedBox(width: 6),
                    Text('Tareas fijadas de hoy (sin conexión)',
                        style: TextStyle(color: ConstantColors.warning, fontWeight: FontWeight.w800, fontSize: 14)),
                  ],
                ),
              ),
            );
            for (final t in _tareas) {
              widgets.add(card(t));
              widgets.add(const SizedBox(height: 10));
            }
            return buildBaseList(children: widgets);
          }
          return buildEmpty('No hay tareas para mostrar.');
        }

        void addSection(String title, List<Map<String,dynamic>> items, {Color? titleColor, IconData? icon}) {
          if (items.isEmpty) return;
          widgets.add(
            Padding(
              padding: const EdgeInsets.only(top: 6, bottom: 10),
              child: Row(
                children: [
                  if (icon != null) ...[Icon(icon, size: 16, color: titleColor ?? ConstantColors.textWhite), const SizedBox(width: 6)],
                  Text(title, style: TextStyle(color: titleColor ?? ConstantColors.textWhite, fontWeight: FontWeight.w800, fontSize: 14)),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: (titleColor ?? ConstantColors.textWhite).withOpacity(0.12),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text('${items.length}', style: TextStyle(color: titleColor ?? ConstantColors.textWhite, fontWeight: FontWeight.w700, fontSize: 12)),
                  ),
                ],
              ),
            ),
          );
          for (final t in items) {
            widgets.add(card(t));
            widgets.add(const SizedBox(height: 10));
          }
        }

        // Pool primero
        if (poolTareas.isNotEmpty) {
          addSection('Tareas disponibles', poolTareas, titleColor: Colors.teal.shade200, icon: Icons.inbox_rounded);
          widgets.add(const SizedBox(height: 4));
        }

        // Luego propias agrupadas
        addSection('Vencidas / sin hacer', vencidas,   titleColor: Colors.red.shade300,    icon: Icons.warning_amber_rounded);
        addSection('Hoy',                  tareasHoy,  titleColor: Colors.amber.shade300,  icon: Icons.today_rounded);
        addSection('Esta semana',          estaSemana, titleColor: Colors.blue.shade300,   icon: Icons.date_range_rounded);
        addSection('Próximas semanas',     futuras,    titleColor: Colors.green.shade300,  icon: Icons.event_rounded);
        addSection('Sin fecha asignada',   sinFecha,   titleColor: ConstantColors.textGrey, icon: Icons.schedule_rounded);
      }

      if (widgets.isNotEmpty) {
        if (widgets.last is SizedBox) widgets.removeLast();
      }

      return buildBaseList(children: widgets);
    }

    // ── Pestaña "Rechazadas": tareas que el asesor descartó (estado
    // 'cancelada'), con el motivo indicado al descartarlas.
    Widget _cardRechazada(Map<String, dynamic> t) {
      final tipo = t['tipo_tarea']?.toString() ?? '';
      final cliente = t['cliente_nombre']?.toString() ?? 'Cliente';
      final ciudad = t['cliente_ciudad']?.toString() ?? '';
      final motivo = t['motivo_descarte']?.toString() ?? '';
      final fechaDescarte = t['descartada_at']?.toString() ?? '';
      final fechaMostrar = fechaDescarte.isNotEmpty
          ? fechaDescarte.split(' ').first
          : (t['fecha_programada']?.toString() ?? '');

      return Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.red.shade300.withOpacity(0.35)),
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
                    color: Colors.red.shade50.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: Colors.red.shade300),
                  ),
                  child: Text(
                    'Rechazada',
                    style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12, color: Colors.red.shade300),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(cliente, style: const TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700)),
            if (ciudad.trim().isNotEmpty) ...[
              const SizedBox(height: 2),
              Text(ciudad, style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
            ],
            if (fechaMostrar.isNotEmpty) ...[
              const SizedBox(height: 8),
              Row(
                children: [
                  const Icon(Icons.event_busy_rounded, size: 16, color: ConstantColors.textGrey),
                  const SizedBox(width: 6),
                  Text(fechaMostrar, style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
                ],
              ),
            ],
            if (motivo.trim().isNotEmpty) ...[
              const SizedBox(height: 8),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: Colors.white.withOpacity(0.05),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  motivo,
                  style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12.5, fontStyle: FontStyle.italic),
                ),
              ),
            ],
          ],
        ),
      );
    }

    Widget buildRechazadas() {
      if (_loading) return buildLoading();
      if (_error != null) return buildError();

      final rechazadas = _tareas.where((t) => (t['estado']?.toString() ?? '') == 'cancelada').toList();
      rechazadas.sort((a, b) {
        final fa = (a['descartada_at']?.toString().isNotEmpty ?? false) ? a['descartada_at'].toString() : (a['created_at']?.toString() ?? '');
        final fb = (b['descartada_at']?.toString().isNotEmpty ?? false) ? b['descartada_at'].toString() : (b['created_at']?.toString() ?? '');
        return fb.compareTo(fa); // más reciente primero
      });

      if (rechazadas.isEmpty) {
        return buildEmpty('No tienes tareas rechazadas.');
      }

      return buildBaseList(children: [
        for (final t in rechazadas) ...[
          _cardRechazada(t),
          const SizedBox(height: 10),
        ],
      ]);
    }

    return DefaultTabController(
      length: 3,
      initialIndex: 1,
      child: Scaffold(
        backgroundColor: ConstantColors.backgroundDark,
        appBar: AppBar(
          backgroundColor: ConstantColors.backgroundDark,
          foregroundColor: ConstantColors.textWhite,
          elevation: 0,
          title: Text(
            'Lista tareas (${_tareas.length})',
            style: const TextStyle(fontWeight: FontWeight.w800),
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
              Tab(text: 'Rechazadas'),
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
            RefreshIndicator(
              onRefresh: _cargar,
              child: buildRechazadas(),
            ),
          ],
        ),
      ),
    );
  }
}
