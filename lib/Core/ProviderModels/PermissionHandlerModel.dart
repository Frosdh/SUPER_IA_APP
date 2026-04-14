import 'package:flutter/cupertino.dart';
import 'package:geolocator/geolocator.dart';

class PermissionHandlerModel extends ChangeNotifier {
  bool isLocationPerGiven = false;
  bool isLocationSerGiven = false;

  PermissionHandlerModel();

  Future<void> bootstrap() async {
    try {
      final permission = await Geolocator.checkPermission();
      isLocationPerGiven =
          permission == LocationPermission.always ||
          permission == LocationPermission.whileInUse;
      if (isLocationPerGiven) {
        isLocationSerGiven = await Geolocator.isLocationServiceEnabled();
      } else {
        isLocationSerGiven = false;
      }
    } catch (_) {
      isLocationPerGiven = false;
      isLocationSerGiven = false;
    }
    notifyListeners();
  }

  Future<bool> checkAppLocationGranted() async {
    final permission = await Geolocator.checkPermission();
    return permission == LocationPermission.always ||
        permission == LocationPermission.whileInUse;
  }

  void requestAppLocationPermission() {
    Geolocator.requestPermission().then((permission) {
      isLocationPerGiven =
          permission == LocationPermission.always ||
          permission == LocationPermission.whileInUse;
      notifyListeners();
    });
  }

  Future<bool> checkLocationServiceEnabled() {
    return Geolocator.isLocationServiceEnabled();
  }

  void requestLocationServiceToEnable() {
    Geolocator.openLocationSettings().then((_) async {
      isLocationSerGiven = await Geolocator.isLocationServiceEnabled();
      notifyListeners();
    });
  }
}
