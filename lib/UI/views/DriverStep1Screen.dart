import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/DriverRegistrationData.dart';
import 'package:fu_uber/UI/views/DriverStep2Screen.dart';

class DriverStep1Screen extends StatelessWidget {
  static const String route = '/driver_step1';

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
        children: [
          // Fondo
          Positioned(
            top: 0, left: 0, right: 0,
            child: Container(
              height: size.height * 0.45,
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [Color(0xFF0F0C29), Color(0xFF302B63), Color(0xFF24243E)],
                ),
              ),
            ),
          ),
          // Orbes
          Positioned(
            top: -size.width * 0.1, right: -size.width * 0.1,
            child: Container(
              width: size.width * 0.5, height: size.width * 0.5,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withOpacity(0.05),
              ),
            ),
          ),

          SafeArea(
            child: Column(
              children: [
                // AppBar
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 4, 16, 0),
                  child: Row(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.arrow_back, color: Colors.white),
                        onPressed: () => Navigator.pop(context),
                      ),
                      const Text(
                        'Registro de Conductor',
                        style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),

                // Progreso
                _buildProgress(context, paso: 1, total: 6, label: 'Zona de operación'),

                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 24),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        // Icono grande
                        Container(
                          width: 110, height: 110,
                          decoration: BoxDecoration(
                            gradient: ConstantColors.buttonGradient,
                            shape: BoxShape.circle,
                            boxShadow: [
                              BoxShadow(
                                color: ConstantColors.primaryViolet.withOpacity(0.35),
                                blurRadius: 30, offset: const Offset(0, 12),
                              ),
                            ],
                          ),
                          child: const Icon(Icons.location_city_rounded, color: Colors.white, size: 52),
                        ),
                        const SizedBox(height: 28),

                        const Text(
                          'Tu zona de operación',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800),
                        ),
                        const SizedBox(height: 12),
                        Text(
                          'GeoMove actualmente opera en Cuenca, Ecuador. Podrás manejar dentro de esta ciudad y sus alrededores.',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: Colors.white.withOpacity(0.65),
                            fontSize: 15, height: 1.5,
                          ),
                        ),
                        const SizedBox(height: 32),

                        // Tarjeta de ciudad
                        Container(
                          padding: const EdgeInsets.all(20),
                          decoration: BoxDecoration(
                            color: Colors.black.withOpacity(0.25),
                            borderRadius: BorderRadius.circular(20),
                            border: Border.all(color: ConstantColors.borderColor.withOpacity(0.7)),
                          ),
                          child: Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  gradient: ConstantColors.buttonGradient,
                                  borderRadius: BorderRadius.circular(14),
                                ),
                                child: const Icon(Icons.location_on_rounded, color: Colors.white, size: 24),
                              ),
                              const SizedBox(width: 16),
                              Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text('Ciudad confirmada',
                                    style: TextStyle(color: ConstantColors.textGrey, fontSize: 12)),
                                  const SizedBox(height: 4),
                                  const Text('Cuenca, Ecuador',
                                    style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800)),
                                ],
                              ),
                              const Spacer(),
                              Container(
                                padding: const EdgeInsets.all(6),
                                decoration: BoxDecoration(
                                  color: Colors.greenAccent.withOpacity(0.15),
                                  shape: BoxShape.circle,
                                ),
                                child: const Icon(Icons.check_circle, color: Colors.greenAccent, size: 22),
                              ),
                            ],
                          ),
                        ),

                        const SizedBox(height: 40),

                        // Botón continuar
                        SizedBox(
                          width: double.infinity, height: 54,
                          child: DecoratedBox(
                            decoration: BoxDecoration(
                              gradient: ConstantColors.buttonGradient,
                              borderRadius: BorderRadius.circular(16),
                              boxShadow: [
                                BoxShadow(
                                  color: ConstantColors.primaryViolet.withOpacity(0.3),
                                  blurRadius: 20, offset: const Offset(0, 8),
                                ),
                              ],
                            ),
                            child: ElevatedButton(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: Colors.transparent, shadowColor: Colors.transparent,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                              ),
                              onPressed: () {
                                final data = DriverRegistrationData(ciudad: 'Cuenca');
                                Navigator.push(context, MaterialPageRoute(
                                  builder: (_) => DriverStep2Screen(data: data),
                                ));
                              },
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: const [
                                  Text('Confirmar y continuar',
                                    style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700)),
                                  SizedBox(width: 8),
                                  Icon(Icons.arrow_forward_rounded, color: Colors.white, size: 18),
                                ],
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
          ),
        ],
      ),
    );
  }
}

// ── Widget reutilizable de progreso ───────────────────────────
Widget _buildProgress(BuildContext context, {required int paso, required int total, required String label}) {
  return Padding(
    padding: const EdgeInsets.fromLTRB(24, 12, 24, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text('Paso $paso de $total — $label',
              style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
            Text('${((paso / total) * 100).round()}%',
              style: TextStyle(color: ConstantColors.primaryBlue, fontSize: 13, fontWeight: FontWeight.w700)),
          ],
        ),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: paso / total,
            minHeight: 6,
            backgroundColor: Colors.white.withOpacity(0.12),
            valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryBlue),
          ),
        ),
      ],
    ),
  );
}
