import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/DriverRegistrationData.dart';
import 'package:fu_uber/UI/views/DriverStep5Screen.dart';

class DriverStep4Screen extends StatefulWidget {
  final DriverRegistrationData data;
  const DriverStep4Screen({Key? key, required this.data}) : super(key: key);

  @override
  _DriverStep4ScreenState createState() => _DriverStep4ScreenState();
}

class _DriverStep4ScreenState extends State<DriverStep4Screen> {
  final _formKey    = GlobalKey<FormState>();
  final _marcaCtrl  = TextEditingController();
  final _modeloCtrl = TextEditingController();
  final _placaCtrl  = TextEditingController();
  final _colorCtrl  = TextEditingController();
  final _anioCtrl   = TextEditingController();

  final List<_CatItem> _categorias = const [
    _CatItem(id: 1, nombre: 'Fuber-X',   icon: Icons.directions_car,   desc: 'Estándar'),
    _CatItem(id: 2, nombre: 'Fuber-Plus', icon: Icons.airport_shuttle,  desc: 'Lujo'),
    _CatItem(id: 3, nombre: 'Fuber-Moto', icon: Icons.two_wheeler,      desc: 'Moto'),
  ];

  @override
  void initState() {
    super.initState();
    // Pre-fill si ya hay datos
    _marcaCtrl.text  = widget.data.marca;
    _modeloCtrl.text = widget.data.modelo;
    _placaCtrl.text  = widget.data.placa;
    _colorCtrl.text  = widget.data.color;
    _anioCtrl.text   = widget.data.anio > 0 ? widget.data.anio.toString() : '';
  }

  @override
  void dispose() {
    _marcaCtrl.dispose(); _modeloCtrl.dispose(); _placaCtrl.dispose();
    _colorCtrl.dispose(); _anioCtrl.dispose();
    super.dispose();
  }

  void _next() {
    if (!_formKey.currentState!.validate()) return;
    widget.data
      ..marca       = _marcaCtrl.text.trim()
      ..modelo      = _modeloCtrl.text.trim()
      ..placa       = _placaCtrl.text.trim().toUpperCase()
      ..color       = _colorCtrl.text.trim()
      ..anio        = int.tryParse(_anioCtrl.text.trim()) ?? 2020;
    Navigator.push(context, MaterialPageRoute(
      builder: (_) => DriverStep5Screen(data: widget.data),
    ));
  }

