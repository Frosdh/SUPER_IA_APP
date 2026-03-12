import 'dart:async';

import 'package:firebase_auth/firebase_auth.dart';
import 'package:flutter/cupertino.dart';
import 'package:fu_uber/Core/Enums/Enums.dart';
import 'package:fu_uber/Core/Repository/Repository.dart';

class VerificationModel extends ChangeNotifier {
  String phoneNumber;
  String otp;
  String verificationId;
  int forceResendingToken;
  String otpErrorMessage = '';
  bool otpAutoVerified = false;

  bool ifOtpHasError = true;
  bool showCircularLoader = false;
  bool shopCircularLoaderOTP = false;

  updateCircularLoading(bool b) {
    showCircularLoader = b;
    notifyListeners();
  }

  TextEditingController oTPTextController = TextEditingController();

  setPhoneNumber(String phone) {
    phoneNumber = phone;
    notifyListeners();
  }

  setOtp(String otpp) {
    otp = otpp;
    notifyListeners();
  }

  String get phoneNumberE164 {
    final raw = (phoneNumber ?? '').replaceAll(RegExp(r'[^0-9]'), '');
    if (raw.isEmpty) return '';
    if (raw.startsWith('593')) return '+$raw';
    if (raw.length == 10 && raw.startsWith('0')) {
      return '+593${raw.substring(1)}';
    }
    if (raw.length == 9) {
      return '+593$raw';
    }
    return '+$raw';
  }

  void _setOtpError(String message) {
    otpErrorMessage = message ?? '';
    notifyListeners();
  }

  Future<AuthStatus> isUserAlreadyAuthenticated() async {
    return await Repository.isUserAlreadyAuthenticated();
  }

  Future<int> handlePhoneVerification() async {
    final numero = phoneNumberE164;
    if (numero.isEmpty) {
      _setOtpError('Ingresa un numero valido');
      return 0;
    }

    otpAutoVerified = false;
    verificationId = null;
    _setOtpError('');

    try {
      final completer = Completer<int>();
      final auth = FirebaseAuth.instance;

      await auth.verifyPhoneNumber(
        phoneNumber: numero,
        timeout: const Duration(seconds: 60),
        forceResendingToken: forceResendingToken,
        verificationCompleted: (AuthCredential credential) async {
          try {
            await auth.signInWithCredential(credential);
            otpAutoVerified = true;
            if (!completer.isCompleted) {
              completer.complete(1);
            }
            notifyListeners();
          } catch (e) {
            print('FirebaseAuth verificationCompleted error: $e');
            _setOtpError('No se pudo verificar automaticamente');
            if (!completer.isCompleted) {
              completer.complete(0);
            }
          }
        },
        verificationFailed: (FirebaseAuthException e) {
          print('FirebaseAuth verificationFailed: ${e.code} ${e.message}');
          String message = 'No se pudo enviar el codigo';
          if (e.code == 'invalid-phone-number') {
            message = 'Numero de telefono invalido';
          } else if (e.code == 'too-many-requests') {
            message = 'Demasiados intentos. Intenta mas tarde';
          } else if (e.message != null && e.message.isNotEmpty) {
            message = e.message;
          }
          _setOtpError(message);
          if (!completer.isCompleted) {
            completer.complete(0);
          }
        },
        codeSent: (String verId, [int resendToken]) {
          verificationId = verId;
          forceResendingToken = resendToken;
          _setOtpError('');
          if (!completer.isCompleted) {
            completer.complete(1);
          }
        },
        codeAutoRetrievalTimeout: (String verId) {
          verificationId = verId;
          notifyListeners();
        },
      );

      return await completer.future;
    } catch (e) {
      print('FirebaseAuth handlePhoneVerification catch: $e');
      _setOtpError('Error verificando telefono');
      return 0;
    }
  }

  Future<int> oTPVerification() async {
    if (otpAutoVerified) {
      return 1;
    }

    if ((verificationId ?? '').isEmpty || (otp ?? '').isEmpty) {
      _setOtpError('Solicita un codigo primero');
      return 0;
    }

    try {
      final credential = PhoneAuthProvider.credential(
        verificationId: verificationId,
        smsCode: otp,
      );
      await FirebaseAuth.instance.signInWithCredential(credential);
      _setOtpError('');
      return 1;
    } on FirebaseAuthException catch (e) {
      print('FirebaseAuth oTPVerification error: ${e.code} ${e.message}');
      if (e.code == 'invalid-verification-code') {
        _setOtpError('Codigo incorrecto');
      } else if (e.code == 'session-expired') {
        _setOtpError('El codigo expiro. Reenvialo');
      } else {
        _setOtpError('No se pudo verificar el codigo');
      }
      return 0;
    } catch (e) {
      print('FirebaseAuth oTPVerification catch: $e');
      _setOtpError('No se pudo verificar el codigo');
      return 0;
    }
  }

  Future<bool> registerUser(String nombre, String telefono, String email) async {
    return await Repository.registerNewUser(nombre, telefono, email, "wuber_pass");
  }

  Future<Map<String, dynamic>> checkUserByPhone(String phone) async {
    return await Repository.checkUserByPhone(phone);
  }

  void updateCircularLoadingOtp(bool param0) {
    shopCircularLoaderOTP = param0;
    notifyListeners();
  }

  Future<int> resendOtp() async {
    return await handlePhoneVerification();
  }
}
