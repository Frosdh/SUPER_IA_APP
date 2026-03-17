import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/DriverRegistrationData.dart';
import 'package:fu_uber/UI/views/DriverStep6Screen.dart';

class DriverStep5Screen extends StatefulWidget {
  final DriverRegistrationData data;
  const DriverStep5Screen({Key? key, required this.data}) : super(key: key);

  @override
  _DriverStep5ScreenState createState() => _DriverStep5ScreenState();
}

class _DriverStep5ScreenState extends State<DriverStep5Screen> {
  final _picker = ImagePicker();

  Future<void> _pickImage(ImageSource source) async {
    final xFile = await _picker.pickImage(
      source: source, imageQuality: 80, maxWidth: 600, maxHeight: 600,
    );
    if (xFile != null) {
      setState(() => widget.data.fotoPerfil = File(xFile.path));
    }
  }

  void _next() {
    if (widget.data.fotoPerfil == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Por favor sube una foto de perfil')),
      );
      return;
    }
    Navigator.push(context, MaterialPageRoute(
      builder: (_) => DriverStep6Screen(data: widget.data),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    final foto = widget.data.fotoPerfil;

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
                    IconButton(icon: const Icon(Icons.arrow_back, color: Colors.white),
                      onPressed: () => Navigator.pop(context)),
                    const Text('Registro de Conductor',
                      style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700)),
                  ]),
                ),
                _buildProgress(paso: 5, total: 6, label: 'Foto de perfil'),
                Expanded(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(24, 8, 24, 32),
                    child: Column(
                      children: [
                        const SizedBox(height: 20),

                        // Título
                        const Text('Tu foto de perfil',
                          style: TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 10),
                        Text(
                          'Los pasajeros verán esta foto cuando soliciten un viaje. Usa una foto reciente y clara de tu cara.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white.withOpacity(0.6), fontSize: 14, height: 1.5),
                        ),

                        const SizedBox(height: 36),

                        // Avatar
                        GestureDetector(
                          onTap: () => _showPickerDialog(),
                          child: Stack(
                            alignment: Alignment.bottomRight,
                            children: [
                              Container(
                                width: 140, height: 140,
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  gradient: foto == null ? ConstantColors.buttonGradient : null,
                                  image: foto != null
                                      ? DecorationImage(image: FileImage(foto), fit: BoxFit.cover)
                                      : null,
                                  border: Border.all(
                                    color: foto != null ? Colors.greenAccent.withOpacity(0.5) : Colors.white.withOpacity(0.12),
                                    width: 3,
                                  ),
                                  boxShadow: [
                                    BoxShadow(
                                      color: ConstantColors.primaryViolet.withOpacity(0.3),
                                      blurRadius: 24, offset: const Offset(0, 10),
                                    ),
                                  ],
                                ),
                                child: foto == null
                                    ? const Icon(Icons.person_rounded, color: Colors.white, size: 60)
                                    : null,
                              ),
                              Container(
                                padding: const EdgeInsets.all(10),
                                decoration: BoxDecoration(
                                  gradient: ConstantColors.buttonGradient,
                                  shape: BoxShape.circle,
                                  border: Border.all(color: ConstantColors.backgroundDark, width: 2),
                                ),
                                child: const Icon(Icons.camera_alt_rounded, color: Colors.white, size: 16),
                              ),
                            ],
                          ),
                        ),

                        const SizedBox(height: 16),
                        Text(
                          foto != null ? '¡Foto lista! Toca para cambiarla' : 'Toca para subir tu foto',
                          style: TextStyle(
                            color: foto != null ? Colors.greenAccent : ConstantColors.textGrey,
                            fontSize: 13, fontWeight: FontWeight.w600,
                          ),
                        ),

                        const SizedBox(height: 36),

                        // Botones de acción
                        Row(children: [
                          Expanded(
                            child: _actionBtn(
                              icon: Icons.camera_alt_outlined,
                              label: 'Tomar selfie',
                              onTap: () => _pickImage(ImageSource.camera),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: _actionBtn(
                              icon: Icons.photo_library_outlined,
                              label: 'De galería',
                              onTap: () => _pickImage(ImageSource.gallery),
                            ),
                          ),
                        ]),

                        const SizedBox(height: 32),
                        _btnNext(
                          onPressed: _next,
                          label: 'Siguiente: Antecedentes',
                          enabled: foto != null,
                        ),
                      ],
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

  void _showPickerDialog() {
    showModalBottomSheet(
      context: context,
      backgroundColor: ConstantColors.backgroundCard,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Text('Selecciona una foto',
              style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700)),
            const SizedBox(height: 16),
            ListTile(
              leading: _iconBtn(Icons.camera_alt_outlined),
              title: const Text('Tomar selfie', style: TextStyle(color: Colors.white)),
              onTap: () { Navigator.pop(context); _pickImage(ImageSource.camera); },
            ),
            ListTile(
              leading: _iconBtn(Icons.photo_library_outlined),
              title: const Text('Elegir de galería', style: TextStyle(color: Colors.white)),
              onTap: () { Navigator.pop(context); _pickImage(ImageSource.gallery); },
            ),
          ]),
        ),
      ),
    );
  }

  Widget _iconBtn(IconData icon) => Container(
    padding: const EdgeInsets.all(8),
    decoration: BoxDecoration(gradient: ConstantColors.buttonGradient, borderRadius: BorderRadius.circular(10)),
    child: Icon(icon, color: Colors.white),
  );

  Widget _actionBtn({required IconData icon, required String label, required VoidCallback onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 16),
        decoration: BoxDecoration(
          color: Colors.black.withOpacity(0.22),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: ConstantColors.borderColor.withOpacity(0.7)),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: ConstantColors.primaryBlue, size: 26),
          const SizedBox(height: 6),
          Text(label, style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
        ]),
      ),
    );
  }
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

Widget _btnNext({required VoidCallback onPressed, required String label, bool loading = false, bool enabled = true}) {
  return SizedBox(
    width: double.infinity, height: 54,
    child: DecoratedBox(
      decoration: BoxDecoration(
        gradient: enabled ? ConstantColors.buttonGradient : null,
        color: enabled ? null : Colors.white.withOpacity(0.08),
        borderRadius: BorderRadius.circular(16),
        boxShadow: enabled ? [BoxShadow(color: ConstantColors.primaryViolet.withOpacity(0.28), blurRadius: 20, offset: const Offset(0, 8))] : [],
      ),
      child: ElevatedButton(
        style: ElevatedButton.styleFrom(
          backgroundColor: Colors.transparent, shadowColor: Colors.transparent,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        onPressed: (loading || !enabled) ? null : onPressed,
        child: loading
            ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
            : Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                Text(label, style: TextStyle(color: enabled ? Colors.white : ConstantColors.textGrey, fontSize: 15, fontWeight: FontWeight.w700)),
                const SizedBox(width: 8),
                Icon(Icons.arrow_forward_rounded, color: enabled ? Colors.white : ConstantColors.textGrey, size: 18),
              ]),
      ),
    ),
  );
}
