// ignore_for_file: deprecated_member_use

import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/UI/views/EncuestaProductoScreen.dart';

// ─────────────────────────────────────────────────────────────
//  Paso de la encuesta 
// ─────────────────────────────────────────────────────────────
enum _Paso {
  inicial,
  datosCliente,
  empresaNegocio,
  productosActuales,
  interesProductos,
  busqueda
}

class NuevaEncuestaScreen extends StatefulWidget {
  final String tipoTarea;
  final bool incluirEmpresa;

  /// Datos para prellenar el formulario (paso 1). Útil cuando se abre la
  /// encuesta desde la agenda de tareas tras consultar por cédula.
  /// Claves esperadas (todas opcionales):
  ///   cedula, nombres, apellidos, nombre, telefono, celular, email,
  ///   direccion, ciudad, actividad, nombre_empresa, tiene_ruc (0|1),
  ///   tiene_rise (0|1), es_cliente (0|1)
  final Map<String, dynamic>? initialData;

  /// Cuando se pasa un [tareaIdEdicion] la pantalla entra en modo edición:
  /// carga todos los datos de la encuesta previamente guardada, bloquea la
  /// cédula y al guardar llama a actualizar_encuesta_completa.php (sin
  /// cerrar segmentos ni crear nuevas tareas).
  final String? tareaIdEdicion;

  const NuevaEncuestaScreen({
    Key? key,
    this.tipoTarea = 'prospecto_nuevo',
    this.incluirEmpresa = true,
    this.initialData,
    this.tareaIdEdicion,
  }) : super(key: key);

  bool get modoEdicion =>
      tareaIdEdicion != null && tareaIdEdicion!.trim().isNotEmpty;

  @override
  _NuevaEncuestaScreenState createState() => _NuevaEncuestaScreenState();
}

class _NuevaEncuestaScreenState extends State<NuevaEncuestaScreen> {
  _Paso _paso = _Paso.inicial;
  bool _guardando = false;

  // ── Modo edición ─────────────────────────────────────────────
  bool _cargandoEdicion = false;
  String? _errorEdicion;

  // Prospecto nuevo vs existente (determinado por búsqueda de cédula)
  bool? _esProspectoNuevo;

  // Solo para prospecto nuevo: origen del prospecto
  // 'frio' => no conoce/no sigue; 'seguidor' => sí conoce/sigue
  String? _origenProspecto;

  // GPS
  double? _latInicio, _lngInicio;
  double? _latFin, _lngFin;

  // ── Paso 1: Datos del prospecto ──────────────────────────────
  final _nombreCtrl = TextEditingController();
  final _apellidosCtrl = TextEditingController();
  final _cedulaCtrl = TextEditingController();
  final _telefonoCtrl = TextEditingController();
  final _celularCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _direccionCtrl = TextEditingController();
  final _ciudadCtrl = TextEditingController();
  String? _actividad;
  // Régimen tributario
  String? _regimenTributario;          // 'ruc' | 'rise' | 'no_registrado'
  final _numeroRucCtrl  = TextEditingController(); // número RUC (opcional)
  bool? _declaraIva;
  bool? _emiteFacturas;
  bool? _llevaContabilidad;
  bool? _pagaCuotaRise;
  bool? _emiteNotasVenta;
  bool? _conoceLimiteRise;
  // compat: derivados de _regimenTributario
  bool get _tieneRuc  => _regimenTributario == 'ruc';
  bool get _tieneRise => _regimenTributario == 'rise';

  bool _tieneEmpresa = false;
  final _empresaCtrl = TextEditingController();
  final _formKeyCliente = GlobalKey<FormState>();

  // ── Paso Empresa/Negocio (solo si _tieneEmpresa = true) ─────
  final _formKeyNegocio = GlobalKey<FormState>();
  bool _tipoServProduccion = false; // Servicio/Producción
  bool _tipoComercio = false;       // Comercio
  // Producción: estructura completa por producto (5 productos, 7 materias)
  static const int _kProdCount = 5;
  static const int _kMatCount  = 7;
  late final List<TextEditingController> _prodNameCtrl         = List.generate(_kProdCount, (_) => TextEditingController()); // nombre
  late final List<List<TextEditingController>> _prodMatNomCtrl = List.generate(_kProdCount, (_) => List.generate(_kMatCount, (_) => TextEditingController())); // nombre de cada materia
  late final List<List<TextEditingController>> _prodMatCtrl    = List.generate(_kProdCount, (_) => List.generate(_kMatCount, (_) => TextEditingController())); // valor/costo de cada materia
  late final List<TextEditingController> _prodManoCtrl         = List.generate(_kProdCount, (_) => TextEditingController()); // total mano de obra
  late final List<TextEditingController> _prodEmpaqueCtrl      = List.generate(_kProdCount, (_) => TextEditingController()); // empaques
  late final List<TextEditingController> _prodOtrosCtrl        = List.generate(_kProdCount, (_) => TextEditingController()); // otros costos indirectos
  late final List<TextEditingController> _prodUnidadesProdCtrl = List.generate(_kProdCount, (_) => TextEditingController()); // (2) unidades producidas
  late final List<TextEditingController> _prodPrecioCtrl       = List.generate(_kProdCount, (_) => TextEditingController()); // B. precio unitario
  late final List<TextEditingController> _prodUnidadesVendCtrl = List.generate(_kProdCount, (_) => TextEditingController()); // C. unidades vendidas mes
  late final List<TextEditingController> _prodUnidExistCtrl    = List.generate(_kProdCount, (_) => TextEditingController()); // D. unidades verificadas (inventario)
  // Sin uso activo en producción (compatibilidad dispose)
  late final List<TextEditingController> _prodCostoCtrl        = List.generate(_kProdCount, (_) => TextEditingController());
  late final List<TextEditingController> _prodTipoUnidadCtrl   = List.generate(_kProdCount, (_) => TextEditingController());

  // ── Activos Fijos del Negocio (10 filas) ────────────────────
  static const int _kActivosCount = 10;
  late final List<TextEditingController> _actNegDescCtrl   = List.generate(_kActivosCount, (_) => TextEditingController());
  late final List<TextEditingController> _actNegMarcaCtrl  = List.generate(_kActivosCount, (_) => TextEditingController());
  late final List<TextEditingController> _actNegModeloCtrl = List.generate(_kActivosCount, (_) => TextEditingController());
  late final List<TextEditingController> _actNegSerieCtrl  = List.generate(_kActivosCount, (_) => TextEditingController());
  late final List<TextEditingController> _actNegValorCtrl  = List.generate(_kActivosCount, (_) => TextEditingController());

  // ── Activos Fijos del Hogar (10 filas) ──────────────────────
  late final List<TextEditingController> _actHogDescCtrl   = List.generate(_kActivosCount, (_) => TextEditingController());
  late final List<TextEditingController> _actHogMarcaCtrl  = List.generate(_kActivosCount, (_) => TextEditingController());
  late final List<TextEditingController> _actHogModeloCtrl = List.generate(_kActivosCount, (_) => TextEditingController());
  late final List<TextEditingController> _actHogSerieCtrl  = List.generate(_kActivosCount, (_) => TextEditingController());
  late final List<TextEditingController> _actHogValorCtrl  = List.generate(_kActivosCount, (_) => TextEditingController());

  // ── Comercio: lista de productos comercializados (mínimo 5) ─
  static const int _kComProdCount = 5;
  late final List<TextEditingController> _comNombreCtrl      = List.generate(_kComProdCount, (_) => TextEditingController());
  late final List<TextEditingController> _comCostoCtrl       = List.generate(_kComProdCount, (_) => TextEditingController());
  late final List<TextEditingController> _comPrecioCtrl      = List.generate(_kComProdCount, (_) => TextEditingController());
  late final List<TextEditingController> _comTipoUnidadCtrl  = List.generate(_kComProdCount, (_) => TextEditingController());
  late final List<TextEditingController> _comCantidadCtrl    = List.generate(_kComProdCount, (_) => TextEditingController());
  late final List<TextEditingController> _comUnidExistCtrl   = List.generate(_kComProdCount, (_) => TextEditingController());

  // Ventas por día (Lun–Dom)
  final _ventaLunCtrl  = TextEditingController();
  final _ventaMarCtrl  = TextEditingController();
  final _ventaMieCtrl  = TextEditingController();
  final _ventaJueCtrl  = TextEditingController();
  final _ventaVieCtrl  = TextEditingController();
  final _ventaSabCtrl  = TextEditingController();
  final _ventaDomCtrl  = TextEditingController();
  // Compras por día (Lun–Dom)
  final _compraLunCtrl  = TextEditingController();
  final _compraMarCtrl  = TextEditingController();
  final _compraMieCtrl  = TextEditingController();
  final _compraJueCtrl  = TextEditingController();
  final _compraVieCtrl  = TextEditingController();
  final _compraSabCtrl  = TextEditingController();
  final _compraDomCtrl  = TextEditingController();
  String? _mesAltaVenta;
  String? _mesBajaVenta;
  String? _mesAltaCompra;
  // Días de atención individuales
  bool _diaLun = true;
  bool _diaMar = true;
  bool _diaMie = true;
  bool _diaJue = true;
  bool _diaVie = true;
  bool _diaSab = false;
  bool _diaDom = false;
  int _pctContado = 80;   // % contado (crédito = 100 - contado)
  int _pctEfectivo = 70;  // % cobrado en efectivo (resto = tarjeta/transferencia)

  // ── Vehículos (tabla) ────────────────────────────────────
  static const int _kVehCount = 5;
  late final List<TextEditingController> _vehNegDescCtrl  = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehNegMarcaCtrl = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehNegModCtrl   = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehNegAnioCtrl  = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehNegValCtrl   = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehHogDescCtrl  = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehHogMarcaCtrl = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehHogModCtrl   = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehHogAnioCtrl  = List.generate(_kVehCount, (_) => TextEditingController());
  late final List<TextEditingController> _vehHogValCtrl   = List.generate(_kVehCount, (_) => TextEditingController());

  // ── Inmuebles (tabla) ────────────────────────────────────
  static const int _kInmCount = 5;
  late final List<TextEditingController> _inmNegDescCtrl  = List.generate(_kInmCount, (_) => TextEditingController());
  late final List<TextEditingController> _inmNegAreaCtrl  = List.generate(_kInmCount, (_) => TextEditingController());
  late final List<TextEditingController> _inmNegUbicCtrl  = List.generate(_kInmCount, (_) => TextEditingController());
  late final List<TextEditingController> _inmNegValCtrl   = List.generate(_kInmCount, (_) => TextEditingController());
  late final List<TextEditingController> _inmHogDescCtrl  = List.generate(_kInmCount, (_) => TextEditingController());
  late final List<TextEditingController> _inmHogAreaCtrl  = List.generate(_kInmCount, (_) => TextEditingController());
  late final List<TextEditingController> _inmHogUbicCtrl  = List.generate(_kInmCount, (_) => TextEditingController());
  late final List<TextEditingController> _inmHogValCtrl   = List.generate(_kInmCount, (_) => TextEditingController());

  // ── Otras Deudas (tabla) ──────────────────────────────────
  static const int _kDeudasCount = 6;
  late final List<TextEditingController> _deudaAcreedorCtrl =
      List.generate(_kDeudasCount, (_) => TextEditingController());
  late final List<TextEditingController> _deudaDestinoCtrl =
      List.generate(_kDeudasCount, (_) => TextEditingController());
  late final List<TextEditingController> _deudaMontoIniCtrl =
      List.generate(_kDeudasCount, (_) => TextEditingController());
  late final List<TextEditingController> _deudaSaldoActCtrl =
      List.generate(_kDeudasCount, (_) => TextEditingController());
  late final List<TextEditingController> _deudaPagoMesCtrl =
      List.generate(_kDeudasCount, (_) => TextEditingController());

  // ── Gastos del negocio (desglosados) ──────────────────────
  final _gNegSueldosCtrl     = TextEditingController();
  final _gNegArriendoCtrl    = TextEditingController();
  final _gNegServBasCtrl     = TextEditingController();
  final _gNegTransporteCtrl  = TextEditingController();
  final _gNegMantCtrl        = TextEditingController();
  final _gNegOtrosCtrl       = TextEditingController();
  final _gNegImprevistosCtrl = TextEditingController();

  // ── Otros ingresos (desglosados) ─────────────────────────
  final _oIngConyugeCtrl   = TextEditingController();
  final _oIngArriendosCtrl = TextEditingController();
  final _oIngPensionesCtrl = TextEditingController();
  final _oIngOtrosCtrl     = TextEditingController();

  // ── Gastos familiares (desglosados) ──────────────────────
  final _gFamAlimCtrl        = TextEditingController();
  final _gFamArriendoCtrl    = TextEditingController();
  final _gFamServBasCtrl     = TextEditingController();
  final _gFamEducCtrl        = TextEditingController();
  final _gFamSaludCtrl       = TextEditingController();
  final _gFamOtrosCtrl       = TextEditingController();
  final _gFamImprevistosCtrl = TextEditingController();

  final _recuperacionCreditoCtrl = TextEditingController();
  final _costosVentasCtrl = TextEditingController();
  final _gastosNegocioCtrl = TextEditingController();   // total calculado (retrocompat)
  final _otrosIngresosCtrl = TextEditingController();   // total calculado (retrocompat)
  final _gastosFamiliaresCtrl = TextEditingController(); // total calculado (retrocompat)

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
  // Nueva funcionalidad: propuesta antes del vencimiento
  bool? _propuestaPrevVenc;
  DateTime? _fechaPrevVencInv;
  final _propuestaInvCtrl = TextEditingController();

  // Bancos para cuentas ahorro/corriente
  final _bancoAhorroCtrl = TextEditingController();
  final _bancoCorrienteCtrl = TextEditingController();

  // Instituciones (picker)
  List<String> _instituciones = [];
  bool _institucionesCargadas = false;
  String? _instAhorroSeleccionada;
  String? _instCorrSeleccionada;

  // Si el cliente quiere conocer servicios desde este paso
  bool? _interesConocerServicios;

  // ── Paso 3: Interés en productos ────────────────────────────
  bool? _interesConocer;
  bool _interesCC = false;
  bool _interesAhorro = false;
  bool _interesInv = false;
  bool _interesCred = false;
  // Fichas llenadas por producto
  bool _fichaCC = false;
  bool _fichaAhorro = false;
  bool _fichaInv = false;
  bool _fichaCred = false;
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
  String _acuerdo = '';
  DateTime? _fechaAcuerdo;
  TimeOfDay? _horaAcuerdo;
  final _obsCtrl = TextEditingController();

  // ─────────────────────────────────────────────────────────────

