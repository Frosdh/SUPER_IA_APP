import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/UI/views/LocationPermissionScreen.dart';
import 'package:fu_uber/UI/views/OnboardingScreen.dart';
import 'package:fu_uber/UI/views/WelcomeScreen.dart';

class SplashScreen extends StatefulWidget {
  static const String route = '/splash';

  @override
  _SplashScreenState createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _fadeAnimation;
  late Animation<double> _slideAnimation;

  @override
  void initState() {
    super.initState();

    _controller = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: 1400),
    );

    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: Interval(0.0, 0.7, curve: Curves.easeOut),
      ),
    );

    _slideAnimation = Tween<double>(begin: 20.0, end: 0.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: Interval(0.15, 0.9, curve: Curves.easeOut),
      ),
    );

    _controller.forward();

    Future.delayed(Duration(milliseconds: 2200), () async {
      if (!mounted) return;

      bool loggedIn = await AuthPrefs.hasValidSession();

      if (loggedIn) {
        Navigator.pushReplacementNamed(context, LocationPermissionScreen.route);
      } else {
        bool firstTime = await AuthPrefs.isFirstTime();
        if (firstTime) {
          Navigator.pushReplacementNamed(context, OnboardingScreen.route);
        } else {
          Navigator.pushReplacementNamed(context, WelcomeScreen.route);
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
            radius: 1.15,
            colors: [
              Color(0xFF161233),
              ConstantColors.backgroundDark,
            ],
          ),
        ),
        child: Stack(
          children: [
            Positioned(
              top: -110,
              right: -90,
              child: Container(
                width: 280,
                height: 280,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: ConstantColors.primaryViolet.withOpacity(0.08),
                ),
              ),
            ),
            Positioned(
              bottom: -70,
              left: -70,
              child: Container(
                width: 220,
                height: 220,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: ConstantColors.primaryBlue.withOpacity(0.08),
                ),
              ),
            ),
            Center(
              child: AnimatedBuilder(
                animation: _controller,
                builder: (context, child) {
                  return Opacity(
                    opacity: _fadeAnimation.value,
                    child: Transform.translate(
                      offset: Offset(0, _slideAnimation.value),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          ShaderMask(
                            shaderCallback: (bounds) {
                              return ConstantColors.primaryGradient
                                  .createShader(bounds);
                            },
                            child: Text(
                              'GeoMove',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 40,
                                fontWeight: FontWeight.w800,
                                letterSpacing: 1.0,
                              ),
                            ),
                          ),
                          SizedBox(height: 12),
                          Text(
                            'Movilidad inteligente',
                            style: TextStyle(
                              color: Colors.white.withOpacity(0.9),
                              fontSize: 18,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                          SizedBox(height: 10),
                          Text(
                            'Preparando tu experiencia...',
                            style: TextStyle(
                              color: ConstantColors.textGrey,
                              fontSize: 14,
                              letterSpacing: 0.4,
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
            ),
            Positioned(
              bottom: 56,
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
                              ConstantColors.primaryViolet.withOpacity(0.7),
                            ),
                          ),
                        ),
                        SizedBox(height: 14),
                        Text(
                          'GeoMove',
                          style: TextStyle(
                            color: ConstantColors.textSubtle,
                            fontSize: 12,
                            letterSpacing: 1.1,
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
