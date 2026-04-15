import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Models/CategoriaModel.dart';
import 'package:super_ia/Core/Models/NearbyDriverMapModel.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/Core/Preferences/EmergencyContactsService.dart';
import 'package:super_ia/Core/Preferences/FavoritePlacesService.dart';
import 'package:super_ia/Core/Preferences/RecentPlacesService.dart';
import 'package:super_ia/Core/Services/OsmService.dart';
import 'package:super_ia/Core/Services/SOSService.dart';
import 'package:super_ia/Core/Services/ShareTripService.dart';
import 'package:super_ia/UI/views/ChatScreen.dart';
import 'package:super_ia/UI/views/NuevaEncuestaScreen.dart';
import 'package:super_ia/UI/views/WelcomeScreen.dart';
import 'package:super_ia/Core/Services/BackgroundLocationService.dart';
import 'package:super_ia/Core/Services/DiscountCodeService.dart';
import 'package:super_ia/Core/Models/DiscountCodeModel.dart';
import 'package:super_ia/Core/Preferences/DiscountPreferences.dart';
import 'package:http/http.dart' as http;
import 'package:sliding_up_panel/sliding_up_panel.dart';

// Centro de Cuenca, Ecuador
final LatLng CUENCA_CENTER = LatLng(-2.9001285, -79.0058965);

enum EstadoViaje { ninguno, buscando, conductorAsignado, enCamino, enViaje }

class ParadaViaje {
  final String nombre;
  final LatLng ubicacion;

  ParadaViaje({
    required this.nombre,
    required this.ubicacion,
  });
}

class OsmMapScreen extends StatefulWidget {
  static const String route = '/osmmap';

  @override
  _OsmMapScreenState createState() => _OsmMapScreenState();
}