  @override
  void initState() {
    super.initState();
    _obtenerGPS();
    _aplicarInitialData();
    _cargarInstituciones();
    // Si la pantalla se abre en modo "levantamiento" saltamos la pregunta
    // inicial (¿Desea ser encuestado?) y vamos directo a los datos.
    if (widget.tipoTarea == 'levantamiento') {
      _paso = _Paso.datosCliente;
    }
    if (widget.modoEdicion) {
      _cargandoEdicion = true;
      // Saltar el paso "inicial" (Sí/No encuestado) porque ya viene
      // de una tarea finalizada y vamos directo a los datos.
      _paso = _Paso.datosCliente;
      _esProspectoNuevo = false;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        _cargarEncuestaEnEdicion();
      });
    }
  }

  Future<void> _cargarInstituciones() async {
    try {
      final resp = await http.get(
        Uri.parse('${Constants.apiBaseUrl}/api_cooperativas.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
      ).timeout(const Duration(seconds: 10));

      if (!mounted) return;

      final decoded = json.decode(resp.body);
      if (decoded is Map) {
        final data = Map<String, dynamic>.from(decoded);
        final status = data['status']?.toString();

        List<String> inst = [];

        if (status == 'success' && data['data'] is List) {
          final list = data['data'] as List;
          inst = list
              .map((e) => e is Map ? (e['nombre']?.toString() ?? '') : '')
              .map((s) => s.trim())
              .where((s) => s.isNotEmpty)
              .toSet()
              .toList();
          inst.sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));
        }

        if (inst.isEmpty && status == 'ok' && data['instituciones'] is List) {
          inst = (data['instituciones'] as List)
              .map((e) => e.toString().trim())
              .where((s) => s.isNotEmpty)
              .toSet()
              .toList();
          inst.sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));
        }

        setState(() {
          _instituciones = inst;
          _institucionesCargadas = true;
          // si ya había texto en controllers (modo edición), ajustar selección
          if (_bancoAhorroCtrl.text.trim().isNotEmpty) {
            final t = _bancoAhorroCtrl.text.trim();
            _instAhorroSeleccionada = _instituciones.contains(t) ? t : 'otra';
          }
          if (_bancoCorrienteCtrl.text.trim().isNotEmpty) {
            final t = _bancoCorrienteCtrl.text.trim();
            _instCorrSeleccionada = _instituciones.contains(t) ? t : 'otra';
          }
        });
        return;
      }
    } catch (_) {}

    if (mounted) {
      setState(() {
        _instituciones = [];
        _institucionesCargadas = true;
      });
    }
  }

  /// Carga todos los datos de una encuesta previamente guardada para
  /// permitir modificarla. Se usa solo cuando [widget.modoEdicion] es true.
  Future<void> _cargarEncuestaEnEdicion() async {
    final tid = widget.tareaIdEdicion ?? '';
    if (tid.isEmpty) return;

    setState(() {
      _cargandoEdicion = true;
      _errorEdicion = null;
    });

    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      final url = Uri.parse('${Constants.apiBaseUrl}/obtener_encuesta_completa.php');
      final resp = await http.post(url, body: {
        'tarea_id': tid,
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) {
        throw Exception('Respuesta inválida del servidor');
      }
      if (decoded['status']?.toString() != 'success') {
        throw Exception(decoded['message']?.toString() ?? 'No se pudo cargar la encuesta');
      }

      final data = Map<String, dynamic>.from(decoded['data'] as Map);
      _aplicarDatosEdicion(data);

      if (!mounted) return;
      setState(() {
        _cargandoEdicion = false;
        _errorEdicion = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _cargandoEdicion = false;
        _errorEdicion = e.toString().replaceFirst('Exception: ', '');
      });
    }
  }

  /// Aplica el payload de `obtener_encuesta_completa.php` sobre todos los
  /// controllers / flags del formulario.
  void _aplicarDatosEdicion(Map<String, dynamic> data) {
    String _s(dynamic v) => (v ?? '').toString();
    int _i(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is num) return v.toInt();
      return int.tryParse(v.toString()) ?? 0;
    }
    bool? _ib(dynamic v) {
      if (v == null) return null;
      if (v is bool) return v;
      if (v is int) return v == 1;
      if (v is num) return v.toInt() == 1;
      final s = v.toString().trim();
      if (s.isEmpty) return null;
      return s == '1' || s.toLowerCase() == 'true';
    }
    double _d(dynamic v) {
      if (v == null) return 0.0;
      if (v is num) return v.toDouble();
      return double.tryParse(v.toString()) ?? 0.0;
    }
    DateTime? _fecha(String s) {
      if (s.isEmpty) return null;
      // Formatos: YYYY-MM-DD o YYYY-MM-DD HH:MM:SS
      final base = s.length >= 10 ? s.substring(0, 10) : s;
      try {
        final parts = base.split('-');
        if (parts.length != 3) return null;
        return DateTime(int.parse(parts[0]), int.parse(parts[1]), int.parse(parts[2]));
      } catch (_) {
        return null;
      }
    }
    TimeOfDay? _hora(String s) {
      if (s.isEmpty) return null;
      final partes = s.split(':');
      if (partes.length < 2) return null;
      final h = int.tryParse(partes[0]);
      final m = int.tryParse(partes[1]);
      if (h == null || m == null) return null;
      return TimeOfDay(hour: h, minute: m);
    }

    // ── Cliente ────────────────────────────────────────────────
    final cliente = data['cliente'] is Map
        ? Map<String, dynamic>.from(data['cliente'] as Map)
        : <String, dynamic>{};
    if (cliente.isNotEmpty) {
      final nombreFull = _s(cliente['nombre']).trim();
      if (nombreFull.isNotEmpty) {
        final parts = nombreFull.split(RegExp(r'\s+'));
        if (parts.length == 1) {
          _nombreCtrl.text = parts[0];
          _apellidosCtrl.text = '';
        } else {
          _nombreCtrl.text = parts.first;
          _apellidosCtrl.text = parts.sublist(1).join(' ');
        }
      }
      _cedulaCtrl.text    = _s(cliente['cedula']);
      _telefonoCtrl.text  = _s(cliente['telefono']);
      _celularCtrl.text   = _s(cliente['celular']);
      _emailCtrl.text     = _s(cliente['email']);
      _direccionCtrl.text = _s(cliente['direccion']);
      _ciudadCtrl.text    = _s(cliente['ciudad']);

      final act = _s(cliente['actividad']);
      _actividad = act.isEmpty ? null : act;

      final ne = _s(cliente['nombre_empresa']);
      if (ne.isNotEmpty) {
        _empresaCtrl.text = ne;
        _tieneEmpresa = true;
      } else {
        _empresaCtrl.text = '';
        _tieneEmpresa = false;
      }

      final tieneRuc = _i(cliente['tiene_ruc']) == 1;
      final tieneRise = _i(cliente['tiene_rise']) == 1;
      if (tieneRuc) {
        _regimenTributario = 'ruc';
      } else if (tieneRise) {
        _regimenTributario = 'rise';
      } else {
        _regimenTributario = 'no_registrado';
      }

      final op = _s(cliente['origen_prospecto']);
      if (op.isNotEmpty) _origenProspecto = op;
    }

    // ── Tarea (observaciones como punto de partida) ────────────
    final tarea = data['tarea'] is Map
        ? Map<String, dynamic>.from(data['tarea'] as Map)
        : <String, dynamic>{};
    final obsTarea = _s(tarea['observaciones']);
    if (obsTarea.isNotEmpty) _obsCtrl.text = obsTarea;

    // ── Encuesta negocio ───────────────────────────────────────
    final neg = data['encuesta_negocio'];
    if (neg is Map) {
      final n = Map<String, dynamic>.from(neg);
      _tieneEmpresa = true;
      final _tipoEmpresaStr = _s(n['tipo_empresa']);
      _tipoServProduccion = _tipoEmpresaStr.contains('servicio_produccion');
      _tipoComercio = _tipoEmpresaStr.contains('comercio');
      // Cargar por día individual; fallback al campo agrupado antiguo (retrocompatibilidad)
      final lvVenta = _d(n['venta_lv']).toStringAsFixed(2);
      _ventaLunCtrl.text  = _d(n['venta_lunes']).toStringAsFixed(2)  != '0.00' ? _d(n['venta_lunes']).toStringAsFixed(2)  : lvVenta;
      _ventaMarCtrl.text  = _d(n['venta_martes']).toStringAsFixed(2) != '0.00' ? _d(n['venta_martes']).toStringAsFixed(2) : lvVenta;
      _ventaMieCtrl.text  = _d(n['venta_miercoles']).toStringAsFixed(2) != '0.00' ? _d(n['venta_miercoles']).toStringAsFixed(2) : lvVenta;
      _ventaJueCtrl.text  = _d(n['venta_jueves']).toStringAsFixed(2) != '0.00' ? _d(n['venta_jueves']).toStringAsFixed(2) : lvVenta;
      _ventaVieCtrl.text  = _d(n['venta_viernes']).toStringAsFixed(2) != '0.00' ? _d(n['venta_viernes']).toStringAsFixed(2) : lvVenta;
      _ventaSabCtrl.text  = _d(n['venta_sabado']).toStringAsFixed(2);
      _ventaDomCtrl.text  = _d(n['venta_domingo']).toStringAsFixed(2);
      final lvCompra = _d(n['compra_lv']).toStringAsFixed(2);
      _compraLunCtrl.text = _d(n['compra_lunes']).toStringAsFixed(2)  != '0.00' ? _d(n['compra_lunes']).toStringAsFixed(2)  : lvCompra;
      _compraMarCtrl.text = _d(n['compra_martes']).toStringAsFixed(2) != '0.00' ? _d(n['compra_martes']).toStringAsFixed(2) : lvCompra;
      _compraMieCtrl.text = _d(n['compra_miercoles']).toStringAsFixed(2) != '0.00' ? _d(n['compra_miercoles']).toStringAsFixed(2) : lvCompra;
      _compraJueCtrl.text = _d(n['compra_jueves']).toStringAsFixed(2) != '0.00' ? _d(n['compra_jueves']).toStringAsFixed(2) : lvCompra;
      _compraVieCtrl.text = _d(n['compra_viernes']).toStringAsFixed(2) != '0.00' ? _d(n['compra_viernes']).toStringAsFixed(2) : lvCompra;
      _compraSabCtrl.text = _d(n['compra_sabado']).toStringAsFixed(2);
      _compraDomCtrl.text = _d(n['compra_domingo']).toStringAsFixed(2);
      _mesAltaVenta       = _s(n['mes_alta_venta']).isEmpty  ? null : _s(n['mes_alta_venta']);
      _mesBajaVenta       = _s(n['mes_baja_venta']).isEmpty  ? null : _s(n['mes_baja_venta']);
      _mesAltaCompra      = _s(n['mes_alta_compra']).isEmpty ? null : _s(n['mes_alta_compra']);
      _diaLun = _i(n['dia_lun']) == 1;
      _diaMar = _i(n['dia_mar']) == 1;
      _diaMie = _i(n['dia_mie']) == 1;
      _diaJue = _i(n['dia_jue']) == 1;
      _diaVie = _i(n['dia_vie']) == 1;
      _diaSab = _i(n['dia_sab']) == 1;
      _diaDom = _i(n['dia_dom']) == 1;
      // Retrocompatibilidad: si vienen los campos agrupados
      if (_i(n['dia_lv']) == 1) { _diaLun = _diaMar = _diaMie = _diaJue = _diaVie = true; }
      final pct = n['pct_contado'];
      if (pct != null) _pctContado = _i(pct);
      final pctEf = n['pct_efectivo'];
      if (pctEf != null) _pctEfectivo = _i(pctEf);
      _recuperacionCreditoCtrl.text = _d(n['recuperacion_credito']).toStringAsFixed(2);
      _costosVentasCtrl.text        = _d(n['costos_ventas']).toStringAsFixed(2);
      _gastosNegocioCtrl.text       = _d(n['gastos_negocio']).toStringAsFixed(2);
      _otrosIngresosCtrl.text       = _d(n['otros_ingresos']).toStringAsFixed(2);
      _gastosFamiliaresCtrl.text    = _d(n['gastos_familiares']).toStringAsFixed(2);
      // Gastos negocio desglosados
      _gNegSueldosCtrl.text     = _d(n['g_neg_sueldos']).toStringAsFixed(2);
      _gNegArriendoCtrl.text    = _d(n['g_neg_arriendo']).toStringAsFixed(2);
      _gNegServBasCtrl.text     = _d(n['g_neg_serv_bas']).toStringAsFixed(2);
      _gNegTransporteCtrl.text  = _d(n['g_neg_transporte']).toStringAsFixed(2);
      _gNegMantCtrl.text        = _d(n['g_neg_mantenimiento']).toStringAsFixed(2);
      _gNegOtrosCtrl.text       = _d(n['g_neg_otros']).toStringAsFixed(2);
      _gNegImprevistosCtrl.text = _d(n['g_neg_imprevistos']).toStringAsFixed(2);
      // Otros ingresos desglosados
      _oIngConyugeCtrl.text   = _d(n['o_ing_conyuge']).toStringAsFixed(2);
      _oIngArriendosCtrl.text = _d(n['o_ing_arriendos']).toStringAsFixed(2);
      _oIngPensionesCtrl.text = _d(n['o_ing_pensiones']).toStringAsFixed(2);
      _oIngOtrosCtrl.text     = _d(n['o_ing_otros']).toStringAsFixed(2);
      // Gastos familiares desglosados
      _gFamAlimCtrl.text        = _d(n['g_fam_alim']).toStringAsFixed(2);
      _gFamArriendoCtrl.text    = _d(n['g_fam_arriendo']).toStringAsFixed(2);
      _gFamServBasCtrl.text     = _d(n['g_fam_serv_bas']).toStringAsFixed(2);
      _gFamEducCtrl.text        = _d(n['g_fam_educacion']).toStringAsFixed(2);
      _gFamSaludCtrl.text       = _d(n['g_fam_salud']).toStringAsFixed(2);
      _gFamOtrosCtrl.text       = _d(n['g_fam_otros']).toStringAsFixed(2);
      _gFamImprevistosCtrl.text = _d(n['g_fam_imprevistos']).toStringAsFixed(2);
      // Vehículos
      void _cargarVehiculos(dynamic raw, List<TextEditingController> desc,
          List<TextEditingController> marca, List<TextEditingController> mod,
          List<TextEditingController> anio, List<TextEditingController> val) {
        if (raw == null) return;
        try {
          final list = json.decode(raw.toString()) as List<dynamic>;
          for (int i = 0; i < list.length && i < _kVehCount; i++) {
            final v = Map<String, dynamic>.from(list[i] as Map);
            desc[i].text  = (v['descripcion'] ?? '').toString();
            marca[i].text = (v['marca']       ?? '').toString();
            mod[i].text   = (v['modelo']      ?? '').toString();
            anio[i].text  = (v['anio']        ?? '').toString();
            val[i].text   = (v['valor']       ?? '').toString();
          }
        } catch (_) {}
      }
      _cargarVehiculos(n['vehiculos_negocio_json'], _vehNegDescCtrl, _vehNegMarcaCtrl, _vehNegModCtrl, _vehNegAnioCtrl, _vehNegValCtrl);
      _cargarVehiculos(n['vehiculos_hogar_json'],   _vehHogDescCtrl, _vehHogMarcaCtrl, _vehHogModCtrl, _vehHogAnioCtrl, _vehHogValCtrl);
      // Inmuebles
      void _cargarInmuebles(dynamic raw, List<TextEditingController> desc,
          List<TextEditingController> area, List<TextEditingController> ubic,
          List<TextEditingController> val) {
        if (raw == null) return;
        try {
          final list = json.decode(raw.toString()) as List<dynamic>;
          for (int i = 0; i < list.length && i < _kInmCount; i++) {
            final v = Map<String, dynamic>.from(list[i] as Map);
            desc[i].text = (v['descripcion'] ?? '').toString();
            area[i].text = (v['area']        ?? '').toString();
            ubic[i].text = (v['ubicacion']   ?? '').toString();
            val[i].text  = (v['valor']       ?? '').toString();
          }
        } catch (_) {}
      }
      _cargarInmuebles(n['inmuebles_negocio_json'], _inmNegDescCtrl, _inmNegAreaCtrl, _inmNegUbicCtrl, _inmNegValCtrl);
      _cargarInmuebles(n['inmuebles_hogar_json'],   _inmHogDescCtrl, _inmHogAreaCtrl, _inmHogUbicCtrl, _inmHogValCtrl);
      // Otras deudas
      final deudasRaw = n['otras_deudas_json'];
      if (deudasRaw != null) {
        try {
          final dl = json.decode(deudasRaw.toString()) as List<dynamic>;
          for (int i = 0; i < dl.length && i < _kDeudasCount; i++) {
            final d = Map<String, dynamic>.from(dl[i] as Map);
            _deudaAcreedorCtrl[i].text = (d['acreedor'] ?? '').toString();
            _deudaDestinoCtrl[i].text  = (d['destino']  ?? '').toString();
            _deudaMontoIniCtrl[i].text = (d['monto_inicial']  ?? '').toString();
            _deudaSaldoActCtrl[i].text = (d['saldo_actual']   ?? '').toString();
            _deudaPagoMesCtrl[i].text  = (d['pago_mes']       ?? '').toString();
          }
        } catch (_) {}
      }

      // Cargar productos de Producción (si aplica)
      final prodRaw = n['productos_json'];
      if (prodRaw != null) {
        try {
          final prodList = json.decode(prodRaw.toString()) as List<dynamic>;
          for (int i = 0; i < prodList.length && i < _kProdCount; i++) {
            final p = Map<String, dynamic>.from(prodList[i] as Map);
            _prodNameCtrl[i].text          = _s(p['nombre']);
            // Materias primas (nombre + valor)
            final mats = p['materias'];
            if (mats is List) {
              for (int m = 0; m < mats.length && m < _kMatCount; m++) {
                final mat = mats[m];
                if (mat is Map) {
                  _prodMatNomCtrl[i][m].text = mat['nombre']?.toString() ?? '';
                  _prodMatCtrl[i][m].text    = mat['valor']?.toString() ?? '';
                } else {
                  // retrocompatibilidad: si solo viene el valor
                  _prodMatCtrl[i][m].text = mat?.toString() ?? '';
                }
              }
            }
            _prodManoCtrl[i].text          = _s(p['mano_obra']);
            _prodEmpaqueCtrl[i].text       = _s(p['empaques']);
            _prodOtrosCtrl[i].text         = _s(p['otros_costos']);
            _prodUnidadesProdCtrl[i].text  = _s(p['unidades_producidas']);
            _prodPrecioCtrl[i].text        = _s(p['precio_unitario']);
            _prodUnidadesVendCtrl[i].text  = _s(p['unidades_vendidas']);
            _prodUnidExistCtrl[i].text     = _s(p['unidades_verificadas']);
          }
        } catch (_) {}
      }

      // Cargar productos de Comercio (si aplica)
      final comProdsRaw = n['comercio_productos_json'];
      if (comProdsRaw != null) {
        try {
          final comList = json.decode(comProdsRaw.toString()) as List<dynamic>;
          for (int i = 0; i < comList.length && i < _kComProdCount; i++) {
            final p = Map<String, dynamic>.from(comList[i] as Map);
            _comNombreCtrl[i].text     = _s(p['nombre']);
            _comCostoCtrl[i].text      = _s(p['costo_unidad']);
            _comPrecioCtrl[i].text     = _s(p['precio_venta_unidad']);
            _comTipoUnidadCtrl[i].text = _s(p['tipo_unidad']);
            _comCantidadCtrl[i].text   = _s(p['cantidad_vendida_mes']);
            _comUnidExistCtrl[i].text  = _s(p['unidades_existentes']);
          }
        } catch (_) {}
      }

      // Cargar activos fijos del negocio
      _cargarActivosFijos(n['activos_negocio_json'],
          _actNegDescCtrl, _actNegMarcaCtrl, _actNegModeloCtrl, _actNegSerieCtrl, _actNegValorCtrl);

      // Cargar activos fijos del hogar
      _cargarActivosFijos(n['activos_hogar_json'],
          _actHogDescCtrl, _actHogMarcaCtrl, _actHogModeloCtrl, _actHogSerieCtrl, _actHogValorCtrl);
    }

    // ── Encuesta comercial ─────────────────────────────────────
    final enc = data['encuesta_comercial'];
    if (enc is Map) {
      final e = Map<String, dynamic>.from(enc);
      _mantieneAhorro    = _i(e['mantiene_cuenta_ahorro'])    == 1;
      _mantieneCorriente = _i(e['mantiene_cuenta_corriente']) == 1;
      _tieneInversiones  = _ib(e['tiene_inversiones']);
      _instInvCtrl.text  = _s(e['institucion_inversiones']);
      final vi = e['valor_inversion'];
      _valorInvCtrl.text = vi == null ? '' : _d(vi).toStringAsFixed(2);
      _plazoInvCtrl.text = _s(e['plazo_inversion']);
      _fechaVencInv      = _fecha(_s(e['fecha_vencimiento_inversion']));
      _tieneOpsCred      = _ib(e['tiene_operaciones_crediticias']);
      _instCredCtrl.text = _s(e['institucion_credito']);
      _propuestaPrevVenc = _ib(e['propuesta_prev_vencimiento']);
      _fechaPrevVencInv  = _fecha(_s(e['fecha_previa_vencimiento']));
      _propuestaInvCtrl.text = _s(e['propuesta_inversion']);
      _bancoAhorroCtrl.text = _s(e['banco_ahorro']);
      _bancoCorrienteCtrl.text = _s(e['banco_corriente']);
      if (_institucionesCargadas) {
        final ah = _bancoAhorroCtrl.text.trim();
        _instAhorroSeleccionada = ah.isEmpty ? null : (_instituciones.contains(ah) ? ah : 'otra');
        final cc = _bancoCorrienteCtrl.text.trim();
        _instCorrSeleccionada = cc.isEmpty ? null : (_instituciones.contains(cc) ? cc : 'otra');
      }
      _interesConocerServicios = _ib(e['interes_conocer_servicios']);
      _interesConocer    = _ib(e['interes_conocer_productos']);
      _interesCC     = _i(e['interes_cc'])        == 1;
      _interesAhorro = _i(e['interes_ahorro'])    == 1;
      _interesInv    = _i(e['interes_inversion']) == 1;
      _interesCred   = _i(e['interes_credito'])   == 1;
      _razonYaTrabaja = _i(e['razon_ya_trabaja_institucion']) == 1;
      _razonDesconfia = _i(e['razon_desconfia_servicios'])    == 1;
      _razonAGusto    = _i(e['razon_agusto_actual'])          == 1;
      _razonMalaExp   = _i(e['razon_mala_experiencia'])       == 1;
      _razonOtrosCtrl.text = _s(e['razon_otros']);
      final ac = _s(e['acuerdo_logrado']);
      const _validAcuerdos = ['nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','otro'];
      if (ac.isNotEmpty && _validAcuerdos.contains(ac)) _acuerdo = ac;
      _fechaAcuerdo = _fecha(_s(e['fecha_acuerdo']));
      _horaAcuerdo  = _hora (_s(e['hora_acuerdo']));
      final obs = _s(e['observaciones']);
      if (obs.isNotEmpty) _obsCtrl.text = obs;
    }
  }

  /// Si la encuesta se abre desde la agenda con una cédula que ya existe
  /// en la base, prellena los controladores del paso 1.
  void _aplicarInitialData() {
    final d = widget.initialData;
    if (d == null || d.isEmpty) return;

    // Si la pantalla se abrió con datos iniciales, asumimos que provienen
    // de un prospecto/cliente ya existente.
    _esProspectoNuevo = false;

    String _s(dynamic v) => (v ?? '').toString();
    int _i(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is num) return v.toInt();
      return int.tryParse(v.toString()) ?? 0;
    }

    // Si vienen 'nombres' y 'apellidos' separados, úsalos; si no, intenta
    // partir 'nombre' completo en primer token (nombre) y resto (apellidos).
    final nombresSep = _s(d['nombres']);
    final apellidosSep = _s(d['apellidos']);
    if (nombresSep.isNotEmpty || apellidosSep.isNotEmpty) {
      _nombreCtrl.text = nombresSep;
      _apellidosCtrl.text = apellidosSep;
    } else {
      final nombreFull = _s(d['nombre']).trim();
      if (nombreFull.isNotEmpty) {
        final parts = nombreFull.split(RegExp(r'\s+'));
        if (parts.length == 1) {
          _nombreCtrl.text = parts[0];
        } else {
          _nombreCtrl.text = parts.first;
          _apellidosCtrl.text = parts.sublist(1).join(' ');
        }
      }
    }

    _cedulaCtrl.text  = _s(d['cedula']);
    _telefonoCtrl.text = _s(d['telefono']);
    _celularCtrl.text  = _s(d['celular']);
    _emailCtrl.text    = _s(d['email']);
    _direccionCtrl.text = _s(d['direccion']);
    _ciudadCtrl.text    = _s(d['ciudad']);

    final act = _s(d['actividad']);
    if (act.isNotEmpty) _actividad = act;

    final ne = _s(d['nombre_empresa']);
    if (ne.isNotEmpty) {
      _empresaCtrl.text = ne;
      _tieneEmpresa = true;
    }

    final tieneRuc  = _i(d['tiene_ruc'])  == 1;
    final tieneRise = _i(d['tiene_rise']) == 1;
    if (tieneRuc) {
      _regimenTributario = 'ruc';
    } else if (tieneRise) {
      _regimenTributario = 'rise';
    }
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
    _ciudadCtrl.dispose();
    _numeroRucCtrl.dispose();
    _empresaCtrl.dispose();
    _ventaLunCtrl.dispose();
    _ventaMarCtrl.dispose();
    _ventaMieCtrl.dispose();
    _ventaJueCtrl.dispose();
    _ventaVieCtrl.dispose();
    _ventaSabCtrl.dispose();
    _ventaDomCtrl.dispose();
    _compraLunCtrl.dispose();
    _compraMarCtrl.dispose();
    _compraMieCtrl.dispose();
    _compraJueCtrl.dispose();
    _compraVieCtrl.dispose();
    _compraSabCtrl.dispose();
    _compraDomCtrl.dispose();
    _recuperacionCreditoCtrl.dispose();
    _costosVentasCtrl.dispose();
    _gastosNegocioCtrl.dispose();
    _otrosIngresosCtrl.dispose();
    _gastosFamiliaresCtrl.dispose();
    // Dispose producción controllers
    for (final c in _prodNameCtrl) c.dispose();
    for (final c in _prodCostoCtrl) c.dispose();
    for (final c in _prodPrecioCtrl) c.dispose();
    for (final c in _prodTipoUnidadCtrl) c.dispose();
    for (final c in _prodUnidadesVendCtrl) c.dispose();
    for (final c in _prodUnidExistCtrl) c.dispose();
    for (final row in _prodMatNomCtrl) for (final c in row) c.dispose();
    for (final row in _prodMatCtrl) for (final c in row) c.dispose();
    for (final c in _prodManoCtrl) c.dispose();
    for (final c in _prodEmpaqueCtrl) c.dispose();
    for (final c in _prodOtrosCtrl) c.dispose();
    for (final c in _prodUnidadesProdCtrl) c.dispose();
    // Comercio
    for (final c in _comNombreCtrl) c.dispose();
    for (final c in _comCostoCtrl) c.dispose();
    for (final c in _comPrecioCtrl) c.dispose();
    for (final c in _comTipoUnidadCtrl) c.dispose();
    for (final c in _comCantidadCtrl) c.dispose();
    for (final c in _comUnidExistCtrl) c.dispose();
    // Activos fijos negocio
    for (final c in _actNegDescCtrl)   c.dispose();
    for (final c in _actNegMarcaCtrl)  c.dispose();
    for (final c in _actNegModeloCtrl) c.dispose();
    for (final c in _actNegSerieCtrl)  c.dispose();
    for (final c in _actNegValorCtrl)  c.dispose();
    // Activos fijos hogar
    for (final c in _actHogDescCtrl)   c.dispose();
    for (final c in _actHogMarcaCtrl)  c.dispose();
    for (final c in _actHogModeloCtrl) c.dispose();
    for (final c in _actHogSerieCtrl)  c.dispose();
    for (final c in _actHogValorCtrl)  c.dispose();
    // Vehículos
    for (final c in _vehNegDescCtrl)  c.dispose(); for (final c in _vehNegMarcaCtrl) c.dispose();
    for (final c in _vehNegModCtrl)   c.dispose(); for (final c in _vehNegAnioCtrl)  c.dispose();
    for (final c in _vehNegValCtrl)   c.dispose();
    for (final c in _vehHogDescCtrl)  c.dispose(); for (final c in _vehHogMarcaCtrl) c.dispose();
    for (final c in _vehHogModCtrl)   c.dispose(); for (final c in _vehHogAnioCtrl)  c.dispose();
    for (final c in _vehHogValCtrl)   c.dispose();
    // Inmuebles
    for (final c in _inmNegDescCtrl)  c.dispose(); for (final c in _inmNegAreaCtrl)  c.dispose();
    for (final c in _inmNegUbicCtrl)  c.dispose(); for (final c in _inmNegValCtrl)   c.dispose();
    for (final c in _inmHogDescCtrl)  c.dispose(); for (final c in _inmHogAreaCtrl)  c.dispose();
    for (final c in _inmHogUbicCtrl)  c.dispose(); for (final c in _inmHogValCtrl)   c.dispose();
    // Otras deudas
    for (final c in _deudaAcreedorCtrl) c.dispose();
    for (final c in _deudaDestinoCtrl)  c.dispose();
    for (final c in _deudaMontoIniCtrl) c.dispose();
    for (final c in _deudaSaldoActCtrl) c.dispose();
    for (final c in _deudaPagoMesCtrl)  c.dispose();
    // Gastos negocio desglosados
    _gNegSueldosCtrl.dispose();
    _gNegArriendoCtrl.dispose();
    _gNegServBasCtrl.dispose();
    _gNegTransporteCtrl.dispose();
    _gNegMantCtrl.dispose();
    _gNegOtrosCtrl.dispose();
    _gNegImprevistosCtrl.dispose();
    // Otros ingresos desglosados
    _oIngConyugeCtrl.dispose();
    _oIngArriendosCtrl.dispose();
    _oIngPensionesCtrl.dispose();
    _oIngOtrosCtrl.dispose();
    // Gastos familiares desglosados
    _gFamAlimCtrl.dispose();
    _gFamArriendoCtrl.dispose();
    _gFamServBasCtrl.dispose();
    _gFamEducCtrl.dispose();
    _gFamSaludCtrl.dispose();
    _gFamOtrosCtrl.dispose();
    _gFamImprevistosCtrl.dispose();
    _instInvCtrl.dispose();
    _valorInvCtrl.dispose();
    _plazoInvCtrl.dispose();
    _instCredCtrl.dispose();
    _propuestaInvCtrl.dispose();
    _bancoAhorroCtrl.dispose();
    _bancoCorrienteCtrl.dispose();
    _razonOtrosCtrl.dispose();
    _obsCtrl.dispose();
    super.dispose();
  }

  /// Aplica un Map de datos (mismos campos que initialData) sobre los
  /// controllers del paso 1. Se usa tras consultar por cédula en el SÍ.
  void _aplicarDatosProspectoEncontrado(Map<String, dynamic> d) {
    String _s(dynamic v) => (v ?? '').toString();
    int _i(dynamic v) {
      if (v == null) return 0;
      if (v is int) return v;
      if (v is num) return v.toInt();
      return int.tryParse(v.toString()) ?? 0;
    }

    final nombresSep = _s(d['nombres']);
    final apellidosSep = _s(d['apellidos']);
    if (nombresSep.isNotEmpty || apellidosSep.isNotEmpty) {
      _nombreCtrl.text = nombresSep;
      _apellidosCtrl.text = apellidosSep;
    } else {
      final nombreFull = _s(d['nombre']).trim();
      if (nombreFull.isNotEmpty) {
        final parts = nombreFull.split(RegExp(r'\s+'));
        if (parts.length == 1) {
          _nombreCtrl.text = parts[0];
        } else {
          _nombreCtrl.text = parts.first;
          _apellidosCtrl.text = parts.sublist(1).join(' ');
        }
      }
    }

    _cedulaCtrl.text    = _s(d['cedula']);
    _telefonoCtrl.text  = _s(d['telefono']);
    _celularCtrl.text   = _s(d['celular']);
    _emailCtrl.text     = _s(d['email']);
    _direccionCtrl.text = _s(d['direccion']);
    _ciudadCtrl.text    = _s(d['ciudad']);

    final act = _s(d['actividad']);
    if (act.isNotEmpty) _actividad = act;

    final ne = _s(d['nombre_empresa']);
    if (ne.isNotEmpty) {
      _empresaCtrl.text = ne;
      _tieneEmpresa = true;
    }

    final tieneRuc  = _i(d['tiene_ruc'])  == 1;
    final tieneRise = _i(d['tiene_rise']) == 1;
    if (tieneRuc) {
      _regimenTributario = 'ruc';
    } else if (tieneRise) {
      _regimenTributario = 'rise';
    }
  }

  /// Flujo al tocar "SÍ" en "¿El prospecto desea ser encuestado?":
  /// 1) Muestra diálogo para ingresar cédula.
  /// 2) Consulta buscar_prospecto_por_cedula.php.
  /// 3) Si existe → precarga datos y avanza al paso 1.
  /// 4) Si no existe → precarga solo la cédula y avanza al paso 1.
  Future<void> _iniciarFlujoConCedula() async {
    final cedulaCtrl = TextEditingController();
    final formKey = GlobalKey<FormState>();
    bool _dialogYaCerro = false;

    final cedulaIngresada = await showDialog<String>(
      context: context,
      barrierDismissible: false,
      builder: (dctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: const Text(
          'Buscar prospecto por cédula',
          style: TextStyle(fontWeight: FontWeight.w800),
        ),
        content: Form(
          key: formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Si la cédula ya existe en la base, se cargarán los datos del prospecto o cliente. '
                'Si no existe, se registrará como nuevo prospecto.',
                style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 12.5),
              ),
              const SizedBox(height: 14),
              TextFormField(
                controller: cedulaCtrl,
                autofocus: true,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: InputDecoration(
                  labelText: 'Cédula',
                  prefixIcon: const Icon(Icons.badge_rounded),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                ),
                validator: (v) {
                  final t = (v ?? '').trim();
                  if (t.isEmpty) return 'Cédula requerida';
                  if (t.length < 6) return 'Cédula inválida';
                  return null;
                },
                onFieldSubmitted: (_) {
                    if (_dialogYaCerro) return;
                    if (!(formKey.currentState?.validate() ?? false)) return;
                    _dialogYaCerro = true;
                    Navigator.of(dctx).pop(cedulaCtrl.text.trim());
                },
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
              onPressed: () {
                if (_dialogYaCerro) return;
                _dialogYaCerro = true;
                Navigator.of(dctx).pop(null);
              },
            child: const Text('Continuar sin buscar'),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: ConstantColors.success,
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            icon: const Icon(Icons.search_rounded, size: 18),
            label: const Text('Buscar',
                style: TextStyle(fontWeight: FontWeight.w800)),
            onPressed: () {
              if (_dialogYaCerro) return;
              if (!(formKey.currentState?.validate() ?? false)) return;
              _dialogYaCerro = true;
              Navigator.of(dctx).pop(cedulaCtrl.text.trim());
            },
          ),
        ],
      ),
    );

    // Esperar a que el diálogo se desmonte antes de liberar el controller
    WidgetsBinding.instance.addPostFrameCallback((_) {
      cedulaCtrl.dispose();
    });
    // Si el usuario cancela (o no ingresa cédula), igual avanzamos al formulario
    // para poder registrar el prospecto manualmente.
    if (cedulaIngresada == null || cedulaIngresada.isEmpty) {
      if (!mounted) return;
      _esProspectoNuevo = true;
      _origenProspecto = null;
      setState(() => _paso = _Paso.datosCliente);
      return;
    }
    if (!mounted) return;

    // Loader mientras consulta
    BuildContext? loaderCtx;
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) {
        loaderCtx = ctx;
        return const Center(child: CircularProgressIndicator());
      },
    );

    Map<String, dynamic> resultado;
    try {
      final url = Uri.parse('${Constants.apiBaseUrl}/buscar_prospecto_por_cedula.php');
      final resp = await http.post(url, body: {'cedula': cedulaIngresada})
          .timeout(const Duration(seconds: 15));
      final decoded = json.decode(resp.body);
      if (decoded is Map) {
        resultado = Map<String, dynamic>.from(decoded);
      } else {
        resultado = {'status': 'error', 'message': 'Respuesta inválida del servidor'};
      }
    } catch (e) {
      resultado = {'status': 'error', 'message': e.toString()};
    }

    if (loaderCtx != null) {
      Navigator.of(loaderCtx!).pop(); // cerrar loader (solo el diálogo)
    }
    if (!mounted) return;

    // Si por alguna razón la ruta ya no está activa, no tocar UI.
    final route = ModalRoute.of(context);
    if (route == null || !route.isCurrent) return;

    final status = resultado['status']?.toString() ?? '';

    if (status == 'error') {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(resultado['message']?.toString() ?? 'Error consultando cédula'),
          backgroundColor: ConstantColors.error,
        ),
      );
      return;
    }

    if (status == 'found') {
      final data = (resultado['data'] is Map)
          ? Map<String, dynamic>.from(resultado['data'] as Map)
          : <String, dynamic>{};
      final tipo = (resultado['tipo'] ?? 'prospecto').toString();
      final nombre = (data['nombre'] ?? '').toString();

      _aplicarDatosProspectoEncontrado(data);
      _esProspectoNuevo = false;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            tipo == 'cliente'
                ? 'Cliente existente: $nombre. Datos cargados.'
                : 'Prospecto existente: $nombre. Datos cargados.',
          ),
          backgroundColor: tipo == 'cliente' ? Colors.green.shade700 : ConstantColors.primaryBlue,
        ),
      );
    } else {
      // not_found
      _cedulaCtrl.text = cedulaIngresada;
      _esProspectoNuevo = true;
      _origenProspecto = null;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('Cédula nueva. Se registrará como nuevo prospecto.'),
          backgroundColor: ConstantColors.warning,
        ),
      );
    }

    if (!mounted) return;
    final route2 = ModalRoute.of(context);
    if (route2 == null || !route2.isCurrent) return;
    setState(() => _paso = _Paso.datosCliente);
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

    // La sección de empresa es completamente opcional:
    // - Si tiene empresa: se guarda nombre_empresa, los detalles se completan en "Levantar Empresa".
    // - Si no tiene empresa: se guarda la encuesta/tarea sin ningún dato de empresa.
    // En ambos casos se puede guardar y se puede solicitar crédito.

    // Computar promedios/valores por día para compatibilidad y envío
    final double _vLun = _toDouble(_ventaLunCtrl.text);
    final double _vMar = _toDouble(_ventaMarCtrl.text);
    final double _vMie = _toDouble(_ventaMieCtrl.text);
    final double _vJue = _toDouble(_ventaJueCtrl.text);
    final double _vVie = _toDouble(_ventaVieCtrl.text);
    final double _vSab = _toDouble(_ventaSabCtrl.text);
    final double _vDom = _toDouble(_ventaDomCtrl.text);
    final double _cLun = _toDouble(_compraLunCtrl.text);
    final double _cMar = _toDouble(_compraMarCtrl.text);
    final double _cMie = _toDouble(_compraMieCtrl.text);
    final double _cJue = _toDouble(_compraJueCtrl.text);
    final double _cVie = _toDouble(_compraVieCtrl.text);
    final double _cSab = _toDouble(_compraSabCtrl.text);
    final double _cDom = _toDouble(_compraDomCtrl.text);
    final avgVentaLv = ((_vLun + _vMar + _vMie + _vJue + _vVie) / 5.0);
    final avgCompraLv = ((_cLun + _cMar + _cMie + _cJue + _cVie) / 5.0);

    final body = <String, String>{
      'usuario_id': usuarioId,
      if (asesorId.isNotEmpty) 'asesor_id': asesorId,
      'tipo_tarea': widget.tipoTarea,
      'fue_encuestado': fueEncuestado ? '1' : '0',
      'origen_prospecto': _origenProspecto ?? '',
      if (widget.modoEdicion) 'tarea_id': widget.tareaIdEdicion ?? '',
      // Cliente
      'nombre': _nombreCtrl.text.trim(),
      'apellidos': _apellidosCtrl.text.trim(),
      'cedula': _cedulaCtrl.text.trim(),
      'telefono': _telefonoCtrl.text.trim(),
      'celular': _celularCtrl.text.trim(),
      'email_cliente': _emailCtrl.text.trim(),
      'direccion': _direccionCtrl.text.trim(),
      'ciudad': _ciudadCtrl.text.trim(),
      'actividad': _actividad ?? '',
      'tiene_ruc':  _tieneRuc  ? '1' : '0',
      'tiene_rise': _tieneRise ? '1' : '0',
      'regimen_tributario': _regimenTributario ?? '',
      'numero_ruc': _numeroRucCtrl.text.trim(),
      // Sub-preguntas RUC
      'declara_iva':          _declaraIva       == null ? '' : (_declaraIva!       ? '1' : '0'),
      'emite_facturas':       _emiteFacturas    == null ? '' : (_emiteFacturas!    ? '1' : '0'),
      'lleva_contabilidad':   _llevaContabilidad== null ? '' : (_llevaContabilidad!? '1' : '0'),
      // Sub-preguntas RISE
      'paga_cuota_rise':      _pagaCuotaRise    == null ? '' : (_pagaCuotaRise!    ? '1' : '0'),
      'emite_notas_venta':    _emiteNotasVenta  == null ? '' : (_emiteNotasVenta!  ? '1' : '0'),
      'conoce_limite_rise':   _conoceLimiteRise == null ? '' : (_conoceLimiteRise! ? '1' : '0'),
      'tiene_empresa': _tieneEmpresa ? '1' : '0',
      'nombre_empresa': _empresaCtrl.text.trim(),
      'tipo_empresa': [if (_tipoServProduccion) 'servicio_produccion', if (_tipoComercio) 'comercio'].join(','),
      // Empresa/Negocio (si aplica)
      // Enviar tanto los campos agrupados (compatibilidad) como por día
      'venta_lv':        _tieneEmpresa ? avgVentaLv.toStringAsFixed(2) : '',
      'venta_sabado':    _tieneEmpresa ? _ventaSabCtrl.text.trim() : '',
      'venta_domingo':   _tieneEmpresa ? _ventaDomCtrl.text.trim() : '',
      'venta_lunes':     _tieneEmpresa ? _ventaLunCtrl.text.trim() : '',
      'venta_martes':    _tieneEmpresa ? _ventaMarCtrl.text.trim() : '',
      'venta_miercoles': _tieneEmpresa ? _ventaMieCtrl.text.trim() : '',
      'venta_jueves':    _tieneEmpresa ? _ventaJueCtrl.text.trim() : '',
      'venta_viernes':   _tieneEmpresa ? _ventaVieCtrl.text.trim() : '',
      'mes_alta_venta':  _tieneEmpresa ? (_mesAltaVenta ?? '') : '',
      'mes_baja_venta':  _tieneEmpresa ? (_mesBajaVenta ?? '') : '',
      'compra_lv':       _tieneEmpresa ? avgCompraLv.toStringAsFixed(2) : '',
      'compra_sabado':   _tieneEmpresa ? _compraSabCtrl.text.trim() : '',
      'compra_domingo':  _tieneEmpresa ? _compraDomCtrl.text.trim() : '',
      'compra_lunes':    _tieneEmpresa ? _compraLunCtrl.text.trim() : '',
      'compra_martes':   _tieneEmpresa ? _compraMarCtrl.text.trim() : '',
      'compra_miercoles':_tieneEmpresa ? _compraMieCtrl.text.trim() : '',
      'compra_jueves':   _tieneEmpresa ? _compraJueCtrl.text.trim() : '',
      'compra_viernes':  _tieneEmpresa ? _compraVieCtrl.text.trim() : '',
      'mes_alta_compra': _tieneEmpresa ? (_mesAltaCompra ?? '') : '',
      // Días de atención (individuales y agrupados para compatibilidad)
      'dias_atencion_lunes':  _tieneEmpresa ? (_diaLun ? '1' : '0') : '0',
      'dias_atencion_martes': _tieneEmpresa ? (_diaMar ? '1' : '0') : '0',
      'dias_atencion_miercoles': _tieneEmpresa ? (_diaMie ? '1' : '0') : '0',
      'dias_atencion_jueves': _tieneEmpresa ? (_diaJue ? '1' : '0') : '0',
      'dias_atencion_viernes': _tieneEmpresa ? (_diaVie ? '1' : '0') : '0',
      'dias_atencion_sab': _tieneEmpresa ? (_diaSab ? '1' : '0') : '0',
      'dias_atencion_dom': _tieneEmpresa ? (_diaDom ? '1' : '0') : '0',
      'dias_atencion_lv': _tieneEmpresa ? ((_diaLun||_diaMar||_diaMie||_diaJue||_diaVie) ? '1' : '0') : '0',
      'costos_ventas':        _tieneEmpresa ? _costosVentasCtrl.text.trim() : '',
      'pct_efectivo':         _tieneEmpresa ? _pctEfectivo.toString() : '',
      // Gastos negocio desglosados
      'g_neg_sueldos':        _tieneEmpresa ? _gNegSueldosCtrl.text.trim() : '',
      'g_neg_arriendo':       _tieneEmpresa ? _gNegArriendoCtrl.text.trim() : '',
      'g_neg_serv_bas':       _tieneEmpresa ? _gNegServBasCtrl.text.trim() : '',
      'g_neg_transporte':     _tieneEmpresa ? _gNegTransporteCtrl.text.trim() : '',
      'g_neg_mantenimiento':  _tieneEmpresa ? _gNegMantCtrl.text.trim() : '',
      'g_neg_otros':          _tieneEmpresa ? _gNegOtrosCtrl.text.trim() : '',
      'g_neg_imprevistos':    _tieneEmpresa ? _gNegImprevistosCtrl.text.trim() : '',
      // Otros ingresos desglosados
      'o_ing_conyuge':        _tieneEmpresa ? _oIngConyugeCtrl.text.trim() : '',
      'o_ing_arriendos':      _tieneEmpresa ? _oIngArriendosCtrl.text.trim() : '',
      'o_ing_pensiones':      _tieneEmpresa ? _oIngPensionesCtrl.text.trim() : '',
      'o_ing_otros':          _tieneEmpresa ? _oIngOtrosCtrl.text.trim() : '',
      // Gastos familiares desglosados
      'g_fam_alim':           _tieneEmpresa ? _gFamAlimCtrl.text.trim() : '',
      'g_fam_arriendo':       _tieneEmpresa ? _gFamArriendoCtrl.text.trim() : '',
      'g_fam_serv_bas':       _tieneEmpresa ? _gFamServBasCtrl.text.trim() : '',
      'g_fam_educacion':      _tieneEmpresa ? _gFamEducCtrl.text.trim() : '',
      'g_fam_salud':          _tieneEmpresa ? _gFamSaludCtrl.text.trim() : '',
      'g_fam_otros':          _tieneEmpresa ? _gFamOtrosCtrl.text.trim() : '',
      'g_fam_imprevistos':    _tieneEmpresa ? _gFamImprevistosCtrl.text.trim() : '',
      // Totales calculados (retrocompat)
      'gastos_negocio': _tieneEmpresa ? (() {
        final t = _td(_gNegSueldosCtrl) + _td(_gNegArriendoCtrl) + _td(_gNegServBasCtrl) +
            _td(_gNegTransporteCtrl) + _td(_gNegMantCtrl) + _td(_gNegOtrosCtrl) + _td(_gNegImprevistosCtrl);
        return t > 0 ? t.toStringAsFixed(2) : _gastosNegocioCtrl.text.trim();
      })() : '',
      'otros_ingresos': _tieneEmpresa ? (() {
        final t = _td(_oIngConyugeCtrl) + _td(_oIngArriendosCtrl) + _td(_oIngPensionesCtrl) + _td(_oIngOtrosCtrl);
        return t > 0 ? t.toStringAsFixed(2) : _otrosIngresosCtrl.text.trim();
      })() : '',
      'gastos_familiares': _tieneEmpresa ? (() {
        final t = _td(_gFamAlimCtrl) + _td(_gFamArriendoCtrl) + _td(_gFamServBasCtrl) +
            _td(_gFamEducCtrl) + _td(_gFamSaludCtrl) + _td(_gFamOtrosCtrl) + _td(_gFamImprevistosCtrl);
        return t > 0 ? t.toStringAsFixed(2) : _gastosFamiliaresCtrl.text.trim();
      })() : '',
      // Vehículos
      'vehiculos_negocio_json': json.encode(List.generate(_kVehCount, (i) => {
        'descripcion': _vehNegDescCtrl[i].text.trim(), 'marca': _vehNegMarcaCtrl[i].text.trim(),
        'modelo': _vehNegModCtrl[i].text.trim(), 'anio': _vehNegAnioCtrl[i].text.trim(),
        'valor': _vehNegValCtrl[i].text.trim(),
      })),
      'vehiculos_hogar_json': json.encode(List.generate(_kVehCount, (i) => {
        'descripcion': _vehHogDescCtrl[i].text.trim(), 'marca': _vehHogMarcaCtrl[i].text.trim(),
        'modelo': _vehHogModCtrl[i].text.trim(), 'anio': _vehHogAnioCtrl[i].text.trim(),
        'valor': _vehHogValCtrl[i].text.trim(),
      })),
      // Inmuebles
      'inmuebles_negocio_json': json.encode(List.generate(_kInmCount, (i) => {
        'descripcion': _inmNegDescCtrl[i].text.trim(), 'area': _inmNegAreaCtrl[i].text.trim(),
        'ubicacion': _inmNegUbicCtrl[i].text.trim(), 'valor': _inmNegValCtrl[i].text.trim(),
      })),
      'inmuebles_hogar_json': json.encode(List.generate(_kInmCount, (i) => {
        'descripcion': _inmHogDescCtrl[i].text.trim(), 'area': _inmHogAreaCtrl[i].text.trim(),
        'ubicacion': _inmHogUbicCtrl[i].text.trim(), 'valor': _inmHogValCtrl[i].text.trim(),
      })),
      // Otras deudas
      'otras_deudas_json': json.encode(List.generate(_kDeudasCount, (i) => {
        'acreedor':      _deudaAcreedorCtrl[i].text.trim(),
        'destino':       _deudaDestinoCtrl[i].text.trim(),
        'monto_inicial': _deudaMontoIniCtrl[i].text.trim(),
        'saldo_actual':  _deudaSaldoActCtrl[i].text.trim(),
        'pago_mes':      _deudaPagoMesCtrl[i].text.trim(),
      })),
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
        'banco_ahorro': _bancoAhorroCtrl.text.trim(),
        'banco_corriente': _bancoCorrienteCtrl.text.trim(),
        'tiene_inversiones':
          _tieneInversiones == null ? '' : (_tieneInversiones! ? '1' : '0'),
        'institucion_inversiones': _instInvCtrl.text.trim(),
        'valor_inversion': _valorInvCtrl.text.trim(),
        'plazo_inversion': _plazoInvCtrl.text.trim(),
        'fecha_vencimiento_inversion': _fechaVencInv != null
          ? '${_fechaVencInv!.year}-${_fechaVencInv!.month.toString().padLeft(2, '0')}-${_fechaVencInv!.day.toString().padLeft(2, '0')}'
          : '',
        'propuesta_prev_vencimiento': _propuestaPrevVenc == null ? '' : (_propuestaPrevVenc! ? '1' : '0'),
        'fecha_previa_vencimiento': _fechaPrevVencInv != null
          ? '${_fechaPrevVencInv!.year}-${_fechaPrevVencInv!.month.toString().padLeft(2, '0')}-${_fechaPrevVencInv!.day.toString().padLeft(2, '0')}'
          : '',
        'propuesta_inversion': _propuestaInvCtrl.text.trim(),
        'tiene_operaciones_crediticias':
          _tieneOpsCred == null ? '' : (_tieneOpsCred! ? '1' : '0'),
        'institucion_credito': _instCredCtrl.text.trim(),
        'interes_conocer_productos':
            _interesConocer == null ? '' : (_interesConocer! ? '1' : '0'),
        'nivel_interes':
            (_interesCC || _interesAhorro || _interesInv || _interesCred)
                ? 'alto'
                : (_interesConocer == true ? 'bajo' : 'ninguno'),
        'interes_cc': _interesCC ? '1' : '0',
        'interes_ahorro': _interesAhorro ? '1' : '0',
        'interes_inversion': _interesInv ? '1' : '0',
        'interes_conocer_servicios': _interesConocerServicios == null ? '' : (_interesConocerServicios! ? '1' : '0'),
        // Crear tarea de propuesta de inversión previa al vencimiento:
        // requiere que el usuario haya activado la propuesta Y que exista
        // la fecha de la visita de inversión (_fechaPrevVencInv), no la fecha del acuerdo.
        'crear_tarea_prev_venc': (_propuestaPrevVenc == true && _fechaPrevVencInv != null) ? '1' : '0',
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

    // Si Comercio: empaquetar productos de comercio en JSON para envío
    if (_tipoComercio) {
      final comProds = <Map<String, dynamic>>[];
      for (int i = 0; i < _kComProdCount; i++) {
        final costoVal   = _toDouble(_comCostoCtrl[i].text);
        final precioVal  = _toDouble(_comPrecioCtrl[i].text);
        final cantVal    = _toDouble(_comCantidadCtrl[i].text);
        final unidVal    = _toDouble(_comUnidExistCtrl[i].text);
        final margen     = precioVal > 0 ? (costoVal / precioVal) * 100 : 0.0;
        comProds.add({
          'nombre': _comNombreCtrl[i].text.trim(),
          'costo_unidad': costoVal.toStringAsFixed(2),
          'precio_venta_unidad': precioVal.toStringAsFixed(2),
          'tipo_unidad': _comTipoUnidadCtrl[i].text.trim(),
          'cantidad_vendida_mes': cantVal.toStringAsFixed(0),
          'margen_utilidad': margen.toStringAsFixed(2),
          'unidades_existentes': unidVal.toStringAsFixed(0),
          'costo_total_compra': (costoVal * cantVal).toStringAsFixed(2),
          'venta_mes': (precioVal * cantVal).toStringAsFixed(2),
          'inventario': (unidVal * costoVal).toStringAsFixed(2),
        });
      }
      body['comercio_productos_json'] = json.encode(comProds);
    }

    // Si Servicio/Producción: empaquetar productos en JSON para envío
    if (_tipoServProduccion) {
      final prods = <Map<String, dynamic>>[];
      for (int i = 0; i < _kProdCount; i++) {
        // Construir lista de materias con nombre + valor
        final matsList = List.generate(_kMatCount, (m) => {
          'nombre': _prodMatNomCtrl[i][m].text.trim(),
          'valor':  _prodMatCtrl[i][m].text.trim(),
        });
        final totalMat   = _prodMatCtrl[i].map((c) => _toDouble(c.text)).fold(0.0, (a, b) => a + b);
        final manoVal    = _toDouble(_prodManoCtrl[i].text);
        final empaVal    = _toDouble(_prodEmpaqueCtrl[i].text);
        final otrosVal   = _toDouble(_prodOtrosCtrl[i].text);
        final costoTotal = totalMat + manoVal + empaVal + otrosVal;             // (1)
        final unidProd   = _toDouble(_prodUnidadesProdCtrl[i].text);            // (2)
        final costoUnit  = unidProd * costoTotal;                               // A = (2)×(1)
        final precioB    = _toDouble(_prodPrecioCtrl[i].text);                  // B
        final unidVendC  = _toDouble(_prodUnidadesVendCtrl[i].text);            // C
        final unidVerifD = _toDouble(_prodUnidExistCtrl[i].text);               // D

        prods.add({
          'nombre':              _prodNameCtrl[i].text.trim(),
          'materias':            matsList,
          'total_materia_prima': totalMat.toStringAsFixed(2),
          'mano_obra':           manoVal.toStringAsFixed(2),
          'empaques':            empaVal.toStringAsFixed(2),
          'otros_costos':        otrosVal.toStringAsFixed(2),
          'costo_total':         costoTotal.toStringAsFixed(2),     // (1)
          'unidades_producidas': unidProd.toStringAsFixed(0),       // (2)
          'costo_unitario':      costoUnit.toStringAsFixed(4),      // A
          'precio_unitario':     precioB.toStringAsFixed(2),        // B
          'unidades_vendidas':   unidVendC.toStringAsFixed(0),      // C
          'unidades_verificadas':unidVerifD.toStringAsFixed(0),     // D
          'ventas_mensuales':    (precioB * unidVendC).toStringAsFixed(2),     // B×C
          'costo_ventas':        (costoUnit * unidVendC).toStringAsFixed(2),   // A×C
          'inventarios':         (costoUnit * unidVerifD).toStringAsFixed(2),  // A×D
        });
      }
      body['productos_json'] = json.encode(prods);
    }

    // Activos fijos del negocio
    body['activos_negocio_json'] = json.encode(List.generate(_kActivosCount, (i) => {
      'descripcion':    _actNegDescCtrl[i].text.trim(),
      'marca':          _actNegMarcaCtrl[i].text.trim(),
      'modelo':         _actNegModeloCtrl[i].text.trim(),
      'serie':          _actNegSerieCtrl[i].text.trim(),
      'valor_comercial':_actNegValorCtrl[i].text.trim(),
    }));

    // Activos fijos del hogar
    body['activos_hogar_json'] = json.encode(List.generate(_kActivosCount, (i) => {
      'descripcion':    _actHogDescCtrl[i].text.trim(),
      'marca':          _actHogMarcaCtrl[i].text.trim(),
      'modelo':         _actHogModeloCtrl[i].text.trim(),
      'serie':          _actHogSerieCtrl[i].text.trim(),
      'valor_comercial':_actHogValorCtrl[i].text.trim(),
    }));

    try {
      final endpoint = widget.modoEdicion
          ? 'actualizar_encuesta_completa.php'
          : 'guardar_cliente_encuesta.php';
      final url = Uri.parse('${Constants.apiBaseUrl}/$endpoint');
      debugPrint(
        '>>> [ENC] POST $url usuario_id=$usuarioId asesor_id=${asesorId.isNotEmpty ? asesorId : '-'} fue_encuestado=${fueEncuestado ? 1 : 0} edicion=${widget.modoEdicion}',
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
        if (widget.modoEdicion) {
          // En modo edición NO se cierran/abren segmentos ni se crean nuevas
          // tareas: solo se informa el éxito y se vuelve a la lista.
          _mostrarDialogoModificacionOk();
        } else {
          // ── Cerrar segmento de ruta actual e iniciar el siguiente ──
          final tareaId = data['tarea_id']?.toString() ?? '';
          _cerrarYNuevoSegmento(tareaId: tareaId);

          // Mapa completo de etiquetas para todos los tipos de tarea
          String _labelTipo(String tipo) {
            const labels = <String, String>{
              'nueva_cita_campo':    'Nueva cita en campo',
              'nueva_cita_oficina':  'Nueva cita en oficina',
              'documentos_pendientes': 'Recolectar documentación',
              'levantamiento':       'Levantamiento de empresa',
              'visita_frio':         'Visita en frío',
              'evaluacion':          'Evaluación',
              'prospecto_nuevo':     'Prospecto nuevo',
              'recuperacion':        'Recuperación',
              'post_venta':          'Post venta',
              'represtamo':          'Représ tamo',
              'seguimiento':         'Seguimiento',
            };
            return labels[tipo] ?? tipo.replaceAll('_', ' ');
          }

          String _descTarea(Map data, String idKey, String tipoKey, String fechaKey, String horaKey) {
            final id = data[idKey]?.toString() ?? '';
            if (id.isEmpty) return '';
            final tipo = data[tipoKey]?.toString() ?? '';
            final fecha = data[fechaKey]?.toString() ?? '';
            final hora = data[horaKey]?.toString() ?? '';
            final fh = [fecha, hora].where((e) => e.trim().isNotEmpty).join(' ');
            return '• ${_labelTipo(tipo)}${fh.isNotEmpty ? ' ($fh)' : ''}';
          }

          final lineas = <String>[
            _descTarea(data, 'tarea_followup_id',   'tarea_followup_tipo',   'tarea_followup_fecha',   'tarea_followup_hora'),
            _descTarea(data, 'tarea_inversion_id',  'tarea_inversion_tipo',  'tarea_inversion_fecha',  'tarea_inversion_hora'),
          ].where((l) => l.isNotEmpty).toList();

          final String? seguimientoTexto = lineas.isEmpty
              ? null
              : 'Tareas programadas:\n${lineas.join('\n')}';

          _mostrarDialogoFinalizado(
            fueEncuestado: fueEncuestado,
            seguimientoTexto: seguimientoTexto,
          );
        }
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

  /// Diálogo que se muestra cuando una edición de encuesta se guardó
  /// correctamente. A diferencia del modo normal, no cerramos segmentos
  /// ni creamos nuevas tareas.
  void _mostrarDialogoModificacionOk() {
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
                      ConstantColors.success,
                      ConstantColors.primaryBlue,
                    ],
                  ),
                ),
                child: const Icon(Icons.save_rounded, color: Colors.white, size: 34),
              ),
              const SizedBox(height: 18),
              Text(
                'Cambios guardados',
                style: TextStyle(
                  color: ConstantColors.textDark,
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                'Los datos de la encuesta se actualizaron correctamente.',
                style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 14),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () {
                    Navigator.of(ctx).pop();
                    Navigator.of(context).pop(true);
                  },
                  style: ElevatedButton.styleFrom(
                    backgroundColor: ConstantColors.warning,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: const Text('Volver',
                      style: TextStyle(fontWeight: FontWeight.w700)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _mostrarDialogoFinalizado({
    required bool fueEncuestado,
    String? seguimientoTexto,
  }) {
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
                    ? 'Encuesta y datos del prospecto guardados correctamente.'
                    : 'Se registró que el prospecto no quiso ser encuestado.',
                style:
                    TextStyle(color: ConstantColors.textDarkGrey, fontSize: 14),
                textAlign: TextAlign.center,
              ),
              if (seguimientoTexto != null && seguimientoTexto.trim().isNotEmpty) ...[
                const SizedBox(height: 10),
                Text(
                  seguimientoTexto,
                  style: TextStyle(color: ConstantColors.textDark, fontSize: 13, fontWeight: FontWeight.w600),
                  textAlign: TextAlign.center,
                ),
              ],
              const SizedBox(height: 24),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () {
                    Navigator.of(ctx).pop();
                    Navigator.of(context).pop(true);
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

  List<_Paso> get _pasosActivos {
    return [
      _Paso.datosCliente,
      if (_tieneEmpresa && widget.incluirEmpresa) _Paso.empresaNegocio,
      _Paso.productosActuales,
      _Paso.busqueda,
    ];
  }

  void _irSiguientePaso() {
    if (_paso == _Paso.inicial) {
      setState(() => _paso = _Paso.datosCliente);
      return;
    }
    final pasos = _pasosActivos;
    final idx = pasos.indexOf(_paso);
    if (idx >= 0 && idx < pasos.length - 1) {
      setState(() => _paso = pasos[idx + 1]);
    }
  }

  void _irPasoPrevio() {
    if (_paso == _Paso.inicial) {
      Navigator.pop(context);
      return;
    }
    final pasos = _pasosActivos;
    final idx = pasos.indexOf(_paso);
    if (idx > 0) {
      setState(() => _paso = pasos[idx - 1]);
    } else {
      Navigator.pop(context);
    }
  }

  int get _indexPaso {
    if (_paso == _Paso.inicial) return 0;
    final idx = _pasosActivos.indexOf(_paso);
    return idx < 0 ? 0 : (idx + 1);
  }

  int get _totalPasos => _pasosActivos.length;

  bool get _shouldShowNavButtons {
    if (_paso == _Paso.inicial) return false;
    if (_paso == _Paso.busqueda) return false; // botón inline
    if (_paso == _Paso.interesProductos && _interesConocer == false) return false; // botón inline
    return true;
  }

  // ── BUILD ────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final bottomSafe = MediaQuery.of(context).padding.bottom;
    final bottomPad = 24 + bottomSafe + (_shouldShowNavButtons ? 110 : 20);

    return PopScope(
      canPop: false,
      onPopInvoked: (didPop) {
        if (!didPop) _confirmarSalida();
      },
      child: Scaffold(
        backgroundColor: ConstantColors.grey100,
        body: SafeArea(
          child: Stack(
            children: [
              Column(
                children: [
                  _buildAppBar(),
                  if (widget.modoEdicion) _buildBannerEdicion(),
                  if (_paso != _Paso.inicial) _buildProgreso(),
                  Expanded(
                    child: AnimatedSwitcher(
                      duration: const Duration(milliseconds: 280),
                      child: SingleChildScrollView(
                        key: ValueKey(_paso),
                        padding: EdgeInsets.fromLTRB(20, 16, 20, bottomPad),
                        child: _buildContenidoPaso(),
                      ),
                    ),
                  ),
                ],
              ),
              if (_cargandoEdicion)
                Container(
                  color: Colors.black.withOpacity(0.35),
                  child: const Center(
                    child: Card(
                      child: Padding(
                        padding: EdgeInsets.all(20),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            ),
                            SizedBox(width: 14),
                            Text('Cargando encuesta…',
                                style: TextStyle(fontWeight: FontWeight.w600)),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              if (_errorEdicion != null && !_cargandoEdicion)
                Positioned(
                  top: 10,
                  left: 16,
                  right: 16,
                  child: Material(
                    color: Colors.red.shade50,
                    borderRadius: BorderRadius.circular(12),
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        children: [
                          Icon(Icons.error_outline_rounded,
                              color: Colors.red.shade700, size: 18),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'No se pudo cargar la encuesta: $_errorEdicion',
                              style: TextStyle(
                                color: Colors.red.shade800,
                                fontSize: 12.5,
                              ),
                            ),
                          ),
                          TextButton(
                            onPressed: _cargarEncuestaEnEdicion,
                            child: const Text('Reintentar'),
                          ),
                        ],
                      ),
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

  /// Banner visible en modo edición para que el asesor sepa que está
  /// modificando una encuesta ya finalizada.
  Widget _buildBannerEdicion() {
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: ConstantColors.warning.withOpacity(0.12),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: ConstantColors.warning.withOpacity(0.5)),
      ),
      child: Row(
        children: [
          Icon(Icons.edit_note_rounded, color: ConstantColors.warning, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'Estás modificando una encuesta finalizada. '
              'Se pueden cambiar todos los datos excepto la cédula.',
              style: TextStyle(
                color: ConstantColors.textDark,
                fontSize: 12.5,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ],
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
    if (widget.modoEdicion) {
      switch (_paso) {
        case _Paso.inicial:
        case _Paso.datosCliente:
          return 'Modificar datos';
        case _Paso.empresaNegocio:
          return 'Modificar negocio';
        case _Paso.productosActuales:
          return 'Modificar productos';
        case _Paso.interesProductos:
          return 'Modificar interés';
        case _Paso.busqueda:
          return 'Modificar cierre';
      }
    }
    switch (_paso) {
      case _Paso.inicial:
        return widget.tipoTarea == 'recuperacion'
            ? 'Visita de Recuperación'
            : 'Nueva Tarea';
      case _Paso.datosCliente:
        return 'Datos del Prospecto';
      case _Paso.empresaNegocio:
        return 'Empresa / Negocio';
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
    final total = _totalPasos;
    final actual = _indexPaso; // 1..total
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
      case _Paso.empresaNegocio:
        return _buildPasoEmpresaNegocio();
      case _Paso.productosActuales:
        return _buildPasoProductosActuales();
      case _Paso.interesProductos:
        return _buildPasoInteresProductos();
      case _Paso.busqueda:
        return _buildPasoBusqueda();
    }
  }

  // ── PASO 3: Interés en productos (placeholder, se muestra inline en Productos)
  Widget _buildPasoInteresProductos() {
    return const SizedBox.shrink();
  }

  // ── PASO 0: Pregunta inicial ─────────────────────────────────

  Widget _buildPasoInicial() {
    return Column(
      children: [
        const SizedBox(height: 20),
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
          '¿El prospecto desea ser\nencuestado?',
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
          'Seleccione la respuesta del prospecto para continuar.',
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
                onTap: _iniciarFlujoConCedula,
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

  // ── PASO 1: Datos del prospecto ──────────────────────────────

  Widget _buildPasoDatosCliente() {
    return Form(
      key: _formKeyCliente,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (_esProspectoNuevo == true && _origenProspecto == null) ...[
            _seccionTitulo('Tipo de prospecto'),
            Text(
              'Seleccione una opción para continuar con el registro del prospecto.',
              style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 13),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: _botonRespuestaGrande(
                    label: 'FRÍO',
                    sublabel: 'No conoce / no nos sigue',
                    color: ConstantColors.warning,
                    icon: Icons.ac_unit_rounded,
                    onTap: () => setState(() => _origenProspecto = 'frio'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _botonRespuestaGrande(
                    label: 'SEGUIDOR',
                    sublabel: 'Sí conoce / sí nos sigue',
                    color: ConstantColors.primaryBlue,
                    icon: Icons.favorite_rounded,
                    onTap: () => setState(() => _origenProspecto = 'seguidor'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
          ],
          if (_esProspectoNuevo == true && _origenProspecto == null)
            const SizedBox(height: 6),

          if (!(_esProspectoNuevo == true && _origenProspecto == null)) ...[
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
            label: widget.modoEdicion ? 'Cédula (no editable)' : 'Cédula',
            icon: Icons.badge_rounded,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            readOnly: widget.modoEdicion,
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
          _campo(
            controller: _ciudadCtrl,
            label: 'Ciudad',
            icon: Icons.location_city_rounded,
          ),
          const SizedBox(height: 20),
          _seccionTitulo('Actividad Económica'),
          _dropdownActividad(),
          const SizedBox(height: 16),
          _seccionTitulo('Régimen Tributario'),
          _buildRegimenTributario(),
          const SizedBox(height: 12),
          _switchItem(
            label: '¿Tiene empresa?',
            value: _tieneEmpresa,
            onChanged: (v) {
              setState(() {
                _tieneEmpresa = v;
                if (!_tieneEmpresa) {
                  // Limpiar tipo empresa
                  _tipoServProduccion = false;
                  _tipoComercio = false;
                  // Limpiar datos del paso negocio si el usuario desactiva
                  _ventaLunCtrl.clear();
                  _ventaMarCtrl.clear();
                  _ventaMieCtrl.clear();
                  _ventaJueCtrl.clear();
                  _ventaVieCtrl.clear();
                  _ventaSabCtrl.clear();
                  _ventaDomCtrl.clear();
                  _compraLunCtrl.clear();
                  _compraMarCtrl.clear();
                  _compraMieCtrl.clear();
                  _compraJueCtrl.clear();
                  _compraVieCtrl.clear();
                  _compraSabCtrl.clear();
                  _compraDomCtrl.clear();
                  _mesAltaVenta = null;
                  _mesBajaVenta = null;
                  _mesAltaCompra = null;
                  _diaLun = true;
                  _diaMar = true;
                  _diaMie = true;
                  _diaJue = true;
                  _diaVie = true;
                  _diaSab = false;
                  _diaDom = false;
                  _pctContado = 80;
                  _pctEfectivo = 70;
                  _recuperacionCreditoCtrl.clear();
                  _costosVentasCtrl.clear();
                  _gastosNegocioCtrl.clear();
                  _otrosIngresosCtrl.clear();
                  _gastosFamiliaresCtrl.clear();
                  for (int i = 0; i < _kDeudasCount; i++) {
                    _deudaAcreedorCtrl[i].clear();
                    _deudaDestinoCtrl[i].clear();
                    _deudaMontoIniCtrl[i].clear();
                    _deudaSaldoActCtrl[i].clear();
                    _deudaPagoMesCtrl[i].clear();
                  }
                  _gNegSueldosCtrl.clear(); _gNegArriendoCtrl.clear();
                  _gNegServBasCtrl.clear(); _gNegTransporteCtrl.clear();
                  _gNegMantCtrl.clear(); _gNegOtrosCtrl.clear(); _gNegImprevistosCtrl.clear();
                  _oIngConyugeCtrl.clear(); _oIngArriendosCtrl.clear();
                  _oIngPensionesCtrl.clear(); _oIngOtrosCtrl.clear();
                  _gFamAlimCtrl.clear(); _gFamArriendoCtrl.clear();
                  _gFamServBasCtrl.clear(); _gFamEducCtrl.clear();
                  _gFamSaludCtrl.clear(); _gFamOtrosCtrl.clear(); _gFamImprevistosCtrl.clear();
                }
              });
            },
          ),
          if (_tieneEmpresa) ...[
            const SizedBox(height: 8),
            _campo(
              controller: _empresaCtrl,
              label: 'Nombre de la empresa',
              icon: Icons.business_rounded,
              validator: (v) {
                if (!_tieneEmpresa) return null;
                if (v == null || v.trim().isEmpty) return 'Ingrese el nombre del negocio';
                return null;
              },
            ),
            const SizedBox(height: 8),
            // Tipo de empresa: checkboxes estilo radio (puede seleccionar uno o ambos)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(
                'Tipo de empresa',
                style: TextStyle(
                  color: ConstantColors.textDarkGrey,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
            const SizedBox(height: 6),
            ...[
              (
                key: 'servicio_produccion',
                label: '🏭 Servicio / Producción',
                sub: 'Produce o presta servicios',
                selected: _tipoServProduccion,
                onTap: () => setState(() => _tipoServProduccion = !_tipoServProduccion),
              ),
              (
                key: 'comercio',
                label: '🛒 Comercio',
                sub: 'Compra y venta de productos',
                selected: _tipoComercio,
                onTap: () => setState(() => _tipoComercio = !_tipoComercio),
              ),
            ].map((opt) {
              return GestureDetector(
                onTap: opt.onTap,
                child: Container(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  decoration: BoxDecoration(
                    color: opt.selected
                        ? ConstantColors.warning.withOpacity(0.12)
                        : ConstantColors.grey100,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(
                      color: opt.selected
                          ? ConstantColors.warning
                          : ConstantColors.borderLight,
                      width: opt.selected ? 1.5 : 1,
                    ),
                  ),
                  child: Row(
                    children: [
                      Icon(
                        opt.selected
                            ? Icons.check_box_rounded
                            : Icons.check_box_outline_blank_rounded,
                        color: opt.selected
                            ? ConstantColors.warning
                            : ConstantColors.textDarkGrey,
                        size: 22,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              opt.label,
                              style: TextStyle(
                                color: ConstantColors.textDark,
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            Text(
                              opt.sub,
                              style: TextStyle(
                                color: ConstantColors.textDarkGrey,
                                fontSize: 12,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              );
            }).toList(),
            // Los productos se muestran en el paso siguiente (Empresa/Negocio),
            // después de las preguntas de ingresos y gastos.
          ],
          ],
        ],
      ),
    );
  }

  // ── PASO: Empresa / Negocio ─────────────────────────────────

  double _toDouble(String s) {
    final t = s.trim().replaceAll(',', '.');
    return double.tryParse(t) ?? 0.0;
  }

  /// Shortcut: _toDouble de un TextEditingController
  double _td(TextEditingController c) => _toDouble(c.text);

  // ── Computed: cuotas desde otras deudas ─────────────────
  double get _cuotasNegocioDeudas => List.generate(_kDeudasCount, (i) {
    final dest = _deudaDestinoCtrl[i].text.trim().toLowerCase();
    if (dest.contains('negoc') || dest.contains('empr')) {
      return _toDouble(_deudaPagoMesCtrl[i].text);
    }
    return 0.0;
  }).fold(0.0, (a, b) => a + b);

  double get _cuotasFamiliaresDeudas => List.generate(_kDeudasCount, (i) {
    final dest = _deudaDestinoCtrl[i].text.trim().toLowerCase();
    final acreedor = _deudaAcreedorCtrl[i].text.trim();
    if (acreedor.isEmpty) return 0.0;
    if (!dest.contains('negoc') && !dest.contains('empr')) {
      return _toDouble(_deudaPagoMesCtrl[i].text);
    }
    return 0.0;
  }).fold(0.0, (a, b) => a + b);

  double get _totalPagoMesDeudas => List.generate(_kDeudasCount,
      (i) => _toDouble(_deudaPagoMesCtrl[i].text)).fold(0.0, (a, b) => a + b);

  double get _totalSaldoDeudas => List.generate(_kDeudasCount,
      (i) => _toDouble(_deudaSaldoActCtrl[i].text)).fold(0.0, (a, b) => a + b);

  Widget _dropdownMesSimple(String label, String? value, ValueChanged<String?> onChanged) {
    const meses = <String, String>{
      'enero': 'Enero',
      'febrero': 'Febrero',
      'marzo': 'Marzo',
      'abril': 'Abril',
      'mayo': 'Mayo',
      'junio': 'Junio',
      'julio': 'Julio',
      'agosto': 'Agosto',
      'septiembre': 'Septiembre',
      'octubre': 'Octubre',
      'noviembre': 'Noviembre',
      'diciembre': 'Diciembre',
    };

    return DropdownButtonFormField<String>(
      value: value,
      decoration: InputDecoration(
        labelText: label,
        floatingLabelBehavior: FloatingLabelBehavior.always,
        hintText: 'Seleccione',
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      ),
      items: meses.entries
          .map((e) => DropdownMenuItem<String>(value: e.key, child: Text(e.value)))
          .toList(),
      onChanged: onChanged,
    );
  }

  Widget _buildPasoEmpresaNegocio() {
    // Si por alguna razón entran aquí sin empresa, saltar
    if (!_tieneEmpresa) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) setState(() => _paso = _Paso.productosActuales);
      });
      return const SizedBox.shrink();
    }

    final ventaSemana = _toDouble(_ventaLunCtrl.text) + _toDouble(_ventaMarCtrl.text) + _toDouble(_ventaMieCtrl.text) + _toDouble(_ventaJueCtrl.text) + _toDouble(_ventaVieCtrl.text) + _toDouble(_ventaSabCtrl.text) + _toDouble(_ventaDomCtrl.text);
    final compraSemana = _toDouble(_compraLunCtrl.text) + _toDouble(_compraMarCtrl.text) + _toDouble(_compraMieCtrl.text) + _toDouble(_compraJueCtrl.text) + _toDouble(_compraVieCtrl.text) + _toDouble(_compraSabCtrl.text) + _toDouble(_compraDomCtrl.text);

    return Form(
      key: _formKeyNegocio,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _seccionTitulo('Empresa / Negocio'),
          Text(
            'Datos aproximados para entender el movimiento del negocio (sin ser un interrogatorio).',
            style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 13),
          ),
          const SizedBox(height: 16),

          _seccionTitulo('Comportamiento de ventas (monto \$ al día)'),
          const SizedBox(height: 6),
          // Mostrar un campo por día, de arriba hacia abajo
          _diaCampo('Lunes', _ventaLunCtrl, Icons.trending_up_rounded),
          _diaCampo('Martes', _ventaMarCtrl, Icons.trending_up_rounded),
          _diaCampo('Miércoles', _ventaMieCtrl, Icons.trending_up_rounded),
          _diaCampo('Jueves', _ventaJueCtrl, Icons.trending_up_rounded),
          _diaCampo('Viernes', _ventaVieCtrl, Icons.trending_up_rounded),
          _diaCampo('Sábado', _ventaSabCtrl, Icons.trending_up_rounded),
          _diaCampo('Domingo', _ventaDomCtrl, Icons.trending_up_rounded),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(child: _dropdownMesSimple('Mes alto (venta)', _mesAltaVenta, (v) => setState(() => _mesAltaVenta = v))),
            const SizedBox(width: 10),
            Expanded(child: _dropdownMesSimple('Mes bajo (venta)', _mesBajaVenta, (v) => setState(() => _mesBajaVenta = v))),
          ]),

          const SizedBox(height: 16),
          _seccionTitulo('Comportamiento de compras (monto \$ al día)'),
          _seccionTitulo('Comportamiento de compras (monto \$ al día)'),
          const SizedBox(height: 6),
          _diaCampo('Lunes', _compraLunCtrl, Icons.shopping_cart_rounded),
          _diaCampo('Martes', _compraMarCtrl, Icons.shopping_cart_rounded),
          _diaCampo('Miércoles', _compraMieCtrl, Icons.shopping_cart_rounded),
          _diaCampo('Jueves', _compraJueCtrl, Icons.shopping_cart_rounded),
          _diaCampo('Viernes', _compraVieCtrl, Icons.shopping_cart_rounded),
          _diaCampo('Sábado', _compraSabCtrl, Icons.shopping_cart_rounded),
          _diaCampo('Domingo', _compraDomCtrl, Icons.shopping_cart_rounded),
          const SizedBox(height: 10),
          _dropdownMesSimple('Mes alto (compra)', _mesAltaCompra, (v) => setState(() => _mesAltaCompra = v)),

          const SizedBox(height: 14),
          _seccionTitulo('Días de atención'),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilterChip(label: const Text('Lunes'), selected: _diaLun, onSelected: (v) => setState(() => _diaLun = v)),
              FilterChip(label: const Text('Martes'), selected: _diaMar, onSelected: (v) => setState(() => _diaMar = v)),
              FilterChip(label: const Text('Miércoles'), selected: _diaMie, onSelected: (v) => setState(() => _diaMie = v)),
              FilterChip(label: const Text('Jueves'), selected: _diaJue, onSelected: (v) => setState(() => _diaJue = v)),
              FilterChip(label: const Text('Viernes'), selected: _diaVie, onSelected: (v) => setState(() => _diaVie = v)),
              FilterChip(label: const Text('Sábado'), selected: _diaSab, onSelected: (v) => setState(() => _diaSab = v)),
              FilterChip(label: const Text('Domingo'), selected: _diaDom, onSelected: (v) => setState(() => _diaDom = v)),
            ],
          ),

          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: ConstantColors.grey100,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: ConstantColors.borderLight),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    'Resumen rápido (semana)\nVentas: ${ventaSemana.toStringAsFixed(0)}  |  Compras: ${compraSemana.toStringAsFixed(0)}',
                    style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 12.5, height: 1.35),
                  ),
                ),
              ],
            ),
          ),

          // ─── Forma de cobro ────────────────────────────────────
          const SizedBox(height: 22),
          _seccionTitulo('Forma de cobro'),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: ConstantColors.borderLight),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Icon(Icons.payments_rounded, size: 18, color: ConstantColors.primaryBlue),
                  const SizedBox(width: 8),
                  Expanded(child: Text('¿Qué % cobra en efectivo?',
                      style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5))),
                  const SizedBox(width: 8),
                  Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(color: Colors.green.shade100, borderRadius: BorderRadius.circular(6)),
                      child: Text('💵 $_pctEfectivo% efectivo',
                          style: TextStyle(color: Colors.green.shade900, fontWeight: FontWeight.w700, fontSize: 11.5)),
                    ),
                    const SizedBox(height: 3),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(color: Colors.blue.shade100, borderRadius: BorderRadius.circular(6)),
                      child: Text('💳 ${100 - _pctEfectivo}% digital',
                          style: TextStyle(color: Colors.blue.shade900, fontWeight: FontWeight.w700, fontSize: 11.5)),
                    ),
                  ]),
                ]),
                Slider(
                  value: _pctEfectivo.toDouble(),
                  min: 0, max: 100, divisions: 20,
                  activeColor: Colors.green.shade600,
                  label: '$_pctEfectivo%',
                  onChanged: (v) => setState(() => _pctEfectivo = v.round()),
                ),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('0% efectivo\n(todo digital)', textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey)),
                    Text('50/50', style: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey)),
                    Text('100% efectivo\n(todo en cash)', textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey)),
                  ],
                ),
              ],
            ),
          ),

          // ── Productos según tipo de empresa ─────────────────────
          if (_tipoServProduccion) ...[
            const SizedBox(height: 24),
            _seccionTituloDestacado('🏭 Productos de Producción', 'Ingrese mínimo 5 productos'),
            const SizedBox(height: 8),
            for (int i = 0; i < _kProdCount; i++) _buildProductoCard(i),
            _buildResumenProductos(
              llenos: List.generate(_kProdCount, (i) => _prodNameCtrl[i].text.trim().isNotEmpty).where((v) => v).length,
              totalCostoCompras: List.generate(_kProdCount, (i) {
                final mat = _prodMatCtrl[i].map((c) => _toDouble(c.text)).fold(0.0, (a, b) => a + b);
                final ct  = mat + _toDouble(_prodManoCtrl[i].text) + _toDouble(_prodEmpaqueCtrl[i].text) + _toDouble(_prodOtrosCtrl[i].text);
                final cu  = _toDouble(_prodUnidadesProdCtrl[i].text) * ct;
                return cu * _toDouble(_prodUnidadesVendCtrl[i].text);
              }).fold(0.0, (a, b) => a + b),
              totalVentasMes: List.generate(_kProdCount, (i) =>
                _toDouble(_prodPrecioCtrl[i].text) * _toDouble(_prodUnidadesVendCtrl[i].text))
                .fold(0.0, (a, b) => a + b),
              totalInventario: List.generate(_kProdCount, (i) {
                final mat = _prodMatCtrl[i].map((c) => _toDouble(c.text)).fold(0.0, (a, b) => a + b);
                final ct  = mat + _toDouble(_prodManoCtrl[i].text) + _toDouble(_prodEmpaqueCtrl[i].text) + _toDouble(_prodOtrosCtrl[i].text);
                final cu  = _toDouble(_prodUnidadesProdCtrl[i].text) * ct;
                return cu * _toDouble(_prodUnidExistCtrl[i].text);
              }).fold(0.0, (a, b) => a + b),
              minimo: _kProdCount,
            ),
          ],

          if (_tipoComercio) ...[
            const SizedBox(height: 24),
            _seccionTituloDestacado('🛒 Productos Comercializados', 'Ingrese mínimo 5 productos'),
            const SizedBox(height: 8),
            for (int i = 0; i < _kComProdCount; i++) _buildComercioProductoCard(i),
            _buildResumenProductos(
              llenos: List.generate(_kComProdCount, (i) => _comNombreCtrl[i].text.trim().isNotEmpty).where((v) => v).length,
              totalCostoCompras: List.generate(_kComProdCount, (i) => _toDouble(_comCantidadCtrl[i].text) * _toDouble(_comCostoCtrl[i].text)).fold(0.0, (a, b) => a + b),
              totalVentasMes:    List.generate(_kComProdCount, (i) => _toDouble(_comPrecioCtrl[i].text) * _toDouble(_comCantidadCtrl[i].text)).fold(0.0, (a, b) => a + b),
              totalInventario:   List.generate(_kComProdCount, (i) => _toDouble(_comUnidExistCtrl[i].text) * _toDouble(_comCostoCtrl[i].text)).fold(0.0, (a, b) => a + b),
              minimo: _kComProdCount,
            ),
          ],

          // ── Activos Fijos del Negocio ──────────────────────────
          const SizedBox(height: 28),
          _buildActivosFijos(
            titulo: '🏢 Activos Fijos del Negocio',
            descControllers:   _actNegDescCtrl,
            marcaControllers:  _actNegMarcaCtrl,
            modeloControllers: _actNegModeloCtrl,
            serieControllers:  _actNegSerieCtrl,
            valorControllers:  _actNegValorCtrl,
          ),
          const SizedBox(height: 16),
          _buildVehiculos(titulo: '🚗 Vehículos del Negocio',
            descCtrl: _vehNegDescCtrl, marcaCtrl: _vehNegMarcaCtrl,
            modCtrl: _vehNegModCtrl, anioCtrl: _vehNegAnioCtrl, valCtrl: _vehNegValCtrl),
          const SizedBox(height: 16),
          _buildInmuebles(titulo: '🏭 Inmuebles del Negocio',
            descCtrl: _inmNegDescCtrl, areaCtrl: _inmNegAreaCtrl,
            ubicCtrl: _inmNegUbicCtrl, valCtrl: _inmNegValCtrl),

          // ── Activos Fijos del Hogar ────────────────────────────
          const SizedBox(height: 28),
          _buildActivosFijos(
            titulo: '🏠 Activos Fijos del Hogar',
            descControllers:   _actHogDescCtrl,
            marcaControllers:  _actHogMarcaCtrl,
            modeloControllers: _actHogModeloCtrl,
            serieControllers:  _actHogSerieCtrl,
            valorControllers:  _actHogValorCtrl,
          ),
          const SizedBox(height: 16),
          _buildVehiculos(titulo: '🚙 Vehículos del Hogar',
            descCtrl: _vehHogDescCtrl, marcaCtrl: _vehHogMarcaCtrl,
            modCtrl: _vehHogModCtrl, anioCtrl: _vehHogAnioCtrl, valCtrl: _vehHogValCtrl),
          const SizedBox(height: 16),
          _buildInmuebles(titulo: '🏠 Inmuebles del Hogar',
            descCtrl: _inmHogDescCtrl, areaCtrl: _inmHogAreaCtrl,
            ubicCtrl: _inmHogUbicCtrl, valCtrl: _inmHogValCtrl),

          // ─── Gastos del negocio (desglosados) ────────────────────
          const SizedBox(height: 22),
          _seccionTitulo('(-) Gastos del Negocio (mensual)'),
          const SizedBox(height: 6),
          StatefulBuilder(builder: (ctx, setLocal) {
            final totalGN = _td(_gNegSueldosCtrl) + _td(_gNegArriendoCtrl) + _td(_gNegServBasCtrl) +
                _cuotasNegocioDeudas + _td(_gNegTransporteCtrl) + _td(_gNegMantCtrl) +
                _td(_gNegOtrosCtrl) + _td(_gNegImprevistosCtrl);
            void reb() { setLocal(() {}); setState(() {}); }
            Widget gCampo(TextEditingController c, String lbl, IconData ic) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: _campo(controller: c, label: lbl, icon: ic,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.,]'))],
                onChanged: (_) => reb(),
              ),
            );
            return Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: ConstantColors.borderLight),
              ),
              child: Column(children: [
                gCampo(_gNegSueldosCtrl,     'Sueldos (total trabajadores)', Icons.people_rounded),
                gCampo(_gNegArriendoCtrl,    'Arriendo local/bodega', Icons.store_mall_directory_rounded),
                gCampo(_gNegServBasCtrl,     'Servicios básicos (agua, luz, internet)', Icons.electrical_services_rounded),
                // Cuotas préstamos negocio: auto desde otras deudas
                Container(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  decoration: BoxDecoration(
                    color: Colors.amber.shade50,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.amber.shade400),
                  ),
                  child: Row(children: [
                    Icon(Icons.account_balance_rounded, size: 18, color: Colors.amber.shade800),
                    const SizedBox(width: 8),
                    Expanded(child: Text('Cuotas préstamos negocio',
                        style: TextStyle(color: Colors.amber.shade900, fontWeight: FontWeight.w600, fontSize: 13))),
                    Text('\$${_cuotasNegocioDeudas.toStringAsFixed(2)}',
                        style: TextStyle(color: Colors.amber.shade900, fontWeight: FontWeight.w800, fontSize: 14)),
                  ]),
                ),
                gCampo(_gNegTransporteCtrl,  'Transporte / flete', Icons.local_shipping_rounded),
                gCampo(_gNegMantCtrl,        'Mantenimiento equipos', Icons.build_rounded),
                gCampo(_gNegOtrosCtrl,       'Otros gastos del negocio', Icons.more_horiz_rounded),
                gCampo(_gNegImprevistosCtrl, 'Imprevistos', Icons.warning_amber_rounded),
                // Total
                Container(
                  margin: const EdgeInsets.only(top: 4),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: Colors.red.shade50,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.red.shade300),
                  ),
                  child: Row(children: [
                    Expanded(child: Text('TOTAL GASTOS NEGOCIO',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: Colors.red.shade800))),
                    Text('\$${totalGN.toStringAsFixed(2)}',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: Colors.red.shade800)),
                  ]),
                ),
              ]),
            );
          }),

          // ─── (+) Otros ingresos (desglosados) ────────────────────
          const SizedBox(height: 22),
          _seccionTitulo('(+) Otros Ingresos (mensual)'),
          const SizedBox(height: 6),
          StatefulBuilder(builder: (ctx, setLocal) {
            void reb() { setLocal(() {}); setState(() {}); }
            Widget oCampo(TextEditingController c, String lbl, IconData ic) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: _campo(controller: c, label: lbl, icon: ic,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.,]'))],
                onChanged: (_) => reb(),
              ),
            );
            final totalOI = _td(_oIngConyugeCtrl) + _td(_oIngArriendosCtrl) +
                _td(_oIngPensionesCtrl) + _td(_oIngOtrosCtrl);
            return Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: ConstantColors.borderLight),
              ),
              child: Column(children: [
                oCampo(_oIngConyugeCtrl,   'Cónyuge (empleo o negocio adicional)', Icons.people_alt_rounded),
                oCampo(_oIngArriendosCtrl, 'Arriendos recibidos', Icons.home_work_rounded),
                oCampo(_oIngPensionesCtrl, 'Pensiones', Icons.elderly_rounded),
                oCampo(_oIngOtrosCtrl,     'Otros ingresos', Icons.add_circle_outline_rounded),
                Container(
                  margin: const EdgeInsets.only(top: 4),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: Colors.blue.shade50,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.blue.shade300),
                  ),
                  child: Row(children: [
                    Expanded(child: Text('TOTAL OTROS INGRESOS',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: Colors.blue.shade800))),
                    Text('\$${totalOI.toStringAsFixed(2)}',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: Colors.blue.shade800)),
                  ]),
                ),
              ]),
            );
          }),

          // ─── (-) Gastos familiares (desglosados) ──────────────────
          const SizedBox(height: 22),
          _seccionTitulo('(-) Gastos Familiares (mensual)'),
          const SizedBox(height: 6),
          StatefulBuilder(builder: (ctx, setLocal) {
            void reb() { setLocal(() {}); setState(() {}); }
            Widget fCampo(TextEditingController c, String lbl, IconData ic) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: _campo(controller: c, label: lbl, icon: ic,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.,]'))],
                onChanged: (_) => reb(),
              ),
            );
            final totalGF = _td(_gFamAlimCtrl) + _td(_gFamArriendoCtrl) + _td(_gFamServBasCtrl) +
                _cuotasFamiliaresDeudas + _td(_gFamEducCtrl) + _td(_gFamSaludCtrl) +
                _td(_gFamOtrosCtrl) + _td(_gFamImprevistosCtrl);
            return Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: ConstantColors.borderLight),
              ),
              child: Column(children: [
                fCampo(_gFamAlimCtrl,        'Alimentación', Icons.restaurant_rounded),
                fCampo(_gFamArriendoCtrl,    'Arriendo familiar', Icons.home_rounded),
                fCampo(_gFamServBasCtrl,     'Servicios básicos hogar', Icons.bolt_rounded),
                // Cuotas familiares: auto desde otras deudas
                Container(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  decoration: BoxDecoration(
                    color: Colors.amber.shade50,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.amber.shade400),
                  ),
                  child: Row(children: [
                    Icon(Icons.credit_card_rounded, size: 18, color: Colors.amber.shade800),
                    const SizedBox(width: 8),
                    Expanded(child: Text('Cuotas préstamos familiares',
                        style: TextStyle(color: Colors.amber.shade900, fontWeight: FontWeight.w600, fontSize: 13))),
                    Text('\$${_cuotasFamiliaresDeudas.toStringAsFixed(2)}',
                        style: TextStyle(color: Colors.amber.shade900, fontWeight: FontWeight.w800, fontSize: 14)),
                  ]),
                ),
                fCampo(_gFamEducCtrl,        'Educación', Icons.school_rounded),
                fCampo(_gFamSaludCtrl,       'Salud', Icons.health_and_safety_rounded),
                fCampo(_gFamOtrosCtrl,       'Otros gastos familiares', Icons.more_horiz_rounded),
                fCampo(_gFamImprevistosCtrl, 'Imprevistos', Icons.warning_amber_rounded),
                Container(
                  margin: const EdgeInsets.only(top: 4),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: Colors.orange.shade50,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.orange.shade300),
                  ),
                  child: Row(children: [
                    Expanded(child: Text('TOTAL GASTOS FAMILIARES',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: Colors.orange.shade800))),
                    Text('\$${totalGF.toStringAsFixed(2)}',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: Colors.orange.shade800)),
                  ]),
                ),
              ]),
            );
          }),

          // ─── Otras Deudas (tabla) ─────────────────────────────────
          const SizedBox(height: 22),
          _buildOtrasDeudas(),

          // ── Flujo de Ingresos y Gastos (resumen) ───────────────
          const SizedBox(height: 28),
          _buildFlujoResumen(),
        ],
      ),
    );
  }

  void _cargarActivosFijos(
    dynamic raw,
    List<TextEditingController> desc,
    List<TextEditingController> marca,
    List<TextEditingController> modelo,
    List<TextEditingController> serie,
    List<TextEditingController> valor,
  ) {
    if (raw == null) return;
    try {
      final list = json.decode(raw.toString()) as List<dynamic>;
      for (int i = 0; i < list.length && i < _kActivosCount; i++) {
        final a = Map<String, dynamic>.from(list[i] as Map);
        final s = (dynamic v) => (v ?? '').toString();
        desc[i].text   = s(a['descripcion']);
        marca[i].text  = s(a['marca']);
        modelo[i].text = s(a['modelo']);
        serie[i].text  = s(a['serie']);
        valor[i].text  = s(a['valor_comercial']);
      }
    } catch (_) {}
  }

  // ─────────────────────────────────────────────────────────────
  //  VEHÍCULOS
  // ─────────────────────────────────────────────────────────────
  Widget _buildVehiculos({
    required String titulo,
    required List<TextEditingController> descCtrl,
    required List<TextEditingController> marcaCtrl,
    required List<TextEditingController> modCtrl,
    required List<TextEditingController> anioCtrl,
    required List<TextEditingController> valCtrl,
  }) {
    return StatefulBuilder(builder: (context, setLocal) {
      void rebuild() { setLocal(() {}); setState(() {}); }
      final totalVal = valCtrl.map((c) => _toDouble(c.text)).fold(0.0, (a, b) => a + b);
      return Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: ConstantColors.borderLight),
          boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 6, offset: const Offset(0, 2))],
        ),
        child: Column(children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: Colors.teal.shade50,
              borderRadius: const BorderRadius.only(topLeft: Radius.circular(16), topRight: Radius.circular(16)),
              border: Border(bottom: BorderSide(color: Colors.teal.shade200)),
            ),
            child: Row(children: [
              Icon(Icons.directions_car_rounded, color: Colors.teal.shade700, size: 20),
              const SizedBox(width: 10),
              Text(titulo, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
            ]),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
            color: ConstantColors.grey100,
            child: Row(children: [
              Expanded(flex: 4, child: _colHeader('VEHÍCULO')),
              const SizedBox(width: 4),
              Expanded(flex: 3, child: _colHeader('MARCA')),
              const SizedBox(width: 4),
              Expanded(flex: 3, child: _colHeader('MODELO')),
              const SizedBox(width: 4),
              Expanded(flex: 2, child: _colHeader('AÑO')),
              const SizedBox(width: 4),
              Expanded(flex: 3, child: _colHeader('VALOR \$', right: true)),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 8, 10, 0),
            child: Column(children: List.generate(_kVehCount, (i) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(flex: 4, child: _campoActivoTexto(descCtrl[i],  'Tipo/Desc.', rebuild)),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _campoActivoTexto(marcaCtrl[i], 'Marca', rebuild)),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _campoActivoTexto(modCtrl[i],   'Modelo', rebuild)),
                const SizedBox(width: 4),
                Expanded(flex: 2, child: _campoActivoTexto(anioCtrl[i],  'Año', rebuild)),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _campoActivoNum(valCtrl[i], '\$', rebuild)),
              ]),
            ))),
          ),
          Container(
            margin: const EdgeInsets.all(10),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
            decoration: BoxDecoration(
              color: Colors.teal.shade50,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: Colors.teal.shade300),
            ),
            child: Row(children: [
              Expanded(child: Text('TOTAL VEHÍCULOS',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5, color: Colors.teal.shade800))),
              Text('\$${totalVal.toStringAsFixed(2)}',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: Colors.teal.shade800)),
            ]),
          ),
        ]),
      );
    });
  }

  // ─────────────────────────────────────────────────────────────
  //  INMUEBLES
  // ─────────────────────────────────────────────────────────────
  Widget _buildInmuebles({
    required String titulo,
    required List<TextEditingController> descCtrl,
    required List<TextEditingController> areaCtrl,
    required List<TextEditingController> ubicCtrl,
    required List<TextEditingController> valCtrl,
  }) {
    return StatefulBuilder(builder: (context, setLocal) {
      void rebuild() { setLocal(() {}); setState(() {}); }
      final totalVal = valCtrl.map((c) => _toDouble(c.text)).fold(0.0, (a, b) => a + b);
      return Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: ConstantColors.borderLight),
          boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 6, offset: const Offset(0, 2))],
        ),
        child: Column(children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: Colors.brown.shade50,
              borderRadius: const BorderRadius.only(topLeft: Radius.circular(16), topRight: Radius.circular(16)),
              border: Border(bottom: BorderSide(color: Colors.brown.shade200)),
            ),
            child: Row(children: [
              Icon(Icons.home_work_rounded, color: Colors.brown.shade700, size: 20),
              const SizedBox(width: 10),
              Text(titulo, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
            ]),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
            color: ConstantColors.grey100,
            child: Row(children: [
              Expanded(flex: 4, child: _colHeader('INMUEBLE')),
              const SizedBox(width: 4),
              Expanded(flex: 3, child: _colHeader('ÁREA (m²)')),
              const SizedBox(width: 4),
              Expanded(flex: 4, child: _colHeader('UBICACIÓN')),
              const SizedBox(width: 4),
              Expanded(flex: 3, child: _colHeader('VALOR \$', right: true)),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 8, 10, 0),
            child: Column(children: List.generate(_kInmCount, (i) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(flex: 4, child: _campoActivoTexto(descCtrl[i], 'Tipo', rebuild)),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _campoActivoNum(areaCtrl[i], '', rebuild)),
                const SizedBox(width: 4),
                Expanded(flex: 4, child: _campoActivoTexto(ubicCtrl[i], 'Sector/Ciudad', rebuild)),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _campoActivoNum(valCtrl[i], '\$', rebuild)),
              ]),
            ))),
          ),
          Container(
            margin: const EdgeInsets.all(10),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
            decoration: BoxDecoration(
              color: Colors.brown.shade50,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: Colors.brown.shade300),
            ),
            child: Row(children: [
              Expanded(child: Text('TOTAL INMUEBLES',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5, color: Colors.brown.shade800))),
              Text('\$${totalVal.toStringAsFixed(2)}',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: Colors.brown.shade800)),
            ]),
          ),
        ]),
      );
    });
  }

  // ─────────────────────────────────────────────────────────────
  //  OTRAS DEUDAS
  // ─────────────────────────────────────────────────────────────
  Widget _buildOtrasDeudas() {
    return StatefulBuilder(builder: (context, setLocal) {
      void rebuild() { setLocal(() {}); setState(() {}); }

      final totalSaldo  = _totalSaldoDeudas;
      final totalPago   = _totalPagoMesDeudas;

      return Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: ConstantColors.borderLight),
          boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 6, offset: const Offset(0, 2))],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Encabezado
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
              decoration: BoxDecoration(
                color: Colors.red.shade50,
                borderRadius: const BorderRadius.only(topLeft: Radius.circular(16), topRight: Radius.circular(16)),
                border: Border(bottom: BorderSide(color: Colors.red.shade200)),
              ),
              child: Row(children: [
                Icon(Icons.account_balance_wallet_rounded, color: Colors.red.shade700, size: 20),
                const SizedBox(width: 10),
                Text('💳 Otras Deudas',
                    style: TextStyle(color: ConstantColors.textDark, fontWeight: FontWeight.w800, fontSize: 15)),
              ]),
            ),
            // Cabecera de columnas
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              color: ConstantColors.grey100,
              child: Row(children: [
                Expanded(flex: 4, child: _colHeader('ACREEDOR')),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _colHeader('DESTINO')),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _colHeader('MONTO INI.')),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _colHeader('SALDO ACT.')),
                const SizedBox(width: 4),
                Expanded(flex: 3, child: _colHeader('PAGO MES', right: true)),
              ]),
            ),
            // Filas
            Padding(
              padding: const EdgeInsets.fromLTRB(10, 8, 10, 0),
              child: Column(
                children: List.generate(_kDeudasCount, (i) {
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(flex: 4, child: _campoActivoTexto(_deudaAcreedorCtrl[i], 'Acreedor', rebuild)),
                        const SizedBox(width: 4),
                        Expanded(flex: 3, child: _campoActivoTexto(_deudaDestinoCtrl[i], 'Destino', rebuild)),
                        const SizedBox(width: 4),
                        Expanded(flex: 3, child: _campoActivoNum(_deudaMontoIniCtrl[i], '\$', rebuild)),
                        const SizedBox(width: 4),
                        Expanded(flex: 3, child: _campoActivoNum(_deudaSaldoActCtrl[i], '\$', rebuild)),
                        const SizedBox(width: 4),
                        Expanded(flex: 3, child: _campoActivoNum(_deudaPagoMesCtrl[i], '\$', rebuild)),
                      ],
                    ),
                  );
                }),
              ),
            ),
            // Totales
            Container(
              margin: const EdgeInsets.all(10),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: Colors.amber.shade50,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.amber.shade400),
              ),
              child: Row(children: [
                Expanded(child: Text('TOTALES',
                    style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: Colors.amber.shade900))),
                const SizedBox(width: 8),
                Text('Saldo: \$${totalSaldo.toStringAsFixed(2)}',
                    style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5, color: Colors.red.shade700)),
                const SizedBox(width: 12),
                Text('Pago/mes: \$${totalPago.toStringAsFixed(2)}',
                    style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: Colors.red.shade800)),
              ]),
            ),
          ],
        ),
      );
    });
  }

  // ─────────────────────────────────────────────────────────────
  //  FLUJO DE INGRESOS Y GASTOS (resumen visual)
  // ─────────────────────────────────────────────────────────────
  Widget _buildFlujoResumen() {
    // ── BASE: comportamiento semanal × 4 = mensual ──────────────
    // Esta es la fuente principal de ventas y compras (lo que el quiz pregunta)
    final ventasSemana =
        _toDouble(_ventaLunCtrl.text) + _toDouble(_ventaMarCtrl.text) +
        _toDouble(_ventaMieCtrl.text) + _toDouble(_ventaJueCtrl.text) +
        _toDouble(_ventaVieCtrl.text) + _toDouble(_ventaSabCtrl.text) +
        _toDouble(_ventaDomCtrl.text);
    final compraSemana =
        _toDouble(_compraLunCtrl.text) + _toDouble(_compraMarCtrl.text) +
        _toDouble(_compraMieCtrl.text) + _toDouble(_compraJueCtrl.text) +
        _toDouble(_compraVieCtrl.text) + _toDouble(_compraSabCtrl.text) +
        _toDouble(_compraDomCtrl.text);

    final ventasMensuales = ventasSemana * 4;
    final comprasMensuales = compraSemana * 4;

    // ── Ventas desde productos (para desglose, si están llenos) ──
    final ventasProd = _tipoServProduccion
        ? List.generate(_kProdCount, (i) =>
            _toDouble(_prodPrecioCtrl[i].text) * _toDouble(_prodUnidadesVendCtrl[i].text))
            .fold(0.0, (a, b) => a + b)
        : 0.0;
    final ventasCom = _tipoComercio
        ? List.generate(_kComProdCount, (i) =>
            _toDouble(_comPrecioCtrl[i].text) * _toDouble(_comCantidadCtrl[i].text))
            .fold(0.0, (a, b) => a + b)
        : 0.0;

    // Ventas totales: prioridad al comportamiento diario × 4
    // (si hay productos los mostramos como desglose pero no overrideamos)
    final ventasTotal = ventasMensuales > 0 ? ventasMensuales : (ventasProd + ventasCom);

    final ventasEfectivo      = ventasTotal * (_pctEfectivo / 100);
    final ventasTransferencia = ventasTotal * ((100 - _pctEfectivo) / 100);
    final ventasContado       = ventasTotal * (_pctContado / 100);

    // ── Costos de ventas = compras mensuales (comportamiento × 4) ──
    // Para producción: costo unitario = costoTotal / unidadesProducidas (no multiplicado)
    final costosVentasProdDetalle = _tipoServProduccion
        ? List.generate(_kProdCount, (i) {
            final mat = _prodMatCtrl[i].map((c) => _toDouble(c.text)).fold(0.0, (a, b) => a + b);
            final ct  = mat + _toDouble(_prodManoCtrl[i].text) +
                        _toDouble(_prodEmpaqueCtrl[i].text) + _toDouble(_prodOtrosCtrl[i].text);
            final up  = _toDouble(_prodUnidadesProdCtrl[i].text);
            // costo unitario = costo total del lote / unidades producidas
            final cu  = up > 0 ? ct / up : ct;
            return cu * _toDouble(_prodUnidadesVendCtrl[i].text);
          }).fold(0.0, (a, b) => a + b)
        : 0.0;
    final costosVentasComDetalle = _tipoComercio
        ? List.generate(_kComProdCount, (i) =>
            _toDouble(_comCantidadCtrl[i].text) * _toDouble(_comCostoCtrl[i].text))
            .fold(0.0, (a, b) => a + b)
        : 0.0;

    // Costos: usar compras × 4 como base; si hay productos con datos, usar esos
    final costosProductos = costosVentasProdDetalle + costosVentasComDetalle;
    final costosVentas = comprasMensuales > 0
        ? comprasMensuales
        : (costosProductos > 0 ? costosProductos : 0.0);

    final utilidadBruta = ventasContado - costosVentas;

    final gastoNegTotal = _td(_gNegSueldosCtrl) + _td(_gNegArriendoCtrl) + _td(_gNegServBasCtrl) +
        _cuotasNegocioDeudas + _td(_gNegTransporteCtrl) + _td(_gNegMantCtrl) +
        _td(_gNegOtrosCtrl) + _td(_gNegImprevistosCtrl);

    final ingresosNetosNegocio = utilidadBruta - gastoNegTotal;

    final otrosIngresos = _td(_oIngConyugeCtrl) + _td(_oIngArriendosCtrl) +
        _td(_oIngPensionesCtrl) + _td(_oIngOtrosCtrl);

    final gastoFamTotal = _td(_gFamAlimCtrl) + _td(_gFamArriendoCtrl) + _td(_gFamServBasCtrl) +
        _cuotasFamiliaresDeudas + _td(_gFamEducCtrl) + _td(_gFamSaludCtrl) +
        _td(_gFamOtrosCtrl) + _td(_gFamImprevistosCtrl);

    final saldoDisponible = ingresosNetosNegocio + otrosIngresos - gastoFamTotal;
    final colorSaldo = saldoDisponible >= 0 ? Colors.green.shade700 : Colors.red.shade700;

    Widget fila(String label, double valor, {bool bold = false, Color? color, bool separador = false}) {
      final c = color ?? (bold ? ConstantColors.textDark : ConstantColors.textDarkGrey);
      return Column(children: [
        if (separador) Divider(height: 12, color: ConstantColors.borderLight),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            Expanded(child: Text(label,
                style: TextStyle(fontSize: 13, fontWeight: bold ? FontWeight.w800 : FontWeight.w500, color: c))),
            Text('\$${valor.toStringAsFixed(2)}',
                style: TextStyle(fontSize: 13.5, fontWeight: bold ? FontWeight.w800 : FontWeight.w600, color: c)),
          ]),
        ),
      ]);
    }

    Widget subFila(String label, double valor, {Color? color}) {
      return Padding(
        padding: const EdgeInsets.only(left: 16, bottom: 3),
        child: Row(children: [
          Icon(Icons.arrow_right_rounded, size: 16, color: color ?? ConstantColors.textDarkGrey),
          const SizedBox(width: 4),
          Expanded(child: Text(label,
              style: TextStyle(fontSize: 12, color: color ?? ConstantColors.textDarkGrey))),
          Text('\$${valor.toStringAsFixed(2)}',
              style: TextStyle(fontSize: 12, color: color ?? ConstantColors.textDarkGrey,
                  fontWeight: FontWeight.w600)),
        ]),
      );
    }

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderLight),
        boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 8, offset: const Offset(0, 3))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
            decoration: BoxDecoration(
              color: const Color(0xFF1E3A5F),
              borderRadius: const BorderRadius.only(topLeft: Radius.circular(16), topRight: Radius.circular(16)),
            ),
            child: Row(children: [
              const Icon(Icons.bar_chart_rounded, color: Colors.white, size: 20),
              const SizedBox(width: 10),
              const Text('FLUJO DE INGRESOS Y GASTOS',
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 14,
                      letterSpacing: 0.5)),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Ventas
                fila('VENTAS DE CONTADO (${_pctContado}%)', ventasContado, bold: true),
                if (_tipoServProduccion || _tipoComercio) ...[
                  if (_tipoServProduccion) subFila('Empresa Producción', ventasProd),
                  if (_tipoComercio) subFila('Empresa Comercio', ventasCom),
                ] else ...[
                  subFila('Ventas mensuales (sem×4)', ventasMensuales),
                ],
                subFila('  💵 Efectivo ($_pctEfectivo%)', ventasEfectivo, color: Colors.green.shade700),
                subFila('  💳 Tarjeta/Transf. (${100-_pctEfectivo}%)', ventasTransferencia, color: Colors.blue.shade600),
                fila('(-) COSTOS DE VENTAS', costosVentas),
                fila('(=) UTILIDAD BRUTA', utilidadBruta, bold: true,
                    color: utilidadBruta >= 0 ? Colors.green.shade800 : Colors.red.shade700,
                    separador: true),

                const SizedBox(height: 4),
                fila('(-) GASTOS DEL NEGOCIO', gastoNegTotal),
                subFila('Sueldos', _td(_gNegSueldosCtrl)),
                subFila('Arriendo', _td(_gNegArriendoCtrl)),
                subFila('Servicios básicos', _td(_gNegServBasCtrl)),
                subFila('Cuotas préstamos negocio', _cuotasNegocioDeudas,
                    color: Colors.amber.shade800),
                subFila('Transporte', _td(_gNegTransporteCtrl)),
                subFila('Mantenimiento', _td(_gNegMantCtrl)),
                subFila('Otros gastos negocio', _td(_gNegOtrosCtrl)),
                subFila('Imprevistos', _td(_gNegImprevistosCtrl)),

                fila('(=) INGRESOS NETOS DEL NEGOCIO', ingresosNetosNegocio, bold: true,
                    color: ingresosNetosNegocio >= 0 ? Colors.green.shade800 : Colors.red.shade700,
                    separador: true),

                const SizedBox(height: 4),
                fila('(+) OTROS INGRESOS', otrosIngresos),
                subFila('Cónyuge', _td(_oIngConyugeCtrl)),
                subFila('Arriendos', _td(_oIngArriendosCtrl)),
                subFila('Pensiones', _td(_oIngPensionesCtrl)),
                subFila('Otros', _td(_oIngOtrosCtrl)),

                const SizedBox(height: 4),
                fila('(-) GASTOS FAMILIARES', gastoFamTotal, separador: true),
                subFila('Alimentación', _td(_gFamAlimCtrl)),
                subFila('Arriendo familiar', _td(_gFamArriendoCtrl)),
                subFila('Servicios básicos', _td(_gFamServBasCtrl)),
                subFila('Cuotas préstamos familiares', _cuotasFamiliaresDeudas,
                    color: Colors.amber.shade800),
                subFila('Educación', _td(_gFamEducCtrl)),
                subFila('Salud', _td(_gFamSaludCtrl)),
                subFila('Otros gastos familiares', _td(_gFamOtrosCtrl)),
                subFila('Imprevistos', _td(_gFamImprevistosCtrl)),

                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  decoration: BoxDecoration(
                    color: saldoDisponible >= 0 ? Colors.green.shade50 : Colors.red.shade50,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: saldoDisponible >= 0 ? Colors.green.shade400 : Colors.red.shade400),
                  ),
                  child: Row(children: [
                    Icon(saldoDisponible >= 0 ? Icons.check_circle_rounded : Icons.warning_rounded,
                        color: colorSaldo, size: 22),
                    const SizedBox(width: 10),
                    Expanded(child: Text('(=) SALDO DISPONIBLE',
                        style: TextStyle(fontWeight: FontWeight.w900, fontSize: 14, color: colorSaldo))),
                    Text('\$${saldoDisponible.toStringAsFixed(2)}',
                        style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16, color: colorSaldo)),
                  ]),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildActivosFijos({
    required String titulo,
    required List<TextEditingController> descControllers,
    required List<TextEditingController> marcaControllers,
    required List<TextEditingController> modeloControllers,
    required List<TextEditingController> serieControllers,
    required List<TextEditingController> valorControllers,
  }) {
    return StatefulBuilder(
      builder: (context, setLocal) {
        void rebuild() { setLocal(() {}); setState(() {}); }

        final totalValor = valorControllers.map((c) => _toDouble(c.text)).fold(0.0, (a, b) => a + b);

        return Container(
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: ConstantColors.borderLight),
            boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 6, offset: const Offset(0, 2))],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Encabezado
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
                decoration: BoxDecoration(
                  color: const Color(0xFF3B82F6).withOpacity(0.10),
                  borderRadius: const BorderRadius.only(topLeft: Radius.circular(16), topRight: Radius.circular(16)),
                  border: Border(bottom: BorderSide(color: const Color(0xFF3B82F6).withOpacity(0.3))),
                ),
                child: Row(children: [
                  Icon(Icons.account_balance_rounded, color: const Color(0xFF3B82F6), size: 20),
                  const SizedBox(width: 10),
                  Text(titulo,
                      style: TextStyle(color: ConstantColors.textDark, fontWeight: FontWeight.w800, fontSize: 15)),
                ]),
              ),

              // Cabecera de columnas
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                color: ConstantColors.grey100,
                child: Row(children: [
                  Expanded(flex: 4, child: _colHeader('DESCRIPCIÓN')),
                  const SizedBox(width: 6),
                  Expanded(flex: 3, child: _colHeader('MARCA')),
                  const SizedBox(width: 6),
                  Expanded(flex: 3, child: _colHeader('MODELO')),
                  const SizedBox(width: 6),
                  Expanded(flex: 3, child: _colHeader('SERIE')),
                  const SizedBox(width: 6),
                  Expanded(flex: 3, child: _colHeader('VALOR \$', right: true)),
                ]),
              ),

              // Filas de activos
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                child: Column(
                  children: List.generate(_kActivosCount, (i) {
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(flex: 4, child: _campoActivoTexto(descControllers[i],   'Descripción', rebuild)),
                          const SizedBox(width: 6),
                          Expanded(flex: 3, child: _campoActivoTexto(marcaControllers[i],  'Marca',       rebuild)),
                          const SizedBox(width: 6),
                          Expanded(flex: 3, child: _campoActivoTexto(modeloControllers[i], 'Modelo',      rebuild)),
                          const SizedBox(width: 6),
                          Expanded(flex: 3, child: _campoActivoTexto(serieControllers[i],  'Serie',       rebuild)),
                          const SizedBox(width: 6),
                          Expanded(flex: 3, child: _campoActivoValor(valorControllers[i],  rebuild)),
                        ],
                      ),
                    );
                  }),
                ),
              ),

              // Fila TOTAL
              Container(
                margin: const EdgeInsets.fromLTRB(12, 4, 12, 14),
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  color: ConstantColors.warning.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: ConstantColors.warning.withOpacity(0.5), width: 1.5),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('VALOR TOTAL',
                        style: TextStyle(color: ConstantColors.textDark, fontWeight: FontWeight.w800, fontSize: 14)),
                    Text('\$${totalValor.toStringAsFixed(2)}',
                        style: TextStyle(color: ConstantColors.warning, fontWeight: FontWeight.w900, fontSize: 16)),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _colHeader(String txt, {bool right = false}) {
    return Text(txt,
        textAlign: right ? TextAlign.right : TextAlign.left,
        style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: ConstantColors.textDarkGrey, letterSpacing: 0.3));
  }

  Widget _campoActivoTexto(TextEditingController ctrl, String hint, VoidCallback onChanged) {
    return TextFormField(
      controller: ctrl,
      onChanged: (_) => onChanged(),
      style: TextStyle(color: ConstantColors.textDark, fontSize: 12),
      decoration: InputDecoration(
        hintText: hint,
        hintStyle: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey.withOpacity(0.6)),
        filled: true,
        fillColor: ConstantColors.grey100,
        isDense: true,
        border:        OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: ConstantColors.borderLight)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: ConstantColors.borderLight)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: const Color(0xFF3B82F6), width: 1.5)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      ),
    );
  }

  Widget _campoActivoNum(TextEditingController ctrl, String prefix, VoidCallback onChanged) {
    return TextFormField(
      controller: ctrl,
      onChanged: (_) => onChanged(),
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.,]'))],
      textAlign: TextAlign.right,
      style: TextStyle(color: ConstantColors.textDark, fontSize: 12, fontWeight: FontWeight.w600),
      decoration: InputDecoration(
        prefixText: prefix,
        prefixStyle: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey),
        hintText: '0.00',
        hintStyle: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey.withOpacity(0.5)),
        filled: true,
        fillColor: Colors.amber.shade50,
        isDense: true,
        border:        OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: Colors.amber.shade300)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: Colors.amber.shade300)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: Colors.amber.shade600, width: 1.5)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      ),
    );
  }

  Widget _campoActivoValor(TextEditingController ctrl, VoidCallback onChanged) {
    return TextFormField(
      controller: ctrl,
      onChanged: (_) => onChanged(),
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.,]'))],
      textAlign: TextAlign.right,
      style: TextStyle(color: ConstantColors.textDark, fontSize: 12, fontWeight: FontWeight.w600),
      decoration: InputDecoration(
        hintText: '0.00',
        hintStyle: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey.withOpacity(0.5)),
        filled: true,
        fillColor: ConstantColors.backgroundYellowLight,
        isDense: true,
        border:        OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: ConstantColors.borderYellow)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: ConstantColors.borderYellow)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide(color: ConstantColors.warning, width: 1.5)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      ),
    );
  }

  Widget _buildResumenProductos({
    required int llenos,
    required double totalCostoCompras,
    required double totalVentasMes,
    required double totalInventario,
    required int minimo,
  }) {
    final completo = llenos >= minimo;
    return Container(
      margin: const EdgeInsets.only(top: 4, bottom: 8),
      decoration: BoxDecoration(
        color: completo ? ConstantColors.success.withOpacity(0.07) : ConstantColors.error.withOpacity(0.06),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: completo ? ConstantColors.success.withOpacity(0.4) : ConstantColors.error.withOpacity(0.35),
          width: 1.5,
        ),
      ),
      child: Column(
        children: [
          // Barra de estado de productos llenados
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            decoration: BoxDecoration(
              color: completo ? ConstantColors.success.withOpacity(0.12) : ConstantColors.error.withOpacity(0.09),
              borderRadius: const BorderRadius.only(topLeft: Radius.circular(15), topRight: Radius.circular(15)),
            ),
            child: Row(
              children: [
                Icon(
                  completo ? Icons.check_circle_rounded : Icons.warning_rounded,
                  color: completo ? ConstantColors.success : ConstantColors.error,
                  size: 20,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    completo
                        ? 'Todos los productos completados ($llenos/$minimo)'
                        : 'Faltan productos por completar ($llenos/$minimo)',
                    style: TextStyle(
                      color: completo ? ConstantColors.success : ConstantColors.error,
                      fontWeight: FontWeight.w700,
                      fontSize: 13,
                    ),
                  ),
                ),
              ],
            ),
          ),
          // Totales
          Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              children: [
                Row(children: [
                  Expanded(child: _totalItem('Costo total compras', '\$${totalCostoCompras.toStringAsFixed(2)}', ConstantColors.warning)),
                  const SizedBox(width: 10),
                  Expanded(child: _totalItem('Ventas al mes',        '\$${totalVentasMes.toStringAsFixed(2)}',    ConstantColors.success)),
                ]),
                const SizedBox(height: 10),
                _totalItem('Inventario total', '\$${totalInventario.toStringAsFixed(2)}', const Color(0xFF3B82F6)),
              ],
            ),
          ),
          if (!completo)
            Padding(
              padding: const EdgeInsets.only(left: 16, right: 16, bottom: 14),
              child: Row(
                children: [
                  Icon(Icons.info_outline_rounded, color: ConstantColors.error, size: 16),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      'Complete los $minimo productos para poder continuar',
                      style: TextStyle(color: ConstantColors.error, fontSize: 12),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _totalItem(String label, String valor, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: color.withOpacity(0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withOpacity(0.25)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey)),
          const SizedBox(height: 3),
          Text(valor, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: color)),
        ],
      ),
    );
  }

  Widget _seccionTituloDestacado(String titulo, String subtitulo) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: ConstantColors.warning.withOpacity(0.10),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: ConstantColors.warning.withOpacity(0.4)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(titulo, style: TextStyle(color: ConstantColors.textDark, fontSize: 15, fontWeight: FontWeight.w800)),
                Text(subtitulo, style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 12)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildComercioProductoCard(int idx) {
    final n = idx + 1;
    final nombre    = _comNombreCtrl[idx];
    final costo     = _comCostoCtrl[idx];
    final precio    = _comPrecioCtrl[idx];
    final tipoUnid  = _comTipoUnidadCtrl[idx];
    final cantidad  = _comCantidadCtrl[idx];
    final unidExist = _comUnidExistCtrl[idx];

    double _d(String s) => _toDouble(s);
    double costoUnitVal()    => _d(costo.text);
    double precioVentaVal()  => _d(precio.text);
    double cantidadVal()     => _d(cantidad.text);
    double unidExistVal()    => _d(unidExist.text);
    // margen = costo / precio de unidad
    double margenUtil() {
      final p = precioVentaVal();
      if (p <= 0) return 0;
      return (costoUnitVal() / p) * 100;
    }
    double costoTotalCompra() => costoUnitVal() * cantidadVal();
    double ventaMes()         => precioVentaVal() * cantidadVal();
    double inventario()       => unidExistVal() * costoUnitVal();

    return StatefulBuilder(
      builder: (context, setLocal) {
        void rebuild() {
          setLocal(() {});
          setState(() {});
        }

        return Container(
          margin: const EdgeInsets.only(bottom: 16),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: ConstantColors.borderLight),
            boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 6, offset: const Offset(0, 2))],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Encabezado
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: BoxDecoration(
                  color: const Color(0xFF3B82F6).withOpacity(0.10),
                  borderRadius: const BorderRadius.only(topLeft: Radius.circular(16), topRight: Radius.circular(16)),
                  border: Border(bottom: BorderSide(color: const Color(0xFF3B82F6).withOpacity(0.3))),
                ),
                child: Row(
                  children: [
                    Icon(Icons.shopping_cart_rounded, color: const Color(0xFF3B82F6), size: 20),
                    const SizedBox(width: 8),
                    Text('Producto $n', style: TextStyle(color: ConstantColors.textDark, fontWeight: FontWeight.w800, fontSize: 15)),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Nombre
                    _campo(controller: nombre, label: 'Nombre del producto', icon: Icons.label_rounded),
                    const SizedBox(height: 6),
                    // Costo y precio
                    Row(children: [
                      Expanded(child: _campoNumCom(costo,   'Costo por unidad',       Icons.price_change_outlined, rebuild)),
                      const SizedBox(width: 8),
                      Expanded(child: _campoNumCom(precio,  'Precio de venta x unidad', Icons.sell_rounded,          rebuild)),
                    ]),
                    const SizedBox(height: 6),
                    // Tipo unidad y cantidad vendida
                    Row(children: [
                      Expanded(child: _campo(controller: tipoUnid, label: 'Tipo de unidad', icon: Icons.straighten_rounded)),
                      const SizedBox(width: 8),
                      Expanded(child: _campoNumCom(cantidad, 'Cantidad vendida al mes', Icons.shopping_bag_rounded, rebuild)),
                    ]),
                    const SizedBox(height: 6),
                    // Unidades existentes
                    _campoNumCom(unidExist, 'Unidades existentes (inventario)', Icons.inventory_rounded, rebuild),
                    const SizedBox(height: 12),
                    // Resultados calculados — Fila 1
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: ConstantColors.grey100,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: ConstantColors.borderLight),
                      ),
                      child: Column(
                        children: [
                          Row(children: [
                            Expanded(child: _resultadoCom('Margen utilidad', '${margenUtil().toStringAsFixed(1)}%', ConstantColors.success)),
                            const SizedBox(width: 8),
                            Expanded(child: _resultadoCom('Costo total compra', '\$${costoTotalCompra().toStringAsFixed(2)}', ConstantColors.warning)),
                          ]),
                          const SizedBox(height: 8),
                          Row(children: [
                            Expanded(child: _resultadoCom('Venta mes', '\$${ventaMes().toStringAsFixed(2)}', const Color(0xFF3B82F6))),
                            const SizedBox(width: 8),
                            Expanded(child: _resultadoCom('Inventario', '\$${inventario().toStringAsFixed(2)}', ConstantColors.textDark)),
                          ]),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _campoNumCom(TextEditingController ctrl, String label, IconData icon, VoidCallback onChanged) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        controller: ctrl,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.,]'))],
        onChanged: (_) => onChanged(),
        style: TextStyle(color: ConstantColors.textDark, fontSize: 14),
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(icon, color: const Color(0xFF3B82F6), size: 20),
          filled: true,
          fillColor: ConstantColors.grey100,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: ConstantColors.borderLight)),
          enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: ConstantColors.borderLight)),
          focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: const Color(0xFF3B82F6), width: 1.5)),
          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        ),
      ),
    );
  }

  Widget _resultadoCom(String label, String valor, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: color.withOpacity(0.08),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withOpacity(0.25)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: TextStyle(fontSize: 10, color: ConstantColors.textDarkGrey)),
          const SizedBox(height: 2),
          Text(valor, style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: color)),
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
        _largeChoiceCard(
          label: 'Cuenta de Ahorros',
          value: _mantieneAhorro,
          color: const Color(0xFF10B981),
          leadingIcon: Icons.savings_rounded,
          onTap: () => setState(() => _mantieneAhorro = !_mantieneAhorro),
        ),
        // Mostrar picker/entrada de institución inmediatamente debajo
        if (_mantieneAhorro) ...[
          const SizedBox(height: 8),
          if (!_institucionesCargadas) ...[
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(children: [
                SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: ConstantColors.primaryViolet)),
                const SizedBox(width: 10),
                Text('Cargando instituciones…', style: TextStyle(fontSize: 12, color: ConstantColors.textGrey)),
              ]),
            ),
          ] else ...[
            Container(
              margin: const EdgeInsets.only(bottom: 6),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 2),
              decoration: BoxDecoration(
                color: ConstantColors.grey100,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: ConstantColors.borderLight),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  value: _instAhorroSeleccionada,
                  isExpanded: true,
                  dropdownColor: Colors.white,
                  style: TextStyle(color: ConstantColors.textDark, fontSize: 13),
                  hint: Text('Seleccionar institución (ahorro)', style: TextStyle(fontSize: 13, color: ConstantColors.textGrey)),
                  items: [
                    ..._instituciones.map((nombre) => DropdownMenuItem<String>(value: nombre, child: Text(nombre, style: TextStyle(color: ConstantColors.textDark)))),
                    DropdownMenuItem<String>(value: 'otra', child: Text('Otra', style: TextStyle(color: ConstantColors.textDark))),
                  ],
                  onChanged: (v) {
                    setState(() {
                      _instAhorroSeleccionada = v;
                      if (v == null) {
                        _bancoAhorroCtrl.text = '';
                      } else if (v == 'otra') {
                        _bancoAhorroCtrl.text = '';
                      } else {
                        _bancoAhorroCtrl.text = v;
                      }
                    });
                  },
                ),
              ),
            ),
            if (_instAhorroSeleccionada == 'otra' || _instituciones.isEmpty) ...[
              _campo(controller: _bancoAhorroCtrl, label: 'Institución (ahorro)', icon: Icons.account_balance_rounded),
            ],
          ],
        ],

        _largeChoiceCard(
          label: 'Cuenta Corriente',
          value: _mantieneCorriente,
          color: const Color(0xFF3B82F6),
          leadingIcon: Icons.account_balance_rounded,
          onTap: () => setState(() => _mantieneCorriente = !_mantieneCorriente),
        ),
        // Mostrar picker/entrada de institución inmediatamente debajo
        if (_mantieneCorriente) ...[
          const SizedBox(height: 8),
          if (!_institucionesCargadas) ...[
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(children: [
                SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: ConstantColors.primaryViolet)),
                const SizedBox(width: 10),
                Text('Cargando instituciones…', style: TextStyle(fontSize: 12, color: ConstantColors.textGrey)),
              ]),
            ),
          ] else ...[
            Container(
              margin: const EdgeInsets.only(bottom: 6),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 2),
              decoration: BoxDecoration(
                color: ConstantColors.grey100,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: ConstantColors.borderLight),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  value: _instCorrSeleccionada,
                  isExpanded: true,
                  dropdownColor: Colors.white,
                  style: TextStyle(color: ConstantColors.textDark, fontSize: 13),
                  hint: Text('Seleccionar institución (corriente)', style: TextStyle(fontSize: 13, color: ConstantColors.textGrey)),
                  items: [
                    ..._instituciones.map((nombre) => DropdownMenuItem<String>(value: nombre, child: Text(nombre, style: TextStyle(color: ConstantColors.textDark)))),
                    DropdownMenuItem<String>(value: 'otra', child: Text('Otra', style: TextStyle(color: ConstantColors.textDark))),
                  ],
                  onChanged: (v) {
                    setState(() {
                      _instCorrSeleccionada = v;
                      if (v == null) {
                        _bancoCorrienteCtrl.text = '';
                      } else if (v == 'otra') {
                        _bancoCorrienteCtrl.text = '';
                      } else {
                        _bancoCorrienteCtrl.text = v;
                      }
                    });
                  },
                ),
              ),
            ),
            if (_instCorrSeleccionada == 'otra' || _instituciones.isEmpty) ...[
              _campo(controller: _bancoCorrienteCtrl, label: 'Institución (corriente)', icon: Icons.account_balance_rounded),
            ],
          ],
        ],


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
          const SizedBox(height: 12),
          _seccionTitulo('¿Le interesaría que le hagamos una propuesta previo al vencimiento?'),
          _preguntaSiNo(
            value: _propuestaPrevVenc,
            onChanged: (v) => setState(() => _propuestaPrevVenc = v),
          ),
          if (_propuestaPrevVenc == true) ...[
            const SizedBox(height: 8),
            // Fecha y hora para crear la tarea/propuesta
            _fieldFecha(
              label: 'Fecha de la propuesta (tarea)',
              fecha: _fechaAcuerdo,
              onPick: () => _seleccionarFecha((d) => setState(() {
                _fechaAcuerdo = d;
                // al elegir fecha asumimos que se quiere agendar una cita en campo
                _acuerdo = 'nueva_cita_campo';
              })),
            ),
            const SizedBox(height: 8),
            _fieldHora(),
            const SizedBox(height: 8),
            _campo(
              controller: _propuestaInvCtrl,
              label: 'Propuesta de inversión (resumen)',
              icon: Icons.note_alt_rounded,
              maxLines: 3,
            ),
          ],
        ],

        // Mostrar interés en productos/servicios (movido al final del paso)
        _seccionTitulo('¿Le interesaría conocer nuestros\nproductos o servicios?'),
        _preguntaSiNo(
          value: _interesConocer,
          onChanged: (v) {
            if (v == false && (_fichaCC || _fichaAhorro || _fichaInv || _fichaCred)) {
              _mostrarError('No puedes desmarcar el interés porque ya completaste una ficha de producto.');
              setState(() => _interesConocer = true);
              return;
            }
            setState(() {
              _interesConocer = v;
              if (v == false) {
                _interesCC = _interesAhorro = _interesInv = _interesCred = false;
              } else {
                _razonYaTrabaja = _razonDesconfia = _razonAGusto = _razonMalaExp = false;
                _razonOtrosCtrl.clear();
              }
            });
          },
        ),
        if (_interesConocer == true) ...[
          const SizedBox(height: 16),
          _seccionTitulo('¿Cuáles productos le interesan?'),
          _productoItem(
            label: 'Cuenta Corriente',
            icono: Icons.account_balance_rounded,
            color: const Color(0xFF3B82F6),
            value: _fichaCC ? true : _interesCC,
            fichaLlena: _fichaCC,
            onChanged: (v) {
              if (_fichaCC) return;
              setState(() => _interesCC = v ?? false);
            },
            onLlenarFicha: () => _abrirFichaProducto(ProductoTipo.cuentaCorriente),
          ),
          _productoItem(
            label: 'Cuenta de Ahorros',
            icono: Icons.savings_rounded,
            color: const Color(0xFF10B981),
            value: _fichaAhorro ? true : _interesAhorro,
            fichaLlena: _fichaAhorro,
            onChanged: (v) {
              if (_fichaAhorro) return;
              setState(() => _interesAhorro = v ?? false);
            },
            onLlenarFicha: () => _abrirFichaProducto(ProductoTipo.cuentaAhorros),
          ),
          _productoItem(
            label: 'Inversiones',
            icono: Icons.trending_up_rounded,
            color: const Color(0xFF8B5CF6),
            value: _fichaInv ? true : _interesInv,
            fichaLlena: _fichaInv,
            onChanged: (v) {
              if (_fichaInv) return;
              setState(() => _interesInv = v ?? false);
            },
            onLlenarFicha: () => _abrirFichaProducto(ProductoTipo.inversiones),
          ),
          _productoItem(
            label: 'Crédito',
            icono: Icons.credit_score_rounded,
            color: const Color(0xFFF59E0B),
            value: _fichaCred ? true : _interesCred,
            fichaLlena: _fichaCred,
            onChanged: (v) {
              if (_fichaCred) return;
              setState(() => _interesCred = v ?? false);
            },
            onLlenarFicha: () => _abrirFichaProducto(ProductoTipo.credito),
          ),
        ] else ...[
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

        ],
        const SizedBox(height: 20),
      ],
    );
  }
  // ── PASO 4: Búsqueda y acuerdo ───────────────────────────────

  Widget _buildPasoBusqueda() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Pregunta de interés eliminada por petición del usuario.
        const SizedBox(height: 8),
        // Espacio antes de la siguiente sección.
        const SizedBox(height: 12),
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
        const SizedBox(height: 12),
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
          label: widget.modoEdicion ? 'Guardar cambios' : 'Finalizar y Guardar',
          sublabel: widget.modoEdicion
              ? 'Se actualizarán todos los datos de la encuesta'
              : 'Se guardarán todos los datos de la encuesta',
          onTap: () => _guardarEncuesta(fueEncuestado: true),
        ),
      ],
    );
  }

  // ── Botones de navegación ────────────────────────────────────

  Widget? _buildBotonesNavegacion() {
    if (!_shouldShowNavButtons) return null;

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
    if (_paso == _Paso.empresaNegocio) {
      // La empresa es completamente opcional. Solo validamos el formulario base
      // (campos requeridos que tenga _formKeyNegocio). Tipo y productos son opcionales:
      // se completan después en "Levantar Empresa" si aplica.
      if (!(_formKeyNegocio.currentState?.validate() ?? false)) return;
    }
    _irSiguientePaso();
  }

  // ── Navegación a ficha de producto ───────────────────────────

  Future<void> _abrirFichaProducto(ProductoTipo tipo) async {
    final cedula = _cedulaCtrl.text.trim();
    final nombre = '${_nombreCtrl.text.trim()} ${_apellidosCtrl.text.trim()}'.trim();
    final result = await Navigator.push<bool>(
      context,
      MaterialPageRoute(
        builder: (_) => EncuestaProductoScreen(
          tipo: tipo,
          clienteCedula: cedula,
          clienteNombre: nombre,
        ),
      ),
    );
    if (result == true && mounted) {
      setState(() {
        switch (tipo) {
          case ProductoTipo.cuentaCorriente:
            _fichaCC = true;
            _interesCC = true;
            break;
          case ProductoTipo.cuentaAhorros:
            _fichaAhorro = true;
            _interesAhorro = true;
            break;
          case ProductoTipo.inversiones:
            _fichaInv = true;
            _interesInv = true;
            break;
          case ProductoTipo.credito:
            _fichaCred = true;
            _interesCred = true;
            break;
        }
      });
    }
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
    bool readOnly = false,
    void Function(String)? onChanged,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        controller: controller,
        keyboardType: keyboardType,
        inputFormatters: inputFormatters,
        maxLines: maxLines,
        validator: validator,
        readOnly: readOnly,
        onChanged: onChanged,
        enableInteractiveSelection: !readOnly,
        style: TextStyle(
          color: readOnly ? ConstantColors.textDarkGrey : ConstantColors.textDark,
          fontSize: 14,
        ),
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(
            readOnly ? Icons.lock_rounded : icon,
            color: readOnly ? ConstantColors.textDarkGrey : ConstantColors.warning,
            size: 20,
          ),
          suffixIcon: readOnly
              ? Icon(Icons.block_rounded,
                  color: ConstantColors.textDarkGrey, size: 18)
              : null,
          filled: true,
          fillColor: readOnly
              ? ConstantColors.grey100.withOpacity(0.5)
              : ConstantColors.grey100,
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

  Widget _diaCampo(String diaLabel, TextEditingController controller, IconData icon) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          SizedBox(width: 100, child: Text(diaLabel, style: TextStyle(fontWeight: FontWeight.w700, color: ConstantColors.textDark))),
          const SizedBox(width: 10),
          Expanded(
            child: TextFormField(
              controller: controller,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.,]'))],
              style: TextStyle(color: ConstantColors.textDark),
              decoration: InputDecoration(
                hintText: '0.00',
                hintStyle: TextStyle(color: ConstantColors.textDarkGrey.withOpacity(0.6)),
                prefixIcon: Icon(icon, color: ConstantColors.warning, size: 20),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                filled: true,
                fillColor: ConstantColors.grey100,
              ),
              cursorColor: ConstantColors.textDark,
              validator: (v) {
                if (!_tieneEmpresa) return null;
                // Campos por día pueden dejarse vacíos; validar en conjunto si es necesario
                return null;
              },
            ),
          ),
        ],
      ),
    );
  }

  // ── Producto (producción) ─────────────────────────────────
  Widget _buildProductoCard(int idx) {
    final n          = idx + 1;
    final nombre     = _prodNameCtrl[idx];
    final matsNom    = _prodMatNomCtrl[idx];  // nombres de materias 1-7
    final mats       = _prodMatCtrl[idx];     // valores/costos de materias 1-7
    final mano       = _prodManoCtrl[idx];    // total mano de obra
    final empaque    = _prodEmpaqueCtrl[idx]; // empaques
    final otros      = _prodOtrosCtrl[idx];   // otros costos indirectos
    final unidProd   = _prodUnidadesProdCtrl[idx]; // (2) unidades producidas
    final precioB    = _prodPrecioCtrl[idx];       // B. precio unitario
    final unidVendC  = _prodUnidadesVendCtrl[idx]; // C. unidades vendidas mes
    final unidVerifD = _prodUnidExistCtrl[idx];    // D. unidades verificadas

    // ── Fórmulas (igual que Excel) ─────────────────────────────
    double totalMateria()  => mats.map((c) => _toDouble(c.text)).fold(0.0, (a, b) => a + b);
    double costoTotal()    => totalMateria() + _toDouble(mano.text) + _toDouble(empaque.text) + _toDouble(otros.text);
    double costoUnitario() => _toDouble(unidProd.text) * costoTotal(); // A = (2) × (1)
    double ventasMensuales()  => _toDouble(precioB.text) * _toDouble(unidVendC.text);   // B × C
    double costoDeVentas()    => costoUnitario() * _toDouble(unidVendC.text);            // A × C
    double inventarios()      => costoUnitario() * _toDouble(unidVerifD.text);           // A × D

    return StatefulBuilder(
      builder: (context, setLocal) {
        void rebuild() { setLocal(() {}); setState(() {}); }

        return Container(
          margin: const EdgeInsets.only(bottom: 20),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: ConstantColors.borderLight),
            boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.06), blurRadius: 8, offset: const Offset(0, 2))],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ── Encabezado ──────────────────────────────────────
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: BoxDecoration(
                  color: ConstantColors.warning.withOpacity(0.12),
                  borderRadius: const BorderRadius.only(topLeft: Radius.circular(16), topRight: Radius.circular(16)),
                  border: Border(bottom: BorderSide(color: ConstantColors.warning.withOpacity(0.35))),
                ),
                child: Row(children: [
                  Icon(Icons.precision_manufacturing_rounded, color: ConstantColors.warning, size: 22),
                  const SizedBox(width: 10),
                  Text('PRODUCTO $n',
                      style: TextStyle(color: ConstantColors.textDark, fontWeight: FontWeight.w900, fontSize: 15, letterSpacing: 0.5)),
                ]),
              ),

              Padding(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Nombre
                    _campo(controller: nombre, label: 'Nombre del producto', icon: Icons.inventory_2_rounded),

                    // ── Materias primas 1-7 (nombre + valor) ─────
                    _subTituloProd('Materias primas', Icons.grain_rounded),
                    const SizedBox(height: 6),
                    for (int m = 0; m < _kMatCount; m++) ...[
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // Número de materia
                          Container(
                            margin: const EdgeInsets.only(top: 14, right: 8),
                            width: 22,
                            height: 22,
                            decoration: BoxDecoration(
                              color: ConstantColors.warning.withOpacity(0.15),
                              shape: BoxShape.circle,
                              border: Border.all(color: ConstantColors.warning.withOpacity(0.5)),
                            ),
                            child: Center(
                              child: Text('${m + 1}',
                                  style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: ConstantColors.warning)),
                            ),
                          ),
                          // Nombre de la materia
                          Expanded(
                            flex: 5,
                            child: _campoTextoMat(matsNom[m], 'Nombre materia ${m + 1}', rebuild),
                          ),
                          const SizedBox(width: 8),
                          // Valor/costo
                          Expanded(
                            flex: 3,
                            child: _campoNumProd(mats[m], 'Valor \$', Icons.attach_money_rounded, rebuild),
                          ),
                        ],
                      ),
                    ],

                    // Total Materia Prima calculado
                    _filaCalculada('Total Materia Prima', totalMateria(), ConstantColors.warning.withOpacity(0.85)),
                    const SizedBox(height: 10),

                    // ── Costos adicionales ───────────────────────
                    _subTituloProd('Costos adicionales', Icons.attach_money_rounded),
                    const SizedBox(height: 6),
                    _campoNumProd(mano,    'Total Mano de Obra',       Icons.handyman_rounded,         rebuild),
                    _campoNumProd(empaque, 'Empaques',                 Icons.inventory_2_rounded,       rebuild),
                    _campoNumProd(otros,   'Otros costos indirectos',  Icons.more_horiz_rounded,        rebuild),

                    // Costo Total (1)
                    _filaCalculada('Costo Total  (1)', costoTotal(), ConstantColors.warning.withOpacity(0.85)),
                    const SizedBox(height: 10),

                    // ── Producción y precio ──────────────────────
                    _subTituloProd('Producción y precio', Icons.sell_rounded),
                    const SizedBox(height: 6),
                    _campoNumProd(unidProd,  'Unidades producidas  (2)', Icons.production_quantity_limits_rounded, rebuild),

                    // A. Costo unitario = (1)/(2)
                    _filaCalculadaDestacada('A.  Costo unitario  (2×1)', costoUnitario()),
                    const SizedBox(height: 8),

                    _campoNumProd(precioB,   'B.  Precio unitario',      Icons.sell_rounded,              rebuild),
                    _campoNumProd(unidVendC, 'C.  Unidades vendidas mes', Icons.shopping_bag_rounded,      rebuild),
                    _campoNumProd(unidVerifD,'D.  Unidades verificadas',  Icons.verified_rounded,          rebuild),
                    const SizedBox(height: 10),

                    // ── Resultados finales ───────────────────────
                    _subTituloProd('Resultados', Icons.bar_chart_rounded),
                    const SizedBox(height: 8),
                    Row(children: [
                      Expanded(child: _resultadoProd('Ventas mensuales\n(B × C)',  '\$${ventasMensuales().toStringAsFixed(2)}', ConstantColors.success)),
                      const SizedBox(width: 8),
                      Expanded(child: _resultadoProd('Costo de ventas\n(A × C)',   '\$${costoDeVentas().toStringAsFixed(2)}',   ConstantColors.warning)),
                    ]),
                    const SizedBox(height: 8),
                    _resultadoProd('Inventarios  (A × D)', '\$${inventarios().toStringAsFixed(2)}', const Color(0xFF3B82F6)),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  // ── Helpers visuales para producción ────────────────────────

  Widget _subTituloProd(String texto, IconData icono) {
    return Padding(
      padding: const EdgeInsets.only(top: 4, bottom: 4),
      child: Row(children: [
        Icon(icono, size: 15, color: ConstantColors.warning),
        const SizedBox(width: 6),
        Text(texto, style: TextStyle(color: ConstantColors.textDark, fontSize: 13, fontWeight: FontWeight.w700)),
      ]),
    );
  }

  Widget _filaCalculada(String label, double valor, Color color) {
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: color.withOpacity(0.10),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withOpacity(0.45)),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: TextStyle(color: ConstantColors.textDark, fontSize: 13, fontWeight: FontWeight.w600)),
          Text('\$${valor.toStringAsFixed(2)}',
              style: TextStyle(color: color, fontSize: 15, fontWeight: FontWeight.w800)),
        ],
      ),
    );
  }

  Widget _filaCalculadaDestacada(String label, double valor) {
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: ConstantColors.error.withOpacity(0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: ConstantColors.error.withOpacity(0.5), width: 1.5),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: TextStyle(color: ConstantColors.textDark, fontSize: 13, fontWeight: FontWeight.w700)),
          Text('\$${valor.toStringAsFixed(4)}',
              style: TextStyle(color: ConstantColors.error, fontSize: 15, fontWeight: FontWeight.w800)),
        ],
      ),
    );
  }

  Widget _campoTextoMat(TextEditingController ctrl, String label, VoidCallback onChanged) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextFormField(
        controller: ctrl,
        onChanged: (_) => onChanged(),
        style: TextStyle(color: ConstantColors.textDark, fontSize: 13),
        decoration: InputDecoration(
          labelText: label,
          labelStyle: TextStyle(fontSize: 12, color: ConstantColors.textDarkGrey),
          prefixIcon: Icon(Icons.grain_rounded, color: ConstantColors.textDarkGrey, size: 17),
          filled: true,
          fillColor: ConstantColors.grey100,
          border:        OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: ConstantColors.borderLight)),
          enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: ConstantColors.borderLight)),
          focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: ConstantColors.warning, width: 1.5)),
          contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        ),
      ),
    );
  }

  Widget _campoNumProd(TextEditingController ctrl, String label, IconData icon, VoidCallback onChanged) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextFormField(
        controller: ctrl,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.,]'))],
        onChanged: (_) => onChanged(),
        style: TextStyle(color: ConstantColors.textDark, fontSize: 14),
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(icon, color: ConstantColors.warning, size: 19),
          filled: true,
          fillColor: ConstantColors.grey100,
          border:        OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: ConstantColors.borderLight)),
          enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: ConstantColors.borderLight)),
          focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: ConstantColors.warning, width: 1.5)),
          contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        ),
      ),
    );
  }

  Widget _resultadoProd(String label, String valor, Color color) {
    return Container(
      margin: const EdgeInsets.only(bottom: 2),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: color.withOpacity(0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withOpacity(0.3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: TextStyle(fontSize: 11, color: ConstantColors.textDarkGrey, height: 1.3)),
          const SizedBox(height: 4),
          Text(valor, style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: color)),
        ],
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
      ('',                   'Ninguno'),
      ('nueva_cita_campo',   'Nueva cita en campo'),
      ('nueva_cita_oficina', 'Nueva cita en oficina'),
      ('reprogramacion',     'Reprogramación'),
      ('seguimiento',        'Recolectar documentación'),
      ('otro',               'Levantamiento / Otro'),
    ];
    return DropdownButtonFormField<String>(
      value: _acuerdo,
      items: opciones
          .map((o) => DropdownMenuItem(value: o.$1, child: Text(o.$2)))
          .toList(),
      onChanged: (v) => setState(() => _acuerdo = v ?? ''),
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

  // ── RÉGIMEN TRIBUTARIO ───────────────────────────────────────
  Widget _buildRegimenTributario() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // ── Opciones principales ─────────────────────────────────
        ...[
          ('ruc',           '📋 RUC',              'Régimen general'),
          ('rise',          '🟦 RISE',             'Régimen simplificado'),
          ('no_registrado', '⬜ No está registrado', ''),
        ].map((opt) {
          final val = opt.$1;
          final title = opt.$2;
          final sub = opt.$3;
          final selected = _regimenTributario == val;
          return GestureDetector(
            onTap: () => setState(() => _regimenTributario = selected ? null : val),
            child: Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: selected
                    ? ConstantColors.warning.withOpacity(0.12)
                    : ConstantColors.grey100,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: selected
                      ? ConstantColors.warning
                      : ConstantColors.borderLight,
                  width: selected ? 1.5 : 1,
                ),
              ),
              child: Row(children: [
                Icon(
                  selected
                      ? Icons.radio_button_checked_rounded
                      : Icons.radio_button_off_rounded,
                  color: selected
                      ? ConstantColors.warning
                      : ConstantColors.textDarkGrey,
                  size: 20,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title,
                          style: TextStyle(
                              color: ConstantColors.textDark,
                              fontSize: 14,
                              fontWeight: FontWeight.w600)),
                      if (sub.isNotEmpty)
                        Text(sub,
                            style: TextStyle(
                                color: ConstantColors.textDarkGrey,
                                fontSize: 12)),
                    ],
                  ),
                ),
              ]),
            ),
          );
        }),

        // ── Sub-preguntas RUC ────────────────────────────────────
        if (_regimenTributario == 'ruc') ...[
          const SizedBox(height: 4),
          _campo(
            controller: _numeroRucCtrl,
            label: 'Número de RUC (opcional)',
            icon: Icons.badge_rounded,
            keyboardType: TextInputType.number,
          ),
          const SizedBox(height: 6),
          _subPreguntaSiNo(
            emoji: '📄',
            label: '¿Declara IVA mensualmente?',
            value: _declaraIva,
            onChanged: (v) => setState(() => _declaraIva = v),
          ),
          _subPreguntaSiNo(
            emoji: '🧾',
            label: '¿Emite facturas electrónicas?',
            value: _emiteFacturas,
            onChanged: (v) => setState(() => _emiteFacturas = v),
          ),
          _subPreguntaSiNo(
            emoji: '📊',
            label: '¿Lleva contabilidad?',
            value: _llevaContabilidad,
            onChanged: (v) => setState(() => _llevaContabilidad = v),
          ),
        ],

        // ── Sub-preguntas RISE ───────────────────────────────────
        if (_regimenTributario == 'rise') ...[
          const SizedBox(height: 6),
          _subPreguntaSiNo(
            emoji: '💳',
            label: '¿Paga su cuota mensual del RISE?',
            value: _pagaCuotaRise,
            onChanged: (v) => setState(() => _pagaCuotaRise = v),
          ),
          _subPreguntaSiNo(
            emoji: '📝',
            label: '¿Emite notas de venta?',
            value: _emiteNotasVenta,
            onChanged: (v) => setState(() => _emiteNotasVenta = v),
          ),
          _subPreguntaSiNo(
            emoji: '📈',
            label: '¿Conoce el límite de ingresos del RISE?',
            value: _conoceLimiteRise,
            onChanged: (v) => setState(() => _conoceLimiteRise = v),
          ),
        ],
      ],
    );
  }

  /// Fila Sí/No compacta para sub-preguntas tributarias
  Widget _subPreguntaSiNo({
    required String emoji,
    required String label,
    required bool? value,
    required ValueChanged<bool?> onChanged,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: ConstantColors.grey100,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: ConstantColors.borderLight),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$emoji $label',
              style: TextStyle(
                  color: ConstantColors.textDark,
                  fontSize: 13,
                  fontWeight: FontWeight.w500)),
          const SizedBox(height: 8),
          Row(children: [
            _chipSiNo(label: 'Sí', selected: value == true,
                color: Colors.green.shade600,
                onTap: () => onChanged(value == true ? null : true)),
            const SizedBox(width: 8),
            _chipSiNo(label: 'No', selected: value == false,
                color: Colors.red.shade400,
                onTap: () => onChanged(value == false ? null : false)),
            const SizedBox(width: 8),
            if (value == null)
              Text('sin respuesta',
                  style: TextStyle(
                      color: ConstantColors.textDarkGrey, fontSize: 11)),
          ]),
        ],
      ),
    );
  }

  Widget _chipSiNo({
    required String label,
    required bool selected,
    required Color color,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 7),
        decoration: BoxDecoration(
          color: selected ? color.withOpacity(0.18) : Colors.transparent,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(
              color: selected ? color : ConstantColors.borderLight,
              width: selected ? 1.5 : 1),
        ),
        child: Text(label,
            style: TextStyle(
                color: selected ? color : ConstantColors.textDarkGrey,
                fontSize: 13,
                fontWeight:
                    selected ? FontWeight.w700 : FontWeight.w400)),
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

  // ── Large selectable card used for prominent choices (cuentas) ──
  Widget _largeChoiceCard({
    required String label,
    required bool value,
    required VoidCallback onTap,
    IconData? leadingIcon,
    Color? color,
  }) {
    final bg = value ? (color ?? ConstantColors.warning).withOpacity(0.12) : Colors.transparent;
    final border = value ? (color ?? ConstantColors.warning).withOpacity(0.5) : ConstantColors.borderLight;
    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: border),
          boxShadow: value
              ? [BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 6, offset: const Offset(0, 2))]
              : null,
        ),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                color: value ? (color ?? ConstantColors.warning) : ConstantColors.grey100,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Icon(
                value ? Icons.check : (leadingIcon ?? Icons.account_balance_rounded),
                color: value ? Colors.white : ConstantColors.textDarkGrey,
                size: 20,
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  color: value ? ConstantColors.textDark : ConstantColors.textDark,
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Item de producto con checkbox + botón "Llenar ficha" ────
  Widget _productoItem({
    required String label,
    required IconData icono,
    required Color color,
    required bool value,
    required bool fichaLlena,
    required ValueChanged<bool?> onChanged,
    required VoidCallback onLlenarFicha,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: value ? color.withOpacity(0.08) : ConstantColors.grey100,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: value ? color.withOpacity(0.4) : ConstantColors.borderLight,
        ),
      ),
      child: Row(
        children: [
          GestureDetector(
            // Si la ficha ya fue completada, no se puede desmarcar
            onTap: fichaLlena && value
                ? null
                : () => onChanged(!value),
            child: Icon(
              fichaLlena && value
                  ? Icons.lock_rounded
                  : value
                      ? Icons.check_box_rounded
                      : Icons.check_box_outline_blank_rounded,
              color: fichaLlena && value
                  ? ConstantColors.success
                  : value
                      ? color
                      : ConstantColors.textDarkGrey,
              size: 22,
            ),
          ),
          const SizedBox(width: 10),
          Container(
            width: 28,
            height: 28,
            decoration: BoxDecoration(
              color: color.withOpacity(0.12),
              borderRadius: BorderRadius.circular(7),
            ),
            child: Icon(icono, color: color, size: 16),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: GestureDetector(
              onTap: fichaLlena && value
                  ? null
                  : () => onChanged(!value),
              child: Text(
                label,
                style: TextStyle(
                  color: value ? ConstantColors.textDark : ConstantColors.textDarkGrey,
                  fontSize: 13,
                  fontWeight: value ? FontWeight.w600 : FontWeight.w400,
                ),
              ),
            ),
          ),
          if (value) ...[
            const SizedBox(width: 8),
            GestureDetector(
              onTap: onLlenarFicha,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: fichaLlena
                      ? ConstantColors.success.withOpacity(0.12)
                      : color.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: fichaLlena ? ConstantColors.success : color,
                    width: 1.2,
                  ),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      fichaLlena ? Icons.check_circle_rounded : Icons.edit_note_rounded,
                      color: fichaLlena ? ConstantColors.success : color,
                      size: 14,
                    ),
                    const SizedBox(width: 4),
                    Text(
                      fichaLlena ? 'Completa' : 'Llenar ficha',
                      style: TextStyle(
                        color: fichaLlena ? ConstantColors.success : color,
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
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
                borderRadius: BorderRadius.circular(12),
              ),
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
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                ),
              )
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
         