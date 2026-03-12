import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/ProviderModels/VerificationModel.dart';
import 'package:fu_uber/Core/Services/PushNotificationService.dart';
import 'package:fu_uber/UI/views/LocationPermissionScreen.dart';
import 'package:provider/provider.dart';

class RegisterScreen extends StatefulWidget {
  static const String route = '/register';

  @override
  _RegisterScreenState createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen>
    with SingleTickerProviderStateMixin {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final TextEditingController _nameController = TextEditingController();
  final TextEditingController _emailController = TextEditingController();
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  AnimationController _animController;
  Animation<double> _fadeAnimation;
  Animation<Offset> _slideAnimation;
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: 600),
    );
    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _animController, curve: Curves.easeOut),
    );
    _slideAnimation =
        Tween<Offset>(begin: Offset(0, 0.2), end: Offset.zero).animate(
      CurvedAnimation(parent: _animController, curve: Curves.easeOut),
    );
    _animController.forward();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _animController.dispose();
    super.dispose();
  }

  void _continuar(VerificationModel verificationModel) async {
    if (_formKey.currentState.validate()) {
      setState(() => _isLoading = true);

      final nombre = _nameController.text.trim();
      final email = _emailController.text.trim();
      final telefono = verificationModel.phoneNumber ?? '';

      if (telefono.isEmpty) {
        setState(() => _isLoading = false);
        _scaffoldKey.currentState.showSnackBar(
          SnackBar(
            content: Text('No se encontró el teléfono verificado. Intenta de nuevo.'),
            backgroundColor: ConstantColors.error,
          ),
        );
        return;
      }

      // Registrar usuario en la base de datos
      bool exito = await verificationModel.registerUser(nombre, telefono, email);

      setState(() => _isLoading = false);

      if (exito) {
        // Guardar sesión solo si el backend confirmó registro.
        await AuthPrefs.saveUserSession(
          nombre: nombre,
          telefono: telefono,
          email: email,
        );
        await PushNotificationService.syncTokenWithBackend(force: true);
        Navigator.pushReplacementNamed(context, LocationPermissionScreen.route);
      } else {
        await AuthPrefs.clearSession();
        _scaffoldKey.currentState.showSnackBar(
          SnackBar(
            content: Text('No se pudo registrar en la base de datos. Revisa la API y vuelve a intentar.'),
            backgroundColor: ConstantColors.error,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final verificationModel = Provider.of<VerificationModel>(context);
    final size = MediaQuery.of(context).size;

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: ConstantColors.backgroundDark,
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFF1A0A3D),
              ConstantColors.backgroundDark,
            ],
          ),
        ),
        child: SafeArea(
          child: SingleChildScrollView(
            padding: EdgeInsets.symmetric(horizontal: 28),
            child: FadeTransition(
              opacity: _fadeAnimation,
              child: SlideTransition(
                position: _slideAnimation,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(height: size.height * 0.08),

                    // Ícono de bienvenida
                    Center(
                      child: Container(
                        width: 80,
                        height: 80,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: LinearGradient(
                            colors: [
                              ConstantColors.primaryViolet,
                              ConstantColors.primaryBlue,
                            ],
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                          ),
                          boxShadow: [
                            BoxShadow(
                              color: ConstantColors.primaryViolet.withOpacity(0.4),
                              blurRadius: 30,
                              spreadRadius: 2,
                            ),
                          ],
                        ),
                        child: Icon(
                          Icons.person_rounded,
                          color: Colors.white,
                          size: 40,
                        ),
                      ),
                    ),

                    SizedBox(height: 36),

                    // Título
                    Text(
                      '¡Casi listo!',
                      style: TextStyle(
                        color: ConstantColors.textWhite,
                        fontSize: 32,
                        fontWeight: FontWeight.w800,
                      ),
                    ),

                    SizedBox(height: 10),

                    Text(
                      'Completa tu perfil para que los conductores te reconozcan.',
                      style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 15,
                        height: 1.5,
                      ),
                    ),

                    SizedBox(height: 40),

                    // Label
                    Text(
                      'Tu nombre completo',
                      style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.5,
                      ),
                    ),

                    SizedBox(height: 10),

                    // Campo de nombre
                    Form(
                      key: _formKey,
                      child: Container(
                        decoration: BoxDecoration(
                          color: ConstantColors.backgroundCard,
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(
                            color: ConstantColors.borderColor,
                            width: 1.5,
                          ),
                        ),
                        child: TextFormField(
                          controller: _nameController,
                          textCapitalization: TextCapitalization.words,
                          keyboardType: TextInputType.name,
                          style: TextStyle(
                            color: ConstantColors.textWhite,
                            fontSize: 16,
                            fontWeight: FontWeight.w500,
                          ),
                          decoration: InputDecoration(
                            hintText: 'Ej: María García',
                            hintStyle: TextStyle(
                              color: ConstantColors.textSubtle,
                              fontSize: 15,
                            ),
                            prefixIcon: Icon(
                              Icons.person_outline_rounded,
                              color: ConstantColors.textSubtle,
                              size: 22,
                            ),
                            border: InputBorder.none,
                            contentPadding: EdgeInsets.symmetric(
                              horizontal: 16,
                              vertical: 16,
                            ),
                            errorStyle: TextStyle(
                              color: ConstantColors.error,
                              fontSize: 12,
                            ),
                          ),
                          validator: (value) {
                            if (value == null || value.trim().isEmpty) {
                              return 'Por favor ingresa tu nombre';
                            }
                            if (value.trim().length < 3) {
                              return 'El nombre debe tener al menos 3 caracteres';
                            }
                            return null;
                          },
                        ),
                      ),
                    ),

                    SizedBox(height: 20),

                    // Label email
                    Text(
                      'Correo electrónico',
                      style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.5,
                      ),
                    ),

                    SizedBox(height: 10),

                    // Campo de email
                    Container(
                      decoration: BoxDecoration(
                        color: ConstantColors.backgroundCard,
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(
                          color: ConstantColors.borderColor,
                          width: 1.5,
                        ),
                      ),
                      child: TextFormField(
                        controller: _emailController,
                        keyboardType: TextInputType.emailAddress,
                        style: TextStyle(
                          color: ConstantColors.textWhite,
                          fontSize: 16,
                          fontWeight: FontWeight.w500,
                        ),
                        decoration: InputDecoration(
                          hintText: 'Ej: wendy@gmail.com',
                          hintStyle: TextStyle(
                            color: ConstantColors.textSubtle,
                            fontSize: 15,
                          ),
                          prefixIcon: Icon(
                            Icons.email_outlined,
                            color: ConstantColors.textSubtle,
                            size: 22,
                          ),
                          border: InputBorder.none,
                          contentPadding: EdgeInsets.symmetric(
                            horizontal: 16,
                            vertical: 16,
                          ),
                          errorStyle: TextStyle(
                            color: ConstantColors.error,
                            fontSize: 12,
                          ),
                        ),
                        validator: (value) {
                          if (value == null || value.trim().isEmpty) {
                            return 'Por favor ingresa tu correo';
                          }
                          if (!value.contains('@') || !value.contains('.')) {
                            return 'Ingresa un correo válido';
                          }
                          return null;
                        },
                      ),
                    ),

                    SizedBox(height: 12),

                    Text(
                      'Para enviarte recibos y notificaciones de tus viajes',
                      style: TextStyle(
                        color: ConstantColors.textSubtle,
                        fontSize: 12,
                        height: 1.4,
                      ),
                    ),

                    SizedBox(height: 40),

                    // Botón continuar
                    _isLoading
                        ? Center(
                            child: CircularProgressIndicator(
                              strokeWidth: 2.5,
                              valueColor: AlwaysStoppedAnimation<Color>(
                                ConstantColors.primaryViolet,
                              ),
                            ),
                          )
                        : GestureDetector(
                            onTap: () => _continuar(verificationModel),
                            child: Container(
                              width: double.infinity,
                              height: 56,
                              decoration: BoxDecoration(
                                gradient: LinearGradient(
                                  colors: [
                                    ConstantColors.primaryViolet,
                                    ConstantColors.primaryBlue,
                                  ],
                                  begin: Alignment.centerLeft,
                                  end: Alignment.centerRight,
                                ),
                                borderRadius: BorderRadius.circular(16),
                                boxShadow: [
                                  BoxShadow(
                                    color: ConstantColors.primaryViolet
                                        .withOpacity(0.4),
                                    blurRadius: 20,
                                    offset: Offset(0, 8),
                                  ),
                                ],
                              ),
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Text(
                                    'Comenzar a viajar',
                                    style: TextStyle(
                                      color: Colors.white,
                                      fontSize: 16,
                                      fontWeight: FontWeight.w700,
                                      letterSpacing: 0.5,
                                    ),
                                  ),
                                  SizedBox(width: 8),
                                  Icon(
                                    Icons.arrow_forward_rounded,
                                    color: Colors.white,
                                    size: 20,
                                  ),
                                ],
                              ),
                            ),
                          ),

                    SizedBox(height: 32),

                    // Info de número verificado
                    Container(
                      padding: EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: ConstantColors.primaryViolet.withOpacity(0.08),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                          color: ConstantColors.primaryViolet.withOpacity(0.2),
                        ),
                      ),
                      child: Row(
                        children: [
                          Icon(
                            Icons.check_circle_rounded,
                            color: ConstantColors.success,
                            size: 20,
                          ),
                          SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Número verificado',
                                  style: TextStyle(
                                    color: ConstantColors.textWhite,
                                    fontSize: 13,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                SizedBox(height: 2),
                                Text(
                                  verificationModel.phoneNumber ?? '',
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
                    ),

                    SizedBox(height: 32),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
