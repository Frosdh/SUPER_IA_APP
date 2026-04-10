import 'package:flutter/material.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Services/PayPhoneService.dart';
import 'package:webview_flutter/webview_flutter.dart';

/// Resultado que se devuelve al hacer pop de esta pantalla.
class PayPhoneWebViewResult {
  final bool approved;
  final String status;          // 'approved' | 'cancelled' | 'error'
  final int? payPhoneTransactionId;
  final String? errorMessage;

  PayPhoneWebViewResult({
    required this.approved,
    required this.status,
    this.payPhoneTransactionId,
    this.errorMessage,
  });
}

/// Pantalla que muestra el WebView de PayPhone Button para que el
/// pasajero ingrese los datos de su tarjeta y confirme el pago.
///
/// Recibe los argumentos vía Navigator:
///   {
///     'redirectUrl'          : String  ← URL que devuelve PayPhone al crear el pago
///     'paymentId'            : int     ← ID de PayPhone
///     'clientTransactionId'  : String  ← ID generado por la app
///     'amount'               : double  ← monto en dólares
///   }
class PayPhoneWebViewScreen extends StatefulWidget {
  static const String route = '/payphone_webview';

  const PayPhoneWebViewScreen({super.key});

  @override
  State<PayPhoneWebViewScreen> createState() => _PayPhoneWebViewScreenState();
}

class _PayPhoneWebViewScreenState extends State<PayPhoneWebViewScreen> {
  late final WebViewController _controller;
  bool _loading = true;
  bool _procesando = false;  // true mientras confirmamos con PayPhone API
  bool _initialized = false; // guard para didChangeDependencies

  @override
  void initState() {
    super.initState();
    // El controlador se configura en didChangeDependencies para tener args disponibles
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Solo inicializar una vez (didChangeDependencies puede llamarse varias veces)
    if (!_initialized) {
      _initialized = true;
      _initWebView();
    }
  }

  void _initWebView() {
    final args = ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?;
    if (args == null) return;

    final redirectUrl         = args['redirectUrl']         as String;
    final paymentId           = args['paymentId']           as int;
    final clientTransactionId = args['clientTransactionId'] as String;

    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(ConstantColors.backgroundDark)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (_) => setState(() => _loading = true),
          onPageFinished: (_) => setState(() => _loading = false),
          onWebResourceError: (error) {
            print('>>> [PAYPHONE_WV] Error cargando: ${error.description}');
          },
          onNavigationRequest: (request) {
            final url = request.url;
            print('>>> [PAYPHONE_WV] Navegando a: $url');

            // ── Pago exitoso ──────────────────────────────────────────────
            if (url.startsWith(Constants.payPhoneResponseUrl)) {
              _handlePaymentResponse(
                url: url,
                paymentId: paymentId,
                clientTransactionId: clientTransactionId,
              );
              return NavigationDecision.prevent;
            }

            // ── Pago cancelado ────────────────────────────────────────────
            if (url.startsWith(Constants.payPhoneCancelUrl)) {
              _returnResult(PayPhoneWebViewResult(
                approved: false,
                status: 'cancelled',
              ));
              return NavigationDecision.prevent;
            }

            return NavigationDecision.navigate;
          },
        ),
      )
      ..loadRequest(Uri.parse(redirectUrl));
  }

  // ─── Maneja el retorno de PayPhone tras el pago ────────────────────────
  Future<void> _handlePaymentResponse({
    required String url,
    required int paymentId,
    required String clientTransactionId,
  }) async {
    if (_procesando) return;
    setState(() {
      _procesando = true;
      _loading = true;
    });

    // Llamamos a PayPhone para confirmar que el pago fue aprobado
    final confirmResult = await PayPhoneService.confirmarPago(
      paymentId: paymentId,
      clientTransactionId: clientTransactionId,
    );

    if (mounted) {
      _returnResult(PayPhoneWebViewResult(
        approved: confirmResult.approved,
        status: confirmResult.approved ? 'approved' : 'cancelled',
        payPhoneTransactionId: confirmResult.payPhoneTransactionId,
        errorMessage: confirmResult.approved ? null : confirmResult.message,
      ));
    }
  }

  void _returnResult(PayPhoneWebViewResult result) {
    if (Navigator.of(context).canPop()) {
      Navigator.of(context).pop(result);
    }
  }

  // ─── Botón atrás: pregunta si quiere cancelar el pago ─────────────────
  Future<bool> _onWillPop() async {
    if (_procesando) return false; // No salir mientras confirmamos

    final cancel = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: ConstantColors.backgroundCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(
          '¿Cancelar el pago?',
          style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700),
        ),
        content: Text(
          'Si sales ahora el pago no se completará.',
          style: TextStyle(color: ConstantColors.textGrey),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text('Continuar pagando',
                style: TextStyle(color: ConstantColors.primaryViolet)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text('Cancelar pago',
                style: TextStyle(color: ConstantColors.error)),
          ),
        ],
      ),
    );

    if (cancel == true) {
      _returnResult(PayPhoneWebViewResult(
        approved: false,
        status: 'cancelled',
      ));
      return false; // La navegación la hace _returnResult
    }
    return false;
  }

  @override
  Widget build(BuildContext context) {
    final args = ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?;
    final amount = (args?['amount'] as num?)?.toDouble() ?? 0.0;

    return WillPopScope(
      onWillPop: _onWillPop,
      child: Scaffold(
        backgroundColor: ConstantColors.backgroundDark,
        appBar: AppBar(
          backgroundColor: ConstantColors.backgroundCard,
          elevation: 0,
          leading: IconButton(
            icon: Icon(Icons.close_rounded, color: ConstantColors.textWhite),
            onPressed: () => _onWillPop(),
          ),
          title: Row(
            children: [
              // Logo PayPhone
              Container(
                width: 28,
                height: 28,
                decoration: BoxDecoration(
                  color: const Color(0xFF00A6B4),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Center(
                  child: Text(
                    'P',
                    style: TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w900,
                      fontSize: 16,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Pago con PayPhone',
                    style: TextStyle(
                      color: ConstantColors.textWhite,
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  Text(
                    '\$${amount.toStringAsFixed(2)} USD',
                    style: TextStyle(
                      color: ConstantColors.primaryViolet,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ],
          ),
          // Indicador de seguridad
          actions: [
            Padding(
              padding: const EdgeInsets.only(right: 16),
              child: Row(
                children: [
                  Icon(Icons.lock_rounded, color: Colors.green, size: 14),
                  const SizedBox(width: 4),
                  Text(
                    'Seguro',
                    style: TextStyle(color: Colors.green, fontSize: 12),
                  ),
                ],
              ),
            ),
          ],
        ),
        body: Stack(
          children: [
            // WebView principal
            WebViewWidget(controller: _controller),

            // Loading overlay
            if (_loading || _procesando)
              Container(
                color: ConstantColors.backgroundDark.withOpacity(0.92),
                child: Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 70,
                        height: 70,
                        decoration: BoxDecoration(
                          color: const Color(0xFF00A6B4).withOpacity(0.15),
                          shape: BoxShape.circle,
                        ),
                        child: Center(
                          child: CircularProgressIndicator(
                            strokeWidth: 3,
                            valueColor: AlwaysStoppedAnimation<Color>(
                              const Color(0xFF00A6B4),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: 20),
                      Text(
                        _procesando
                            ? 'Confirmando tu pago...'
                            : 'Cargando pasarela de pago...',
                        style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        _procesando
                            ? 'Por favor espera un momento'
                            : 'Esto puede tomar unos segundos',
                        style: TextStyle(
                          color: ConstantColors.textGrey,
                          fontSize: 13,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
