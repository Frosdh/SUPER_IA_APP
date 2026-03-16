import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/ProviderModels/VerificationModel.dart';
import 'package:fu_uber/UI/views/DriverLoginScreen.dart';
import 'package:fu_uber/UI/widgets/OtpBottomSheet.dart';
import 'package:provider/provider.dart';

class SignInPage extends StatefulWidget {
  static const String route = '/signin';

  @override
  _SignInPageState createState() => _SignInPageState();
}

class _SignInPageState extends State<SignInPage> {
  final GlobalKey<FormState> _emailFormKey = GlobalKey<FormState>();
  final TextEditingController emailTextController = TextEditingController();

  @override
  void dispose() {
    emailTextController.dispose();
    super.dispose();
  }

  Future<void> _handleVerification(
    BuildContext context,
    VerificationModel verificationModel,
  ) async {
    if (!(_emailFormKey.currentState?.validate() ?? false)) {
      return;
    }

    verificationModel.setEmail(emailTextController.text);
    final response = await verificationModel.handleEmailVerification();

    if (!mounted) return;

    if (response == 1) {
      showModalBottomSheet(
        isScrollControlled: true,
        context: context,
        backgroundColor: Colors.transparent,
        builder: (_) => ChangeNotifierProvider<VerificationModel>.value(
          value: verificationModel,
          child: OtpBottomSheet(),
        ),
      );
    } else {
      final errorMessage = verificationModel.otpErrorMessage?.isNotEmpty == true
          ? verificationModel.otpErrorMessage
          : 'No se pudo enviar el codigo al correo';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage)),
      );
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

    return ChangeNotifierProvider<VerificationModel>(
      create: (_) => VerificationModel(),
      child: Scaffold(
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
                    height: mediaQuery.height * 0.42,
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
                                        'Hola de nuevo',
                                        style: TextStyle(
                                          color: ConstantColors.textGrey,
                                          fontSize: 14,
                                        ),
                                      ),
                                      SizedBox(height: 6),
                                      Text(
                                        'Bienvenido a GeoMove',
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
                                        'Ingresa con tu correo y recibe un codigo para entrar de forma rapida y segura.',
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
                                  color: Colors.black.withOpacity(0.24),
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
                                      'Acceso por correo',
                                      style: TextStyle(
                                        color: Colors.white,
                                        fontSize: 18,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                    SizedBox(height: 6),
                                    Text(
                                      'Usaremos un codigo temporal para validar tu ingreso.',
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
                                                  ConstantColors.buttonGradient,
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
                                      padding: const EdgeInsets.all(14),
                                      child: Row(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: <Widget>[
                                          Icon(
                                            Icons.phone_iphone_outlined,
                                            size: 18,
                                            color: ConstantColors.primaryBlue,
                                          ),
                                          SizedBox(width: 10),
                                          Expanded(
                                            child: Text(
                                              'Tu telefono se registrara despues en tu perfil, pero el acceso principal se validara con correo.',
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
                                          borderRadius:
                                              BorderRadius.circular(16),
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
                                              borderRadius:
                                                  BorderRadius.circular(16),
                                            ),
                                          ),
                                          onPressed: verificationModel
                                                  .showCircularLoader
                                              ? null
                                              : () => _handleVerification(
                                                    formContext,
                                                    verificationModel,
                                                  ),
                                          child: verificationModel
                                                  .showCircularLoader
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
                                                      Icons.mail_outline,
                                                      color: Colors.white,
                                                      size: 18,
                                                    ),
                                                    SizedBox(width: 8),
                                                    Text(
                                                      'Recibir codigo por correo',
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
                                          Icons.verified_user_outlined,
                                          color: ConstantColors.success,
                                          size: 18,
                                        ),
                                        SizedBox(width: 8),
                                        Text(
                                          'Ingreso sin contrasena',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                      ],
                                    ),
                                    SizedBox(height: 8),
                                    Text(
                                      'Solo necesitas tu correo y el codigo temporal. El registro se completa despues con tus datos personales.',
                                      style: TextStyle(
                                        color: ConstantColors.textGrey,
                                        fontSize: 12.8,
                                        height: 1.45,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              SizedBox(height: 14),
                              InkWell(
                                borderRadius: BorderRadius.circular(18),
                                onTap: () {
                                  Navigator.pushNamed(
                                    context,
                                    DriverLoginScreen.route,
                                  );
                                },
                                child: _buildInfoCard(
                                  child: Row(
                                    children: <Widget>[
                                      Container(
                                        width: 42,
                                        height: 42,
                                        decoration: BoxDecoration(
                                          color: ConstantColors.primaryBlue.withOpacity(0.16),
                                          borderRadius: BorderRadius.circular(12),
                                        ),
                                        child: Icon(
                                          Icons.directions_car,
                                          color: ConstantColors.primaryBlue,
                                        ),
                                      ),
                                      SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: <Widget>[
                                            Text(
                                              'Soy conductor',
                                              style: TextStyle(
                                                color: Colors.white,
                                                fontWeight: FontWeight.w700,
                                              ),
                                            ),
                                            SizedBox(height: 4),
                                            Text(
                                              'Entrar al panel del conductor y cambiar tu disponibilidad.',
                                              style: TextStyle(
                                                color: ConstantColors.textGrey,
                                                fontSize: 12.8,
                                                height: 1.4,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                      Icon(
                                        Icons.arrow_forward_ios,
                                        color: ConstantColors.textSubtle,
                                        size: 16,
                                      ),
                                    ],
                                  ),
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
            );
          },
        ),
      ),
    );
  }
}