  Widget _field({
    required TextEditingController ctrl, required String label, required IconData icon,
    TextInputType keyboard = TextInputType.text,
    List<TextInputFormatter>? formatters,
    String? Function(String?)? validator,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard, borderRadius: BorderRadius.circular(18),
        border: Border.all(color: ConstantColors.borderColor.withOpacity(0.9)),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      child: TextFormField(
        controller: ctrl, keyboardType: keyboard, inputFormatters: formatters,
        style: const TextStyle(color: Colors.white, fontSize: 15),
        decoration: InputDecoration(
          prefixIcon: Icon(icon, color: ConstantColors.primaryBlue, size: 20),
          labelText: label,
          labelStyle: const TextStyle(color: ConstantColors.textGrey, fontSize: 14),
          border: InputBorder.none,
        ),
        validator: validator ?? (v) => (v?.trim().isEmpty ?? true) ? 'Campo requerido' : null,
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
                  child: Row(children: [
                    IconButton(icon: const Icon(Icons.arrow_back, color: Colors.white), onPressed: () => Navigator.pop(context)),
                    const Text('Registro de Conductor',
                      style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700)),
                  ]),
                ),
                _buildProgress(paso: 4, total: 6, label: 'Datos del vehículo'),
                Expanded(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(24, 4, 24, 32),
                    child: Form(
                      key: _formKey,
                      child: Column(
                        children: [
                          // Selector de categoría
                          _cardSection(
                            icon: Icons.category_outlined, title: 'Tipo de servicio',
                            subtitle: 'Selecciona la categoría de tu vehículo',
                            child: Row(
                              children: _categorias.map((cat) {
                                final sel = widget.data.categoriaId == cat.id;
                                return Expanded(
                                  child: GestureDetector(
                                    onTap: () => setState(() => widget.data.categoriaId = cat.id),
                                    child: AnimatedContainer(
                                      duration: const Duration(milliseconds: 200),
                                      margin: const EdgeInsets.symmetric(horizontal: 4),
                                      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 4),
                                      decoration: BoxDecoration(
                                        gradient: sel ? ConstantColors.buttonGradient : null,
                                        color: sel ? null : ConstantColors.backgroundCard,
                                        borderRadius: BorderRadius.circular(14),
                                        border: Border.all(
                                          color: sel ? Colors.transparent : ConstantColors.borderColor.withOpacity(0.7)),
                                      ),
                                      child: Column(children: [
                                        Icon(cat.icon, color: sel ? Colors.white : ConstantColors.textGrey, size: 22),
                                        const SizedBox(height: 6),
                                        Text(cat.nombre, textAlign: TextAlign.center,
                                          style: TextStyle(
                                            color: sel ? Colors.white : ConstantColors.textGrey,
                                            fontSize: 11, fontWeight: sel ? FontWeight.w700 : FontWeight.normal)),
                                        Text(cat.desc, textAlign: TextAlign.center,
                                          style: TextStyle(
                                            color: sel ? Colors.white.withOpacity(0.75) : ConstantColors.textGrey.withOpacity(0.6),
                                            fontSize: 9)),
                                      ]),
                                    ),
                                  ),
                                );
                              }).toList(),
                            ),
                          ),
                          const SizedBox(height: 14),
                          // Datos del vehículo
                          _cardSection(
                            icon: Icons.directions_car_outlined, title: 'Información del vehículo',
                            subtitle: 'Datos del vehículo que usarás',
                            child: Column(children: [
                              _field(ctrl: _marcaCtrl,  label: 'Marca (Ej: Toyota)',   icon: Icons.directions_car_outlined),
                              _field(ctrl: _modeloCtrl, label: 'Modelo (Ej: Corolla)',  icon: Icons.drive_eta_outlined),
                              _field(ctrl: _placaCtrl,  label: 'Placa (Ej: ABC-1234)', icon: Icons.confirmation_number_outlined,
                                formatters: [_UpperCaseFormatter(), LengthLimitingTextInputFormatter(8)]),
                              _field(ctrl: _colorCtrl,  label: 'Color (Ej: Blanco)',   icon: Icons.color_lens_outlined),
                              _field(ctrl: _anioCtrl,   label: 'Año (Ej: 2020)',       icon: Icons.calendar_today_outlined,
                                keyboard: TextInputType.number,
                                formatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(4)],
                                validator: (v) {
                                  final val = int.tryParse(v ?? '');
                                  if (val == null) return 'Ingresa el año';
                                  if (val < 1990 || val > DateTime.now().year + 1) return 'Año inválido';
                                  return null;
                                }),
                            ]),
                          ),
                          const SizedBox(height: 24),
                          _btnNext(onPressed: _next, label: 'Siguiente: Foto de perfil'),
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

class _CatItem {
  final int id;
  final String nombre;
  final IconData icon;
  final String desc;
  const _CatItem({required this.id, required this.nombre, required this.icon, required this.desc});
}

class _UpperCaseFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(TextEditingValue o, TextEditingValue n) =>
      n.copyWith(text: n.text.toUpperCase());
}

Widget _cardSection({required IconData icon, required String title, required String subtitle, required Widget child}) {
  return Container(
    padding: const EdgeInsets.all(20),
    decoration: BoxDecoration(
      color: Colors.black.withOpacity(0.22), borderRadius: BorderRadius.circular(24),
      border: Border.all(color: ConstantColors.borderColor.withOpacity(0.7)),
    ),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
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
      child,
    ]),
  );
}

Widget _buildProgress({required int paso, required int total, required String label}) {
  return Padding(
    padding: const EdgeInsets.fromLTRB(24, 12, 24, 12),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text('Paso $paso de $total — $label',
          style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
        Text('${((paso / total) * 100).round()}%',
          style: TextStyle(color: ConstantColors.primaryBlue, fontSize: 13, fontWeight: FontWeight.w700)),
      ]),
      const SizedBox(height: 8),
      ClipRRect(
        borderRadius: BorderRadius.circular(6),
        child: LinearProgressIndicator(
          value: paso / total, minHeight: 6,
          backgroundColor: Colors.white.withOpacity(0.12),
          valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryBlue),
        ),
      ),
    ]),
  );
}

Widget _btnNext({required VoidCallback onPressed, required String label, bool loading = false}) {
  return SizedBox(
    width: double.infinity, height: 54,
    child: DecoratedBox(
      decoration: BoxDecoration(
        gradient: ConstantColors.buttonGradient, borderRadius: BorderRadius.circular(16),
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
