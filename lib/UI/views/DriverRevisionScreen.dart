import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Networking/ApiProvider.dart';
import 'package:fu_uber/UI/views/DriverLoginScreen.dart';
import 'package:image_picker/image_picker.dart';

class DriverRevisionScreen extends StatefulWidget {
  static const String route = '/driver_revision';
  final int conductorId;

  const DriverRevisionScreen({Key? key, required this.conductorId})
      : super(key: key);

  @override
  _DriverRevisionScreenState createState() => _DriverRevisionScreenState();
}

class _DriverRevisionScreenState extends State<DriverRevisionScreen> {
  final ApiProvider _apiProvider = ApiProvider();
  final ImagePicker _picker = ImagePicker();
  
  bool _isLoading = true;
  String? _errorMsg;
  Map<String, dynamic>? _documentosData;

  @override
  void initState() {
    super.initState();
    _cargarDocumentos();
  }

  Future<void> _cargarDocumentos() async {
    setState(() {
      _isLoading = true;
      _errorMsg = null;
    });

    final resp = await _apiProvider.obtenerDocumentosConductor(widget.conductorId);

    if (resp['status'] == 'success') {
      setState(() {
        _documentosData = resp;
        _isLoading = false;
      });
    } else {
      setState(() {
        _errorMsg = resp['message'] ?? 'Error al cargar los documentos';
        _isLoading = false;
      });
    }
  }

  Future<void> _volverASubir(String tipoDoc, String nombreDoc) async {
    final XFile? imagen = await _picker.pickImage(source: ImageSource.gallery, imageQuality: 70);
    if (imagen == null) return;

    setState(() => _isLoading = true);

    try {
      final File file = File(imagen.path);
      final List<int> bytes = await file.readAsBytes();
      final String base64Image = base64Encode(bytes);

      final resp = await _apiProvider.uploadDocumentoConductor(
        conductorId: widget.conductorId,
        tipo: tipoDoc,
        imagenBase64: base64Image,
      );

      if (resp['status'] == 'success') {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$nombreDoc subido correctamente.'), backgroundColor: Colors.green),
        );
        await _cargarDocumentos(); // Recargar la lista
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(resp['message'] ?? 'Error al subir el documento'), backgroundColor: Colors.red),
        );
        setState(() => _isLoading = false);
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error al procesar la imagen: $e'), backgroundColor: Colors.red),
      );
      setState(() => _isLoading = false);
    }
  }

  Widget _buildDocItem(String tipo, String titulo) {
    if (_documentosData == null) return const SizedBox();

    final docsList = _documentosData!['documentos'] as List<dynamic>? ?? [];
    
    // Buscar en la lista de documentos el tipo correspondiente
    Map<String, dynamic>? docInfo;
    for (var doc in docsList) {
      if (doc['tipo'] == tipo) {
        docInfo = doc as Map<String, dynamic>;
        break;
      }
    }

    final estado = docInfo?['estado'] ?? 'pendiente';
    final notas = docInfo?['notas'] ?? '';

    Color estadoColor;
    IconData estadoIcon;
    String estadoTexto;

    if (estado == 'aprobado') {
      estadoColor = Colors.green;
      estadoIcon = Icons.check_circle;
      estadoTexto = 'Aprobado';
    } else if (estado == 'rechazado') {
      estadoColor = Colors.redAccent;
      estadoIcon = Icons.cancel;
      estadoTexto = 'Rechazado';
    } else {
      estadoColor = Colors.orange;
      estadoIcon = Icons.access_time_filled;
      estadoTexto = 'Pendiente';
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.05),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: estadoColor.withOpacity(0.5)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(estadoIcon, color: estadoColor, size: 24),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  titulo,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: estadoColor.withOpacity(0.2),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  estadoTexto,
                  style: TextStyle(
                    color: estadoColor,
                    fontSize: 12,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
          if (estado == 'rechazado' && notas.isNotEmpty) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.red.withOpacity(0.1),
                borderRadius: BorderRadius.circular(8),
                border: Border(
                  left: BorderSide(
                    color: Colors.redAccent,
                    width: 4,
                  ),
                ),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.info_outline, color: Colors.redAccent, size: 20),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Motivo: $notas',
                      style: const TextStyle(color: Colors.white, fontSize: 13),
                    ),
                  ),
                ],
              ),
            ),
          ],
          if (estado == 'rechazado') ...[
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: () => _volverASubir(tipo, titulo),
                icon: const Icon(Icons.upload_file, color: Colors.white),
                label: const Text(
                  'Volver a subir documento',
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                ),
                style: ElevatedButton.styleFrom(
                  backgroundColor: ConstantColors.primaryViolet,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
              ),
            ),
          ]
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: SafeArea(
        child: Column(
          children: [
            // Cabecera
            Padding(
              padding: const EdgeInsets.all(16.0),
              child: Row(
                children: [
                   IconButton(
                    icon: const Icon(Icons.arrow_back, color: Colors.white),
                    onPressed: () => Navigator.pushReplacementNamed(context, DriverLoginScreen.route),
                  ),
                  const Text(
                    'Revisión de Cuenta',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ],
              ),
            ),
            
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _errorMsg != null
                      ? Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              const Icon(Icons.error_outline, color: Colors.redAccent, size: 48),
                              const SizedBox(height: 16),
                              Text(
                                _errorMsg!,
                                style: const TextStyle(color: Colors.white, fontSize: 16),
                                textAlign: TextAlign.center,
                              ),
                              const SizedBox(height: 16),
                              ElevatedButton(
                                onPressed: _cargarDocumentos,
                                child: const Text('Reintentar'),
                              ),
                            ],
                          ),
                        )
                      : RefreshIndicator(
                          onRefresh: _cargarDocumentos,
                          child: ListView(
                            padding: const EdgeInsets.all(16.0),
                            children: [
                              Container(
                                padding: const EdgeInsets.all(20),
                                decoration: BoxDecoration(
                                  color: Colors.white.withOpacity(0.05),
                                  borderRadius: BorderRadius.circular(20),
                                ),
                                child: Column(
                                  children: [
                                    const Icon(
                                      Icons.pending_actions,
                                      color: ConstantColors.primaryBlue,
                                      size: 48,
                                    ),
                                    const SizedBox(height: 16),
                                    const Text(
                                      'Tu cuenta está en revisión',
                                      style: TextStyle(
                                        color: Colors.white,
                                        fontSize: 22,
                                        fontWeight: FontWeight.bold,
                                      ),
                                      textAlign: TextAlign.center,
                                    ),
                                    const SizedBox(height: 8),
                                    Text(
                                      'Por favor verifica el estado de tus documentos. Si alguno fue rechazado, puedes volver a subirlo aquí mismo.',
                                      style: TextStyle(
                                        color: Colors.white.withOpacity(0.7),
                                        fontSize: 14,
                                        height: 1.5,
                                      ),
                                      textAlign: TextAlign.center,
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: 24),
                              const Text(
                                'Estado de Documentos',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 18,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                              const SizedBox(height: 16),
                              _buildDocItem('licencia_frente', 'Licencia (Frente)'),
                              _buildDocItem('licencia_reverso', 'Licencia (Reverso)'),
                              _buildDocItem('cedula', 'Cédula de Identidad'),
                              _buildDocItem('soat', 'SOAT / Seguro'),
                              _buildDocItem('matricula', 'Matrícula Vehicular'),
                              if (_documentosData?['tipo_conductor'] == 'cooperativa')
                                _buildDocItem('vinculacion_cooperativa', 'Resolución de Cooperativa'),
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
