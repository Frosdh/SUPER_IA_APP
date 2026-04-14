import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/Core/ProviderModels/UserDetailsModel.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';

class EditProfileScreen extends StatefulWidget {
  static const String route = '/edit_profile';

  @override
  _EditProfileScreenState createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nombreController = TextEditingController();
  final _emailController  = TextEditingController();

  bool _guardando = false;
  String _telefono = '';

  @override
  void initState() {
    super.initState();
    _cargarDatosActuales();
  }

  @override
  void dispose() {
    _nombreController.dispose();
    _emailController.dispose();
    super.dispose();
  }

  Future<void> _cargarDatosActuales() async {
    final nombre   = await AuthPrefs.getUserName();
    final email    = await AuthPrefs.getUserEmail();
    final telefono = await AuthPrefs.getUserPhone();
    if (mounted) {
      setState(() {
        _nombreController.text = nombre;
        _emailController.text  = email;
        _telefono = telefono;
      });
    }
  }

  Future<void> _guardarCambios() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    if (_guardando) return;

    setState(() => _guardando = true);

    final nombre = _nombreController.text.trim();
    final email  = _emailController.text.trim();

    bool exito = false;

    // Intentar actualizar en el servidor (hosting)
    try {
      final response = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/actualizar_perfil.php'),
        body: {
          'telefono': _telefono,
          'nombre': nombre,
          'email': email,
        },
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['status'] == 'success') {
          exito = true;
        }
      }
    } catch (_) {}

    // Actualizar SharedPreferences localmente siempre
    await AuthPrefs.saveUserSession(
      nombre:   nombre,
      telefono: _telefono,
      email:    email,
    );

    // Notificar al Provider para que el perfil se actualice en toda la app
    if (mounted) {
      Provider.of<UserDetailsModel>(context, listen: false).reload();
    }

    setState(() => _guardando = false);

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            exito ? '¡Perfil actualizado!' : 'Guardado localmente (sin conexión)',
            style: TextStyle(color: Colors.white),
          ),
          backgroundColor: exito ? Color(0xFF7B2FF7) : Colors.orange,
        ),
      );
      await Future.delayed(Duration(milliseconds: 800));
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Color(0xFF12121F),
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: Icon(Icons.arrow_back, color: Colors.white),
          onPressed: () => Navigator.of(context).pop(),
        ),
        title: Text('Editar perfil', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
      ),
      body: SingleChildScrollView(
        padding: EdgeInsets.all(24),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [

              // ── Avatar grande ────────────────────────────────
              Center(
                child: Container(
                  width: 90,
                  height: 90,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: LinearGradient(
                      colors: [Color(0xFF7B2FF7), Color(0xFF2B6BF7)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                  ),
                  child: Center(
                    child: Text(
                      _nombreController.text.isNotEmpty
                          ? _nombreController.text[0].toUpperCase()
                          : '?',
                      style: TextStyle(fontSize: 38, fontWeight: FontWeight.bold, color: Colors.white),
                    ),
                  ),
                ),
              ),

              SizedBox(height: 32),

              // ── Nombre ───────────────────────────────────────
              Text('Nombre completo', style: TextStyle(color: Colors.white60, fontSize: 13, fontWeight: FontWeight.w500)),
              SizedBox(height: 8),
              TextFormField(
                controller: _nombreController,
                style: TextStyle(color: Colors.white, fontSize: 16),
                decoration: _inputDecoration(Icons.person_outline, 'Tu nombre'),
                textCapitalization: TextCapitalization.words,
                onChanged: (_) => setState(() {}), // para actualizar la inicial del avatar
                validator: (v) {
                  if (v == null || v.trim().isEmpty) return 'El nombre es obligatorio';
                  if (v.trim().length < 2) return 'Mínimo 2 caracteres';
                  return null;
                },
              ),

              SizedBox(height: 20),

              // ── Email ────────────────────────────────────────
              Text('Correo electrónico', style: TextStyle(color: Colors.white60, fontSize: 13, fontWeight: FontWeight.w500)),
              SizedBox(height: 8),
              TextFormField(
                controller: _emailController,
                style: TextStyle(color: Colors.white, fontSize: 16),
                decoration: _inputDecoration(Icons.email_outlined, 'tu@email.com'),
                keyboardType: TextInputType.emailAddress,
                validator: (v) {
                  if (v != null && v.isNotEmpty && !v.contains('@')) {
                    return 'Ingresa un correo válido';
                  }
                  return null;
                },
              ),

              SizedBox(height: 20),

              // ── Teléfono (solo lectura) ───────────────────────
              Text('Teléfono', style: TextStyle(color: Colors.white60, fontSize: 13, fontWeight: FontWeight.w500)),
              SizedBox(height: 8),
              Container(
                padding: EdgeInsets.symmetric(horizontal: 16, vertical: 16),
                decoration: BoxDecoration(
                  color: Color(0xFF1E1E2C),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: Colors.white12),
                ),
                child: Row(
                  children: [
                    Icon(Icons.phone_outlined, color: Colors.white30, size: 20),
                    SizedBox(width: 12),
                    Text(_telefono.isNotEmpty ? _telefono : '—',
                        style: TextStyle(color: Colors.white38, fontSize: 16)),
                    Spacer(),
                    Icon(Icons.lock_outline, color: Colors.white30, size: 16),
                  ],
                ),
              ),
              Padding(
                padding: EdgeInsets.only(top: 6, left: 4),
                child: Text(
                  'El teléfono no se puede cambiar',
                  style: TextStyle(color: Colors.white30, fontSize: 11),
                ),
              ),

              SizedBox(height: 40),

              // ── Botón guardar ────────────────────────────────
              GestureDetector(
                onTap: _guardando ? null : _guardarCambios,
                child: Container(
                  width: double.infinity,
                  height: 56,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: _guardando
                          ? [Colors.grey, Colors.grey]
                          : [Color(0xFF7B2FF7), Color(0xFF2B6BF7)],
                      begin: Alignment.centerLeft,
                      end: Alignment.centerRight,
                    ),
                    borderRadius: BorderRadius.circular(16),
                    boxShadow: _guardando ? [] : [
                      BoxShadow(
                        color: Color(0xFF7B2FF7).withOpacity(0.4),
                        blurRadius: 16,
                        offset: Offset(0, 6),
                      ),
                    ],
                  ),
                  child: Center(
                    child: _guardando
                        ? SizedBox(
                            width: 24,
                            height: 24,
                            child: CircularProgressIndicator(
                              strokeWidth: 2.5,
                              valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                            ),
                          )
                        : Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.check_rounded, color: Colors.white, size: 22),
                              SizedBox(width: 8),
                              Text(
                                'Guardar cambios',
                                style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700),
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
    );
  }

  InputDecoration _inputDecoration(IconData icon, String hint) {
    return InputDecoration(
      prefixIcon: Icon(icon, color: Color(0xFF7B2FF7), size: 20),
      hintText: hint,
      hintStyle: TextStyle(color: Colors.white30, fontSize: 15),
      filled: true,
      fillColor: Color(0xFF1E1E2C),
      contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: Colors.white12),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: Colors.white12),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: Color(0xFF7B2FF7), width: 2),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: Colors.redAccent),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: Colors.redAccent, width: 2),
      ),
    );
  }
}
