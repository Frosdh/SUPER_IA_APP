import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';

// ─────────────────────────────────────────────────────────────
//  Tipos de producto
// ─────────────────────────────────────────────────────────────
enum ProductoTipo { cuentaCorriente, cuentaAhorros, inversiones, credito }

extension ProductoTipoExt on ProductoTipo {
  String get key {
    switch (this) {
      case ProductoTipo.cuentaCorriente: return 'cuenta_corriente';
      case ProductoTipo.cuentaAhorros:  return 'cuenta_ahorros';
      case ProductoTipo.inversiones:    return 'inversiones';
      case ProductoTipo.credito:        return 'credito';
    }
  }

  String get titulo {
    switch (this) {
      case ProductoTipo.cuentaCorriente: return 'Ficha: Cuenta Corriente';
      case ProductoTipo.cuentaAhorros:  return 'Ficha: Cuenta de Ahorros';
      case ProductoTipo.inversiones:    return 'Ficha: Inversiones';
      case ProductoTipo.credito:        return 'Ficha: Crédito';
    }
  }

  IconData get icono {
    switch (this) {
      case ProductoTipo.cuentaCorriente: return Icons.account_balance_rounded;
      case ProductoTipo.cuentaAhorros:  return Icons.savings_rounded;
      case ProductoTipo.inversiones:    return Icons.trending_up_rounded;
      case ProductoTipo.credito:        return Icons.credit_score_rounded;
    }
  }

  Color get color {
    switch (this) {
      case ProductoTipo.cuentaCorriente: return const Color(0xFF3B82F6);
      case ProductoTipo.cuentaAhorros:  return const Color(0xFF10B981);
      case ProductoTipo.inversiones:    return const Color(0xFF8B5CF6);
      case ProductoTipo.credito:        return const Color(0xFFF59E0B);
    }
  }
}

// ─────────────────────────────────────────────────────────────
//  Pantalla principal
// ─────────────────────────────────────────────────────────────
class EncuestaProductoScreen extends StatefulWidget {
  final ProductoTipo tipo;
  final String clienteCedula;
  final String clienteNombre;

  const EncuestaProductoScreen({
    Key? key,
    required this.tipo,
    required this.clienteCedula,
    required this.clienteNombre,
  }) : super(key: key);

  @override
  State<EncuestaProductoScreen> createState() => _EncuestaProductoScreenState();
}

class _EncuestaProductoScreenState extends State<EncuestaProductoScreen> {
  bool _guardando = false;

  // GPS auto
  double? _lat, _lng;
  String _horaGps = '';

  // ── CRÉDITO ─────────────────────────────────────────────────
  bool? _requiereCredito;
  // Destino crédito (single-select)
  String? _destinoCredito;
  final _destOtrosCtrl   = TextEditingController();
  final _montoCredCtrl   = TextEditingController();
  final _plazoCredCtrl   = TextEditingController();

  // Solicitante
  final _solNombreCtrl          = TextEditingController();
  final _solCedulaCtrl          = TextEditingController();
  final _solCelularCtrl         = TextEditingController();
  String? _solEstadoCivil;       // 'soltero'|'casado'|'divorciado'|'viudo'|'union_libre'
  final _solConyugeNombreCtrl   = TextEditingController();
  final _solConyugeCedulaCtrl   = TextEditingController();
  final _solConyugeCelularCtrl  = TextEditingController();

  // Garante
  final _garNombreCtrl          = TextEditingController();
  final _garCedulaCtrl          = TextEditingController();
  final _garCelularCtrl         = TextEditingController();
  String? _garEstadoCivil;       // 'soltero'|'casado'|'divorciado'|'viudo'|'union_libre'
  final _garConyugeNombreCtrl   = TextEditingController();
  final _garConyugeCedulaCtrl   = TextEditingController();
  final _garConyugeCelularCtrl  = TextEditingController();

  // Dirección levantada en sitio
  final _direccionSitioCtrl     = TextEditingController();

  // ¿Tiene empresa/negocio? → controla si se muestra Levantamiento
  bool? _tieneEmpresa;

  // Levantamiento campo — ventas
  final _ventaLvCtrl     = TextEditingController();
  final _ventaSabCtrl    = TextEditingController();
  final _ventaDomCtrl    = TextEditingController();
  String? _mesAltaVenta;
  String? _mesBajaVenta;
  // Levantamiento campo — compras
  final _compraLvCtrl    = TextEditingController();
  final _compraSabCtrl   = TextEditingController();
  final _compraDomCtrl   = TextEditingController();
  String? _mesAltaCompra;
  // Días de atención
  bool _diaLv  = false;
  bool _diaSab = false;
  bool _diaDom = false;
  // Checklist documentos recibidos
  bool _docCedula        = false;
  bool _docPlanilla      = false;
  bool _docRucRise       = false;
  bool _docEstados       = false;
  bool _docDeclaraciones = false;
  bool _docMatricula     = false;
  bool _docFotoNegocio   = false;
  bool _docSolicitudCred = false;
  bool _docFotoCliente   = false;

  // ── CUENTA CORRIENTE ────────────────────────────────────────
  String? _tipoCC;  // 'personal' | 'empresarial'
  final _propositoCCCtrl    = TextEditingController();
  final _montoDepositoCCCtrl = TextEditingController();
  bool? _usaCheques;
  bool? _requiereTD;
  final _ingresoMensualCCCtrl = TextEditingController();
  bool? _tieneNomina;
  final _obsCCCtrl = TextEditingController();

  // ── CUENTA DE AHORROS ───────────────────────────────────────
  String? _tipoAhorro; // 'normal'|'programado'|'infantil'|'otro'
  final _montoInicialAhCtrl = TextEditingController();
  String? _frecuenciaAhorro; // 'diaria'|'semanal'|'quincenal'|'mensual'
  final _objetivoAhCtrl = TextEditingController();
  bool? _tieneAhorroOtraInst;
  final _instAhCtrl = TextEditingController();
  final _obsAhCtrl  = TextEditingController();

