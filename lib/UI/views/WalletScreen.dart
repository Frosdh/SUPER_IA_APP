import 'package:flutter/material.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Models/TransactionModel.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/Core/Preferences/PaymentPrefs.dart';
import 'package:super_ia/Core/Preferences/TransactionHistoryService.dart';

/// Pantalla de billetera virtual / créditos del pasajero.
/// Muestra el saldo disponible, el historial de créditos usados
/// y permite configurar el método de pago preferido.
class WalletScreen extends StatefulWidget {
  static const String route = '/wallet';

  const WalletScreen({super.key});

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  double _creditos = 0.0;
  String _metodoPref = 'cash';
  List<TransactionModel> _transacciones = [];
  bool _loading = true;
  String _userPhone = '';

  @override
  void initState() {
    super.initState();
    _cargarDatos();
  }

  Future<void> _cargarDatos() async {
    final phone  = await AuthPrefs.getUserPhone();
    final creds  = await PaymentPrefs.getCredits(phone);
    final method = await PaymentPrefs.getPreferredMethod();
    final txns   = await TransactionHistoryService.obtenerTransacciones(
        userPhone: phone);

    if (mounted) {
      setState(() {
        _userPhone    = phone;
        _creditos     = creds;
        _metodoPref   = method;
        _transacciones = txns;
        _loading      = false;
      });
    }
  }