class _OsmMapScreenState extends State<OsmMapScreen> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final MapController _mapController = MapController();
  final TextEditingController _searchController = TextEditingController();
  final PanelController _panelController = PanelController();
  bool _rutaInicialProcesada = false;
  Map<String, dynamic>? _viajeRepetidoPendiente;

  LatLng _miUbicacion = CUENCA_CENTER;
  LatLng _puntoRecogida = CUENCA_CENTER;
  LatLng? _destino;
  String _destinoNombre = '';
  List<ParadaViaje> _paradasIntermedias = [];
  bool _agregandoParada = false;
  String _origenNombre = 'Mi ubicación';
  String _userName = '';

  List<PlaceResult> _sugerencias = [];
  List<LatLng> _rutaPuntos = [];
  RouteResult? _rutaInfo;

  bool _cargandoUbicacion = true;
  bool _buscando = false;
  bool _mostrandoSugerencias = false;
  bool _mostrandoPanel = false;
  bool _calculandoRuta = false;
  bool _ajustandoRecogida = false;
  bool _actualizandoRecogida = false;
  bool _seleccionandoDestinoMapa = false;
  bool _actualizandoDestinoMapa = false;
  String _searchFeedback = '';
  Timer? _searchDebounce;
  Timer? _pickupDebounce;
  Timer? _destinoDebounce;
  double _panelPosition = 0.0; // 0.0 = colapsado, 1.0 = expandido

  // ── Categorías ────────────────────────────────────
  List<CategoriaModel> _categorias = [];
  CategoriaModel? _categoriaSeleccionada;
  double _precioCalculado = 0.0;

  // ── Favoritos y recientes ─────────────────────────
  List<LugarFavorito> _favoritos = [];
  List<LugarReciente> _recientes = [];
  bool _mostrandoRecientes = false;
  List<NearbyDriverMapModel> _conductoresCercanos = [];

  // ── Estado del viaje ──────────────────────────────
  EstadoViaje _estadoViaje = EstadoViaje.ninguno;
  Map<String, dynamic>? _conductorData;
  LatLng? _conductorUbicacion; // posición en tiempo real del conductor
  Timer? _simulacionTimer;
  Timer? _pollingTimer;
  Timer? _nearbyDriversTimer;
  int? _viajeId;
  bool _perfilMostrado =
      false; // Para mostrar el modal del conductor solo 1 vez

  // ── Descuentos ────────────────────────────────────
  double _descuentoAplicado = 0.0;
  String _codigoAplicado = '';

  @override
  void initState() {
    super.initState();
    _obtenerUbicacion();
    _cargarNombreUsuario();
    _cargarCategorias();
    _cargarFavoritosYRecientes();

    // Refresco automático de conductores cercanos cada 20 segundos
    _nearbyDriversTimer = Timer.periodic(const Duration(seconds: 20), (timer) {
      if (mounted && _estadoViaje == EstadoViaje.ninguno) {
        _cargarConductoresCercanos();
      }
    });
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _pickupDebounce?.cancel();
    _destinoDebounce?.cancel();
    _simulacionTimer?.cancel();
    _pollingTimer?.cancel();
    _nearbyDriversTimer?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  void _mostrarMensaje(String msg, {Color color = Colors.black87}) {
    final ctx = _scaffoldKey.currentContext;
    if (ctx == null) return;
    ScaffoldMessenger.of(ctx).showSnackBar(
      SnackBar(
          content: Text(msg),
          backgroundColor: color,
          duration: const Duration(seconds: 3)),
    );
  }

  Future<void> _notificarAsesorDesconectado() async {
    try {
      final asesorId = await AuthPrefs.getAsesorId();
      final usuarioId = await AuthPrefs.getUsuarioId();

      if (asesorId.isEmpty && usuarioId.isEmpty) {
        return;
      }

      final response = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/actualizar_estado_asesor.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {
          'estado': 'desconectado',
          'asesor_id': asesorId,
          'usuario_id': usuarioId,
        },
      ).timeout(const Duration(seconds: 8));

      debugPrint('>>> [ASESOR_STATUS] desconectado HTTP ${response.statusCode}');
    } catch (_) {
      // No bloqueamos el logout si falla la notificación.
    }
  }

  // ── Cerrar sesión ────────────────────────────────────────────
  Future<void> _cerrarSesion() async {
    // Mostrar diálogo de confirmación
    final confirmar = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ConstantColors.backgroundCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            Icon(Icons.logout_rounded, color: Colors.redAccent, size: 22),
            SizedBox(width: 8),
            Text(
              'Cerrar sesión',
              style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 17,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
        content: Text(
          '¿Seguro que deseas cerrar sesión?',
          style: TextStyle(color: ConstantColors.textGrey, fontSize: 14),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text('Cancelar',
                style: TextStyle(color: ConstantColors.textGrey)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.redAccent,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10)),
            ),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text('Salir',
                style: TextStyle(
                    color: Colors.white, fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );

    if (confirmar != true) return;

    // Detener servicio de ubicación en segundo plano
    await BackgroundLocationService.stop();

    // Marcar asesor como desconectado para ocultarlo de inmediato en el mapa web.
    await _notificarAsesorDesconectado();

    // Limpiar sesión guardada
    await AuthPrefs.clearSession();

    if (!mounted) return;

    // Navegar a pantalla de bienvenida y eliminar el historial
    Navigator.of(context).pushNamedAndRemoveUntil(
      WelcomeScreen.route,
      (route) => false,
    );
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_rutaInicialProcesada) return;

    final route = ModalRoute.of(context);
    final args = route != null ? route.settings.arguments : null;
    if (args is Map<String, dynamic> && args['repeat_trip'] == true) {
      _rutaInicialProcesada = true;
      _viajeRepetidoPendiente = Map<String, dynamic>.from(args);
      if (!_cargandoUbicacion) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          final pending = _viajeRepetidoPendiente;
          if (mounted && pending != null) {
            _viajeRepetidoPendiente = null;
            _procesarViajeRepetido(pending);
          }
        });
      }
    }
  }

  // ── Cargar categorías desde el servidor ──────────
  Future<void> _cargarCategorias() async {
    final urls = [
      '${Constants.apiBaseUrl}/obtener_categorias.php',
    ];
    for (final url in urls) {
      try {
        final response = await http.post(
          Uri.parse(url),
          headers: {'ngrok-skip-browser-warning': 'true'},
        ).timeout(const Duration(seconds: 20));
        print('>>> [CAT] HTTP ${response.statusCode} desde $url');
        if (response.statusCode == 200) {
          print('>>> [CAT] Body: ${response.body}');
          final data = json.decode(response.body);
          if (data['status'] == 'success') {
            final lista = (data['categorias'] as List)
                .map((e) => CategoriaModel.fromJson(e))
                .toList();
            print('>>> [CAT] Categorias cargadas: ${lista.length}');
            if (mounted && lista.isNotEmpty) {
              setState(() {
                _categorias = lista;
                _categoriaSeleccionada = lista.first;
              });
            }
            return;
          } else {
            print('>>> [CAT] status != success: ${data['message']}');
          }
        }
      } catch (e) {
        print('>>> [CAT] Error en $url: $e');
      }
    }
    // Fallback si no hay conexión
    print('>>> [CAT] Usando fallback Super_IA Mobile-X');
    if (mounted && _categorias.isEmpty) {
      final fallback = CategoriaModel(
        id: 1,
        nombre: 'Super_IA Mobile-X',
        tarifaBase: 1.50,
        precioKm: 0.40,
        precioMinuto: 0.10,
      );
      setState(() {
        _categorias = [fallback];
        _categoriaSeleccionada = fallback;
      });
    }
  }

  // ── Calcular precio según categoría ──────────────
  void _recalcularPrecio() {
    final info = _rutaInfo;
    final cat = _categoriaSeleccionada;
    if (info == null || cat == null) return;
    setState(() {
      _precioCalculado = cat.calcularPrecio(
        info.distanciaKm,
        info.duracionMin,
      );
    });
  }

  String _obtenerResumenDestino() {
    final partes = <String>[
      ..._paradasIntermedias.map((p) => p.nombre),
      if (_destinoNombre.isNotEmpty) _destinoNombre,
    ];
    return partes.isEmpty ? '' : partes.join(' -> ');
  }

  Future<void> _agregarParadaSeleccionada(PlaceResult lugar) async {
    if (_destino == null) return;
    if (_paradasIntermedias.length >= 3) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Puedes agregar hasta 3 paradas'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    FocusScope.of(context).unfocus();
    setState(() {
      _paradasIntermedias.add(
        ParadaViaje(
          nombre: lugar.shortName,
          ubicacion: LatLng(lugar.lat, lugar.lon),
        ),
      );
      _agregandoParada = false;
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes = false;
      _searchController.clear();
      _calculandoRuta = true;
      _rutaInfo = null;
      _rutaPuntos = [];
    });

    await _calcularRuta(_destino!);
  }

  void _iniciarAgregarParada() {
    if (_destino == null) return;

    // Si ya estamos con la búsqueda abierta y usamos el mapa, esto sirve para CONFIRMAR
    if (_agregandoParada && _seleccionandoDestinoMapa) {
      final centro = _mapController.center;
      if (centro != null) {
        _agregarParadaSeleccionada(PlaceResult(
          displayName: _searchController.text.isNotEmpty
              ? _searchController.text
              : 'Parada intermedia',
          shortName: _searchController.text.isNotEmpty
              ? _searchController.text
              : 'Parada',
          lat: centro.latitude,
          lon: centro.longitude,
        ));
        setState(() {
          _seleccionandoDestinoMapa = false;
          _actualizandoDestinoMapa = false;
        });
      }
      return;
    }

    setState(() {
      _agregandoParada = true;
      _seleccionandoDestinoMapa =
          true; // ACTIVAR AUTOMÁTICAMENTE para que aparezca el selector
      _searchController.clear();
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes = _recientes.isNotEmpty;
      _searchFeedback = '';
    });

    // Centrar el mapa para empezar a elegir la parada
    _mapController.move(
      _mapController.center ?? _miUbicacion,
      _mapController.zoom ?? 15.0,
    );
  }

  Future<void> _eliminarParada(int index) async {
    if (index < 0 || index >= _paradasIntermedias.length) return;

    setState(() {
      _paradasIntermedias.removeAt(index);
      _calculandoRuta = true;
      _rutaInfo = null;
      _rutaPuntos = [];
    });

    if (_destino != null) {
      await _calcularRuta(_destino!, recenterMap: false);
    }
  }

  // ── Mostrar confirmación antes de solicitar ───────
  void _mostrarConfirmacion() {
    final cat = _categoriaSeleccionada;
    if (cat == null) return;
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (sheetContext) => _ConfirmacionSheet(
        origen: _origenNombre,
        destino: _obtenerResumenDestino(),
        categoria: cat,
        distanciaKm: _rutaInfo?.distanciaKm ?? 0,
        duracionMin: _rutaInfo?.duracionMin ?? 0,
        precio: _precioCalculado,
        onConfirmar: () {
          _solicitarViaje();
        },
        onCuponAplicado: (codigo, descuento, precioFinal) {
          // Guardar los datos del cupón en el estado
          setState(() {
            _codigoAplicado = codigo;
            _descuentoAplicado = descuento;
            _precioCalculado = precioFinal;
          });
          print(
              '>>> [CUPOM] Guardado: $codigo, Descuento: \$${descuento.toStringAsFixed(2)}');
        },
      ),
    );
  }

  // ── Solicitar viaje ───────────────────────────────
  void _solicitarViaje() async {
    setState(() {
      _estadoViaje = EstadoViaje.buscando;
      _mostrandoPanel = false;
      _viajeId = null;
    });

    final telefono = await AuthPrefs.getUserPhone();
    final id = await _crearViajeEnServidor(telefono);
    if (mounted) setState(() => _viajeId = id ?? 0);

    _iniciarPolling();
  }

  // ── Crear viaje en el servidor ────────────────────
  Future<int?> _crearViajeEnServidor(String telefono) async {
    final urls = [
      '${Constants.apiBaseUrl}/solicitar_viaje.php',
    ];
    for (final url in urls) {
      try {
        final response = await http.post(Uri.parse(url), body: {
          'telefono': telefono,
          'categoria_id': (_categoriaSeleccionada?.id ?? 1).toString(),
          'origen_texto': _origenNombre,
          'destino_texto': _obtenerResumenDestino(),
          'distancia_km': (_rutaInfo?.distanciaKm ?? 0.0).toString(),
          'duracion_min': (_rutaInfo?.duracionMin ?? 0).toString(),
          'tarifa_total': _precioCalculado.toString(),
          'origen_lat': (_puntoRecogida ?? _miUbicacion).latitude.toString(),
          'origen_lng': (_puntoRecogida ?? _miUbicacion).longitude.toString(),
          'destino_lat': (_destino?.latitude ?? 0.0).toString(),
          'destino_lng': (_destino?.longitude ?? 0.0).toString(),
        }).timeout(const Duration(seconds: 20));

        if (response.statusCode == 200) {
          final data = json.decode(response.body);
          if (data['status'] == 'success') {
            print('>>> [VIAJE] Creado con ID: ${data['viaje_id']}');
            return int.tryParse(data['viaje_id'].toString()) ?? 0;
          }
        }
      } catch (e) {
        print('>>> [VIAJE] Error al crear viaje en $url: $e');
      }
    }
    return null;
  }

  // ── Polling ───────────────────────────────────────
  void _iniciarPolling() {
    _pollingTimer?.cancel();
    _pollingTimer = Timer.periodic(Duration(seconds: 5), (_) async {
      if (_viajeId == null || !mounted) return;
      final estadoData = await _verificarEstadoViaje(_viajeId!);
      if (!mounted) return;
      final estado = (estadoData['estado'] as String?) ?? '';
      final conductor =
          (estadoData['conductor'] as Map?)?.cast<String, dynamic>() ??
              <String, dynamic>{};

      // ── Actualizar posición del conductor en tiempo real ──
      final cLat = double.tryParse(conductor['latitud']?.toString() ?? '');
      final cLng = double.tryParse(conductor['longitud']?.toString() ?? '');
      if (cLat != null && cLng != null && cLat != 0 && cLng != 0) {
        setState(() => _conductorUbicacion = LatLng(cLat, cLng));
      }

      if (estado == 'aceptado' &&
          _estadoViaje != EstadoViaje.conductorAsignado &&
          _estadoViaje != EstadoViaje.enCamino &&
          _estadoViaje != EstadoViaje.enViaje) {
        final nombre = conductor['nombre']?.toString() ?? 'Conductor';
        setState(() {
          _estadoViaje = EstadoViaje.conductorAsignado;
          _conductorData = {
            'id': conductor['id'] ?? 0,
            'nombre': nombre,
            'inicial':
                nombre.isNotEmpty ? nombre.substring(0, 1).toUpperCase() : 'C',
            'calificacion': conductor['calificacion'] ?? 5.0,
            'viajes': conductor['viajes'] ?? 0,
            'auto': conductor['auto'] ?? 'Vehiculo asignado',
            'placa': conductor['placa'] ?? '',
            'color': conductor['color'] ?? '',
            'eta_min': conductor['eta_min'] ?? 3,
            'telefono': conductor['telefono']?.toString() ?? '',
          };
        });
        // Mostrar perfil del conductor automáticamente (solo la primera vez)
        if (!_perfilMostrado) {
          _perfilMostrado = true;
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _mostrarPerfilConductor();
          });
        }
      } else if (estado == 'en_camino' &&
          _estadoViaje != EstadoViaje.enCamino &&
          _estadoViaje != EstadoViaje.enViaje) {
        final nombre = conductor['nombre']?.toString() ??
            (_conductorData?['nombre'] ?? 'Conductor');
        setState(() {
          _estadoViaje = EstadoViaje.enCamino;
          _conductorData ??= {
            'id': conductor['id'] ?? 0,
            'nombre': nombre,
            'inicial':
                nombre.isNotEmpty ? nombre.substring(0, 1).toUpperCase() : 'C',
            'calificacion': conductor['calificacion'] ?? 5.0,
            'viajes': conductor['viajes'] ?? 0,
            'auto': conductor['auto'] ?? 'Vehiculo asignado',
            'placa': conductor['placa'] ?? '',
            'color': conductor['color'] ?? '',
            'eta_min': conductor['eta_min'] ?? 3,
          };
        });
      } else if (estado == 'aceptado' ||
          estado == 'en_camino' ||
          estado == 'iniciado') {
        // Actualizar ETA y datos del conductor aunque el estado no cambie
        if (_conductorData != null && conductor['eta_min'] != null) {
          setState(() {
            _conductorData!['eta_min'] = conductor['eta_min'];
          });
        }
        if (estado == 'iniciado' && _estadoViaje != EstadoViaje.enViaje) {
          setState(() => _estadoViaje = EstadoViaje.enViaje);
        }
      } else if (estado == 'terminado') {
        _pollingTimer?.cancel();
        _finalizarViaje();
      } else if (estado == 'cancelado') {
        _pollingTimer?.cancel();
        _cancelarViaje();
      }
    });
  }

  Future<Map<String, dynamic>> _verificarEstadoViaje(int viajeId) async {
    final urls = [
      '${Constants.apiBaseUrl}/estado_viaje.php',
    ];
    for (final url in urls) {
      try {
        final response = await http.post(Uri.parse(url), body: {
          'viaje_id': viajeId.toString(),
        }).timeout(const Duration(seconds: 20));
        if (response.statusCode == 200) {
          final data = json.decode(response.body);
          if (data['status'] == 'success')
            return (data as Map).cast<String, dynamic>();
        }
      } catch (_) {}
    }
    return <String, dynamic>{'estado': ''};
  }

  // ── Modal perfil del conductor ────────────────────
  void _mostrarPerfilConductor() {
    final data = _conductorData;
    if (data == null || !mounted) return;

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => Container(
        padding: const EdgeInsets.fromLTRB(24, 20, 24, 36),
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
          border: Border.all(color: ConstantColors.borderColor),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withOpacity(0.45),
                blurRadius: 24,
                offset: const Offset(0, -6)),
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Handle
            Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: ConstantColors.borderColor,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 24),

            // Avatar grande + badge de calificación
            Stack(
              alignment: Alignment.bottomRight,
              children: [
                CircleAvatar(
                  radius: 44,
                  backgroundColor:
                      ConstantColors.primaryViolet.withOpacity(0.2),
                  child: Text(
                    data['inicial'] ?? 'C',
                    style: TextStyle(
                      color: ConstantColors.primaryViolet,
                      fontSize: 38,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 7, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.amber,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(
                        color: ConstantColors.backgroundCard, width: 2),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.star_rounded,
                          color: Colors.white, size: 11),
                      const SizedBox(width: 2),
                      Text(
                        '${data['calificacion']}',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),

            // Nombre
            Text(
              data['nombre'] ?? 'Conductor',
              style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 20,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              '${data['viajes']} viajes completados',
              style: TextStyle(color: ConstantColors.textGrey, fontSize: 13),
            ),
            const SizedBox(height: 24),

            // Tarjeta del vehículo
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: ConstantColors.backgroundDark,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: ConstantColors.borderColor),
              ),
              child: Row(
                children: [
                  Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(
                      color: ConstantColors.primaryViolet.withOpacity(0.12),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Icon(
                      Icons.directions_car_filled_rounded,
                      color: ConstantColors.primaryViolet,
                      size: 26,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          data['auto'] ?? 'Vehículo',
                          style: TextStyle(
                            color: ConstantColors.textWhite,
                            fontSize: 15,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          '${data['color']}',
                          style: TextStyle(
                              color: ConstantColors.textGrey, fontSize: 12),
                        ),
                      ],
                    ),
                  ),
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: ConstantColors.primaryViolet.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                          color: ConstantColors.primaryViolet.withOpacity(0.3)),
                    ),
                    child: Text(
                      data['placa'] ?? '',
                      style: TextStyle(
                        color: ConstantColors.primaryViolet,
                        fontWeight: FontWeight.w800,
                        fontSize: 13,
                        letterSpacing: 1.2,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // ETA chip
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 14),
              decoration: BoxDecoration(
                color: Colors.green.withOpacity(0.08),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: Colors.green.withOpacity(0.25)),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.access_time_rounded,
                      color: Colors.greenAccent, size: 18),
                  const SizedBox(width: 8),
                  Text(
                    '¡Conductor en camino! Llegará en aprox. ${data['eta_min']} min',
                    style: const TextStyle(
                      color: Colors.greenAccent,
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // Botón cerrar
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
                  'Entendido',
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

  Future<void> _cancelarViajeEnServidor() async {
    if (_viajeId == null) return;
    final urls = [
      '${Constants.apiBaseUrl}/cancelar_viaje.php',
    ];
    for (final url in urls) {
      try {
        await http.post(Uri.parse(url), body: {
          'viaje_id': _viajeId.toString(),
        }).timeout(const Duration(seconds: 6));
        break;
      } catch (_) {}
    }
  }

  void _cancelarViaje() async {
    final confirmar = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: ConstantColors.backgroundCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: Text(
          '¿Cancelar viaje?',
          style: TextStyle(
              color: ConstantColors.textWhite, fontWeight: FontWeight.w700),
        ),
        content: Text(
          'Se cancelará la búsqueda de conductor.',
          style: TextStyle(color: ConstantColors.textGrey, fontSize: 14),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text('No, continuar',
                style: TextStyle(color: ConstantColors.primaryViolet)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child:
                Text('Sí, cancelar', style: TextStyle(color: Colors.redAccent)),
          ),
        ],
      ),
    );
    if (confirmar != true) return;

    _simulacionTimer?.cancel();
    _pollingTimer?.cancel();
    _cancelarViajeEnServidor();
    setState(() {
      _estadoViaje = EstadoViaje.ninguno;
      _conductorData = null;
      _conductorUbicacion = null;
      _viajeId = null;
      _perfilMostrado = false;
      _mostrandoPanel = _destino != null;
    });
  }

  void _finalizarViaje() {
    _simulacionTimer?.cancel();

    // Registrar uso del cupón en el servidor
    if (_codigoAplicado.isNotEmpty && _viajeId != null) {
      DiscountCodeService.registrarUsoEnServidor(_codigoAplicado, _viajeId!);
    }

    Navigator.pushNamed(
      context,
      '/ride_completed',
      arguments: {
        'viaje_id': _viajeId,
        'conductor': _conductorData,
        'origen': _origenNombre,
        'destino': _obtenerResumenDestino(),
        'distancia': _rutaInfo?.distanciaKm ?? 0.0,
        'duracion': _rutaInfo?.duracionMin ?? 0,
        'precio': _precioCalculado,
        'descuento': _descuentoAplicado,
        'codigo_descuento': _codigoAplicado,
        'origen_lat': (_puntoRecogida ?? _miUbicacion).latitude,
        'origen_lng': (_puntoRecogida ?? _miUbicacion).longitude,
        'destino_lat': _destino?.latitude ?? 0.0,
        'destino_lng': _destino?.longitude ?? 0.0,
        // ── Para el recibo detallado ──
        'categoria_nombre': _categoriaSeleccionada?.nombre ?? 'Super_IA Mobile-X',
        'tarifa_base': _categoriaSeleccionada?.tarifaBase ?? 1.50,
        'precio_km': _categoriaSeleccionada?.precioKm ?? 0.45,
        'precio_minuto': _categoriaSeleccionada?.precioMinuto ?? 0.10,
      },
    ).then((_) {
      setState(() {
        _estadoViaje = EstadoViaje.ninguno;
        _conductorData = null;
        _perfilMostrado = false;
        _mostrandoPanel = false;
        _destino = null;
        _destinoNombre = '';
        _rutaPuntos = [];
        _rutaInfo = null;
        _precioCalculado = 0.0;
        _descuentoAplicado = 0.0;
        _codigoAplicado = '';
        _searchController.clear();
      });

      // Limpiar cupón en preferencias
      DiscountPreferences.limpiarCuponActual();
    });
  }

  // ── Enviar SOS a todos los contactos de emergencia ────
  /// Toque simple en SOS → cuenta regresiva de 5 s y auto-envío.
  /// Permite cancelar antes de que termine.
  Future<void> _sosConCuentaRegresiva() async {
    int cuenta = 5;
    bool cancelado = false;

    await showDialog(
      context: context,
      barrierDismissible: false,
      builder: (dialogCtx) {
        return StatefulBuilder(
          builder: (ctx, setDialogState) {
            // Ticker interno del diálogo
            Future.doWhile(() async {
              await Future.delayed(const Duration(seconds: 1));
              if (!ctx.mounted || cancelado) return false;
              cuenta--;
              setDialogState(() {});
              if (cuenta <= 0) {
                Navigator.of(dialogCtx).pop();
                return false;
              }
              return true;
            });

            return AlertDialog(
              backgroundColor: Colors.red.shade900,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(24)),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.warning_rounded, color: Colors.white, size: 48),
                  const SizedBox(height: 12),
                  const Text(
                    '¡EMERGENCIA!',
                    style: TextStyle(
                        color: Colors.white,
                        fontSize: 22,
                        fontWeight: FontWeight.w900),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Enviando SOS en $cuenta segundos…',
                    style: const TextStyle(color: Colors.white70, fontSize: 14),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton(
                      style: OutlinedButton.styleFrom(
                        side: const BorderSide(color: Colors.white54),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(14)),
                      ),
                      onPressed: () {
                        cancelado = true;
                        Navigator.of(dialogCtx).pop();
                      },
                      child: const Text('Cancelar',
                          style: TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w700)),
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );

    if (!cancelado && mounted) {
      await _enviarSOS();
    }
  }

  Future<void> _enviarSOS() async {
    try {
      // Obtener nombre de usuario
      final nombreUsuario = _userName.isNotEmpty ? _userName : 'Pasajero';

      // Obtener contactos de emergencia
      final contactos = await EmergencyContactsService.obtenerContactos();

      if (contactos.isEmpty) {
        _mostrarMensaje('No tienes contactos de emergencia registrados',
            color: Colors.orange);
        return;
      }

      // Enviar SOS a cada contacto
      int enviados = 0;
      for (final contacto in contactos) {
        try {
          // Validar teléfono
          if (!SOSService.validarTelefono(contacto.telefono)) {
            print('>>> [SOS] Teléfono inválido: ${contacto.telefono}');
            continue;
          }

          // Formatear teléfono para WhatsApp
          final telefonoFormateado =
              SOSService.formatearTelefonoWhatsapp(contacto.telefono);

          // Enviar SOS
          await SOSService.enviarSOS(
            telefono: telefonoFormateado,
            ubicacion: _puntoRecogida ?? _miUbicacion,
            nombrePasajero: nombreUsuario,
          );
          enviados++;
        } catch (e) {
          print('>>> [SOS] Error al enviar a ${contacto.nombre}: $e');
        }
      }

      // Mostrar confirmación
      _mostrarMensaje(
          '¡SOS enviado a $enviados contacto${enviados != 1 ? 's' : ''}!',
          color: Colors.green);
    } catch (e) {
      print('>>> [SOS] Error general: $e');
      _mostrarMensaje('Error al enviar SOS', color: Colors.red);
    }
  }

  // ── Compartir viaje con familia/amigos ─────────────────
  Future<void> _compartirViaje() async {
    try {
      // Validar que hay un viaje activo
      final bool _hayViajeActivo =
          _estadoViaje == EstadoViaje.conductorAsignado ||
              _estadoViaje == EstadoViaje.enCamino ||
              _estadoViaje == EstadoViaje.enViaje;
      if (!_hayViajeActivo || _conductorData == null) {
        _mostrarMensaje('No hay un viaje activo para compartir',
            color: Colors.orange);
        return;
      }

      // Validar ubicación y destino
      if ((_puntoRecogida ?? _miUbicacion) == null || _destino == null) {
        _mostrarMensaje('Ubicación incompleta', color: Colors.red);
        return;
      }

      // Generar mensaje con detalles del viaje
      final conductorLocal = _conductorData ?? <String, dynamic>{};
      final mensaje = ShareTripService.generarMensajeViaje(
        nombrePasajero: _userName.isNotEmpty ? _userName : 'Pasajero',
        destinoNombre: _obtenerResumenDestino(),
        ubicacionActual: _puntoRecogida ?? _miUbicacion,
        destino: _destino!,
        distanciaKm: _rutaInfo?.distanciaKm ?? 0.0,
        duracionMin: _rutaInfo?.duracionMin ?? 0,
        precioEstimado: _precioCalculado,
        nombreConductor: conductorLocal['nombre']?.toString() ?? '',
        placaConductor: conductorLocal['placa']?.toString() ?? '',
        autoConductor: conductorLocal['auto']?.toString() ?? '',
        etaMin: conductorLocal['eta_min'] ?? 0,
      );

      print('>>> [SHARE] Mensaje generado:\n$mensaje');

      // Mostrar opciones de compartir
      _mostrarOpcionesCompartir(mensaje);
    } catch (e) {
      print('>>> [SHARE] Error al preparar compartir: $e');
      _mostrarMensaje('Error al preparar compartir viaje', color: Colors.red);
    }
  }

  // ── Mostrar opciones para compartir ────────────────────
  void _mostrarOpcionesCompartir(String mensaje) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => Container(
        padding: EdgeInsets.fromLTRB(20, 16, 20, 36),
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
          border: Border.all(color: ConstantColors.borderColor),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withOpacity(0.4),
                blurRadius: 20,
                offset: Offset(0, -5))
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                  color: ConstantColors.borderColor,
                  borderRadius: BorderRadius.circular(2)),
            ),
            SizedBox(height: 24),
            Text('Compartir viaje',
                style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 18,
                    fontWeight: FontWeight.w700)),
            SizedBox(height: 20),

            // Opción: WhatsApp
            GestureDetector(
              onTap: () => _compartirWhatsapp(mensaje),
              child: Container(
                padding: EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: BoxDecoration(
                  color: Colors.green.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: Colors.green.withOpacity(0.3)),
                ),
                child: Row(
                  children: [
                    Icon(Icons.message, color: Colors.green, size: 24),
                    SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('WhatsApp',
                              style: TextStyle(
                                  color: Colors.green,
                                  fontSize: 15,
                                  fontWeight: FontWeight.w600)),
                          Text('Compartir con contacto',
                              style: TextStyle(
                                  color: ConstantColors.textGrey,
                                  fontSize: 12)),
                        ],
                      ),
                    ),
                    Icon(Icons.arrow_forward_rounded,
                        color: ConstantColors.textGrey, size: 20),
                  ],
                ),
              ),
            ),

            SizedBox(height: 12),

            // Opción: SMS
            GestureDetector(
              onTap: () => _compartirSMS(mensaje),
              child: Container(
                padding: EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: BoxDecoration(
                  color: ConstantColors.primaryBlue.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(
                      color: ConstantColors.primaryBlue.withOpacity(0.3)),
                ),
                child: Row(
                  children: [
                    Icon(Icons.sms,
                        color: ConstantColors.primaryBlue, size: 24),
                    SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('SMS',
                              style: TextStyle(
                                  color: ConstantColors.primaryBlue,
                                  fontSize: 15,
                                  fontWeight: FontWeight.w600)),
                          Text('Enviar por mensaje de texto',
                              style: TextStyle(
                                  color: ConstantColors.textGrey,
                                  fontSize: 12)),
                        ],
                      ),
                    ),
                    Icon(Icons.arrow_forward_rounded,
                        color: ConstantColors.textGrey, size: 20),
                  ],
                ),
              ),
            ),

            SizedBox(height: 12),

            // Opción: Copiar enlace
            GestureDetector(
              onTap: () => _copiarEnlace(),
              child: Container(
                padding: EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: BoxDecoration(
                  color: ConstantColors.primaryViolet.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(
                      color: ConstantColors.primaryViolet.withOpacity(0.3)),
                ),
                child: Row(
                  children: [
                    Icon(Icons.link,
                        color: ConstantColors.primaryViolet, size: 24),
                    SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Copiar enlace',
                              style: TextStyle(
                                  color: ConstantColors.primaryViolet,
                                  fontSize: 15,
                                  fontWeight: FontWeight.w600)),
                          Text('Copiar ubicación al portapapeles',
                              style: TextStyle(
                                  color: ConstantColors.textGrey,
                                  fontSize: 12)),
                        ],
                      ),
                    ),
                    Icon(Icons.arrow_forward_rounded,
                        color: ConstantColors.textGrey, size: 20),
                  ],
                ),
              ),
            ),

            SizedBox(height: 16),

            // Botón cerrar
            GestureDetector(
              onTap: () => Navigator.pop(context),
              child: Container(
                width: double.infinity,
                height: 48,
                decoration: BoxDecoration(
                  color: Colors.transparent,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: ConstantColors.borderColor),
                ),
                child: Center(
                  child: Text('Cerrar',
                      style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 15,
                          fontWeight: FontWeight.w600)),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Compartir por WhatsApp ───────────────────────────────
  Future<void> _compartirWhatsapp(String mensaje) async {
    try {
      Navigator.pop(context); // Cerrar modal

      // Solicitar número de teléfono
      String telefono = '';
      await showDialog(
        context: context,
        builder: (_) => AlertDialog(
          backgroundColor: ConstantColors.backgroundCard,
          title: Text('Ingresa el número',
              style: TextStyle(color: ConstantColors.textWhite)),
          content: TextField(
            style: TextStyle(color: ConstantColors.textWhite),
            decoration: InputDecoration(
              hintText: '+593 9xxxxxxxx',
              hintStyle: TextStyle(color: ConstantColors.textGrey),
              border:
                  OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              focusedBorder: OutlineInputBorder(
                borderSide: BorderSide(color: ConstantColors.primaryViolet),
                borderRadius: BorderRadius.circular(8),
              ),
            ),
            onChanged: (value) => telefono = value,
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text('Cancelar',
                  style: TextStyle(color: ConstantColors.textGrey)),
            ),
            TextButton(
              onPressed: () {
                Navigator.pop(context);
                _enviarPorWhatsappAContacto(telefono, mensaje);
              },
              child: Text('Enviar', style: TextStyle(color: Colors.green)),
            ),
          ],
        ),
      );
    } catch (e) {
      print('>>> [SHARE] Error en compartir WhatsApp: $e');
    }
  }

  // ── Enviar por WhatsApp a contacto específico ─────────────
  Future<void> _enviarPorWhatsappAContacto(
      String telefono, String mensaje) async {
    try {
      if (telefono.isEmpty) {
        _mostrarMensaje('Ingresa un número de teléfono', color: Colors.orange);
        return;
      }

      // Validar teléfono
      if (!ShareTripService.validarTelefono(telefono)) {
        _mostrarMensaje('Número de teléfono inválido', color: Colors.red);
        return;
      }

      // Enviar por WhatsApp
      await ShareTripService.compartirPorWhatsapp(
        telefono: telefono,
        mensaje: mensaje,
      );

      _mostrarMensaje('¡Viaje compartido por WhatsApp!', color: Colors.green);
    } catch (e) {
      print('>>> [SHARE] Error al enviar WhatsApp: $e');
      _mostrarMensaje('Error al enviar por WhatsApp', color: Colors.red);
    }
  }

  // ── Compartir por SMS ────────────────────────────────────
  Future<void> _compartirSMS(String mensaje) async {
    try {
      Navigator.pop(context); // Cerrar modal

      // Solicitar número de teléfono
      String telefono = '';
      await showDialog(
        context: context,
        builder: (_) => AlertDialog(
          backgroundColor: ConstantColors.backgroundCard,
          title: Text('Ingresa el número',
              style: TextStyle(color: ConstantColors.textWhite)),
          content: TextField(
            style: TextStyle(color: ConstantColors.textWhite),
            decoration: InputDecoration(
              hintText: '+593 9xxxxxxxx',
              hintStyle: TextStyle(color: ConstantColors.textGrey),
              border:
                  OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              focusedBorder: OutlineInputBorder(
                borderSide: BorderSide(color: ConstantColors.primaryViolet),
                borderRadius: BorderRadius.circular(8),
              ),
            ),
            onChanged: (value) => telefono = value,
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text('Cancelar',
                  style: TextStyle(color: ConstantColors.textGrey)),
            ),
            TextButton(
              onPressed: () {
                Navigator.pop(context);
                _enviarSMSAContacto(telefono, mensaje);
              },
              child: Text('Enviar',
                  style: TextStyle(color: ConstantColors.primaryBlue)),
            ),
          ],
        ),
      );
    } catch (e) {
      print('>>> [SHARE] Error en compartir SMS: $e');
    }
  }

  // ── Enviar SMS a contacto específico ──────────────────────
  Future<void> _enviarSMSAContacto(String telefono, String mensaje) async {
    try {
      if (telefono.isEmpty) {
        _mostrarMensaje('Ingresa un número de teléfono', color: Colors.orange);
        return;
      }

      // Validar teléfono
      if (!ShareTripService.validarTelefono(telefono)) {
        _mostrarMensaje('Número de teléfono inválido', color: Colors.red);
        return;
      }

      // Enviar por SMS
      await ShareTripService.compartirPorSMS(
        telefono: telefono,
        mensaje: mensaje,
      );

      _mostrarMensaje('¡Viaje compartido por SMS!', color: Colors.green);
    } catch (e) {
      print('>>> [SHARE] Error al enviar SMS: $e');
      _mostrarMensaje('Error al enviar SMS', color: Colors.red);
    }
  }

  // ── Copiar enlace de ubicación ───────────────────────────
  Future<void> _copiarEnlace() async {
    try {
      Navigator.pop(context); // Cerrar modal

      if (_miUbicacion == null) {
        _mostrarMensaje('Ubicación no disponible', color: Colors.red);
        return;
      }

      final enlace =
          ShareTripService.generarEnlaceMaps(_puntoRecogida ?? _miUbicacion);

      // Copiar al portapapeles
      await Future.delayed(
          Duration(milliseconds: 300)); // Pequeña pausa para cerrar el modal

      // Para copiar al portapapeles, necesitamos usar dart:io y otros métodos
      // Por ahora, mostraremos el enlace en una alerta
      await showDialog(
        context: context,
        builder: (_) => AlertDialog(
          backgroundColor: ConstantColors.backgroundCard,
          title: Text('Enlace de ubicación',
              style: TextStyle(color: ConstantColors.textWhite)),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SelectableText(
                enlace,
                style:
                    TextStyle(color: ConstantColors.primaryBlue, fontSize: 12),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text('Cerrar',
                  style: TextStyle(color: ConstantColors.textGrey)),
            ),
          ],
        ),
      );

      _mostrarMensaje('¡Enlace preparado!', color: Colors.green);
    } catch (e) {
      print('>>> [SHARE] Error al copiar enlace: $e');
    }
  }

  Future<void> _cargarNombreUsuario() async {
    final nombre = await AuthPrefs.getUserName();
    if (mounted) setState(() => _userName = nombre);
  }

  // ── Modal de perfil del asesor ───────────────────────────────
  Future<void> _mostrarPerfilModal() async {
    final nombre = await AuthPrefs.getUserName();
    final telefono = await AuthPrefs.getUserPhone();
    final email = await AuthPrefs.getUserEmail();

    if (!mounted) return;

    final nombreCtrl = TextEditingController(text: nombre);
    final telefonoCtrl = TextEditingController(text: telefono);
    final emailCtrl = TextEditingController(text: email);
    bool guardandoPerfil = false;

    await showModalBottomSheet(
      context: context,
      backgroundColor: ConstantColors.backgroundCard,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (context, setModalState) => Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom,
          ),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Handle
                Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: Colors.white24,
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
                const SizedBox(height: 20),
                // Ícono
                Container(
                  width: 70,
                  height: 70,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: ConstantColors.primaryGradient,
                  ),
                  child: const Icon(Icons.person_rounded,
                      color: Colors.white, size: 36),
                ),
                const SizedBox(height: 12),
                Text(
                  'Mi Perfil',
                  style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 18,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'Asesor Comercial',
                  style: TextStyle(
                      color: ConstantColors.primaryViolet, fontSize: 13),
                ),
                const SizedBox(height: 24),
                // Campo nombre
                _perfilCampo(
                    ctrl: nombreCtrl,
                    label: 'Nombre completo',
                    icon: Icons.person_rounded),
                const SizedBox(height: 12),
                _perfilCampo(
                    ctrl: telefonoCtrl,
                    label: 'Teléfono',
                    icon: Icons.phone_rounded,
                    tipo: TextInputType.phone),
                const SizedBox(height: 12),
                _perfilCampo(
                    ctrl: emailCtrl,
                    label: 'Email',
                    icon: Icons.email_rounded,
                    tipo: TextInputType.emailAddress),
                const SizedBox(height: 24),
                // Botón guardar
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: guardandoPerfil
                        ? null
                        : () async {
                            setModalState(() => guardandoPerfil = true);
                            await AuthPrefs.saveUserSession(
                              nombre: nombreCtrl.text.trim(),
                              telefono: telefonoCtrl.text.trim(),
                              email: emailCtrl.text.trim(),
                            );
                            if (mounted)
                              setState(
                                  () => _userName = nombreCtrl.text.trim());
                            Navigator.pop(ctx);
                            ScaffoldMessenger.of(this.context).showSnackBar(
                              SnackBar(
                                content: const Text('Perfil actualizado'),
                                backgroundColor: ConstantColors.success,
                                behavior: SnackBarBehavior.floating,
                              ),
                            );
                          },
                    style: ElevatedButton.styleFrom(
                      backgroundColor: ConstantColors.primaryViolet,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14)),
                    ),
                    child: guardandoPerfil
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                                strokeWidth: 2, color: Colors.white))
                        : const Text('Guardar cambios',
                            style: TextStyle(
                                fontWeight: FontWeight.w700, fontSize: 15)),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    nombreCtrl.dispose();
    telefonoCtrl.dispose();
    emailCtrl.dispose();
  }

  Widget _perfilCampo({
    required TextEditingController ctrl,
    required String label,
    required IconData icon,
    TextInputType tipo = TextInputType.text,
  }) {
    return TextField(
      controller: ctrl,
      keyboardType: tipo,
      style: TextStyle(color: ConstantColors.textWhite, fontSize: 14),
      decoration: InputDecoration(
        labelText: label,
        prefixIcon: Icon(icon, color: ConstantColors.primaryViolet, size: 20),
        filled: true,
        fillColor: ConstantColors.backgroundLight,
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
          borderSide:
              BorderSide(color: ConstantColors.primaryViolet, width: 1.5),
        ),
        labelStyle: TextStyle(color: ConstantColors.textSubtle, fontSize: 13),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
    );
  }

  Future<void> _cargarFavoritosYRecientes() async {
    final favs = await FavoritePlacesService.obtenerFavoritos();
    final recs = await RecentPlacesService.obtenerRecientes();
    if (mounted)
      setState(() {
        _favoritos = favs;
        _recientes = recs;
      });
  }

  Future<void> _cargarConductoresCercanos() async {
    final urls = [
      '${Constants.apiBaseUrl}/obtener_conductores_cercanos.php',
    ];

    final categoriaId = _categoriaSeleccionada?.id ?? 0;

    for (final url in urls) {
      try {
        final response = await http.post(
          Uri.parse(url),
          headers: {'ngrok-skip-browser-warning': 'true'},
          body: {
            'lat': (_puntoRecogida ?? _miUbicacion).latitude.toString(),
            'lng': (_puntoRecogida ?? _miUbicacion).longitude.toString(),
            'categoria_id': categoriaId.toString(),
          },
        ).timeout(const Duration(seconds: 20));

        if (response.statusCode == 200) {
          final data = json.decode(response.body);
          if (data['status'] == 'success') {
            final lista = (data['conductores'] as List)
                .map((e) =>
                    NearbyDriverMapModel.fromJson(Map<String, dynamic>.from(e)))
                .toList();
            if (mounted) setState(() => _conductoresCercanos = lista);
            return;
          }
        }
      } catch (e) {
        print('>>> [DRIVERS] Error en $url: $e');
      }
    }

    if (mounted) setState(() => _conductoresCercanos = []);
  }

  void _mostrarInfoConductor(NearbyDriverMapModel conductor) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (_) => Container(
        padding: EdgeInsets.fromLTRB(20, 20, 20, 30),
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
          border: Border.all(color: ConstantColors.borderColor),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: ConstantColors.borderColor,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
            SizedBox(height: 18),
            Row(
              children: [
                CircleAvatar(
                  radius: 24,
                  backgroundColor:
                      ConstantColors.primaryViolet.withOpacity(0.18),
                  child: Text(
                    conductor.nombre.isNotEmpty
                        ? conductor.nombre[0].toUpperCase()
                        : 'C',
                    style: TextStyle(
                      color: ConstantColors.primaryViolet,
                      fontSize: 22,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        conductor.nombre,
                        style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 16,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      SizedBox(height: 4),
                      Text(
                        conductor.autoDescripcion,
                        style: TextStyle(
                          color: ConstantColors.textGrey,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
                Row(
                  children: [
                    Icon(Icons.star_rounded, color: Colors.amber, size: 16),
                    SizedBox(width: 4),
                    Text(
                      conductor.calificacion.toStringAsFixed(1),
                      style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              ],
            ),
            SizedBox(height: 18),
            Container(
              padding: EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: ConstantColors.backgroundLight,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: ConstantColors.borderColor),
              ),
              child: Row(
                children: [
                  Icon(Icons.near_me_rounded,
                      color: ConstantColors.primaryBlue, size: 18),
                  SizedBox(width: 8),
                  Text(
                    'A ${conductor.distanciaKm.toStringAsFixed(1)} km de ti',
                    style: TextStyle(
                      color: ConstantColors.textWhite,
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _procesarViajeRepetido(Map<String, dynamic> args) async {
    LatLng? destino;
    final double destinoLat = (args['destino_lat'] as num)?.toDouble() ?? 0.0;
    final double destinoLng = (args['destino_lng'] as num)?.toDouble() ?? 0.0;
    final String destinoTexto = args['destino'] ?? '';

    if (destinoLat != 0.0 || destinoLng != 0.0) {
      destino = LatLng(destinoLat, destinoLng);
    } else if (destinoTexto.isNotEmpty) {
      final resultados = await OsmService.buscarLugar(destinoTexto);
      if (resultados.isNotEmpty) {
        destino = LatLng(resultados.first.lat, resultados.first.lon);
      }
    }

    if (destino == null) {
      _mostrarMensaje('No se pudo preparar este viaje nuevamente',
          color: Colors.orange);
      return;
    }

    final destinoFinal = destino!;
    FocusScope.of(context).unfocus();
    setState(() {
      _destino = destinoFinal;
      _destinoNombre = destinoTexto.isNotEmpty ? destinoTexto : 'Destino';
      _paradasIntermedias = [];
      _agregandoParada = false;
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes = false;
      _ajustandoRecogida = false;
      _actualizandoRecogida = false;
      _seleccionandoDestinoMapa = false;
      _actualizandoDestinoMapa = false;
      _searchController.text = _destinoNombre;
      _calculandoRuta = true;
      _mostrandoPanel = true;
      _rutaInfo = null;
      _rutaPuntos = [];
      _estadoViaje = EstadoViaje.ninguno;
    });

    _mapController.move(destinoFinal, 14.0);
    await _calcularRuta(destinoFinal);

    if (mounted) {
      _mostrarMensaje('Viaje anterior cargado. Revisa y confirma.',
          color: ConstantColors.primaryViolet);
    }
  }

  // Usar un favorito como destino directo
  void _seleccionarFavorito(LugarFavorito fav) {
    if (_agregandoParada && _destino != null) {
      _agregarParadaSeleccionada(PlaceResult(
        displayName: fav.direccion,
        shortName: fav.direccion,
        lat: fav.lat,
        lon: fav.lng,
      ));
      return;
    }

    final destino = LatLng(fav.lat, fav.lng);
    FocusScope.of(context).unfocus();
    setState(() {
      _destino = destino;
      _destinoNombre = fav.direccion;
      _paradasIntermedias = [];
      _agregandoParada = false;
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes = false;
      _ajustandoRecogida = false;
      _actualizandoRecogida = false;
      _seleccionandoDestinoMapa = false;
      _actualizandoDestinoMapa = false;
      _searchController.text = fav.direccion;
      _calculandoRuta = true;
      _mostrandoPanel = true;
      _rutaInfo = null;
      _rutaPuntos = [];
    });
    _mapController.move(destino, 14.0);
    _calcularRuta(destino);
  }

  // Usar un reciente como destino directo
  void _seleccionarReciente(LugarReciente rec) {
    if (_agregandoParada && _destino != null) {
      _agregarParadaSeleccionada(PlaceResult(
        displayName: rec.nombre,
        shortName: rec.nombre,
        lat: rec.lat,
        lon: rec.lng,
      ));
      return;
    }

    final destino = LatLng(rec.lat, rec.lng);
    FocusScope.of(context).unfocus();
    setState(() {
      _destino = destino;
      _destinoNombre = rec.nombre;
      _paradasIntermedias = [];
      _agregandoParada = false;
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes = false;
      _ajustandoRecogida = false;
      _actualizandoRecogida = false;
      _seleccionandoDestinoMapa = false;
      _actualizandoDestinoMapa = false;
      _searchController.text = rec.nombre;
      _calculandoRuta = true;
      _mostrandoPanel = true;
      _rutaInfo = null;
      _rutaPuntos = [];
    });
    _mapController.move(destino, 14.0);
    _calcularRuta(destino);
  }

  Future<void> _obtenerUbicacion() async {
    try {
      LocationPermission permission = await Geolocator.checkPermission();

      if (permission == LocationPermission.deniedForever) {
        _mostrarMensaje('Ubicación denegada. Habilítala en ajustes.',
            color: Colors.red);
        await Geolocator.openAppSettings();
        return;
      }

      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
        if (permission == LocationPermission.denied) {
          _mostrarMensaje('Se requieren permisos de ubicación para funcionar',
              color: Colors.orange);
          return;
        }
        if (permission == LocationPermission.deniedForever) {
          _mostrarMensaje('Ubicación denegada. Habilítala en ajustes.',
              color: Colors.red);
          return;
        }
      }

      Position position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );
      if (!mounted) return;
      final ubicacion = LatLng(position.latitude, position.longitude);
      final nombre = await OsmService.obtenerNombreLugar(
        position.latitude,
        position.longitude,
      );
      if (!mounted) return;
      setState(() {
        _miUbicacion = ubicacion;
        _puntoRecogida = ubicacion;
        _origenNombre = nombre;
        _cargandoUbicacion = false;
      });
      _mapController.move(ubicacion, 15.0);
      _cargarConductoresCercanos();
      final pending = _viajeRepetidoPendiente;
      if (pending != null) {
        _viajeRepetidoPendiente = null;
        await _procesarViajeRepetido(pending);
      }
    } catch (e) {
      if (mounted) setState(() => _cargandoUbicacion = false);
      final pending = _viajeRepetidoPendiente;
      if (pending != null) {
        _viajeRepetidoPendiente = null;
        await _procesarViajeRepetido(pending);
      }
    }
  }

  Future<void> _buscarDestino(String query) async {
    final texto = query.trim();
    if (texto.isEmpty) {
      setState(() {
        _sugerencias = [];
        _mostrandoSugerencias = false;
        _buscando = false;
        _searchFeedback = '';
      });
      return;
    }
    if (texto.length < 3) {
      setState(() {
        _sugerencias = [];
        _mostrandoSugerencias = false;
        _buscando = false;
        _searchFeedback = 'Escribe al menos 3 letras';
      });
      return;
    }
    setState(() {
      _buscando = true;
      _searchFeedback = '';
    });
    final resultados = await OsmService.buscarLugar(texto);
    if (!mounted) return;
    setState(() {
      _sugerencias = resultados;
      _mostrandoSugerencias = resultados.isNotEmpty;
      _buscando = false;
      _searchFeedback = resultados.isEmpty
          ? 'Sin resultados. Prueba con otra dirección.'
          : '';
    });
  }

  void _onSearchChanged(String value) {
    if (value.isEmpty) {
      setState(() {
        _mostrandoRecientes = true;
        _mostrandoSugerencias = false;
        _sugerencias = [];
        _searchFeedback = '';
      });
      return;
    }
    setState(() => _mostrandoRecientes = false);
    _searchDebounce?.cancel();
    _searchDebounce =
        Timer(const Duration(milliseconds: 450), () => _buscarDestino(value));
  }

  Future<void> _seleccionarDestino(PlaceResult lugar) async {
    if (_agregandoParada && _destino != null) {
      await _agregarParadaSeleccionada(lugar);
      return;
    }

    final destino = LatLng(lugar.lat, lugar.lon);
    FocusScope.of(context).unfocus();
    setState(() {
      _destino = destino;
      _destinoNombre = lugar.shortName;
      _paradasIntermedias = [];
      _agregandoParada = false;
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes = false;
      _ajustandoRecogida = false;
      _actualizandoRecogida = false;
      _seleccionandoDestinoMapa = false;
      _actualizandoDestinoMapa = false;
      _searchController.text = lugar.shortName;
      _calculandoRuta = true;
      _mostrandoPanel = true;
      _rutaInfo = null;
      _rutaPuntos = [];
    });
    // Guardar en recientes
    RecentPlacesService.guardarReciente(LugarReciente(
      nombre: lugar.shortName,
      lat: lugar.lat,
      lng: lugar.lon,
      fecha: DateTime.now(),
    )).then((_) => _cargarFavoritosYRecientes());
    _mapController.move(destino, 14.0);
    await _calcularRuta(destino);
  }

  void _toggleSeleccionDestinoMapa() {
    FocusScope.of(context).unfocus();
    setState(() {
      _seleccionandoDestinoMapa = !_seleccionandoDestinoMapa;
      if (_seleccionandoDestinoMapa) {
        _ajustandoRecogida = false;
        _actualizandoRecogida = false;
      } else {
        _actualizandoDestinoMapa = false;
      }
    });

    if (_seleccionandoDestinoMapa) {
      _mapController.move(
        _destino ?? _puntoRecogida ?? _miUbicacion,
        _mapController.zoom ?? 15.0,
      );
    }
  }

  // ── Calcular y mostrar la ruta al destino ─────────
  Future<void> _calcularRuta(LatLng destino, {bool recenterMap = true}) async {
    final origenRuta = _puntoRecogida ?? _miUbicacion;
    final puntosRuta = <LatLng>[
      origenRuta,
      ..._paradasIntermedias.map((p) => p.ubicacion),
      destino,
    ];

    final Distance distCalc = Distance();
    final List<LatLng> puntosCompletos = [];
    double distanciaTotalKm = 0.0;
    int duracionTotalMin = 0;

    for (int i = 0; i < puntosRuta.length - 1; i++) {
      final origenTramo = puntosRuta[i];
      final destinoTramo = puntosRuta[i + 1];
      final rutaTramoTemp =
          await OsmService.calcularRuta(origenTramo, destinoTramo);
      final RouteResult rutaTramo;

      if (rutaTramoTemp == null) {
        final distanciaM =
            distCalc.as(LengthUnit.Meter, origenTramo, destinoTramo);
        final distanciaKm = distanciaM / 1000;
        final duracionMin = ((distanciaKm / 30) * 60).round();
        rutaTramo = RouteResult(
          puntos: [origenTramo, destinoTramo],
          distanciaKm: distanciaKm,
          duracionMin: duracionMin,
          precioEstimado: 0.0,
        );
      } else {
        rutaTramo = rutaTramoTemp;
      }

      if (puntosCompletos.isEmpty) {
        puntosCompletos.addAll(rutaTramo.puntos);
      } else {
        puntosCompletos.addAll(rutaTramo.puntos.skip(1));
      }

      distanciaTotalKm += rutaTramo.distanciaKm;
      duracionTotalMin += rutaTramo.duracionMin;
    }

    final ruta = RouteResult(
      puntos: puntosCompletos,
      distanciaKm: distanciaTotalKm,
      duracionMin: duracionTotalMin,
      precioEstimado: 0.0,
    );

    if (mounted) {
      setState(() {
        _rutaPuntos = ruta.puntos;
        _rutaInfo = ruta;
        _calculandoRuta = false;
      });
      _recalcularPrecio();
      if (recenterMap && puntosRuta.length >= 2) {
        // Calcular el centro de todos los puntos de la ruta (incluyendo paradas)
        double minLat = puntosRuta[0].latitude;
        double maxLat = puntosRuta[0].latitude;
        double minLng = puntosRuta[0].longitude;
        double maxLng = puntosRuta[0].longitude;

        for (var p in puntosRuta) {
          if (p.latitude < minLat) minLat = p.latitude;
          if (p.latitude > maxLat) maxLat = p.latitude;
          if (p.longitude < minLng) minLng = p.longitude;
          if (p.longitude > maxLng) maxLng = p.longitude;
        }

        _mapController.move(
          LatLng((minLat + maxLat) / 2, (minLng + maxLng) / 2),
          _mapController.zoom ?? 13.0,
        );
      }
    }
  }

  void _limpiarDestino() {
    setState(() {
      _destino = null;
      _destinoNombre = '';
      _rutaPuntos = [];
      _rutaInfo = null;
      _paradasIntermedias = [];
      _agregandoParada = false;
      _mostrandoPanel = false;
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes = false;
      _seleccionandoDestinoMapa = false;
      _actualizandoDestinoMapa = false;
      _searchFeedback = '';
      _precioCalculado = 0.0;
      _searchController.clear();
    });
  }

  void _centrarEnMiUbicacion() => _mapController.move(_miUbicacion, 15.0);

  void _toggleAjusteRecogida() {
    setState(() {
      _ajustandoRecogida = !_ajustandoRecogida;
      if (_ajustandoRecogida) {
        _seleccionandoDestinoMapa = false;
        _actualizandoDestinoMapa = false;
      }
      if (!_ajustandoRecogida) {
        _actualizandoRecogida = false;
      }
    });
    if (_ajustandoRecogida) {
      _mapController.move(
        _puntoRecogida ?? _miUbicacion,
        _mapController.zoom ?? 15.0,
      );
    }
  }

  void _onMapaMovido(MapPosition position, bool hasGesture) {
    if (!hasGesture || position.center == null) return;

    final nuevoPunto = position.center!;
    if (_ajustandoRecogida) {
      _pickupDebounce?.cancel();
      if (mounted && !_actualizandoRecogida) {
        setState(() => _actualizandoRecogida = true);
      }
      _pickupDebounce = Timer(const Duration(milliseconds: 700), () {
        _actualizarPuntoRecogida(nuevoPunto);
      });
    } else if (_seleccionandoDestinoMapa) {
      _destinoDebounce?.cancel();
      if (mounted && !_actualizandoDestinoMapa) {
        setState(() => _actualizandoDestinoMapa = true);
      }
      _destinoDebounce = Timer(const Duration(milliseconds: 700), () {
        _actualizarDestinoDesdeMapa(nuevoPunto);
      });
    }
  }

  Future<void> _actualizarPuntoRecogida(LatLng nuevoPunto) async {
    final nombre = await OsmService.obtenerNombreLugar(
      nuevoPunto.latitude,
      nuevoPunto.longitude,
    );
    if (!mounted) return;

    setState(() {
      _puntoRecogida = nuevoPunto;
      _origenNombre = nombre;
      _actualizandoRecogida = false;
      _ajustandoRecogida = false; // AUTO-APAGAR para bloquear ubicación
    });

    _cargarConductoresCercanos();

    if (_destino != null) {
      setState(() => _calculandoRuta = true);
      await _calcularRuta(_destino!, recenterMap: false);
    }
  }

  Future<void> _actualizarDestinoDesdeMapa(LatLng nuevoPunto) async {
    final nombre = await OsmService.obtenerNombreLugar(
      nuevoPunto.latitude,
      nuevoPunto.longitude,
    );
    if (!mounted) return;

    if (_agregandoParada) {
      // Si estamos añadiendo una parada, SOLO actualizamos el texto de búsqueda
      // para que el usuario pueda ver el nombre y confirmar.
      setState(() {
        _searchController.text = nombre;
        _actualizandoDestinoMapa = false;
        _searchFeedback = '';
        _mostrandoSugerencias = false;
        // NO auto-apagamos aquí porque el flujo de paradas requiere confirmación manual
      });
      return;
    }

    setState(() {
      _destino = nuevoPunto;
      _destinoNombre = nombre;
      _searchController.text = nombre;
      _mostrandoPanel = true;
      _calculandoRuta = true;
      _actualizandoDestinoMapa = false;

      // SOLO auto-apagamos si NO estamos añadiendo una parada
      if (!_agregandoParada) {
        _seleccionandoDestinoMapa = false;
      }

      _rutaInfo = null;
      _rutaPuntos = [];
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes = false;
    });

    await _calcularRuta(nuevoPunto, recenterMap: false);
  }

  @override
  Widget build(BuildContext context) {
    bool isViajando = _estadoViaje != EstadoViaje.ninguno;
    double minH = (_mostrandoPanel || isViajando) ? 24 : 0;

    // Altura dinámica: Menos espacio si solo hay un destino, más si hay paradas
    double maxH;
    if (isViajando) {
      maxH = MediaQuery.of(context).size.height *
          0.40; // Altura compacta para seguimiento
    } else if (_paradasIntermedias.isEmpty) {
      maxH = MediaQuery.of(context).size.height *
          0.52; // Altura ideal para 1 destino + tipos de vehículo
    } else {
      maxH = MediaQuery.of(context).size.height *
          0.72; // Altura para viajes con paradas
    }

    double currentPanelHeight = 0;

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
          children: [
            // ── MAPA ────────────────────────────────────────────────
            FlutterMap(
              mapController: _mapController,
              options: MapOptions(
                center: CUENCA_CENTER,
                zoom: 14.0,
                minZoom: 5.0,
                maxZoom: 18.0,
                onPositionChanged: _onMapaMovido,
              ),
              children: [
                TileLayer(
                  urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                  tileProvider: NetworkTileProvider(
                    headers: {
                      'User-Agent': 'SuperIA/1.0 (com.coac.super_ia)',
                    },
                  ),
                  additionalOptions: {
                    'attribution': '© OpenStreetMap contributors'
                  },
                ),
                if (_rutaPuntos.isNotEmpty)
                  PolylineLayer(polylines: [
                    Polyline(
                        points: _rutaPuntos,
                        strokeWidth: 4.0,
                        color: ConstantColors.primaryViolet),
                  ]),
                MarkerLayer(markers: [
                  Marker(
                    point: _miUbicacion,
                    width: 24,
                    height: 24,
                    child: Container(
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: ConstantColors.primaryBlue.withOpacity(0.22),
                        border: Border.all(color: Colors.white, width: 2),
                      ),
                      child: Center(
                        child: Container(
                          width: 10,
                          height: 10,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: ConstantColors.primaryBlue,
                          ),
                        ),
                      ),
                    ),
                  ),
                  Marker(
                    point: _puntoRecogida ?? _miUbicacion,
                    width: 54,
                    height: 54,
                    child: Container(
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: ConstantColors.primaryBlue.withOpacity(0.14),
                        border: Border.all(
                          color: ConstantColors.primaryBlue.withOpacity(0.45),
                          width: 2,
                        ),
                      ),
                      child: Icon(
                        Icons.my_location_rounded,
                        color: ConstantColors.primaryBlue,
                        size: 24,
                      ),
                    ),
                  ),
                  if (_destino != null)
                    Marker(
                      point: _destino!,
                      width: 40,
                      height: 50,
                      child: Icon(Icons.location_on,
                          color: ConstantColors.primaryViolet, size: 40),
                    ),
                  ..._paradasIntermedias
                      .asMap()
                      .entries
                      .map((entry) => Marker(
                            point: entry.value.ubicacion,
                            width: 40,
                            height: 40,
                            child: Container(
                              decoration: BoxDecoration(
                                color: Colors.orange.shade600,
                                shape: BoxShape.circle,
                                border:
                                    Border.all(color: Colors.white, width: 2),
                              ),
                              child: Center(
                                child: Text(
                                  '${entry.key + 1}',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 14,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                            ),
                          ))
                      .toList(),
                  // ── Marcador del conductor asignado (tiempo real) ──
                  if (_conductorUbicacion != null &&
                      (_estadoViaje == EstadoViaje.conductorAsignado ||
                          _estadoViaje == EstadoViaje.enCamino ||
                          _estadoViaje == EstadoViaje.enViaje))
                    Marker(
                      point: _conductorUbicacion!,
                      width: 58,
                      height: 72,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            width: 50,
                            height: 50,
                            decoration: BoxDecoration(
                              color: ConstantColors.primaryBlue,
                              shape: BoxShape.circle,
                              border: Border.all(color: Colors.white, width: 3),
                              boxShadow: [
                                BoxShadow(color: Colors.black45, blurRadius: 10)
                              ],
                            ),
                            child: const Icon(Icons.directions_car_rounded,
                                color: Colors.white, size: 26),
                          ),
                          Container(
                            width: 2,
                            height: 16,
                            color: ConstantColors.primaryBlue,
                          ),
                        ],
                      ),
                    ),

                  ..._conductoresCercanos
                      .map((conductor) => Marker(
                            point:
                                LatLng(conductor.latitud, conductor.longitud),
                            width: 52,
                            height: 52,
                            child: GestureDetector(
                              onTap: () => _mostrarInfoConductor(conductor),
                              child: Container(
                                decoration: BoxDecoration(
                                  color: conductor.estado == 'libre'
                                      ? Colors.green.withOpacity(0.9)
                                      : Colors.deepOrange.withOpacity(0.9),
                                  shape: BoxShape.circle,
                                  border:
                                      Border.all(color: Colors.white, width: 2),
                                  boxShadow: [
                                    BoxShadow(
                                      color: Colors.black.withOpacity(0.25),
                                      blurRadius: 8,
                                      offset: Offset(0, 4),
                                    ),
                                  ],
                                ),
                                child: Icon(
                                  Icons.directions_car_rounded,
                                  color: Colors.white,
                                  size: 24,
                                ),
                              ),
                            ),
                          ))
                      .toList(),
                ]),
              ],
            ),

            // ── BARRA SUPERIOR ───────────────────────────────────────
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              child: Container(
                padding: EdgeInsets.fromLTRB(16, 50, 16, 12),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      ConstantColors.backgroundDark,
                      ConstantColors.backgroundDark.withOpacity(0.0)
                    ],
                  ),
                ),
                child: Column(children: [
                  Row(children: [
                    GestureDetector(
                      onTap: _mostrarPerfilModal,
                      child: Container(
                        width: 38,
                        height: 38,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: LinearGradient(colors: [
                            ConstantColors.primaryViolet,
                            ConstantColors.primaryBlue
                          ]),
                        ),
                        child: Icon(Icons.person_rounded,
                            color: Colors.white, size: 20),
                      ),
                    ),
                    SizedBox(width: 10),
                    Expanded(
                        child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Hola, $_userName',
                            style: TextStyle(
                                color: ConstantColors.textWhite,
                                fontSize: 15,
                                fontWeight: FontWeight.w700)),
                        Text('¿Qué haremos hoy?',
                            style: TextStyle(
                                color: ConstantColors.textGrey, fontSize: 12)),
                      ],
                    )),
                    if (_cargandoUbicacion)
                      SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                              strokeWidth: 2,
                              valueColor: AlwaysStoppedAnimation<Color>(
                                  ConstantColors.primaryViolet))),
                  ]),
                  // (búsqueda/destino eliminados)
                  if (false) Container(
                      decoration: BoxDecoration(
                        color: ConstantColors.backgroundCard,
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(
                            color: ConstantColors.borderColor, width: 1.5),
                        boxShadow: [
                          BoxShadow(
                              color: Colors.black.withOpacity(0.3),
                              blurRadius: 10,
                              offset: Offset(0, 4))
                        ],
                      ),
                      child: Row(children: [
                        Padding(
                            padding: EdgeInsets.symmetric(horizontal: 14),
                            child: Icon(Icons.search_rounded,
                                color: ConstantColors.primaryViolet, size: 22)),
                        Expanded(
                            child: TextField(
                          controller: _searchController,
                          style: TextStyle(
                              color: ConstantColors.textWhite, fontSize: 15),
                          decoration: InputDecoration(
                            hintText: _agregandoParada
                                ? 'Busca una parada...'
                                : 'Busca tu destino...',
                            hintStyle: TextStyle(
                                color: ConstantColors.textSubtle, fontSize: 14),
                            border: InputBorder.none,
                            contentPadding: EdgeInsets.symmetric(vertical: 14),
                          ),
                          onTap: () {
                            if (_searchController.text.isEmpty &&
                                _recientes.isNotEmpty) {
                              setState(() => _mostrandoRecientes = true);
                            }
                          },
                          onChanged: _onSearchChanged,
                        )),
                        if (_searchController.text.isNotEmpty)
                          GestureDetector(
                            onTap: _limpiarDestino,
                            child: Padding(
                                padding: EdgeInsets.symmetric(horizontal: 12),
                                child: Icon(Icons.close_rounded,
                                    color: ConstantColors.textGrey, size: 20)),
                          ),
                        if (_estadoViaje == EstadoViaje.ninguno)
                          GestureDetector(
                            onTap: _toggleSeleccionDestinoMapa,
                            child: Container(
                              margin: EdgeInsets.only(right: 10),
                              padding: EdgeInsets.all(8),
                              decoration: BoxDecoration(
                                color: _seleccionandoDestinoMapa
                                    ? Colors.orangeAccent.withOpacity(0.18)
                                    : Colors.transparent,
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: Icon(
                                Icons.place_rounded,
                                color: _seleccionandoDestinoMapa
                                    ? Colors.orangeAccent
                                    : ConstantColors.textGrey,
                                size: 20,
                              ),
                            ),
                          ),
                      ]),
                    ),
                    // ── Botones favoritos rápidos ───────────────────────
                    if (false && _favoritos.isNotEmpty &&
                        _estadoViaje == EstadoViaje.ninguno &&
                        !_mostrandoSugerencias &&
                        !_buscando &&
                        _searchController.text.isEmpty) ...[
                      SizedBox(height: 8),
                      Row(
                        children: _favoritos.map((fav) {
                          return Expanded(
                            child: GestureDetector(
                              onTap: () => _seleccionarFavorito(fav),
                              child: Container(
                                margin: EdgeInsets.only(
                                    right: _favoritos.last == fav ? 0 : 8),
                                padding: EdgeInsets.symmetric(
                                    vertical: 9, horizontal: 10),
                                decoration: BoxDecoration(
                                  color: ConstantColors.backgroundCard,
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(
                                      color: ConstantColors.borderColor),
                                ),
                                child: Row(children: [
                                  Text(fav.icono,
                                      style: TextStyle(fontSize: 16)),
                                  SizedBox(width: 6),
                                  Expanded(
                                      child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(fav.nombre,
                                          style: TextStyle(
                                              color: ConstantColors.textWhite,
                                              fontSize: 12,
                                              fontWeight: FontWeight.w700)),
                                      Text(fav.direccion,
                                          style: TextStyle(
                                              color: ConstantColors.textSubtle,
                                              fontSize: 10),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis),
                                    ],
                                  )),
                                ]),
                              ),
                            ),
                          );
                        }).toList(),
                      ),
                    ],

                    // ── Recientes (cuando el campo está vacío y enfocado) ───
                    if (_mostrandoRecientes &&
                        _recientes.isNotEmpty &&
                        _estadoViaje == EstadoViaje.ninguno)
                      Container(
                        margin: EdgeInsets.only(top: 4),
                        decoration: BoxDecoration(
                          color: ConstantColors.backgroundCard,
                          borderRadius: BorderRadius.circular(12),
                          border:
                              Border.all(color: ConstantColors.borderColor),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Padding(
                              padding: EdgeInsets.fromLTRB(14, 10, 14, 4),
                              child: Text('Recientes',
                                  style: TextStyle(
                                      color: ConstantColors.textSubtle,
                                      fontSize: 11,
                                      fontWeight: FontWeight.w600)),
                            ),
                            ..._recientes
                                .take(5)
                                .map((rec) => GestureDetector(
                                      onTap: () => _seleccionarReciente(rec),
                                      child: Container(
                                        padding: EdgeInsets.symmetric(
                                            horizontal: 14, vertical: 10),
                                        decoration: BoxDecoration(
                                            border: Border(
                                                top: BorderSide(
                                                    color: ConstantColors
                                                        .dividerColor))),
                                        child: Row(children: [
                                          Icon(Icons.history_rounded,
                                              color: ConstantColors.textSubtle,
                                              size: 16),
                                          SizedBox(width: 10),
                                          Expanded(
                                              child: Text(rec.nombre,
                                                  style: TextStyle(
                                                      color: ConstantColors
                                                          .textWhite,
                                                      fontSize: 13),
                                                  maxLines: 1,
                                                  overflow:
                                                      TextOverflow.ellipsis)),
                                        ]),
                                      ),
                                    ))
                                .toList(),
                          ],
                        ),
                      ),

                    // Sugerencias
                    if (_mostrandoSugerencias ||
                        _buscando ||
                        _searchFeedback.isNotEmpty)
                      Container(
                        margin: EdgeInsets.only(top: 4),
                        decoration: BoxDecoration(
                          color: ConstantColors.backgroundCard,
                          borderRadius: BorderRadius.circular(12),
                          border:
                              Border.all(color: ConstantColors.borderColor),
                        ),
                        child: _buscando
                            ? Padding(
                                padding: EdgeInsets.symmetric(vertical: 14),
                                child: Center(
                                    child: SizedBox(
                                        width: 20,
                                        height: 20,
                                        child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                            valueColor: AlwaysStoppedAnimation<
                                                    Color>(
                                                ConstantColors
                                                    .primaryViolet)))))
                            : _mostrandoSugerencias
                                ? Column(
                                    children: _sugerencias
                                        .map((lugar) => GestureDetector(
                                              onTap: () =>
                                                  _seleccionarDestino(lugar),
                                              child: Container(
                                                padding: EdgeInsets.symmetric(
                                                    horizontal: 16,
                                                    vertical: 12),
                                                decoration: BoxDecoration(
                                                    border: Border(
                                                  bottom: BorderSide(
                                                      color: _sugerencias
                                                                  .last ==
                                                              lugar
                                                          ? Colors.transparent
                                                          : ConstantColors
                                                              .dividerColor),
                                                )),
                                                child: Row(children: [
                                                  Icon(Icons.place_rounded,
                                                      color: ConstantColors
                                                          .primaryViolet,
                                                      size: 18),
                                                  SizedBox(width: 10),
                                                  Expanded(
                                                      child: Text(
                                                          lugar.shortName,
                                                          style: TextStyle(
                                                              color:
                                                                  ConstantColors
                                                                      .textWhite,
                                                              fontSize: 14),
                                                          maxLines: 1,
                                                          overflow: TextOverflow
                                                              .ellipsis)),
                                                ]),
                                              ),
                                            ))
                                        .toList())
                                : Padding(
                                    padding: EdgeInsets.symmetric(
                                        horizontal: 16, vertical: 12),
                                    child: Row(children: [
                                      Icon(Icons.info_outline_rounded,
                                          color: ConstantColors.textGrey,
                                          size: 16),
                                      SizedBox(width: 8),
                                      Expanded(
                                          child: Text(_searchFeedback,
                                              style: TextStyle(
                                                  color: ConstantColors
                                                      .textGrey,
                                                  fontSize: 13))),
                                    ])),
                      ),
                  
                ]),
              ),
            ),

            // ── BOTÓN CERRAR SESIÓN (izquierda inferior) ─────────────
            Positioned(
              left: 16,
              bottom: currentPanelHeight + 16,
              child: GestureDetector(
                onTap: _cerrarSesion,
                child: Container(
                  height: 48,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  decoration: BoxDecoration(
                    color: ConstantColors.backgroundCard,
                    borderRadius: BorderRadius.circular(24),
                    border: Border.all(
                        color: Colors.redAccent.withOpacity(0.6), width: 1.5),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(0.3),
                        blurRadius: 8,
                        offset: const Offset(0, 3),
                      ),
                    ],
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.logout_rounded,
                          color: Colors.redAccent, size: 20),
                      const SizedBox(width: 8),
                      Text(
                        'Cerrar sesión',
                        style: TextStyle(
                          color: Colors.redAccent,
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),

            // ── BOTÓN MI UBICACIÓN ──────────────────────────────────
            Positioned(
              right: 16,
              bottom: currentPanelHeight + 16,
              child: GestureDetector(
                onTap: _centrarEnMiUbicacion,
                child: Container(
                  width: 48,
                  height: 48,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: ConstantColors.backgroundCard,
                    border: Border.all(color: ConstantColors.borderColor),
                    boxShadow: [
                      BoxShadow(
                          color: Colors.black.withOpacity(0.3), blurRadius: 8)
                    ],
                  ),
                  child: Icon(Icons.my_location_rounded,
                      color: ConstantColors.primaryViolet, size: 22),
                ),
              ),
            ),

            if (_estadoViaje == EstadoViaje.ninguno)
              Positioned(
                right: 16,
                bottom: currentPanelHeight + 72,
                child: GestureDetector(
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) =>
                          const NuevaEncuestaScreen(tipoTarea: 'recuperacion'),
                    ),
                  ),
                  child: Container(
                    padding: EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    decoration: BoxDecoration(
                      color: ConstantColors.backgroundCard,
                      borderRadius: BorderRadius.circular(24),
                      border: Border.all(color: ConstantColors.borderColor),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withOpacity(0.25),
                          blurRadius: 8,
                          offset: Offset(0, 4),
                        ),
                      ],
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.loop_rounded,
                            color: ConstantColors.warning, size: 18),
                        SizedBox(width: 8),
                        Text(
                          'Agenda de recuperación',
                          style: TextStyle(
                              color: Colors.white,
                              fontSize: 12,
                              fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  ),
                ),
              ),

            if (_estadoViaje == EstadoViaje.ninguno)
              Positioned(
                right: 16,
                bottom: currentPanelHeight + 128,
                child: GestureDetector(
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => const NuevaEncuestaScreen(
                          tipoTarea: 'prospecto_nuevo'),
                    ),
                  ),
                  child: Container(
                    padding: EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: [
                          ConstantColors.warning,
                          ConstantColors.primaryBlue
                        ],
                      ),
                      borderRadius: BorderRadius.circular(24),
                      boxShadow: [
                        BoxShadow(
                          color: ConstantColors.warning.withOpacity(0.35),
                          blurRadius: 10,
                          offset: Offset(0, 4),
                        ),
                      ],
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.assignment_rounded,
                            color: Colors.white, size: 18),
                        SizedBox(width: 8),
                        Text(
                          'Agenda de tareas',
                          style: TextStyle(
                              color: Colors.white,
                              fontSize: 12,
                              fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  ),
                ),
              ),

            // (Añadir parada eliminado)

            // ── BOTÓN SHARE TRIP (Durante viaje activo) ───────────────
            if (_estadoViaje == EstadoViaje.conductorAsignado ||
                _estadoViaje == EstadoViaje.enCamino ||
                _estadoViaje == EstadoViaje.enViaje)
              Positioned(
                right: 16,
                bottom: 512,
                child: GestureDetector(
                  onTap: _compartirViaje,
                  child: AnimatedContainer(
                    duration: Duration(milliseconds: 200),
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: Colors.blue.shade600,
                      boxShadow: [
                        BoxShadow(
                          color: Colors.blue.withOpacity(0.5),
                          blurRadius: 12,
                          spreadRadius: 2,
                        ),
                      ],
                    ),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.share_rounded,
                            color: Colors.white, size: 24),
                        SizedBox(height: 2),
                        Text('SHARE',
                            style: TextStyle(
                                color: Colors.white,
                                fontSize: 7,
                                fontWeight: FontWeight.bold)),
                      ],
                    ),
                  ),
                ),
              ),

            // ── BOTÓN CHAT (Durante viaje activo) ────────────────────
            if ((_estadoViaje == EstadoViaje.conductorAsignado ||
                    _estadoViaje == EstadoViaje.enCamino ||
                    _estadoViaje == EstadoViaje.enViaje) &&
                _conductorData != null)
              Positioned(
                right: 16,
                bottom: 446,
                child: GestureDetector(
                  onTap: () {
                    Navigator.pushNamed(
                      context,
                      ChatScreen.route,
                      arguments: {
                        'viaje_id': _viajeId ?? 0,
                        'remitente': 'pasajero',
                        'nombre_otro': _conductorData?['nombre'] ?? 'Conductor',
                        'telefono_otro': _conductorData?['telefono'] ?? '',
                      },
                    );
                  },
                  child: Container(
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: ConstantColors.primaryViolet,
                      boxShadow: [
                        BoxShadow(
                          color: ConstantColors.primaryViolet.withOpacity(0.5),
                          blurRadius: 12,
                          spreadRadius: 2,
                        ),
                      ],
                    ),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(Icons.chat_rounded,
                            color: Colors.white, size: 22),
                        const SizedBox(height: 2),
                        const Text('CHAT',
                            style: TextStyle(
                                color: Colors.white,
                                fontSize: 8,
                                fontWeight: FontWeight.bold)),
                      ],
                    ),
                  ),
                ),
              ),

            // ── BOTÓN SOS (Durante viaje activo) ─────────────────────
            if (_estadoViaje == EstadoViaje.conductorAsignado ||
                _estadoViaje == EstadoViaje.enCamino ||
                _estadoViaje == EstadoViaje.enViaje)
              Positioned(
                right: 16,
                bottom: 380,
                child: GestureDetector(
                  onTap: _sosConCuentaRegresiva, // tap = cuenta regresiva 5s
                  onLongPress: _enviarSOS, // long press = envío inmediato
                  child: AnimatedContainer(
                    duration: Duration(milliseconds: 200),
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: Colors.red.shade600,
                      boxShadow: [
                        BoxShadow(
                          color: Colors.red.withOpacity(0.5),
                          blurRadius: 12,
                          spreadRadius: 2,
                        ),
                      ],
                    ),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.warning, color: Colors.white, size: 24),
                        SizedBox(height: 2),
                        Text('SOS',
                            style: TextStyle(
                                color: Colors.white,
                                fontSize: 8,
                                fontWeight: FontWeight.bold)),
                      ],
                    ),
                  ),
                ),
              ),

            if (_ajustandoRecogida ||
                _seleccionandoDestinoMapa ||
                _agregandoParada)
              IgnorePointer(
                child: Center(
                  child: Transform.translate(
                    offset: const Offset(0, -24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 6),
                          decoration: BoxDecoration(
                            color:
                                ConstantColors.backgroundCard.withOpacity(0.95),
                            borderRadius: BorderRadius.circular(20),
                            border:
                                Border.all(color: ConstantColors.borderColor),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              if (_actualizandoRecogida ||
                                  _actualizandoDestinoMapa)
                                SizedBox(
                                  width: 12,
                                  height: 12,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    valueColor: AlwaysStoppedAnimation<Color>(
                                      ConstantColors.primaryViolet,
                                    ),
                                  ),
                                )
                              else
                                Icon(
                                  Icons.open_with_rounded,
                                  color: ConstantColors.primaryViolet,
                                  size: 14,
                                ),
                              const SizedBox(width: 8),
                              Text(
                                _ajustandoRecogida
                                    ? (_actualizandoRecogida
                                        ? 'Actualizando recogida...'
                                        : 'Mueve el mapa y pulsa "LISTO"')
                                    : (_actualizandoDestinoMapa
                                        ? 'Actualizando destino...'
                                        : 'Mueve el mapa y pulsa "CONFIRMAR"'),
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 11,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 8),
                        Icon(
                          _ajustandoRecogida
                              ? Icons.location_on_rounded
                              : (_agregandoParada
                                  ? Icons.add_location_alt_rounded
                                  : Icons.place_rounded),
                          color: _ajustandoRecogida
                              ? ConstantColors.primaryViolet
                              : (_agregandoParada
                                  ? Colors.deepOrange
                                  : Colors.orangeAccent),
                          size: 42,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        ),
    );
  }

  Widget _buildPanelContent() {
    if (_estadoViaje == EstadoViaje.buscando) {
      return _buildBuscandoPanel();
    }
    if ((_estadoViaje == EstadoViaje.conductorAsignado ||
            _estadoViaje == EstadoViaje.enCamino ||
            _estadoViaje == EstadoViaje.enViaje) &&
        _conductorData != null) {
      return _buildEstadoViajePanel();
    }
    if (_mostrandoPanel && _estadoViaje == EstadoViaje.ninguno) {
      return _buildRutaPanel();
    }
    return const SizedBox.shrink();
  }

  Widget _buildRutaPanel() {
    return SingleChildScrollView(
      physics: const ClampingScrollPhysics(),
      child: Container(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 40),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                    color: ConstantColors.borderColor,
                    borderRadius: BorderRadius.circular(2))),
            const SizedBox(height: 16),
            Row(children: [
              Column(children: [
                Icon(Icons.circle, size: 10, color: ConstantColors.primaryBlue),
                Container(
                    width: 2, height: 24, color: ConstantColors.borderColor),
                Icon(Icons.location_on,
                    size: 16, color: ConstantColors.primaryViolet),
              ]),
              const SizedBox(width: 12),
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    Text(_origenNombre,
                        style: TextStyle(
                            color: ConstantColors.textGrey, fontSize: 13),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 14),
                    Text(_destinoNombre,
                        style: TextStyle(
                            color: ConstantColors.textWhite,
                            fontSize: 14,
                            fontWeight: FontWeight.w600),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis),
                  ])),
            ]),
            if (_paradasIntermedias.isNotEmpty) ...[
              const SizedBox(height: 14),
              ..._paradasIntermedias
                  .asMap()
                  .entries
                  .map((entry) => Container(
                        margin: const EdgeInsets.only(bottom: 8),
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 10),
                        decoration: BoxDecoration(
                            color: ConstantColors.backgroundLight,
                            borderRadius: BorderRadius.circular(12),
                            border:
                                Border.all(color: ConstantColors.borderColor)),
                        child: Row(children: [
                          CircleAvatar(
                              radius: 11,
                              backgroundColor: Colors.orange.withOpacity(0.16),
                              child: Text('${entry.key + 1}',
                                  style: TextStyle(
                                      color: Colors.orange.shade300,
                                      fontSize: 11,
                                      fontWeight: FontWeight.bold))),
                          const SizedBox(width: 10),
                          Expanded(
                              child: Text(entry.value.nombre,
                                  style: TextStyle(
                                      color: ConstantColors.textWhite,
                                      fontSize: 13),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis)),
                          GestureDetector(
                              onTap: () => _eliminarParada(entry.key),
                              child: Icon(Icons.close_rounded,
                                  color: ConstantColors.textGrey, size: 18)),
                        ]),
                      ))
                  .toList(),
            ],
            if (!_ajustandoRecogida &&
                !_seleccionandoDestinoMapa &&
                !_agregandoParada) ...[
              const SizedBox(height: 16),
              Divider(color: ConstantColors.dividerColor),
              const SizedBox(height: 12),
              if (_categorias.isNotEmpty) ...[
                Align(
                    alignment: Alignment.centerLeft,
                    child: Text('Tipo de vehículo',
                        style: TextStyle(
                            color: ConstantColors.textGrey,
                            fontSize: 12,
                            fontWeight: FontWeight.w500))),
                const SizedBox(height: 10),
                SizedBox(
                  height: 80,
                  child: ListView.builder(
                    scrollDirection: Axis.horizontal,
                    itemCount: _categorias.length,
                    itemBuilder: (_, i) {
                      final cat = _categorias[i];
                      final isSelected = _categoriaSeleccionada?.id == cat.id;
                      return GestureDetector(
                        onTap: () {
                          setState(() => _categoriaSeleccionada = cat);
                          _recalcularPrecio();
                        },
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 200),
                          margin: const EdgeInsets.only(right: 10),
                          padding: const EdgeInsets.symmetric(
                              horizontal: 16, vertical: 10),
                          decoration: BoxDecoration(
                            color: isSelected
                                ? ConstantColors.primaryViolet.withOpacity(0.15)
                                : ConstantColors.backgroundLight,
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(
                                color: isSelected
                                    ? ConstantColors.primaryViolet
                                    : ConstantColors.borderColor,
                                width: isSelected ? 2 : 1),
                          ),
                          child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Text(cat.icono,
                                    style: const TextStyle(fontSize: 22)),
                                const SizedBox(height: 4),
                                Text(cat.nombre,
                                    style: TextStyle(
                                        color: isSelected
                                            ? ConstantColors.primaryViolet
                                            : ConstantColors.textWhite,
                                        fontSize: 12,
                                        fontWeight: FontWeight.w600)),
                              ]),
                        ),
                      );
                    },
                  ),
                ),
                const SizedBox(height: 12),
                Divider(color: ConstantColors.dividerColor),
              ],
              const SizedBox(height: 12),
              _calculandoRuta
                  ? const Center(child: CircularProgressIndicator())
                  : Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                          _buildInfoChip(
                              Icons.straighten_rounded,
                              '${_rutaInfo?.distanciaKm.toStringAsFixed(1)} km',
                              'Distancia'),
                          _buildInfoChip(Icons.access_time_rounded,
                              '${_rutaInfo?.duracionMin} min', 'Tiempo'),
                          _buildInfoChip(
                              Icons.attach_money_rounded,
                              '\$${_precioCalculado.toStringAsFixed(2)}',
                              'Precio',
                              highlight: true),
                        ]),
              const SizedBox(height: 20),
              GestureDetector(
                onTap: _calculandoRuta ? null : _mostrarConfirmacion,
                child: Container(
                  width: double.infinity,
                  height: 56,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(colors: [
                      ConstantColors.primaryViolet,
                      ConstantColors.primaryBlue
                    ]),
                    borderRadius: BorderRadius.circular(16),
                    boxShadow: [
                      BoxShadow(
                          color: ConstantColors.primaryViolet.withOpacity(0.4),
                          blurRadius: 16,
                          offset: const Offset(0, 6))
                    ],
                  ),
                  child: Center(
                      child: Text(
                          _calculandoRuta ? 'Calculando...' : 'Pedir viaje',
                          style: const TextStyle(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.w700))),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildBuscandoPanel() {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 40),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
                color: ConstantColors.borderColor,
                borderRadius: BorderRadius.circular(2))),
        const SizedBox(height: 28),
        Stack(alignment: Alignment.center, children: [
          Container(
              width: 80,
              height: 80,
              decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: ConstantColors.primaryViolet.withOpacity(0.12))),
          Container(
              width: 56,
              height: 56,
              decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: ConstantColors.primaryViolet.withOpacity(0.2))),
          SizedBox(
              width: 36,
              height: 36,
              child: CircularProgressIndicator(
                  strokeWidth: 3,
                  valueColor: AlwaysStoppedAnimation<Color>(
                      ConstantColors.primaryViolet))),
        ]),
        const SizedBox(height: 20),
        Text('Buscando conductor...',
            style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 18,
                fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        Text('Estamos encontrando el mejor conductor cerca de ti',
            style: TextStyle(
                color: ConstantColors.textGrey, fontSize: 13, height: 1.4),
            textAlign: TextAlign.center),
        const SizedBox(height: 16),
        if (_categoriaSeleccionada != null)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            decoration: BoxDecoration(
              color: ConstantColors.primaryViolet.withOpacity(0.1),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                  color: ConstantColors.primaryViolet.withOpacity(0.3)),
            ),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Text(_categoriaSeleccionada!.icono,
                  style: const TextStyle(fontSize: 16)),
              const SizedBox(width: 6),
              Text(_categoriaSeleccionada!.nombre,
                  style: TextStyle(
                      color: ConstantColors.primaryViolet,
                      fontSize: 13,
                      fontWeight: FontWeight.w600)),
              const SizedBox(width: 8),
              Text('· \$${_precioCalculado.toStringAsFixed(2)}',
                  style:
                      TextStyle(color: ConstantColors.textGrey, fontSize: 13)),
            ]),
          ),
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: BoxDecoration(
              color: ConstantColors.backgroundLight,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: ConstantColors.borderColor)),
          child: Row(children: [
            Icon(Icons.circle, size: 8, color: ConstantColors.primaryBlue),
            const SizedBox(width: 8),
            Expanded(
                child: Text(_origenNombre,
                    style:
                        TextStyle(color: ConstantColors.textGrey, fontSize: 12),
                    overflow: TextOverflow.ellipsis)),
            Icon(Icons.arrow_forward_rounded,
                size: 14, color: ConstantColors.textGrey),
            const SizedBox(width: 8),
            Icon(Icons.location_on,
                size: 14, color: ConstantColors.primaryViolet),
            const SizedBox(width: 4),
            Expanded(
                child: Text(_destinoNombre,
                    style: TextStyle(
                        color: ConstantColors.textWhite,
                        fontSize: 12,
                        fontWeight: FontWeight.w600),
                    overflow: TextOverflow.ellipsis)),
          ]),
        ),
        const SizedBox(height: 20),
        GestureDetector(
          onTap: _cancelarViaje,
          child: Container(
            width: double.infinity,
            height: 52,
            decoration: BoxDecoration(
                color: Colors.red.withOpacity(0.1),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: Colors.redAccent.withOpacity(0.4))),
            child: const Center(
                child: Text('Cancelar búsqueda',
                    style: TextStyle(
                        color: Colors.redAccent,
                        fontSize: 15,
                        fontWeight: FontWeight.w600))),
          ),
        ),
      ]),
    );
  }

  Widget _buildEstadoViajePanel() {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 40),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
                color: ConstantColors.borderColor,
                borderRadius: BorderRadius.circular(2))),
        const SizedBox(height: 16),
        Row(children: [
          Icon(
            _estadoViaje == EstadoViaje.enViaje
                ? Icons.directions_car_filled_rounded
                : Icons.check_circle_rounded,
            color: _estadoViaje == EstadoViaje.enViaje
                ? Colors.orangeAccent
                : Colors.greenAccent,
            size: 20,
          ),
          const SizedBox(width: 8),
          Text(
            _estadoViaje == EstadoViaje.enViaje
                ? '¡Estás en viaje!'
                : _estadoViaje == EstadoViaje.enCamino
                    ? '¡Tu conductor está en camino!'
                    : '¡Conductor asignado!',
            style: TextStyle(
              color: _estadoViaje == EstadoViaje.enViaje
                  ? Colors.orangeAccent
                  : Colors.greenAccent,
              fontSize: 15,
              fontWeight: FontWeight.w700,
            ),
          ),
          const Spacer(),
          if (_estadoViaje != EstadoViaje.enViaje)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
              decoration: BoxDecoration(
                  color: ConstantColors.primaryViolet.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(8)),
              child: Text('${_conductorData!['eta_min']} min',
                  style: TextStyle(
                      color: ConstantColors.primaryViolet,
                      fontWeight: FontWeight.w700,
                      fontSize: 13)),
            ),
        ]),
        const SizedBox(height: 14),
        Divider(color: ConstantColors.dividerColor),
        const SizedBox(height: 14),
        Row(children: [
          GestureDetector(
            onTap: _mostrarPerfilConductor,
            child: Stack(alignment: Alignment.bottomRight, children: [
              CircleAvatar(
                radius: 30,
                backgroundColor: ConstantColors.primaryViolet.withOpacity(0.2),
                child: Text(_conductorData!['inicial'],
                    style: TextStyle(
                        color: ConstantColors.primaryViolet,
                        fontSize: 24,
                        fontWeight: FontWeight.bold)),
              ),
              Container(
                width: 18,
                height: 18,
                decoration: BoxDecoration(
                    color: ConstantColors.primaryViolet,
                    shape: BoxShape.circle,
                    border: Border.all(
                        color: ConstantColors.backgroundCard, width: 1.5)),
                child: const Icon(Icons.info_outline_rounded,
                    color: Colors.white, size: 11),
              ),
            ]),
          ),
          const SizedBox(width: 14),
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                Text(_conductorData!['nombre'],
                    style: TextStyle(
                        color: ConstantColors.textWhite,
                        fontSize: 16,
                        fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                Row(children: [
                  const Icon(Icons.star_rounded, color: Colors.amber, size: 16),
                  const SizedBox(width: 4),
                  Text(
                      '${_conductorData!['calificacion']}  ·  ${_conductorData!['viajes']} viajes',
                      style: TextStyle(
                          color: ConstantColors.textGrey, fontSize: 12)),
                ]),
              ])),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(_conductorData!['auto'],
                style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 13,
                    fontWeight: FontWeight.w600)),
            const SizedBox(height: 2),
            Text('${_conductorData!['color']} · ${_conductorData!['placa']}',
                style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          ]),
        ]),
        if (_estadoViaje != EstadoViaje.enViaje) ...[
          const SizedBox(height: 20),
          GestureDetector(
            onTap: _cancelarViaje,
            child: Container(
              width: double.infinity,
              height: 48,
              decoration: BoxDecoration(
                  color: Colors.red.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: Colors.redAccent.withOpacity(0.4))),
              child: const Center(
                  child: Text('Cancelar viaje',
                      style: TextStyle(
                          color: Colors.redAccent,
                          fontSize: 14,
                          fontWeight: FontWeight.w600))),
            ),
          ),
        ],
      ]),
    );
  }

  Widget _buildInfoChip(IconData icon, String value, String label,
      {bool highlight = false}) {
    return Column(children: [
      Icon(icon,
          color: highlight
              ? ConstantColors.primaryViolet
              : ConstantColors.textGrey,
          size: 20),
      const SizedBox(height: 4),
      Text(value,
          style: TextStyle(
              color: highlight
                  ? ConstantColors.primaryViolet
                  : ConstantColors.textWhite,
              fontSize: 15,
              fontWeight: FontWeight.w700)),
      Text(label,
          style: TextStyle(color: ConstantColors.textSubtle, fontSize: 11)),
    ]);
  }
}

