import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/ProviderModels/UserDetailsModel.dart';
import 'package:fu_uber/UI/views/EditProfileScreen.dart';
import 'package:fu_uber/UI/views/EmergencyContactsScreen.dart';
import 'package:fu_uber/UI/views/FavoritePlacesScreen.dart';
import 'package:fu_uber/UI/views/HelpFaqScreen.dart';
import 'package:fu_uber/UI/views/RideHistoryScreen.dart';
import 'package:fu_uber/UI/views/PaymentHistoryScreen.dart';
import 'package:fu_uber/UI/views/WalletScreen.dart';
import 'package:fu_uber/UI/views/SignIn.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

class ProfileScreen extends StatefulWidget {
  static const route = "/profilescreen";
  static const TAG = "ProfileScreen";

  @override
  _ProfileScreenState createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  String _photoBase64 = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<UserDetailsModel>(context, listen: false).reload();
    });
    _cargarFoto();
  }

  Future<void> _cargarFoto() async {
    final foto = await AuthPrefs.getUserPhoto();
    if (mounted) setState(() => _photoBase64 = foto);
  }

  Future<void> _cambiarFoto() async {
    ImageSource? opcion;

    await showModalBottomSheet(
      context: context,
      backgroundColor: ConstantColors.backgroundCard,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: EdgeInsets.symmetric(vertical: 16, horizontal: 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40, height: 4,
                decoration: BoxDecoration(
                  color: Colors.white24,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
              SizedBox(height: 20),
              Text(
                'Cambiar foto de perfil',
                style: TextStyle(
                  color: ConstantColors.textWhite,
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                ),
              ),
              SizedBox(height: 20),
              _opcionFotoItem(
                icon: Icons.camera_alt,
                label: 'Tomar foto',
                color: ConstantColors.primaryViolet,
                onTap: () {
                  opcion = ImageSource.camera;
                  Navigator.pop(ctx);
                },
              ),
              SizedBox(height: 12),
              _opcionFotoItem(
                icon: Icons.photo_library,
                label: 'Elegir de galería',
                color: ConstantColors.primaryBlue,
                onTap: () {
                  opcion = ImageSource.gallery;
                  Navigator.pop(ctx);
                },
              ),
              if (_photoBase64.isNotEmpty) ...[
                SizedBox(height: 12),
                _opcionFotoItem(
                  icon: Icons.delete_outline,
                  label: 'Eliminar foto',
                  color: Colors.redAccent,
                  onTap: () async {
                    Navigator.pop(ctx);
                    await AuthPrefs.saveUserPhoto('');
                    if (mounted) setState(() => _photoBase64 = '');
                  },
                ),
              ],
              SizedBox(height: 8),
            ],
          ),
        ),
      ),
    );

    if (opcion == null) return;
    final source = opcion!;

    try {
      final picker     = ImagePicker();
      final pickedFile = await picker.pickImage(
        source:       source,
        imageQuality: 70,
        maxWidth:     400,
      );
      if (pickedFile == null) return;

      final bytes      = await File(pickedFile.path).readAsBytes();
      final base64Str  = base64Encode(bytes);
      await AuthPrefs.saveUserPhoto(base64Str);
      if (mounted) setState(() => _photoBase64 = base64Str);
    } catch (e) {
      print('>>> [FOTO] Error: $e');
    }
  }

  Widget _opcionFotoItem({required IconData icon, required String label, required Color color, required VoidCallback onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: double.infinity,
        padding: EdgeInsets.symmetric(vertical: 14, horizontal: 16),
        decoration: BoxDecoration(
          color: color.withOpacity(0.1),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: color.withOpacity(0.3)),
        ),
        child: Row(
          children: [
            Icon(icon, color: color, size: 22),
            SizedBox(width: 14),
            Text(label, style: TextStyle(color: color, fontSize: 15, fontWeight: FontWeight.w600)),
          ],
        ),
      ),
    );
  }

  void _cerrarSesion() async {
    await AuthPrefs.clearSession();
    Navigator.of(context).pushNamedAndRemoveUntil(
      SignInPage.route,
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    final userModel = Provider.of<UserDetailsModel>(context);

    final String inicial = (userModel.name != null && userModel.name.isNotEmpty)
        ? userModel.name[0].toUpperCase()
        : '?';

    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: Icon(Icons.arrow_back, color: Colors.white),
          onPressed: () => Navigator.of(context).pop(),
        ),
        title: Text('Mi perfil', style: TextStyle(color: Colors.white)),
        actions: [
          IconButton(
            icon: Icon(Icons.edit, color: Colors.white70),
            tooltip: 'Editar perfil',
            onPressed: () async {
              await Navigator.pushNamed(context, EditProfileScreen.route);
              Provider.of<UserDetailsModel>(context, listen: false).reload();
            },
          ),
          IconButton(
            icon: Icon(Icons.logout, color: Colors.redAccent),
            tooltip: 'Cerrar sesión',
            onPressed: _cerrarSesion,
          ),
        ],
      ),
      body: SingleChildScrollView(
        child: Column(
          children: [
            // ── Header con foto ──────────────────────────────────────────
            Container(
              width: double.infinity,
              padding: EdgeInsets.symmetric(vertical: 36),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [Color(0xFF7B2FF7), Color(0xFF2B6BF7)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.only(
                  bottomLeft: Radius.circular(40),
                  bottomRight: Radius.circular(40),
                ),
              ),
              child: Column(
                children: [
                  // Avatar con botón de cámara
                  Stack(
                    children: [
                      GestureDetector(
                        onTap: _cambiarFoto,
                        child: Container(
                          width: 100,
                          height: 100,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            border: Border.all(color: Colors.white38, width: 3),
                            boxShadow: [
                              BoxShadow(
                                color: Colors.black26,
                                blurRadius: 16,
                                offset: Offset(0, 6),
                              ),
                            ],
                          ),
                          child: ClipOval(
                            child: _photoBase64.isNotEmpty
                                ? Image.memory(
                                    base64Decode(_photoBase64),
                                    fit: BoxFit.cover,
                                    width: 100,
                                    height: 100,
                                  )
                                : Container(
                                    color: Colors.white24,
                                    child: Center(
                                      child: Text(
                                        inicial,
                                        style: TextStyle(
                                          fontSize: 38,
                                          fontWeight: FontWeight.bold,
                                          color: Colors.white,
                                        ),
                                      ),
                                    ),
                                  ),
                          ),
                        ),
                      ),
                      // Botón cámara pequeño
                      Positioned(
                        bottom: 0,
                        right: 0,
                        child: GestureDetector(
                          onTap: _cambiarFoto,
                          child: Container(
                            width: 32,
                            height: 32,
                            decoration: BoxDecoration(
                              color: Colors.white,
                              shape: BoxShape.circle,
                              boxShadow: [BoxShadow(color: Colors.black26, blurRadius: 6)],
                            ),
                            child: Icon(
                              Icons.camera_alt,
                              size: 17,
                              color: Color(0xFF7B2FF7),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),

                  SizedBox(height: 14),
                  Text(
                    userModel.name != null && userModel.name.isNotEmpty
                        ? userModel.name
                        : 'Cargando...',
                    style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.bold,
                      color: Colors.white,
                    ),
                  ),
                  SizedBox(height: 4),
                  Text(
                    userModel.phone ?? '',
                    style: TextStyle(fontSize: 13, color: Colors.white60),
                  ),
                ],
              ),
            ),

            SizedBox(height: 24),

            // ── Tarjeta de datos ─────────────────────────────────────────
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Card(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                color: ConstantColors.backgroundCard,
                elevation: 0,
                child: Column(
                  children: [
                    _infoTile(Icons.person, 'Nombre', userModel.name ?? ''),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _infoTile(Icons.email, 'Correo', userModel.email ?? ''),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _infoTile(Icons.phone, 'Teléfono', userModel.phone ?? ''),
                  ],
                ),
              ),
            ),

            SizedBox(height: 20),

            // ── Botones de acciones ──────────────────────────────────────
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Card(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                color: ConstantColors.backgroundCard,
                elevation: 0,
                child: Column(
                  children: [
                    _accionTile(
                      Icons.directions_car,
                      'Mis viajes',
                      () => Navigator.pushNamed(context, RideHistoryScreen.route),
                    ),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _accionTile(
                      Icons.account_balance_wallet_rounded,
                      'Mi billetera',
                      () => Navigator.pushNamed(context, WalletScreen.route),
                      color: ConstantColors.primaryViolet,
                    ),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _accionTile(
                      Icons.receipt_long_rounded,
                      'Historial de pagos',
                      () => Navigator.pushNamed(context, PaymentHistoryScreen.route),
                    ),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _accionTile(
                      Icons.favorite,
                      'Lugares favoritos',
                      () => Navigator.pushNamed(context, FavoritePlacesScreen.route),
                      color: Colors.pinkAccent,
                    ),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _accionTile(
                      Icons.warning,
                      'Contactos de emergencia',
                      () => Navigator.pushNamed(context, EmergencyContactsScreen.route),
                      color: Colors.redAccent,
                    ),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _accionTile(Icons.settings, 'Configuración', () {}),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _accionTile(
                      Icons.help_outline,
                      'Ayuda',
                      () => Navigator.pushNamed(context, HelpFaqScreen.route),
                    ),
                    Divider(color: ConstantColors.dividerColor, height: 1),
                    _accionTile(
                      Icons.exit_to_app,
                      'Cerrar sesión',
                      _cerrarSesion,
                      color: Colors.redAccent,
                    ),
                  ],
                ),
              ),
            ),

            SizedBox(height: 40),
          ],
        ),
      ),
    );
  }

  Widget _infoTile(IconData icon, String label, String value) {
    return ListTile(
      leading: Icon(icon, color: ConstantColors.primaryViolet),
      title: Text(label, style: TextStyle(fontSize: 11, color: ConstantColors.textSubtle)),
      subtitle: Text(
        value.isNotEmpty ? value : '—',
        style: TextStyle(fontSize: 15, color: ConstantColors.textWhite),
      ),
    );
  }

  Widget _accionTile(IconData icon, String label, VoidCallback onTap, {Color? color}) {
    return ListTile(
      leading: Icon(icon, color: color ?? ConstantColors.textGrey),
      title: Text(
        label,
        style: TextStyle(color: color ?? ConstantColors.textWhite, fontSize: 15),
      ),
      trailing: Icon(Icons.chevron_right, color: Colors.white24),
      onTap: onTap,
    );
  }
}
