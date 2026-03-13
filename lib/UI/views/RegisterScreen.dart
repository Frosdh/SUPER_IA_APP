import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/ProviderModels/VerificationModel.dart';
import 'package:fu_uber/Core/Services/PushNotificationService.dart';
import 'package:fu_uber/UI/views/OsmMapScreen.dart';
import 'package:provider/provider.dart';

class RegisterScreen extends StatefulWidget {
  static const String route = '/register';
  final String verifiedEmail;

  const RegisterScreen({Key key, this.verifiedEmail = ''}) : super(key: key);

  @override
  _RegisterScreenState createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _phoneController = TextEditingController();
  bool _saving = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final verificationModel = Provider.of<VerificationModel>(context, listen: false);
    final resolvedEmail = widget.verifiedEmail?.isNotEmpty == true
        ? widget.verifiedEmail
        : (verificationModel.email ?? '');
    if (_emailController.text.isEmpty && resolvedEmail.isNotEmpty) {
      _emailController.text = resolvedEmail;
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _phoneController.dispose();
    super.dispose();
  }

  Future<void> _registerUser(VerificationModel verificationModel) async {
    if (!_formKey.currentState.validate()) {
      return;
    }

    setState(() {
      _saving = true;
    });

    final pushService = PushNotificationService();
    String tokenFcm = '';
    try {
      tokenFcm = await pushService.getToken() ?? '';
    } catch (_) {}

    final response = await verificationModel.registerUser(
      nombre: _nameController.text.trim(),
      telefono: _phoneController.text.trim(),
      email: _emailController.text.trim(),
      tokenFcm: tokenFcm,
    );

    if (!mounted) return;

    setState(() {
      _saving = false;
    });

    if (response != 1) {
      _scaffoldKey.currentState.showSnackBar(
        SnackBar(
          content: Text(
            verificationModel.registerErrorMessage?.isNotEmpty == true
                ? verificationModel.registerErrorMessage
                : 'No se pudo completar el registro',
          ),
        ),
      );
      return;
    }

    if (verificationModel.welcomeEmailSent == false) {
      _scaffoldKey.currentState.showSnackBar(
        SnackBar(
          content: Text(
            verificationModel.welcomeEmailError?.isNotEmpty == true
                ? 'Cuenta creada, pero fallo el correo de bienvenida: ${verificationModel.welcomeEmailError}'
                : 'Cuenta creada, pero no se pudo enviar el correo de bienvenida',
          ),
        ),
      );
    }

    await AuthPrefs.saveUserSession(
      telefono: _phoneController.text.trim(),
      nombre: _nameController.text.trim(),
      email: _emailController.text.trim(),
    );

    Navigator.pushAndRemoveUntil(
      context,
      MaterialPageRoute(builder: (_) => OsmMapScreen()),
      (_) => false,
    );
  }

