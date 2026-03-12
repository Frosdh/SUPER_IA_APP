import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/ProviderModels/VerificationModel.dart';
import 'package:fu_uber/Core/Utils/LogUtils.dart';
import 'package:fu_uber/UI/widgets/OtpBottomSheet.dart';
import 'package:provider/provider.dart';

class SignInPage extends StatefulWidget {
  static const route = "/signinscreen";
  static const TAG = "SignInScreen";

  @override
  _SignInPageState createState() => _SignInPageState();
}

class _SignInPageState extends State<SignInPage>
    with SingleTickerProviderStateMixin {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final GlobalKey<FormState> _phoneFormKey = GlobalKey<FormState>();

  final phoneTextController = TextEditingController();
  AnimationController _animController;
  Animation<double> _fadeAnimation;
  Animation<Offset> _slideAnimation;

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: 700),
    );
    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _animController, curve: Curves.easeOut),
    );
    _slideAnimation =
        Tween<Offset>(begin: Offset(0, 0.3), end: Offset.zero).animate(
      CurvedAnimation(parent: _animController, curve: Curves.easeOut),
    );
    _animController.forward();
  }

  @override
  void dispose() {
    phoneTextController.dispose();
    _animController.dispose();
    super.dispose();
  }

  void _mostrarProximamente() {
    _scaffoldKey.currentState.showSnackBar(
      SnackBar(
        content: Row(
          children: [
            Icon(Icons.star, color: Colors.white, size: 18),
            SizedBox(width: 10),
            Text('Proximamente disponible'),
          ],
        ),
        backgroundColor: ConstantColors.primaryViolet,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        duration: Duration(seconds: 2),
      ),
    );
  }

  void _handleVerification(VerificationModel verificationModel) {
    if (_phoneFormKey.currentState.validate()) {
      verificationModel.setPhoneNumber(phoneTextController.text);
      verificationModel.updateCircularLoading(true);

      Future.delayed(Duration(seconds: 1)).then((_) {
        verificationModel.handlePhoneVerification().then((response) {
          ProjectLog.logIt(SignInPage.TAG, "PhoneVerification Response", response);
          verificationModel.updateCircularLoading(false);
          if (response == 1) {
            showModalBottomSheet(
              backgroundColor: Colors.transparent,
              isScrollControlled: true,
              context: context,
              builder: (context) => OtpBottomSheet(),
            );
          } else {
            _scaffoldKey.currentState.showSnackBar(
              SnackBar(
                content: Text(
                  verificationModel.otpErrorMessage != null &&
                          verificationModel.otpErrorMessage.isNotEmpty
                      ? verificationModel.otpErrorMessage
                      : 'Algo salio mal. Intenta nuevamente.',
                ),
                backgroundColor: ConstantColors.error,
              ),
            );
          }
        });
      });
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
                    Center(
                      child: Container(
                        width: 64,
                        height: 64,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: ConstantColors.primaryGradient,
                          boxShadow: [
                            BoxShadow(
                              color: ConstantColors.primaryViolet.withOpacity(0.4),
                              blurRadius: 24,
                              spreadRadius: 2,
                            ),
                          ],
                        ),
                        child: Icon(
                          Icons.directions_car_rounded,
                          color: Colors.white,
                          size: 32,
                        ),
                      ),
                    ),
                    SizedBox(height: 40),
                    Text(
                      'Bienvenido',
                      style: TextStyle(
                        color: ConstantColors.textWhite,
                        fontSize: 32,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    SizedBox(height: 8),
                    Text(
                      'Ingresa tu numero de telefono\npara continuar',
                      style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 15,
                        height: 1.5,
                      ),
                    ),
                    SizedBox(height: 48),
                    Text(
                      'Numero de telefono',
                      style: TextStyle(
                        color: ConstantColors.textGrey,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.5,
                      ),
                    ),
                    SizedBox(height: 10),
                    Form(
                      key: _phoneFormKey,
                      child: Container(
                        decoration: BoxDecoration(
                          color: ConstantColors.backgroundCard,
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(
                            color: ConstantColors.borderColor,
                            width: 1.5,
                          ),
                        ),
                        child: Row(
                          children: [
                            Container(
                              padding: EdgeInsets.symmetric(horizontal: 14, vertical: 16),
                              decoration: BoxDecoration(
                                border: Border(
                                  right: BorderSide(
                                    color: ConstantColors.borderColor,
                                    width: 1.5,
                                  ),
                                ),
                              ),
                              child: Row(
                                children: [
                                  Text('EC', style: TextStyle(fontSize: 14)),
                                  SizedBox(width: 6),
                                  Text(
                                    '+593',
                                    style: TextStyle(
                                      color: ConstantColors.textGrey,
                                      fontSize: 15,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            Expanded(
                              child: TextFormField(
                                controller: phoneTextController,
                                keyboardType: TextInputType.phone,
                                inputFormatters: [
                                  FilteringTextInputFormatter.digitsOnly,
                                  LengthLimitingTextInputFormatter(10),
                                ],
                                style: TextStyle(
                                  color: ConstantColors.textWhite,
                                  fontSize: 16,
                                  letterSpacing: 1.5,
                                  fontWeight: FontWeight.w500,
                                ),
                                decoration: InputDecoration(
                                  hintText: '0999 999 999',
                                  hintStyle: TextStyle(
                                    color: ConstantColors.textSubtle,
                                    fontSize: 15,
                                    letterSpacing: 1.0,
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
                                  if (value == null || value.isEmpty) {
                                    return 'Ingresa tu numero de telefono';
                                  }
                                  if (value.length < 9 || value.length > 10) {
                                    return 'Numero invalido (9-10 digitos)';
                                  }
                                  return null;
                                },
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    SizedBox(height: 12),
                    Text(
                      'Te enviaremos un codigo de verificacion via SMS',
                      style: TextStyle(
                        color: ConstantColors.textSubtle,
                        fontSize: 12,
                        height: 1.4,
                      ),
                    ),
                    SizedBox(height: 40),
                    verificationModel.showCircularLoader
                        ? Center(
                            child: Container(
                              width: 52,
                              height: 52,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                  ConstantColors.primaryViolet,
                                ),
                              ),
                            ),
                          )
                        : GestureDetector(
                            onTap: () => _handleVerification(verificationModel),
                            child: Container(
                              width: double.infinity,
                              height: 56,
                              decoration: BoxDecoration(
                                gradient: ConstantColors.buttonGradient,
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
                                    'Continuar',
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
                    SizedBox(height: 40),
                    Row(
                      children: [
                        Expanded(
                          child: Divider(
                            color: ConstantColors.dividerColor,
                            thickness: 1,
                          ),
                        ),
                        Padding(
                          padding: EdgeInsets.symmetric(horizontal: 16),
                          child: Text(
                            'o',
                            style: TextStyle(
                              color: ConstantColors.textSubtle,
                              fontSize: 13,
                            ),
                          ),
                        ),
                        Expanded(
                          child: Divider(
                            color: ConstantColors.dividerColor,
                            thickness: 1,
                          ),
                        ),
                      ],
                    ),
                    SizedBox(height: 24),
                    GestureDetector(
                      onTap: () => _handleVerification(verificationModel),
                      child: Container(
                        width: double.infinity,
                        height: 52,
                        decoration: BoxDecoration(
                          color: ConstantColors.accentWhatsApp.withOpacity(0.12),
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(
                            color: ConstantColors.accentWhatsApp.withOpacity(0.3),
                            width: 1.5,
                          ),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              Icons.chat_rounded,
                              color: ConstantColors.accentWhatsApp,
                              size: 20,
                            ),
                            SizedBox(width: 10),
                            Text(
                              'Verificar con SMS',
                              style: TextStyle(
                                color: ConstantColors.accentWhatsApp,
                                fontSize: 15,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    SizedBox(height: 20),
                    Row(
                      children: [
                        Expanded(
                          child: Divider(
                            color: ConstantColors.dividerColor,
                            thickness: 1,
                          ),
                        ),
                        Padding(
                          padding: EdgeInsets.symmetric(horizontal: 14),
                          child: Text(
                            'o inicia con',
                            style: TextStyle(
                              color: ConstantColors.textSubtle,
                              fontSize: 12,
                            ),
                          ),
                        ),
                        Expanded(
                          child: Divider(
                            color: ConstantColors.dividerColor,
                            thickness: 1,
                          ),
                        ),
                      ],
                    ),
                    SizedBox(height: 16),
                    Row(
                      children: [
                        Expanded(
                          child: GestureDetector(
                            onTap: _mostrarProximamente,
                            child: Container(
                              height: 52,
                              decoration: BoxDecoration(
                                color: ConstantColors.backgroundCard,
                                borderRadius: BorderRadius.circular(14),
                                border: Border.all(
                                  color: ConstantColors.borderColor,
                                  width: 1.5,
                                ),
                              ),
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Text(
                                    'G',
                                    style: TextStyle(
                                      fontSize: 20,
                                      fontWeight: FontWeight.w800,
                                      foreground: Paint()
                                        ..shader = LinearGradient(
                                          colors: [
                                            Color(0xFF4285F4),
                                            Color(0xFFEA4335),
                                          ],
                                        ).createShader(
                                          Rect.fromLTWH(0, 0, 20, 20),
                                        ),
                                    ),
                                  ),
                                  SizedBox(width: 8),
                                  Text(
                                    'Google',
                                    style: TextStyle(
                                      color: ConstantColors.textGrey,
                                      fontSize: 14,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                        SizedBox(width: 12),
                        Expanded(
                          child: GestureDetector(
                            onTap: _mostrarProximamente,
                            child: Container(
                              height: 52,
                              decoration: BoxDecoration(
                                color: ConstantColors.backgroundCard,
                                borderRadius: BorderRadius.circular(14),
                                border: Border.all(
                                  color: ConstantColors.borderColor,
                                  width: 1.5,
                                ),
                              ),
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Container(
                                    width: 22,
                                    height: 22,
                                    alignment: Alignment.center,
                                    decoration: BoxDecoration(
                                      color: Color(0xFF1877F2),
                                      shape: BoxShape.circle,
                                    ),
                                    child: Text(
                                      'f',
                                      style: TextStyle(
                                        color: Colors.white,
                                        fontSize: 14,
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                  ),
                                  SizedBox(width: 8),
                                  Text(
                                    'Facebook',
                                    style: TextStyle(
                                      color: ConstantColors.textGrey,
                                      fontSize: 14,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                    SizedBox(height: 24),
                    Center(
                      child: Text.rich(
                        TextSpan(
                          text: 'Al continuar, aceptas nuestros ',
                          style: TextStyle(
                            color: ConstantColors.textSubtle,
                            fontSize: 12,
                          ),
                          children: [
                            TextSpan(
                              text: 'Terminos de servicio',
                              style: TextStyle(
                                color: ConstantColors.primaryViolet,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            TextSpan(text: ' y '),
                            TextSpan(
                              text: 'Politica de privacidad',
                              style: TextStyle(
                                color: ConstantColors.primaryViolet,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                        textAlign: TextAlign.center,
                      ),
                    ),
                    SizedBox(height: 24),
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
