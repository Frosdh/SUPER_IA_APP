import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/DriverRegistrationData.dart';
import 'package:fu_uber/UI/views/DriverStep3Screen.dart';

class DriverStep2Screen extends StatefulWidget {
  final DriverRegistrationData data;
  const DriverStep2Screen({Key? key, required this.data}) : super(key: key);

  @override
  _DriverStep2ScreenState createState() => _DriverStep2ScreenState();
}

class _DriverStep2ScreenState extends State<DriverStep2Screen> {
  final _formKey         = GlobalKey<FormState>();
  final _nombreCtrl      = TextEditingController();
  final _emailCtrl       = TextEditingController();
  final _telefonoCtrl    = TextEditingController();
  final _cedulaCtrl      = TextEditingController();
  final _passCtrl        = TextEditingController();
  final _confirmCtrl     = TextEditingController();

  bool _showPass    = false;
  bool _showConfirm = false;

  @override
  void dispose() {
    _nombreCtrl.dispose(); _emailCtrl.dispose(); _telefonoCtrl.dispose();
    _cedulaCtrl.dispose(); _passCtrl.dispose(); _confirmCtrl.dispose();
    super.dispose();
  }

  void _next() {
    if (!_formKey.currentState!.validate()) return;
    widget.data
      ..nombre   = _nombreCtrl.text.trim()
      ..email    = _emailCtrl.text.trim()
      ..telefono = _telefonoCtrl.text.trim()
      ..cedula   = _cedulaCtrl.text.trim()
      ..password = _passCtrl.text.trim();
    Navigator.push(context, MaterialPageRoute(
      builder: (_) => DriverStep3Screen(data: widget.data),
    ));
  }