  Widget _buildProgressBar(bool active) {
    return Expanded(
      child: Container(
        height: 4,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(4),
          gradient: active ? ConstantColors.buttonGradient : null,
          color: active ? null : ConstantColors.dividerColor,
        ),
      ),
    );
  }

  Widget _buildDarkField({
    TextEditingController controller,
    String label,
    String hintText,
    IconData icon,
    TextInputType keyboardType,
    bool readOnly = false,
    FormFieldValidator<String> validator,
  }) {
    return TextFormField(
      controller: controller,
      keyboardType: keyboardType,
      readOnly: readOnly,
      style: TextStyle(
        color: Colors.white,
        fontSize: 15,
      ),
      decoration: InputDecoration(
        labelText: label,
        hintText: hintText,
        labelStyle: TextStyle(color: ConstantColors.textGrey),
        hintStyle: TextStyle(color: ConstantColors.textSubtle),
        prefixIcon: Icon(icon, color: ConstantColors.primaryViolet),
        filled: true,
        fillColor: ConstantColors.backgroundCard,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: ConstantColors.borderColor),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(
            color: ConstantColors.primaryViolet,
            width: 1.4,
          ),
        ),
      ),
      validator: validator,
    );
  }

  @override
  Widget build(BuildContext context) {
    final Size mediaQuery = MediaQuery.of(context).size;

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: ConstantColors.backgroundDark,
      body: Consumer<VerificationModel>(
        builder: (_, verificationModel, __) {
          return Stack(
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
                        Color(0xFF1A1535),
                        ConstantColors.backgroundDark,
                      ],
                    ),
                  ),
                ),
              ),
              Positioned(
                top: -mediaQuery.height * 0.05,
                right: -mediaQuery.width * 0.10,
                child: Container(
                  width: mediaQuery.width * 0.40,
                  height: mediaQuery.width * 0.40,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Colors.white.withOpacity(0.08),
                  ),
                ),
              ),
              SafeArea(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(18, 14, 18, 28),
                  child: Form(
                    key: _formKey,
                    child: Column(
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
                              onPressed: () => Navigator.pop(context),
                            ),
                          ],
                        ),
                        SizedBox(height: 14),
                        Row(
                          children: <Widget>[
                            _buildProgressBar(true),
                            SizedBox(width: 6),
                            _buildProgressBar(true),
                            SizedBox(width: 6),
                            _buildProgressBar(false),
                          ],
                        ),
                        SizedBox(height: 26),
                        Center(
                          child: Container(
                            width: 88,
                            height: 88,
                            decoration: BoxDecoration(
                              gradient: ConstantColors.buttonGradient,
                              borderRadius: BorderRadius.circular(28),
                              boxShadow: <BoxShadow>[
                                BoxShadow(
                                  color: ConstantColors.primaryViolet
                                      .withOpacity(0.25),
                                  blurRadius: 26,
                                  offset: Offset(0, 12),
                                ),
                              ],
                            ),
                            child: Icon(
                              Icons.person_outline,
                              color: Colors.white,
                              size: 42,
                            ),
                          ),
                        ),
                        SizedBox(height: 22),
                        Center(
                          child: Text(
                            'Termina tu perfil',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 28,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                        SizedBox(height: 8),
                        Center(
                          child: Text(
                            'Tu correo ya fue verificado. Ahora te pediremos solo lo esencial para activar tu cuenta.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: ConstantColors.textGrey,
                              fontSize: 14,
                              height: 1.4,
                            ),
                          ),
                        ),
                        SizedBox(height: 24),
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            color: Color(0xFF10261A),
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(
                              color: ConstantColors.success.withOpacity(0.35),
                            ),
                          ),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: <Widget>[
                              Icon(
                                Icons.mark_email_read_outlined,
                                color: ConstantColors.success,
                              ),
                              SizedBox(width: 12),
                              Expanded(
                                child: Text(
                                  'Correo verificado\n${verificationModel.email ?? _emailController.text}',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w600,
                                    height: 1.35,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                        SizedBox(height: 18),
                        _buildDarkField(
                          controller: _nameController,
                          label: 'Nombre completo',
                          hintText: 'Como quieres que te llamen',
                          icon: Icons.person_outline,
                          validator: (value) {
                            if ((value?.trim() ?? '').isEmpty) {
                              return 'Ingresa tu nombre';
                            }
                            return null;
                          },
                        ),
                        SizedBox(height: 16),
                        _buildDarkField(
                          controller: _phoneController,
                          label: 'Numero de telefono',
                          hintText: '09XXXXXXXX',
                          icon: Icons.phone_iphone_outlined,
                          keyboardType: TextInputType.phone,
                          validator: (value) {
                            final phone = value?.trim() ?? '';
                            if (phone.isEmpty) {
                              return 'Ingresa tu numero';
                            }
                            if (phone.length < 10) {
                              return 'Ingresa un numero valido';
                            }
                            return null;
                          },
                        ),
                        SizedBox(height: 16),
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            color: Colors.black.withOpacity(0.18),
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(
                              color: ConstantColors.dividerColor,
                            ),
                          ),
                          child: Text(
                            'El correo ya quedo confirmado en el paso anterior. Aqui solo completas tu nombre y telefono para activar la cuenta.',
                            style: TextStyle(
                              color: ConstantColors.textGrey,
                              fontSize: 12.8,
                              height: 1.45,
                            ),
                          ),
                        ),
                        SizedBox(height: 24),
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
                            child: RaisedButton(
                              color: Colors.transparent,
                              elevation: 0,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(16),
                              ),
                              child: _saving
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
                                        Text(
                                          'Crear cuenta',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontWeight: FontWeight.w700,
                                            fontSize: 15,
                                          ),
                                        ),
                                        SizedBox(width: 8),
                                        Icon(
                                          Icons.arrow_forward,
                                          color: Colors.white,
                                          size: 18,
                                        ),
                                      ],
                                    ),
                              onPressed: _saving
                                  ? null
                                  : () => _registerUser(verificationModel),
                            ),
                          ),
                        ),
                        SizedBox(height: 16),
                        Center(
                          child: Text(
                            'Paso final para entrar a la app',
                            style: TextStyle(
                              color: ConstantColors.textSubtle,
                              fontSize: 11.5,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
