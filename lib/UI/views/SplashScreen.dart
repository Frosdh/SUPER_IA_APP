import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/UI/views/OnboardingScreen.dart';
import 'package:fu_uber/UI/views/SignIn.dart';
import 'package:fu_uber/UI/views/LocationPermissionScreen.dart';

class SplashScreen extends StatefulWidget {
  static const String route = '/splash';

  @override
  _SplashScreenState createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with SingleTickerProviderStateMixin {
  AnimationController _controller;
  Animation<double> _fadeAnimation;
  Animation<double> _scaleAnimation;
  Animation<double> _slideAnimation;

  @override
  void initState() {
    super.initState();

    _controller = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: 1800),
    );

    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: Interval(0.0, 0.6, curve: Curves.easeOut),
      ),
    );

    _scaleAnimation = Tween<double>(begin: 0.7, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: Interval(0.0, 0.6, curve: Curves.elasticOut),
      ),
    );

    _slideAnimation = Tween<double>(begin: 30.0, end: 0.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: Interval(0.3, 0.9, curve: Curves.easeOut),
      ),
    );

    _controller.forward();

    // Verificar sesión después de 3 segundos
    Future.delayed(Duration(milliseconds: 3000), () async {
      if (!mounted) return;

      bool loggedIn = await AuthPrefs.hasValidSession();

      if (loggedIn) {
        // Usuario ya registrado → ir directo al mapa
        Navigator.pushReplacementNamed(context, LocationPermissionScreen.route);
      } else {
        bool firstTime = await AuthPrefs.isFirstTime();
        if (firstTime) {
          // Primera vez → mostrar onboarding
          Navigator.pushReplacementNamed(context, OnboardingScreen.route);
        } else {
          // Ya vio el onboarding pero no está logueado → ir a login
          Navigator.pushReplacementNamed(context, SignInPage.route);
        }
      }
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: Container(
        decoration: BoxDecoration(
          gradient: RadialGradient(
            center: Alignment.center,
            radius: 1.2,
            colors: [
              Color(0xFF1A0A3D),
              ConstantColors.backgroundDark,
            ],
          ),
        ),
        child: Stack(
          children: [
            // Círculo decorativo fondo
            Positioned(
              top: -100,
              right: -100,
              child: Container(
                width: 350,
                height: 350,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: ConstantColors.primaryViolet.withOpacity(0.08),
                ),
              ),
            ),
            Positioned(
              bottom: -80,
              left: -80,
              child: Container(
                width: 280,
                height: 280,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: ConstantColors.primaryBlue.withOpacity(0.08),
                ),
              ),
            ),

            // Contenido central
            Center(
              child: AnimatedBuilder(
                animation: _controller,
                builder: (context, child) {
                  return Opacity(
                    opacity: _fadeAnimation.value,
                    child: Transform.translate(
                      offset: Offset(0, _slideAnimation.value),
                      child: Transform.scale(
                        scale: _scaleAnimation.value,
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            // Logo / Ícono principal
                            Container(
                              width: 110,
                              height: 110,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                gradient: ConstantColors.primaryGradient,
                                boxShadow: [
                                  BoxShadow(
                                    color: ConstantColors.primaryViolet
                                        .withOpacity(0.5),
                                    blurRadius: 40,
                                    spreadRadius: 5,
                                  ),
                                ],
                              ),
                              child: Icon(
                                Icons.directions_car_rounded,
                                color: Colors.white,
                                size: 56,
                              ),
                            ),

                            SizedBox(height: 28),

                            // Nombre de la app
                            ShaderMask(
                              shaderCallback: (bounds) =>
                                  ConstantColors.primaryGradient
                                      .createShader(bounds),
                              child: Text(
                                'Wendy',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 46,
                                  fontWeight: FontWeight.w800,
                                  letterSpacing: 2.0,
                                ),
                              ),
                            ),

                            Text(
                              'UBER',
                              style: TextStyle(
                                color: Colors.white.withOpacity(0.9),
                                fontSize: 18,
                                fontWeight: FontWeight.w300,
                                letterSpacing: 8.0,
                              ),
                            ),

                            SizedBox(height: 12),

                            // Tagline
                            Text(
                              'Tu viaje, tu manera',
                              style: TextStyle(
                                color: ConstantColors.textGrey,
                                fontSize: 14,
                                letterSpacing: 1.0,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  );
                },
              ),
            ),

            // Indicador de carga abajo
            Positioned(
              bottom: 60,
              left: 0,
              right: 0,
              child: AnimatedBuilder(
                animation: _controller,
                builder: (context, child) {
                  return Opacity(
                    opacity: _fadeAnimation.value,
                    child: Column(
                      children: [
                        SizedBox(
                          width: 24,
                          height: 24,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            valueColor: AlwaysStoppedAnimation<Color>(
                              ConstantColors.primaryViolet.withOpacity(0.6),
                            ),
                          ),
                        ),
                        SizedBox(height: 16),
                        Text(
                          'Cuenca, Ecuador',
                          style: TextStyle(
                            color: ConstantColors.textSubtle,
                            fontSize: 12,
                            letterSpacing: 1.5,
                          ),
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}
