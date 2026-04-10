import 'dart:async';
import 'package:flutter/material.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Networking/ApiProvider.dart';
import 'package:super_ia/UI/views/DriverHomeScreen.dart';
import 'package:super_ia/UI/views/DriverLoginScreen.dart';

class DriverWaitingScreen extends StatefulWidget {
  static const String route = '/driver_waiting';

  /// Si se conoce el conductor_id (recién registrado), se usa para polling.
  final int? conductorId;

  const DriverWaitingScreen({Key? key, this.conductorId}) : super(key: key);

  @override
  _DriverWaitingScreenState createState() => _DriverWaitingScreenState();
}

class _DriverWaitingScreenState extends State<DriverWaitingScreen> {
  final _api = ApiProvider();
  Timer? _timer;

  bool _cargando = true;
  int  _verificado = 0;
  List<_DocEstado> _documentos = [];

  static const _tiposOrden = [
    'licencia_frente', 'licencia_reverso', 'cedula', 'soat', 'matricula', 'vinculacion_cooperativa',
  ];
  static const _nombres = {
    'licencia_frente':  'Licencia (frente)',
    'licencia_reverso': 'Licencia (reverso)',
    'cedula':           'Cédula de identidad',
    'soat':             'SOAT / Seguro',
    'matricula':        'Matrícula',
    'vinculacion_cooperativa': 'Documento de Cooperativa',
  };

