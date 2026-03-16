import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/UI/views/SignIn.dart';

class OnboardingScreen extends StatefulWidget {
  static const String route = '/onboarding';

  @override
  _OnboardingScreenState createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen>
    with TickerProviderStateMixin {
  final PageController _pageController = PageController();
  int _currentPage = 0;

  late AnimationController _animController;
  late Animation<double> _fadeAnim;

  final List<OnboardingData> _pages = [
    OnboardingData(
      icon: Icons.location_on_rounded,
      iconColor: Color(0xFFA78BFA),
      title: 'Tu destino,\nen segundos',
      subtitle:
          'Ingresa a donde quieres ir y encuentra conductores cerca de ti en tiempo real.',
      gradientColors: [Color(0xFF1A0A3D), Color(0xFF0D0D1A)],
    ),
    OnboardingData(
      icon: Icons.security_rounded,
      iconColor: Color(0xFF60A5FA),
      title: 'Seguro y\nconfiable',
      subtitle:
          'Todos nuestros conductores estan verificados. Comparte tu viaje en tiempo real con familiares.',
      gradientColors: [Color(0xFF0A1A3D), Color(0xFF0D0D1A)],
    ),
    OnboardingData(
      icon: Icons.payments_rounded,
      iconColor: Color(0xFF818CF8),
      title: 'Paga como\nprefieras',
      subtitle:
          'Efectivo o transferencia bancaria. Ves el precio antes de confirmar.',
      gradientColors: [Color(0xFF0D0A3D), Color(0xFF0D0D1A)],
    ),
  ];

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: 400),
    );
    _fadeAnim = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _animController, curve: Curves.easeIn),
    );
    _animController.forward();
  }

  @override
  void dispose() {
    _pageController.dispose();
    _animController.dispose();
    super.dispose();
  }

  void _onPageChanged(int page) {
    setState(() {
      _currentPage = page;
    });
    _animController.reset();
    _animController.forward();
  }

  void _nextPage() async {
    if (_currentPage < _pages.length - 1) {
      _pageController.nextPage(
        duration: Duration(milliseconds: 400),
        curve: Curves.easeInOut,
      );
    } else {
      await AuthPrefs.markOnboardingSeen();
      if (mounted) Navigator.pushReplacementNamed(context, SignInPage.route);
    }
  }

  void _skip() async {
    await AuthPrefs.markOnboardingSeen();
    if (mounted) Navigator.pushReplacementNamed(context, SignInPage.route);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: LayoutBuilder(
        builder: (context, constraints) {
          return Stack(
            children: [
              Positioned.fill(
                child: PageView.builder(
                  controller: _pageController,
                  onPageChanged: _onPageChanged,
                  itemCount: _pages.length,
                  itemBuilder: (context, index) {
                    return _buildPage(_pages[index], constraints);
                  },
                ),
              ),
              SafeArea(
                child: Align(
                  alignment: Alignment.topRight,
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(24, 16, 24, 8),
                    child: GestureDetector(
                      onTap: _skip,
                      child: Container(
                        padding:
                            EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(0.08),
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(
                            color: Colors.white.withOpacity(0.15),
                          ),
                        ),
                        child: Text(
                          'Omitir',
                          style: TextStyle(
                            color: ConstantColors.textGrey,
                            fontSize: 13,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              SafeArea(
                child: Align(
                  alignment: Alignment.bottomCenter,
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(24, 12, 24, 24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: List.generate(
                            _pages.length,
                            (index) => _buildDot(index),
                          ),
                        ),
                        SizedBox(height: 24),
                        GestureDetector(
                          onTap: _nextPage,
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
                                _currentPage == _pages.length - 1
                                    ? 'Empezar ahora'
                                    : 'Siguiente',
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
                      ],
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _buildPage(OnboardingData data, BoxConstraints constraints) {
    final bool compactHeight = constraints.maxHeight < 720;
    final double horizontalPadding = constraints.maxWidth < 360 ? 24 : 32;

    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: data.gradientColors,
        ),
      ),
      child: SingleChildScrollView(
        physics: BouncingScrollPhysics(),
        padding: EdgeInsets.fromLTRB(
          horizontalPadding,
          compactHeight ? 96 : 112,
          horizontalPadding,
          180,
        ),
        child: ConstrainedBox(
          constraints: BoxConstraints(
            minHeight: constraints.maxHeight - (compactHeight ? 180 : 200),
          ),
          child: FadeTransition(
            opacity: _fadeAnim,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: compactHeight ? 76 : 90,
                  height: compactHeight ? 76 : 90,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: data.iconColor.withOpacity(0.15),
                    border: Border.all(
                      color: data.iconColor.withOpacity(0.3),
                      width: 1.5,
                    ),
                  ),
                  child: Icon(
                    data.icon,
                    color: data.iconColor,
                    size: compactHeight ? 38 : 44,
                  ),
                ),
                SizedBox(height: compactHeight ? 28 : 40),
                Text(
                  data.title,
                  style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: compactHeight ? 30 : 36,
                    fontWeight: FontWeight.w800,
                    height: 1.2,
                  ),
                ),
                SizedBox(height: 20),
                Container(
                  width: 50,
                  height: 3,
                  decoration: BoxDecoration(
                    gradient: ConstantColors.buttonGradient,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                SizedBox(height: 24),
                Text(
                  data.subtitle,
                  style: TextStyle(
                    color: ConstantColors.textGrey,
                    fontSize: compactHeight ? 15 : 16,
                    height: 1.6,
                    fontWeight: FontWeight.w400,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildDot(int index) {
    bool isActive = index == _currentPage;
    return AnimatedContainer(
      duration: Duration(milliseconds: 300),
      margin: EdgeInsets.symmetric(horizontal: 4),
      width: isActive ? 24 : 8,
      height: 8,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(4),
        gradient: isActive ? ConstantColors.buttonGradient : null,
        color: isActive ? null : Colors.white.withOpacity(0.2),
      ),
    );
  }
}

class OnboardingData {
  final IconData icon;
  final Color iconColor;
  final String title;
  final String subtitle;
  final List<Color> gradientColors;

  OnboardingData({
    required this.icon,
    required this.iconColor,
    required this.title,
    required this.subtitle,
    required this.gradientColors,
  });
}
