import 'package:shared_preferences/shared_preferences.dart';

class DiscountPreferences {
  static const String _keyUsedCoupons = 'used_coupons';
  static const String _keyCurrentCoupon = 'current_coupon';
  static const String _keyCurrentDiscount = 'current_discount';

  /// Guardar un cupón como usado
  static Future<void> guardarCuponUsado(String codigo) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final usedCoupons = prefs.getStringList(_keyUsedCoupons) ?? [];

      if (!usedCoupons.contains(codigo)) {
        usedCoupons.add(codigo);
        await prefs.setStringList(_keyUsedCoupons, usedCoupons);
        print('>>> [PREF] Cupón guardado como usado: $codigo');
      }
    } catch (e) {
      print('>>> [PREF] Error al guardar cupón usado: $e');
    }
  }

  /// Verificar si un cupón ya fue usado
  static Future<bool> fueUsado(String codigo) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final usedCoupons = prefs.getStringList(_keyUsedCoupons) ?? [];
      return usedCoupons.contains(codigo);
    } catch (e) {
      print('>>> [PREF] Error al verificar cupón usado: $e');
      return false;
    }
  }

  /// Obtener lista de cupones usados
  static Future<List<String>> obtenerCuponesUsados() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getStringList(_keyUsedCoupons) ?? [];
    } catch (e) {
      print('>>> [PREF] Error al obtener cupones usados: $e');
      return [];
    }
  }

  /// Guardar cupón actual (durante viaje)
  static Future<void> guardarCuponActual(String codigo) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_keyCurrentCoupon, codigo);
      print('>>> [PREF] Cupón actual guardado: $codigo');
    } catch (e) {
      print('>>> [PREF] Error al guardar cupón actual: $e');
    }
  }

  /// Obtener cupón actual
  static Future<String> obtenerCuponActual() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_keyCurrentCoupon) ?? '';
    } catch (e) {
      print('>>> [PREF] Error al obtener cupón actual: $e');
      return '';
    }
  }

  /// Guardar descuento actual
  static Future<void> guardarDescuentoActual(double descuento) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setDouble(_keyCurrentDiscount, descuento);
      print('>>> [PREF] Descuento guardado: \$${descuento.toStringAsFixed(2)}');
    } catch (e) {
      print('>>> [PREF] Error al guardar descuento: $e');
    }
  }

  /// Obtener descuento actual
  static Future<double> obtenerDescuentoActual() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getDouble(_keyCurrentDiscount) ?? 0.0;
    } catch (e) {
      print('>>> [PREF] Error al obtener descuento: $e');
      return 0.0;
    }
  }

  /// Limpiar cupón y descuento actual (después de viaje confirmado)
  static Future<void> limpiarCuponActual() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_keyCurrentCoupon);
      await prefs.remove(_keyCurrentDiscount);
      print('>>> [PREF] Cupón y descuento limpiados');
    } catch (e) {
      print('>>> [PREF] Error al limpiar cupón actual: $e');
    }
  }

  /// Limpiar todo (para debugging)
  static Future<void> limpiarTodo() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_keyUsedCoupons);
      await prefs.remove(_keyCurrentCoupon);
      await prefs.remove(_keyCurrentDiscount);
      print('>>> [PREF] Preferencias de cupones limpiadas');
    } catch (e) {
      print('>>> [PREF] Error al limpiar preferencias: $e');
    }
  }
}