  @override
  void initState() {
    super.initState();
    if (widget.conductorId != null) {
      _cargarEstado();
      _timer = Timer.periodic(const Duration(seconds: 15), (_) => _cargarEstado());
    } else {
      setState(() => _cargando = false);
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _cargarEstado() async {
    if (widget.conductorId == null) return;
    final resp = await _api.obtenerDocumentosConductor(widget.conductorId!);
    if (!mounted) return;

    if (resp['status'] == 'success') {
      final docs = (resp['documentos'] as List? ?? [])
          .map((d) => _DocEstado(
                tipo:   d['tipo'] ?? '',
                estado: d['estado'] ?? 'pendiente',
                notas:  d['notas'] ?? '',
              ))
          .toList();

      // Ordenar según _tiposOrden
      docs.sort((a, b) =>
          (_tiposOrden.indexOf(a.tipo)).compareTo(_tiposOrden.indexOf(b.tipo)));

      final verificado = (resp['verificado'] as int?) ?? 0;

      setState(() {
        _verificado  = verificado;
        _documentos  = docs;
        _cargando    = false;
      });

      // Si ya fue aprobado, ir directo al home
      if (verificado == 1 && mounted) {
        _timer?.cancel();
        Navigator.pushReplacementNamed(context, DriverHomeScreen.route);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    final tieneDocs = _documentos.isNotEmpty;

    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      body: Stack(
        children: [
          Positioned(
            top: 0, left: 0, right: 0,
            child: Container(
              height: size.height * 0.45,
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft, end: Alignment.bottomRight,
                  colors: [Color(0xFF0F0C29), Color(0xFF302B63), Color(0xFF24243E)],
                ),
              ),
            ),
          ),
          Positioned(top: -size.width * 0.1, right: -size.width * 0.12,
            child: Container(
              width: size.width * 0.5, height: size.width * 0.5,
              decoration: BoxDecoration(shape: BoxShape.circle,
                color: Colors.white.withOpacity(0.05)),
            ),
          ),

          SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Column(
                children: [
                  const SizedBox(height: 24),

                  // Ícono principal
                  Container(
                    width: 100, height: 100,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: ConstantColors.buttonGradient,
                      boxShadow: [BoxShadow(
                        color: ConstantColors.primaryViolet.withOpacity(0.35),
                        blurRadius: 28, offset: const Offset(0, 10),
                      )],
                    ),
                    child: const Icon(Icons.access_time_filled_rounded, color: Colors.white, size: 46),
                  ),
                  const SizedBox(height: 24),

                  const Text('¡Registro completado!',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 10),
                  Text('Tu cuenta está siendo revisada',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: ConstantColors.primaryBlue, fontSize: 15, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 6),
                  Text('El proceso tarda entre 1 a 3 días hábiles.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 13)),

                  const SizedBox(height: 28),

                  // ── Estado de documentos ──────────────────────
                  if (_cargando)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 20),
                      child: CircularProgressIndicator(
                        strokeWidth: 2.5,
                        valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                      ),
                    )
                  else if (tieneDocs) ...[
                    Container(
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: Colors.black.withOpacity(0.22),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: ConstantColors.borderColor.withOpacity(0.6)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(children: [
                            Icon(Icons.fact_check_outlined, color: ConstantColors.primaryBlue, size: 20),
                            const SizedBox(width: 10),
                            const Text('Estado de tus documentos',
                              style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700)),
                          ]),
                          const SizedBox(height: 16),
                          ..._documentos.map(_buildDocRow).toList(),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    // Leyenda
                    Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      _leyendaChip('Pendiente', Colors.orange),
                      const SizedBox(width: 10),
                      _leyendaChip('Aprobado', Colors.greenAccent),
                      const SizedBox(width: 10),
                      _leyendaChip('Rechazado', Colors.redAccent),
                    ]),
                  ] else ...[
                    // Sin conductor_id: pasos visuales estáticos
                    Container(
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: Colors.black.withOpacity(0.22),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: ConstantColors.borderColor.withOpacity(0.6)),
                      ),
                      child: Column(children: [
                        _pasoItem(Icons.check_circle_outline, Colors.greenAccent, 'Datos recibidos',
                            'Tu información y documentos fueron enviados.'),
                        const SizedBox(height: 14),
                        _pasoItem(Icons.manage_accounts_outlined, ConstantColors.primaryBlue, 'Revisión del administrador',
                            'Un administrador revisará tu información y documentos.'),
                        const SizedBox(height: 14),
                        _pasoItem(Icons.directions_car_outlined, ConstantColors.primaryViolet, 'Cuenta activada',
                            'Recibirás acceso al panel de conductor cuando seas aprobado.'),
                      ]),
                    ),
                  ],

                  const SizedBox(height: 32),

                  // Botón ir al login
                  SizedBox(
                    width: double.infinity, height: 54,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: ConstantColors.buttonGradient,
                        borderRadius: BorderRadius.circular(16),
                        boxShadow: [BoxShadow(
                          color: ConstantColors.primaryViolet.withOpacity(0.28),
                          blurRadius: 20, offset: const Offset(0, 8),
                        )],
                      ),
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.transparent, shadowColor: Colors.transparent,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                        ),
                        onPressed: () => Navigator.pushReplacementNamed(context, DriverLoginScreen.route),
                        child: Row(mainAxisAlignment: MainAxisAlignment.center, children: const [
                          Icon(Icons.login_rounded, color: Colors.white, size: 18),
                          SizedBox(width: 8),
                          Text('Ir al Login', style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700)),
                        ]),
                      ),
                    ),
                  ),

                  const SizedBox(height: 32),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildDocRow(_DocEstado doc) {
    final nombre = _nombres[doc.tipo] ?? doc.tipo;
    Color color;
    IconData icon;
    switch (doc.estado) {
      case 'aprobado':
        color = Colors.greenAccent; icon = Icons.check_circle_rounded; break;
      case 'rechazado':
        color = Colors.redAccent; icon = Icons.cancel_rounded; break;
      default:
        color = Colors.orange; icon = Icons.hourglass_top_rounded;
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(width: 10),
        Expanded(child: Text(nombre,
          style: const TextStyle(color: Colors.white, fontSize: 13))),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
          decoration: BoxDecoration(
            color: color.withOpacity(0.12),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Text(
            doc.estado == 'aprobado' ? 'Aprobado'
                : doc.estado == 'rechazado' ? 'Rechazado'
                : 'Pendiente',
            style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700),
          ),
        ),
      ]),
    );
  }

  Widget _leyendaChip(String label, Color color) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Container(width: 8, height: 8, decoration: BoxDecoration(shape: BoxShape.circle, color: color)),
      const SizedBox(width: 4),
      Text(label, style: TextStyle(color: ConstantColors.textGrey, fontSize: 11)),
    ],
  );

  Widget _pasoItem(IconData icon, Color color, String titulo, String sub) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Container(
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(color: color.withOpacity(0.14), borderRadius: BorderRadius.circular(10)),
        child: Icon(icon, color: color, size: 20),
      ),
      const SizedBox(width: 14),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(titulo, style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w600)),
        const SizedBox(height: 2),
        Text(sub, style: const TextStyle(color: ConstantColors.textGrey, fontSize: 12, height: 1.4)),
      ])),
    ],
  );
}

class _DocEstado {
  final String tipo;
  final String estado;
  final String notas;
  const _DocEstado({required this.tipo, required this.estado, required this.notas});
}
