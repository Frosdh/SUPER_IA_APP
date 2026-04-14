import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Networking/ApiProvider.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/UI/views/LocationPermissionScreen.dart';

class SignInPage extends StatefulWidget {
  static const String route = '/signin';

  @override
  _SignInPageState createState() => _SignInPageState();
}

class _SignInPageState extends State<SignInPage> {
  final GlobalKey<FormState> _emailFormKey = GlobalKey<FormState>();
  final TextEditingController emailTextController = TextEditingController();
  final TextEditingController passwordTextController = TextEditingController();

  bool _loading = false;
  bool _obscurePassword = true;

  @override
  void dispose() {
    emailTextController.dispose();
    passwordTextController.dispose();
    super.dispose();
  }

  Future<void> _handleLogin(BuildContext context) async {
    if (!(_emailFormKey.currentState?.validate() ?? false)) {
      return;
    }

    setState(() => _loading = true);
    try {
      final api = ApiProvider();
      final res = await api.loginAsesor(
        email: emailTextController.text.trim(),
        password: passwordTextController.text,
      );

      if (!mounted) return;

      if ((res['status'] ?? '') != 'success') {
        final msg = (res['message'] ?? 'No se pudo iniciar sesión').toString();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(msg)),
        );
        return;
      }

      final user = (res['user'] is Map) ? (res['user'] as Map).cast<String, dynamic>() : <String, dynamic>{};
      await AuthPrefs.saveUserSession(
        nombre: (user['nombre'] ?? '').toString(),
        telefono: (user['telefono'] ?? '').toString(),
        email: (user['email'] ?? emailTextController.text.trim()).toString(),
        usuarioId: (user['id'] ?? '').toString(),
      );

      final asesorId = (user['asesor_id'] ?? '').toString();
      if (asesorId.isNotEmpty) {
        await AuthPrefs.saveAsesorId(asesorId);
      }

