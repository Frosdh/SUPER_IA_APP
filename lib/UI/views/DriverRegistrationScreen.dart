import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/UI/views/VehicleRegistrationScreen.dart';
import 'package:super_ia/Core/Networking/ApiProvider.dart';

class DriverRegistrationScreen extends StatefulWidget {
  static const String route = '/driver_registration';

  @override
  _DriverRegistrationScreenState createState() =>
      _DriverRegistrationScreenState();
}

class _DriverRegistrationScreenState
    extends State<DriverRegistrationScreen> {
  final _formKey = GlobalKey<FormState>();

  final _nombreController    = TextEditingController();
  final _telefonoController  = TextEditingController();
  final _cedulaController    = TextEditingController();
  final _passwordController  = TextEditingController();
  final _confirmController   = TextEditingController();

  bool _showPassword  = false;
  bool _showConfirm   = false;

  // Registro Dual
  String _tipoConductor = 'independiente'; // 'independiente' o 'cooperativa'
  int? _cooperativaId;
  List<dynamic> _cooperativas = [];
  bool _loadingCooperativas = false;

  @override
  void initState() {
    super.initState();
    _fetchCooperativas();
  }

  Future<void> _fetchCooperativas() async {
    setState(() => _loadingCooperativas = true);
    final api = ApiProvider();
    final resp = await api.obtenerCooperativas();
    if (mounted && resp['status'] == 'success') {
      setState(() {
        _cooperativas = resp['cooperativas'] ?? [];
        _loadingCooperativas = false;
      });
    } else if (mounted) {
      setState(() => _loadingCooperativas = false);
    }
  }

  @override
  void dispose() {
    _nombreController.dispose();
    _telefonoController.dispose();
    _cedulaController.dispose();
    _passwordController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  void _nextStep() {
    if (_formKey.currentState!.validate()) {
      if (_tipoConductor == 'cooperativa' && _cooperativaId == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Por favor, selecciona tu cooperativa')),
        );
        return;
      }

      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (context) => VehicleRegistrationScreen(
            nombre:   _nombreController.text.trim(),
            telefono: _telefonoController.text.trim(),
            cedula:   _cedulaController.text.trim(),
            password: _passwordController.text.trim(),
            tipoConductor: _tipoConductor,
            cooperativaId: _cooperativaId,
          ),
        ),
      );
    }
  }

  // ── Campo de texto estilizado ─────────────────────────────
  Widget _buildField({
    required TextEditingController controller,
    required String label,
    required IconData icon,
    bool obscure = false,
    bool? showText,
    VoidCallback? onToggle,
    TextInputType keyboardType = TextInputType.text,
    List<TextInputFormatter>? inputFormatters,
    String? Function(String?)? validator,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: ConstantColors.borderColor.withOpacity(0.9),
        ),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      child: TextFormField(
        controller: controller,
        obscureText: obscure && !(showText ?? false),
        keyboardType: keyboardType,
        inputFormatters: inputFormatters,
        style: const TextStyle(color: Colors.white, fontSize: 15),
        decoration: InputDecoration(
          prefixIcon: Icon(icon, color: ConstantColors.primaryBlue, size: 20),
          labelText: label,
          labelStyle: const TextStyle(
            color: ConstantColors.textGrey,
            fontSize: 14,
          ),
          border: InputBorder.none,
          suffixIcon: (obscure && onToggle != null)
              ? IconButton(
                  icon: Icon(
                    (showText ?? false)
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                    color: ConstantColors.textGrey,
                    size: 20,
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
          // Fondo degradado superior
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: Container(
              height: size.height * 0.28,
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    Color(0xFF0F0C29),
                    Color(0xFF302B63),
                    Color(0xFF24243E),
                  ],
                ),
              ),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                // ── AppBar personalizado ──────────────────────────
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 4, 16, 0),
                  child: Row(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.arrow_back, color: Colors.white),
                        onPressed: () => Navigator.pop(context),
                      ),
                      const Expanded(
                        child: Text(
                          'Registro de Conductor',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),

                // ── Indicador de progreso ─────────────────────────
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 24,
                    vertical: 12,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text(
                            'Paso 1 de 2 — Datos personales',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            '50%',
                            style: TextStyle(
                              color: ConstantColors.primaryBlue,
                              fontSize: 13,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(6),
                        child: LinearProgressIndicator(
                          value: 0.5,
                          minHeight: 6,
                          backgroundColor:
                              Colors.white.withOpacity(0.12),
                          valueColor: AlwaysStoppedAnimation<Color>(
                            ConstantColors.primaryBlue,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),

                // ── Formulario ────────────────────────────────────
                Expanded(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(24, 4, 24, 32),
                    child: Form(
                      key: _formKey,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Container(
                            padding: const EdgeInsets.all(20),
                            decoration: BoxDecoration(
                              color: Colors.black.withOpacity(0.22),
                              borderRadius: BorderRadius.circular(24),
                              border: Border.all(
                                color: ConstantColors.borderColor
                                    .withOpacity(0.7),
                              ),
                            ),
                            child: Column(
                              crossAxisAlignment:
                                  CrossAxisAlignment.start,
                              children: [
                                // ── Selección de Perfil ────────────────
                                const Text(
                                  '¿Cómo deseas registrarte?',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 15,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 12),
                                Row(
                                  children: [
                                    Expanded(
                                      child: _buildProfileCard(
                                        title: 'Independiente',
                                        icon: Icons.person_pin_outlined,
                                        selected: _tipoConductor == 'independiente',
                                        onTap: () => setState(() => _tipoConductor = 'independiente'),
                                      ),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: _buildProfileCard(
                                        title: 'Cooperativa',
                                        icon: Icons.group_outlined,
                                        selected: _tipoConductor == 'cooperativa',
                                        onTap: () => setState(() => _tipoConductor = 'cooperativa'),
                                      ),
                                    ),
                                  ],
                                ),
                                if (_tipoConductor == 'cooperativa') ...[
                                  const SizedBox(height: 16),
                                  _buildCooperativaDropdown(),
                                ],
                                const SizedBox(height: 24),
                                const Divider(color: Colors.white24),
                                const SizedBox(height: 16),

                                Row(
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.all(10),
                                      decoration: BoxDecoration(
                                        gradient:
                                            ConstantColors.buttonGradient,
                                        borderRadius:
                                            BorderRadius.circular(12),
                                      ),
                                      child: const Icon(
                                        Icons.person_outline,
                                        color: Colors.white,
                                        size: 20,
                                      ),
                                    ),
                                    const SizedBox(width: 12),
                                    const Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          'Datos personales',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 17,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                        Text(
                                          'Ingresa tu información básica',
                                          style: TextStyle(
                                            color:
                                                ConstantColors.textGrey,
                                            fontSize: 12,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 20),

                                // Nombre completo
                                _buildField(
                                  controller: _nombreController,
                                  label: 'Nombre completo',
                                  icon: Icons.badge_outlined,
                                  validator: (v) {
                                    if ((v ?? '').trim().isEmpty) {
                                      return 'Ingresa tu nombre completo';
                                    }
                                    if (v!.trim().split(' ').length < 2) {
                                      return 'Ingresa nombre y apellido';
                                    }
                                    return null;
                                  },
                                ),

                                // Teléfono
                                _buildField(
                                  controller: _telefonoController,
                                  label: 'Teléfono (10 dígitos)',
                                  icon: Icons.phone_outlined,
                                  keyboardType: TextInputType.phone,
                                  inputFormatters: [
                                    FilteringTextInputFormatter.digitsOnly,
                                    LengthLimitingTextInputFormatter(10),
                                  ],
                                  validator: (v) {
                                    final val = (v ?? '').trim();
                                    if (val.isEmpty) {
                                      return 'Ingresa tu teléfono';
                                    }
                                    if (val.length != 10) {
                                      return 'El teléfono debe tener 10 dígitos';
                                    }
                                    return null;
                                  },
                                ),

                                // Cédula
                                _buildField(
                                  controller: _cedulaController,
                                  label: 'Cédula (10 dígitos)',
                                  icon: Icons.credit_card_outlined,
                                  keyboardType: TextInputType.number,
                                  inputFormatters: [
                                    FilteringTextInputFormatter.digitsOnly,
                                    LengthLimitingTextInputFormatter(10),
                                  ],
                                  validator: (v) {
                                    final val = (v ?? '').trim();
                                    if (val.isEmpty) {
                                      return 'Ingresa tu cédula';
                                    }
                                    if (val.length != 10) {
                                      return 'La cédula debe tener 10 dígitos';
                                    }
                                    return null;
                                  },
                                ),

                                // Contraseña
                                _buildField(
                                  controller: _passwordController,
                                  label: 'Contraseña',
                                  icon: Icons.lock_outline,
                                  obscure: true,
                                  showText: _showPassword,
                                  onToggle: () => setState(
                                    () => _showPassword = !_showPassword,
                                  ),
                                  validator: (v) {
                                    if ((v ?? '').trim().isEmpty) {
                                      return 'Ingresa una contraseña';
                                    }
                                    if (v!.trim().length < 6) {
                                      return 'Mínimo 6 caracteres';
                                    }
                                    return null;
                                  },
                                ),

                                // Confirmar contraseña
                                _buildField(
                                  controller: _confirmController,
                                  label: 'Confirmar contraseña',
                                  icon: Icons.lock_reset_outlined,
                                  obscure: true,
                                  showText: _showConfirm,
                                  onToggle: () => setState(
                                    () => _showConfirm = !_showConfirm,
                                  ),
                                  validator: (v) {
                                    if ((v ?? '').trim().isEmpty) {
                                      return 'Confirma tu contraseña';
                                    }
                                    if (v!.trim() !=
                                        _passwordController.text.trim()) {
                                      return 'Las contraseñas no coinciden';
                                    }
                                    return null;
                                  },
                                ),
                              ],
                            ),
                          ),

                          const SizedBox(height: 24),

                          // ── Botón siguiente ───────────────────────
                          SizedBox(
                            height: 54,
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                gradient: ConstantColors.buttonGradient,
                                borderRadius: BorderRadius.circular(16),
                                boxShadow: [
                                  BoxShadow(
                                    color: ConstantColors.primaryViolet
                                        .withOpacity(0.28),
                                    blurRadius: 20,
                                    offset: const Offset(0, 8),
                                  ),
                                ],
                              ),
                              child: ElevatedButton(
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: Colors.transparent,
                                  shadowColor: Colors.transparent,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(16),
                                  ),
                                ),
                                onPressed: _nextStep,
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: const [
                                    Text(
                                      'Siguiente: Datos del Vehículo',
                                      style: TextStyle(
                                        color: Colors.white,
                                        fontSize: 15,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                    SizedBox(width: 8),
                                    Icon(
                                      Icons.arrow_forward_rounded,
                                      color: Colors.white,
                                      size: 18,
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ),
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

  Widget _buildProfileCard({
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
            Icon(
              icon,
              color: selected
                  ? ConstantColors.primaryBlue
                  : ConstantColors.textGrey,
              size: 28,
            ),
            const SizedBox(height: 8),
            Text(
              title,
              style: TextStyle(
                color: selected ? Colors.white : ConstantColors.textGrey,
                fontSize: 13,
                fontWeight: selected ? FontWeight.w700 : FontWeight.normal,
              ),
            ),
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
          value: _cooperativaId,
          hint: Text(
            _loadingCooperativas ? 'Cargando...' : 'Selecciona tu cooperativa',
            style: const TextStyle(color: ConstantColors.textGrey, fontSize: 14),
          ),
          dropdownColor: ConstantColors.backgroundDark,
          icon: const Icon(Icons.arrow_drop_down,
              color: ConstantColors.primaryBlue),
          isExpanded: true,
          style: const TextStyle(color: Colors.white, fontSize: 15),
          items: _cooperativas.map((c) {
            return DropdownMenuItem<int>(
              value: int.tryParse(c['id'].toString()),
              child: Text(c['nombre']?.toString() ?? ''),
            );
          }).toList(),
          onChanged: (val) => setState(() => _cooperativaId = val),
        ),
      ),
    );
  }
}
