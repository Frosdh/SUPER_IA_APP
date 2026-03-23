import 'dart:convert';
import 'package:fu_uber/Core/Models/TransactionModel.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Servicio que guarda y recupera el historial de transacciones
/// de pago del pasajero en SharedPreferences.
class TransactionHistoryService {
  static const _keyPrefix = 'transactions_';

  /// Guarda una transacción nueva (o la actualiza si ya existe).
  static Future<void> guardarTransaccion({
    required String userPhone,
    required TransactionModel transaccion,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final key = '$_keyPrefix$userPhone';
    final lista = await obtenerTransacciones(userPhone: userPhone);

    // Reemplazar si ya existe el mismo clientTransactionId
    final idx = lista.indexWhere(
      (t) => t.clientTransactionId == transaccion.clientTransactionId,
    );
    if (idx >= 0) {
      lista[idx] = transaccion;
    } else {
      lista.insert(0, transaccion); // más reciente primero
    }

    // Limitar a 50 transacciones guardadas
    final limitada = lista.take(50).toList();
    final jsonStr = jsonEncode(limitada.map((t) => t.toJson()).toList());
    await prefs.setString(key, jsonStr);
  }

  /// Recupera todas las transacciones del usuario, más recientes primero.
  static Future<List<TransactionModel>> obtenerTransacciones({
    required String userPhone,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final key = '$_keyPrefix$userPhone';
    final raw = prefs.getString(key);
    if (raw == null) return [];
    try {
      final lista = jsonDecode(raw) as List<dynamic>;
      return lista
          .map((e) => TransactionModel.fromJson(
                (e as Map).cast<String, dynamic>(),
              ))
          .toList();
    } catch (_) {
      return [];
    }
  }

  /// Genera un ID único para la transacción: RIDE-{viajeId}-{timestamp}
  static String generarClientTransactionId(int? viajeId) {
    final ts = DateTime.now().millisecondsSinceEpoch;
    return 'RIDE-${viajeId ?? 0}-$ts';
  }
}
