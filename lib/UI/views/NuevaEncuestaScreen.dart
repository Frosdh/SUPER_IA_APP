import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';

// ─────────────────────────────────────────────────────────────
//  Paso de la encuesta 
// ─────────────────────────────────────────────────────────────
enum _Paso {
  inicial,
  datosCliente,
  productosActuales,
  interesProductos,
  busqueda
}

class NuevaEncuestaScreen extends StatefulWidget {
  final String tipoTarea;

  const NuevaEncuestaScreen({Key? key, this.tipoTarea = 'prospecto_nuevo'})
      : super(key: key);

  @override
  _NuevaEncuestaScreenState createState() => _NuevaEncuestaScreenState();
}

class _NuevaEncuestaScreenState extends State<NuevaEncuestaScreen> {
  _Paso _paso = _Paso.inicial;
  bool _guardando = false;

  // GPS
  double? _latInicio, _lngInicio;
  double? _latFin, _lngFin;

  // ── Paso 1: Datos del cliente ────────────────────────────────
  final _nombreCtrl = TextEditingController();
  final _apellidosCtrl = TextEditingController();
  final _cedulaCtrl = TextEditingController();
  final _telefonoCtrl = TextEditingController();
  final _celularCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _direccionCtrl = TextEditingController();
  String? _actividad;
  bool _tieneRuc = false;
  bool _tieneRise = false;
  bool _tieneEmpresa = false;
  final _empresaCtrl = TextEditingController();
  final _formKeyCliente = GlobalKey<FormState>();

  // ── Paso 2: Productos actuales ───────────────────────────────
  bool _mantieneAhorro = false;
  bool _mantieneCorriente = false;
  bool? _tieneInversiones;
  final _instInvCtrl = TextEditingController();
  final _valorInvCtrl = TextEditingController();
  final _plazoInvCtrl = TextEditingController();
  DateTime? _fechaVencInv;
  bool? _tieneOpsCred;
  final _instCredCtrl = TextEditingController();
  bool? _mantieneProdFin;
  final _instProdFinCtrl = TextEditingController();

  // ── Paso 3: Interés en productos ────────────────────────────
  bool? _interesConocer;
  bool _interesCC = false;
  bool _interesAhorro = false;
  bool _interesInv = false;
  bool _interesCred = false;
  // Razones de NO
  bool _razonYaTrabaja = false;
  bool _razonDesconfia = false;
  bool _razonAGusto = false;
  bool _razonMalaExp = false;
  final _razonOtrosCtrl = TextEditingController();

  // ── Paso 4: Búsqueda y acuerdo ──────────────────────────────
  bool? _interesTrabajar;
  bool _buscaAgilidad = false;
  bool _buscaCajeros = false;
  bool _buscaBanca = false;
  bool _buscaAgencias = false;
  bool _buscaCreditoR = false;
  bool _buscaTD = false;
  bool _buscaTC = false;
  DateTime? _fechaVencCDP;
  String _acuerdo = 'ninguno';
  DateTime? _fechaAcuerdo;
  TimeOfDay? _horaAcuerdo;
  final _obsCtrl = TextEditingController();

  // ─────────────────────────────────────────────────────────────

  @override
  void initState() {
    super.initState();
    _obtenerGPS();
  }

  @override
  void dispose() {
    _nombreCtrl.dispose();
    _apellidosCtrl.dispose();
    _cedulaCtrl.dispose();
    _telefonoCtrl.dispose();
    _celularCtrl.dispose();
    _emailCtrl.dispose();
    _direccionCtrl.dispose();
    _empresaCtrl.dispose();
    _instInvCtrl.dispose();
    _valorInvCtrl.dispose();
    _plazoInvCtrl.dispose();
    _instCredCtrl.dispose();
    _instProdFinCtrl.dispose();
    _razonOtrosCtrl.dispose();
    _obsCtrl.dispose();
    super.dispose();
  }

