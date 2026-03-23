import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/DriverRegistrationData.dart';
import 'package:fu_uber/UI/views/DriverStep4Screen.dart';

class DriverStep3Screen extends StatefulWidget {
  final DriverRegistrationData data;
  const DriverStep3Screen({Key? key, required this.data}) : super(key: key);

  @override
  _DriverStep3ScreenState createState() => _DriverStep3ScreenState();
}

class _DriverStep3ScreenState extends State<DriverStep3Screen> {
  final _picker = ImagePicker();

  Future<File?> _pickImage(ImageSource source) async {
    final xFile = await _picker.pickImage(
      source: source,
      imageQuality: 70,
      maxWidth: 1200,
      maxHeight: 1200,
    );
    if (xFile == null) return null;
    return File(xFile.path);
  }

  void _showPickerDialog(String titulo, void Function(File) onPicked) {
    showModalBottomSheet(
      context: context,
      backgroundColor: ConstantColors.backgroundCard,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(20),
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
              Align(
                alignment: Alignment.centerLeft,
                child: Text(titulo,
                  style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700)),
              ),
              const SizedBox(height: 16),
              ListTile(
                leading: Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    gradient: ConstantColors.buttonGradient,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.camera_alt_outlined, color: Colors.white),
                ),
                title: const Text('Tomar foto', style: TextStyle(color: Colors.white)),
                onTap: () async {
                  Navigator.pop(context);
                  final f = await _pickImage(ImageSource.camera);
                  if (f != null) onPicked(f);
                },
              ),
              ListTile(
                leading: Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: ConstantColors.borderColor,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.photo_library_outlined, color: Colors.white),
                ),
                title: const Text('Elegir de galería', style: TextStyle(color: Colors.white)),
                onTap: () async {
                  Navigator.pop(context);
                  final f = await _pickImage(ImageSource.gallery);
                  if (f != null) onPicked(f);
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _next() {
    if (!widget.data.documentosCompletos) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Debes subir todos los documentos requeridos')),
      );
      return;
    }
    Navigator.push(context, MaterialPageRoute(
      builder: (_) => DriverStep4Screen(data: widget.data),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    final d = widget.data;

    final docs = [
      _DocItem(
        titulo: 'Licencia de conducir',
        subtitulo: 'Parte frontal',
        tipo: 'licencia_frente',
        file: d.licenciaFrente,
        icon: Icons.credit_card_rounded,
        onTap: () => _showPickerDialog('Licencia (frente)', (f) => setState(() => d.licenciaFrente = f)),
      ),
      _DocItem(
        titulo: 'Licencia de conducir',
        subtitulo: 'Parte posterior',
        tipo: 'licencia_reverso',
        file: d.licenciaReverso,
        icon: Icons.credit_card_rounded,
        onTap: () => _showPickerDialog('Licencia (reverso)', (f) => setState(() => d.licenciaReverso = f)),
      ),
      _DocItem(
        titulo: 'Cédula de identidad',
        subtitulo: 'Foto clara del documento',
        tipo: 'cedula',
        file: d.fotoCedula,
        icon: Icons.badge_outlined,
        onTap: () => _showPickerDialog('Cédula de identidad', (f) => setState(() => d.fotoCedula = f)),
      ),
      _DocItem(
        titulo: 'SOAT / Seguro',
        subtitulo: 'Seguro obligatorio del vehículo',
        tipo: 'soat',
        file: d.fotoSoat,
        icon: Icons.shield_outlined,
        onTap: () => _showPickerDialog('SOAT / Seguro', (f) => setState(() => d.fotoSoat = f)),
      ),
      _DocItem(
        titulo: 'Matrícula',
        subtitulo: 'Documento de propiedad del vehículo',
        tipo: 'matricula',
        file: d.fotoMatricula,
        icon: Icons.article_outlined,
        onTap: () => _showPickerDialog('Matrícula del vehículo', (f) => setState(() => d.fotoMatricula = f)),
      ),
      if (d.tipoConductor == 'cooperativa')
        _DocItem(
          titulo: 'Vinculación a Cooperativa',
          subtitulo: 'Certificado o carnet de la cooperativa',
          tipo: 'vinculacion_cooperativa',
          file: d.vinculacionCoop,
          icon: Icons.assignment_ind_outlined,
          onTap: () => _showPickerDialog('Documento de Cooperativa', (f) => setState(() => d.vinculacionCoop = f)),
        ),
    ];

    final totalDocs = docs.length;
    final completados = docs.where((doc) => doc.file != null).length;

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
                // AppBar
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
                _buildProgress(paso: 3, total: 6, label: 'Documentos'),

                // Contador
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 24),
                  child: Row(
                    children: [
                      Icon(
                        completados == totalDocs ? Icons.check_circle_rounded : Icons.info_outline,
                        color: completados == totalDocs ? Colors.greenAccent : ConstantColors.primaryBlue,
                        size: 18,
                      ),
                      const SizedBox(width: 8),
                      Text(
                        completados == totalDocs
                            ? '¡Todos los documentos listos!'
                            : '$completados de $totalDocs documentos subidos',
                        style: TextStyle(
                          color: completados == totalDocs ? Colors.greenAccent : ConstantColors.textGrey,
                          fontSize: 13,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 8),

                // Lista de documentos
                Expanded(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(24, 4, 24, 24),
                    child: Column(
                      children: [
                        ...docs.map((doc) => _buildDocCard(doc)).toList(),
                        const SizedBox(height: 8),
                        // Consejo
                        Container(
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            color: ConstantColors.primaryBlue.withOpacity(0.08),
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: ConstantColors.primaryBlue.withOpacity(0.25)),
                          ),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Icon(Icons.lightbulb_outline, color: ConstantColors.primaryBlue, size: 18),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Text(
                                  'Toma fotos en un lugar bien iluminado. Los documentos deben ser legibles y estar vigentes.',
                                  style: TextStyle(color: ConstantColors.textGrey, fontSize: 12.5, height: 1.4),
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 20),
                        _btnNext(
                          onPressed: _next,
                          label: 'Siguiente: Datos del Vehículo',
                          enabled: widget.data.documentosCompletos,
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

  Widget _buildDocCard(_DocItem doc) {
    final done = doc.file != null;
    return GestureDetector(
      onTap: doc.onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: done
              ? Colors.greenAccent.withOpacity(0.07)
              : Colors.black.withOpacity(0.22),
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: done
                ? Colors.greenAccent.withOpacity(0.35)
                : ConstantColors.borderColor.withOpacity(0.7),
          ),
        ),
        child: Row(
          children: [
            // Preview o ícono
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: done
                  ? Image.file(doc.file!, width: 56, height: 56, fit: BoxFit.cover)
                  : Container(
                      width: 56, height: 56,
                      color: ConstantColors.backgroundCard,
                      child: Icon(doc.icon, color: ConstantColors.textGrey, size: 26),
                    ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(doc.titulo,
                    style: TextStyle(
                      color: done ? Colors.white : Colors.white.withOpacity(0.85),
                      fontSize: 14, fontWeight: FontWeight.w700,
                    )),
                  const SizedBox(height: 3),
                  Text(doc.subtitulo,
                    style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
                  const SizedBox(height: 5),
                  Text(
                    done ? 'Foto subida — toca para cambiar' : 'Toca para subir foto',
                    style: TextStyle(
                      color: done ? Colors.greenAccent : ConstantColors.primaryBlue,
                      fontSize: 11.5, fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
            Icon(
              done ? Icons.check_circle_rounded : Icons.upload_file_outlined,
              color: done ? Colors.greenAccent : ConstantColors.textGrey,
              size: 22,
            ),
          ],
        ),
      ),
    );
  }
}

class _DocItem {
  final String titulo;
  final String subtitulo;
  final String tipo;
  final File? file;
  final IconData icon;
  final VoidCallback onTap;

  const _DocItem({
    required this.titulo, required this.subtitulo, required this.tipo,
    required this.file, required this.icon, required this.onTap,
  });
}

Widget _buildProgress({required int paso, required int total, required String label}) {
  return Padding(
    padding: const EdgeInsets.fromLTRB(24, 12, 24, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
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
      ],
    ),
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
