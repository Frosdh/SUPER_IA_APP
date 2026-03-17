import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Networking/ApiProvider.dart';
import 'package:fu_uber/UI/views/DriverWaitingScreen.dart';
import 'package:image_picker/image_picker.dart';

class VehicleRegistrationScreen extends StatefulWidget {
  final String nombre;
  final String telefono;
  final String cedula;
  final String password;
  final String tipoConductor;
  final int? cooperativaId;

  const VehicleRegistrationScreen({
    Key? key,
    required this.nombre,
    required this.telefono,
    required this.cedula,
    required this.password,
    this.tipoConductor = 'independiente',
    this.cooperativaId,
  }) : super(key: key);

  @override
  _VehicleRegistrationScreenState createState() =>
      _VehicleRegistrationScreenState();
}

class _VehicleRegistrationScreenState
    extends State<VehicleRegistrationScreen> {
  final _formKey = GlobalKey<FormState>();
  final ApiProvider _apiProvider = ApiProvider();
  bool _isLoading = false;

  final _marcaController  = TextEditingController();
  final _modeloController = TextEditingController();
  final _placaController  = TextEditingController();
  final _colorController  = TextEditingController();
  final _anioController   = TextEditingController();

  // Categorías de vehículo
  int _categoriaId = 1;
  final List<_CategoriaItem> _categorias = const [
    _CategoriaItem(id: 1, nombre: 'Fuber-X',    icon: Icons.directions_car,    descripcion: 'Vehículo estándar'),
    _CategoriaItem(id: 2, nombre: 'Fuber-Plus',  icon: Icons.airport_shuttle,   descripcion: 'Vehículo de lujo'),
    _CategoriaItem(id: 3, nombre: 'Fuber-Moto',  icon: Icons.two_wheeler,       descripcion: 'Motocicleta'),
    _CategoriaItem(id: 4, nombre: 'Taxi',        icon: Icons.local_taxi,        descripcion: 'Servicio de Taxi'),
  ];

  // Documento de vinculación (para cooperativas)
  String? _documentoVinculacionBase64;
  bool _subiendoDoc = false;

  @override
  void dispose() {
    _marcaController.dispose();
    _modeloController.dispose();
    _placaController.dispose();
    _colorController.dispose();
    _anioController.dispose();
    super.dispose();
  }

  void _showMessage(String text) {
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(text)));
  }

  Future<void> _seleccionarDocumento() async {
    final picker = ImagePicker();
    final pickedFile = await showModalBottomSheet<XFile?>(
      context: context,
      backgroundColor: ConstantColors.backgroundCard,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.camera_alt, color: Colors.white),
              title: const Text('Tomar foto', style: TextStyle(color: Colors.white)),
              onTap: () async => Navigator.pop(ctx, await picker.pickImage(source: ImageSource.camera, imageQuality: 70)),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library, color: Colors.white),
              title: const Text('Elegir de galería', style: TextStyle(color: Colors.white)),
              onTap: () async => Navigator.pop(ctx, await picker.pickImage(source: ImageSource.gallery, imageQuality: 70)),
            ),
          ],
        ),
      ),
    );

    if (pickedFile != null) {
      final bytes = await File(pickedFile.path).readAsBytes();
      setState(() {
        _documentoVinculacionBase64 = base64Encode(bytes);
      });
    }
  }

  Future<void> _register() async {
    if (!_formKey.currentState!.validate()) return;

    if (widget.tipoConductor == 'cooperativa' && _documentoVinculacionBase64 == null) {
      _showMessage('Por favor, sube el documento de vinculación con la cooperativa');
      return;
    }

    setState(() => _isLoading = true);

    final response = await _apiProvider.registerDriver(
      nombre:      widget.nombre,
      telefono:    widget.telefono,
      cedula:      widget.cedula,
      password:    widget.password,
      marca:       _marcaController.text.trim(),
      modelo:      _modeloController.text.trim(),
      placa:       _placaController.text.trim().toUpperCase(),
      color:       _colorController.text.trim(),
      anio:        int.tryParse(_anioController.text.trim()) ?? 2020,
      categoriaId: _categoriaId,
      tipoConductor: widget.tipoConductor,
      cooperativaId: widget.cooperativaId,
    );

    if (response['status'] == 'success') {
      final int conductorId = int.tryParse(response['conductor_id']?.toString() ?? '0') ?? 0;
      
      // Subir documento si es cooperativa
      if (widget.tipoConductor == 'cooperativa' && _documentoVinculacionBase64 != null && conductorId > 0) {
        setState(() => _subiendoDoc = true);
        await _apiProvider.uploadDocumentoConductor(
          conductorId: conductorId,
          tipo: 'vinculacion_cooperativa',
          imagenBase64: _documentoVinculacionBase64!,
        );
        setState(() => _subiendoDoc = false);
      }

      setState(() => _isLoading = false);
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (context) => DriverWaitingScreen(conductorId: conductorId)),
        (route) => false,
      );
    } else {
      setState(() => _isLoading = false);
      _showMessage(
        response['message']?.toString() ?? 'Error al registrar conductor',
      );
    }
  }

  // ── Campo estilizado ──────────────────────────────────────
  Widget _buildField({
    required TextEditingController controller,
    required String label,
    required IconData icon,
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
        ),
        validator: validator ??
            (v) => (v?.trim().isEmpty ?? true) ? 'Campo requerido' : null,
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
          // Fondo degradado
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
                        icon: const Icon(Icons.arrow_back,
                            color: Colors.white),
                        onPressed: () => Navigator.pop(context),
                      ),
                      const Expanded(
                        child: Text(
                          'Datos del Vehículo',
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
                            'Paso 2 de 2 — Datos del vehículo',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            '100%',
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
                          value: 1.0,
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
                          // ── Selector de categoría ─────────────────
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
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
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
                                        Icons.category_outlined,
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
                                          'Tipo de servicio',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 17,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                        Text(
                                          'Selecciona la categoría de tu vehículo',
                                          style: TextStyle(
                                            color: ConstantColors.textGrey,
                                            fontSize: 12,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 16),
                                Row(
                                  children: _categorias.map((cat) {
                                    final selected = _categoriaId == cat.id;
                                    return Expanded(
                                      child: GestureDetector(
                                        onTap: () => setState(
                                          () => _categoriaId = cat.id,
                                        ),
                                        child: AnimatedContainer(
                                          duration: const Duration(
                                              milliseconds: 200),
                                          margin: const EdgeInsets.symmetric(
                                              horizontal: 4),
                                          padding: const EdgeInsets.symmetric(
                                            vertical: 12,
                                            horizontal: 6,
                                          ),
                                          decoration: BoxDecoration(
                                            gradient: selected
                                                ? ConstantColors
                                                    .buttonGradient
                                                : null,
                                            color: selected
                                                ? null
                                                : ConstantColors
                                                    .backgroundCard,
                                            borderRadius:
                                                BorderRadius.circular(14),
                                            border: Border.all(
                                              color: selected
                                                  ? Colors.transparent
                                                  : ConstantColors.borderColor
                                                      .withOpacity(0.7),
                                            ),
                                          ),
                                          child: Column(
                                            children: [
                                              Icon(
                                                cat.icon,
                                                color: selected
                                                    ? Colors.white
                                                    : ConstantColors.textGrey,
                                                size: 22,
                                              ),
                                              const SizedBox(height: 6),
                                              Text(
                                                cat.nombre,
                                                textAlign: TextAlign.center,
                                                style: TextStyle(
                                                  color: selected
                                                      ? Colors.white
                                                      : ConstantColors
                                                          .textGrey,
                                                  fontSize: 11,
                                                  fontWeight: selected
                                                      ? FontWeight.w700
                                                      : FontWeight.normal,
                                                ),
                                              ),
                                              const SizedBox(height: 2),
                                              Text(
                                                cat.descripcion,
                                                textAlign: TextAlign.center,
                                                style: TextStyle(
                                                  color: selected
                                                      ? Colors.white
                                                          .withOpacity(0.8)
                                                      : ConstantColors
                                                          .textGrey
                                                          .withOpacity(0.6),
                                                  fontSize: 9,
                                                ),
                                              ),
                                            ],
                                          ),
                                        ),
                                      ),
                                    );
                                  }).toList(),
                                ),
                              ],
                            ),
                          ),

                          const SizedBox(height: 16),

                          // ── Datos del vehículo ────────────────────
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
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
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
                                        Icons.directions_car_outlined,
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
                                          'Información del vehículo',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 17,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                        Text(
                                          'Datos del auto o moto que conducirás',
                                          style: TextStyle(
                                            color: ConstantColors.textGrey,
                                            fontSize: 12,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 20),

                                _buildField(
                                  controller: _marcaController,
                                  label: 'Marca (Ej: Toyota)',
                                  icon: Icons.directions_car_outlined,
                                ),
                                _buildField(
                                  controller: _modeloController,
                                  label: 'Modelo (Ej: Corolla)',
                                  icon: Icons.drive_eta_outlined,
                                ),
                                _buildField(
                                  controller: _placaController,
                                  label: 'Placa (Ej: ABC-1234)',
                                  icon: Icons.confirmation_number_outlined,
                                  inputFormatters: [
                                    UpperCaseTextFormatter(),
                                    LengthLimitingTextInputFormatter(8),
                                  ],
                                  validator: (v) {
                                    if ((v ?? '').trim().isEmpty) {
                                      return 'Ingresa la placa';
                                    }
                                    return null;
                                  },
                                ),
                                _buildField(
                                  controller: _colorController,
                                  label: 'Color (Ej: Blanco)',
                                  icon: Icons.color_lens_outlined,
                                ),
                                _buildField(
                                  controller: _anioController,
                                  label: 'Año (Ej: 2020)',
                                  icon: Icons.calendar_today_outlined,
                                  keyboardType: TextInputType.number,
                                  inputFormatters: [
                                    FilteringTextInputFormatter.digitsOnly,
                                    LengthLimitingTextInputFormatter(4),
                                  ],
                                  validator: (v) {
                                    final val = int.tryParse(v ?? '');
                                    if (val == null) {
                                      return 'Ingresa el año';
                                    }
                                    final now =
                                        DateTime.now().year;
                                    if (val < 1990 || val > now + 1) {
                                      return 'Año inválido';
                                    }
                                    return null;
                                  },
                                ),
                              ],
                            ),
                          ),

                          _buildDocumentSection(),

                          const SizedBox(height: 24),

                          // ── Botón registrar ───────────────────────
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
                                onPressed: _isLoading ? null : _register,
                                child: _isLoading
                                    ? const SizedBox(
                                        width: 22,
                                        height: 22,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2.5,
                                          color: Colors.white,
                                        ),
                                      )
                                    : Row(
                                        mainAxisAlignment:
                                            MainAxisAlignment.center,
                                        children: const [
                                          Icon(
                                            Icons.check_circle_outline,
                                            color: Colors.white,
                                            size: 18,
                                          ),
                                          SizedBox(width: 8),
                                          Text(
                                            'Finalizar Registro',
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

  Widget _buildDocumentSection() {
    if (widget.tipoConductor != 'cooperativa') return const SizedBox.shrink();

    return Container(
      margin: const EdgeInsets.only(top: 16),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: Colors.black.withOpacity(0.22),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(
          color: ConstantColors.borderColor.withOpacity(0.7),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  gradient: ConstantColors.buttonGradient,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(
                  Icons.file_present_outlined,
                  color: Colors.white,
                  size: 20,
                ),
              ),
              const SizedBox(width: 12),
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Documento de Cooperativa',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    Text(
                      'Sube tu carta de vinculación o contrato',
                      style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 20),
          GestureDetector(
            onTap: _subiendoDoc ? null : _seleccionarDocumento,
            child: Container(
              height: 120,
              width: double.infinity,
              decoration: BoxDecoration(
                color: ConstantColors.backgroundCard,
                borderRadius: BorderRadius.circular(18),
                border: Border.all(
                  color: _documentoVinculacionBase64 != null
                      ? ConstantColors.primaryBlue
                      : ConstantColors.borderColor.withOpacity(0.5),
                  style: BorderStyle.solid,
                ),
              ),
              child: _documentoVinculacionBase64 != null
                  ? ClipRRect(
                      borderRadius: BorderRadius.circular(18),
                      child: Image.memory(
                        base64Decode(_documentoVinculacionBase64!),
                        fit: BoxFit.cover,
                      ),
                    )
                  : Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.cloud_upload_outlined,
                            color: ConstantColors.primaryBlue, size: 30),
                        const SizedBox(height: 8),
                        const Text(
                          'Toca para subir documento',
                          style: TextStyle(
                              color: ConstantColors.textGrey, fontSize: 13),
                        ),
                      ],
                    ),
            ),
          ),
          if (_documentoVinculacionBase64 != null)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.check_circle,
                      color: Colors.greenAccent, size: 16),
                  const SizedBox(width: 6),
                  const Text('Documento cargado',
                      style:
                          TextStyle(color: Colors.greenAccent, fontSize: 12)),
                  const SizedBox(width: 10),
                  TextButton(
                    onPressed: _seleccionarDocumento,
                    child: const Text('Cambiar',
                        style: TextStyle(
                            color: ConstantColors.primaryBlue, fontSize: 12)),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

// ── Modelo de categoría ───────────────────────────────────────
class _CategoriaItem {
  final int id;
  final String nombre;
  final IconData icon;
  final String descripcion;

  const _CategoriaItem({
    required this.id,
    required this.nombre,
    required this.icon,
    required this.descripcion,
  });
}

// ── Formatter para mayúsculas automáticas (placa) ─────────────
class UpperCaseTextFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    return newValue.copyWith(text: newValue.text.toUpperCase());
  }
}
