import 'package:flutter/material.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Models/TransactionModel.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/Core/Preferences/TransactionHistoryService.dart';
import 'package:intl/intl.dart';

/// Pantalla que muestra el historial de pagos del pasajero.
class PaymentHistoryScreen extends StatefulWidget {
  static const String route = '/payment_history';

  const PaymentHistoryScreen({super.key});

  @override
  State<PaymentHistoryScreen> createState() => _PaymentHistoryScreenState();
}

class _PaymentHistoryScreenState extends State<PaymentHistoryScreen> {
  List<TransactionModel> _transacciones = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _cargarHistorial();
  }

  Future<void> _cargarHistorial() async {
    final phone = await AuthPrefs.getUserPhone() ?? '';
    final lista = await TransactionHistoryService.obtenerTransacciones(
      userPhone: phone,
    );
    if (mounted) {
      setState(() {
        _transacciones = lista;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: ConstantColors.backgroundCard,
        elevation: 0,
        leading: IconButton(
          icon: Icon(Icons.arrow_back_ios_new_rounded,
              color: ConstantColors.textWhite, size: 20),
          onPressed: () => Navigator.pop(context),
        ),
        title: Text(
          'Historial de Pagos',
          style: TextStyle(
            color: ConstantColors.textWhite,
            fontSize: 18,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      body: _loading
          ? Center(
              child: CircularProgressIndicator(
                valueColor: AlwaysStoppedAnimation<Color>(
                    ConstantColors.primaryViolet),
              ),
            )
          : _transacciones.isEmpty
              ? _buildEmpty()
              : _buildList(),
    );
  }

  Widget _buildEmpty() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 80,
            height: 80,
            decoration: BoxDecoration(
              color: ConstantColors.primaryViolet.withOpacity(0.1),
              shape: BoxShape.circle,
            ),
            child: Icon(Icons.receipt_long_rounded,
                color: ConstantColors.primaryViolet, size: 36),
          ),
          const SizedBox(height: 20),
          Text(
            'Sin pagos registrados',
            style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 16,
                fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Text(
            'Tus pagos aparecerán aquí\ndespués de cada viaje',
            style: TextStyle(color: ConstantColors.textGrey, fontSize: 13),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }

  Widget _buildList() {
    // Calcular totales
    final totalPagado = _transacciones
        .where((t) => t.isSuccess)
        .fold<double>(0.0, (sum, t) => sum + t.amount);

    return Column(
      children: [
        // Resumen superior
        Container(
          margin: const EdgeInsets.all(16),
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [Color(0xFF1A0A3A), Color(0xFF0A1A35)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: ConstantColors.borderColor),
          ),
          child: Row(
            children: [
              Expanded(
                child: _buildSummaryItem(
                  icon: Icons.receipt_rounded,
                  label: 'Total pagos',
                  value: '${_transacciones.length}',
                  color: ConstantColors.primaryViolet,
                ),
              ),
              Container(
                  width: 1, height: 40, color: ConstantColors.borderColor),
              Expanded(
                child: _buildSummaryItem(
                  icon: Icons.attach_money_rounded,
                  label: 'Total gastado',
                  value: '\$${totalPagado.toStringAsFixed(2)}',
                  color: ConstantColors.success,
                ),
              ),
              Container(
                  width: 1, height: 40, color: ConstantColors.borderColor),
              Expanded(
                child: _buildSummaryItem(
                  icon: Icons.credit_card_rounded,
                  label: 'Con tarjeta',
                  value:
                      '${_transacciones.where((t) => t.method == 'payphone').length}',
                  color: const Color(0xFF00A6B4),
                ),
              ),
            ],
          ),
        ),

        // Lista de transacciones
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            itemCount: _transacciones.length,
            itemBuilder: (context, index) {
              return _buildTransactionCard(_transacciones[index]);
            },
          ),
        ),
      ],
    );
  }

  Widget _buildSummaryItem({
    required IconData icon,
    required String label,
    required String value,
    required Color color,
  }) {
    return Column(
      children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(height: 6),
        Text(value,
            style: TextStyle(
                color: color, fontSize: 16, fontWeight: FontWeight.w800)),
        const SizedBox(height: 2),
        Text(label,
            style: TextStyle(
                color: ConstantColors.textSubtle,
                fontSize: 11),
            textAlign: TextAlign.center),
      ],
    );
  }

  Widget _buildTransactionCard(TransactionModel t) {
    final isPayPhone = t.method == 'payphone';
    final isSuccess = t.isSuccess;

    Color statusColor;
    IconData statusIcon;
    switch (t.status) {
      case 'approved':
        statusColor = ConstantColors.success;
        statusIcon = Icons.check_circle_rounded;
        break;
      case 'cash':
        statusColor = ConstantColors.warning;
        statusIcon = Icons.payments_rounded;
        break;
      case 'cancelled':
        statusColor = ConstantColors.error;
        statusIcon = Icons.cancel_rounded;
        break;
      default:
        statusColor = ConstantColors.textGrey;
        statusIcon = Icons.access_time_rounded;
    }

    String fechaFormateada;
    try {
      final dt = DateTime.parse(t.fecha);
      fechaFormateada =
          DateFormat('dd/MM/yyyy  HH:mm').format(dt);
    } catch (_) {
      fechaFormateada = t.fecha;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: isSuccess
              ? statusColor.withOpacity(0.25)
              : ConstantColors.borderColor,
        ),
      ),
      child: Row(
        children: [
          // Ícono método
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: (isPayPhone
                      ? const Color(0xFF00A6B4)
                      : ConstantColors.warning)
                  .withOpacity(0.12),
              shape: BoxShape.circle,
            ),
            child: Center(
              child: Icon(
                isPayPhone ? Icons.credit_card_rounded : Icons.payments_rounded,
                color: isPayPhone
                    ? const Color(0xFF00A6B4)
                    : ConstantColors.warning,
                size: 22,
              ),
            ),
          ),
          const SizedBox(width: 14),

          // Info
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  t.reference,
                  style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Icon(Icons.access_time_rounded,
                        color: ConstantColors.textSubtle, size: 11),
                    const SizedBox(width: 4),
                    Text(
                      fechaFormateada,
                      style: TextStyle(
                          color: ConstantColors.textSubtle, fontSize: 11),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Icon(statusIcon, color: statusColor, size: 12),
                    const SizedBox(width: 4),
                    Text(
                      t.statusLabel,
                      style: TextStyle(
                          color: statusColor,
                          fontSize: 11,
                          fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 6, vertical: 1),
                      decoration: BoxDecoration(
                        color: (isPayPhone
                                ? const Color(0xFF00A6B4)
                                : ConstantColors.warning)
                            .withOpacity(0.12),
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        t.methodLabel,
                        style: TextStyle(
                          color: isPayPhone
                              ? const Color(0xFF00A6B4)
                              : ConstantColors.warning,
                          fontSize: 10,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),

          // Monto
          Text(
            '\$${t.amount.toStringAsFixed(2)}',
            style: TextStyle(
              color: isSuccess
                  ? ConstantColors.textWhite
                  : ConstantColors.textSubtle,
              fontSize: 16,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}