// ────────────────────────────────────────────────────────────────────────────
// WIDGET: Pantalla de confirmación (bottom sheet)
// ────────────────────────────────────────────────────────────────────────────
class _ConfirmacionSheet extends StatefulWidget {
  final String origen;
  final String destino;
  final CategoriaModel categoria;
  final double distanciaKm;
  final int duracionMin;
  final double precio;
  final VoidCallback onConfirmar;
  final Function(String, double, double)
      onCuponAplicado; // (codigo, descuento, precioFinal)

  const _ConfirmacionSheet({
    Key? key,
    required this.origen,
    required this.destino,
    required this.categoria,
    required this.distanciaKm,
    required this.duracionMin,
    required this.precio,
    required this.onConfirmar,
    required this.onCuponAplicado,
  }) : super(key: key);

  @override
  _ConfirmacionSheetState createState() => _ConfirmacionSheetState();
}

class _ConfirmacionSheetState extends State<_ConfirmacionSheet> {
  TextEditingController _codigoController = TextEditingController();
  bool _validandoCodigo = false;
  String _codigoActual = '';
  double _descuentoActual = 0.0;
  String _mensajeError = '';

  @override
  void initState() {
    super.initState();
    _cargarCuponActual();
  }

  @override
  void dispose() {
    _codigoController.dispose();
    super.dispose();
  }

