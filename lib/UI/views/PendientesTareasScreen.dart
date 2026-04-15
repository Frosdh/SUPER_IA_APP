import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';

class PendientesTareasScreen extends StatefulWidget {
  const PendientesTareasScreen({super.key});

  @override
  State<PendientesTareasScreen> createState() => _PendientesTareasScreenState();
}

class _PendientesTareasScreenState extends State<PendientesTareasScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _tareas = const [];

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  String _tipoLabel(String tipo) {
    switch (tipo) {
      case 'nueva_cita_campo':
        return 'Nueva cita en campo';
      case 'nueva_cita_oficina':
        return 'Nueva cita en oficina';
      case 'documentos_pendientes':
        return 'Recolectar documentación';
      case 'levantamiento':
        return 'Levantamiento';
      default:
        return tipo.replaceAll('_', ' ');
    }
  }

  Future<void> _cargar() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final usuarioId = await AuthPrefs.getUsuarioId();
      final asesorId = await AuthPrefs.getAsesorId();

      final url = Uri.parse('${Constants.apiBaseUrl}/obtener_tareas_pendientes_asesor.php');
      final resp = await http.post(url, body: {
        'usuario_id': usuarioId,
        if (asesorId.isNotEmpty) 'asesor_id': asesorId,
        'desde': DateTime.now().toIso8601String().substring(0, 10),
      }).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) {
        throw Exception('Respuesta invalida');
      }

      final status = decoded['status']?.toString() ?? '';
      if (status != 'success') {
        throw Exception(decoded['message']?.toString() ?? 'No se pudo cargar');
      }

      final tareas = decoded['tareas'];
      if (tareas is List) {
        _tareas = tareas.map((e) => Map<String, dynamic>.from(e as Map)).toList();
      } else {
        _tareas = [];
      }

      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.toString();
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: ConstantColors.backgroundDark,
        foregroundColor: ConstantColors.textWhite,
        elevation: 0,
        title: const Text(
          'Pendientes',
          style: TextStyle(fontWeight: FontWeight.w800),
        ),
        actions: [
          IconButton(
            onPressed: _cargar,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _cargar,
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : (_error != null)
                ? ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(16),
                    children: [
                      Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: Colors.red.shade50,
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: Colors.red.shade200),
                        ),
                        child: Text(
                          'No se pudo cargar.\n$_error',
                          style: TextStyle(color: Colors.red.shade800),
                        ),
                      ),
                    ],
                  )
                : (_tareas.isEmpty)
                    ? ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.all(16),
                        children: const [
                          SizedBox(height: 30),
                          Center(
                            child: Text(
                              'No hay tareas pendientes.',
                              style: TextStyle(fontWeight: FontWeight.w700, color: ConstantColors.textWhite),
                            ),
                          ),
                        ],
                      )
                    : ListView.separated(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.all(16),
                        itemCount: _tareas.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (context, i) {
                          final t = _tareas[i];
                          final tipo = t['tipo_tarea']?.toString() ?? '';
                          final estado = t['estado']?.toString() ?? '';
                          final fecha = t['fecha_programada']?.toString() ?? '';
                          final hora = t['hora_programada']?.toString() ?? '';
                          final cliente = t['cliente_nombre']?.toString() ?? 'Cliente';
                          final ciudad = t['cliente_ciudad']?.toString() ?? '';

                          return Container(
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: ConstantColors.backgroundCard,
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(color: ConstantColors.borderColor),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  children: [
                                    Expanded(
                                      child: Text(
                                        _tipoLabel(tipo),
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w800,
                                          fontSize: 14,
                                          color: ConstantColors.textWhite,
                                        ),
                                      ),
                                    ),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                                      decoration: BoxDecoration(
                                        color: estado == 'programada'
                                            ? Colors.blue.shade50
                                            : Colors.orange.shade50,
                                        borderRadius: BorderRadius.circular(20),
                                        border: Border.all(
                                          color: estado == 'programada'
                                              ? Colors.blue.shade200
                                              : Colors.orange.shade200,
                                        ),
                                      ),
                                      child: Text(
                                        estado.isEmpty ? '—' : estado,
                                        style: TextStyle(
                                          fontWeight: FontWeight.w700,
                                          fontSize: 12,
                                          color: estado == 'programada'
                                              ? Colors.blue.shade800
                                              : Colors.orange.shade800,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 8),
                                Text(
                                  cliente,
                                  style: TextStyle(
                                    color: ConstantColors.textWhite,
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                                if (ciudad.trim().isNotEmpty) ...[
                                  const SizedBox(height: 2),
                                  Text(
                                    ciudad,
                                    style: TextStyle(color: ConstantColors.textGrey, fontSize: 12),
                                  ),
                                ],
                                const SizedBox(height: 8),
                                Row(
                                  children: [
                                    Icon(Icons.calendar_month_rounded, size: 16, color: ConstantColors.textGrey),
                                    const SizedBox(width: 6),
                                    Text(
                                      [fecha, hora].where((e) => e.trim().isNotEmpty).join(' '),
                                      style: TextStyle(color: ConstantColors.textGrey, fontSize: 12),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          );
                        },
                      ),
      ),
    );
  }
}