  Future<void> _cambiarMetodo(String nuevoMetodo) async {
    await PaymentPrefs.savePreferredMethod(nuevoMetodo);
    setState(() => _metodoPref = nuevoMetodo);
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Método de pago actualizado a ${nuevoMetodo == 'cash' ? 'Efectivo' : 'PayPhone'}',
            style: TextStyle(color: Colors.white),
          ),
          backgroundColor: ConstantColors.success,
          behavior: SnackBarBehavior.floating,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          duration: Duration(seconds: 2),
        ),
      );
    }
  }

  /// Demo: agrega créditos de prueba (en producción esto vendría del servidor)
  Future<void> _agregarCreditosDemo() async {
    await showDialog(
      context: context,
      builder: (_) => _DialogAgregarCreditos(
        onConfirm: (monto) async {
          await PaymentPrefs.addCredits(_userPhone, monto);
          await _cargarDatos();
        },
      ),
    );
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
        title: Text('Mi Billetera',
            style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 17,
                fontWeight: FontWeight.w700)),
      ),
      body: _loading
          ? Center(
              child: CircularProgressIndicator(
                valueColor: AlwaysStoppedAnimation<Color>(
                    ConstantColors.primaryViolet),
              ),
            )
          : SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ── Tarjeta de saldo ─────────────────────────────────
                  _buildBalanceCard(),
                  SizedBox(height: 24),

                  // ── Método preferido ─────────────────────────────────
                  _buildPreferredMethod(),
                  SizedBox(height: 24),

                  // ── Historial de pagos ────────────────────────────────
                  _buildPaymentHistory(),
                  SizedBox(height: 40),
                ],
              ),
            ),
    );
  }

  // ─── Tarjeta de saldo ─────────────────────────────────────────────────
  Widget _buildBalanceCard() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            ConstantColors.primaryViolet.withOpacity(0.9),
            ConstantColors.primaryBlue.withOpacity(0.8),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: ConstantColors.primaryViolet.withOpacity(0.35),
            blurRadius: 20,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.account_balance_wallet_rounded,
                  color: Colors.white70, size: 18),
              SizedBox(width: 8),
              Text('Saldo de créditos',
                  style: TextStyle(color: Colors.white70, fontSize: 13)),
              Spacer(),
              GestureDetector(
                onTap: _agregarCreditosDemo,
                child: Container(
                  padding: EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: Colors.white30),
                  ),
                  child: Row(children: [
                    Icon(Icons.add_rounded, color: Colors.white, size: 14),
                    SizedBox(width: 4),
                    Text('Agregar',
                        style: TextStyle(
                            color: Colors.white,
                            fontSize: 12,
                            fontWeight: FontWeight.w600)),
                  ]),
                ),
              ),
            ],
          ),
          SizedBox(height: 16),
          Text(
            '\$${_creditos.toStringAsFixed(2)}',
            style: TextStyle(
              color: Colors.white,
              fontSize: 44,
              fontWeight: FontWeight.w900,
              letterSpacing: -1.5,
            ),
          ),
          Text('USD disponibles',
              style: TextStyle(color: Colors.white60, fontSize: 13)),
          SizedBox(height: 20),

          // Stats rápidos
          Row(
            children: [
              _buildQuickStat(
                '${_transacciones.where((t) => t.isSuccess).length}',
                'Pagos realizados',
              ),
              Container(
                  width: 1,
                  height: 30,
                  color: Colors.white24,
                  margin: EdgeInsets.symmetric(horizontal: 16)),
              _buildQuickStat(
                '\$${_transacciones.where((t) => t.isSuccess).fold(0.0, (s, t) => s + t.amount).toStringAsFixed(2)}',
                'Total gastado',
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildQuickStat(String value, String label) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(value,
          style: TextStyle(
              color: Colors.white,
              fontSize: 18,
              fontWeight: FontWeight.w800)),
      Text(label, style: TextStyle(color: Colors.white60, fontSize: 11)),
    ]);
  }

  // ─── Método de pago preferido ─────────────────────────────────────────
  Widget _buildPreferredMethod() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Método de pago preferido',
            style: TextStyle(
                color: ConstantColors.textWhite,
                fontSize: 16,
                fontWeight: FontWeight.w700)),
        Text(
            'Se pre-seleccionará automáticamente en cada viaje',
            style: TextStyle(color: ConstantColors.textSubtle, fontSize: 12)),
        SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: _buildMethodOption(
                label: 'Efectivo',
                icon: Icons.payments_rounded,
                value: 'cash',
                color: ConstantColors.warning,
              ),
            ),
            SizedBox(width: 12),
            Expanded(
              child: _buildMethodOption(
                label: 'PayPhone',
                icon: null,
                value: 'payphone',
                color: Color(0xFF00A6B4),
                payPhoneLogo: true,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildMethodOption({
    required String label,
    required IconData? icon,
    required String value,
    required Color color,
    bool payPhoneLogo = false,
  }) {
    final selected = _metodoPref == value;
    return GestureDetector(
      onTap: () => _cambiarMetodo(value),
      child: AnimatedContainer(
        duration: Duration(milliseconds: 200),
        padding: EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: selected ? color.withOpacity(0.15) : ConstantColors.backgroundCard,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected ? color : ConstantColors.borderColor,
            width: selected ? 2 : 1,
          ),
        ),
        child: Column(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: color.withOpacity(0.15),
                shape: BoxShape.circle,
              ),
              child: Center(
                child: payPhoneLogo
                    ? Text('P',
                        style: TextStyle(
                            color: color,
                            fontWeight: FontWeight.w900,
                            fontSize: 22))
                    : Icon(icon!, color: color, size: 22),
              ),
            ),
            SizedBox(height: 10),
            Text(label,
                style: TextStyle(
                    color: selected ? color : ConstantColors.textWhite,
                    fontWeight: FontWeight.w700,
                    fontSize: 14)),
            SizedBox(height: 4),
            if (selected)
              Container(
                padding: EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: color.withOpacity(0.2),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text('Predeterminado',
                    style: TextStyle(
                        color: color,
                        fontSize: 10,
                        fontWeight: FontWeight.w700)),
              ),
          ],
        ),
      ),
    );
  }

  // ─── Historial de pagos recientes ─────────────────────────────────────
  Widget _buildPaymentHistory() {
    final recientes = _transacciones.take(5).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text('Pagos recientes',
                style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 16,
                    fontWeight: FontWeight.w700)),
            if (_transacciones.length > 5)
              TextButton(
                onPressed: () => Navigator.pushNamed(context, '/payment_history'),
                child: Text('Ver todos',
                    style: TextStyle(
                        color: ConstantColors.primaryViolet, fontSize: 13)),
              ),
          ],
        ),
        SizedBox(height: 12),
        if (recientes.isEmpty)
          Container(
            padding: EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: ConstantColors.backgroundCard,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: ConstantColors.borderColor),
            ),
            child: Center(
              child: Text('Sin pagos registrados aún',
                  style: TextStyle(
                      color: ConstantColors.textGrey, fontSize: 13)),
            ),
          )
        else
          ...recientes.map((t) => _buildMiniTxnRow(t)).toList(),
      ],
    );
  }

  Widget _buildMiniTxnRow(TransactionModel t) {
    final isPP = t.method == 'payphone';
    final color = t.isSuccess ? ConstantColors.success : ConstantColors.error;

    return Container(
      margin: EdgeInsets.only(bottom: 8),
      padding: EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: ConstantColors.borderColor),
      ),
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: (isPP ? Color(0xFF00A6B4) : ConstantColors.warning)
                  .withOpacity(0.12),
              shape: BoxShape.circle,
            ),
            child: Center(
              child: isPP
                  ? Text('P',
                      style: TextStyle(
                          color: Color(0xFF00A6B4),
                          fontWeight: FontWeight.w900))
                  : Icon(Icons.payments_rounded,
                      color: ConstantColors.warning, size: 16),
            ),
          ),
          SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(t.reference,
                    style: TextStyle(
                        color: ConstantColors.textWhite,
                        fontSize: 13,
                        fontWeight: FontWeight.w600),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis),
                Text(t.methodLabel,
                    style: TextStyle(
                        color: ConstantColors.textSubtle, fontSize: 11)),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text('\$${t.amount.toStringAsFixed(2)}',
                  style: TextStyle(
                      color: ConstantColors.textWhite,
                      fontWeight: FontWeight.w700,
                      fontSize: 14)),
              Text(t.statusLabel,
                  style: TextStyle(color: color, fontSize: 11)),
            ],
          ),
        ],
      ),
    );
  }
}

