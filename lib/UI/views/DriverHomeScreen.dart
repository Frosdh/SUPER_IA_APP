import 'dart:async';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Networking/ApiProvider.dart';
import 'package:super_ia/Core/Preferences/DriverPrefs.dart';
import 'package:super_ia/Core/Services/OsmService.dart';
import 'package:super_ia/Core/Services/PushNotificationService.dart';
import 'package:super_ia/Core/Services/BackgroundLocationService.dart';
import 'package:super_ia/UI/views/DriverLoginScreen.dart';
import 'package:super_ia/UI/views/DriverProfileScreen.dart';
import 'package:super_ia/UI/views/DriverTripHistoryScreen.dart';
import 'package:super_ia/UI/views/ChatScreen.dart';
import 'package:geolocator/geolocator.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:sliding_up_panel/sliding_up_panel.dart';

class DriverHomeScreen extends StatefulWidget {
  static const String route = '/driver_home';

  @override
  _DriverHomeScreenState createState() => _DriverHomeScreenState();
}

class _DriverHomeScreenState extends State<DriverHomeScreen> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final ApiProvider _apiProvider = ApiProvider();

  // Panel para viaje activo
  final PanelController _activeRidePanelController = PanelController();
  final PanelController _requestPanelController = PanelController();
  final PanelController _mainPanelController = PanelController();
  double _currentPanelHeight = 24.0;
  double _requestPanelHeight = 24.0;
  double _mainPanelHeight = 24.0;

  int _driverId = 0;
  String _driverName = '';
  String _driverPhone = '';
  String _driverCedula = '';
  String _driverStatus = 'desconectado';
  String _lastLocationText = 'Aun sin ubicacion enviada';
  Map<String, dynamic>? _pendingRequest;
  Map<String, dynamic>? _activeRide;
  bool _loading = true;
  bool _updatingStatus = false;
  bool _updatingLocation = false;
  bool _sendingLocation = false;
  bool _respondingRequest = false;
  bool _fetchingRequest = false;
  bool _updatingRideStatus = false;
  Timer? _requestPollingTimer;
  Timer? _ridePollingTimer;
  StreamSubscription<Position>? _positionSubscription;

  // ── Mapa con ruta ──────────────────────────────────
  final MapController _mapController = MapController();
  LatLng? _miUbicacion;
  List<LatLng> _rutaPuntos = [];
  bool _calculandoRuta = false;
  Timer? _mapTimer;

  // ── Sonido de alerta ───────────────────────────────
  final AudioPlayer _audioPlayer = AudioPlayer();
  int? _lastAlertedRequestId;

  // ── Resumen post-viaje ─────────────────────────────
  Map<String, dynamic>? _completedRide;

  // ── Mapa de solicitud entrante ─────────────────────
  final MapController _solicitudMapController = MapController();
  List<LatLng> _rutaSolicitud = [];
  bool _calculandoRutaSolicitud = false;
  LatLng? _miPosActual; // posición del conductor, actualizada en cada envío de ubicación

  @override
  void initState() {
    super.initState();
    _loadDriverSession();
  }

  @override
  void dispose() {
    _requestPollingTimer?.cancel();
    _ridePollingTimer?.cancel();
    _mapTimer?.cancel();
    _positionSubscription?.cancel();
    _audioPlayer.dispose();
    super.dispose();
  }

  void _showMessage(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message)),
    );
  }

  Future<void> _playAlertSound() async {
    try {
      await _audioPlayer.stop();
      await _audioPlayer.play(AssetSource('sounds/alert.wav'));
    } catch (e) {
      print('>>> [ALERT_SOUND] Error: $e');
    }
  }

  // ── Calcular ruta del conductor al punto de recogida de la solicitud ──
  Future<void> _calcularRutaHaciaSolicitud(Map<String, dynamic> solicitud) async {
    final pickupLat = double.tryParse(solicitud['origen_lat']?.toString() ?? '');
    final pickupLng = double.tryParse(solicitud['origen_lng']?.toString() ?? '');
    if (pickupLat == null || pickupLng == null) return;
    final pickup = LatLng(pickupLat, pickupLng);

    // Obtener posición actual del conductor
    LatLng? miPosTemp = _miPosActual;
    if (miPosTemp == null) {
      try {
        final perm = await Geolocator.checkPermission();
        if (perm == LocationPermission.denied || perm == LocationPermission.deniedForever) return;
        final pos = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high);
        final newPos = LatLng(pos.latitude, pos.longitude);
        miPosTemp = newPos;
        if (mounted) setState(() => _miPosActual = newPos);
      } catch (_) { return; }
    }
    final LatLng miPos = miPosTemp; // guaranteed non-null from here

    if (mounted) setState(() => _calculandoRutaSolicitud = true);
    final ruta = await OsmService.calcularRuta(miPos, pickup);
    if (!mounted) return;
    setState(() {
      _rutaSolicitud = ruta?.puntos ?? [miPos, pickup];
      _calculandoRutaSolicitud = false;
    });
    // Centrar mapa en la recogida
    try { _solicitudMapController.move(pickup, 14.0); } catch (_) {}
  }

  // ── Resumen de ganancias al finalizar el viaje ─────
  void _mostrarResumenViaje(Map<String, dynamic> ride) {
    final pasajero  = ride['pasajero_nombre']?.toString()  ?? 'Pasajero';
    final origen    = ride['origen_texto']?.toString()     ?? '';
    final destino   = ride['destino_texto']?.toString()    ?? '';
    final tarifa    = ride['tarifa_total']?.toString()     ?? '0';
    final distancia = ride['distancia_km']?.toString()     ?? '0';
    final duracion  = ride['duracion_min']?.toString()     ?? '0';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => Container(
        padding: EdgeInsets.fromLTRB(24, 24, 24, MediaQuery.of(context).padding.bottom + 24),
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          // Handle
          Container(
            width: 40, height: 4,
            decoration: BoxDecoration(
              color: Colors.white24,
              borderRadius: BorderRadius.circular(4),
            ),
          ),
          const SizedBox(height: 20),

          // Icono de éxito
          Container(
            width: 64, height: 64,
            decoration: BoxDecoration(
              color: ConstantColors.success.withOpacity(0.15),
              shape: BoxShape.circle,
            ),
            child: Icon(Icons.check_circle_rounded, color: ConstantColors.success, size: 38),
          ),
          const SizedBox(height: 14),
          Text('¡Viaje completado!', style: TextStyle(color: ConstantColors.textWhite, fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(pasajero, style: TextStyle(color: ConstantColors.textGrey, fontSize: 14)),
          const SizedBox(height: 20),

          // Tarifa grande
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 18),
            decoration: BoxDecoration(
              color: ConstantColors.success.withOpacity(0.10),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: ConstantColors.success.withOpacity(0.3)),
            ),
            child: Column(children: [
              Text('Ganancia del viaje', style: TextStyle(color: ConstantColors.textGrey, fontSize: 13)),
              const SizedBox(height: 6),
              Text('\$$tarifa', style: TextStyle(color: ConstantColors.success, fontSize: 36, fontWeight: FontWeight.w900)),
            ]),
          ),
          const SizedBox(height: 16),

          // Métricas
          Row(children: [
            Expanded(child: _resumenMetrica(Icons.straighten_rounded, 'Distancia', '$distancia km')),
            const SizedBox(width: 12),
            Expanded(child: _resumenMetrica(Icons.access_time_rounded, 'Duración', '$duracion min')),
          ]),
          const SizedBox(height: 16),

          // Ruta
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: ConstantColors.backgroundDark.withOpacity(0.5), borderRadius: BorderRadius.circular(14)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Container(width: 10, height: 10, decoration: BoxDecoration(color: Colors.green.shade500, shape: BoxShape.circle)),
                const SizedBox(width: 8),
                Expanded(child: Text(origen, style: TextStyle(color: ConstantColors.textWhite, fontSize: 13), maxLines: 1, overflow: TextOverflow.ellipsis)),
              ]),
              Padding(
                padding: const EdgeInsets.only(left: 4),
                child: Container(width: 2, height: 16, color: ConstantColors.borderColor),
              ),
              Row(children: [
                Container(width: 10, height: 10, decoration: BoxDecoration(color: ConstantColors.primaryViolet, shape: BoxShape.circle)),
                const SizedBox(width: 8),
                Expanded(child: Text(destino, style: TextStyle(color: ConstantColors.textWhite, fontSize: 13), maxLines: 1, overflow: TextOverflow.ellipsis)),
              ]),
            ]),
          ),
          const SizedBox(height: 20),

          SizedBox(
            width: double.infinity, height: 52,
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: ConstantColors.primaryBlue, foregroundColor: Colors.white,
                elevation: 0, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              ),
              onPressed: () => Navigator.pop(context),
              child: const Text('Continuar', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _resumenMetrica(IconData icon, String label, String value) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundDark.withOpacity(0.5),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(children: [
        Icon(icon, color: ConstantColors.primaryBlue, size: 20),
        const SizedBox(width: 10),
        Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: TextStyle(color: ConstantColors.textGrey, fontSize: 11)),
          const SizedBox(height: 2),
          Text(value, style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700, fontSize: 15)),
        ]),
      ]),
    );
  }

  Future<void> _loadDriverSession() async {
    final driverId = await DriverPrefs.getDriverId();
    final driverName = await DriverPrefs.getDriverName();
    final driverPhone = await DriverPrefs.getDriverPhone();
    final driverCedula = await DriverPrefs.getDriverCedula();
    final driverStatus = await DriverPrefs.getDriverStatus();
    final activeRide = await DriverPrefs.getActiveRide();

    if (!mounted) {
      return;
    }

    setState(() {
      _driverId = driverId;
      _driverName = driverName;
      _driverPhone = driverPhone;
      _driverCedula = driverCedula;
      _driverStatus = driverStatus;
      _activeRide = activeRide;
      _loading = false;
    });

    _restartLocationUpdates();
    _restartRequestPolling();
    _restartRidePolling();
    if (_activeRide != null) _iniciarActualizacionesMapa();
    _obtenerPosicionInicial(); // centrar mapa al entrar
  }

  // Obtiene GPS y centra el mapa sin llamar al servidor
  Future<void> _obtenerPosicionInicial() async {
    try {
      LocationPermission permiso = await Geolocator.checkPermission();
      if (permiso == LocationPermission.denied) {
        permiso = await Geolocator.requestPermission();
        if (permiso == LocationPermission.denied) return;
      }
      if (permiso == LocationPermission.deniedForever) return;

      final position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );
      if (!mounted) return;
      final nuevaPos = LatLng(position.latitude, position.longitude);
      setState(() {
        _miUbicacion = nuevaPos;
        _miPosActual = nuevaPos;
      });
      // Centrar mapa en la posición real del conductor
      WidgetsBinding.instance.addPostFrameCallback((_) {
        try { _mapController.move(nuevaPos, 15.0); } catch (_) {}
      });
    } catch (_) {}
  }

  Future<void> _toggleStatus() async {
    setState(() => _updatingStatus = true);

    final nextStatus = _driverStatus == 'libre' ? 'desconectado' : 'libre';
    final response = await _apiProvider.updateDriverStatus(
      conductorId: _driverId,
      estado: nextStatus,
    );

    if (!mounted) {
      return;
    }

    if (response['status'] != 'success') {
      setState(() => _updatingStatus = false);
      _showMessage(response['message']?.toString() ?? 'No se pudo actualizar el estado');
      return;
    }

    await DriverPrefs.saveDriverStatus(nextStatus);

    // Cuando el conductor se conecta: sincronizar FCM + arrancar servicio background
    if (nextStatus == 'libre') {
      // 1. Guardar token FCM (silencioso — nunca crashea la app)
      try {
        final fcmToken = await PushNotificationService.getToken();
        if (fcmToken.isNotEmpty) {
          await _apiProvider.syncDriverFcmToken(
            conductorId: _driverId,
            tokenFcm: fcmToken,
          );
          print('>>> [DRIVER_FCM] Token FCM sincronizado al conectarse');
        }
      } catch (e) {
        print('>>> [DRIVER_FCM] Error al sincronizar token (no crítico): $e');
      }

      // 2. (stub) BackgroundLocationService ya no usa foreground service nativo
      await BackgroundLocationService.start();
    } else {
      await BackgroundLocationService.stop();
    }

    if (!mounted) {
      return;
    }

    setState(() {
      _updatingStatus = false;
      _driverStatus = nextStatus;
      if (nextStatus != 'libre') {
        _pendingRequest = null;
      }
    });
    _restartLocationUpdates();
    _restartRequestPolling();
  }

  void _restartLocationUpdates() {
    _positionSubscription?.cancel();

    if (_driverId <= 0 || _driverStatus == 'desconectado') {
      BackgroundLocationService.stop();
      return;
    }

    // El Foreground Service se encarga de enviar la ubicación cada 15s al servidor
    BackgroundLocationService.start();
    
    // El Stream se encarga de actualizar el mapa LOCALMENTE en tiempo real (UI)
    _positionSubscription = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 5, 
      ),
    ).listen((Position position) {
      if (mounted) {
        final pos = LatLng(position.latitude, position.longitude);
        setState(() {
          _miPosActual = pos;
          _miUbicacion = pos;
          _lastLocationText = '${position.latitude.toStringAsFixed(5)}, ${position.longitude.toStringAsFixed(5)}';
        });
      }
    });

    _sendLocationUpdate(silent: true);
  }

  Future<void> _sendLocationUpdate({bool silent = false}) async {
    if (_sendingLocation || _driverId <= 0) {
      return;
    }
    _sendingLocation = true;

    if (!silent && mounted) {
      setState(() => _updatingLocation = true);
    }

    try {
      LocationPermission permiso = await Geolocator.checkPermission();
      if (permiso == LocationPermission.denied) {
        permiso = await Geolocator.requestPermission();
        if (permiso == LocationPermission.denied) return;
      }
      if (permiso == LocationPermission.deniedForever) return;

      final position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
        timeLimit: const Duration(seconds: 10),
      );

      // Guardar coordenadas para referencia de background
      await DriverPrefs.saveLastLocation(position.latitude, position.longitude);

      final response = await _apiProvider.updateDriverLocation(
        conductorId: _driverId,
        latitud: position.latitude,
        longitud: position.longitude,
      );

      if (!mounted) {
        return;
      }

      if (response['status'] != 'success') {
        if (!silent) {
          _showMessage(response['message']?.toString() ?? 'No se pudo actualizar la ubicacion');
        }
      } else {
        if (mounted) {
          final pos = LatLng(position.latitude, position.longitude);
          setState(() {
            _lastLocationText =
                'Lat ${position.latitude.toStringAsFixed(5)}, Lng ${position.longitude.toStringAsFixed(5)}';
            _miPosActual = pos;
            _miUbicacion = pos; // mantener mapa idle también actualizado
          });
        }
        if (!silent) {
          _showMessage('Ubicacion actualizada');
        }
      }
    } catch (e) {
      if (mounted && !silent) {
        _showMessage('No se pudo obtener la ubicacion: $e');
      }
    } finally {
      _sendingLocation = false;
      if (!silent && mounted) {
        setState(() => _updatingLocation = false);
      }
    }
  }

  Future<void> _updateLocation() => _sendLocationUpdate(silent: false);

  // ── Mapa: obtener GPS y calcular ruta ─────────────
  void _iniciarActualizacionesMapa() {
    _mapTimer?.cancel();
    if (_activeRide == null) return;
    _actualizarUbicacionYRuta();
    _mapTimer = Timer.periodic(const Duration(seconds: 15), (_) {
      _actualizarUbicacionYRuta();
    });
  }

  Future<void> _actualizarUbicacionYRuta() async {
    try {
      // Verificar y solicitar permisos de ubicación
      LocationPermission permiso = await Geolocator.checkPermission();
      if (permiso == LocationPermission.denied) {
        permiso = await Geolocator.requestPermission();
        if (permiso == LocationPermission.denied) {
          print('>>> [DRIVER_MAP] Permiso denegado');
          return;
        }
      }
      if (permiso == LocationPermission.deniedForever) {
        if (mounted) _showMessage('Activa el permiso de ubicación en ajustes del teléfono');
        return;
      }

      final position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );
      if (!mounted) return;
      final nuevaPos = LatLng(position.latitude, position.longitude);
      setState(() => _miUbicacion = nuevaPos);
      await _calcularRutaConductor();
      try { _mapController.move(nuevaPos, 14.5); } catch (_) {}
    } catch (e) {
      print('>>> [DRIVER_MAP] Error GPS: $e');
    }
  }

  Future<void> _calcularRutaConductor() async {
    final ride = _activeRide;
    final miPos = _miUbicacion;
    if (ride == null || miPos == null) return;

    final estado = ride['estado']?.toString() ?? '';

    // Fase 1 (aceptado / en_camino): ir al punto de recogida
    // Fase 2 (iniciado): ir al destino
    LatLng? puntoDestino;

    if (estado == 'aceptado' || estado == 'en_camino') {
      final lat = double.tryParse(ride['origen_lat']?.toString() ?? '');
      final lng = double.tryParse(ride['origen_lng']?.toString() ?? '');
      if (lat != null && lng != null) puntoDestino = LatLng(lat, lng);
    } else if (estado == 'iniciado') {
      final lat = double.tryParse(ride['destino_lat']?.toString() ?? '');
      final lng = double.tryParse(ride['destino_lng']?.toString() ?? '');
      if (lat != null && lng != null) puntoDestino = LatLng(lat, lng);
    }

    if (puntoDestino == null) return;

    if (mounted) setState(() => _calculandoRuta = true);
    final ruta = await OsmService.calcularRuta(miPos, puntoDestino);
    if (!mounted) return;
    setState(() {
      _rutaPuntos = ruta?.puntos ?? [miPos, puntoDestino!];
      _calculandoRuta = false;
    });
  }

  void _restartRequestPolling() {
    _requestPollingTimer?.cancel();
    // No buscamos nuevas solicitudes si el conductor no esta libre o ya tiene un viaje activo.
    if (_driverId <= 0 || _driverStatus != 'libre' || _activeRide != null) {
      return;
    }

    _fetchPendingRequest();
    _requestPollingTimer = Timer.periodic(Duration(seconds: 5), (_) {
      _fetchPendingRequest();
    });
  }

  // Verifica periódicamente si el pasajero canceló el viaje activo.
  void _restartRidePolling() {
    _ridePollingTimer?.cancel();
    if (_driverId <= 0 || _activeRide == null) return;

    _ridePollingTimer = Timer.periodic(Duration(seconds: 5), (_) {
      _fetchRideStatus();
    });
  }

  Future<void> _fetchRideStatus() async {
    final ride = _activeRide;
    if (ride == null) return;
    final viajeIdRaw = ride['viaje_id'];
    final viajeId = viajeIdRaw is int ? viajeIdRaw : int.tryParse(viajeIdRaw?.toString() ?? '') ?? 0;
    if (viajeId <= 0) return;

    final response = await _apiProvider.getRideStatus(viajeId: viajeId);
    if (!mounted) return;

    final estado = response['estado']?.toString() ?? '';
    if (estado == 'cancelado') {
      _ridePollingTimer?.cancel();
      await DriverPrefs.clearActiveRide();
      await DriverPrefs.saveDriverStatus('libre');
      if (!mounted) return;
      setState(() {
        _activeRide = null;
        _driverStatus = 'libre';
      });
      _restartRequestPolling();
      _restartLocationUpdates();
      _showMessage('El pasajero canceló el viaje');
    }
  }

  Future<void> _fetchPendingRequest() async {
    if (_fetchingRequest) return; // evitar solapamiento si el server tarda más de 5s
    _fetchingRequest = true;
    try {
      await _doFetchPendingRequest();
    } finally {
      _fetchingRequest = false;
    }
  }

  Future<void> _doFetchPendingRequest() async {
    final response = await _apiProvider.getDriverPendingRequest(
      conductorId: _driverId,
    );

    if (!mounted || _driverStatus != 'libre') {
      return;
    }

    if (response['status'] != 'success') {
      return;
    }

    if (response['found'] == true) {
      final solicitud = (response['solicitud'] as Map?)?.cast<String, dynamic>();
      setState(() {
        _pendingRequest = solicitud;
      });
      // Reproducir alerta y calcular ruta solo cuando llega una nueva solicitud
      if (solicitud != null) {
        final reqId = solicitud['viaje_id'] is int
            ? solicitud['viaje_id'] as int
            : int.tryParse(solicitud['viaje_id']?.toString() ?? '');
        if (reqId != null && reqId != _lastAlertedRequestId) {
          _lastAlertedRequestId = reqId;
          _playAlertSound();
          _calcularRutaHaciaSolicitud(solicitud);
        }
      }
    } else if (_pendingRequest != null) {
      setState(() {
        _pendingRequest = null;
        _rutaSolicitud = [];
      });
    }
  }

  Future<void> _handleRequest(String accion) async {
    final req = _pendingRequest;
    if (req == null || _respondingRequest) {
      return;
    }

    setState(() => _respondingRequest = true);

    final viajeIdRaw = req['viaje_id'];
    final viajeId = viajeIdRaw is int
        ? viajeIdRaw
        : int.tryParse(viajeIdRaw?.toString() ?? '') ?? 0;
    if (viajeId <= 0) {
      setState(() => _respondingRequest = false);
      return;
    }

    final response = await _apiProvider.respondDriverRequest(
      conductorId: _driverId,
      viajeId: viajeId,
      accion: accion,
    );

    if (!mounted) {
      return;
    }

    setState(() => _respondingRequest = false);

    if (response['status'] != 'success') {
      _showMessage(response['message']?.toString() ?? 'No se pudo responder la solicitud');
      return;
    }

    if (accion == 'aceptar') {
      await DriverPrefs.saveDriverStatus('ocupado');
      await DriverPrefs.saveActiveRide(<String, dynamic>{
        'viaje_id': req['viaje_id'],
        'pasajero_nombre': req['pasajero_nombre'],
        'pasajero_telefono': req['pasajero_telefono'],
        'origen_texto': req['origen_texto'],
        'destino_texto': req['destino_texto'],
        'distancia_km': req['distancia_km'],
        'duracion_min': req['duracion_min'],
        'tarifa_total': req['tarifa_total'],
        'origen_lat': req['origen_lat'],
        'origen_lng': req['origen_lng'],
        'destino_lat': req['destino_lat'],
        'destino_lng': req['destino_lng'],
        'estado': 'aceptado',
      });
      if (!mounted) {
        return;
      }
      setState(() {
        _driverStatus = 'ocupado';
        _activeRide = <String, dynamic>{
          'viaje_id': req['viaje_id'],
          'pasajero_nombre': req['pasajero_nombre'],
          'pasajero_telefono': req['pasajero_telefono'],
          'origen_texto': req['origen_texto'],
          'destino_texto': req['destino_texto'],
          'distancia_km': req['distancia_km'],
          'duracion_min': req['duracion_min'],
          'tarifa_total': req['tarifa_total'],
          'origen_lat': req['origen_lat'],
          'origen_lng': req['origen_lng'],
          'destino_lat': req['destino_lat'],
          'destino_lng': req['destino_lng'],
          'estado': 'aceptado',
        };
        _pendingRequest = null;
        _rutaPuntos = [];
        _rutaSolicitud = [];
      });
      _restartRequestPolling();
      _restartRidePolling();
      _iniciarActualizacionesMapa();
      _showMessage('Viaje aceptado');
      return;
    }

    setState(() {
      _pendingRequest = null;
      _rutaSolicitud = [];
    });
    _showMessage('Solicitud rechazada');
    _restartRequestPolling();
  }

  Future<void> _setRideStatus(String nextEstado) async {
    final ride = _activeRide;
    if (ride == null || _updatingRideStatus) return;
    final viajeIdRaw = ride['viaje_id'];
    final viajeId = viajeIdRaw is int
        ? viajeIdRaw
        : int.tryParse(viajeIdRaw?.toString() ?? '') ?? 0;
    if (viajeId <= 0) {
      _showMessage('Error: ID de viaje inválido');
      return;
    }

    setState(() => _updatingRideStatus = true);

    Map<String, dynamic> response;
    try {
      response = await _apiProvider.updateRideStatus(
        conductorId: _driverId,
        viajeId: viajeId,
        estado: nextEstado,
      );
    } catch (e) {
      print('>>> [SET_STATUS] Error: $e');
      if (mounted) {
        setState(() => _updatingRideStatus = false);
        _showMessage('Error de conexión al actualizar estado');
      }
      return;
    } finally {
      // Garantiza que _updatingRideStatus siempre se libere
      if (mounted) setState(() => _updatingRideStatus = false);
    }

    if (!mounted) return;

    if (response['status'] != 'success') {
      _showMessage(response['message']?.toString() ?? 'No se pudo actualizar el estado del viaje');
      return;
    }

    // Actualiza el estado local y persiste.
    final updated = Map<String, dynamic>.from(ride);
    updated['estado'] = nextEstado;
    await DriverPrefs.saveActiveRide(updated);

    if (!mounted) return;
    setState(() {
      _activeRide = updated;
    });

    if (nextEstado == 'terminado') {
      // Guardar datos del viaje completado para mostrar resumen
      final completedRideCopy = Map<String, dynamic>.from(updated);

      // Backend ya libera al conductor. Aqui limpiamos el viaje activo y volvemos a libre.
      _ridePollingTimer?.cancel();
      _mapTimer?.cancel();
      await DriverPrefs.clearActiveRide();
      await DriverPrefs.saveDriverStatus('libre');
      if (!mounted) return;
      setState(() {
        _activeRide = null;
        _driverStatus = 'libre';
        _rutaPuntos = [];
        _miUbicacion = null;
        _completedRide = completedRideCopy;
      });
      _restartRequestPolling();
      // Mostrar resumen de ganancias
      if (mounted) _mostrarResumenViaje(completedRideCopy);
    } else if (nextEstado == 'en_camino') {
      // Recalcular ruta hacia el pasajero
      setState(() => _rutaPuntos = []);
      _calcularRutaConductor();
      _showMessage('Estado: En camino al pasajero');
    } else if (nextEstado == 'iniciado') {
      // Recalcular ruta hacia el destino
      setState(() => _rutaPuntos = []);
      _calcularRutaConductor();
      _showMessage('Estado: Viaje iniciado');
    }
  }

  // ── Cancelar viaje desde el conductor ─────────────
  Future<void> _cancelarViaje() async {
    final ride = _activeRide;
    if (ride == null) return;

    final estado = ride['estado']?.toString() ?? '';
    if (estado == 'iniciado') {
      _showMessage('No puedes cancelar un viaje que ya está en curso');
      return;
    }

    // LISTA DE MOTIVOS
    final motivos = [
      'Tráfico muy intenso',
      'Emergencia personal',
      'Problema con el vehículo',
      'Otro inconveniente'
    ];

    final motivoSeleccionado = await showDialog<String>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: ConstantColors.backgroundCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        title: Text('¿Por qué cancelas?',
            style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w800, fontSize: 18)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: motivos.map((m) => ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(m, style: TextStyle(color: ConstantColors.textGrey, fontSize: 15)),
            trailing: Icon(Icons.chevron_right, color: ConstantColors.primaryBlue, size: 20),
            onTap: () => Navigator.pop(context, m),
          )).toList(),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text('No cancelar',
                style: TextStyle(color: ConstantColors.textGrey)),
          ),
        ],
      ),
    );

    if (motivoSeleccionado == null || !mounted) return;

    final viajeIdRaw = ride['viaje_id'];
    final viajeId = viajeIdRaw is int
        ? viajeIdRaw
        : int.tryParse(viajeIdRaw?.toString() ?? '') ?? 0;
    if (viajeId <= 0) return;

    setState(() => _updatingRideStatus = true);

    try {
      await _apiProvider.updateRideStatus(
        conductorId: _driverId,
        viajeId: viajeId,
        estado: 'cancelado',
      );
    } catch (_) {}

    _ridePollingTimer?.cancel();
    _mapTimer?.cancel();
    await DriverPrefs.clearActiveRide();
    await DriverPrefs.saveDriverStatus('libre');
    if (!mounted) return;

    setState(() {
      _activeRide = null;
      _driverStatus = 'libre';
      _rutaPuntos = [];
      _miUbicacion = null;
      _updatingRideStatus = false;
    });

    _restartRequestPolling();
    _showMessage('Viaje cancelado');
  }

  Widget _buildActiveRideCard() {
    final ride = _activeRide;
    if (ride == null) return SizedBox.shrink();

    final estado = ride['estado']?.toString() ?? 'aceptado';
    final pasajero = ride['pasajero_nombre']?.toString() ?? 'Pasajero';
    final telefono = ride['pasajero_telefono']?.toString() ?? '';
    final origen = ride['origen_texto']?.toString() ?? '';
    final destino = ride['destino_texto']?.toString() ?? '';

    String estadoLabel;
    if (estado == 'en_camino') {
      estadoLabel = 'En camino al pasajero';
    } else if (estado == 'iniciado') {
      estadoLabel = 'En viaje';
    } else if (estado == 'terminado') {
      estadoLabel = 'Terminado';
    } else {
      estadoLabel = 'Aceptado';
    }

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: ConstantColors.primaryBlue.withOpacity(0.35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(Icons.directions_car_filled_outlined, color: ConstantColors.primaryBlue),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Viaje activo',
                  style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: ConstantColors.primaryBlue.withOpacity(0.16),
                  borderRadius: BorderRadius.circular(30),
                ),
                child: Text(
                  estadoLabel,
                  style: TextStyle(color: ConstantColors.primaryBlue, fontWeight: FontWeight.w700),
                ),
              ),
            ],
          ),
          SizedBox(height: 14),
          Text(
            pasajero,
            style: TextStyle(
              color: ConstantColors.textWhite,
              fontSize: 20,
              fontWeight: FontWeight.w800,
            ),
          ),
          if (telefono.isNotEmpty) ...[
            SizedBox(height: 6),
            Text(
              telefono,
              style: TextStyle(color: ConstantColors.textGrey, fontSize: 13),
            ),
          ],
          SizedBox(height: 14),
          Text('Recogida', style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          SizedBox(height: 4),
          Text(origen, style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w600)),
          SizedBox(height: 12),
          Text('Destino', style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          SizedBox(height: 4),
          Text(destino, style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w600)),
          SizedBox(height: 16),
          Row(
            children: <Widget>[
              Expanded(
                child: OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    side: BorderSide(color: ConstantColors.primaryBlue),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  ),
                  onPressed: _updatingRideStatus || estado == 'en_camino' || estado == 'iniciado'
                      ? null
                      : () => _setRideStatus('en_camino'),
                  child: Text(
                    'En camino',
                    style: TextStyle(color: ConstantColors.primaryBlue, fontWeight: FontWeight.w700),
                  ),
                ),
              ),
              SizedBox(width: 10),
              Expanded(
                child: OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    side: BorderSide(color: ConstantColors.warning),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  ),
                  onPressed: _updatingRideStatus || estado == 'iniciado'
                      ? null
                      : () => _setRideStatus('iniciado'),
                  child: Text(
                    'Iniciar',
                    style: TextStyle(color: ConstantColors.warning, fontWeight: FontWeight.w700),
                  ),
                ),
              ),
            ],
          ),
          SizedBox(height: 10),
          SizedBox(
            width: double.infinity,
            height: 48,
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.redAccent,
                elevation: 0,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              onPressed: _updatingRideStatus ? null : () => _setRideStatus('terminado'),
              child: _updatingRideStatus
                  ? SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                      ),
                    )
                  : Text(
                      'Finalizar viaje',
                      style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800),
                    ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _logout() async {
    await DriverPrefs.clearDriverSession();
    if (!mounted) {
      return;
    }
    Navigator.pushNamedAndRemoveUntil(
      context,
      DriverLoginScreen.route,
      (Route<dynamic> route) => false,
    );
  }

  Widget _buildInfoTile(IconData icon, String label, String value) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Row(
        children: <Widget>[
          Icon(icon, color: ConstantColors.primaryBlue),
          SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  label,
                  style: TextStyle(
                    color: ConstantColors.textGrey,
                    fontSize: 12,
                  ),
                ),
                SizedBox(height: 4),
                Text(
                  value,
                  style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 15,
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

  Widget _buildPendingRequestCard() {
    final req = _pendingRequest;
    if (req == null) {
      return SizedBox.shrink();
    }

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: ConstantColors.primaryViolet.withOpacity(0.4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(Icons.notifications_active_outlined, color: ConstantColors.warning),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Solicitud de viaje entrante',
                  style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 18,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          SizedBox(height: 14),
          Text(
            req['pasajero_nombre']?.toString() ?? 'Pasajero',
            style: TextStyle(
              color: ConstantColors.textWhite,
              fontSize: 20,
              fontWeight: FontWeight.w800,
            ),
          ),
          SizedBox(height: 6),
          Text(
            req['pasajero_telefono']?.toString() ?? '',
            style: TextStyle(
              color: ConstantColors.textGrey,
              fontSize: 13,
            ),
          ),
          SizedBox(height: 14),
          Text(
            'Recogida',
            style: TextStyle(color: ConstantColors.textGrey, fontSize: 12),
          ),
          SizedBox(height: 4),
          Text(
            req['origen_texto']?.toString() ?? '',
            style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w600),
          ),
          SizedBox(height: 12),
          Text(
            'Destino',
            style: TextStyle(color: ConstantColors.textGrey, fontSize: 12),
          ),
          SizedBox(height: 4),
          Text(
            req['destino_texto']?.toString() ?? '',
            style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w600),
          ),
          SizedBox(height: 14),
          Row(
            children: <Widget>[
              Expanded(
                child: _buildMiniMetric(
                  'Distancia',
                  '${(req['distancia_km'] ?? 0).toString()} km',
                ),
              ),
              SizedBox(width: 10),
              Expanded(
                child: _buildMiniMetric(
                  'Duracion',
                  '${(req['duracion_min'] ?? 0).toString()} min',
                ),
              ),
              SizedBox(width: 10),
              Expanded(
                child: _buildMiniMetric(
                  'Ganancia',
                  '\$${(req['tarifa_total'] ?? 0).toString()}',
                ),
              ),
            ],
          ),
          SizedBox(height: 16),
          Row(
            children: <Widget>[
              Expanded(
                child: OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    side: BorderSide(color: Colors.redAccent),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14),
                    ),
                  ),
                  onPressed: _respondingRequest ? null : () => _handleRequest('rechazar'),
                  child: Text(
                    'Rechazar',
                    style: TextStyle(
                      color: Colors.redAccent,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ),
              SizedBox(width: 12),
              Expanded(
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: ConstantColors.success,
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14),
                    ),
                  ),
                  onPressed: _respondingRequest ? null : () => _handleRequest('aceptar'),
                  child: _respondingRequest
                      ? SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                          ),
                        )
                      : Text(
                          'Aceptar',
                          style: TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildMiniMetric(String label, String value) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundDark.withOpacity(0.45),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            label,
            style: TextStyle(color: ConstantColors.textGrey, fontSize: 11),
          ),
          SizedBox(height: 4),
          Text(
            value,
            style: TextStyle(
              color: ConstantColors.textWhite,
              fontSize: 13,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return Scaffold(
        backgroundColor: ConstantColors.backgroundDark,
        body: Center(
          child: CircularProgressIndicator(
            valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryBlue),
          ),
        ),
      );
    }

    // ── MODO VIAJE ACTIVO: mapa pantalla completa ──────────
    if (_activeRide != null) {
      return _buildVistaViajeActivo();
    }

    // ── MODO SOLICITUD ENTRANTE: mapa con ubicación del pasajero ──
    if (_pendingRequest != null) {
      return _buildVistaSolicitudEnMapa();
    }

    // ── MODO NORMAL: mapa estilo Uber ─────────────────────
    final miPos = _miUbicacion ?? const LatLng(-2.9001285, -79.0058965);
    final bool conectado = _driverStatus == 'libre';

    return Scaffold(
      key: _scaffoldKey,
      extendBodyBehindAppBar: true,
      body: SlidingUpPanel(
        controller: _mainPanelController,
        minHeight: 24,
        maxHeight: 220, // Altura ajustada para el botón y chips
        parallaxEnabled: false,
        color: Colors.transparent,
        onPanelSlide: (position) {
          setState(() {
            _mainPanelHeight = 24 + (position * (220 - 24));
          });
        },
        body: Stack(
          children: [
            // ── Mapa pantalla completa ──────────────────────
            FlutterMap(
              mapController: _mapController,
              options: MapOptions(initialCenter: miPos, initialZoom: 15.0),
              children: [
                TileLayer(
                  urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                  tileProvider: NetworkTileProvider(
                    headers: {
                      'User-Agent': 'GeoMoveApp/1.0 (com.sahdeepsingh.fu_uber)',
                    },
                  ),
                ),
                MarkerLayer(markers: [
                  Marker(
                    point: miPos,
                    width: 56,
                    height: 56,
                    child: Container(
                      decoration: BoxDecoration(
                        color: conectado ? ConstantColors.primaryBlue : Colors.grey.shade600,
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 3),
                        boxShadow: [BoxShadow(color: Colors.black45, blurRadius: 10)],
                      ),
                      child: const Icon(Icons.directions_car_rounded, color: Colors.white, size: 28),
                    ),
                  ),
                ]),
              ],
            ),

            // ── Barra superior: nombre + botones ───────────
            Positioned(
              top: MediaQuery.of(context).padding.top + 12,
              left: 16,
              right: 16,
              child: Row(
                children: [
                  // Nombre del conductor
                  Expanded(
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                      decoration: BoxDecoration(
                        color: ConstantColors.backgroundCard.withOpacity(0.95),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: ConstantColors.borderColor),
                        boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 8)],
                      ),
                      child: Row(children: [
                        CircleAvatar(
                          radius: 16,
                          backgroundColor: ConstantColors.primaryBlue.withOpacity(0.2),
                          child: Icon(Icons.person, color: ConstantColors.primaryBlue, size: 18),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            _driverName,
                            style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700, fontSize: 14),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ]),
                    ),
                  ),
                  const SizedBox(width: 8),
                  // Botón perfil
                  _btnTopCircle(
                    icon: Icons.person_rounded,
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const DriverProfileScreen()),
                    ),
                  ),
                  const SizedBox(width: 8),
                  // Botón historial
                  _btnTopCircle(
                    icon: Icons.history_rounded,
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => DriverTripHistoryScreen(driverId: _driverId)),
                    ),
                  ),
                  const SizedBox(width: 8),
                  // Botón logout
                  _btnTopCircle(icon: Icons.logout_rounded, onTap: _logout),
                ],
              ),
            ),

            // ── Badge estado (centro-superior) ─────────────
            Positioned(
              top: MediaQuery.of(context).padding.top + 80,
              left: 0,
              right: 0,
              child: Center(
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 300),
                  padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                  decoration: BoxDecoration(
                    color: conectado
                        ? ConstantColors.success.withOpacity(0.92)
                        : Colors.grey.shade700.withOpacity(0.92),
                    borderRadius: BorderRadius.circular(30),
                    boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 8)],
                  ),
                  child: Text(
                    conectado ? '● En línea — esperando viajes' : '● Desconectado',
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 13),
                  ),
                ),
              ),
            ),

            // ── Botón centrar mapa ──────────────────────────
            Positioned(
              bottom: _mainPanelHeight + 16,
              right: 16,
              child: GestureDetector(
                onTap: () {
                  try { _mapController.move(miPos, 15.0); } catch (_) {}
                },
                child: Container(
                  width: 46, height: 46,
                  decoration: BoxDecoration(
                    color: ConstantColors.backgroundCard,
                    shape: BoxShape.circle,
                    border: Border.all(color: ConstantColors.borderColor),
                    boxShadow: [BoxShadow(color: Colors.black45, blurRadius: 8)],
                  ),
                  child: Icon(Icons.my_location_rounded, color: ConstantColors.primaryBlue, size: 22),
                ),
              ),
            ),
          ],
        ),

        // ── Panel inferior ─────────────────────────────
        panel: Container(
          padding: EdgeInsets.fromLTRB(20, 12, 20, MediaQuery.of(context).padding.bottom + 20),
          decoration: BoxDecoration(
            color: ConstantColors.backgroundCard,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
            border: Border.all(color: ConstantColors.borderColor),
            boxShadow: [BoxShadow(color: Colors.black54, blurRadius: 20, offset: const Offset(0, -4))],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Handle
              Container(
                width: 40, height: 4,
                margin: const EdgeInsets.only(bottom: 20),
                decoration: BoxDecoration(
                  color: Colors.white24,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),

              // Botón principal: Conectar / Desconectar
              SizedBox(
                width: double.infinity,
                height: 56,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: conectado ? Colors.redAccent : ConstantColors.success,
                    elevation: 0,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                  ),
                  onPressed: _updatingStatus ? null : _toggleStatus,
                  child: _updatingStatus
                      ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, valueColor: AlwaysStoppedAnimation<Color>(Colors.white)))
                      : Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                          Icon(conectado ? Icons.power_settings_new_rounded : Icons.check_circle_rounded,
                              color: Colors.white, size: 22),
                          const SizedBox(width: 10),
                          Text(
                            conectado ? 'Desconectarme' : 'Conectarme para recibir viajes',
                            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15),
                          ),
                        ]),
                ),
              ),

              const SizedBox(height: 14),

              // Info rápida: teléfono y última ubicación
              Row(children: [
                Expanded(child: _buildInfoChip(Icons.phone_rounded, _driverPhone)),
                const SizedBox(width: 10),
                Expanded(child: _buildInfoChip(Icons.location_on_rounded, _lastLocationText)),
              ]),
            ],
          ),
        ),
      ),
    );
  }

  // Botón circular para la barra superior
  Widget _btnTopCircle({required IconData icon, required VoidCallback onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 44, height: 44,
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard.withOpacity(0.95),
          shape: BoxShape.circle,
          border: Border.all(color: ConstantColors.borderColor),
          boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 8)],
        ),
        child: Icon(icon, color: ConstantColors.textWhite, size: 20),
      ),
    );
  }

  // Chip de info pequeño para el panel inferior
  Widget _buildInfoChip(IconData icon, String texto) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundDark.withOpacity(0.5),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Row(children: [
        Icon(icon, color: ConstantColors.textGrey, size: 14),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            texto,
            style: TextStyle(color: ConstantColors.textGrey, fontSize: 11),
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ]),
    );
  }

  // ── Vista de solicitud entrante con mapa ───────────────
  Widget _buildVistaSolicitudEnMapa() {
    final req = _pendingRequest!;
    final pasajero = req['pasajero_nombre']?.toString() ?? 'Pasajero';
    final telefono = req['pasajero_telefono']?.toString() ?? '';
    final origen   = req['origen_texto']?.toString()    ?? '';
    final destino  = req['destino_texto']?.toString()   ?? '';
    final tarifa   = req['tarifa_total']?.toString()    ?? '0';
    final distancia = req['distancia_km']?.toString()   ?? '0';
    final duracion  = req['duracion_min']?.toString()   ?? '0';

    final pickupLat = double.tryParse(req['origen_lat']?.toString() ?? '');
    final pickupLng = double.tryParse(req['origen_lng']?.toString() ?? '');
    final pickupPoint = (pickupLat != null && pickupLng != null) ? LatLng(pickupLat, pickupLng) : null;

    final centroMapa = pickupPoint ?? _miPosActual ?? const LatLng(-2.9001285, -79.0058965);

    final double panelMaxHeight = (telefono.isNotEmpty) ? 460 : 400;

    return Scaffold(
      extendBodyBehindAppBar: true,
      body: SlidingUpPanel(
        controller: _requestPanelController,
        minHeight: 24,
        maxHeight: panelMaxHeight,
        parallaxEnabled: false,
        color: Colors.transparent,
        onPanelSlide: (position) {
          setState(() {
            _requestPanelHeight = 24 + (position * (panelMaxHeight - 24));
          });
        },
        body: Stack(children: [
          // ── Mapa pantalla completa ──────────────────────
          FlutterMap(
            mapController: _solicitudMapController,
            options: MapOptions(initialCenter: centroMapa, initialZoom: 14.5),
            children: [
              TileLayer(
                urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                tileProvider: NetworkTileProvider(
                  headers: {
                    'User-Agent': 'GeoMoveApp/1.0 (com.sahdeepsingh.fu_uber)',
                  },
                ),
              ),
              if (_rutaSolicitud.isNotEmpty)
                PolylineLayer(polylines: [
                  Polyline(points: _rutaSolicitud, color: ConstantColors.primaryBlue, strokeWidth: 5.0),
                ]),
              MarkerLayer(markers: [
                if (pickupPoint != null)
                  Marker(
                    point: pickupPoint, width: 52, height: 68,
                    child: Column(children: [
                      Container(
                        width: 44, height: 44,
                        decoration: BoxDecoration(
                          color: Colors.green.shade600,
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 2.5),
                          boxShadow: [BoxShadow(color: Colors.black45, blurRadius: 8)],
                        ),
                        child: const Icon(Icons.person_pin_rounded, color: Colors.white, size: 26),
                      ),
                      Container(width: 2, height: 18, color: Colors.green.shade600),
                    ]),
                  ),
                if (_miPosActual != null)
                  Marker(
                    point: _miPosActual!, width: 44, height: 44,
                    child: Container(
                      decoration: BoxDecoration(
                        color: ConstantColors.primaryBlue,
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 2),
                        boxShadow: [BoxShadow(color: Colors.black45, blurRadius: 6)],
                      ),
                      child: const Icon(Icons.directions_car_rounded, color: Colors.white, size: 24),
                    ),
                  ),
              ]),
            ],
          ),

          // ── Botón salir (arriba derecha) ──
          Positioned(
            top: MediaQuery.of(context).padding.top + 12,
            right: 16,
            child: GestureDetector(
              onTap: _logout,
              child: Container(
                width: 44, height: 44,
                decoration: BoxDecoration(
                  color: ConstantColors.backgroundCard.withOpacity(0.9),
                  shape: BoxShape.circle,
                  boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 8)],
                ),
                child: Icon(Icons.logout_rounded, color: ConstantColors.textWhite, size: 20),
              ),
            ),
          ),

          // ── Badge "Nueva solicitud" ──
          Positioned(
            top: MediaQuery.of(context).padding.top + 12,
            left: 16,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              decoration: BoxDecoration(
                color: ConstantColors.backgroundCard.withOpacity(0.93),
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: ConstantColors.warning.withOpacity(0.5)),
                boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 8)],
              ),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                if (_calculandoRutaSolicitud)
                  SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.warning)))
                else
                  Icon(Icons.notifications_active_rounded, color: ConstantColors.warning, size: 16),
                const SizedBox(width: 8),
                Text(
                  _calculandoRutaSolicitud ? 'Calculando ruta...' : 'Nueva solicitud de viaje',
                  style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700, fontSize: 13),
                ),
              ]),
            ),
          ),

          // Botón Recenter
          Positioned(
            bottom: _requestPanelHeight + 16,
            right: 16,
            child: GestureDetector(
              onTap: () {
                try { _solicitudMapController.move(centroMapa, 14.5); } catch (_) {}
              },
              child: Container(
                width: 44, height: 44,
                decoration: BoxDecoration(
                  color: ConstantColors.backgroundCard,
                  shape: BoxShape.circle,
                  border: Border.all(color: ConstantColors.borderColor),
                  boxShadow: [BoxShadow(color: Colors.black45, blurRadius: 8)],
                ),
                child: Icon(Icons.my_location_rounded, color: ConstantColors.primaryBlue, size: 22),
              ),
            ),
          ),
        ]),

        // ── Panel inferior deslizable ──
        panel: Container(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
          decoration: BoxDecoration(
            color: ConstantColors.backgroundCard,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
            border: Border.all(color: ConstantColors.borderColor),
            boxShadow: [BoxShadow(color: Colors.black54, blurRadius: 20, offset: const Offset(0, -4))],
          ),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            // Handle
            Container(width: 40, height: 4, margin: const EdgeInsets.only(bottom: 20),
                decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(4))),

            // Pasajero
            Row(children: [
              CircleAvatar(
                radius: 22,
                backgroundColor: ConstantColors.primaryViolet.withOpacity(0.2),
                child: Icon(Icons.person, color: ConstantColors.primaryViolet, size: 24),
              ),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(pasajero, style: TextStyle(color: ConstantColors.textWhite, fontSize: 16, fontWeight: FontWeight.w700)),
                if (telefono.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(telefono, style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
                ],
              ])),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                decoration: BoxDecoration(
                  color: ConstantColors.success.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text('\$$tarifa', style: TextStyle(color: ConstantColors.success, fontWeight: FontWeight.w800, fontSize: 16)),
              ),
            ]),
            const SizedBox(height: 12),

            // Ruta texto
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: ConstantColors.backgroundDark.withOpacity(0.4),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(children: [
                Row(children: [
                  Container(width: 8, height: 8, decoration: BoxDecoration(color: Colors.green.shade500, shape: BoxShape.circle)),
                  const SizedBox(width: 8),
                  Expanded(child: Text(origen, style: TextStyle(color: ConstantColors.textWhite, fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis)),
                ]),
                const SizedBox(height: 8),
                Row(children: [
                  Container(width: 8, height: 8, decoration: BoxDecoration(color: ConstantColors.primaryViolet, shape: BoxShape.circle)),
                  const SizedBox(width: 8),
                  Expanded(child: Text(destino, style: TextStyle(color: ConstantColors.textWhite, fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis)),
                ]),
              ]),
            ),
            const SizedBox(height: 10),

            // Métricas
            Row(children: [
              _buildMiniMetric('Distancia', '$distancia km'),
              const SizedBox(width: 10),
              _buildMiniMetric('Duración', '$duracion min'),
              const SizedBox(width: 10),
              _buildMiniMetric('Ganancia', '\$$tarifa'),
            ]),
            const SizedBox(height: 18),

            // Botón llamar (si hay teléfono)
            if (telefono.isNotEmpty) ...[
              GestureDetector(
                onTap: () async {
                  final uri = Uri.parse('tel:$telefono');
                  if (await canLaunchUrl(uri)) {
                    await launchUrl(uri);
                  } else {
                    _showMessage('No se pudo abrir el marcador');
                  }
                },
                child: Container(
                  width: double.infinity, height: 48,
                  margin: const EdgeInsets.only(bottom: 12),
                  decoration: BoxDecoration(
                    color: ConstantColors.primaryBlue.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: ConstantColors.primaryBlue.withOpacity(0.5)),
                  ),
                  child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                    Icon(Icons.phone_rounded, color: ConstantColors.primaryBlue, size: 18),
                    const SizedBox(width: 8),
                    Text('Llamar al pasajero', style: TextStyle(color: ConstantColors.primaryBlue, fontWeight: FontWeight.w700, fontSize: 13)),
                  ]),
                ),
              ),
            ],

            // Botones aceptar / rechazar
            Row(children: [
              Expanded(
                child: OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    side: const BorderSide(color: Colors.redAccent),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  onPressed: _respondingRequest ? null : () => _handleRequest('rechazar'),
                  child: const Text('Rechazar', style: TextStyle(color: Colors.redAccent, fontWeight: FontWeight.w700, fontSize: 15)),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                flex: 2,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: ConstantColors.success,
                    elevation: 0,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  onPressed: _respondingRequest ? null : () => _handleRequest('aceptar'),
                  child: _respondingRequest
                      ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, valueColor: AlwaysStoppedAnimation<Color>(Colors.white)))
                      : const Text('Aceptar viaje', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15)),
                ),
              ),
            ]),
          ]),
        ),
      ),
    );
  }

  // ── Llamar al pasajero ─────────────────────────────────
  Future<void> _llamarPasajero() async {
    final telefono = _activeRide?['pasajero_telefono']?.toString() ?? '';
    if (telefono.isEmpty) {
      _showMessage('No hay número de teléfono disponible');
      return;
    }
    final uri = Uri.parse('tel:$telefono');
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri);
    } else {
      _showMessage('No se pudo abrir el marcador');
    }
  }

  // ── Abrir Google Maps o Waze con el destino ────────────
  Future<void> _abrirNavegacion({bool waze = false}) async {
    final ride = _activeRide;
    if (ride == null) return;

    // Usamos destino durante viaje iniciado, origen durante en_camino/aceptado
    final estado = ride['estado']?.toString() ?? 'aceptado';
    final lat = estado == 'iniciado'
        ? double.tryParse(ride['destino_lat']?.toString() ?? '')
        : double.tryParse(ride['origen_lat']?.toString() ?? '');
    final lng = estado == 'iniciado'
        ? double.tryParse(ride['destino_lng']?.toString() ?? '')
        : double.tryParse(ride['origen_lng']?.toString() ?? '');

    if (lat == null || lng == null) {
      _showMessage('Coordenadas no disponibles');
      return;
    }

    Uri uri;
    if (waze) {
      uri = Uri.parse('https://waze.com/ul?ll=$lat,$lng&navigate=yes');
    } else {
      uri = Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$lat,$lng&travelmode=driving');
    }

    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } else {
      _showMessage('No se pudo abrir la aplicación de navegación');
    }
  }

  Widget _buildVistaViajeActivo() {
    final ride = _activeRide!;
    final estado = ride['estado']?.toString() ?? 'aceptado';
    final pasajero = ride['pasajero_nombre']?.toString() ?? 'Pasajero';
    final origen = ride['origen_texto']?.toString() ?? '';
    final destino = ride['destino_texto']?.toString() ?? '';
    final tarifa = ride['tarifa_total']?.toString() ?? '0';

    final miPos = _miUbicacion ?? const LatLng(-2.9001285, -79.0058965);

    final pickupLat = double.tryParse(ride['origen_lat']?.toString() ?? '');
    final pickupLng = double.tryParse(ride['origen_lng']?.toString() ?? '');
    final pickupPoint = (pickupLat != null && pickupLng != null) ? LatLng(pickupLat, pickupLng) : null;

    final destLat = double.tryParse(ride['destino_lat']?.toString() ?? '');
    final destLng = double.tryParse(ride['destino_lng']?.toString() ?? '');
    final destPoint = (destLat != null && destLng != null) ? LatLng(destLat, destLng) : null;

    final colorRuta = estado == 'iniciado' ? ConstantColors.primaryViolet : ConstantColors.primaryBlue;

    String estadoLabel;
    Color estadoColor;
    if (estado == 'iniciado') {
      estadoLabel = 'En viaje';
      estadoColor = ConstantColors.primaryViolet;
    } else if (estado == 'en_camino') {
      estadoLabel = 'En camino al pasajero';
      estadoColor = Colors.orangeAccent;
    } else {
      estadoLabel = 'Viaje aceptado';
      estadoColor = ConstantColors.primaryBlue;
    }

    // Altura del panel según estado
    final double panelMaxHeight = (estado == 'iniciado') ? 360 : 460;

    return Scaffold(
      extendBodyBehindAppBar: true,
      body: SlidingUpPanel(
        controller: _activeRidePanelController,
        minHeight: 24,
        maxHeight: panelMaxHeight,
        parallaxEnabled: false,
        color: Colors.transparent,
        onPanelSlide: (position) {
          setState(() {
            _currentPanelHeight = 24 + (position * (panelMaxHeight - 24));
          });
        },
        body: Stack(
          children: [
            // ── Mapa pantalla completa ──────────────────────
            FlutterMap(
              mapController: _mapController,
              options: MapOptions(initialCenter: miPos, initialZoom: 14.5),
              children: [
                TileLayer(
                  urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                  tileProvider: NetworkTileProvider(
                    headers: {
                      'User-Agent': 'GeoMoveApp/1.0 (com.sahdeepsingh.fu_uber)',
                    },
                  ),
                ),
                if (_rutaPuntos.isNotEmpty)
                  PolylineLayer(polylines: [
                    Polyline(points: _rutaPuntos, color: colorRuta, strokeWidth: 5.5),
                  ]),
                MarkerLayer(markers: [
                  // Mi posición (carro)
                  Marker(
                    point: miPos, width: 48, height: 48,
                    child: Container(
                      decoration: BoxDecoration(
                        color: ConstantColors.primaryBlue,
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 2.5),
                        boxShadow: [BoxShadow(color: Colors.black45, blurRadius: 8)],
                      ),
                      child: const Icon(Icons.directions_car_rounded, color: Colors.white, size: 26),
                    ),
                  ),
                  // Punto de recogida (persona verde)
                  if (pickupPoint != null)
                    Marker(
                      point: pickupPoint, width: 44, height: 56,
                      child: Column(children: [
                        Container(
                          width: 38, height: 38,
                          decoration: BoxDecoration(
                            color: Colors.green.shade600,
                            shape: BoxShape.circle,
                            border: Border.all(color: Colors.white, width: 2),
                            boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 6)],
                          ),
                          child: const Icon(Icons.person, color: Colors.white, size: 22),
                        ),
                        Container(width: 2, height: 14, color: Colors.green.shade600),
                      ]),
                    ),
                  // Punto de destino (bandera violeta) — solo en viaje iniciado
                  if (destPoint != null && estado == 'iniciado')
                    Marker(
                      point: destPoint, width: 44, height: 56,
                      child: Column(children: [
                        Container(
                          width: 38, height: 38,
                          decoration: BoxDecoration(
                            color: ConstantColors.primaryViolet,
                            shape: BoxShape.circle,
                            border: Border.all(color: Colors.white, width: 2),
                            boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 6)],
                          ),
                          child: const Icon(Icons.flag_rounded, color: Colors.white, size: 22),
                        ),
                        Container(width: 2, height: 14, color: ConstantColors.primaryViolet),
                      ]),
                    ),
                ]),
              ],
            ),

            // ── Badge estado (arriba izquierda) ────────────
            Positioned(
              top: MediaQuery.of(context).padding.top + 12,
              left: 16,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                decoration: BoxDecoration(
                  color: ConstantColors.backgroundCard.withOpacity(0.93),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: estadoColor.withOpacity(0.5)),
                  boxShadow: [BoxShadow(color: Colors.black38, blurRadius: 8)],
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  if (_calculandoRuta)
                    SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, valueColor: AlwaysStoppedAnimation<Color>(estadoColor)))
                  else
                    Container(width: 8, height: 8, decoration: BoxDecoration(color: estadoColor, shape: BoxShape.circle)),
                  const SizedBox(width: 8),
                  Text(estadoLabel, style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700, fontSize: 13)),
                ]),
              ),
            ),

            // ── Botón centrar mapa ──────────────────────────
            Positioned(
              bottom: _currentPanelHeight + 16,
              right: 16,
              child: GestureDetector(
                onTap: () {
                  try { _mapController.move(miPos, 14.5); } catch (_) {}
                },
                child: Container(
                  width: 44, height: 44,
                  decoration: BoxDecoration(
                    color: ConstantColors.backgroundCard,
                    shape: BoxShape.circle,
                    border: Border.all(color: ConstantColors.borderColor),
                    boxShadow: [BoxShadow(color: Colors.black45, blurRadius: 8)],
                  ),
                  child: Icon(Icons.my_location_rounded, color: ConstantColors.primaryBlue, size: 22),
                ),
              ),
            ),
          ],
        ),

        // ── Panel inferior deslizable ─────────────────────
        panel: Container(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
          decoration: BoxDecoration(
            color: ConstantColors.backgroundCard,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
            border: Border.all(color: ConstantColors.borderColor),
            boxShadow: [BoxShadow(color: Colors.black54, blurRadius: 20, offset: const Offset(0, -4))],
          ),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            // Handle
            Container(
              width: 40, height: 4, margin: const EdgeInsets.only(bottom: 20),
              decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(4)),
            ),

            // Pasajero + tarifa
            Row(children: [
              CircleAvatar(
                radius: 22,
                backgroundColor: ConstantColors.primaryBlue.withOpacity(0.2),
                child: Icon(Icons.person, color: ConstantColors.primaryBlue, size: 24),
              ),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(pasajero, style: TextStyle(color: ConstantColors.textWhite, fontSize: 16, fontWeight: FontWeight.w700)),
                const SizedBox(height: 3),
                Text(
                  estado == 'iniciado' ? 'Destino: $destino' : 'Recogida: $origen',
                  style: TextStyle(color: ConstantColors.textGrey, fontSize: 12),
                  overflow: TextOverflow.ellipsis,
                ),
              ])),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                decoration: BoxDecoration(
                  color: ConstantColors.success.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text('\$$tarifa', style: TextStyle(color: ConstantColors.success, fontWeight: FontWeight.w800, fontSize: 16)),
              ),
            ]),

            const SizedBox(height: 18),

            // ── Botones de contacto y navegación ─────────────
            Row(children: [
              // Llamar al pasajero
              Expanded(
                child: GestureDetector(
                  onTap: _llamarPasajero,
                  child: Container(
                    height: 50,
                    decoration: BoxDecoration(
                      color: ConstantColors.primaryBlue.withOpacity(0.12),
                      borderRadius: BorderRadius.circular(15),
                      border: Border.all(color: ConstantColors.primaryBlue.withOpacity(0.5)),
                    ),
                    child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Icon(Icons.phone_rounded, color: ConstantColors.primaryBlue, size: 18),
                      const SizedBox(width: 6),
                      const Text('Llamar', style: TextStyle(color: ConstantColors.primaryBlue, fontWeight: FontWeight.w700, fontSize: 14)),
                    ]),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              // Chat con el pasajero
              Expanded(
                child: GestureDetector(
                  onTap: () {
                    final ride = _activeRide;
                    if (ride == null) return;
                    final viajeIdRaw = ride['viaje_id'];
                    final viajeId = viajeIdRaw is int
                        ? viajeIdRaw
                        : int.tryParse(viajeIdRaw?.toString() ?? '') ?? 0;
                    Navigator.pushNamed(
                      context,
                      ChatScreen.route,
                      arguments: {
                        'viaje_id':     viajeId,
                        'remitente':    'conductor',
                        'nombre_otro':  ride['pasajero_nombre']?.toString() ?? 'Pasajero',
                        'telefono_otro': ride['pasajero_telefono']?.toString() ?? '',
                      },
                    );
                  },
                  child: Container(
                    height: 50,
                    decoration: BoxDecoration(
                      color: ConstantColors.primaryViolet.withOpacity(0.12),
                      borderRadius: BorderRadius.circular(15),
                      border: Border.all(color: ConstantColors.primaryViolet.withOpacity(0.5)),
                    ),
                    child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Icon(Icons.chat_rounded, color: ConstantColors.primaryViolet, size: 18),
                      const SizedBox(width: 6),
                      const Text('Chat', style: TextStyle(color: ConstantColors.primaryViolet, fontWeight: FontWeight.w700, fontSize: 14)),
                    ]),
                  ),
                ),
              ),
            ]),

            const SizedBox(height: 12),

            Row(children: [
              // Abrir en Maps
              Expanded(
                child: GestureDetector(
                  onTap: () => _abrirNavegacion(waze: false),
                  child: Container(
                    height: 50,
                    decoration: BoxDecoration(
                      color: ConstantColors.success.withOpacity(0.12),
                      borderRadius: BorderRadius.circular(15),
                      border: Border.all(color: ConstantColors.success.withOpacity(0.4)),
                    ),
                    child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Icon(Icons.map_rounded, color: ConstantColors.success, size: 18),
                      const SizedBox(width: 6),
                      const Text('Maps', style: TextStyle(color: ConstantColors.success, fontWeight: FontWeight.w700, fontSize: 14)),
                    ]),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              // Abrir en Waze
              Expanded(
                child: GestureDetector(
                  onTap: () => _abrirNavegacion(waze: true),
                  child: Container(
                    height: 50,
                    decoration: BoxDecoration(
                      color: Colors.cyan.withOpacity(0.10),
                      borderRadius: BorderRadius.circular(15),
                      border: Border.all(color: Colors.cyan.withOpacity(0.5)),
                    ),
                    child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Icon(Icons.navigation_rounded, color: Colors.cyan.shade300, size: 18),
                      SizedBox(width: 6),
                      Text('Waze', style: TextStyle(color: Colors.cyan.shade300, fontWeight: FontWeight.w700, fontSize: 14)),
                    ]),
                  ),
                ),
              ),
            ]),

            const SizedBox(height: 16),
            Divider(color: ConstantColors.borderColor.withOpacity(0.5)),
            const SizedBox(height: 16),

            // Botones de acción
            Row(children: [
              if (estado == 'aceptado')
                Expanded(
                  child: _botonAccion(
                    label: 'En camino',
                    icono: Icons.directions_car_rounded,
                    color: ConstantColors.primaryBlue,
                    cargando: _updatingRideStatus,
                    onTap: () => _setRideStatus('en_camino'),
                  ),
                ),
              if (estado == 'aceptado') const SizedBox(width: 10),

              if (estado == 'aceptado' || estado == 'en_camino')
                Expanded(
                  child: _botonAccion(
                    label: estado == 'en_camino' ? '¡He llegado!' : 'Ya voy',
                    icono: Icons.flag_rounded,
                    color: Colors.orangeAccent,
                    cargando: _updatingRideStatus,
                    onTap: () => _setRideStatus(estado == 'en_camino' ? 'iniciado' : 'en_camino'),
                  ),
                ),

              if (estado == 'iniciado') ...[
                Expanded(
                  child: _botonAccion(
                    label: 'Finalizar viaje',
                    icono: Icons.check_circle_rounded,
                    color: ConstantColors.success,
                    cargando: _updatingRideStatus,
                    onTap: () => _setRideStatus('terminado'),
                  ),
                ),
              ],
            ]),

            if (estado != 'iniciado') ...[
              const SizedBox(height: 6),
              Center(
                child: TextButton.icon(
                  onPressed: _updatingRideStatus ? null : _cancelarViaje,
                  icon: Icon(Icons.cancel_outlined, color: Colors.redAccent.withOpacity(0.8), size: 16),
                  label: Text(
                    'Cancelar viaje',
                    style: TextStyle(
                      color: Colors.redAccent.withOpacity(0.8),
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ),
            ],
          ]),
        ),
      ),
    );
  }

  Widget _botonAccion({
    required String label,
    required IconData icono,
    required Color color,
    required bool cargando,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: cargando ? null : onTap,
      child: Container(
        height: 52,
        decoration: BoxDecoration(
          color: color.withOpacity(0.12),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: color.withOpacity(0.5)),
        ),
        child: cargando
            ? Center(child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, valueColor: AlwaysStoppedAnimation<Color>(color))))
            : Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(icono, color: color, size: 18),
                SizedBox(width: 6),
                Text(label, style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 13)),
              ]),
      ),
    );
  }
}
