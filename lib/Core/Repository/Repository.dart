import 'dart:async';

import 'package:super_ia/Core/Models/Drivers.dart';
import 'package:super_ia/Core/Models/NearbyDriverMapModel.dart';
import 'package:super_ia/Core/Models/UserPlaces.dart';
import 'package:super_ia/Core/Networking/ApiProvider.dart';

class Repository {
  final ApiProvider apiProvider = ApiProvider();

  Future<Map<String, dynamic>> registerNewUser({
    required String nombre,
    required String telefono,
    required String email,
    required String tokenFcm,
  }) {
    return apiProvider.registerNewUser(
      nombre: nombre,
      telefono: telefono,
      email: email,
      tokenFcm: tokenFcm,
    );
  }

  Future<int> sendEmailOtp(String email) {
    return apiProvider.sendEmailOtp(email);
  }

  Future<int> verifyEmailOtp(String email, String codigo) {
    return apiProvider.verifyEmailOtp(email, codigo);
  }

  Future<Map<String, dynamic>> checkUserByEmail(String email) {
    return apiProvider.checkUserByEmail(email);
  }

  Future<List<NearbyDriverMapModel>> getNearbyDriverMapData({
    required double lat,
    required double lng,
    int? categoriaId,
    double radioKm = 5,
  }) {
    return apiProvider.getNearbyDrivers(
      lat: lat,
      lng: lng,
      categoriaId: categoriaId,
      radioKm: radioKm,
    );
  }

  // Compatibilidad con modelos viejos del proyecto.
  static void getNearbyDrivers(
      StreamController<List<Driver>> nearbyDriverStreamController) {
    nearbyDriverStreamController.sink.add(<Driver>[]);
  }

  static void addFavPlacesToDataBase(List<UserPlaces> data) {}
}
