import 'package:flutter/material.dart';
import 'package:super_ia/UI/views/SignIn.dart';
import 'package:http/http.dart' as http;
import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:super_ia/Core/Models/CooperativaModel.dart';
import 'package:super_ia/Core/Models/SupervisorModel.dart';
import 'package:image_picker/image_picker.dart';
import 'package:file_picker/file_picker.dart';
import 'package:super_ia/Core/Constants/Constants.dart';

class AsesorRegistrationScreen extends StatefulWidget {
  static const String route = '/asesor-registration';

  const AsesorRegistrationScreen({Key? key}) : super(key: key);

  @override
  _AsesorRegistrationScreenState createState() =>
      _AsesorRegistrationScreenState();
}

class _AsesorRegistrationScreenState extends State<AsesorRegistrationScreen>
    with TickerProviderStateMixin {
  late AnimationController _fadeController;
  late Animation<double> _fadeAnimation;

  final _formKey = GlobalKey<FormState>();

  // Controllers
  final _nombresController = TextEditingController();
  final _apellidosController = TextEditingController();
  final _emailController = TextEditingController();
  final _telefonoController = TextEditingController();
  final _contrasenaController = TextEditingController();

  // Variables para los dropdowns
  List<CooperativaModel> cooperativas = [];
  List<SupervisorModel> supervisores = [];
  CooperativaModel? cooperativaSeleccionada;
  SupervisorModel? supervisorSeleccionado;

  bool _obscurePassword = true;
  bool _isLoading = false;
  bool _cargandoCooperativas = false;
  bool _cargandoSupervisores = false;
  bool _cargandoDocumento = false;

  // Variables para documento
  String? _documentoSeleccionado;
  String? _documentoNombre;
  String? _documentoPath;

  // URL base del servidor — siempre desde Constants para no tener IPs duplicadas
  static String get baseURL => Constants.apiBaseUrl;

  @override
  void initState() {
    super.initState();
    _fadeController = AnimationController(
      duration: const Duration(milliseconds: 600),
      vsync: this,
    );
    _fadeAnimation = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _fadeController, curve: Curves.easeOut),
    );
    _fadeController.forward();
    
    // Cargar cooperativas al iniciar
    _cargarCooperativas();
  }

  @override
  void dispose() {
    _fadeController.dispose();
    _nombresController.dispose();
    _apellidosController.dispose();
    _emailController.dispose();
    _telefonoController.dispose();
    _contrasenaController.dispose();
    super.dispose();
  }

  /// Cargar lista de cooperativas desde la API
  Future<void> _cargarCooperativas() async {
    setState(() {
      _cargandoCooperativas = true;
    });

    try {
      final url = '$baseURL/api_cooperativas.php';
      print('🔍 Conectando a: $url');
      
      final response = await http.get(
        Uri.parse(url),
      ).timeout(const Duration(seconds: 10));

      print('📡 Status Code: ${response.statusCode}');
      final preview = response.body.length > 200 
          ? response.body.substring(0, 200) 
          : response.body;
      print('📄 Response Body: $preview');

      if (response.statusCode == 200) {
        final jsonData = jsonDecode(response.body);
        print('✅ JSON Decodificado: $jsonData');
        
        if (jsonData['status'] == 'success') {
          final List<dynamic> data = jsonData['data'] ?? [];
          print('✅ Cooperativas encontradas: ${data.length}');
          setState(() {
            cooperativas = data
                .map((item) => CooperativaModel.fromJson(item as Map<String, dynamic>))
                .toList();
          });
        } else {
          _mostrarError('Error al cargar cooperativas: ${jsonData['message']}');
        }
      } else {
        _mostrarError('Error del servidor: ${response.statusCode}\n${response.body}');
      }
      } on TimeoutException catch (e) {
        print('❌ Timeout: $e');
        print('❌ StackTrace: ${StackTrace.current}');
        _mostrarError(
          'Tiempo de espera agotado (10s).\n'
          'URL actual: $baseURL\n\n'
          'Si estás en teléfono físico y el Wi‑Fi está aislado, usa USB:\n'
          '1) adb reverse tcp:8080 tcp:80\n'
          '2) flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8080/SUPER_IA/server_php',
        );
      } catch (e) {
        print('❌ Error: $e');
        print('❌ StackTrace: ${StackTrace.current}');
        _mostrarError('Error de conexión:\n${e.toString()}');
    } finally {
      setState(() {
        _cargandoCooperativas = false;
      });
    }
  }

  /// Cargar supervisores de la cooperativa seleccionada
  Future<void> _cargarSupervisores(String cooperativaId) async {
    setState(() {
      _cargandoSupervisores = true;
      supervisores = [];
      supervisorSeleccionado = null;
    });

    try {
      final response = await http.post(
        Uri.parse('$baseURL/api_supervisores_cooperativa.php'),
        body: {'unidad_bancaria_id': cooperativaId},
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final jsonData = jsonDecode(response.body);
        if (jsonData['status'] == 'success') {
          final List<dynamic> data = jsonData['data'] ?? [];
          setState(() {
            supervisores = data
                .map((item) => SupervisorModel.fromJson(item as Map<String, dynamic>))
                .toList();
          });
          
          if (supervisores.isEmpty) {
            _mostrarSnackbar('No hay supervisores disponibles para esta cooperativa', Colors.orange);
          }
        } else {
          _mostrarError('Error al cargar supervisores: ${jsonData['message']}');
        }
      } else {
        _mostrarError('Error del servidor: ${response.statusCode}');
      }
    } catch (e) {
      _mostrarError('Error de conexión: ${e.toString()}');
    } finally {
      setState(() {
        _cargandoSupervisores = false;
      });
    }
  }

  /// Enviar registro del asesor
  Future<void> _submitForm() async {
    if (!(_formKey.currentState?.validate() ?? false)) {
      return;
    }

    if (cooperativaSeleccionada == null || supervisorSeleccionado == null) {
      _mostrarError('Por favor selecciona cooperativa y supervisor');
      return;
    }

    setState(() {
      _isLoading = true;
    });

    try {
      final formData = {
        'nombres': _nombresController.text,
        'apellidos': _apellidosController.text,
        'email': _emailController.text,
        'telefono': _telefonoController.text,
        'contrasena': _contrasenaController.text,
        'supervisor_id': supervisorSeleccionado!.id,
        'unidad_bancaria_id': cooperativaSeleccionada!.id,
        if (_documentoPath != null) 'documento_path': _documentoPath!,
      };

      print('📋 Enviando datos: ${jsonEncode(formData)}');

      final response = await http.post(
        Uri.parse('$baseURL/api_crear_asesor.php'),
        body: formData,
      ).timeout(const Duration(seconds: 10));

      print('📡 Response Status: ${response.statusCode}');
      print('📄 Response Body: ${response.body}');

      if (response.statusCode == 200) {
        final jsonData = jsonDecode(response.body);
        
        if (jsonData['status'] == 'success') {
          if (mounted) {
            _mostrarSnackbar('✅ Asesor registrado exitosamente. Pendiente de aprobación.', const Color(0xFF003D7A));
            
            Future.delayed(const Duration(seconds: 2), () {
              if (mounted) {
                Navigator.pushReplacementNamed(context, '/signin');
              }
            });
          }
        } else {
          _mostrarError('Error: ${jsonData['message']}');
        }
      } else {
        // Mostrar el error del servidor
        try {
          final errorData = jsonDecode(response.body);
          _mostrarError('Error (${response.statusCode}): ${errorData['message'] ?? 'Error desconocido'}');
        } catch (_) {
          _mostrarError('Error del servidor: ${response.statusCode}\n${response.body}');
        }
      }
    } catch (e) {
      _mostrarError('Error: ${e.toString()}');
      print('❌ Exception: $e');
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  void _mostrarError(String mensaje) {
    _mostrarSnackbar('❌ $mensaje', Colors.red);
  }

  void _mostrarSnackbar(String mensaje, Color color) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(mensaje),
        backgroundColor: color,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: const Color(0xFFFFC800),
      body: FadeTransition(
        opacity: _fadeAnimation,
        child: SafeArea(
          child: SingleChildScrollView(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // ── Header ────────────────────────────────────────
                  Row(
                    children: [
                      GestureDetector(
                        onTap: () => Navigator.pop(context),
                        child: Container(
                          width: 40,
                          height: 40,
                          decoration: BoxDecoration(
                            color: const Color(0xFF333333),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Icon(
                            Icons.arrow_back_ios_new,
                            color: Colors.white,
                            size: 16,
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'SUPER_IA',
                              style: TextStyle(
                                color: Color(0xFF333333),
                                fontSize: 14,
                                fontWeight: FontWeight.w800,
                                letterSpacing: 0.5,
                              ),
                            ),
                            Text(
                              'Crear Cuenta de Asesor',
                              style: TextStyle(
                                color: const Color(0xFF666666),
                                fontSize: 12,
                                fontWeight: FontWeight.w400,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),

                  const SizedBox(height: 24),

                  // ── Contenedor principal ──────────────────────────
                  Container(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(20),
                      boxShadow: [
                        BoxShadow(
                          color: const Color(0xFF333333).withOpacity(0.15),
                          blurRadius: 20,
                          offset: const Offset(0, 10),
                        ),
                      ],
                    ),
                    padding: const EdgeInsets.all(24),
                    child: Form(
                      key: _formKey,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          // ── Título ────────────────────────────────
                          const Text(
                            'Completa tus datos',
                            style: TextStyle(
                              color: Color(0xFF333333),
                              fontSize: 22,
                              fontWeight: FontWeight.w800,
                              height: 1.2,
                            ),
                          ),
                          const SizedBox(height: 6),
                          const Text(
                            'para enviar la solicitud.',
                            style: TextStyle(
                              color: Color(0xFF666666),
                              fontSize: 14,
                              fontWeight: FontWeight.w400,
                            ),
                          ),

                          const SizedBox(height: 20),

                          // ── Info box ──────────────────────────────
                          Container(
                            decoration: BoxDecoration(
                              color: const Color(0xFF003D7A).withOpacity(0.08),
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(
                                color: const Color(0xFF003D7A).withOpacity(0.2),
                              ),
                            ),
                            padding: const EdgeInsets.all(12),
                            child: const Text(
                              '📌 La cooperativa y supervisor se cargarán automáticamente desde la base de datos.',
                              style: TextStyle(
                                color: Color(0xFF003D7A),
                                fontSize: 12,
                                fontWeight: FontWeight.w500,
                                height: 1.5,
                              ),
                            ),
                          ),

                          const SizedBox(height: 24),

                          // ── Cooperativa (Cargando dinámicamente) ───
                          if (_cargandoCooperativas)
                            const SizedBox(
                              height: 60,
                              child: Center(
                                child: CircularProgressIndicator(),
                              ),
                            )
                          else
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  '🏢 Cooperativa / Banco',
                                  style: TextStyle(
                                    color: Color(0xFF333333),
                                    fontSize: 12,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 6),
                                Container(
                                  decoration: BoxDecoration(
                                    border: Border.all(
                                      color: const Color(0xFFE0E0E0),
                                    ),
                                    borderRadius: BorderRadius.circular(10),
                                    color: const Color(0xFFFAFAFA),
                                  ),
                                  child: DropdownButtonFormField<CooperativaModel>(
                                    value: cooperativaSeleccionada,
                                    isExpanded: true,
                                    items: cooperativas
                                        .map((coop) => DropdownMenuItem(
                                              value: coop,
                                              child: Text(
                                                '${coop.nombre} (${coop.codigo})',
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                            ))
                                        .toList(),
                                    onChanged: (coop) {
                                      setState(() {
                                        cooperativaSeleccionada = coop;
                                      });
                                      if (coop != null) {
                                        _cargarSupervisores(coop.id);
                                      }
                                    },
                                    decoration: InputDecoration(
                                      border: InputBorder.none,
                                      contentPadding:
                                          const EdgeInsets.symmetric(
                                            horizontal: 12,
                                            vertical: 12,
                                          ),
                                      hint: const Text(
                                        '-- Selecciona una cooperativa --',
                                        style: TextStyle(
                                          color: Color(0xFFBBBBBB),
                                          fontSize: 13,
                                        ),
                                      ),
                                    ),
                                  ),
                                ),
                              ],
                            ),

                          const SizedBox(height: 16),

                          // ── Supervisor (Dinámico) ─────────────────
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text(
                                '👤 Supervisor Responsable',
                                style: TextStyle(
                                  color: Color(0xFF333333),
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              const SizedBox(height: 6),
                              Container(
                                decoration: BoxDecoration(
                                  border: Border.all(
                                    color: const Color(0xFFE0E0E0),
                                  ),
                                  borderRadius: BorderRadius.circular(10),
                                  color: const Color(0xFFFAFAFA),
                                ),
                                child: cooperativaSeleccionada == null
                                    ? const Padding(
                                        padding: EdgeInsets.symmetric(
                                          horizontal: 12,
                                          vertical: 12,
                                        ),
                                        child: Text(
                                          '-- Primero selecciona una cooperativa --',
                                          style: TextStyle(
                                            color: Color(0xFFBBBBBB),
                                            fontSize: 13,
                                          ),
                                        ),
                                      )
                                    : _cargandoSupervisores
                                        ? const Padding(
                                            padding: EdgeInsets.symmetric(
                                              horizontal: 12,
                                              vertical: 12,
                                            ),
                                            child: SizedBox(
                                              height: 20,
                                              width: 20,
                                              child:
                                                  CircularProgressIndicator(
                                                strokeWidth: 2,
                                              ),
                                            ),
                                          )
                                        : DropdownButtonFormField<SupervisorModel>(
                                            value: supervisorSeleccionado,
                                            isExpanded: true,
                                            items: supervisores
                                                .map((sup) =>
                                                    DropdownMenuItem(
                                                      value: sup,
                                                      child: Text(
                                                        sup.nombre,
                                                        overflow: TextOverflow.ellipsis,
                                                      ),
                                                    ))
                                                .toList(),
                                            onChanged: (sup) {
                                              setState(() {
                                                supervisorSeleccionado = sup;
                                              });
                                            },
                                            decoration: InputDecoration(
                                              border: InputBorder.none,
                                              contentPadding:
                                                  const EdgeInsets.symmetric(
                                                    horizontal: 12,
                                                    vertical: 12,
                                                  ),
                                              hint: Text(
                                                supervisores.isEmpty
                                                    ? 'No hay supervisores disponibles'
                                                    : '-- Selecciona un supervisor --',
                                                style: const TextStyle(
                                                  color:
                                                      Color(0xFFBBBBBB),
                                                  fontSize: 13,
                                                ),
                                              ),
                                            ),
                                          ),
                              ),
                            ],
                          ),

                          const SizedBox(height: 16),

                          // ── Nombres y Apellidos (en fila) ─────────
                          Row(
                            children: [
                              Expanded(
                                child: _buildTextField(
                                  label: '👤 Nombres',
                                  hint: 'Ej: Juan Carlos',
                                  controller: _nombresController,
                                  validator: (value) {
                                    if (value?.isEmpty ?? true) {
                                      return 'Requerido';
                                    }
                                    return null;
                                  },
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: _buildTextField(
                                  label: '👤 Apellidos',
                                  hint: 'Ej: García López',
                                  controller: _apellidosController,
                                  validator: (value) {
                                    if (value?.isEmpty ?? true) {
                                      return 'Requerido';
                                    }
                                    return null;
                                  },
                                ),
                              ),
                            ],
                          ),

                          const SizedBox(height: 16),

                          // ── Email ──────────────────────────────
                          _buildTextField(
                            label: '📧 Email',
                            hint: 'tu@email.com',
                            controller: _emailController,
                            keyboardType: TextInputType.emailAddress,
                            validator: (value) {
                              if (value?.isEmpty ?? true) {
                                return 'Email requerido';
                              }
                              if (!value!.contains('@')) {
                                return 'Email inválido';
                              }
                              return null;
                            },
                          ),

                          const SizedBox(height: 16),

                          // ── Teléfono ───────────────────────────
                          _buildTextField(
                            label: '📱 Teléfono',
                            hint: 'Ej: +593 99 1234567',
                            controller: _telefonoController,
                            keyboardType: TextInputType.phone,
                            validator: (value) {
                              if (value?.isEmpty ?? true) {
                                return 'Teléfono requerido';
                              }
                              return null;
                            },
                          ),

                          const SizedBox(height: 20),

                          // ── Contraseña ─────────────────────────
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text(
                                '🔐 Contraseña',
                                style: TextStyle(
                                  color: Color(0xFF333333),
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              const SizedBox(height: 6),
                              TextFormField(
                                controller: _contrasenaController,
                                obscureText: _obscurePassword,
                                style: const TextStyle(
                                  color: Color(0xFF333333),
                                  fontSize: 13,
                                  fontWeight: FontWeight.w500,
                                ),
                                validator: (value) {
                                  if (value?.isEmpty ?? true) {
                                    return 'Contraseña requerida';
                                  }
                                  if ((value?.length ?? 0) < 6) {
                                    return 'Mínimo 6 caracteres';
                                  }
                                  return null;
                                },
                                decoration: InputDecoration(
                                  hintText: 'Mínimo 6 caracteres',
                                  hintStyle: const TextStyle(
                                    color: Color(0xFF999999),
                                    fontSize: 13,
                                    fontWeight: FontWeight.w400,
                                  ),
                                  contentPadding: const EdgeInsets.symmetric(
                                    horizontal: 12,
                                    vertical: 10,
                                  ),
                                  suffixIcon: GestureDetector(
                                    onTap: () {
                                      setState(() {
                                        _obscurePassword = !_obscurePassword;
                                      });
                                    },
                                    child: Icon(
                                      _obscurePassword
                                          ? Icons.visibility_off
                                          : Icons.visibility,
                                      color: const Color(0xFF999999),
                                      size: 18,
                                    ),
                                  ),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(10),
                                    borderSide: const BorderSide(
                                      color: Color(0xFFE0E0E0),
                                    ),
                                  ),
                                  enabledBorder: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(10),
                                    borderSide: const BorderSide(
                                      color: Color(0xFFE0E0E0),
                                    ),
                                  ),
                                  focusedBorder: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(10),
                                    borderSide: const BorderSide(
                                      color: Color(0xFF003D7A),
                                      width: 2,
                                    ),
                                  ),
                                  errorBorder: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(10),
                                    borderSide: const BorderSide(color: Colors.red, width: 1.5),
                                  ),
                                  filled: true,
                                  fillColor: const Color(0xFFFAFAFA),
                                ),
                              ),
                            ],
                          ),

                          const SizedBox(height: 24),

                          // ── Documento PDF/Imagen ─────────────────────────
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text(
                                '📄 Documento de Identidad (PDF, JPG, PNG)',
                                style: TextStyle(
                                  color: Color(0xFF333333),
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              const SizedBox(height: 6),
                              GestureDetector(
                                onTap: _cargandoDocumento 
                                    ? null 
                                    : () {
                                        _mostrarDialogoDocumento();
                                      },
                                child: Container(
                                  height: 80,
                                  decoration: BoxDecoration(
                                    border: Border.all(
                                      color: _documentoSeleccionado != null 
                                          ? const Color(0xFF003D7A)
                                          : const Color(0xFFDDDDDD),
                                      width: 2,
                                      style: BorderStyle.solid,
                                    ),
                                    borderRadius: BorderRadius.circular(10),
                                    color: _documentoSeleccionado != null
                                        ? const Color(0xFFE8F0FF)
                                        : const Color(0xFFF5F5F5),
                                  ),
                                  child: Center(
                                    child: _cargandoDocumento
                                        ? const SizedBox(
                                            width: 32,
                                            height: 32,
                                            child: CircularProgressIndicator(
                                              strokeWidth: 2,
                                            ),
                                          )
                                        : _documentoSeleccionado != null
                                            ? Column(
                                                mainAxisAlignment:
                                                    MainAxisAlignment.center,
                                                children: [
                                                  const Icon(
                                                    Icons.check_circle,
                                                    size: 32,
                                                    color: Color(0xFF003D7A),
                                                  ),
                                                  const SizedBox(height: 8),
                                                  Text(
                                                    '✅ $_documentoNombre',
                                                    style: const TextStyle(
                                                      color:
                                                          Color(0xFF003D7A),
                                                      fontSize: 11,
                                                      fontWeight:
                                                          FontWeight.w600,
                                                    ),
                                                    textAlign: TextAlign.center,
                                                  ),
                                                ],
                                              )
                                            : Column(
                                                mainAxisAlignment:
                                                    MainAxisAlignment.center,
                                                children: const [
                                                  Icon(
                                                    Icons.cloud_upload_outlined,
                                                    size: 32,
                                                    color:
                                                        Color(0xFF003D7A),
                                                  ),
                                                  SizedBox(height: 8),
                                                  Text(
                                                    '📤 Toca para subir documento',
                                                    style: TextStyle(
                                                      color:
                                                          Color(0xFF666666),
                                                      fontSize: 12,
                                                      fontWeight:
                                                          FontWeight.w500,
                                                    ),
                                                  ),
                                                ],
                                              ),
                                  ),
                                ),
                              ),
                            ],
                          ),

                          const SizedBox(height: 24),
                          GestureDetector(
                            onTap: _isLoading ? null : _submitForm,
                            child: Container(
                              height: 54,
                              decoration: BoxDecoration(
                                gradient: const LinearGradient(
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                  colors: [
                                    Color(0xFF003D7A),
                                    Color(0xFF1E5A96),
                                  ],
                                ),
                                borderRadius: BorderRadius.circular(12),
                                boxShadow: [
                                  BoxShadow(
                                    color: const Color(0xFF003D7A)
                                        .withOpacity(0.3),
                                    blurRadius: 15,
                                    offset: const Offset(0, 5),
                                  ),
                                ],
                              ),
                              child: Center(
                                child: _isLoading
                                    ? const SizedBox(
                                        width: 24,
                                        height: 24,
                                        child: CircularProgressIndicator(
                                          valueColor:
                                              AlwaysStoppedAnimation<Color>(
                                            Colors.white,
                                          ),
                                          strokeWidth: 2.5,
                                        ),
                                      )
                                    : const Text(
                                        '✈️ Registrarse como Asesor',
                                        style: TextStyle(
                                          color: Colors.white,
                                          fontSize: 16,
                                          fontWeight: FontWeight.w700,
                                          letterSpacing: 0.5,
                                        ),
                                      ),
                              ),
                            ),
                          ),

                          const SizedBox(height: 14),

                          // ── Volver al Login ──────────────────────
                          GestureDetector(
                            onTap: () => Navigator.pushReplacementNamed(
                              context,
                              '/signin',
                            ),
                            child: Container(
                              height: 48,
                              decoration: BoxDecoration(
                                color: const Color(0xFFF5F5F5),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(
                                  color: const Color(0xFFDDDDDD),
                                ),
                              ),
                              child: const Center(
                                child: Text(
                                  '← ¿Ya tienes cuenta? Inicia sesión',
                                  style: TextStyle(
                                    color: Color(0xFF666666),
                                    fontSize: 14,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),

                  const SizedBox(height: 20),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildTextField({
    required String label,
    required String hint,
    required TextEditingController controller,
    TextInputType keyboardType = TextInputType.text,
    bool obscureText = false,
    Widget? suffixIcon,
    String? Function(String?)? validator,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(
            color: Color(0xFF333333),
            fontSize: 12,
            fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 6),
        TextFormField(
          controller: controller,
          keyboardType: keyboardType,
          obscureText: obscureText,
          validator: validator,
          decoration: InputDecoration(
            hintText: hint,
            hintStyle: const TextStyle(
              color: Color(0xFFBBBBBB),
              fontSize: 13,
            ),
            prefixIconConstraints: const BoxConstraints(minWidth: 40),
            suffixIcon: suffixIcon != null
                ? Padding(
                    padding: const EdgeInsets.only(right: 12),
                    child: suffixIcon,
                  )
                : null,
            filled: true,
            fillColor: const Color(0xFFFAFAFA),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: const BorderSide(color: Color(0xFFE0E0E0)),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: const BorderSide(color: Color(0xFFE0E0E0)),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: const BorderSide(
                color: Color(0xFF003D7A),
                width: 2,
              ),
            ),
            errorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: const BorderSide(color: Colors.red, width: 1.5),
            ),
            contentPadding: const EdgeInsets.symmetric(
              horizontal: 12,
              vertical: 10,
            ),
          ),
          style: const TextStyle(
            color: Color(0xFF333333),
            fontSize: 13,
            fontWeight: FontWeight.w500,
          ),
        ),
      ],
    );
  }

  /// Mostrar diálogo para seleccionar tipo de documento
  void _mostrarDialogoDocumento() {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Seleccionar Documento'),
        content: const Text('Documento de prueba simulado para demostración'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancelar'),
          ),
          TextButton(
            onPressed: () {
              Navigator.pop(context);
              _simularCargaDocumento();
            },
            child: const Text('Seleccionar'),
          ),
        ],
      ),
    );
  }

  /// Seleccionar y cargar documento desde device (camera/gallery)
  void _simularCargaDocumento() async {
    try {
      final picker = ImagePicker();
      
      // Mostrar opciones: cámara o galería
      showModalBottomSheet(
        context: context,
        backgroundColor: const Color(0xFF1A1A1A),
        builder: (BuildContext ctx) => Container(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40, height: 4,
                margin: const EdgeInsets.only(top: 16, bottom: 8),
                decoration: BoxDecoration(
                  color: Colors.white24,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
              ListTile(
                leading: const Icon(Icons.camera_alt, color: Colors.white),
                title: const Text('Tomar foto', style: TextStyle(color: Colors.white)),
                onTap: () async {
                  Navigator.pop(ctx);
                  await _cargarYSubirDocumento(
                    await picker.pickImage(source: ImageSource.camera, imageQuality: 85)
                  );
                },
              ),
              ListTile(
                leading: const Icon(Icons.photo_library, color: Colors.white),
                title: const Text('Seleccionar de galería', style: TextStyle(color: Colors.white)),
                onTap: () async {
                  Navigator.pop(ctx);
                  await _cargarYSubirDocumento(
                    await picker.pickImage(source: ImageSource.gallery, imageQuality: 85)
                  );
                },
              ),
              ListTile(
                leading: const Icon(Icons.picture_as_pdf, color: Colors.white),
                title: const Text('Seleccionar PDF', style: TextStyle(color: Colors.white)),
                onTap: () async {
                  Navigator.pop(ctx);
                  await _seleccionarYSubirPdf();
                },
              ),
            ],
          ),
        ),
      );
    } catch (e) {
      _mostrarError('Error: ${e.toString()}');
      print('❌ Error: $e');
    }
  }

  /// Procesar y subir documento seleccionado
  Future<void> _cargarYSubirDocumento(XFile? imagenPickeada) async {
    if (imagenPickeada == null) return;

    try {
      setState(() => _cargandoDocumento = true);

      print('📄 Archivo seleccionado: ${imagenPickeada.name} (${imagenPickeada.path})');

      // Leer contenido del archivo
      final bytes = await imagenPickeada.readAsBytes();

      if (bytes.isEmpty) {
        throw Exception('El archivo está vacío');
      }

      await _subirDocumentoBytes(filename: imagenPickeada.name, bytes: bytes);
    } catch (e) {
      if (mounted) {
        setState(() => _cargandoDocumento = false);
        _mostrarError('Error al subir documento: ${e.toString()}');
      }
      print('❌ Error: $e');
    }
  }

  Future<void> _seleccionarYSubirPdf() async {
    try {
      setState(() => _cargandoDocumento = true);

      final result = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['pdf'],
        withData: true,
      );

      if (result == null || result.files.isEmpty) {
        if (mounted) setState(() => _cargandoDocumento = false);
        return;
      }

      final file = result.files.first;
      final Uint8List? bytes = file.bytes;
      final name = file.name;

      if (bytes == null || bytes.isEmpty) {
        throw Exception('No se pudo leer el PDF');
      }

      await _subirDocumentoBytes(filename: name, bytes: bytes);
    } catch (e) {
      if (mounted) {
        setState(() => _cargandoDocumento = false);
        _mostrarError('Error al subir PDF: ${e.toString()}');
      }
    }
  }

  Future<void> _subirDocumentoBytes({
    required String filename,
    required List<int> bytes,
  }) async {
    final request = http.MultipartRequest(
      'POST',
      Uri.parse('$baseURL/api_subir_documento.php'),
    );

    request.files.add(
      http.MultipartFile.fromBytes(
        'file',
        bytes,
        filename: filename,
      ),
    );

    print('📤 Subiendo archivo $filename (${bytes.length} bytes)...');
    final response = await request.send().timeout(const Duration(seconds: 30));

    final bodyText = await response.stream.bytesToString();
    print('📡 Status: ${response.statusCode}');
    if (bodyText.trim().isNotEmpty) {
      print('📄 Body: $bodyText');
    }

    Map<String, dynamic>? responseData;
    try {
      final decoded = jsonDecode(bodyText);
      if (decoded is Map<String, dynamic>) {
        responseData = decoded;
      } else if (decoded is Map) {
        responseData = Map<String, dynamic>.from(decoded);
      }
    } catch (_) {
      responseData = null;
    }

    if (response.statusCode == 200 && responseData != null) {
      if (responseData['status'] == 'success') {
        if (mounted) {
          setState(() {
            _documentoSeleccionado = filename;
            _documentoNombre = filename.length > 30
                ? '${filename.substring(0, 27)}...'
                : filename;
            _documentoPath = responseData!['filepath'];
            _cargandoDocumento = false;
          });

          _mostrarSnackbar(
            '✅ $filename - Subido exitosamente',
            const Color(0xFF003D7A),
          );
        }
        return;
      }

      throw Exception(responseData['message'] ?? 'Error desconocido');
    }

    final serverMessage = responseData?['message']?.toString();
    if (serverMessage != null && serverMessage.isNotEmpty) {
      throw Exception(serverMessage);
    }

    if (bodyText.trim().isEmpty) {
      throw Exception(
        'El servidor respondió HTTP ${response.statusCode} sin body.\n'
        'Esto suele pasar cuando el hosting está ejecutando un PHP viejo o hay un fatal antes de imprimir JSON.\n'
        'Revise/actualice: server_php/api_subir_documento.php en el hosting.\n'
        'Endpoint: $baseURL/api_subir_documento.php',
      );
    }

    throw Exception('Error del servidor: HTTP ${response.statusCode}');
  }
}
