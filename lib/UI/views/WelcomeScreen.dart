import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/UI/views/DriverLoginScreen.dart';
import 'package:fu_uber/UI/views/SignIn.dart';

class WelcomeScreen extends StatefulWidget {
  static const String route = '/welcome';

  @override
  _WelcomeScreenState createState() => _WelcomeScreenState();
}

class _WelcomeScreenState extends State<WelcomeScreen>
    with TickerProviderStateMixin {
  late AnimationController _logoController;
  late AnimationController _cardsController;
  late Animation<double> _logoFade;
  late Animation<double> _logoSlide;
  late Animation<double> _cardsFade;
  late Animation<Offset> _passengerSlide;
  late Animation<Offset> _driverSlide;

  @override
  void initState() {
    super.initState();

    _logoController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 700),
    );
    _cardsController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 600),
    );

    _logoFade = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _logoController, curve: Curves.easeOut),
    );
    _logoSlide = Tween<double>(begin: 24, end: 0).animate(
      CurvedAnimation(parent: _logoController, curve: Curves.easeOut),
    );
    _cardsFade = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _cardsController, curve: Curves.easeOut),
    );
    _passengerSlide = Tween<Offset>(
      begin: const Offset(-0.3, 0),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(parent: _cardsController, curve: Curves.easeOut),
    );
    _driverSlide = Tween<Offset>(
      begin: const Offset(0.3, 0),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(parent: _cardsController, curve: Curves.easeOut),
    );

    _logoController.forward().then((_) {
      Future.delayed(const Duration(milliseconds: 100), () {
        if (mounted) _cardsController.forward();
      });
    });
  }

  @override
  void dispose() {
    _logoController.dispose();
    _cardsController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
        children: [
          // ── Fondo degradado ─────────────────────────────────
          Container(
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Color(0xFF0F0C29),
                  Color(0xFF1A1535),
                  Color(0xFF0D0D1A),
                ],
              ),
            ),
          ),

          // ── Orbe decorativo superior derecha ─────────────────
          Positioned(
            top: -size.width * 0.15,
            right: -size.width * 0.12,
            child: Container(
              width: size.width * 0.55,
              height: size.width * 0.55,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: ConstantColors.primaryViolet.withOpacity(0.07),
              ),
            ),
          ),

          // ── Orbe decorativo inferior izquierda ────────────────
          Positioned(
            bottom: -size.width * 0.12,
            left: -size.width * 0.10,
            child: Container(
              width: size.width * 0.45,
              height: size.width * 0.45,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: ConstantColors.primaryBlue.withOpacity(0.07),
              ),
            ),
          ),

          // ── Contenido principal ───────────────────────────────
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Column(
                children: [
                  const Spacer(flex: 2),

                  // ── Logo + Título ─────────────────────────────
                  AnimatedBuilder(
                    animation: _logoController,
                    builder: (context, child) => Opacity(
                      opacity: _logoFade.value,
                      child: Transform.translate(
                        offset: Offset(0, _logoSlide.value),
                        child: child,
                      ),
                    ),
                    child: Column(
                      children: [
                        // Logo
                        Container(
                          width: 90,
                          height: 90,
                          decoration: BoxDecoration(
                            gradient: ConstantColors.buttonGradient,
                            borderRadius: BorderRadius.circular(26),
                            boxShadow: [
                              BoxShadow(
                                color: ConstantColors.primaryViolet
                                    .withOpacity(0.35),
                                blurRadius: 28,
                                offset: const Offset(0, 12),
                              ),
                            ],
                          ),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(26),
                            child: Image.asset(
                              'images/geomove_logo.png',
                              fit: BoxFit.cover,
                            ),
                          ),
                        ),
                        const SizedBox(height: 20),
                        ShaderMask(
                          shaderCallback: (bounds) =>
                              ConstantColors.primaryGradient
                                  .createShader(bounds),
                          child: const Text(
                            'GeoMove',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 36,
                              fontWeight: FontWeight.w800,
                              letterSpacing: 0.5,
                            ),
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Movilidad inteligente en Cuenca',
                          style: TextStyle(
                            color: Colors.white.withOpacity(0.55),
                            fontSize: 14,
                          ),
                        ),
                      ],
                    ),
                  ),

                  const Spacer(flex: 2),

                  // ── Pregunta ──────────────────────────────────
                  AnimatedBuilder(
                    animation: _cardsController,
                    builder: (context, child) => Opacity(
                      opacity: _cardsFade.value,
                      child: child,
                    ),
                    child: Column(
                      children: [
                        const Text(
                          '¿Cómo quieres\nentrar hoy?',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 26,
                            fontWeight: FontWeight.w800,
                            height: 1.2,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          'Elige tu rol para continuar',
                          style: TextStyle(
                            color: Colors.white.withOpacity(0.45),
                            fontSize: 14,
                          ),
                        ),
                      ],
                    ),
                  ),

                  const SizedBox(height: 28),

                  // ── Tarjetas ──────────────────────────────────
                  AnimatedBuilder(
                    animation: _cardsController,
                    builder: (context, _) => Row(
                      children: [
                        // ── PASAJERO ────────────────────────────
                        Expanded(
                          child: SlideTransition(
                            position: _passengerSlide,
                            child: FadeTransition(
                              opacity: _cardsFade,
                              child: _RoleCard(
                                icon: Icons.person_rounded,
                                title: 'Soy\nPasajero',
                                subtitle: 'Pide tu viaje\nfácil y rápido',
                                gradientColors: const [
                                  Color(0xFF7C3AED),
                                  Color(0xFF4F46E5),
                                ],
                                glowColor: Color(0xFF7C3AED),
                                onTap: () => Navigator.pushNamed(
                                  context,
                                  SignInPage.route,
                                ),
                              ),
                            ),
                          ),
                        ),

                        const SizedBox(width: 16),

                        // ── CONDUCTOR ───────────────────────────
                        Expanded(
                          child: SlideTransition(
                            position: _driverSlide,
                            child: FadeTransition(
                              opacity: _cardsFade,
                              child: _RoleCard(
                                icon: Icons.drive_eta_rounded,
                                title: 'Soy\nConductor',
                                subtitle: 'Maneja y\ngenera ingresos',
                                gradientColors: const [
                                  Color(0xFF0EA5E9),
                                  Color(0xFF2563EB),
                                ],
                                glowColor: Color(0xFF0EA5E9),
                                onTap: () => Navigator.pushNamed(
                                  context,
                                  DriverLoginScreen.route,
                                ),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),

                  const Spacer(flex: 2),

                  // ── Footer ────────────────────────────────────
                  AnimatedBuilder(
                    animation: _cardsController,
                    builder: (context, child) => Opacity(
                      opacity: _cardsFade.value,
                      child: child,
                    ),
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 24),
                      child: Text(
                        'Al continuar aceptas nuestros Términos de Uso\ny Política de Privacidad',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: Colors.white.withOpacity(0.28),
                          fontSize: 11.5,
                          height: 1.5,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Tarjeta de rol ────────────────────────────────────────────
class _RoleCard extends StatefulWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final List<Color> gradientColors;
  final Color glowColor;
  final VoidCallback onTap;

  const _RoleCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.gradientColors,
    required this.glowColor,
    required this.onTap,
  });

  @override
  _RoleCardState createState() => _RoleCardState();
}

class _RoleCardState extends State<_RoleCard>
    with SingleTickerProviderStateMixin {
  late AnimationController _pressController;
  late Animation<double> _scaleAnim;

  @override
  void initState() {
    super.initState();
    _pressController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 120),
      lowerBound: 0.0,
      upperBound: 1.0,
    );
    _scaleAnim = Tween<double>(begin: 1.0, end: 0.95).animate(
      CurvedAnimation(parent: _pressController, curve: Curves.easeInOut),
    );
  }

  @override
  void dispose() {
    _pressController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return GestureDetector(
      onTapDown: (_) => _pressController.forward(),
      onTapUp: (_) {
        _pressController.reverse();
        widget.onTap();
      },
      onTapCancel: () => _pressController.reverse(),
      child: AnimatedBuilder(
        animation: _scaleAnim,
        builder: (context, child) => Transform.scale(
          scale: _scaleAnim.value,
          child: child,
        ),
        child: Container(
          height: size.height * 0.26,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(26),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                widget.gradientColors[0].withOpacity(0.85),
                widget.gradientColors[1],
              ],
            ),
            boxShadow: [
              BoxShadow(
                color: widget.glowColor.withOpacity(0.30),
                blurRadius: 24,
                offset: const Offset(0, 10),
              ),
            ],
            border: Border.all(
              color: Colors.white.withOpacity(0.12),
              width: 1,
            ),
          ),
          child: Stack(
            children: [
              // Brillo interno
              Positioned(
                top: -20,
                right: -20,
                child: Container(
                  width: 90,
                  height: 90,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Colors.white.withOpacity(0.07),
                  ),
                ),
              ),

              Padding(
                padding: const EdgeInsets.all(22),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Ícono
                    Container(
                      width: 52,
                      height: 52,
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.18),
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: Icon(
                        widget.icon,
                        color: Colors.white,
                        size: 28,
                      ),
                    ),
                    const Spacer(),
                    // Título
                    Text(
                      widget.title,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w800,
                        height: 1.15,
                      ),
                    ),
                    const SizedBox(height: 6),
                    // Subtítulo
                    Text(
                      widget.subtitle,
                      style: TextStyle(
                        color: Colors.white.withOpacity(0.72),
                        fontSize: 12,
                        height: 1.4,
                      ),
                    ),
                    const SizedBox(height: 12),
                    // Flecha
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 12,
                            vertical: 6,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.white.withOpacity(0.18),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: const [
                              Text(
                                'Entrar',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 12,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                              SizedBox(width: 4),
                              Icon(
                                Icons.arrow_forward_rounded,
                                color: Colors.white,
                                size: 14,
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
