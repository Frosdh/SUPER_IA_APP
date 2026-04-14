import 'package:flutter/material.dart';
import 'package:super_ia/Core/Enums/Enums.dart';

class CurrentRideCreationModel extends ChangeNotifier {
  late RideType selectedRideType;
  bool riderFound = false;

  CurrentRideCreationModel() {
    selectedRideType = RideType.Classic;
  }

  String getEstimationFromOriginDestination() {
    return "200";
  }

  void carTypeChanged(int index) {
    selectedRideType = RideType.values[index];
    notifyListeners();
  }

  void searchForRides() {
    Future.delayed(const Duration(seconds: 5), () {
      riderFound = true;
      notifyListeners();
    });
  }
}