  // Cargar cupón que pudo haber sido guardado anteriormente
  Future<void> _cargarCuponActual() async {
    try {
      final cupom = await DiscountPreferences.obtenerCuponActual();
      final descuento = await DiscountPreferences.obtenerDescuentoActual();
      if (mounted) {
        setState(() {
          _codigoActual = cupom;
          _descuentoActual = descuento;
          if (cupom.isNotEmpty) {
            _codigoController.text = cupom;
          }
        });
      }
    } catch (e) {
      print('>>> [DISCOUNT] Error al cargar cupón: $e');
    }
  }

  // Validar código de descuento
  Future<void> _validarCodigo() async {
    final codigo = _codigoController.text.trim();

    if (codigo.isEmpty) {
      setState(() => _mensajeError = 'Ingresa un código');
      return;
    }

    setState(() {
      _validandoCodigo = true;
      _mensajeError = '';
    });

    try {
      // Importar DiscountCodeService
      final discountCode = await DiscountCodeService.validarCodigo(codigo);

      // Verificar que cumple el monto mínimo
      if (!DiscountCodeService.cumpleMinimoViaje(discountCode, widget.precio)) {
        setState(() {
          _mensajeError = DiscountCodeService.obtenerMensajeMinimo(
              discountCode, widget.precio);
          _validandoCodigo = false;
        });
        return;
      }

      // Calcular descuento
      final descuento = DiscountCodeService.obtenerMontoDescuento(
          widget.precio, discountCode);

      // Guardar cupón actual
      await DiscountPreferences.guardarCuponActual(codigo);
      await DiscountPreferences.guardarDescuentoActual(descuento);

      // Calcular precio final
      final precioFinal = widget.precio - descuento;

      if (mounted) {
        setState(() {
          _codigoActual = codigo;
          _descuentoActual = descuento;
          _mensajeError = '';
          _validandoCodigo = false;
        });

        // Notificar al padre que el cupón fue aplicado
        if (widget.onCuponAplicado != null) {
          widget.onCuponAplicado(codigo, descuento, precioFinal);
        }
      }

      print(
          '>>> [DISCOUNT] Cupón válido: $codigo, Descuento: \$${descuento.toStringAsFixed(2)}, Precio final: \$${precioFinal.toStringAsFixed(2)}');
    } catch (e) {
      if (mounted) {
        setState(() {
          _mensajeError = e.toString().replaceAll('Exception: ', '');
          _validandoCodigo = false;
        });
      }
      print('>>> [DISCOUNT] Error al validar: $e');
    }
  }

