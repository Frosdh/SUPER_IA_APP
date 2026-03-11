import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong/latlong.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Models/CategoriaModel.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/Preferences/FavoritePlacesService.dart';
import 'package:fu_uber/Core/Preferences/RecentPlacesService.dart';
import 'package:fu_uber/Core/Services/OsmService.dart';
import 'package:http/http.dart' as http;

// Centro de Cuenca, Ecuador
final LatLng CUENCA_CENTER = LatLng(-2.9001285, -79.0058965);

enum EstadoViaje { ninguno, buscando, conductorAsignado }

class OsmMapScreen extends StatefulWidget {
  static const String route = '/osmmap';

  @override
  _OsmMapScreenState createState() => _OsmMapScreenState();
}

class _OsmMapScreenState extends State<OsmMapScreen> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final MapController _mapController = MapController();
  final TextEditingController _searchController = TextEditingController();

  LatLng _miUbicacion = CUENCA_CENTER;
  LatLng _destino;
  String _destinoNombre = '';
  String _origenNombre = 'Mi ubicación';
  String _userName = '';

  List<PlaceResult> _sugerencias = [];
  List<LatLng> _rutaPuntos = [];
  RouteResult _rutaInfo;

  bool _cargandoUbicacion = true;
  bool _buscando = false;
  bool _mostrandoSugerencias = false;
  bool _mostrandoPanel = false;
  bool _calculandoRuta = false;
  String _searchFeedback = '';
  Timer _searchDebounce;

  // ── Categorías ────────────────────────────────────
  List<CategoriaModel> _categorias = [];
  CategoriaModel _categoriaSeleccionada;
  double _precioCalculado = 0.0;

  // ── Favoritos y recientes ─────────────────────────
  List<LugarFavorito>  _favoritos = [];
  List<LugarReciente>  _recientes = [];
  bool _mostrandoRecientes = false;

  // ── Estado del viaje ──────────────────────────────
  EstadoViaje _estadoViaje = EstadoViaje.ninguno;
  Map<String, dynamic> _conductorData;
  Timer _simulacionTimer;
  Timer _pollingTimer;
  int _viajeId;

  @override
  void initState() {
    super.initState();
    _obtenerUbicacion();
    _cargarNombreUsuario();
    _cargarCategorias();
    _cargarFavoritosYRecientes();
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _simulacionTimer?.cancel();
    _pollingTimer?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  // ── Cargar categorías desde el servidor ──────────
  Future<void> _cargarCategorias() async {
    final urls = [
      '${Constants.apiBaseUrl}/obtener_categorias.php',
      'http://10.0.2.2/fuber_api/obtener_categorias.php',
    ];
    for (final url in urls) {
      try {
        final response = await http.post(
          url,
          headers: {'ngrok-skip-browser-warning': 'true'},
        ).timeout(const Duration(seconds: 8));
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
    print('>>> [CAT] Usando fallback Fuber-X');
    if (mounted && _categorias.isEmpty) {
      final fallback = CategoriaModel(
        id: 1,
        nombre: 'Fuber-X',
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
    if (_rutaInfo == null || _categoriaSeleccionada == null) return;
    setState(() {
      _precioCalculado = _categoriaSeleccionada.calcularPrecio(
        _rutaInfo.distanciaKm,
        _rutaInfo.duracionMin,
      );
    });
  }

  // ── Mostrar confirmación antes de solicitar ───────
  void _mostrarConfirmacion() {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => _ConfirmacionSheet(
        origen: _origenNombre,
        destino: _destinoNombre,
        categoria: _categoriaSeleccionada,
        distanciaKm: _rutaInfo?.distanciaKm ?? 0,
        duracionMin: _rutaInfo?.duracionMin ?? 0,
        precio: _precioCalculado,
        onConfirmar: () {
          Navigator.pop(context); // Cierra el sheet
          _solicitarViaje();
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
    if (mounted) setState(() => _viajeId = id);

    // Mostrar conductor simulado tras 4 segundos
    _simulacionTimer = Timer(Duration(seconds: 4), () {
      if (!mounted || _estadoViaje != EstadoViaje.buscando) return;
      setState(() {
        _estadoViaje = EstadoViaje.conductorAsignado;
        _conductorData = {
          'id': 0,
          'nombre': 'Carlos Mendoza',
          'inicial': 'C',
          'calificacion': 4.8,
          'viajes': 312,
          'auto': 'Toyota Corolla',
          'placa': 'ABC-1234',
          'color': 'Blanco',
          'eta_min': 3,
        };
      });
    });

    _iniciarPolling();
  }

  // ── Crear viaje en el servidor ────────────────────
  Future<int> _crearViajeEnServidor(String telefono) async {
    final urls = [
      '${Constants.apiBaseUrl}/solicitar_viaje.php',
      'http://10.0.2.2/fuber_api/solicitar_viaje.php',
    ];
    for (final url in urls) {
      try {
        final response = await http.post(url, body: {
          'telefono':      telefono,
          'categoria_id':  (_categoriaSeleccionada?.id ?? 1).toString(),
          'origen_texto':  _origenNombre,
          'destino_texto': _destinoNombre,
          'distancia_km':  (_rutaInfo?.distanciaKm ?? 0.0).toString(),
          'duracion_min':  (_rutaInfo?.duracionMin  ?? 0).toString(),
          'tarifa_total':  _precioCalculado.toString(),
          'origen_lat':    _miUbicacion.latitude.toString(),
          'origen_lng':    _miUbicacion.longitude.toString(),
          'destino_lat':   (_destino?.latitude  ?? 0.0).toString(),
          'destino_lng':   (_destino?.longitude ?? 0.0).toString(),
        }).timeout(const Duration(seconds: 8));

        if (response.statusCode == 200) {
          final data = json.decode(response.body);
          if (data['status'] == 'success') {
            print('>>> [VIAJE] Creado con ID: ${data['viaje_id']}');
            return data['viaje_id'] as int;
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
      final estado = await _verificarEstadoViaje(_viajeId);
      if (!mounted) return;
      if (estado == 'terminado') {
        _pollingTimer?.cancel();
        _finalizarViaje();
      } else if (estado == 'cancelado') {
        _pollingTimer?.cancel();
        _cancelarViaje();
      }
    });
  }

  Future<String> _verificarEstadoViaje(int viajeId) async {
    final urls = [
      '${Constants.apiBaseUrl}/estado_viaje.php',
      'http://10.0.2.2/fuber_api/estado_viaje.php',
    ];
    for (final url in urls) {
      try {
        final response = await http.post(url, body: {
          'viaje_id': viajeId.toString(),
        }).timeout(const Duration(seconds: 6));
        if (response.statusCode == 200) {
          final data = json.decode(response.body);
          if (data['status'] == 'success') return data['estado'] as String;
        }
      } catch (_) {}
    }
    return '';
  }

  Future<void> _cancelarViajeEnServidor() async {
    if (_viajeId == null) return;
    final urls = [
      '${Constants.apiBaseUrl}/cancelar_viaje.php',
      'http://10.0.2.2/fuber_api/cancelar_viaje.php',
    ];
    for (final url in urls) {
      try {
        await http.post(url, body: {
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
          style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700),
        ),
        content: Text(
          'Se cancelará la búsqueda de conductor.',
          style: TextStyle(color: ConstantColors.textGrey, fontSize: 14),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text('No, continuar', style: TextStyle(color: ConstantColors.primaryViolet)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text('Sí, cancelar', style: TextStyle(color: Colors.redAccent)),
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
      _viajeId = null;
      _mostrandoPanel = _destino != null;
    });
  }

  void _finalizarViaje() {
    _simulacionTimer?.cancel();
    Navigator.pushNamed(
      context,
      '/ride_completed',
      arguments: {
        'viaje_id':        _viajeId,
        'conductor':       _conductorData,
        'origen':          _origenNombre,
        'destino':         _destinoNombre,
        'distancia':       _rutaInfo?.distanciaKm ?? 0.0,
        'duracion':        _rutaInfo?.duracionMin ?? 0,
        'precio':          _precioCalculado,
        'origen_lat':      _miUbicacion.latitude,
        'origen_lng':      _miUbicacion.longitude,
        'destino_lat':     _destino?.latitude ?? 0.0,
        'destino_lng':     _destino?.longitude ?? 0.0,
        // ── Para el recibo detallado ──
        'categoria_nombre': _categoriaSeleccionada?.nombre ?? 'Fuber-X',
        'tarifa_base':      _categoriaSeleccionada?.tarifaBase ?? 1.50,
        'precio_km':        _categoriaSeleccionada?.precioKm ?? 0.45,
        'precio_minuto':    _categoriaSeleccionada?.precioMinuto ?? 0.10,
      },
    ).then((_) {
      setState(() {
        _estadoViaje = EstadoViaje.ninguno;
        _conductorData = null;
        _mostrandoPanel = false;
        _destino = null;
        _destinoNombre = '';
        _rutaPuntos = [];
        _rutaInfo = null;
        _precioCalculado = 0.0;
        _searchController.clear();
      });
    });
  }

  Future<void> _cargarNombreUsuario() async {
    final nombre = await AuthPrefs.getUserName();
    if (mounted) setState(() => _userName = nombre);
  }

  Future<void> _cargarFavoritosYRecientes() async {
    final favs  = await FavoritePlacesService.obtenerFavoritos();
    final recs   = await RecentPlacesService.obtenerRecientes();
    if (mounted) setState(() { _favoritos = favs; _recientes = recs; });
  }

  // Usar un favorito como destino directo
  void _seleccionarFavorito(LugarFavorito fav) {
    final destino = LatLng(fav.lat, fav.lng);
    FocusScope.of(context).unfocus();
    setState(() {
      _destino             = destino;
      _destinoNombre       = fav.direccion;
      _sugerencias         = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes  = false;
      _searchController.text = fav.direccion;
      _calculandoRuta      = true;
      _mostrandoPanel      = true;
      _rutaInfo            = null;
      _rutaPuntos          = [];
    });
    _mapController.move(destino, 14.0);
    _calcularRuta(destino);
  }

  // Usar un reciente como destino directo
  void _seleccionarReciente(LugarReciente rec) {
    final destino = LatLng(rec.lat, rec.lng);
    FocusScope.of(context).unfocus();
    setState(() {
      _destino             = destino;
      _destinoNombre       = rec.nombre;
      _sugerencias         = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes  = false;
      _searchController.text = rec.nombre;
      _calculandoRuta      = true;
      _mostrandoPanel      = true;
      _rutaInfo            = null;
      _rutaPuntos          = [];
    });
    _mapController.move(destino, 14.0);
    _calcularRuta(destino);
  }

  Future<void> _obtenerUbicacion() async {
    try {
      Position position = await Geolocator().getCurrentPosition(
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
        _origenNombre = nombre;
        _cargandoUbicacion = false;
      });
      _mapController.move(ubicacion, 15.0);
    } catch (e) {
      if (mounted) setState(() => _cargandoUbicacion = false);
    }
  }

  Future<void> _buscarDestino(String query) async {
    final texto = query.trim();
    if (texto.isEmpty) {
      setState(() { _sugerencias = []; _mostrandoSugerencias = false; _buscando = false; _searchFeedback = ''; });
      return;
    }
    if (texto.length < 3) {
      setState(() { _sugerencias = []; _mostrandoSugerencias = false; _buscando = false; _searchFeedback = 'Escribe al menos 3 letras'; });
      return;
    }
    setState(() { _buscando = true; _searchFeedback = ''; });
    final resultados = await OsmService.buscarLugar(texto);
    if (!mounted) return;
    setState(() {
      _sugerencias = resultados;
      _mostrandoSugerencias = resultados.isNotEmpty;
      _buscando = false;
      _searchFeedback = resultados.isEmpty ? 'Sin resultados. Prueba con otra dirección.' : '';
    });
  }

  void _onSearchChanged(String value) {
    if (value.isEmpty) {
      setState(() { _mostrandoRecientes = true; _mostrandoSugerencias = false; _sugerencias = []; _searchFeedback = ''; });
      return;
    }
    setState(() => _mostrandoRecientes = false);
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 450), () => _buscarDestino(value));
  }

  Future<void> _seleccionarDestino(PlaceResult lugar) async {
    final destino = LatLng(lugar.lat, lugar.lon);
    FocusScope.of(context).unfocus();
    setState(() {
      _destino = destino;
      _destinoNombre = lugar.shortName;
      _sugerencias = [];
      _mostrandoSugerencias = false;
      _mostrandoRecientes  = false;
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

  // ── Calcular y mostrar la ruta al destino ─────────
  Future<void> _calcularRuta(LatLng destino) async {
    RouteResult ruta = await OsmService.calcularRuta(_miUbicacion, destino);

    if (ruta == null) {
      final Distance distCalc = Distance();
      final distanciaM = distCalc.as(LengthUnit.Meter, _miUbicacion, destino);
      final distanciaKm = distanciaM / 1000;
      final duracionMin = ((distanciaKm / 30) * 60).round();
      ruta = RouteResult(
        puntos: [_miUbicacion, destino],
        distanciaKm: distanciaKm,
        duracionMin: duracionMin,
        precioEstimado: 0.0,
      );
    }

    if (mounted) {
      setState(() {
        _rutaPuntos = ruta.puntos;
        _rutaInfo = ruta;
        _calculandoRuta = false;
      });
      _recalcularPrecio();
      _mapController.move(
        LatLng(
          (_miUbicacion.latitude + destino.latitude) / 2,
          (_miUbicacion.longitude + destino.longitude) / 2,
        ),
        13.0,
      );
    }
  }

  void _limpiarDestino() {
    setState(() {
      _destino = null; _destinoNombre = ''; _rutaPuntos = []; _rutaInfo = null;
      _mostrandoPanel = false; _sugerencias = []; _mostrandoSugerencias = false;
      _mostrandoRecientes = false;
      _searchFeedback = ''; _precioCalculado = 0.0; _searchController.clear();
    });
  }

  void _centrarEnMiUbicacion() => _mapController.move(_miUbicacion, 15.0);

  // ────────────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
        children: [
          // ── MAPA ────────────────────────────────────────────────
          FlutterMap(
            mapController: _mapController,
            options: MapOptions(center: CUENCA_CENTER, zoom: 14.0, minZoom: 5.0, maxZoom: 18.0),
            layers: [
              TileLayerOptions(
                urlTemplate: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                subdomains: ['a', 'b', 'c'],
                additionalOptions: {'attribution': '© OpenStreetMap contributors'},
              ),
              if (_rutaPuntos.isNotEmpty)
                PolylineLayerOptions(polylines: [
                  Polyline(points: _rutaPuntos, strokeWidth: 4.0, color: ConstantColors.primaryViolet),
                ]),
              MarkerLayerOptions(markers: [
                Marker(
                  point: _miUbicacion, width: 50, height: 50,
                  builder: (_) => Container(
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: ConstantColors.primaryBlue.withOpacity(0.3),
                      border: Border.all(color: ConstantColors.primaryBlue, width: 2),
                    ),
                    child: Center(child: Container(width: 14, height: 14,
                      decoration: BoxDecoration(shape: BoxShape.circle, color: ConstantColors.primaryBlue),
                    )),
                  ),
                ),
                if (_destino != null)
                  Marker(
                    point: _destino, width: 40, height: 50,
                    anchorPos: AnchorPos.align(AnchorAlign.top),
                    builder: (_) => Icon(Icons.location_on, color: ConstantColors.primaryViolet, size: 40),
                  ),
              ]),
            ],
          ),

          // ── BARRA SUPERIOR ───────────────────────────────────────
          Positioned(
            top: 0, left: 0, right: 0,
            child: Container(
              padding: EdgeInsets.fromLTRB(16, 50, 16, 12),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter, end: Alignment.bottomCenter,
                  colors: [ConstantColors.backgroundDark, ConstantColors.backgroundDark.withOpacity(0.0)],
                ),
              ),
              child: Column(children: [
                Row(children: [
                  GestureDetector(
                    onTap: () => Navigator.pushNamed(context, '/profilescreen'),
                    child: Container(
                      width: 38, height: 38,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: LinearGradient(colors: [ConstantColors.primaryViolet, ConstantColors.primaryBlue]),
                      ),
                      child: Icon(Icons.person_rounded, color: Colors.white, size: 20),
                    ),
                  ),
                  SizedBox(width: 10),
                  Expanded(child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Hola, $_userName', style: TextStyle(color: ConstantColors.textWhite, fontSize: 15, fontWeight: FontWeight.w700)),
                      Text('¿A dónde vamos hoy?', style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
                    ],
                  )),
                  if (_cargandoUbicacion)
                    SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2,
                      valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryViolet))),
                ]),
                SizedBox(height: 12),
                // Barra de búsqueda
                Container(
                  decoration: BoxDecoration(
                    color: ConstantColors.backgroundCard,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: ConstantColors.borderColor, width: 1.5),
                    boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.3), blurRadius: 10, offset: Offset(0, 4))],
                  ),
                  child: Row(children: [
                    Padding(padding: EdgeInsets.symmetric(horizontal: 14),
                      child: Icon(Icons.search_rounded, color: ConstantColors.primaryViolet, size: 22)),
                    Expanded(child: TextField(
                      controller: _searchController,
                      style: TextStyle(color: ConstantColors.textWhite, fontSize: 15),
                      decoration: InputDecoration(
                        hintText: 'Busca tu destino...',
                        hintStyle: TextStyle(color: ConstantColors.textSubtle, fontSize: 14),
                        border: InputBorder.none,
                        contentPadding: EdgeInsets.symmetric(vertical: 14),
                      ),
                      onTap: () {
                        if (_searchController.text.isEmpty && _recientes.isNotEmpty) {
                          setState(() => _mostrandoRecientes = true);
                        }
                      },
                      onChanged: _onSearchChanged,
                    )),
                    if (_searchController.text.isNotEmpty)
                      GestureDetector(
                        onTap: _limpiarDestino,
                        child: Padding(padding: EdgeInsets.symmetric(horizontal: 12),
                          child: Icon(Icons.close_rounded, color: ConstantColors.textGrey, size: 20)),
                      ),
                  ]),
                ),
                // ── Botones favoritos rápidos ───────────────────────
                if (_favoritos.isNotEmpty && _estadoViaje == EstadoViaje.ninguno &&
                    !_mostrandoSugerencias && !_buscando && _searchController.text.isEmpty) ...[
                  SizedBox(height: 8),
                  Row(
                    children: _favoritos.map((fav) {
                      return Expanded(
                        child: GestureDetector(
                          onTap: () => _seleccionarFavorito(fav),
                          child: Container(
                            margin: EdgeInsets.only(right: _favoritos.last == fav ? 0 : 8),
                            padding: EdgeInsets.symmetric(vertical: 9, horizontal: 10),
                            decoration: BoxDecoration(
                              color: ConstantColors.backgroundCard,
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: ConstantColors.borderColor),
                            ),
                            child: Row(children: [
                              Text(fav.icono, style: TextStyle(fontSize: 16)),
                              SizedBox(width: 6),
                              Expanded(child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(fav.nombre, style: TextStyle(color: ConstantColors.textWhite, fontSize: 12, fontWeight: FontWeight.w700)),
                                  Text(fav.direccion, style: TextStyle(color: ConstantColors.textSubtle, fontSize: 10),
                                    maxLines: 1, overflow: TextOverflow.ellipsis),
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
                if (_mostrandoRecientes && _recientes.isNotEmpty && _estadoViaje == EstadoViaje.ninguno)
                  Container(
                    margin: EdgeInsets.only(top: 4),
                    decoration: BoxDecoration(
                      color: ConstantColors.backgroundCard,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: ConstantColors.borderColor),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Padding(
                          padding: EdgeInsets.fromLTRB(14, 10, 14, 4),
                          child: Text('Recientes', style: TextStyle(color: ConstantColors.textSubtle, fontSize: 11, fontWeight: FontWeight.w600)),
                        ),
                        ..._recientes.take(5).map((rec) => GestureDetector(
                          onTap: () => _seleccionarReciente(rec),
                          child: Container(
                            padding: EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                            decoration: BoxDecoration(border: Border(top: BorderSide(color: ConstantColors.dividerColor))),
                            child: Row(children: [
                              Icon(Icons.history_rounded, color: ConstantColors.textSubtle, size: 16),
                              SizedBox(width: 10),
                              Expanded(child: Text(rec.nombre,
                                style: TextStyle(color: ConstantColors.textWhite, fontSize: 13),
                                maxLines: 1, overflow: TextOverflow.ellipsis)),
                            ]),
                          ),
                        )).toList(),
                      ],
                    ),
                  ),

                // Sugerencias
                if (_mostrandoSugerencias || _buscando || _searchFeedback.isNotEmpty)
                  Container(
                    margin: EdgeInsets.only(top: 4),
                    decoration: BoxDecoration(
                      color: ConstantColors.backgroundCard,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: ConstantColors.borderColor),
                    ),
                    child: _buscando
                        ? Padding(padding: EdgeInsets.symmetric(vertical: 14),
                            child: Center(child: SizedBox(width: 20, height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2,
                                valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryViolet)))))
                        : _mostrandoSugerencias
                            ? Column(children: _sugerencias.map((lugar) => GestureDetector(
                                onTap: () => _seleccionarDestino(lugar),
                                child: Container(
                                  padding: EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                                  decoration: BoxDecoration(border: Border(
                                    bottom: BorderSide(color: _sugerencias.last == lugar ? Colors.transparent : ConstantColors.dividerColor),
                                  )),
                                  child: Row(children: [
                                    Icon(Icons.place_rounded, color: ConstantColors.primaryViolet, size: 18),
                                    SizedBox(width: 10),
                                    Expanded(child: Text(lugar.shortName,
                                      style: TextStyle(color: ConstantColors.textWhite, fontSize: 14),
                                      maxLines: 1, overflow: TextOverflow.ellipsis)),
                                  ]),
                                ),
                              )).toList())
                            : Padding(padding: EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                                child: Row(children: [
                                  Icon(Icons.info_outline_rounded, color: ConstantColors.textGrey, size: 16),
                                  SizedBox(width: 8),
                                  Expanded(child: Text(_searchFeedback,
                                    style: TextStyle(color: ConstantColors.textGrey, fontSize: 13))),
                                ])),
                  ),
              ]),
            ),
          ),

          // ── BOTÓN MI UBICACIÓN ──────────────────────────────────
          Positioned(
            right: 16,
            bottom: (_mostrandoPanel || _estadoViaje != EstadoViaje.ninguno) ? 300 : 100,
            child: GestureDetector(
              onTap: _centrarEnMiUbicacion,
              child: Container(
                width: 48, height: 48,
                decoration: BoxDecoration(
                  shape: BoxShape.circle, color: ConstantColors.backgroundCard,
                  border: Border.all(color: ConstantColors.borderColor),
                  boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.3), blurRadius: 8)],
                ),
                child: Icon(Icons.my_location_rounded, color: ConstantColors.primaryViolet, size: 22),
              ),
            ),
          ),

          // ── PANEL INFERIOR (ruta + categorías + confirmar) ──────
          if (_mostrandoPanel && _estadoViaje == EstadoViaje.ninguno)
            Positioned(
              bottom: 0, left: 0, right: 0,
              child: Container(
                padding: EdgeInsets.fromLTRB(20, 20, 20, 36),
                decoration: BoxDecoration(
                  color: ConstantColors.backgroundCard,
                  borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
                  border: Border.all(color: ConstantColors.borderColor),
                  boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.4), blurRadius: 20, offset: Offset(0, -5))],
                ),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  // Handle
                  Container(width: 40, height: 4,
                    decoration: BoxDecoration(color: ConstantColors.borderColor, borderRadius: BorderRadius.circular(2))),
                  SizedBox(height: 16),

                  // Origen → Destino
                  Row(children: [
                    Column(children: [
                      Icon(Icons.circle, size: 10, color: ConstantColors.primaryBlue),
                      Container(width: 2, height: 24, color: ConstantColors.borderColor),
                      Icon(Icons.location_on, size: 16, color: ConstantColors.primaryViolet),
                    ]),
                    SizedBox(width: 12),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(_origenNombre, style: TextStyle(color: ConstantColors.textGrey, fontSize: 13),
                        maxLines: 1, overflow: TextOverflow.ellipsis),
                      SizedBox(height: 14),
                      Text(_destinoNombre, style: TextStyle(color: ConstantColors.textWhite, fontSize: 14, fontWeight: FontWeight.w600),
                        maxLines: 1, overflow: TextOverflow.ellipsis),
                    ])),
                  ]),

                  SizedBox(height: 16),
                  Divider(color: ConstantColors.dividerColor),
                  SizedBox(height: 12),

                  // ── SELECTOR DE TIPO DE VEHÍCULO ──────────────
                  if (_categorias.isNotEmpty) ...[
                    Align(
                      alignment: Alignment.centerLeft,
                      child: Text('Tipo de vehículo',
                        style: TextStyle(color: ConstantColors.textGrey, fontSize: 12, fontWeight: FontWeight.w500)),
                    ),
                    SizedBox(height: 10),
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
                              duration: Duration(milliseconds: 200),
                              margin: EdgeInsets.only(right: 10),
                              padding: EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                              decoration: BoxDecoration(
                                color: isSelected
                                    ? ConstantColors.primaryViolet.withOpacity(0.15)
                                    : ConstantColors.backgroundLight,
                                borderRadius: BorderRadius.circular(14),
                                border: Border.all(
                                  color: isSelected ? ConstantColors.primaryViolet : ConstantColors.borderColor,
                                  width: isSelected ? 2 : 1,
                                ),
                              ),
                              child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                                Text(cat.icono, style: TextStyle(fontSize: 22)),
                                SizedBox(height: 4),
                                Text(cat.nombre,
                                  style: TextStyle(
                                    color: isSelected ? ConstantColors.primaryViolet : ConstantColors.textWhite,
                                    fontSize: 12, fontWeight: FontWeight.w600,
                                  )),
                              ]),
                            ),
                          );
                        },
                      ),
                    ),
                    SizedBox(height: 12),
                    Divider(color: ConstantColors.dividerColor),
                    SizedBox(height: 12),
                  ],

                  // ── INFO DE RUTA ──────────────────────────────
                  _calculandoRuta
                      ? Center(child: Column(children: [
                          SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 3,
                            valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryViolet))),
                          SizedBox(height: 8),
                          Text('Calculando ruta...', style: TextStyle(color: ConstantColors.textGrey, fontSize: 13)),
                        ]))
                      : _rutaInfo != null
                          ? Row(mainAxisAlignment: MainAxisAlignment.spaceAround, children: [
                              _buildInfoChip(Icons.straighten_rounded, '${_rutaInfo.distanciaKm.toStringAsFixed(1)} km', 'Distancia'),
                              _buildInfoChip(Icons.access_time_rounded, '${_rutaInfo.duracionMin} min', 'Tiempo'),
                              _buildInfoChip(Icons.attach_money_rounded, '\$${_precioCalculado.toStringAsFixed(2)}', 'Precio', highlight: true),
                            ])
                          : SizedBox.shrink(),

                  SizedBox(height: 20),

                  // ── BOTÓN CONFIRMAR ───────────────────────────
                  GestureDetector(
                    onTap: _calculandoRuta ? null : _mostrarConfirmacion,
                    child: Container(
                      width: double.infinity, height: 56,
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          colors: [ConstantColors.primaryViolet, ConstantColors.primaryBlue],
                          begin: Alignment.centerLeft, end: Alignment.centerRight,
                        ),
                        borderRadius: BorderRadius.circular(16),
                        boxShadow: [BoxShadow(color: ConstantColors.primaryViolet.withOpacity(0.4), blurRadius: 16, offset: Offset(0, 6))],
                      ),
                      child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                        _calculandoRuta
                            ? SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2,
                                valueColor: AlwaysStoppedAnimation<Color>(Colors.white)))
                            : Icon(Icons.directions_car_rounded, color: Colors.white, size: 22),
                        SizedBox(width: 10),
                        Text(
                          _calculandoRuta ? 'Calculando...'
                              : _precioCalculado > 0 ? 'Pedir viaje · \$${_precioCalculado.toStringAsFixed(2)}'
                              : 'Pedir viaje',
                          style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700),
                        ),
                      ]),
                    ),
                  ),
                ]),
              ),
            ),

          // ── PANEL: BUSCANDO CONDUCTOR ─────────────────────────
          if (_estadoViaje == EstadoViaje.buscando)
            Positioned(
              bottom: 0, left: 0, right: 0,
              child: Container(
                padding: EdgeInsets.fromLTRB(20, 20, 20, 40),
                decoration: BoxDecoration(
                  color: ConstantColors.backgroundCard,
                  borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
                  border: Border.all(color: ConstantColors.borderColor),
                  boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.4), blurRadius: 20, offset: Offset(0, -5))],
                ),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Container(width: 40, height: 4,
                    decoration: BoxDecoration(color: ConstantColors.borderColor, borderRadius: BorderRadius.circular(2))),
                  SizedBox(height: 28),
                  Stack(alignment: Alignment.center, children: [
                    Container(width: 80, height: 80, decoration: BoxDecoration(shape: BoxShape.circle, color: ConstantColors.primaryViolet.withOpacity(0.12))),
                    Container(width: 56, height: 56, decoration: BoxDecoration(shape: BoxShape.circle, color: ConstantColors.primaryViolet.withOpacity(0.2))),
                    SizedBox(width: 36, height: 36, child: CircularProgressIndicator(strokeWidth: 3, valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryViolet))),
                  ]),
                  SizedBox(height: 20),
                  Text('Buscando conductor...', style: TextStyle(color: ConstantColors.textWhite, fontSize: 18, fontWeight: FontWeight.w700)),
                  SizedBox(height: 8),
                  Text('Estamos encontrando el mejor conductor cerca de ti',
                    style: TextStyle(color: ConstantColors.textGrey, fontSize: 13, height: 1.4), textAlign: TextAlign.center),
                  SizedBox(height: 16),
                  // Categoría seleccionada
                  if (_categoriaSeleccionada != null)
                    Container(
                      padding: EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                      decoration: BoxDecoration(
                        color: ConstantColors.primaryViolet.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: ConstantColors.primaryViolet.withOpacity(0.3)),
                      ),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        Text(_categoriaSeleccionada.icono, style: TextStyle(fontSize: 16)),
                        SizedBox(width: 6),
                        Text(_categoriaSeleccionada.nombre,
                          style: TextStyle(color: ConstantColors.primaryViolet, fontSize: 13, fontWeight: FontWeight.w600)),
                        SizedBox(width: 8),
                        Text('· \$${_precioCalculado.toStringAsFixed(2)}',
                          style: TextStyle(color: ConstantColors.textGrey, fontSize: 13)),
                      ]),
                    ),
                  SizedBox(height: 16),
                  // Ruta resumida
                  Container(
                    padding: EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    decoration: BoxDecoration(
                      color: ConstantColors.backgroundLight,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: ConstantColors.borderColor),
                    ),
                    child: Row(children: [
                      Icon(Icons.circle, size: 8, color: ConstantColors.primaryBlue),
                      SizedBox(width: 8),
                      Expanded(child: Text(_origenNombre, style: TextStyle(color: ConstantColors.textGrey, fontSize: 12), overflow: TextOverflow.ellipsis)),
                      Icon(Icons.arrow_forward_rounded, size: 14, color: ConstantColors.textGrey),
                      SizedBox(width: 8),
                      Icon(Icons.location_on, size: 14, color: ConstantColors.primaryViolet),
                      SizedBox(width: 4),
                      Expanded(child: Text(_destinoNombre, style: TextStyle(color: ConstantColors.textWhite, fontSize: 12, fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis)),
                    ]),
                  ),
                  SizedBox(height: 20),
                  GestureDetector(
                    onTap: _cancelarViaje,
                    child: Container(
                      width: double.infinity, height: 52,
                      decoration: BoxDecoration(
                        color: Colors.red.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: Colors.redAccent.withOpacity(0.4)),
                      ),
                      child: Center(child: Text('Cancelar búsqueda',
                        style: TextStyle(color: Colors.redAccent, fontSize: 15, fontWeight: FontWeight.w600))),
                    ),
                  ),
                ]),
              ),
            ),

          // ── PANEL: CONDUCTOR EN CAMINO ────────────────────────
          if (_estadoViaje == EstadoViaje.conductorAsignado && _conductorData != null)
            Positioned(
              bottom: 0, left: 0, right: 0,
              child: Container(
                padding: EdgeInsets.fromLTRB(20, 20, 20, 40),
                decoration: BoxDecoration(
                  color: ConstantColors.backgroundCard,
                  borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
                  border: Border.all(color: ConstantColors.borderColor),
                  boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.4), blurRadius: 20, offset: Offset(0, -5))],
                ),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Container(width: 40, height: 4,
                    decoration: BoxDecoration(color: ConstantColors.borderColor, borderRadius: BorderRadius.circular(2))),
                  SizedBox(height: 16),
                  Row(children: [
                    Icon(Icons.check_circle_rounded, color: Colors.greenAccent, size: 20),
                    SizedBox(width: 8),
                    Text('¡Conductor en camino!', style: TextStyle(color: Colors.greenAccent, fontSize: 15, fontWeight: FontWeight.w700)),
                    Spacer(),
                    Container(
                      padding: EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: ConstantColors.primaryViolet.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text('${_conductorData['eta_min']} min',
                        style: TextStyle(color: ConstantColors.primaryViolet, fontWeight: FontWeight.w700, fontSize: 13)),
                    ),
                  ]),
                  SizedBox(height: 14),
                  Divider(color: ConstantColors.dividerColor),
                  SizedBox(height: 14),
                  Row(children: [
                    CircleAvatar(
                      radius: 30,
                      backgroundColor: ConstantColors.primaryViolet.withOpacity(0.2),
                      child: Text(_conductorData['inicial'],
                        style: TextStyle(color: ConstantColors.primaryViolet, fontSize: 24, fontWeight: FontWeight.bold)),
                    ),
                    SizedBox(width: 14),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(_conductorData['nombre'], style: TextStyle(color: ConstantColors.textWhite, fontSize: 16, fontWeight: FontWeight.w700)),
                      SizedBox(height: 4),
                      Row(children: [
                        Icon(Icons.star_rounded, color: Colors.amber, size: 16),
                        SizedBox(width: 4),
                        Text('${_conductorData['calificacion']}  ·  ${_conductorData['viajes']} viajes',
                          style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
                      ]),
                    ])),
                    Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(_conductorData['auto'], style: TextStyle(color: ConstantColors.textWhite, fontSize: 13, fontWeight: FontWeight.w600)),
                      SizedBox(height: 2),
                      Text('${_conductorData['color']} · ${_conductorData['placa']}',
                        style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
                    ]),
                  ]),
                  SizedBox(height: 20),
                  GestureDetector(
                    onTap: _cancelarViaje,
                    child: Container(
                      width: double.infinity, height: 48,
                      decoration: BoxDecoration(
                        color: Colors.red.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: Colors.redAccent.withOpacity(0.4)),
                      ),
                      child: Center(child: Text('Cancelar viaje',
                        style: TextStyle(color: Colors.redAccent, fontSize: 14, fontWeight: FontWeight.w600))),
                    ),
                  ),
                ]),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildInfoChip(IconData icon, String value, String label, {bool highlight = false}) {
    return Column(children: [
      Icon(icon, color: highlight ? ConstantColors.primaryViolet : ConstantColors.textGrey, size: 20),
      SizedBox(height: 4),
      Text(value, style: TextStyle(color: highlight ? ConstantColors.primaryViolet : ConstantColors.textWhite, fontSize: 15, fontWeight: FontWeight.w700)),
      Text(label, style: TextStyle(color: ConstantColors.textSubtle, fontSize: 11)),
    ]);
  }
}

