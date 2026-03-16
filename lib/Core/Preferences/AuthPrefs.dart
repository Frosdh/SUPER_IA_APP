import 'package:shared_preferences/shared_preferences.dart';

class AuthPrefs {
  static const String KEY_IS_LOGGED_IN = 'is_logged_in';
  static const String KEY_PHONE = 'user_phone';
  static const String KEY_NAME = 'user_name';
  static const String KEY_EMAIL = 'user_email';
  static const String KEY_FIRST_TIME = 'first_time';
  static const String KEY_BACKEND_OK = 'backend_registration_ok';
  static const String KEY_PHOTO_BASE64 = 'user_photo_base64';
  static const String KEY_FCM_TOKEN = 'user_fcm_token';
  static const String KEY_FCM_TOKEN_SYNCED = 'user_fcm_token_synced';

  static Future<String> _scopedKey(String baseKey) async {
    final prefs = await SharedPreferences.getInstance();
    final phone = (prefs.getString(KEY_PHONE) ?? '').trim();
    if (phone.isEmpty) {
      return baseKey;
    }
    return '${baseKey}_$phone';
  }

  static Future<void> saveUserSession({
    required String nombre,
    required String telefono,
    required String email,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(KEY_IS_LOGGED_IN, true);
    await prefs.setString(KEY_PHONE, telefono);
    await prefs.setString(KEY_NAME, nombre);
    await prefs.setString(KEY_EMAIL, email);
    await prefs.setBool(KEY_BACKEND_OK, true);
    await prefs.setBool(KEY_FIRST_TIME, false);
  }

  static Future<bool> isLoggedIn() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(KEY_IS_LOGGED_IN) ?? false;
  }

  static Future<bool> hasValidSession() async {
    final prefs = await SharedPreferences.getInstance();
    final loggedIn = prefs.getBool(KEY_IS_LOGGED_IN) ?? false;
    final backendOk = prefs.getBool(KEY_BACKEND_OK) ?? false;
    final phone = (prefs.getString(KEY_PHONE) ?? '').trim();
    return loggedIn && backendOk && phone.isNotEmpty;
  }

  static Future<bool> isFirstTime() async {
    final prefs = await SharedPreferences.getInstance();
    final firstTime = prefs.getBool(KEY_FIRST_TIME);
    return firstTime == null ? true : firstTime;
  }

  static Future<void> markOnboardingSeen() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(KEY_FIRST_TIME, false);
  }

  static Future<String> getUserPhone() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_PHONE) ?? '';
  }

  static Future<String> getUserName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_NAME) ?? '';
  }

  static Future<String> getUserEmail() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_EMAIL) ?? '';
  }

  static Future<void> saveUserPhoto(String? base64) async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _scopedKey(KEY_PHOTO_BASE64);
    await prefs.setString(key, base64 ?? '');
  }

  static Future<String> getUserPhoto() async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _scopedKey(KEY_PHOTO_BASE64);
    final scopedValue = prefs.getString(key);
    if (scopedValue != null && scopedValue.isNotEmpty) {
      return scopedValue;
    }
    return prefs.getString(KEY_PHOTO_BASE64) ?? '';
  }

  static Future<void> saveFcmToken(String? token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(KEY_FCM_TOKEN, token ?? '');
  }

  static Future<String> getFcmToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_FCM_TOKEN) ?? '';
  }

  static Future<void> saveSyncedFcmToken(String? token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(KEY_FCM_TOKEN_SYNCED, token ?? '');
  }

  static Future<String> getSyncedFcmToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_FCM_TOKEN_SYNCED) ?? '';
  }

  static Future<void> clearSession() async {
    final prefs = await SharedPreferences.getInstance();
    final photoKey = await _scopedKey(KEY_PHOTO_BASE64);
    await prefs.setBool(KEY_IS_LOGGED_IN, false);
    await prefs.remove(KEY_PHONE);
    await prefs.remove(KEY_NAME);
    await prefs.remove(KEY_EMAIL);
    await prefs.remove(KEY_BACKEND_OK);
    await prefs.remove(KEY_FCM_TOKEN_SYNCED);
    await prefs.remove(photoKey);
  }
}
