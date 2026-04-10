import 'package:flutter/material.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/UI/views/WelcomeScreen.dart';

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
      icon: Icons.app_registration_rounded,
      iconColor: Color(0xFF003D7A),
      title: 'Tu registro\nen segundos',
      subtitle:
          'Completa tu perfil rápidamente y comienza a gestionar tus actividades comerciales en tiempo real.',
      gradientColors: [Color(0xFFFFC800), Color(0xFFFFD700)],
    ),
    OnboardingData(
      icon: Icons.security_rounded,
      iconColor: Color(0xFF003D7A),
      title: 'Seguro y\nconfiable',
      subtitle:
          'Todos nuestros datos se guardan de forma segura y eficiente a tiempo real.',
      gradientColors: [Color(0xFFFFD700), Color(0xFFFFC800)],
    ),
    OnboardingData(
      icon: Icons.assignment_rounded,
      iconColor: Color(0xFF003D7A),
      title: 'Encuesta como\nprefieras',
      subtitle:
          'Escoges tareas y sectores que desees. Realiza encuestas comerciales y crediticias donde quieras.',
      gradientColors: [Color(0xFFFFC800), Color(0xFFFFD700)],
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
      if (mounted) Navigator.pushReplacementNamed(context, WelcomeScreen.route);
    }
  }

  void _skip() async {
    await AuthPrefs.markOnboardingSeen();
    if (mounted) Navigator.pushReplacementNamed(context, WelcomeScreen.route);
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
                              gradient: const LinearGradient(
                                begin: Alignment.topLeft,
                                end: Alignment.bottomRight,
                                colors: [
                                  Color(0xFF003D7A),
                                  Color(0xFF1E5A96),
                                ],
                              ),
                              borderRadius: BorderRadius.circular(16),
                              boxShadow: [
                                BoxShadow(
                                  color:
                                      const Color(0xFF003D7A).withOpacity(0.4),
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
                    color: Colors.black87,
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