  // ── INVERSIONES ─────────────────────────────────────────────
  String? _tipoInversion; // 'dpf'|'acciones'|'otro'
  final _montoInvCtrl   = TextEditingController();
  final _plazoInvCtrl   = TextEditingController();
  String? _objetivoInv; // 'rendimiento_fijo'|'capitalizacion'|'crecimiento'|'otro'
  bool? _tieneInvOtra;
  bool? _renovacionAuto;
  final _obsInvCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _obtenerGPS();
  }

  @override
  void dispose() {
    _destOtrosCtrl.dispose();
    _montoCredCtrl.dispose();
    _plazoCredCtrl.dispose();
    _solNombreCtrl.dispose();
    _solCedulaCtrl.dispose();
    _solCelularCtrl.dispose();
    _solConyugeNombreCtrl.dispose();
    _solConyugeCedulaCtrl.dispose();
    _solConyugeCelularCtrl.dispose();
    _garNombreCtrl.dispose();
    _garCedulaCtrl.dispose();
    _garCelularCtrl.dispose();
    _garConyugeNombreCtrl.dispose();
    _garConyugeCedulaCtrl.dispose();
    _garConyugeCelularCtrl.dispose();
    _direccionSitioCtrl.dispose();
    _ventaLvCtrl.dispose();
    _ventaSabCtrl.dispose();
    _ventaDomCtrl.dispose();
    _compraLvCtrl.dispose();
    _compraSabCtrl.dispose();
    _compraDomCtrl.dispose();
    _propositoCCCtrl.dispose();
    _montoDepositoCCCtrl.dispose();
    _ingresoMensualCCCtrl.dispose();
    _obsCCCtrl.dispose();
    _montoInicialAhCtrl.dispose();
    _objetivoAhCtrl.dispose();
    _instAhCtrl.dispose();
    _obsAhCtrl.dispose();
    _montoInvCtrl.dispose();
    _plazoInvCtrl.dispose();
    _obsInvCtrl.dispose();
    super.dispose();
  }

  Future<void> _obtenerGPS() async {
    try {
      final pos = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      ).timeout(const Duration(seconds: 10));
      final now = DateTime.now();
      if (mounted) {
        setState(() {
          _lat = pos.latitude;
          _lng = pos.longitude;
          _horaGps =
              '${now.hour.toString().padLeft(2, '0')}:${now.minute.toString().padLeft(2, '0')}:${now.second.toString().padLeft(2, '0')}';
        });
      }
    } catch (_) {}
  }

  Future<void> _guardar() async {
    if (_guardando) return;
    setState(() => _guardando = true);

    final usuarioId = await AuthPrefs.getUsuarioId();
    final asesorId  = await AuthPrefs.getAsesorId();

    final body = <String, String>{
      'usuario_id':      usuarioId,
      if (asesorId.isNotEmpty) 'asesor_id': asesorId,
      'producto_tipo':   widget.tipo.key,
      'cliente_cedula':  widget.clienteCedula,
      'cliente_nombre':  widget.clienteNombre,
      'latitud':         (_lat ?? 0).toString(),
      'longitud':        (_lng ?? 0).toString(),
      'hora_gps':        _horaGps,
    };

    switch (widget.tipo) {
      case ProductoTipo.credito:
        body.addAll(_bodyCredito());
        break;
      case ProductoTipo.cuentaCorriente:
        body.addAll(_bodyCC());
        break;
      case ProductoTipo.cuentaAhorros:
        body.addAll(_bodyAhorros());
        break;
      case ProductoTipo.inversiones:
        body.addAll(_bodyInversiones());
        break;
    }

    try {
      final resp = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/guardar_ficha_producto.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: body,
      ).timeout(const Duration(seconds: 20));

      if (!mounted) return;

      Map<String, dynamic>? data;
      try {
        final decoded = json.decode(resp.body);
        if (decoded is Map<String, dynamic>) data = decoded;
      } catch (_) {}

      if (resp.statusCode == 200 && data?['status'] == 'success') {
        _mostrarExito();
      } else {
        _mostrarError(data?['message']?.toString() ?? 'Error al guardar (HTTP ${resp.statusCode})');
      }
    } catch (e) {
      if (!mounted) return;
      _mostrarError('Sin conexión. ($e)');
    } finally {
      if (mounted) setState(() => _guardando = false);
    }
  }

  Map<String, String> _bodyCredito() => {
    'requiere_credito':       _requiereCredito == null ? '' : (_requiereCredito! ? '1' : '0'),
    'destino_credito':        _destinoCredito ?? '',
    'dest_otros_detalle':     _destOtrosCtrl.text.trim(),
    'monto_credito':          _montoCredCtrl.text.trim(),
    'plazo_credito_meses':    _plazoCredCtrl.text.trim(),
    // Solicitante
    'solicitante_nombre':          _solNombreCtrl.text.trim(),
    'solicitante_cedula':          _solCedulaCtrl.text.trim(),
    'solicitante_celular':         _solCelularCtrl.text.trim(),
    'solicitante_estado_civil':    _solEstadoCivil ?? '',
    'solicitante_conyuge_nombre':  _solConyugeNombreCtrl.text.trim(),
    'solicitante_conyuge_cedula':  _solConyugeCedulaCtrl.text.trim(),
    'solicitante_conyuge_celular': _solConyugeCelularCtrl.text.trim(),
    // Garante
    'garante_nombre':              _garNombreCtrl.text.trim(),
    'garante_cedula':              _garCedulaCtrl.text.trim(),
    'garante_celular':             _garCelularCtrl.text.trim(),
    'garante_estado_civil':        _garEstadoCivil ?? '',
    'garante_conyuge_nombre':      _garConyugeNombreCtrl.text.trim(),
    'garante_conyuge_cedula':      _garConyugeCedulaCtrl.text.trim(),
    'garante_conyuge_celular':     _garConyugeCelularCtrl.text.trim(),
    // Dirección
    'direccion_sitio':             _direccionSitioCtrl.text.trim(),
    // Empresa
    'tiene_empresa':               _tieneEmpresa == null ? '' : (_tieneEmpresa! ? '1' : '0'),
    'venta_lv':               _tieneEmpresa == true ? _ventaLvCtrl.text.trim()  : '',
    'venta_sabado':           _tieneEmpresa == true ? _ventaSabCtrl.text.trim() : '',
    'venta_domingo':          _tieneEmpresa == true ? _ventaDomCtrl.text.trim() : '',
    'mes_alta_venta':         _tieneEmpresa == true ? (_mesAltaVenta ?? '') : '',
    'mes_baja_venta':         _tieneEmpresa == true ? (_mesBajaVenta ?? '') : '',
    'compra_lv':              _tieneEmpresa == true ? _compraLvCtrl.text.trim()  : '',
    'compra_sabado':          _tieneEmpresa == true ? _compraSabCtrl.text.trim() : '',
    'compra_domingo':         _tieneEmpresa == true ? _compraDomCtrl.text.trim() : '',
    'mes_alta_compra':        _tieneEmpresa == true ? (_mesAltaCompra ?? '') : '',
    'dias_atencion_lv':       _tieneEmpresa == true ? (_diaLv  ? '1' : '0') : '0',
    'dias_atencion_sab':      _tieneEmpresa == true ? (_diaSab ? '1' : '0') : '0',
    'dias_atencion_dom':      _tieneEmpresa == true ? (_diaDom ? '1' : '0') : '0',
    // Documentos recibidos
    'doc_cedula':             _docCedula          ? '1' : '0',
    'doc_planilla':           _docPlanilla        ? '1' : '0',
    'doc_ruc_rise':           _docRucRise         ? '1' : '0',
    'doc_estados_cuenta':     _docEstados         ? '1' : '0',
    'doc_declaraciones':      _docDeclaraciones   ? '1' : '0',
    'doc_matricula':          _docMatricula       ? '1' : '0',
    'doc_foto_negocio':       _docFotoNegocio     ? '1' : '0',
    'doc_solicitud_credito':  _docSolicitudCred   ? '1' : '0',
    'doc_foto_cliente':       _docFotoCliente     ? '1' : '0',
  };

  Map<String, String> _bodyCC() => {
    'tipo_cc':             _tipoCC ?? '',
    'proposito':           _propositoCCCtrl.text.trim(),
    'monto_deposito_prom': _montoDepositoCCCtrl.text.trim(),
    'usa_cheques':         _usaCheques == null ? '' : (_usaCheques! ? '1' : '0'),
    'requiere_td':         _requiereTD == null ? '' : (_requiereTD! ? '1' : '0'),
    'ingreso_mensual':     _ingresoMensualCCCtrl.text.trim(),
    'tiene_nomina':        _tieneNomina == null ? '' : (_tieneNomina! ? '1' : '0'),
    'observaciones':       _obsCCCtrl.text.trim(),
  };

  Map<String, String> _bodyAhorros() => {
    'tipo_ahorro':          _tipoAhorro ?? '',
    'monto_inicial':        _montoInicialAhCtrl.text.trim(),
    'frecuencia_deposito':  _frecuenciaAhorro ?? '',
    'objetivo_ahorro':      _objetivoAhCtrl.text.trim(),
    'tiene_ahorro_otra':    _tieneAhorroOtraInst == null ? '' : (_tieneAhorroOtraInst! ? '1' : '0'),
    'institucion_ahorro':   _instAhCtrl.text.trim(),
    'observaciones':        _obsAhCtrl.text.trim(),
  };

  Map<String, String> _bodyInversiones() => {
    'tipo_inversion':    _tipoInversion ?? '',
    'monto_inversion':   _montoInvCtrl.text.trim(),
    'plazo_meses':       _plazoInvCtrl.text.trim(),
    'objetivo_inversion':_objetivoInv ?? '',
    'tiene_inv_otra':    _tieneInvOtra == null ? '' : (_tieneInvOtra! ? '1' : '0'),
    'renovacion_auto':   _renovacionAuto == null ? '' : (_renovacionAuto! ? '1' : '0'),
    'observaciones':     _obsInvCtrl.text.trim(),
  };

  void _mostrarExito() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => Dialog(
        backgroundColor: ConstantColors.grey100,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 64,
                height: 64,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: widget.tipo.color.withOpacity(0.15),
                  border: Border.all(color: widget.tipo.color, width: 2),
                ),
                child: Icon(widget.tipo.icono, color: widget.tipo.color, size: 32),
              ),
              const SizedBox(height: 16),
              Text('Ficha Guardada',
                  style: TextStyle(
                    color: ConstantColors.textDark,
                    fontSize: 18,
                    fontWeight: FontWeight.w700,
                  )),
              const SizedBox(height: 8),
              Text(
                'Los datos de ${widget.tipo.titulo.replaceFirst('Ficha: ', '')} han sido guardados.',
                style: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 13),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () {
                    Navigator.of(ctx).pop();
                    Navigator.of(context).pop(true);
                  },
                  style: ElevatedButton.styleFrom(
                    backgroundColor: widget.tipo.color,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: const Text('Volver',
                      style: TextStyle(fontWeight: FontWeight.w700)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _mostrarError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: ConstantColors.error,
      behavior: SnackBarBehavior.floating,
    ));
  }

  // ── BUILD ────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.grey100,
      body: SafeArea(
        child: Column(
          children: [
            _buildHeader(),
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 100),
                child: _buildFormulario(),
              ),
            ),
          ],
        ),
      ),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerFloat,
      floatingActionButton: _buildBotonGuardar(),
    );
  }

  Widget _buildHeader() {
    return Container(
      padding: const EdgeInsets.fromLTRB(8, 12, 16, 12),
      child: Row(
        children: [
          IconButton(
            icon: Icon(Icons.arrow_back_ios_rounded,
                color: ConstantColors.textDark, size: 22),
            onPressed: () => Navigator.pop(context, false),
          ),
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: widget.tipo.color.withOpacity(0.15),
            ),
            child: Icon(widget.tipo.icono, color: widget.tipo.color, size: 20),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(widget.tipo.titulo,
                    style: TextStyle(
                        color: ConstantColors.textDark,
                        fontSize: 15,
                        fontWeight: FontWeight.w700)),
                Text(widget.clienteNombre,
                    style: TextStyle(
                        color: ConstantColors.textDarkGrey, fontSize: 11)),
              ],
            ),
          ),
          // GPS badge
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            decoration: BoxDecoration(
              color: _lat != null
                  ? ConstantColors.success.withOpacity(0.1)
                  : ConstantColors.warning.withOpacity(0.1),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(
                  color: _lat != null
                      ? ConstantColors.success
                      : ConstantColors.warning),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.gps_fixed_rounded,
                    size: 12,
                    color: _lat != null
                        ? ConstantColors.success
                        : ConstantColors.warning),
                const SizedBox(width: 3),
                Text(_lat != null ? 'GPS OK' : 'Sin GPS',
                    style: TextStyle(
                        color: _lat != null
                            ? ConstantColors.success
                            : ConstantColors.warning,
                        fontSize: 10,
                        fontWeight: FontWeight.w600)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFormulario() {
    switch (widget.tipo) {
      case ProductoTipo.credito:        return _formCredito();
      case ProductoTipo.cuentaCorriente: return _formCC();
      case ProductoTipo.cuentaAhorros:  return _formAhorros();
      case ProductoTipo.inversiones:    return _formInversiones();
    }
  }

  Widget _buildBotonGuardar() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: SizedBox(
        width: double.infinity,
        child: Container(
          decoration: BoxDecoration(
            color: widget.tipo.color,
            borderRadius: BorderRadius.circular(14),
            boxShadow: [
              BoxShadow(
                color: widget.tipo.color.withOpacity(0.35),
                blurRadius: 12,
                offset: const Offset(0, 4),
              )
            ],
          ),
          child: ElevatedButton(
            onPressed: _guardando ? null : _guardar,
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.transparent,
              shadowColor: Colors.transparent,
              padding: const EdgeInsets.symmetric(vertical: 15),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(14)),
            ),
            child: _guardando
                ? const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(
                        strokeWidth: 2.5, color: Colors.white),
                  )
                : const Text('Guardar Ficha',
                    style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        fontSize: 15)),
          ),
        ),
      ),
    );
  }

  // ═══════════════════════════════════════════════════════════════
  //  FORMULARIO CRÉDITO
  // ═══════════════════════════════════════════════════════════════

  Widget _formCredito() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // ── Evaluación ──────────────────────────────────────────
        _tarjetaSeccion(
          icon: Icons.credit_score_rounded,
          color: widget.tipo.color,
          titulo: 'Evaluación de Crédito',
          children: [
            _titulo('¿Requiere crédito?'),
            _chipsSiNo(
              value: _requiereCredito,
              onChanged: (v) => setState(() => _requiereCredito = v),
            ),
            if (_requiereCredito == true) ...[
              const SizedBox(height: 14),
              _titulo('Destino del crédito'),
              _destinoChipsGrid(),
              if (_destinoCredito == 'otros')
                _campo(
                  controller: _destOtrosCtrl,
                  label: 'Especifique otro destino',
                  icon: Icons.edit_rounded,
                ),
              const SizedBox(height: 10),
              Row(children: [
                Expanded(
                  child: _campo(
                    controller: _montoCredCtrl,
                    label: 'Monto aproximado (\$)',
                    icon: Icons.attach_money_rounded,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _campo(
                    controller: _plazoCredCtrl,
                    label: 'Plazo (meses)',
                    icon: Icons.calendar_month_rounded,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  ),
                ),
              ]),
            ],
          ],
        ),

        // ── Georreferenciación ──────────────────────────────────
        _tarjetaSeccion(
          icon: Icons.location_on_rounded,
          color: const Color(0xFF10B981),
          titulo: 'Georreferenciación',
          children: [
            Row(children: [
              Icon(Icons.gps_fixed_rounded, size: 16, color: ConstantColors.textDarkGrey),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  _lat != null
                      ? 'Lat: ${_lat!.toStringAsFixed(6)}  Lng: ${_lng!.toStringAsFixed(6)}\nHora: $_horaGps'
                      : 'Obteniendo posición GPS...',
                  style: TextStyle(
                      color: _lat != null
                          ? ConstantColors.textDark
                          : ConstantColors.textDarkGrey,
                      fontSize: 13),
                ),
              ),
              if (_lat == null)
                SizedBox(
                  width: 16, height: 16,
                  child: CircularProgressIndicator(
                    strokeWidth: 2, color: ConstantColors.warning),
                ),
              if (_lat != null)
                Icon(Icons.check_circle_rounded,
                    color: ConstantColors.success, size: 20),
            ]),
          ],
        ),

        // ── Solicitante / Garante ───────────────────────────────
        _tarjetaSeccion(
          icon: Icons.people_rounded,
          color: const Color(0xFF8B5CF6),
          titulo: 'Datos del Solicitante y Garante',
          children: [
            // ── Dirección levantada ──────────────────────────
            _titulo('Dirección levantada en sitio'),
            _campo(
              controller: _direccionSitioCtrl,
              label: 'Dirección del negocio / domicilio',
              icon: Icons.location_on_rounded,
              maxLines: 2,
            ),
            const Divider(height: 20),

            // ── Solicitante ──────────────────────────────────
            _titulo('Solicitante (Deudor)'),
            _campo(controller: _solNombreCtrl, label: 'Nombre completo', icon: Icons.person_rounded),
            Row(children: [
              Expanded(child: _campo(
                controller: _solCedulaCtrl,
                label: 'Cédula de identidad',
                icon: Icons.badge_rounded,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              )),
              const SizedBox(width: 10),
              Expanded(child: _campo(
                controller: _solCelularCtrl,
                label: 'Celular',
                icon: Icons.phone_rounded,
                keyboardType: TextInputType.phone,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              )),
            ]),
            _titulo('Estado civil del solicitante'),
            _chipsOpciones(
              opciones: const [
                ('soltero',     'Soltero/a'),
                ('casado',      'Casado/a'),
                ('union_libre', 'Unión libre'),
                ('divorciado',  'Divorciado/a'),
              ],
              seleccionado: _solEstadoCivil,
              onChanged: (v) => setState(() => _solEstadoCivil = v),
              wrap: true,
            ),
            if (_solEstadoCivil == 'casado' || _solEstadoCivil == 'union_libre') ...[
              const SizedBox(height: 10),
              _titulo('Cónyuge del solicitante'),
              _campo(controller: _solConyugeNombreCtrl, label: 'Nombre completo del cónyuge', icon: Icons.person_add_rounded),
              Row(children: [
                Expanded(child: _campo(
                  controller: _solConyugeCedulaCtrl,
                  label: 'Cédula del cónyuge',
                  icon: Icons.badge_rounded,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                )),
                const SizedBox(width: 10),
                Expanded(child: _campo(
                  controller: _solConyugeCelularCtrl,
                  label: 'Celular del cónyuge',
                  icon: Icons.phone_rounded,
                  keyboardType: TextInputType.phone,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                )),
              ]),
            ],

            const Divider(height: 20),

            // ── Garante ──────────────────────────────────────
            _titulo('Garante (opcional)'),
            _campo(controller: _garNombreCtrl, label: 'Nombre completo del garante', icon: Icons.person_outline_rounded),
            Row(children: [
              Expanded(child: _campo(
                controller: _garCedulaCtrl,
                label: 'Cédula de identidad',
                icon: Icons.badge_outlined,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              )),
              const SizedBox(width: 10),
              Expanded(child: _campo(
                controller: _garCelularCtrl,
                label: 'Celular',
                icon: Icons.phone_outlined,
                keyboardType: TextInputType.phone,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              )),
            ]),
            if (true) ...[   // siempre visible para registrar estado civil del garante
              _titulo('Estado civil del garante'),
              _chipsOpciones(
                opciones: const [
                  ('soltero',     'Soltero/a'),
                  ('casado',      'Casado/a'),
                  ('union_libre', 'Unión libre'),
                  ('divorciado',  'Divorciado/a'),
                ],
                seleccionado: _garEstadoCivil,
                onChanged: (v) => setState(() => _garEstadoCivil = v),
                wrap: true,
              ),
              if (_garEstadoCivil == 'casado' || _garEstadoCivil == 'union_libre') ...[
                const SizedBox(height: 10),
                _titulo('Cónyuge del garante'),
                _campo(controller: _garConyugeNombreCtrl, label: 'Nombre completo del cónyuge', icon: Icons.person_add_alt_rounded),
                Row(children: [
                  Expanded(child: _campo(
                    controller: _garConyugeCedulaCtrl,
                    label: 'Cédula del cónyuge',
                    icon: Icons.badge_rounded,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  )),
                  const SizedBox(width: 10),
                  Expanded(child: _campo(
                    controller: _garConyugeCelularCtrl,
                    label: 'Celular del cónyuge',
                    icon: Icons.phone_rounded,
                    keyboardType: TextInputType.phone,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  )),
                ]),
              ],
            ],
          ],
        ),

        // ── Empresa / Negocio ──────────────────────────────────
        _tarjetaSeccion(
          icon: Icons.storefront_rounded,
          color: const Color(0xFFF59E0B),
          titulo: 'Información de Empresa / Negocio',
          children: [
            _titulo('¿El solicitante tiene empresa o negocio?'),
            _chipsSiNo(
              value: _tieneEmpresa,
              onChanged: (v) => setState(() => _tieneEmpresa = v),
            ),
            if (_tieneEmpresa == true) ...[
              const SizedBox(height: 16),
              const Divider(height: 4),
              const SizedBox(height: 12),
              _titulo('Comportamiento de Ventas (monto \$ al día)'),
              Row(children: [
                Expanded(child: _campo(controller: _ventaLvCtrl,  label: 'Lun – Vie', icon: Icons.trending_up_rounded, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))])),
                const SizedBox(width: 8),
                Expanded(child: _campo(controller: _ventaSabCtrl, label: 'Sábado',    icon: Icons.trending_up_rounded, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))])),
                const SizedBox(width: 8),
                Expanded(child: _campo(controller: _ventaDomCtrl, label: 'Domingo',   icon: Icons.trending_up_rounded, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))])),
              ]),
              Row(children: [
                Expanded(child: _dropdownMes('Mes alta venta', _mesAltaVenta, (v) => setState(() => _mesAltaVenta = v))),
                const SizedBox(width: 10),
                Expanded(child: _dropdownMes('Mes baja venta', _mesBajaVenta, (v) => setState(() => _mesBajaVenta = v))),
              ]),
              const SizedBox(height: 10),
              _titulo('Comportamiento de Compras (monto \$ al día)'),
              Row(children: [
                Expanded(child: _campo(controller: _compraLvCtrl,  label: 'Lun – Vie', icon: Icons.shopping_cart_rounded, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))])),
                const SizedBox(width: 8),
                Expanded(child: _campo(controller: _compraSabCtrl, label: 'Sábado',    icon: Icons.shopping_cart_rounded, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))])),
                const SizedBox(width: 8),
                Expanded(child: _campo(controller: _compraDomCtrl, label: 'Domingo',   icon: Icons.shopping_cart_rounded, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))])),
              ]),
              _dropdownMes('Mes alta compra', _mesAltaCompra, (v) => setState(() => _mesAltaCompra = v)),
              const SizedBox(height: 10),
              _titulo('Días de atención del negocio'),
              Row(children: [
                _chipDia('Lun–Vie', _diaLv,  (v) => setState(() => _diaLv  = v)),
                const SizedBox(width: 8),
                _chipDia('Sábado',  _diaSab, (v) => setState(() => _diaSab = v)),
                const SizedBox(width: 8),
                _chipDia('Domingo', _diaDom, (v) => setState(() => _diaDom = v)),
              ]),
            ],
          ],
        ),

        // ── Checklist documentos ────────────────────────────────
        _tarjetaSeccion(
          icon: Icons.checklist_rounded,
          color: const Color(0xFF3B82F6),
          titulo: 'Documentos para Crédito en Proceso',
          children: [
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                'Toca cada documento que el cliente entregó al asesor:',
                style: TextStyle(fontSize: 12, color: ConstantColors.textDarkGrey),
              ),
            ),
            _docTarjeta(
              key: '_docCedula',
              icon: Icons.badge_rounded,
              label: 'Cédula de identidad',
              sublabel: 'Deudor y cónyuge',
              value: _docCedula,
            ),
            _docTarjeta(
              key: '_docPlanilla',
              icon: Icons.receipt_long_rounded,
              label: 'Planilla de servicios',
              sublabel: 'Agua, luz o teléfono',
              value: _docPlanilla,
            ),
            _docTarjeta(
              key: '_docRucRise',
              icon: Icons.article_rounded,
              label: 'RUC / RISE',
              sublabel: 'Registro tributario',
              value: _docRucRise,
            ),
            _docTarjeta(
              key: '_docEstados',
              icon: Icons.account_balance_rounded,
              label: 'Estados de cuenta',
              sublabel: 'Últimos 3 meses',
              value: _docEstados,
            ),
            _docTarjeta(
              key: '_docDeclaraciones',
              icon: Icons.assignment_rounded,
              label: 'Declaraciones de IVA / IR',
              sublabel: 'Últimas declaraciones',
              value: _docDeclaraciones,
            ),
            _docTarjeta(
              key: '_docMatricula',
              icon: Icons.store_rounded,
              label: 'Matrícula del negocio',
              sublabel: 'Patente municipal',
              value: _docMatricula,
            ),
            _docTarjeta(
              key: '_docFotoNegocio',
              icon: Icons.photo_camera_rounded,
              label: 'Foto del negocio',
              sublabel: 'Fachada y local',
              value: _docFotoNegocio,
            ),
            _docTarjeta(
              key: '_docSolicitudCred',
              icon: Icons.description_rounded,
              label: 'Solicitud de crédito',
              sublabel: 'Formulario firmado',
              value: _docSolicitudCred,
            ),
            _docTarjeta(
              key: '_docFotoCliente',
              icon: Icons.face_rounded,
              label: 'Foto del cliente',
              sublabel: 'Foto para expediente',
              value: _docFotoCliente,
            ),
          ],
        ),

        const SizedBox(height: 20),
      ],
    );
  }

  // ═══════════════════════════════════════════════════════════════
  //  FORMULARIO CUENTA CORRIENTE
  // ═══════════════════════════════════════════════════════════════

  Widget _formCC() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _tarjetaSeccion(
          icon: Icons.account_balance_rounded,
          color: widget.tipo.color,
          titulo: 'Datos de Cuenta Corriente',
          children: [
            _titulo('Tipo de cuenta'),
            _chipsOpciones(
              opciones: [
                ('personal',    'Personal'),
                ('empresarial', 'Empresarial'),
              ],
              seleccionado: _tipoCC,
              onChanged: (v) => setState(() => _tipoCC = v),
            ),
            const SizedBox(height: 12),
            _campo(
              controller: _propositoCCCtrl,
              label: 'Propósito principal de la cuenta',
              icon: Icons.info_outline_rounded,
              maxLines: 2,
            ),
            _campo(
              controller: _montoDepositoCCCtrl,
              label: 'Monto promedio depósito mensual (\$)',
              icon: Icons.attach_money_rounded,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))],
            ),
            _campo(
              controller: _ingresoMensualCCCtrl,
              label: 'Ingreso mensual estimado (\$)',
              icon: Icons.account_balance_wallet_rounded,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))],
            ),
            _titulo('¿Usa cheques frecuentemente?'),
            _chipsSiNo(
              value: _usaCheques,
              onChanged: (v) => setState(() => _usaCheques = v),
            ),
            const SizedBox(height: 12),
            _titulo('¿Requiere tarjeta de débito?'),
            _chipsSiNo(
              value: _requiereTD,
              onChanged: (v) => setState(() => _requiereTD = v),
            ),
            const SizedBox(height: 12),
            _titulo('¿Tiene nómina / sueldo fijo?'),
            _chipsSiNo(
              value: _tieneNomina,
              onChanged: (v) => setState(() => _tieneNomina = v),
            ),
            const SizedBox(height: 12),
            _campo(
              controller: _obsCCCtrl,
              label: 'Observaciones',
              icon: Icons.notes_rounded,
              maxLines: 3,
            ),
          ],
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  // ═══════════════════════════════════════════════════════════════
  //  FORMULARIO CUENTA DE AHORROS
  // ═══════════════════════════════════════════════════════════════

  Widget _formAhorros() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _tarjetaSeccion(
          icon: Icons.savings_rounded,
          color: widget.tipo.color,
          titulo: 'Datos de Cuenta de Ahorros',
          children: [
            _titulo('Tipo de ahorro'),
            _chipsOpciones(
              opciones: [
                ('normal',     'Normal'),
                ('programado', 'Programado'),
                ('infantil',   'Infantil'),
                ('otro',       'Otro'),
              ],
              seleccionado: _tipoAhorro,
              onChanged: (v) => setState(() => _tipoAhorro = v),
              wrap: true,
            ),
            const SizedBox(height: 12),
            _campo(
              controller: _montoInicialAhCtrl,
              label: 'Monto inicial estimado (\$)',
              icon: Icons.attach_money_rounded,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))],
            ),
            _titulo('Frecuencia de depósito'),
            _chipsOpciones(
              opciones: [
                ('diaria',     'Diaria'),
                ('semanal',    'Semanal'),
                ('quincenal',  'Quincenal'),
                ('mensual',    'Mensual'),
              ],
              seleccionado: _frecuenciaAhorro,
              onChanged: (v) => setState(() => _frecuenciaAhorro = v),
              wrap: true,
            ),
            const SizedBox(height: 12),
            _campo(
              controller: _objetivoAhCtrl,
              label: 'Objetivo del ahorro',
              icon: Icons.flag_rounded,
              maxLines: 2,
            ),
            _titulo('¿Tiene ahorro en otra institución?'),
            _chipsSiNo(
              value: _tieneAhorroOtraInst,
              onChanged: (v) => setState(() => _tieneAhorroOtraInst = v),
            ),
            if (_tieneAhorroOtraInst == true) ...[
              const SizedBox(height: 10),
              _campo(
                controller: _instAhCtrl,
                label: '¿En qué institución?',
                icon: Icons.account_balance_rounded,
              ),
            ],
            const SizedBox(height: 4),
            _campo(
              controller: _obsAhCtrl,
              label: 'Observaciones',
              icon: Icons.notes_rounded,
              maxLines: 3,
            ),
          ],
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  // ═══════════════════════════════════════════════════════════════
  //  FORMULARIO INVERSIONES
  // ═══════════════════════════════════════════════════════════════

  Widget _formInversiones() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _tarjetaSeccion(
          icon: Icons.trending_up_rounded,
          color: widget.tipo.color,
          titulo: 'Datos de Inversión',
          children: [
            _titulo('Tipo de inversión'),
            _chipsOpciones(
              opciones: [
                ('dpf',      'DPF'),
                ('acciones', 'Acciones'),
                ('otro',     'Otro'),
              ],
              seleccionado: _tipoInversion,
              onChanged: (v) => setState(() => _tipoInversion = v),
            ),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: _campo(
                controller: _montoInvCtrl,
                label: 'Monto a invertir (\$)',
                icon: Icons.attach_money_rounded,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))],
              )),
              const SizedBox(width: 10),
              Expanded(child: _campo(
                controller: _plazoInvCtrl,
                label: 'Plazo deseado (meses)',
                icon: Icons.calendar_month_rounded,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              )),
            ]),
            _titulo('Objetivo de inversión'),
            _chipsOpciones(
              opciones: [
                ('rendimiento_fijo', 'Rendimiento fijo'),
                ('capitalizacion',   'Capitalización'),
                ('crecimiento',      'Crecimiento'),
                ('otro',             'Otro'),
              ],
              seleccionado: _objetivoInv,
              onChanged: (v) => setState(() => _objetivoInv = v),
              wrap: true,
            ),
            const SizedBox(height: 12),
            _titulo('¿Tiene inversiones en otra institución?'),
            _chipsSiNo(
              value: _tieneInvOtra,
              onChanged: (v) => setState(() => _tieneInvOtra = v),
            ),
            const SizedBox(height: 12),
            _titulo('¿Acepta renovación automática?'),
            _chipsSiNo(
              value: _renovacionAuto,
              onChanged: (v) => setState(() => _renovacionAuto = v),
            ),
            const SizedBox(height: 12),
            _campo(
              controller: _obsInvCtrl,
              label: 'Observaciones',
              icon: Icons.notes_rounded,
              maxLines: 3,
            ),
          ],
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  // ═══════════════════════════════════════════════════════════════
  //  WIDGET HELPERS
  // ═══════════════════════════════════════════════════════════════

  Widget _tarjetaSeccion({
    required IconData icon,
    required Color color,
    required String titulo,
    required List<Widget> children,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withOpacity(0.2)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 8,
            offset: const Offset(0, 2),
          )
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(
                  color: color.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(icon, color: color, size: 18),
              ),
              const SizedBox(width: 10),
              Text(
                titulo,
                style: TextStyle(
                  color: ConstantColors.textDark,
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          ...children,
        ],
      ),
    );
  }

  Widget _titulo(String t) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(t,
            style: TextStyle(
                color: ConstantColors.textDark,
                fontSize: 13,
                fontWeight: FontWeight.w600)),
      );

  Widget _campo({
    required TextEditingController controller,
    required String label,
    required IconData icon,
    TextInputType keyboardType = TextInputType.text,
    List<TextInputFormatter>? inputFormatters,
    int maxLines = 1,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        controller: controller,
        keyboardType: keyboardType,
        inputFormatters: inputFormatters,
        maxLines: maxLines,
        style: TextStyle(color: ConstantColors.textDark, fontSize: 13),
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(icon, color: ConstantColors.warning, size: 18),
          filled: true,
          fillColor: ConstantColors.grey100,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: ConstantColors.borderLight),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: ConstantColors.borderLight),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: ConstantColors.warning, width: 1.5),
          ),
          labelStyle: TextStyle(color: ConstantColors.textDarkGrey, fontSize: 12),
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        ),
      ),
    );
  }

  // ── Grid de chips para Destino del crédito ─────────────────
  Widget _destinoChipsGrid() {
    const destinos = [
      ('cap_trabajo',    Icons.work_rounded,             'Capital de trabajo'),
      ('activos_fijos',  Icons.precision_manufacturing_rounded, 'Activos fijos'),
      ('pago_deudas',    Icons.money_off_rounded,         'Pago de deudas'),
      ('consolidacion',  Icons.merge_type_rounded,        'Consolidación de deudas'),
      ('vehiculo',       Icons.directions_car_rounded,    'Compra de vehículo'),
      ('vivienda_comp',  Icons.home_rounded,              'Compra de vivienda'),
      ('arreglos',       Icons.home_repair_service_rounded,'Arreglos de vivienda'),
      ('educacion',      Icons.school_rounded,            'Educación'),
      ('viajes',         Icons.flight_rounded,            'Viajes'),
      ('otros',          Icons.more_horiz_rounded,        'Otros'),
    ];

    bool valFor(String k) => _destinoCredito == k;

    void toggle(String k) =>
        setState(() => _destinoCredito = (_destinoCredito == k ? null : k));

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        itemCount: destinos.length,
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          mainAxisSpacing: 8,
          crossAxisSpacing: 8,
          childAspectRatio: 3.0,
        ),
        itemBuilder: (_, i) {
          final key   = destinos[i].$1;
          final ic    = destinos[i].$2;
          final label = destinos[i].$3;
          final sel   = valFor(key);
          final color = widget.tipo.color;
          return GestureDetector(
            onTap: () => toggle(key),
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 160),
              decoration: BoxDecoration(
                color: sel ? color.withOpacity(0.13) : ConstantColors.grey100,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(
                  color: sel ? color : ConstantColors.borderLight,
                  width: sel ? 1.8 : 1,
                ),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              child: Row(
                children: [
                  Icon(ic, size: 16,
                      color: sel ? color : ConstantColors.textDarkGrey),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      label,
                      style: TextStyle(
                        fontSize: 12,
                        color: sel ? color : ConstantColors.textDark,
                        fontWeight: sel ? FontWeight.w700 : FontWeight.w500,
                      ),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  if (sel)
                    Icon(Icons.check_circle_rounded, size: 14, color: color),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  // ── Tarjeta de documento (toque para marcar recibido) ───────
  Widget _docTarjeta({
    required String key,
    required IconData icon,
    required String label,
    required String sublabel,
    required bool value,
  }) {
    const color = Color(0xFF3B82F6);
    return GestureDetector(
      onTap: () => setState(() {
        switch (key) {
          case '_docCedula':        _docCedula        = !_docCedula;        break;
          case '_docPlanilla':      _docPlanilla      = !_docPlanilla;      break;
          case '_docRucRise':       _docRucRise       = !_docRucRise;       break;
          case '_docEstados':       _docEstados       = !_docEstados;       break;
          case '_docDeclaraciones': _docDeclaraciones = !_docDeclaraciones; break;
          case '_docMatricula':     _docMatricula     = !_docMatricula;     break;
          case '_docFotoNegocio':   _docFotoNegocio   = !_docFotoNegocio;   break;
          case '_docSolicitudCred': _docSolicitudCred = !_docSolicitudCred; break;
          case '_docFotoCliente':   _docFotoCliente   = !_docFotoCliente;   break;
        }
      }),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: value ? color.withOpacity(0.08) : ConstantColors.grey100,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: value ? color : ConstantColors.borderLight,
            width: value ? 1.8 : 1,
          ),
        ),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                color: value ? color.withOpacity(0.15) : Colors.grey.withOpacity(0.1),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Icon(icon, size: 18,
                  color: value ? color : ConstantColors.textDarkGrey),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label,
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: value ? color : ConstantColors.textDark,
                      )),
                  Text(sublabel,
                      style: TextStyle(
                          fontSize: 11,
                          color: ConstantColors.textDarkGrey)),
                ],
              ),
            ),
            AnimatedContainer(
              duration: const Duration(milliseconds: 160),
              width: 26,
              height: 26,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: value ? color : Colors.transparent,
                border: Border.all(
                    color: value ? color : ConstantColors.borderLight,
                    width: 1.5),
              ),
              child: value
                  ? const Icon(Icons.check_rounded, size: 16, color: Colors.white)
                  : null,
            ),
          ],
        ),
      ),
    );
  }

  Widget _checkRow({
    required String label,
    required bool value,
    required ValueChanged<bool?> onChanged,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 2),
      child: Row(
        children: [
          Checkbox(
            value: value,
            onChanged: onChanged,
            activeColor: ConstantColors.warning,
            materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
            visualDensity: VisualDensity.compact,
          ),
          Expanded(
            child: Text(label,
                style: TextStyle(
                    color: ConstantColors.textDark, fontSize: 13)),
          ),
        ],
      ),
    );
  }

  Widget _checkRowIcon({
    required String label,
    required IconData icon,
    required bool value,
    required ValueChanged<bool?> onChanged,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        children: [
          Checkbox(
            value: value,
            onChanged: onChanged,
            activeColor: const Color(0xFF3B82F6),
            materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
            visualDensity: VisualDensity.compact,
          ),
          Icon(icon, size: 16, color: ConstantColors.textDarkGrey),
          const SizedBox(width: 6),
          Expanded(
            child: Text(label,
                style: TextStyle(
                    color: ConstantColors.textDark, fontSize: 13)),
          ),
        ],
      ),
    );
  }

  Widget _chipsSiNo({
    required bool? value,
    required ValueChanged<bool?> onChanged,
  }) {
    return Row(
      children: [
        _chip(label: 'Sí',  selected: value == true,  color: ConstantColors.success, onTap: () => onChanged(value == true ? null : true)),
        const SizedBox(width: 10),
        _chip(label: 'No',  selected: value == false, color: ConstantColors.error,   onTap: () => onChanged(value == false ? null : false)),
      ],
    );
  }

  Widget _chipsOpciones({
    required List<(String, String)> opciones,
    required String? seleccionado,
    required ValueChanged<String?> onChanged,
    bool wrap = false,
  }) {
    final chips = opciones.map((o) => _chip(
          label: o.$2,
          selected: seleccionado == o.$1,
          color: widget.tipo.color,
          onTap: () => onChanged(seleccionado == o.$1 ? null : o.$1),
        )).toList();

    if (wrap) {
      return Wrap(spacing: 8, runSpacing: 8, children: chips);
    }
    return Row(children: chips.expand((c) => [c, const SizedBox(width: 8)]).toList()..removeLast());
  }

  Widget _chip({
    required String label,
    required bool selected,
    required Color color,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 9),
        decoration: BoxDecoration(
          color: selected ? color.withOpacity(0.15) : ConstantColors.grey100,
          borderRadius: BorderRadius.circular(24),
          border: Border.all(
            color: selected ? color : ConstantColors.borderLight,
            width: selected ? 1.8 : 1,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: selected ? color : ConstantColors.textDarkGrey,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            fontSize: 13,
          ),
        ),
      ),
    );
  }

  Widget _chipDia(String label, bool selected, ValueChanged<bool> onChanged) {
    return GestureDetector(
      onTap: () => onChanged(!selected),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected
              ? const Color(0xFFF59E0B).withOpacity(0.15)
              : ConstantColors.grey100,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(
            color: selected ? const Color(0xFFF59E0B) : ConstantColors.borderLight,
            width: selected ? 1.8 : 1,
          ),
        ),
        child: Text(label,
            style: TextStyle(
              color: selected
                  ? const Color(0xFFF59E0B)
                  : ConstantColors.textDarkGrey,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              fontSize: 12,
            )),
      ),
    );
  }

  Widget _dropdownMes(
      String label, String? value, ValueChanged<String?> onChanged) {
    final meses = [
      ('01', 'Enero'),   ('02', 'Febrero'), ('03', 'Marzo'),
      ('04', 'Abril'),   ('05', 'Mayo'),    ('06', 'Junio'),
      ('07', 'Julio'),   ('08', 'Agosto'),  ('09', 'Septiembre'),
      ('10', 'Octubre'), ('11', 'Noviembre'),('12', 'Diciembre'),
    ];
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: DropdownButtonFormField<String>(
        value: value,
        hint: Text(label,
            style: TextStyle(
                color: ConstantColors.textDarkGrey, fontSize: 12)),
        items: meses
            .map((m) => DropdownMenuItem(value: m.$1, child: Text(m.$2)))
            .toList(),
        onChanged: onChanged,
        isExpanded: true,
        dropdownColor: ConstantColors.grey100,
        style: TextStyle(color: ConstantColors.textDark, fontSize: 13),
        decoration: InputDecoration(
          filled: true,
          fillColor: ConstantColors.grey100,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: ConstantColors.borderLight),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: ConstantColors.borderLight),
          ),
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        ),
      ),
    );
  }
}
