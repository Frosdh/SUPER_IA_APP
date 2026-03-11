import 'dart:async';

import 'package:fu_uber/Core/Enums/Enums.dart';
import 'package:fu_uber/Core/Models/Drivers.dart';
import 'package:fu_uber/Core/Models/UserPlaces.dart';
import 'package:fu_uber/Core/Networking/ApiProvider.dart';

class Repository {
  static Future<AuthStatus> isUserAlreadyAuthenticated() async {
    return AuthStatus.Authenticated;
  }

  static Future<int> sendOTP(String phone) async {
    print(">>> [REPOSITORY] Llamando a ApiProvider.sendOtpToUser para: $phone");
    return await ApiProvider.sendOtpToUser(phone);
  }

  static Future<bool> registerNewUser(String nombre, String telefono, String email, String password) async {
    print(">>> [REPOSITORY] Iniciando registro de nuevo usuario en el servidor...");
    return await ApiProvider.registerNewUser(nombre, telefono, email, password);
  }

  static Future<int> verifyOtp(String text) async {
    //just returning 1
    //somehow check the otp
    return await ApiProvider.verifyOtp(text);
  }

  static Future<Map<String, dynamic>> checkUserByPhone(String phone) async {
    return await ApiProvider.checkUserByPhone(phone);
  }

  static void getNearbyDrivers(
      StreamController<List<Driver>> nearbyDriverStreamController) {
    nearbyDriverStreamController.sink.add(ApiProvider.getNearbyDrivers());
  }

  static void addFavPlacesToDataBase(List<UserPlaces> data) {
    //
  }
}
