import 'dart:convert';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Models/TransactionModel.dart';
import 'package:http/http.dart' as http;

/// Resultado de crear una transacción con PayPhone Button.
class PayPhoneCreateResult {
  final bool success;
  final int? paymentId;
  final String? redirectUrl;
  final String? errorMessage;

  PayPhoneCreateResult({
    required this.success,
    this.paymentId,
    this.redirectUrl,
    this.errorMessage,
  });
}

/// Resultado de confirmar una transacción con PayPhone.
class PayPhoneConfirmResult {
  final bool approved;
  final String transactionStatus; // 'Approved', 'Pending', 'Cancelled', etc.
  final String? message;
  final int? payPhoneTransactionId;

  PayPhoneConfirmResult({
    required this.approved,
    required this.transactionStatus,
    this.message,
    this.payPhoneTransactionId,
  });
}

/// Servicio que encapsula todas las llamadas a la API de PayPhone Button.
///
/// Documentación PayPhone:
///   Crear pago  → POST  {payPhoneApiUrl}/button/
///   Confirmar   → GET   {payPhoneApiUrl}/button/V2/confirm
///
/// El token de autenticación se toma de [Constants.payPhoneToken].
/// Recuerda reemplazarlo con tu token real en Constants.dart.
class PayPhoneService {
  static const _headers = {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ${Constants.payPhoneToken}',
  };

  // ─── CREAR PAGO (abre WebView con URL devuelta) ──────────────────────────
  /// Crea una transacción en PayPhone.
  /// [amountUSD] debe ser el monto en dólares (ej. 2.50).
  /// PayPhone requiere el monto × 100 (en centavos de dólar).
  static Future<PayPhoneCreateResult> crearPago({
    required double amountUSD,
    required String clientTransactionId,
    required String referencia,
  }) async {
    final url = '${Constants.payPhoneApiUrl}/button/';
    final amountCents = (amountUSD * 100).round();

    final body = jsonEncode({
      'amount': amountCents,
      'amountWithTax': 0,
      'amountWithoutTax': amountCents,
      'tax': 0,
      'clientTransactionId': clientTransactionId,
      'responseUrl': Constants.payPhoneResponseUrl,
      'cancellationUrl': Constants.payPhoneCancelUrl,
      'reference': referencia,
      'currency': 'USD',
      'lang': 'ES',
    });

    try {
      print('>>> [PAYPHONE] Creando pago: $clientTransactionId - \$$amountUSD');
      final response = await http
          .post(Uri.parse(url), headers: _headers, body: body)
          .timeout(const Duration(seconds: 15));

      print('>>> [PAYPHONE] Create HTTP ${response.statusCode}');
      print('>>> [PAYPHONE] Create Body: ${response.body}');

      if (response.statusCode == 200 || response.statusCode == 201) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        final paymentId  = data['paymentId']  as int?;
        final redirectUrl = data['redirectUrl'] as String?;

        if (paymentId != null && redirectUrl != null) {
          return PayPhoneCreateResult(
            success: true,
            paymentId: paymentId,
            redirectUrl: redirectUrl,
          );
        }
        return PayPhoneCreateResult(
          success: false,
          errorMessage: 'Respuesta inválida de PayPhone: ${response.body}',
        );
      }

      return PayPhoneCreateResult(
        success: false,
        errorMessage: 'Error HTTP ${response.statusCode}: ${response.body}',
      );
    } catch (e) {
      print('>>> [PAYPHONE] Error al crear pago: $e');
      return PayPhoneCreateResult(
        success: false,
        errorMessage: 'Error de red: $e',
      );
    }
  }

  // ─── CONFIRMAR PAGO ──────────────────────────────────────────────────────
  /// Consulta a PayPhone si la transacción fue aprobada.
  /// Se llama después de que el WebView detecta el regreso a [responseUrl].
  static Future<PayPhoneConfirmResult> confirmarPago({
    required int paymentId,
    required String clientTransactionId,
  }) async {
    final url =
        '${Constants.payPhoneApiUrl}/button/V2/confirm'
        '?id=$paymentId'
        '&clientTransactionId=$clientTransactionId';

    try {
      print('>>> [PAYPHONE] Confirmando pago: paymentId=$paymentId');
      final response = await http
          .get(Uri.parse(url), headers: _headers)
          .timeout(const Duration(seconds: 15));

      print('>>> [PAYPHONE] Confirm HTTP ${response.statusCode}');
      print('>>> [PAYPHONE] Confirm Body: ${response.body}');

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        final transactionStatus = data['transactionStatus'] as String? ?? '';
        final approved = transactionStatus.toLowerCase() == 'approved';
        final ppId = data['id'] as int?;

        return PayPhoneConfirmResult(
          approved: approved,
          transactionStatus: transactionStatus,
          payPhoneTransactionId: ppId,
        );
      }

      return PayPhoneConfirmResult(
        approved: false,
        transactionStatus: 'Error',
        message: 'HTTP ${response.statusCode}',
      );
    } catch (e) {
      print('>>> [PAYPHONE] Error al confirmar pago: $e');
      return PayPhoneConfirmResult(
        approved: false,
        transactionStatus: 'Error',
        message: 'Error de red: $e',
      );
    }
  }

  // ─── HELPER: construir TransactionModel desde resultado ──────────────────
  static TransactionModel buildTransaction({
    required String clientTransactionId,
    required double amountUSD,
    required String referencia,
    required int? viajeId,
    required PayPhoneConfirmResult confirmResult,
  }) {
    return TransactionModel(
      clientTransactionId: clientTransactionId,
      payPhoneTransactionId: confirmResult.payPhoneTransactionId,
      amount: amountUSD,
      method: 'payphone',
      status: confirmResult.approved ? 'approved' : 'cancelled',
      reference: referencia,
      fecha: DateTime.now().toIso8601String(),
      viajeId: viajeId,
    );
  }

  /// Construye un [TransactionModel] para pago en efectivo.
  static TransactionModel buildCashTransaction({
    required String clientTransactionId,
    required double amountUSD,
    required String referencia,
    required int? viajeId,
  }) {
    return TransactionModel(
      clientTransactionId: clientTransactionId,
      amount: amountUSD,
      method: 'cash',
      status: 'cash',
      reference: referencia,
      fecha: DateTime.now().toIso8601String(),
      viajeId: viajeId,
    );
  }
}
