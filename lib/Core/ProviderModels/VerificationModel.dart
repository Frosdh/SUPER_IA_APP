import 'package:flutter/cupertino.dart';
import 'package:fu_uber/Core/Repository/Repository.dart';

class VerificationModel extends ChangeNotifier {
  final Repository _repository = Repository();

  String? phoneNumber;
  String? email;
  String? otp;
  String otpErrorMessage = '';
  String registerErrorMessage = '';
  bool? welcomeEmailSent;
  String welcomeEmailError = '';
  bool showCircularLoader = false;
  bool shopCircularLoaderOTP = false;

  void setPhoneNumber(String? newPhoneNumber) {
    phoneNumber = newPhoneNumber?.trim();
    notifyListeners();
  }

  void setEmail(String? newEmail) {
    email = newEmail?.trim().toLowerCase();
    notifyListeners();
  }

  void setOtp(String? newOtp) {
    otp = newOtp?.trim();
    otpErrorMessage = '';
    notifyListeners();
  }

  Future<int> handleEmailVerification() async {
    final localEmail = email;
    if (localEmail == null || localEmail.isEmpty) {
      otpErrorMessage = 'Ingresa un correo valido';
      notifyListeners();
      return 0;
    }

    showCircularLoader = true;
    otpErrorMessage = '';
    notifyListeners();

    final result = await _repository.sendEmailOtp(localEmail);

    showCircularLoader = false;
    if (result != 1) {
      otpErrorMessage = 'No se pudo enviar el codigo al correo';
    }
    notifyListeners();
    return result;
  }

  Future<int> oTPVerification() async {
    final localEmail = email;
    final localOtp = otp;
    if (localEmail == null || localEmail.isEmpty || localOtp == null || localOtp.isEmpty) {
      otpErrorMessage = 'Completa el correo y el codigo';
      notifyListeners();
      return 0;
    }

    shopCircularLoaderOTP = true;
    otpErrorMessage = '';
    notifyListeners();

    final result = await _repository.verifyEmailOtp(localEmail, localOtp);

    shopCircularLoaderOTP = false;
    if (result != 1) {
      otpErrorMessage = 'Codigo incorrecto o expirado';
    }
    notifyListeners();
    return result;
  }

  Future<Map<String, dynamic>> checkUserByEmail(String correo) async {
    return _repository.checkUserByEmail(correo);
  }

  Future<int> registerUser({
    required String nombre,
    required String telefono,
    required String email,
    required String tokenFcm,
  }) async {
    registerErrorMessage = '';
    welcomeEmailSent = null;
    welcomeEmailError = '';
    final response = await _repository.registerNewUser(
      nombre: nombre,
      telefono: telefono,
      email: email,
      tokenFcm: tokenFcm,
    );

    if (response.containsKey('welcome_email_sent')) {
      welcomeEmailSent = response['welcome_email_sent'] == true;
      welcomeEmailError = response['welcome_email_error']?.toString() ?? '';
    }

    if ((response['status'] ?? '') == 'success') {
      notifyListeners();
      return 1;
    }

    registerErrorMessage = response['message']?.toString() ??
        'No se pudo completar el registro';
    notifyListeners();
    return 0;
  }

  Future<int> resendOtp() async {
    return await handleEmailVerification();
  }
}
