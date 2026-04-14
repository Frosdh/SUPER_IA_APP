/// Modelo que representa una transacción de pago registrada en la app.
class TransactionModel {
  final String clientTransactionId; // ID único generado por la app
  final int? payPhoneTransactionId; // ID asignado por PayPhone
  final double amount;              // Monto en dólares
  final String method;              // 'payphone' | 'cash'
  final String status;              // 'approved' | 'pending' | 'cancelled' | 'cash'
  final String reference;          // Descripción del viaje
  final String fecha;              // ISO 8601
  final int? viajeId;

  TransactionModel({
    required this.clientTransactionId,
    this.payPhoneTransactionId,
    required this.amount,
    required this.method,
    required this.status,
    required this.reference,
    required this.fecha,
    this.viajeId,
  });

  Map<String, dynamic> toJson() => {
        'clientTransactionId': clientTransactionId,
        'payPhoneTransactionId': payPhoneTransactionId,
        'amount': amount,
        'method': method,
        'status': status,
        'reference': reference,
        'fecha': fecha,
        'viajeId': viajeId,
      };

  factory TransactionModel.fromJson(Map<String, dynamic> json) =>
      TransactionModel(
        clientTransactionId: json['clientTransactionId'] as String,
        payPhoneTransactionId: json['payPhoneTransactionId'] as int?,
        amount: (json['amount'] as num).toDouble(),
        method: json['method'] as String,
        status: json['status'] as String,
        reference: json['reference'] as String,
        fecha: json['fecha'] as String,
        viajeId: json['viajeId'] as int?,
      );

  /// ¿El pago fue exitoso (sin importar si es efectivo o tarjeta)?
  bool get isSuccess =>
      status == 'approved' || status == 'cash';

  /// Texto amigable del método de pago
  String get methodLabel =>
      method == 'payphone' ? 'PayPhone' : 'Efectivo';

  /// Texto amigable del estado
  String get statusLabel {
    switch (status) {
      case 'approved': return 'Aprobado';
      case 'cash':     return 'Efectivo';
      case 'pending':  return 'Pendiente';
      case 'cancelled': return 'Cancelado';
      default:         return status;
    }
  }
}
