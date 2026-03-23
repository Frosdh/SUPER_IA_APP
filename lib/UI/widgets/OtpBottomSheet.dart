import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/ProviderModels/VerificationModel.dart';
import 'package:fu_uber/UI/views/OsmMapScreen.dart';
import 'package:fu_uber/UI/views/RegisterScreen.dart';
import 'package:provider/provider.dart';

class OtpBottomSheet extends StatefulWidget {
  @override
  _OtpBottomSheetState createState() => _OtpBottomSheetState();
}

class _OtpBottomSheetState extends State<OtpBottomSheet> {
  final TextEditingController _otpController = TextEditingController();
  final GlobalKey<FormState> _otpFormKey = GlobalKey<FormState>();

  @override
  void dispose() {
    _otpController.dispose();
    super.dispose();
  }

  Future<void> _verifyOtp(VerificationModel verificationModel) async {
    if (!(_otpFormKey.currentState?.validate() ?? false)) {
      return;
    }

    verificationModel.setOtp(_otpController.text);
    final response = await verificationModel.oTPVerification();

    if (!mounted) return;

    if (response != 1) {
      final message = verificationModel.otpErrorMessage?.isNotEmpty == true
          ? verificationModel.otpErrorMessage
          : 'No se pudo verificar el codigo';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message)),
      );
      return;
    }

    final email = verificationModel.email;
    final localEmail = email ?? '';
    final userResponse = await verificationModel.checkUserByEmail(localEmail);

    if (!mounted) return;

    final exists = userResponse['status'] == 'success' &&
        userResponse['exists'] == true;

    if (exists) {
      await AuthPrefs.saveUserSession(
        telefono: userResponse['telefono'] ?? '',
        nombre: userResponse['nombre'] ?? '',
        email: userResponse['email'] ?? localEmail,
      );

      Navigator.pop(context);
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (_) => OsmMapScreen()),
        (_) => false,
      );
      return;
    }

    Navigator.pop(context);
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => ChangeNotifierProvider<VerificationModel>(
          create: (_) => VerificationModel()..setEmail(localEmail),
          child: RegisterScreen(verifiedEmail: localEmail),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final verificationModel = Provider.of<VerificationModel>(context);
    final viewInsets = MediaQuery.of(context).viewInsets;

    return Padding(
      padding: EdgeInsets.only(bottom: viewInsets.bottom),
      child: Container(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
          border: Border.all(color: ConstantColors.borderColor.withOpacity(0.5)),
        ),
        child: Form(
          key: _otpFormKey,
          child: Builder(
            builder: (formContext) {
              return Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Center(
                    child: Container(
                      width: 40,
                      height: 4,
                      decoration: BoxDecoration(
                        color: Colors.white24,
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                  ),
                  const SizedBox(height: 24),
                  const Text(
                    'Verifica tu correo',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 22,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'Ingresa el código de 6 dígitos enviado a ${verificationModel.email ?? ''}.',
                    style: TextStyle(
                      color: ConstantColors.textGrey,
                      fontSize: 14,
                    ),
                  ),
                  SizedBox(height: 22),
                  TextFormField(
                    controller: _otpController,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    decoration: InputDecoration(
                      hintText: '123456',
                      counterText: '',
                      prefixIcon: Icon(Icons.lock_outline),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(14),
                      ),
                    ),
                    validator: (value) {
                      final otp = value?.trim() ?? '';
                      if (otp.isEmpty) {
                        return 'Ingresa el codigo';
                      }
                      if (otp.length != 6) {
                        return 'El codigo debe tener 6 digitos';
                      }
                      return null;
                    },
                  ),
                  if (verificationModel.otpErrorMessage?.isNotEmpty == true)
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Text(
                        verificationModel.otpErrorMessage,
                        style: TextStyle(
                          color: Colors.redAccent,
                          fontSize: 13,
                        ),
                      ),
                    ),
                  SizedBox(height: 18),
                  SizedBox(
                    width: double.infinity,
                    height: 50,
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: ConstantColors.primaryViolet,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14),
                        ),
                      ),
                      onPressed: verificationModel.shopCircularLoaderOTP
                          ? null
                          : () => _verifyOtp(verificationModel),
                      child: verificationModel.shopCircularLoaderOTP
                          ? SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                valueColor:
                                    AlwaysStoppedAnimation<Color>(Colors.white),
                              ),
                            )
                          : Text(
                              'Confirmar codigo',
                              style: TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                    ),
                  ),
                  SizedBox(height: 10),
                  Align(
                    alignment: Alignment.center,
                    child: TextButton(
                      onPressed: () async {
                        final result = await verificationModel.resendOtp();
                        if (!mounted) return;
                        final message = result == 1
                            ? 'Te enviamos un nuevo codigo al correo'
                            : verificationModel.otpErrorMessage;
                        ScaffoldMessenger.of(formContext).showSnackBar(
                          SnackBar(content: Text(message)),
                        );
                      },
                      child: Text('Reenviar codigo'),
                    ),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}
