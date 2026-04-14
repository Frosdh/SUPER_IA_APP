import 'dart:async';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/Core/Preferences/DriverPrefs.dart';
import 'package:super_ia/Core/ProviderModels/PermissionHandlerModel.dart';
import 'package:super_ia/Core/Services/BackgroundLocationService.dart';
import 'package:super_ia/UI/views/DriverHomeScreen.dart';
import 'package:super_ia/UI/views/OsmMapScreen.dart';

class LocationPermissionScreen extends StatefulWidget {
  static const String route = 'locationScreen';

  @override
  _LocationPermissionScreenState createState() =>
      _LocationPermissionScreenState();
}

class _LocationPermissionScreenState extends State<LocationPermissionScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController loadingController;
  late final Animation<double> animation;

  bool _yaNavego = false;
  bool _autoPermissionRequested = false;
  bool _autoLocationAttempted = false;
  bool _isGettingLocation = false;
  String? _locationError;

  @override
  void initState() {
    super.initState();

    loadingController =
        AnimationController(vsync: this, duration: const Duration(seconds: 5));
    animation = Tween<double>(begin: 0, end: 40).animate(
      CurvedAnimation(parent: loadingController, curve: Curves.easeInOutCirc),
    );
    loadingController.addStatusListener((status) {
      if (status == AnimationStatus.completed) {
        loadingController.forward(from: 0);
      }
    });
    loadingController.forward();

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      Provider.of<PermissionHandlerModel>(context, listen: false).bootstrap();
    });
  }

  @override
  void dispose() {
    loadingController.dispose();
    super.dispose();
  }

  Widget SpringEffect() {
    return Align(
      alignment: const Alignment(0, -0.25),
      child: IgnorePointer(
        child: AnimatedBuilder(
          animation: animation,
          builder: (context, _) {
            final size = animation.value;
            return Container(
              width: size,
              height: size,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withOpacity(0.06),
              ),
            );
          },
        ),
      ),
    );
  }

  Future<void> _intentarNavegar() async {
    if (_yaNavego || !mounted || _isGettingLocation) return;
    setState(() {
      _isGettingLocation = true;
      _locationError = null;
    });

    try {
      Position? position;

      // Si el sistema ya tiene una ubicación previa, úsala para no bloquear.
      try {
        position = await Geolocator.getLastKnownPosition(
          forceAndroidLocationManager: true,
        );
        position ??= await Geolocator.getLastKnownPosition();
      } catch (_) {
        // Ignorar.
      }

      // Intento con alta precisión.
      try {
        position = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.high,
          forceAndroidLocationManager: true,
          timeLimit: const Duration(seconds: 20),
        );
      } catch (_) {
        // Fallback: baja precisión.
        try {
          position = await Geolocator.getCurrentPosition(
            desiredAccuracy: LocationAccuracy.low,
            forceAndroidLocationManager: true,
            timeLimit: const Duration(seconds: 15),
          );
        } catch (_) {
          // Fallback: primer valor del stream.
          try {
            position = await Geolocator.getPositionStream(
              locationSettings: const LocationSettings(
                accuracy: LocationAccuracy.high,
                distanceFilter: 0,
              ),
            ).first.timeout(const Duration(seconds: 20));
          } catch (_) {
            try {
              position = await Geolocator.getPositionStream(
                locationSettings: const LocationSettings(
                  accuracy: LocationAccuracy.low,
                  distanceFilter: 0,
                ),
              ).first.timeout(const Duration(seconds: 15));
            } catch (_) {
              // deja position null
            }
          }
        }
      }

      if (position == null) {
        throw TimeoutException('No se pudo obtener ubicación');
      }

      if (!mounted || _yaNavego) return;

      final driverLoggedIn = await DriverPrefs.isDriverLoggedIn();
      if (driverLoggedIn) {
        _yaNavego = true;
        Navigator.of(context).pushReplacementNamed(DriverHomeScreen.route);
        return;
      }

      // Para asesores: iniciar servicio de fondo (no bloquea navegación si falla).
      try {
        await BackgroundLocationService.start();
      } catch (e) {
        debugPrint('⚠️ BackgroundLocationService.start() falló: $e');
      }

      // Iniciar primer segmento de ruta al comenzar la sesión
      try {
        final asesorId  = await AuthPrefs.getAsesorId();
        final usuarioId = await AuthPrefs.getUsuarioId();
        await http.post(
          Uri.parse('${Constants.apiBaseUrl}/api_iniciar_segmento.php'),
          headers: {'ngrok-skip-browser-warning': 'true'},
          body: {
            'asesor_id':  asesorId,
            'usuario_id': usuarioId,
            'latitud':    position.latitude.toString(),
            'longitud':   position.longitude.toString(),
          },
        ).timeout(const Duration(seconds: 8));
        debugPrint('✅ Segmento de ruta iniciado al login');
      } catch (e) {
        debugPrint('⚠️ No se pudo iniciar segmento de ruta: $e');
      }

      _yaNavego = true;
      Navigator.of(context).pushReplacementNamed(OsmMapScreen.route);
    } on LocationServiceDisabledException {
      if (!mounted) return;
      setState(() {
        _locationError = 'Activa el GPS (Ubicación) para continuar.';
      });
    } on PermissionDeniedException {
      if (!mounted) return;
      setState(() {
        _locationError = 'Permiso de ubicación denegado. Vuelve a dar permiso.';
      });
    } on TimeoutException {
      if (!mounted) return;
      setState(() {
        _locationError =
            'No se pudo obtener la ubicación. Asegúrate de que el GPS esté activo y reintenta.';
      });
    } catch (e) {
      if (!mounted) return;
      debugPrint('⚠️ Error inesperado en _intentarNavegar: $e');
      setState(() {
        _locationError =
            'Error al obtener ubicación ($e). Verifica que el GPS esté activo y reintenta.';
      });
    } finally {
      if (!mounted) return;
      setState(() {
        _isGettingLocation = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final permModel = Provider.of<PermissionHandlerModel>(context);

    // Auto-solicitar permiso 1 vez al entrar.
    if (!_autoPermissionRequested && !permModel.isLocationPerGiven) {
      _autoPermissionRequested = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        permModel.requestAppLocationPermission();
        Future.delayed(const Duration(milliseconds: 600), () {
          if (!mounted) return;
          permModel.bootstrap();
        });
      });
    }

    // Auto-intento una sola vez cuando ya hay permiso + servicio.
    if (permModel.isLocationPerGiven &&
        permModel.isLocationSerGiven &&
        !_yaNavego &&
        !_autoLocationAttempted) {
      _autoLocationAttempted = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        _intentarNavegar();
      });
    }

    final statusText = _locationError ??
        (permModel.isLocationPerGiven
            ? (permModel.isLocationSerGiven
                ? (_isGettingLocation
                    ? 'Obteniendo ubicación…'
                    : 'Listo para continuar')
                : 'Activa el GPS para continuar')
            : 'Necesitamos tu ubicación para abrir el mapa');

    final buttonText = !permModel.isLocationPerGiven
        ? 'Dar permiso'
        : (!permModel.isLocationSerGiven
            ? 'Activar GPS'
            : (_isGettingLocation ? 'Obteniendo ubicación…' : 'Continuar'));

    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: SafeArea(
        child: Stack(
          children: <Widget>[
            SpringEffect(),
            Align(
              alignment: const Alignment(0, -0.25),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 22),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    const Icon(Icons.my_location,
                        color: Colors.white, size: 34),
                    const SizedBox(height: 10),
                    const Text(
                      'Permiso de ubicación',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      statusText,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white.withOpacity(0.75),
                        fontSize: 13,
                        height: 1.35,
                      ),
                    ),
                    if (_isGettingLocation)
                      const Padding(
                        padding: EdgeInsets.only(top: 14),
                        child: SizedBox(
                          width: 22,
                          height: 22,
                          child: CircularProgressIndicator(strokeWidth: 2.5),
                        ),
                      ),
                  ],
                ),
              ),
            ),
            Align(
              alignment: Alignment.bottomCenter,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
                child: SizedBox(
                  width: double.infinity,
                  height: 56,
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: ConstantColors.PrimaryColor,
                    ),
                    onPressed: _isGettingLocation
                        ? null
                        : () async {
                            if (!permModel.isLocationPerGiven) {
                              permModel.requestAppLocationPermission();
                              await Future.delayed(
                                  const Duration(milliseconds: 600));
                              if (!mounted) return;
                              await permModel.bootstrap();
                              return;
                            }

                            if (!permModel.isLocationSerGiven) {
                              permModel.requestLocationServiceToEnable();
                              await Future.delayed(
                                  const Duration(milliseconds: 800));
                              if (!mounted) return;
                              await permModel.bootstrap();
                              return;
                            }

                            await _intentarNavegar();
                          },
                    child: Text(
                      buttonText,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