      if (!mounted) return;
      Navigator.pushReplacementNamed(context, LocationPermissionScreen.route);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error de conexión: $e')),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Widget _buildHeroOrb({
    required double size,
    required double top,
    double? left,
    double? right,
    required double opacity,
  }) {
    return Positioned(
      top: top,
      left: left,
      right: right,
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: Colors.white.withOpacity(opacity),
        ),
      ),
    );
  }

  Widget _buildInfoCard({
    required Widget child,
    EdgeInsetsGeometry padding = const EdgeInsets.all(16),
  }) {
    return Container(
      width: double.infinity,
      padding: padding,
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: ConstantColors.borderColor.withOpacity(0.9),
        ),
      ),
      child: child,
    );
  }

  @override
  Widget build(BuildContext context) {
    final Size mediaQuery = MediaQuery.of(context).size;
    final args = ModalRoute.of(context)?.settings.arguments;
    final isRegisterMode = args is Map && args['mode'] == 'register';

    return Scaffold(
      backgroundColor: ConstantColors.warning,
      body: Stack(
        children: <Widget>[
          Container(color: ConstantColors.warning),
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: Container(
              height: mediaQuery.height * 0.42,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: <Color>[
                    ConstantColors.warning.withOpacity(0.28),
                    ConstantColors.primaryBlue.withOpacity(0.22),
                    ConstantColors.warning,
                  ],
                ),
              ),
            ),
          ),
          _buildHeroOrb(
            size: mediaQuery.width * 0.60,
            top: -mediaQuery.height * 0.04,
            right: -mediaQuery.width * 0.18,
            opacity: 0.08,
          ),
          _buildHeroOrb(
            size: mediaQuery.width * 0.34,
            top: mediaQuery.height * 0.16,
            left: -mediaQuery.width * 0.10,
            opacity: 0.10,
          ),
          SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(18, 14, 18, 28),
              child: Form(
                key: _emailFormKey,
                child: Builder(
                  builder: (formContext) {
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                              Row(
                                children: <Widget>[
                                  IconButton(
                                    padding: EdgeInsets.zero,
                                    constraints: BoxConstraints(
                                      minWidth: 36,
                                      minHeight: 36,
                                    ),
                                    icon: Icon(
                                      Icons.arrow_back,
                                      color: Colors.white,
                                    ),
                                    onPressed: () => SchedulerBinding.instance
                                        .addPostFrameCallback((_) {
                                      Navigator.pop(context);
                                    }),
                                  ),
                                ],
                              ),
                              SizedBox(height: 4),
                              Center(
                                child: Container(
                                  width: mediaQuery.width > 430
                                      ? 400
                                      : double.infinity,
                                  padding: const EdgeInsets.fromLTRB(
                                    24,
                                    18,
                                    24,
                                    24,
                                  ),
                                  decoration: BoxDecoration(
                                    color: Colors.white.withOpacity(0.04),
                                    borderRadius: BorderRadius.circular(28),
                                    border: Border.all(
                                      color: Colors.white.withOpacity(0.08),
                                    ),
                                  ),
                                  child: Column(
                                    children: <Widget>[
                                      Image.asset(
                                        'images/geomove_logo.png',
                                        height: 88,
                                        fit: BoxFit.contain,
                                      ),
                                      SizedBox(height: 18),
                                      Text(
                                        isRegisterMode
                                            ? 'Crea tu cuenta (Asesor)'
                                            : 'Hola de nuevo',
                                        style: TextStyle(
                                          color: ConstantColors.textGrey,
                                          fontSize: 14,
                                        ),
                                      ),
                                      SizedBox(height: 6),
                                      Text(
                                        isRegisterMode
                                            ? 'Crear usuario en Super_IA'
                                            : 'Bienvenido a Super_IA',
                                        textAlign: TextAlign.center,
                                        style: TextStyle(
                                          color: Colors.white,
                                          fontSize: 30,
                                          fontWeight: FontWeight.w800,
                                          height: 1.1,
                                        ),
                                      ),
                                      SizedBox(height: 12),
                                      Text(
                                        isRegisterMode
                                            ? 'Verifica tu correo y completa el registro. Tu cuenta quedará pendiente de aprobación.'
                                            : 'Ingresa con tu correo y tu clave para acceder a tu panel con mapa.',
                                        textAlign: TextAlign.center,
                                        style: TextStyle(
                                          color: Colors.white.withOpacity(0.70),
                                          fontSize: 14,
                                          height: 1.45,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                              SizedBox(height: 22),
                              Container(
                                width: mediaQuery.width > 430
                                    ? 400
                                    : double.infinity,
                                padding: const EdgeInsets.fromLTRB(
                                  18,
                                  22,
                                  18,
                                  22,
                                ),
                                decoration: BoxDecoration(
                                  color: ConstantColors.backgroundDark.withOpacity(0.24),
                                  borderRadius: BorderRadius.circular(28),
                                  border: Border.all(
                                    color: ConstantColors.borderColor
                                        .withOpacity(0.8),
                                  ),
                                ),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: <Widget>[
                                    Text(
                                      'Iniciar sesión',
                                      style: TextStyle(
                                        color: Colors.white,
                                        fontSize: 18,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                    SizedBox(height: 6),
                                    Text(
                                      'Correo + clave. Si ya estás aprobado, entrarás directo al mapa.',
                                      style: TextStyle(
                                        color: ConstantColors.textGrey,
                                        fontSize: 13,
                                        height: 1.4,
                                      ),
                                    ),
                                    SizedBox(height: 18),
                                    _buildInfoCard(
                                      padding: const EdgeInsets.symmetric(
                                        horizontal: 14,
                                        vertical: 12,
                                      ),
                                      child: Row(
                                        children: <Widget>[
                                          Container(
                                            width: 42,
                                            height: 42,
                                            decoration: BoxDecoration(
                                              gradient:
                                                  ConstantColors.yellowBlueGradient,
                                              borderRadius:
                                                  BorderRadius.circular(12),
                                            ),
                                            child: Icon(
                                              Icons.alternate_email,
                                              color: Colors.white,
                                            ),
                                          ),
                                          SizedBox(width: 12),
                                          Expanded(
                                            child: TextFormField(
                                              controller: emailTextController,
                                              keyboardType:
                                                  TextInputType.emailAddress,
                                              style: TextStyle(
                                                color: Colors.white,
                                                fontSize: 15,
                                              ),
                                              decoration: InputDecoration(
                                                hintText: 'ejemplo@correo.com',
                                                hintStyle: TextStyle(
                                                  color:
                                                      ConstantColors.textSubtle,
                                                ),
                                                border: InputBorder.none,
                                                isDense: true,
                                              ),
                                              validator: (value) {
                                                final email =
                                                    value?.trim() ?? '';
                                                if (email.isEmpty) {
                                                  return 'Ingresa tu correo';
                                                }
                                                final emailRegex = RegExp(
                                                  r'^[^@\s]+@[^@\s]+\.[^@\s]+$',
                                                );
                                                if (!emailRegex
                                                    .hasMatch(email)) {
                                                  return 'Ingresa un correo valido';
                                                }
                                                return null;
                                              },
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    SizedBox(height: 14),
                                    _buildInfoCard(
                                      padding: const EdgeInsets.symmetric(
                                        horizontal: 14,
                                        vertical: 12,
                                      ),
                                      child: Row(
                                        children: <Widget>[
                                          Container(
                                            width: 42,
                                            height: 42,
                                            decoration: BoxDecoration(
                                              gradient: ConstantColors.yellowBlueGradient,
                                              borderRadius: BorderRadius.circular(12),
                                            ),
                                            child: Icon(
                                              Icons.lock_outline,
                                              color: Colors.white,
                                            ),
                                          ),
                                          SizedBox(width: 12),
                                          Expanded(
                                            child: TextFormField(
                                              controller: passwordTextController,
                                              obscureText: _obscurePassword,
                                              style: TextStyle(
                                                color: Colors.white,
                                                fontSize: 15,
                                              ),
                                              decoration: InputDecoration(
                                                hintText: 'Clave',
                                                hintStyle: TextStyle(
                                                  color: ConstantColors.textSubtle,
                                                ),
                                                border: InputBorder.none,
                                                isDense: true,
                                                suffixIcon: IconButton(
                                                  icon: Icon(
                                                    _obscurePassword
                                                        ? Icons.visibility_off
                                                        : Icons.visibility,
                                                    color: ConstantColors.textSubtle,
                                                    size: 20,
                                                  ),
                                                  onPressed: () => setState(
                                                    () => _obscurePassword = !_obscurePassword,
                                                  ),
                                                ),
                                              ),
                                              validator: (value) {
                                                final pass = value ?? '';
                                                if (pass.trim().isEmpty) {
                                                  return 'Ingresa tu clave';
                                                }
                                                if (pass.length < 4) {
                                                  return 'Clave demasiado corta';
                                                }
                                                return null;
                                              },
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    SizedBox(height: 14),
                                    _buildInfoCard(
                                      padding: const EdgeInsets.all(14),
                                      child: Row(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: <Widget>[
                                          Icon(
                                            Icons.map_outlined,
                                            size: 18,
                                            color: ConstantColors.primaryBlue,
                                          ),
                                          SizedBox(width: 10),
                                          Expanded(
                                            child: Text(
                                              'Después de iniciar sesión, te llevaremos al mapa para empezar a gestionar tu trabajo.',
                                              style: TextStyle(
                                                color: ConstantColors.textGrey,
                                                fontSize: 12.8,
                                                height: 1.4,
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    SizedBox(height: 22),
                                    SizedBox(
                                      width: double.infinity,
                                      height: 54,
                                      child: DecoratedBox(
                                        decoration: BoxDecoration(
                                          gradient: ConstantColors.yellowBlueGradient,
                                          borderRadius:
                                              BorderRadius.circular(16),
                                          boxShadow: <BoxShadow>[
                                            BoxShadow(
                                              color: ConstantColors.primaryBlue
                                                  .withOpacity(0.28),
                                              blurRadius: 22,
                                              offset: Offset(0, 10),
                                            ),
                                          ],
                                        ),
                                        child: ElevatedButton(
                                          style: ElevatedButton.styleFrom(
                                            backgroundColor: Colors.transparent,
                                            elevation: 0,
                                            shape: RoundedRectangleBorder(
                                              borderRadius:
                                                  BorderRadius.circular(16),
                                            ),
                                          ),
                                          onPressed: _loading
                                              ? null
                                              : () => _handleLogin(formContext),
                                          child: _loading
                                              ? SizedBox(
                                                  width: 22,
                                                  height: 22,
                                                  child:
                                                      CircularProgressIndicator(
                                                    strokeWidth: 2.5,
                                                    valueColor:
                                                        AlwaysStoppedAnimation<
                                                            Color>(
                                                      Colors.white,
                                                    ),
                                                  ),
                                                )
                                              : Row(
                                                  mainAxisAlignment:
                                                      MainAxisAlignment.center,
                                                  children: <Widget>[
                                                    Icon(
                                                      Icons.login,
                                                      color: Colors.white,
                                                      size: 18,
                                                    ),
                                                    SizedBox(width: 8),
                                                    Text(
                                                      'Ingresar',
                                                      style: TextStyle(
                                                        color: Colors.white,
                                                        fontSize: 15,
                                                        fontWeight:
                                                            FontWeight.w700,
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
                              SizedBox(height: 24),
                              Row(
                                children: <Widget>[
                                  Expanded(
                                    child: Container(
                                      height: 1,
                                      color:
                                          ConstantColors.dividerColor,
                                    ),
                                  ),
                                  Padding(
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 10,
                                    ),
                                    child: Text(
                                      'acceso seguro',
                                      style: TextStyle(
                                        color: ConstantColors.textSubtle,
                                        fontSize: 12,
                                      ),
                                    ),
                                  ),
                                  Expanded(
                                    child: Container(
                                      height: 1,
                                      color:
                                          ConstantColors.dividerColor,
                                    ),
                                  ),
                                ],
                              ),
                              SizedBox(height: 18),
                              _buildInfoCard(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: <Widget>[
                                    Row(
                                      children: <Widget>[
                                        Icon(
                                          Icons.lock_open_outlined,
                                          color: ConstantColors.success,
                                          size: 18,
                                        ),
                                        SizedBox(width: 8),
                                        Text(
                                          'Acceso por credenciales',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                      ],
                                    ),
                                    SizedBox(height: 8),
                                    Text(
                                      'Tu cuenta debe estar aprobada por el supervisor para poder entrar. Si está pendiente o rechazada, verás un mensaje.',
                                      style: TextStyle(
                                        color: ConstantColors.textGrey,
                                        fontSize: 12.8,
                                        height: 1.45,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              SizedBox(height: 22),
                              Center(
                                child: Text(
                                  'Al continuar aceptas nuestros Terminos de Uso',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: ConstantColors.textSubtle,
                                    fontSize: 11.5,
                                  ),
                                ),
                              ),
                      ],
                    );
                  },
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
