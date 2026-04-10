import 'package:flutter/material.dart';
import 'package:flutter/physics.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Preferences/DriverPrefs.dart';
import 'package:super_ia/Core/ProviderModels/PermissionHandlerModel.dart';
import 'package:super_ia/Core/Services/BackgroundLocationService.dart';
import 'package:super_ia/UI/views/DriverHomeScreen.dart';
import 'package:super_ia/UI/views/OsmMapScreen.dart';
import 'package:geolocator/geolocator.dart';
import 'package:provider/provider.dart';

class LocationPermissionScreen extends StatefulWidget {
  static final String route = "locationScreen";

  @override
  _LocationPermissionScreenState createState() =>
      _LocationPermissionScreenState();
}

class _LocationPermissionScreenState extends State<LocationPermissionScreen>
    with SingleTickerProviderStateMixin {
  late AnimationController loadingController;
  late Animation<double> animation;
  bool _yaNavego = false;
  bool _autoPermissionRequested = false;

  @override
  void dispose() {
    loadingController.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    loadingController =
        AnimationController(vsync: this, duration: const Duration(seconds: 5));
    animation = Tween<double>(begin: 0, end: 40).animate(CurvedAnimation(
        parent: loadingController, curve: Curves.easeInOutCirc));
    loadingController.addStatusListener((AnimationStatus status) {
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

  /// Intenta obtener la posición actual y navega al mapa OSM
  Future<void> _intentarNavegar() async {
    if (_yaNavego || !mounted) return;
    try {
      await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );
      if (mounted && !_yaNavego) {
        _yaNavego = true;
        
        // Verificar si es conductor para decidir la ruta
        bool driverLoggedIn = await DriverPrefs.isDriverLoggedIn();
        if (driverLoggedIn) {
          Navigator.of(context).pushReplacementNamed(DriverHomeScreen.route);
        } else {
          // Para asesores: si existe asesor_id guardado, se iniciará el envío en segundo plano.
          await BackgroundLocationService.start();
          Navigator.of(context).pushReplacementNamed(OsmMapScreen.route);
        }
      }
    } catch (_) {
      // Si falla, el usuario verá el botón para intentar de nuevo
    }
  }

  @override
  Widget build(BuildContext context) {
    final permModel = Provider.of<PermissionHandlerModel>(context);

    // Auto-solicitar permiso 1 vez al entrar (evita que el usuario se quede en pantalla vacía).
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

    // Provider notifies rebuilds but does NOT trigger didChangeDependencies.
    // So we trigger the navigation attempt from build (post-frame) once ready.
    if (permModel.isLocationPerGiven && permModel.isLocationSerGiven && !_yaNavego) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        _intentarNavegar();
      });
    }

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
                    Icon(Icons.my_location, color: Colors.white, size: 34),
                    const SizedBox(height: 10),
                    Text(
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
                      permModel.isLocationPerGiven
                          ? (permModel.isLocationSerGiven
                              ? 'Obteniendo ubicación…'
                              : 'Activa el GPS para continuar')
                          : 'Necesitamos tu ubicación para abrir el mapa',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white.withOpacity(0.75),
                        fontSize: 13,
                        height: 1.35,
                      ),
                    ),
                    if (permModel.isLocationPerGiven && permModel.isLocationSerGiven)
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
                    onPressed: () async {
                      if (!permModel.isLocationPerGiven) {
                        permModel.requestAppLocationPermission();
                        await Future.delayed(const Duration(milliseconds: 600));
                        await permModel.bootstrap();
                        return;
                      }
                      if (!permModel.isLocationSerGiven) {
                        permModel.requestLocationServiceToEnable();
                        await Future.delayed(const Duration(milliseconds: 600));
                        await permModel.bootstrap();
                        return;
                      }
                      await _intentarNavegar();
                    },
                    child: Text(
                      !permModel.isLocationPerGiven
                          ? 'Dar permiso de ubicación'
                          : (!permModel.isLocationSerGiven
                              ? 'Activar GPS'
                              : 'Continuar al mapa'),
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 15,
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

class SpringEffect extends StatefulWidget {
  @override
  SpringState createState() => SpringState();
}

class SpringState extends State<SpringEffect> with TickerProviderStateMixin {
  late AnimationController controller;
  late AnimationController controller2;
  late Animation<double> animation;
  late SpringSimulation simulation;
  double _position = 0;

  @override
  void initState() {
    super.initState();
    simulation = SpringSimulation(
      const SpringDescription(
        mass: 1.0,
        stiffness: 100.0,
        damping: 5.0,
      ),
      200.0,
      100.0,
      -2000.0,
    );

    controller2 = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 70));
    animation = Tween(begin: 100.0, end: 200.0).animate(controller2)
      ..addListener(() {
        if (controller2.status == AnimationStatus.completed) {
          controller.forward(from: 0);
        }
        setState(() {
          _position = animation.value;
        });
      });

    controller =
        AnimationController(vsync: this, duration: const Duration(seconds: 2))
          ..forward()
          ..addListener(() {
            if (controller.status == AnimationStatus.completed) {
              controller2.forward(from: 0);
            }
            setState(() {
              _position = simulation.x(controller.value);
            });
          });
  }

  @override
  void dispose() {
    controller.dispose();
    controller2.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
        child: Center(
      child: Stack(
        fit: StackFit.expand,
        children: <Widget>[
          Align(
            alignment: Alignment(0, _position / 1000),
            child: Image.asset(
              "images/pickupIcon.png",
              width: 50,
            ),
          ),
        ],
      ),
    ));
  }
}