  Widget _field({
    required TextEditingController ctrl,
    required String label,
    required IconData icon,
    bool obscure = false,
    bool? showText,
    VoidCallback? onToggle,
    TextInputType keyboard = TextInputType.text,
    List<TextInputFormatter>? formatters,
    String? Function(String?)? validator,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: ConstantColors.borderColor.withOpacity(0.9)),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      child: TextFormField(
        controller: ctrl,
        obscureText: obscure && !(showText ?? false),
        keyboardType: keyboard,
        inputFormatters: formatters,
        style: const TextStyle(color: Colors.white, fontSize: 15),
        decoration: InputDecoration(
          prefixIcon: Icon(icon, color: ConstantColors.primaryBlue, size: 20),
          labelText: label,
          labelStyle: const TextStyle(color: ConstantColors.textGrey, fontSize: 14),
          border: InputBorder.none,
          suffixIcon: (obscure && onToggle != null)
              ? IconButton(
                  icon: Icon(
                    (showText ?? false) ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                    color: ConstantColors.textGrey, size: 20,
                  ),
                  onPressed: onToggle,
                )
              : null,
        ),
        validator: validator,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
        children: [
          Positioned(top: 0, left: 0, right: 0,
            child: Container(height: size.height * 0.28,
              decoration: const BoxDecoration(
                gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight,
                  colors: [Color(0xFF0F0C29), Color(0xFF302B63), Color(0xFF24243E)]),
              ),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 4, 16, 0),
                  child: Row(
                    children: [
                      IconButton(icon: const Icon(Icons.arrow_back, color: Colors.white),
                        onPressed: () => Navigator.pop(context)),
                      const Text('Registro de Conductor',
                        style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700)),
                    ],
                  ),
                ),
                _buildProgress(paso: 2, total: 6, label: 'Datos personales'),
                Expanded(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(24, 4, 24, 32),
                    child: Form(
                      key: _formKey,
                      child: Column(
                        children: [
                          _card(
                            icon: Icons.person_outline, title: 'Tus datos', subtitle: 'Información básica de tu cuenta',
                            children: [
                              _field(ctrl: _nombreCtrl, label: 'Nombre completo', icon: Icons.badge_outlined,
                                validator: (v) {
                                  if ((v ?? '').trim().isEmpty) return 'Ingresa tu nombre completo';
                                  if (v!.trim().split(' ').length < 2) return 'Ingresa nombre y apellido';
                                  return null;
                                }),
                              _field(ctrl: _emailCtrl, label: 'Correo electrónico', icon: Icons.email_outlined,
                                keyboard: TextInputType.emailAddress,
                                validator: (v) {
                                  final val = (v ?? '').trim();
                                  if (val.isEmpty) return 'Ingresa tu correo';
                                  if (!val.contains('@') || !val.contains('.')) return 'Correo inválido';
                                  return null;
                                }),
                              _field(ctrl: _telefonoCtrl, label: 'Teléfono (10 dígitos)', icon: Icons.phone_outlined,
                                keyboard: TextInputType.phone,
                                formatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(10)],
                                validator: (v) {
                                  if ((v ?? '').trim().length != 10) return 'Debe tener 10 dígitos';
                                  return null;
                                }),
                              _field(ctrl: _cedulaCtrl, label: 'Cédula (10 dígitos)', icon: Icons.credit_card_outlined,
                                keyboard: TextInputType.number,
                                formatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(10)],
                                validator: (v) {
                                  if ((v ?? '').trim().length != 10) return 'La cédula debe tener 10 dígitos';
                                  return null;
                                }),
                            ],
                          ),
                          const SizedBox(height: 14),
                          _card(
                            icon: Icons.lock_outline, title: 'Contraseña', subtitle: 'Crea una clave segura',
                            children: [
                              _field(ctrl: _passCtrl, label: 'Contraseña', icon: Icons.lock_outline,
                                obscure: true, showText: _showPass, onToggle: () => setState(() => _showPass = !_showPass),
                                validator: (v) {
                                  if ((v ?? '').trim().length < 6) return 'Mínimo 6 caracteres';
                                  return null;
                                }),
                              _field(ctrl: _confirmCtrl, label: 'Confirmar contraseña', icon: Icons.lock_reset_outlined,
                                obscure: true, showText: _showConfirm, onToggle: () => setState(() => _showConfirm = !_showConfirm),
                                validator: (v) {
                                  if ((v ?? '').trim() != _passCtrl.text.trim()) return 'Las contraseñas no coinciden';
                                  return null;
                                }),
                            ],
                          ),
                          const SizedBox(height: 24),
                          _btnNext(onPressed: _next, label: 'Siguiente: Documentos'),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ── Widgets compartidos en todos los Steps ────────────────────

Widget _buildProgress({required int paso, required int total, required String label}) {
  return Padding(
    padding: const EdgeInsets.fromLTRB(24, 12, 24, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text('Paso $paso de $total — $label',
              style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
            Text('${((paso / total) * 100).round()}%',
              style: TextStyle(color: ConstantColors.primaryBlue, fontSize: 13, fontWeight: FontWeight.w700)),
          ],
        ),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: paso / total, minHeight: 6,
            backgroundColor: Colors.white.withOpacity(0.12),
            valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryBlue),
          ),
        ),
      ],
    ),
  );
}

Widget _card({required IconData icon, required String title, required String subtitle, required List<Widget> children}) {
  return Container(
    padding: const EdgeInsets.all(20),
    decoration: BoxDecoration(
      color: Colors.black.withOpacity(0.22),
      borderRadius: BorderRadius.circular(24),
      border: Border.all(color: ConstantColors.borderColor.withOpacity(0.7)),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(gradient: ConstantColors.buttonGradient, borderRadius: BorderRadius.circular(12)),
            child: Icon(icon, color: Colors.white, size: 20),
          ),
          const SizedBox(width: 12),
          Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w700)),
            Text(subtitle, style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
          ]),
        ]),
        const SizedBox(height: 20),
        ...children,
      ],
    ),
  );
}

Widget _btnNext({required VoidCallback onPressed, required String label, bool loading = false}) {
  return SizedBox(
    width: double.infinity, height: 54,
    child: DecoratedBox(
      decoration: BoxDecoration(
        gradient: ConstantColors.buttonGradient,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [BoxShadow(color: ConstantColors.primaryViolet.withOpacity(0.28), blurRadius: 20, offset: const Offset(0, 8))],
      ),
      child: ElevatedButton(
        style: ElevatedButton.styleFrom(
          backgroundColor: Colors.transparent, shadowColor: Colors.transparent,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        onPressed: loading ? null : onPressed,
        child: loading
            ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
            : Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                Text(label, style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700)),
                const SizedBox(width: 8),
                const Icon(Icons.arrow_forward_rounded, color: Colors.white, size: 18),
              ]),
      ),
    ),
  );
}
