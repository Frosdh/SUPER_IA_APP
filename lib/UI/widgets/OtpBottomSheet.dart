import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/ProviderModels/VerificationModel.dart';
import 'package:fu_uber/Core/Services/PushNotificationService.dart';
import 'package:fu_uber/UI/views/LocationPermissionScreen.dart';
import 'package:fu_uber/UI/views/RegisterScreen.dart';
import 'package:provider/provider.dart';

class OtpBottomSheet extends StatefulWidget {
  @override
  _OtpBottomSheetState createState() => _OtpBottomSheetState();
}

class _OtpBottomSheetState extends State<OtpBottomSheet> {
  final List<TextEditingController> _controllers =
      List.generate(6, (_) => TextEditingController());
  final List<FocusNode> _focusNodes = List.generate(6, (_) => FocusNode());
  bool _hasError = false;

  @override
  void dispose() {
    for (var c in _controllers) c.dispose();
    for (var f in _focusNodes) f.dispose();
    super.dispose();
  }

  String get _otpCode => _controllers.map((c) => c.text).join();

  void _onDigitChanged(int index, String value) {
    if (value.isNotEmpty && index < 5) {
      _focusNodes[index + 1].requestFocus();
    }
    if (value.isEmpty && index > 0) {
      _focusNodes[index - 1].requestFocus();
    }
    setState(() {
      _hasError = false;
    });
  }

  Future<void> _verifyOtp(VerificationModel verificationModel) async {
    final otp = _otpCode;
    if (!verificationModel.otpAutoVerified && otp.length < 6) {
      setState(() => _hasError = true);
      return;
    }

    verificationModel.setOtp(otp);
    verificationModel.updateCircularLoadingOtp(true);

    final response = await verificationModel.oTPVerification();
    if (!mounted) return;
    verificationModel.updateCircularLoadingOtp(false);

    if (response != 1) {
      setState(() => _hasError = true);
      for (var c in _controllers) c.clear();
      _focusNodes[0].requestFocus();
      return;
    }

    final phone = verificationModel.phoneNumber ?? '';
    final userCheck = await verificationModel.checkUserByPhone(phone);
    if (!mounted) return;

    final checkSuccess = userCheck["success"] == true;
    final exists = userCheck["exists"] == true;

    if (checkSuccess && exists) {
      await AuthPrefs.saveUserSession(
        nombre: userCheck["nombre"] ?? '',
        telefono: phone,
        email: userCheck["email"] ?? '',
      );
      await PushNotificationService.syncTokenWithBackend(force: true);
      if (!mounted) return;
      Navigator.pushReplacementNamed(context, LocationPermissionScreen.route);
      return;
    }

    if (!checkSuccess) {
      final sesionValida = await AuthPrefs.hasValidSession();
      final telefonoGuardado = await AuthPrefs.getUserPhone();
      if (sesionValida && telefonoGuardado == phone) {
        if (!mounted) return;
        Navigator.pushReplacementNamed(context, LocationPermissionScreen.route);
        return;
      }
    }

    if (!mounted) return;
    Navigator.pushReplacementNamed(context, RegisterScreen.route);
  }

  Future<void> _resendOtp(VerificationModel verificationModel) async {
    verificationModel.updateCircularLoadingOtp(true);
    final response = await verificationModel.resendOtp();
    if (!mounted) return;
    verificationModel.updateCircularLoadingOtp(false);

    if (response == 1) {
      for (var c in _controllers) c.clear();
      setState(() => _hasError = false);
      _focusNodes[0].requestFocus();
      Scaffold.of(context).showSnackBar(
        SnackBar(
          content: Text('Codigo reenviado correctamente'),
          backgroundColor: ConstantColors.primaryViolet,
        ),
      );
      return;
    }

    setState(() => _hasError = true);
  }

