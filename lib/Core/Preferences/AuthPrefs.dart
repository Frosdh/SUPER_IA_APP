import 'package:shared_preferences/shared_preferences.dart';

class AuthPrefs {
  // Claves de almacenamiento
  static const String KEY_IS_LOGGED_IN = 'is_logged_in';
  static const String KEY_PHONE        = 'user_phone';
  static const String KEY_NAME         = 'user_name';
  static const String KEY_EMAIL        = 'user_email';
  static const String KEY_FIRST_TIME   = 'first_time';
  static const String KEY_BACKEND_OK   = 'backend_registration_ok';
  static const String KEY_PHOTO_BASE64 = 'user_photo_base64';
  static const String KEY_FCM_TOKEN = 'user_fcm_token';
  static const String KEY_FCM_TOKEN_SYNCED = 'user_fcm_token_synced';

  // ─── Guardar sesión completa ───────────────────────────────────────────────
  static Future<void> saveUserSession({
    String nombre,
    String telefono,
    String email,
  }) async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setBool(KEY_IS_LOGGED_IN, true);
    await prefs.setString(KEY_PHONE,  telefono ?? '');
    await prefs.setString(KEY_NAME,   nombre   ?? '');
    await prefs.setString(KEY_EMAIL,  email    ?? '');
    await prefs.setBool(KEY_BACKEND_OK, true);
    // Marcar que ya NO es primera vez (no mostrar onboarding)
    await prefs.setBool(KEY_FIRST_TIME, false);
  }

  // ─── Verificar si hay sesión activa ───────────────────────────────────────
  static Future<bool> isLoggedIn() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    return prefs.getBool(KEY_IS_LOGGED_IN) ?? false;
  }

  static Future<bool> hasValidSession() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    final loggedIn = prefs.getBool(KEY_IS_LOGGED_IN) ?? false;
    final backendOk = prefs.getBool(KEY_BACKEND_OK) ?? false;
    final phone = (prefs.getString(KEY_PHONE) ?? '').trim();
    return loggedIn && backendOk && phone.isNotEmpty;
  }

  // ─── Verificar si es primera vez (mostrar onboarding) ─────────────────────
  static Future<bool> isFirstTime() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    // Si no existe la clave, es primera vez
    bool firstTime = prefs.getBool(KEY_FIRST_TIME);
    return firstTime == null ? true : firstTime;
  }

  // ─── Marcar que el onboarding ya fue visto ─────────────────────────────────
  static Future<void> markOnboardingSeen() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setBool(KEY_FIRST_TIME, false);
  }

  // ─── Obtener datos del usuario ────────────────────────────────────────────
  static Future<String> getUserPhone() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_PHONE) ?? '';
  }

  static Future<String> getUserName() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_NAME) ?? '';
  }

  static Future<String> getUserEmail() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_EMAIL) ?? '';
  }

  // ─── Foto de perfil (base64) ──────────────────────────────────────────────
  static Future<void> saveUserPhoto(String base64) async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setString(KEY_PHOTO_BASE64, base64);
  }

  static Future<String> getUserPhoto() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_PHOTO_BASE64) ?? '';
  }

  static Future<void> saveFcmToken(String token) async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setString(KEY_FCM_TOKEN, token ?? '');
  }

  static Future<String> getFcmToken() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_FCM_TOKEN) ?? '';
  }

  static Future<void> saveSyncedFcmToken(String token) async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setString(KEY_FCM_TOKEN_SYNCED, token ?? '');
  }

  static Future<String> getSyncedFcmToken() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    return prefs.getString(KEY_FCM_TOKEN_SYNCED) ?? '';
  }

  // ─── Cerrar sesión ────────────────────────────────────────────────────────
  static Future<void> clearSession() async {
    SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setBool(KEY_IS_LOGGED_IN, false);
    await prefs.remove(KEY_PHONE);
    await prefs.remove(KEY_NAME);
    await prefs.remove(KEY_EMAIL);
    await prefs.remove(KEY_BACKEND_OK);
    await prefs.remove(KEY_FCM_TOKEN_SYNCED);
    // Mantener KEY_FIRST_TIME en false para no mostrar onboarding de nuevo
  }
}