// ────────────────────────────────────────────────────────────────────────────
// WIDGET: Pantalla de confirmación (bottom sheet)
// ────────────────────────────────────────────────────────────────────────────
class _ConfirmacionSheet extends StatelessWidget {
  final String origen;
  final String destino;
  final CategoriaModel categoria;
  final double distanciaKm;
  final int duracionMin;
  final double precio;
  final VoidCallback onConfirmar;

  const _ConfirmacionSheet({
    Key key,
    @required this.origen,
    @required this.destino,
    @required this.categoria,
    @required this.distanciaKm,
    @required this.duracionMin,
    @required this.precio,
    @required this.onConfirmar,
  }) : super(key: key);

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
        Container(width: 40, height: 4,
          decoration: BoxDecoration(color: ConstantColors.borderColor, borderRadius: BorderRadius.circular(2))),
        SizedBox(height: 20),

        // Título
        Text('Confirmar viaje',
          style: TextStyle(color: ConstantColors.textWhite, fontSize: 20, fontWeight: FontWeight.w800)),
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
              Container(width: 2, height: 28, color: ConstantColors.borderColor),
              Icon(Icons.location_on, size: 16, color: ConstantColors.primaryViolet),
            ]),
            SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(origen, style: TextStyle(color: ConstantColors.textGrey, fontSize: 13),
                maxLines: 1, overflow: TextOverflow.ellipsis),
              SizedBox(height: 18),
              Text(destino, style: TextStyle(color: ConstantColors.textWhite, fontSize: 14, fontWeight: FontWeight.w600),
                maxLines: 1, overflow: TextOverflow.ellipsis),
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
              width: 50, height: 50,
              decoration: BoxDecoration(
                color: ConstantColors.primaryViolet.withOpacity(0.1),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Center(child: Text(categoria.icono, style: TextStyle(fontSize: 24))),
            ),
            SizedBox(width: 14),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(categoria.nombre,
                style: TextStyle(color: ConstantColors.textWhite, fontSize: 15, fontWeight: FontWeight.w700)),
              SizedBox(height: 4),
              Text('${distanciaKm.toStringAsFixed(1)} km  ·  $duracionMin min',
                style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
            ])),
            // Precio destacado
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text('\$${precio.toStringAsFixed(2)}',
                style: TextStyle(color: ConstantColors.primaryViolet, fontSize: 22, fontWeight: FontWeight.w800)),
              Text('Estimado', style: TextStyle(color: ConstantColors.textSubtle, fontSize: 11)),
            ]),
          ]),
        ),

        SizedBox(height: 8),
        // Desglose precio
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 4),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Tarifa base', style: TextStyle(color: ConstantColors.textSubtle, fontSize: 12)),
            Text('\$${categoria.tarifaBase.toStringAsFixed(2)}', style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          ]),
        ),
        SizedBox(height: 4),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 4),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Por distancia (${distanciaKm.toStringAsFixed(1)} km)', style: TextStyle(color: ConstantColors.textSubtle, fontSize: 12)),
            Text('\$${(categoria.precioKm * distanciaKm).toStringAsFixed(2)}', style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          ]),
        ),
        SizedBox(height: 4),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 4),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Por tiempo ($duracionMin min)', style: TextStyle(color: ConstantColors.textSubtle, fontSize: 12)),
            Text('\$${(categoria.precioMinuto * duracionMin).toStringAsFixed(2)}', style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          ]),
        ),

        SizedBox(height: 24),

        // Botón confirmar
        GestureDetector(
          onTap: onConfirmar,
          child: Container(
            width: double.infinity, height: 56,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [ConstantColors.primaryViolet, ConstantColors.primaryBlue],
                begin: Alignment.centerLeft, end: Alignment.centerRight,
              ),
              borderRadius: BorderRadius.circular(16),
              boxShadow: [BoxShadow(color: ConstantColors.primaryViolet.withOpacity(0.4), blurRadius: 16, offset: Offset(0, 6))],
            ),
            child: Center(child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(Icons.check_circle_outline_rounded, color: Colors.white, size: 22),
              SizedBox(width: 10),
              Text('Confirmar y solicitar', style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700)),
            ])),
          ),
        ),

        SizedBox(height: 12),

        // Botón cancelar
        GestureDetector(
          onTap: () => Navigator.pop(context),
          child: Container(
            width: double.infinity, height: 48,
            decoration: BoxDecoration(
              color: Colors.transparent,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: ConstantColors.borderColor),
            ),
            child: Center(child: Text('Volver',
              style: TextStyle(color: ConstantColors.textGrey, fontSize: 15, fontWeight: FontWeight.w500))),
          ),
        ),
      ]),
    );
  }
}
