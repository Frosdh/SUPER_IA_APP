import 'package:flutter/cupertino.dart';
import 'package:location/location.dart';

class PermissionHandlerModel extends ChangeNotifier {
  Location location = Location();

  bool isLocationPerGiven = false;
  bool isLocationSerGiven = false;

  PermissionHandlerModel() {
    location.changeSettings(accuracy: LocationAccuracy.low);
    location.hasPermission().then((status) {
      final isGiven = status == PermissionStatus.granted;
      if (isGiven) {
        isLocationPerGiven = true;
        location.serviceEnabled().then((isEnabled) {
          if (isEnabled) {
            isLocationSerGiven = true;
          } else {
            isLocationSerGiven = false;
          }
          notifyListeners();
        });
      } else {
        isLocationPerGiven = false;
      }
      notifyListeners();
    });
  }

  Future<bool> checkAppLocationGranted() async {
    final status = await location.hasPermission();
    return status == PermissionStatus.granted;
  }

  void requestAppLocationPermission() {
    location.requestPermission().then((status) {
      isLocationPerGiven = status == PermissionStatus.granted;
      notifyListeners();
    });
  }

  Future<bool> checkLocationServiceEnabled() {
    return location.serviceEnabled();
  }

  void requestLocationServiceToEnable() {
    location.requestService().then((isGiven) {
      isLocationSerGiven = isGiven;
      notifyListeners();
    });
  }
}
