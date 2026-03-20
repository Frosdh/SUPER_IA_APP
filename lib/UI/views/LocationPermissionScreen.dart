import 'package:flutter/material.dart';
import 'package:flutter/physics.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/DriverPrefs.dart';
import 'package:fu_uber/Core/ProviderModels/PermissionHandlerModel.dart';
import 'package:fu_uber/UI/views/DriverHomeScreen.dart';
import 'package:fu_uber/UI/views/OsmMapScreen.dart';
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
    return Material(
      child: Container(
        child: Stack(
          children: <Widget>[
            SpringEffect(),
            permModel.isLocationPerGiven
                ? Align(
                    alignment: const Alignment(0, 0.5),
                    child: permModel.isLocationSerGiven
                        ? const Text("Fetching Location...")
                        : InkResponse(
                            onTap: () {
                              permModel.requestLocationServiceToEnable();
                            },
                            child: Padding(
                              padding: const EdgeInsets.all(16.0),
                              child: Container(
                                color: ConstantColors.PrimaryColor,
                                height: 40,
                                width: double.infinity,
                                child: const Text(
                                  "Location Service Not Enabled",
                                  style: TextStyle(
                                      fontSize: 20, color: Colors.white),
                                ),
                              ),
                            ),
                          ),
                  )
                : Align(
                    alignment: Alignment.bottomCenter,
                    child: InkResponse(
                      onTap: () {
                        permModel.requestAppLocationPermission();
                      },
                      child: Padding(
                        padding: const EdgeInsets.all(16.0),
                        child: Container(
                          color: ConstantColors.PrimaryColor,
                          height: 60,
                          width: double.infinity,
                          child: const Center(
                              child: Text(
                            "Location Permission is Not Given",
                            style:
                                TextStyle(fontSize: 15, color: Colors.white),
                          )),
                        ),
                      ),
                    ),
                  )
          ],
        ),
      ),
    );
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final permModel = Provider.of<PermissionHandlerModel>(context);
    if (permModel.isLocationPerGiven &&
        permModel.isLocationSerGiven &&
        !_yaNavego) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        _intentarNavegar();
      });
    }
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