  @override
  Widget build(BuildContext context) {
    final verificationModel = Provider.of<VerificationModel>(context);
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;

    return Container(
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      padding: EdgeInsets.fromLTRB(28, 16, 28, 32 + bottomInset),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: ConstantColors.borderColor,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
          SizedBox(height: 24),
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              color: ConstantColors.accentWhatsApp.withOpacity(0.15),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                color: ConstantColors.accentWhatsApp.withOpacity(0.3),
              ),
            ),
            child: Icon(
              Icons.chat_rounded,
              color: ConstantColors.accentWhatsApp,
              size: 28,
            ),
          ),
          SizedBox(height: 20),
          Text(
            'Verificacion',
            style: TextStyle(
              color: ConstantColors.textWhite,
              fontSize: 24,
              fontWeight: FontWeight.w800,
            ),
          ),
          SizedBox(height: 8),
          RichText(
            text: TextSpan(
              text: 'Enviamos un codigo a ',
              style: TextStyle(
                color: ConstantColors.textGrey,
                fontSize: 14,
                height: 1.5,
              ),
              children: [
                TextSpan(
                  text: verificationModel.phoneNumber ?? 'tu numero',
                  style: TextStyle(
                    color: ConstantColors.primaryViolet,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
          SizedBox(height: 32),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: List.generate(6, (index) => _buildOtpBox(index)),
          ),
          if (_hasError) ...[
            SizedBox(height: 12),
            Text(
              verificationModel.otpErrorMessage.isNotEmpty
                  ? verificationModel.otpErrorMessage
                  : 'Codigo incorrecto. Intentalo de nuevo.',
              style: TextStyle(
                color: ConstantColors.error,
                fontSize: 12,
              ),
            ),
          ],
          SizedBox(height: 32),
          verificationModel.shopCircularLoaderOTP
              ? Center(
                  child: CircularProgressIndicator(
                    strokeWidth: 2.5,
                    valueColor: AlwaysStoppedAnimation<Color>(
                      ConstantColors.primaryViolet,
                    ),
                  ),
                )
              : GestureDetector(
                  onTap: () => _verifyOtp(verificationModel),
                  child: Container(
                    width: double.infinity,
                    height: 56,
                    decoration: BoxDecoration(
                      gradient: ConstantColors.buttonGradient,
                      borderRadius: BorderRadius.circular(16),
                      boxShadow: [
                        BoxShadow(
                          color:
                              ConstantColors.primaryViolet.withOpacity(0.4),
                          blurRadius: 20,
                          offset: Offset(0, 8),
                        ),
                      ],
                    ),
                    child: Center(
                      child: Text(
                        'Verificar codigo',
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
          SizedBox(height: 16),
          Center(
            child: GestureDetector(
              onTap: () => _resendOtp(verificationModel),
              child: RichText(
                text: TextSpan(
                  text: 'No recibiste el codigo? ',
                  style: TextStyle(
                    color: ConstantColors.textSubtle,
                    fontSize: 13,
                  ),
                  children: [
                    TextSpan(
                      text: 'Reenviar',
                      style: TextStyle(
                        color: ConstantColors.primaryViolet,
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
    );
  }

  Widget _buildOtpBox(int index) {
    return Container(
      width: 48,
      height: 64,
      decoration: BoxDecoration(
        color: ConstantColors.backgroundLight,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: _hasError
              ? ConstantColors.error
              : _focusNodes[index].hasFocus
                  ? ConstantColors.primaryViolet
                  : ConstantColors.borderColor,
          width: 1.5,
        ),
      ),
      child: TextFormField(
        controller: _controllers[index],
        focusNode: _focusNodes[index],
        textAlign: TextAlign.center,
        keyboardType: TextInputType.number,
        inputFormatters: [
          FilteringTextInputFormatter.digitsOnly,
          LengthLimitingTextInputFormatter(1),
        ],
        style: TextStyle(
          color: ConstantColors.textWhite,
          fontSize: 24,
          fontWeight: FontWeight.w700,
        ),
        decoration: InputDecoration(
          border: InputBorder.none,
          counterText: '',
        ),
        onChanged: (value) => _onDigitChanged(index, value),
      ),
    );
  }
}
