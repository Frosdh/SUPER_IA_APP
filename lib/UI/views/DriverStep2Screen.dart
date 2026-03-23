import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/DriverRegistrationData.dart';
import 'package:fu_uber/Core/Networking/ApiProvider.dart';
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
  String _countryPrefix  = '+593';

  final _api = ApiProvider();
  bool _loadingCoops = false;
  List<dynamic> _cooperativas = [];

   bool _showPass    = false;
  bool _showConfirm = false;

  @override
  void initState() {
    super.initState();
    _loadCoops();
    _nombreCtrl.text   = widget.data.nombre;
    _emailCtrl.text    = widget.data.email;
    _telefonoCtrl.text = widget.data.telefono;
    _cedulaCtrl.text   = widget.data.cedula;
  }

  Future<void> _loadCoops() async {
    setState(() => _loadingCoops = true);
    final resp = await _api.obtenerCooperativas();
    if (mounted) {
      setState(() {
        _loadingCoops = false;
        if (resp['status'] == 'success') {
          _cooperativas = resp['cooperativas'] ?? [];
        }
      });
    }
  }

  @override
  void dispose() {
    _nombreCtrl.dispose(); _emailCtrl.dispose(); _telefonoCtrl.dispose();
    _cedulaCtrl.dispose(); _passCtrl.dispose(); _confirmCtrl.dispose();
    super.dispose();
  }

   void _next() {
    if (!_formKey.currentState!.validate()) return;

    if (widget.data.tipoConductor == 'cooperativa' && widget.data.cooperativaId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Por favor, selecciona tu cooperativa')),
      );
      return;
    }

    widget.data
      ..nombre   = _nombreCtrl.text.trim()
      ..email    = _emailCtrl.text.trim()
      ..telefono = '$_countryPrefix${_telefonoCtrl.text.trim()}'
      ..cedula   = _cedulaCtrl.text.trim()
      ..password = _passCtrl.text.trim();
    Navigator.push(context, MaterialPageRoute(
      builder: (_) => DriverStep3Screen(data: widget.data),
    ));
  }

  void _showPrefixPicker() {
    final prefixes = [
      {'name': 'Ecuador', 'code': '+593'},
      {'name': 'Estados Unidos', 'code': '+1'},
      {'name': 'Colombia', 'code': '+57'},
      {'name': 'Perú', 'code': '+51'},
      {'name': 'Argentina', 'code': '+54'},
      {'name': 'Chile', 'code': '+56'},
      {'name': 'España', 'code': '+34'},
      {'name': 'México', 'code': '+52'},
    ];

    showModalBottomSheet(
      context: context,
      backgroundColor: ConstantColors.backgroundDark,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (context) => Container(
        padding: const EdgeInsets.symmetric(vertical: 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Handle
            Container(
              width: 40, height: 4,
              margin: const EdgeInsets.only(bottom: 20),
              decoration: BoxDecoration(
                color: Colors.white24,
                borderRadius: BorderRadius.circular(4),
              ),
            ),
            const Text('Selecciona tu país', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 15),
            Expanded(
              child: ListView.builder(
                itemCount: prefixes.length,
                itemBuilder: (context, i) => ListTile(
                  leading: const Icon(Icons.public_rounded, color: ConstantColors.primaryBlue),
                  title: Text(prefixes[i]['name']!, style: const TextStyle(color: Colors.white)),
                  trailing: Text(prefixes[i]['code']!, style: const TextStyle(color: ConstantColors.textGrey, fontWeight: FontWeight.bold)),
                  onTap: () {
                    setState(() => _countryPrefix = prefixes[i]['code']!);
                    Navigator.pop(context);
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
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
                              Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  GestureDetector(
                                    onTap: _showPrefixPicker,
                                    child: Container(
                                      height: 58,
                                      padding: const EdgeInsets.symmetric(horizontal: 14),
                                      margin: const EdgeInsets.only(right: 8),
                                      decoration: BoxDecoration(
                                        color: ConstantColors.backgroundCard,
                                        borderRadius: BorderRadius.circular(18),
                                        border: Border.all(color: ConstantColors.borderColor.withOpacity(0.9)),
                                      ),
                                      child: Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          const Icon(Icons.public_rounded, color: ConstantColors.primaryBlue, size: 18),
                                          const SizedBox(width: 6),
                                          Text(_countryPrefix, style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w600)),
                                        ],
                                      ),
                                    ),
                                  ),
                                  Expanded(
                                    child: _field(ctrl: _telefonoCtrl, label: 'Teléfono', icon: Icons.phone_outlined,
                                      keyboard: TextInputType.phone,
                                      formatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(12)],
                                      validator: (v) {
                                        final val = (v ?? '').trim();
                                        if (val.isEmpty) return 'Ingresa tu número';
                                        if (val.length < 7) return 'Número muy corto';
                                        return null;
                                      }),
                                  ),
                                ],
                              ),
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
                          _card(
                            icon: Icons.group_outlined, title: 'Perfil de Conductor', subtitle: '¿Cómo deseas registrarte?',
                            children: [
                              Row(
                                children: [
                                  Expanded(
                                    child: _buildProfileTypeCard(
                                      title: 'Independiente',
                                      icon: Icons.person_pin_outlined,
                                      selected: widget.data.tipoConductor == 'independiente',
                                      onTap: () => setState(() => widget.data.tipoConductor = 'independiente'),
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: _buildProfileTypeCard(
                                      title: 'Cooperativa',
                                      icon: Icons.group_outlined,
                                      selected: widget.data.tipoConductor == 'cooperativa',
                                      onTap: () => setState(() => widget.data.tipoConductor = 'cooperativa'),
                                    ),
                                  ),
                                ],
                              ),
                              if (widget.data.tipoConductor == 'cooperativa') ...[
                                const SizedBox(height: 16),
                                _buildCooperativaDropdown(),
                              ],
                            ],
                          ),
                          const SizedBox(height: 14),
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

  Widget _buildProfileTypeCard({
    required String title,
    required IconData icon,
    required bool selected,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 250),
        padding: const EdgeInsets.symmetric(vertical: 16),
        decoration: BoxDecoration(
          color: selected
              ? ConstantColors.primaryBlue.withOpacity(0.15)
              : ConstantColors.backgroundCard,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: selected
                ? ConstantColors.primaryBlue
                : ConstantColors.borderColor.withOpacity(0.6),
            width: selected ? 2 : 1,
          ),
        ),
        child: Column(
          children: [
            Icon(icon,
              color: selected ? ConstantColors.primaryBlue : ConstantColors.textGrey,
              size: 28),
            const SizedBox(height: 8),
            Text(title,
              style: TextStyle(
                color: selected ? Colors.white : ConstantColors.textGrey,
                fontSize: 13, fontWeight: selected ? FontWeight.w700 : FontWeight.normal,
              )),
          ],
        ),
      ),
    );
  }

  Widget _buildCooperativaDropdown() {
    return Container(
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: ConstantColors.borderColor.withOpacity(0.9)),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<int>(
          value: widget.data.cooperativaId,
          hint: Text(
            _loadingCoops ? 'Cargando...' : 'Selecciona tu cooperativa',
            style: const TextStyle(color: ConstantColors.textGrey, fontSize: 14),
          ),
          dropdownColor: ConstantColors.backgroundDark,
          icon: const Icon(Icons.arrow_drop_down, color: ConstantColors.primaryBlue),
          isExpanded: true,
          style: const TextStyle(color: Colors.white, fontSize: 15),
          items: _cooperativas.map((c) {
            return DropdownMenuItem<int>(
              value: int.tryParse(c['id'].toString()),
              child: Text(c['nombre']?.toString() ?? ''),
            );
          }).toList(),
          onChanged: (val) {
            setState(() {
              widget.data.cooperativaId = val;
              // Lógica de auto-selección: Si el nombre contiene "taxi", categoría = 4
              final coop = _cooperativas.firstWhere((c) => c['id'].toString() == val.toString(), orElse: () => null);
              if (coop != null) {
                final nombre = coop['nombre'].toString().toLowerCase();
                if (nombre.contains('taxi')) {
                  widget.data.categoriaId = 4;
                }
              }
            });
          },
        ),
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
