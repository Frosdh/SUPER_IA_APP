import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Networking/ApiProvider.dart';
import 'package:super_ia/UI/views/NuevaPasswordScreen.dart';

class VerificarOtpScreen extends StatefulWidget {
  static const String route = '/verificar-otp';

  const VerificarOtpScreen({Key? key}) : super(key: key);

  @override
  _VerificarOtpScreenState createState() => _VerificarOtpScreenState();
}

class _VerificarOtpScreenState extends State<VerificarOtpScreen> {
  final List<TextEditingController> _controllers =
      List.generate(6, (_) => TextEditingController());
  final List<FocusNode> _focusNodes = List.generate(6, (_) => FocusNode());

  bool _loading = false;
  bool _reenvioLoading = false;
  String _email = '';

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final args = ModalRoute.of(context)?.settings.arguments;
    if (args is Map) {
      _email = (args['email'] ?? '').toString();
    }
  }

  @override
  void dispose() {
    for (final c in _controllers) {
      c.dispose();
    }
    for (final f in _focusNodes) {
      f.dispose();
    }
    super.dispose();
  }

  String get _codigoCompleto =>
      _controllers.map((c) => c.text).join();

  Future<void> _verificarOtp() async {
    final codigo = _codigoCompleto;
    if (codigo.length < 6) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Ingresa los 6 dígitos del código.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    setState(() => _loading = true);
    try {
      final api = ApiProvider();
      final res = await api.verificarOtpAsesor(
        email: _email,
        codigo: codigo,
      );

      if (!mounted) return;

      if ((res['status'] ?? '') == 'success') {
        Navigator.pushNamed(
          context,
          NuevaPasswordScreen.route,
          arguments: {'email': _email},
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content:
                Text(res['message'] ?? 'Código incorrecto o expirado.'),
            backgroundColor: Colors.redAccent,
          ),
        );
        // Limpiar campos
        for (final c in _controllers) {
          c.clear();
        }
        _focusNodes[0].requestFocus();
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Error de conexión: $e'),
          backgroundColor: Colors.redAccent,
        ),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _reenviarOtp() async {
    if (_reenvioLoading) return;
    setState(() => _reenvioLoading = true);
    try {
      final api = ApiProvider();
      await api.enviarOtpAsesor(email: _email);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Código reenviado. Revisa tu correo.'),
          backgroundColor: Colors.green,
        ),
      );
    } catch (_) {
    } finally {
      if (mounted) setState(() => _reenvioLoading = false);
    }
  }

  Widget _buildOtpBox(int index) {
    return SizedBox(
      width: 46,
      height: 56,
      child: TextFormField(
        controller: _controllers[index],
        focusNode: _focusNodes[index],
        textAlign: TextAlign.center,
        keyboardType: TextInputType.number,
        maxLength: 1,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        style: const TextStyle(
          color: Colors.white,
          fontSize: 22,
          fontWeight: FontWeight.w700,
        ),
        decoration: InputDecoration(
          counterText: '',
          filled: true,
          fillColor: ConstantColors.backgroundCard,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: ConstantColors.borderColor),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(color: ConstantColors.borderColor),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: BorderSide(
              color: ConstantColors.primaryBlue,
              width: 2,
            ),
          ),
        ),
        onChanged: (value) {
          if (value.isNotEmpty && index < 5) {
            _focusNodes[index + 1].requestFocus();
          }
          if (value.isEmpty && index > 0) {
            _focusNodes[index - 1].requestFocus();
          }
          // Auto-verificar cuando se completan los 6 dígitos
          if (_codigoCompleto.length == 6) {
            _verificarOtp();
          }
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: ConstantColors.warning,
      body: Stack(
        children: [
          Container(color: ConstantColors.warning),
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: Container(
              height: size.height * 0.40,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    ConstantColors.warning.withOpacity(0.28),
                    ConstantColors.primaryBlue.withOpacity(0.22),
                    ConstantColors.warning,
                  ],
                ),
              ),
            ),
          ),
          SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(18, 14, 18, 28),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Back button
                  IconButton(
                    padding: EdgeInsets.zero,
                    constraints:
                        const BoxConstraints(minWidth: 36, minHeight: 36),
                    icon:
                        const Icon(Icons.arrow_back, color: Colors.white),
                    onPressed: () => Navigator.pop(context),
                  ),
                  const SizedBox(height: 4),

                  // Header
                  Center(
                    child: Container(
                      width: size.width > 430 ? 400 : double.infinity,
                      padding: const EdgeInsets.fromLTRB(24, 18, 24, 24),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.04),
                        borderRadius: BorderRadius.circular(28),
                        border: Border.all(
                          color: Colors.white.withOpacity(0.08),
                        ),
                      ),
                      child: Column(
                        children: [
                          Container(
                            width: 68,
                            height: 68,
                            decoration: BoxDecoration(
                              gradient: ConstantColors.yellowBlueGradient,
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: const Icon(
                              Icons.mark_email_read_rounded,
                              color: Colors.white,
                              size: 34,
                            ),
                          ),
                          const SizedBox(height: 16),
                          const Text(
                            'Verifica tu código',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 24,
                              fontWeight: FontWeight.w800,
                              height: 1.1,
                            ),
                          ),
                          const SizedBox(height: 10),
                          Text(
                            'Ingresa el código de 6 dígitos que enviamos a:',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: Colors.white.withOpacity(0.70),
                              fontSize: 13.5,
                              height: 1.45,
                            ),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            _email,
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: ConstantColors.primaryBlue,
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 26),

                  // OTP boxes card
                  Container(
                    padding: const EdgeInsets.fromLTRB(18, 24, 18, 24),
                    decoration: BoxDecoration(
                      color: ConstantColors.backgroundDark.withOpacity(0.24),
                      borderRadius: BorderRadius.circular(28),
                      border: Border.all(
                        color: ConstantColors.borderColor.withOpacity(0.8),
                      ),
                    ),
                    child: Column(
                      children: [
                        // 6 boxes
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                          children: List.generate(
                            6,
                            (index) => _buildOtpBox(index),
                          ),
                        ),
                        const SizedBox(height: 28),

                        // Verify button
                        SizedBox(
                          width: double.infinity,
                          height: 54,
                          child: DecoratedBox(
                            decoration: BoxDecoration(
                              gradient: ConstantColors.yellowBlueGradient,
                              borderRadius: BorderRadius.circular(16),
                              boxShadow: [
                                BoxShadow(
                                  color: ConstantColors.primaryBlue
                                      .withOpacity(0.28),
                                  blurRadius: 22,
                                  offset: const Offset(0, 10),
                                ),
                              ],
                            ),
                            child: ElevatedButton(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: Colors.transparent,
                                elevation: 0,
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(16),
                                ),
                              ),
                              onPressed: _loading ? null : _verificarOtp,
                              child: _loading
                                  ? const SizedBox(
                                      width: 22,
                                      height: 22,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2.5,
                                        valueColor:
                                            AlwaysStoppedAnimation<Color>(
                                                Colors.white),
                                      ),
                                    )
                                  : const Row(
                                      mainAxisAlignment:
                                          MainAxisAlignment.center,
                                      children: [
                                        Icon(Icons.verified_rounded,
                                            color: Colors.white, size: 18),
                                        SizedBox(width: 8),
                                        Text(
                                          'Verificar código',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 15,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                      ],
                                    ),
                            ),
                          ),
                        ),
                        const SizedBox(height: 18),

                        // Resend link
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text(
                              '¿No recibiste el código? ',
                              style: TextStyle(
                                color: ConstantColors.textGrey,
                                fontSize: 13,
                              ),
                            ),
                            GestureDetector(
                              onTap: _reenvioLoading
                                  ? null
                                  : _reenviarOtp,
                              child: _reenvioLoading
                                  ? const SizedBox(
                                      width: 14,
                                      height: 14,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                      ),
                                    )
                                  : Text(
                                      'Reenviar',
                                      style: TextStyle(
                                        color: ConstantColors.primaryBlue,
                                        fontSize: 13,
                                        fontWeight: FontWeight.w600,
                                        decoration:
                                            TextDecoration.underline,
                                        decorationColor:
                                            ConstantColors.primaryBlue,
                                      ),
                                    ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 22),

                  // Info tip
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: ConstantColors.backgroundCard,
                      borderRadius: BorderRadius.circular(18),
                      border: Border.all(
                        color: ConstantColors.borderColor.withOpacity(0.9),
                      ),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(Icons.timer_outlined,
                            color: ConstantColors.primaryBlue, size: 18),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            'El código es válido por 10 minutos. Si expiró, vuelve atrás y solicita uno nuevo.',
                            style: TextStyle(
                              color: ConstantColors.textGrey,
                              fontSize: 12.5,
                              height: 1.45,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
