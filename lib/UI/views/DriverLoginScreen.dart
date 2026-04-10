import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Networking/ApiProvider.dart';
import 'package:super_ia/Core/Preferences/DriverPrefs.dart';
import 'package:super_ia/UI/views/DriverHomeScreen.dart';
import 'package:super_ia/UI/views/DriverRegistrationScreen.dart';
import 'package:super_ia/UI/views/DriverStep1Screen.dart';
import 'package:super_ia/UI/views/DriverRevisionScreen.dart';

class DriverLoginScreen extends StatefulWidget {
  static const String route = '/driver_login';

  @override
  _DriverLoginScreenState createState() => _DriverLoginScreenState();
}

class _DriverLoginScreenState extends State<DriverLoginScreen> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final TextEditingController _identificadorController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();
  final ApiProvider _apiProvider = ApiProvider();

  bool _isLoading = false;
  bool _showPassword = false;

  @override
  void dispose() {
    _identificadorController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _showMessage(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message)),
    );
  }

  Future<void> _loginDriver() async {
    if (!(_formKey.currentState?.validate() ?? false)) {
      return;
    }

    setState(() => _isLoading = true);

    final response = await _apiProvider.loginDriver(
      identificador: _identificadorController.text.trim(),
      password: _passwordController.text.trim(),
    );

    if (!mounted) {
      return;
    }

    setState(() => _isLoading = false);

    if (response['status'] == 'pending') {
      final conductor = (response['conductor'] as Map?)?.cast<String, dynamic>() ?? <String, dynamic>{};
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(
          builder: (_) => DriverRevisionScreen(
            conductorId: conductor['id'] as int,
          ),
        ),
      );
      return;
    }

    if (response['status'] != 'success') {
      _showMessage(response['message']?.toString() ?? 'No se pudo iniciar sesion');
      return;
    }

    final conductor = (response['conductor'] as Map?)?.cast<String, dynamic>() ?? <String, dynamic>{};
    await DriverPrefs.saveDriverSession(
      id: conductor['id'] as int,
      nombre: conductor['nombre']?.toString() ?? '',
      telefono: conductor['telefono']?.toString() ?? '',
      cedula: conductor['cedula']?.toString() ?? '',
      estado: conductor['estado']?.toString() ?? '',
    );

    Navigator.pushReplacementNamed(context, DriverHomeScreen.route);
  }

  Widget _buildHeroOrb({
    required double size,
    required double top,
    double left = 0.0,
    double right = 0.0,
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
    final mediaQuery = MediaQuery.of(context).size;

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
        children: <Widget>[
          Container(color: ConstantColors.backgroundDark),
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: Container(
              height: mediaQuery.height * 0.38,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: <Color>[
                    Color(0xFF0F0C29),
                    Color(0xFF302B63),
                    Color(0xFF24243E),
                  ],
                ),
              ),
            ),
          ),
          _buildHeroOrb(
            size: mediaQuery.width * 0.54,
            top: -mediaQuery.height * 0.02,
            right: -mediaQuery.width * 0.14,
            opacity: 0.08,
          ),
          _buildHeroOrb(
            size: mediaQuery.width * 0.26,
            top: mediaQuery.height * 0.13,
            left: -mediaQuery.width * 0.08,
            opacity: 0.10,
          ),
          SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(18, 14, 18, 28),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    IconButton(
                      padding: EdgeInsets.zero,
                      constraints: BoxConstraints(minWidth: 36, minHeight: 36),
                      icon: Icon(Icons.arrow_back, color: Colors.white),
                      onPressed: () => SchedulerBinding.instance.addPostFrameCallback((_) {
                        Navigator.pop(context);
                      }),
                    ),
                    SizedBox(height: 4),
                    Center(
                      child: Container(
                        width: mediaQuery.width > 430 ? 400 : double.infinity,
                        padding: const EdgeInsets.fromLTRB(24, 18, 24, 24),
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
                              'Panel conductor',
                              style: TextStyle(
                                color: ConstantColors.textGrey,
                                fontSize: 14,
                              ),
                            ),
                            SizedBox(height: 6),
                            Text(
                              'Ingresa para empezar\na conducir',
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
                              'Usa tu telefono o cedula y tu clave para entrar al panel del conductor.',
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
                      width: mediaQuery.width > 430 ? 400 : double.infinity,
                      padding: const EdgeInsets.fromLTRB(18, 22, 18, 22),
                      decoration: BoxDecoration(
                        color: Colors.black.withOpacity(0.24),
                        borderRadius: BorderRadius.circular(28),
                        border: Border.all(
                          color: ConstantColors.borderColor.withOpacity(0.8),
                        ),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Text(
                            'Acceso de conductores',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 18,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          SizedBox(height: 6),
                          Text(
                            'Ingresa con los datos que ya fueron registrados y aprobados.',
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
                              vertical: 4,
                            ),
                            child: TextFormField(
                              controller: _identificadorController,
                              style: TextStyle(
                                color: Colors.white,
                              ),
                              decoration: InputDecoration(
                                prefixIcon: Icon(
                                  Icons.person_outline,
                                  color: ConstantColors.primaryBlue,
                                ),
                                labelText: 'Telefono o cedula',
                                hintText: 'Ej: 0999999999 o 0102030405',
                              ),
                              validator: (value) {
                                if ((value ?? '').trim().isEmpty) {
                                  return 'Ingresa tu telefono o cedula';
                                }
                                return null;
                              },
                            ),
                          ),
                          SizedBox(height: 14),
                          _buildInfoCard(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 14,
                              vertical: 4,
                            ),
                            child: TextFormField(
                              controller: _passwordController,
                              obscureText: !_showPassword,
                              style: TextStyle(color: Colors.white),
                              decoration: InputDecoration(
                                prefixIcon: Icon(
                                  Icons.lock_outline,
                                  color: ConstantColors.primaryViolet,
                                ),
                                labelText: 'Contraseña',
                                hintText: 'Ingresa tu clave',
                                suffixIcon: IconButton(
                                  icon: Icon(
                                    _showPassword
                                        ? Icons.visibility_off_outlined
                                        : Icons.visibility_outlined,
                                    color: ConstantColors.textGrey,
                                    size: 20,
                                  ),
                                  onPressed: () => setState(
                                    () => _showPassword = !_showPassword,
                                  ),
                                ),
                              ),
                              validator: (value) {
                                if ((value ?? '').trim().isEmpty) {
                                  return 'Ingresa tu contraseña';
                                }
                                return null;
                              },
                            ),
                          ),
                          SizedBox(height: 16),
                          _buildInfoCard(
                            padding: const EdgeInsets.all(14),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: <Widget>[
                                Icon(
                                  Icons.info_outline,
                                  size: 18,
                                  color: ConstantColors.primaryBlue,
                                ),
                                SizedBox(width: 10),
                                Expanded(
                                  child: Text(
                                    'En esta primera fase el acceso es para conductores ya registrados y verificados.',
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
                                gradient: ConstantColors.buttonGradient,
                                borderRadius: BorderRadius.circular(16),
                                boxShadow: <BoxShadow>[
                                  BoxShadow(
                                    color: ConstantColors.primaryViolet
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
                                    borderRadius: BorderRadius.circular(16),
                                  ),
                                ),
                                onPressed: _isLoading ? null : _loginDriver,
                                child: _isLoading
                                    ? SizedBox(
                                        width: 22,
                                        height: 22,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2.5,
                                          valueColor:
                                              AlwaysStoppedAnimation<Color>(
                                            Colors.white,
                                          ),
                                        ),
                                      )
                                    : Row(
                                        mainAxisAlignment:
                                            MainAxisAlignment.center,
                                        children: <Widget>[
                                          Icon(
                                            Icons.directions_car_outlined,
                                            color: Colors.white,
                                            size: 18,
                                          ),
                                          SizedBox(width: 8),
                                          Text(
                                            'Entrar como conductor',
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

                    // ── Link de registro ──────────────────────────
                    SizedBox(height: 20),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          '¿No tienes cuenta? ',
                          style: TextStyle(
                            color: ConstantColors.textGrey,
                            fontSize: 14,
                          ),
                        ),
                        GestureDetector(
                          onTap: () => Navigator.pushNamed(
                            context,
                            DriverStep1Screen.route,
                          ),
                          child: Text(
                            'Regístrate aquí',
                            style: TextStyle(
                              color: ConstantColors.primaryBlue,
                              fontSize: 14,
                              fontWeight: FontWeight.w700,
                              decoration: TextDecoration.underline,
                              decorationColor: ConstantColors.primaryBlue,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