  Future<void> _obtenerGPS() async {
    try {
      final pos = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      ).timeout(const Duration(seconds: 10));
      if (mounted) {
        setState(() {
          _latInicio = pos.latitude;
          _lngInicio = pos.longitude;
        });
      }
    } catch (_) {}
  }

  Future<void> _capturarGPSFinal() async {
    try {
      final pos = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      ).timeout(const Duration(seconds: 8));
      _latFin = pos.latitude;
      _lngFin = pos.longitude;
    } catch (_) {
      _latFin = _latInicio;
      _lngFin = _lngInicio;
    }
  }

  // ── Guardado en servidor ─────────────────────────────────────

  Map<String, dynamic>? _tryDecodeJsonMap(String body) {
    try {
      final decoded = json.decode(body);
      if (decoded is Map<String, dynamic>) return decoded;
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
      return null;
    } catch (_) {
      return null;
    }
  }

  Future<void> _guardarEncuesta({bool fueEncuestado = true}) async {
    if (_guardando) return;
    setState(() => _guardando = true);

    await _capturarGPSFinal();

    // Obtener usuario_id; si está vacío mostrar error claro al usuario
    final usuarioId = await AuthPrefs.getUsuarioId();
    final asesorId = await AuthPrefs.getAsesorId();
    if (usuarioId.isEmpty) {
      setState(() => _guardando = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Error: sesión no encontrada. Por favor cierra sesión y vuelve a ingresar.',
            ),
            backgroundColor: Colors.red,
            duration: Duration(seconds: 5),
          ),
        );
      }
      return;
    }

    final body = <String, String>{
      'usuario_id': usuarioId,
      if (asesorId.isNotEmpty) 'asesor_id': asesorId,
      'tipo_tarea': widget.tipoTarea,
      'fue_encuestado': fueEncuestado ? '1' : '0',
      // Cliente
      'nombre': _nombreCtrl.text.trim(),
      'apellidos': _apellidosCtrl.text.trim(),
      'cedula': _cedulaCtrl.text.trim(),
      'telefono': _telefonoCtrl.text.trim(),
      'celular': _celularCtrl.text.trim(),
      'email_cliente': _emailCtrl.text.trim(),
      'direccion': _direccionCtrl.text.trim(),
      'actividad': _actividad ?? '',
      'tiene_ruc': _tieneRuc ? '1' : '0',
      'tiene_rise': _tieneRise ? '1' : '0',
      'tiene_empresa': _tieneEmpresa ? '1' : '0',
      'nombre_empresa': _empresaCtrl.text.trim(),
      // GPS
      'latitud_inicio': (_latInicio ?? 0).toString(),
      'longitud_inicio': (_lngInicio ?? 0).toString(),
      'latitud_fin': (_latFin ?? 0).toString(),
      'longitud_fin': (_lngFin ?? 0).toString(),
    };

    if (fueEncuestado) {
      body.addAll({
        'mantiene_cuenta_ahorro': _mantieneAhorro ? '1' : '0',
        'mantiene_cuenta_corriente': _mantieneCorriente ? '1' : '0',
        'tiene_inversiones':
            _tieneInversiones == null ? '' : (_tieneInversiones! ? '1' : '0'),
        'institucion_inversiones': _instInvCtrl.text.trim(),
        'valor_inversion': _valorInvCtrl.text.trim(),
        'plazo_inversion': _plazoInvCtrl.text.trim(),
        'fecha_vencimiento_inversion': _fechaVencInv != null
            ? '${_fechaVencInv!.year}-${_fechaVencInv!.month.toString().padLeft(2, '0')}-${_fechaVencInv!.day.toString().padLeft(2, '0')}'
            : '',
        'tiene_operaciones_crediticias':
            _tieneOpsCred == null ? '' : (_tieneOpsCred! ? '1' : '0'),
        'institucion_credito': _instCredCtrl.text.trim(),
        'mantiene_producto_financiero':
            _mantieneProdFin == null ? '' : (_mantieneProdFin! ? '1' : '0'),
        'institucion_producto_financiero': _instProdFinCtrl.text.trim(),
        'interes_conocer_productos':
            _interesConocer == null ? '' : (_interesConocer! ? '1' : '0'),
        'nivel_interes':
            (_interesCC || _interesAhorro || _interesInv || _interesCred)
                ? 'alto'
                : (_interesConocer == true ? 'bajo' : 'ninguno'),
        'interes_cc': _interesCC ? '1' : '0',
        'interes_ahorro': _interesAhorro ? '1' : '0',
        'interes_inversion': _interesInv ? '1' : '0',
        'interes_credito': _interesCred ? '1' : '0',
        'razon_ya_trabaja_institucion': _razonYaTrabaja ? '1' : '0',
        'razon_desconfia_servicios': _razonDesconfia ? '1' : '0',
        'razon_agusto_actual': _razonAGusto ? '1' : '0',
        'razon_mala_experiencia': _razonMalaExp ? '1' : '0',
        'razon_otros': _razonOtrosCtrl.text.trim(),
        'interes_trabajar_institucion':
            _interesTrabajar == null ? '' : (_interesTrabajar! ? '1' : '0'),
        'que_busca_agilidad': _buscaAgilidad ? '1' : '0',
        'que_busca_cajeros': _buscaCajeros ? '1' : '0',
        'que_busca_banca_linea': _buscaBanca ? '1' : '0',
        'que_busca_agencias': _buscaAgencias ? '1' : '0',
        'que_busca_credito_rapido': _buscaCreditoR ? '1' : '0',
        'que_busca_tarjeta_debito': _buscaTD ? '1' : '0',
        'que_busca_tarjeta_credito': _buscaTC ? '1' : '0',
        'fecha_vencimiento_cdp': _fechaVencCDP != null
            ? '${_fechaVencCDP!.year}-${_fechaVencCDP!.month.toString().padLeft(2, '0')}-${_fechaVencCDP!.day.toString().padLeft(2, '0')}'
            : '',
        'acuerdo_logrado': _acuerdo,
        'fecha_acuerdo': _fechaAcuerdo != null
            ? '${_fechaAcuerdo!.year}-${_fechaAcuerdo!.month.toString().padLeft(2, '0')}-${_fechaAcuerdo!.day.toString().padLeft(2, '0')}'
            : '',
        'hora_acuerdo': _horaAcuerdo != null
            ? '${_horaAcuerdo!.hour.toString().padLeft(2, '0')}:${_horaAcuerdo!.minute.toString().padLeft(2, '0')}:00'
            : '',
        'observaciones': _obsCtrl.text.trim(),
      });
    }

    try {
      final url = Uri.parse('${Constants.apiBaseUrl}/guardar_cliente_encuesta.php');
      debugPrint(
        '>>> [ENC] POST $url usuario_id=$usuarioId asesor_id=${asesorId.isNotEmpty ? asesorId : '-'} fue_encuestado=${fueEncuestado ? 1 : 0}',
      );

      final resp = await http
          .post(
            url,
            body: body,
          )
          .timeout(const Duration(seconds: 20));

      if (!mounted) return;

      final rawBody = resp.body;
      debugPrint('>>> [ENC] HTTP ${resp.statusCode} len=${rawBody.length} headers=${resp.headers}');
      if (rawBody.isNotEmpty) {
        final preview = rawBody.length > 500 ? rawBody.substring(0, 500) : rawBody;
        debugPrint('>>> [ENC] body(0..${preview.length}): $preview');
      }
      if (rawBody.trim().isEmpty) {
        _mostrarError(
          'El servidor respondió HTTP ${resp.statusCode} sin body.\n'
          'Esto suele ser un fatal en PHP o el hosting no tiene los archivos actualizados.\n'
          'Revise/actualice: server_php/guardar_cliente_encuesta.php y server_php/db_config.php en el hosting.\n'
          'Endpoint: ${Constants.apiBaseUrl}/guardar_cliente_encuesta.php',
        );
        return;
      }

      final data = _tryDecodeJsonMap(rawBody);
      if (data == null) {
        _mostrarError(
          'Respuesta inválida del servidor (HTTP ${resp.statusCode}).',
        );
        return;
      }

      if (resp.statusCode != 200) {
        _mostrarError(data['message']?.toString() ?? 'Error HTTP ${resp.statusCode}');
        return;
      }

      if (data['status'] == 'success') {
        // ── Cerrar segmento de ruta actual e iniciar el siguiente ──
        final tareaId = data['tarea_id']?.toString() ?? '';
        _cerrarYNuevoSegmento(tareaId: tareaId);

        _mostrarDialogoFinalizado(fueEncuestado: fueEncuestado);
      } else {
        _mostrarError(data['message']?.toString() ?? 'Error al guardar');
      }
    } catch (e) {
      if (!mounted) return;
      _mostrarError('No se pudo guardar en el servidor. ($e)');
    } finally {
      if (mounted) setState(() => _guardando = false);
    }
  }

  /// Cierra el segmento activo y abre uno nuevo (no bloquea la UI).
  Future<void> _cerrarYNuevoSegmento({required String tareaId}) async {
    try {
      final asesorId  = await AuthPrefs.getAsesorId();
      final usuarioId = await AuthPrefs.getUsuarioId();

      // Obtener posición actual para el punto de corte
      double? lat, lng;
      try {
        final pos = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.high,
        ).timeout(const Duration(seconds: 6));
        lat = pos.latitude;
        lng = pos.longitude;
      } catch (_) {
        // Sin GPS en este momento; se guarda sin coordenada de corte
      }

      await http.post(
        Uri.parse('${Constants.apiBaseUrl}/api_cerrar_segmento.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {
          'asesor_id':  asesorId,
          'usuario_id': usuarioId,
          'tarea_id':   tareaId,
          'latitud':    lat?.toString() ?? '',
          'longitud':   lng?.toString() ?? '',
          'razon':      'tarea_completada',
        },
      ).timeout(const Duration(seconds: 8));

      debugPrint('✅ Segmento cerrado y nuevo iniciado (tarea=$tareaId)');
    } catch (e) {
      debugPrint('⚠️ Error al gestionar segmento de ruta: $e');
    }
  }

  void _mostrarDialogoFinalizado({required bool fueEncuestado}) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => Dialog(
        backgroundColor: ConstantColors.grey100,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 64,
                height: 64,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(
                    colors: [
                      ConstantColors.warning,
                      ConstantColors.primaryBlue
                    ],
                  ),
                ),
                child: Icon(Icons.check_rounded, color: Colors.white, size: 34),
              ),
              const SizedBox(height: 18),
              Text(
                'Tarea Finalizada',
                style: TextStyle(
                  color: ConstantColors.textDark,
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                fueEncuestado
                    ? 'Encuesta y datos del cliente guardados correctamente.'
                    : 'Se registró que el cliente no quiso ser encuestado.',
                style:
                    TextStyle(color: ConstantColors.textDarkGrey, fontSize: 14),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () {
                    Navigator.of(ctx).pop();
                    Navigator.of(context).pop();
                  },
                  style: ElevatedButton.styleFrom(
                    backgroundColor: ConstantColors.warning,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: const Text('Volver al mapa',
                      style: TextStyle(fontWeight: FontWeight.w700)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _mostrarError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: ConstantColors.error,
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  // ── Navegación entre pasos ───────────────────────────────────

  void _irSiguientePaso() {
    final pasos = _Paso.values;
    final idx = pasos.indexOf(_paso);
    if (idx < pasos.length - 1) {
      setState(() => _paso = pasos[idx + 1]);
    }
  }

  void _irPasoPrevio() {
    final pasos = _Paso.values;
    final idx = pasos.indexOf(_paso);
    if (idx > 0) {
      setState(() => _paso = pasos[idx - 1]);
    } else {
      Navigator.pop(context);
    }
  }

  int get _indexPaso => _Paso.values.indexOf(_paso);
  int get _totalPasos =>
      _Paso.values.length - 1; // inicial no cuenta en progreso

  // ── BUILD ────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvoked: (didPop) {
        if (!didPop) _confirmarSalida();
      },
      child: Scaffold(
        backgroundColor: ConstantColors.grey100,
        body: SafeArea(
          child: Column(
            children: [
              _buildAppBar(),
              if (_paso != _Paso.inicial) _buildProgreso(),
              Expanded(
                child: AnimatedSwitcher(
                  duration: const Duration(milliseconds: 280),
                  child: SingleChildScrollView(
                    key: ValueKey(_paso),
                    padding: const EdgeInsets.fromLTRB(20, 16, 20, 100),
                    child: _buildContenidoPaso(),
                  ),
                ),
              ),
            ],
          ),
        ),
        floatingActionButtonLocation: FloatingActionButtonLocation.centerFloat,
        floatingActionButton: _buildBotonesNavegacion(),
      ),
    );
  }

  // ── App Bar ──────────────────────────────────────────────────

  Widget _buildAppBar() {
    return Container(
      padding: const EdgeInsets.fromLTRB(8, 12, 16, 8),
      child: Row(
        children: [
          IconButton(
            icon: Icon(Icons.arrow_back_ios_rounded,
                color: ConstantColors.textDark, size: 22),
            onPressed: _paso == _Paso.inicial
                ? () => Navigator.pop(context)
                : _irPasoPrevio,
          ),
          Expanded(
            child: Text(
              _tituloPaso(),
              style: TextStyle(
                color: ConstantColors.textDark,
                fontSize: 17,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          // Indicador GPS
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: _latInicio != null
                  ? ConstantColors.success.withOpacity(0.15)
                  : ConstantColors.warning.withOpacity(0.15),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(
                color: _latInicio != null
                    ? ConstantColors.success
                    : ConstantColors.warning,
              ),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.gps_fixed_rounded,
                    size: 14,
                    color: _latInicio != null
                        ? ConstantColors.success
                        : ConstantColors.warning),
                const SizedBox(width: 4),
                Text(
                  _latInicio != null ? 'GPS OK' : 'Sin GPS',
                  style: TextStyle(
                    color: _latInicio != null
                        ? ConstantColors.success
                        : ConstantColors.warning,
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _tituloPaso() {
    switch (_paso) {
      case _Paso.inicial:
        return widget.tipoTarea == 'recuperacion'
            ? 'Visita de Recuperación'
            : 'Nueva Tarea';
      case _Paso.datosCliente:
        return 'Datos del Cliente';
      case _Paso.productosActuales:
        return 'Situación Financiera';
      case _Paso.interesProductos:
        return 'Interés en Productos';
      case _Paso.busqueda:
        return 'Acuerdo y Cierre';
    }
  }

  // ── Barra de progreso ────────────────────────────────────────

  Widget _buildProgreso() {
    final total = 4;
    final actual = _indexPaso; // 1-4 después de inicial
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: List.generate(total, (i) {
              final done = i < actual;
              final current = i == actual - 1;
              return Expanded(
                child: Container(
                  height: 4,
                  margin: EdgeInsets.only(right: i < total - 1 ? 4 : 0),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(4),
                    gradient:
                        done || current ? ConstantColors.greyGradient : null,
                    color: done || current
                        ? null
                        : ConstantColors.primaryNavyLight,
                  ),
                ),
              );
            }),
          ),
          const SizedBox(height: 6),
          Text(
            'Paso $actual de $total',
            style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 11),
          ),
        ],
      ),
    );
  }

  // ── Contenido por paso ───────────────────────────────────────

  Widget _buildContenidoPaso() {
    switch (_paso) {
      case _Paso.inicial:
        return _buildPasoInicial();
      case _Paso.datosCliente:
        return _buildPasoDatosCliente();
      case _Paso.productosActuales:
        return _buildPasoProductosActuales();
      case _Paso.interesProductos:
        return _buildPasoInteresProductos();
      case _Paso.busqueda:
        return _buildPasoBusqueda();
    }
  }

  // ── PASO 0: Pregunta inicial ─────────────────────────────────

  Widget _buildPasoInicial() {
    return Column(
      children: [
        const SizedBox(height: 20),
        Container(
          width: 80,
          height: 80,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: LinearGradient(
              colors: [
                ConstantColors.primaryBlue.withOpacity(0.4),
                ConstantColors.backgroundAmber.withOpacity(0.4)
              ],
            ),
          ),
          child: Icon(
            widget.tipoTarea == 'recuperacion'
                ? Icons.loop_rounded
                : Icons.person_add_rounded,
            color: ConstantColors.primaryBlue,
            size: 38,
          ),
        ),
        const SizedBox(height: 24),
        Text(
          '¿El cliente desea ser\nencuestado?',
          style: TextStyle(
            color: ConstantColors.textDark,
            fontSize: 22,
            fontWeight: FontWeight.w700,
            height: 1.3,
          ),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 10),
        Text(
          'Seleccione la respuesta del cliente para continuar.',
          style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 14),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 48),
        Row(
          children: [
            Expanded(
              child: _botonRespuestaGrande(
                label: 'SÍ',
                sublabel: 'Continuar con encuesta',
                color: ConstantColors.success,
                icon: Icons.check_circle_rounded,
                onTap: () => setState(() => _paso = _Paso.datosCliente),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _botonRespuestaGrande(
                label: 'NO',
                sublabel: 'Finalizar tarea',
                color: ConstantColors.error,
                icon: Icons.cancel_rounded,
                onTap: () => _guardarSinEncuesta(),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _botonRespuestaGrande({
    required String label,
    required String sublabel,
    required Color color,
    required IconData icon,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 16),
        decoration: BoxDecoration(
          color: color.withOpacity(0.1),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: color.withOpacity(0.4), width: 1.5),
        ),
        child: Column(
          children: [
            Icon(icon, color: color, size: 40),
            const SizedBox(height: 10),
            Text(label,
                style: TextStyle(
                    color: color, fontSize: 22, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            Text(sublabel,
                style: TextStyle(color: color.withOpacity(0.7), fontSize: 11),
                textAlign: TextAlign.center),
          ],
        ),
      ),
    );
  }

  Future<void> _guardarSinEncuesta() async {
    // Requiere al menos nombre para registrar que pasamos por aquí
    _mostrarDialogConfirmarNoEncuesta();
  }

  void _mostrarDialogConfirmarNoEncuesta() {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ConstantColors.grey100,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: Text('Confirmar',
            style: TextStyle(
                color: ConstantColors.textDark, fontWeight: FontWeight.w700)),
        content: Text(
          '¿Desea registrar esta visita sin encuesta? Se guardará la ubicación GPS.',
          style: TextStyle(color: ConstantColors.textDarkGrey),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('Cancelar',
                style: TextStyle(color: ConstantColors.textDarkGrey)),
          ),
          ElevatedButton(
            onPressed: () {
              Navigator.pop(ctx);
              _guardarEncuesta(fueEncuestado: false);
            },
            style: ElevatedButton.styleFrom(
              backgroundColor: ConstantColors.warning,
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12)),
            ),
            child: const Text('Registrar visita'),
          ),
        ],
      ),
    );
  }

  // ── PASO 1: Datos del cliente ────────────────────────────────

  Widget _buildPasoDatosCliente() {
    return Form(
      key: _formKeyCliente,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _seccionTitulo('Información Personal'),
          _campo(
            controller: _nombreCtrl,
            label: 'Nombres *',
            icon: Icons.person_rounded,
            validator: (v) =>
                (v == null || v.trim().isEmpty) ? 'Campo requerido' : null,
          ),
          _campo(
            controller: _apellidosCtrl,
            label: 'Apellidos',
            icon: Icons.person_outline_rounded,
          ),
          _campo(
            controller: _cedulaCtrl,
            label: 'Cédula',
            icon: Icons.badge_rounded,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _campo(
            controller: _telefonoCtrl,
            label: 'Teléfono fijo',
            icon: Icons.phone_rounded,
            keyboardType: TextInputType.phone,
          ),
          _campo(
            controller: _celularCtrl,
            label: 'Celular',
            icon: Icons.smartphone_rounded,
            keyboardType: TextInputType.phone,
          ),
          _campo(
            controller: _emailCtrl,
            label: 'Email',
            icon: Icons.email_rounded,
            keyboardType: TextInputType.emailAddress,
          ),
          _campo(
            controller: _direccionCtrl,
            label: 'Dirección',
            icon: Icons.home_rounded,
            maxLines: 2,
          ),
          const SizedBox(height: 20),
          _seccionTitulo('Actividad Económica'),
          _dropdownActividad(),
          const SizedBox(height: 12),
          _switchItem(
            label: '¿Tiene RUC?',
            value: _tieneRuc,
            onChanged: (v) => setState(() => _tieneRuc = v),
          ),
          _switchItem(
            label: '¿Tiene RISE?',
            value: _tieneRise,
            onChanged: (v) => setState(() => _tieneRise = v),
          ),
          _switchItem(
            label: '¿Tiene empresa?',
            value: _tieneEmpresa,
            onChanged: (v) => setState(() => _tieneEmpresa = v),
          ),
          if (_tieneEmpresa) ...[
            const SizedBox(height: 8),
            _campo(
              controller: _empresaCtrl,
              label: 'Nombre de la empresa',
              icon: Icons.business_rounded,
            ),
          ],
        ],
      ),
    );
  }

  // ── PASO 2: Productos actuales ───────────────────────────────

  Widget _buildPasoProductosActuales() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _seccionTitulo('¿Qué cuentas mantiene?'),
        _checkboxItem(
          label: 'Cuenta de Ahorros',
          value: _mantieneAhorro,
          onChanged: (v) => setState(() => _mantieneAhorro = v ?? false),
        ),
        _checkboxItem(
          label: 'Cuenta Corriente',
          value: _mantieneCorriente,
          onChanged: (v) => setState(() => _mantieneCorriente = v ?? false),
        ),
        const SizedBox(height: 20),
        _seccionTitulo('¿Tiene inversiones?'),
        _preguntaSiNo(
          value: _tieneInversiones,
          onChanged: (v) => setState(() => _tieneInversiones = v),
        ),
        if (_tieneInversiones == true) ...[
          const SizedBox(height: 10),
          _campo(
              controller: _instInvCtrl,
              label: 'Institución donde invierte',
              icon: Icons.account_balance_rounded),
          _campo(
              controller: _valorInvCtrl,
              label: 'Valor de la inversión (\$)',
              icon: Icons.attach_money_rounded,
              keyboardType: TextInputType.number),
          _campo(
              controller: _plazoInvCtrl,
              label: 'Plazo',
              icon: Icons.schedule_rounded),
          _fieldFecha(
            label: 'Fecha de vencimiento',
            fecha: _fechaVencInv,
            onPick: () =>
                _seleccionarFecha((d) => setState(() => _fechaVencInv = d)),
          ),
        ],
        const SizedBox(height: 20),
        _seccionTitulo('¿Tiene operaciones crediticias?'),
        _preguntaSiNo(
          value: _tieneOpsCred,
          onChanged: (v) => setState(() => _tieneOpsCred = v),
        ),
        if (_tieneOpsCred == true) ...[
          const SizedBox(height: 10),
          _campo(
              controller: _instCredCtrl,
              label: '¿En qué institución?',
              icon: Icons.account_balance_rounded),
        ],
        const SizedBox(height: 20),
        _seccionTitulo('¿Mantiene actualmente algún\nproducto financiero?'),
        _preguntaSiNo(
          value: _mantieneProdFin,
          onChanged: (v) => setState(() => _mantieneProdFin = v),
        ),
        if (_mantieneProdFin == true) ...[
          const SizedBox(height: 10),
          _campo(
              controller: _instProdFinCtrl,
              label: '¿Con qué institución?',
              icon: Icons.account_balance_rounded),
        ],
      ],
    );
  }

  // ── PASO 3: Interés en productos ─────────────────────────────

  Widget _buildPasoInteresProductos() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _seccionTitulo(
            '¿Le interesaría conocer nuestros\nproductos o servicios?'),
        _preguntaSiNo(
          value: _interesConocer,
          onChanged: (v) {
            setState(() {
              _interesConocer = v;
              if (v == false) {
                // Limpiar selecciones de interés
                _interesCC =
                    _interesAhorro = _interesInv = _interesCred = false;
              } else {
                // Limpiar razones
                _razonYaTrabaja =
                    _razonDesconfia = _razonAGusto = _razonMalaExp = false;
                _razonOtrosCtrl.clear();
              }
            });
          },
        ),
        if (_interesConocer == true) ...[
          const SizedBox(height: 16),
          _seccionTitulo('¿Cuáles productos le interesan?'),
          _checkboxItem(
            label: 'Cuenta Corriente',
            value: _interesCC,
            onChanged: (v) => setState(() => _interesCC = v ?? false),
          ),
          _checkboxItem(
            label: 'Cuenta de Ahorros',
            value: _interesAhorro,
            onChanged: (v) => setState(() => _interesAhorro = v ?? false),
          ),
          _checkboxItem(
            label: 'Inversiones',
            value: _interesInv,
            onChanged: (v) => setState(() => _interesInv = v ?? false),
          ),
          _checkboxItem(
            label: 'Crédito',
            value: _interesCred,
            onChanged: (v) => setState(() => _interesCred = v ?? false),
          ),
        ],
        if (_interesConocer == false) ...[
          const SizedBox(height: 16),
          _seccionTitulo('¿Cuál es la razón?'),
          _checkboxItem(
            label: 'Ya trabaja con su institución por muchos años',
            value: _razonYaTrabaja,
            onChanged: (v) => setState(() => _razonYaTrabaja = v ?? false),
          ),
          _checkboxItem(
            label: 'Desconfía en los servicios a ofrecer',
            value: _razonDesconfia,
            onChanged: (v) => setState(() => _razonDesconfia = v ?? false),
          ),
          _checkboxItem(
            label: 'Está a gusto con la institución actual',
            value: _razonAGusto,
            onChanged: (v) => setState(() => _razonAGusto = v ?? false),
          ),
          _checkboxItem(
            label: 'Mala experiencia con nuestra institución',
            value: _razonMalaExp,
            onChanged: (v) => setState(() => _razonMalaExp = v ?? false),
          ),
          const SizedBox(height: 8),
          _campo(
            controller: _razonOtrosCtrl,
            label: 'Otros (especifique)',
            icon: Icons.edit_rounded,
            maxLines: 2,
          ),
          const SizedBox(height: 20),
          // Terminal: botón Finalizar Tarea
          _botonFinalizar(
            label: 'Finalizar Tarea',
            sublabel: 'Se guardará la encuesta como sin interés',
            onTap: () => _guardarEncuesta(fueEncuestado: true),
          ),
        ],
      ],
    );
  }

  // ── PASO 4: Búsqueda y acuerdo ───────────────────────────────

  Widget _buildPasoBusqueda() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _seccionTitulo('¿Le interesaría trabajar con\nnuestra institución?'),
        _preguntaSiNo(
          value: _interesTrabajar,
          onChanged: (v) => setState(() => _interesTrabajar = v),
        ),
        const SizedBox(height: 20),
        _seccionTitulo('¿Qué busca de una institución\nfinanciera?'),
        _checkboxItem(
            label: 'Agilidad',
            value: _buscaAgilidad,
            onChanged: (v) => setState(() => _buscaAgilidad = v ?? false)),
        _checkboxItem(
            label: 'Cajeros',
            value: _buscaCajeros,
            onChanged: (v) => setState(() => _buscaCajeros = v ?? false)),
        _checkboxItem(
            label: 'Banca en línea',
            value: _buscaBanca,
            onChanged: (v) => setState(() => _buscaBanca = v ?? false)),
        _checkboxItem(
            label: 'Agencias en su sector',
            value: _buscaAgencias,
            onChanged: (v) => setState(() => _buscaAgencias = v ?? false)),
        _checkboxItem(
            label: 'Crédito rápido',
            value: _buscaCreditoR,
            onChanged: (v) => setState(() => _buscaCreditoR = v ?? false)),
        _checkboxItem(
            label: 'Tarjeta débito',
            value: _buscaTD,
            onChanged: (v) => setState(() => _buscaTD = v ?? false)),
        _checkboxItem(
            label: 'Tarjeta crédito',
            value: _buscaTC,
            onChanged: (v) => setState(() => _buscaTC = v ?? false)),
        const SizedBox(height: 20),
        _seccionTitulo('¿Tiene un CDP?'),
        _fieldFecha(
          label: 'Fecha de vencimiento del CDP',
          fecha: _fechaVencCDP,
          onPick: () =>
              _seleccionarFecha((d) => setState(() => _fechaVencCDP = d)),
        ),
        const SizedBox(height: 20),
        _seccionTitulo('Acuerdo Logrado'),
        _dropdownAcuerdo(),
        if (_acuerdo != 'ninguno') ...[
          const SizedBox(height: 12),
          _fieldFecha(
            label: 'Fecha del acuerdo',
            fecha: _fechaAcuerdo,
            onPick: () =>
                _seleccionarFecha((d) => setState(() => _fechaAcuerdo = d)),
          ),
          const SizedBox(height: 8),
          _fieldHora(),
        ],
        const SizedBox(height: 16),
        _campo(
          controller: _obsCtrl,
          label: 'Observaciones',
          icon: Icons.notes_rounded,
          maxLines: 3,
        ),
        const SizedBox(height: 20),
        _botonFinalizar(
          label: 'Finalizar y Guardar',
          sublabel: 'Se guardarán todos los datos de la encuesta',
          onTap: () => _guardarEncuesta(fueEncuestado: true),
        ),
      ],
    );
  }

  // ── Botones de navegación ────────────────────────────────────

  Widget? _buildBotonesNavegacion() {
    if (_paso == _Paso.inicial) return null;
    if (_paso == _Paso.busqueda) return null; // botón inline
    if (_paso == _Paso.interesProductos && _interesConocer == false)
      return null; // botón inline

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Row(
        children: [
          if (_paso != _Paso.datosCliente) ...[
            Expanded(
              flex: 2,
              child: OutlinedButton(
                onPressed: _irPasoPrevio,
                style: OutlinedButton.styleFrom(
                  foregroundColor: ConstantColors.textDarkGrey,
                  side: BorderSide(color: ConstantColors.borderLight),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14)),
                ),
                child: const Text('Atrás'),
              ),
            ),
            const SizedBox(width: 12),
          ],
          Expanded(
            flex: 3,
            child: Container(
              decoration: BoxDecoration(
                gradient: ConstantColors.buttonGradient,
                borderRadius: BorderRadius.circular(14),
                boxShadow: [
                  BoxShadow(
                    color: ConstantColors.warning.withOpacity(0.3),
                    blurRadius: 12,
                    offset: const Offset(0, 4),
                  )
                ],
              ),
              child: ElevatedButton(
                onPressed: _avanzarPaso,
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.transparent,
                  shadowColor: Colors.transparent,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14)),
                ),
                child: Text(
                  _paso == _Paso.productosActuales ? 'Continuar' : 'Siguiente',
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                    fontSize: 15,
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  void _avanzarPaso() {
    if (_paso == _Paso.datosCliente) {
      if (!(_formKeyCliente.currentState?.validate() ?? false)) return;
    }
    _irSiguientePaso();
  }

  // ── Widget helpers ───────────────────────────────────────────

  Widget _seccionTitulo(String titulo) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Text(
        titulo,
        style: TextStyle(
          color: ConstantColors.textDark,
          fontSize: 15,
          fontWeight: FontWeight.w700,
          height: 1.3,
        ),
      ),
    );
  }

  Widget _campo({
    required TextEditingController controller,
    required String label,
    required IconData icon,
    TextInputType keyboardType = TextInputType.text,
    List<TextInputFormatter>? inputFormatters,
    int maxLines = 1,
    String? Function(String?)? validator,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        controller: controller,
        keyboardType: keyboardType,
        inputFormatters: inputFormatters,
        maxLines: maxLines,
        validator: validator,
        style: TextStyle(color: ConstantColors.textDark, fontSize: 14),
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(icon, color: ConstantColors.warning, size: 20),
          filled: true,
          fillColor: ConstantColors.grey100,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: ConstantColors.borderLight),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: ConstantColors.borderLight),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: ConstantColors.warning, width: 1.5),
          ),
          errorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: ConstantColors.error),
          ),
          labelStyle:
              TextStyle(color: ConstantColors.textDarkGrey, fontSize: 13),
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        ),
      ),
    );
  }

  Widget _dropdownActividad() {
    final opciones = [
      ('negocio_propio', 'Negocio Propio'),
      ('empleado_privado', 'Empleado Privado'),
      ('empleado_publico', 'Empleado Público'),
      ('profesional', 'Profesional'),
    ];
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: DropdownButtonFormField<String>(
        value: _actividad,
        hint: Text('Seleccionar actividad',
            style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 13)),
        items: opciones
            .map((o) => DropdownMenuItem(value: o.$1, child: Text(o.$2)))
            .toList(),
        onChanged: (v) => setState(() => _actividad = v),
        dropdownColor: ConstantColors.grey100,
        style: TextStyle(color: ConstantColors.textDark, fontSize: 14),
        decoration: InputDecoration(
          prefixIcon:
              Icon(Icons.work_rounded, color: ConstantColors.warning, size: 20),
          filled: true,
          fillColor: ConstantColors.grey100,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: ConstantColors.borderLight),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: ConstantColors.borderLight),
          ),
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        ),
      ),
    );
  }

  Widget _dropdownAcuerdo() {
    final opciones = [
      ('ninguno', 'Ninguno'),
      ('nueva_cita_campo', 'Nueva cita en campo'),
      ('nueva_cita_oficina', 'Nueva cita en oficina'),
      ('recolectar_documentacion', 'Recolectar documentación'),
      ('levantamiento_campo', 'Levantamiento en campo'),
    ];
    return DropdownButtonFormField<String>(
      value: _acuerdo,
      items: opciones
          .map((o) => DropdownMenuItem(value: o.$1, child: Text(o.$2)))
          .toList(),
      onChanged: (v) => setState(() => _acuerdo = v ?? 'ninguno'),
      dropdownColor: ConstantColors.grey100,
      style: TextStyle(color: ConstantColors.textDark, fontSize: 14),
      decoration: InputDecoration(
        prefixIcon: Icon(Icons.handshake_rounded,
            color: ConstantColors.warning, size: 20),
        filled: true,
        fillColor: ConstantColors.grey100,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: ConstantColors.borderLight),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: ConstantColors.borderLight),
        ),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
    );
  }

  Widget _switchItem({
    required String label,
    required bool value,
    required ValueChanged<bool> onChanged,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: ConstantColors.grey100,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: ConstantColors.borderLight),
      ),
      child: Row(
        children: [
          Expanded(
              child: Text(label,
                  style:
                      TextStyle(color: ConstantColors.textDark, fontSize: 14))),
          Switch(
            value: value,
            onChanged: onChanged,
            activeColor: ConstantColors.warning,
          ),
        ],
      ),
    );
  }

  Widget _checkboxItem({
    required String label,
    required bool value,
    required ValueChanged<bool?> onChanged,
  }) {
    return GestureDetector(
      onTap: () => onChanged(!value),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(
          color: value
              ? ConstantColors.warning.withOpacity(0.12)
              : ConstantColors.grey100,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: value
                ? ConstantColors.warning.withOpacity(0.5)
                : ConstantColors.borderLight,
          ),
        ),
        child: Row(
          children: [
            Icon(
              value
                  ? Icons.check_box_rounded
                  : Icons.check_box_outline_blank_rounded,
              color:
                  value ? ConstantColors.warning : ConstantColors.textDarkGrey,
              size: 22,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  color: value
                      ? ConstantColors.textDark
                      : ConstantColors.textDarkGrey,
                  fontSize: 14,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _preguntaSiNo({
    required bool? value,
    required ValueChanged<bool?> onChanged,
  }) {
    return Row(
      children: [
        Expanded(
          child: GestureDetector(
            onTap: () => onChanged(true),
            child: Container(
              padding: const EdgeInsets.symmetric(vertical: 14),
              decoration: BoxDecoration(
                color: value == true
                    ? ConstantColors.success.withOpacity(0.15)
                    : ConstantColors.grey100,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: value == true
                      ? ConstantColors.success
                      : ConstantColors.borderLight,
                ),
              ),
              child: Column(
                children: [
                  Icon(Icons.check_rounded,
                      color: value == true
                          ? ConstantColors.success
                          : ConstantColors.textDarkGrey,
                      size: 24),
                  const SizedBox(height: 4),
                  Text('SÍ',
                      style: TextStyle(
                        color: value == true
                            ? ConstantColors.success
                            : ConstantColors.textDarkGrey,
                        fontWeight: FontWeight.w700,
                        fontSize: 15,
                      )),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: GestureDetector(
            onTap: () => onChanged(false),
            child: Container(
              padding: const EdgeInsets.symmetric(vertical: 14),
              decoration: BoxDecoration(
                color: value == false
                    ? ConstantColors.error.withOpacity(0.12)
                    : ConstantColors.grey100,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: value == false
                      ? ConstantColors.error
                      : ConstantColors.borderLight,
                ),
              ),
              child: Column(
                children: [
                  Icon(Icons.close_rounded,
                      color: value == false
                          ? ConstantColors.error
                          : ConstantColors.textDarkGrey,
                      size: 24),
                  const SizedBox(height: 4),
                  Text('NO',
                      style: TextStyle(
                        color: value == false
                            ? ConstantColors.error
                            : ConstantColors.textDarkGrey,
                        fontWeight: FontWeight.w700,
                        fontSize: 15,
                      )),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _fieldFecha({
    required String label,
    required DateTime? fecha,
    required VoidCallback onPick,
  }) {
    return GestureDetector(
      onTap: onPick,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: ConstantColors.grey100,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: ConstantColors.borderLight),
        ),
        child: Row(
          children: [
            Icon(Icons.calendar_today_rounded,
                color: ConstantColors.warning, size: 20),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                fecha != null
                    ? '${fecha.day.toString().padLeft(2, '0')}/${fecha.month.toString().padLeft(2, '0')}/${fecha.year}'
                    : label,
                style: TextStyle(
                  color: fecha != null
                      ? ConstantColors.textDark
                      : ConstantColors.textDarkGrey,
                  fontSize: 14,
                ),
              ),
            ),
            if (fecha != null)
              GestureDetector(
                onTap: () {
                  // Clear the date via callback — just pick new one
                },
                child: Icon(Icons.edit_calendar_rounded,
                    color: ConstantColors.textDarkGrey, size: 18),
              ),
          ],
        ),
      ),
    );
  }

  Widget _fieldHora() {
    return GestureDetector(
      onTap: () async {
        final t = await showTimePicker(
          context: context,
          initialTime: _horaAcuerdo ?? TimeOfDay.now(),
          builder: (ctx, child) => Theme(
            data: ThemeData.dark().copyWith(
              colorScheme: ColorScheme.dark(primary: ConstantColors.warning),
            ),
            child: child!,
          ),
        );
        if (t != null) setState(() => _horaAcuerdo = t);
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: ConstantColors.grey100,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: ConstantColors.borderLight),
        ),
        child: Row(
          children: [
            Icon(Icons.access_time_rounded,
                color: ConstantColors.warning, size: 20),
            const SizedBox(width: 12),
            Text(
              _horaAcuerdo != null
                  ? _horaAcuerdo!.format(context)
                  : 'Hora del acuerdo',
              style: TextStyle(
                color: _horaAcuerdo != null
                    ? ConstantColors.textDark
                    : ConstantColors.textDarkGrey,
                fontSize: 14,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _seleccionarFecha(ValueChanged<DateTime> onSelected) async {
    final d = await showDatePicker(
      context: context,
      initialDate: DateTime.now(),
      firstDate: DateTime(2020),
      lastDate: DateTime(2030),
      builder: (ctx, child) => Theme(
        data: ThemeData.dark().copyWith(
          colorScheme: ColorScheme.dark(primary: ConstantColors.warning),
        ),
        child: child!,
      ),
    );
    if (d != null) onSelected(d);
  }

  void _confirmarSalida() {
    if (!mounted) return;
    if (_guardando) return;

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ConstantColors.grey100,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: Text('Salir',
            style: TextStyle(
                color: ConstantColors.textDark, fontWeight: FontWeight.w700)),
        content: Text(
          '¿Desea salir de la encuesta? Se perderán los cambios no guardados.',
          style: TextStyle(color: ConstantColors.textDarkGrey),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('Cancelar',
                style: TextStyle(color: ConstantColors.textDarkGrey)),
          ),
          ElevatedButton(
            onPressed: () {
              Navigator.pop(ctx);
              Navigator.pop(context);
            },
            style: ElevatedButton.styleFrom(
              backgroundColor: ConstantColors.warning,
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12)),
            ),
            child: const Text('Salir'),
          ),
        ],
      ),
    );
  }

  Widget _botonFinalizar({
    required String label,
    required String sublabel,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: _guardando ? null : onTap,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 20),
        decoration: BoxDecoration(
          gradient: ConstantColors.buttonGradient,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [
            BoxShadow(
              color: ConstantColors.warning.withOpacity(0.35),
              blurRadius: 16,
              offset: const Offset(0, 6),
            )
          ],
        ),
        child: _guardando
            ? const Center(
                child: SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white)))
            : Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    label,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                    ),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    sublabel,
                    style: const TextStyle(
                      color: Colors.white70,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
      ),
    );
  }
}
      