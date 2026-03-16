import 'package:shared_preferences/shared_preferences.dart';
import 'dart:convert';

class DriverPrefs {
  static const String _keyIsLoggedIn = 'driver_is_logged_in';
  static const String _keyDriverId = 'driver_id';
  static const String _keyDriverName = 'driver_name';
  static const String _keyDriverPhone = 'driver_phone';
  static const String _keyDriverCedula = 'driver_cedula';
  static const String _keyDriverStatus = 'driver_status';
  static const String _keyActiveRideJson = 'driver_active_ride_json';

  static Future<void> saveDriverSession({
    required int id,
    required String nombre,
    required String telefono,
    required String cedula,
    required String estado,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_keyIsLoggedIn, true);
    await prefs.setInt(_keyDriverId, id);
    await prefs.setString(_keyDriverName, nombre);
    await prefs.setString(_keyDriverPhone, telefono);
    await prefs.setString(_keyDriverCedula, cedula);
    await prefs.setString(_keyDriverStatus, estado);
  }

  static Future<void> saveDriverStatus(String? estado) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyDriverStatus, estado ?? 'desconectado');
  }

  static Future<void> saveActiveRide(Map<String, dynamic>? ride) async {
    final prefs = await SharedPreferences.getInstance();
    if (ride == null) {
      await prefs.remove(_keyActiveRideJson);
      return;
    }
    await prefs.setString(_keyActiveRideJson, json.encode(ride));
  }

  static Future<Map<String, dynamic>?> getActiveRide() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_keyActiveRideJson);
    if (raw == null || raw.isEmpty) return null;
    try {
      final decoded = json.decode(raw);
      return (decoded as Map<dynamic, dynamic>).cast<String, dynamic>();
    } catch (_) {
      return null;
    }
  }

  static Future<void> clearActiveRide() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyActiveRideJson);
  }

  static Future<bool> isDriverLoggedIn() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_keyIsLoggedIn) ?? false;
  }

  static Future<int> getDriverId() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getInt(_keyDriverId) ?? 0;
  }

  static Future<String> getDriverName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyDriverName) ?? '';
  }

  static Future<String> getDriverPhone() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyDriverPhone) ?? '';
  }

  static Future<String> getDriverCedula() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyDriverCedula) ?? '';
  }

  static Future<String> getDriverStatus() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyDriverStatus) ?? 'desconectado';
  }

  static Future<void> clearDriverSession() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyIsLoggedIn);
    await prefs.remove(_keyDriverId);
    await prefs.remove(_keyDriverName);
    await prefs.remove(_keyDriverPhone);
    await prefs.remove(_keyDriverCedula);
    await prefs.remove(_keyDriverStatus);
    await prefs.remove(_keyActiveRideJson);
  }
}
