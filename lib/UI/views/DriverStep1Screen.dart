import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Models/DriverRegistrationData.dart';
import 'package:super_ia/Core/Services/OsmService.dart';
import 'package:super_ia/UI/views/DriverStep2Screen.dart';

class DriverStep1Screen extends StatefulWidget {
  static const String route = '/driver_step1';

  @override
  _DriverStep1ScreenState createState() => _DriverStep1ScreenState();
}

class _DriverStep1ScreenState extends State<DriverStep1Screen> {
  final _data = DriverRegistrationData();
  bool _loadingLocation = true;
  String _detectedLocation = 'Detectando ubicación...';

  final _cantonCtrl = TextEditingController();
  final _provinciaCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _initLocation();
  }

  Future<void> _initLocation() async {
    try {
      LocationPermission permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.whileInUse || permission == LocationPermission.always) {
        Position position = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high);
        final address = await OsmService.obtenerNombreLugar(position.latitude, position.longitude);
        
        // Intentar extraer cantón/ciudad de la dirección (el formato de OSM varía)
        // Por ahora lo pre-poblamos con la dirección completa para que el usuario la limpie si desea
        if (mounted) {
          setState(() {
            _detectedLocation = address;
            _cantonCtrl.text = _extraerDato(address, 0); // Aproximacion
            _provinciaCtrl.text = _extraerDato(address, 1);
            _loadingLocation = false;
          });
        }
      } else {
        if (mounted) setState(() { _detectedLocation = 'Permiso denegado'; _loadingLocation = false; });
      }
    } catch (e) {
      if (mounted) setState(() { _detectedLocation = 'Error al detectar'; _loadingLocation = false; });
    }
  }

  String _extraerDato(String address, int index) {
    var partes = address.split(',');
    if (partes.length > index) return partes[partes.length - 1 - index].trim();
    return '';
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
        children: [
          Positioned(top: 0, left: 0, right: 0,
            child: Container(height: size.height * 0.45,
              decoration: const BoxDecoration(
                gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight,
                  colors: [Color(0xFF0F0C29), Color(0xFF302B63), Color(0xFF24243E)]),
              ),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 4, 16, 0),
                  child: Row(children: [
                    IconButton(icon: const Icon(Icons.arrow_back, color: Colors.white),
                      onPressed: () => Navigator.pop(context)),
                    const Text('Registro de Conductor',
                      style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700)),
                  ]),
                ),
                _buildProgress(paso: 1, total: 6, label: 'Zona de operación'),
                Expanded(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.symmetric(horizontal: 24),
                    child: Column(
                      children: [
                        const SizedBox(height: 20),
                        Container(
                          width: 100, height: 100,
                          decoration: BoxDecoration(gradient: ConstantColors.buttonGradient, shape: BoxShape.circle,
                            boxShadow: [BoxShadow(color: ConstantColors.primaryViolet.withOpacity(0.3), blurRadius: 25, offset: const Offset(0, 10))],
                          ),
                          child: const Icon(Icons.public_rounded, color: Colors.white, size: 48),
                        ),
                        const SizedBox(height: 24),
                        const Text('Tu zona de operación', textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 10),
                        Text('Super_IA Mobile opera en múltiples regiones. Confirma tu ubicación para ver las cooperativas disponibles.',
                          textAlign: TextAlign.center, style: TextStyle(color: Colors.white.withOpacity(0.6), fontSize: 14)),
                        
                        const SizedBox(height: 30),

                        // Formulario Geografico
                        Container(
                          padding: const EdgeInsets.all(20),
                          decoration: BoxDecoration(
                            color: Colors.black.withOpacity(0.25),
                            borderRadius: BorderRadius.circular(24),
                            border: Border.all(color: ConstantColors.borderColor.withOpacity(0.6)),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(children: [
                                Icon(Icons.location_searching_rounded, color: ConstantColors.primaryBlue, size: 18),
                                const SizedBox(width: 8),
                                Text(_loadingLocation ? 'Detectando...' : 'Ubicación detectada',
                                  style: const TextStyle(color: ConstantColors.textGrey, fontSize: 13, fontWeight: FontWeight.w600)),
                              ]),
                              const SizedBox(height: 6),
                              Text(_detectedLocation, style: const TextStyle(color: Colors.white, fontSize: 14, fontStyle: FontStyle.italic)),
                              const Divider(height: 32, color: Colors.white10),
                              
                              _geoField(controller: _cantonCtrl, label: 'Ciudad / Cantón', icon: Icons.location_city_rounded),
                              const SizedBox(height: 12),
                              _geoField(controller: _provinciaCtrl, label: 'Provincia / Estado', icon: Icons.map_outlined),
                            ],
                          ),
                        ),

                        const SizedBox(height: 40),

                        SizedBox(width: double.infinity, height: 54,
                          child: DecoratedBox(
                            decoration: BoxDecoration(gradient: ConstantColors.buttonGradient, borderRadius: BorderRadius.circular(16),
                              boxShadow: [BoxShadow(color: ConstantColors.primaryViolet.withOpacity(0.3), blurRadius: 20, offset: const Offset(0, 8))],
                            ),
                            child: ElevatedButton(
                              style: ElevatedButton.styleFrom(backgroundColor: Colors.transparent, shadowColor: Colors.transparent,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))),
                              onPressed: () {
                                if (_cantonCtrl.text.trim().isEmpty) {
                                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Ingresa tu ciudad o cantón')));
                                  return;
                                }
                                _data.canton = _cantonCtrl.text.trim();
                                _data.ciudad = _cantonCtrl.text.trim();
                                _data.provincia = _provinciaCtrl.text.trim();
                                _data.pais = 'Ecuador'; // Valor por defecto o detectado
                                
                                Navigator.push(context, MaterialPageRoute(
                                  builder: (_) => DriverStep2Screen(data: _data),
                                ));
                              },
                              child: Row(mainAxisAlignment: MainAxisAlignment.center, children: const [
                                Text('Confirmar y continuar', style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700)),
                                SizedBox(width: 8),
                                Icon(Icons.arrow_forward_rounded, color: Colors.white, size: 18),
                              ]),
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

  Widget _geoField({required TextEditingController controller, required String label, required IconData icon}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12, fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        TextField(
          controller: controller,
          style: const TextStyle(color: Colors.white, fontSize: 15),
          decoration: InputDecoration(
            prefixIcon: Icon(icon, color: ConstantColors.primaryBlue, size: 20),
            filled: true, fillColor: Colors.white.withOpacity(0.04),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
            contentPadding: const EdgeInsets.symmetric(vertical: 12),
          ),
        ),
      ],
    );
  }

  Widget _buildProgress({required int paso, required int total, required String label}) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text('Paso $paso de $total — $label', style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
          Text('${((paso / total) * 100).round()}%', style: TextStyle(color: ConstantColors.primaryBlue, fontSize: 13, fontWeight: FontWeight.w700)),
        ]),
        const SizedBox(height: 8),
        ClipRRect(borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(value: paso / total, minHeight: 6, backgroundColor: Colors.white.withOpacity(0.12), valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryBlue)),
        ),
      ]),
    );
  }
}