  // Remover cupón
  void _removerCupon() {
    setState(() {
      _codigoActual = '';
      _descuentoActual = 0.0;
      _codigoController.clear();
      _mensajeError = '';
    });
    DiscountPreferences.limpiarCuponActual();
  }

  // Calcular precio final
  double _calcularPrecioFinal() {
    return widget.precio - _descuentoActual;
  }

  // Confirmar viaje guardando el cupón
  Future<void> _confirmarConCupon() async {
    Navigator.of(context).pop(); // Cerrar el modal
    widget.onConfirmar();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(20, 20, 20, 40),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        // Handle
        Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
                color: ConstantColors.borderColor,
                borderRadius: BorderRadius.circular(2))),
        SizedBox(height: 20),

        // Título
        Text('Confirmar viaje',
            style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 20,
                fontWeight: FontWeight.w800)),
        SizedBox(height: 20),

        // Ruta
        Container(
          padding: EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: ConstantColors.backgroundLight,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: ConstantColors.borderColor),
          ),
          child: Row(children: [
            Column(children: [
              Icon(Icons.circle, size: 10, color: ConstantColors.primaryBlue),
              Container(
                  width: 2, height: 28, color: ConstantColors.borderColor),
              Icon(Icons.location_on,
                  size: 16, color: ConstantColors.primaryViolet),
            ]),
            SizedBox(width: 12),
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text(widget.origen,
                      style: TextStyle(
                          color: ConstantColors.textGrey, fontSize: 13),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
                  SizedBox(height: 18),
                  Text(widget.destino,
                      style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 14,
                          fontWeight: FontWeight.w600),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
                ])),
          ]),
        ),

        SizedBox(height: 16),

        // Categoría + precio + detalles
        Container(
          padding: EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: ConstantColors.backgroundLight,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: ConstantColors.borderColor),
          ),
          child: Row(children: [
            // Ícono vehículo
            Container(
              width: 50,
              height: 50,
              decoration: BoxDecoration(
                color: ConstantColors.primaryViolet.withOpacity(0.1),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Center(
                  child: Text(widget.categoria.icono,
                      style: TextStyle(fontSize: 24))),
            ),
            SizedBox(width: 14),
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text(widget.categoria.nombre,
                      style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 15,
                          fontWeight: FontWeight.w700)),
                  SizedBox(height: 4),
                  Text(
                      '${widget.distanciaKm.toStringAsFixed(1)} km  ·  $widget.duracionMin min',
                      style: TextStyle(
                          color: ConstantColors.textGrey, fontSize: 12)),
                ])),
            // Precio destacado
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text('\$${widget.precio.toStringAsFixed(2)}',
                  style: TextStyle(
                      color: ConstantColors.primaryViolet,
                      fontSize: 22,
                      fontWeight: FontWeight.w800)),
              Text('Estimado',
                  style: TextStyle(
                      color: ConstantColors.textSubtle, fontSize: 11)),
            ]),
          ]),
        ),

        SizedBox(height: 8),
        // Desglose precio
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 4),
          child:
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Tarifa base',
                style:
                    TextStyle(color: ConstantColors.textSubtle, fontSize: 12)),
            Text('\$${widget.categoria.tarifaBase.toStringAsFixed(2)}',
                style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          ]),
        ),
        SizedBox(height: 4),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 4),
          child:
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Por distancia (${widget.distanciaKm.toStringAsFixed(1)} km)',
                style:
                    TextStyle(color: ConstantColors.textSubtle, fontSize: 12)),
            Text(
                '\$${(widget.categoria.precioKm * widget.distanciaKm).toStringAsFixed(2)}',
                style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          ]),
        ),
        SizedBox(height: 4),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 4),
          child:
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Por tiempo ($widget.duracionMin min)',
                style:
                    TextStyle(color: ConstantColors.textSubtle, fontSize: 12)),
            Text(
                '\$${(widget.categoria.precioMinuto * widget.duracionMin).toStringAsFixed(2)}',
                style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          ]),
        ),

        SizedBox(height: 16),
        Divider(color: ConstantColors.dividerColor, height: 1),

        // ── SECCIÓN DE CUPONES ─────────────────────────
        SizedBox(height: 16),
        if (_codigoActual.isEmpty) ...[
          // No hay cupón aplicado
          Text('🎟️ ¿Tienes un código de descuento?',
              style: TextStyle(
                  color: ConstantColors.textGrey,
                  fontSize: 12,
                  fontWeight: FontWeight.w600)),
          SizedBox(height: 10),
          Row(children: [
            Expanded(
              child: TextField(
                controller: _codigoController,
                style: TextStyle(color: ConstantColors.textWhite, fontSize: 14),
                decoration: InputDecoration(
                  hintText: 'Ingresa código',
                  hintStyle: TextStyle(color: ConstantColors.textSubtle),
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10)),
                  focusedBorder: OutlineInputBorder(
                    borderSide: BorderSide(color: ConstantColors.primaryViolet),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderSide: BorderSide(color: ConstantColors.borderColor),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  contentPadding:
                      EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                ),
                onChanged: (_) => setState(() => _mensajeError = ''),
              ),
            ),
            SizedBox(width: 10),
            GestureDetector(
              onTap: _validandoCodigo ? null : _validarCodigo,
              child: Container(
                padding: EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                decoration: BoxDecoration(
                  color: ConstantColors.primaryViolet,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: _validandoCodigo
                    ? SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2,
                            valueColor:
                                AlwaysStoppedAnimation<Color>(Colors.white)))
                    : Text('Validar',
                        style: TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w600,
                            fontSize: 12)),
              ),
            ),
          ]),
          if (_mensajeError.isNotEmpty)
            Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text(_mensajeError,
                  style: TextStyle(color: Colors.red, fontSize: 11),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis),
            ),
        ] else ...[
          // Cupón aplicado
          Container(
            padding: EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.green.withOpacity(0.1),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: Colors.green.withOpacity(0.3)),
            ),
            child: Row(children: [
              Icon(Icons.check_circle, color: Colors.green, size: 20),
              SizedBox(width: 10),
              Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('¡Código aplicado!',
                          style: TextStyle(
                              color: Colors.green,
                              fontSize: 12,
                              fontWeight: FontWeight.w600)),
                      Text(_codigoActual,
                          style: TextStyle(
                              color: ConstantColors.textWhite,
                              fontSize: 13,
                              fontWeight: FontWeight.w700)),
                    ]),
              ),
              GestureDetector(
                onTap: _removerCupon,
                child:
                    Icon(Icons.close, color: ConstantColors.textGrey, size: 20),
              ),
            ]),
          ),
        ],

        SizedBox(height: 16),

        // ── RESUMEN DE PRECIO CON DESCUENTO ────────────
        if (_descuentoActual > 0)
          Column(children: [
            Divider(color: ConstantColors.dividerColor, height: 1),
            SizedBox(height: 8),
            Padding(
              padding: EdgeInsets.symmetric(horizontal: 4),
              child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Descuento',
                        style: TextStyle(
                            color: Colors.green,
                            fontSize: 12,
                            fontWeight: FontWeight.w600)),
                    Text('-\$${_descuentoActual.toStringAsFixed(2)}',
                        style: TextStyle(
                            color: Colors.green,
                            fontSize: 12,
                            fontWeight: FontWeight.w600)),
                  ]),
            ),
            SizedBox(height: 8),
            Padding(
              padding: EdgeInsets.symmetric(horizontal: 4),
              child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Total',
                        style: TextStyle(
                            color: ConstantColors.primaryViolet,
                            fontSize: 14,
                            fontWeight: FontWeight.w700)),
                    Text('\$${_calcularPrecioFinal().toStringAsFixed(2)}',
                        style: TextStyle(
                            color: ConstantColors.primaryViolet,
                            fontSize: 14,
                            fontWeight: FontWeight.w700)),
                  ]),
            ),
            SizedBox(height: 8),
          ]),

        SizedBox(height: 16),

        // Botón confirmar
        GestureDetector(
          onTap: () => _confirmarConCupon(),
          child: Container(
            width: double.infinity,
            height: 56,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  ConstantColors.primaryViolet,
                  ConstantColors.primaryBlue
                ],
                begin: Alignment.centerLeft,
                end: Alignment.centerRight,
              ),
              borderRadius: BorderRadius.circular(16),
              boxShadow: [
                BoxShadow(
                    color: ConstantColors.primaryViolet.withOpacity(0.4),
                    blurRadius: 16,
                    offset: Offset(0, 6))
              ],
            ),
            child: Center(
                child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(Icons.check_circle_outline_rounded,
                  color: Colors.white, size: 22),
              SizedBox(width: 10),
              Text(
                _descuentoActual > 0
                    ? 'Confirmar · \$${_calcularPrecioFinal().toStringAsFixed(2)}'
                    : 'Confirmar y solicitar',
                style: TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.w700),
              ),
            ])),
          ),
        ),

        SizedBox(height: 12),

        // Botón cancelar
        GestureDetector(
          onTap: () => Navigator.pop(context),
          child: Container(
            width: double.infinity,
            height: 48,
            decoration: BoxDecoration(
              color: Colors.transparent,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: ConstantColors.borderColor),
            ),
            child: Center(
                child: Text('Volver',
                    style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 15,
                        fontWeight: FontWeight.w500))),
          ),
        ),
      ]),
    );
  }
}
