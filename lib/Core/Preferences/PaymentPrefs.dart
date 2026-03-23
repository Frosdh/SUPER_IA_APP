import 'package:shared_preferences/shared_preferences.dart';

/// Preferencias de pago del pasajero:
///  - Método de pago guardado/preferido ('cash' | 'payphone')
///  - Créditos de la billetera virtual
class PaymentPrefs {
  static const _keyMethod   = 'preferred_payment_method';
  static const _keyCredits  = 'wallet_credits_';

  // ─── MÉTODO PREFERIDO ───────────────────────────────────────────────────

  /// Guarda el método preferido del usuario.
  static Future<void> savePreferredMethod(String method) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyMethod, method);
  }

  /// Devuelve el método preferido ('cash' | 'payphone'). Por defecto 'cash'.
  static Future<String> getPreferredMethod() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_keyMethod) ?? 'cash';
  }

  // ─── BILLETERA VIRTUAL ──────────────────────────────────────────────────

  /// Obtiene el saldo de créditos del usuario (en USD).
  static Future<double> getCredits(String userPhone) async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getDouble('$_keyCredits$userPhone') ?? 0.0;
  }

  /// Agrega créditos al saldo del usuario.
  static Future<void> addCredits(String userPhone, double amount) async {
    final prefs = await SharedPreferences.getInstance();
    final current = prefs.getDouble('$_keyCredits$userPhone') ?? 0.0;
    await prefs.setDouble('$_keyCredits$userPhone', current + amount);
  }

  /// Descuenta créditos del saldo. Devuelve false si no hay suficiente saldo.
  static Future<bool> useCredits(String userPhone, double amount) async {
    final prefs = await SharedPreferences.getInstance();
    final current = prefs.getDouble('$_keyCredits$userPhone') ?? 0.0;
    if (current < amount) return false;
    await prefs.setDouble('$_keyCredits$userPhone', current - amount);
    return true;
  }

  /// Resetea el saldo de créditos (para pruebas o reembolsos).
  static Future<void> setCredits(String userPhone, double amount) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setDouble('$_keyCredits$userPhone', amount);
  }
}