// ─── Diálogo para agregar créditos (demo) ──────────────────────────────────
class _DialogAgregarCreditos extends StatefulWidget {
  final Future<void> Function(double monto) onConfirm;
  const _DialogAgregarCreditos({required this.onConfirm});

  @override
  State<_DialogAgregarCreditos> createState() => _DialogAgregarCreditosState();
}

class _DialogAgregarCreditosState extends State<_DialogAgregarCreditos> {
  double _selectedAmount = 5.0;
  bool _loading = false;
  final List<double> _opciones = [2.0, 5.0, 10.0, 20.0];

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: ConstantColors.backgroundCard,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Text('Agregar créditos',
          style: TextStyle(
              color: ConstantColors.textWhite, fontWeight: FontWeight.w700)),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('Selecciona el monto a agregar a tu billetera:',
              style: TextStyle(color: ConstantColors.textGrey, fontSize: 13)),
          SizedBox(height: 16),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: _opciones.map((monto) {
              final selected = _selectedAmount == monto;
              return GestureDetector(
                onTap: () => setState(() => _selectedAmount = monto),
                child: Container(
                  padding: EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                  decoration: BoxDecoration(
                    color: selected
                        ? ConstantColors.primaryViolet.withOpacity(0.2)
                        : ConstantColors.backgroundDark,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: selected
                          ? ConstantColors.primaryViolet
                          : ConstantColors.borderColor,
                      width: selected ? 2 : 1,
                    ),
                  ),
                  child: Text('\$$monto',
                      style: TextStyle(
                          color: selected
                              ? ConstantColors.primaryViolet
                              : ConstantColors.textWhite,
                          fontWeight: FontWeight.w700,
                          fontSize: 16)),
                ),
              );
            }).toList(),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text('Cancelar',
              style: TextStyle(color: ConstantColors.textGrey)),
        ),
        TextButton(
          onPressed: _loading
              ? null
              : () async {
                  setState(() => _loading = true);
                  await widget.onConfirm(_selectedAmount);
                  if (context.mounted) Navigator.pop(context);
                },
          child: _loading
              ? SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(
                      strokeWidth: 2,
                      valueColor: AlwaysStoppedAnimation<Color>(
                          ConstantColors.primaryViolet)))
              : Text('Agregar \$$_selectedAmount',
                  style: TextStyle(
                      color: ConstantColors.primaryViolet,
                      fontWeight: FontWeight.w700)),
        ),
      ],
    );
  }
}
